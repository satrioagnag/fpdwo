<?php
include 'koneksi.php';
set_time_limit(120);

// Optimize connection for large queries
mysqli_query($conn, "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
mysqli_query($conn, "SET SESSION tmp_table_size=256*1024*1024");
mysqli_query($conn, "SET SESSION max_heap_table_size=256*1024*1024");

function fetchData(mysqli $conn, string $query): array {
    $result = mysqli_query($conn, $query);
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

// Get max year once
$maxYearRow = fetchData($conn, "SELECT MAX(Year) AS max_year FROM dimdate");
$maxYear = (int)($maxYearRow[0]['max_year'] ?? 2001);

// OPTIMIZED: Single query with pre-aggregation and limiting
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
  STRAIGHT_JOIN dimdate dd ON fs.DateKey = dd.DateKey
  STRAIGHT_JOIN dimproduct dp ON fs.ProductKey = dp.ProductKey
  STRAIGHT_JOIN dimcustomer dc ON fs.CustomerKey = dc.CustomerKey
  WHERE dd.Year = $maxYear
  GROUP BY 
    CASE
      WHEN dp.ReorderPoint <= 200 THEN 'Komponen'
      WHEN dp.ReorderPoint <= 500 THEN 'Aksesoris'
      ELSE 'Produk Jadi'
    END,
    dc.CustomerType,
    fs.ProductKey,
    dp.ProductName
  ORDER BY freq DESC
  LIMIT 200
");

// Process in PHP (more efficient than multiple SQL queries)
$categoryFrequencyMap = [];
$segmentTotalsMap = [];

foreach ($categoryDetail as $r) {
    $cat = $r['category'];
    $seg = $r['segment'];
    
    // Aggregate frequency
    $key = $cat . '||' . $seg;
    $categoryFrequencyMap[$key] = ($categoryFrequencyMap[$key] ?? 0) + (float)$r['freq'];
    
    // Aggregate totals
    $segmentTotalsMap[$seg] = ($segmentTotalsMap[$seg] ?? 0) + (float)$r['qty'];
}

// Convert to arrays for JSON
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
const categoryFrequency = <?= json_encode($categoryFrequency, JSON_NUMERIC_CHECK) ?>;
const segmentTotals     = <?= json_encode($segmentTotals, JSON_NUMERIC_CHECK) ?>;
const categoryDetail    = <?= json_encode($categoryDetail, JSON_NUMERIC_CHECK) ?>;

console.log('customer data lengths', categoryFrequency.length, segmentTotals.length, categoryDetail.length);
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const fmt = (n) => Number(n).toLocaleString();

  let selectedCategory = null;
  let selectedSegment  = null;

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

  const categories = [...new Set(categoryFrequency.map(i => i.category))];
  const segments   = [...new Set(categoryFrequency.map(i => i.segment))];

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

  renderTable();
  refreshHighlights();
});
</script>