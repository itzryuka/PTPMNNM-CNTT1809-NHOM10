<?php
/**
 * Xuất danh mục ra file CSV (mở bằng Excel) / tải file mẫu để nhập.
 *   ?entity=items|warehouses            xuất danh sách đang lọc (q, status)
 *   ?entity=items|warehouses&template=1 file mẫu: đúng các cột mà chức năng "Nhập Excel" đọc
 * File xuất ra cũng nhập lại được (các cột thừa như tồn kho, trạng thái được bỏ qua).
 */
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/csv_functions.php';
require_once __DIR__ . '/../functions/item_functions.php';
require_once __DIR__ . '/../functions/warehouse_functions.php';

$user     = requireLogin('../');
$entity   = (string) ($_GET['entity'] ?? '');
$template = !empty($_GET['template']);
$keyword  = trim((string) ($_GET['q'] ?? ''));
$stamp    = date('Ymd-His');
$pdo      = getDbConnection();

if ($entity === 'items') {
    $header = ['Mã hàng', 'Tên mặt hàng', 'Đơn vị tính', 'Mô tả'];
    if ($template) {
        sendCsv('mau-nhap-mat-hang.csv', $header, [
            ['SP101', 'Nước cam Twister 455ml', 'Chai', 'Ví dụ: thêm mặt hàng mới'],
            ['SP001', 'Sữa tươi Vinamilk 1L', 'Hộp', 'Ví dụ: mã đã có -> cập nhật tên / ĐVT / mô tả'],
        ]);
    }
    $status = in_array($_GET['status'] ?? '', ['active', 'inactive', 'all'], true) ? $_GET['status'] : 'active';
    $items  = getItems($pdo, $keyword, 100000, 0, $user['warehouses'], $status);
    sendCsv("mat-hang-$stamp.csv", array_merge($header, ['Tổng tồn (các kho được xem)', 'Lô còn tồn', 'Trạng thái']), array_map(fn ($i) => [
        $i['sku'], $i['name'], $i['unit'], $i['description'] ?? '',
        formatQty($i['total_qty']), $i['batch_list'] ?? '', $i['is_active'] ? 'Đang dùng' : 'Ngừng dùng',
    ], $items));
}

if ($entity === 'warehouses') {
    $header = ['Mã kho', 'Tên kho', 'Địa chỉ'];
    if ($template) {
        sendCsv('mau-nhap-kho.csv', $header, [
            ['KHO-CT', 'Kho Cần Thơ', 'Số 2 Hòa Bình, Ninh Kiều, Cần Thơ'],
        ]);
    }
    $warehouses = getWarehouses($pdo, $keyword, 100000, 0, $user['warehouses']);
    sendCsv("kho-$stamp.csv", array_merge($header, ['Số mặt hàng còn tồn', 'Số lô còn tồn', 'Trạng thái']), array_map(fn ($w) => [
        $w['code'], $w['name'], $w['address'] ?? '',
        (int) $w['item_count'], (int) $w['batch_count'], $w['is_active'] ? 'Hoạt động' : 'Ngừng hoạt động',
    ], $warehouses));
}

http_response_code(400);
exit('Loại dữ liệu xuất không hợp lệ.');
