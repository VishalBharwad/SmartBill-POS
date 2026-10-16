<?php
require_once __DIR__ . '/auth.php';
requireAdmin();

require_once __DIR__ . '/config/database.php';

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function hasColumn(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function money($value): string {
    return '₹' . number_format((float)$value, 2);
}

$search = trim($_GET['search'] ?? '');
$paymentFilter = trim($_GET['payment_method'] ?? '');
$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');
$viewId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT);
$message = '';

$hasPaymentMethod = hasColumn($pdo, 'invoices', 'payment_method');

$dateColumn = null;
foreach (['created_at', 'invoice_date', 'created_on', 'date_created'] as $column) {
    if (hasColumn($pdo, 'invoices', $column)) {
        $dateColumn = $column;
        break;
    }
}

$dateSelect = $dateColumn
    ? "i.`$dateColumn` AS invoice_date"
    : "NULL AS invoice_date";

$paymentSelect = $hasPaymentMethod
    ? 'i.payment_method'
    : "'Not recorded' AS payment_method";

/* Build filters once for the list, summary and CSV export. */
$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = '(i.invoice_number LIKE ? OR c.name LIKE ? OR CAST(i.id AS CHAR) LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}

if ($hasPaymentMethod && $paymentFilter !== '') {
    $conditions[] = 'i.payment_method = ?';
    $params[] = $paymentFilter;
}

if ($dateColumn && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $conditions[] = "DATE(i.`$dateColumn`) >= ?";
    $params[] = $fromDate;
}

if ($dateColumn && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $conditions[] = "DATE(i.`$dateColumn`) <= ?";
    $params[] = $toDate;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

/* Invoice details */
$selectedInvoice = null;
$invoiceItems = [];

if ($viewId && $viewId > 0) {
    $stmt = $pdo->prepare(
        "SELECT i.id, i.invoice_number, i.subtotal,
                i.discount_amount, i.total_amount,
                $paymentSelect, $dateSelect,
                c.name AS customer_name, c.phone AS customer_phone
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         WHERE i.id = ?
         LIMIT 1"
    );

    $stmt->execute([$viewId]);
    $selectedInvoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedInvoice) {
        $itemStmt = $pdo->prepare(
            "SELECT product_name, brand_name, quantity,
                    unit_price, line_total
             FROM invoice_items
             WHERE invoice_id = ?
             ORDER BY id ASC"
        );
        $itemStmt->execute([$viewId]);
        $invoiceItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $message = 'The selected invoice was not found.';
    }
}

/* Invoice list */
$listStmt = $pdo->prepare(
    "SELECT i.id, i.invoice_number, i.subtotal,
            i.discount_amount, i.total_amount,
            $paymentSelect, $dateSelect,
            c.name AS customer_name
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     $where
     ORDER BY i.id DESC
     LIMIT 500"
);
$listStmt->execute($params);
$invoices = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/* Summary statistics for all matching invoices */
$summaryStmt = $pdo->prepare(
    "SELECT COUNT(*) AS invoice_count,
            COALESCE(SUM(i.total_amount), 0) AS sales_total,
            COALESCE(AVG(i.total_amount), 0) AS average_sale
     FROM invoices i
     LEFT JOIN customers c ON c.id = i.customer_id
     $where"
);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

/* Payment filter options */
$paymentMethods = [];

if ($hasPaymentMethod) {
    $methodStmt = $pdo->query(
        "SELECT DISTINCT payment_method
         FROM invoices
         WHERE payment_method IS NOT NULL
           AND payment_method <> ''
         ORDER BY payment_method"
    );
    $paymentMethods = $methodStmt->fetchAll(PDO::FETCH_COLUMN);
}

/* Export matching invoices to CSV */
if (($_GET['export'] ?? '') === 'csv') {
    $exportStmt = $pdo->prepare(
        "SELECT i.invoice_number, c.name AS customer_name,
                $dateSelect, $paymentSelect,
                i.subtotal, i.discount_amount, i.total_amount
         FROM invoices i
         LEFT JOIN customers c ON c.id = i.customer_id
         $where
         ORDER BY i.id DESC
         LIMIT 5000"
    );

    $exportStmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="invoice-report-' .
        date('Y-m-d') . '.csv"'
    );

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Invoice Number',
        'Customer',
        'Date',
        'Payment Method',
        'Subtotal',
        'Discount',
        'Total'
    ]);

    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['invoice_number'],
            $row['customer_name'] ?? 'Unknown customer',
            $row['invoice_date'] ?? '',
            $row['payment_method'] ?? 'Not recorded',
            $row['subtotal'],
            $row['discount_amount'],
            $row['total_amount']
        ]);
    }

    fclose($output);
    exit;
}

/* Keep filters when navigating between invoice pages */
$queryParams = [];

if ($search !== '') $queryParams['search'] = $search;
if ($paymentFilter !== '') $queryParams['payment_method'] = $paymentFilter;
if ($fromDate !== '') $queryParams['from_date'] = $fromDate;
if ($toDate !== '') $queryParams['to_date'] = $toDate;

$filterQuery = http_build_query($queryParams);
$exportUrl = 'invoices.php?' .
    ($filterQuery !== '' ? $filterQuery . '&' : '') . 'export=csv';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Invoices | Brainbean Technolabs</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .invoice-heading {
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .invoice-subtitle,
        .muted-small {
            color: #7b8799;
        }

        .muted-small {
            font-size: .82rem;
        }

        .invoice-stat {
            height: 100%;
            padding: 20px;
            background: #fff;
            border: 1px solid #e8edf5;
            border-radius: 16px;
            box-shadow: 0 3px 12px rgba(25,42,70,.035);
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            background: #edf2ff;
            color: #405fc1;
            font-size: 21px;
        }

        .invoice-card,
        .detail-panel {
            background: #fff;
            border: 1px solid #e8edf5;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(25,42,70,.035);
        }

        .detail-panel {
            padding: 24px;
        }

        .invoice-table th {
            padding: 14px 12px;
            background: #f8fafc;
            color: #748094;
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            white-space: nowrap;
        }

        .invoice-table td {
            padding: 15px 12px;
            vertical-align: middle;
        }

        .invoice-number {
            color: #24324a;
            font-weight: 750;
        }

        .payment-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef7f1;
            color: #21734a;
            font-size: .78rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .filter-panel {
            padding: 18px;
            background: #f8fafc;
            border: 1px solid #edf0f5;
            border-radius: 14px;
        }

        .form-control,
        .form-select,
        .btn {
            border-radius: 9px;
        }

        .form-control,
        .form-select {
            min-height: 42px;
        }

        .empty-state {
            padding: 45px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 58px;
            height: 58px;
            margin: 0 auto 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            background: #f0f4ff;
            color: #526bd1;
            font-size: 27px;
        }

        @media (max-width: 767px) {
            .invoice-stat { padding: 15px; }
            .detail-panel { padding: 16px; }
            .invoice-table td { padding: 12px 10px; }
        }

        @media print {
            @page { size: A4; margin: 12mm; }

            body.print-invoice,
            body.print-list {
                background: #fff !important;
                color: #111 !important;
            }

            body.print-invoice .sidebar,
            body.print-invoice .main-sidebar,
            body.print-invoice nav,
            body.print-invoice .no-print,
            body.print-list .sidebar,
            body.print-list .main-sidebar,
            body.print-list nav,
            body.print-list .no-print {
                display: none !important;
            }

            body.print-invoice .main-content,
            body.print-list .main-content {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            body.print-invoice .main-content > *:not(.detail-panel) {
                display: none !important;
            }

            body.print-invoice .detail-panel {
                display: block !important;
                padding: 0 !important;
                border: 0 !important;
                box-shadow: none !important;
            }

            body.print-invoice .detail-panel th,
            body.print-invoice .detail-panel td {
                padding: 8px !important;
                border: 1px solid #ddd !important;
                color: #111 !important;
            }

            body.print-list .invoice-card,
            body.print-list .invoice-stat {
                box-shadow: none !important;
            }
        }
    </style>
</head>

<body>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <div class="muted-small text-uppercase fw-bold mb-1">
                Sales Management
            </div>
            <h1 class="page-title invoice-heading mb-1">Invoices</h1>
            <div class="invoice-subtitle">
                <i class="bi bi-receipt me-1"></i>
                Manage transactions, payments and customer invoices.
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 no-print">
            <button class="btn btn-outline-secondary" onclick="printInvoiceList()">
                <i class="bi bi-printer me-2"></i>Print List
            </button>

            <a href="<?= e($exportUrl) ?>" class="btn btn-outline-success">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i>Export CSV
            </a>

            <a href="pos.php" class="btn btn-primary">
                <i class="bi bi-plus-lg me-2"></i>New Sale
            </a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i><?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedInvoice): ?>
        <section class="detail-panel mb-4">

            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                <div>
                    <div class="muted-small fw-bold mb-1">INVOICE DETAILS</div>
                    <h4 class="fw-bold mb-1"><?= e($selectedInvoice['invoice_number']) ?></h4>
                    <div class="muted-small">
                        <?= !empty($selectedInvoice['invoice_date'])
                            ? e(date('d M Y, h:i A', strtotime($selectedInvoice['invoice_date'])))
                            : 'Date not available' ?>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 no-print">
                    <button class="btn btn-primary" onclick="printInvoice()">
                        <i class="bi bi-printer me-2"></i>Print Invoice
                    </button>

                    <a href="invoices.php<?= $filterQuery !== '' ? '?' . e($filterQuery) : '' ?>"
                       class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back
                    </a>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="p-3 rounded-3 bg-light h-100">
                        <div class="muted-small">Customer</div>
                        <div class="fw-bold"><?= e($selectedInvoice['customer_name'] ?? 'Unknown customer') ?></div>
                        <div class="muted-small"><?= e($selectedInvoice['customer_phone'] ?? '') ?></div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="p-3 rounded-3 bg-light h-100">
                        <div class="muted-small">Payment Method</div>
                        <div class="fw-bold">
                            <i class="bi bi-credit-card me-1"></i>
                            <?= e(ucwords(str_replace('_', ' ', $selectedInvoice['payment_method'] ?? 'Not recorded'))) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="p-3 rounded-3 bg-light h-100">
                        <div class="muted-small">Grand Total</div>
                        <div class="fs-4 fw-bold text-success">
                            <?= money($selectedInvoice['total_amount']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th>Brand</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Line Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$invoiceItems): ?>
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">
                                No invoice items found.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($invoiceItems as $item): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($item['product_name']) ?></td>
                            <td><?= e($item['brand_name'] ?? '—') ?></td>
                            <td class="text-end"><?= (int)$item['quantity'] ?></td>
                            <td class="text-end"><?= money($item['unit_price']) ?></td>
                            <td class="text-end fw-semibold"><?= money($item['line_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="row justify-content-end">
                <div class="col-md-5 col-lg-4">
                    <div class="d-flex justify-content-between py-2">
                        <span class="text-secondary">Subtotal</span>
                        <strong><?= money($selectedInvoice['subtotal']) ?></strong>
                    </div>

                    <div class="d-flex justify-content-between py-2">
                        <span class="text-secondary">Discount</span>
                        <strong class="text-danger">− <?= money($selectedInvoice['discount_amount']) ?></strong>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between">
                        <span class="fw-bold">Grand Total</span>
                        <span class="fs-5 fw-bold text-success"><?= money($selectedInvoice['total_amount']) ?></span>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top d-flex justify-content-between flex-wrap gap-2 muted-small">
                <span>Thank you for your purchase.</span>
                <span>Brainbean Technolabs</span>
            </div>
        </section>
    <?php endif; ?>

    <!-- Dashboard statistics -->
    <div class="row g-3 mb-4">

        <div class="col-sm-6 col-xl-4">
            <div class="invoice-stat">
                <div class="d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <div class="muted-small mb-1">Matching Invoices</div>
                        <div class="fs-3 fw-bold">
                            <?= number_format((int)($summary['invoice_count'] ?? 0)) ?>
                        </div>
                    </div>
                    <div class="stat-icon"><i class="bi bi-receipt-cutoff"></i></div>
                </div>
                <div class="muted-small mt-2">Based on current filters</div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-4">
            <div class="invoice-stat">
                <div class="d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <div class="muted-small mb-1">Total Sales</div>
                        <div class="fs-3 fw-bold text-success">
                            <?= money($summary['sales_total'] ?? 0) ?>
                        </div>
                    </div>
                    <div class="stat-icon" style="background:#eaf7ef;color:#21734a">
                        <i class="bi bi-currency-rupee"></i>
                    </div>
                </div>
                <div class="muted-small mt-2">Matching invoice value</div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-4">
            <div class="invoice-stat">
                <div class="d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <div class="muted-small mb-1">Average Sale</div>
                        <div class="fs-3 fw-bold">
                            <?= money($summary['average_sale'] ?? 0) ?>
                        </div>
                    </div>
                    <div class="stat-icon" style="background:#fff4e5;color:#a75c00">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                </div>
                <div class="muted-small mt-2">
                    <?= $hasPaymentMethod ? 'Payment tracking enabled' : 'Payment method not recorded in database' ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Search and filters -->
    <section class="invoice-card mb-4">

        <div class="p-3 p-md-4 border-bottom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-1">Find Invoices</h5>
                    <div class="muted-small">Search transactions and narrow your results.</div>
                </div>

                <?php if ($filterQuery !== ''): ?>
                    <a href="invoices.php" class="btn btn-sm btn-outline-secondary no-print">
                        <i class="bi bi-x-circle me-1"></i>Clear Filters
                    </a>
                <?php endif; ?>
            </div>

            <form method="GET" action="invoices.php" class="filter-panel no-print">
                <div class="row g-3 align-items-end">

                    <div class="col-lg-4 col-md-6">
                        <label for="search" class="form-label small fw-semibold">Search</label>
                        <input type="search" id="search" name="search"
                               value="<?= e($search) ?>"
                               class="form-control"
                               placeholder="Invoice number, customer or ID">
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <label for="from_date" class="form-label small fw-semibold">From Date</label>
                        <input type="date" id="from_date" name="from_date"
                               value="<?= e($fromDate) ?>"
                               class="form-control" <?= !$dateColumn ? 'disabled' : '' ?>>
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <label for="to_date" class="form-label small fw-semibold">To Date</label>
                        <input type="date" id="to_date" name="to_date"
                               value="<?= e($toDate) ?>"
                               class="form-control" <?= !$dateColumn ? 'disabled' : '' ?>>
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <label for="payment_method" class="form-label small fw-semibold">Payment</label>
                        <select id="payment_method" name="payment_method"
                                class="form-select" <?= !$hasPaymentMethod ? 'disabled' : '' ?>>
                            <option value="">All Methods</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?= e($method) ?>"
                                    <?= $paymentFilter === $method ? 'selected' : '' ?>>
                                    <?= e(ucwords(str_replace('_', ' ', $method))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel me-2"></i>Filter
                        </button>
                    </div>

                </div>

                <?php if (!$dateColumn): ?>
                    <div class="muted-small mt-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Date filtering is unavailable because no supported invoice date column was found.
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Invoice history -->
        <div class="p-3 p-md-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold mb-1">Sales History</h5>
                <div class="muted-small">
                    Showing <?= count($invoices) ?> invoices, up to 500 latest matching records.
                </div>
            </div>
            <span class="muted-small"><i class="bi bi-shield-check me-1"></i>Invoice Records</span>
        </div>

        <div class="table-responsive">
            <table class="table invoice-table mb-0" id="invoiceList">
                <thead>
                <tr>
                    <th class="ps-3 ps-md-4">Invoice</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Payment</th>
                    <th class="text-end">Subtotal</th>
                    <th class="text-end">Discount</th>
                    <th class="text-end">Total</th>
                    <th class="text-end pe-3 pe-md-4 no-print">Action</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!$invoices): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-receipt"></i></div>
                                <div class="fw-bold mb-1">No invoices found</div>
                                <div class="muted-small mb-3">
                                    Change your filters or create a new sale.
                                </div>
                                <a href="pos.php" class="btn btn-primary btn-sm no-print">
                                    <i class="bi bi-plus-lg me-1"></i>Create Sale
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td class="ps-3 ps-md-4">
                            <div class="invoice-number"><?= e($invoice['invoice_number']) ?></div>
                            <div class="muted-small">ID #<?= (int)$invoice['id'] ?></div>
                        </td>

                        <td class="fw-semibold">
                            <?= e($invoice['customer_name'] ?? 'Unknown customer') ?>
                        </td>

                        <td class="text-nowrap">
                            <?= !empty($invoice['invoice_date'])
                                ? e(date('d M Y, h:i A', strtotime($invoice['invoice_date'])))
                                : '—' ?>
                        </td>

                        <td>
                            <span class="payment-pill">
                                <i class="bi bi-credit-card"></i>
                                <?= e(ucwords(str_replace('_', ' ', $invoice['payment_method'] ?? 'Not recorded'))) ?>
                            </span>
                        </td>

                        <td class="text-end text-nowrap"><?= money($invoice['subtotal']) ?></td>
                        <td class="text-end text-nowrap text-danger"><?= money($invoice['discount_amount']) ?></td>
                        <td class="text-end text-nowrap fw-bold"><?= money($invoice['total_amount']) ?></td>

                        <td class="text-end pe-3 pe-md-4 no-print">
                            <a class="btn btn-sm btn-outline-primary"
                               href="invoices.php?view=<?= (int)$invoice['id'] ?><?= $filterQuery !== '' ? '&' . e($filterQuery) : '' ?>">
                                <i class="bi bi-eye me-1"></i>View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="text-center text-secondary small mt-4">
        Brainbean Technolabs · Invoice Management
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function printInvoice() {
    document.body.classList.remove('print-list');
    document.body.classList.add('print-invoice');
    window.print();
}

function printInvoiceList() {
    document.body.classList.remove('print-invoice');
    document.body.classList.add('print-list');
    window.print();
}

window.addEventListener('afterprint', function () {
    document.body.classList.remove('print-invoice', 'print-list');
});
</script>
</body>
</html>
