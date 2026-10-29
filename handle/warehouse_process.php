<?php
// Xử lý danh mục kho: thêm, sửa, xóa (hoặc ngừng hoạt động), xóa nhiều, hoạt động lại, nhập CSV. Chỉ nhận POST.
// Xử lý xong quay về đúng trang đã gửi (danh sách giữ nguyên bộ lọc, hoặc trang chi tiết).
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/csv_functions.php';
require_once __DIR__ . '/../functions/warehouse_functions.php';

$user = requireLogin('../');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Chỉ chấp nhận POST.');
}
verifyCsrf();

$action      = (string) ($_POST['action'] ?? '');
$warehouseId = (int) ($_POST['id'] ?? 0);
$back        = catalogReturnUrl('warehouse_list.php', 'warehouse_detail.php', $_POST, $warehouseId);

try {
    $pdo = getDbConnection();
    switch ($action) {
        case 'create':
            createWarehouse($pdo, $user, $_POST);
            setFlash('success', 'Đã thêm kho ' . strtoupper(trim((string) $_POST['code'])) . '. Bạn và tài khoản quản trị đã được phân quyền trên kho này.');
            redirect($back);

        case 'update':
            updateWarehouse($pdo, $user, $warehouseId, $_POST);
            setFlash('success', 'Đã lưu thay đổi kho ' . strtoupper(trim((string) $_POST['code'])) . '.');
            redirect($back);

        case 'delete':
            $result = removeWarehouse($pdo, $user, $warehouseId);
            setFlash('success', $result === 'deleted'
                ? 'Đã xóa kho.'
                : 'Kho đã có lịch sử chứng từ nên không xóa được: đã chuyển sang "Ngừng hoạt động" (vẫn tra cứu được, không lập phiếu mới được).');
            // Xóa thật từ trang chi tiết thì trang đó không còn -> về danh sách
            redirect($result === 'deleted' && ($_POST['return_to'] ?? '') === 'detail' ? '../views/warehouse_list.php' : $back);

        case 'bulk_delete':
            $ids = array_unique(array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []));
            if (!$ids) {
                throw new AppException('Chưa chọn kho nào.');
            }
            $counts = ['deleted' => 0, 'deactivated' => 0];
            $errors = [];
            foreach ($ids as $id) {
                try {
                    $counts[removeWarehouse($pdo, $user, $id)]++;
                } catch (AppException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $message = "Đã xóa {$counts['deleted']}, chuyển ngừng hoạt động {$counts['deactivated']} kho.";
            setFlash($errors ? 'error' : 'success', $message . ($errors ? ' Không xử lý được: ' . implode(' | ', $errors) : ''));
            redirect($back);

        case 'restore':
            restoreWarehouse($pdo, $user, $warehouseId);
            setFlash('success', 'Kho đã hoạt động lại.');
            redirect($back);

        case 'import':
            $rows = readCsvUpload($_FILES['file'] ?? null, WAREHOUSE_CSV_COLUMNS, ['code', 'name']);
            [$created, $updated] = importWarehouses($pdo, $user, $rows);
            setFlash('success', "Nhập file xong: thêm $created, cập nhật $updated kho.");
            redirect($back);

        default:
            http_response_code(400);
            exit('Thao tác không hợp lệ.');
    }
} catch (AppException | PDOException $e) {
    if ($e instanceof PDOException) {
        error_log('[warehouse_process] ' . $e->getMessage());
    }
    $message = $e instanceof PDOException ? dbErrorMessage($e) : $e->getMessage();
    if (in_array($action, ['create', 'update'], true)) {
        // Thêm / sửa lỗi: trang quay về mở lại popup với dữ liệu đã nhập và lỗi ngay trong popup
        keepModalState('warehouse_modal', $_POST, $message);
    } else {
        setFlash('error', $message);
    }
}
redirect($back);
