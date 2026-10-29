<?php
// Đổi mật khẩu của chính mình. Tài khoản còn mật khẩu tạm (vừa được cấp / đặt lại) bị chuyển tới đây ở mọi trang.
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';

$user   = requireLogin('../', true);
$forced = (bool) $user['must_change_password'];

$pageTitle  = 'Đổi mật khẩu';
$basePath   = '../';
$activeMenu = '';
require __DIR__ . '/layout/header.php';
?>

            <form class="form-card password-card" method="post" action="../handle/account_process.php" data-lock>
                <h3><i class="fas fa-key"></i> <?= $forced ? 'Đặt mật khẩu của bạn' : 'Đổi mật khẩu' ?></h3>
                <?php if ($forced): ?>
                    <div class="alert-warning" style="margin-bottom: 16px;"><i class="fas fa-info-circle"></i>
                        Bạn đang dùng mật khẩu tạm do quản trị cấp. Hãy đặt mật khẩu riêng để tiếp tục dùng hệ thống.</div>
                <?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label for="current"><?= $forced ? 'Mật khẩu tạm' : 'Mật khẩu hiện tại' ?></label>
                    <input class="form-control" type="password" id="current" name="current" required autocomplete="current-password" autofocus>
                </div>
                <div class="form-group">
                    <label for="new">Mật khẩu mới</label>
                    <input class="form-control" type="password" id="new" name="new" required minlength="8" autocomplete="new-password">
                    <small class="form-hint">Ít nhất 8 ký tự, gồm cả chữ và số.</small>
                </div>
                <div class="form-group">
                    <label for="confirm">Nhập lại mật khẩu mới</label>
                    <input class="form-control" type="password" id="confirm" name="confirm" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-actions" style="margin-top: 8px;">
                    <?php if (!$forced): ?><a class="btn btn-light" href="../index.php">Hủy</a><?php endif; ?>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Lưu mật khẩu</button>
                </div>
            </form>

<?php require __DIR__ . '/layout/footer.php'; ?>
