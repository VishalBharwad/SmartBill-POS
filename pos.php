<?php
require_once __DIR__ . '/auth.php';
requireAdmin();

require_once __DIR__ . "/config/database.php";

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function productImage($image) {
    $image = trim((string) $image);

    if ($image === '') {
        return '';
    }

    // Normalize paths saved by older versions of the project.
    $image = str_replace('\\', '/', $image);

    // Avoid directory traversal and allow only files inside the product upload folder.
    if (preg_match('~(^|/)\\.\\.(/|$)~', $image)) {
        return '';
    }

    $marker = 'uploads/products/';
    $position = stripos($image, $marker);

    if ($position !== false) {
        $relativePath = substr($image, $position);
        $filename = basename($relativePath);
    } else {
        // Most records store only the image filename.
        $filename = basename($image);
        $relativePath = $marker . $filename;
    }

    if ($filename === '') {
        return '';
    }

    $fullPath = __DIR__ . '/uploads/products/' . $filename;

    if (!is_file($fullPath)) {
        return '';
    }

    return 'uploads/products/' . rawurlencode($filename);
}

$message = '';
$messageType = 'danger';

// Handle POS actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $productId = filter_var(
                $_POST['product_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $quantity = filter_var(
                $_POST['quantity'] ?? 1,
                FILTER_VALIDATE_INT
            );

            if (
                !$productId || $productId < 1 ||
                !$quantity || $quantity < 1 || $quantity > 9999
            ) {
                throw new RuntimeException(
                    'Select a valid product and quantity.'
                );
            }

            $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
            $stmt->execute([$productId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException('Product not found.');
            }

            $newQuantity = ($_SESSION['pos_cart'][$productId] ?? 0)
                + $quantity;

            if ($newQuantity > 9999) {
                throw new RuntimeException('Maximum quantity is 9999.');
            }

            $_SESSION['pos_cart'][$productId] = $newQuantity;

            header('Location: pos.php');
            exit;
        }

        if ($action === 'update_cart') {
            $quantities = $_POST['qty'] ?? [];

            foreach ($_SESSION['pos_cart'] as $productId => $oldQuantity) {
                $quantity = filter_var(
                    $quantities[$productId] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (
                    $quantity === false || $quantity === null ||
                    $quantity < 1 || $quantity > 9999
                ) {
                    throw new RuntimeException(
                        'Each quantity must be between 1 and 9999.'
                    );
                }

                $_SESSION['pos_cart'][$productId] = $quantity;
            }

            header('Location: pos.php');
            exit;
        }

        if ($action === 'remove') {
            $productId = filter_var(
                $_POST['product_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($productId) {
                unset($_SESSION['pos_cart'][$productId]);
            }

            header('Location: pos.php');
            exit;
        }

        if ($action === 'clear') {
            $_SESSION['pos_cart'] = [];
            header('Location: pos.php');
            exit;
        }

        if ($action === 'checkout') {
            if (empty($_SESSION['pos_cart'])) {
                throw new RuntimeException(
                    'Add at least one product before checkout.'
                );
            }

            $customerId = filter_var(
                $_POST['customer_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            // Payment method selected at checkout.
            $paymentMethod = $_POST['payment_method'] ?? 'cash';
            $allowedPaymentMethods = ['cash', 'upi', 'card', 'bank_transfer'];

            if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
                throw new RuntimeException('Please select a valid payment method.');
            }

            $discountType = $_POST['discount_type'] ?? 'fixed';

            $discountValue = filter_var(
                $_POST['discount_value'] ?? 0,
                FILTER_VALIDATE_FLOAT
            );

            // "No discount" is stored as a zero fixed discount.
            if ($discountType === 'none') {
                $discountType = 'fixed';
                $discountValue = 0;
            }

            if (!$customerId || $customerId < 1) {
                throw new RuntimeException('Please select a customer.');
            }

            if (!in_array($discountType, ['fixed', 'percentage'], true)) {
                throw new RuntimeException('Invalid discount type.');
            }

            if (
                $discountValue === false ||
                !is_finite((float)$discountValue) ||
                $discountValue < 0 ||
                $discountValue > 99999999.99
            ) {
                throw new RuntimeException('Enter a valid discount.');
            }

            if ($discountType === 'percentage' && $discountValue > 100) {
                throw new RuntimeException(
                    'Percentage discount cannot exceed 100%.'
                );
            }

            $stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
            $stmt->execute([$customerId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException('Customer was not found.');
            }

            // Read current prices from the database.
            $cartIds = array_map(
                'intval',
                array_keys($_SESSION['pos_cart'])
            );

            $placeholders = implode(
                ',',
                array_fill(0, count($cartIds), '?')
            );

            $stmt = $pdo->prepare(
                "SELECT id, name, brand_name, sell_price
                 FROM products
                 WHERE id IN ($placeholders)"
            );

            $stmt->execute($cartIds);

            $productMap = [];

            foreach ($stmt->fetchAll() as $product) {
                $productMap[(int)$product['id']] = $product;
            }

            if (count($productMap) !== count($_SESSION['pos_cart'])) {
                throw new RuntimeException(
                    'A cart product no longer exists. Review your cart.'
                );
            }

            $items = [];
            $subtotal = 0.00;

            foreach ($_SESSION['pos_cart'] as $productId => $quantity) {
                $productId = (int)$productId;
                $quantity = (int)$quantity;
                $product = $productMap[$productId];

                $unitPrice = (float)$product['sell_price'];
                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal = round($subtotal + $lineTotal, 2);

                $items[] = [
                    'product_id' => $productId,
                    'product_name' => $product['name'],
                    'brand_name' => $product['brand_name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal
                ];
            }

            $discountValue = round((float)$discountValue, 2);

            if ($discountType === 'percentage') {
                $discountAmount = round(
                    $subtotal * $discountValue / 100,
                    2
                );
            } else {
                $discountAmount = $discountValue;
            }

            if ($discountAmount > $subtotal) {
                throw new RuntimeException(
                    'Discount cannot exceed the subtotal.'
                );
            }

            $totalAmount = round($subtotal - $discountAmount, 2);

            // Add the payment_method column if this database has not been upgraded yet.
            $paymentColumn = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'payment_method'")->fetch();
            if (!$paymentColumn) {
                $pdo->exec("ALTER TABLE invoices ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cash'");
            }

            // Save invoice and items in one transaction.
            $pdo->beginTransaction();

            $invoiceNumber = 'INV-' . date('Ymd-His')
                . '-' . strtoupper(bin2hex(random_bytes(3)));

            $stmt = $pdo->prepare(
                "INSERT INTO invoices
                 (invoice_number, customer_id, subtotal, discount_type,
                  discount_value, discount_amount, total_amount, payment_method)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $invoiceNumber,
                $customerId,
                $subtotal,
                $discountType,
                $discountValue,
                $discountAmount,
                $totalAmount,
                $paymentMethod
            ]);

            $invoiceId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                "INSERT INTO invoice_items
                 (invoice_id, product_id, product_name, brand_name,
                  quantity, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($items as $item) {
                $itemStmt->execute([
                    $invoiceId,
                    $item['product_id'],
                    $item['product_name'],
                    $item['brand_name'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['line_total']
                ]);
            }

            $pdo->commit();

            $_SESSION['pos_cart'] = [];
            $_SESSION['pos_flash'] = [
                'type' => 'success',
                'invoice_number' => $invoiceNumber,
                'total' => $totalAmount,
                'payment_method' => $paymentMethod
            ];

            header('Location: pos.php');
            exit;
        }
    } catch (RuntimeException $ex) {
        $message = $ex->getMessage();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $message = 'Unable to complete the operation. Check the database and try again.';
    }
}

// Invoice success message
$success = $_SESSION['pos_flash'] ?? null;
unset($_SESSION['pos_flash']);

// Customers
$customers = $pdo->query(
    "SELECT id, name, phone FROM customers ORDER BY name"
)->fetchAll();

// Product search: show the latest 6 products by default
$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare(
        "SELECT id, name, brand_name, sell_price, image
         FROM products
         WHERE name LIKE ? OR brand_name LIKE ?
         ORDER BY name ASC
         LIMIT 50"
    );

    $term = '%' . $search . '%';
    $stmt->execute([$term, $term]);
} else {
    $stmt = $pdo->query(
        "SELECT id, name, brand_name, sell_price, image
         FROM products
         ORDER BY id DESC
         LIMIT 6"
    );
}

$searchProducts = $stmt->fetchAll();

// Cart items
$cartItems = [];
$subtotal = 0.00;

if (!empty($_SESSION['pos_cart'])) {
    $cartIds = array_map(
        'intval',
        array_keys($_SESSION['pos_cart'])
    );

    $placeholders = implode(
        ',',
        array_fill(0, count($cartIds), '?')
    );

    $stmt = $pdo->prepare(
        "SELECT id, name, brand_name, sell_price, image
         FROM products
         WHERE id IN ($placeholders)"
    );

    $stmt->execute($cartIds);

    foreach ($stmt->fetchAll() as $product) {
        $id = (int)$product['id'];
        $quantity = (int)$_SESSION['pos_cart'][$id];
        $price = (float)$product['sell_price'];
        $lineTotal = round($price * $quantity, 2);

        $product['quantity'] = $quantity;
        $product['line_total'] = $lineTotal;

        $cartItems[] = $product;
        $subtotal = round($subtotal + $lineTotal, 2);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Point of Sale | Brainbean Technolabs</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .pos-heading {
            font-weight: 750;
            letter-spacing: -.5px;
        }

        .pos-subtitle {
            color: #718096;
            font-size: .93rem;
        }

        .pos-card {
            border: 1px solid #e8edf3;
            border-radius: 16px;
            box-shadow: 0 5px 20px rgba(30, 50, 80, .05);
            overflow: hidden;
        }

        .pos-card .card-header {
            background: #fff;
            border-bottom: 1px solid #edf0f5;
            padding: 17px 20px;
        }

        .pos-card .card-body {
            padding: 20px;
        }

        .product-image {
            width: 66px;
            height: 66px;
            flex-shrink: 0;
            object-fit: contain;
            background: #f7f9fc;
            border: 1px solid #edf0f5;
            border-radius: 12px;
            padding: 5px;
        }

        .product-placeholder {
            width: 66px;
            height: 66px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f4ff;
            color: #6479b8;
            border-radius: 12px;
            font-size: 25px;
        }

        .product-result {
            border: 1px solid #edf0f5;
            border-radius: 13px !important;
            margin-bottom: 10px;
            padding: 13px;
        }

        .product-result:last-child {
            margin-bottom: 0;
        }

        .product-result:hover {
            background: #f9fbff;
            border-color: #cdd8f8;
        }

        .product-name {
            font-weight: 650;
            color: #26334a;
        }

        .product-brand {
            color: #8490a3;
            font-size: .83rem;
        }

        .product-price {
            color: #176b47;
            font-weight: 750;
        }

        .cart-table th {
            background: #f7f9fc;
            color: #667085;
            font-size: .79rem;
            font-weight: 650;
            white-space: nowrap;
        }

        .cart-table td {
            padding-top: 15px;
            padding-bottom: 15px;
        }

        .cart-qty {
            width: 78px;
            min-width: 68px;
        }

        .summary-panel {
            background: #f7f9fc;
            border: 1px solid #edf0f5;
            border-radius: 14px;
            padding: 17px;
        }

        .grand-total {
            font-size: 1.65rem;
            font-weight: 800;
            color: #176b47;
        }

        .cart-count {
            background: #edf2ff;
            color: #405fc1;
            border-radius: 20px;
            padding: 6px 11px;
            font-size: .8rem;
            font-weight: 700;
        }

        .empty-cart {
            padding: 40px 15px;
            text-align: center;
            color: #8490a3;
        }

        .empty-cart i {
            display: block;
            font-size: 42px;
            color: #bdc8dc;
            margin-bottom: 12px;
        }

        .checkout-button {
            padding: 13px;
            font-weight: 700;
            border-radius: 11px;
        }

        .search-input {
            min-height: 45px;
        }

        .section-caption {
            font-size: .83rem;
            color: #7b8799;
        }

        .pos-card {
            transition: box-shadow .2s ease, transform .2s ease;
        }

        .pos-card:focus-within {
            box-shadow: 0 9px 28px rgba(30, 50, 80, .09);
        }

        .product-result {
            transition: background-color .18s ease, border-color .18s ease;
        }

        .product-image {
            object-fit: contain;
        }

        .product-result .btn {
            white-space: nowrap;
        }

        .summary-panel hr {
            border-color: #dfe6ef;
            opacity: 1;
        }

        .checkout-button:disabled {
            cursor: not-allowed;
            opacity: .6;
        }

        @media (max-width: 767px) {
            .pos-card .card-body {
                padding: 14px;
            }

            .product-image,
            .product-placeholder {
                width: 52px;
                height: 52px;
            }

            .grand-total {
                font-size: 1.4rem;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content">

    <!-- Page heading -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="page-title pos-heading mb-1">Point of Sale</h1>
            <div class="pos-subtitle">
                <i class="bi bi-receipt me-1"></i>
                Search products, manage your cart and create an invoice.
            </div>
        </div>

        <div class="d-inline-flex align-items-center gap-2 rounded-pill px-3 py-2"
             style="background:#eaf7ef;color:#176b47;font-weight:650;">
            <i class="bi bi-circle-fill" style="font-size:8px;"></i>
            New Sale
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-circle me-2"></i>
            <?= e($message) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            Invoice <strong><?= e($success['invoice_number']) ?></strong>
            saved successfully. Total:
            <strong>₹<?= number_format((float)$success['total'], 2) ?></strong>
            <?php if (!empty($success['payment_method'])): ?>
                <span class="ms-1">· Payment: <strong><?= e(ucwords(str_replace('_', ' ', $success['payment_method']))) ?></strong></span>
            <?php endif; ?>
            <a href="invoices.php" class="alert-link ms-2">View invoices</a>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">

  
<!-- LEFT: Product search -->
<div class="col-xl-5 col-lg-5">
    <div class="card pos-card h-100">
        <div class="card-header d-flex align-items-center gap-2">
            <div class="rounded-3 d-flex align-items-center justify-content-center"
                 style="width:40px;height:40px;background:#edf2ff;color:#405fc1;">
                <i class="bi bi-search fs-5"></i>
            </div>
            <div>
                <h5 class="mb-0 fw-bold">Find Products</h5>
                <div class="section-caption">Search by product or brand</div>
            </div>
        </div>

        <div class="card-body">
            <form method="GET" action="pos.php" class="mb-3">
                <label for="productSearch" class="form-label fw-semibold">
                    Product Search
                </label>

                <div class="input-group">
                    <span class="input-group-text bg-white">
                        <i class="bi bi-search text-secondary"></i>
                    </span>

                    <input type="search"
                           id="productSearch"
                           name="search"
                           class="form-control search-input"
                           value="<?= e($search ?? '') ?>"
                           placeholder="e.g. Mouse, HP, Keyboard"
                           autocomplete="off">

                    <button class="btn btn-primary px-3" type="submit">
                        Search
                    </button>
                </div>
            </form>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="section-caption">
                    <?= !empty($search) ? 'Search Results' : 'Available Products' ?>
                </span>

                <?php if (!empty($search)): ?>
                    <a href="pos.php" class="small text-decoration-none">Clear</a>
                <?php endif; ?>
            </div>

            <?php if (empty($searchProducts)): ?>
                <div class="empty-cart">
                    <i class="bi bi-search"></i>
                    <h6 class="fw-bold text-dark">No products found</h6>
                    <p class="mb-0">
                        <?= !empty($search)
                            ? 'Try another product name or brand.'
                            : 'No products are available. Add products on the Products page.' ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="list-group">
                    <?php foreach ($searchProducts as $product): ?>
                        <?php $imagePath = productImage($product['image'] ?? null); ?>

                        <div class="list-group-item product-result">
                            <div class="d-flex align-items-center gap-3">

                                <?php if ($imagePath !== ''): ?>
                                    <img src="<?= e($imagePath) ?>"
                                         alt="<?= e($product['name']) ?>"
                                         class="product-image">
                                <?php else: ?>
                                    <div class="product-placeholder">
                                        <i class="bi bi-box-seam"></i>
                                    </div>
                                <?php endif; ?>

                                <div class="flex-grow-1 min-w-0">
                                    <div class="product-name">
                                        <?= e($product['name']) ?>
                                    </div>

                                    <div class="product-brand">
                                        <?= e($product['brand_name']) ?>
                                    </div>

                                    <div class="product-price mt-1">
                                        ₹<?= number_format((float)$product['sell_price'], 2) ?>
                                    </div>
                                </div>

                                <form method="POST" action="pos.php">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id"
                                           value="<?= (int)$product['id'] ?>">
                                    <input type="hidden" name="quantity" value="1">

                                    <button class="btn btn-primary btn-sm px-3"
                                            type="submit">
                                        <i class="bi bi-plus-lg me-1"></i>Add
                                    </button>
                                </form>

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>


        <!-- RIGHT: Cart and checkout -->
        <div class="col-xl-7 col-lg-7">

            <div class="card pos-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-3 d-flex align-items-center justify-content-center"
                             style="width:40px;height:40px;background:#eaf7ef;color:#176b47;">
                            <i class="bi bi-cart3 fs-5"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold">Shopping Cart</h5>
                            <div class="section-caption">Review items before checkout</div>
                        </div>
                    </div>

                    <span class="cart-count">
                        <?= count($cartItems) ?> item<?= count($cartItems) === 1 ? '' : 's' ?>
                    </span>
                </div>

                <div class="card-body">

                    <?php if (empty($cartItems)): ?>

                        <div class="empty-cart">
                            <i class="bi bi-cart-x"></i>
                            <h5 class="fw-bold text-dark">Your cart is empty</h5>
                            <p class="mb-0">
                                Search for a product and click Add to start a sale.
                            </p>
                        </div>

                    <?php else: ?>

                        <!-- Update quantities -->
                        <form method="POST" action="pos.php" id="updateCartForm">
                            <input type="hidden" name="csrf_token"
                                   value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="update_cart">

                            <div class="table-responsive">
                                <table class="table cart-table align-middle mb-3">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Price</th>
                                            <th>Qty</th>
                                            <th class="text-end">Total</th>
                                            <th></th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                    <?php foreach ($cartItems as $item): ?>
                                        <?php $imagePath = productImage($item['image'] ?? null); ?>

                                        <tr data-price="<?= e($item['sell_price']) ?>">
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <?php if ($imagePath !== ''): ?>
                                                        <img src="<?= e($imagePath) ?>"
                                                             alt="<?= e($item['name']) ?>"
                                                             class="product-image"
                                                             style="width:48px;height:48px;">
                                                    <?php else: ?>
                                                        <div class="product-placeholder"
                                                             style="width:48px;height:48px;font-size:19px;">
                                                            <i class="bi bi-box-seam"></i>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div>
                                                        <div class="product-name">
                                                            <?= e($item['name']) ?>
                                                        </div>
                                                        <div class="product-brand">
                                                            <?= e($item['brand_name']) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td class="text-nowrap">
                                                ₹<?= number_format((float)$item['sell_price'], 2) ?>
                                            </td>

                                            <td>
                                                <input type="number"
                                                       class="form-control form-control-sm cart-qty"
                                                       name="qty[<?= (int)$item['id'] ?>]"
                                                       value="<?= (int)$item['quantity'] ?>"
                                                       data-initial-quantity="<?= (int)$item['quantity'] ?>"
                                                       min="1" max="9999" required
                                                       aria-label="Quantity for <?= e($item['name']) ?>">
                                            </td>

                                            <td class="text-end fw-semibold text-nowrap line-total">
                                                ₹<?= number_format((float)$item['line_total'], 2) ?>
                                            </td>

                                            <td>
                                                <button type="submit"
                                                        form="remove-<?= (int)$item['id'] ?>"
                                                        class="btn btn-sm btn-outline-danger"
                                                        title="Remove product"
                                                        aria-label="Remove <?= e($item['name']) ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <button type="submit" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-arrow-repeat me-1"></i>
                                    Update Quantities
                                </button>

                                <span class="section-caption">
                                    Change quantities and update before checkout.
                                </span>
                            </div>
                        </form>

                        <!-- Remove product forms -->
                        <?php foreach ($cartItems as $item): ?>
                            <form id="remove-<?= (int)$item['id'] ?>"
                                  method="POST" action="pos.php" class="d-none">
                                <input type="hidden" name="csrf_token"
                                       value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="product_id"
                                       value="<?= (int)$item['id'] ?>">
                            </form>
                        <?php endforeach; ?>

                        <hr class="my-4">

                        <!-- Checkout -->
                        <form method="POST" action="pos.php" id="checkoutForm">
                            <input type="hidden" name="csrf_token"
                                   value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="checkout">

                            <div class="mb-4">
                                <label for="customer" class="form-label fw-semibold">
                                    <i class="bi bi-person me-1"></i> Select Customer
                                </label>

                                <select id="customer" name="customer_id"
                                        class="form-select" required>
                                    <option value="">Choose a customer</option>

                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= (int)$customer['id'] ?>">
                                            <?= e($customer['name']) ?>
                                            <?= $customer['phone']
                                                ? ' (' . e($customer['phone']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Payment method -->
                            <div class="mb-4">
                                <label for="paymentMethod" class="form-label fw-semibold">
                                    <i class="bi bi-credit-card me-1"></i> Payment Method
                                </label>
                                <select id="paymentMethod" name="payment_method" class="form-select" required>
                                    <option value="cash">Cash</option>
                                    <option value="upi">UPI</option>
                                    <option value="card">Debit / Credit Card</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                </select>
                                <div class="form-text">Choose how the customer pays for this invoice.</div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="discountType" class="form-label fw-semibold">
                                        <i class="bi bi-percent me-1"></i> Discount Type
                                    </label>

                                    <select id="discountType" name="discount_type"
                                            class="form-select">
                                        <option value="none">No Discount</option>
                                        <option value="fixed">Fixed Amount (₹)</option>
                                        <option value="percentage">Percentage (%)</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="discountValue" class="form-label fw-semibold">
                                        Discount Value
                                    </label>

                                    <input type="number"
                                           id="discountValue"
                                           name="discount_value"
                                           class="form-control"
                                           min="0" max="99999999.99"
                                           step="0.01" value="0" disabled>
                                </div>
                            </div>

                            <div id="quantityNotice" class="alert alert-warning py-2 small d-none mb-3">
                                <i class="bi bi-info-circle me-1"></i>
                                You changed a quantity. Click <strong>Update Quantities</strong> before saving the invoice.
                            </div>

                            <!-- Live price summary -->
                            <div class="summary-panel mb-3"
                                 id="priceSummary"
                                 data-subtotal="<?= e(number_format($subtotal, 2, '.', '')) ?>">

                                <div class="d-flex justify-content-between mb-3">
                                    <span class="text-secondary">Subtotal</span>
                                    <strong id="subtotalText">
                                        ₹<?= number_format($subtotal, 2) ?>
                                    </strong>
                                </div>

                                <div class="d-flex justify-content-between mb-3">
                                    <span class="text-secondary">Discount</span>
                                    <strong class="text-danger" id="discountText">
                                        − ₹0.00
                                    </strong>
                                </div>

                                <hr>

                                <div class="d-flex justify-content-between align-items-center gap-3">
                                    <div>
                                        <div class="fw-bold">Grand Total</div>
                                        <div class="section-caption">Amount to collect</div>
                                    </div>

                                    <div class="grand-total" id="grandText">
                                        ₹<?= number_format($subtotal, 2) ?>
                                    </div>
                                </div>

                                <div id="discountError"
                                     class="text-danger small mt-3 d-none"></div>
                            </div>

                            <button type="submit"
                                    id="saveInvoiceButton"
                                    class="btn btn-success checkout-button w-100"
                                    onclick="return confirm('Save this invoice?');">
                                <i class="bi bi-check-circle me-2"></i>
                                Save Invoice
                            </button>
                        </form>

                        <!-- Clear cart -->
                        <form method="POST" action="pos.php" class="mt-2"
                              onsubmit="return confirm('Clear the entire cart?');">
                            <input type="hidden" name="csrf_token"
                                   value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="clear">

                            <button type="submit" class="btn btn-outline-danger w-100">
                                <i class="bi bi-trash3 me-1"></i>
                                Clear Cart
                            </button>
                        </form>

                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <div class="text-center text-secondary small mt-4">
        Brainbean Technolabs · Point of Sale
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const summary = document.getElementById('priceSummary');

    // The summary only exists when the cart has products.
    if (!summary) {
        return;
    }

    const discountType = document.getElementById('discountType');
    const discountValue = document.getElementById('discountValue');
    const subtotalText = document.getElementById('subtotalText');
    const discountText = document.getElementById('discountText');
    const grandText = document.getElementById('grandText');
    const discountError = document.getElementById('discountError');
    const saveButton = document.getElementById('saveInvoiceButton');

    function money(value) {
        return '₹' + value.toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function calculateTotal() {
        let subtotal = 0;

        // Recalculate from current quantity inputs for a live preview.
        document.querySelectorAll('#updateCartForm tbody tr').forEach(function (row) {
            const price = Number(row.dataset.price) || 0;
            const quantityInput = row.querySelector('.cart-qty');
            const quantity = Math.max(1, Math.min(9999, Number(quantityInput.value) || 1));
            const lineTotal = price * quantity;

            subtotal += lineTotal;

            const lineTotalElement = row.querySelector('.line-total');
            if (lineTotalElement) {
                lineTotalElement.textContent = money(lineTotal);
            }
        });

        subtotal = Math.round((subtotal + Number.EPSILON) * 100) / 100;

        const type = discountType.value;
        const rawValue = discountValue.value.trim();
        const value = rawValue === '' ? 0 : Number(rawValue);

        let discount = 0;
        let error = '';

        if (!Number.isFinite(value) || value < 0) {
            error = 'Enter a valid discount amount.';
        } else if (type === 'percentage' && value > 100) {
            error = 'Percentage discount cannot exceed 100%.';
        } else if (type === 'fixed' && value > subtotal) {
            error = 'Discount cannot exceed the subtotal.';
        } else if (type === 'percentage') {
            discount = subtotal * value / 100;
        } else if (type === 'fixed') {
            discount = value;
        }

        discount = Math.round((discount + Number.EPSILON) * 100) / 100;
        const grandTotal = Math.max(0, subtotal - discount);

        subtotalText.textContent = money(subtotal);
        discountText.textContent = '− ' + money(discount);
        grandText.textContent = money(grandTotal);

        discountError.textContent = error;
        discountError.classList.toggle('d-none', !error);
        saveButton.disabled = Boolean(error);

        // If the user changes the quantity, remind them to save it first.
        const qtyInputs = document.querySelectorAll('.cart-qty');
        const quantitiesChanged = Array.from(qtyInputs).some(function (input) {
            const initialQuantity = Number(input.dataset.initialQuantity);
            const currentQuantity = Number(input.value);

            return input.value.trim() === '' ||
                !Number.isInteger(currentQuantity) ||
                currentQuantity < 1 ||
                currentQuantity > 9999 ||
                currentQuantity !== initialQuantity;
        });

        const quantityNotice = document.getElementById('quantityNotice');

        if (quantitiesChanged) {
            saveButton.disabled = true;

            if (quantityNotice) {
                quantityNotice.classList.remove('d-none');
            }
        } else if (quantityNotice) {
            quantityNotice.classList.add('d-none');
        }
    }

    function updateDiscountInput() {
        const noDiscount = discountType.value === 'none';

        discountValue.disabled = noDiscount;
        discountValue.value = noDiscount ? '0' : discountValue.value;

        if (noDiscount) {
            discountValue.value = '0';
        }

        calculateTotal();
    }

    discountType.addEventListener('change', updateDiscountInput);
    discountValue.addEventListener('input', calculateTotal);

    document.querySelectorAll('.cart-qty').forEach(function (input) {
        input.addEventListener('input', calculateTotal);
    });

    calculateTotal();
});
</script>

</body>
</html>
