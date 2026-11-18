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

$count      = null;
$lines      = [];
$members    = [];
$candidates = [];
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
        $lines      = getStockCountLines($pdo, $countId);
        $members    = getStockCountMembers($pdo, $countId);
        $candidates = getStockCountCandidates($pdo, [(int) $count['warehouse_id']]);
    }
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$isOpen      = ($count['status'] ?? '') === 'COUNTING';
$canDecide   = $count !== null && $isOpen && isManager($user) && canAccessWarehouse($user, (int) $count['warehouse_id']);   // chỉ quản lý của kho này
// Nhập số đếm: chỉ nhân viên có tên trong đợt và quản lý kho; người khác chỉ xem
$canEnter    = $isOpen && canEnterStockCount($user, array_map('intval', array_column($members, 'id')));
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
                        <?= e($count['warehouse_name']) ?> đang kiểm kê: tạm thời không nhận / xuất / gửi / giữ hàng của kho được cho tới khi chốt đợt này.
                    </div>
                    <?php if (!$canEnter): ?>
                        <!-- Chưa có tên trong đợt: tự tham gia (nhân viên được phân quyền kho này) -->
                        <form class="join-card" method="post" action="../handle/stock_count_process.php" data-lock>
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="join">
                            <input type="hidden" name="id" value="<?= (int) $count['id'] ?>">
                            <span><i class="fas fa-user-plus"></i> Bạn chưa tham gia đợt kiểm kê này nên chỉ xem được.</span>
                            <button class="btn btn-primary" type="submit"><i class="fas fa-sign-in-alt"></i> Tham gia kiểm kê</button>
                        </form>
                    <?php endif; ?>
                    <p class="form-hint"><i class="fas fa-users"></i> Nhiều người cùng đếm được: <strong>gõ số vào dòng nào thì dòng đó giữ cho bạn ngay</strong>
                        (người khác thấy <i class="fas fa-pen"></i> tên bạn và không nhập được dòng đó). Chuyển sang dòng khác hoặc Enter là <strong>tự lưu</strong>:
                        dòng mang tên bạn, người khác không sửa được <i class="fas fa-lock"></i>, còn <strong>bạn vẫn sửa lại được</strong> cho tới khi chốt đợt.
                        Cần người khác đếm lại thì nhờ quản lý kho mở khóa dòng. Trang tự cập nhật dòng người khác đang nhập / vừa nhập.</p>
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
                        <div><dt>Tiến độ</dt><dd id="countProgress">Đã đếm <?= $countedRows ?> / <?= count($lines) ?> dòng · <?= $diffRows ?> dòng lệch</dd></div>
                        <div class="span-2">
                            <dt>Nhân viên kiểm kê</dt>
                            <dd>
                                <?php foreach ($members as $member): ?>
                                    <span class="badge <?= (int) $member['counted_lines'] > 0 ? 'badge-active' : 'badge-inactive' ?>" title="Đã đếm <?= (int) $member['counted_lines'] ?> dòng">
                                        <?= e($member['full_name']) ?> · <?= (int) $member['counted_lines'] ?> dòng
                                    </span>
                                <?php endforeach; ?>
                                <?= $members ? '' : '<span class="muted">—</span>' ?>
                            </dd>
                        </div>
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
                <!-- Gõ số: giữ dòng (claim_line); chuyển dòng / Enter: lưu riêng dòng đó bằng fetch (save_line) rồi khóa ngay - xem js/stock_count.js -->
                <form method="post" action="../handle/stock_count_process.php" id="countForm" data-can-enter="<?= $canEnter ? '1' : '' ?>"
                      data-poll="<?= $isOpen ? e('../handle/stock_count_process.php?action=lines&id=' . (int) $count['id']) : '' ?>"
                      data-can-unlock="<?= $canDecide ? '1' : '' ?>">
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
                                        <th style="min-width: 170px;">Người nhập</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lines as $n => $line): ?>
                                        <?php
                                        $lineId   = (int) $line['id'];
                                        $locked   = $line['counted_qty'] !== null;     // đã có người lưu số đếm
                                        // Dòng chính mình nhập: vẫn sửa được khi đợt còn mở; dòng người khác nhập thì khóa với mình
                                        $own      = $canEnter && $locked && (int) $line['counted_by'] === (int) $user['id'];
                                        $editable = $canEnter && (!$locked || $own);
                                        // Người khác đang gõ dòng này (giữ dòng còn hạn) -> mình không nhập được
                                        $claimed  = $isOpen && !$locked && $line['claimer_id'] !== null && (int) $line['claimer_id'] !== (int) $user['id'];
                                        $savedQty = $own ? plainQty($line['counted_qty']) : '';
                                        $savedWhy = $own ? (string) $line['reason'] : '';
                                        $counted  = $oldLines[$lineId]['counted_qty'] ?? $savedQty;
                                        $reason   = $oldLines[$lineId]['reason'] ?? $savedWhy;
                                        $countedAt = $line['counted_at'] !== null ? date('H:i d/m', strtotime($line['counted_at'])) : '';
                                        ?>
                                        <tr class="js-count-row<?= $isOpen && $locked && !$own ? ' row-locked' : '' ?><?= $own ? ' row-saved' : '' ?><?= $claimed ? ' row-claimed' : '' ?>" data-line="<?= $lineId ?>" data-system="<?= e($line['system_qty']) ?>"
                                            <?php if ($own): ?>data-saved-qty="<?= e($savedQty) ?>" data-saved-reason="<?= e($savedWhy) ?>" data-saved-by="<?= e($line['counted_by_name']) ?>" data-saved-at="<?= e($countedAt) ?>"<?php endif; ?>>
                                            <td><?= $n + 1 ?></td>
                                            <td><?= e($line['sku'] . ' - ' . $line['item_name']) ?></td>
                                            <td><?= e($line['batch_no']) ?><br><span class="muted"><?= $line['expiry_date'] ? formatDate($line['expiry_date']) : '—' ?></span></td>
                                            <td class="text-right"><?= formatQty($line['system_qty']) ?> <span class="muted"><?= e($line['unit']) ?></span></td>
                                            <td>
                                                <?php if ($editable): ?>
                                                    <input class="form-control js-counted" type="number" min="0" step="any" <?= $claimed ? 'disabled' : '' ?>
                                                           name="lines[<?= $lineId ?>][counted_qty]" value="<?= e($counted) ?>" placeholder="<?= $claimed ? 'Người khác đang nhập' : 'Chưa đếm' ?>">
                                                <?php else: ?>
                                                    <?= $isOpen && $locked ? '<i class="fas fa-lock muted" title="Đã khóa"></i> ' : '' ?><?= $locked ? formatQty($line['counted_qty']) : '—' ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right js-diff"><?= $line['counted_qty'] !== null ? diffLabel($line['difference']) : '<span class="muted">—</span>' ?></td>
                                            <td>
                                                <?php if ($editable): ?>
                                                    <input class="form-control js-reason" name="lines[<?= $lineId ?>][reason]" maxlength="255" <?= $claimed ? 'disabled' : '' ?>
                                                           value="<?= e($reason) ?>" placeholder="Bắt buộc khi có chênh lệch">
                                                <?php else: ?>
                                                    <?= $line['reason'] !== null ? e($line['reason']) : '<span class="muted">—</span>' ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="js-counter">
                                                <?php if ($claimed): ?>
                                                    <span class="count-by count-claimed"><i class="fas fa-pen"></i> <strong><?= e($line['claimer_name']) ?></strong> đang nhập…</span>
                                                <?php elseif ($own): ?>
                                                    <span class="count-by count-own"><i class="fas fa-check-circle"></i> <strong><?= e($line['counted_by_name']) ?></strong></span>
                                                    <span class="muted"><?= e($countedAt) ?> · sửa được</span>
                                                <?php elseif ($editable): ?>
                                                    <span class="muted">—</span>
                                                <?php elseif ($line['counted_by_name']): ?>
                                                    <span class="count-by"><?= $isOpen ? '<i class="fas fa-lock"></i> ' : '' ?><strong><?= e($line['counted_by_name']) ?></strong></span>
                                                    <span class="muted"><?= e($countedAt) ?></span>
                                                    <?php if ($canDecide && $locked): ?>
                                                        <button class="icon-btn" type="submit" form="unlockForm" name="line_id" value="<?= $lineId ?>" title="Mở khóa để đếm lại"
                                                                data-confirm-title="Mở khóa dòng" data-confirm-ok="Mở khóa"
                                                                data-confirm="Mở khóa dòng <?= e($line['sku'] . ' - lô ' . $line['batch_no']) ?>? Số đếm của <?= e($line['counted_by_name']) ?> bị xóa để đếm lại."><i class="fas fa-lock-open icon-edit"></i></button>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($isOpen): ?>
                        <div class="form-actions">
                            <span class="muted" id="dirtyHint"></span>
                        </div>
                    <?php endif; ?>
                </form>

                <?php if ($canDecide): ?>
                    <!-- Mở khóa 1 dòng đã đếm (nút trong bảng gắn vào bằng form="unlockForm") -->
                    <form id="unlockForm" method="post" action="../handle/stock_count_process.php">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="unlock_line">
                        <input type="hidden" name="id" value="<?= (int) $count['id'] ?>">
                    </form>

                    <!-- ĐỔI NHÂN VIÊN KIỂM KÊ (quản lý): thêm người đếm giúp / bỏ người vắng; dòng đã đếm giữ nguyên -->
                    <form class="form-card" method="post" action="../handle/stock_count_process.php" style="margin-top: 20px;">
                        <h3><i class="fas fa-user-check"></i> Nhân viên kiểm kê</h3>
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="members">
                        <input type="hidden" name="id" value="<?= (int) $count['id'] ?>">
                        <?php $memberIds = array_map('intval', array_column($members, 'id')); ?>
                        <div class="check-grid js-member-grid">
                            <?php foreach ($candidates as $candidate): ?>
                                <label class="check-chip">
                                    <input type="checkbox" name="members[]" value="<?= (int) $candidate['id'] ?>" <?= in_array((int) $candidate['id'], $memberIds, true) ? 'checked' : '' ?>>
                                    <span><?= e($candidate['full_name']) ?> <span class="muted">· <?= e(roleLabel($candidate['role'])) ?></span></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-actions" style="margin-top: 12px;">
                            <span class="muted">Bỏ ai ra thì các dòng người đó đã đếm vẫn giữ nguyên.</span>
                            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Cập nhật danh sách</button>
                        </div>
                    </form>

                    <!-- DUYỆT / HỦY (quản lý) -->
                    <div class="form-card" style="margin-top: 20px;">
                        <h3><i class="fas fa-gavel"></i> Chốt đợt kiểm kê</h3>
                        <p class="form-hint">
                            Khi duyệt, hệ thống sinh <strong>một phiếu điều chỉnh</strong> cho các dòng lệch (thực tế nhiều hơn → nhập thêm, ít hơn → xuất bớt),
                            ghi sổ phát sinh và cập nhật tồn trong cùng một giao dịch, rồi mở khóa kho.
                            Yêu cầu: đã đếm hết các dòng và dòng lệch nào cũng có lý do.
                        </p>
                        <div class="decision-row">
                            <form method="post" action="../handle/stock_count_process.php" class="approve-form js-decision" data-lock=".decision-row"
                                  data-confirm-title="Duyệt đợt kiểm kê" data-confirm-ok="Duyệt" data-confirm-variant="import"
                                  data-confirm="Duyệt đợt <?= e($count['code']) ?>? Phiếu điều chỉnh sẽ được ghi sổ và không sửa / xóa được.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="id" value="<?= (int) $count['id'] ?>">
                                <input class="form-control" name="reason" maxlength="200" required
                                       value="Điều chỉnh tồn theo số đếm thực tế" placeholder="Lý do điều chỉnh (bắt buộc)">
                                <button class="btn btn-import" type="submit"><i class="fas fa-check"></i> Duyệt &amp; sinh phiếu điều chỉnh</button>
                            </form>
                            <form method="post" action="../handle/stock_count_process.php" class="js-decision" data-lock=".decision-row"
                                  data-confirm-title="Hủy đợt kiểm kê" data-confirm-ok="Hủy đợt"
                                  data-confirm="Hủy đợt <?= e($count['code']) ?>? Số đếm bị bỏ, không ghi phát sinh nào.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= (int) $count['id'] ?>">
                                <button class="btn btn-danger" type="submit"><i class="fas fa-ban"></i> Hủy đợt</button>
                            </form>
                        </div>
                    </div>
                <?php elseif ($isOpen): ?>
                    <p class="form-hint text-right"><i class="fas fa-hourglass-half"></i> Nhập số đếm (tự lưu khi chuyển dòng); quản lý kho sẽ duyệt đợt kiểm kê.</p>
                <?php endif; ?>

                <div class="form-actions">
                    <a class="btn btn-light" href="stock_count_list.php"><i class="fas fa-arrow-left"></i> Danh sách đợt kiểm kê</a>
                </div>
            <?php else: ?>
                <a class="btn btn-light" href="stock_count_list.php" style="display: inline-flex;"><i class="fas fa-arrow-left"></i> Danh sách đợt kiểm kê</a>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
