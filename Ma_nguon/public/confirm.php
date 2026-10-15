<?php
require __DIR__.'/../src/Session.php';
require __DIR__.'/../src/Input.php';
require __DIR__.'/../src/Draft.php';
if (draftExpired($_SESSION, time(), 1800)) {
    cancelDraft($_SESSION);
    header('Location: /register.php', true, 303); exit;
}
$data = $_SESSION['draft']['data'];
require __DIR__.'/../templates/header.php';
?>
<div class="page-heading">
<h1>Bước 2. Xác nhận</h1>
<p>Đối chiếu thông tin trước khi xác nhận.</p>
</div>
<section class="confirmation" aria-label="Thông tin đăng ký">
<dl class="summary-list">
<div>
<dt>Họ tên</dt>
<dd><?= e($data['name']) ?></dd>
</div>
<div>
<dt>Email</dt>
<dd><?= e($data['email']) ?></dd>
</div>
</dl>
<div class="confirmation-actions">
<a href="/register.php">Sửa lại</a>
<form method="post" action="/complete.php">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<input type="hidden" name="event_id" value="<?= (int)$data['event_id'] ?>">
<button>Xác nhận</button>
</form>
<form method="post" action="/cancel.php">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<button class="button-secondary button-cancel">Hủy bản nháp</button>
</form>
</div>
</section>
<?php require __DIR__.'/../templates/footer.php'; ?>
