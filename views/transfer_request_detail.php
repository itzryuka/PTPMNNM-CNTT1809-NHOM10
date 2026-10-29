<?php
// Chi tiết yêu cầu điều chuyển: hàng cần + số có thể xuất hiện tại ở kho được đề nghị; đồng ý / từ chối / rút lại
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/transfer_request_functions.php';

$user      = requireLogin('../');
$requestId = (int) ($_GET['id'] ?? 0);

$request = null;
$lines   = [];
$dbError = '';
try {
    $pdo     = getDbConnection();
    $request = getTransferRequest($pdo, $requestId);
    if ($request === null) {
        http_response_code(404);
        $dbError = 'Không tìm thấy yêu cầu điều chuyển.';
    } elseif (!canAccessWarehouse($user, (int) $request['from_warehouse_id']) && !canAccessWarehouse($user, (int) $request['to_warehouse_id'])) {
        http_response_code(403);
        $dbError = 'Bạn không có quyền xem yêu cầu của kho này.';
        $request = null;
    } else {
        $lines = getTransferRequestLines($pdo, $requestId, (int) $request['from_warehouse_id']);
    }
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$isPending = ($request['status'] ?? '') === 'PENDING';
$canDecide = $request && $isPending && isManager($user) && canAccessWarehouse($user, (int) $request['from_warehouse_id']);
$canCancel = $request && $isPending && canAccessWarehouse($user, (int) $request['to_warehouse_id']);
$enough    = !array_filter($lines, fn ($l) => toMilli($l['available']) < toMilli($l['quantity']));

$pageTitle  = $request ? 'Yêu cầu ' . $request['code'] : 'Yêu cầu điều chuyển';
$basePath   = '../';
$activeMenu = 'transfer_requests';
require __DIR__ . '/layout/header.php';
?>

            <?php if ($request): ?>
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-hand-holding"></i> <?= e($request['code']) ?></h3>
                        <?= transferRequestStatusBadge($request['status']) ?>
                    </div>
                    <dl class="detail-grid">
                        <div><dt>Kho cần hàng</dt><dd><?= e($request['to_code'] . ' - ' . $request['to_name']) ?></dd></div>
                        <div><dt>Đề nghị kho gửi hàng</dt><dd><?= e($request['from_code'] . ' - ' . $request['from_name']) ?></dd></div>
                        <div><dt>Cần trước ngày</dt><dd><?= $request['needed_by'] ? formatDate($request['needed_by']) : '—' ?></dd></div>
                        <div><dt>Người yêu cầu</dt><dd><?= e($request['creator_name']) ?><br><span class="muted"><?= e(date('d/m/Y H:i', strtotime($request['created_at']))) ?></span></dd></div>
                        <div class="span-2"><dt>Lý do</dt><dd><?= e($request['reason']) ?></dd></div>
                        <?php if (!$isPending): ?>
                            <div>
                                <dt><?= $request['status'] === 'CANCELLED' ? 'Người rút lại' : 'Người xử lý' ?></dt>
                                <dd><?= e($request['decider_name']) ?><br><span class="muted"><?= e(date('d/m/Y H:i', strtotime($request['decided_at']))) ?></span></dd>
                            </div>
                            <?php if ($request['decision_note']): ?><div><dt>Ghi chú xử lý</dt><dd><?= e($request['decision_note']) ?></dd></div><?php endif; ?>
                        <?php endif; ?>
                        <?php if ($request['transfer_voucher_id']): ?>
                            <div class="span-2">
                                <dt>Phiếu điều chuyển</dt>
                                <dd><a class="link-strong" href="voucher_detail.php?id=<?= (int) $request['transfer_voucher_id'] ?>"><?= e($request['voucher_code']) ?></a>
                                    <?= voucherStatusBadge($request['voucher_status'], 'TRANSFER') ?></dd>
                            </div>
                        <?php endif; ?>
                    </dl>
                </div>

                <div class="panel" style="margin-top: 20px;">
                    <div class="panel-header"><h3><i class="fas fa-list"></i> Hàng cần</h3></div>
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th><th>Mặt hàng</th><th class="text-right">Số lượng cần</th>
                                    <?php if ($isPending): ?><th class="text-right">Có thể xuất tại <?= e($request['from_name']) ?></th><th>Kiểm tra</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lines as $n => $line): ?>
                                    <tr>
                                        <td><?= $n + 1 ?></td>
                                        <td><?= e($line['sku'] . ' - ' . $line['item_name']) ?></td>
                                        <td class="text-right"><strong><?= formatQty($line['quantity']) ?></strong> <span class="muted"><?= e($line['unit']) ?></span></td>
                                        <?php if ($isPending): ?>
                                            <td class="text-right"><?= formatQty($line['available']) ?></td>
                                            <td><?= toMilli($line['available']) >= toMilli($line['quantity'])
                                                ? '<span class="badge badge-active">Đủ hàng</span>'
                                                : '<span class="badge badge-rejected">Thiếu ' . formatQty($line['quantity'] - $line['available']) . '</span>' ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($canDecide): ?>
                    <div class="form-card step-card">
                        <h3><i class="fas fa-gavel"></i> <?= e($request['from_name']) ?> xử lý yêu cầu</h3>
                        <p class="form-hint">Đồng ý: hệ thống chọn lô hết hạn trước (chỉ lấy hàng chưa bị giữ), lập phiếu điều chuyển và <strong>giữ hàng ngay</strong> để không bị xuất cho người khác. Sau đó thủ kho bấm "Gửi hàng" khi xe rời kho.</p>
                        <div class="decision-row">
                            <form class="approve-form" method="post" action="../handle/transfer_request_process.php" data-lock=".decision-row"
                                  data-confirm-title="Đồng ý yêu cầu" data-confirm-ok="Đồng ý" data-confirm-variant="import"
                                  data-confirm="Đồng ý chuyển hàng cho <?= e($request['to_name']) ?>? Hệ thống lập phiếu điều chuyển và giữ chỗ hàng ngay.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="accept">
                                <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
                                <input class="form-control" name="note" maxlength="255" placeholder="Ghi chú (vd: gửi chuyến chiều nay)">
                                <button class="btn btn-import" type="submit" <?= $enough ? '' : 'disabled title="Kho không đủ hàng có thể xuất"' ?>><i class="fas fa-check"></i> Đồng ý &amp; lập phiếu</button>
                            </form>
                            <form class="approve-form" method="post" action="../handle/transfer_request_process.php" data-lock=".decision-row"
                                  data-confirm-title="Từ chối yêu cầu" data-confirm-ok="Từ chối"
                                  data-confirm="Từ chối yêu cầu <?= e($request['code']) ?>? Bên yêu cầu sẽ thấy lý do.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="decline">
                                <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
                                <input class="form-control" name="note" required maxlength="255" placeholder="Lý do từ chối (bắt buộc)">
                                <button class="btn btn-danger" type="submit"><i class="fas fa-ban"></i> Từ chối</button>
                            </form>
                        </div>
                        <?php if (!$enough): ?><p class="form-hint" style="margin-top: 10px;">Kho không đủ hàng có thể xuất cho mọi dòng: hãy từ chối kèm lý do, hoặc nhập thêm hàng trước.</p><?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="form-actions">
                    <a class="btn btn-light" href="transfer_request_list.php"><i class="fas fa-arrow-left"></i> Danh sách yêu cầu</a>
                    <?php if ($canCancel): ?>
                        <form method="post" action="../handle/transfer_request_process.php" data-lock=".form-actions"
                              data-confirm-title="Rút lại yêu cầu" data-confirm-ok="Rút lại" data-confirm="Rút lại yêu cầu <?= e($request['code']) ?>?">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
                            <button class="btn btn-light" type="submit"><i class="fas fa-undo"></i> Rút lại yêu cầu</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($isPending && !$canDecide): ?>
                        <span class="muted"><i class="fas fa-hourglass-half"></i> Chờ quản lý <?= e($request['from_name']) ?> xử lý.</span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <a class="btn btn-light" href="transfer_request_list.php" style="display: inline-flex;"><i class="fas fa-arrow-left"></i> Danh sách yêu cầu</a>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
