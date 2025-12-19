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

// Ambil tahun terbaru sekali saja
$maxYearRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(Year) AS max_year FROM dimdate"));
$maxYear = $maxYearRow['max_year'] ?? 2023;

$categoryFrequency = fetchData($conn, "
    SELECT 
        CASE 
            WHEN dp.ReorderPoint <= 200 THEN 'Komponen'      -- diasumsikan ReorderPoint kecil = cepat manufaktur
            WHEN dp.ReorderPoint <= 500 THEN 'Aksesoris'
            ELSE 'Produk Jadi' 
        END AS category,
        dc.CustomerType AS segment,
        COUNT(*) AS frequency
    FROM factsales fs
    JOIN dimproduct dp ON fs.ProductKey = dp.ProductKey
    JOIN dimcustomer dc ON fs.CustomerKey = dc.CustomerKey
    JOIN dimdate dd ON fs.DateKey = dd.DateKey
    WHERE dd.Year = $maxYear
    GROUP BY category, segment
");

$segmentTotals = fetchData($conn, "
    SELECT 
        dc.CustomerType AS segment,
        SUM(fs.OrderQuantity) AS total_qty
    FROM factsales fs
    JOIN dimcustomer dc ON fs.CustomerKey = dc.CustomerKey
    JOIN dimdate dd ON fs.DateKey = dd.DateKey
    WHERE dd.Year = $maxYear
    GROUP BY dc.CustomerType
");

$categoryDetail = fetchData($conn, "
    SELECT 
        CASE 
            WHEN dp.ReorderPoint <= 200 THEN 'Komponen'
            WHEN dp.ReorderPoint <= 500 THEN 'Aksesoris'
            ELSE 'Produk Jadi' 
        END AS category,
        dc.CustomerType AS segment,
        dp.ProductName AS product,
        SUM(fs.OrderQuantity) AS qty
    FROM factsales fs
    JOIN dimproduct dp ON fs.ProductKey = dp.ProductKey
    JOIN dimcustomer dc ON fs.CustomerKey = dc.CustomerKey
    JOIN dimdate dd ON fs.DateKey = dd.DateKey
    WHERE dd.Year = $maxYear
    GROUP BY category, segment, dp.ProductName
    ORDER BY qty DESC
    LIMIT 100
");
?>
<main>
    <div class="container-fluid px-4">
        <h1 class="mt-4">Skenario 2 - Customer & Frequency</h1>
        <ol class="breadcrumb mb-4">
            <li class="breadcrumb-item active">Menjawab: Kategori produk apa yang memiliki tingkat order frequency tertinggi di segmen pelanggan Individual dibandingkan Reseller?</li>
        </ol>
        <div class="row mb-4">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">Order frequency per segmen</div>
                    <div class="card-body">
                        <canvas id="categoryStacked"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header">Distribusi total order per segmen</div>
                    <div class="card-body">
                        <canvas id="segmentPie"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header">Detail kategori (klik bar untuk cross-filter)</div>
            <div class="card-body table-responsive">
                <table class="table table-bordered" id="categoryTable">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Produk</th>
                            <th>Qty</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</main>
<script>
const categoryFrequency = <?php echo json_encode($categoryFrequency); ?>;
const segmentTotals = <?php echo json_encode($segmentTotals); ?>;
const categoryDetail = <?php echo json_encode($categoryDetail); ?>;

const categories = [...new Set(categoryFrequency.map(item => item.category))];
const segments = ['Individual', 'Reseller'];

const stackedDatasets = segments.map(segment => ({
    label: segment,
    data: categories.map(cat => {
        const row = categoryFrequency.find(item => item.segment === segment && item.category === cat);
        return row ? Number(row.frequency) : 0;
    }),
    backgroundColor: segment === 'Individual' ? 'rgba(54, 162, 235, 0.7)' : 'rgba(255, 99, 132, 0.7)'
}));

const categoryStacked = new Chart(document.getElementById('categoryStacked'), {
    type: 'bar',
    data: { labels: categories, datasets: stackedDatasets },
    options: {
        scales: { xAxes: [{ stacked: true }], yAxes: [{ stacked: true, ticks: { beginAtZero: true } }] },
        onClick: (_, elements) => {
            if (elements.length > 0) {
                const category = categories[elements[0]._index];
                renderCategoryTable(category);
            }
        }
    }
});

const segmentPie = new Chart(document.getElementById('segmentPie'), {
    type: 'doughnut',
    data: {
        labels: segmentTotals.map(item => item.segment),
        datasets: [{
            data: segmentTotals.map(item => item.total_qty),
            backgroundColor: ['rgba(54, 162, 235, 0.7)', 'rgba(255, 99, 132, 0.7)']
        }]
    },
    options: {
        onClick: (_, elements) => {
            if (elements.length > 0) {
                const segment = segmentTotals[elements[0]._index].segment;
                renderCategoryTable(null, segment);
            }
        }
    }
});

const tableBody = document.querySelector('#categoryTable tbody');
function renderCategoryTable(categoryFilter = null, segmentFilter = null) {
    const filtered = categoryDetail.filter(row => {
        const matchesCategory = !categoryFilter || row.category === categoryFilter;
        const matchesSegment = !segmentFilter || row.segment === segmentFilter;
        return matchesCategory && matchesSegment;
    });
    const rows = filtered.map(row => `<tr><td>${row.category} - ${row.segment}</td><td>${row.product}</td><td>${Number(row.qty).toLocaleString()}</td></tr>`).join('');
    tableBody.innerHTML = rows || '<tr><td colspan="3" class="text-center">Tidak ada data</td></tr>';
}

renderCategoryTable();
</script>
