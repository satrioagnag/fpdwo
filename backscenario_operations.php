<?php
include 'koneksi.php';

function fetchData(mysqli $conn, string $query): array {
    $result = mysqli_query($conn, $query, MYSQLI_USE_RESULT);
    if (!$result) {
        die("Query error: " . mysqli_error($conn) . "<br>Query: " . $query);
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);
    return $rows;
}

$maxYearRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(Year) AS max_year FROM dimdate"));
$maxYear = $maxYearRow['max_year'] ?? 2023;

$salesPerformance = fetchData($conn, "
    SELECT 
        dsp.SalesPersonName AS salesperson, 
        SUM(fs.OrderCount) AS orders, 
        SUM(fs.TotalRevenue) AS revenue
    FROM factsales fs
    JOIN dimsalesperson dsp ON fs.SalesPersonKey = dsp.SalesPersonKey
    JOIN dimdate dd ON fs.DateKey = dd.DateKey
    WHERE dd.Year = $maxYear AND dsp.SalesPersonName IS NOT NULL
    GROUP BY dsp.SalesPersonName
    ORDER BY revenue DESC
");

$orderDrill = fetchData($conn, "
    SELECT 
        dsp.SalesPersonName AS salesperson, 
        dd.MonthName AS month, 
        SUM(fs.TotalRevenue) AS revenue
    FROM factsales fs
    JOIN dimsalesperson dsp ON fs.SalesPersonKey = dsp.SalesPersonKey
    JOIN dimdate dd ON fs.DateKey = dd.DateKey
    WHERE dd.Year = $maxYear
    GROUP BY dsp.SalesPersonName, dd.MonthName
    ORDER BY dsp.SalesPersonName, dd.DayNumberOfMonth
");

$lowStock = fetchData($conn, "
    SELECT 
        dp.ProductName AS product, 
        SUM(fi.EndOfDayQuantity) AS qty
    FROM factinventory fi
    JOIN dimproduct dp ON fi.ProductKey = dp.ProductKey
    JOIN dimdate dd ON fi.DateKey = dd.DateKey
    WHERE dd.Year = $maxYear
    GROUP BY dp.ProductName
    HAVING SUM(fi.EndOfDayQuantity) < dp.SafetyStockLevel
    ORDER BY qty ASC
    LIMIT 10
");
?>
<main>
    <div class="container-fluid px-4">
        <h1 class="mt-4">Skenario 3 - Salesperson & Inventory</h1>
        <ol class="breadcrumb mb-4">
            <li class="breadcrumb-item active">Menjawab: hubungan performa Sales Person dengan total penjualan & produk dengan kekurangan stok.</li>
        </ol>
        <div class="row mb-4">
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header">Apakah terdapat hubungan antara performa Sales Person dengan total penjualan dalam satu tahun terakhir?</div>
                    <div class="card-body">
                        <canvas id="salespersonChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header">Drill-down: klik sales person untuk melihat tren bulanan</div>
                    <div class="card-body">
                        <canvas id="salespersonDrill"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header">Produk mana yang paling sering mengalami kekurangan stok (kurang dari batas aman)?</div>
            <div class="card-body">
                <canvas id="inventoryChart"></canvas>
            </div>
        </div>
    </div>
</main>
<script>
const salesPerformance = <?php echo json_encode($salesPerformance); ?>;
const orderDrill = <?php echo json_encode($orderDrill); ?>;
const lowStock = <?php echo json_encode($lowStock); ?>;

const salesChart = new Chart(document.getElementById('salespersonChart'), {
    type: 'bar',
    data: {
        labels: salesPerformance.map(item => `Sales ${item.salesperson}`),
        datasets: [{
            label: 'Total Penjualan',
            data: salesPerformance.map(item => item.revenue),
            backgroundColor: 'rgba(75, 192, 192, 0.7)'
        }, {
            label: 'Jumlah Order',
            data: salesPerformance.map(item => item.orders),
            type: 'line',
            borderColor: 'rgba(255, 159, 64, 0.9)',
            fill: false,
            yAxisID: 'y-axis-2'
        }]
    },
    options: {
        scales: {
            yAxes: [
                { id: 'y-axis-1', position: 'left', ticks: { beginAtZero: true } },
                { id: 'y-axis-2', position: 'right', ticks: { beginAtZero: true } }
            ]
        },
        onClick: (_, elements) => {
            if (elements.length > 0) {
                const salesperson = salesPerformance[elements[0]._index].salesperson;
                renderSalespersonDrill(salesperson);
            }
        }
    }
});

const drillCtx = document.getElementById('salespersonDrill').getContext('2d');
let drillChart;
function renderSalespersonDrill(salesperson) {
    const filtered = orderDrill.filter(item => item.salesperson === salesperson);
    if (drillChart) drillChart.destroy();
    drillChart = new Chart(drillCtx, {
        type: 'line',
        data: {
            labels: filtered.map(item => `Bulan ${item.month}`),
            datasets: [{
                label: `Revenue Bulanan Sales ${salesperson}`,
                data: filtered.map(item => item.revenue),
                borderColor: 'rgba(99, 132, 255, 0.9)',
                fill: false
            }]
        }
    });
}

renderSalespersonDrill(salesPerformance.length ? salesPerformance[0].salesperson : null);

new Chart(document.getElementById('inventoryChart'), {
    type: 'horizontalBar',
    data: {
        labels: lowStock.map(item => item.product),
        datasets: [{
            label: 'Qty tersedia',
            data: lowStock.map(item => item.qty),
            backgroundColor: 'rgba(255, 99, 132, 0.7)'
        }]
    },
    options: {
        scales: { xAxes: [{ ticks: { beginAtZero: true } }] }
    }
});
</script>
