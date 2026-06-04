<?php
session_start();
require __DIR__ . '/config.php';

$errors = [];
$success = '';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    $errors[] = 'Database connection failed.';
}

// Load current user
$user = null;
if (empty($errors)) {
    $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        $errors[] = 'User not found.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    if (isset($_POST['update_name'])) {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Name cannot be empty.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET name = :name WHERE id = :id');
            $stmt->execute([':name' => $name, ':id' => $userId]);
            $_SESSION['user_name'] = $name;
            $success = 'Name updated.';
        }
    }

    if (isset($_POST['change_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($current === '' || $new === '' || $confirm === '') {
            $errors[] = 'All password fields are required.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } elseif (strlen($new) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } else {
            // verify current password
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($current, $row['password_hash'])) {
                $errors[] = 'Current password is incorrect.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                $stmt->execute([':hash' => $hash, ':id' => $userId]);
                $success = 'Password changed successfully.';
            }
        }
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Account</title>
    <link rel="stylesheet" href="style.css">
    <style>body{padding:24px;font-family:Arial,Helvetica,sans-serif} label{display:block;margin:8px 0} .small{font-size:0.9rem;color:#666}</style>
</head>
<body>
    <div style="max-width:720px;margin:20px auto;">
        <h1>Account Settings</h1>
        <?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul></div><?php endif; ?>

        <section style="margin-bottom:20px;">
            <h2>Edit name</h2>
            <form method="post" action="">
                <label>Name <input type="text" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required></label>
                <div style="margin-top:8px;"><button type="submit" name="update_name">Save name</button></div>
            </form>
        </section>

        <section>
            <h2>Change password</h2>
            <form method="post" action="">
                <label>Current password <input type="password" name="current_password" required></label>
                <label>New password <input type="password" name="new_password" required></label>
                <label>Confirm new password <input type="password" name="confirm_password" required></label>
                <div style="margin-top:8px;"><button type="submit" name="change_password">Change password</button></div>
            </form>
        </section>

        <div style="margin-top:18px;"><a href="index.php">Back to dashboard</a> • <a href="logout.php">Sign out</a></div>
    </div>
</body>
</html>
