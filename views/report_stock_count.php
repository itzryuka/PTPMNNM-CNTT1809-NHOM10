<?php
// Báo cáo kiểm kê: các đợt đã chốt trong kỳ, tỉ lệ khớp, dòng lệch kèm lý do, lý do lệch thường gặp
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/report_functions.php';

$user = requireLogin('../');

if (!isset($_GET['from'])) {
    $_GET['from'] = date('Y-01-01');                   // mặc định: từ đầu năm (kiểm kê thường theo tháng / quý)
}
[$from, $to] = readDateRange();
$filters = ['warehouse_id' => (int) ($_GET['warehouse'] ?? 0), 'from' => $from, 'to' => $to];

$counts      = [];
$differences = [];
$warehouses  = [];
$dbError     = '';
try {
    $pdo         = getDbConnection();
    $warehouses  = getWarehouseOptions($pdo, $user['warehouses']);
    $counts      = getStockCountReport($pdo, $filters, $user['warehouses']);
    $differences = getStockCountDifferences($pdo, array_column($counts, 'id'));
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}
$lineTotal = array_sum(array_column($counts, 'line_count'));
$diffTotal = array_sum(array_column($counts, 'diff_count'));
$reasons   = array_count_values(array_map(fn ($d) => trim((string) $d['reason']), $differences));
arsort($reasons);
$exportUrl = '../handle/export.php?' . http_build_query(['entity' => 'count_report', 'warehouse' => $filters['warehouse_id'], 'from' => $from, 'to' => $to]);

$pageTitle  = 'Báo cáo kiểm kê';
$basePath   = '../';
$activeMenu = 'count_report';
require __DIR__ . '/layout/header.php';
?>

            <div class="toolbar">
                <div class="toolbar-top">
                    <span class="muted toolbar-note"><i class="fas fa-info-circle"></i> Chỉ tính các đợt đã chốt (duyệt) trong kỳ. Mỗi dòng lệch đã được điều chỉnh bằng phiếu điều chỉnh có lý do.</span>
                    <a class="btn btn-export" href="<?= e($exportUrl) ?>"><i class="fas fa-file-export"></i> Xuất Excel</a>
                </div>
                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left"></div>
                    <form class="toolbar-bottom-right" method="get">
                        <div class="date-range">
                            <input class="filter-select" type="date" name="from" value="<?= e($from) ?>" aria-label="Từ ngày">
                            <span class="muted">→</span>
                            <input class="filter-select" type="date" name="to" value="<?= e($to) ?>" aria-label="Đến ngày">
                        </div>
                        <select class="filter-select" name="warehouse" onchange="this.form.submit()">
                            <option value="0">Tất cả kho được xem</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $filters['warehouse_id'] ? 'selected' : '' ?>><?= e($warehouse['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-icon" type="submit" title="Xem"><i class="fas fa-search"></i></button>
                    </form>
                </div>
            </div>

            <div class="summary-grid">
                <div class="summary-card"><div class="label">Đợt kiểm kê đã chốt</div><div class="value"><?= count($counts) ?></div><div class="sub"><?= formatDate($from) ?> – <?= formatDate($to) ?></div></div>
                <div class="summary-card"><div class="label">Dòng đã đếm (kho × lô)</div><div class="value"><?= $lineTotal ?></div><div class="sub">tỉ lệ khớp <?= $lineTotal ? number_format(100 * ($lineTotal - $diffTotal) / $lineTotal, 1, ',', '.') : '0' ?>%</div></div>
                <div class="summary-card"><div class="label">Dòng thiếu so với sổ</div><div class="value" style="color: #b42318;"><?= array_sum(array_column($counts, 'short_count')) ?></div><div class="sub">đã xuất bỏ bằng phiếu điều chỉnh</div></div>
                <div class="summary-card"><div class="label">Dòng thừa so với sổ</div><div class="value" style="color: #1e8449;"><?= array_sum(array_column($counts, 'surplus_count')) ?></div><div class="sub">đã nhập thêm bằng phiếu điều chỉnh</div></div>
            </div>

            <div class="panel">
                <div class="panel-header"><h3><i class="fas fa-clipboard-check"></i> Các đợt kiểm kê</h3></div>
                <div class="table-container" style="border: none; border-radius: 0;">
                    <table class="nowrap-table">
                        <thead><tr><th>Mã đợt</th><th>Kho</th><th>Chụp số lúc</th><th>Chốt lúc</th><th>Người duyệt</th><th class="text-right">Dòng đếm</th><th class="text-right">Dòng lệch</th><th class="text-right">Tỉ lệ khớp</th><th>Phiếu điều chỉnh</th></tr></thead>
                        <tbody>
                            <?php if (empty($counts)): ?>
                                <tr class="empty-row"><td colspan="9">Không có đợt kiểm kê nào được chốt trong kỳ.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($counts as $count): ?>
                                <tr>
                                    <td><a class="link-strong" href="stock_count_detail.php?id=<?= (int) $count['id'] ?>"><?= e($count['code']) ?></a></td>
                                    <td><?= e($count['warehouse_name']) ?></td>
                                    <td><?= e(date('d/m/Y H:i', strtotime($count['created_at']))) ?></td>
                                    <td><?= e(date('d/m/Y H:i', strtotime($count['approved_at']))) ?></td>
                                    <td><?= e($count['approver_name']) ?></td>
                                    <td class="text-right num"><?= (int) $count['line_count'] ?></td>
                                    <td class="text-right num"><?= (int) $count['diff_count'] ? '<strong class="qty-out">' . (int) $count['diff_count'] . '</strong>' : '0' ?></td>
                                    <td class="text-right num"><?= $count['line_count'] ? number_format(100 * ($count['line_count'] - $count['diff_count']) / $count['line_count'], 1, ',', '.') : '0' ?>%</td>
                                    <td><?= $count['adjustment_code'] ? '<a class="link-strong" href="voucher_detail.php?id=' . (int) $count['adjustment_id'] . '">' . e($count['adjustment_code']) . '</a>' : '<span class="muted">Không lệch</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="dash-grid" style="margin-top: 20px;">
                <div class="panel">
                    <div class="panel-header"><h3><i class="fas fa-not-equal"></i> Dòng lệch và lý do</h3></div>
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table class="nowrap-table">
                            <thead><tr><th>Đợt</th><th>Mặt hàng / lô</th><th class="text-right">Sổ</th><th class="text-right">Đếm</th><th class="text-right">Lệch</th><th>Lý do</th></tr></thead>
                            <tbody>
                                <?php if (empty($differences)): ?>
                                    <tr class="empty-row"><td colspan="6">Không có dòng lệch.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($differences as $diff): ?>
                                    <tr>
                                        <td><?= e($diff['count_code']) ?><br><span class="muted"><?= e($diff['warehouse_name']) ?></span></td>
                                        <td class="wrap"><?= e($diff['sku'] . ' - ' . $diff['item_name']) ?><br><span class="muted">Lô <?= e($diff['batch_no']) ?></span></td>
                                        <td class="text-right num"><?= formatQty($diff['system_qty']) ?></td>
                                        <td class="text-right num"><?= formatQty($diff['counted_qty']) ?></td>
                                        <td class="text-right num"><?= (float) $diff['difference'] > 0 ? '<span class="qty-in">+' . formatQty($diff['difference']) . '</span>' : '<span class="qty-out">−' . formatQty(-$diff['difference']) . '</span>' ?> <span class="muted"><?= e($diff['unit']) ?></span></td>
                                        <td class="wrap"><?= e($diff['reason']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header"><h3><i class="fas fa-list-ol"></i> Lý do lệch thường gặp</h3></div>
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table>
                            <thead><tr><th>Lý do</th><th class="text-right">Số dòng</th></tr></thead>
                            <tbody>
                                <?php if (empty($reasons)): ?>
                                    <tr class="empty-row"><td colspan="2">Chưa có dữ liệu.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($reasons as $reason => $times): ?>
                                    <tr><td class="wrap"><?= e($reason) ?></td><td class="text-right num"><strong><?= $times ?></strong></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

<?php require __DIR__ . '/layout/footer.php'; ?>
