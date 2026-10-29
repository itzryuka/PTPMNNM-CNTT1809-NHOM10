<?php
// Quản lý tài khoản (chỉ quản trị): cấp tài khoản cho nhân viên mới, sửa vai trò / kho, khóa khi nghỉ việc, đặt lại mật khẩu
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/account_functions.php';

$user = requireLogin('../');
if (!isAdmin($user)) {
    setFlash('error', 'Chỉ quản trị hệ thống mới vào được trang Quản lý tài khoản.');
    redirect('../index.php');
}

[$keyword, $pageSize, $page] = readListParams();
$filters = [
    'keyword' => $keyword,
    'role'    => in_array($_GET['role'] ?? '', ['ADMIN', 'MANAGER', 'STAFF'], true) ? $_GET['role'] : '',
    'status'  => array_key_exists($_GET['status'] ?? '', ACCOUNT_STATUS_FILTERS) ? $_GET['status'] : 'active',
];
$sort       = readSort(ACCOUNT_SORT_COLUMNS, 'role');
$modalState = takeModalState('account_modal');
$secret     = $_SESSION['account_secret'] ?? null;      // mật khẩu tạm vừa sinh: hiện đúng 1 lần
unset($_SESSION['account_secret']);

$accounts         = [];
$warehouseOptions = [];
$noManager        = [];
$totalAccounts    = 0;
$dbError          = '';
try {
    $pdo              = getDbConnection();
    $warehouseOptions = assignableWarehouses($pdo);
    $noManager        = warehousesWithoutManager($pdo);
    $totalAccounts    = countAccounts($pdo, $filters);
    $totalPages       = max(1, (int) ceil($totalAccounts / $pageSize));
    $page             = min($page, $totalPages);
    $accounts         = getAccounts($pdo, $filters, $pageSize, ($page - 1) * $pageSize, $sort);
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$pageTitle  = 'Tài khoản & phân quyền kho';
$basePath   = '../';
$activeMenu = 'users';
require __DIR__ . '/layout/header.php';
?>

            <?php if ($noManager): ?>
                <div class="alert-warning" style="margin-bottom: 16px;"><i class="fas fa-exclamation-triangle"></i>
                    Chưa có quản lý kho nào phụ trách: <strong><?= e(implode(', ', array_map(fn ($w) => $w['code'] . ' - ' . $w['name'], $noManager))) ?></strong>.
                    Phiếu của kho này chỉ quản trị duyệt được. Bấm sửa một tài khoản quản lý và tick thêm kho.</div>
            <?php endif; ?>

            <div class="toolbar">
                <div class="toolbar-top">
                    <span class="muted toolbar-note"><i class="fas fa-info-circle"></i> Nhân viên không tự đăng ký được: quản trị cấp tài khoản kèm mật khẩu tạm, lần đầu đăng nhập phải đổi.</span>
                    <button class="btn btn-primary" type="button" data-modal-open="accountModal" data-modal-title="Cấp tài khoản mới"
                            data-fill='{"action":"create","role":"STAFF"}'><i class="fas fa-user-plus"></i> Cấp tài khoản</button>
                </div>
                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left"></div>
                    <form class="toolbar-bottom-right" method="get">
                        <input type="hidden" name="size" value="<?= $pageSize ?>">
                        <?= sortHiddenInputs($sort) ?>
                        <select class="filter-select" name="role" onchange="this.form.submit()">
                            <option value="">Mọi vai trò</option>
                            <?php foreach (['ADMIN', 'MANAGER', 'STAFF'] as $role): ?>
                                <option value="<?= $role ?>" <?= $filters['role'] === $role ? 'selected' : '' ?>><?= e(roleLabel($role)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" name="status" onchange="this.form.submit()">
                            <?php foreach (ACCOUNT_STATUS_FILTERS as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="search-box">
                            <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Tên đăng nhập / họ tên...">
                            <button type="submit"><i class="fas fa-search" style="color: #94a3b8;"></i></button>
                        </div>
                        <a class="btn btn-icon" href="account_list.php" title="Bỏ lọc"><i class="fas fa-sync-alt"></i></a>
                        <span class="total-count">Tổng số: <strong><?= $totalAccounts ?></strong></span>
                    </form>
                </div>
            </div>

            <div class="table-container">
                <table class="nowrap-table">
                    <thead>
                        <tr>
                            <?= sortHeader('Tên Đăng Nhập', 'username', $sort) ?>
                            <?= sortHeader('Họ Tên', 'name', $sort) ?>
                            <?= sortHeader('Vai Trò', 'role', $sort) ?>
                            <th>Kho Phụ Trách</th>
                            <th>Trạng Thái</th>
                            <?= sortHeader('Đăng Nhập Gần Nhất', 'last_login', $sort, '', 'desc') ?>
                            <th style="text-align: center;">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($accounts)): ?>
                            <tr class="empty-row"><td colspan="7">Không có tài khoản nào khớp bộ lọc.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($accounts as $account): ?>
                            <?php
                            $accountId = (int) $account['id'];
                            $isAdminRow = $account['role'] === 'ADMIN';
                            $fill = ['action' => 'update', 'id' => $accountId, 'username' => $account['username'], 'full_name' => $account['full_name'],
                                     'role' => $account['role'], 'warehouses' => $account['warehouse_ids'] ? array_map('intval', explode(',', $account['warehouse_ids'])) : []];
                            ?>
                            <tr class="<?= $account['is_active'] ? '' : 'row-inactive' ?>">
                                <td><strong><?= e($account['username']) ?></strong></td>
                                <td><?= e($account['full_name']) ?></td>
                                <td><span class="badge <?= ['ADMIN' => 'badge-rejected', 'MANAGER' => 'badge-info', 'STAFF' => 'badge-step'][$account['role']] ?>"><?= e(roleLabel($account['role'])) ?></span></td>
                                <td><?= $isAdminRow ? '<span class="muted">Mọi kho</span>' : ($account['warehouse_codes'] ? e($account['warehouse_codes']) : '<span class="badge badge-pending">Chưa giao kho</span>') ?></td>
                                <td>
                                    <?php if (!$account['is_active']): ?><span class="badge badge-inactive">Đã khóa</span>
                                    <?php elseif ($account['must_change_password']): ?><span class="badge badge-warn">Chờ đổi mật khẩu tạm</span>
                                    <?php else: ?><span class="badge badge-active">Hoạt động</span><?php endif; ?>
                                </td>
                                <td><?= $account['last_login_at'] ? e(date('d/m/Y H:i', strtotime($account['last_login_at']))) : '<span class="muted">Chưa đăng nhập</span>' ?></td>
                                <td class="row-actions">
                                    <?php if ($isAdminRow): ?>
                                        <span class="muted" title="Tài khoản quản trị không sửa / khóa qua giao diện"><i class="fas fa-lock"></i></span>
                                    <?php else: ?>
                                        <button class="icon-btn" type="button" title="Sửa vai trò / kho" data-modal-open="accountModal" data-modal-title="Sửa tài khoản <?= e($account['username']) ?>"
                                                data-fill="<?= e(json_encode($fill, JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-edit icon-edit"></i></button>
                                        <button class="icon-btn" type="submit" form="resetForm" name="id" value="<?= $accountId ?>" title="Đặt lại mật khẩu" style="color: #c2410c;"
                                                data-confirm-title="Đặt lại mật khẩu" data-confirm-ok="Đặt lại" data-confirm-variant="primary"
                                                data-confirm="Đặt lại mật khẩu cho <?= e($account['username']) ?>? Mật khẩu cũ hết tác dụng; hệ thống sinh mật khẩu tạm mới."><i class="fas fa-key"></i></button>
                                        <?php if ($account['is_active']): ?>
                                            <button class="icon-btn" type="submit" form="lockForm" name="id" value="<?= $accountId ?>" title="Khóa (nghỉ việc)" data-confirm-ok="Khóa"
                                                    data-confirm="Khóa tài khoản <?= e($account['username']) ?>? Người này không đăng nhập được nữa; chứng từ cũ vẫn giữ tên họ."><i class="fas fa-user-lock icon-delete"></i></button>
                                        <?php else: ?>
                                            <button class="icon-btn restore" type="submit" form="unlockForm" name="id" value="<?= $accountId ?>" title="Mở khóa"><i class="fas fa-unlock"></i></button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php foreach (['lockForm' => 'lock', 'unlockForm' => 'unlock', 'resetForm' => 'reset_password'] as $formId => $formAction): ?>
                <form id="<?= $formId ?>" method="post" action="../handle/account_process.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="<?= $formAction ?>">
                    <input type="hidden" name="return_query" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
                </form>
            <?php endforeach; ?>

            <?php require __DIR__ . '/partials/account_modal.php'; ?>

            <?php if ($secret): ?>
                <!-- Mật khẩu tạm: hiện đúng 1 lần, không lưu ở đâu khác -->
                <div class="modal-backdrop open" id="secretModal">
                    <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="secretTitle">
                        <div class="modal-header">
                            <h3 id="secretTitle"><?= e($secret['why']) ?></h3>
                            <button class="modal-close" type="button" data-modal-close title="Đóng">&times;</button>
                        </div>
                        <div class="modal-body">
                            <dl class="secret-box">
                                <dt>Tên đăng nhập</dt><dd><code><?= e($secret['username']) ?></code></dd>
                                <dt>Mật khẩu tạm</dt><dd><code id="tempPassword"><?= e($secret['password']) ?></code></dd>
                            </dl>
                            <p class="form-hint">Hãy đưa trực tiếp cho người dùng. Lần đầu đăng nhập họ <strong>bắt buộc đổi mật khẩu</strong>. Mật khẩu tạm này <strong>sẽ không hiện lại</strong> sau khi đóng hộp thoại.</p>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-light" type="button" id="copyTempPassword"><i class="fas fa-copy"></i> Sao chép</button>
                            <button class="btn btn-primary" type="button" data-modal-close>Đã ghi lại</button>
                        </div>
                    </div>
                </div>
                <script>
                    document.getElementById('copyTempPassword').addEventListener('click', event => {
                        const button = event.currentTarget;
                        const text = document.getElementById('tempPassword').textContent;
                        navigator.clipboard.writeText(text).then(() => { button.innerHTML = '<i class="fas fa-check"></i> Đã sao chép'; }, () => {
                            getSelection().selectAllChildren(document.getElementById('tempPassword'));
                        });
                    });
                </script>
            <?php endif; ?>

<?php require __DIR__ . '/layout/pagination.php'; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
