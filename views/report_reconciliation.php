<?php
// Đối chiếu sổ: 4 phép kiểm tra tính toàn vẹn giữa tồn kho, hàng đang giữ, hàng đang đi đường và sổ phát sinh
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/report_functions.php';

$user = requireLogin('../');

$report  = [];
$dbError = '';
try {
    $report = getReconciliationReport(getDbConnection(), $user['warehouses']);
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}
$checks = [
    'stock'       => ['Tồn kho = tổng sổ phát sinh', 'Mỗi kho × lô: số tồn đang lưu bằng tổng mọi phát sinh đã ghi. Lệch nghĩa là có ai sửa tồn ngoài sổ.', ['Tồn đang lưu', 'Tổng sổ phát sinh']],
    'reservation' => ['Hàng đang giữ = phiếu đã duyệt chưa rời kho', 'Số "đang giữ" của mỗi lô bằng tổng các phiếu xuất / điều chuyển đã duyệt nhưng chưa xuất / gửi.', ['Đang giữ', 'Theo phiếu đã duyệt']],
    'transit'     => ['Hàng đang đi đường = phiếu điều chuyển chưa nhận', 'Tồn kho trung chuyển bằng tổng (đã gửi − đã nhận − hao hụt) của các phiếu điều chuyển. Kiểm tra trên toàn hệ thống.', ['Tồn trung chuyển', 'Theo phiếu']],
    'progress'    => ['Tiến độ phiếu khớp các đợt ghi sổ', 'Số đã nhận / đã xuất / đã gửi ghi trên từng dòng phiếu bằng tổng phát sinh của các đợt ghi sổ tương ứng.', null],
];
$allOk = $report && !array_filter($report, fn ($r) => $r['bad']);

$pageTitle  = 'Đối chiếu sổ';
$basePath   = '../';
$activeMenu = 'reconciliation';
require __DIR__ . '/layout/header.php';
?>

            <?php if ($report): ?>
                <div class="recon-banner <?= $allOk ? 'ok' : 'bad' ?>" style="margin: 0 0 16px;">
                    <i class="fas <?= $allOk ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <div>
                        <strong><?= $allOk ? 'Sổ sách khớp: cả 4 phép kiểm tra đều đạt.' : 'Phát hiện chênh lệch, xem chi tiết bên dưới.' ?></strong>
                        Kiểm tra lúc <?= date('d/m/Y H:i') ?>, trên các kho bạn được xem. Tải lại trang để kiểm tra lại.
                    </div>
                </div>

                <div class="check-list">
                    <?php foreach ($checks as $key => [$title, $explain, $columns]): ?>
                        <?php $result = $report[$key]; $ok = !$result['bad']; ?>
                        <div class="check-item <?= $ok ? 'ok' : 'bad' ?>">
                            <i class="fas <?= $ok ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                            <div style="flex: 1; min-width: 0;">
                                <h4><?= e($title) ?> — <?= $ok ? 'đạt' : count($result['bad']) . ' dòng lệch' ?></h4>
                                <p><?= e($explain) ?> Đã kiểm <?= $result['total'] ?> dòng.</p>
                                <?php if (!$ok): ?>
                                    <div class="table-container" style="margin-top: 10px;">
                                        <table class="nowrap-table">
                                            <?php if ($key === 'progress'): ?>
                                                <thead><tr><th>Phiếu</th><th>Mặt hàng / lô</th><th class="text-right">Nhận (dòng / sổ)</th><th class="text-right">Xuất, gửi (dòng / sổ)</th></tr></thead>
                                                <tbody>
                                                    <?php foreach ($result['bad'] as $row): ?>
                                                        <tr><td><?= e($row['code']) ?></td><td><?= e($row['sku'] . ' · ' . $row['batch_no']) ?></td>
                                                            <td class="text-right"><?= formatQty($row['received_qty']) ?> / <?= formatQty($row['type'] === 'TRANSFER' ? $row['arrived_posted'] : $row['received_posted']) ?></td>
                                                            <td class="text-right"><?= formatQty($row['shipped_qty']) ?> / <?= formatQty($row['shipped_posted']) ?></td></tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            <?php else: ?>
                                                <thead><tr><?php if ($key !== 'transit'): ?><th>Kho</th><?php endif; ?><th>Mặt hàng / lô</th><th class="text-right"><?= e($columns[0]) ?></th><th class="text-right"><?= e($columns[1]) ?></th></tr></thead>
                                                <tbody>
                                                    <?php foreach ($result['bad'] as $row): ?>
                                                        <tr><?php if ($key !== 'transit'): ?><td><?= e($row['warehouse_name']) ?></td><?php endif; ?>
                                                            <td><?= e($row['sku'] . ' - ' . $row['item_name'] . ' · ' . $row['batch_no']) ?></td>
                                                            <td class="text-right"><?= formatQty($row['left_qty']) ?></td><td class="text-right"><?= formatQty($row['right_qty']) ?></td></tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
