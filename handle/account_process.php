<?php
// Tài khoản & phân quyền kho: cấp tài khoản, sửa vai trò / kho phụ trách, khóa / mở khóa, đặt lại mật khẩu (chỉ quản trị);
// đổi mật khẩu của chính mình (mọi người). Chỉ nhận POST.
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/account_functions.php';

$action = (string) ($_POST['action'] ?? '');
// Trang đổi mật khẩu phải dùng được khi tài khoản còn mật khẩu tạm
$user = requireLogin('../', $action === 'change_password');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Chỉ chấp nhận POST.');
}
verifyCsrf();

$accountId = (int) ($_POST['id'] ?? 0);
$back      = '../views/account_list.php';
parse_str((string) ($_POST['return_query'] ?? ''), $query);
$query = array_intersect_key($query, array_flip(['q', 'role', 'status', 'sort', 'dir', 'size', 'page']));
$back .= $query ? '?' . http_build_query($query) : '';

/** Mật khẩu tạm chỉ hiện 1 lần trên trang danh sách (không lưu ở đâu khác) */
$showSecret = function (string $username, string $password, string $why): void {
    $_SESSION['account_secret'] = ['username' => $username, 'password' => $password, 'why' => $why];
};

try {
    $pdo = getDbConnection();
    switch ($action) {
        case 'create':
            [$newId, $password] = createAccount($pdo, $user, $_POST);
            $showSecret(getAccount($pdo, $newId)['username'], $password, 'Đã cấp tài khoản mới');
            redirect($back);

        case 'update':
            updateAccount($pdo, $user, $accountId, $_POST);
            setFlash('success', 'Đã lưu tài khoản. Quyền mới có hiệu lực ngay ở lần tải trang tiếp theo của người đó.');
            redirect($back);

        case 'lock':
        case 'unlock':
            setAccountActive($pdo, $user, $accountId, $action === 'unlock');
            setFlash('success', $action === 'lock' ? 'Đã khóa tài khoản: người này không đăng nhập được nữa, lịch sử chứng từ giữ nguyên.' : 'Đã mở khóa tài khoản.');
            redirect($back);

        case 'reset_password':
            $password = resetAccountPassword($pdo, $user, $accountId);
            $showSecret(getAccount($pdo, $accountId)['username'], $password, 'Đã đặt lại mật khẩu');
            redirect($back);

        case 'change_password':
            changeOwnPassword($pdo, $user, (string) ($_POST['current'] ?? ''), (string) ($_POST['new'] ?? ''), (string) ($_POST['confirm'] ?? ''));
            setFlash('success', 'Đã đổi mật khẩu.');
            redirect('../index.php');

        default:
            http_response_code(400);
            exit('Thao tác không hợp lệ.');
    }
} catch (AppException $e) {
    $message = $e->getMessage();
} catch (PDOException $e) {
    error_log('[account_process] ' . $e->getMessage());
    $message = dbErrorMessage($e);
}

if ($action === 'change_password') {
    setFlash('error', $message);
    redirect('../views/change_password.php');
}
if (in_array($action, ['create', 'update'], true)) {
    keepModalState('account_modal', $_POST, $message);   // mở lại popup kèm dữ liệu đã nhập và lỗi
} else {
    setFlash('error', $message);
}
redirect($back);
