// Popup dùng chung cho mọi trang.
//  - Mở:  phần tử có data-modal-open="idPopup"
//         + data-fill='{"action":"update","id":5,"sku":"SP001",...}' để điền sẵn các ô có thuộc tính data-field
//         + data-modal-title="..." để đổi tiêu đề popup
//  - Đóng: nút [data-modal-close], bấm ra nền tối, hoặc phím Esc
//  - Xác nhận: nút submit (hoặc cả form) có data-confirm="..." -> hỏi bằng popup #confirmModal thay cho confirm() của trình duyệt
//         + data-confirm-ok="Xóa" (chữ trên nút đồng ý), data-confirm-title="...", data-confirm-variant="danger|primary|import|info"
//         + form có data-lock (hoặc data-lock=".selector") -> sau khi đồng ý thì khóa các nút để không bấm 2 lần
//  - Trong JS: appDialog({ message, title, ok, variant, alert }) trả về Promise<boolean>; alert: true = thông báo chỉ có 1 nút
(function () {
    const confirmModal = document.getElementById('confirmModal');
    let resolveDialog = null;

    function openModal(modal) {
        modal.classList.add('open');
        const first = modal.querySelector('input:not([type="hidden"]):not([disabled]):not([readonly]), select, textarea');
        setTimeout(() => (first || modal.querySelector('.modal-footer .btn:last-child'))?.focus(), 50);
    }

    function closeModal(modal) {
        modal.classList.remove('open');
        if (modal === confirmModal) finishDialog(false);   // đóng bằng ×, Hủy, nền tối, Esc = không đồng ý
    }

    function finishDialog(result) {
        const resolve = resolveDialog;
        resolveDialog = null;
        resolve?.(result);
    }

    function fillForm(modal, data) {
        modal.querySelectorAll('[data-field]').forEach(field => {
            if (field.type === 'checkbox') {             // nhóm ô chọn, vd name="warehouses[]" <- data.warehouses = [1, 3]
                const values = data[field.name.replace('[]', '')];
                field.checked = Array.isArray(values) && values.map(String).includes(field.value);
            } else {
                field.value = data[field.name] ?? '';
            }
            if (field.hasAttribute('data-edit-readonly')) {   // vd tên đăng nhập: chỉ nhập khi tạo mới
                field.readOnly = data.action === 'update';
            }
        });
        modal.querySelectorAll('.js-modal-error').forEach(el => el.remove());   // lỗi của lần lưu trước
        modal.querySelectorAll('[data-check]').forEach(field => showCheck(field, null));
    }

    // ===== Kiểm tra trùng mã / tên ngay khi gõ (ô có data-check="items:code" ...) =====
    // Chỉ để báo sớm; khi lưu, server vẫn kiểm tra lại đầy đủ.
    function showCheck(field, message) {
        const hint = field.parentElement.querySelector('.field-check');
        field.setCustomValidity(message || '');            // còn lỗi thì form không gửi đi
        field.classList.toggle('is-invalid', Boolean(message));
        if (hint) {
            hint.textContent = message || '';
            hint.hidden = !message;
        }
    }

    const checkTimers = new WeakMap();
    document.addEventListener('input', event => {
        const field = event.target.closest('[data-check]');
        if (!field) return;
        clearTimeout(checkTimers.get(field));
        showCheck(field, null);
        checkTimers.set(field, setTimeout(async () => {
            const [entity, kind] = field.dataset.check.split(':');
            const form = field.form;
            // form.action bị ô <input name="action"> che mất -> đọc thẳng thuộc tính action
            const url = new URL('check_catalog.php', new URL(form.getAttribute('action'), location.href));
            url.search = new URLSearchParams({ entity, field: kind, value: field.value, id: form.elements.id?.value || 0 });
            try {
                const result = await (await fetch(url, { headers: { Accept: 'application/json' } })).json();
                if (field.value === url.searchParams.get('value')) showCheck(field, result.ok ? null : result.message);
            } catch (e) {
                /* mất mạng / phiên hết hạn: bỏ qua, server vẫn kiểm tra khi lưu */
            }
        }, 350));
    });

    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-modal-open]');
        if (trigger) {
            event.preventDefault();
            const modal = document.getElementById(trigger.dataset.modalOpen);
            if (!modal) return;
            if (trigger.dataset.fill !== undefined) {
                fillForm(modal, trigger.dataset.fill ? JSON.parse(trigger.dataset.fill) : {});
            }
            if (trigger.dataset.modalTitle) {
                modal.querySelector('.modal-title').textContent = trigger.dataset.modalTitle;
            }
            openModal(modal);
            return;
        }
        const backdrop = event.target.classList.contains('modal-backdrop') ? event.target : null;
        const closer = event.target.closest('[data-modal-close]');
        if (backdrop || closer) {
            closeModal((backdrop || closer).closest('.modal-backdrop'));
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.open').forEach(closeModal);
        }
    });

    // Popup đang mở sẵn khi tải trang (server trả lại lỗi khi lưu) -> đặt con trỏ vào ô đầu tiên
    document.querySelectorAll('.modal-backdrop.open').forEach(openModal);

    // ===== Popup xác nhận / thông báo =====
    const VARIANTS = {
        danger:  { button: 'btn-danger',  icon: 'fa-exclamation-triangle' },
        primary: { button: 'btn-primary', icon: 'fa-question-circle' },
        import:  { button: 'btn-import',  icon: 'fa-check-circle' },
        info:    { button: 'btn-export',  icon: 'fa-info-circle' },
    };

    window.appDialog = function (options) {
        if (!confirmModal) {   // trang không có footer chung
            return Promise.resolve(options.alert ? (window.alert(options.message), true) : window.confirm(options.message));
        }
        finishDialog(false);   // popup cũ còn chờ -> coi như hủy
        const name = VARIANTS[options.variant] ? options.variant : (options.alert ? 'info' : 'danger');
        const variant = VARIANTS[name];
        confirmModal.querySelector('.js-confirm-title').textContent = options.title || (options.alert ? 'Thông báo' : 'Xác nhận');
        confirmModal.querySelector('.js-confirm-message').textContent = options.message;
        confirmModal.querySelector('.js-confirm-body').className = 'modal-body confirm-body js-confirm-body is-' + name;
        confirmModal.querySelector('.js-confirm-icon').className = 'fas ' + variant.icon + ' js-confirm-icon';
        const ok = confirmModal.querySelector('.js-confirm-ok');
        ok.className = 'btn ' + variant.button + ' js-confirm-ok';
        ok.textContent = options.ok || (options.alert ? 'Đã hiểu' : 'Đồng ý');
        confirmModal.querySelector('.js-confirm-cancel').hidden = Boolean(options.alert);
        openModal(confirmModal);
        return new Promise(resolve => { resolveDialog = resolve; });
    };

    confirmModal?.querySelector('.js-confirm-ok').addEventListener('click', () => {
        finishDialog(true);
        closeModal(confirmModal);
    });

    // Khóa nút sau khi gửi (server vẫn chặn ghi đúp bằng khóa phiếu FOR UPDATE / client_token)
    function lockForm(form) {
        if (form.dataset.lock === undefined) return;
        const buttons = form.dataset.lock ? document.querySelectorAll(form.dataset.lock + ' button') : form.querySelectorAll('button');
        setTimeout(() => buttons.forEach(button => { button.disabled = true; }), 0);
    }

    // Xác nhận trước khi gửi form (xóa, duyệt, từ chối, mở / chốt đợt kiểm kê...)
    document.addEventListener('submit', event => {
        const form = event.target;
        const button = event.submitter;
        if (event.defaultPrevented) return;   // script riêng của trang đã chặn (vd. còn số đếm chưa lưu)
        if (form.dataset.confirmed === '1') {
            lockForm(form);
            return;
        }
        const source = button?.dataset.confirm ? button : (form.dataset.confirm ? form : null);
        if (!source) {
            lockForm(form);
            return;
        }
        event.preventDefault();
        appDialog({
            message: source.dataset.confirm,
            title:   source.dataset.confirmTitle,
            ok:      source.dataset.confirmOk,
            variant: source.dataset.confirmVariant,
        }).then(confirmed => {
            if (!confirmed) return;
            form.dataset.confirmed = '1';
            form.requestSubmit(button || undefined);
            delete form.dataset.confirmed;
        });
    });
})();
