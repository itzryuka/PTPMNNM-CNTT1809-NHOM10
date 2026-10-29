<?php
// Danh sách Phiếu nhập kho (?type=IMPORT) / Phiếu xuất kho (?type=EXPORT)
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';

$user = requireLogin('../');

$type     = requestedVoucherType($_GET['type'] ?? '');
$typeInfo = voucherPageInfo($type);
[$keyword, $pageSize, $page] = readListParams();
$filters = [
    'keyword'      => $keyword,
    'status'       => in_array($_GET['status'] ?? '', VOUCHER_TYPE_STATUSES[$type], true) ? $_GET['status'] : '',
    'warehouse_id' => (int) ($_GET['warehouse'] ?? 0),
];
$sort = readSort(VOUCHER_SORT_COLUMNS, 'date', 'desc');

$vouchers      = [];
$warehouses    = [];
$totalVouchers = 0;
$dbError       = '';
try {
    $pdo           = getDbConnection();
    $warehouses    = getWarehouseOptions($pdo, $user['warehouses']);
    $totalVouchers = countVouchers($pdo, $type, $filters, $user['warehouses']);
    $totalPages    = max(1, (int) ceil($totalVouchers / $pageSize));
    $page          = min($page, $totalPages);
    $vouchers      = getVouchers($pdo, $type, $filters, $user['warehouses'], $pageSize, ($page - 1) * $pageSize, $sort);
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$exportUrl = '../handle/export.php?' . http_build_query([
    'entity' => 'vouchers', 'type' => $type, 'q' => $keyword, 'status' => $filters['status'],
    'warehouse' => $filters['warehouse_id'], 'sort' => $sort[0], 'dir' => $sort[1],
]);

$pageTitle  = $typeInfo['title'];
$basePath   = '../';
$activeMenu = $typeInfo['menu'];
require __DIR__ . '/layout/header.php';
?>

            <!-- TOOLBAR -->
            <div class="toolbar">
                <div class="toolbar-top">
                    <?php if (!$typeInfo['create']): ?>
                        <span class="muted toolbar-note"><i class="fas fa-info-circle"></i> Phiếu điều chỉnh được sinh tự động khi duyệt đợt kiểm kê.</span>
                    <?php else: ?>
                        <a class="btn btn-template" href="../handle/export.php?entity=vouchers&amp;type=<?= $type ?>&amp;template=1"
                           title="File CSV mẫu: mỗi dòng 1 mặt hàng, các dòng cùng Số phiếu gộp thành 1 phiếu"><i class="fas fa-download"></i> Tải mẫu</a>
                    <?php endif; ?>
                    <a class="btn btn-export" href="<?= e($exportUrl) ?>" title="Xuất các phiếu đang lọc ra file CSV (mỗi dòng hàng 1 dòng)"><i class="fas fa-file-export"></i> Xuất Excel</a>
                    <?php if ($typeInfo['create']): ?>
                        <form class="inline-form" method="post" action="../handle/voucher_process.php" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="import">
                            <input type="hidden" name="type" value="<?= $type ?>">
                            <input type="hidden" name="return_query" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
                            <input type="file" name="file" id="importFile" accept=".csv" hidden>
                            <button type="button" class="btn btn-import" data-file-trigger="importFile"
                                    title="Nhập file CSV theo mẫu: mỗi Số phiếu thành 1 phiếu Chờ duyệt. Nhập lại cùng file không tạo trùng."><i class="fas fa-file-excel"></i> Nhập Excel</button>
                        </form>
                        <a class="btn btn-primary" href="voucher_form.php?type=<?= $type ?>"><i class="fas fa-plus"></i> <?= e($typeInfo['create']) ?></a>
                    <?php endif; ?>
                </div>

                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left"></div>
                    <form class="toolbar-bottom-right" method="get">
                        <input type="hidden" name="type" value="<?= $type ?>">
                        <input type="hidden" name="size" value="<?= $pageSize ?>">
                        <?= sortHiddenInputs($sort) ?>
                        <select class="filter-select" name="status" onchange="this.form.submit()">
                            <option value="">Tất cả trạng thái</option>
                            <?php foreach (VOUCHER_TYPE_STATUSES[$type] as $statusKey): ?>
                                <option value="<?= $statusKey ?>" <?= $filters['status'] === $statusKey ? 'selected' : '' ?>><?= e(voucherStatusLabel($statusKey, $type)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" name="warehouse" onchange="this.form.submit()">
                            <option value="0">Tất cả kho</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $filters['warehouse_id'] ? 'selected' : '' ?>><?= e($warehouse['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="search-box">
                            <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Mã phiếu / lý do...">
                            <button type="submit"><i class="fas fa-search" style="color: #94a3b8;"></i></button>
                        </div>
                        <a class="btn btn-icon" href="?type=<?= $type ?>" title="Bỏ lọc"><i class="fas fa-sync-alt"></i></a>
                        <span class="total-count">Tổng số: <strong><?= $totalVouchers ?></strong></span>
                    </form>
                </div>
            </div>

            <!-- Table Data -->
            <div class="table-container">
                <table class="nowrap-table">
                    <thead>
                        <tr>
                            <?= sortHeader('Mã Phiếu', 'code', $sort) ?>
                            <?= sortHeader('Ngày Lập', 'date', $sort, '', 'desc') ?>
                            <?= sortHeader($type === 'TRANSFER' ? 'Kho Đi → Kho Đến' : 'Kho', 'warehouse', $sort) ?>
                            <th>Lý Do / Ghi Chú</th>
                            <?= sortHeader('Số Dòng', 'lines', $sort, 'text-right', 'desc') ?>
                            <?= sortHeader('Tổng SL', 'total_qty', $sort, 'text-right', 'desc') ?>
                            <?= sortHeader('Người Lập', 'creator', $sort) ?>
                            <?= sortHeader('Trạng Thái', 'status', $sort) ?>
                            <th style="text-align: center;">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vouchers)): ?>
                            <tr class="empty-row"><td colspan="9">Không có phiếu nào.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($vouchers as $voucher): ?>
                            <tr>
                                <td><a class="link-strong" href="voucher_detail.php?id=<?= (int) $voucher['id'] ?>"><?= e($voucher['code']) ?></a></td>
                                <td><?= formatDate($voucher['voucher_date']) ?></td>
                                <td>
                                    <?= e($voucher['warehouse_name']) ?>
                                    <?php if ($voucher['to_warehouse_name'] !== null): ?>
                                        <i class="fas fa-arrow-right muted"></i> <?= e($voucher['to_warehouse_name']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="wrap"><?= $voucher['reason'] !== null ? e($voucher['reason']) : '<span class="muted">—</span>' ?></td>
                                <td class="text-right"><?= (int) $voucher['line_count'] ?></td>
                                <td class="text-right"><?= formatQty($voucher['total_qty']) ?></td>
                                <td><?= e($voucher['creator_name']) ?></td>
                                <td><?= voucherStatusBadge($voucher['status'], $type) ?></td>
                                <td class="row-actions">
                                    <a href="voucher_detail.php?id=<?= (int) $voucher['id'] ?>" title="Xem chi tiết"><i class="fas fa-eye icon-view"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
<?php require __DIR__ . '/layout/pagination.php'; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
