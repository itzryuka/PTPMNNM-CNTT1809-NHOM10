<?php
// Chứng từ kho: lập phiếu, duyệt (nhập: đồng ý; xuất / điều chuyển: giữ chỗ hàng; điều chỉnh: ghi sổ ngay), từ chối.
// Các bước sau khi duyệt (nhận hàng, xuất kho, gửi / nhận điều chuyển) nằm trong voucher_flow_functions.php

/** Lỗi nghiệp vụ: thông báo hiển thị thẳng cho người dùng */
class VoucherException extends AppException
{
}

const VOUCHER_PREFIX = ['IMPORT' => 'PN', 'EXPORT' => 'PX', 'TRANSFER' => 'PC', 'ADJUSTMENT' => 'DC'];

require_once __DIR__ . '/voucher_flow_functions.php';

// =====================================================================
// TRA CỨU
// =====================================================================

/**
 * Điều kiện lọc danh sách phiếu. $filters = [keyword, status, warehouse_id]
 * Chỉ hiện phiếu liên quan tới kho user được phân quyền (lọc ở server):
 * kho chính, hoặc kho đến với phiếu điều chuyển (kho nhận cũng thấy hàng chuyển tới).
 */
function buildVoucherCondition(string $type, array $filters, array $allowedWarehouses): array
{
    [$fromFilter, $fromParams] = warehouseFilter('v.warehouse_id', $allowedWarehouses);
    [$toFilter, $toParams]     = warehouseFilter('v.to_warehouse_id', $allowedWarehouses);
    $sql    = "v.type = ? AND ($fromFilter OR $toFilter)";
    $params = array_merge([$type], $fromParams, $toParams);

    if ($filters['status'] !== '') {
        $sql     .= ' AND v.status = ?';
        $params[] = $filters['status'];
    }
    if ($filters['warehouse_id'] > 0) {
        $sql     .= ' AND (v.warehouse_id = ? OR v.to_warehouse_id = ?)';
        $params[] = $filters['warehouse_id'];
        $params[] = $filters['warehouse_id'];
    }
    if ($filters['keyword'] !== '') {
        $sql     .= ' AND (v.code LIKE ? OR v.reason LIKE ?)';
        $params[] = "%{$filters['keyword']}%";
        $params[] = "%{$filters['keyword']}%";
    }
    return [$sql, $params];
}

function countVouchers(PDO $pdo, string $type, array $filters, array $allowedWarehouses): int
{
    [$where, $params] = buildVoucherCondition($type, $filters, $allowedWarehouses);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vouchers v WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

// Cột sắp xếp được của danh sách phiếu (khóa trên URL => biểu thức SQL)
const VOUCHER_SORT_COLUMNS = [
    'date'      => 'v.voucher_date',
    'code'      => 'v.code',
    'warehouse' => 'w.name',
    'lines'     => 'line_count',
    'total_qty' => 'total_qty',
    'creator'   => 'u.full_name',
    'status'    => 'v.status',
];

/** $sort = [khóa VOUCHER_SORT_COLUMNS, 'asc'|'desc']; mặc định phiếu mới nhất trước */
function getVouchers(PDO $pdo, string $type, array $filters, array $allowedWarehouses, int $limit, int $offset,
                     array $sort = ['date', 'desc']): array
{
    [$where, $params] = buildVoucherCondition($type, $filters, $allowedWarehouses);
    $orderBy = orderByClause(VOUCHER_SORT_COLUMNS, $sort, 'v.id DESC');
    $limit   = max(1, $limit);
    $offset  = max(0, $offset);

    $stmt = $pdo->prepare("
        SELECT v.id, v.code, v.status, v.voucher_date, v.reason,
               w.code AS warehouse_code, w.name AS warehouse_name, tw.code AS to_warehouse_code, tw.name AS to_warehouse_name,
               u.full_name AS creator_name,
               COUNT(vl.id) AS line_count, COALESCE(SUM(vl.quantity), 0) AS total_qty
        FROM vouchers v
        JOIN warehouses w       ON w.id = v.warehouse_id
        LEFT JOIN warehouses tw ON tw.id = v.to_warehouse_id
        JOIN users u            ON u.id = v.created_by
        LEFT JOIN voucher_lines vl ON vl.voucher_id = v.id
        WHERE $where
        GROUP BY v.id, v.code, v.status, v.voucher_date, v.reason, w.code, w.name, tw.code, tw.name, u.full_name
        ORDER BY $orderBy
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getVoucher(PDO $pdo, int $voucherId): ?array
{
    $stmt = $pdo->prepare("
        SELECT v.*,
               w.code AS warehouse_code, w.name AS warehouse_name,
               tw.code AS to_warehouse_code, tw.name AS to_warehouse_name,
               rv.code AS reference_code,
               sc.id AS stock_count_id, sc.code AS stock_count_code,
               cu.full_name AS creator_name, au.full_name AS approver_name
        FROM vouchers v
        JOIN warehouses w       ON w.id = v.warehouse_id
        LEFT JOIN warehouses tw ON tw.id = v.to_warehouse_id
        LEFT JOIN vouchers rv   ON rv.id = v.reference_voucher_id
        LEFT JOIN stock_counts sc ON sc.adjustment_voucher_id = v.id
        JOIN users cu           ON cu.id = v.created_by
        LEFT JOIN users au      ON au.id = v.approved_by
        WHERE v.id = ?
    ");
    $stmt->execute([$voucherId]);
    return $stmt->fetch() ?: null;
}

/** Dòng chứng từ kèm tiến độ và tồn hiện tại của lô tại kho chính (để "kiểm tra" trước khi duyệt) */
function getVoucherLines(PDO $pdo, int $voucherId, int $warehouseId): array
{
    $stmt = $pdo->prepare("
        SELECT vl.id, vl.item_id, vl.batch_id, vl.quantity, vl.note,
               vl.received_qty, vl.damaged_qty, vl.shipped_qty, vl.lost_qty,
               i.sku, i.name AS item_name, i.unit,
               b.batch_no, b.expiry_date,
               COALESCE(s.quantity, 0) AS current_qty, COALESCE(s.reserved, 0) AS reserved_qty,
               COALESCE(s.quantity, 0) - COALESCE(s.reserved, 0) AS available_qty
        FROM voucher_lines vl
        JOIN items i   ON i.id = vl.item_id
        JOIN batches b ON b.id = vl.batch_id
        LEFT JOIN stocks s ON s.warehouse_id = ? AND s.item_id = vl.item_id AND s.batch_id = vl.batch_id
        WHERE vl.voucher_id = ?
        ORDER BY vl.id
    ");
    $stmt->execute([$warehouseId, $voucherId]);
    return $stmt->fetchAll();
}

/** Các dòng sổ phát sinh mà chứng từ đã ghi */
function getVoucherMovements(PDO $pdo, int $voucherId): array
{
    $stmt = $pdo->prepare("
        SELECT sm.id, sm.quantity, sm.moved_at, p.code AS posting_code, p.kind AS posting_kind,
               w.code AS warehouse_code, w.name AS warehouse_name, w.is_transit, i.sku, i.name AS item_name, i.unit, b.batch_no,
               u.full_name AS created_by_name
        FROM stock_movements sm
        JOIN voucher_postings p ON p.id = sm.posting_id
        JOIN warehouses w ON w.id = sm.warehouse_id
        JOIN items i      ON i.id = sm.item_id
        JOIN batches b    ON b.id = sm.batch_id
        JOIN users u      ON u.id = sm.created_by
        WHERE sm.voucher_id = ?
        ORDER BY sm.id
    ");
    $stmt->execute([$voucherId]);
    return $stmt->fetchAll();
}

/** Đợt kiểm kê đang mở tại các kho (kho đang bị "đóng băng") */
function getOpenStockCounts(PDO $pdo, array $warehouseIds, bool $lock = false): array
{
    if (empty($warehouseIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($warehouseIds), '?'));
    $stmt = $pdo->prepare("
        SELECT sc.id, sc.code, w.code AS warehouse_code
        FROM stock_counts sc
        JOIN warehouses w ON w.id = sc.warehouse_id
        WHERE sc.warehouse_id IN ($placeholders) AND sc.status = 'COUNTING'
        " . ($lock ? 'LOCK IN SHARE MODE' : ''));
    $stmt->execute(array_map('intval', $warehouseIds));
    return $stmt->fetchAll();
}

// ----- Dữ liệu cho form lập phiếu -----

/** $allowedWarehouses = null -> mọi kho đang hoạt động (vd chọn kho đến khi điều chuyển). Không gồm kho trung chuyển. */
function getWarehouseOptions(PDO $pdo, ?array $allowedWarehouses): array
{
    [$whFilter, $whParams] = warehouseFilter('w.id', $allowedWarehouses);
    $stmt = $pdo->prepare("SELECT w.id, w.code, w.name FROM warehouses w WHERE w.is_active = 1 AND w.is_transit = 0 AND $whFilter ORDER BY w.code");
    $stmt->execute($whParams);
    return $stmt->fetchAll();
}

function getItemOptions(PDO $pdo): array
{
    return $pdo->query('SELECT id, sku, name, unit FROM items WHERE is_active = 1 ORDER BY sku')->fetchAll();
}

function getBatchOptions(PDO $pdo): array
{
    return $pdo->query('SELECT id, item_id, batch_no, expiry_date FROM batches ORDER BY item_id, expiry_date')->fetchAll();
}

/**
 * Lô còn hàng CÓ THỂ XUẤT (tồn - đang giữ cho phiếu đã duyệt) tại các kho user được phép,
 * hết hạn trước xếp trước (gợi ý xuất FEFO). Cột quantity = số có thể xuất.
 */
function getStockOptions(PDO $pdo, array $allowedWarehouses): array
{
    [$whFilter, $whParams] = warehouseFilter('s.warehouse_id', $allowedWarehouses);
    $stmt = $pdo->prepare("
        SELECT s.warehouse_id, s.item_id, s.batch_id, s.quantity - s.reserved AS quantity, b.batch_no, b.expiry_date
        FROM stocks s
        JOIN batches b ON b.id = s.batch_id
        WHERE s.quantity - s.reserved > 0 AND $whFilter
        ORDER BY s.warehouse_id, s.item_id, b.expiry_date, b.batch_no
    ");
    $stmt->execute($whParams);
    return $stmt->fetchAll();
}

// =====================================================================
// LẬP PHIẾU
// =====================================================================

/**
 * Lập phiếu nhập / xuất / điều chuyển ở trạng thái PENDING. Trả về id phiếu.
 * $input: type, warehouse_id, to_warehouse_id (chỉ TRANSFER), voucher_date, reason, client_token, lines[]
 *   mỗi dòng: item_id, batch_id (id lô | 'new' khi nhập), new_batch_no, new_mfg, new_expiry, quantity
 */
function createVoucher(PDO $pdo, array $user, array $input): int
{
    $type          = (string) ($input['type'] ?? '');
    $warehouseId   = (int) ($input['warehouse_id'] ?? 0);
    $toWarehouseId = $type === 'TRANSFER' ? (int) ($input['to_warehouse_id'] ?? 0) : null;
    $voucherDate   = (string) ($input['voucher_date'] ?? '');
    $reason        = trim((string) ($input['reason'] ?? ''));
    $token         = (string) ($input['client_token'] ?? '');

    if (!in_array($type, ['IMPORT', 'EXPORT', 'TRANSFER'], true)) {
        throw new VoucherException('Loại phiếu không hợp lệ.');
    }
    // PHÂN QUYỀN PHÍA SERVER: kho gửi lên (kho đi nếu điều chuyển) phải thuộc danh sách kho user phụ trách.
    // Sửa HTML / gửi request trực tiếp (Postman) với kho khác đều bị chặn tại đây.
    if (!canAccessWarehouse($user, $warehouseId)) {
        throw new VoucherException('Lỗi bảo mật: bạn không có quyền lập phiếu cho kho này.');
    }
    if ($type === 'TRANSFER' && ($toWarehouseId <= 0 || $toWarehouseId === $warehouseId)) {
        throw new VoucherException('Kho đến phải khác kho đi.');
    }
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        throw new VoucherException('Biểu mẫu không hợp lệ, vui lòng tải lại trang.');
    }
    if (!isValidDate($voucherDate) || $voucherDate > date('Y-m-d')) {
        throw new VoucherException('Ngày lập phiếu không hợp lệ (không được sau ngày hôm nay).');
    }
    if (mb_strlen($reason) > 255) {
        throw new VoucherException('Lý do / ghi chú tối đa 255 ký tự.');
    }
    $lines = normalizeVoucherLines($type, is_array($input['lines'] ?? null) ? $input['lines'] : []);

    // Gửi lại form đã lưu (bấm Lưu 2 lần, F5...) -> trả về phiếu đã tạo, không tạo thêm
    $existingId = findVoucherByToken($pdo, $token);
    if ($existingId !== null) {
        return $existingId;
    }

    // Nhập nhiều phiếu từ file: hàm gọi đã mở transaction chung (tất cả hoặc không) -> không mở / chốt riêng
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('SELECT is_active = 1 AND is_transit = 0 FROM warehouses WHERE id = ?');
        foreach (array_filter([$warehouseId, $toWarehouseId]) as $id) {
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() !== 1) {
                throw new VoucherException('Kho không tồn tại hoặc đã ngừng hoạt động.');
            }
        }

        $seen = [];
        foreach ($lines as $n => &$line) {
            $label = 'Dòng ' . ($n + 1) . ': ';

            $stmt = $pdo->prepare('SELECT sku FROM items WHERE id = ? AND is_active = 1');
            $stmt->execute([$line['item_id']]);
            if ($stmt->fetchColumn() === false) {
                throw new VoucherException($label . 'mặt hàng không tồn tại.');
            }

            if ($line['new_batch'] !== null) {
                $line['batch_id'] = createBatch($pdo, $line['item_id'], $line['new_batch'], $label);
            } else {
                $stmt = $pdo->prepare('SELECT expiry_date FROM batches WHERE id = ? AND item_id = ?');
                $stmt->execute([$line['batch_id'], $line['item_id']]);
                $expiry = $stmt->fetchColumn();
                if ($expiry === false) {
                    throw new VoucherException($label . 'lô hàng không thuộc mặt hàng đã chọn.');
                }
                if ($type !== 'IMPORT') {               // xuất / điều chuyển: lấy hàng khỏi kho chính
                    if ($expiry !== null && $expiry < date('Y-m-d')) {
                        throw new VoucherException($label . 'lô đã hết hạn, không được xuất / điều chuyển.');
                    }
                    // Kiểm tra sớm cho người lập. Kiểm tra QUYẾT ĐỊNH vẫn nằm ở bước duyệt (có khóa, giữ chỗ).
                    $available = getStockQty($pdo, $warehouseId, $line['item_id'], $line['batch_id']);
                    if (toMilli($available) < toMilli($line['quantity'])) {
                        throw new VoucherException($label . 'không đủ tồn có thể xuất (còn ' . formatQty($available) . ').');
                    }
                }
            }

            $key = $line['item_id'] . ':' . $line['batch_id'];
            if (isset($seen[$key])) {
                throw new VoucherException($label . 'trùng mặt hàng và lô với dòng ' . $seen[$key] . '. Hãy gộp số lượng vào 1 dòng.');
            }
            $seen[$key] = $n + 1;
        }
        unset($line);

        $code = nextVoucherCode($pdo, $type, $voucherDate);
        $stmt = $pdo->prepare('
            INSERT INTO vouchers (code, type, warehouse_id, to_warehouse_id, voucher_date, reason, client_token, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$code, $type, $warehouseId, $toWarehouseId, $voucherDate, $reason === '' ? null : $reason, $token, $user['id']]);
        $voucherId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity) VALUES (?, ?, ?, ?)');
        foreach ($lines as $line) {
            $stmt->execute([$voucherId, $line['item_id'], $line['batch_id'], $line['quantity']]);
        }

        if ($ownTransaction) {
            $pdo->commit();
        }
        return $voucherId;
    } catch (PDOException $e) {
        if (!$ownTransaction) {
            throw $e;
        }
        $pdo->rollBack();
        // 2 request cùng token gửi đồng thời: request sau vi phạm UNIQUE(client_token) -> trả về phiếu của request trước
        if (($e->errorInfo[1] ?? 0) === 1062 && ($existingId = findVoucherByToken($pdo, $token)) !== null) {
            return $existingId;
        }
        throw $e;
    } catch (Throwable $e) {
        if ($ownTransaction) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Kiểm tra định dạng từng dòng gửi lên; bỏ qua dòng trống hoàn toàn */
function normalizeVoucherLines(string $type, array $rawLines): array
{
    $lines = [];
    foreach (array_values($rawLines) as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $itemId   = (int) ($raw['item_id'] ?? 0);
        $batchRaw = (string) ($raw['batch_id'] ?? '');
        $qtyRaw   = trim((string) ($raw['quantity'] ?? ''));
        if ($itemId === 0 && $batchRaw === '' && $qtyRaw === '') {
            continue;
        }

        $label = 'Dòng ' . (count($lines) + 1) . ': ';
        if ($itemId <= 0) {
            throw new VoucherException($label . 'chưa chọn mặt hàng.');
        }
        if (!preg_match('/^\d{1,12}(\.\d{1,3})?$/', $qtyRaw) || (float) $qtyRaw <= 0) {
            throw new VoucherException($label . 'số lượng phải là số dương (tối đa 3 chữ số thập phân).');
        }

        $newBatch = null;
        $batchId  = 0;
        if ($type === 'IMPORT' && $batchRaw === 'new') {
            $newBatch = [
                'batch_no' => trim((string) ($raw['new_batch_no'] ?? '')),
                'mfg'      => (string) ($raw['new_mfg'] ?? ''),
                'expiry'   => (string) ($raw['new_expiry'] ?? ''),
            ];
            if (!preg_match('/^[A-Za-z0-9._\/-]{1,50}$/', $newBatch['batch_no'])) {
                throw new VoucherException($label . 'số lô mới chỉ gồm chữ, số và . _ / - (tối đa 50 ký tự).');
            }
            if (!isValidDate($newBatch['expiry'])) {
                throw new VoucherException($label . 'lô mới phải có hạn sử dụng.');
            }
            if ($newBatch['mfg'] !== '' && (!isValidDate($newBatch['mfg']) || $newBatch['mfg'] >= $newBatch['expiry'])) {
                throw new VoucherException($label . 'ngày sản xuất phải trước hạn sử dụng.');
            }
        } else {
            $batchId = (int) $batchRaw;
            if ($batchId <= 0) {
                throw new VoucherException($label . 'chưa chọn lô hàng.');
            }
        }

        $lines[] = ['item_id' => $itemId, 'batch_id' => $batchId, 'new_batch' => $newBatch, 'quantity' => $qtyRaw];
    }

    if (empty($lines)) {
        throw new VoucherException('Phiếu phải có ít nhất 1 dòng hàng.');
    }
    return $lines;
}

/** Tạo lô mới khi nhập hàng (nằm trong transaction lập phiếu) */
function createBatch(PDO $pdo, int $itemId, array $batch, string $label): int
{
    $stmt = $pdo->prepare('SELECT id FROM batches WHERE item_id = ? AND batch_no = ?');
    $stmt->execute([$itemId, $batch['batch_no']]);
    if ($stmt->fetchColumn() !== false) {
        throw new VoucherException($label . 'lô ' . $batch['batch_no'] . ' đã tồn tại, hãy chọn trong danh sách lô có sẵn.');
    }
    $stmt = $pdo->prepare('INSERT INTO batches (item_id, batch_no, mfg_date, expiry_date) VALUES (?, ?, ?, ?)');
    $stmt->execute([$itemId, $batch['batch_no'], $batch['mfg'] === '' ? null : $batch['mfg'], $batch['expiry']]);
    return (int) $pdo->lastInsertId();
}

function findVoucherByToken(PDO $pdo, string $token): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM vouchers WHERE client_token = ?');
    $stmt->execute([$token]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** Mã phiếu tiếp theo, vd PN-2026-0021 */
function nextVoucherCode(PDO $pdo, string $type, string $voucherDate): string
{
    return nextDocumentCode($pdo, 'vouchers', VOUCHER_PREFIX[$type] . '-' . substr($voucherDate, 0, 4) . '-');
}

/**
 * Số chứng từ tiếp theo theo tiền tố, vd 'KK-2026-' -> KK-2026-0002.
 * FOR UPDATE (phải nằm trong transaction) để 2 người lập cùng lúc không trùng số.
 */
function nextDocumentCode(PDO $pdo, string $table, string $prefix): string
{
    if (!in_array($table, ['vouchers', 'stock_counts', 'transfer_requests'], true)) {
        throw new InvalidArgumentException("Bảng không hợp lệ: $table");
    }
    $stmt = $pdo->prepare("SELECT code FROM $table WHERE code LIKE ? ORDER BY code DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = $last === false ? 1 : (int) substr($last, strlen($prefix)) + 1;
    return $prefix . sprintf('%04d', $next);
}

/** Số CÓ THỂ XUẤT của 1 lô tại kho = tồn - đang giữ cho phiếu đã duyệt */
function getStockQty(PDO $pdo, int $warehouseId, int $itemId, int $batchId): string
{
    $stmt = $pdo->prepare('SELECT quantity - reserved FROM stocks WHERE warehouse_id = ? AND item_id = ? AND batch_id = ?');
    $stmt->execute([$warehouseId, $itemId, $batchId]);
    $qty = $stmt->fetchColumn();
    return $qty === false ? '0' : (string) $qty;
}

function isValidDate(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

// =====================================================================
// DUYỆT PHIẾU — TRÁI TIM CỦA HỆ THỐNG
// =====================================================================

/**
 * Duyệt chứng từ, trong MỘT transaction (lỗi ở bất kỳ bước nào -> rollBack toàn bộ):
 *   IMPORT     : đồng ý nhập -> chờ hàng về (tồn chưa đổi; số thực nhận được ghi ở bước "Nhận hàng")
 *   EXPORT     : GIỮ CHỖ hàng ở kho (có thể xuất giảm, tồn thực chưa đổi; trừ tồn ở bước "Đã xuất kho")
 *   TRANSFER   : GIỮ CHỖ hàng ở kho đi (trừ tồn ở bước "Gửi hàng")
 *   ADJUSTMENT : ghi sổ phát sinh + cập nhật tồn ngay (postVoucher)
 *
 *  1. Khóa phiếu (FOR UPDATE)  -> 2 người bấm duyệt cùng lúc: người sau chờ, rồi thấy phiếu đã duyệt -> bị từ chối
 *  2. Kiểm tra quyền (quản lý của kho chính) + kho không đang kiểm kê (với phiếu giữ hàng)
 *  3. Khóa từng dòng tồn (FOR UPDATE) theo lô -> 2 phiếu cùng giữ 1 lô: phiếu sau chờ, đọc lại số đang giữ
 *     mới -> không đủ "có thể xuất" thì bị từ chối. CHECK reserved <= quantity là lưới an toàn cuối.
 *  4. Đổi trạng thái APPROVED sau cùng
 */
function approveVoucher(PDO $pdo, array $user, int $voucherId): void
{
    inTransaction($pdo, function () use ($pdo, $user, $voucherId) {
        $voucher = lockVoucher($pdo, $voucherId);
        if ($voucher['type'] === 'ADJUSTMENT') {
            postVoucher($pdo, $user, $voucherId);
            return;
        }
        if ($voucher['status'] !== 'PENDING') {
            throw new VoucherException("Chứng từ {$voucher['code']} đã được xử lý trước đó ("
                . voucherStatusLabel($voucher['status'], $voucher['type']) . '). Không duyệt / ghi nhận thêm lần nữa.');
        }
        assertCanDecide($user, [(int) $voucher['warehouse_id']]);
        if (in_array($voucher['type'], ['EXPORT', 'TRANSFER'], true)) {
            assertNotCounting($pdo, [(int) $voucher['warehouse_id']]);
            reserveVoucherLines($pdo, $voucher, lockVoucherLines($pdo, $voucherId));
        }
        $stmt = $pdo->prepare("UPDATE vouchers SET status = 'APPROVED', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$user['id'], $voucherId]);
    });
}

/**
 * Ghi sổ phiếu điều chỉnh: 1 đợt ghi sổ ADJUST + phát sinh có dấu + cập nhật tồn, rồi APPROVED.
 * PHẢI được gọi bên trong 1 transaction đang mở (approveVoucher, duyệt kiểm kê, xử lý hao hụt điều chuyển).
 * $countingCountId: đợt kiểm kê đang được chốt -> không tính là "kho đang kiểm kê" với chính phiếu điều chỉnh của nó.
 * $checkAccess = false: phiếu hệ thống tự lập ở kho trung chuyển (không phân quyền cho ai).
 */
function postVoucher(PDO $pdo, array $user, int $voucherId, ?int $countingCountId = null, bool $checkAccess = true): void
{
    $voucher = lockVoucher($pdo, $voucherId);
    if ($voucher['status'] !== 'PENDING') {
        throw new VoucherException("Chứng từ {$voucher['code']} đã được xử lý trước đó ("
            . voucherStatusLabel($voucher['status'], $voucher['type']) . '). Không ghi nhận thêm lần nữa.');
    }
    if ($voucher['type'] !== 'ADJUSTMENT') {
        throw new LogicException('postVoucher chỉ dùng cho phiếu điều chỉnh.');
    }
    if ($checkAccess) {
        assertCanDecide($user, [(int) $voucher['warehouse_id']]);
    }
    assertNotCounting($pdo, [(int) $voucher['warehouse_id']], $countingCountId);
    $lines = lockVoucherLines($pdo, $voucherId);

    $postingId = openPosting($pdo, $voucher, 'ADJUST', $user, null, null);
    $changes   = [];
    foreach ($lines as $line) {
        addPostingLine($pdo, $postingId, (int) $line['id'], $line['quantity']);
        $changes[] = [$line, (int) $voucher['warehouse_id'], $line['quantity'], '0'];
    }
    applyStockChanges($pdo, $voucher, $postingId, $changes, $user);

    $stmt = $pdo->prepare("UPDATE vouchers SET status = 'APPROVED', approved_by = ?, approved_at = NOW() WHERE id = ?");
    $stmt->execute([$user['id'], $voucherId]);
}

/** Từ chối phiếu chờ duyệt: không phát sinh gì, phiếu bị khóa vĩnh viễn */
function rejectVoucher(PDO $pdo, array $user, int $voucherId): void
{
    inTransaction($pdo, function () use ($pdo, $user, $voucherId) {
        $voucher = lockVoucher($pdo, $voucherId);
        if ($voucher['status'] !== 'PENDING') {
            throw new VoucherException("Chứng từ {$voucher['code']} đã được xử lý trước đó (" . voucherStatusLabel($voucher['status'], $voucher['type']) . ').');
        }
        assertCanDecide($user, [(int) $voucher['warehouse_id']]);
        $stmt = $pdo->prepare("UPDATE vouchers SET status = 'REJECTED', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$user['id'], $voucherId]);
    });
}

function assertCanDecide(array $user, array $warehouseIds): void
{
    if (!isManager($user)) {
        throw new VoucherException('Chỉ quản lý kho mới được duyệt / từ chối chứng từ.');
    }
    foreach ($warehouseIds as $warehouseId) {
        if (!canAccessWarehouse($user, $warehouseId)) {
            throw new VoucherException('Lỗi bảo mật: bạn không có quyền trên kho của chứng từ này.');
        }
    }
}

/** Kho đang kiểm kê thì không được làm thay đổi tồn / giữ hàng (đóng băng tồn kho) */
function assertNotCounting(PDO $pdo, array $warehouseIds, ?int $exceptCountId = null): void
{
    // LOCK IN SHARE MODE: chặn việc mở đợt kiểm kê mới cho kho này cho tới khi giao dịch xong
    $openCounts = array_filter(
        getOpenStockCounts($pdo, $warehouseIds, true),
        fn ($count) => (int) $count['id'] !== $exceptCountId
    );
    if (!empty($openCounts)) {
        $count = array_values($openCounts)[0];
        throw new VoucherException("Kho {$count['warehouse_code']} đang kiểm kê (đợt {$count['code']}): tạm thời không nhận / xuất / gửi / giữ hàng được. Hãy chờ chốt kiểm kê.");
    }
}

// =====================================================================
// HIỂN THỊ THEO LOẠI PHIẾU
// =====================================================================

/** Đọc loại phiếu từ request, chỉ chấp nhận các loại trong $allowed (mặc định: loại đầu tiên) */
function requestedVoucherType($value, array $allowed = ['IMPORT', 'EXPORT', 'TRANSFER', 'ADJUSTMENT']): string
{
    return in_array($value, $allowed, true) ? $value : $allowed[0];
}

/** Tiêu đề trang danh sách và mục menu tương ứng của từng loại phiếu */
function voucherPageInfo(string $type): array
{
    return [
        'IMPORT'     => ['title' => 'Phiếu Nhập Kho',   'menu' => 'imports',     'create' => 'Lập phiếu nhập'],
        'EXPORT'     => ['title' => 'Phiếu Xuất Kho',   'menu' => 'exports',     'create' => 'Lập phiếu xuất'],
        'TRANSFER'   => ['title' => 'Phiếu Điều Chuyển', 'menu' => 'transfers',  'create' => 'Lập phiếu điều chuyển'],
        'ADJUSTMENT' => ['title' => 'Phiếu Điều Chỉnh', 'menu' => 'adjustments', 'create' => null],
    ][$type];
}
