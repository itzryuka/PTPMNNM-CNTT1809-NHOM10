<?php
// ===== TRANG TỔNG QUAN (DASHBOARD) — trang chủ tạm thời =====
require_once __DIR__ . '/functions/db_connection.php';
require_once __DIR__ . '/functions/helpers.php';
require_once __DIR__ . '/functions/auth.php';
require_once __DIR__ . '/functions/dashboard_functions.php';

$user = requireLogin('');

// Kho user được phân quyền (requireLogin đọc lại từ CSDL ở mỗi request)
$allowedWarehouses = $user['warehouses'];

$stats          = ['warehouses' => 0, 'items' => 0, 'pending' => 0, 'movements' => 0];
$recentVouchers = [];
$expiring       = [];
$reconTotal     = 0;
$reconMismatch  = 0;
$dbError        = '';
try {
    $pdo            = getDbConnection();
    $stats          = getDashboardStats($pdo, $allowedWarehouses);
    $recentVouchers = getRecentVouchers($pdo, $allowedWarehouses);
    $expiring       = getExpiringBatches($pdo, $allowedWarehouses);
    [$reconTotal, $reconMismatch] = getReconciliationSummary($pdo, $allowedWarehouses);
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$pageTitle  = 'Tổng quan';
$basePath   = '';
$activeMenu = 'dashboard';
require __DIR__ . '/views/layout/header.php';
?>

            <!-- CHỈ SỐ NHANH -->
            <div class="stat-grid">
                <a class="stat-card" href="views/warehouse_list.php">
                    <div class="stat-icon blue"><i class="fas fa-warehouse"></i></div>
                    <div>
                        <div class="stat-label">Kho đang hoạt động</div>
                        <div class="stat-value"><?= $stats['warehouses'] ?></div>
                    </div>
                </a>
                <a class="stat-card" href="views/item_list.php">
                    <div class="stat-icon green"><i class="fas fa-box"></i></div>
                    <div>
                        <div class="stat-label">Mặt hàng</div>
                        <div class="stat-value"><?= $stats['items'] ?></div>
                    </div>
                </a>
                <div class="stat-card">
                    <div class="stat-icon amber"><i class="fas fa-hourglass-half"></i></div>
                    <div>
                        <div class="stat-label">Phiếu chờ duyệt</div>
                        <div class="stat-value"><?= $stats['pending'] ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon slate"><i class="fas fa-exchange-alt"></i></div>
                    <div>
                        <div class="stat-label">Phát sinh 30 ngày qua</div>
                        <div class="stat-value"><?= $stats['movements'] ?></div>
                    </div>
                </div>
            </div>

            <div class="dash-grid">
                <!-- CHỨNG TỪ GẦN ĐÂY -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-file-invoice"></i> Chứng từ gần đây</h3>
                    </div>
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Mã phiếu</th>
                                    <th>Loại</th>
                                    <th>Kho</th>
                                    <th>Ngày lập</th>
                                    <th class="text-right">Số dòng</th>
                                    <th>Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentVouchers)): ?>
                                    <tr class="empty-row"><td colspan="6">Chưa có chứng từ nào.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($recentVouchers as $voucher): ?>
                                    <tr>
                                        <td><strong><?= e($voucher['code']) ?></strong></td>
                                        <td><?= e(voucherTypeLabel($voucher['type'])) ?></td>
                                        <td>
                                            <?= e($voucher['warehouse_code']) ?>
                                            <?php if ($voucher['to_warehouse_code'] !== null): ?>
                                                <i class="fas fa-arrow-right muted"></i> <?= e($voucher['to_warehouse_code']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= formatDate($voucher['voucher_date']) ?></td>
                                        <td class="text-right"><?= (int) $voucher['line_count'] ?></td>
                                        <td><?= voucherStatusBadge($voucher['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- LÔ SẮP HẾT HẠN -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-calendar-times"></i> Lô hết hạn / sắp hết hạn (60 ngày)</h3>
                    </div>
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Mặt hàng / Lô</th>
                                    <th>Kho</th>
                                    <th>Hạn dùng</th>
                                    <th class="text-right">Tồn</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($expiring)): ?>
                                    <tr class="empty-row"><td colspan="4">Không có lô nào sắp hết hạn.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($expiring as $batch): ?>
                                    <tr>
                                        <td class="wrap">
                                            <?= e($batch['item_name']) ?><br>
                                            <span class="muted"><?= e($batch['sku']) ?> · Lô <?= e($batch['batch_no']) ?></span>
                                        </td>
                                        <td><?= e($batch['warehouse_code']) ?></td>
                                        <td>
                                            <?= formatDate($batch['expiry_date']) ?><br>
                                            <?php if ((int) $batch['days_left'] < 0): ?>
                                                <span class="badge badge-expired">Đã hết hạn</span>
                                            <?php else: ?>
                                                <span class="badge badge-pending">Còn <?= (int) $batch['days_left'] ?> ngày</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right"><?= formatQty($batch['quantity']) ?> <span class="muted"><?= e($batch['unit']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ĐỐI CHIẾU TỒN KHO VỚI SỔ PHÁT SINH -->
            <?php if ($dbError === ''): ?>
                <?php if ($reconMismatch === 0): ?>
                    <div class="recon-banner ok">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Tồn kho khớp sổ phát sinh.</strong>
                            Cả <?= $reconTotal ?> dòng tồn (kho × lô) đều bằng tổng các phát sinh đã ghi nhận.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="recon-banner bad">
                        <i class="fas fa-exclamation-circle"></i>
                        <div>
                            <strong>Phát hiện <?= $reconMismatch ?> / <?= $reconTotal ?> dòng tồn lệch sổ phát sinh.</strong>
                            Kiểm tra view <code>v_stock_reconciliation</code> (is_matched = 0).
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

<?php require __DIR__ . '/views/layout/footer.php'; ?>
