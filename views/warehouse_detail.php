<?php
// Chi tiết kho: thông tin, hoạt động đang mở, hàng đang có trong kho theo lô
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/warehouse_functions.php';

$user        = requireLogin('../');
$warehouseId = (int) ($_GET['id'] ?? 0);

$warehouse  = null;
$stock      = [];
$activity   = ['pending' => 0, 'open_count' => null];
$modalState = takeModalState('warehouse_modal');   // lần sửa trước bị lỗi -> mở lại popup kèm lỗi
$dbError    = '';
try {
    $pdo = getDbConnection();
    if (!canAccessWarehouse($user, $warehouseId)) {
        http_response_code(403);
        $dbError = 'Không tìm thấy kho, hoặc bạn không có quyền xem kho này.';
    } else {
        $warehouse = getWarehouse($pdo, $warehouseId);
        $stock     = getWarehouseStock($pdo, $warehouseId);
        $activity  = getWarehouseActivity($pdo, $warehouseId);
    }
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}
$itemCount = count(array_unique(array_column($stock, 'item_id')));
$canManage = isManager($user);

$pageTitle  = $warehouse ? $warehouse['name'] : 'Kho';
$basePath   = '../';
$activeMenu = 'warehouses';
require __DIR__ . '/layout/header.php';
?>

            <?php if ($warehouse): ?>
                <?php if ($activity['open_count']): ?>
                    <div class="alert-warning">
                        <i class="fas fa-snowflake"></i> Kho đang kiểm kê (đợt
                        <a class="link-strong" href="stock_count_detail.php?id=<?= (int) $activity['open_count']['id'] ?>"><?= e($activity['open_count']['code']) ?></a>):
                        phiếu nhập / xuất / điều chuyển tạm thời không duyệt được.
                    </div>
                <?php endif; ?>

                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-warehouse"></i> <?= e($warehouse['code'] . ' - ' . $warehouse['name']) ?></h3>
                        <span class="badge <?= $warehouse['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $warehouse['is_active'] ? 'Hoạt động' : 'Ngừng hoạt động' ?></span>
                    </div>
                    <dl class="detail-grid">
                        <div class="span-2"><dt>Địa chỉ</dt><dd><?= $warehouse['address'] !== null ? e($warehouse['address']) : '—' ?></dd></div>
                        <div><dt>Mặt hàng / lô đang có</dt><dd><?= $itemCount ?> mặt hàng · <?= count($stock) ?> lô</dd></div>
                        <div><dt>Phiếu chờ duyệt</dt><dd><?= $activity['pending'] ?></dd></div>
                    </dl>
                </div>

                <div class="panel" style="margin-top: 20px;">
                    <div class="panel-header"><h3><i class="fas fa-boxes"></i> Hàng đang có trong kho</h3></div>
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table class="nowrap-table">
                            <thead>
                                <tr>
                                    <th>Mã hàng</th>
                                    <th>Tên mặt hàng</th>
                                    <th>Lô</th>
                                    <th>Hạn SD</th>
                                    <th class="text-right">Tồn</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stock)): ?>
                                    <tr class="empty-row"><td colspan="5">Kho đang trống.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($stock as $row): ?>
                                    <tr>
                                        <td><?= e($row['sku']) ?></td>
                                        <td><a class="link-strong" href="item_detail.php?id=<?= (int) $row['item_id'] ?>"><?= e($row['item_name']) ?></a></td>
                                        <td><?= e($row['batch_no']) ?></td>
                                        <td>
                                            <?= $row['expiry_date'] ? formatDate($row['expiry_date']) : '—' ?>
                                            <?php if ($row['expiry_date'] && $row['expiry_date'] < date('Y-m-d')): ?> <span class="badge badge-expired">Đã hết hạn</span><?php endif; ?>
                                        </td>
                                        <td class="text-right"><strong><?= formatQty($row['quantity']) ?></strong> <span class="muted"><?= e($row['unit']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="form-actions">
                    <a class="btn btn-light" href="warehouse_list.php"><i class="fas fa-arrow-left"></i> Danh sách kho</a>
                    <?php if ($canManage): ?>
                        <form method="post" action="../handle/warehouse_process.php">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $warehouse['id'] ?>">
                            <input type="hidden" name="return_to" value="detail">
                            <?php if ($warehouse['is_active']): ?>
                                <button class="btn btn-danger" type="submit" name="action" value="delete" data-confirm-ok="Xóa"
                                        data-confirm="Xóa <?= e($warehouse['name']) ?>? Nếu kho đã có lịch sử chứng từ, kho sẽ được chuyển sang ngừng hoạt động."><i class="fas fa-trash-alt"></i> Xóa</button>
                            <?php else: ?>
                                <button class="btn btn-import" type="submit" name="action" value="restore"><i class="fas fa-undo"></i> Hoạt động lại</button>
                            <?php endif; ?>
                        </form>
                        <?php $fill = ['action' => 'update', 'id' => (int) $warehouse['id'], 'code' => $warehouse['code'], 'name' => $warehouse['name'], 'address' => $warehouse['address'] ?? '']; ?>
                        <button class="btn btn-primary" type="button" data-modal-open="warehouseModal" data-modal-title="Sửa <?= e($warehouse['name']) ?>"
                                data-fill="<?= e(json_encode($fill, JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-edit"></i> Sửa</button>
                    <?php endif; ?>
                </div>
                <?php if ($canManage) { $modalReturnTo = 'detail'; require __DIR__ . '/partials/warehouse_modal.php'; } ?>
            <?php else: ?>
                <a class="btn btn-light" href="warehouse_list.php" style="display: inline-flex;"><i class="fas fa-arrow-left"></i> Danh sách kho</a>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
