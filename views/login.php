<?php
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';

startSession();
if (!empty($_SESSION['user_id'])) {
    redirect('../index.php');
}
$flash    = takeFlash();
$username = $_SESSION['login_username'] ?? '';
unset($_SESSION['login_username']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập · <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('../', 'images/logo.svg')) ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?= e(asset('../', 'css/style.css')) ?>" rel="stylesheet">
</head>
<body class="login-page">
    <main class="login-wrap">
    <form class="login-card" method="post" action="../handle/login_process.php">
        <div class="login-brand">
            <img class="login-logo" src="<?= e(asset('../', 'images/logo.svg')) ?>" alt="">
            <h1 class="brand-name">Omni<span>Stock</span></h1>
            <p class="login-subtitle"><?= APP_TAGLINE ?> · nhập, xuất, điều chuyển, kiểm kê</p>
        </div>

        <?php if ($flash): ?>
            <?php if ($flash['message'] === LOCKED_ACCOUNT_MESSAGE): ?>
                <div class="alert-error"><i class="fas fa-lock"></i> <strong>Tài khoản đang bị khóa.</strong><br><?= e(substr(LOCKED_ACCOUNT_MESSAGE, strlen('Tài khoản của bạn đang bị khóa. '))) ?></div>
            <?php else: ?>
                <div class="alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>
        <?php endif; ?>

        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <div class="form-group">
            <label for="username">Tên đăng nhập</label>
            <input class="form-control" id="username" name="username" value="<?= e($username) ?>" required autofocus autocomplete="username">
        </div>
        <div class="form-group">
            <label for="password">Mật khẩu</label>
            <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary login-submit" type="submit"><i class="fas fa-sign-in-alt"></i> Đăng nhập</button>
    </form>
    <p class="login-footer">© <?= date('Y') ?> <?= APP_NAME ?></p>
    </main>
</body>
</html>
