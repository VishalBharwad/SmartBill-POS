<?php
require_once __DIR__ . '/auth.php';
requireAdmin();
?>
<?php
require_once __DIR__ . "/config/database.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION["products_csrf"])) {
    $_SESSION["products_csrf"] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function productColumns(PDO $pdo): array
{
    $result = [];
    foreach ($pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $result[$column["Field"]] = true;
    }
    return $result;
}

function firstExistingColumn(array $columns, array $choices): ?string
{
    foreach ($choices as $choice) {
        if (isset($columns[$choice])) {
            return $choice;
        }
    }
    return null;
}

function productImagePath(?string $image): ?string
{
    $image = trim((string) $image);
    if ($image === "") {
        return null;
    }

    // Normalize common paths saved by older versions of the project.
    $image = str_replace("\\", "/", $image);

    // Reject traversal paths so a database value cannot point outside the project.
    if (preg_match('~(^|/)\.\.(/|$)~', $image)) {
        return null;
    }

    // Convert Windows absolute paths to a web-relative path when possible.
    if (preg_match('/^[a-z]:\//i', $image)) {
        $position = stripos($image, "uploads/products/");
        if ($position !== false) {
            return substr($image, $position);
        }
        return null;
    }

    // Convert paths such as /brainbean_task/uploads/products/item.jpg.
    $position = stripos($image, "uploads/products/");
    if ($position !== false) {
        return substr($image, $position);
    }

    $image = ltrim($image, "/");

    // If the database stores only a filename, use the project's upload folder.
    if (!str_contains($image, "/")) {
        return "uploads/products/" . rawurlencode($image);
    }

    // Support older records stored as products/item.jpg.
    if (str_starts_with($image, "products/")) {
        return "uploads/" . $image;
    }

    // Already-relative paths such as assets/images/item.jpg.
    return $image;
}

$message = $_SESSION["products_flash"]["message"] ?? "";
$messageType = $_SESSION["products_flash"]["type"] ?? "success";
unset($_SESSION["products_flash"]);

try {
    $columns = productColumns($pdo);
} catch (Throwable $e) {
    $columns = [];
    $message = "Could not read the products table. Check your database connection and table.";
    $messageType = "danger";
}

$stockColumn = firstExistingColumn($columns, ["stock_quantity", "quantity", "stock", "qty"]);
$purchasePriceColumn = firstExistingColumn($columns, ["purchase_price", "cost_price", "buy_price"]);
$categoryColumn = firstExistingColumn($columns, ["category", "category_name"]);
$expiryColumn = firstExistingColumn($columns, ["expiry_date", "expiry"]);
$descriptionColumn = firstExistingColumn($columns, ["description", "details"]);
$skuColumn = firstExistingColumn($columns, ["sku", "product_code", "barcode"]);

$editProduct = null;
$editId = (int) ($_GET["edit"] ?? 0);

if ($columns && $editId > 0 && isset($columns["id"])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$editId]);
    $editProduct = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $columns) {
    $postedToken = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["products_csrf"], $postedToken)) {
        $_SESSION["products_flash"] = [
            "message" => "Your form session expired. Please try again.",
            "type" => "danger"
        ];
        header("Location: products.php");
        exit;
    }

    $action = $_POST["action"] ?? "";
    $id = (int) ($_POST["id"] ?? 0);

    try {
        if ($action === "delete") {
            if ($id < 1) {
                throw new RuntimeException("Invalid product selected.");
            }

            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);

            $_SESSION["products_flash"] = [
                "message" => $stmt->rowCount() ? "Product deleted successfully." : "Product was not found.",
                "type" => $stmt->rowCount() ? "success" : "warning"
            ];
        } elseif ($action === "add" || $action === "edit") {
            $name = trim($_POST["name"] ?? "");
            $brand = trim($_POST["brand_name"] ?? "");
            $sellPrice = trim($_POST["sell_price"] ?? "");

            if ($name === "") {
                throw new RuntimeException("Product name is required.");
            }
            if (!isset($columns["sell_price"])) {
                throw new RuntimeException("Your products table needs a sell_price column for this page.");
            }
            if ($sellPrice === "" || !is_numeric($sellPrice) || (float) $sellPrice < 0) {
                throw new RuntimeException("Enter a valid selling price (zero or greater).");
            }

            $data = [
                "name" => $name,
                "brand_name" => $brand,
                "sell_price" => number_format((float) $sellPrice, 2, ".", "")
            ];

            if ($purchasePriceColumn) {
                $purchase = trim($_POST["purchase_price"] ?? "");
                if ($purchase !== "" && (!is_numeric($purchase) || (float) $purchase < 0)) {
                    throw new RuntimeException("Enter a valid purchase price.");
                }
                $data[$purchasePriceColumn] = $purchase === "" ? null : number_format((float) $purchase, 2, ".", "");
            }

            if ($stockColumn) {
                $stock = trim($_POST["stock_quantity"] ?? "");
                if ($stock === "" || filter_var($stock, FILTER_VALIDATE_INT) === false || (int) $stock < 0) {
                    throw new RuntimeException("Stock quantity must be a whole number equal to or greater than zero.");
                }
                $data[$stockColumn] = (int) $stock;
            }

            if ($categoryColumn) {
                $data[$categoryColumn] = trim($_POST["category"] ?? "");
            }

            if ($expiryColumn) {
                $expiry = trim($_POST["expiry_date"] ?? "");
                if ($expiry !== "") {
                    $date = DateTime::createFromFormat("Y-m-d", $expiry);
                    if (!$date || $date->format("Y-m-d") !== $expiry) {
                        throw new RuntimeException("Enter a valid expiry date.");
                    }
                }
                $data[$expiryColumn] = $expiry === "" ? null : $expiry;
            }

            if ($descriptionColumn) {
                $data[$descriptionColumn] = trim($_POST["description"] ?? "");
            }

            if ($skuColumn) {
                $data[$skuColumn] = trim($_POST["sku"] ?? "");
            }

            // Only include fields that actually exist in the database table.
            $data = array_filter(
                $data,
                static fn($value, $field) => isset($columns[$field]),
                ARRAY_FILTER_USE_BOTH
            );

            $newImage = null;
            if (isset($columns["image"]) && isset($_FILES["image"]) &&
                $_FILES["image"]["error"] !== UPLOAD_ERR_NO_FILE) {

                if ($_FILES["image"]["error"] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException("The product image could not be uploaded.");
                }

                if ($_FILES["image"]["size"] > 5 * 1024 * 1024) {
                    throw new RuntimeException("Product images must be 5 MB or smaller.");
                }

                $tmpPath = $_FILES["image"]["tmp_name"];
                $imageInfo = @getimagesize($tmpPath);
                if ($imageInfo === false) {
                    throw new RuntimeException("Please upload a valid image file.");
                }

                $mime = $imageInfo["mime"] ?? "";
                $allowedTypes = [
                    "image/jpeg" => "jpg",
                    "image/png" => "png",
                    "image/webp" => "webp",
                    "image/gif" => "gif"
                ];

                if (!isset($allowedTypes[$mime])) {
                    throw new RuntimeException("Allowed image types: JPG, PNG, WEBP, and GIF.");
                }

                $uploadDirectory = __DIR__ . "/uploads/products";
                if (!is_dir($uploadDirectory) &&
                    !mkdir($uploadDirectory, 0775, true) &&
                    !is_dir($uploadDirectory)) {
                    throw new RuntimeException("Could not create the product image folder.");
                }

                $fileName = "product_" . bin2hex(random_bytes(12)) . "." . $allowedTypes[$mime];
                if (!move_uploaded_file($tmpPath, $uploadDirectory . "/" . $fileName)) {
                    throw new RuntimeException("Could not save the product image.");
                }

                $newImage = "uploads/products/" . $fileName;
                $data["image"] = $newImage;
            }

            if ($action === "add") {
                $insertFields = array_keys($data);
                $quotedFields = array_map(static fn($field) => "`$field`", $insertFields);
                $placeholders = array_fill(0, count($insertFields), "?");

                $sql = "INSERT INTO products (" . implode(", ", $quotedFields) . ")
                        VALUES (" . implode(", ", $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($data));

                $_SESSION["products_flash"] = [
                    "message" => "Product added successfully.",
                    "type" => "success"
                ];
            } else {
                if ($id < 1) {
                    throw new RuntimeException("Invalid product selected.");
                }

                // Keep the existing image if no new image was uploaded.
                if (isset($columns["image"]) && $newImage === null) {
                    unset($data["image"]);
                }

                if (!$data) {
                    throw new RuntimeException("No product fields were available to update.");
                }

                $setParts = [];
                foreach (array_keys($data) as $field) {
                    $setParts[] = "`$field` = ?";
                }

                $stmt = $pdo->prepare(
                    "UPDATE products SET " . implode(", ", $setParts) . " WHERE id = ?"
                );
                $stmt->execute([...array_values($data), $id]);

                $_SESSION["products_flash"] = [
                    "message" => "Product updated successfully.",
                    "type" => "success"
                ];
            }
        } else {
            throw new RuntimeException("Unknown product action.");
        }
    } catch (Throwable $e) {
        $_SESSION["products_flash"] = [
            "message" => $e instanceof RuntimeException
                ? $e->getMessage()
                : "Could not save this change. Check the product fields and database constraints.",
            "type" => "danger"
        ];
    }

    header("Location: products.php");
    exit;
}

$search = trim($_GET["search"] ?? "");
$products = [];

if ($columns && isset($columns["id"])) {
    $sql = "SELECT * FROM products";
    $params = [];

    if ($search !== "") {
        $conditions = [];
        foreach (["name", "brand_name", $skuColumn, $categoryColumn] as $field) {
            if ($field && isset($columns[$field]) && !in_array("`$field` LIKE ?", $conditions, true)) {
                $conditions[] = "`$field` LIKE ?";
                $params[] = "%" . $search . "%";
            }
        }

        if ($conditions) {
            $sql .= " WHERE " . implode(" OR ", $conditions);
        }
    }

    $sql .= " ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$productCount = count($products);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products | Brainbean POS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        :root {
            --product-primary: #4f46e5;
            --product-text: #172033;
            --product-muted: #7b8498;
            --product-border: #edf0f5;
            --product-bg: #f5f7fb;
        }
        body {
            background: var(--product-bg);
            color: var(--product-text);
            font-family: Inter, "Segoe UI", Arial, sans-serif;
        }
        .main-content { padding: 28px; min-width: 0; }
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            gap: 16px; flex-wrap: wrap; margin-bottom: 26px;
        }
        .page-title { font-size: 29px; font-weight: 750; letter-spacing: -.7px; margin: 0 0 6px; }
        .muted { color: var(--product-muted); font-size: 13px; }
        .panel {
            background: #fff; border: 1px solid var(--product-border);
            border-radius: 18px; box-shadow: 0 4px 18px rgba(20,31,56,.035);
            overflow: hidden; margin-bottom: 24px;
        }
        .panel-header {
            padding: 21px 24px; border-bottom: 1px solid var(--product-border);
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px; flex-wrap: wrap;
        }
        .panel-title { font-size: 17px; font-weight: 750; margin: 0 0 4px; }
        .panel-body { padding: 24px; }
        .form-label { font-size: 13px; font-weight: 650; color: #465066; }
        .form-control, .form-select {
            border-color: #e3e7ef; border-radius: 10px; min-height: 44px;
            font-size: 14px; box-shadow: none !important;
        }
        .form-control:focus, .form-select:focus { border-color: #a7a1ff; }
        textarea.form-control { min-height: 92px; }
        .btn-primary-custom {
            background: var(--product-primary); color: #fff; border: 0;
            border-radius: 10px; padding: 11px 16px; font-size: 13px; font-weight: 700;
        }
        .btn-primary-custom:hover { background: #4038ca; color: #fff; }
        .btn-soft {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            border: 1px solid var(--product-border); background: #fff; color: #4b5568;
            border-radius: 9px; padding: 9px 12px; text-decoration: none;
            font-size: 12px; font-weight: 650;
        }
        .btn-soft:hover { background: #f5f6fa; color: var(--product-primary); }
        .search-form { display: flex; gap: 8px; flex-wrap: wrap; }
        .search-form .form-control { min-width: 220px; }
        .count-chip {
            display: inline-flex; align-items: center; gap: 6px; background: #eeedff;
            color: var(--product-primary); border-radius: 30px; padding: 7px 11px;
            font-size: 12px; font-weight: 750;
        }
        .table { margin: 0; vertical-align: middle; }
        .table thead th {
            background: #fafbfe; color: var(--product-muted); font-size: 11px;
            font-weight: 750; text-transform: uppercase; letter-spacing: .5px;
            padding: 15px 18px; border-bottom: 1px solid var(--product-border);
            white-space: nowrap;
        }
        .table tbody td { padding: 14px 18px; font-size: 13px; border-bottom: 1px solid #f0f2f7; }
        .table tbody tr:last-child td { border-bottom: 0; }
        .product-thumb {
            width: 48px; height: 48px; border-radius: 12px; object-fit: cover;
            border: 1px solid var(--product-border); background: #f7f8fc;
        }
        .product-placeholder {
            width: 48px; height: 48px; border-radius: 12px; display: inline-flex;
            align-items: center; justify-content: center; color: var(--product-primary);
            background: #eeedff; font-size: 21px; flex-shrink: 0;
        }
        .product-name { font-weight: 750; color: #30394c; }
        .product-meta { color: var(--product-muted); font-size: 12px; margin-top: 3px; }
        .price { font-weight: 750; white-space: nowrap; color: #202b40; }
        .stock-pill {
            display: inline-flex; align-items: center; border-radius: 30px;
            padding: 5px 9px; font-size: 11px; font-weight: 750; white-space: nowrap;
        }
        .stock-ok { color: #128052; background: #e4f9ef; }
        .stock-low { color: #b66b00; background: #fff0df; }
        .stock-out { color: #c33434; background: #fff0f0; }
        .action-buttons { display: flex; gap: 7px; justify-content: flex-end; }
        .action-btn {
            width: 34px; height: 34px; display: inline-flex; align-items: center;
            justify-content: center; border: 1px solid var(--product-border);
            border-radius: 9px; background: #fff; color: #596276; text-decoration: none;
        }
        .action-btn:hover { background: #eeedff; color: var(--product-primary); }
        .action-btn.delete:hover { background: #fff0f0; color: #dc3545; }
        .empty-state { text-align: center; padding: 42px 20px !important; color: var(--product-muted); }
        .empty-state i { font-size: 34px; display: block; margin-bottom: 10px; color: #b3b9c8; }
        .image-preview { display: flex; align-items: center; gap: 12px; margin-top: 10px; }
        .small-note { font-size: 11px; color: var(--product-muted); margin-top: 5px; }
        @media (max-width: 768px) {
            .main-content { padding: 18px 14px; }
            .page-title { font-size: 25px; }
            .panel-header, .panel-body { padding: 17px; }
            .search-form { width: 100%; }
            .search-form .form-control { min-width: 0; flex: 1; }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . "/includes/sidebar.php"; ?>

<main class="main-content">
    <header class="page-header">
        <div>
            <h1 class="page-title">Products</h1>
            <p class="muted mb-0">Manage your inventory, prices, stock, and product images.</p>
        </div>
        <div class="count-chip">
            <i class="bi bi-box-seam-fill"></i>
            <?= number_format($productCount) ?> products shown
        </div>
    </header>

    <?php if ($message !== ""): ?>
        <div class="alert alert-<?= h($messageType) ?> alert-dismissible fade show" role="alert">
            <?= h($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!$columns): ?>
        <div class="alert alert-danger">
            The products table could not be read. Check your database connection and make sure the <code>products</code> table exists.
        </div>
    <?php elseif (!isset($columns["id"], $columns["name"], $columns["sell_price"])): ?>
        <div class="alert alert-danger">
            This page requires <code>id</code>, <code>name</code>, and <code>sell_price</code> columns in the <code>products</code> table.
        </div>
    <?php else: ?>

    <!-- ADD / EDIT PRODUCT -->
    <section class="panel" id="product-form">
        <div class="panel-header">
            <div>
                <h2 class="panel-title">
                    <i class="bi bi-<?= $editProduct ? 'pencil-square' : 'plus-circle' ?> me-2 text-primary"></i>
                    <?= $editProduct ? 'Edit Product' : 'Add New Product' ?>
                </h2>
                <p class="muted mb-0">
                    <?= $editProduct ? 'Update the details for this product.' : 'Add a product to your inventory.' ?>
                </p>
            </div>
            <?php if ($editProduct): ?>
                <a href="products.php" class="btn-soft"><i class="bi bi-x-lg"></i> Cancel edit</a>
            <?php endif; ?>
        </div>

        <div class="panel-body">
            <form method="POST" action="products.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["products_csrf"]) ?>">
                <input type="hidden" name="action" value="<?= $editProduct ? 'edit' : 'add' ?>">
                <?php if ($editProduct): ?>
                    <input type="hidden" name="id" value="<?= (int) $editProduct["id"] ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="name" class="form-label">Product Name <span class="text-danger">*</span></label>
                        <input type="text" id="name" name="name" class="form-control"
                               placeholder="e.g. Product name" maxlength="180" required
                               value="<?= h($editProduct["name"] ?? "") ?>">
                    </div>

                    <?php if (isset($columns["brand_name"])): ?>
                    <div class="col-12 col-md-6">
                        <label for="brand_name" class="form-label">Brand</label>
                        <input type="text" id="brand_name" name="brand_name" class="form-control"
                               placeholder="Enter brand name" maxlength="150"
                               value="<?= h($editProduct["brand_name"] ?? "") ?>">
                    </div>
                    <?php endif; ?>

                    <?php if ($skuColumn): ?>
                    <div class="col-12 col-md-6">
                        <label for="sku" class="form-label">SKU / Product Code</label>
                        <input type="text" id="sku" name="sku" class="form-control"
                               placeholder="Enter SKU or barcode" maxlength="100"
                               value="<?= h($editProduct[$skuColumn] ?? "") ?>">
                    </div>
                    <?php endif; ?>

                    <?php if ($categoryColumn): ?>
                    <div class="col-12 col-md-6">
                        <label for="category" class="form-label">Category</label>
                        <input type="text" id="category" name="category" class="form-control"
                               placeholder="Enter category" maxlength="100"
                               value="<?= h($editProduct[$categoryColumn] ?? "") ?>">
                    </div>
                    <?php endif; ?>

                    <?php if ($purchasePriceColumn): ?>
                    <div class="col-12 col-md-6">
                        <label for="purchase_price" class="form-label">Purchase Price (₹)</label>
                        <input type="number" id="purchase_price" name="purchase_price" class="form-control"
                               min="0" step="0.01" placeholder="0.00"
                               value="<?= h($editProduct[$purchasePriceColumn] ?? "") ?>">
                    </div>
                    <?php endif; ?>

                    <div class="col-12 col-md-6">
                        <label for="sell_price" class="form-label">Selling Price (₹) <span class="text-danger">*</span></label>
                        <input type="number" id="sell_price" name="sell_price" class="form-control"
                               min="0" step="0.01" placeholder="0.00" required
                               value="<?= h($editProduct["sell_price"] ?? "") ?>">
                    </div>

                    <?php if ($stockColumn): ?>
                    <div class="col-12 col-md-6">
                        <label for="stock_quantity" class="form-label">Stock Quantity</label>
                        <input type="number" id="stock_quantity" name="stock_quantity" class="form-control"
                               min="0" step="1" required placeholder="Enter available quantity"
                               value="<?= h($editProduct[$stockColumn] ?? "0") ?>">
                    </div>
                    <?php endif; ?>

                    <?php if ($expiryColumn): ?>
                    <div class="col-12 col-md-6">
                        <label for="expiry_date" class="form-label">Expiry Date</label>
                        <input type="date" id="expiry_date" name="expiry_date" class="form-control"
                               value="<?= h($editProduct[$expiryColumn] ?? "") ?>">
                    </div>
                    <?php endif; ?>

                    <?php if (isset($columns["image"])): ?>
                    <div class="col-12 col-md-6">
                        <label for="image" class="form-label">Product Image</label>
                        <input type="file" id="image" name="image" class="form-control"
                               accept="image/jpeg,image/png,image/webp,image/gif">
                        <div class="small-note">JPG, PNG, WEBP or GIF. Maximum 5 MB.</div>

                        <?php if ($editProduct && !empty($editProduct["image"])): ?>
                            <?php $currentImage = productImagePath($editProduct["image"]); ?>
                            <?php if ($currentImage): ?>
                            <div class="image-preview">
                                <img src="<?= h($currentImage) ?>" class="product-thumb" alt="Current product image">
                                <span class="muted">Current image. Choose another file to replace it.</span>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($descriptionColumn): ?>
                    <div class="col-12">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" class="form-control"
                                  placeholder="Optional product details"><?= h($editProduct[$descriptionColumn] ?? "") ?></textarea>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn-primary-custom">
                        <i class="bi bi-<?= $editProduct ? 'check2-circle' : 'plus-lg' ?> me-1"></i>
                        <?= $editProduct ? 'Update Product' : 'Save Product' ?>
                    </button>

                    <?php if (!$editProduct): ?>
                        <button type="reset" class="btn-soft">
                            <i class="bi bi-arrow-counterclockwise"></i> Clear Form
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <!-- PRODUCT INVENTORY -->
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2 class="panel-title"><i class="bi bi-boxes me-2 text-primary"></i>Product Inventory</h2>
                <p class="muted mb-0">View and manage the products in your store.</p>
            </div>

            <form method="GET" action="products.php" class="search-form">
                <input type="search" name="search" class="form-control"
                       placeholder="Search products..."
                       value="<?= h($search) ?>" aria-label="Search products">
                <button type="submit" class="btn-primary-custom">
                    <i class="bi bi-search me-1"></i> Search
                </button>
                <?php if ($search !== ""): ?>
                    <a href="products.php" class="btn-soft">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Product</th>
                        <?php if (isset($columns["brand_name"])): ?><th>Brand</th><?php endif; ?>
                        <?php if ($categoryColumn): ?><th>Category</th><?php endif; ?>
                        <?php if ($purchasePriceColumn): ?><th>Purchase Price</th><?php endif; ?>
                        <th>Selling Price</th>
                        <?php if ($stockColumn): ?><th>Stock</th><?php endif; ?>
                        <?php if ($expiryColumn): ?><th>Expiry</th><?php endif; ?>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$products): ?>
                    <tr>
                        <td colspan="9" class="empty-state">
                            <i class="bi bi-box-seam"></i>
                            <div class="fw-bold mb-1">
                                <?= $search !== "" ? "No matching products" : "No products added yet" ?>
                            </div>
                            <div class="small">
                                <?= $search !== "" ? "Try another search term." : "Use the form above to add your first product." ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php $imagePath = productImagePath($product["image"] ?? null); ?>
                                    <?php if ($imagePath): ?>
                                        <img src="<?= h($imagePath) ?>" class="product-thumb me-3"
                                             alt="<?= h($product["name"]) ?>"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                                        <span class="product-placeholder me-3" style="display:none;">
                                            <i class="bi bi-box-seam"></i>
                                        </span>
                                    <?php else: ?>
                                        <span class="product-placeholder me-3"><i class="bi bi-box-seam"></i></span>
                                    <?php endif; ?>

                                    <div>
                                        <div class="product-name"><?= h($product["name"]) ?></div>
                                        <?php if ($skuColumn && !empty($product[$skuColumn])): ?>
                                            <div class="product-meta">SKU: <?= h($product[$skuColumn]) ?></div>
                                        <?php elseif (isset($product["id"])): ?>
                                            <div class="product-meta">ID: <?= (int) $product["id"] ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <?php if (isset($columns["brand_name"])): ?>
                                <td><?= h(($product["brand_name"] ?? "") ?: "—") ?></td>
                            <?php endif; ?>

                            <?php if ($categoryColumn): ?>
                                <td><?= h(($product[$categoryColumn] ?? "") ?: "—") ?></td>
                            <?php endif; ?>

                            <?php if ($purchasePriceColumn): ?>
                                <td>₹<?= number_format((float) ($product[$purchasePriceColumn] ?? 0), 2) ?></td>
                            <?php endif; ?>

                            <td class="price">₹<?= number_format((float) $product["sell_price"], 2) ?></td>

                            <?php if ($stockColumn): ?>
                                <?php $stockValue = (int) ($product[$stockColumn] ?? 0); ?>
                                <td>
                                    <span class="stock-pill <?= $stockValue <= 0 ? 'stock-out' : ($stockValue <= 5 ? 'stock-low' : 'stock-ok') ?>">
                                        <?= $stockValue <= 0 ? "Out of stock" : h($stockValue) . " in stock" ?>
                                    </span>
                                </td>
                            <?php endif; ?>

                            <?php if ($expiryColumn): ?>
                                <td>
                                    <?php
                                    $expiryValue = $product[$expiryColumn] ?? "";
                                    if ($expiryValue) {
                                        $expiryTimestamp = strtotime($expiryValue);
                                        echo $expiryTimestamp ? h(date("d M Y", $expiryTimestamp)) : h($expiryValue);
                                    } else {
                                        echo "—";
                                    }
                                    ?>
                                </td>
                            <?php endif; ?>

                            <td>
                                <div class="action-buttons">
                                    <a class="action-btn" href="products.php?edit=<?= (int) $product["id"] ?>#product-form"
                                       title="Edit product" aria-label="Edit product">
                                        <i class="bi bi-pencil"></i>
                                    </a>

                                    <form method="POST" action="products.php"
                                          onsubmit="return confirm('Delete this product? If it is linked to an invoice, the database may prevent deletion.');">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION["products_csrf"]) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $product["id"] ?>">
                                        <button type="submit" class="action-btn delete"
                                                title="Delete product" aria-label="Delete product">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
