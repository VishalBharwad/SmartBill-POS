<?php
require_once __DIR__ . '/auth.php';
requireAdmin();

require_once __DIR__ . '/config/database.php';

// Default report range: first day of this month through today.
$today = date('Y-m-d');
$firstDay = date('Y-m-01');
$startDate = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) ? $_GET['start_date'] : $firstDay;
$endDate = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) ? $_GET['end_date'] : $today;
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}
$from = $startDate . ' 00:00:00';
$to = $endDate . ' 23:59:59';

function money($amount): string {
    return '₹' . number_format((float)$amount, 2);
}
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Detect optional payment_method column so this page works with older database schemas too.
$hasPaymentMethod = false;
try {
    $columns = $pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN);
    $hasPaymentMethod = in_array('payment_method', $columns, true);
} catch (Throwable $e) {
    $hasPaymentMethod = false;
}

$params = [':from' => $from, ':to' => $to];
$summarySql = "SELECT COUNT(*) AS invoice_count,
                      COALESCE(SUM(subtotal), 0) AS gross_sales,
                      COALESCE(SUM(discount_amount), 0) AS total_discounts,
                      COALESCE(SUM(total_amount), 0) AS net_sales
               FROM invoices WHERE created_at BETWEEN :from AND :to";
$stmt = $pdo->prepare($summarySql);
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['invoice_count'=>0,'gross_sales'=>0,'total_discounts'=>0,'net_sales'=>0];

// Profit is estimated using each product's current cost_price (not a historical cost snapshot).
$costSql = "SELECT COALESCE(SUM(ii.quantity * COALESCE(p.cost_price, 0)), 0)
            FROM invoice_items ii
            INNER JOIN invoices i ON i.id = ii.invoice_id
            LEFT JOIN products p ON p.id = ii.product_id
            WHERE i.created_at BETWEEN :from AND :to";
$stmt = $pdo->prepare($costSql);
$stmt->execute($params);
$estimatedCost = (float)$stmt->fetchColumn();
$estimatedProfit = (float)$summary['net_sales'] - $estimatedCost;

$stmt = $pdo->prepare("SELECT i.id, i.invoice_number, i.created_at, i.total_amount, i.subtotal,
                              i.discount_amount, c.name AS customer_name" .
                      ($hasPaymentMethod ? ", i.payment_method" : ", NULL AS payment_method") . "
                       FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                       WHERE i.created_at BETWEEN :from AND :to
                       ORDER BY i.created_at DESC, i.id DESC");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Export the filtered transaction list as CSV for Excel.
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'sales-report-' . $startDate . '-to-' . $endDate . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    $headers = ['Invoice Number', 'Date', 'Customer'];
    if ($hasPaymentMethod) $headers[] = 'Payment Method';
    array_push($headers, 'Subtotal', 'Discount', 'Net Total');
    fputcsv($output, $headers);
    foreach ($invoices as $invoice) {
        $row = [$invoice['invoice_number'], $invoice['created_at'], $invoice['customer_name'] ?: 'Walk-in Customer'];
        if ($hasPaymentMethod) $row[] = $invoice['payment_method'] ?: 'Not specified';
        array_push($row, $invoice['subtotal'], $invoice['discount_amount'], $invoice['total_amount']);
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

$stmt = $pdo->prepare("SELECT ii.product_name, COALESCE(ii.brand_name, '') AS brand_name,
                              SUM(ii.quantity) AS units_sold, SUM(ii.line_total) AS sales_total
                       FROM invoice_items ii INNER JOIN invoices i ON i.id = ii.invoice_id
                       WHERE i.created_at BETWEEN :from AND :to
                       GROUP BY ii.product_id, ii.product_name, ii.brand_name
                       ORDER BY units_sold DESC, sales_total DESC LIMIT 5");
$stmt->execute($params);
$topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT COALESCE(c.name, 'Walk-in Customer') AS customer_name,
                              COUNT(i.id) AS invoice_count, SUM(i.total_amount) AS sales_total
                       FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                       WHERE i.created_at BETWEEN :from AND :to
                       GROUP BY i.customer_id, c.name
                       ORDER BY sales_total DESC LIMIT 5");
$stmt->execute($params);
$topCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT COALESCE(c.name, 'Walk-in Customer') AS customer_name,
                              COUNT(i.id) AS invoice_count,
                              COALESCE(SUM(i.subtotal), 0) AS gross_sales,
                              COALESCE(SUM(i.discount_amount), 0) AS discounts,
                              COALESCE(SUM(i.total_amount), 0) AS net_sales,
                              MAX(i.created_at) AS last_purchase
                       FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                       WHERE i.created_at BETWEEN :from AND :to
                       GROUP BY i.customer_id, c.name
                       ORDER BY net_sales DESC, invoice_count DESC");
$stmt->execute($params);
$customerReport = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT DATE(created_at) AS sale_date, COUNT(*) AS invoice_count,
                              COALESCE(SUM(total_amount), 0) AS sales_total
                       FROM invoices WHERE created_at BETWEEN :from AND :to
                       GROUP BY DATE(created_at) ORDER BY sale_date DESC");
$stmt->execute($params);
$dailySales = $stmt->fetchAll(PDO::FETCH_ASSOC);

$paymentSummary = [];
if ($hasPaymentMethod) {
    $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(payment_method, ''), 'Not specified') AS payment_method,
                                  COUNT(*) AS invoice_count, COALESCE(SUM(total_amount), 0) AS sales_total
                           FROM invoices WHERE created_at BETWEEN :from AND :to
                           GROUP BY COALESCE(NULLIF(payment_method, ''), 'Not specified')
                           ORDER BY sales_total DESC");
    $stmt->execute($params);
    $paymentSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report | Brainbean POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .report-header{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:24px}
        .report-title{font-weight:750;margin:0;color:#172033}.report-subtitle{color:#778197;margin:5px 0 0}
        .filter-card,.report-card,.metric-card{background:#fff;border:1px solid #e9edf4;border-radius:15px;box-shadow:0 5px 18px rgba(30,45,75,.04)}
        .filter-card,.report-card{padding:20px;margin-bottom:22px}.metric-card{padding:20px;height:100%;position:relative;overflow:hidden}
        .metric-label{font-size:.86rem;color:#778197;margin-bottom:8px}.metric-value{font-size:1.55rem;font-weight:750;color:#172033;overflow-wrap:anywhere}
        .metric-icon{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px;background:#edf3ff;color:#315bd6;font-size:1.2rem;margin-bottom:15px}
        .section-title{font-size:1.05rem;font-weight:700;margin:0 0 16px;color:#172033}.table thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:#6b7588;background:#f7f9fc;white-space:nowrap}
        .table td{vertical-align:middle}.empty-state{padding:25px;text-align:center;color:#7b8495}.report-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}
        @media(max-width:900px){.report-grid{grid-template-columns:1fr}}
        @media print{ @page{size:A4 landscape;margin:12mm} body{background:#fff!important;font-size:10pt}.sidebar,.main-sidebar,nav,.no-print,footer{display:none!important}.main-content{margin:0!important;padding:0!important;width:100%!important}.metric-card,.filter-card,.report-card{box-shadow:none!important;break-inside:avoid;border:1px solid #ddd!important}.report-grid{grid-template-columns:1fr 1fr}.table{font-size:8.5pt}.report-title{font-size:20pt}.report-card{padding:12px!important;margin-bottom:12px!important} }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main class="main-content">
    <div class="topbar d-flex justify-content-between align-items-center mb-4">
        <div><span class="text-muted">Reports</span> <i class="bi bi-chevron-right small mx-1"></i> <strong>Sales Report</strong></div>
        <div class="text-muted small"><i class="bi bi-calendar3 me-1"></i><?= h(date('d M Y')) ?></div>
    </div>

    <div class="report-header">
        <div><h1 class="report-title">Sales Report</h1><p class="report-subtitle">Complete sales, customer-wise, and payment-method reports in one place.</p></div>
        <button class="btn btn-outline-primary no-print" type="button" onclick="window.print()"><i class="bi bi-printer me-2"></i>Print Report</button>
    </div>

    <form method="get" class="filter-card no-print">
        <div class="row g-3 align-items-end">
            <div class="col-sm-5 col-lg-4"><label for="start_date" class="form-label fw-semibold">From date</label><input type="date" class="form-control" id="start_date" name="start_date" value="<?= h($startDate) ?>" required></div>
            <div class="col-sm-5 col-lg-4"><label for="end_date" class="form-label fw-semibold">To date</label><input type="date" class="form-control" id="end_date" name="end_date" value="<?= h($endDate) ?>" required></div>
            <div class="col-sm-2 col-lg-4 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button><a class="btn btn-light border" href="sales_report.php" title="Reset dates"><i class="bi bi-arrow-counterclockwise"></i></a></div>
            <div class="col-12 d-flex flex-wrap gap-2 align-items-center"><span class="small text-muted me-1">Quick range:</span><button type="button" class="btn btn-sm btn-outline-secondary" onclick="setReportRange('today')">Today</button><button type="button" class="btn btn-sm btn-outline-secondary" onclick="setReportRange('week')">Last 7 days</button><button type="button" class="btn btn-sm btn-outline-secondary" onclick="setReportRange('month')">This month</button><a class="btn btn-sm btn-outline-success ms-md-auto" href="?start_date=<?= h($startDate) ?>&amp;end_date=<?= h($endDate) ?>&amp;export=csv"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV</a></div>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><div class="metric-card"><div class="metric-icon"><i class="bi bi-receipt"></i></div><div class="metric-label">Total Invoices</div><div class="metric-value"><?= number_format((int)$summary['invoice_count']) ?></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="metric-card"><div class="metric-icon"><i class="bi bi-graph-up-arrow"></i></div><div class="metric-label">Gross Sales</div><div class="metric-value"><?= money($summary['gross_sales']) ?></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="metric-card"><div class="metric-icon"><i class="bi bi-tags"></i></div><div class="metric-label">Total Discounts</div><div class="metric-value"><?= money($summary['total_discounts']) ?></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="metric-card"><div class="metric-icon"><i class="bi bi-currency-rupee"></i></div><div class="metric-label">Net Sales</div><div class="metric-value"><?= money($summary['net_sales']) ?></div></div></div>
        <div class="col-sm-6 col-xl-6"><div class="metric-card"><div class="metric-icon"><i class="bi bi-box-seam"></i></div><div class="metric-label">Estimated Cost of Goods</div><div class="metric-value"><?= money($estimatedCost) ?></div><div class="small text-muted mt-2">Uses current product cost prices.</div></div></div>
        <div class="col-sm-6 col-xl-6"><div class="metric-card"><div class="metric-icon"><i class="bi bi-wallet2"></i></div><div class="metric-label">Estimated Profit</div><div class="metric-value <?= $estimatedProfit < 0 ? 'text-danger' : 'text-success' ?>"><?= money($estimatedProfit) ?></div><div class="small text-muted mt-2">Net sales minus estimated product cost.</div></div></div>
    </div>

    <div class="report-grid">
        <section class="report-card"><h2 class="section-title"><i class="bi bi-trophy me-2 text-warning"></i>Top-Selling Products</h2>
            <?php if (!$topProducts): ?><div class="empty-state">No product sales found for this date range.</div><?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Product</th><th>Units Sold</th><th class="text-end">Sales</th></tr></thead><tbody>
            <?php foreach ($topProducts as $product): ?><tr><td><div class="fw-semibold"><?= h($product['product_name']) ?></div><?php if ($product['brand_name'] !== ''): ?><small class="text-muted"><?= h($product['brand_name']) ?></small><?php endif; ?></td><td><?= number_format((float)$product['units_sold']) ?></td><td class="text-end fw-semibold"><?= money($product['sales_total']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
        <section class="report-card"><h2 class="section-title"><i class="bi bi-people me-2 text-primary"></i>Top Customers</h2>
            <?php if (!$topCustomers): ?><div class="empty-state">No customer sales found for this date range.</div><?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Customer</th><th>Invoices</th><th class="text-end">Sales</th></tr></thead><tbody>
            <?php foreach ($topCustomers as $customer): ?><tr><td><?= h($customer['customer_name']) ?></td><td><?= number_format((int)$customer['invoice_count']) ?></td><td class="text-end fw-semibold"><?= money($customer['sales_total']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
        <section class="report-card"><h2 class="section-title"><i class="bi bi-calendar-week me-2 text-primary"></i>Daily Sales</h2>
            <?php if (!$dailySales): ?><div class="empty-state">No sales found for this date range.</div><?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Date</th><th>Invoices</th><th class="text-end">Net Sales</th></tr></thead><tbody>
            <?php foreach ($dailySales as $day): ?><tr><td><?= h(date('d M Y', strtotime($day['sale_date']))) ?></td><td><?= number_format((int)$day['invoice_count']) ?></td><td class="text-end fw-semibold"><?= money($day['sales_total']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
        <?php if ($hasPaymentMethod): ?><section class="report-card"><h2 class="section-title"><i class="bi bi-credit-card me-2 text-primary"></i>Payment Method Report</h2>
            <?php if (!$paymentSummary): ?><div class="empty-state">No payment data found for this date range.</div><?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Method</th><th>Invoices</th><th>Share of Sales</th><th class="text-end">Sales</th></tr></thead><tbody>
            <?php foreach ($paymentSummary as $payment): $paymentShare = (float)$summary['net_sales'] > 0 ? ((float)$payment['sales_total'] / (float)$summary['net_sales'] * 100) : 0; ?><tr><td><?= h($payment['payment_method']) ?></td><td><?= number_format((int)$payment['invoice_count']) ?></td><td><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:8px;min-width:65px"><div class="progress-bar" style="width:<?= min(100, max(0, $paymentShare)) ?>%"></div></div><small><?= number_format($paymentShare, 1) ?>%</small></div></td><td class="text-end fw-semibold"><?= money($payment['sales_total']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section><?php endif; ?>
    </div>

    <section class="report-card mt-4"><h2 class="section-title"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Customer-wise Sales Report</h2>
        <?php if (!$customerReport): ?><div class="empty-state">No customer sales found for this date range.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Customer</th><th>Invoices</th><th class="text-end">Gross Sales</th><th class="text-end">Discounts</th><th class="text-end">Net Sales</th><th>Last Purchase</th></tr></thead><tbody>
        <?php foreach ($customerReport as $customer): ?><tr><td class="fw-semibold"><?= h($customer['customer_name']) ?></td><td><?= number_format((int)$customer['invoice_count']) ?></td><td class="text-end"><?= money($customer['gross_sales']) ?></td><td class="text-end"><?= money($customer['discounts']) ?></td><td class="text-end fw-bold"><?= money($customer['net_sales']) ?></td><td><?= $customer['last_purchase'] ? h(date('d M Y, h:i A', strtotime($customer['last_purchase']))) : '—' ?></td></tr><?php endforeach; ?>
        </tbody><tfoot><tr class="fw-bold"><td>TOTAL</td><td><?= number_format(array_sum(array_column($customerReport, 'invoice_count'))) ?></td><td class="text-end"><?= money(array_sum(array_column($customerReport, 'gross_sales'))) ?></td><td class="text-end"><?= money(array_sum(array_column($customerReport, 'discounts'))) ?></td><td class="text-end"><?= money(array_sum(array_column($customerReport, 'net_sales'))) ?></td><td></td></tr></tfoot></table></div><?php endif; ?>
    </section>

    <section class="report-card mt-4"><h2 class="section-title"><i class="bi bi-list-check me-2 text-primary"></i>Sales Transactions</h2>
        <?php if (!$invoices): ?><div class="empty-state">No invoices were created during this date range.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Invoice #</th><th>Date &amp; Time</th><th>Customer</th><?php if ($hasPaymentMethod): ?><th>Payment</th><?php endif; ?><th class="text-end">Subtotal</th><th class="text-end">Discount</th><th class="text-end">Net Total</th></tr></thead><tbody>
        <?php foreach ($invoices as $invoice): ?><tr><td class="fw-semibold"><?= h($invoice['invoice_number']) ?></td><td><?= h(date('d M Y, h:i A', strtotime($invoice['created_at']))) ?></td><td><?= h($invoice['customer_name'] ?: 'Walk-in Customer') ?></td><?php if ($hasPaymentMethod): ?><td><?= h($invoice['payment_method'] ?: 'Not specified') ?></td><?php endif; ?><td class="text-end"><?= money($invoice['subtotal']) ?></td><td class="text-end"><?= money($invoice['discount_amount']) ?></td><td class="text-end fw-bold"><?= money($invoice['total_amount']) ?></td></tr><?php endforeach; ?>
        </tbody><tfoot><tr class="fw-bold"><td colspan="<?= $hasPaymentMethod ? 4 : 3 ?>">TOTAL (<?= number_format(count($invoices)) ?> invoices)</td><td class="text-end"><?= money(array_sum(array_column($invoices, 'subtotal'))) ?></td><td class="text-end"><?= money(array_sum(array_column($invoices, 'discount_amount'))) ?></td><td class="text-end"><?= money(array_sum(array_column($invoices, 'total_amount'))) ?></td></tr></tfoot></table></div><?php endif; ?>
    </section>
    <div class="text-center small text-muted py-3">Report period: <?= h(date('d M Y', strtotime($startDate))) ?> – <?= h(date('d M Y', strtotime($endDate))) ?></div>
</main>
<script>
function setReportRange(range) {
    const today = new Date();
    const end = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    let start = new Date(end);
    if (range === 'week') start.setDate(start.getDate() - 6);
    if (range === 'month') start = new Date(end.getFullYear(), end.getMonth(), 1);
    const fmt = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    document.getElementById('start_date').value = fmt(start);
    document.getElementById('end_date').value = fmt(end);
    document.querySelector('form.filter-card').requestSubmit();
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
