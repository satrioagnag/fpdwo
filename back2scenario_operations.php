<?php
include 'koneksi.php';

set_time_limit(60);
@mysqli_query($conn, "SET SESSION net_read_timeout=30");
@mysqli_query($conn, "SET SESSION net_write_timeout=30");

function fetchData(mysqli $conn, string $query, int $maxRows = 5000): array {
    $result = mysqli_query($conn, $query);
    if (!$result) {
        error_log("SQL ERROR: " . mysqli_error($conn) . " | QUERY: " . $query);
        return [];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
        if (count($rows) >= $maxRows) break;
    }
    mysqli_free_result($result);
    return $rows;
}

/* ========= Tahun terbaru + range DateKey ========= */
$maxYearRow = fetchData($conn, "SELECT MAX(Year) AS max_year FROM dimdate", 1);
$maxYear = (int)($maxYearRow[0]['max_year'] ?? 2001);

$rangeRow = fetchData($conn, "SELECT MIN(DateKey) AS min_k, MAX(DateKey) AS max_k FROM dimdate WHERE Year = $maxYear", 1);
$minDateKey = (int)($rangeRow[0]['min_k'] ?? 0);
$maxDateKey = (int)($rangeRow[0]['max_k'] ?? 0);

if ($minDateKey === 0 || $maxDateKey === 0) {
    die("DateKey range not found for Year=$maxYear");
}

/* ========= Cache (biar reload ga nembak DB terus) ========= */
$cacheFile = __DIR__ . "/cache_operations_$maxYear.json";
$cacheTTL  = 600; // 10 menit

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    $lowStockRows   = $cached['lowStockRows'] ?? [];
    $movementRows   = $cached['movementRows'] ?? [];
    $locationRows   = $cached['locationRows'] ?? [];
    $detailRows     = $cached['detailRows'] ?? [];
    $snapshotDateKey = (int)($cached['snapshotDateKey'] ?? 0);
} else {

    /* ========= 1) Tentukan snapshot inventory: DateKey terbaru di tahun itu ========= */
    $snapRow = fetchData($conn, "
        SELECT MAX(fi.DateKey) AS snap
        FROM factinventory fi
        WHERE fi.DateKey BETWEEN $minDateKey AND $maxDateKey
    ", 1);

    $snapshotDateKey = (int)($snapRow[0]['snap'] ?? 0);
    if ($snapshotDateKey === 0) {
        die("No inventory snapshot found for Year=$maxYear");
    }

    /* ========= 2) Low stock: Top shortage (cepat, snapshot only) =========
       - stok snapshot: SUM EndOfDayQuantity per product (gabung semua lokasi)
       - threshold: dp.ReorderPoint / dp.SafetyStockLevel
       - shortageScore: seberapa jauh di bawah threshold (buat ranking)
    */
    // 1) coba ambil yang benar-benar LOW dulu
$lowStockRows = fetchData($conn, "
  SELECT
    dp.ProductName AS product,
    dl.LocationName AS location,
    COALESCE(fi.EndOfDayQuantity,0) AS qty,
    GREATEST(COALESCE(dp.ReorderPoint,0), COALESCE(dp.SafetyStockLevel,0)) AS threshold,
    (GREATEST(COALESCE(dp.ReorderPoint,0), COALESCE(dp.SafetyStockLevel,0)) - COALESCE(fi.EndOfDayQuantity,0)) AS shortage
  FROM factinventory fi
  JOIN dimproduct dp ON fi.ProductKey = dp.ProductKey
  JOIN dimlocation dl ON fi.LocationKey = dl.LocationKey
  WHERE fi.DateKey = $snapshotDateKey
    AND COALESCE(fi.EndOfDayQuantity,0) < GREATEST(COALESCE(dp.ReorderPoint,0), COALESCE(dp.SafetyStockLevel,0))
  ORDER BY shortage DESC
  LIMIT 20
", 50);

// 2) kalau ternyata ga ada yang LOW, ambil yang PALING DEKAT threshold (risk ranking)
if (count($lowStockRows) === 0) {
  $lowStockRows = fetchData($conn, "
    SELECT
      dp.ProductName AS product,
      dl.LocationName AS location,
      COALESCE(fi.EndOfDayQuantity,0) AS qty,
      GREATEST(COALESCE(dp.ReorderPoint,0), COALESCE(dp.SafetyStockLevel,0)) AS threshold,
      (GREATEST(COALESCE(dp.ReorderPoint,0), COALESCE(dp.SafetyStockLevel,0)) - COALESCE(fi.EndOfDayQuantity,0)) AS shortage
    FROM factinventory fi
    JOIN dimproduct dp ON fi.ProductKey = dp.ProductKey
    JOIN dimlocation dl ON fi.LocationKey = dl.LocationKey
    WHERE fi.DateKey = $snapshotDateKey
      AND GREATEST(COALESCE(dp.ReorderPoint,0), COALESCE(dp.SafetyStockLevel,0)) > 0
    ORDER BY shortage DESC
    LIMIT 20
  ", 50);
}


    /* ========= 3) Movement per bulan (tahun terbaru) =========
       Karena dimdate kamu gak punya MonthNum, kita derive dari DateKey: YYYYMMDD -> MM
    */
    $movementRows = fetchData($conn, "
        SELECT
          (FLOOR(fi.DateKey / 100) % 100) AS month_num,
          MAX(dd.MonthName) AS month_name,
          SUM(fi.QuantityIn) AS qty_in,
          SUM(fi.QuantityOut) AS qty_out
        FROM factinventory fi
        JOIN dimdate dd ON fi.DateKey = dd.DateKey
        WHERE fi.DateKey BETWEEN $minDateKey AND $maxDateKey
        GROUP BY month_num
        ORDER BY month_num
    ", 50);

    /* ========= 4) Distribusi stok per lokasi (snapshot) ========= */
    $locationRows = fetchData($conn, "
        SELECT
          dl.LocationName AS location,
          SUM(fi.EndOfDayQuantity) AS qty
        FROM factinventory fi
        JOIN dimlocation dl ON fi.LocationKey = dl.LocationKey
        WHERE fi.DateKey = $snapshotDateKey
        GROUP BY dl.LocationName
        ORDER BY qty DESC
        LIMIT 12
    ", 50);

    /* ========= 5) Detail table (snapshot) =========
       - kita batasi Top 300 baris, cukup untuk drill/filter UI
    */
    $detailRows = fetchData($conn, "
        SELECT
          dp.ProductName AS product,
          dl.LocationName AS location,
          fi.EndOfDayQuantity AS qty,
          dp.ReorderPoint AS reorder_point,
          dp.SafetyStockLevel AS safety_stock,
          GREATEST(dp.ReorderPoint, dp.SafetyStockLevel) AS threshold
        FROM factinventory fi
        JOIN dimproduct dp ON fi.ProductKey = dp.ProductKey
        JOIN dimlocation dl ON fi.LocationKey = dl.LocationKey
        WHERE fi.DateKey = $snapshotDateKey
        ORDER BY fi.EndOfDayQuantity ASC
        LIMIT 300
    ", 400);

    @file_put_contents($cacheFile, json_encode([
        'snapshotDateKey' => $snapshotDateKey,
        'lowStockRows' => $lowStockRows,
        'movementRows' => $movementRows,
        'locationRows' => $locationRows,
        'detailRows' => $detailRows
    ]));
}

/* ========= Helper label snapshot ========= */
$snapLabel = (string)$snapshotDateKey;
if (strlen($snapLabel) === 8) {
    $snapLabel = substr($snapLabel,0,4) . '-' . substr($snapLabel,4,2) . '-' . substr($snapLabel,6,2);
}
?>

<main>
  <div class="container-fluid px-4">
    <h1 class="mt-4">Skenario 3 - Operations Health</h1>
    <ol class="breadcrumb mb-4">
      <li class="breadcrumb-item active">
        Visualisasi menjawab: Subcategory/produk mana yang paling sering mengalami low inventory? Bagaimana tren pergerakan stok? Dan lokasi mana yang paling padat/kritis.
      </li>
    </ol>

    <div class="row mb-4">
      <div class="col-lg-7">
        <div class="card mb-4">
          <div class="card-header">
            Low Inventory (Top shortage) — Snapshot: <?= htmlspecialchars($snapLabel) ?> (klik bar untuk filter)
          </div>
          <div class="card-body">
            <canvas id="lowStockChart" height="260"></canvas>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card mb-4">
          <div class="card-header">
            Distribusi stok per lokasi — Snapshot (klik donut untuk filter)
          </div>
          <div class="card-body">
            <canvas id="locationChart" height="260"></canvas>
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header">Inventory movement (Qty In vs Qty Out) — Tahun <?= htmlspecialchars((string)$maxYear) ?></div>
      <div class="card-body">
        <canvas id="movementChart" height="220"></canvas>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header">Detail snapshot (filter: produk/lokasi)</div>
      <div class="card-body">
        <button id="btnResetOpsFilter" class="btn btn-sm btn-outline-secondary mb-3">Reset Filter</button>
        <div class="table-responsive">
          <table class="table table-striped" id="opsTable">
            <thead>
              <tr>
                <th>Produk</th>
                <th>Lokasi</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Threshold</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="text-muted small mt-2">
          Status = LOW kalau Qty &lt; max(ReorderPoint, SafetyStockLevel).
        </div>
      </div>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
const lowStockRows = <?= json_encode($lowStockRows, JSON_NUMERIC_CHECK) ?>;
const movementRows = <?= json_encode($movementRows, JSON_NUMERIC_CHECK) ?>;
const locationRows = <?= json_encode($locationRows, JSON_NUMERIC_CHECK) ?>;
const detailRows   = <?= json_encode($detailRows, JSON_NUMERIC_CHECK) ?>;

console.log('ops lengths', lowStockRows.length, movementRows.length, locationRows.length, detailRows.length);
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const fmt = (n) => Number(n).toLocaleString();

  let selectedProduct = null;
  let selectedLocation = null;

  const tbody = document.querySelector('#opsTable tbody');

  function statusRow(r) {
    const qty = Number(r.qty);
    const th  = Number(r.threshold);
    return qty < th ? 'LOW' : 'OK';
  }

  function renderTable() {
    const filtered = detailRows.filter(r => {
      const okP = !selectedProduct || r.product === selectedProduct;
      const okL = !selectedLocation || r.location === selectedLocation;
      return okP && okL;
    });

    const rows = filtered.map(r => `
      <tr>
        <td>${r.product}</td>
        <td>${r.location}</td>
        <td class="text-end">${fmt(r.qty)}</td>
        <td class="text-end">${fmt(r.threshold)}</td>
        <td>${statusRow(r)}</td>
      </tr>
    `).join('');

    tbody.innerHTML = rows || `<tr><td colspan="5" class="text-center">Tidak ada data</td></tr>`;
  }

  function resetFilter() {
    selectedProduct = null;
    selectedLocation = null;
    renderTable();
    refreshHighlights();
  }

  document.getElementById('btnResetOpsFilter')?.addEventListener('click', resetFilter);

  /* ===== Chart 1: Low stock ===== */
  const lowLabels = lowStockRows.map(r => `${r.product} @ ${r.location}`);
const lowData   = lowStockRows.map(r => Number(r.shortage));


  const lowStockChart = new Chart(
    document.getElementById('lowStockChart'),
    {
      type: 'bar',
      data: {
        labels: lowLabels,
        datasets: [{
          label: 'Shortage (threshold - qty)',
          data: lowData,
          backgroundColor: lowLabels.map(() => 'rgba(255,99,132,0.55)')
        }]
      },
      options: {
        plugins: {
          tooltip: {
            callbacks: {
label: (ctx) => `Shortage: ${fmt(ctx.parsed.y)}`
            }
          }
        },
        onClick: (_, elements) => {
          if (!elements.length) return;
          const idx = elements[0].index;
          selectedProduct = lowStockRows[idx].product;
          renderTable();
          refreshHighlights();
        }
      }
    }
  );

  /* ===== Chart 2: Location distribution ===== */
  const locLabels = locationRows.map(r => r.location);
  const locData   = locationRows.map(r => Number(r.qty));

  const locationChart = new Chart(
    document.getElementById('locationChart'),
    {
      type: 'doughnut',
      data: {
        labels: locLabels,
        datasets: [{
          data: locData,
          backgroundColor: locLabels.map((_,i) => `rgba(54,162,235,${0.25 + (i%7)*0.08})`)
        }]
      },
      options: {
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => `${ctx.label}: ${fmt(ctx.parsed)}`
            }
          }
        },
        onClick: (_, elements) => {
          if (!elements.length) return;
          const idx = elements[0].index;
          selectedLocation = locLabels[idx];
          renderTable();
          refreshHighlights();
        }
      }
    }
  );

  /* ===== Chart 3: Movement ===== */
  const moveLabels = movementRows
    .sort((a,b) => Number(a.month_num) - Number(b.month_num))
    .map(r => r.month_name);

  const moveIn  = movementRows
    .sort((a,b) => Number(a.month_num) - Number(b.month_num))
    .map(r => Number(r.qty_in));

  const moveOut = movementRows
    .sort((a,b) => Number(a.month_num) - Number(b.month_num))
    .map(r => Number(r.qty_out));

  const movementChart = new Chart(
    document.getElementById('movementChart'),
    {
      type: 'line',
      data: {
        labels: moveLabels,
        datasets: [
          { label: 'Quantity In',  data: moveIn,  tension: 0.25, fill: false },
          { label: 'Quantity Out', data: moveOut, tension: 0.25, fill: false }
        ]
      },
      options: {
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => `${ctx.dataset.label}: ${fmt(ctx.parsed.y)}`
            }
          }
        }
      }
    }
  );

  function refreshHighlights() {
    // dim lowStock bars that are not selected product
    lowStockChart.data.datasets[0].backgroundColor = lowStockRows.map(r => {
      if (!selectedProduct) return 'rgba(255,99,132,0.55)';
      return r.product === selectedProduct ? 'rgba(255,99,132,0.85)' : 'rgba(255,99,132,0.20)';
    });
    lowStockChart.update();

    // dim donut slices that are not selected location
    locationChart.data.datasets[0].backgroundColor = locLabels.map((loc,i) => {
      const base = 0.25 + (i%7)*0.08;
      if (!selectedLocation) return `rgba(54,162,235,${base})`;
      return loc === selectedLocation ? `rgba(54,162,235,0.85)` : `rgba(54,162,235,0.18)`;
    });
    locationChart.update();
  }

  // init
  renderTable();
  refreshHighlights();
});
</script>
