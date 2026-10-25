<?php
// ===== (1) NẠP KẾT NỐI + HÀM TRUY VẤN =====
// File nằm trong views/ nên lùi 1 cấp: __DIR__ . '/../functions/...'
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/item_functions.php';

$user = requireLogin('../');

// ===== (2) ĐỌC THAM SỐ TỪ URL: ?q=...&status=...&size=...&page=... =====
[$keyword, $pageSize, $page] = readListParams();
$status = in_array($_GET['status'] ?? '', ['active', 'inactive', 'all'], true) ? $_GET['status'] : 'active';

// Kho user được phân quyền (requireLogin đọc lại từ CSDL ở mỗi request)
$allowedWarehouses = $user['warehouses'];
// Thêm / sửa / xóa / nhập file: chỉ quản lý (server cũng kiểm tra lại trong handle/item_process.php)
$canManage = isManager($user);

// ===== (3) TRUY VẤN CSDL =====
$items      = [];
$totalItems = 0;
$dbError    = '';
try {
    $pdo        = getDbConnection();
    $totalItems = countItems($pdo, $keyword, $status);
    $totalPages = max(1, (int) ceil($totalItems / $pageSize));
    $page       = min($page, $totalPages);
    $items      = getItems($pdo, $keyword, $pageSize, ($page - 1) * $pageSize, $allowedWarehouses, $status);
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$exportUrl = '../handle/export.php?' . http_build_query(['entity' => 'items', 'q' => $keyword, 'status' => $status]);

// ===== (4) GIAO DIỆN =====
$pageTitle  = 'Danh sách Mặt Hàng';
$basePath   = '../';
$activeMenu = 'items';
require __DIR__ . '/layout/header.php';
?>

            <!-- TOOLBAR -->
            <div class="toolbar">
                <div class="toolbar-top">
                    <a class="btn btn-template" href="../handle/export.php?entity=items&amp;template=1" title="File CSV mẫu để nhập danh mục"><i class="fas fa-download"></i> Tải mẫu</a>
                    <a class="btn btn-export" href="<?= e($exportUrl) ?>" title="Xuất danh sách đang lọc ra file CSV (mở bằng Excel)"><i class="fas fa-file-export"></i> Xuất Excel</a>
                    <?php if ($canManage): ?>
                        <form class="inline-form" method="post" action="../handle/item_process.php" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="import">
                            <input type="file" name="file" id="importFile" accept=".csv" hidden>
                            <button type="button" class="btn btn-import" data-file-trigger="importFile" title="Nhập file CSV theo mẫu: mã đã có thì cập nhật, mã mới thì thêm"><i class="fas fa-file-excel"></i> Nhập Excel</button>
                        </form>
                        <a class="btn btn-primary" href="item_form.php"><i class="fas fa-plus"></i> Thêm Mới</a>
                    <?php endif; ?>
                </div>

                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left">
                        <?php if ($canManage): ?>
                            <button class="btn btn-danger hidden" id="btnDeleteSelected" type="submit" form="bulkForm"
                                    data-confirm="Xóa các mặt hàng đã chọn? Mặt hàng đã có lịch sử sẽ được chuyển sang ngừng dùng.">
                                <i class="fas fa-trash-alt"></i> Xóa đã chọn
                            </button>
                        <?php endif; ?>
                    </div>
                    <!-- Ô lọc: gửi ?q=...&status=... lên server, server lọc bằng SQL -->
                    <form class="toolbar-bottom-right" method="get">
                        <input type="hidden" name="size" value="<?= $pageSize ?>">
                        <select class="filter-select" name="status" onchange="this.form.submit()">
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Đang dùng</option>
                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Ngừng dùng</option>
                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Tất cả</option>
                        </select>
                        <div class="search-box">
                            <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Lọc theo mã / tên hàng...">
                            <button type="submit"><i class="fas fa-search" style="color: #94a3b8;"></i></button>
                        </div>
                        <a class="btn btn-icon" href="?size=<?= $pageSize ?>" title="Tải lại"><i class="fas fa-sync-alt"></i></a>
                        <span class="total-count">Tổng số: <strong><?= $totalItems ?></strong></span>
                    </form>
                </div>
            </div>

            <!-- Table Data -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <?php if ($canManage): ?>
                                <th style="width: 40px;"><input type="checkbox" id="selectAll"></th>
                            <?php endif; ?>
                            <th>Mã Hàng</th>
                            <th>Tên Mặt Hàng</th>
                            <th>Đơn Vị Tính</th>
                            <th>Số Lô Hiện Tại</th>
                            <th class="text-right">Tổng Tồn</th>
                            <th style="text-align: center;">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr class="empty-row">
                                <td colspan="7">Không có mặt hàng nào<?= $keyword !== '' ? ' khớp với "' . e($keyword) . '"' : '' ?>.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php $itemId = (int) $item['id']; ?>
                                <tr class="<?= $item['is_active'] ? '' : 'row-inactive' ?>">
                                    <?php if ($canManage): ?>
                                        <td><input type="checkbox" class="row-checkbox" name="ids[]" value="<?= $itemId ?>" form="bulkForm"></td>
                                    <?php endif; ?>
                                    <td><?= e($item['sku']) ?></td>
                                    <td>
                                        <a class="link-strong" href="item_detail.php?id=<?= $itemId ?>"><?= e($item['name']) ?></a>
                                        <?php if (!$item['is_active']): ?> <span class="badge badge-inactive">Ngừng dùng</span><?php endif; ?>
                                    </td>
                                    <td><?= e($item['unit']) ?></td>
                                    <td><?= $item['batch_list'] !== null ? e($item['batch_list']) : '<span style="color:#94a3b8">—</span>' ?></td>
                                    <td class="text-right"><?= formatQty($item['total_qty']) ?></td>
                                    <td class="row-actions">
                                        <a href="item_detail.php?id=<?= $itemId ?>" title="Xem chi tiết"><i class="fas fa-eye icon-view"></i></a>
                                        <?php if ($canManage): ?>
                                            <a href="item_form.php?id=<?= $itemId ?>" title="Chỉnh sửa"><i class="fas fa-edit icon-edit"></i></a>
                                            <?php if ($item['is_active']): ?>
                                                <button class="icon-btn" type="submit" form="deleteForm" name="id" value="<?= $itemId ?>" title="Xóa"
                                                        data-confirm="Xóa mặt hàng <?= e($item['sku']) ?>? Nếu đã có lịch sử nhập / xuất, mặt hàng sẽ được chuyển sang ngừng dùng."><i class="fas fa-trash-alt icon-delete"></i></button>
                                            <?php else: ?>
                                                <button class="icon-btn restore" type="submit" form="restoreForm" name="id" value="<?= $itemId ?>" title="Dùng lại"><i class="fas fa-undo"></i></button>
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
                <!-- Form ẩn cho nút xóa / dùng lại / xóa nhiều (nút trong bảng gắn vào bằng thuộc tính form="...") -->
                <?php foreach (['bulkForm' => 'bulk_delete', 'deleteForm' => 'delete', 'restoreForm' => 'restore'] as $formId => $formAction): ?>
                    <form id="<?= $formId ?>" method="post" action="../handle/item_process.php">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="<?= $formAction ?>">
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Pagination -->
<?php require __DIR__ . '/layout/pagination.php'; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
