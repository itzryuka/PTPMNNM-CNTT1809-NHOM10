<?php
// Danh sách Phiếu nhập kho (?type=IMPORT) / Phiếu xuất kho (?type=EXPORT)
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';

$user = requireLogin('../');

$type = ($_GET['type'] ?? '') === 'EXPORT' ? 'EXPORT' : 'IMPORT';
[$keyword, $pageSize, $page] = readListParams();
$filters = [
    'keyword'      => $keyword,
    'status'       => in_array($_GET['status'] ?? '', ['PENDING', 'APPROVED', 'REJECTED'], true) ? $_GET['status'] : '',
    'warehouse_id' => (int) ($_GET['warehouse'] ?? 0),
];

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
    $vouchers      = getVouchers($pdo, $type, $filters, $user['warehouses'], $pageSize, ($page - 1) * $pageSize);
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$pageTitle  = $type === 'IMPORT' ? 'Phiếu Nhập Kho' : 'Phiếu Xuất Kho';
$basePath   = '../';
$activeMenu = $type === 'IMPORT' ? 'imports' : 'exports';
require __DIR__ . '/layout/header.php';
?>

            <!-- TOOLBAR -->
            <div class="toolbar">
                <div class="toolbar-top">
                    <a class="btn btn-primary" href="voucher_form.php?type=<?= $type ?>"><i class="fas fa-plus"></i> Lập phiếu <?= $type === 'IMPORT' ? 'nhập' : 'xuất' ?></a>
                </div>

                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left"></div>
                    <form class="toolbar-bottom-right" method="get">
                        <input type="hidden" name="type" value="<?= $type ?>">
                        <input type="hidden" name="size" value="<?= $pageSize ?>">
                        <select class="filter-select" name="status" onchange="this.form.submit()">
                            <option value="">Tất cả trạng thái</option>
                            <option value="PENDING" <?= $filters['status'] === 'PENDING' ? 'selected' : '' ?>>Chờ duyệt</option>
                            <option value="APPROVED" <?= $filters['status'] === 'APPROVED' ? 'selected' : '' ?>>Đã ghi nhận</option>
                            <option value="REJECTED" <?= $filters['status'] === 'REJECTED' ? 'selected' : '' ?>>Từ chối</option>
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
                            <th>Mã Phiếu</th>
                            <th>Ngày Lập</th>
                            <th>Kho</th>
                            <th>Lý Do / Ghi Chú</th>
                            <th class="text-right">Số Dòng</th>
                            <th class="text-right">Tổng SL</th>
                            <th>Người Lập</th>
                            <th>Trạng Thái</th>
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
                                <td><?= e($voucher['warehouse_name']) ?></td>
                                <td class="wrap"><?= $voucher['reason'] !== null ? e($voucher['reason']) : '<span class="muted">—</span>' ?></td>
                                <td class="text-right"><?= (int) $voucher['line_count'] ?></td>
                                <td class="text-right"><?= formatQty($voucher['total_qty']) ?></td>
                                <td><?= e($voucher['creator_name']) ?></td>
                                <td><?= voucherStatusBadge($voucher['status']) ?></td>
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
