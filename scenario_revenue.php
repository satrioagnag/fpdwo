<?php
include 'koneksi.php';

function fetchData(mysqli $conn, string $query): array {
    $result = mysqli_query($conn, $query); // buffered
    if (!$result) {
        error_log("SQL ERROR: " . mysqli_error($conn));
        return [];
    }
    $rows = [];
    $count = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
        if (++$count > 5000) break; // safety sementara buat debug
    }
    mysqli_free_result($result);
    return $rows;
}



$maxYearRow = fetchData($conn, "SELECT MAX(Year) AS y FROM dimdate");
$maxYear = (int)($maxYearRow[0]['y'] ?? 2001);
$minYear = $maxYear - 2;


$sqlTopRevenue = "
SELECT
    dp.ProductName AS product,
    SUM(fs.TotalRevenue) AS revenue
FROM factsales fs
JOIN dimproduct dp ON fs.ProductKey = dp.ProductKey
WHERE fs.DateKey IN (
  SELECT DateKey FROM dimdate WHERE Year BETWEEN $minYear AND $maxYear
)
GROUP BY dp.ProductName
ORDER BY revenue DESC
LIMIT 10
";


$sqlYearly = "
   SELECT
    dd.Year AS year,
    SUM(fs.TotalRevenue) AS revenue
FROM factsales fs
JOIN dimdate dd ON fs.DateKey = dd.DateKey
GROUP BY dd.Year
ORDER BY dd.Year;

";

$sqlMonthly = "
    SELECT
    dd.Year AS year,
    dd.MonthName AS MonthName,
    SUM(fs.TotalRevenue) AS revenue
FROM factsales fs
JOIN dimdate dd ON fs.DateKey = dd.DateKey
GROUP BY dd.Year, dd.MonthName
ORDER BY dd.Year, dd.MonthName;

";

$sqlSpecialOffer = "
    SELECT
    dp.ProductName AS product,
    agg.qty,
    agg.revenue,
    IF(agg.hasOffer = 1, 'Dengan SpecialOffer', 'Tanpa SpecialOffer') AS offer_bucket
FROM (
    SELECT
        fs.ProductKey,
        (dso.DiscountPct > 0) AS hasOffer,
        SUM(fs.OrderQuantity) AS qty,
        SUM(fs.TotalRevenue) AS revenue
    FROM factsales fs
    JOIN dimspecialoffer dso
        ON fs.SpecialOfferKey = dso.SpecialOfferKey
  WHERE fs.DateKey IN (
  SELECT DateKey FROM dimdate WHERE Year BETWEEN $minYear AND $maxYear
)
    GROUP BY fs.ProductKey, hasOffer
) agg
JOIN dimproduct dp
    ON dp.ProductKey = agg.ProductKey
ORDER BY agg.revenue DESC
LIMIT 100;
";

$topRevenue = fetchData($conn, $sqlTopRevenue);
$specialOfferImpact = fetchData($conn, $sqlSpecialOffer);
$yearlyRevenue = fetchData($conn, $sqlYearly);
$monthlyRevenue = fetchData($conn, $sqlMonthly);

?>
<main>

    <div class="container-fluid px-4">
        <h1 class="mt-4">Skenario 1 - Revenue & Special Offer</h1>
        <ol class="breadcrumb mb-4">
            <li class="breadcrumb-item active">Visualisasi menjawab: Produk mana yang memberi kontribusi revenue tertinggi? & Pengaruh SpecialOffer terhadap penjualan.</li>
        </ol>
        <div class="row mb-4">
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header">Produk mana yang memberikan kontribusi revenue tertinggi dalam 3 tahun terakhir (Top 10)?</div>
                    <div class="card-body">
                        <canvas id="topRevenueChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header">Seberapa besar pengaruh penggunaan diskon (SpecialOffer) terhadap peningkatan jumlah penjualan?</div>
                    <div class="card-body">
                        <canvas id="specialOfferChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header">Drill-down Revenue: klik tahun untuk melihat tren bulanan</div>
                    <div class="card-body">
                        <canvas id="yearlyChart"></canvas>
                        <div class="mt-3">
                            <canvas id="monthlyChart" height="120"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header">Detail produk hasil cross-filter</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped" id="detailTable">
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Bucket SpecialOffer</th>
                                <th>Qty</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

        <!-- 1) Load Chart.js dulu -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<!-- 2) Inject data JSON (INI HARUS script sendiri, tanpa script di dalam script) -->
<script>
const topRevenueData = <?= json_encode($topRevenue, JSON_NUMERIC_CHECK) ?>;
const specialOfferData = <?= json_encode($specialOfferImpact, JSON_NUMERIC_CHECK) ?>;
const yearlyData = <?= json_encode($yearlyRevenue, JSON_NUMERIC_CHECK) ?>;
const monthlyData = <?= json_encode($monthlyRevenue, JSON_NUMERIC_CHECK) ?>;

console.log('data lengths', topRevenueData.length, specialOfferData.length, yearlyData.length, monthlyData.length);
</script>

<!-- 3) Baru kode chart kamu (inline dulu biar gampang debug) -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  const fmt = (n) => Number(n).toLocaleString();

  // ======= DOM =======
  const detailTbody = document.querySelector('#detailTable tbody');

  // Tambahin tombol reset (auto) di atas tabel
  const tableCard = document.querySelector('#detailTable')?.closest('.card-body');
  if (tableCard && !document.getElementById('btnResetFilter')) {
    const btn = document.createElement('button');
    btn.id = 'btnResetFilter';
    btn.className = 'btn btn-sm btn-outline-secondary mb-3';
    btn.textContent = 'Reset Filter';
    tableCard.prepend(btn);
  }
  const btnReset = document.getElementById('btnResetFilter');

  // ======= STATE =======
  let selectedProduct = null;
  let selectedYear = null;

  // ======= HELPERS =======
  function renderTable(productFilter = null) {
    const rows = specialOfferData
      .filter(i => !productFilter || i.product === productFilter)
      .map(i => `
        <tr>
          <td>${i.product}</td>
          <td>${i.offer_bucket}</td>
          <td>${fmt(i.qty)}</td>
          <td>${fmt(i.revenue)}</td>
        </tr>
      `)
      .join('');

    detailTbody.innerHTML = rows || `
      <tr><td colspan="4" class="text-center">Tidak ada data</td></tr>
    `;
  }

  function setTopRevenueHighlight(product) {
    // Chart.js v3: dataset.backgroundColor bisa array
    const base = 'rgba(54,162,235,0.55)';
    const hi   = 'rgba(255,99,132,0.85)';
    topRevenueChart.data.datasets[0].backgroundColor =
      topRevenueData.map(i => i.product === product ? hi : base);
    topRevenueChart.update();
  }

  function setSpecialOfferHighlight(product) {
    const base = 'rgba(255,159,64,0.45)';
    const hi   = 'rgba(255,99,132,0.85)';
    specialOfferChart.data.datasets[0].backgroundColor =
      specialOfferData.map(i => i.product === product ? hi : base);
    specialOfferChart.update();
  }

  function applyProductFilter(product) {
    selectedProduct = product;
    renderTable(product);
    setTopRevenueHighlight(product);
    setSpecialOfferHighlight(product);
  }

  function resetFilters() {
    selectedProduct = null;
    renderTable(null);

    // reset colors
    topRevenueChart.data.datasets[0].backgroundColor = 'rgba(54,162,235,0.55)';
    topRevenueChart.update();

    specialOfferChart.data.datasets[0].backgroundColor = 'rgba(255,159,64,0.45)';
    specialOfferChart.update();
  }

  // ======= INIT TABLE =======
  renderTable(null);
  btnReset?.addEventListener('click', resetFilters);

  // ======= CHART 1: TOP REVENUE =======
  const topRevenueChart = new Chart(
    document.getElementById('topRevenueChart'),
    {
      type: 'bar',
      data: {
        labels: topRevenueData.map(i => i.product),
        datasets: [{
          label: 'Revenue',
          data: topRevenueData.map(i => i.revenue),
          backgroundColor: 'rgba(54,162,235,0.55)'
        }]
      },
      options: {
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => `Revenue: ${fmt(ctx.parsed.y)}`
            }
          }
        },
        onClick: (_, elements) => {
          if (!elements.length) return;
          const idx = elements[0].index;
          applyProductFilter(topRevenueData[idx].product);
        }
      }
    }
  );

  // ======= CHART 2: SPECIAL OFFER IMPACT (Qty) =======
  const specialOfferChart = new Chart(
    document.getElementById('specialOfferChart'),
    {
      type: 'bar',
      data: {
        labels: specialOfferData.map(i => `${i.product} (${i.offer_bucket})`),
        datasets: [{
          label: 'Qty',
          data: specialOfferData.map(i => i.qty),
          backgroundColor: 'rgba(255,159,64,0.45)'
        }]
      },
      options: {
        indexAxis: 'y',
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => `Qty: ${fmt(ctx.parsed.x)}`
            }
          }
        },
        onClick: (_, elements) => {
          if (!elements.length) return;
          const idx = elements[0].index;
          applyProductFilter(specialOfferData[idx].product);
        }
      }
    }
  );

  // ======= CHART 3: YEARLY REVENUE =======
  const yearlyChart = new Chart(
    document.getElementById('yearlyChart'),
    {
      type: 'line',
      data: {
        labels: yearlyData.map(i => i.year),
        datasets: [{
          label: 'Revenue Tahunan',
          data: yearlyData.map(i => i.revenue),
          fill: false,
          tension: 0.25
        }]
      },
      options: {
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => `Revenue: ${fmt(ctx.parsed.y)}`
            }
          }
        },
        onClick: (_, elements) => {
          if (!elements.length) return;
          const idx = elements[0].index;
          selectedYear = yearlyData[idx].year;
          renderMonthly(selectedYear);
        }
      }
    }
  );

  // ======= CHART 4: MONTHLY (Drilldown) =======
  let monthlyChart;

  function renderMonthly(year) {
    const filtered = monthlyData
      .filter(i => Number(i.year) === Number(year));

    if (monthlyChart) monthlyChart.destroy();

    monthlyChart = new Chart(
      document.getElementById('monthlyChart'),
      {
        type: 'bar',
        data: {
          labels: filtered.map(i => `Bulan ${i.MonthName}`),
          datasets: [{
            label: `Revenue Bulanan ${year}`,
            data: filtered.map(i => i.revenue),
            backgroundColor: 'rgba(99,132,255,0.55)'
          }]
        },
        options: {
          plugins: {
            tooltip: {
              callbacks: {
                label: (ctx) => `Revenue: ${fmt(ctx.parsed.y)}`
              }
            }
          }
        }
      }
    );
  }

  // default: tahun terakhir
  if (yearlyData.length) {
    renderMonthly(yearlyData[yearlyData.length - 1].year);
  }

  // ======= EXTRA: klik judul chart untuk reset (optional fun) =======
  document.querySelectorAll('.card-header').forEach(h => {
    h.style.cursor = 'pointer';
    h.title = 'Klik untuk reset filter';
    h.addEventListener('dblclick', resetFilters);
  });
});
</script>






