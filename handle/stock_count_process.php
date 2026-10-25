<?php
// Xử lý Kiểm kê: tạo đợt, lưu số đếm, duyệt (sinh phiếu điều chỉnh), hủy. Chỉ nhận POST.
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/stock_count_functions.php';

$user = requireLogin('../');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Chỉ chấp nhận POST.');
}
verifyCsrf();

$action  = (string) ($_POST['action'] ?? '');
$countId = (int) ($_POST['id'] ?? 0);

try {
    $pdo = getDbConnection();
    switch ($action) {
        case 'create':
            $countId = createStockCount($pdo, $user, (int) ($_POST['warehouse_id'] ?? 0), (string) ($_POST['note'] ?? ''));
            setFlash('success', 'Đã mở đợt kiểm kê và chụp số tồn. Kho tạm khóa giao dịch cho tới khi chốt đợt.');
            redirect('../views/stock_count_detail.php?id=' . $countId);

        case 'save':
            $entries = is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [];
            $changed = saveStockCountEntries($pdo, $user, $countId, $entries);
            setFlash('success', "Đã lưu số đếm ($changed dòng thay đổi).");
            redirect('../views/stock_count_detail.php?id=' . $countId);

        case 'approve':
            $adjustmentId = approveStockCount($pdo, $user, $countId, (string) ($_POST['reason'] ?? ''));
            setFlash('success', $adjustmentId
                ? 'Đã duyệt kiểm kê: phiếu điều chỉnh đã được ghi sổ, tồn kho khớp số đếm thực tế.'
                : 'Đã duyệt kiểm kê: không có chênh lệch nên không cần phiếu điều chỉnh.');
            redirect('../views/stock_count_detail.php?id=' . $countId);

        case 'cancel':
            cancelStockCount($pdo, $user, $countId);
            setFlash('success', 'Đã hủy đợt kiểm kê. Không có phát sinh nào được ghi, kho mở lại giao dịch.');
            redirect('../views/stock_count_detail.php?id=' . $countId);

        default:
            http_response_code(400);
            exit('Thao tác không hợp lệ.');
    }
} catch (VoucherException $e) {
    setFlash('error', $e->getMessage());
} catch (PDOException $e) {
    error_log('[stock_count_process] ' . $e->getMessage());
    setFlash('error', 'Lỗi CSDL, thao tác đã được hoàn tác: ' . $e->getMessage());
}

// Lỗi khi lưu số đếm: giữ lại số vừa nhập để không phải gõ lại
if ($action === 'save') {
    $_SESSION['stock_count_old'] = ['id' => $countId, 'lines' => $_POST['lines'] ?? []];
}
redirect($countId > 0 ? '../views/stock_count_detail.php?id=' . $countId : '../views/stock_count_list.php');
