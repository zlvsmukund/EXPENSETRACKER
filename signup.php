<?php
session_start();
require __DIR__ . '/config.php';

$errors = [];

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    $errors[] = 'Could not connect to the database.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if ($name === '') $errors[] = 'Enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                $errors[] = 'An account with that email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :hash)');
                $insert->execute([':name' => $name, ':email' => $email, ':hash' => $hash]);
                $userId = (int)$pdo->lastInsertId();
                $_SESSION['user_id'] = $userId;
                $_SESSION['user_name'] = $name;
                header('Location: index.php');
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not create account. Try again later.';
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign up</title>
    <link rel="stylesheet" href="style.css">
    <style>body{padding:24px;font-family:Arial,Helvetica,sans-serif}</style>
</head>
<body>
    <div style="max-width:480px;margin:24px auto;">
        <h1>Create account</h1>
        <?php if ($errors): ?>
            <div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul></div>
        <?php endif; ?>
        <form method="post" action="">
            <label>Name <input type="text" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required></label>
            <label>Email <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required></label>
            <label>Password <input type="password" name="password" required></label>
            <label>Confirm <input type="password" name="confirm" required></label>
            <div style="margin-top:12px;"><button type="submit">Sign up</button> <a href="login.php">Already have an account?</a></div>
        </form>
    </div>
</body>
</html>
