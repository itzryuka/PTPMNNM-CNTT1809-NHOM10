<?php
// Danh sách yêu cầu điều chuyển: yêu cầu gửi tới kho tôi (cần xử lý) và yêu cầu kho tôi đã gửi
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/transfer_request_functions.php';

$user = requireLogin('../');

[$keyword, $pageSize, $page] = readListParams();
$filters = [
    'keyword'   => $keyword,
    'status'    => array_key_exists($_GET['status'] ?? '', TRANSFER_REQUEST_STATUS) ? $_GET['status'] : '',
    'direction' => in_array($_GET['direction'] ?? '', ['incoming', 'outgoing'], true) ? $_GET['direction'] : '',
];
$sort = readSort(TRANSFER_REQUEST_SORT_COLUMNS, 'created', 'desc');

$requests      = [];
$totalRequests = 0;
$dbError       = '';
try {
    $pdo           = getDbConnection();
    $totalRequests = countTransferRequests($pdo, $filters, $user['warehouses']);
    $totalPages    = max(1, (int) ceil($totalRequests / $pageSize));
    $page          = min($page, $totalPages);
    $requests      = getTransferRequests($pdo, $filters, $user['warehouses'], $pageSize, ($page - 1) * $pageSize, $sort);
} catch (PDOException $e) {
    $totalPages = 1;
    $dbError    = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$pageTitle  = 'Yêu cầu điều chuyển';
$basePath   = '../';
$activeMenu = 'transfer_requests';
require __DIR__ . '/layout/header.php';
?>

            <div class="toolbar">
                <div class="toolbar-top">
                    <span class="muted toolbar-note"><i class="fas fa-info-circle"></i> Kho thiếu hàng gửi yêu cầu; kho có hàng đồng ý thì hệ thống tự lập phiếu điều chuyển và giữ hàng.</span>
                    <a class="btn btn-primary" href="transfer_request_form.php"><i class="fas fa-plus"></i> Lập yêu cầu</a>
                </div>
                <div class="toolbar-bottom">
                    <div class="toolbar-bottom-left"></div>
                    <form class="toolbar-bottom-right" method="get">
                        <input type="hidden" name="size" value="<?= $pageSize ?>">
                        <?= sortHiddenInputs($sort) ?>
                        <select class="filter-select" name="direction" onchange="this.form.submit()">
                            <option value="">Tất cả yêu cầu</option>
                            <option value="incoming" <?= $filters['direction'] === 'incoming' ? 'selected' : '' ?>>Gửi tới kho tôi (cần xử lý)</option>
                            <option value="outgoing" <?= $filters['direction'] === 'outgoing' ? 'selected' : '' ?>>Kho tôi đã yêu cầu</option>
                        </select>
                        <select class="filter-select" name="status" onchange="this.form.submit()">
                            <option value="">Tất cả trạng thái</option>
                            <?php foreach (TRANSFER_REQUEST_STATUS as $statusKey => [, $statusLabel]): ?>
                                <option value="<?= $statusKey ?>" <?= $filters['status'] === $statusKey ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="search-box">
                            <input type="text" name="q" value="<?= e($keyword) ?>" placeholder="Mã yêu cầu / lý do...">
                            <button type="submit"><i class="fas fa-search" style="color: #94a3b8;"></i></button>
                        </div>
                        <a class="btn btn-icon" href="transfer_request_list.php" title="Bỏ lọc"><i class="fas fa-sync-alt"></i></a>
                        <span class="total-count">Tổng số: <strong><?= $totalRequests ?></strong></span>
                    </form>
                </div>
            </div>

            <div class="table-container">
                <table class="nowrap-table">
                    <thead>
                        <tr>
                            <?= sortHeader('Mã Yêu Cầu', 'code', $sort) ?>
                            <?= sortHeader('Ngày Lập', 'created', $sort, '', 'desc') ?>
                            <?= sortHeader('Kho Cần Hàng', 'to', $sort) ?>
                            <?= sortHeader('Kho Được Đề Nghị', 'from', $sort) ?>
                            <th>Lý Do</th>
                            <?= sortHeader('Cần Trước', 'needed_by', $sort) ?>
                            <?= sortHeader('Trạng Thái', 'status', $sort) ?>
                            <th>Phiếu Điều Chuyển</th>
                            <th style="text-align: center;">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr class="empty-row"><td colspan="9">Không có yêu cầu nào.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($requests as $request): ?>
                            <?php $mustAct = $request['status'] === 'PENDING' && isManager($user) && canAccessWarehouse($user, (int) $request['from_warehouse_id']); ?>
                            <tr>
                                <td><a class="link-strong" href="transfer_request_detail.php?id=<?= (int) $request['id'] ?>"><?= e($request['code']) ?></a></td>
                                <td><?= e(date('d/m/Y', strtotime($request['created_at']))) ?></td>
                                <td><?= e($request['to_name']) ?></td>
                                <td><?= e($request['from_name']) ?></td>
                                <td class="wrap"><?= e($request['reason']) ?></td>
                                <td>
                                    <?= $request['needed_by'] ? formatDate($request['needed_by']) : '—' ?>
                                    <?php if ($request['needed_by'] && $request['status'] === 'PENDING' && $request['needed_by'] < date('Y-m-d', strtotime('+2 days'))): ?>
                                        <span class="badge badge-rejected">Gấp</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= transferRequestStatusBadge($request['status']) ?> <?= $mustAct ? '<span class="badge badge-warn">Cần bạn xử lý</span>' : '' ?></td>
                                <td>
                                    <?php if ($request['voucher_id']): ?>
                                        <a class="link-strong" href="voucher_detail.php?id=<?= (int) $request['voucher_id'] ?>"><?= e($request['voucher_code']) ?></a>
                                        <?= voucherStatusBadge($request['voucher_status'], 'TRANSFER') ?>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="row-actions">
                                    <a href="transfer_request_detail.php?id=<?= (int) $request['id'] ?>" title="Xem chi tiết"><i class="fas fa-eye icon-view"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

<?php require __DIR__ . '/layout/pagination.php'; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
