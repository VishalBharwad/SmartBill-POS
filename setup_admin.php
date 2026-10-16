
<?php
require_once 'config/database.php';

$username = 'admin';
$password = 'Admin@123';

$check = $pdo->prepare(
    "SELECT id FROM admins WHERE username = ?"
);
$check->execute([$username]);

if (!$check->fetch()) {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        "INSERT INTO admins (username, password) VALUES (?, ?)"
    );
    $stmt->execute([$username, $hash]);

    echo "Default admin created successfully.";
} else {
    echo "Admin account already exists.";
}

echo "<br>Username: admin";
echo "<br>Password: Admin@123";
?>
