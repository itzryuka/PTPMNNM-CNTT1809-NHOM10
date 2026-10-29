<?php
// Chi tiết lô: thông tin, hạn dùng, lô đang ở kho nào (tồn / đang giữ / có thể xuất), các phiếu đã dùng lô này
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/batch_functions.php';

$user    = requireLogin('../');
$batchId = (int) ($_GET['id'] ?? 0);

$batch    = null;
$stock    = [];
$vouchers = [];
$dbError  = '';
try {
    $pdo   = getDbConnection();
    $batch = getBatch($pdo, $batchId);
    if ($batch === null) {
        http_response_code(404);
        $dbError = 'Không tìm thấy lô hàng.';
    } else {
        $stock    = getBatchStock($pdo, $batchId, $user['warehouses']);
        $vouchers = getBatchVouchers($pdo, $batchId, $user['warehouses']);
    }
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}
$sum = fn (string $column) => array_sum(array_map(fn ($r) => (float) $r[$column], $stock));

$pageTitle  = $batch ? 'Lô ' . $batch['batch_no'] : 'Lô hàng';
$basePath   = '../';
$activeMenu = 'batches';
require __DIR__ . '/layout/header.php';
?>

            <?php if ($batch): ?>
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-layer-group"></i> Lô <?= e($batch['batch_no']) ?></h3>
                        <?= expiryBadge($batch['expiry_date'], $batch['days_left']) ?>
                    </div>
                    <dl class="detail-grid">
                        <div class="span-2"><dt>Mặt hàng</dt><dd><a class="link-strong" href="item_detail.php?id=<?= (int) $batch['item_id'] ?>"><?= e($batch['sku'] . ' - ' . $batch['item_name']) ?></a></dd></div>
                        <div><dt>Ngày sản xuất</dt><dd><?= $batch['mfg_date'] ? formatDate($batch['mfg_date']) : '—' ?></dd></div>
                        <div><dt>Hạn sử dụng</dt><dd><?= $batch['expiry_date'] ? formatDate($batch['expiry_date']) : '—' ?></dd></div>
                        <div><dt>Tồn ở các kho bạn được xem</dt><dd><strong><?= formatQty($sum('quantity')) ?></strong> <?= e($batch['unit']) ?></dd></div>
                        <div><dt>Có thể xuất</dt><dd><strong><?= formatQty($sum('available')) ?></strong> <?= e($batch['unit']) ?><?= $sum('reserved') ? ' <span class="muted">(đang giữ ' . formatQty($sum('reserved')) . ')</span>' : '' ?></dd></div>
                        <div><dt>Ngày tạo lô</dt><dd><?= e(date('d/m/Y', strtotime($batch['created_at']))) ?></dd></div>
                    </dl>
                </div>
                <?php if ($batch['days_left'] !== null && (int) $batch['days_left'] < 0 && $stock): ?>
                    <div class="alert-warning" style="margin-top: 16px;"><i class="fas fa-exclamation-triangle"></i> Lô đã hết hạn nhưng còn hàng: không xuất / điều chuyển được. Hãy xử lý hủy hàng bằng kiểm kê (ghi chênh lệch có lý do).</div>
                <?php endif; ?>

                <div class="panel" style="margin-top: 20px;">
                    <div class="panel-header"><h3><i class="fas fa-warehouse"></i> Lô đang ở kho nào</h3>
                        <a class="link-strong" href="report_ledger.php?<?= e(http_build_query(['view' => 'detail', 'q' => $batch['batch_no'], 'from' => '2000-01-01'])) ?>"><i class="fas fa-book"></i> Xem thẻ kho của lô</a></div>
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table>
                            <thead><tr><th>Kho</th><th class="text-right">Tồn thực</th><th class="text-right">Đang giữ</th><th class="text-right">Có thể xuất</th><th>Cập nhật lần cuối</th></tr></thead>
                            <tbody>
                                <?php if (empty($stock)): ?>
                                    <tr class="empty-row"><td colspan="5">Lô không còn hàng ở kho nào bạn được xem.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($stock as $row): ?>
                                    <tr>
                                        <td><a class="link-strong" href="warehouse_detail.php?id=<?= (int) $row['warehouse_id'] ?>"><?= e($row['code'] . ' - ' . $row['name']) ?></a></td>
                                        <td class="text-right num"><?= formatQty($row['quantity']) ?></td>
                                        <td class="text-right num"><?= toMilli($row['reserved']) ? '<span class="badge badge-info">' . formatQty($row['reserved']) . '</span>' : '—' ?></td>
                                        <td class="text-right num"><strong><?= formatQty($row['available']) ?></strong> <span class="muted"><?= e($batch['unit']) ?></span></td>
                                        <td><?= e(date('d/m/Y H:i', strtotime($row['updated_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel" style="margin-top: 20px;">
                    <div class="panel-header"><h3><i class="fas fa-file-invoice"></i> Phiếu đã dùng lô này</h3><span class="muted"><?= count($vouchers) ?> phiếu gần nhất</span></div>
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table class="nowrap-table">
                            <thead><tr><th>Mã phiếu</th><th>Loại</th><th>Ngày lập</th><th>Kho</th><th class="text-right">Số lượng</th><th>Trạng thái</th></tr></thead>
                            <tbody>
                                <?php if (empty($vouchers)): ?>
                                    <tr class="empty-row"><td colspan="6">Chưa có phiếu nào ở kho bạn được xem.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($vouchers as $voucher): ?>
                                    <tr>
                                        <td><a class="link-strong" href="voucher_detail.php?id=<?= (int) $voucher['id'] ?>"><?= e($voucher['code']) ?></a></td>
                                        <td><?= e(voucherTypeLabel($voucher['type'])) ?></td>
                                        <td><?= formatDate($voucher['voucher_date']) ?></td>
                                        <td><?= e($voucher['warehouse_name']) ?><?= $voucher['to_warehouse_name'] ? ' → ' . e($voucher['to_warehouse_name']) : '' ?></td>
                                        <td class="text-right num"><?= $voucher['type'] === 'ADJUSTMENT' && $voucher['quantity'] > 0 ? '+' : '' ?><?= $voucher['type'] === 'ADJUSTMENT' && $voucher['quantity'] < 0 ? '−' . formatQty(-$voucher['quantity']) : formatQty($voucher['quantity']) ?></td>
                                        <td><?= voucherStatusBadge($voucher['status'], $voucher['type']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="form-actions">
                    <a class="btn btn-light" href="batch_list.php"><i class="fas fa-arrow-left"></i> Danh sách lô</a>
                </div>
            <?php else: ?>
                <a class="btn btn-light" href="batch_list.php" style="display: inline-flex;"><i class="fas fa-arrow-left"></i> Danh sách lô</a>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
