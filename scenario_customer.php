<?php

include 'koneksi.php';
set_time_limit(120); // 2 menit
mysqli_query($conn, "SET SESSION net_read_timeout=120");
mysqli_query($conn, "SET SESSION net_write_timeout=120");

function fetchData(mysqli $conn, string $query): array {
    $result = mysqli_query($conn, $query); // buffered
    if (!$result) {
        error_log("SQL ERROR: " . mysqli_error($conn));
        return [];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);
    return $rows;
}

// Ambil tahun terbaru
$maxYearRow = fetchData($conn, "SELECT MAX(Year) AS max_year FROM dimdate");
$maxYear = (int)($maxYearRow[0]['max_year'] ?? 2001);

$rangeRow = fetchData($conn, "SELECT MIN(DateKey) AS min_k, MAX(DateKey) AS max_k FROM dimdate WHERE Year = $maxYear");
$minDateKey = (int)$rangeRow[0]['min_k'];
$maxDateKey = (int)$rangeRow[0]['max_k'];

$topProducts = fetchData($conn, "
  SELECT fs.ProductKey, SUM(fs.OrderCount) AS freq
  FROM factsales fs
  WHERE fs.DateKey BETWEEN $minDateKey AND $maxDateKey
  GROUP BY fs.ProductKey
  ORDER BY freq DESC
  LIMIT 30
");

$productKeys = array_map(fn($r)=> (int)$r['ProductKey'], $topProducts);
if (!$productKeys) $productKeys = [0];
$inKeys = implode(',', $productKeys);

$categoryDetail = fetchData($conn, "
  SELECT
    CASE
      WHEN dp.ReorderPoint <= 200 THEN 'Komponen'
      WHEN dp.ReorderPoint <= 500 THEN 'Aksesoris'
      ELSE 'Produk Jadi'
    END AS category,
    dc.CustomerType AS segment,
    dp.ProductName AS product,
    SUM(fs.OrderQuantity) AS qty,
    SUM(fs.OrderCount) AS freq
  FROM factsales fs
  JOIN dimproduct dp  ON fs.ProductKey  = dp.ProductKey
  JOIN dimcustomer dc ON fs.CustomerKey = dc.CustomerKey
  WHERE fs.DateKey BETWEEN $minDateKey AND $maxDateKey
    AND fs.ProductKey IN ($inKeys)
  GROUP BY fs.ProductKey, dc.CustomerType, category, dp.ProductName
  ORDER BY freq DESC
");
$categoryFrequencyMap = []; // key: category||segment => frequency
$segmentTotalsMap = [];    // key: segment => total qty

foreach ($categoryDetail as $r) {
    $cat = $r['category'];
    $seg = $r['segment'];

    // frequency per kategori+segmen (pakai SUM(OrderCount))
    $key = $cat . '||' . $seg;
    $categoryFrequencyMap[$key] = ($categoryFrequencyMap[$key] ?? 0) + (float)$r['freq'];

    // total qty per segmen
    $segmentTotalsMap[$seg] = ($segmentTotalsMap[$seg] ?? 0) + (float)$r['qty'];
}

// bentuk array untuk JSON
$categoryFrequency = [];
foreach ($categoryFrequencyMap as $key => $val) {
    [$cat, $seg] = explode('||', $key);
    $categoryFrequency[] = ['category' => $cat, 'segment' => $seg, 'frequency' => $val];
}

$segmentTotals = [];
foreach ($segmentTotalsMap as $seg => $val) {
    $segmentTotals[] = ['segment' => $seg, 'total_qty' => $val];
}

?>

<main>
  <div class="container-fluid px-4">
    <h1 class="mt-4">Skenario 2 - Customer Frequency</h1>
    <ol class="breadcrumb mb-4">
      <li class="breadcrumb-item active">
        Visualisasi menjawab: Produk apa yang memiliki tingkat order frequency tertinggi di segmen pelanggan Individual dibandingkan Reseller?
      </li>
    </ol>

    <div class="row mb-4">
      <div class="col-lg-8">
        <div class="card mb-4">
          <div class="card-header">Order frequency per kategori & segmen (tahun <?= htmlspecialchars((string)$maxYear) ?>)</div>
          <div class="card-body">
            <canvas id="categoryStacked" height="280"></canvas>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card mb-4">
          <div class="card-header">Distribusi total order qty per segmen</div>
          <div class="card-body">
            <canvas id="segmentPie" height="280"></canvas>
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header">Detail produk (klik bar / klik pie untuk filter)</div>
      <div class="card-body">
        <button id="btnResetCustomerFilter" class="btn btn-sm btn-outline-secondary mb-3">Reset Filter</button>
        <div class="table-responsive">
          <table class="table table-striped" id="categoryTable">
            <thead>
              <tr>
                <th>Kategori</th>
                <th>Segmen</th>
                <th>Produk</th>
                <th class="text-end">Qty</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- Chart.js v3+ (samain dengan scenario_revenue.php) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<!-- Inject JSON -->
<script>
const categoryFrequency = <?= json_encode($categoryFrequency, JSON_NUMERIC_CHECK) ?>;
const segmentTotals     = <?= json_encode($segmentTotals, JSON_NUMERIC_CHECK) ?>;
const categoryDetail    = <?= json_encode($categoryDetail, JSON_NUMERIC_CHECK) ?>;

console.log('customer data lengths', categoryFrequency.length, segmentTotals.length, categoryDetail.length);
</script>

<!-- Logic & Charts -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  const fmt = (n) => Number(n).toLocaleString();

  // ---------- state ----------
  let selectedCategory = null;
  let selectedSegment  = null;

  // ---------- table ----------
  const tbody = document.querySelector('#categoryTable tbody');

  function renderTable() {
    const filtered = categoryDetail.filter(r => {
      const okCat = !selectedCategory || r.category === selectedCategory;
      const okSeg = !selectedSegment  || r.segment === selectedSegment;
      return okCat && okSeg;
    });

    const rows = filtered.map(r => `
      <tr>
        <td>${r.category}</td>
        <td>${r.segment}</td>
        <td>${r.product}</td>
        <td class="text-end">${fmt(r.qty)}</td>
      </tr>
    `).join('');

    tbody.innerHTML = rows || `<tr><td colspan="4" class="text-center">Tidak ada data</td></tr>`;
  }

  function resetFilter() {
    selectedCategory = null;
    selectedSegment = null;
    renderTable();
    refreshHighlights();
  }

  document.getElementById('btnResetCustomerFilter')?.addEventListener('click', resetFilter);

  // ---------- prepare categories & segments ----------
  const categories = [...new Set(categoryFrequency.map(i => i.category))];
  const segments   = [...new Set(categoryFrequency.map(i => i.segment))];

  const datasets = segments.map(seg => ({
    label: seg,
    data: categories.map(cat => {
const freqMap = new Map();
categoryFrequency.forEach(r => {
  freqMap.set(`${r.category}||${r.segment}`, Number(r.frequency));
});

const datasets = segments.map(seg => ({
  label: seg,
  data: categories.map(cat => freqMap.get(`${cat}||${seg}`) || 0),
  backgroundColor: seg === 'Individual'
    ? 'rgba(54,162,235,0.55)'
    : 'rgba(255,99,132,0.55)'
}));
      return row ? Number(row.frequency) : 0;
    }),
    backgroundColor: seg === 'Individual'
      ? 'rgba(54,162,235,0.55)'
      : 'rgba(255,99,132,0.55)'
  }));

  // ---------- stacked bar ----------
  const categoryStacked = new Chart(
    document.getElementById('categoryStacked'),
    {
      type: 'bar',
      data: { labels: categories, datasets },
      options: {
        responsive: true,
        scales: {
          x: { stacked: true },
          y: { stacked: true, beginAtZero: true }
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => `${ctx.dataset.label}: ${fmt(ctx.parsed.y)}`
            }
          }
        },
        onClick: (_, elements) => {
          if (!elements.length) return;
          const el = elements[0];
          selectedCategory = categories[el.index];
          selectedSegment  = datasets[el.datasetIndex].label;
          renderTable();
          refreshHighlights();
        }
      }
    }
  );

  // ---------- doughnut ----------
  const segmentPie = new Chart(
    document.getElementById('segmentPie'),
    {
      type: 'doughnut',
      data: {
        labels: segmentTotals.map(i => i.segment),
        datasets: [{
          data: segmentTotals.map(i => i.total_qty),
          backgroundColor: [
            'rgba(54,162,235,0.60)',
            'rgba(255,99,132,0.60)',
            'rgba(255,159,64,0.60)',
            'rgba(75,192,192,0.60)'
          ]
        }]
      },
      options: {
        plugins: {
          tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${fmt(ctx.parsed)}` } }
        },
        onClick: (_, elements) => {
          if (!elements.length) return;
          selectedSegment = segmentPie.data.labels[elements[0].index];
          renderTable();
          refreshHighlights();
        }
      }
    }
  );

  function refreshHighlights() {
    // highlight bar chart by dimming others
    categoryStacked.data.datasets.forEach(ds => {
      const base = ds.label === 'Individual' ? 'rgba(54,162,235,0.55)' : 'rgba(255,99,132,0.55)';
      ds.backgroundColor = categories.map(cat => {
        const hitCat = !selectedCategory || cat === selectedCategory;
        const hitSeg = !selectedSegment  || ds.label === selectedSegment;
        return (hitCat && hitSeg) ? base.replace('0.55','0.85') : base.replace('0.55','0.25');
      });
    });
    categoryStacked.update();
  }

  // init
  renderTable();
  refreshHighlights();
});
</script>
