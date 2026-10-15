<?php
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../src/EventFilter.php';
$events = require __DIR__ . '/../data/events.php';
$query = field($_GET, 'q');
$category = field($_GET, 'category');
$availableOnly = field($_GET, 'available') === '1';
$visible = filterEvents($events, $query, $category, $availableOnly);
$categories = ['dien-tu' => 'Điện tử', 'noi-that' => 'Nội thất'];
require_once __DIR__ . '/../templates/event-presentation.php';
require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading page-heading--list page-search">
<h1>Tồn kho tại các điểm</h1>
<p>Tìm mặt hàng theo tên hàng hoặc tên kho, lọc theo loại và tình trạng còn hàng.</p>
</div>
<form class="search-form" method="get">
    <label class="form-field" for="q">Từ khóa (tên hàng, tên kho)
<input id="q" name="q" value="<?= e($query) ?>">
    </label>
    <label class="form-field" for="category">Loại hàng
<select id="category" name="category">
<option value="">Tất cả</option>
<?php foreach ($categories as $value => $label): ?>
<option value="<?= e($value) ?>" <?= $category === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
</select>
    </label>
    <label class="filter-checkbox" for="available">
<input type="checkbox" id="available" name="available" value="1" <?= $availableOnly ? 'checked' : '' ?>>
Chỉ hàng còn có thể điều chuyển
    </label>
    <button>Tìm</button>
</form>
<p class="result-count">Kết quả: <?= count($visible) ?></p>
<section class="event-list" aria-label="Danh sách mặt hàng tồn kho">
<?php foreach ($visible as $event): ?>
    <article class="event-item event-card">
<?php $content = uiContent((int)$event['id']); ?>
<figure class="event-cover">
<img src="<?= e(uiImage($content['image'])) ?>" alt="<?= e($content['alt']) ?>" width="960" height="540">
<figcaption>Minh họa</figcaption>
</figure>
<div class="event-card-content">
        <h2 class="event-title"><?= e($event['title']) ?></h2>
<p class="event-date">Kiểm kê gần nhất: <?= e(uiDate($content['start'])) ?></p>
<p class="event-venue"><?= e($content['venue']) ?></p>
<p><?= e($content['summary']) ?></p>
<p class="event-meta">
        Loại: <?= e($categories[$event['category']] ?? $event['category']) ?>.
        Tồn <?= (int)$event['capacity'] ?>, đã giữ <?= (int)$event['registered'] ?>,
        còn <?= max(0, $event['capacity'] - $event['registered']) ?> có thể điều chuyển (dữ liệu mẫu).
    </p>
<a class="text-button" href="/event.php?id=<?= (int)$event['id'] ?>">Xem chi tiết</a>
</div>
</article>
<?php endforeach; ?>
</section>
<?php if (!$visible): ?>
    <p class="empty-state">Không có mặt hàng phù hợp.<span>Thử từ khóa khác hoặc bỏ bớt điều kiện lọc.</span>
</p>
<?php endif; ?>
<p class="content-note">Tên hàng, điểm kho và số liệu là dữ liệu giả lập phục vụ học tập.</p>
<?php require __DIR__ . '/../templates/footer.php'; ?>
