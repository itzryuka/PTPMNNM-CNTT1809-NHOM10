/* A page-memory-only preview. No storage, cookies, registration calls or payments. */
'use strict';
(() => {
  const payload = document.getElementById('preview-data');
  if (!payload) return;
  const data = JSON.parse(payload.textContent);
  const seats = new Map(data.seats.map(seat => [seat.id, seat]));
  const eventSelect = document.getElementById('preview-event');
  const quantity = document.getElementById('preview-quantity');
  const feedback = document.getElementById('preview-feedback');
  const summary = document.getElementById('preview-summary');
  const money = value => new Intl.NumberFormat('vi-VN', {
    style: 'currency', currency: 'VND', maximumFractionDigits: 0
  }).format(value);
  const canChoose = data.status === 'upcoming';
  let tier = data.tickets[0].id;
  let selected = new Set();

  function message(text) { feedback.textContent = text; }
  function currentTicket() { return data.tickets.find(ticket => ticket.id === tier); }
  function validQuantity() {
    return Number.isInteger(quantity.valueAsNumber) &&
      quantity.valueAsNumber >= 1 && quantity.valueAsNumber <= 6;
  }
  function openFirstSeatRow() {
    document.querySelectorAll('[data-seat-row]').forEach(group => {
      group.open = group.dataset.seatRow === (tier === 'vip' ? 'A' : 'C');
    });
  }
  function render() {
    const ticket = currentTicket();
    const valid = validQuantity();
    const count = valid ? quantity.valueAsNumber : 0;
    quantity.setAttribute('aria-invalid', String(!valid));
    document.querySelectorAll('[data-ticket]').forEach(button => {
      const active = canChoose && button.dataset.ticket === tier;
      button.disabled = !canChoose;
      button.setAttribute('aria-pressed', String(active));
      button.querySelector('.selection-indicator').textContent = active ? 'Đang chọn thử' : 'Chưa chọn';
    });
    document.querySelectorAll('[data-seat]').forEach(button => {
      const seat = seats.get(button.dataset.seat);
      const active = selected.has(seat.id);
      button.disabled = !canChoose || !seat.available || seat.tier !== tier;
      button.setAttribute('aria-pressed', String(active));
      button.querySelector('.seat-mark').textContent = !seat.available ? 'X' : active ? 'Chọn' : 'Trống';
      button.setAttribute('aria-label', 'Ghế ' + seat.id + ', ' +
        (seat.tier === 'vip' ? 'VIP' : 'Tiêu chuẩn') + ', ' +
        (!seat.available ? 'không khả dụng theo dữ liệu mẫu' :
          active ? 'đang chọn thử' : seat.tier !== tier ? 'thuộc hạng vé khác' : 'có thể chọn theo dữ liệu mẫu'));
    });
    document.querySelectorAll('[data-seat-list]').forEach(input => {
      const seat = seats.get(input.dataset.seatList);
      input.checked = selected.has(seat.id);
      input.disabled = !canChoose || !seat.available || seat.tier !== tier;
    });
    document.getElementById('preview-total').textContent = !canChoose ?
      'Không thể chọn: sự kiện mẫu đã hết chỗ hoặc đã hủy.' :
      valid ? ticket.name + ' · ' + count + ' vé mẫu · ' + money(ticket.price * count) + ' minh họa' :
        'Nhập số lượng nguyên từ 1 đến 6.';
    document.getElementById('preview-selection').textContent = data.seating === 'numbered' ?
      selected.size + '/' + count + ' ghế đã chọn thử' +
        (selected.size ? ': ' + [...selected].join(', ') : '.') :
      ticket.zone + ' · không chọn số ghế.';
    document.querySelectorAll('[data-seat-tier]').forEach(group => {
      group.hidden = group.dataset.seatTier !== tier;
    });
    summary.hidden = true;
  }
  function reset() {
    tier = data.tickets[0].id;
    selected = new Set();
    quantity.value = '1';
    eventSelect.value = data.id;
    eventSelect.disabled = false;
    quantity.disabled = !canChoose;
    document.getElementById('preview-show-summary').disabled = !canChoose;
    document.getElementById('preview-reset').disabled = false;
    const toggle = document.getElementById('seat-view-toggle');
    if (toggle) {
      toggle.disabled = !canChoose;
      toggle.textContent = 'Dùng danh sách ghế';
      document.getElementById('seat-map').hidden = false;
      document.getElementById('seat-list').hidden = true;
    }
    message(canChoose ? 'Lựa chọn chỉ ở trang này; chưa lưu hoặc giữ chỗ.' :
      'Nội dung vẫn đọc được, nhưng lựa chọn mẫu đã được khóa.');
    render();
    openFirstSeatRow();
  }
  function chooseSeat(id) {
    const seat = seats.get(id);
    if (!canChoose || !seat || !seat.available || seat.tier !== tier) return;
    if (!validQuantity()) {
      message('Nhập số lượng nguyên từ 1 đến 6 trước khi chọn ghế.');
      render();
      return;
    }
    if (selected.has(id)) {
      selected.delete(id);
      message('Đã bỏ ghế ' + id + ' khỏi lựa chọn thử.');
    } else if (selected.size < quantity.valueAsNumber) {
      selected.add(id);
      message('Đã chọn thử ghế ' + id + '. Chưa giữ ghế.');
    } else {
      message('Đã đủ số ghế cho số lượng mẫu. Bỏ một ghế hoặc tăng số lượng.');
    }
    render();
  }
  document.querySelectorAll('[data-ticket]').forEach(button => {
    button.addEventListener('click', () => {
      if (!canChoose) return;
      const changed = tier !== button.dataset.ticket;
      tier = button.dataset.ticket;
      selected = new Set([...selected].filter(id => seats.get(id).tier === tier));
      message(changed ? 'Đã đổi hạng. Các ghế không thuộc hạng mới đã được bỏ.' : 'Hạng vé đang được chọn thử.');
      render();
      if (changed) openFirstSeatRow();
    });
  });
  quantity.addEventListener('input', () => {
    selected.clear();
    message(validQuantity() ? 'Số lượng đã đổi; chọn lại ghế nếu sự kiện đánh số ghế.' :
      'Số lượng phải là số nguyên từ 1 đến 6.');
    render();
  });
  document.querySelectorAll('[data-seat]').forEach(button => {
    button.addEventListener('click', () => chooseSeat(button.dataset.seat));
  });
  document.querySelectorAll('[data-seat-list]').forEach(input => {
    input.addEventListener('change', () => chooseSeat(input.dataset.seatList));
  });
  const toggle = document.getElementById('seat-view-toggle');
  if (toggle) toggle.addEventListener('click', () => {
    const list = document.getElementById('seat-list');
    list.hidden = !list.hidden;
    document.getElementById('seat-map').hidden = !list.hidden;
    toggle.textContent = list.hidden ? 'Dùng danh sách ghế' : 'Dùng sơ đồ ghế';
  });
  eventSelect.addEventListener('change', () => {
    // Navigation loads a fresh, independent preview. Nothing is sent to registration.
    if (eventSelect.value.startsWith('demo-')) {
      window.location.assign('/ticket-preview.php?id=' + encodeURIComponent(eventSelect.value));
    }
  });
  document.getElementById('preview-reset').addEventListener('click', () => {
    reset();
    message('Đã đặt lại lựa chọn thử của sự kiện này.');
  });
  document.getElementById('preview-show-summary').addEventListener('click', () => {
    if (!canChoose || !validQuantity()) {
      message('Kiểm tra số lượng mẫu trước khi xem tóm tắt.');
      feedback.focus();
      return;
    }
    if (data.seating === 'numbered' && selected.size !== quantity.valueAsNumber) {
      message('Số ghế đã chọn phải khớp số lượng vé mẫu.');
      feedback.focus();
      return;
    }
    document.getElementById('summary-event').textContent = data.title;
    document.getElementById('summary-ticket').textContent = currentTicket().name;
    document.getElementById('summary-quantity').textContent = quantity.value;
    document.getElementById('summary-seats').textContent = data.seating === 'numbered' ?
      [...selected].join(', ') : currentTicket().zone;
    document.getElementById('summary-total').textContent =
      money(currentTicket().price * quantity.valueAsNumber) + ' minh họa';
    summary.hidden = false;
    summary.focus();
  });
  window.addEventListener('pageshow', reset);
  reset();
})();
