<?php
// Xử lý Phiếu nhập / xuất: lập phiếu, duyệt, từ chối. Chỉ nhận POST, mọi kiểm tra đều ở server.
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';

$user = requireLogin('../');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Chỉ chấp nhận POST.');
}
verifyCsrf();

$action    = (string) ($_POST['action'] ?? '');
$voucherId = (int) ($_POST['id'] ?? 0);
$type      = ($_POST['type'] ?? '') === 'EXPORT' ? 'EXPORT' : 'IMPORT';

try {
    $pdo = getDbConnection();
    switch ($action) {
        case 'create':
            $voucherId = createVoucher($pdo, $user, $_POST);
            unset($_SESSION['voucher_form_old']);
            setFlash('success', 'Đã lưu phiếu, đang chờ quản lý kho duyệt.');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        case 'approve':
            approveVoucher($pdo, $user, $voucherId);
            setFlash('success', 'Đã xác nhận chứng từ: sổ phát sinh và tồn kho đã được cập nhật.');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        case 'reject':
            rejectVoucher($pdo, $user, $voucherId);
            setFlash('success', 'Đã từ chối chứng từ. Không có phát sinh nào được ghi.');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        default:
            http_response_code(400);
            exit('Thao tác không hợp lệ.');
    }
} catch (VoucherException $e) {
    setFlash('error', $e->getMessage());
} catch (PDOException $e) {
    // Transaction đã rollBack trong hàm nghiệp vụ -> không có thay đổi dở dang
    error_log('[voucher_process] ' . $e->getMessage());
    setFlash('error', 'Lỗi CSDL, thao tác đã được hoàn tác: ' . $e->getMessage());
}

// Lỗi: quay lại form (giữ dữ liệu đã nhập) hoặc trang chi tiết
if ($action === 'create') {
    $_SESSION['voucher_form_old'] = $_POST;
    redirect('../views/voucher_form.php?type=' . $type);
}
redirect('../views/voucher_detail.php?id=' . $voucherId);
