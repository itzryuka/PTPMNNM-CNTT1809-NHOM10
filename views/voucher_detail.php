<?php
// Chi tiết chứng từ: thông tin + tiến độ từng dòng, thao tác của bước hiện tại (duyệt, nhận hàng, xuất kho,
// gửi / nhận điều chuyển, xử lý chênh lệch), các đợt ghi sổ và sổ phát sinh đã ghi.
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/transfer_request_functions.php';

$user      = requireLogin('../');
$voucherId = (int) ($_GET['id'] ?? 0);

$voucher     = null;
$lines       = [];
$movements   = [];
$postings    = [];
$adjustments = [];
$request     = null;
$openCounts  = [];
$dbError     = '';
try {
    $pdo     = getDbConnection();
    $voucher = getVoucher($pdo, $voucherId);
    if ($voucher === null) {
        http_response_code(404);
        $dbError = 'Không tìm thấy chứng từ.';
    } else {
        $warehouseIds = array_values(array_filter([(int) $voucher['warehouse_id'], (int) $voucher['to_warehouse_id']]));
        // Quyền xem: phụ trách kho đi hoặc kho đến. Phiếu hao hụt ở kho trung chuyển: xem qua phiếu điều chuyển gốc.
        $viewIds = $warehouseIds;
        if ($voucher['reference_voucher_id']) {
            $origin  = getVoucher($pdo, (int) $voucher['reference_voucher_id']);
            $viewIds = array_merge($viewIds, array_filter([(int) $origin['warehouse_id'], (int) $origin['to_warehouse_id']]));
        }
        if (!array_filter($viewIds, fn ($id) => canAccessWarehouse($user, $id))) {
            http_response_code(403);
            $dbError = 'Bạn không có quyền xem chứng từ của kho này.';
            $voucher = null;
        } else {
            $lines       = getVoucherLines($pdo, $voucherId, (int) $voucher['warehouse_id']);
            $movements   = getVoucherMovements($pdo, $voucherId);
            $postings    = getVoucherPostings($pdo, $voucherId);
            $adjustments = getReferencingAdjustments($pdo, $voucherId);
            $request     = getRequestOfVoucher($pdo, $voucherId);
            $openCounts  = getOpenStockCounts($pdo, $warehouseIds);
        }
    }
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$type   = $voucher['type'] ?? 'IMPORT';
$status = $voucher['status'] ?? '';
$fromId = (int) ($voucher['warehouse_id'] ?? 0);
$toId   = (int) ($voucher['to_warehouse_id'] ?? 0);
$isManagerOf = fn (int $id) => isManager($user) && canAccessWarehouse($user, $id);

// Ai làm được gì ở bước hiện tại (server vẫn kiểm tra lại trong handle/voucher_process.php)
$can = [
    'decide'   => $voucher && $status === 'PENDING' && $isManagerOf($fromId),
    'receive'  => $voucher && $type === 'IMPORT' && in_array($status, ['APPROVED', 'PARTIAL'], true) && canAccessWarehouse($user, $fromId),
    'close'    => $voucher && $type === 'IMPORT' && in_array($status, ['APPROVED', 'PARTIAL'], true) && $isManagerOf($fromId),
    'ship'     => $voucher && $type === 'EXPORT' && $status === 'APPROVED' && canAccessWarehouse($user, $fromId),
    'send'     => $voucher && $type === 'TRANSFER' && $status === 'APPROVED' && canAccessWarehouse($user, $fromId),
    'release'  => $voucher && in_array($type, ['EXPORT', 'TRANSFER'], true) && $status === 'APPROVED' && $isManagerOf($fromId),
    'arrive'   => $voucher && $type === 'TRANSFER' && in_array($status, ['IN_TRANSIT', 'DISCREPANCY'], true) && canAccessWarehouse($user, $toId),
    'resolve'  => $voucher && $type === 'TRANSFER' && $status === 'DISCREPANCY' && ($isManagerOf($fromId) || $isManagerOf($toId)),
];
$frozen      = (bool) $openCounts;
$daysOnRoad  = $voucher && $voucher['shipped_at'] && in_array($status, ['IN_TRANSIT', 'DISCREPANCY'], true)
               ? (int) floor((time() - strtotime($voucher['shipped_at'])) / 86400) : null;
$listUrl     = 'voucher_list.php?type=' . $type;
$token       = fn () => bin2hex(random_bytes(16));
$csrf        = $voucher ? csrfToken() : '';
$qtyUnit     = fn ($qty, $line) => formatQty($qty) . ' <span class="muted">' . e($line['unit']) . '</span>';

$pageTitle  = $voucher ? voucherTypeLabel($type) . ' ' . $voucher['code'] : 'Chi tiết chứng từ';
$basePath   = '../';
$activeMenu = voucherPageInfo($type)['menu'];
require __DIR__ . '/layout/header.php';
?>

            <?php if ($voucher): ?>
                <?php if ($openCounts && !in_array($status, ['COMPLETED', 'CLOSED', 'CANCELLED', 'REJECTED'], true)): ?>
                    <div class="alert-warning">
                        <i class="fas fa-snowflake"></i>
                        Kho <?= e($openCounts[0]['warehouse_code']) ?> đang kiểm kê (đợt <?= e($openCounts[0]['code']) ?>): tồn kho đang bị đóng băng,
                        tạm thời không nhận / xuất / gửi / giữ hàng được cho tới khi chốt kiểm kê.
                    </div>
                <?php endif; ?>
                <?php if ($daysOnRoad !== null && $daysOnRoad > OVERDUE_TRANSIT_DAYS): ?>
                    <div class="alert-warning">
                        <i class="fas fa-truck"></i>
                        Hàng đã đi đường <strong><?= $daysOnRoad ?> ngày</strong>, quá hạn nhận <?= OVERDUE_TRANSIT_DAYS ?> ngày. Hãy liên hệ kho đến / nhà xe để kiểm tra.
                    </div>
                <?php endif; ?>

                <!-- THÔNG TIN PHIẾU -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-file-invoice"></i> <?= e($voucher['code']) ?></h3>
                        <?= voucherStatusBadge($status, $type) ?>
                    </div>
                    <dl class="detail-grid">
                        <div><dt>Loại chứng từ</dt><dd><?= e(voucherTypeLabel($type)) ?></dd></div>
                        <div>
                            <dt><?= $type === 'TRANSFER' ? 'Kho đi → kho đến' : 'Kho' ?></dt>
                            <dd>
                                <?= e($voucher['warehouse_name']) ?>
                                <?php if ($voucher['to_warehouse_name']): ?> → <?= e($voucher['to_warehouse_name']) ?><?php endif; ?>
                            </dd>
                        </div>
                        <div><dt>Ngày lập</dt><dd><?= formatDate($voucher['voucher_date']) ?></dd></div>
                        <div><dt>Người lập</dt><dd><?= e($voucher['creator_name']) ?></dd></div>
                        <div class="span-2"><dt>Lý do / ghi chú</dt><dd><?= $voucher['reason'] !== null ? e($voucher['reason']) : '—' ?></dd></div>
                        <?php if ($request): ?>
                            <div><dt>Theo yêu cầu điều chuyển</dt><dd><a class="link-strong" href="transfer_request_detail.php?id=<?= (int) $request['id'] ?>"><?= e($request['code']) ?></a></dd></div>
                        <?php endif; ?>
                        <?php if ($voucher['reference_code']): ?>
                            <div><dt>Điều chỉnh cho chứng từ</dt><dd><a class="link-strong" href="voucher_detail.php?id=<?= (int) $voucher['reference_voucher_id'] ?>"><?= e($voucher['reference_code']) ?></a></dd></div>
                        <?php endif; ?>
                        <?php if ($voucher['stock_count_code']): ?>
                            <div><dt>Sinh từ đợt kiểm kê</dt><dd><a class="link-strong" href="stock_count_detail.php?id=<?= (int) $voucher['stock_count_id'] ?>"><?= e($voucher['stock_count_code']) ?></a></dd></div>
                        <?php endif; ?>
                        <?php if ($status !== 'PENDING' && $voucher['approver_name']): ?>
                            <div>
                                <dt><?= $status === 'REJECTED' ? 'Người từ chối' : 'Người duyệt' ?></dt>
                                <dd><?= e($voucher['approver_name']) ?><br><span class="muted"><?= e(date('d/m/Y H:i', strtotime($voucher['approved_at']))) ?></span></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ($voucher['shipped_at']): ?>
                            <div>
                                <dt><?= $type === 'TRANSFER' ? 'Gửi hàng lúc' : 'Xuất kho lúc' ?></dt>
                                <dd><?= e(date('d/m/Y H:i', strtotime($voucher['shipped_at']))) ?>
                                    <?php if ($daysOnRoad !== null): ?><br><span class="badge <?= $daysOnRoad > OVERDUE_TRANSIT_DAYS ? 'badge-rejected' : 'badge-transit' ?>">Đi đường <?= $daysOnRoad ?> ngày</span><?php endif; ?>
                                </dd>
                            </div>
                        <?php endif; ?>
                        <?php if ($voucher['completed_at']): ?>
                            <div><dt>Kết thúc lúc</dt><dd><?= e(date('d/m/Y H:i', strtotime($voucher['completed_at']))) ?></dd></div>
                        <?php endif; ?>
                        <?php if ($voucher['closed_reason']): ?>
                            <div class="span-2"><dt><?= $status === 'CANCELLED' ? 'Lý do hủy' : 'Lý do đóng phiếu' ?></dt><dd><?= e($voucher['closed_reason']) ?></dd></div>
                        <?php endif; ?>
                        <?php foreach ($adjustments as $adjustment): ?>
                            <div class="span-2"><dt>Phiếu điều chỉnh liên quan</dt><dd><a class="link-strong" href="voucher_detail.php?id=<?= (int) $adjustment['id'] ?>"><?= e($adjustment['code']) ?></a> — <?= e($adjustment['reason']) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                </div>

                <!-- DÒNG HÀNG + TIẾN ĐỘ -->
                <div class="panel" style="margin-top: 20px;">
                    <div class="panel-header"><h3><i class="fas fa-list"></i> Chi tiết hàng hóa</h3></div>
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Mặt hàng</th>
                                    <th>Lô</th>
                                    <th>Hạn SD</th>
                                    <?php if ($type === 'IMPORT'): ?>
                                        <th class="text-right">Đặt</th><th class="text-right">Đã nhận tốt</th><th class="text-right">Hỏng (trả NCC)</th><th class="text-right">Còn chờ</th>
                                    <?php elseif ($type === 'TRANSFER' && $status !== 'PENDING'): ?>
                                        <th class="text-right">Theo phiếu</th><th class="text-right">Đã gửi</th><th class="text-right">Kho đến đã nhận</th><th class="text-right">Hao hụt</th><th class="text-right">Đang đi đường</th>
                                    <?php else: ?>
                                        <th class="text-right">Số lượng</th>
                                    <?php endif; ?>
                                    <?php if ($type === 'ADJUSTMENT'): ?><th>Lý do từng dòng</th><?php endif; ?>
                                    <?php if ($status === 'PENDING' && in_array($type, ['EXPORT', 'TRANSFER'], true)): ?>
                                        <th class="text-right">Có thể xuất</th><th>Kiểm tra</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $shortage = false; ?>
                                <?php foreach ($lines as $n => $line): ?>
                                    <tr>
                                        <td><?= $n + 1 ?></td>
                                        <td><?= e($line['sku'] . ' - ' . $line['item_name']) ?></td>
                                        <td><?= e($line['batch_no']) ?></td>
                                        <td><?= $line['expiry_date'] ? formatDate($line['expiry_date']) : '—' ?></td>
                                        <?php if ($type === 'IMPORT'): ?>
                                            <?php $waiting = $line['quantity'] - $line['received_qty']; ?>
                                            <td class="text-right"><?= $qtyUnit($line['quantity'], $line) ?></td>
                                            <td class="text-right qty-in"><?= formatQty($line['received_qty']) ?></td>
                                            <td class="text-right"><?= toMilli($line['damaged_qty']) ? '<span class="qty-out">' . formatQty($line['damaged_qty']) . '</span>' : '—' ?></td>
                                            <td class="text-right"><strong><?= toMilli($waiting) > 0 ? formatQty($waiting) : '<span class="badge badge-active">Đủ</span>' ?></strong></td>
                                        <?php elseif ($type === 'TRANSFER' && $status !== 'PENDING'): ?>
                                            <?php $onRoad = $line['shipped_qty'] - $line['received_qty'] - $line['lost_qty']; ?>
                                            <td class="text-right"><?= $qtyUnit($line['quantity'], $line) ?></td>
                                            <td class="text-right"><?= formatQty($line['shipped_qty']) ?></td>
                                            <td class="text-right qty-in"><?= formatQty($line['received_qty']) ?></td>
                                            <td class="text-right"><?= toMilli($line['lost_qty']) ? '<span class="qty-out">' . formatQty($line['lost_qty']) . '</span>' : '—' ?></td>
                                            <td class="text-right"><strong><?= toMilli($onRoad) > 0 ? '<span class="badge badge-transit">' . formatQty($onRoad) . '</span>' : '—' ?></strong></td>
                                        <?php else: ?>
                                            <td class="text-right"><strong><?= $type === 'ADJUSTMENT' && $line['quantity'] > 0 ? '+' : '' ?><?= $type === 'ADJUSTMENT' && $line['quantity'] < 0 ? '−' . formatQty(-$line['quantity']) : formatQty($line['quantity']) ?></strong> <span class="muted"><?= e($line['unit']) ?></span></td>
                                        <?php endif; ?>
                                        <?php if ($type === 'ADJUSTMENT'): ?><td><?= $line['note'] !== null ? e($line['note']) : '—' ?></td><?php endif; ?>
                                        <?php if ($status === 'PENDING' && in_array($type, ['EXPORT', 'TRANSFER'], true)): ?>
                                            <?php $enough = toMilli($line['available_qty']) >= toMilli($line['quantity']); $shortage = $shortage || !$enough; ?>
                                            <td class="text-right" title="Tồn <?= e(formatQty($line['current_qty'])) ?>, đang giữ cho phiếu khác <?= e(formatQty($line['reserved_qty'])) ?>"><?= formatQty($line['available_qty']) ?></td>
                                            <td><?= $enough ? '<span class="badge badge-active">Đủ hàng</span>' : '<span class="badge badge-rejected">Thiếu ' . formatQty($line['quantity'] - $line['available_qty']) . '</span>' ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- THAO TÁC CỦA BƯỚC HIỆN TẠI -->
                <?php if ($can['receive']): ?>
                    <form class="form-card step-card" method="post" action="../handle/voucher_process.php" data-lock
                          data-confirm-title="Ghi nhận đợt nhận hàng" data-confirm-ok="Ghi nhận" data-confirm-variant="import"
                          data-confirm="Ghi nhận đợt nhận hàng này? Số nhận tốt sẽ được cộng vào tồn kho và ghi sổ phát sinh.">
                        <h3><i class="fas fa-dolly"></i> Nhận hàng (đợt <?= count($postings) + 1 ?>)</h3>
                        <p class="form-hint">Đếm hàng trên xe, ghi số <strong>nhận tốt</strong> (cộng vào tồn) và số <strong>hỏng</strong> (không vào tồn, ghi biên bản trả nhà cung cấp). Hàng về nhiều đợt thì ghi từng đợt.</p>
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="receive">
                        <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                        <input type="hidden" name="client_token" value="<?= $token() ?>">
                        <div class="table-container step-table">
                            <table>
                                <thead><tr><th>Mặt hàng / lô</th><th class="text-right">Còn chờ</th><th class="text-right">Nhận tốt</th><th class="text-right">Hỏng</th></tr></thead>
                                <tbody>
                                    <?php foreach ($lines as $line): ?>
                                        <?php $waiting = $line['quantity'] - $line['received_qty']; if (toMilli($waiting) <= 0) continue; ?>
                                        <tr>
                                            <td><?= e($line['sku'] . ' - ' . $line['item_name']) ?><br><span class="muted">Lô <?= e($line['batch_no']) ?></span></td>
                                            <td class="text-right"><?= $qtyUnit($waiting, $line) ?></td>
                                            <td class="text-right"><input class="form-control qty-input" type="number" min="0" step="0.001" max="<?= plainQty($waiting) ?>" name="lines[<?= (int) $line['id'] ?>][good]" value="<?= plainQty($waiting) ?>" aria-label="Nhận tốt <?= e($line['sku']) ?>"></td>
                                            <td class="text-right"><input class="form-control qty-input" type="number" min="0" step="0.001" name="lines[<?= (int) $line['id'] ?>][damaged]" value="0" aria-label="Hỏng <?= e($line['sku']) ?>"></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="step-actions">
                            <input class="form-control" name="note" maxlength="255" placeholder="Lý do hàng hỏng / ghi chú đợt nhận (bắt buộc nếu có hàng hỏng)">
                            <button class="btn btn-import" type="submit" <?= $frozen ? 'disabled' : '' ?>><i class="fas fa-check"></i> Ghi nhận đợt nhận</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($can['arrive']): ?>
                    <form class="form-card step-card" method="post" action="../handle/voucher_process.php" data-lock
                          data-confirm-title="Xác nhận nhận hàng" data-confirm-ok="Xác nhận" data-confirm-variant="import"
                          data-confirm="Xác nhận số hàng <?= e($voucher['to_warehouse_name']) ?> thực nhận? Số này được chuyển từ &quot;Đang đi đường&quot; vào kho; phần thiếu chờ quản lý xử lý.">
                        <h3><i class="fas fa-truck-loading"></i> Kho đến nhận hàng</h3>
                        <p class="form-hint">Đếm số thực nhận. Nhận đủ thì phiếu hoàn tất; nhận thiếu thì phần thiếu vẫn nằm ở "Đang đi đường" để quản lý xử lý (chờ giao bổ sung hoặc ghi hao hụt).</p>
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="receive_transfer">
                        <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                        <input type="hidden" name="client_token" value="<?= $token() ?>">
                        <div class="table-container step-table">
                            <table>
                                <thead><tr><th>Mặt hàng / lô</th><th class="text-right">Đang đi đường</th><th class="text-right">Thực nhận</th></tr></thead>
                                <tbody>
                                    <?php foreach ($lines as $line): ?>
                                        <?php $onRoad = $line['shipped_qty'] - $line['received_qty'] - $line['lost_qty']; if (toMilli($onRoad) <= 0) continue; ?>
                                        <tr>
                                            <td><?= e($line['sku'] . ' - ' . $line['item_name']) ?><br><span class="muted">Lô <?= e($line['batch_no']) ?></span></td>
                                            <td class="text-right"><?= $qtyUnit($onRoad, $line) ?></td>
                                            <td class="text-right"><input class="form-control qty-input" type="number" min="0" step="0.001" max="<?= plainQty($onRoad) ?>" name="lines[<?= (int) $line['id'] ?>]" value="<?= plainQty($onRoad) ?>" aria-label="Thực nhận <?= e($line['sku']) ?>"></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="step-actions"><span></span><button class="btn btn-import" type="submit" <?= $frozen ? 'disabled' : '' ?>><i class="fas fa-check"></i> Xác nhận nhận hàng</button></div>
                    </form>
                <?php endif; ?>

                <?php if ($can['resolve']): ?>
                    <form class="form-card step-card" method="post" action="../handle/voucher_process.php" data-lock
                          data-confirm-title="Xử lý chênh lệch" data-confirm-ok="Xác nhận" data-confirm-variant="primary"
                          data-confirm="Xác nhận cách xử lý phần hàng còn thiếu của <?= e($voucher['code']) ?>?">
                        <h3><i class="fas fa-balance-scale"></i> Xử lý phần nhận thiếu</h3>
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="resolve">
                        <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                        <div class="radio-options">
                            <label><input type="radio" name="resolution" value="lost" checked> <span><strong>Thất lạc trên đường</strong><br><span class="muted">Lập phiếu điều chỉnh (−) "Hàng đang đi đường", phiếu hoàn tất</span></span></label>
                            <label><input type="radio" name="resolution" value="damaged"> <span><strong>Hư hỏng khi vận chuyển</strong><br><span class="muted">Như trên, ghi lý do hư hỏng</span></span></label>
                            <label><input type="radio" name="resolution" value="wait"> <span><strong>Chờ giao bổ sung</strong><br><span class="muted">Giữ nguyên ở "Đang đi đường", kho đến nhận tiếp khi hàng tới</span></span></label>
                        </div>
                        <div class="step-actions">
                            <input class="form-control" name="note" maxlength="200" placeholder="Diễn giải (bắt buộc khi ghi hao hụt), vd: Biên bản nhà xe số 12/10">
                            <button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> Xác nhận xử lý</button>
                        </div>
                    </form>
                <?php endif; ?>

                <!-- CÁC ĐỢT GHI SỔ -->
                <?php if ($postings): ?>
                    <div class="panel" style="margin-top: 20px;">
                        <div class="panel-header"><h3><i class="fas fa-history"></i> Các đợt ghi sổ</h3><span class="muted"><?= count($postings) ?> đợt</span></div>
                        <div class="table-container" style="border: none; border-radius: 0;">
                            <table>
                                <thead><tr><th>Đợt</th><th>Bước</th><th>Thời điểm</th><th>Người ghi</th><th>Hàng</th><th>Ghi chú</th></tr></thead>
                                <tbody>
                                    <?php foreach ($postings as $posting): ?>
                                        <tr>
                                            <td class="nowrap"><strong><?= e($posting['code']) ?></strong></td>
                                            <td class="nowrap"><span class="badge badge-step"><?= e(postingKindLabel($posting['kind'])) ?></span></td>
                                            <td class="nowrap"><?= e(date('d/m/Y H:i', strtotime($posting['created_at']))) ?></td>
                                            <td><?= e($posting['created_by_name']) ?></td>
                                            <td class="wrap">
                                                <?php foreach ($posting['lines'] as $pl): ?>
                                                    <div><?= e($pl['sku']) ?> · lô <?= e($pl['batch_no']) ?>: <strong><?= formatQty(abs($pl['quantity'])) ?></strong> <?= e($pl['unit']) ?><?= toMilli($pl['damaged_qty']) ? ' <span class="qty-out">(hỏng ' . formatQty($pl['damaged_qty']) . ')</span>' : '' ?></div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td class="wrap"><?= $posting['note'] !== null ? e($posting['note']) : '—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- SỔ PHÁT SINH ĐÃ GHI -->
                <?php if ($movements): ?>
                    <div class="panel" style="margin-top: 20px;">
                        <div class="panel-header"><h3><i class="fas fa-book"></i> Sổ phát sinh đã ghi nhận</h3></div>
                        <div class="table-container" style="border: none; border-radius: 0;">
                            <table>
                                <thead>
                                    <tr><th>Thời điểm</th><th>Đợt</th><th>Kho</th><th>Mặt hàng</th><th>Lô</th><th class="text-right">Phát sinh</th><th>Người ghi</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($movements as $movement): ?>
                                        <tr>
                                            <td><?= e(date('d/m/Y H:i', strtotime($movement['moved_at']))) ?></td>
                                            <td class="nowrap"><?= e($movement['posting_code']) ?></td>
                                            <td><?= $movement['is_transit'] ? '<span class="badge badge-transit">Đang đi đường</span>' : e($movement['warehouse_code']) ?></td>
                                            <td><?= e($movement['sku'] . ' - ' . $movement['item_name']) ?></td>
                                            <td><?= e($movement['batch_no']) ?></td>
                                            <td class="text-right <?= $movement['quantity'] > 0 ? 'qty-in' : 'qty-out' ?>">
                                                <?= $movement['quantity'] > 0 ? '+' : '−' ?><?= formatQty(abs($movement['quantity'])) ?> <?= e($movement['unit']) ?>
                                            </td>
                                            <td><?= e($movement['created_by_name']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- NÚT THAO TÁC -->
                <div class="form-actions">
                    <a class="btn btn-light" href="<?= e($listUrl) ?>"><i class="fas fa-arrow-left"></i> Quay lại danh sách</a>
                    <?php if ($can['decide']): ?>
                        <form method="post" action="../handle/voucher_process.php" data-lock=".form-actions"
                              data-confirm-title="Từ chối chứng từ" data-confirm-ok="Từ chối"
                              data-confirm="Từ chối chứng từ <?= e($voucher['code']) ?>? Phiếu sẽ bị khóa, không ghi nhận phát sinh.">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                            <button class="btn btn-danger" type="submit"><i class="fas fa-ban"></i> Từ chối</button>
                        </form>
                        <?php
                        [$approveLabel, $approveConfirm] = [
                            'IMPORT'     => ['Duyệt (đồng ý nhập)', 'Duyệt phiếu nhập? Tồn chưa thay đổi; hàng chỉ được cộng khi thủ kho ghi nhận số thực nhận.'],
                            'EXPORT'     => ['Duyệt & giữ hàng', 'Duyệt phiếu xuất? Hệ thống giữ chỗ số hàng này ngay (phiếu khác không lấy được); tồn chỉ bị trừ khi bấm "Đã xuất kho".'],
                            'TRANSFER'   => ['Duyệt & giữ hàng', 'Duyệt phiếu điều chuyển? Hệ thống giữ chỗ hàng ở kho đi; tồn chỉ bị trừ khi bấm "Gửi hàng".'],
                            'ADJUSTMENT' => ['Xác nhận & ghi sổ', 'Xác nhận phiếu điều chỉnh? Sổ phát sinh và tồn được cập nhật ngay, không sửa / xóa được nữa.'],
                        ][$type];
                        ?>
                        <form method="post" action="../handle/voucher_process.php" data-lock=".form-actions"
                              data-confirm-title="Duyệt chứng từ <?= e($voucher['code']) ?>" data-confirm-ok="Duyệt" data-confirm-variant="import"
                              data-confirm="<?= e($approveConfirm) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                            <button class="btn btn-import" type="submit" <?= $frozen && $type !== 'IMPORT' ? 'disabled' : '' ?>><i class="fas fa-check"></i> <?= e($approveLabel) ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ($can['close']): ?>
                        <form class="inline-reason" method="post" action="../handle/voucher_process.php" data-lock=".form-actions"
                              data-confirm-title="Đóng phiếu nhập" data-confirm-ok="Đóng phiếu"
                              data-confirm="Đóng phiếu khi nhà cung cấp không giao phần còn lại? Tồn giữ nguyên số đã thực nhận, phiếu không nhận thêm được.">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="close">
                            <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                            <input class="form-control" name="reason" required maxlength="255" placeholder="Lý do đóng phiếu (vd: NCC báo hết hàng)">
                            <button class="btn btn-light" type="submit"><i class="fas fa-lock"></i> Đóng phiếu (thiếu hàng)</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($can['release']): ?>
                        <form class="inline-reason" method="post" action="../handle/voucher_process.php" data-lock=".form-actions"
                              data-confirm-title="Hủy giữ chỗ" data-confirm-ok="Hủy giữ chỗ"
                              data-confirm="Hủy phiếu <?= e($voucher['code']) ?> và trả số hàng đang giữ về &quot;có thể xuất&quot;? Phiếu không dùng lại được.">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="cancel_reservation">
                            <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                            <input class="form-control" name="reason" required maxlength="255" placeholder="Lý do hủy (vd: khách hủy đơn)">
                            <button class="btn btn-light" type="submit"><i class="fas fa-undo"></i> Hủy giữ chỗ</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($can['ship']): ?>
                        <form method="post" action="../handle/voucher_process.php" data-lock=".form-actions"
                              data-confirm-title="Xuất kho" data-confirm-ok="Đã xuất kho" data-confirm-variant="import"
                              data-confirm="Hàng của <?= e($voucher['code']) ?> đã lên xe? Tồn và hàng đang giữ sẽ được trừ, ghi sổ phát sinh.">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="ship">
                            <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                            <input type="hidden" name="client_token" value="<?= $token() ?>">
                            <button class="btn btn-import" type="submit" <?= $frozen ? 'disabled' : '' ?>><i class="fas fa-truck"></i> Đã xuất kho</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($can['send']): ?>
                        <form method="post" action="../handle/voucher_process.php" data-lock=".form-actions"
                              data-confirm-title="Gửi hàng" data-confirm-ok="Gửi hàng" data-confirm-variant="import"
                              data-confirm="Xe đã rời <?= e($voucher['warehouse_name']) ?>? Kho đi bị trừ, hàng chuyển sang &quot;Đang đi đường&quot; chờ <?= e($voucher['to_warehouse_name']) ?> nhận.">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="send">
                            <input type="hidden" name="id" value="<?= (int) $voucher['id'] ?>">
                            <input type="hidden" name="client_token" value="<?= $token() ?>">
                            <button class="btn btn-import" type="submit" <?= $frozen ? 'disabled' : '' ?>><i class="fas fa-shipping-fast"></i> Gửi hàng</button>
                        </form>
                    <?php endif; ?>

                    <?php if (!array_filter($can)): ?>
                        <?php
                        $waitingFor = match (true) {
                            $status === 'PENDING'                                            => 'Chờ quản lý ' . $voucher['warehouse_name'] . ' duyệt.',
                            $type === 'IMPORT' && in_array($status, ['APPROVED', 'PARTIAL'], true) => 'Chờ ' . $voucher['warehouse_name'] . ' nhận hàng.',
                            $type === 'EXPORT' && $status === 'APPROVED'                     => 'Đang giữ hàng, chờ ' . $voucher['warehouse_name'] . ' xuất kho.',
                            $type === 'TRANSFER' && $status === 'APPROVED'                   => 'Đang giữ hàng, chờ ' . $voucher['warehouse_name'] . ' gửi hàng.',
                            $type === 'TRANSFER' && $status === 'IN_TRANSIT'                 => 'Hàng đang đi đường, chờ ' . $voucher['to_warehouse_name'] . ' nhận.',
                            $type === 'TRANSFER' && $status === 'DISCREPANCY'                => 'Kho đến nhận thiếu, chờ quản lý xử lý.',
                            default                                                          => 'Chứng từ đã kết thúc: không sửa / xóa được. Muốn sửa sai, hãy lập phiếu điều chỉnh.',
                        };
                        ?>
                        <span class="muted"><i class="fas <?= in_array($status, ['COMPLETED', 'CLOSED', 'CANCELLED', 'REJECTED'], true) || ($type === 'ADJUSTMENT' && $status === 'APPROVED') ? 'fa-lock' : 'fa-hourglass-half' ?>"></i> <?= e($waitingFor) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($can['decide'] && $shortage): ?>
                    <p class="form-hint text-right">Có dòng không đủ hàng có thể xuất: nếu duyệt, hệ thống sẽ từ chối giữ chỗ và không thay đổi gì.</p>
                <?php endif; ?>
            <?php else: ?>
                <a class="btn btn-light" href="../index.php" style="display: inline-flex;"><i class="fas fa-arrow-left"></i> Về trang tổng quan</a>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
