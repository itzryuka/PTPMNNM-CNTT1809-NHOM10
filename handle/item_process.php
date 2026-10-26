<?php
// Xử lý danh mục mặt hàng: thêm, sửa, xóa (hoặc ngừng dùng), xóa nhiều, dùng lại, nhập CSV. Chỉ nhận POST.
// Xử lý xong quay về đúng trang đã gửi (danh sách giữ nguyên bộ lọc, hoặc trang chi tiết).
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/csv_functions.php';
require_once __DIR__ . '/../functions/item_functions.php';

$user = requireLogin('../');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Chỉ chấp nhận POST.');
}
verifyCsrf();

$action = (string) ($_POST['action'] ?? '');
$itemId = (int) ($_POST['id'] ?? 0);
$back   = catalogReturnUrl('item_list.php', 'item_detail.php', $_POST, $itemId);

try {
    $pdo = getDbConnection();
    switch ($action) {
        case 'create':
            $itemId = createItem($pdo, $user, $_POST);
            setFlash('success', 'Đã thêm mặt hàng ' . strtoupper(trim((string) $_POST['sku'])) . '.');
            redirect($back);

        case 'update':
            updateItem($pdo, $user, $itemId, $_POST);
            setFlash('success', 'Đã lưu thay đổi mặt hàng ' . strtoupper(trim((string) $_POST['sku'])) . '.');
            redirect($back);

        case 'delete':
            $result = removeItem($pdo, $user, $itemId);
            setFlash('success', $result === 'deleted'
                ? 'Đã xóa mặt hàng.'
                : 'Mặt hàng đã có lịch sử nhập / xuất nên không xóa được: đã chuyển sang "Ngừng dùng" (vẫn tra cứu được, không lập phiếu mới được).');
            // Xóa thật từ trang chi tiết thì trang đó không còn -> về danh sách
            redirect($result === 'deleted' && ($_POST['return_to'] ?? '') === 'detail' ? '../views/item_list.php' : $back);

        case 'bulk_delete':
            $ids = array_unique(array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []));
            if (!$ids) {
                throw new AppException('Chưa chọn mặt hàng nào.');
            }
            $counts = ['deleted' => 0, 'deactivated' => 0];
            $errors = [];
            foreach ($ids as $id) {
                try {
                    $counts[removeItem($pdo, $user, $id)]++;
                } catch (AppException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $message = "Đã xóa {$counts['deleted']}, chuyển ngừng dùng {$counts['deactivated']} mặt hàng.";
            setFlash($errors ? 'error' : 'success', $message . ($errors ? ' Không xử lý được: ' . implode(' | ', $errors) : ''));
            redirect($back);

        case 'restore':
            restoreItem($pdo, $user, $itemId);
            setFlash('success', 'Mặt hàng đã được dùng lại.');
            redirect($back);

        case 'import':
            $rows = readCsvUpload($_FILES['file'] ?? null, ITEM_CSV_COLUMNS, ['sku', 'name', 'unit']);
            [$created, $updated] = importItems($pdo, $user, $rows);
            setFlash('success', "Nhập file xong: thêm $created, cập nhật $updated mặt hàng.");
            redirect($back);

        default:
            http_response_code(400);
            exit('Thao tác không hợp lệ.');
    }
} catch (AppException | PDOException $e) {
    if ($e instanceof PDOException) {
        error_log('[item_process] ' . $e->getMessage());
    }
    $message = ($e instanceof PDOException ? 'Lỗi CSDL, thao tác đã được hoàn tác: ' : '') . $e->getMessage();
    if (in_array($action, ['create', 'update'], true)) {
        // Thêm / sửa lỗi: trang quay về mở lại popup với dữ liệu đã nhập và lỗi ngay trong popup
        keepModalState('item_modal', $_POST, $message);
    } else {
        setFlash('error', $message);
    }
}
redirect($back);
