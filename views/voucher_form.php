<?php
// Lập Phiếu nhập kho (?type=IMPORT) / Phiếu xuất kho (?type=EXPORT)
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';

$user = requireLogin('../');
$type     = requestedVoucherType($_GET['type'] ?? '', ['IMPORT', 'EXPORT', 'TRANSFER']);
$typeInfo = voucherPageInfo($type);

// Dữ liệu đã nhập khi lưu lỗi lần trước (handle/voucher_process.php gửi lại)
$old = $_SESSION['voucher_form_old'] ?? null;
unset($_SESSION['voucher_form_old']);
if (!is_array($old) || ($old['type'] ?? '') !== $type) {
    $old = null;
}
// Mã chống gửi trùng: giữ nguyên khi form bị trả lại vì lỗi (phiếu chưa được tạo)
$clientToken = preg_match('/^[0-9a-f]{32}$/', $old['client_token'] ?? '') ? $old['client_token'] : bin2hex(random_bytes(16));

$warehouses     = [];
$toWarehouses   = [];
$formData       = [];
$dbError        = '';
try {
    $pdo        = getDbConnection();
    $warehouses = getWarehouseOptions($pdo, $user['warehouses']);
    // Kho đến của phiếu điều chuyển: mọi kho đang hoạt động (người lập chỉ cần quyền ở kho đi)
    $toWarehouses = $type === 'TRANSFER' ? getWarehouseOptions($pdo, null) : [];
    $formData   = [
        'type'     => $type,
        'today'    => date('Y-m-d'),
        'items'    => getItemOptions($pdo),
        'batches'  => $type === 'IMPORT' ? getBatchOptions($pdo) : [],
        'stock'    => $type !== 'IMPORT' ? getStockOptions($pdo, $user['warehouses']) : [],
        'oldLines' => array_values(is_array($old['lines'] ?? null) ? $old['lines'] : []),
    ];
} catch (PDOException $e) {
    $dbError = 'Không thể tải dữ liệu: ' . $e->getMessage();
}

$selectedWarehouse   = (int) ($old['warehouse_id'] ?? ($warehouses[0]['id'] ?? 0));
$selectedToWarehouse = (int) ($old['to_warehouse_id'] ?? 0);
$isImport     = $type === 'IMPORT';
$fromLabel    = ['IMPORT' => 'Kho nhập', 'EXPORT' => 'Kho xuất', 'TRANSFER' => 'Kho đi'][$type];
$reasonLabel  = [
    'IMPORT'   => ['Nhà cung cấp / người giao hàng / ghi chú', 'Vd: Nhập hàng từ NCC Vinamilk, người giao: Anh Tuấn'],
    'EXPORT'   => ['Lý do xuất / người nhận hàng', 'Vd: Xuất bán cho Siêu thị Hòa Bình'],
    'TRANSFER' => ['Lý do điều chuyển / người vận chuyển', 'Vd: Bổ sung hàng cho Kho HCM, xe 51C-123.45'],
][$type];
$pageTitle    = 'Lập ' . $typeInfo['title'];
$basePath     = '../';
$activeMenu   = $typeInfo['menu'];
$extraScripts = ['js/voucher_form.js'];
require __DIR__ . '/layout/header.php';
?>

            <?php if (empty($warehouses) && $dbError === ''): ?>
                <div class="alert-error"><i class="fas fa-lock"></i> Tài khoản của bạn chưa được phân quyền kho nào nên không thể lập phiếu.</div>
            <?php else: ?>
            <form method="post" action="../handle/voucher_process.php" id="voucherForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="type" value="<?= $type ?>">
                <input type="hidden" name="client_token" value="<?= e($clientToken) ?>">

                <!-- PHẦN ĐẦU PHIẾU -->
                <div class="form-card">
                    <h3><i class="fas fa-file-alt"></i> Thông tin phiếu</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="warehouseId"><?= $fromLabel ?> *</label>
                            <!-- Chỉ liệt kê kho được phân quyền; server vẫn kiểm tra lại khi lưu -->
                            <select class="form-control" id="warehouseId" name="warehouse_id" required>
                                <?php foreach ($warehouses as $warehouse): ?>
                                    <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $selectedWarehouse ? 'selected' : '' ?>>
                                        <?= e($warehouse['code'] . ' - ' . $warehouse['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($type === 'TRANSFER'): ?>
                            <div class="form-group">
                                <label for="toWarehouseId">Kho đến *</label>
                                <select class="form-control" id="toWarehouseId" name="to_warehouse_id" required>
                                    <option value="">-- Chọn kho đến --</option>
                                    <?php foreach ($toWarehouses as $warehouse): ?>
                                        <option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $selectedToWarehouse ? 'selected' : '' ?>>
                                            <?= e($warehouse['code'] . ' - ' . $warehouse['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label for="voucherDate">Ngày lập *</label>
                            <input class="form-control" type="date" id="voucherDate" name="voucher_date" required
                                   value="<?= e($old['voucher_date'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label>Người lập</label>
                            <input class="form-control" value="<?= e($user['full_name']) ?>" disabled>
                        </div>
                        <div class="form-group span-all">
                            <label for="reason"><?= $reasonLabel[0] ?></label>
                            <input class="form-control" id="reason" name="reason" maxlength="255"
                                   value="<?= e($old['reason'] ?? '') ?>"
                                   placeholder="<?= $reasonLabel[1] ?>">
                        </div>
                    </div>
                </div>

                <!-- CHI TIẾT HÀNG -->
                <div class="form-card">
                    <div class="panel-title-row">
                        <h3><i class="fas fa-list"></i> Chi tiết hàng hóa</h3>
                        <button type="button" class="btn btn-export btn-sm" id="addLine"><i class="fas fa-plus"></i> Thêm dòng</button>
                    </div>
                    <?php if (!$isImport): ?>
                        <p class="form-hint">Chỉ hiện các lô còn tồn tại <?= $type === 'TRANSFER' ? 'kho đi' : 'kho đã chọn' ?>, lô hết hạn sớm xếp trước. Số tồn được kiểm tra lại khi duyệt phiếu.</p>
                    <?php else: ?>
                        <p class="form-hint">Chọn lô có sẵn hoặc chọn "+ Tạo lô mới" để khai báo số lô và hạn sử dụng.</p>
                    <?php endif; ?>
                    <div class="table-container line-table">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th style="width: 32%;">Mặt hàng *</th>
                                    <th>Lô hàng *</th>
                                    <th style="width: 140px;">Số lượng *</th>
                                    <th style="width: 70px;">ĐVT</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="lineRows"></tbody>
                        </table>
                    </div>
                </div>

                <div class="form-actions">
                    <a class="btn btn-light" href="voucher_list.php?type=<?= $type ?>">Hủy</a>
                    <button class="btn btn-primary" type="submit" id="submitVoucher"><i class="fas fa-save"></i> Lưu phiếu (chờ duyệt)</button>
                </div>
            </form>

            <script>
                window.VOUCHER_FORM = <?= json_encode($formData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            </script>
            <?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
