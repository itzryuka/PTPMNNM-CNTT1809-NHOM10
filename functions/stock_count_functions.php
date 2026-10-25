<?php
// Kiểm kê: tạo đợt (chụp tồn + đóng băng kho) -> nhập số đếm -> duyệt (sinh phiếu điều chỉnh có lý do) / hủy
// Dùng chung VoucherException, postVoucher(), nextDocumentCode() trong voucher_functions.php

// =====================================================================
// TRA CỨU
// =====================================================================

function buildStockCountCondition(array $filters, array $allowedWarehouses): array
{
    [$whFilter, $params] = warehouseFilter('sc.warehouse_id', $allowedWarehouses);
    $sql = $whFilter;
    if ($filters['status'] !== '') {
        $sql     .= ' AND sc.status = ?';
        $params[] = $filters['status'];
    }
    if ($filters['warehouse_id'] > 0) {
        $sql     .= ' AND sc.warehouse_id = ?';
        $params[] = $filters['warehouse_id'];
    }
    if ($filters['keyword'] !== '') {
        $sql     .= ' AND (sc.code LIKE ? OR sc.note LIKE ?)';
        $params[] = "%{$filters['keyword']}%";
        $params[] = "%{$filters['keyword']}%";
    }
    return [$sql, $params];
}

function countStockCounts(PDO $pdo, array $filters, array $allowedWarehouses): int
{
    [$where, $params] = buildStockCountCondition($filters, $allowedWarehouses);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_counts sc WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function getStockCounts(PDO $pdo, array $filters, array $allowedWarehouses, int $limit, int $offset): array
{
    [$where, $params] = buildStockCountCondition($filters, $allowedWarehouses);
    $limit  = max(1, $limit);
    $offset = max(0, $offset);

    $stmt = $pdo->prepare("
        SELECT sc.id, sc.code, sc.status, sc.note, sc.created_at, sc.adjustment_voucher_id,
               w.name AS warehouse_name, u.full_name AS creator_name, v.code AS adjustment_code,
               COUNT(l.id)                                         AS line_count,
               SUM(l.counted_qty IS NOT NULL)                      AS counted_count,
               SUM(l.counted_qty IS NOT NULL AND l.difference <> 0) AS diff_count
        FROM stock_counts sc
        JOIN warehouses w ON w.id = sc.warehouse_id
        JOIN users u      ON u.id = sc.created_by
        LEFT JOIN vouchers v ON v.id = sc.adjustment_voucher_id
        LEFT JOIN stock_count_lines l ON l.count_id = sc.id
        WHERE $where
        GROUP BY sc.id, sc.code, sc.status, sc.note, sc.created_at, sc.adjustment_voucher_id, w.name, u.full_name, v.code
        ORDER BY sc.created_at DESC, sc.id DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getStockCount(PDO $pdo, int $countId): ?array
{
    $stmt = $pdo->prepare("
        SELECT sc.*, w.code AS warehouse_code, w.name AS warehouse_name,
               cu.full_name AS creator_name, au.full_name AS approver_name,
               v.code AS adjustment_code, v.reason AS adjustment_reason
        FROM stock_counts sc
        JOIN warehouses w   ON w.id = sc.warehouse_id
        JOIN users cu       ON cu.id = sc.created_by
        LEFT JOIN users au  ON au.id = sc.approved_by
        LEFT JOIN vouchers v ON v.id = sc.adjustment_voucher_id
        WHERE sc.id = ?
    ");
    $stmt->execute([$countId]);
    return $stmt->fetch() ?: null;
}

function getStockCountLines(PDO $pdo, int $countId): array
{
    $stmt = $pdo->prepare("
        SELECT l.id, l.item_id, l.batch_id, l.system_qty, l.counted_qty, l.difference, l.reason, l.counted_at,
               i.sku, i.name AS item_name, i.unit, b.batch_no, b.expiry_date,
               u.full_name AS counted_by_name
        FROM stock_count_lines l
        JOIN items i   ON i.id = l.item_id
        JOIN batches b ON b.id = l.batch_id
        LEFT JOIN users u ON u.id = l.counted_by
        WHERE l.count_id = ?
        ORDER BY i.sku, b.expiry_date, b.batch_no
    ");
    $stmt->execute([$countId]);
    return $stmt->fetchAll();
}

function stockCountStatusBadge(string $status): string
{
    [$class, $label] = [
        'COUNTING'  => ['badge-pending', 'Đang kiểm kê'],
        'APPROVED'  => ['badge-active', 'Đã duyệt'],
        'CANCELLED' => ['badge-inactive', 'Đã hủy'],
    ][$status] ?? ['badge-inactive', $status];
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

// =====================================================================
// NGHIỆP VỤ
// =====================================================================

/**
 * Tạo đợt kiểm kê: chụp (snapshot) số tồn hiện tại của mọi lô còn hàng trong kho.
 * Từ lúc này kho bị "đóng băng": phiếu nhập / xuất / điều chuyển của kho không duyệt được
 * cho tới khi đợt kiểm kê được duyệt hoặc hủy -> số tồn chụp lại luôn đúng tới lúc chốt.
 */
function createStockCount(PDO $pdo, array $user, int $warehouseId, string $note): int
{
    if (!isManager($user)) {
        throw new VoucherException('Chỉ quản lý kho mới được mở đợt kiểm kê (việc này tạm khóa mọi giao dịch của kho).');
    }
    if (!canAccessWarehouse($user, $warehouseId)) {
        throw new VoucherException('Lỗi bảo mật: bạn không có quyền trên kho này.');
    }
    $note = trim($note);
    if (mb_strlen($note) > 255) {
        throw new VoucherException('Ghi chú tối đa 255 ký tự.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT is_active FROM warehouses WHERE id = ?');
        $stmt->execute([$warehouseId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new VoucherException('Kho không tồn tại hoặc đã ngừng hoạt động.');
        }

        // Mỗi kho chỉ 1 đợt đang mở. FOR UPDATE khóa cả khoảng chỉ mục -> 2 người tạo cùng lúc: người sau chờ rồi bị chặn
        $stmt = $pdo->prepare("SELECT code FROM stock_counts WHERE warehouse_id = ? AND status = 'COUNTING' FOR UPDATE");
        $stmt->execute([$warehouseId]);
        $open = $stmt->fetchColumn();
        if ($open !== false) {
            throw new VoucherException("Kho đang có đợt kiểm kê $open chưa chốt.");
        }

        // Ghi đợt COUNTING TRƯỚC: phiếu đang duyệt dở (giữ khóa đọc trên stock_counts) phải xong thì lệnh này mới chạy;
        // phiếu duyệt sau đó sẽ chờ đợt này rồi thấy kho đang kiểm kê -> bị chặn. Thứ tự này tránh deadlock.
        $code = nextDocumentCode($pdo, 'stock_counts', 'KK-' . date('Y') . '-');
        $stmt = $pdo->prepare('INSERT INTO stock_counts (code, warehouse_id, status, note, created_by, created_at) VALUES (?, ?, \'COUNTING\', ?, ?, NOW())');
        $stmt->execute([$code, $warehouseId, $note === '' ? null : $note, $user['id']]);
        $countId = (int) $pdo->lastInsertId();

        // Khóa các dòng tồn của kho rồi mới chụp số
        $stmt = $pdo->prepare('SELECT id FROM stocks WHERE warehouse_id = ? FOR UPDATE');
        $stmt->execute([$warehouseId]);
        $stmt->fetchAll();

        $stmt = $pdo->prepare('
            INSERT INTO stock_count_lines (count_id, item_id, batch_id, system_qty)
            SELECT ?, item_id, batch_id, quantity FROM stocks WHERE warehouse_id = ? AND quantity > 0
        ');
        $stmt->execute([$countId, $warehouseId]);
        if ($stmt->rowCount() === 0) {
            throw new VoucherException('Kho chưa có hàng để kiểm kê.');
        }

        $pdo->commit();
        return $countId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Lưu số đếm thực tế (lưu nhiều lần được, khi còn COUNTING).
 * $entries = [line_id => ['counted_qty' => '12' | '', 'reason' => '...'], ...]; để trống = chưa đếm.
 */
function saveStockCountEntries(PDO $pdo, array $user, int $countId, array $entries): int
{
    $pdo->beginTransaction();
    try {
        $count = lockOpenStockCount($pdo, $countId);
        if (!canAccessWarehouse($user, (int) $count['warehouse_id'])) {
            throw new VoucherException('Lỗi bảo mật: bạn không có quyền trên kho này.');
        }

        $stmt = $pdo->prepare('SELECT id, counted_qty, reason FROM stock_count_lines WHERE count_id = ?');
        $stmt->execute([$countId]);
        $lines = [];
        foreach ($stmt->fetchAll() as $row) {
            $lines[(int) $row['id']] = $row;
        }

        $update = $pdo->prepare('UPDATE stock_count_lines SET counted_qty = ?, reason = ?, counted_by = ?, counted_at = NOW() WHERE id = ?');
        $changed = 0;
        foreach ($entries as $lineId => $entry) {
            $lineId = (int) $lineId;
            if (!isset($lines[$lineId]) || !is_array($entry)) {
                throw new VoucherException('Dòng kiểm kê không thuộc đợt này.');
            }
            $qty    = trim((string) ($entry['counted_qty'] ?? ''));
            $reason = trim((string) ($entry['reason'] ?? ''));
            if ($qty !== '' && !preg_match('/^\d{1,12}(\.\d{1,3})?$/', $qty)) {
                throw new VoucherException("Số đếm \"$qty\" không hợp lệ (số không âm, tối đa 3 chữ số thập phân).");
            }
            if (mb_strlen($reason) > 255) {
                throw new VoucherException('Lý do chênh lệch tối đa 255 ký tự.');
            }
            $qty    = $qty === '' ? null : $qty;
            $reason = $reason === '' ? null : $reason;

            $old = $lines[$lineId];
            $sameQty = $old['counted_qty'] === null ? $qty === null : ($qty !== null && toMilli($old['counted_qty']) === toMilli($qty));
            if ($sameQty && $old['reason'] === $reason) {
                continue;                                    // không đổi -> giữ nguyên người đếm / thời điểm đếm
            }
            $update->execute([$qty, $reason, $user['id'], $lineId]);
            $changed++;
        }

        $pdo->commit();
        return $changed;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Duyệt đợt kiểm kê. Trong CÙNG 1 transaction:
 *   1. kiểm tra đã đếm hết, dòng lệch nào cũng có lý do, tồn chưa đổi so với lúc chụp
 *   2. sinh 1 phiếu ADJUSTMENT (lý do chung + lý do từng dòng), dòng = chênh lệch có dấu
 *      (+ thực tế nhiều hơn -> nhập, - thực tế ít hơn -> xuất)
 *   3. ghi sổ phát sinh + cập nhật tồn qua postVoucher() (cùng logic khóa với phiếu thường)
 *   4. chốt đợt: APPROVED, gắn phiếu điều chỉnh -> kho hết đóng băng
 * Trả về id phiếu điều chỉnh (null nếu không có chênh lệch nào).
 */
function approveStockCount(PDO $pdo, array $user, int $countId, string $reason): ?int
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new VoucherException('Phải nhập lý do điều chỉnh.');
    }
    if (mb_strlen($reason) > 200) {
        throw new VoucherException('Lý do điều chỉnh tối đa 200 ký tự.');
    }

    $pdo->beginTransaction();
    try {
        $count = lockOpenStockCount($pdo, $countId);
        assertCanDecide($user, [(int) $count['warehouse_id']]);

        $stmt = $pdo->prepare('
            SELECT l.id, l.item_id, l.batch_id, l.system_qty, l.counted_qty, l.difference, l.reason, i.sku, b.batch_no,
                   COALESCE(s.quantity, 0) AS current_qty
            FROM stock_count_lines l
            JOIN items i   ON i.id = l.item_id
            JOIN batches b ON b.id = l.batch_id
            LEFT JOIN stocks s ON s.warehouse_id = ? AND s.item_id = l.item_id AND s.batch_id = l.batch_id
            WHERE l.count_id = ?
            ORDER BY l.batch_id
            FOR UPDATE
        ');
        $stmt->execute([$count['warehouse_id'], $countId]);
        $lines = $stmt->fetchAll();

        $uncounted = array_filter($lines, fn ($l) => $l['counted_qty'] === null);
        if ($uncounted) {
            throw new VoucherException('Còn ' . count($uncounted) . ' dòng chưa nhập số đếm thực tế.');
        }
        $differences = array_filter($lines, fn ($l) => toMilli($l['difference']) !== 0);
        foreach ($differences as $line) {
            if ($line['reason'] === null || trim($line['reason']) === '') {
                throw new VoucherException("Dòng {$line['sku']} - lô {$line['batch_no']} lệch " . formatQty($line['difference']) . ' nhưng chưa có lý do chênh lệch.');
            }
        }
        foreach ($lines as $line) {
            // Kho đã bị đóng băng nên tồn phải đúng bằng số đã chụp. Khác -> dữ liệu bất thường, dừng lại.
            if (toMilli($line['current_qty']) !== toMilli($line['system_qty'])) {
                throw new VoucherException("Tồn hệ thống của {$line['sku']} - lô {$line['batch_no']} đã thay đổi kể từ lúc chụp số. Hãy hủy đợt và kiểm kê lại.");
            }
        }

        $adjustmentId = null;
        if ($differences) {
            $code = nextVoucherCode($pdo, 'ADJUSTMENT', date('Y-m-d'));
            $stmt = $pdo->prepare("
                INSERT INTO vouchers (code, type, warehouse_id, voucher_date, reason, created_by)
                VALUES (?, 'ADJUSTMENT', ?, CURDATE(), ?, ?)
            ");
            $stmt->execute([$code, $count['warehouse_id'], "Kiểm kê {$count['code']}: $reason", $user['id']]);
            $adjustmentId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, note) VALUES (?, ?, ?, ?, ?)');
            foreach ($differences as $line) {
                $stmt->execute([$adjustmentId, $line['item_id'], $line['batch_id'], $line['difference'], $line['reason']]);
            }

            postVoucher($pdo, $user, $adjustmentId, $countId);
        }

        $stmt = $pdo->prepare("
            UPDATE stock_counts SET status = 'APPROVED', approved_by = ?, approved_at = NOW(), adjustment_voucher_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$user['id'], $adjustmentId, $countId]);

        $pdo->commit();
        return $adjustmentId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Hủy đợt kiểm kê: không sinh phát sinh nào, kho hết đóng băng */
function cancelStockCount(PDO $pdo, array $user, int $countId): void
{
    $pdo->beginTransaction();
    try {
        $count = lockOpenStockCount($pdo, $countId);
        assertCanDecide($user, [(int) $count['warehouse_id']]);
        $stmt = $pdo->prepare("UPDATE stock_counts SET status = 'CANCELLED', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$user['id'], $countId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Khóa đợt kiểm kê (FOR UPDATE) và bắt buộc đang COUNTING -> chống duyệt / hủy / lưu trùng lúc */
function lockOpenStockCount(PDO $pdo, int $countId): array
{
    $stmt = $pdo->prepare('SELECT id, code, warehouse_id, status FROM stock_counts WHERE id = ? FOR UPDATE');
    $stmt->execute([$countId]);
    $count = $stmt->fetch();
    if (!$count) {
        throw new VoucherException('Không tìm thấy đợt kiểm kê.');
    }
    if ($count['status'] !== 'COUNTING') {
        throw new VoucherException("Đợt kiểm kê {$count['code']} đã được chốt trước đó ({$count['status']}).");
    }
    return $count;
}

/** Hiển thị chênh lệch: "Khớp" / "+2" (xanh, thừa) / "−3" (đỏ, thiếu) */
function diffLabel($difference): string
{
    $milli = toMilli($difference);
    if ($milli === 0) {
        return '<span class="badge badge-active">Khớp</span>';
    }
    return $milli > 0
        ? '<span class="qty-in">+' . formatQty($difference) . '</span>'
        : '<span class="qty-out">−' . formatQty(-$difference) . '</span>';
}
