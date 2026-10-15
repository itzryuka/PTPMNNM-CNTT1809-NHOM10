<?php
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../templates/event-presentation.php';
uiReadOnlyPage();
$demoEvents = require __DIR__ . '/../data/ui-demo-events.php';
$id = $_GET['id'] ?? '';
$isDemo = is_string($id) && isset($demoEvents[$id]);
$event = null;
if ($isDemo) {
    $event = $demoEvents[$id];
} elseif (is_string($id) && ctype_digit($id)) {
    $events = require __DIR__ . '/../data/events.php';
    foreach ($events as $candidate) {
        if ((string)$candidate['id'] === $id) {
            $event = $candidate;
            break;
        }
    }
}
if ($event === null) {
    http_response_code(404);
    require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading page-heading--list"><h1>Không tìm thấy sự kiện</h1>
<p>ID không thuộc danh mục hiện có. Không có sự kiện nào được tự chọn thay thế.</p>
<a class="text-button" href="/">Về danh sách sự kiện</a></div>
<?php
    require __DIR__ . '/../templates/footer.php';
    exit;
}
$content = $isDemo ? $event : uiContent((int)$event['id']);
require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading page-heading--list event-detail">
<h1><?= e($event['title']) ?></h1>
<p class="content-note"><?= $isDemo ? 'Sự kiện hư cấu · chỉ trình diễn UI' : 'Sự kiện của lab · nội dung bổ sung hư cấu' ?></p>
<p><?= e($content['summary']) ?></p>
</div>
<figure class="detail-cover">
<img src="<?= e(uiImage($content['image'])) ?>" alt="<?= e($content['alt']) ?>" width="960" height="540">
<figcaption>Ảnh minh họa tự tạo cho dự án; không phải ảnh sự kiện có thật.</figcaption>
</figure>
<p class="detail-facts"><strong>Thời gian:</strong> <?= e(uiDate($content['start'])) ?> – <?= e(uiDate($content['end'])) ?><br>
<strong>Địa điểm:</strong> <?= e($content['venue']) ?> · <?= e($content['timezone']) ?></p>
<div class="detail-layout">
<div class="detail-content">
<section aria-labelledby="intro-title"><h2 id="intro-title">Giới thiệu</h2><p><?= e($content['intro']) ?></p>
<h3>Dành cho ai?</h3><p><?= e($content['audience']) ?></p></section>
<section aria-labelledby="agenda-title"><h2 id="agenda-title">Chương trình</h2>
<ol class="agenda">
<?php foreach ($content['agenda'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
</ol></section>
<section aria-labelledby="organizer-title"><h2 id="organizer-title">Đơn vị tổ chức</h2><p><?= e($content['organizer']) ?></p>
<h3>Chuẩn bị</h3><p><?= e($content['prepare']) ?></p></section>
<?php if ($content['gallery']): ?>
<section aria-label="Không gian minh họa"><h2>Một góc không gian mẫu</h2><div class="event-gallery">
<?php foreach ($content['gallery'] as $image): ?>
<figure><img src="<?= e(uiImage($image['image'])) ?>" alt="<?= e($image['alt']) ?>" width="960" height="540" loading="lazy"><figcaption><?= e($image['alt']) ?></figcaption></figure>
<?php endforeach; ?>
</div></section>
<?php endif; ?>
<section aria-labelledby="tickets-title"><h2 id="tickets-title">Vé và cách bố trí chỗ</h2>
<?php if ($isDemo): ?>
<p><?= e(['numbered'=>'Hội trường đánh số ghế. Hàng A–B là khu VIP, hàng C–H là khu Tiêu chuẩn.','zones'=>'Chọn khu Tiêu chuẩn hoặc VIP, không đánh số và không giữ chỗ cụ thể.','none'=>'Không có sơ đồ hoặc lựa chọn ghế.','online'=>'Trực tuyến, không có hội trường hoặc sơ đồ ghế.'][$event['seating']]) ?></p>
<div class="ticket-info">
<?php foreach ($event['tickets'] as $ticket): ?>
<section><h3><?= e($ticket['name']) ?></h3><p class="ticket-price"><?= e(uiMoney($ticket['price'])) ?> <span>minh họa</span></p><p><?= e($ticket['zone']) ?></p>
<ul><?php foreach ($ticket['benefits'] as $benefit): ?><li><?= e($benefit) ?></li><?php endforeach; ?></ul></section>
<?php endforeach; ?>
</div>
<?php else: ?>
<p>Lab hiện có chỉ xử lý thông tin đăng ký trong biểu mẫu. Chưa có hạng vé, ghế, thanh toán hoặc giữ chỗ. Các phần này không được thêm vào dữ liệu gửi.</p>
<?php endif; ?>
</section>
<section aria-labelledby="policy-title"><h2 id="policy-title">Quy định</h2><ul><?php foreach ($content['policy'] as $rule): ?><li><?= e($rule) ?></li><?php endforeach; ?></ul></section>
<section aria-labelledby="faq-title"><h2 id="faq-title">Câu hỏi thường gặp</h2>
<?php foreach ($content['faq'] as [$question, $answer]): ?><details class="faq"><summary><?= e($question) ?></summary><p><?= e($answer) ?></p></details><?php endforeach; ?>
</section>
</div>
<aside class="event-summary" aria-labelledby="summary-title">
<h2 id="summary-title">Thông tin tham dự</h2>
<dl class="summary-list">
<div><dt>Bắt đầu</dt><dd><time datetime="<?= e($content['start']) ?>"><?= e(uiDate($content['start'])) ?></time></dd></div>
<div><dt>Kết thúc</dt><dd><time datetime="<?= e($content['end']) ?>"><?= e(uiDate($content['end'])) ?></time></dd></div>
<div><dt>Múi giờ</dt><dd><?= e($content['timezone']) ?></dd></div>
<div><dt>Địa điểm</dt><dd><?= e($content['venue']) ?></dd></div>
<div><dt>Hình thức</dt><dd><?= e($content['format']) ?></dd></div>
</dl>
<?php if ($isDemo): ?>
<p class="event-status status-<?= e($event['status']) ?>"><?= e($event['statusLabel']) ?></p>
<a class="filled-button" href="/ticket-preview.php?id=<?= e($event['id']) ?>">Thử giao diện chọn vé</a>
<p class="field-help">Chỉ lựa chọn mẫu, không tạo giao dịch hoặc đăng ký thật.</p>
<?php else: ?>
<a class="filled-button" href="/register.php">Mở biểu mẫu đăng ký</a>
<p class="field-help">Chọn sự kiện trong biểu mẫu. Liên kết này không tự giữ lựa chọn hoặc gửi thêm tham số.</p>
<?php endif; ?>
</aside>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
