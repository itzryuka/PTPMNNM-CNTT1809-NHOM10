<?php
/**
 * Popup cấp / sửa tài khoản (trang Quản lý tài khoản). Trang gọi cần có:
 *   $warehouseOptions - kho giao được (assignableWarehouses)
 *   $modalState       - takeModalState('account_modal'): khác null khi lần lưu trước bị lỗi -> mở sẵn popup kèm lỗi
 * Nút mở: data-modal-open="accountModal" data-fill='{"action":"create"}' hoặc '{"action":"update","id":..,"warehouses":[1,3]}'
 */
$modalValues = $modalState['values'] ?? [];
$modalIsEdit = ($modalValues['action'] ?? '') === 'update';
$checkedWh   = array_map('intval', is_array($modalValues['warehouses'] ?? null) ? $modalValues['warehouses'] : []);
?>
<div class="modal-backdrop<?= $modalState ? ' open' : '' ?>" id="accountModal">
    <form class="modal" method="post" action="../handle/account_process.php" role="dialog" aria-modal="true" aria-labelledby="accountModalTitle">
        <div class="modal-header">
            <h3 class="modal-title" id="accountModalTitle"><?= $modalIsEdit ? 'Sửa tài khoản' : 'Cấp tài khoản mới' ?></h3>
            <button class="modal-close" type="button" data-modal-close title="Đóng">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($modalState): ?>
                <div class="alert-error js-modal-error"><i class="fas fa-exclamation-triangle"></i> <?= e($modalState['error']) ?></div>
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="return_query" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
            <input type="hidden" name="action" data-field value="<?= e($modalValues['action'] ?? 'create') ?>">
            <input type="hidden" name="id" data-field value="<?= e($modalValues['id'] ?? '') ?>">

            <div class="form-grid">
                <div class="form-group">
                    <label for="accountUsername">Tên đăng nhập *</label>
                    <input class="form-control" id="accountUsername" name="username" data-field data-edit-readonly value="<?= e($modalValues['username'] ?? '') ?>"
                           <?= $modalIsEdit ? 'readonly' : '' ?> required maxlength="30" pattern="[a-zA-Z0-9._]{3,30}" placeholder="Vd: nhanvien_d" autocomplete="off" style="text-transform: lowercase;">
                </div>
                <div class="form-group">
                    <label for="accountRole">Vai trò *</label>
                    <select class="form-control" id="accountRole" name="role" data-field required>
                        <?php foreach (ACCOUNT_ROLES as $roleKey => $roleName): ?>
                            <option value="<?= $roleKey ?>" <?= ($modalValues['role'] ?? 'STAFF') === $roleKey ? 'selected' : '' ?>><?= e($roleName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group span-all">
                    <label for="accountName">Họ tên *</label>
                    <input class="form-control" id="accountName" name="full_name" data-field value="<?= e($modalValues['full_name'] ?? '') ?>" required maxlength="100" placeholder="Vd: Võ Thị D (Kho HN)">
                </div>
                <div class="form-group span-all">
                    <label>Kho phụ trách</label>
                    <div class="check-grid">
                        <?php foreach ($warehouseOptions as $warehouse): ?>
                            <label class="check-chip">
                                <input type="checkbox" name="warehouses[]" value="<?= (int) $warehouse['id'] ?>" data-field <?= in_array((int) $warehouse['id'], $checkedWh, true) ? 'checked' : '' ?>>
                                <span><?= e($warehouse['code']) ?> · <?= e($warehouse['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <p class="form-hint" style="margin: 12px 0 0;">
                <strong>Quản lý kho</strong>: duyệt phiếu, giữ / hủy giữ hàng, kiểm kê, sửa thông tin kho, đồng ý yêu cầu điều chuyển — chỉ ở các kho được chọn.
                <strong>Nhân viên kho</strong>: lập phiếu, nhận / xuất / gửi hàng, nhập số đếm — chỉ ở các kho được chọn.
                Cấp mới: hệ thống sinh mật khẩu tạm, người dùng phải đổi ở lần đăng nhập đầu.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" type="button" data-modal-close>Hủy</button>
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Lưu</button>
        </div>
    </form>
</div>
