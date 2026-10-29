<?php
// Tra cứu tồn kho hiện tại: tồn thực / đang giữ / có thể xuất theo kho × lô, hạn dùng; hàng đang đi đường
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/report_functions.php';

$user = requireLogin('../');

[$keyword, $pageSize, $page] = readListParams();
$filters = [
    'keyword'       => $keyword,
    'warehouse_id'  => (int) ($_GET['warehouse'] ?? 0),
    'expiry'        => array_key_exists($expiry = (string) ($_GET['expiry'] ?? ''), STOCK_EXPIRY_FILTERS) ? $expiry : '',
    'reserved_only' => !empty($_GET['reserved']),
];
$sort = readSort(STOCK_REPORT_SORT, 'warehouse');

$rows       = [];
$summary    = ['line_count' => 0, 'items' => 0, 'reserved_lines' => 0, 'expired_lines' => 0, 'soon_lines' => 0];
$inTransit  = [];
$warehouses = [];
$dbError    = '';
try {
    $pdo        = getDbConnection();
    $warehouses = getWarehouseOptions($pdo, $user['warehouses']);
    $summary    = getStockReportSummary($pdo, $filters, $user['warehouses']);
    $totalPages = max(1, (int) ceil($summary['line_count'] / $pageSize));
    $page       = min($page, $totalPages);
    $rows       = getStockReport($pdo, $filters, $user['warehouses'], $pageSize, ($page - 1) * $pageSize, $sort);
    $inTransit  = getInTransitLines($pdo, $user['warehouses']);
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}
$exportUrl = '../handle/export.php?' . http_build_query(['entity' => 'stock_report', 'q' => $keyword, 'warehouse' => $filters['warehouse_id'],
    'expiry' => $filters['expiry'], 'reserved' => $filters['reserved_only'] ? 1 : '', 'sort' => $sort[0], 'dir' => $sort[1]]);

$pageTitle  = 'Tồn kho hiện tại';
$basePath   = '../';
$activeMenu = 'current_stock';
require __DIR__ . '/layout/header.php';
?>

            <div class="summary-grid">
                <div class="summary-card"><div class="label">Dòng tồn (kho × lô)</div><div class="value"><?= $summary['line_count'] ?></div><div class="sub"><?= $summary['items'] ?> mặt hàng</div></div>
                <a class="summary-card" href="?<?= e(http_build_query(['reserved' => 1, 'size' => $pageSize])) ?>" style="text-decoration: none; color: inherit;">
                    <div class="label">Lô đang giữ chỗ</div><div class="value"><?= $summary['reserved_lines'] ?></div><div class="sub">cho phiếu xuất / điều chuyển đã duyệt</div></a>
                <a class="summary-card" href="?<?= e(http_build_query(['expiry' => 'soon', 'size' => $pageSize])) ?>" style="text-decoration: none; color: inherit;">
                    <div class="label">Sắp hết hạn (≤ 30 ngày)</div><div class="value" style="color: #c2410c;"><?= $summary['soon_lines'] ?></div><div class="sub">lô còn hàng</div></a>
                <a class="summary-card" href="?<?= e(http_build_query(['expiry' => 'expired', 'size' => $pageSize])) ?>" style="text-decoration: none; color: inherit;">
                    <div class="label">Đã hết hạn</div><div class="value" style="color: #b42318;"><?= $summary['expired_lines'] ?></div><div class="sub">lô còn hàng, cần xử lý</div></a>
            </div>

            <div class="toolbar">
                <div class="toolbar-top">
                    <span class="muted toolbar-note"><i class="fas fa-info-circle"></i> <strong>Có thể xuất</strong> = tồn thực − hàng đang giữ cho phiếu đã duyệt nhưng chưa rời kho.</span>
                    <a class="btn btn-export" href="<?= e($exportUrl) ?>"><i class="fas fa-file-export"></i> Xuất Excel</a>
                </div>
                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left"></div>
                    <form class="toolbar-bottom-right" method="get">
                        <input type="hidden" name="size" value="<?= $pageSize ?>">
                        <?= sortHiddenInputs($sort) ?>
                        <label class="muted" style="display: flex; gap: 6px; align-items: center;"><input type="checkbox" name="reserved" value="1" <?= $filters['reserved_only'] ? 'checked' : '' ?> onchange="this.form.submit()"> Chỉ lô đang giữ</label>
                        <select class="filter-select" name="expiry" onchange="this.form.submit()">
                            <?php foreach (STOCK_EXPIRY_FILTERS as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $filters['expiry'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" name="warehouse" onchange="this.form.submit()">
                            <option value="0">Tất cả kho</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $filters['warehouse_id'] ? 'selected' : '' ?>><?= e($warehouse['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="search-box">
                            <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Mã / tên hàng / số lô...">
                            <button type="submit"><i class="fas fa-search" style="color: #94a3b8;"></i></button>
                        </div>
                        <a class="btn btn-icon" href="report_stock.php" title="Bỏ lọc"><i class="fas fa-sync-alt"></i></a>
                    </form>
                </div>
            </div>

            <div class="table-container">
                <table class="nowrap-table">
                    <thead>
                        <tr>
                            <?= sortHeader('Kho', 'warehouse', $sort) ?>
                            <?= sortHeader('Mã Hàng', 'sku', $sort) ?>
                            <?= sortHeader('Tên Mặt Hàng', 'name', $sort) ?>
                            <?= sortHeader('Lô', 'batch', $sort) ?>
                            <?= sortHeader('Hạn SD', 'expiry', $sort) ?>
                            <?= sortHeader('Tồn Thực', 'quantity', $sort, 'text-right', 'desc') ?>
                            <?= sortHeader('Đang Giữ', 'reserved', $sort, 'text-right', 'desc') ?>
                            <?= sortHeader('Có Thể Xuất', 'available', $sort, 'text-right', 'desc') ?>
                            <th>ĐVT</th>
                            <th style="text-align: center;">Thẻ Kho</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr class="empty-row"><td colspan="10">Không có lô nào còn hàng khớp bộ lọc.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= e($row['warehouse_name']) ?></td>
                                <td><?= e($row['sku']) ?></td>
                                <td class="wrap"><a class="link-strong" href="item_detail.php?id=<?= (int) $row['item_id'] ?>"><?= e($row['item_name']) ?></a></td>
                                <td><?= e($row['batch_no']) ?></td>
                                <td>
                                    <?= $row['expiry_date'] ? formatDate($row['expiry_date']) : '—' ?>
                                    <?php if ($row['days_left'] !== null && (int) $row['days_left'] < 0): ?> <span class="badge badge-expired">Hết hạn</span>
                                    <?php elseif ($row['days_left'] !== null && (int) $row['days_left'] <= 30): ?> <span class="badge badge-pending">Còn <?= (int) $row['days_left'] ?> ngày</span><?php endif; ?>
                                </td>
                                <td class="text-right num"><?= formatQty($row['quantity']) ?></td>
                                <td class="text-right num"><?= toMilli($row['reserved']) ? '<span class="badge badge-info">' . formatQty($row['reserved']) . '</span>' : '<span class="muted">—</span>' ?></td>
                                <td class="text-right num"><strong><?= formatQty($row['available']) ?></strong></td>
                                <td class="muted"><?= e($row['unit']) ?></td>
                                <td class="row-actions"><a href="report_ledger.php?<?= e(http_build_query(['view' => 'detail', 'warehouse' => $row['warehouse_id'], 'q' => $row['batch_no'], 'from' => '2000-01-01'])) ?>" title="Xem thẻ kho của lô này"><i class="fas fa-book icon-view"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

<?php require __DIR__ . '/layout/pagination.php'; ?>

            <div class="panel" style="margin-top: 24px;">
                <div class="panel-header"><h3><i class="fas fa-truck"></i> Hàng đang đi đường</h3><span class="muted">Điều chuyển đã gửi, kho đến chưa nhận đủ</span></div>
                <div class="table-container" style="border: none; border-radius: 0;">
                    <table class="nowrap-table">
                        <thead><tr><th>Phiếu</th><th>Từ → Đến</th><th>Mặt hàng</th><th>Lô</th><th class="text-right">Đang đi đường</th><th>Gửi lúc</th><th>Trạng thái</th></tr></thead>
                        <tbody>
                            <?php if (empty($inTransit)): ?>
                                <tr class="empty-row"><td colspan="7">Không có hàng nào đang trên đường.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($inTransit as $line): ?>
                                <tr>
                                    <td><a class="link-strong" href="voucher_detail.php?id=<?= (int) $line['voucher_id'] ?>"><?= e($line['code']) ?></a></td>
                                    <td><?= e($line['from_name']) ?> <i class="fas fa-arrow-right muted"></i> <?= e($line['to_name']) ?></td>
                                    <td><?= e($line['sku'] . ' - ' . $line['item_name']) ?></td>
                                    <td><?= e($line['batch_no']) ?></td>
                                    <td class="text-right"><strong><?= formatQty($line['on_road']) ?></strong> <span class="muted"><?= e($line['unit']) ?></span></td>
                                    <td>
                                        <?= e(date('d/m/Y H:i', strtotime($line['shipped_at']))) ?>
                                        <span class="badge <?= (int) $line['days_on_road'] > OVERDUE_TRANSIT_DAYS ? 'badge-rejected' : 'badge-transit' ?>"><?= (int) $line['days_on_road'] ?> ngày</span>
                                    </td>
                                    <td><?= voucherStatusBadge($line['status'], 'TRANSFER') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

<?php require __DIR__ . '/layout/footer.php'; ?>
