// Form lập yêu cầu điều chuyển: thêm / xóa dòng, hiện "có thể xuất" của mặt hàng ở các kho,
// cảnh báo khi kho được đề nghị không đủ. Chỉ là tiện ích giao diện: server kiểm tra lại khi kho kia đồng ý.
(function () {
    const data = window.REQUEST_FORM;
    const body = document.getElementById('requestLines');
    if (!data || !body) return;

    const fromSelect = document.getElementById('fromWarehouse');
    const toSelect = document.getElementById('toWarehouse');
    const formatQty = qty => Number(qty).toLocaleString('vi-VN', { maximumFractionDigits: 3 });
    const escapeHtml = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let counter = 0;

    function itemOptions(selected) {
        return '<option value="">-- Chọn mặt hàng --</option>' + data.items.map(item =>
            `<option value="${item.id}" ${String(item.id) === String(selected) ? 'selected' : ''}>${escapeHtml(item.sku + ' - ' + item.name)} (${escapeHtml(item.unit)})</option>`
        ).join('');
    }

    // Chip "kho: số có thể xuất" cho 1 mặt hàng; kho được đề nghị tô đậm, thiếu thì báo đỏ
    function renderAvailability(tr) {
        const itemId = tr.querySelector('.js-item').value;
        const qty = Number(tr.querySelector('.js-qty').value || 0);
        const cell = tr.querySelector('.js-availability');
        if (!itemId) {
            cell.innerHTML = '<span class="muted">Chọn mặt hàng để xem</span>';
            return;
        }
        const byWarehouse = data.availability[itemId] || {};
        cell.innerHTML = data.warehouses
            .filter(w => String(w.id) !== toSelect.value)
            .map(w => {
                const available = byWarehouse[w.id] || 0;
                const isSource = String(w.id) === fromSelect.value;
                const short = isSource && qty > available;
                const cls = short ? 'badge-rejected' : isSource ? 'badge-info' : available > 0 ? 'badge-step' : 'badge-inactive';
                return `<span class="badge ${cls}" title="${escapeHtml(w.name)}">${escapeHtml(w.code)}: ${formatQty(available)}${short ? ' (không đủ)' : ''}</span>`;
            }).join(' ');
    }

    function addLine(line = {}) {
        const n = counter++;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="js-no"></td>
            <td><select class="form-control js-item" name="lines[${n}][item_id]" required>${itemOptions(line.item_id)}</select></td>
            <td><input class="form-control js-qty" type="number" name="lines[${n}][quantity]" min="0.001" step="0.001" required value="${escapeHtml(line.quantity ?? '')}"></td>
            <td class="js-availability"></td>
            <td><button type="button" class="icon-btn js-remove" title="Xóa dòng"><i class="fas fa-trash-alt icon-delete"></i></button></td>`;
        body.appendChild(tr);
        tr.querySelector('.js-item').addEventListener('change', () => renderAvailability(tr));
        tr.querySelector('.js-qty').addEventListener('input', () => renderAvailability(tr));
        tr.querySelector('.js-remove').addEventListener('click', () => {
            if (body.children.length > 1) {
                tr.remove();
                renumber();
            }
        });
        renderAvailability(tr);
        renumber();
    }

    function renumber() {
        [...body.children].forEach((tr, i) => { tr.querySelector('.js-no').textContent = i + 1; });
    }

    function refreshAll() {
        // Kho được đề nghị không được trùng kho cần hàng
        [...fromSelect.options].forEach(option => { option.disabled = option.value !== '' && option.value === toSelect.value; });
        if (fromSelect.value === toSelect.value) fromSelect.value = '';
        [...body.children].forEach(renderAvailability);
    }

    document.getElementById('addRequestLine').addEventListener('click', () => addLine());
    fromSelect.addEventListener('change', refreshAll);
    toSelect.addEventListener('change', refreshAll);

    (data.oldLines.length ? data.oldLines : [{}]).forEach(addLine);
    refreshAll();
})();
