<?php
include 'koneksi.php';

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

// Tahun terbaru
$maxYearRow = fetchData($conn, "SELECT MAX(Year) AS max_year FROM dimdate");
$maxYear = (int)($maxYearRow[0]['max_year'] ?? 2001);

// Filter tanggal 1 tahun (hindari join dimdate berat di factsales)
$dateKeyFilter = "fs.DateKey IN (SELECT DateKey FROM dimdate WHERE Year = $maxYear)";

// 1) Sales performance (orders vs revenue) per salesperson
$salesPerformance = fetchData($conn, "
    SELECT 
        dsp.SalesPersonName AS salesperson, 
        SUM(fs.OrderCount) AS orders, 
        SUM(fs.TotalRevenue) AS revenue
    FROM factsales fs
    JOIN dimsalesperson dsp ON fs.SalesPersonKey = dsp.SalesPersonKey
    WHERE $dateKeyFilter
      AND dsp.SalesPersonName IS NOT NULL
    GROUP BY dsp.SalesPersonName
    ORDER BY revenue DESC
    LIMIT 15
");

// 2) Drill detail per salesperson (top products)
$salespersonDetail = fetchData($conn, "
    SELECT
        dsp.SalesPersonName AS salesperson,
        dp.ProductName AS product,
        SUM(fs.OrderQuantity) AS qty,
        SUM(fs.TotalRevenue) AS revenue
    FROM factsales fs
    JOIN dimsalesperson dsp ON fs.SalesPersonKey = dsp.SalesPersonKey
    JOIN dimproduct dp ON fs.ProductKey = dp.ProductKey
    WHERE $dateKeyFilter
      AND dsp.SalesPersonName IS NOT NULL
    GROUP BY dsp.SalesPersonName, dp.ProductName
    ORDER BY revenue DESC
    LIMIT 500
");

// 3) Low inventory (dari factinventory + dimproduct threshold)
$lowStock = fetchData($conn, "SELECT
        dp.ProductName AS product,
        SUM(fi.EndOfDayQuantity) AS qty,
        dp.ReorderPoint AS reorder_point,
        dp.SafetyStockLevel AS safety_stock
    FROM factinventory fi
    JOIN dimproduct dp ON fi.ProductKey = dp.ProductKey
    WHERE fi.DateKey = (
        SELECT MAX(fi2.DateKey)
        FROM factinventory fi2
        JOIN dimdate dd ON fi2.DateKey = dd.DateKey
        WHERE dd.Year = $maxYear
    )
    GROUP BY dp.ProductName, dp.ReorderPoint, dp.SafetyStockLevel
    HAVING qty < reorder_point OR qty < safety_stock
    ORDER BY (reorder_point - qty) DESC
    LIMIT 20
");
?>

<main>
  <div class="container-fluid px-4">
    <h1 class="mt-4">Skenario 3 - Operations Health</h1>
    <ol class="breadcrumb mb-4">
      <li class="breadcrumb-item active">
        Visualisasi menjawab: hubungan performa Sales Person dengan penjualan (tahun <?= htmlspecialchars((string)$maxYear) ?>) & produk low inventory.
      </li>
    </ol>

    <div class="row mb-4">
      <div class="col-lg-6">
        <div class="card mb-4">
          <div class="card-header">Performa Sales Person (Orders vs Revenue) - klik untuk drilldown</div>
          <div class="card-body">
            <canvas id="salespersonChart" height="280"></canvas>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card mb-4">
          <div class="card-header">Low Inventory (qty &lt; reorder point)</div>
          <div class="card-body">
            <canvas id="inventoryChart" height="280"></canvas>
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header">Drilldown Sales Person (Top produk)</div>
      <div class="card-body">
        <button id="btnResetOpsFilter" class="btn btn-sm btn-outline-secondary mb-3">Reset Drilldown</button>
        <div class="table-responsive">
          <table class="table table-striped" id="salesTable">
            <thead>
              <tr>
                <th>Sales Person</th>
                <th>Produk</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Revenue</th>
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
const salesPerformance = <?= json_encode($salesPerformance, JSON_NUMERIC_CHECK) ?>;
const salespersonDetail = <?= json_encode($salespersonDetail, JSON_NUMERIC_CHECK) ?>;
const lowStock = <?= json_encode($lowStock, JSON_NUMERIC_CHECK) ?>;

console.log('ops data lengths', salesPerformance.length, salespersonDetail.length, lowStock.length);
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const fmt = (n) => Number(n).toLocaleString();

  // -------- table drilldown --------
  const tbody = document.querySelector('#salesTable tbody');
  let selectedSales = null;

  function renderSalesTable() {
    const filtered = salespersonDetail.filter(r => !selectedSales || r.salesperson === selectedSales);
    const rows = filtered.map(r => `
      <tr>
        <td>${r.salesperson}</td>
        <td>${r.product}</td>
        <td class="text-end">${fmt(r.qty)}</td>
        <td class="text-end">${fmt(r.revenue)}</td>
      </tr>
    `).join('');
    tbody.innerHTML = rows || `<tr><td colspan="4" class="text-center">Tidak ada data</td></tr>`;
  }

  function resetDrill() {
    selectedSales = null;
    renderSalesTable();
    highlightSales(null);
  }

  document.getElementById('btnResetOpsFilter')?.addEventListener('click', resetDrill);

  // -------- Chart 1: mixed axis (orders + revenue) --------
  const labels = salesPerformance.map(i => i.salesperson);

  const salespersonChart = new Chart(
    document.getElementById('salespersonChart'),
    {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Orders',
            data: salesPerformance.map(i => i.orders),
            yAxisID: 'y',
            backgroundColor: 'rgba(54,162,235,0.55)'
          },
          {
            type: 'line',
            label: 'Revenue',
            data: salesPerformance.map(i => i.revenue),
            yAxisID: 'y1',
            borderColor: 'rgba(255,159,64,0.85)',
            tension: 0.25
          }
        ]
      },
      options: {
        responsive: true,
        scales: {
          y:  { beginAtZero: true, position: 'left',  title: { display: true, text: 'Orders' } },
          y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Revenue' } }
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
          const idx = elements[0].index;
          selectedSales = labels[idx];
          renderSalesTable();
          highlightSales(selectedSales);
        }
      }
    }
  );

  function highlightSales(salesperson) {
    const base = 'rgba(54,162,235,0.55)';
    const hi   = 'rgba(255,99,132,0.85)';
    salespersonChart.data.datasets[0].backgroundColor =
      labels.map(l => !salesperson ? base : (l === salesperson ? hi : 'rgba(54,162,235,0.20)'));
    salespersonChart.update();
  }

  // -------- Chart 2: low inventory horizontal --------
  const inventoryChart = new Chart(
    document.getElementById('inventoryChart'),
    {
      type: 'bar',
      data: {
        labels: lowStock.map(i => i.product),
        datasets: [{
          label: 'Qty tersedia',
          data: lowStock.map(i => i.qty),
          backgroundColor: 'rgba(255,99,132,0.55)'
        }]
      },
      options: {
        indexAxis: 'y',
        scales: { x: { beginAtZero: true } },
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => `Qty: ${fmt(ctx.parsed.x)}`
            }
          }
        }
      }
    }
  );

  // init
  renderSalesTable();
});
</script>
