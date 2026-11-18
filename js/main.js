const toggleBtn = document.getElementById('toggleBtn');
const sidebar = document.getElementById('sidebar');

// Điện thoại (≤ 768px): thanh menu là ngăn kéo trượt ra từ trái, có nền mờ; máy tính: thu gọn / mở rộng như cũ
const mobileQuery = window.matchMedia('(max-width: 768px)');
const sidebarBackdrop = document.createElement('div');
sidebarBackdrop.className = 'sidebar-backdrop';
document.body.appendChild(sidebarBackdrop);

function setNavOpen(open) {
    document.body.classList.toggle('nav-open', open);
    toggleBtn.setAttribute('aria-expanded', String(open));
}
sidebarBackdrop.addEventListener('click', () => setNavOpen(false));
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.body.classList.contains('nav-open')) setNavOpen(false);
});
sidebar.addEventListener('click', event => {
    if (mobileQuery.matches && event.target.closest('a[href]')) setNavOpen(false);   // chọn trang -> đóng ngăn kéo
});
mobileQuery.addEventListener('change', () => setNavOpen(false));
document.querySelector('.js-bottom-menu')?.addEventListener('click', () => setNavOpen(true));   // nút "Menu" ở thanh tab dưới

toggleBtn.addEventListener('click', () => {
    if (mobileQuery.matches) {
        setNavOpen(!document.body.classList.contains('nav-open'));
        return;
    }
    sidebar.classList.toggle('collapsed');
    if(sidebar.classList.contains('collapsed')) {
        document.querySelectorAll('.menu-item').forEach(item => {
            item.classList.remove('active');
        });
    }
});

function toggleSubMenu(element) {
    if(sidebar.classList.contains('collapsed')) {
        sidebar.classList.remove('collapsed');
    }
    const parent = element.parentElement;
    document.querySelectorAll('.menu-item').forEach(item => {
        if(item !== parent) item.classList.remove('active');
    });
    parent.classList.toggle('active');
}

// === Chọn nhiều dòng (chỉ chạy trên trang có bảng checkbox) ===
const selectAllCb = document.getElementById('selectAll');
const rowCheckboxes = document.querySelectorAll('.row-checkbox');
const btnDeleteSelected = document.getElementById('btnDeleteSelected');

function toggleDeleteButton() {
    const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
    if(checkedCount > 0) {
        btnDeleteSelected.classList.remove('hidden');
    } else {
        btnDeleteSelected.classList.add('hidden');
    }
}

if (selectAllCb && btnDeleteSelected) {
    selectAllCb.addEventListener('change', function() {
        rowCheckboxes.forEach(cb => cb.checked = this.checked);
        toggleDeleteButton();
    });

    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            toggleDeleteButton();
            if (!this.checked) {
                selectAllCb.checked = false;
            } else {
                const allChecked = document.querySelectorAll('.row-checkbox:not(:checked)').length === 0;
                if (allChecked) selectAllCb.checked = true;
            }
        });
    });
}

// === Phân trang: đổi số dòng / trang hoặc chọn trang -> tải lại với tham số mới trên URL ===
function goTo(params) {
    const url = new URL(window.location.href);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    window.location.href = url.toString();
}

const pageSizeSelect = document.getElementById('pageSize');
const pageJumpSelect = document.getElementById('pageJump');
if (pageSizeSelect) {
    pageSizeSelect.addEventListener('change', function() {
        goTo({ size: this.value, page: 1 });
    });
}
if (pageJumpSelect) {
    pageJumpSelect.addEventListener('change', function() {
        goTo({ page: this.value });
    });
}

// === Nút "Nhập Excel": mở hộp chọn file, chọn xong tự gửi form ===
document.querySelectorAll('[data-file-trigger]').forEach(button => {
    const input = document.getElementById(button.dataset.fileTrigger);
    if (!input) return;
    button.addEventListener('click', () => input.click());
    input.addEventListener('change', () => {
        if (input.files.length) {
            button.disabled = true;
            input.form.submit();
        }
    });
});
