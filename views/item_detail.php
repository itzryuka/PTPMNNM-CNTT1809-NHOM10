<?php
// Chi tiết mặt hàng: thông tin + tồn theo kho / lô (chỉ các kho user được xem)
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/item_functions.php';

$user   = requireLogin('../');
$itemId = (int) ($_GET['id'] ?? 0);

$item       = null;
$stock      = [];
$units      = [];
$modalState = takeModalState('item_modal');   // lần sửa trước bị lỗi -> mở lại popup kèm lỗi
$dbError    = '';
try {
    $pdo   = getDbConnection();
    $units = getUnitSuggestions($pdo);
    $item  = getItem($pdo, $itemId);
    if ($item === null) {
        http_response_code(404);
        $dbError = 'Không tìm thấy mặt hàng.';
    } else {
        $stock = getItemStock($pdo, $itemId, $user['warehouses']);
    }
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}
$total     = array_sum(array_map(fn ($row) => (float) $row['quantity'], $stock));
$canManage = isManager($user);

$pageTitle  = $item ? 'Mặt hàng ' . $item['sku'] : 'Mặt hàng';
$basePath   = '../';
$activeMenu = 'items';
require __DIR__ . '/layout/header.php';
?>

            <?php if ($item): ?>
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-box"></i> <?= e($item['sku'] . ' - ' . $item['name']) ?></h3>
                        <span class="badge <?= $item['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $item['is_active'] ? 'Đang dùng' : 'Ngừng dùng' ?></span>
                    </div>
                    <dl class="detail-grid">
                        <div><dt>Mã hàng</dt><dd><?= e($item['sku']) ?></dd></div>
                        <div><dt>Đơn vị tính</dt><dd><?= e($item['unit']) ?></dd></div>
                        <div><dt>Tổng tồn (các kho bạn được xem)</dt><dd><?= formatQty($total) ?> <?= e($item['unit']) ?></dd></div>
                        <div><dt>Ngày tạo</dt><dd><?= e(date('d/m/Y', strtotime($item['created_at']))) ?></dd></div>
                        <div class="span-2"><dt>Mô tả</dt><dd><?= $item['description'] !== null ? e($item['description']) : '—' ?></dd></div>
                    </dl>
                </div>

                <div class="panel" style="margin-top: 20px;">
                    <div class="panel-header"><h3><i class="fas fa-warehouse"></i> Tồn theo kho và lô</h3></div>
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table class="nowrap-table">
                            <thead>
                                <tr>
                                    <th>Kho</th>
                                    <th>Lô</th>
                                    <th>Ngày SX</th>
                                    <th>Hạn SD</th>
                                    <th class="text-right">Tồn</th>
                                    <th>Cập nhật lần cuối</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stock)): ?>
                                    <tr class="empty-row"><td colspan="6">Không còn tồn ở kho nào bạn được xem.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($stock as $row): ?>
                                    <tr>
                                        <td><?= e($row['warehouse_name']) ?></td>
                                        <td><?= e($row['batch_no']) ?></td>
                                        <td><?= $row['mfg_date'] ? formatDate($row['mfg_date']) : '—' ?></td>
                                        <td>
                                            <?= $row['expiry_date'] ? formatDate($row['expiry_date']) : '—' ?>
                                            <?php if ($row['expiry_date'] && $row['expiry_date'] < date('Y-m-d')): ?> <span class="badge badge-expired">Đã hết hạn</span><?php endif; ?>
                                        </td>
                                        <td class="text-right"><strong><?= formatQty($row['quantity']) ?></strong> <span class="muted"><?= e($item['unit']) ?></span></td>
                                        <td><?= e(date('d/m/Y H:i', strtotime($row['updated_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="form-actions">
                    <a class="btn btn-light" href="item_list.php"><i class="fas fa-arrow-left"></i> Danh sách mặt hàng</a>
                    <?php if ($canManage): ?>
                        <form method="post" action="../handle/item_process.php">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <input type="hidden" name="return_to" value="detail">
                            <?php if ($item['is_active']): ?>
                                <button class="btn btn-danger" type="submit" name="action" value="delete" data-confirm-ok="Xóa"
                                        data-confirm="Xóa mặt hàng <?= e($item['sku'] . ' - ' . $item['name']) ?>? Nếu đã có lịch sử nhập / xuất, mặt hàng sẽ được chuyển sang ngừng dùng."><i class="fas fa-trash-alt"></i> Xóa</button>
                            <?php else: ?>
                                <button class="btn btn-import" type="submit" name="action" value="restore"><i class="fas fa-undo"></i> Dùng lại</button>
                            <?php endif; ?>
                        </form>
                        <?php $fill = ['action' => 'update', 'id' => (int) $item['id'], 'sku' => $item['sku'], 'name' => $item['name'], 'unit' => $item['unit'], 'description' => $item['description'] ?? '']; ?>
                        <button class="btn btn-primary" type="button" data-modal-open="itemModal" data-modal-title="Sửa mặt hàng <?= e($item['sku']) ?>"
                                data-fill="<?= e(json_encode($fill, JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-edit"></i> Sửa</button>
                    <?php endif; ?>
                </div>
                <?php if ($canManage) { $modalReturnTo = 'detail'; require __DIR__ . '/partials/item_modal.php'; } ?>
            <?php else: ?>
                <a class="btn btn-light" href="item_list.php" style="display: inline-flex;"><i class="fas fa-arrow-left"></i> Danh sách mặt hàng</a>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
