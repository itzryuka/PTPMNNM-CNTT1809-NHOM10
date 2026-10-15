<?php
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../templates/event-presentation.php';
uiReadOnlyPage();
$demoEvents = require __DIR__ . '/../data/ui-demo-events.php';
$id = $_GET['id'] ?? '';
if (!is_string($id) || !isset($demoEvents[$id])) {
    http_response_code(404);
    require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading page-heading--list"><h1>Không tìm thấy bản thử</h1><p>Chỉ ID demo trong danh mục được chấp nhận ở đây.</p><a class="text-button" href="/ui-demo.php">Về sự kiện mẫu</a></div>
<?php
    require __DIR__ . '/../templates/footer.php';
    exit;
}
$demo = $demoEvents[$id];
$seats = $demo['seating'] === 'numbered' ? uiSeats() : [];
$available = array_filter($seats, static fn(array $seat): bool => $seat['available']);
$vipAvailable = count(array_filter($available, static fn(array $seat): bool => $seat['tier'] === 'vip'));
$standardAvailable = count($available) - $vipAvailable;
require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading page-heading--list ticket-preview">
<h1>Thử lựa chọn vé và chỗ</h1>
<p><?= e($demo['title']) ?></p>
</div>
<p class="demo-notice" id="demo-boundary">Bản thử giao diện: lựa chọn này chưa giữ ghế, chưa tạo vé và không phát sinh thanh toán.</p>
<noscript><p class="warning-notice">JavaScript đang tắt. Bạn vẫn đọc được các hạng vé và bố trí chỗ mẫu; bật JavaScript để thử lựa chọn. Form nghiệp vụ ở các trang lab vẫn hoạt động độc lập.</p></noscript>
<div class="preview-layout">
<div>
<section class="preview-event" aria-labelledby="choose-event-title">
<h2 id="choose-event-title">Sự kiện mẫu</h2>
<label class="form-field" for="preview-event">Đổi sự kiện để thử trạng thái khác
<select id="preview-event" data-preview-control disabled>
<?php foreach ($demoEvents as $key => $item): ?>
<option value="<?= e($key) ?>" <?= $key === $id ? 'selected' : '' ?>><?= e($item['title']) ?></option>
<?php endforeach; ?>
</select></label>
<p class="field-help">Đổi sự kiện sẽ xóa lựa chọn thử của sự kiện cũ. Tải lại trang cũng đặt lại lựa chọn.</p>
<p class="event-status status-<?= e($demo['status']) ?>"><?= e($demo['statusLabel']) ?></p>
</section>
<section aria-labelledby="ticket-title">
<h2 id="ticket-title">Hạng vé</h2>
<p>Giá cố định bằng VND, chỉ để minh họa. Không có phí hoặc giảm giá bổ sung.</p>
<div class="ticket-options" id="ticket-options">
<?php foreach ($demo['tickets'] as $ticket): ?>
<button type="button" class="ticket-option <?= $ticket['id'] === 'vip' ? 'ticket-vip' : '' ?>" data-ticket="<?= e($ticket['id']) ?>" data-preview-control aria-pressed="false" disabled>
<strong><?= e($ticket['name']) ?></strong>
<span class="ticket-price"><?= e(uiMoney($ticket['price'])) ?></span>
<span><?= e($ticket['zone']) ?></span>
<?php foreach ($ticket['benefits'] as $benefit): ?><span class="ticket-benefit"><?= e($benefit) ?></span><?php endforeach; ?>
<span class="selection-indicator" aria-hidden="true">Chưa chọn</span>
</button>
<?php endforeach; ?>
</div>
<label class="form-field quantity-field" for="preview-quantity">Số lượng mẫu (1–6)
<input id="preview-quantity" type="number" min="1" max="6" step="1" value="1" data-preview-control disabled></label>
</section>
<?php if ($demo['seating'] === 'numbered'): ?>
<section aria-labelledby="seat-title">
<h2 id="seat-title">Sơ đồ hội trường</h2>
<p><?= count($seats) ?> ghế mẫu · <?= count($available) ?> khả dụng: <?= $vipAvailable ?> VIP và <?= $standardAvailable ?> Tiêu chuẩn. Lối đi không phải ghế.</p>
<ul class="seat-legend"><li><span class="legend-open">Trống</span> Có thể chọn theo dữ liệu mẫu</li><li><span class="legend-selected">Chọn</span> Đang chọn thử</li><li><span class="legend-blocked">X</span> Không khả dụng theo dữ liệu mẫu</li></ul>
<button type="button" id="seat-view-toggle" class="button-secondary" data-preview-control disabled aria-controls="seat-list seat-map">Dùng danh sách ghế</button>
<p class="field-help">Trên màn hình nhỏ, chỉ sơ đồ bên dưới được cuộn ngang. Danh sách ghế có cùng lựa chọn và vùng bấm lớn hơn.</p>
<p class="field-help">Ghế thuộc hạng khác được khóa trong lựa chọn hiện tại. Đổi hạng vé để chọn khu tương ứng.</p>
<div id="seat-map">
<div class="seat-map-scroll" tabindex="0" role="group" aria-label="Sơ đồ ghế mẫu, có thể cuộn ngang">
<div class="seat-map">
<div class="stage">SÂN KHẤU · hướng nhìn lên trên</div>
<?php foreach (range('A','H') as $row): ?>
<div class="seat-row"><span class="row-label">Hàng <?= e($row) ?></span>
<?php foreach ($seats as $seat): ?>
<?php if ($seat['row'] !== $row) { continue; } ?>
<button type="button" class="seat <?= $seat['tier'] === 'vip' ? 'seat-vip' : '' ?> <?= !$seat['available'] ? 'seat-unavailable' : '' ?>" data-seat="<?= e($seat['id']) ?>" aria-pressed="false" aria-label="Ghế <?= e($seat['id']) ?>, <?= $seat['tier'] === 'vip' ? 'VIP' : 'Tiêu chuẩn' ?>, <?= $seat['available'] ? 'có thể chọn theo dữ liệu mẫu' : 'không khả dụng theo dữ liệu mẫu' ?>" disabled>
<span><?= e($seat['id']) ?></span><span class="seat-mark" aria-hidden="true"><?= $seat['available'] ? 'Trống' : 'X' ?></span>
</button>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</div></div></div>
<div id="seat-list" class="seat-list" hidden>
<?php foreach (range('A','H') as $row): ?>
<details class="seat-list-row" data-seat-row="<?= e($row) ?>" data-seat-tier="<?= in_array($row,['A','B'],true) ? 'vip' : 'standard' ?>">
<summary>Hàng <?= e($row) ?> · <?= in_array($row,['A','B'],true) ? 'VIP' : 'Tiêu chuẩn' ?></summary>
<div class="seat-list-items">
<?php foreach ($seats as $seat): ?>
<?php if ($seat['row'] !== $row) { continue; } ?>
<label class="seat-list-option <?= !$seat['available'] ? 'seat-unavailable' : '' ?>" for="list-<?= e($seat['id']) ?>">
<input type="checkbox" id="list-<?= e($seat['id']) ?>" data-seat-list="<?= e($seat['id']) ?>" disabled>
<span>Ghế <?= e($seat['id']) ?> · <?= $seat['tier'] === 'vip' ? 'VIP' : 'Tiêu chuẩn' ?><small><?= $seat['available'] ? 'Có thể chọn theo dữ liệu mẫu' : 'Không khả dụng theo dữ liệu mẫu' ?></small></span>
</label>
<?php endforeach; ?>
</div></details>
<?php endforeach; ?>
</div>
</section>
<?php else: ?>
<section class="seating-note"><h2>Cách bố trí chỗ</h2>
<p><?= e(['zones'=>'Theo khu Tiêu chuẩn/VIP, không đánh số ghế. Lựa chọn hạng chỉ là xem thử khu vực.','none'=>'Sự kiện này không có chức năng chọn ghế.','online'=>'Sự kiện online: không có sơ đồ hội trường, số ghế hoặc link họp thật.'][$demo['seating']]) ?></p>
</section>
<?php endif; ?>
</div>
<aside class="preview-summary-panel">
<h2>Lựa chọn đang thử</h2>
<p id="preview-total">Chưa chọn hạng vé</p>
<p id="preview-selection" class="field-help">Chưa chọn ghế.</p>
<p id="preview-feedback" role="status" tabindex="-1"></p>
<div class="preview-actions">
<button type="button" id="preview-show-summary" data-preview-control disabled>Xem tóm tắt mẫu</button>
<button type="button" id="preview-reset" class="button-secondary" data-preview-control disabled>Đặt lại lựa chọn</button>
</div>
<p class="field-help">Không có bước đặt chỗ hoặc thanh toán tiếp theo. Mọi lựa chọn chỉ ở bộ nhớ trang.</p>
<a class="text-button" href="/event.php?id=<?= e($id) ?>">Đọc lại chi tiết sự kiện mẫu</a>
</aside>
</div>
<section id="preview-summary" class="sample-summary" tabindex="-1" hidden aria-labelledby="sample-summary-title">
<h2 id="sample-summary-title">Bản tóm tắt lựa chọn mẫu</h2>
<dl class="summary-list">
<div><dt>Sự kiện</dt><dd id="summary-event"></dd></div>
<div><dt>Hạng</dt><dd id="summary-ticket"></dd></div>
<div><dt>Số lượng</dt><dd id="summary-quantity"></dd></div>
<div><dt>Ghế/khu</dt><dd id="summary-seats"></dd></div>
<div><dt>Tổng mẫu</dt><dd id="summary-total"></dd></div>
</dl>
<p>Chưa giữ ghế, chưa tạo vé và không phát sinh thanh toán. Không có mã đặt chỗ hoặc QR.</p>
</section>
<script type="application/json" id="preview-data"><?= json_encode(['id'=>$demo['id'],'title'=>$demo['title'],'status'=>$demo['status'],'seating'=>$demo['seating'],'tickets'=>$demo['tickets'],'seats'=>$seats], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) ?></script>
<script src="/assets/js/ticket-preview.js" defer></script>
<?php require __DIR__ . '/../templates/footer.php'; ?>
