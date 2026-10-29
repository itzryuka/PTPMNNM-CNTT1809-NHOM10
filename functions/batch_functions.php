<?php
// LÔ HÀNG: mỗi mặt hàng có nhiều lô (số lô, ngày sản xuất, hạn sử dụng). Lô được tạo khi lập phiếu nhập lô mới.
// Trang lô tra cứu: lô nào còn ở đâu, bao nhiêu, còn bao nhiêu ngày hạn, đã đi qua những phiếu nào.
// Số lượng chỉ tính ở các kho user được phân quyền (lọc ở server).
// Cần nạp trước: helpers.php

const BATCH_EXPIRING_DAYS = 30;
const BATCH_STATUS_FILTERS = [
    'in_stock' => 'Còn hàng',
    'soon'     => 'Sắp hết hạn (≤ 30 ngày, còn hàng)',
    'expired'  => 'Đã hết hạn, còn hàng',
    'empty'    => 'Đã hết hàng',
    'all'      => 'Tất cả lô',
];
const BATCH_SORT_COLUMNS = [
    'batch'     => 'b.batch_no',
    'sku'       => 'i.sku',
    'name'      => 'i.name',
    'mfg'       => 'b.mfg_date',
    'expiry'    => 'b.expiry_date',
    'quantity'  => 'quantity',
    'available' => 'available',
];

/**
 * Tồn của lô gom theo kho được xem (và kho đang lọc). Trả về [đoạn JOIN, tham số].
 * Lô không còn ở kho nào được xem vẫn hiện (số 0) để tra cứu lịch sử.
 */
function batchStockJoin(?array $allowedWarehouses, int $warehouseId): array
{
    [$whFilter, $params] = warehouseFilter('s.warehouse_id', $allowedWarehouses);
    if ($warehouseId > 0) {
        $whFilter .= ' AND s.warehouse_id = ?';
        $params[]  = $warehouseId;
    }
    return ["
        LEFT JOIN (
            SELECT s.batch_id, SUM(s.quantity) AS quantity, SUM(s.reserved) AS reserved,
                   SUM(s.quantity > 0) AS warehouse_count
            FROM stocks s
            WHERE $whFilter
            GROUP BY s.batch_id
        ) st ON st.batch_id = b.id
    ", $params];
}

/** $filters = [keyword, status, warehouse_id] */
function buildBatchCondition(array $filters): array
{
    $soon = (int) BATCH_EXPIRING_DAYS;
    $sql  = match ($filters['status'] ?? 'in_stock') {
        'soon'    => "COALESCE(st.quantity, 0) > 0 AND b.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL $soon DAY",
        'expired' => 'COALESCE(st.quantity, 0) > 0 AND b.expiry_date < CURDATE()',
        'empty'   => 'COALESCE(st.quantity, 0) = 0',
        'all'     => '1 = 1',
        default   => 'COALESCE(st.quantity, 0) > 0',
    };
    $params = [];
    if (($filters['keyword'] ?? '') !== '') {
        $sql .= ' AND (b.batch_no LIKE ? OR i.sku LIKE ? OR i.name LIKE ?)';
        array_push($params, "%{$filters['keyword']}%", "%{$filters['keyword']}%", "%{$filters['keyword']}%");
    }
    return [$sql, $params];
}

function countBatches(PDO $pdo, array $filters, ?array $allowedWarehouses): int
{
    [$join, $joinParams] = batchStockJoin($allowedWarehouses, (int) ($filters['warehouse_id'] ?? 0));
    [$where, $params]    = buildBatchCondition($filters);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM batches b JOIN items i ON i.id = b.item_id $join WHERE $where");
    $stmt->execute(array_merge($joinParams, $params));
    return (int) $stmt->fetchColumn();
}

function getBatches(PDO $pdo, array $filters, ?array $allowedWarehouses, int $limit, int $offset, array $sort = ['expiry', 'asc']): array
{
    [$join, $joinParams] = batchStockJoin($allowedWarehouses, (int) ($filters['warehouse_id'] ?? 0));
    [$where, $params]    = buildBatchCondition($filters);
    $orderBy = orderByClause(BATCH_SORT_COLUMNS, $sort, 'b.id');
    $limit   = max(1, $limit);
    $offset  = max(0, $offset);
    $stmt = $pdo->prepare("
        SELECT b.id, b.batch_no, b.mfg_date, b.expiry_date, DATEDIFF(b.expiry_date, CURDATE()) AS days_left,
               i.id AS item_id, i.sku, i.name AS item_name, i.unit,
               COALESCE(st.quantity, 0) AS quantity, COALESCE(st.reserved, 0) AS reserved,
               COALESCE(st.quantity, 0) - COALESCE(st.reserved, 0) AS available, COALESCE(st.warehouse_count, 0) AS warehouse_count
        FROM batches b
        JOIN items i ON i.id = b.item_id
        $join
        WHERE $where
        ORDER BY $orderBy
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute(array_merge($joinParams, $params));
    return $stmt->fetchAll();
}

function getBatch(PDO $pdo, int $batchId): ?array
{
    $stmt = $pdo->prepare('
        SELECT b.*, DATEDIFF(b.expiry_date, CURDATE()) AS days_left, i.sku, i.name AS item_name, i.unit, i.is_active AS item_active
        FROM batches b JOIN items i ON i.id = b.item_id WHERE b.id = ?
    ');
    $stmt->execute([$batchId]);
    return $stmt->fetch() ?: null;
}

/** Lô đang ở những kho nào (trong các kho được xem): tồn, đang giữ, có thể xuất */
function getBatchStock(PDO $pdo, int $batchId, ?array $allowedWarehouses): array
{
    [$whFilter, $params] = warehouseFilter('s.warehouse_id', $allowedWarehouses);
    $stmt = $pdo->prepare("
        SELECT w.id AS warehouse_id, w.code, w.name, s.quantity, s.reserved, s.quantity - s.reserved AS available, s.updated_at
        FROM stocks s JOIN warehouses w ON w.id = s.warehouse_id
        WHERE s.batch_id = ? AND s.quantity > 0 AND $whFilter
        ORDER BY w.code
    ");
    $stmt->execute(array_merge([$batchId], $params));
    return $stmt->fetchAll();
}

/** Các phiếu có dòng thuộc lô này, liên quan tới kho được xem (mới nhất trước) */
function getBatchVouchers(PDO $pdo, int $batchId, array $allowedWarehouses, int $limit = 50): array
{
    [$fromFilter, $fromParams] = warehouseFilter('v.warehouse_id', $allowedWarehouses);
    [$toFilter, $toParams]     = warehouseFilter('v.to_warehouse_id', $allowedWarehouses);
    $limit = max(1, $limit);
    $stmt = $pdo->prepare("
        SELECT v.id, v.code, v.type, v.status, v.voucher_date, w.name AS warehouse_name, tw.name AS to_warehouse_name,
               vl.quantity, vl.received_qty, vl.shipped_qty
        FROM voucher_lines vl
        JOIN vouchers v   ON v.id = vl.voucher_id
        JOIN warehouses w ON w.id = v.warehouse_id
        LEFT JOIN warehouses tw ON tw.id = v.to_warehouse_id
        WHERE vl.batch_id = ? AND ($fromFilter OR $toFilter)
        ORDER BY v.voucher_date DESC, v.id DESC
        LIMIT $limit
    ");
    $stmt->execute(array_merge([$batchId], $fromParams, $toParams));
    return $stmt->fetchAll();
}

/** Nhãn hạn dùng: đã hết hạn / còn N ngày (≤ 30) / còn hạn */
function expiryBadge(?string $expiry, $daysLeft): string
{
    if ($expiry === null) {
        return '<span class="muted">Không hạn</span>';
    }
    $days = (int) $daysLeft;
    if ($days < 0) {
        return '<span class="badge badge-expired">Hết hạn ' . (-$days) . ' ngày</span>';
    }
    if ($days <= BATCH_EXPIRING_DAYS) {
        return '<span class="badge badge-pending">Còn ' . $days . ' ngày</span>';
    }
    return '<span class="badge badge-active">Còn hạn</span>';
}
