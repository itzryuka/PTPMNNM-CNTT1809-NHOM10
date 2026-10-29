// Trang chi tiết đợt kiểm kê: tính chênh lệch ngay khi gõ số đếm, nhắc lưu trước khi duyệt.
// Chỉ là tiện ích giao diện: server tính lại chênh lệch và kiểm tra lý do khi duyệt.
(function () {
    const form = document.getElementById('countForm');
    if (!form) return;

    const rows = form.querySelectorAll('.js-count-row');
    const hint = document.getElementById('dirtyHint');
    let dirty = false;

    function formatQty(qty) {
        return Math.abs(qty).toLocaleString('vi-VN', { maximumFractionDigits: 3 });
    }

    function refresh(row) {
        const input = row.querySelector('.js-counted');
        const reason = row.querySelector('.js-reason');
        const cell = row.querySelector('.js-diff');
        if (!input) return;

        if (input.value === '') {
            cell.innerHTML = '<span class="muted">—</span>';
            row.classList.remove('has-diff');
            reason.classList.remove('needs-reason');
            return;
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
    }

    rows.forEach(row => {
        refresh(row);
        row.querySelectorAll('input').forEach(input => input.addEventListener('input', () => {
            refresh(row);
            dirty = true;
            if (hint) hint.textContent = 'Có thay đổi chưa lưu.';
        }));
    });

    form.addEventListener('submit', () => { dirty = false; });

    // Duyệt / hủy: còn số đếm chưa lưu thì bắt lưu trước (popup xác nhận + khóa nút do js/modal.js lo)
    document.querySelectorAll('.js-decision').forEach(decision => {
        decision.addEventListener('submit', event => {
            if (!dirty) return;
            event.preventDefault();
            appDialog({ alert: true, title: 'Chưa lưu số đếm', message: 'Bạn có số đếm chưa lưu. Hãy bấm "Lưu số đếm" trước khi chốt đợt kiểm kê.' });
        });
    });

    // Bấm link rời trang khi còn số đếm chưa lưu -> hỏi bằng popup
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!dirty || !link || link.target === '_blank' || link.getAttribute('href').startsWith('#')
            || event.ctrlKey || event.metaKey || event.shiftKey) return;
        event.preventDefault();
        appDialog({ title: 'Rời trang?', ok: 'Rời trang', message: 'Số đếm vừa nhập chưa được lưu và sẽ mất nếu rời trang.' })
            .then(leave => {
                if (!leave) return;
                dirty = false;
                window.location.href = link.href;
            });
    });

    // Tải lại / đóng tab: trình duyệt chỉ cho hiện hộp thoại mặc định của nó (không thay bằng popup được)
    window.addEventListener('beforeunload', event => {
        if (dirty) event.preventDefault();
    });
})();
