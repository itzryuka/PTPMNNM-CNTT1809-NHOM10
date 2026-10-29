<?php
// Danh sách đợt kiểm kê (?status=COUNTING: các đợt đang mở cần xử lý chênh lệch)
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/stock_count_functions.php';

$user = requireLogin('../');

[$keyword, $pageSize, $page] = readListParams();
$filters = [
    'keyword'      => $keyword,
    'status'       => in_array($_GET['status'] ?? '', ['COUNTING', 'APPROVED', 'CANCELLED'], true) ? $_GET['status'] : '',
    'warehouse_id' => (int) ($_GET['warehouse'] ?? 0),
];
$sort = readSort(STOCK_COUNT_SORT_COLUMNS, 'created', 'desc');

$counts      = [];
$warehouses  = [];
$openByWh    = [];
$totalCounts = 0;
$dbError     = '';
try {
    $pdo         = getDbConnection();
    $warehouses  = getWarehouseOptions($pdo, $user['warehouses']);
    $totalCounts = countStockCounts($pdo, $filters, $user['warehouses']);
    $totalPages  = max(1, (int) ceil($totalCounts / $pageSize));
    $page        = min($page, $totalPages);
    $counts      = getStockCounts($pdo, $filters, $user['warehouses'], $pageSize, ($page - 1) * $pageSize, $sort);
    foreach (getOpenStockCounts($pdo, array_column($warehouses, 'id')) as $open) {
        $openByWh[$open['warehouse_code']] = $open['code'];
    }
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$pageTitle  = $filters['status'] === 'COUNTING' ? 'Xử lý chênh lệch kiểm kê' : 'Đợt kiểm kê';
$basePath   = '../';
$activeMenu = $filters['status'] === 'COUNTING' ? 'differences' : 'stock_counts';
require __DIR__ . '/layout/header.php';
?>

            <?php if (isManager($user) && $warehouses): ?>
                <!-- MỞ ĐỢT KIỂM KÊ MỚI -->
                <form class="form-card" method="post" action="../handle/stock_count_process.php" data-lock
                      data-confirm-title="Mở đợt kiểm kê" data-confirm-ok="Mở đợt" data-confirm-variant="primary"
                      data-confirm="Hệ thống sẽ chụp số tồn hiện tại và TẠM KHÓA việc nhận / xuất / gửi / giữ hàng của kho cho tới khi chốt đợt. Tiếp tục?">
                    <h3><i class="fas fa-clipboard-list"></i> Mở đợt kiểm kê mới</h3>
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="countWarehouse">Kho kiểm kê *</label>
                            <select class="form-control" id="countWarehouse" name="warehouse_id" required>
                                <?php foreach ($warehouses as $warehouse): ?>
                                    <?php $openCode = $openByWh[$warehouse['code']] ?? null; ?>
                                    <option value="<?= (int) $warehouse['id'] ?>" <?= $openCode ? 'disabled' : '' ?>>
                                        <?= e($warehouse['code'] . ' - ' . $warehouse['name']) ?><?= $openCode ? ' (đang kiểm kê ' . e($openCode) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label for="countNote">Ghi chú</label>
                            <input class="form-control" id="countNote" name="note" maxlength="255" placeholder="Vd: Kiểm kê định kỳ cuối tháng 10">
                        </div>
                    </div>
                    <div class="form-actions" style="margin-top: 16px;">
                        <span class="muted">Số tồn của mọi lô còn hàng được chụp lại ngay khi mở đợt.</span>
                        <button class="btn btn-primary" type="submit"><i class="fas fa-play"></i> Mở đợt kiểm kê</button>
                    </div>
                </form>
            <?php endif; ?>

            <!-- TOOLBAR -->
            <div class="toolbar">
                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left"></div>
                    <form class="toolbar-bottom-right" method="get">
                        <input type="hidden" name="size" value="<?= $pageSize ?>">
                        <?= sortHiddenInputs($sort) ?>
                        <select class="filter-select" name="status" onchange="this.form.submit()">
                            <option value="">Tất cả trạng thái</option>
                            <option value="COUNTING" <?= $filters['status'] === 'COUNTING' ? 'selected' : '' ?>>Đang kiểm kê</option>
                            <option value="APPROVED" <?= $filters['status'] === 'APPROVED' ? 'selected' : '' ?>>Đã duyệt</option>
                            <option value="CANCELLED" <?= $filters['status'] === 'CANCELLED' ? 'selected' : '' ?>>Đã hủy</option>
                        </select>
                        <select class="filter-select" name="warehouse" onchange="this.form.submit()">
                            <option value="0">Tất cả kho</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $filters['warehouse_id'] ? 'selected' : '' ?>><?= e($warehouse['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="search-box">
                            <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Mã đợt / ghi chú...">
                            <button type="submit"><i class="fas fa-search" style="color: #94a3b8;"></i></button>
                        </div>
                        <a class="btn btn-icon" href="stock_count_list.php" title="Bỏ lọc"><i class="fas fa-sync-alt"></i></a>
                        <span class="total-count">Tổng số: <strong><?= $totalCounts ?></strong></span>
                    </form>
                </div>
            </div>

            <!-- Table Data -->
            <div class="table-container">
                <table class="nowrap-table">
                    <thead>
                        <tr>
                            <?= sortHeader('Mã Đợt', 'code', $sort) ?>
                            <?= sortHeader('Kho', 'warehouse', $sort) ?>
                            <?= sortHeader('Thời Điểm Chụp Số', 'created', $sort, '', 'desc') ?>
                            <?= sortHeader('Người Mở', 'creator', $sort) ?>
                            <?= sortHeader('Đã Đếm', 'counted', $sort, 'text-right', 'desc') ?>
                            <?= sortHeader('Dòng Lệch', 'diffs', $sort, 'text-right', 'desc') ?>
                            <th>Phiếu Điều Chỉnh</th>
                            <?= sortHeader('Trạng Thái', 'status', $sort) ?>
                            <th style="text-align: center;">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($counts)): ?>
                            <tr class="empty-row"><td colspan="9">Không có đợt kiểm kê nào.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($counts as $count): ?>
                            <tr>
                                <td><a class="link-strong" href="stock_count_detail.php?id=<?= (int) $count['id'] ?>"><?= e($count['code']) ?></a></td>
                                <td><?= e($count['warehouse_name']) ?></td>
                                <td><?= e(date('d/m/Y H:i', strtotime($count['created_at']))) ?></td>
                                <td><?= e($count['creator_name']) ?></td>
                                <td class="text-right"><?= (int) $count['counted_count'] ?> / <?= (int) $count['line_count'] ?></td>
                                <td class="text-right"><?= (int) $count['diff_count'] ?></td>
                                <td>
                                    <?php if ($count['adjustment_code']): ?>
                                        <a class="link-strong" href="voucher_detail.php?id=<?= (int) $count['adjustment_voucher_id'] ?>"><?= e($count['adjustment_code']) ?></a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= stockCountStatusBadge($count['status']) ?></td>
                                <td class="row-actions">
                                    <a href="stock_count_detail.php?id=<?= (int) $count['id'] ?>" title="Xem / nhập số đếm"><i class="fas fa-eye icon-view"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
<?php require __DIR__ . '/layout/pagination.php'; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
