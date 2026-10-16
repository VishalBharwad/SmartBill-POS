
<?php
require_once 'auth.php';
require_once 'config/database.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if (empty($_SESSION['login_token'])) {
    $_SESSION['login_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['login_token'] ?? '';

    if (!hash_equals($_SESSION['login_token'], $token)) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare(
            'SELECT id, username, password FROM admins WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

     if ($admin && $password === $admin['password']) {
            session_regenerate_id(true);

            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];

            unset($_SESSION['login_token']);

            header('Location: index.php');
            exit;
        }

        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login | Brainbean POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #f3f5fb;
            font-family: Arial, sans-serif;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 35px;
            background: white;
            border-radius: 18px;
            box-shadow: 0 12px 40px rgba(20, 30, 60, .10);
        }
        .brand-icon {
            width: 58px;
            height: 58px;
            margin: 0 auto 16px;
            display: grid;
            place-items: center;
            border-radius: 16px;
            color: white;
            background: #4338ca;
            font-size: 26px;
            font-weight: bold;
        }
        .form-control { padding: 12px; }
        .btn-login {
            padding: 12px;
            background: #4338ca;
            border: 0;
            color: white;
        }
        .btn-login:hover {
            background: #3730a3;
            color: white;
        }
    </style>
</head>
<body>
<div class="login-card">
    <div class="text-center mb-4">
        <div class="brand-icon">B</div>
        <h3 class="fw-bold">Brainbean POS</h3>
        <p class="text-secondary">Sign in to your admin account</p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input type="hidden" name="login_token"
               value="<?= htmlspecialchars($_SESSION['login_token'], ENT_QUOTES, 'UTF-8') ?>">

        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control"
                   autocomplete="username" required autofocus>
        </div>

        <div class="mb-4">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control"
                   autocomplete="current-password" required>
        </div>

        <button type="submit" class="btn btn-login w-100">
            Login as Admin
        </button>
    </form>
</div>
</body>
</html>
