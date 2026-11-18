// Kiểm kê: chọn nhân viên khi mở đợt; trang chi tiết lưu & khóa từng dòng ngay khi nhập, hiện tên người nhập, tự cập nhật dòng người khác vừa nhập.
// Chỉ là tiện ích giao diện: server kiểm tra quyền, khóa dòng, tính lại chênh lệch và lý do.
// Chọn nhân viên đi kiểm kê (form mở đợt + form đổi danh sách): chỉ hiện người của kho đang chọn, bắt chọn ít nhất 1 người
document.querySelectorAll('.js-member-grid').forEach(grid => {
    const form = grid.closest('form');
    const warehouseSelect = form.querySelector('select[name="warehouse_id"]');
    const empty = form.querySelector('.js-member-empty');
    const chips = grid.querySelectorAll('.check-chip');

    function validate() {
        const boxes = [...grid.querySelectorAll('input:not(:disabled)')];
        const message = boxes.some(box => box.checked) ? '' : 'Chọn ít nhất 1 nhân viên đi kiểm kê.';
        boxes.forEach(box => box.setCustomValidity(message));
    }

    function filter() {
        if (warehouseSelect) {
            let visible = 0;
            chips.forEach(chip => {
                const show = chip.dataset.warehouse === warehouseSelect.value;
                chip.hidden = !show;
                chip.querySelector('input').disabled = !show;   // ô ẩn không được gửi lên
                if (show) visible++;
            });
            if (empty) empty.hidden = visible > 0;
        }
        validate();
    }

    warehouseSelect?.addEventListener('change', filter);
    grid.addEventListener('change', validate);
    filter();
});

(function () {
    const form = document.getElementById('countForm');
    if (!form) return;

    const rows = form.querySelectorAll('.js-count-row');
    const hint = document.getElementById('dirtyHint');
    const progress = document.getElementById('countProgress');
    const pollUrl = form.dataset.poll;
    const canUnlock = form.dataset.canUnlock === '1';
    const csrf = form.querySelector('[name="csrf_token"]').value;
    const countId = form.querySelector('[name="id"]').value;
    const endpoint = form.getAttribute('action');
    let unlockedNotice = false;

    function formatQty(qty) {
        return Math.abs(qty).toLocaleString('vi-VN', { maximumFractionDigits: 3 });
    }

    function escapeHtml(text) {
        return String(text ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    }

    // Còn dòng đã gõ số mà chưa bấm "Lưu & khóa"?
    function hasUnsaved() {
        return [...form.querySelectorAll('.js-counted')].some(input => input.value !== '');
    }

    function setHint(text) {
        if (hint) hint.textContent = text;
    }

    // Chênh lệch tính ngay khi gõ (server tính lại khi lưu); trả về chênh lệch theo phần nghìn
    function refresh(row) {
        const input = row.querySelector('.js-counted');
        const reason = row.querySelector('.js-reason');
        const cell = row.querySelector('.js-diff');
        if (!input) return 0;

        if (input.value === '') {
            cell.innerHTML = '<span class="muted">—</span>';
            row.classList.remove('has-diff');
            reason.classList.remove('needs-reason');
            return 0;
        }
        // So sánh theo phần nghìn để tránh sai số số thực
        const diff = Math.round(Number(input.value) * 1000) - Math.round(Number(row.dataset.system) * 1000);
        if (diff === 0) {
            cell.innerHTML = '<span class="badge badge-active">Khớp</span>';
        } else {
            const value = formatQty(diff / 1000);
            cell.innerHTML = diff > 0 ? `<span class="qty-in">+${value}</span>` : `<span class="qty-out">−${value}</span>`;
        }
        row.classList.toggle('has-diff', diff !== 0);
        reason.classList.toggle('needs-reason', diff !== 0 && reason.value.trim() === '');
        return diff;
    }

    function showRowError(row, message) {
        row.querySelector('.count-conflict')?.remove();
        row.querySelector('.js-counter').insertAdjacentHTML('beforeend', `<span class="count-conflict">${escapeHtml(message)}</span>`);
    }

    // Dòng đã có người nhập: hiện số, chênh lệch, lý do và tên người nhập ngay tại dòng; không còn ô nhập
    function lockRow(row, state) {
        const cells = row.children;
        cells[4].innerHTML = `<i class="fas fa-lock muted" title="Đã khóa"></i> ${escapeHtml(state.qty)}`;
        row.querySelector('.js-diff').innerHTML = state.diff;
        cells[6].innerHTML = state.reason ? escapeHtml(state.reason) : '<span class="muted">—</span>';
        let counter = `<span class="count-by"><i class="fas fa-lock"></i> <strong>${escapeHtml(state.by)}</strong></span> <span class="muted">${escapeHtml(state.at)}</span>`;
        if (canUnlock) {
            const name = `${cells[1].textContent.trim().split(' - ')[0]} - lô ${cells[2].firstChild.textContent.trim()}`;
            counter += ` <button class="icon-btn" type="submit" form="unlockForm" name="line_id" value="${row.dataset.line}" title="Mở khóa để đếm lại"
                data-confirm-title="Mở khóa dòng" data-confirm-ok="Mở khóa"
                data-confirm="${escapeHtml(`Mở khóa dòng ${name}? Số đếm của ${state.by} bị xóa để đếm lại.`)}"><i class="fas fa-lock-open icon-edit"></i></button>`;
        }
        row.querySelector('.js-counter').innerHTML = counter;
        row.classList.remove('has-diff', 'row-conflict');
        row.classList.add('row-locked');
    }

    // Áp trạng thái mới nhất từ server cho mọi dòng (sau khi lưu 1 dòng, hoặc mỗi lần hỏi định kỳ)
    function applyStates(lines) {
        let counted = 0;
        let mismatched = 0;
        rows.forEach(row => {
            const state = lines[row.dataset.line];
            if (!state) return;
            if (state.counted) counted++;
            if (state.mismatch) mismatched++;
            const input = row.querySelector('.js-counted');
            const locked = row.classList.contains('row-locked');
            if (state.counted && !locked) {
                if (!input || input.value === '') {
                    lockRow(row, state);                     // người chỉ xem (không có ô nhập) cũng thấy dòng vừa nhập
                } else if (!row.classList.contains('row-conflict')) {
                    // Mình đang gõ dở dòng này mà người khác đã lưu trước -> báo, số của mình sẽ không được ghi
                    row.classList.add('row-conflict');
                    showRowError(row, `${state.by} vừa nhập ${state.qty}: số của bạn sẽ không được ghi. Xóa số của bạn để xem.`);
                }
            } else if (!state.counted && locked && !unlockedNotice) {
                unlockedNotice = true;
                setHint('Quản lý vừa mở khóa dòng để đếm lại: tải lại trang để nhập lại dòng đó.');
            }
        });
        if (progress) progress.textContent = `Đã đếm ${counted} / ${rows.length} dòng · ${mismatched} dòng lệch`;
    }

    // Lưu & khóa 1 dòng
    async function saveLine(row) {
        const input = row.querySelector('.js-counted');
        const reason = row.querySelector('.js-reason');
        const button = row.querySelector('.js-save-line');
        if (!input || button.disabled) return;
        if (input.value === '') {
            showRowError(row, 'Nhập số đếm thực tế trước.');
            input.focus();
            return;
        }
        // Lý do chênh lệch do server kiểm tra: dòng đã có người nhập thì báo điều đó trước, khỏi gõ lý do vô ích
        const missingReason = refresh(row) !== 0 && reason.value.trim() === '';
        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('action', 'save_line');
        body.append('id', countId);
        body.append('line_id', row.dataset.line);
        body.append('counted_qty', input.value);
        body.append('reason', reason.value);

        button.disabled = true;
        input.readOnly = reason.readOnly = true;
        let data;
        try {
            const response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
            data = await response.json();
        } catch (e) {
            data = { ok: false, message: 'Không lưu được (mất kết nối hoặc phiên đăng nhập đã hết). Tải lại trang rồi thử lại.' };
        }
        if (data.lines) {
            // Đã lưu xong (của mình, hoặc người khác nhập trước): bỏ số đang gõ để applyStates khóa dòng theo số trên server
            input.value = '';
            applyStates(data.lines);
        }
        if (row.classList.contains('row-locked')) {
            setHint(data.ok ? 'Đã lưu và khóa dòng.' : data.message);
            focusNext(row);
            return;
        }
        button.disabled = false;
        input.readOnly = reason.readOnly = false;
        showRowError(row, data.message || 'Có lỗi xảy ra.');
        (missingReason ? reason : input).focus();
    }

    // Lưu xong: nhảy tới ô nhập của dòng chưa đếm kế tiếp cho nhập liên tục
    function focusNext(row) {
        let next = row.nextElementSibling;
        while (next && !next.querySelector('.js-counted')) next = next.nextElementSibling;
        next?.querySelector('.js-counted').focus();
    }

    rows.forEach(row => {
        refresh(row);
        row.querySelectorAll('input').forEach(input => {
            input.addEventListener('input', () => {
                refresh(row);
                row.querySelector('.count-conflict')?.remove();
                row.classList.remove('row-conflict');
            });
            // Enter ở ô số đếm / lý do = bấm "Lưu & khóa" của dòng đó
            input.addEventListener('keydown', event => {
                if (event.key !== 'Enter' || event.isComposing) return;
                event.preventDefault();
                saveLine(row);
            });
        });
        row.querySelector('.js-save-line')?.addEventListener('click', () => saveLine(row));
    });
    form.addEventListener('submit', event => event.preventDefault());   // không gửi cả bảng: mỗi dòng lưu riêng

    // Nhiều người cùng đếm: cứ vài giây hỏi server dòng nào vừa được người khác nhập (đã khóa) để khóa luôn trên trang này.
    // Server vẫn là chốt chặn cuối: dòng đã khóa thì không ghi đè dù trang chưa kịp cập nhật.
    async function poll() {
        if (document.hidden) return;
        let data;
        try {
            const response = await fetch(pollUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            data = await response.json();
        } catch (e) {
            return;                                          // mất mạng / hết phiên: lần sau thử lại
        }
        if (!data.lines) return;
        if (data.status !== 'COUNTING') {
            setHint('Đợt kiểm kê vừa được chốt: tải lại trang để xem kết quả.');
            clearInterval(timer);
            return;
        }
        applyStates(data.lines);
    }

    const timer = pollUrl ? setInterval(poll, 5000) : null;
    if (pollUrl) document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

    // Duyệt / hủy: còn số đã gõ mà chưa lưu thì nhắc trước (popup xác nhận + khóa nút do js/modal.js lo)
    document.querySelectorAll('.js-decision').forEach(decision => {
        decision.addEventListener('submit', event => {
            if (!hasUnsaved()) return;
            event.preventDefault();
            appDialog({ alert: true, title: 'Còn số đếm chưa lưu', message: 'Có dòng đã nhập số nhưng chưa bấm "Lưu & khóa". Lưu hoặc xóa số đó trước khi chốt đợt kiểm kê.' });
        });
    });

    // Bấm link rời trang khi còn số chưa lưu -> hỏi bằng popup
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || !hasUnsaved() || link.target === '_blank' || link.getAttribute('href').startsWith('#')
            || event.ctrlKey || event.metaKey || event.shiftKey) return;
        event.preventDefault();
        appDialog({ title: 'Rời trang?', ok: 'Rời trang', message: 'Có số đếm đã nhập nhưng chưa bấm "Lưu & khóa", rời trang sẽ mất.' })
            .then(leave => {
                if (leave) window.location.href = link.href;
            });
    });

    // Tải lại / đóng tab: trình duyệt chỉ cho hiện hộp thoại mặc định của nó (không thay bằng popup được)
    window.addEventListener('beforeunload', event => {
        if (hasUnsaved()) event.preventDefault();
    });
})();
