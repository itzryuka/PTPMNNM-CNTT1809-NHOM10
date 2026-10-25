<?php
// ===== (1) NẠP KẾT NỐI + HÀM TRUY VẤN =====
// File nằm trong views/ nên lùi 1 cấp: __DIR__ . '/../functions/...'
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/warehouse_functions.php';

$user = requireLogin('../');

// ===== (2) ĐỌC THAM SỐ TỪ URL: ?q=...&size=...&page=... =====
[$keyword, $pageSize, $page] = readListParams();

// Kho user được phân quyền (requireLogin đọc lại từ CSDL ở mỗi request)
$allowedWarehouses = $user['warehouses'];

// ===== (3) TRUY VẤN CSDL =====
$warehouses      = [];
$totalWarehouses = 0;
$dbError         = '';
try {
    $pdo             = getDbConnection();
    $totalWarehouses = countWarehouses($pdo, $keyword, $allowedWarehouses);
    $totalPages      = max(1, (int) ceil($totalWarehouses / $pageSize));
    $page            = min($page, $totalPages);
    $warehouses      = getWarehouses($pdo, $keyword, $pageSize, ($page - 1) * $pageSize, $allowedWarehouses);
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

// ===== (4) GIAO DIỆN =====
$pageTitle  = 'Danh sách Kho';
$basePath   = '../';
$activeMenu = 'warehouses';
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
                            <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Lọc theo mã / tên / địa chỉ...">
                            <input type="hidden" name="size" value="<?= $pageSize ?>">
                            <button type="submit"><i class="fas fa-search" style="color: #94a3b8;"></i></button>
                        </form>
                        <a class="btn btn-icon" href="?size=<?= $pageSize ?>" title="Tải lại"><i class="fas fa-sync-alt"></i></a>
                        <span class="total-count">Tổng số: <strong><?= $totalWarehouses ?></strong></span>
                    </div>
                </div>
            </div>

            <!-- Table Data -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="selectAll"></th>
                            <th>Mã Kho</th>
                            <th>Tên Kho</th>
                            <th>Địa Chỉ</th>
                            <th class="text-right">Số Mặt Hàng</th>
                            <th class="text-right">Số Lô Đang Tồn</th>
                            <th>Trạng Thái</th>
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
                                <tr>
                                    <td><input type="checkbox" class="row-checkbox" value="<?= (int) $warehouse['id'] ?>"></td>
                                    <td><?= e($warehouse['code']) ?></td>
                                    <td><?= e($warehouse['name']) ?></td>
                                    <td><?= $warehouse['address'] !== null ? e($warehouse['address']) : '<span style="color:#94a3b8">—</span>' ?></td>
                                    <td class="text-right"><?= (int) $warehouse['item_count'] ?></td>
                                    <td class="text-right"><?= (int) $warehouse['batch_count'] ?></td>
                                    <td>
                                        <?php if ($warehouse['is_active']): ?>
                                            <span class="badge badge-active">Hoạt động</span>
                                        <?php else: ?>
                                            <span class="badge badge-inactive">Ngừng hoạt động</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="row-actions" data-id="<?= (int) $warehouse['id'] ?>">
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
