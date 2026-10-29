<?php
// Xử lý yêu cầu điều chuyển: lập, đồng ý (sinh phiếu điều chuyển đã giữ hàng), từ chối, rút lại. Chỉ nhận POST.
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/transfer_request_functions.php';

$user = requireLogin('../');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Chỉ chấp nhận POST.');
}
verifyCsrf();

$action    = (string) ($_POST['action'] ?? '');
$requestId = (int) ($_POST['id'] ?? 0);
$detailUrl = fn (int $id) => '../views/transfer_request_detail.php?id=' . $id;

try {
    $pdo = getDbConnection();
    switch ($action) {
        case 'create':
            $requestId = createTransferRequest($pdo, $user, $_POST);
            unset($_SESSION['transfer_request_old']);
            setFlash('success', 'Đã gửi yêu cầu điều chuyển. Quản lý kho được đề nghị sẽ đồng ý hoặc từ chối.');
            redirect($detailUrl($requestId));

        case 'accept':
            $voucherId = acceptTransferRequest($pdo, $user, $requestId, (string) ($_POST['note'] ?? ''));
            setFlash('success', 'Đã đồng ý: lập phiếu điều chuyển ' . getVoucher($pdo, $voucherId)['code'] . ' và giữ chỗ hàng. Khi xe rời kho, bấm "Gửi hàng".');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        case 'decline':
            declineTransferRequest($pdo, $user, $requestId, (string) ($_POST['note'] ?? ''));
            setFlash('success', 'Đã từ chối yêu cầu. Bên yêu cầu sẽ thấy lý do.');
            redirect($detailUrl($requestId));

        case 'cancel':
            cancelTransferRequest($pdo, $user, $requestId);
            setFlash('success', 'Đã rút lại yêu cầu.');
            redirect($detailUrl($requestId));

        default:
            http_response_code(400);
            exit('Thao tác không hợp lệ.');
    }
} catch (AppException $e) {
    setFlash('error', $e->getMessage());
} catch (PDOException $e) {
    error_log('[transfer_request_process] ' . $e->getMessage());
    setFlash('error', dbErrorMessage($e));
}

if ($action === 'create') {
    $_SESSION['transfer_request_old'] = $_POST;          // giữ dữ liệu đã nhập khi quay lại form
    redirect('../views/transfer_request_form.php');
}
redirect($detailUrl($requestId));
