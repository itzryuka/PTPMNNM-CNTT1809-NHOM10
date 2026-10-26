// Popup dùng chung cho mọi trang.
//  - Mở:  phần tử có data-modal-open="idPopup"
//         + data-fill='{"action":"update","id":5,"sku":"SP001",...}' để điền sẵn các ô có thuộc tính data-field
//         + data-modal-title="..." để đổi tiêu đề popup
//  - Đóng: nút [data-modal-close], bấm ra nền tối, hoặc phím Esc
//  - Xác nhận: nút submit có data-confirm="..." -> hỏi bằng popup #confirmModal thay cho confirm() của trình duyệt
(function () {
    function openModal(modal) {
        modal.classList.add('open');
        const first = modal.querySelector('input:not([type="hidden"]):not([disabled]):not([readonly]), select, textarea');
        setTimeout(() => (first || modal.querySelector('.modal-footer .btn:last-child'))?.focus(), 50);
    }

    function closeModal(modal) {
        modal.classList.remove('open');
    }

    function fillForm(modal, data) {
        modal.querySelectorAll('[data-field]').forEach(field => {
            field.value = data[field.name] ?? '';
        });
        modal.querySelectorAll('.js-modal-error').forEach(el => el.remove());   // lỗi của lần lưu trước
    }

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

    // Xác nhận trước khi gửi (xóa, xóa nhiều...)
    const confirmModal = document.getElementById('confirmModal');
    let pending = null;
    document.addEventListener('submit', event => {
        const button = event.submitter;
        if (!confirmModal || !button || !button.dataset.confirm || button.dataset.confirmed === '1') return;
        event.preventDefault();
        pending = { form: event.target, button };
        confirmModal.querySelector('.js-confirm-message').textContent = button.dataset.confirm;
        confirmModal.querySelector('.js-confirm-ok').innerHTML = button.dataset.confirmOk || '<i class="fas fa-check"></i> Đồng ý';
        openModal(confirmModal);
    });
    confirmModal?.querySelector('.js-confirm-ok').addEventListener('click', () => {
        if (!pending) return;
        const { form, button } = pending;
        pending = null;
        closeModal(confirmModal);
        button.dataset.confirmed = '1';
        form.requestSubmit(button);
        button.dataset.confirmed = '';
    });
})();
