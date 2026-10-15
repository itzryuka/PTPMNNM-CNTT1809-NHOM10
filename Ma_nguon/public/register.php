<?php
require __DIR__ . '/../src/Session.php';
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../src/Draft.php';
$events = require __DIR__ . '/../data/events.php';
$errors = [];
$data = $_SESSION['draft']['data'] ?? ['event_id' => 1, 'name' => '', 'email' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    $result = validateRegistration($_POST, $events);
    $errors = $result['errors'];
    $data = $result['data'];
    if (!$errors) {
        saveDraft($_SESSION, $data, time());
        header('Location: /confirm.php', true, 303);
        exit;
    }
}
require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading">
<h1>Bước 1. Nhập thông tin</h1>
<p>Điền thông tin để chuyển đến bước xem lại bản nháp.</p>
</div>
<div class="error-summary" role="alert">
<p class="error-messages"><?= e(implode(', ', $errors)) ?></p>
<p>Thông tin chưa hợp lệ. Kiểm tra lại các thông tin đã nhập rồi gửi lại.</p>
</div>
<form class="registration-form" method="post">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <label class="form-field" for="event_id">Sự kiện
<select id="event_id" name="event_id">
            <?php foreach ($events as $event): ?>
                <option value="<?= $event['id'] ?>" <?= (int)$data['event_id'] === $event['id'] ? 'selected' : '' ?>>
                    <?= e($event['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <br>
    <label class="form-field" for="name">Họ tên
<input id="name" name="name" value="<?= e($data['name']) ?>">
    </label>
    <br>
    <label class="form-field" for="email">Email
<input id="email" name="email" value="<?= e($data['email']) ?>">
    </label>
    <br>
    <button>Tiếp tục</button>
</form>
<form class="preferences-form" method="post" action="/preferences.php">
<h2>Tùy chọn hiển thị</h2>
<p>Form này chỉ lưu tùy chọn Cookie, không gửi đăng ký.</p>
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <label class="form-field" for="view">Cách hiển thị
<select id="view" name="view">
            <option value="compact">Gọn</option>
            <option value="full">Đầy đủ</option>
        </select>
    </label>
    <button class="button-secondary">Lưu tùy chọn</button>
</form>
<p class="cookie-status">Tùy chọn Cookie: <?= e(field($_COOKIE, 'view')) ?>
</p>
<?php require __DIR__ . '/../templates/footer.php'; ?>