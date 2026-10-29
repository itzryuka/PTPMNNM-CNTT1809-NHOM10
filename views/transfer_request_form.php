<?php
// Lập yêu cầu điều chuyển: kho CẦN hàng (kho mình phụ trách) đề nghị kho CÓ hàng chuyển sang.
// Hiện "có thể xuất" của từng mặt hàng ở các kho để chọn kho được đề nghị.
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/transfer_request_functions.php';

$user = requireLogin('../');

$old = $_SESSION['transfer_request_old'] ?? null;
unset($_SESSION['transfer_request_old']);
$old = is_array($old) ? $old : [];
$clientToken = preg_match('/^[0-9a-f]{32}$/', $old['client_token'] ?? '') ? $old['client_token'] : bin2hex(random_bytes(16));

$myWarehouses  = [];
$allWarehouses = [];
$formData      = [];
$dbError       = '';
try {
    $pdo           = getDbConnection();
    $myWarehouses  = getWarehouseOptions($pdo, $user['warehouses']);
    $allWarehouses = getWarehouseOptions($pdo, null);
    $formData = [
        'items'        => getItemOptions($pdo),
        'warehouses'   => array_map(fn ($w) => ['id' => (int) $w['id'], 'code' => $w['code'], 'name' => $w['name']], $allWarehouses),
        'availability' => getNetworkAvailability($pdo),
        'oldLines'     => array_values(is_array($old['lines'] ?? null) ? $old['lines'] : []),
    ];
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}
$selectedTo   = (int) ($old['to_warehouse_id'] ?? ($myWarehouses[0]['id'] ?? 0));
$selectedFrom = (int) ($old['from_warehouse_id'] ?? 0);

$pageTitle    = 'Lập yêu cầu điều chuyển';
$basePath     = '../';
$activeMenu   = 'transfer_requests';
$extraScripts = ['js/transfer_request.js'];
require __DIR__ . '/layout/header.php';
?>

            <?php if (empty($myWarehouses) && $dbError === ''): ?>
                <div class="alert-error"><i class="fas fa-lock"></i> Tài khoản của bạn chưa được phân quyền kho nào nên không thể lập yêu cầu.</div>
            <?php else: ?>
            <form method="post" action="../handle/transfer_request_process.php" id="requestForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="client_token" value="<?= e($clientToken) ?>">

                <div class="form-card">
                    <h3><i class="fas fa-hand-holding"></i> Thông tin yêu cầu</h3>
                    <p class="form-hint" style="margin-top: -8px;">Kho của bạn đang thiếu hàng cho khách? Chọn kho còn hàng và gửi yêu cầu. Khi kho đó đồng ý, hệ thống tự chọn lô, lập phiếu điều chuyển và giữ hàng cho bạn ngay.</p>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="toWarehouse">Kho cần hàng (kho của bạn) *</label>
                            <select class="form-control" id="toWarehouse" name="to_warehouse_id" required>
                                <?php foreach ($myWarehouses as $warehouse): ?>
                                    <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $selectedTo ? 'selected' : '' ?>><?= e($warehouse['code'] . ' - ' . $warehouse['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="fromWarehouse">Đề nghị kho gửi hàng *</label>
                            <select class="form-control" id="fromWarehouse" name="from_warehouse_id" required>
                                <option value="">-- Chọn kho còn hàng --</option>
                                <?php foreach ($allWarehouses as $warehouse): ?>
                                    <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $selectedFrom ? 'selected' : '' ?>><?= e($warehouse['code'] . ' - ' . $warehouse['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="neededBy">Cần hàng trước ngày</label>
                            <input class="form-control" type="date" id="neededBy" name="needed_by" min="<?= date('Y-m-d') ?>" value="<?= e($old['needed_by'] ?? '') ?>">
                        </div>
                        <div class="form-group span-all">
                            <label for="reason">Lý do (khách hàng / đơn hàng) *</label>
                            <input class="form-control" id="reason" name="reason" required maxlength="255" value="<?= e($old['reason'] ?? '') ?>"
                                   placeholder="Vd: Cửa hàng Thành Công đặt 20 thùng, giao trước 02/11">
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <div class="panel-title-row">
                        <h3><i class="fas fa-list"></i> Hàng cần</h3>
                        <button type="button" class="btn btn-export btn-sm" id="addRequestLine"><i class="fas fa-plus"></i> Thêm dòng</button>
                    </div>
                    <p class="form-hint">Cột "Có thể xuất ở các kho" là số hàng chưa bị giữ cho phiếu khác (đã bỏ lô hết hạn). Kho được đề nghị được tô đậm.</p>
                    <div class="table-container line-table">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th style="width: 34%;">Mặt hàng *</th>
                                    <th style="width: 140px;">Số lượng *</th>
                                    <th>Có thể xuất ở các kho</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="requestLines"></tbody>
                        </table>
                    </div>
                </div>

                <div class="form-actions">
                    <a class="btn btn-light" href="transfer_request_list.php">Hủy</a>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i> Gửi yêu cầu</button>
                </div>
            </form>

            <script>
                window.REQUEST_FORM = <?= json_encode($formData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            </script>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
