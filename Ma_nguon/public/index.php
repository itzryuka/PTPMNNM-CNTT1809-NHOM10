<?php
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../src/EventFilter.php';
$events = require __DIR__ . '/../data/events.php';
$query = field($_GET, 'q');
$visible = filterEvents($events, $query);
require_once __DIR__ . '/../templates/event-presentation.php';
require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading page-heading--list page-search">
<h1>Sự kiện</h1>
<p>Tìm theo tên và xem thông tin sự kiện.</p>
</div>
<form class="search-form" method="get">
    <label class="form-field" for="q">Từ khóa
<input id="q" name="q" value="<?= e($query) ?>">
    </label>
    <button>Tìm</button>
</form>
<p class="result-count">Kết quả: <?= count($visible) ?></p>
<section class="event-list" aria-label="Danh sách sự kiện">
<?php foreach ($visible as $event): ?>
    <article class="event-item event-card">
<?php $content = uiContent((int)$event['id']); ?>
<figure class="event-cover">
<img src="<?= e(uiImage($content['image'])) ?>" alt="<?= e($content['alt']) ?>" width="960" height="540">
<figcaption>Minh họa</figcaption>
</figure>
<div class="event-card-content">
        <h2 class="event-title"><?= e($event['title']) ?></h2>
<p class="event-date"><?= e(uiDate($content['start'])) ?></p>
<p class="event-venue"><?= e($content['venue']) ?></p>
<p><?= e($content['summary']) ?></p>
<p class="event-meta">
        Loại: <?= e($event['category']) ?>. 
        Còn <?= max(0, $event['capacity'] - $event['registered']) ?>
        chỗ (dữ liệu mẫu).
    </p>
<a class="text-button" href="/event.php?id=<?= (int)$event['id'] ?>">Xem chi tiết</a>
</div>
</article>
<?php endforeach; ?>
</section>
<?php if (!$visible): ?>
    <p class="empty-state">Không có kết quả.<span>Thử tìm với một từ khóa khác.</span>
</p>
<?php endif; ?>
<p class="content-note">Thời gian, địa điểm và ảnh bổ sung là nội dung hư cấu phục vụ học tập. Dữ liệu đăng ký vẫn lấy từ nguồn gốc của lab.</p>
<section class="demo-entry"><h2>Sáu bối cảnh, một khu thử riêng</h2>
<p>Hội thảo, workshop, âm nhạc, nghề nghiệp, online và triển lãm. Thử vé và ghế mà không đưa lựa chọn vào đăng ký của lab.</p>
<a class="text-button" href="/ui-demo.php">Khám phá sự kiện mẫu</a></section>
<?php require __DIR__ . '/../templates/footer.php'; ?>
