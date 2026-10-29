<?php
/**
 * Xuất danh mục ra file CSV (mở bằng Excel) / tải file mẫu để nhập.
 *   ?entity=items|warehouses            xuất danh sách đang lọc (q, status, warehouse, sort, dir)
 *   ?entity=items|warehouses&template=1 file mẫu: đúng các cột mà chức năng "Nhập Excel" đọc
 *   ?entity=batches                     lô hàng (q, status, warehouse, sort, dir)
 *   ?entity=vouchers&type=IMPORT|EXPORT|TRANSFER|ADJUSTMENT[&template=1]   phiếu: mỗi dòng hàng 1 dòng
 * File xuất ra cũng nhập lại được (các cột thừa như tồn kho, trạng thái được bỏ qua).
 */
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/csv_functions.php';
require_once __DIR__ . '/../functions/item_functions.php';
require_once __DIR__ . '/../functions/warehouse_functions.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/voucher_csv_functions.php';
require_once __DIR__ . '/../functions/report_functions.php';
require_once __DIR__ . '/../functions/batch_functions.php';

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
    // Cùng bộ lọc / thứ tự với trang danh sách
    $status      = array_key_exists($_GET['status'] ?? '', ITEM_STATUS_FILTERS) ? $_GET['status'] : 'active';
    $warehouseId = (int) ($_GET['warehouse'] ?? 0);
    $items       = getItems($pdo, $keyword, 100000, 0, $user['warehouses'], $status, $warehouseId, readSort(ITEM_SORT_COLUMNS, 'sku'));
    $qtyHeader   = $warehouseId > 0 ? 'Tồn tại kho đang lọc' : 'Tổng tồn (các kho được xem)';
    sendCsv("mat-hang-$stamp.csv", array_merge($header, [$qtyHeader, 'Lô còn tồn', 'Trạng thái']), array_map(fn ($i) => [
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
    $status     = array_key_exists($_GET['status'] ?? '', WAREHOUSE_STATUS_FILTERS) ? $_GET['status'] : 'all';
    $warehouses = getWarehouses($pdo, $keyword, 100000, 0, $user['warehouses'], $status, readSort(WAREHOUSE_SORT_COLUMNS, 'code'));
    sendCsv("kho-$stamp.csv", array_merge($header, ['Số mặt hàng còn tồn', 'Số lô còn tồn', 'Trạng thái']), array_map(fn ($w) => [
        $w['code'], $w['name'], $w['address'] ?? '',
        (int) $w['item_count'], (int) $w['batch_count'], $w['is_active'] ? 'Hoạt động' : 'Ngừng hoạt động',
    ], $warehouses));
}

if ($entity === 'vouchers') {
    $type   = requestedVoucherType($_GET['type'] ?? '');
    $prefix = strtolower(VOUCHER_PREFIX[$type]);
    if ($template) {
        if ($type === 'ADJUSTMENT') {
            http_response_code(400);
            exit('Phiếu điều chỉnh được sinh tự động khi duyệt kiểm kê, không nhập từ file.');
        }
        sendCsv("mau-nhap-phieu-$prefix.csv", voucherCsvTemplateHeader($type), voucherCsvTemplateRows($pdo, $type, $user['warehouses']));
    }
    // Cùng bộ lọc / thứ tự với trang danh sách phiếu
    $filters = [
        'keyword'      => $keyword,
        'status'       => in_array($_GET['status'] ?? '', VOUCHER_TYPE_STATUSES[$type], true) ? $_GET['status'] : '',
        'warehouse_id' => (int) ($_GET['warehouse'] ?? 0),
    ];
    $rows = getVoucherExportRows($pdo, $type, $filters, $user['warehouses'], readSort(VOUCHER_SORT_COLUMNS, 'date', 'desc'));
    sendCsv("phieu-$prefix-$stamp.csv", VOUCHER_EXPORT_HEADER, $rows);
}

// ----- Báo cáo: cùng bộ lọc với trang báo cáo tương ứng -----
$warehouseId = (int) ($_GET['warehouse'] ?? 0);

if ($entity === 'stock_report') {
    $filters = [
        'keyword' => $keyword, 'warehouse_id' => $warehouseId,
        'expiry' => array_key_exists($expiry = (string) ($_GET['expiry'] ?? ''), STOCK_EXPIRY_FILTERS) ? $expiry : '',
        'reserved_only' => !empty($_GET['reserved']),
    ];
    $rows = getStockReport($pdo, $filters, $user['warehouses'], 1000000, 0, readSort(STOCK_REPORT_SORT, 'warehouse'));
    sendCsv("ton-kho-$stamp.csv", ['Kho', 'Mã hàng', 'Tên mặt hàng', 'ĐVT', 'Số lô', 'Hạn sử dụng', 'Tồn thực', 'Đang giữ', 'Có thể xuất'], array_map(fn ($r) => [
        $r['warehouse_name'], $r['sku'], $r['item_name'], $r['unit'], $r['batch_no'], formatDate($r['expiry_date']),
        formatQty($r['quantity']), formatQty($r['reserved']), formatQty($r['available']),
    ], $rows));
}

if ($entity === 'ledger_summary' || $entity === 'ledger_detail') {
    [$from, $to] = readDateRange();
    $filters = ['keyword' => $keyword, 'warehouse_id' => $warehouseId, 'from' => $from, 'to' => $to,
                'type' => in_array($_GET['type'] ?? '', ['IMPORT', 'EXPORT', 'TRANSFER', 'ADJUSTMENT'], true) ? $_GET['type'] : ''];
    $period = str_replace('-', '', $from) . '-' . str_replace('-', '', $to);
    if ($entity === 'ledger_summary') {
        $rows = getMovementSummary($pdo, $filters, $user['warehouses'], readSort(MOVEMENT_SUMMARY_SORT, 'sku'));
        sendCsv("nhap-xuat-ton-$period.csv", ['Mã hàng', 'Tên mặt hàng', 'ĐVT', 'Tồn đầu kỳ', 'Nhập trong kỳ', 'Xuất trong kỳ', 'Tồn cuối kỳ'], array_map(fn ($r) => [
            $r['sku'], $r['item_name'], $r['unit'], formatQty($r['opening']), formatQty($r['qty_in']), formatQty($r['qty_out']), formatQty($r['closing']),
        ], $rows));
    }
    $rows = getLedgerEntries($pdo, $filters, $user['warehouses'], 1000000, 0);
    sendCsv("the-kho-$period.csv", ['Thời điểm', 'Chứng từ', 'Đợt ghi sổ', 'Bước', 'Kho', 'Mã hàng', 'Tên mặt hàng', 'Số lô', 'Nhập', 'Xuất', 'Tồn sau', 'ĐVT', 'Người ghi'], array_map(fn ($r) => [
        date('d/m/Y H:i', strtotime($r['moved_at'])), $r['voucher_code'], $r['posting_code'], postingKindLabel($r['posting_kind']), $r['warehouse_name'],
        $r['sku'], $r['item_name'], $r['batch_no'], formatQty($r['qty_in']), formatQty($r['qty_out']), formatQty($r['balance_after']), $r['unit'], $r['created_by_name'],
    ], $rows));
}

if ($entity === 'count_report') {
    [$from, $to] = readDateRange();
    $counts = getStockCountReport($pdo, ['warehouse_id' => $warehouseId, 'from' => $from, 'to' => $to], $user['warehouses']);
    $rows   = getStockCountDifferences($pdo, array_column($counts, 'id'));
    sendCsv('bao-cao-kiem-ke-' . str_replace('-', '', $from) . '-' . str_replace('-', '', $to) . '.csv',
        ['Đợt kiểm kê', 'Kho', 'Mã hàng', 'Tên mặt hàng', 'Số lô', 'ĐVT', 'Tồn sổ', 'Số đếm', 'Chênh lệch', 'Lý do', 'Người đếm'], array_map(fn ($r) => [
            $r['count_code'], $r['warehouse_name'], $r['sku'], $r['item_name'], $r['batch_no'], $r['unit'],
            formatQty($r['system_qty']), formatQty($r['counted_qty']), ($r['difference'] > 0 ? '+' : '-') . formatQty(abs($r['difference'])), $r['reason'], $r['counted_by_name'] ?? '',
        ], $rows));
}

if ($entity === 'batches') {
    $filters = ['keyword' => $keyword, 'warehouse_id' => $warehouseId,
                'status' => array_key_exists($_GET['status'] ?? '', BATCH_STATUS_FILTERS) ? $_GET['status'] : 'in_stock'];
    $rows = getBatches($pdo, $filters, $user['warehouses'], 1000000, 0, readSort(BATCH_SORT_COLUMNS, 'expiry'));
    sendCsv("lo-hang-$stamp.csv", ['Số lô', 'Mã hàng', 'Tên mặt hàng', 'ĐVT', 'Ngày sản xuất', 'Hạn sử dụng', 'Còn (ngày)', 'Tồn', 'Đang giữ', 'Có thể xuất', 'Số kho có'], array_map(fn ($r) => [
        $r['batch_no'], $r['sku'], $r['item_name'], $r['unit'], formatDate($r['mfg_date']), formatDate($r['expiry_date']), $r['days_left'] ?? '',
        formatQty($r['quantity']), formatQty($r['reserved']), formatQty($r['available']), (int) $r['warehouse_count'],
    ], $rows));
}

http_response_code(400);
exit('Loại dữ liệu xuất không hợp lệ.');
