// Kiểm kê: chọn nhân viên khi mở đợt; trang chi tiết giữ dòng ngay khi gõ, tự lưu & khóa khi rời dòng, hiện tên người đang nhập / đã nhập.
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


// Trang chi tiết: gõ số vào dòng nào thì GIỮ dòng đó cho mình ngay (người khác thấy tên và không nhập được),
// chuyển sang dòng khác / Enter thì tự lưu. Dòng đã lưu khóa với người khác nhưng chính người nhập vẫn sửa được
// (dòng .row-saved, số đã lưu ở data-saved-*). Server là chốt chặn cuối cho mọi bước (giữ dòng, lưu, khóa).
(function () {
    const form = document.getElementById('countForm');
    if (!form) return;

    const rows = [...form.querySelectorAll('.js-count-row')];
    const hint = document.getElementById('dirtyHint');
    const progress = document.getElementById('countProgress');
    const pollUrl = form.dataset.poll;
    const canUnlock = form.dataset.canUnlock === '1';
    const csrf = form.querySelector('[name="csrf_token"]').value;
    const countId = form.querySelector('[name="id"]').value;
    const endpoint = form.getAttribute('action');
    const HEARTBEAT_MS = 15000;                              // báo "còn đang gõ" (server giữ dòng 45 giây)
    const mine = new Set();                                  // các dòng mình đang giữ
    const claiming = new Map();                              // dòng đang gửi yêu cầu giữ -> Promise
    let unlockedNotice = false;
    let closed = false;                                      // quản lý đã duyệt / hủy đợt trong lúc trang đang mở

    const qtyInput = row => row.querySelector('.js-counted');
    const reasonInput = row => row.querySelector('.js-reason');
    const isOwnSaved = row => (row.dataset.savedQty ?? '') !== '';
    // Số / lý do trên ô khác với số đã lưu (dòng mới: khác rỗng)?
    const isDirty = row => qtyInput(row).value !== (row.dataset.savedQty ?? '')
        || reasonInput(row).value.trim() !== (row.dataset.savedReason ?? '').trim();

    function formatQty(qty) {
        return Math.abs(qty).toLocaleString('vi-VN', { maximumFractionDigits: 3 });
    }

    function escapeHtml(text) {
        return String(text ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    }

    function setHint(text) {
        if (hint) hint.textContent = text;
    }

    // Còn dòng đã gõ / sửa mà chưa lưu được (vd. thiếu lý do chênh lệch)?
    function hasUnsaved() {
        return rows.some(row => {
            const input = qtyInput(row);
            return input && !input.disabled && isDirty(row);
        });
    }

    // Chênh lệch tính ngay khi gõ (server tính lại khi lưu); trả về chênh lệch theo phần nghìn
    function refresh(row) {
        const input = qtyInput(row);
        const reason = reasonInput(row);
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

    function setCounter(row, html) {
        row.querySelector('.js-counter').innerHTML = html;
    }

    function showRowError(row, message) {
        row.querySelector('.count-conflict')?.remove();
        row.querySelector('.js-counter').insertAdjacentHTML('beforeend', `<span class="count-conflict">${escapeHtml(message)}</span>`);
    }

    function clearInputs(row) {
        const input = qtyInput(row);
        if (!input) return;
        input.value = '';
        reasonInput(row).value = '';
        refresh(row);
    }

    // Mình đang giữ dòng: hiện "Bạn đang nhập" + nút lưu ngay (không bắt buộc: rời dòng là tự lưu)
    function showMine(row) {
        const error = row.querySelector('.count-conflict');      // giữ thông báo đang hiện (vd. thiếu lý do)
        row.classList.add('row-mine');
        row.classList.remove('row-claimed');
        setCounter(row, `<span class="count-by count-mine"><i class="fas fa-pen"></i> <strong>Bạn</strong> đang nhập</span>
            <button class="icon-btn js-save-line" type="button" title="Lưu ngay (hoặc Enter / chuyển sang dòng khác)"><i class="fas fa-check icon-view"></i></button>`);
        if (error) row.querySelector('.js-counter').append(error);
    }

    // Dòng mình đã lưu: hiện tên + giờ, ô nhập vẫn mở để sửa
    function showSaved(row) {
        row.classList.add('row-saved');
        row.classList.remove('row-mine', 'row-claimed', 'row-conflict');
        setCounter(row, `<span class="count-by count-own"><i class="fas fa-check-circle"></i> <strong>${escapeHtml(row.dataset.savedBy)}</strong></span>
            <span class="muted">${escapeHtml(row.dataset.savedAt)} · sửa được</span>`);
    }

    // Đưa ô nhập về đúng số đã lưu (bỏ phần đang sửa dở)
    function restoreSaved(row) {
        qtyInput(row).value = row.dataset.savedQty;
        reasonInput(row).value = row.dataset.savedReason ?? '';
        refresh(row);
        showSaved(row);
    }

    function showIdle(row) {
        if (isOwnSaved(row)) {
            showSaved(row);
            return;
        }
        row.classList.remove('row-mine', 'row-claimed', 'row-saved');
        row.querySelectorAll('input').forEach(input => { input.disabled = false; });
        const input = qtyInput(row);
        if (input) input.placeholder = 'Chưa đếm';
        setCounter(row, '<span class="muted">—</span>');
    }

    // Người khác đang gõ dòng này: khóa ô nhập của mình, hiện tên người đó
    function showClaimed(row, name) {
        row.classList.add('row-claimed');
        row.classList.remove('row-mine');
        row.querySelectorAll('input').forEach(input => { input.disabled = true; });
        const input = qtyInput(row);
        if (input) input.placeholder = 'Người khác đang nhập';
        setCounter(row, `<span class="count-by count-claimed"><i class="fas fa-pen"></i> <strong>${escapeHtml(name)}</strong> đang nhập…</span>`);
    }

    // Dòng đã lưu: hiện số, chênh lệch, lý do và tên người nhập ngay tại dòng; không còn ô nhập
    function lockRow(row, state) {
        const cells = row.children;
        mine.delete(row.dataset.line);
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
        setCounter(row, counter);
        row.classList.remove('has-diff', 'row-conflict', 'row-mine', 'row-claimed', 'row-saved');
        row.classList.add('row-locked');
    }

    // Áp trạng thái mới nhất từ server cho mọi dòng (sau mỗi thao tác, hoặc mỗi lần hỏi định kỳ)
    function applyStates(lines) {
        let counted = 0;
        let mismatched = 0;
        rows.forEach(row => {
            const state = lines[row.dataset.line];
            if (!state) return;
            if (state.counted) counted++;
            if (state.mismatch) mismatched++;
            const input = qtyInput(row);
            const locked = row.classList.contains('row-locked');
            const line = row.dataset.line;

            if (state.counted && state.own && input) {
                // Dòng của mình: cập nhật số đã lưu; đang sửa dở thì giữ nguyên chữ đang gõ
                const editing = mine.has(line) || claiming.has(line) || row.dataset.saving || isDirty(row);
                row.dataset.savedQty = state.raw;
                row.dataset.savedReason = state.reason ?? '';
                row.dataset.savedBy = state.by;
                row.dataset.savedAt = state.at;
                if (!editing) restoreSaved(row);
                return;
            }
            if (!state.counted && isOwnSaved(row)) {
                // Quản lý vừa mở khóa dòng mình đã nhập: về dòng chưa đếm
                ['savedQty', 'savedReason', 'savedBy', 'savedAt'].forEach(key => delete row.dataset[key]);
                if (!mine.has(line)) {
                    clearInputs(row);
                    showIdle(row);
                }
            }

            if (state.counted) {
                if (locked) return;
                if (!input || input.value === '' || row.dataset.saving) {
                    lockRow(row, state);
                } else if (!row.classList.contains('row-conflict')) {
                    // Hiếm: mình mất quyền giữ (vd. máy ngủ quá lâu) và người khác đã lưu -> báo, số của mình không được ghi
                    row.classList.add('row-conflict');
                    showRowError(row, `${state.by} đã nhập ${state.qty}: số của bạn không được ghi. Xóa số của bạn để xem.`);
                }
            } else if (locked) {
                if (!unlockedNotice) {
                    unlockedNotice = true;
                    setHint('Quản lý vừa mở khóa dòng để đếm lại: tải lại trang để nhập lại dòng đó.');
                }
            } else if (state.claim && !state.mine) {
                if (row.classList.contains('row-claimed')) {
                    row.querySelector('.count-claimed strong').textContent = state.claim;
                    return;
                }
                const lost = input && input.value !== '';
                mine.delete(row.dataset.line);
                clearInputs(row);
                showClaimed(row, state.claim);
                if (lost) showRowError(row, `${state.claim} đã giữ dòng này trước, số bạn gõ đã bị bỏ.`);
            } else if (row.classList.contains('row-claimed')) {
                showIdle(row);                               // người kia đã thôi gõ (xóa số / rời trang)
            }
        });
        if (progress) progress.textContent = `Đã đếm ${counted} / ${rows.length} dòng · ${mismatched} dòng lệch`;
    }

    async function post(action, fields) {
        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('action', action);
        body.append('id', countId);
        Object.entries(fields).forEach(([key, value]) => body.append(key, value));
        try {
            const response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
            const data = await response.json();
            if (response.status === 422) poll();             // bị từ chối: có thể đợt vừa bị chốt -> hỏi ngay để báo
            return data;
        } catch (e) {
            return { ok: false, message: 'Mất kết nối hoặc phiên đăng nhập đã hết. Tải lại trang rồi thử lại.' };
        }
    }

    // Giữ dòng ngay khi gõ ký tự đầu tiên. Ai gửi tới server trước thì được, người sau nhận tên người giữ.
    function claim(row) {
        const line = row.dataset.line;
        if (claiming.has(line)) return claiming.get(line);
        const request = post('claim_line', { line_id: line }).then(data => {
            claiming.delete(line);
            if (data.ok) {
                mine.add(line);
                if (!row.dataset.saving && !row.classList.contains('row-locked')) showMine(row);
                return true;
            }
            mine.delete(line);
            if (data.lines) {
                if (isOwnSaved(row)) restoreSaved(row); else clearInputs(row);
                applyStates(data.lines);                     // khóa dòng (đã có người lưu) hoặc hiện tên người đang giữ
            }
            showRowError(row, data.message || 'Không giữ được dòng này.');
            return false;
        });
        claiming.set(line, request);
        return request;
    }

    // Thôi gõ (xóa số / rời dòng khi ô trống): nhả dòng cho người khác
    function release(row) {
        const line = row.dataset.line;
        if (!mine.has(line)) return;
        mine.delete(line);
        showIdle(row);                                       // dòng mình đã lưu: về trạng thái "đã lưu, sửa được"
        post('release_line', { line_id: line });
    }

    // Lưu 1 dòng rồi khóa. fromEnter: người dùng bấm Enter (được chuyển con trỏ); rời dòng thì không giành con trỏ.
    async function saveLine(row, fromEnter) {
        const input = qtyInput(row);
        const reason = reasonInput(row);
        if (!input || input.disabled || row.dataset.saving) return false;
        if (!isDirty(row)) {
            release(row);                                    // không đổi gì: khỏi lưu
            if (isOwnSaved(row)) return true;
            if (fromEnter) showRowError(row, 'Nhập số đếm thực tế trước.');
            return false;
        }
        if (input.value === '') {
            if (isOwnSaved(row)) {
                // Xóa trắng dòng đã lưu: không xóa số trên server, trả lại số cũ
                release(row);
                restoreSaved(row);
                showRowError(row, 'Không để trống được dòng đã nhập: sửa thành số đếm đúng.');
                return false;
            }
            release(row);
            if (fromEnter) showRowError(row, 'Nhập số đếm thực tế trước.');
            return false;
        }
        if (refresh(row) !== 0 && reason.value.trim() === '') {
            showRowError(row, 'Lệch so với tồn hệ thống: nhập lý do chênh lệch, dòng sẽ tự lưu khi bạn rời dòng.');
            if (fromEnter) reason.focus();
            return false;
        }
        const line = row.dataset.line;
        if (claiming.has(line) && !(await claiming.get(line))) return false;   // người khác giữ trước -> đã báo

        row.dataset.saving = '1';
        input.readOnly = reason.readOnly = true;
        setCounter(row, '<span class="muted"><i class="fas fa-spinner fa-spin"></i> Đang lưu…</span>');
        const data = await post('save_line', { line_id: line, counted_qty: input.value, reason: reason.value });
        delete row.dataset.saving;
        input.readOnly = reason.readOnly = false;
        if (data.ok) {
            // Lưu xong: số trên ô thành "số đã lưu", applyStates đồng bộ lại theo server (tên, giờ, chênh lệch)
            mine.delete(line);
            row.dataset.savedQty = input.value;
            row.dataset.savedReason = reason.value.trim();
            applyStates(data.lines);
            setHint('Đã lưu. Dòng mang tên bạn: người khác không sửa được, bạn vẫn sửa lại được.');
            return true;
        }
        if (data.lines) {
            // Người khác đã nhập / đang giữ dòng này trước: bỏ số mình gõ, hiện theo server
            mine.delete(line);
            if (isOwnSaved(row)) restoreSaved(row); else clearInputs(row);
            applyStates(data.lines);
            showRowError(row, data.message);
            return false;
        }
        if (mine.has(line)) showMine(row);
        showRowError(row, data.message || 'Có lỗi xảy ra.');
        return false;
    }

    // Bấm Enter lưu xong: nhảy tới ô nhập của dòng còn trống kế tiếp cho nhập liên tục
    function focusNext(row) {
        let next = row.nextElementSibling;
        while (next && !(qtyInput(next) && !qtyInput(next).disabled && qtyInput(next).value === '')) next = next.nextElementSibling;
        next && qtyInput(next).focus();
    }

    rows.forEach(row => {
        refresh(row);
        row.querySelectorAll('input').forEach(input => {
            input.addEventListener('input', () => {
                refresh(row);
                row.querySelector('.count-conflict')?.remove();
                row.classList.remove('row-conflict');
                if (isDirty(row) && !mine.has(row.dataset.line)) claim(row);
            });
            input.addEventListener('keydown', async event => {
                if (event.key !== 'Enter' || event.isComposing) return;
                event.preventDefault();
                if (await saveLine(row, true)) focusNext(row);
            });
        });
        // Rời khỏi dòng (sang dòng khác, bấm ra ngoài): tự lưu; ô trống thì nhả dòng.
        // Chuyển sang cửa sổ / tab khác thì không tính là rời dòng (đang gõ dở).
        row.addEventListener('focusout', () => {
            setTimeout(() => {
                if (row.contains(document.activeElement) || !document.hasFocus()) return;
                saveLine(row, false);
            }, 0);
        });
    });
    form.addEventListener('click', event => {
        const button = event.target.closest('.js-save-line');
        if (button) saveLine(button.closest('.js-count-row'), true).then(ok => ok && focusNext(button.closest('.js-count-row')));
    });
    form.addEventListener('submit', event => event.preventDefault());   // không gửi cả bảng: mỗi dòng lưu riêng

    // Đang gõ dở thì định kỳ báo server "vẫn đang nhập" để giữ dòng; ô đã trống và không còn con trỏ thì nhả
    setInterval(() => {
        mine.forEach(line => {
            const row = form.querySelector(`.js-count-row[data-line="${line}"]`);
            if (!row || row.dataset.saving) return;
            if (isDirty(row) || row.contains(document.activeElement)) {
                post('claim_line', { line_id: line }).then(data => {
                    if (!data.ok && data.lines) applyStates(data.lines);
                });
            } else {
                release(row);
            }
        });
    }, HEARTBEAT_MS);

    // Rời / đóng trang: nhả các dòng đang giữ ngay (không chờ hết hạn)
    window.addEventListener('pagehide', () => {
        if (!mine.size || !navigator.sendBeacon) return;
        const body = new FormData();
        body.append('csrf_token', csrf);
        body.append('action', 'release_line');
        body.append('id', countId);
        mine.forEach(line => body.append('line_ids[]', line));
        navigator.sendBeacon(endpoint, body);
    });

    // Nhiều người cùng đếm: vài giây hỏi server 1 lần dòng nào người khác đang gõ / vừa lưu để cập nhật trang này
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
            countClosed(data);
            return;
        }
        applyStates(data.lines);
    }

    // Quản lý duyệt / hủy đợt khi mọi người còn đang đếm: khóa trang, hiện thông báo, Đồng ý -> về trang danh sách kiểm kê
    function countClosed(data) {
        if (closed) return;
        closed = true;
        clearInterval(timer);
        mine.clear();
        form.querySelectorAll('input, button').forEach(el => { el.disabled = true; });
        const action = data.status === 'APPROVED' ? 'chốt (duyệt)' : 'hủy';
        const by = data.closed_by ? ` bởi ${data.closed_by}` : '';
        setHint(`Đợt kiểm kê đã được ${action}${by}.`);
        appDialog({
            alert: true,
            variant: data.status === 'APPROVED' ? 'info' : 'danger',
            title: 'Đợt kiểm kê đã kết thúc',
            ok: 'Đồng ý',
            message: `Đợt ${data.code} vừa được ${action}${by}. Không nhập thêm số đếm được nữa; số đang gõ dở (nếu có) sẽ không được ghi. Bấm Đồng ý để quay lại trang kiểm kê.`,
        }).then(() => { window.location.href = 'stock_count_list.php'; });
    }

    const timer = pollUrl ? setInterval(poll, 3000) : null;
    if (pollUrl) document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

    // Duyệt / hủy: còn số đã gõ mà chưa lưu thì nhắc trước (popup xác nhận + khóa nút do js/modal.js lo)
    document.querySelectorAll('.js-decision').forEach(decision => {
        decision.addEventListener('submit', event => {
            if (!hasUnsaved()) return;
            event.preventDefault();
            appDialog({ alert: true, title: 'Còn số đếm chưa lưu', message: 'Có dòng đã gõ / sửa số nhưng chưa lưu được (thường do thiếu lý do chênh lệch). Hoàn tất hoặc bỏ phần sửa đó trước khi chốt đợt.' });
        });
    });

    // Bấm link rời trang khi còn số chưa lưu -> hỏi bằng popup
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || !hasUnsaved() || link.target === '_blank' || link.getAttribute('href').startsWith('#')
            || event.ctrlKey || event.metaKey || event.shiftKey) return;
        event.preventDefault();
        appDialog({ title: 'Rời trang?', ok: 'Rời trang', message: 'Có dòng đã gõ số nhưng chưa lưu được (thường do thiếu lý do chênh lệch), rời trang sẽ mất.' })
            .then(leave => {
                if (leave) window.location.href = link.href;
            });
    });

    // Tải lại / đóng tab: trình duyệt chỉ cho hiện hộp thoại mặc định của nó (không thay bằng popup được)
    window.addEventListener('beforeunload', event => {
        if (hasUnsaved()) event.preventDefault();
    });
})();
