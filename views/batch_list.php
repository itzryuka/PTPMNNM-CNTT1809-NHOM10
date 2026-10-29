<?php
// Danh sách lô hàng: lô còn ở đâu, bao nhiêu, còn hạn bao lâu (số lượng chỉ tính ở kho được phân quyền)
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/batch_functions.php';

$user = requireLogin('../');

[$keyword, $pageSize, $page] = readListParams();
$filters = [
    'keyword'      => $keyword,
    'status'       => array_key_exists($_GET['status'] ?? '', BATCH_STATUS_FILTERS) ? $_GET['status'] : 'in_stock',
    'warehouse_id' => (int) ($_GET['warehouse'] ?? 0),
];
$sort = readSort(BATCH_SORT_COLUMNS, 'expiry');

$batches      = [];
$warehouses   = [];
$totalBatches = 0;
$dbError      = '';
try {
    $pdo          = getDbConnection();
    $warehouses   = getWarehouseOptions($pdo, $user['warehouses']);
    $totalBatches = countBatches($pdo, $filters, $user['warehouses']);
    $totalPages   = max(1, (int) ceil($totalBatches / $pageSize));
    $page         = min($page, $totalPages);
    $batches      = getBatches($pdo, $filters, $user['warehouses'], $pageSize, ($page - 1) * $pageSize, $sort);
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}
$exportUrl = '../handle/export.php?' . http_build_query(['entity' => 'batches', 'q' => $keyword, 'status' => $filters['status'],
    'warehouse' => $filters['warehouse_id'], 'sort' => $sort[0], 'dir' => $sort[1]]);

$pageTitle  = 'Lô hàng';
$basePath   = '../';
$activeMenu = 'batches';
require __DIR__ . '/layout/header.php';
?>

            <div class="toolbar">
                <div class="toolbar-top">
                    <span class="muted toolbar-note"><i class="fas fa-info-circle"></i> Lô được tạo khi lập phiếu nhập lô mới. Xuất / điều chuyển ưu tiên lô hết hạn trước; lô đã hết hạn không xuất được.</span>
                    <a class="btn btn-export" href="<?= e($exportUrl) ?>"><i class="fas fa-file-export"></i> Xuất Excel</a>
                </div>
                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left"></div>
                    <form class="toolbar-bottom-right" method="get">
                        <input type="hidden" name="size" value="<?= $pageSize ?>">
                        <?= sortHiddenInputs($sort) ?>
                        <select class="filter-select" name="status" onchange="this.form.submit()">
                            <?php foreach (BATCH_STATUS_FILTERS as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" name="warehouse" onchange="this.form.submit()">
                            <option value="0">Tất cả kho được xem</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $filters['warehouse_id'] ? 'selected' : '' ?>><?= e($warehouse['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="search-box">
                            <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Số lô / mã / tên hàng...">
                            <button type="submit"><i class="fas fa-search" style="color: #94a3b8;"></i></button>
                        </div>
                        <a class="btn btn-icon" href="batch_list.php" title="Bỏ lọc"><i class="fas fa-sync-alt"></i></a>
                        <span class="total-count">Tổng số: <strong><?= $totalBatches ?></strong></span>
                    </form>
                </div>
            </div>

            <div class="table-container">
                <table class="nowrap-table">
                    <thead>
                        <tr>
                            <?= sortHeader('Số Lô', 'batch', $sort) ?>
                            <?= sortHeader('Mã Hàng', 'sku', $sort) ?>
                            <?= sortHeader('Tên Mặt Hàng', 'name', $sort) ?>
                            <?= sortHeader('Ngày SX', 'mfg', $sort) ?>
                            <?= sortHeader('Hạn SD', 'expiry', $sort) ?>
                            <th>Tình Trạng Hạn</th>
                            <?= sortHeader('Tồn', 'quantity', $sort, 'text-right', 'desc') ?>
                            <?= sortHeader('Có Thể Xuất', 'available', $sort, 'text-right', 'desc') ?>
                            <th class="text-right">Số Kho Có</th>
                            <th style="text-align: center;">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($batches)): ?>
                            <tr class="empty-row"><td colspan="10">Không có lô nào khớp bộ lọc.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($batches as $batch): ?>
                            <tr>
                                <td><a class="link-strong" href="batch_detail.php?id=<?= (int) $batch['id'] ?>"><?= e($batch['batch_no']) ?></a></td>
                                <td><?= e($batch['sku']) ?></td>
                                <td class="wrap"><?= e($batch['item_name']) ?></td>
                                <td><?= $batch['mfg_date'] ? formatDate($batch['mfg_date']) : '—' ?></td>
                                <td><?= $batch['expiry_date'] ? formatDate($batch['expiry_date']) : '—' ?></td>
                                <td><?= expiryBadge($batch['expiry_date'], $batch['days_left']) ?></td>
                                <td class="text-right num"><?= formatQty($batch['quantity']) ?> <span class="muted"><?= e($batch['unit']) ?></span></td>
                                <td class="text-right num"><strong><?= formatQty($batch['available']) ?></strong><?= toMilli($batch['reserved']) ? ' <span class="badge badge-info" title="Đang giữ cho phiếu đã duyệt">giữ ' . formatQty($batch['reserved']) . '</span>' : '' ?></td>
                                <td class="text-right num"><?= (int) $batch['warehouse_count'] ?></td>
                                <td class="row-actions">
                                    <a href="batch_detail.php?id=<?= (int) $batch['id'] ?>" title="Xem lô"><i class="fas fa-eye icon-view"></i></a>
                                    <a href="report_ledger.php?<?= e(http_build_query(['view' => 'detail', 'q' => $batch['batch_no'], 'from' => '2000-01-01'])) ?>" title="Thẻ kho của lô"><i class="fas fa-book icon-edit"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

<?php require __DIR__ . '/layout/pagination.php'; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
