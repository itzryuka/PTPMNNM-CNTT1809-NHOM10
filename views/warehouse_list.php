<?php
// ===== (1) NẠP KẾT NỐI + HÀM TRUY VẤN =====
// File nằm trong views/ nên lùi 1 cấp: __DIR__ . '/../functions/...'
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/warehouse_functions.php';

$user = requireLogin('../');

// ===== (2) ĐỌC THAM SỐ TỪ URL: ?q=...&status=...&sort=...&dir=...&size=...&page=... =====
[$keyword, $pageSize, $page] = readListParams();
$status = array_key_exists($_GET['status'] ?? '', WAREHOUSE_STATUS_FILTERS) ? $_GET['status'] : 'all';
$sort   = readSort(WAREHOUSE_SORT_COLUMNS, 'code');

// Kho user được phân quyền (requireLogin đọc lại từ CSDL ở mỗi request)
$allowedWarehouses = $user['warehouses'];
// Sửa thông tin kho: quản lý (chỉ kho được giao). Tạo / xóa / mở lại / nhập file kho mới: chỉ quản trị.
// Server kiểm tra lại trong functions/warehouse_functions.php
$canManage = isManager($user);
$isAdmin   = isAdmin($user);
// Lần lưu trước trong popup bị lỗi -> mở lại popup kèm dữ liệu đã nhập và lỗi
$modalState = takeModalState('warehouse_modal');

// ===== (3) TRUY VẤN CSDL =====
$warehouses      = [];
$totalWarehouses = 0;
$dbError         = '';
try {
    $pdo             = getDbConnection();
    $totalWarehouses = countWarehouses($pdo, $keyword, $allowedWarehouses, $status);
    $totalPages      = max(1, (int) ceil($totalWarehouses / $pageSize));
    $page            = min($page, $totalPages);
    $warehouses      = getWarehouses($pdo, $keyword, $pageSize, ($page - 1) * $pageSize, $allowedWarehouses, $status, $sort);
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$exportUrl = '../handle/export.php?' . http_build_query([
    'entity' => 'warehouses', 'q' => $keyword, 'status' => $status, 'sort' => $sort[0], 'dir' => $sort[1],
]);

// ===== (4) GIAO DIỆN =====
$pageTitle  = 'Danh sách Kho';
$basePath   = '../';
$activeMenu = 'warehouses';
require __DIR__ . '/layout/header.php';
?>

            <!-- TOOLBAR -->
            <div class="toolbar">
                <div class="toolbar-top">
                    <a class="btn btn-template" href="../handle/export.php?entity=warehouses&amp;template=1" title="File CSV mẫu để nhập danh sách kho"><i class="fas fa-download"></i> Tải mẫu</a>
                    <a class="btn btn-export" href="<?= e($exportUrl) ?>" title="Xuất danh sách đang lọc ra file CSV (mở bằng Excel)"><i class="fas fa-file-export"></i> Xuất Excel</a>
                    <?php if ($isAdmin): ?>
                        <form class="inline-form" method="post" action="../handle/warehouse_process.php" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="import">
                            <input type="hidden" name="return_query" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
                            <input type="file" name="file" id="importFile" accept=".csv" hidden>
                            <button type="button" class="btn btn-import" data-file-trigger="importFile" title="Nhập file CSV theo mẫu: mã đã có thì cập nhật, mã mới thì thêm"><i class="fas fa-file-excel"></i> Nhập Excel</button>
                        </form>
                        <button class="btn btn-primary" type="button" data-modal-open="warehouseModal" data-modal-title="Thêm kho" data-fill='{"action":"create"}'><i class="fas fa-plus"></i> Thêm Mới</button>
                    <?php endif; ?>
                </div>

                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left">
                        <?php if ($isAdmin): ?>
                            <button class="btn btn-danger hidden" id="btnDeleteSelected" type="submit" form="bulkForm" data-confirm-ok="Xóa"
                                    data-confirm="Xóa các kho đã chọn? Kho đã có lịch sử sẽ được chuyển sang ngừng hoạt động.">
                                <i class="fas fa-trash-alt"></i> Xóa đã chọn
                            </button>
                        <?php endif; ?>
                    </div>
                    <!-- Ô lọc: gửi ?q=...&status=... lên server, server lọc bằng SQL -->
                    <form class="toolbar-bottom-right" method="get">
                        <input type="hidden" name="size" value="<?= $pageSize ?>">
                        <?= sortHiddenInputs($sort) ?>
                        <select class="filter-select" name="status" onchange="this.form.submit()" title="Tình trạng kho">
                            <?php foreach (WAREHOUSE_STATUS_FILTERS as $statusKey => $statusLabel): ?>
                                <option value="<?= $statusKey ?>" <?= $status === $statusKey ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="search-box">
                            <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Lọc theo mã / tên / địa chỉ...">
                            <button type="submit"><i class="fas fa-search" style="color: #94a3b8;"></i></button>
                        </div>
                        <a class="btn btn-icon" href="?size=<?= $pageSize ?>" title="Tải lại"><i class="fas fa-sync-alt"></i></a>
                        <span class="total-count">Tổng số: <strong><?= $totalWarehouses ?></strong></span>
                    </form>
                </div>
            </div>

            <!-- Table Data -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <?php if ($isAdmin): ?>
                                <th style="width: 40px;"><input type="checkbox" id="selectAll"></th>
                            <?php endif; ?>
                            <?= sortHeader('Mã Kho', 'code', $sort) ?>
                            <?= sortHeader('Tên Kho', 'name', $sort) ?>
                            <?= sortHeader('Địa Chỉ', 'address', $sort) ?>
                            <?= sortHeader('Số Mặt Hàng', 'items', $sort, 'text-right', 'desc') ?>
                            <?= sortHeader('Số Lô Đang Tồn', 'batches', $sort, 'text-right', 'desc') ?>
                            <?= sortHeader('Trạng Thái', 'status', $sort, '', 'desc') ?>
                            <th style="text-align: center;">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($warehouses)): ?>
                            <tr class="empty-row">
                                <td colspan="8">Không có kho nào<?= $keyword !== '' ? ' khớp với "' . e($keyword) . '"' : '' ?>.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <?php $warehouseId = (int) $warehouse['id']; ?>
                                <tr class="<?= $warehouse['is_active'] ? '' : 'row-inactive' ?>">
                                    <?php if ($isAdmin): ?>
                                        <td><input type="checkbox" class="row-checkbox" name="ids[]" value="<?= $warehouseId ?>" form="bulkForm"></td>
                                    <?php endif; ?>
                                    <td><?= e($warehouse['code']) ?></td>
                                    <td><a class="link-strong" href="warehouse_detail.php?id=<?= $warehouseId ?>"><?= e($warehouse['name']) ?></a></td>
                                    <td><?= $warehouse['address'] !== null ? e($warehouse['address']) : '<span style="color:#94a3b8">—</span>' ?></td>
                                    <td class="text-right"><?= (int) $warehouse['item_count'] ?></td>
                                    <td class="text-right"><?= (int) $warehouse['batch_count'] ?></td>
                                    <td>
                                        <?php if ($warehouse['is_active']): ?>
                                            <span class="badge badge-active">Hoạt động</span>
                                        <?php else: ?>
                                            <span class="badge badge-inactive">Ngừng hoạt động</span>
                                        <?php endif; ?>
                                        <?php if ($warehouse['open_count_code']): ?>
                                            <span class="badge badge-pending" title="Đợt <?= e($warehouse['open_count_code']) ?>: kho tạm thời không nhận / xuất / gửi / giữ hàng được"><i class="fas fa-snowflake"></i> Đang kiểm kê</span>
                                        <?php endif; ?>
                                        <?php if ((int) $warehouse['pending_count'] > 0): ?>
                                            <span class="badge badge-pending"><?= (int) $warehouse['pending_count'] ?> phiếu chờ duyệt</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="row-actions">
                                        <a href="warehouse_detail.php?id=<?= $warehouseId ?>" title="Xem chi tiết"><i class="fas fa-eye icon-view"></i></a>
                                        <?php if ($canManage): ?>
                                            <?php $fill = ['action' => 'update', 'id' => $warehouseId, 'code' => $warehouse['code'], 'name' => $warehouse['name'], 'address' => $warehouse['address'] ?? '']; ?>
                                            <button class="icon-btn" type="button" title="Chỉnh sửa" data-modal-open="warehouseModal"
                                                    data-modal-title="Sửa <?= e($warehouse['name']) ?>" data-fill="<?= e(json_encode($fill, JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-edit icon-edit"></i></button>
                                            <?php if ($isAdmin && $warehouse['is_active']): ?>
                                                <button class="icon-btn" type="submit" form="deleteForm" name="id" value="<?= $warehouseId ?>" title="Xóa" data-confirm-ok="Xóa"
                                                        data-confirm="Xóa <?= e($warehouse['name']) ?>? Nếu kho đã có lịch sử chứng từ, kho sẽ được chuyển sang ngừng hoạt động."><i class="fas fa-trash-alt icon-delete"></i></button>
                                            <?php elseif ($isAdmin): ?>
                                                <button class="icon-btn restore" type="submit" form="restoreForm" name="id" value="<?= $warehouseId ?>" title="Hoạt động lại"><i class="fas fa-undo"></i></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($canManage): ?>
                <!-- Form ẩn cho nút xóa / hoạt động lại / xóa nhiều (nút trong bảng gắn vào bằng thuộc tính form="..."); chỉ quản trị -->
                <?php if ($isAdmin): ?>
                <?php foreach (['bulkForm' => 'bulk_delete', 'deleteForm' => 'delete', 'restoreForm' => 'restore'] as $formId => $formAction): ?>
                    <form id="<?= $formId ?>" method="post" action="../handle/warehouse_process.php">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="<?= $formAction ?>">
                        <input type="hidden" name="return_query" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
                    </form>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php $modalReturnTo = 'list'; require __DIR__ . '/partials/warehouse_modal.php'; ?>
            <?php endif; ?>

            <!-- Pagination -->
<?php require __DIR__ . '/layout/pagination.php'; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
