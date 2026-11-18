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

// Cột sắp xếp được của danh sách đợt kiểm kê (khóa trên URL => biểu thức SQL)
const STOCK_COUNT_SORT_COLUMNS = [
    'created'   => 'sc.created_at',
    'code'      => 'sc.code',
    'warehouse' => 'w.name',
    'creator'   => 'u.full_name',
    'counted'   => 'counted_count',
    'diffs'     => 'diff_count',
    'status'    => 'sc.status',
];

/** $sort = [khóa STOCK_COUNT_SORT_COLUMNS, 'asc'|'desc']; mặc định đợt mới nhất trước */
function getStockCounts(PDO $pdo, array $filters, array $allowedWarehouses, int $limit, int $offset,
                        array $sort = ['created', 'desc']): array
{
    [$where, $params] = buildStockCountCondition($filters, $allowedWarehouses);
    $orderBy = orderByClause(STOCK_COUNT_SORT_COLUMNS, $sort, 'sc.id DESC');
    $limit   = max(1, $limit);
    $offset  = max(0, $offset);

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
        ORDER BY $orderBy
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
function createStockCount(PDO $pdo, array $user, int $warehouseId, string $note, array $memberIds): int
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
        insertStockCountMembers($pdo, $user, $countId, normalizeStockCountMembers($pdo, $warehouseId, $memberIds));

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
 * Lưu số đếm thực tế. Nhiều nhân viên cùng đếm 1 đợt: dòng nào đã có người lưu số đếm thì KHÓA,
 * không ai ghi đè được nữa (kể cả người đã đếm) - muốn đếm lại thì quản lý kho mở khóa dòng đó.
 * $entries = [line_id => ['counted_qty' => '12' | '', 'reason' => '...'], ...]; để trống = chưa đếm (bỏ qua).
 * Dòng lệch phải có lý do ngay khi lưu (vì lưu xong là khóa, không bổ sung sau được).
 * Trả về ['saved' => số dòng đã ghi, 'conflicts' => [thông báo dòng đã bị người khác nhập trước]].
 */
function saveStockCountEntries(PDO $pdo, array $user, int $countId, array $entries): array
{
    $pdo->beginTransaction();
    try {
        // FOR UPDATE trên đợt: 2 nhân viên bấm lưu cùng lúc -> người sau chờ, rồi thấy dòng người trước đã khóa
        $count = lockOpenStockCount($pdo, $countId);
        if (!canAccessWarehouse($user, (int) $count['warehouse_id'])) {
            throw new VoucherException('Lỗi bảo mật: bạn không có quyền trên kho này.');
        }
        if (!canEnterStockCount($user, getStockCountMemberIds($pdo, $countId))) {
            throw new VoucherException("Bạn không có trong danh sách nhân viên kiểm kê đợt {$count['code']}. Nhờ quản lý kho thêm bạn vào đợt.");
        }

        $stmt = $pdo->prepare('
            SELECT l.id, l.system_qty, l.counted_qty, i.sku, b.batch_no, u.full_name AS counted_by_name
            FROM stock_count_lines l
            JOIN items i   ON i.id = l.item_id
            JOIN batches b ON b.id = l.batch_id
            LEFT JOIN users u ON u.id = l.counted_by
            WHERE l.count_id = ?
        ');
        $stmt->execute([$countId]);
        $lines = [];
        foreach ($stmt->fetchAll() as $row) {
            $lines[(int) $row['id']] = $row;
        }

        $update    = $pdo->prepare('UPDATE stock_count_lines SET counted_qty = ?, reason = ?, counted_by = ?, counted_at = NOW() WHERE id = ? AND counted_qty IS NULL');
        $toSave    = [];
        $conflicts = [];
        foreach ($entries as $lineId => $entry) {
            $lineId = (int) $lineId;
            if (!isset($lines[$lineId]) || !is_array($entry)) {
                throw new VoucherException('Dòng kiểm kê không thuộc đợt này.');
            }
            $qty    = trim((string) ($entry['counted_qty'] ?? ''));
            $reason = trim((string) ($entry['reason'] ?? ''));
            if ($qty === '') {
                continue;                                    // chưa đếm dòng này
            }
            $line = $lines[$lineId];
            $name = "{$line['sku']} - lô {$line['batch_no']}";
            if (!preg_match('/^\d{1,12}(\.\d{1,3})?$/', $qty)) {
                throw new VoucherException("Số đếm \"$qty\" của $name không hợp lệ (số không âm, tối đa 3 chữ số thập phân).");
            }
            if (mb_strlen($reason) > 255) {
                throw new VoucherException('Lý do chênh lệch tối đa 255 ký tự.');
            }
            if ($line['counted_qty'] !== null) {
                // Đã khóa: gửi lại đúng số cũ (trang chưa tải lại) thì bỏ qua, khác thì báo không ghi đè
                if (toMilli($line['counted_qty']) !== toMilli($qty)) {
                    $conflicts[] = "$name đã được {$line['counted_by_name']} nhập " . formatQty($line['counted_qty'])
                                 . ', số bạn nhập (' . formatQty($qty) . ') không được ghi.';
                }
                continue;
            }
            if (toMilli($qty) !== toMilli($line['system_qty']) && $reason === '') {
                throw new VoucherException("$name lệch so với tồn hệ thống: phải nhập lý do chênh lệch trước khi lưu (lưu xong dòng sẽ bị khóa).");
            }
            $toSave[] = [$qty, $reason === '' ? null : $reason, $user['id'], $lineId];
        }

        foreach ($toSave as $params) {
            $update->execute($params);
        }

        $pdo->commit();
        return ['saved' => count($toSave), 'conflicts' => $conflicts];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------
// NHÂN VIÊN KIỂM KÊ: quản lý chọn khi mở đợt, đổi được khi đợt còn mở
// ---------------------------------------------------------------------

/** Người chọn được đi kiểm kê kho: tài khoản đang hoạt động (nhân viên / quản lý) được phân quyền kho đó */
function getStockCountCandidates(PDO $pdo, array $warehouseIds): array
{
    if (!$warehouseIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($warehouseIds), '?'));
    $stmt = $pdo->prepare("
        SELECT uw.warehouse_id, u.id, u.full_name, u.role
        FROM user_warehouses uw JOIN users u ON u.id = uw.user_id
        WHERE uw.warehouse_id IN ($placeholders) AND u.is_active = 1 AND u.role IN ('STAFF', 'MANAGER')
        ORDER BY u.role DESC, u.full_name
    ");
    $stmt->execute(array_map('intval', $warehouseIds));
    return $stmt->fetchAll();
}

/** Lọc danh sách id gửi lên: phải là người chọn được của kho, ít nhất 1 người */
function normalizeStockCountMembers(PDO $pdo, int $warehouseId, array $memberIds): array
{
    $allowed = array_map('intval', array_column(getStockCountCandidates($pdo, [$warehouseId]), 'id'));
    $ids     = array_values(array_unique(array_map('intval', $memberIds)));
    foreach ($ids as $id) {
        if (!in_array($id, $allowed, true)) {
            throw new VoucherException('Có người được chọn không thuộc kho này hoặc tài khoản đã bị khóa.');
        }
    }
    if (!$ids) {
        throw new VoucherException('Phải chọn ít nhất 1 nhân viên đi kiểm kê.');
    }
    return $ids;
}

function insertStockCountMembers(PDO $pdo, array $user, int $countId, array $memberIds): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO stock_count_members (count_id, user_id, added_by) VALUES (?, ?, ?)');
    foreach ($memberIds as $memberId) {
        $stmt->execute([$countId, $memberId, $user['id']]);
    }
}

function getStockCountMemberIds(PDO $pdo, int $countId): array
{
    $stmt = $pdo->prepare('SELECT user_id FROM stock_count_members WHERE count_id = ?');
    $stmt->execute([$countId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Danh sách nhân viên của đợt kèm số dòng mỗi người đã đếm */
function getStockCountMembers(PDO $pdo, int $countId): array
{
    $stmt = $pdo->prepare('
        SELECT u.id, u.full_name, u.role, u.is_active,
               (SELECT COUNT(*) FROM stock_count_lines l WHERE l.count_id = m.count_id AND l.counted_by = u.id) AS counted_lines
        FROM stock_count_members m JOIN users u ON u.id = m.user_id
        WHERE m.count_id = ?
        ORDER BY u.full_name
    ');
    $stmt->execute([$countId]);
    return $stmt->fetchAll();
}

/** Nhập số đếm được: người có tên trong đợt, hoặc quản lý (kho đã được canAccessWarehouse kiểm tra riêng) */
function canEnterStockCount(array $user, array $memberIds): bool
{
    return isManager($user) || in_array((int) $user['id'], $memberIds, true);
}

/** Quản lý đổi danh sách nhân viên khi đợt còn mở; người bị bỏ ra vẫn giữ các dòng đã đếm */
function updateStockCountMembers(PDO $pdo, array $user, int $countId, array $memberIds): void
{
    $pdo->beginTransaction();
    try {
        $count = lockOpenStockCount($pdo, $countId);
        assertCanDecide($user, [(int) $count['warehouse_id']]);
        $ids = normalizeStockCountMembers($pdo, (int) $count['warehouse_id'], $memberIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM stock_count_members WHERE count_id = ? AND user_id NOT IN ($placeholders)");
        $stmt->execute(array_merge([$countId], $ids));
        insertStockCountMembers($pdo, $user, $countId, $ids);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Quản lý kho mở khóa 1 dòng đã đếm (nhập nhầm) -> dòng về "chưa đếm", nhân viên đếm lại */
function unlockStockCountLine(PDO $pdo, array $user, int $countId, int $lineId): string
{
    $pdo->beginTransaction();
    try {
        $count = lockOpenStockCount($pdo, $countId);
        assertCanDecide($user, [(int) $count['warehouse_id']]);
        $stmt = $pdo->prepare('
            SELECT i.sku, b.batch_no FROM stock_count_lines l
            JOIN items i ON i.id = l.item_id JOIN batches b ON b.id = l.batch_id
            WHERE l.id = ? AND l.count_id = ? AND l.counted_qty IS NOT NULL
        ');
        $stmt->execute([$lineId, $countId]);
        $line = $stmt->fetch();
        if (!$line) {
            throw new VoucherException('Dòng kiểm kê không tồn tại hoặc chưa được đếm.');
        }
        $stmt = $pdo->prepare('UPDATE stock_count_lines SET counted_qty = NULL, reason = NULL, counted_by = NULL, counted_at = NULL WHERE id = ?');
        $stmt->execute([$lineId]);
        $pdo->commit();
        return "{$line['sku']} - lô {$line['batch_no']}";
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Trạng thái các dòng (cho trang chi tiết tự cập nhật dòng người khác vừa khóa): [line_id => {...}] */
function getStockCountLineStates(PDO $pdo, int $countId): array
{
    $stmt = $pdo->prepare('
        SELECT l.id, l.counted_qty, l.difference, l.reason, l.counted_at, u.full_name AS counted_by_name
        FROM stock_count_lines l LEFT JOIN users u ON u.id = l.counted_by
        WHERE l.count_id = ?
    ');
    $stmt->execute([$countId]);
    $states = [];
    foreach ($stmt->fetchAll() as $row) {
        $states[(int) $row['id']] = [
            'counted'  => $row['counted_qty'] !== null,
            'qty'      => $row['counted_qty'] !== null ? formatQty($row['counted_qty']) : null,
            'diff'     => $row['counted_qty'] !== null ? diffLabel($row['difference']) : null,
            'mismatch' => $row['counted_qty'] !== null && toMilli($row['difference']) !== 0,
            'reason'   => $row['reason'],
            'by'       => $row['counted_by_name'],
            'at'       => $row['counted_at'] !== null ? date('H:i d/m', strtotime($row['counted_at'])) : null,
        ];
    }
    return $states;
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
