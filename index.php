<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
?>
<?php
require_once __DIR__ . "/config/database.php";

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($value): string { return '₹' . number_format((float)$value, 2); }

$productCount = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$invoiceCount = (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$totalSales = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices")->fetchColumn();
$todaySales = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$todayInvoices = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE DATE(created_at) = CURDATE()")->fetchColumn();

$recentInvoices = $pdo->query(
    "SELECT i.invoice_number, c.name AS customer_name, i.total_amount, i.created_at
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     ORDER BY i.id DESC LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

$weeklySales = $pdo->query(
    "SELECT DATE(created_at) AS sale_date, COALESCE(SUM(total_amount), 0) AS daily_sales
     FROM invoices
     WHERE created_at >= CURDATE() - INTERVAL 6 DAY
       AND created_at < CURDATE() + INTERVAL 1 DAY
     GROUP BY DATE(created_at) ORDER BY sale_date"
)->fetchAll(PDO::FETCH_ASSOC);
$salesByDate = [];
foreach ($weeklySales as $sale) $salesByDate[$sale['sale_date']] = (float)$sale['daily_sales'];
$chartLabels = $chartValues = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D', strtotime($date));
    $chartValues[] = $salesByDate[$date] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | Brainbean POS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--dash-primary:#5548e8;--dash-text:#172033;--dash-muted:#7b8498;--dash-border:#edf0f5}
body{background:#f5f7fb;color:var(--dash-text);font-family:Inter,"Segoe UI",Arial,sans-serif}
/* Sidebar handles the desktop body offset; do not add another margin here. */
.main-content{width:100%;max-width:none;min-width:0;margin:0;padding:28px}
.dashboard-header{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:26px}
.page-title{font-size:29px;font-weight:800;letter-spacing:-.7px;margin:0 0 6px;color:var(--dash-text)}
.muted{font-size:13px;color:var(--dash-muted)}
.welcome-label{display:inline-flex;align-items:center;gap:7px;background:#eeedff;color:var(--dash-primary);padding:6px 12px;border-radius:30px;font-size:11px;font-weight:800;letter-spacing:.5px;margin-bottom:10px}
.admin-profile{display:flex;align-items:center;gap:11px;background:#fff;border:1px solid var(--dash-border);border-radius:14px;padding:10px 14px}
.admin-avatar{width:42px;height:42px;display:grid;place-items:center;border-radius:12px;background:#eeedff;color:var(--dash-primary);font-size:20px}
.dashboard-card,.section-card{height:100%;background:#fff;border:1px solid var(--dash-border);border-radius:17px;box-shadow:0 4px 18px rgba(20,31,56,.035);overflow:hidden}
.stat-card{padding:22px;transition:transform .18s ease,box-shadow .18s ease}.stat-card:hover{transform:translateY(-2px);box-shadow:0 9px 24px rgba(20,31,56,.07)}
.stat-heading{display:flex;justify-content:space-between;align-items:center;gap:10px}.stat-label{font-size:13px;color:var(--dash-muted);font-weight:650}
.stat-icon,.quick-icon{width:46px;height:46px;display:grid;place-items:center;border-radius:13px;font-size:21px;flex-shrink:0}
.purple{background:#eeedff;color:#5b50e8}.green{background:#e4f9ef;color:#169b62}.orange{background:#fff0df;color:#ed8b26}.blue{background:#e4f2ff;color:#2785db}
.stat-value{font-size:clamp(22px,2vw,30px);font-weight:800;letter-spacing:-.6px;margin:18px 0 7px;overflow-wrap:anywhere}.stat-description{font-size:12px;color:var(--dash-muted)}
.section-header{padding:20px 23px;border-bottom:1px solid var(--dash-border);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.section-title{font-size:16px;font-weight:800;margin:0 0 4px}.section-subtitle{font-size:12px;color:var(--dash-muted);margin:0}.section-body{padding:22px}
.btn-dashboard{display:inline-flex;align-items:center;justify-content:center;gap:7px;background:var(--dash-primary);color:#fff;border:0;border-radius:10px;padding:10px 14px;font-size:12px;font-weight:700;text-decoration:none}.btn-dashboard:hover{background:#4038ca;color:#fff}
.btn-soft{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--dash-border);border-radius:9px;background:#f8f9fc;color:#4b5568;padding:8px 11px;font-size:11px;font-weight:700}
.chart-container{height:270px;position:relative}.quick-action{display:flex;align-items:center;gap:12px;padding:13px;border:1px solid var(--dash-border);border-radius:13px;text-decoration:none;color:var(--dash-text);transition:.18s ease}.quick-action:hover{border-color:#c8c4ff;background:#faf9ff;color:var(--dash-primary);transform:translateY(-1px)}
.quick-action-title{font-size:12px;font-weight:800;margin-bottom:3px}.quick-action-description{font-size:11px;color:var(--dash-muted)}
.table{margin:0;vertical-align:middle}.table thead th{background:#fafbfe;color:var(--dash-muted);font-size:10px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;padding:14px 17px;border-bottom:1px solid var(--dash-border);white-space:nowrap}.table tbody td{padding:15px 17px;font-size:12px;border-bottom:1px solid #f0f2f7}.table tbody tr:last-child td{border-bottom:0}
.invoice-icon{width:34px;height:34px;display:inline-grid;place-items:center;border-radius:10px;background:#eeedff;color:var(--dash-primary);margin-right:8px}.invoice-number{font-weight:750}.amount{font-weight:800;white-space:nowrap}.empty-state{text-align:center;padding:38px 18px!important;color:var(--dash-muted)}.empty-state i{display:block;font-size:32px;color:#b3b9c8;margin-bottom:10px}.dashboard-footer{text-align:center;color:var(--dash-muted);font-size:11px;padding:22px 0 4px}
@media(max-width:900px){.main-content{padding:72px 20px 24px}}
@media(max-width:576px){.main-content{padding:72px 14px 18px}.page-title{font-size:25px}.section-header,.section-body{padding:16px}.stat-card{padding:17px}.chart-container{height:225px}}
</style>
</head>
<body>
<?php require_once __DIR__ . "/includes/sidebar.php"; ?>
<main class="main-content">
<header class="dashboard-header">
  <div><div class="welcome-label"><i class="bi bi-stars"></i> ADMIN OVERVIEW</div><h1 class="page-title">Dashboard</h1><p class="muted mb-0">Welcome back! Here's what's happening with your store.</p></div>
  <div class="admin-profile"><div class="admin-avatar"><i class="bi bi-person-fill"></i></div><div><div class="fw-bold">Admin</div><div class="muted">Administrator</div></div></div>
</header>
<div class="row g-3 g-xl-4 mb-4">
  <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-card stat-card"><div class="stat-heading"><span class="stat-label">Total Products</span><span class="stat-icon purple"><i class="bi bi-box-seam"></i></span></div><div class="stat-value"><?=number_format($productCount)?></div><div class="stat-description"><i class="bi bi-check-circle-fill text-success me-1"></i>Products in inventory</div></div></div>
  <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-card stat-card"><div class="stat-heading"><span class="stat-label">Total Customers</span><span class="stat-icon green"><i class="bi bi-people"></i></span></div><div class="stat-value"><?=number_format($totalCustomers)?></div><div class="stat-description"><i class="bi bi-person-check-fill text-success me-1"></i>Registered customers</div></div></div>
  <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-card stat-card"><div class="stat-heading"><span class="stat-label">Total Invoices</span><span class="stat-icon orange"><i class="bi bi-receipt"></i></span></div><div class="stat-value"><?=number_format($invoiceCount)?></div><div class="stat-description"><i class="bi bi-calendar-check text-warning me-1"></i><?=number_format($todayInvoices)?> created today</div></div></div>
  <div class="col-12 col-sm-6 col-xl-3"><div class="dashboard-card stat-card"><div class="stat-heading"><span class="stat-label">Total Sales</span><span class="stat-icon blue"><i class="bi bi-currency-rupee"></i></span></div><div class="stat-value"><?=money($totalSales)?></div><div class="stat-description"><i class="bi bi-graph-up-arrow text-success me-1"></i><?=money($todaySales)?> sales today</div></div></div>
</div>
<div class="row g-3 g-xl-4 mb-4">
 <div class="col-12 col-xl-8"><section class="section-card"><div class="section-header"><div><h2 class="section-title">Sales Overview</h2><p class="section-subtitle">Daily invoice sales for the last 7 days</p></div><span class="btn-soft"><i class="bi bi-calendar3"></i> Last 7 Days</span></div><div class="section-body"><div class="chart-container"><canvas id="salesChart"></canvas></div></div></section></div>
 <div class="col-12 col-xl-4"><section class="section-card"><div class="section-header"><div><h2 class="section-title">Quick Actions</h2><p class="section-subtitle">Common store management tasks</p></div></div><div class="section-body"><div class="d-grid gap-3">
 <a href="pos.php" class="quick-action"><span class="quick-icon purple"><i class="bi bi-cart-plus"></i></span><span><span class="quick-action-title d-block">Create New Sale</span><span class="quick-action-description">Open POS and generate an invoice</span></span><i class="bi bi-chevron-right ms-auto"></i></a>
 <a href="products.php" class="quick-action"><span class="quick-icon green"><i class="bi bi-box-seam"></i></span><span><span class="quick-action-title d-block">Manage Products</span><span class="quick-action-description">View and manage inventory</span></span><i class="bi bi-chevron-right ms-auto"></i></a>
 <a href="customers.php" class="quick-action"><span class="quick-icon orange"><i class="bi bi-person-plus"></i></span><span><span class="quick-action-title d-block">Manage Customers</span><span class="quick-action-description">View registered customers</span></span><i class="bi bi-chevron-right ms-auto"></i></a>
 </div></div></section></div>
</div>
<section class="section-card"><div class="section-header"><div><h2 class="section-title">Recent Invoices</h2><p class="section-subtitle">Your five most recently created invoices</p></div><span class="btn-soft"><i class="bi bi-receipt"></i> <?=number_format($invoiceCount)?> Total</span></div><div class="table-responsive"><table class="table table-hover"><thead><tr><th>Invoice</th><th>Customer</th><th>Date &amp; Time</th><th class="text-end">Amount</th></tr></thead><tbody>
<?php if (!$recentInvoices): ?><tr><td colspan="4" class="empty-state"><i class="bi bi-receipt-cutoff"></i><div class="fw-bold mb-1">No invoices yet</div><div class="small mb-3">Your recent sales will appear here.</div><a href="pos.php" class="btn-dashboard"><i class="bi bi-plus-lg"></i>Create First Sale</a></td></tr>
<?php else: foreach ($recentInvoices as $invoice): ?><tr><td><span class="invoice-icon"><i class="bi bi-receipt"></i></span><span class="invoice-number"><?=e($invoice['invoice_number'] ?? '—')?></span></td><td><?=e($invoice['customer_name'] ?? 'Walk-in Customer')?></td><td><?=!empty($invoice['created_at']) ? e(date('d M Y, h:i A', strtotime($invoice['created_at']))) : '—'?></td><td class="text-end"><span class="amount"><?=money($invoice['total_amount'] ?? 0)?></span></td></tr><?php endforeach; endif; ?>
</tbody></table></div></section>
<footer class="dashboard-footer">Brainbean POS &amp; Inventory Management <span class="mx-1">•</span> Dashboard</footer>
</main>
<script>
const chartLabels = <?=json_encode($chartLabels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;
const chartValues = <?=json_encode($chartValues, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;
const canvas = document.getElementById('salesChart');
if (canvas && window.Chart) new Chart(canvas, {
 type:'line', data:{labels:chartLabels,datasets:[{label:'Sales',data:chartValues,borderColor:'#5b50e8',backgroundColor:'rgba(91,80,232,.10)',borderWidth:3,fill:true,tension:.38,pointRadius:4,pointHoverRadius:6,pointBackgroundColor:'#5b50e8',pointBorderColor:'#fff',pointBorderWidth:2}]},
 options:{responsive:true,maintainAspectRatio:false,interaction:{intersect:false,mode:'index'},plugins:{legend:{display:false},tooltip:{callbacks:{label:(ctx)=>' Sales: ₹'+Number(ctx.parsed.y).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})}}},scales:{x:{grid:{display:false},border:{display:false},ticks:{color:'#7b8498'}},y:{beginAtZero:true,border:{display:false},grid:{color:'#edf0f5'},ticks:{color:'#7b8498',callback:(value)=>'₹'+Number(value).toLocaleString('en-IN')}}}}
});
</script>
</body>
</html>
