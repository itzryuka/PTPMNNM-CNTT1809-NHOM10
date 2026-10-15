<?php
require __DIR__ . '/../src/Input.php';
$events = require __DIR__ . '/../data/events.php';
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = validateRegistration($_POST, $events);
}
require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading">
<h1>Lập phiếu điều chuyển hàng</h1>
<p>Chọn mặt hàng, nhập người lập phiếu để kiểm tra và xem trước. Chưa lưu phiếu, chưa trừ kho.</p>
</div>
<form class="registration-form" method="post">
    <label class="form-field" for="event_id">Mặt hàng (tại kho xuất)
<select id="event_id" name="event_id">
            <?php foreach ($events as $event): ?>
                <option value="<?= $event['id'] ?>">
                    <?= e($event['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="form-field" for="name">Người lập phiếu
<input id="name" name="name" maxlength="120"
               value="<?= e(field($_POST, 'name')) ?>">
    </label>
    <label class="form-field" for="email">Email người lập
<input id="email" name="email"
               value="<?= e(field($_POST, 'email')) ?>">
    </label>
    <button>Kiểm tra</button>
</form>
<?php if ($result !== null): ?>
    <?php if ($result['errors']): ?>
        <div class="error-summary" role="alert">
<p class="error-messages"><?= e(implode(', ', $result['errors'])) ?></p>
<p>Thông tin chưa hợp lệ. Kiểm tra lại các thông tin đã nhập rồi gửi lại.</p>
</div>
    <?php else: ?>
        <section class="preview" role="status">
<h2>Xem trước phiếu điều chuyển</h2>
<p class="status-message">PREVIEW_OK: <?= e($result['data']['name']) ?>
            — <?= e($result['data']['email']) ?></p>
        <p>Chỉ xem trước; chưa lưu Session hoặc CSDL, chưa trừ tồn kho.</p>
</section>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
