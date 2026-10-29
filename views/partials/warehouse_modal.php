<?php
/**
 * Popup thêm / sửa kho (dùng ở trang danh sách và trang chi tiết).
 * Trang gọi cần có:
 *   $modalReturnTo - 'list' | 'detail': lưu xong quay về đâu
 *   $modalState    - takeModalState('warehouse_modal'): khác null khi lần lưu trước bị lỗi -> mở sẵn popup kèm lỗi
 * Nút mở: data-modal-open="warehouseModal" data-fill='{"action":"create"}' hoặc '{"action":"update","id":..,"code":..}'
 */
$modalValues = $modalState['values'] ?? [];
$modalIsEdit = ($modalValues['action'] ?? '') === 'update';
?>
<div class="modal-backdrop<?= $modalState ? ' open' : '' ?>" id="warehouseModal">
    <form class="modal" method="post" action="../handle/warehouse_process.php" role="dialog" aria-modal="true" aria-labelledby="warehouseModalTitle">
        <div class="modal-header">
            <h3 class="modal-title" id="warehouseModalTitle"><?= $modalIsEdit ? 'Sửa kho' : 'Thêm kho' ?></h3>
            <button class="modal-close" type="button" data-modal-close title="Đóng">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($modalState): ?>
                <div class="alert-error js-modal-error"><i class="fas fa-exclamation-triangle"></i> <?= e($modalState['error']) ?></div>
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="return_to" value="<?= e($modalReturnTo) ?>">
            <input type="hidden" name="return_query" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
            <input type="hidden" name="action" data-field value="<?= e($modalValues['action'] ?? 'create') ?>">
            <input type="hidden" name="id" data-field value="<?= e($modalValues['id'] ?? '') ?>">

            <div class="form-grid">
                <div class="form-group">
                    <label for="warehouseCode">Mã kho *</label>
                    <input class="form-control" id="warehouseCode" name="code" data-field data-check="warehouses:code" value="<?= e($modalValues['code'] ?? '') ?>" required maxlength="20"
                           pattern="[A-Za-z0-9_\-]+" title="Chữ không dấu, số và _ -" placeholder="Vd: KHO-CT" style="text-transform: uppercase;">
                    <small class="field-check" hidden></small>
                </div>
                <div class="form-group">
                    <label for="warehouseName">Tên kho *</label>
                    <input class="form-control" id="warehouseName" name="name" data-field data-check="warehouses:name" value="<?= e($modalValues['name'] ?? '') ?>" required maxlength="100" placeholder="Vd: Kho Cần Thơ">
                    <small class="field-check" hidden></small>
                </div>
                <div class="form-group span-all">
                    <label for="warehouseAddress">Địa chỉ</label>
                    <input class="form-control" id="warehouseAddress" name="address" data-field value="<?= e($modalValues['address'] ?? '') ?>" maxlength="255">
                </div>
            </div>
            <p class="form-hint js-create-hint" style="margin: 12px 0 0;">Kho mới được phân quyền ngay cho bạn và các tài khoản quản trị.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" type="button" data-modal-close>Hủy</button>
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Lưu</button>
        </div>
    </form>
</div>
