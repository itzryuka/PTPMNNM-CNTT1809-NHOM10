<?php
// Chi tiết chứng từ: kiểm tra tồn, xác nhận / từ chối, xem các dòng sổ phát sinh đã ghi
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';

$user      = requireLogin('../');
$voucherId = (int) ($_GET['id'] ?? 0);

$voucher    = null;
$lines      = [];
$movements  = [];
$openCounts = [];
$dbError    = '';
try {
    $pdo     = getDbConnection();
    $voucher = getVoucher($pdo, $voucherId);
    if ($voucher === null) {
        http_response_code(404);
        $dbError = 'Không tìm thấy chứng từ.';
    } else {
        $warehouseIds = array_values(array_filter([(int) $voucher['warehouse_id'], (int) $voucher['to_warehouse_id']]));
        // Quyền xem: phải phụ trách kho đi hoặc kho đến của chứng từ
        $canView = (bool) array_filter($warehouseIds, fn ($id) => canAccessWarehouse($user, $id));
        if (!$canView) {
            http_response_code(403);
            $dbError = 'Bạn không có quyền xem chứng từ của kho này.';
            $voucher = null;
        } else {
            $lines = getVoucherLines($pdo, $voucherId, (int) $voucher['warehouse_id']);
            if ($voucher['status'] === 'APPROVED') {
                $movements = getVoucherMovements($pdo, $voucherId);
            } elseif ($voucher['status'] === 'PENDING') {
                $openCounts = getOpenStockCounts($pdo, $warehouseIds);
            }
        }
    }
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$type       = $voucher['type'] ?? 'IMPORT';
$isPending  = ($voucher['status'] ?? '') === 'PENDING';
$decreases  = in_array($type, ['EXPORT', 'TRANSFER'], true);   // phiếu làm giảm tồn kho chính
$canDecide  = $voucher !== null && $isPending && isManager($user)
              && !array_filter($warehouseIds, fn ($id) => !canAccessWarehouse($user, $id));
$listUrl    = in_array($type, ['IMPORT', 'EXPORT'], true) ? 'voucher_list.php?type=' . $type : '../index.php';

$pageTitle  = $voucher ? voucherTypeLabel($type) . ' ' . $voucher['code'] : 'Chi tiết chứng từ';
$basePath   = '../';
$activeMenu = ['IMPORT' => 'imports', 'EXPORT' => 'exports', 'TRANSFER' => 'transfers', 'ADJUSTMENT' => 'adjustments'][$type];
require __DIR__ . '/layout/header.php';
?>

            <?php if ($voucher): ?>
                <?php if ($isPending && $openCounts): ?>
                    <div class="alert-warning">
                        <i class="fas fa-snowflake"></i>
                        Kho <?= e($openCounts[0]['warehouse_code']) ?> đang kiểm kê (đợt <?= e($openCounts[0]['code']) ?>): tồn kho đang bị đóng băng, phiếu chỉ duyệt được sau khi chốt kiểm kê.
                    </div>
                <?php endif; ?>

                <!-- THÔNG TIN PHIẾU -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-file-invoice"></i> <?= e($voucher['code']) ?></h3>
                        <?= voucherStatusBadge($voucher['status']) ?>
                    </div>
                    <dl class="detail-grid">
                        <div><dt>Loại chứng từ</dt><dd><?= e(voucherTypeLabel($type)) ?></dd></div>
                        <div>
                            <dt><?= $type === 'TRANSFER' ? 'Kho đi → kho đến' : 'Kho' ?></dt>
                            <dd>
                                <?= e($voucher['warehouse_name']) ?>
                                <?php if ($voucher['to_warehouse_name']): ?> → <?= e($voucher['to_warehouse_name']) ?><?php endif; ?>
                            </dd>
                        </div>
                        <div><dt>Ngày lập</dt><dd><?= formatDate($voucher['voucher_date']) ?></dd></div>
                        <div><dt>Người lập</dt><dd><?= e($voucher['creator_name']) ?></dd></div>
                        <div class="span-2"><dt>Lý do / ghi chú</dt><dd><?= $voucher['reason'] !== null ? e($voucher['reason']) : '—' ?></dd></div>
                        <?php if ($voucher['reference_code']): ?>
                            <div><dt>Điều chỉnh cho chứng từ</dt><dd><a class="link-strong" href="voucher_detail.php?id=<?= (int) $voucher['reference_voucher_id'] ?>"><?= e($voucher['reference_code']) ?></a></dd></div>
                        <?php endif; ?>
                        <?php if (!$isPending): ?>
                            <div>
                                <dt><?= $voucher['status'] === 'APPROVED' ? 'Người xác nhận' : 'Người từ chối' ?></dt>
                                <dd><?= e($voucher['approver_name']) ?><br><span class="muted"><?= e(date('d/m/Y H:i', strtotime($voucher['approved_at']))) ?></span></dd>
                            </div>
                        <?php endif; ?>
                    </dl>
                </div>

                <!-- DÒNG HÀNG -->
                <div class="panel" style="margin-top: 20px;">
                    <div class="panel-header"><h3><i class="fas fa-list"></i> Chi tiết hàng hóa</h3></div>
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Mặt hàng</th>
                                    <th>Lô</th>
                                    <th>Hạn SD</th>
                                    <th class="text-right">Số lượng</th>
                                    <th>ĐVT</th>
                                    <?php if ($isPending && $decreases): ?>
                                        <th class="text-right">Tồn hiện tại</th>
                                        <th>Kiểm tra</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $shortage = false; ?>
                                <?php foreach ($lines as $n => $line): ?>
                                    <?php $enough = toMilli($line['current_qty']) >= toMilli($line['quantity']); $shortage = $shortage || !$enough; ?>
                                    <tr>
                                        <td><?= $n + 1 ?></td>
                                        <td><?= e($line['sku'] . ' - ' . $line['item_name']) ?></td>
                                        <td><?= e($line['batch_no']) ?></td>
                                        <td><?= $line['expiry_date'] ? formatDate($line['expiry_date']) : '—' ?></td>
                                        <td class="text-right"><strong><?= formatQty($line['quantity']) ?></strong></td>
                                        <td class="muted"><?= e($line['unit']) ?></td>
                                        <?php if ($isPending && $decreases): ?>
                                            <td class="text-right"><?= formatQty($line['current_qty']) ?></td>
                                            <td>
                                                <?php if ($enough): ?>
                                                    <span class="badge badge-active">Đủ hàng</span>
                                                <?php else: ?>
                                                    <span class="badge badge-rejected">Thiếu <?= formatQty($line['quantity'] - $line['current_qty']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SỔ PHÁT SINH ĐÃ GHI -->
                <?php if ($movements): ?>
                    <div class="panel" style="margin-top: 20px;">
                        <div class="panel-header"><h3><i class="fas fa-book"></i> Sổ phát sinh đã ghi nhận</h3></div>
                        <div class="table-container" style="border: none; border-radius: 0;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Thời điểm</th>
                                        <th>Kho</th>
                                        <th>Mặt hàng</th>
                                        <th>Lô</th>
                                        <th class="text-right">Phát sinh</th>
                                        <th>Người ghi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($movements as $movement): ?>
                                        <tr>
                                            <td><?= e(date('d/m/Y H:i', strtotime($movement['moved_at']))) ?></td>
                                            <td><?= e($movement['warehouse_code']) ?></td>
                                            <td><?= e($movement['sku'] . ' - ' . $movement['item_name']) ?></td>
                                            <td><?= e($movement['batch_no']) ?></td>
                                            <td class="text-right <?= $movement['quantity'] > 0 ? 'qty-in' : 'qty-out' ?>">
                                                <?= $movement['quantity'] > 0 ? '+' : '−' ?><?= formatQty(abs($movement['quantity'])) ?> <?= e($movement['unit']) ?>
                                            </td>
                                            <td><?= e($movement['created_by_name']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- THAO TÁC -->
                <div class="form-actions">
                    <a class="btn btn-light" href="<?= e($listUrl) ?>"><i class="fas fa-arrow-left"></i> Quay lại danh sách</a>
                    <?php if ($canDecide): ?>
                        <form method="post" action="../handle/voucher_process.php" onsubmit="return confirm('Từ chối chứng từ <?= e($voucher['code']) ?>? Phiếu sẽ bị khóa, không ghi nhận phát sinh.') && lockButtons(this);">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                            <button class="btn btn-danger" type="submit"><i class="fas fa-ban"></i> Từ chối</button>
                        </form>
                        <form method="post" action="../handle/voucher_process.php" onsubmit="return confirm('Xác nhận chứng từ <?= e($voucher['code']) ?>? Sau khi xác nhận sẽ ghi sổ phát sinh và không sửa / xóa được nữa.') && lockButtons(this);">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                            <button class="btn btn-import" type="submit" <?= $openCounts ? 'disabled' : '' ?>><i class="fas fa-check"></i> Xác nhận chứng từ</button>
                        </form>
                    <?php elseif ($isPending): ?>
                        <span class="muted"><i class="fas fa-hourglass-half"></i> Chờ quản lý kho xác nhận.</span>
                    <?php else: ?>
                        <span class="muted"><i class="fas fa-lock"></i> Chứng từ đã xử lý: không sửa / xóa được. Muốn sửa sai, hãy lập phiếu điều chỉnh.</span>
                    <?php endif; ?>
                </div>
                <?php if ($canDecide && $shortage): ?>
                    <p class="form-hint text-right">Có dòng đang thiếu hàng: nếu xác nhận, hệ thống sẽ từ chối ghi nhận và không thay đổi tồn kho.</p>
                <?php endif; ?>

                <script>
                    // Bấm 1 lần là khóa nút (server vẫn chặn ghi đúp bằng khóa phiếu FOR UPDATE)
                    function lockButtons(form) {
                        setTimeout(() => document.querySelectorAll('.form-actions button').forEach(b => { b.disabled = true; }), 0);
                        return true;
                    }
                </script>
            <?php else: ?>
                <a class="btn btn-light" href="../index.php" style="display: inline-flex;"><i class="fas fa-arrow-left"></i> Về trang tổng quan</a>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
