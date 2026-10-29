<?php
// Sổ phát sinh: tab "Nhập – xuất – tồn" (tổng hợp theo mặt hàng trong kỳ) và tab "Thẻ kho" (từng phát sinh + số dư)
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/report_functions.php';

$user = requireLogin('../');

$view = ($_GET['view'] ?? '') === 'detail' ? 'detail' : 'summary';
[$keyword, $pageSize, $page] = readListParams();
[$from, $to] = readDateRange();
$filters = [
    'keyword'      => $keyword,
    'warehouse_id' => (int) ($_GET['warehouse'] ?? 0),
    'type'         => in_array($_GET['type'] ?? '', ['IMPORT', 'EXPORT', 'TRANSFER', 'ADJUSTMENT'], true) ? $_GET['type'] : '',
    'from'         => $from,
    'to'           => $to,
];
$sort = readSort(MOVEMENT_SUMMARY_SORT, 'sku');

$summary    = [];
$entries    = [];
$total      = 0;
$warehouses = [];
$dbError    = '';
try {
    $pdo        = getDbConnection();
    $warehouses = getWarehouseOptions($pdo, $user['warehouses']);
    if ($view === 'summary') {
        $summary    = getMovementSummary($pdo, $filters, $user['warehouses'], $sort);
        $totalPages = 1;
    } else {
        $total      = countLedgerEntries($pdo, $filters, $user['warehouses']);
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page       = min($page, $totalPages);
        $entries    = getLedgerEntries($pdo, $filters, $user['warehouses'], $pageSize, ($page - 1) * $pageSize);
    }
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$query     = fn (array $override) => '?' . http_build_query(array_merge(['view' => $view, 'warehouse' => $filters['warehouse_id'], 'q' => $keyword,
    'type' => $filters['type'], 'from' => $from, 'to' => $to, 'size' => $pageSize], $override));
$exportUrl = '../handle/export.php' . $query(['entity' => $view === 'summary' ? 'ledger_summary' : 'ledger_detail', 'sort' => $sort[0], 'dir' => $sort[1]]);

$pageTitle  = $view === 'summary' ? 'Nhập – xuất – tồn' : 'Thẻ kho / sổ phát sinh';
$basePath   = '../';
$activeMenu = $view === 'summary' ? 'ledger_summary' : 'ledger';
require __DIR__ . '/layout/header.php';
?>

            <nav class="report-tabs">
                <a class="<?= $view === 'summary' ? 'current' : '' ?>" href="<?= e($query(['view' => 'summary', 'type' => ''])) ?>"><i class="fas fa-table"></i> Nhập – xuất – tồn</a>
                <a class="<?= $view === 'detail' ? 'current' : '' ?>" href="<?= e($query(['view' => 'detail'])) ?>"><i class="fas fa-book"></i> Thẻ kho (chi tiết phát sinh)</a>
            </nav>

            <div class="toolbar">
                <div class="toolbar-top">
                    <span class="muted toolbar-note"><i class="fas fa-info-circle"></i>
                        <?= $view === 'summary'
                            ? 'Tính thẳng từ sổ phát sinh: tồn cuối = tồn đầu + nhập − xuất. Điều chuyển là xuất ở kho đi và nhập ở kho đến.'
                            : 'Mỗi dòng là 1 phát sinh đã ghi sổ (chỉ thêm, không sửa / xóa). "Tồn sau" là số dư của kho × lô ngay sau phát sinh đó.' ?></span>
                    <a class="btn btn-export" href="<?= e($exportUrl) ?>"><i class="fas fa-file-export"></i> Xuất Excel</a>
                </div>
                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left"></div>
                    <form class="toolbar-bottom-right" method="get">
                        <input type="hidden" name="view" value="<?= $view ?>">
                        <input type="hidden" name="size" value="<?= $pageSize ?>">
                        <?php if ($view === 'summary'): ?><?= sortHiddenInputs($sort) ?><?php endif; ?>
                        <div class="date-range">
                            <input class="filter-select" type="date" name="from" value="<?= e($from) ?>" max="<?= date('Y-m-d') ?>" aria-label="Từ ngày">
                            <span class="muted">→</span>
                            <input class="filter-select" type="date" name="to" value="<?= e($to) ?>" max="<?= date('Y-m-d') ?>" aria-label="Đến ngày">
                        </div>
                        <?php if ($view === 'detail'): ?>
                            <select class="filter-select" name="type" onchange="this.form.submit()">
                                <option value="">Mọi loại chứng từ</option>
                                <?php foreach (['IMPORT', 'EXPORT', 'TRANSFER', 'ADJUSTMENT'] as $voucherType): ?>
                                    <option value="<?= $voucherType ?>" <?= $filters['type'] === $voucherType ? 'selected' : '' ?>><?= e(voucherTypeLabel($voucherType)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <select class="filter-select" name="warehouse" onchange="this.form.submit()">
                            <option value="0">Tất cả kho được xem</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $filters['warehouse_id'] ? 'selected' : '' ?>><?= e($warehouse['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="search-box">
                            <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="<?= $view === 'summary' ? 'Mã / tên hàng...' : 'Mã / tên hàng, số lô, mã phiếu...' ?>">
                            <button type="submit"><i class="fas fa-search" style="color: #94a3b8;"></i></button>
                        </div>
                        <a class="btn btn-icon" href="report_ledger.php?view=<?= $view ?>" title="Bỏ lọc"><i class="fas fa-sync-alt"></i></a>
                    </form>
                </div>
            </div>

            <?php if ($view === 'summary'): ?>
                <div class="table-container">
                    <table class="nowrap-table">
                        <thead>
                            <tr>
                                <?= sortHeader('Mã Hàng', 'sku', $sort) ?>
                                <?= sortHeader('Tên Mặt Hàng', 'name', $sort) ?>
                                <th>ĐVT</th>
                                <?= sortHeader('Tồn Đầu Kỳ', 'opening', $sort, 'text-right', 'desc') ?>
                                <?= sortHeader('Nhập Trong Kỳ', 'in', $sort, 'text-right', 'desc') ?>
                                <?= sortHeader('Xuất Trong Kỳ', 'out', $sort, 'text-right', 'desc') ?>
                                <?= sortHeader('Tồn Cuối Kỳ', 'closing', $sort, 'text-right', 'desc') ?>
                                <th style="text-align: center;">Thẻ Kho</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($summary)): ?>
                                <tr class="empty-row"><td colspan="8">Không có phát sinh nào trong kỳ <?= formatDate($from) ?> – <?= formatDate($to) ?>.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($summary as $row): ?>
                                <tr>
                                    <td><?= e($row['sku']) ?></td>
                                    <td class="wrap"><?= e($row['item_name']) ?></td>
                                    <td class="muted"><?= e($row['unit']) ?></td>
                                    <td class="text-right num"><?= formatQty($row['opening']) ?></td>
                                    <td class="text-right num qty-in"><?= toMilli($row['qty_in']) ? '+' . formatQty($row['qty_in']) : '—' ?></td>
                                    <td class="text-right num qty-out"><?= toMilli($row['qty_out']) ? '−' . formatQty($row['qty_out']) : '—' ?></td>
                                    <td class="text-right num"><strong><?= formatQty($row['closing']) ?></strong></td>
                                    <td class="row-actions"><a href="<?= e($query(['view' => 'detail', 'q' => $row['sku'], 'type' => ''])) ?>" title="Xem từng phát sinh của mặt hàng"><i class="fas fa-book icon-view"></i></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($summary): ?>
                            <tfoot><tr><td colspan="8"><?= count($summary) ?> mặt hàng có phát sinh hoặc còn tồn · kỳ <?= formatDate($from) ?> – <?= formatDate($to) ?></td></tr></tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="nowrap-table">
                        <thead>
                            <tr><th>Thời điểm</th><th>Chứng từ</th><th>Bước</th><th>Kho</th><th>Mặt hàng</th><th>Lô</th><th class="text-right">Nhập</th><th class="text-right">Xuất</th><th class="text-right">Tồn sau</th><th>Người ghi</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($entries)): ?>
                                <tr class="empty-row"><td colspan="10">Không có phát sinh nào khớp bộ lọc trong kỳ <?= formatDate($from) ?> – <?= formatDate($to) ?>.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($entries as $entry): ?>
                                <tr>
                                    <td><?= e(date('d/m/Y H:i', strtotime($entry['moved_at']))) ?></td>
                                    <td><a class="link-strong" href="voucher_detail.php?id=<?= (int) $entry['voucher_id'] ?>"><?= e($entry['voucher_code']) ?></a></td>
                                    <td><span class="badge badge-step"><?= e(postingKindLabel($entry['posting_kind'])) ?></span></td>
                                    <td><?= e($entry['warehouse_name']) ?></td>
                                    <td><?= e($entry['sku'] . ' - ' . $entry['item_name']) ?></td>
                                    <td><?= e($entry['batch_no']) ?></td>
                                    <td class="text-right num qty-in"><?= toMilli($entry['qty_in']) ? '+' . formatQty($entry['qty_in']) : '' ?></td>
                                    <td class="text-right num qty-out"><?= toMilli($entry['qty_out']) ? '−' . formatQty($entry['qty_out']) : '' ?></td>
                                    <td class="text-right num"><strong><?= formatQty($entry['balance_after']) ?></strong> <span class="muted"><?= e($entry['unit']) ?></span></td>
                                    <td><?= e($entry['created_by_name']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
<?php require __DIR__ . '/layout/pagination.php'; ?>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
