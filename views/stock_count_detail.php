<?php
// Chi tiết đợt kiểm kê: nhập số đếm thực tế, xem chênh lệch, duyệt (sinh phiếu điều chỉnh) / hủy
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/stock_count_functions.php';

$user    = requireLogin('../');
$countId = (int) ($_GET['id'] ?? 0);

// Số đếm đã gõ nhưng lưu lỗi lần trước
$old = $_SESSION['stock_count_old'] ?? null;
unset($_SESSION['stock_count_old']);
$oldLines = (is_array($old) && (int) $old['id'] === $countId && is_array($old['lines'])) ? $old['lines'] : [];

$count   = null;
$lines   = [];
$dbError = '';
try {
    $pdo   = getDbConnection();
    $count = getStockCount($pdo, $countId);
    if ($count === null) {
        http_response_code(404);
        $dbError = 'Không tìm thấy đợt kiểm kê.';
    } elseif (!canAccessWarehouse($user, (int) $count['warehouse_id'])) {
        http_response_code(403);
        $dbError = 'Bạn không có quyền xem đợt kiểm kê của kho này.';
        $count   = null;
    } else {
        $lines = getStockCountLines($pdo, $countId);
    }
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$isOpen      = ($count['status'] ?? '') === 'COUNTING';
$canDecide   = $count !== null && $isOpen && isManager($user);
$countedRows = count(array_filter($lines, fn ($l) => $l['counted_qty'] !== null));
$diffRows    = count(array_filter($lines, fn ($l) => $l['counted_qty'] !== null && toMilli($l['difference']) !== 0));

$pageTitle    = $count ? 'Kiểm kê ' . $count['code'] : 'Đợt kiểm kê';
$basePath     = '../';
$activeMenu   = $isOpen ? 'differences' : 'stock_counts';
$extraScripts = ['js/stock_count.js'];
require __DIR__ . '/layout/header.php';
?>

            <?php if ($count): ?>
                <?php if ($isOpen): ?>
                    <div class="alert-warning">
                        <i class="fas fa-snowflake"></i>
                        <?= e($count['warehouse_name']) ?> đang kiểm kê: mọi phiếu nhập / xuất / điều chuyển của kho tạm thời không duyệt được cho tới khi chốt đợt này.
                    </div>
                <?php endif; ?>

                <!-- THÔNG TIN ĐỢT -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-clipboard-check"></i> <?= e($count['code']) ?></h3>
                        <?= stockCountStatusBadge($count['status']) ?>
                    </div>
                    <dl class="detail-grid">
                        <div><dt>Kho</dt><dd><?= e($count['warehouse_name']) ?></dd></div>
                        <div><dt>Chụp số tồn lúc</dt><dd><?= e(date('d/m/Y H:i', strtotime($count['created_at']))) ?></dd></div>
                        <div><dt>Người mở đợt</dt><dd><?= e($count['creator_name']) ?></dd></div>
                        <div><dt>Tiến độ</dt><dd>Đã đếm <?= $countedRows ?> / <?= count($lines) ?> dòng · <?= $diffRows ?> dòng lệch</dd></div>
                        <div class="span-2"><dt>Ghi chú</dt><dd><?= $count['note'] !== null ? e($count['note']) : '—' ?></dd></div>
                        <?php if (!$isOpen): ?>
                            <div>
                                <dt><?= $count['status'] === 'APPROVED' ? 'Người duyệt' : 'Người hủy' ?></dt>
                                <dd><?= e($count['approver_name']) ?><br><span class="muted"><?= e(date('d/m/Y H:i', strtotime($count['approved_at']))) ?></span></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ($count['adjustment_code']): ?>
                            <div>
                                <dt>Phiếu điều chỉnh</dt>
                                <dd><a class="link-strong" href="voucher_detail.php?id=<?= (int) $count['adjustment_voucher_id'] ?>"><?= e($count['adjustment_code']) ?></a><br><span class="muted"><?= e($count['adjustment_reason']) ?></span></dd>
                            </div>
                        <?php elseif ($count['status'] === 'APPROVED'): ?>
                            <div><dt>Phiếu điều chỉnh</dt><dd class="muted">Không có chênh lệch</dd></div>
                        <?php endif; ?>
                    </dl>
                </div>

                <!-- DÒNG KIỂM KÊ -->
                <form method="post" action="../handle/stock_count_process.php" id="countForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int) $count['id'] ?>">

                    <div class="panel" style="margin-top: 20px;">
                        <div class="panel-header">
                            <h3><i class="fas fa-list-ol"></i> Số tồn hệ thống và số đếm thực tế</h3>
                            <?php if ($isOpen): ?>
                                <span class="muted">Chênh lệch = Đếm thực tế − Tồn hệ thống</span>
                            <?php endif; ?>
                        </div>
                        <div class="table-container count-table" style="border: none; border-radius: 0;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Mặt hàng</th>
                                        <th>Lô / Hạn SD</th>
                                        <th class="text-right">Tồn hệ thống</th>
                                        <th style="width: 140px;">Đếm thực tế</th>
                                        <th class="text-right">Chênh lệch</th>
                                        <th style="min-width: 220px;">Lý do chênh lệch</th>
                                        <th>Người đếm</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lines as $n => $line): ?>
                                        <?php
                                        $lineId  = (int) $line['id'];
                                        $counted = $oldLines[$lineId]['counted_qty'] ?? ($line['counted_qty'] !== null ? rtrim(rtrim($line['counted_qty'], '0'), '.') : '');
                                        $reason  = $oldLines[$lineId]['reason'] ?? ($line['reason'] ?? '');
                                        ?>
                                        <tr class="js-count-row" data-system="<?= e($line['system_qty']) ?>">
                                            <td><?= $n + 1 ?></td>
                                            <td><?= e($line['sku'] . ' - ' . $line['item_name']) ?></td>
                                            <td><?= e($line['batch_no']) ?><br><span class="muted"><?= $line['expiry_date'] ? formatDate($line['expiry_date']) : '—' ?></span></td>
                                            <td class="text-right"><?= formatQty($line['system_qty']) ?> <span class="muted"><?= e($line['unit']) ?></span></td>
                                            <td>
                                                <?php if ($isOpen): ?>
                                                    <input class="form-control js-counted" type="number" min="0" step="0.001"
                                                           name="lines[<?= $lineId ?>][counted_qty]" value="<?= e($counted) ?>" placeholder="Chưa đếm">
                                                <?php else: ?>
                                                    <?= $line['counted_qty'] !== null ? formatQty($line['counted_qty']) : '—' ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right js-diff"><?= $line['counted_qty'] !== null ? diffLabel($line['difference']) : '<span class="muted">—</span>' ?></td>
                                            <td>
                                                <?php if ($isOpen): ?>
                                                    <input class="form-control js-reason" name="lines[<?= $lineId ?>][reason]" maxlength="255"
                                                           value="<?= e($reason) ?>" placeholder="Bắt buộc khi có chênh lệch">
                                                <?php else: ?>
                                                    <?= $line['reason'] !== null ? e($line['reason']) : '<span class="muted">—</span>' ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $line['counted_by_name'] ? e($line['counted_by_name']) : '<span class="muted">—</span>' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($isOpen): ?>
                        <div class="form-actions">
                            <span class="muted" id="dirtyHint"></span>
                            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Lưu số đếm</button>
                        </div>
                    <?php endif; ?>
                </form>

                <?php if ($canDecide): ?>
                    <!-- DUYỆT / HỦY (quản lý) -->
                    <div class="form-card" style="margin-top: 20px;">
                        <h3><i class="fas fa-gavel"></i> Chốt đợt kiểm kê</h3>
                        <p class="form-hint">
                            Khi duyệt, hệ thống sinh <strong>một phiếu điều chỉnh</strong> cho các dòng lệch (thực tế nhiều hơn → nhập thêm, ít hơn → xuất bớt),
                            ghi sổ phát sinh và cập nhật tồn trong cùng một giao dịch, rồi mở khóa kho.
                            Yêu cầu: đã đếm hết các dòng và dòng lệch nào cũng có lý do.
                        </p>
                        <div class="decision-row">
                            <form method="post" action="../handle/stock_count_process.php" class="approve-form js-decision"
                                  data-confirm="Duyệt đợt <?= e($count['code']) ?>? Phiếu điều chỉnh sẽ được ghi sổ và không sửa / xóa được.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="id" value="<?= (int) $count['id'] ?>">
                                <input class="form-control" name="reason" maxlength="200" required
                                       value="Điều chỉnh tồn theo số đếm thực tế" placeholder="Lý do điều chỉnh (bắt buộc)">
                                <button class="btn btn-import" type="submit"><i class="fas fa-check"></i> Duyệt &amp; sinh phiếu điều chỉnh</button>
                            </form>
                            <form method="post" action="../handle/stock_count_process.php" class="js-decision"
                                  data-confirm="Hủy đợt <?= e($count['code']) ?>? Số đếm bị bỏ, không ghi phát sinh nào.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= (int) $count['id'] ?>">
                                <button class="btn btn-danger" type="submit"><i class="fas fa-ban"></i> Hủy đợt</button>
                            </form>
                        </div>
                    </div>
                <?php elseif ($isOpen): ?>
                    <p class="form-hint text-right"><i class="fas fa-hourglass-half"></i> Nhập và lưu số đếm; quản lý kho sẽ duyệt đợt kiểm kê.</p>
                <?php endif; ?>

                <div class="form-actions">
                    <a class="btn btn-light" href="stock_count_list.php"><i class="fas fa-arrow-left"></i> Danh sách đợt kiểm kê</a>
                </div>
            <?php else: ?>
                <a class="btn btn-light" href="stock_count_list.php" style="display: inline-flex;"><i class="fas fa-arrow-left"></i> Danh sách đợt kiểm kê</a>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
