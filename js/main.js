const toggleBtn = document.getElementById('toggleBtn');
const sidebar = document.getElementById('sidebar');

toggleBtn.addEventListener('click', () => {
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
