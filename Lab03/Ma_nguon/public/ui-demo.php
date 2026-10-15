<?php
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../templates/event-presentation.php';
uiReadOnlyPage();
$demoEvents = require __DIR__ . '/../data/ui-demo-events.php';
require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading page-heading--list demo-page">
<h1>Khám phá sự kiện mẫu</h1>
<p>Sáu bối cảnh để xem cách giao diện phản hồi. Tất cả sự kiện, lịch, địa điểm và giá đều hư cấu.</p>
</div>
<p class="demo-notice">Khu trình diễn riêng · Không tạo đăng ký, vé, chỗ giữ hoặc thanh toán. Trạng thái cố định theo mốc demo 14/10/2026.</p>
<section class="event-list demo-grid" aria-label="Sáu sự kiện hư cấu">
<?php foreach ($demoEvents as $demo): ?>
<article class="event-item event-card">
<figure class="event-cover">
<img src="<?= e(uiImage($demo['image'])) ?>" alt="<?= e($demo['alt']) ?>" width="960" height="540">
<figcaption>Minh họa</figcaption>
</figure>
<div class="event-card-content">
<p class="event-type"><?= e($demo['category']) ?> · <?= e($demo['format']) ?></p>
<h2 class="event-title"><?= e($demo['title']) ?></h2>
<p class="event-status status-<?= e($demo['status']) ?>"><?= e($demo['statusLabel']) ?></p>
<p class="event-date"><?= e(uiDate($demo['start'])) ?></p>
<p class="event-venue"><?= e($demo['venue']) ?></p>
<p><?= e($demo['summary']) ?></p>
<a class="text-button" href="/event.php?id=<?= e($demo['id']) ?>">Xem chi tiết</a>
</div>
</article>
<?php endforeach; ?>
</section>
<?php require __DIR__ . '/../templates/footer.php'; ?>
