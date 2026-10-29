<?php
// Xử lý đăng nhập / đăng xuất
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../views/login.php');
}
verifyCsrf();

if (($_POST['action'] ?? '') === 'logout') {
    logoutUser();
    redirect('../views/login.php');
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

try {
    $pdo = getDbConnection();
    if ($username !== '' && attemptLogin($pdo, $username, $password)) {
        redirect('../index.php');
    }
    setFlash('error', isLockedAccount($pdo, $username, $password) ? LOCKED_ACCOUNT_MESSAGE : 'Sai tên đăng nhập hoặc mật khẩu.');
} catch (PDOException $e) {
    setFlash('error', 'Không kết nối được CSDL: ' . $e->getMessage());
}
$_SESSION['login_username'] = $username;
redirect('../views/login.php');
