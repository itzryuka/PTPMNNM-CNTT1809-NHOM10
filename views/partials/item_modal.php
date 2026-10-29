<?php
/**
 * Popup thêm / sửa mặt hàng (dùng ở trang danh sách và trang chi tiết).
 * Trang gọi cần có:
 *   $units         - gợi ý đơn vị tính (getUnitSuggestions)
 *   $modalReturnTo - 'list' | 'detail': lưu xong quay về đâu
 *   $modalState    - takeModalState('item_modal'): khác null khi lần lưu trước bị lỗi -> mở sẵn popup kèm lỗi
 * Nút mở: data-modal-open="itemModal" data-fill='{"action":"create"}' hoặc '{"action":"update","id":..,"sku":..}'
 */
$modalValues = $modalState['values'] ?? [];
$modalIsEdit = ($modalValues['action'] ?? '') === 'update';
?>
<div class="modal-backdrop<?= $modalState ? ' open' : '' ?>" id="itemModal">
    <form class="modal" method="post" action="../handle/item_process.php" role="dialog" aria-modal="true" aria-labelledby="itemModalTitle">
        <div class="modal-header">
            <h3 class="modal-title" id="itemModalTitle"><?= $modalIsEdit ? 'Sửa mặt hàng' : 'Thêm mặt hàng' ?></h3>
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
                    <label for="itemSku">Mã hàng *</label>
                    <input class="form-control" id="itemSku" name="sku" data-field data-check="items:code" value="<?= e($modalValues['sku'] ?? '') ?>" required maxlength="30"
                           pattern="[A-Za-z0-9._\-]+" title="Chữ không dấu, số và . _ -" placeholder="Vd: SP031" style="text-transform: uppercase;">
                    <small class="field-check" hidden></small>
                </div>
                <div class="form-group">
                    <label for="itemUnit">Đơn vị tính *</label>
                    <input class="form-control" id="itemUnit" name="unit" data-field value="<?= e($modalValues['unit'] ?? '') ?>" required maxlength="20" list="itemUnitList" placeholder="Chọn hoặc gõ mới">
                    <datalist id="itemUnitList">
                        <?php foreach ($units as $unit): ?><option value="<?= e($unit) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group span-all">
                    <label for="itemName">Tên mặt hàng *</label>
                    <input class="form-control" id="itemName" name="name" data-field data-check="items:name" value="<?= e($modalValues['name'] ?? '') ?>" required maxlength="150" placeholder="Vd: Nước cam Twister 455ml">
                    <small class="field-check" hidden></small>
                </div>
                <div class="form-group span-all">
                    <label for="itemDescription">Mô tả</label>
                    <input class="form-control" id="itemDescription" name="description" data-field value="<?= e($modalValues['description'] ?? '') ?>" maxlength="255">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" type="button" data-modal-close>Hủy</button>
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Lưu</button>
        </div>
    </form>
</div>
