// Bảng chi tiết hàng của form lập phiếu nhập / xuất.
// Dữ liệu do views/voucher_form.php đưa vào window.VOUCHER_FORM.
// Đây chỉ là tiện ích giao diện: mọi kiểm tra quyết định đều làm lại ở server.
(function () {
    const data = window.VOUCHER_FORM;
    const tbody = document.getElementById('lineRows');
    if (!data || !tbody) return;

    const isImport = data.type === 'IMPORT';
    const warehouseSelect = document.getElementById('warehouseId');
    const itemById = new Map(data.items.map(item => [String(item.id), item]));
    let nextIndex = 0;

    function option(value, label, selected = false, disabled = false) {
        const el = document.createElement('option');
        el.value = value;
        el.textContent = label;
        el.selected = selected;
        el.disabled = disabled;
        return el;
    }

    function formatDate(iso) {
        if (!iso) return 'không có';
        const [y, m, d] = iso.split('-');
        return `${d}/${m}/${y}`;
    }

    function formatQty(qty) {
        return Number(qty).toLocaleString('vi-VN', { maximumFractionDigits: 3 });
    }

    // Nhập: mọi lô của mặt hàng + "Tạo lô mới"
    // Xuất: chỉ lô còn tồn tại kho đang chọn, hết hạn trước xếp trước (FEFO); lô hết hạn bị khóa
    function fillBatches(row, selected) {
        const itemId = row.querySelector('.js-item').value;
        const select = row.querySelector('.js-batch');
        select.innerHTML = '';

        if (!itemId) {
            select.append(option('', '-- Chọn mặt hàng trước --'));
            select.disabled = true;
            toggleNewBatch(row);
            return;
        }
        select.disabled = false;

        if (isImport) {
            select.append(option('', '-- Chọn lô --'));
            data.batches
                .filter(b => String(b.item_id) === itemId)
                .forEach(b => select.append(option(b.id, `${b.batch_no} (HSD ${formatDate(b.expiry_date)})`, String(b.id) === String(selected))));
            select.append(option('new', '+ Tạo lô mới', selected === 'new'));
        } else {
            const lots = data.stock.filter(s => String(s.warehouse_id) === warehouseSelect.value && String(s.item_id) === itemId);
            if (!lots.length) {
                select.append(option('', 'Kho này đã hết mặt hàng này'));
            } else {
                select.append(option('', '-- Chọn lô --'));
            }
            lots.forEach(s => {
                const expired = s.expiry_date !== null && s.expiry_date < data.today;
                const label = `${s.batch_no} (HSD ${formatDate(s.expiry_date)}) — có thể xuất ${formatQty(s.quantity)}${expired ? ' — ĐÃ HẾT HẠN' : ''}`;
                select.append(option(s.batch_id, label, String(s.batch_id) === String(selected), expired));
            });
        }
        toggleNewBatch(row);
    }

    function toggleNewBatch(row) {
        const box = row.querySelector('.js-new-batch');
        if (!box) return;
        const isNew = row.querySelector('.js-batch').value === 'new';
        box.classList.toggle('hidden', !isNew);
        box.querySelectorAll('input').forEach(input => { input.disabled = !isNew; });
    }

    function updateUnit(row) {
        const item = itemById.get(row.querySelector('.js-item').value);
        row.querySelector('.js-unit').textContent = item ? item.unit : '';
    }

    function renumber() {
        tbody.querySelectorAll('tr').forEach((tr, i) => { tr.querySelector('.js-no').textContent = i + 1; });
    }

    function addRow(line = {}) {
        const i = nextIndex++;
        const name = field => `lines[${i}][${field}]`;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="js-no"></td>
            <td><select class="form-control js-item" name="${name('item_id')}"></select></td>
            <td>
                <select class="form-control js-batch" name="${name('batch_id')}"></select>
                ${isImport ? `
                <div class="new-batch js-new-batch hidden">
                    <label>Số lô mới *<input class="form-control js-new-no" name="${name('new_batch_no')}" maxlength="50" required></label>
                    <label>Ngày SX<input class="form-control js-new-mfg" type="date" name="${name('new_mfg')}"></label>
                    <label>Hạn SD *<input class="form-control js-new-exp" type="date" name="${name('new_expiry')}" required></label>
                </div>` : ''}
            </td>
            <td><input class="form-control js-qty" type="number" name="${name('quantity')}" min="0.001" step="0.001" required></td>
            <td class="js-unit muted"></td>
            <td><button type="button" class="icon-btn js-remove" title="Xóa dòng"><i class="fas fa-times"></i></button></td>`;

        const itemSelect = tr.querySelector('.js-item');
        itemSelect.append(option('', '-- Chọn mặt hàng --'));
        data.items.forEach(item => itemSelect.append(option(item.id, `${item.sku} - ${item.name}`, String(item.id) === String(line.item_id))));

        // Giá trị gán bằng thuộc tính DOM (không ghép chuỗi HTML) -> không bị chèn mã
        tr.querySelector('.js-qty').value = line.quantity ?? '';
        if (isImport) {
            tr.querySelector('.js-new-no').value = line.new_batch_no ?? '';
            tr.querySelector('.js-new-mfg').value = line.new_mfg ?? '';
            tr.querySelector('.js-new-exp').value = line.new_expiry ?? '';
        }

        itemSelect.addEventListener('change', () => { fillBatches(tr, ''); updateUnit(tr); });
        tr.querySelector('.js-batch').addEventListener('change', () => toggleNewBatch(tr));
        tr.querySelector('.js-remove').addEventListener('click', () => {
            if (tbody.children.length > 1) {
                tr.remove();
                renumber();
            } else {
                itemSelect.value = '';
                tr.querySelector('.js-qty').value = '';
                fillBatches(tr, '');
                updateUnit(tr);
            }
        });

        tbody.append(tr);
        fillBatches(tr, line.batch_id ?? '');
        updateUnit(tr);
        renumber();
    }

    // Điều chuyển: kho đến không được trùng kho đi
    const toWarehouseSelect = document.getElementById('toWarehouseId');
    function syncDestination() {
        if (!toWarehouseSelect) return;
        [...toWarehouseSelect.options].forEach(o => { o.disabled = o.value !== '' && o.value === warehouseSelect.value; });
        if (toWarehouseSelect.selectedOptions[0] && toWarehouseSelect.selectedOptions[0].disabled) {
            toWarehouseSelect.value = '';
        }
    }

    // Đổi kho xuất / kho đi -> nạp lại danh sách lô còn tồn của kho mới
    if (!isImport) {
        warehouseSelect.addEventListener('change', () => {
            tbody.querySelectorAll('tr').forEach(tr => fillBatches(tr, tr.querySelector('.js-batch').value));
            syncDestination();
        });
        syncDestination();
    }

    document.getElementById('addLine').addEventListener('click', () => addRow());

    // Chặn bấm "Lưu" 2 lần (server cũng chặn bằng client_token)
    document.getElementById('voucherForm').addEventListener('submit', () => {
        const button = document.getElementById('submitVoucher');
        setTimeout(() => { button.disabled = true; }, 0);
    });

    (data.oldLines.length ? data.oldLines : [{}]).forEach(line => addRow(line));
})();
