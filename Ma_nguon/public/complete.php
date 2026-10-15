<?php
require __DIR__ . '/../src/Session.php';
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../src/Draft.php';
require __DIR__ . '/../src/RegistrationRepository.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    if (draftExpired($_SESSION, time(), 1800)) {
        cancelDraft($_SESSION);
        header('Location: /register.php', true, 303);
        exit;
    }
    try {
        $data = $_SESSION['draft']['data'];
        $repo = new RegistrationRepository(db());
        $_SESSION['completed'] = $repo->save($data['event_id'], $data['name'], $data['email']);
        cancelDraft($_SESSION);
        header('Location: /complete.php', true, 303);
        exit;
    } catch (Throwable $ex) {
        error_log('CommunityPass: registration_save_failed');
        http_response_code(503);
        $error = 'Không lưu được. Bản nháp vẫn được giữ để thử lại.';
    }
}
if ($error === '' && !isset($_SESSION['completed'])) {
    header('Location: /register.php', true, 303);
    exit;
}
require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading">
<h1>Kết quả lưu trữ</h1>
</div>
<section class="completion" role="status">
<p class="status-message">
    <?= e($error !== '' ? $error : $_SESSION['completed']) ?>
</p>
<p>Mẫu Lab 05 kiểm tra lưu bền vững và đăng ký trùng; chưa có giới hạn chỗ đồng thời.</p>
<a href="/confirm.php">Quay lại xác nhận</a>
</section>
<?php require __DIR__ . '/../templates/footer.php'; ?>