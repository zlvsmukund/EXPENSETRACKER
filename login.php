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
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if ($password === '') $errors[] = 'Enter your password.';

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('SELECT id, name, password_hash FROM users WHERE email = :email');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['user_name'] = $user['name'];
                header('Location: index.php');
                exit;
            } else {
                $errors[] = 'Incorrect email or password.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Login failed. Try again later.';
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login</title>
    <link rel="stylesheet" href="style.css">
    <style>body{padding:24px;font-family:Arial,Helvetica,sans-serif}</style>
</head>
<body>
    <div style="max-width:480px;margin:24px auto;">
        <h1>Sign in</h1>
        <?php if ($errors): ?>
            <div class="alert error"><ul><?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul></div>
        <?php endif; ?>
        <form method="post" action="">
            <label>Email <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required></label>
            <label>Password <input type="password" name="password" required></label>
            <div style="margin-top:12px;"><button type="submit">Login</button> <a href="signup.php">Create account</a></div>
        </form>
    </div>
</body>
</html>
