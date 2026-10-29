<?php
// ===== TRANG TỔNG QUAN (DASHBOARD) — trang chủ tạm thời =====
require_once __DIR__ . '/functions/db_connection.php';
require_once __DIR__ . '/functions/helpers.php';
require_once __DIR__ . '/functions/auth.php';
require_once __DIR__ . '/functions/voucher_functions.php';   // OVERDUE_TRANSIT_DAYS, nhãn trạng thái
require_once __DIR__ . '/functions/dashboard_functions.php';

$user = requireLogin('');

// Kho user được phân quyền (requireLogin đọc lại từ CSDL ở mỗi request)
$allowedWarehouses = $user['warehouses'];

$stats          = ['warehouses' => 0, 'items' => 0, 'pending' => 0, 'requests' => 0, 'in_transit' => 0, 'overdue' => 0];
$overdue        = [];
$recentVouchers = [];
$expiring       = [];
$reconTotal     = 0;
$reconMismatch  = 0;
$dbError        = '';
try {
    $pdo            = getDbConnection();
    $stats          = getDashboardStats($pdo, $allowedWarehouses);
    $overdue        = getOverdueTransfers($pdo, $allowedWarehouses);
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

            <!-- CẢNH BÁO: ĐIỀU CHUYỂN QUÁ HẠN NHẬN -->
            <?php if ($overdue): ?>
                <div class="alert-warning">
                    <i class="fas fa-truck"></i>
                    <strong><?= count($overdue) ?> phiếu điều chuyển đi đường quá <?= OVERDUE_TRANSIT_DAYS ?> ngày chưa nhận đủ:</strong>
                    <?php foreach ($overdue as $n => $transfer): ?>
                        <?= $n ? ' · ' : '' ?><a class="link-strong" href="views/voucher_detail.php?id=<?= (int) $transfer['id'] ?>"><?= e($transfer['code']) ?></a>
                        (<?= e($transfer['from_name']) ?> → <?= e($transfer['to_name']) ?>, <?= (int) $transfer['days_on_road'] ?> ngày)
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

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
                <a class="stat-card" href="views/transfer_request_list.php?direction=incoming&amp;status=PENDING">
                    <div class="stat-icon amber"><i class="fas fa-hourglass-half"></i></div>
                    <div>
                        <div class="stat-label">Phiếu chờ duyệt · yêu cầu chờ xử lý</div>
                        <div class="stat-value"><?= $stats['pending'] ?> · <?= $stats['requests'] ?></div>
                    </div>
                </a>
                <a class="stat-card" href="views/report_stock.php">
                    <div class="stat-icon slate"><i class="fas fa-truck"></i></div>
                    <div>
                        <div class="stat-label">Điều chuyển đang đi đường<?= $stats['overdue'] ? ' (' . $stats['overdue'] . ' quá hạn)' : '' ?></div>
                        <div class="stat-value"><?= $stats['in_transit'] ?></div>
                    </div>
                </a>
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
                                        <td><?= voucherStatusBadge($voucher['status'], $voucher['type']) ?></td>
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
                            <strong>Sổ sách khớp.</strong>
                            Cả <?= $reconTotal ?> dòng kiểm tra đều đạt: tồn = sổ phát sinh, hàng đang giữ = phiếu đã duyệt, hàng đang đi đường = phiếu chưa nhận.
                            <a class="link-strong" href="views/report_reconciliation.php">Xem đối chiếu</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="recon-banner bad">
                        <i class="fas fa-exclamation-circle"></i>
                        <div>
                            <strong>Phát hiện <?= $reconMismatch ?> / <?= $reconTotal ?> dòng lệch sổ.</strong>
                            <a class="link-strong" href="views/report_reconciliation.php">Xem chi tiết ở trang Đối chiếu sổ</a>.
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

<?php require __DIR__ . '/views/layout/footer.php'; ?>
