<?php
// ===== (1) NẠP KẾT NỐI + HÀM TRUY VẤN =====
// File nằm trong views/ nên lùi 1 cấp: __DIR__ . '/../functions/...'
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/item_functions.php';

$user = requireLogin('../');

// ===== (2) ĐỌC THAM SỐ TỪ URL: ?q=...&size=...&page=... =====
[$keyword, $pageSize, $page] = readListParams();

// Kho user được phân quyền (requireLogin đọc lại từ CSDL ở mỗi request)
$allowedWarehouses = $user['warehouses'];

// ===== (3) TRUY VẤN CSDL =====
$items      = [];
$totalItems = 0;
$dbError    = '';
try {
    $pdo        = getDbConnection();
    $totalItems = countItems($pdo, $keyword);
    $totalPages = max(1, (int) ceil($totalItems / $pageSize));
    $page       = min($page, $totalPages);
    $items      = getItems($pdo, $keyword, $pageSize, ($page - 1) * $pageSize, $allowedWarehouses);
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

// ===== (4) GIAO DIỆN =====
$pageTitle  = 'Danh sách Mặt Hàng';
$basePath   = '../';
$activeMenu = 'items';
require __DIR__ . '/layout/header.php';
?>

            <!-- TOOLBAR -->
            <div class="toolbar">
                <div class="toolbar-top">
                    <button class="btn btn-template"><i class="fas fa-download"></i> Tải mẫu</button>
                    <button class="btn btn-export"><i class="fas fa-file-export"></i> Xuất Excel</button>
                    <button class="btn btn-import"><i class="fas fa-file-excel"></i> Nhập Excel</button>
                    <button class="btn btn-primary"><i class="fas fa-plus"></i> Thêm Mới</button>
                </div>

                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left">
                        <button class="btn btn-danger hidden" id="btnDeleteSelected">
                            <i class="fas fa-trash-alt"></i> Xóa đã chọn
                        </button>
                    </div>
                    <div class="toolbar-bottom-right">
                        <!-- Ô lọc: gửi ?q=... lên server, server lọc bằng SQL LIKE -->
                        <form class="search-box" method="get">
                            <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Lọc theo mã / tên hàng...">
                            <input type="hidden" name="size" value="<?= $pageSize ?>">
                            <button type="submit"><i class="fas fa-search" style="color: #94a3b8;"></i></button>
                        </form>
                        <a class="btn btn-icon" href="?size=<?= $pageSize ?>" title="Tải lại"><i class="fas fa-sync-alt"></i></a>
                        <span class="total-count">Tổng số: <strong><?= $totalItems ?></strong></span>
                    </div>
                </div>
            </div>

            <!-- Table Data -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="selectAll"></th>
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
                                <tr>
                                    <td><input type="checkbox" class="row-checkbox" value="<?= (int) $item['id'] ?>"></td>
                                    <td><?= e($item['sku']) ?></td>
                                    <td><?= e($item['name']) ?></td>
                                    <td><?= e($item['unit']) ?></td>
                                    <td><?= $item['batch_list'] !== null ? e($item['batch_list']) : '<span style="color:#94a3b8">—</span>' ?></td>
                                    <td class="text-right"><?= formatQty($item['total_qty']) ?></td>
                                    <td class="row-actions" data-id="<?= (int) $item['id'] ?>">
                                        <i class="fas fa-eye icon-view" title="Xem chi tiết"></i>
                                        <i class="fas fa-edit icon-edit" title="Chỉnh sửa"></i>
                                        <i class="fas fa-trash-alt icon-delete" title="Xóa"></i>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
<?php require __DIR__ . '/layout/pagination.php'; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
