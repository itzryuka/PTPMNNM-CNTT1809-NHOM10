<?php
// ===== (1) NẠP KẾT NỐI + HÀM TRUY VẤN =====
// File nằm trong views/ nên lùi 1 cấp: __DIR__ . '/../functions/...'
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/item_functions.php';
require_once __DIR__ . '/../functions/voucher_functions.php';   // getWarehouseOptions()

$user = requireLogin('../');

// ===== (2) ĐỌC THAM SỐ TỪ URL: ?q=...&status=...&warehouse=...&sort=...&dir=...&size=...&page=... =====
[$keyword, $pageSize, $page] = readListParams();
$status      = array_key_exists($_GET['status'] ?? '', ITEM_STATUS_FILTERS) ? $_GET['status'] : 'active';
$warehouseId = (int) ($_GET['warehouse'] ?? 0);
$sort        = readSort(ITEM_SORT_COLUMNS, 'sku');

// Kho user được phân quyền (requireLogin đọc lại từ CSDL ở mỗi request)
$allowedWarehouses = $user['warehouses'];
// Thêm / sửa / xóa / nhập file: chỉ quản lý (server cũng kiểm tra lại trong handle/item_process.php)
$canManage = isManager($user);
// Lần lưu trước trong popup bị lỗi -> mở lại popup kèm dữ liệu đã nhập và lỗi
$modalState = takeModalState('item_modal');

// ===== (3) TRUY VẤN CSDL =====
$items      = [];
$units      = [];
$warehouses = [];
$nextSku    = '';
$totalItems = 0;
$dbError    = '';
try {
    $pdo        = getDbConnection();
    $units      = getUnitSuggestions($pdo);
    $warehouses = getWarehouseOptions($pdo, $allowedWarehouses);
    $nextSku    = $canManage ? nextCatalogCode($pdo, 'items', 'SP') : '';   // điền sẵn mã gợi ý khi thêm mới
    $totalItems = countItems($pdo, $keyword, $status, $allowedWarehouses, $warehouseId);
    $totalPages = max(1, (int) ceil($totalItems / $pageSize));
    $page       = min($page, $totalPages);
    $items      = getItems($pdo, $keyword, $pageSize, ($page - 1) * $pageSize, $allowedWarehouses, $status, $warehouseId, $sort);
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$exportUrl = '../handle/export.php?' . http_build_query([
    'entity' => 'items', 'q' => $keyword, 'status' => $status, 'warehouse' => $warehouseId, 'sort' => $sort[0], 'dir' => $sort[1],
]);

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
                            <input type="hidden" name="return_query" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
                            <input type="file" name="file" id="importFile" accept=".csv" hidden>
                            <button type="button" class="btn btn-import" data-file-trigger="importFile" title="Nhập file CSV theo mẫu: mã đã có thì cập nhật, mã mới thì thêm"><i class="fas fa-file-excel"></i> Nhập Excel</button>
                        </form>
                        <button class="btn btn-primary" type="button" data-modal-open="itemModal" data-modal-title="Thêm mặt hàng"
                                data-fill="<?= e(json_encode(['action' => 'create', 'sku' => $nextSku])) ?>"><i class="fas fa-plus"></i> Thêm Mới</button>
                    <?php endif; ?>
                </div>

                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left">
                        <?php if ($canManage): ?>
                            <button class="btn btn-danger hidden" id="btnDeleteSelected" type="submit" form="bulkForm" data-confirm-ok="Xóa"
                                    data-confirm="Xóa các mặt hàng đã chọn? Mặt hàng đã có lịch sử sẽ được chuyển sang ngừng dùng.">
                                <i class="fas fa-trash-alt"></i> Xóa đã chọn
                            </button>
                        <?php endif; ?>
                    </div>
                    <!-- Ô lọc: gửi ?q=...&status=...&warehouse=... lên server, server lọc bằng SQL -->
                    <form class="toolbar-bottom-right" method="get">
                        <input type="hidden" name="size" value="<?= $pageSize ?>">
                        <?= sortHiddenInputs($sort) ?>
                        <select class="filter-select" name="status" onchange="this.form.submit()" title="Tình trạng mặt hàng / tồn kho">
                            <?php foreach (ITEM_STATUS_FILTERS as $statusKey => $statusLabel): ?>
                                <option value="<?= $statusKey ?>" <?= $status === $statusKey ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" name="warehouse" onchange="this.form.submit()" title="Tồn kho, số lô và lọc tồn chỉ tính trong kho này">
                            <option value="0">Tất cả kho</option>
                            <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $warehouseId ? 'selected' : '' ?>><?= e($warehouse['name']) ?></option>
                            <?php endforeach; ?>
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
                            <?= sortHeader('Mã Hàng', 'sku', $sort) ?>
                            <?= sortHeader('Tên Mặt Hàng', 'name', $sort) ?>
                            <?= sortHeader('Đơn Vị Tính', 'unit', $sort) ?>
                            <?= sortHeader('Số Lô Hiện Tại', 'batches', $sort, '', 'desc') ?>
                            <?= sortHeader($warehouseId > 0 ? 'Tồn Tại Kho' : 'Tổng Tồn', 'total_qty', $sort, 'text-right', 'desc') ?>
                            <th style="text-align: center;">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr class="empty-row">
                                <td colspan="7">Không có mặt hàng nào<?= $keyword !== '' ? ' khớp với "' . e($keyword) . '"' : '' ?> (lọc: <?= e(mb_strtolower(ITEM_STATUS_FILTERS[$status])) ?>).</td>
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
                                    <td class="text-right"><?= (float) $item['total_qty'] > 0 ? formatQty($item['total_qty']) : '<span class="badge badge-inactive">Hết hàng</span>' ?></td>
                                    <td class="row-actions">
                                        <a href="item_detail.php?id=<?= $itemId ?>" title="Xem chi tiết"><i class="fas fa-eye icon-view"></i></a>
                                        <?php if ($canManage): ?>
                                            <?php $fill = ['action' => 'update', 'id' => $itemId, 'sku' => $item['sku'], 'name' => $item['name'], 'unit' => $item['unit'], 'description' => $item['description'] ?? '']; ?>
                                            <button class="icon-btn" type="button" title="Chỉnh sửa" data-modal-open="itemModal"
                                                    data-modal-title="Sửa mặt hàng <?= e($item['sku']) ?>" data-fill="<?= e(json_encode($fill, JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-edit icon-edit"></i></button>
                                            <?php if ($item['is_active']): ?>
                                                <button class="icon-btn" type="submit" form="deleteForm" name="id" value="<?= $itemId ?>" title="Xóa" data-confirm-ok="Xóa"
                                                        data-confirm="Xóa mặt hàng <?= e($item['sku'] . ' - ' . $item['name']) ?>? Nếu đã có lịch sử nhập / xuất, mặt hàng sẽ được chuyển sang ngừng dùng."><i class="fas fa-trash-alt icon-delete"></i></button>
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
                        <input type="hidden" name="return_query" value="<?= e($_SERVER['QUERY_STRING'] ?? '') ?>">
                    </form>
                <?php endforeach; ?>

                <?php $modalReturnTo = 'list'; require __DIR__ . '/partials/item_modal.php'; ?>
            <?php endif; ?>

            <!-- Pagination -->
<?php require __DIR__ . '/layout/pagination.php'; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
