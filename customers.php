<?php
declare(strict_types=1);
require_once __DIR__ . "/config/database.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$message = $_SESSION["customer_message"] ?? "";
$messageType = $_SESSION["customer_message_type"] ?? "success";
unset($_SESSION["customer_message"], $_SESSION["customer_message_type"]);

$columns = [];
$customers = [];
$editCustomer = null;
$search = trim((string)($_GET["search"] ?? ""));
$editId = (int)($_GET["edit"] ?? 0);

try {
    foreach ($pdo->query("SHOW COLUMNS FROM `customers`")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column["Field"]] = true;
    }
    if (!isset($columns["id"], $columns["name"])) {
        throw new RuntimeException("The customers table must contain at least id and name columns.");
    }
} catch (Throwable $e) {
    $columns = [];
    $message = "Could not read the customers table. Check your database connection and table structure.";
    $messageType = "danger";
}

if ($columns && $_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string)($_POST["action"] ?? "");
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["customer_csrf"] ?? "", $token)) {
        $message = "Your session token expired. Please refresh the page and try again.";
        $messageType = "danger";
    } else {
        try {
            if (in_array($action, ["add", "edit"], true)) {
                $name = trim((string)($_POST["name"] ?? ""));
                $phone = trim((string)($_POST["phone"] ?? ""));
                $email = trim((string)($_POST["email"] ?? ""));
                $address = trim((string)($_POST["address"] ?? ""));
                $id = (int)($_POST["id"] ?? 0);

                if ($name === "") {
                    throw new RuntimeException("Customer name is required.");
                }
                if ($email !== "" && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException("Please enter a valid email address.");
                }

                $data = ["name" => $name];
                foreach (["phone" => $phone, "email" => $email, "address" => $address] as $field => $value) {
                    if (isset($columns[$field])) {
                        $data[$field] = $value;
                    }
                }

                if ($action === "add") {
                    $fields = array_keys($data);
                    $sql = "INSERT INTO `customers` (" . implode(", ", array_map(fn($f) => "`$f`", $fields)) .
                           ") VALUES (" . implode(", ", array_fill(0, count($fields), "?")) . ")";
                    $pdo->prepare($sql)->execute(array_values($data));
                    $_SESSION["customer_message"] = "Customer added successfully.";
                    $_SESSION["customer_message_type"] = "success";
                } else {
                    if ($id < 1) {
                        throw new RuntimeException("Invalid customer selected.");
                    }
                    $set = implode(", ", array_map(fn($f) => "`$f` = ?", array_keys($data)));
                    $stmt = $pdo->prepare("UPDATE `customers` SET $set WHERE `id` = ?");
                    $stmt->execute([...array_values($data), $id]);
                    $_SESSION["customer_message"] = "Customer updated successfully.";
                    $_SESSION["customer_message_type"] = "success";
                }
            } elseif ($action === "delete") {
                $id = (int)($_POST["id"] ?? 0);
                if ($id < 1) {
                    throw new RuntimeException("Invalid customer selected.");
                }
                $stmt = $pdo->prepare("DELETE FROM `customers` WHERE `id` = ?");
                $stmt->execute([$id]);
                $_SESSION["customer_message"] = $stmt->rowCount() ? "Customer deleted successfully." : "Customer was not found.";
                $_SESSION["customer_message_type"] = $stmt->rowCount() ? "success" : "warning";
            } else {
                throw new RuntimeException("Invalid action.");
            }
        } catch (RuntimeException $e) {
            $_SESSION["customer_message"] = $e->getMessage();
            $_SESSION["customer_message_type"] = "danger";
        } catch (Throwable $e) {
            $_SESSION["customer_message"] = "Could not complete this change. The customer may be linked to an invoice or a required database field may be missing.";
            $_SESSION["customer_message_type"] = "danger";
        }
    }

    $redirect = "customers.php";
    if ($search !== "") {
        $redirect .= "?search=" . urlencode($search);
    }
    header("Location: " . $redirect);
    exit;
}

if (!isset($_SESSION["customer_csrf"])) {
    $_SESSION["customer_csrf"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["customer_csrf"];

if ($columns && $editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM `customers` WHERE `id` = ?");
    $stmt->execute([$editId]);
    $editCustomer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editCustomer) {
        $message = "Customer not found.";
        $messageType = "warning";
    }
}

if ($columns) {
    $params = [];
    $conditions = [];
    if ($search !== "") {
        foreach (["name", "phone", "email", "address"] as $field) {
            if (isset($columns[$field])) {
                $conditions[] = "`$field` LIKE ?";
                $params[] = "%" . $search . "%";
            }
        }
    }
    $sql = "SELECT * FROM `customers`" . ($conditions ? " WHERE " . implode(" OR ", $conditions) : "") . " ORDER BY `id` DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$customerCount = count($customers);
$columnCount = 2 + (int)isset($columns["phone"]) + (int)isset($columns["email"]) + (int)isset($columns["address"]);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customers | Brainbean POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root{--cp:#4f46e5;--ct:#172033;--cm:#7b8498;--cb:#edf0f5;--bg:#f5f7fb}
        body{background:var(--bg);color:var(--ct);font-family:Inter,"Segoe UI",Arial,sans-serif}
        .main-content{padding:28px;min-width:0}.page-header{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:24px}
        .page-title{font-size:29px;font-weight:750;letter-spacing:-.7px;margin:0 0 6px}.muted{color:var(--cm);font-size:13px}
        .panel{background:#fff;border:1px solid var(--cb);border-radius:18px;box-shadow:0 4px 18px #141f3809;overflow:hidden;margin-bottom:24px}
        .panel-header{padding:21px 24px;border-bottom:1px solid var(--cb);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
        .panel-title{font-size:17px;font-weight:750;margin:0 0 4px}.panel-body{padding:24px}.form-label{font-size:13px;font-weight:650;color:#465066}
        .form-control{border-color:#e3e7ef;border-radius:10px;min-height:44px;font-size:14px;box-shadow:none!important}.form-control:focus{border-color:#a7a1ff}
        textarea.form-control{min-height:92px}.btn-primary-custom{background:var(--cp);color:#fff;border:0;border-radius:10px;padding:11px 16px;font-size:13px;font-weight:700}
        .btn-primary-custom:hover{background:#4038ca;color:#fff}.btn-soft{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--cb);background:#fff;color:#4b5568;border-radius:9px;padding:9px 12px;text-decoration:none;font-size:12px;font-weight:650}
        .btn-soft:hover{background:#f5f6fa;color:var(--cp)}.search-form{display:flex;gap:8px;flex-wrap:wrap}.search-form .form-control{min-width:220px}
        .table{margin:0;vertical-align:middle}.table thead th{background:#fafbfe;color:var(--cm);font-size:11px;font-weight:750;text-transform:uppercase;letter-spacing:.5px;padding:15px 18px;border-bottom:1px solid var(--cb);white-space:nowrap}
        .table tbody td{padding:15px 18px;font-size:13px;border-bottom:1px solid #f0f2f7}.table tbody tr:last-child td{border-bottom:0}
        .customer-avatar{width:38px;height:38px;border-radius:12px;background:#eeedff;color:var(--cp);display:inline-flex;align-items:center;justify-content:center;font-weight:750;margin-right:10px}
        .customer-name{font-weight:700}.empty-state{text-align:center;padding:42px 20px!important;color:var(--cm)}.empty-state i{font-size:34px;display:block;margin-bottom:10px;color:#b3b9c8}
        .count-chip{display:inline-flex;align-items:center;gap:6px;background:#eeedff;color:var(--cp);border-radius:30px;padding:7px 11px;font-size:12px;font-weight:750}
        .action-buttons{display:flex;gap:7px;justify-content:flex-end}.action-btn{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--cb);border-radius:9px;background:#fff;color:#596276;text-decoration:none}
        .action-btn:hover{background:#eeedff;color:var(--cp)}.action-btn.delete:hover{background:#fff0f0;color:#dc3545}
        @media(max-width:768px){.main-content{padding:18px 14px}.page-title{font-size:25px}.panel-header,.panel-body{padding:17px}.search-form{width:100%}.search-form .form-control{min-width:0;flex:1}}
    </style>
</head>
<body>
<?php require_once __DIR__ . "/includes/sidebar.php"; ?>
<main class="main-content">
    <header class="page-header">
        <div><h1 class="page-title">Customers</h1><p class="muted mb-0">Manage your customer records in one place.</p></div>
        <div class="count-chip"><i class="bi bi-people-fill"></i><?= number_format($customerCount) ?> shown</div>
    </header>

    <?php if ($message !== ""): ?>
        <div class="alert alert-<?= h($messageType) ?> alert-dismissible fade show" role="alert">
            <?= h($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!$columns): ?>
        <div class="alert alert-danger">The customers table could not be read. Check the database connection and ensure the <code>customers</code> table exists with <code>id</code> and <code>name</code> fields.</div>
    <?php else: ?>
    <section class="panel" id="customer-form">
        <div class="panel-header">
            <div><h2 class="panel-title"><i class="bi bi-<?= $editCustomer ? 'pencil-square' : 'person-plus' ?> me-2 text-primary"></i><?= $editCustomer ? 'Edit Customer' : 'Add New Customer' ?></h2>
            <p class="muted mb-0"><?= $editCustomer ? 'Update the customer information below.' : 'Enter customer details to create a record.' ?></p></div>
            <?php if ($editCustomer): ?><a href="customers.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>" class="btn-soft"><i class="bi bi-x-lg"></i> Cancel edit</a><?php endif; ?>
        </div>
        <div class="panel-body">
            <form method="post" action="customers.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="<?= $editCustomer ? 'edit' : 'add' ?>">
                <?php if ($editCustomer): ?><input type="hidden" name="id" value="<?= (int)$editCustomer["id"] ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-12 col-md-6"><label for="name" class="form-label">Customer Name <span class="text-danger">*</span></label><input type="text" id="name" name="name" class="form-control" placeholder="Enter full name" maxlength="150" required value="<?= h($editCustomer["name"] ?? "") ?>"></div>
                    <?php if (isset($columns["phone"])): ?><div class="col-12 col-md-6"><label for="phone" class="form-label">Phone Number</label><input type="tel" id="phone" name="phone" class="form-control" placeholder="Enter phone number" maxlength="30" value="<?= h($editCustomer["phone"] ?? "") ?>"></div><?php endif; ?>
                    <?php if (isset($columns["email"])): ?><div class="col-12 col-md-6"><label for="email" class="form-label">Email Address</label><input type="email" id="email" name="email" class="form-control" placeholder="name@example.com" maxlength="150" value="<?= h($editCustomer["email"] ?? "") ?>"></div><?php endif; ?>
                    <?php if (isset($columns["address"])): ?><div class="col-12 col-md-6"><label for="address" class="form-label">Address</label><textarea id="address" name="address" class="form-control" placeholder="Enter customer address"><?= h($editCustomer["address"] ?? "") ?></textarea></div><?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-4"><button type="submit" class="btn-primary-custom"><i class="bi bi-<?= $editCustomer ? 'check2-circle' : 'person-plus' ?> me-1"></i><?= $editCustomer ? 'Update Customer' : 'Save Customer' ?></button>
                <?php if (!$editCustomer): ?><button type="reset" class="btn-soft"><i class="bi bi-arrow-counterclockwise"></i> Clear Form</button><?php endif; ?></div>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div><h2 class="panel-title"><i class="bi bi-people me-2 text-primary"></i>Customer Directory</h2><p class="muted mb-0">Search, edit, and manage saved customers.</p></div>
            <form method="get" action="customers.php" class="search-form"><input type="search" name="search" class="form-control" placeholder="Search name, phone, email..." value="<?= h($search) ?>" aria-label="Search customers">
                <button type="submit" class="btn-primary-custom"><i class="bi bi-search me-1"></i>Search</button><?php if ($search !== ""): ?><a href="customers.php" class="btn-soft">Reset</a><?php endif; ?></form>
        </div>
        <div class="table-responsive"><table class="table table-hover">
            <thead><tr><th>Customer</th><?php if (isset($columns["phone"])): ?><th>Phone</th><?php endif; ?><?php if (isset($columns["email"])): ?><th>Email</th><?php endif; ?><?php if (isset($columns["address"])): ?><th>Address</th><?php endif; ?><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if (!$customers): ?><tr><td colspan="<?= $columnCount ?>" class="empty-state"><i class="bi bi-person-lines-fill"></i><div class="fw-bold mb-1"><?= $search !== "" ? "No matching customers" : "No customers added yet" ?></div><div class="small"><?= $search !== "" ? "Try a different name, phone number, email, or address." : "Use the form above to add your first customer." ?></div></td></tr>
            <?php else: foreach ($customers as $customer): ?>
                <tr><td><span class="customer-avatar"><?= h(strtoupper(substr(trim((string)($customer["name"] ?? "C")), 0, 1))) ?></span><span class="customer-name"><?= h($customer["name"] ?? "") ?></span></td>
                <?php if (isset($columns["phone"])): ?><td><?= h(($customer["phone"] ?? "") ?: "—") ?></td><?php endif; ?>
                <?php if (isset($columns["email"])): ?><td><?= h(($customer["email"] ?? "") ?: "—") ?></td><?php endif; ?>
                <?php if (isset($columns["address"])): ?><td><?= h(($customer["address"] ?? "") ?: "—") ?></td><?php endif; ?>
                <td><div class="action-buttons"><a class="action-btn" href="customers.php?edit=<?= (int)$customer["id"] ?><?= $search !== "" ? '&search=' . urlencode($search) : '' ?>" title="Edit customer" aria-label="Edit customer"><i class="bi bi-pencil"></i></a>
                <form method="post" action="customers.php<?= $search !== "" ? '?search=' . urlencode($search) : '' ?>" onsubmit="return confirm('Delete this customer? If they have invoices linked, the database may prevent deletion.');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$customer["id"] ?>">
                    <button type="submit" class="action-btn delete" title="Delete customer" aria-label="Delete customer"><i class="bi bi-trash3"></i></button></form></div></td></tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
