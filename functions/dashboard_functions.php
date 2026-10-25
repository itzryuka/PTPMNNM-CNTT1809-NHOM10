<?php
// Các truy vấn cho trang Tổng quan (Dashboard). Mọi số liệu đều lọc theo kho user được phép.

function getDashboardStats(PDO $pdo, ?array $allowedWarehouses): array
{
    [$whFilter, $whParams] = warehouseFilter('w.id', $allowedWarehouses);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM warehouses w WHERE w.is_active = 1 AND $whFilter");
    $stmt->execute($whParams);
    $warehouseCount = (int) $stmt->fetchColumn();

    $itemCount = (int) $pdo->query('SELECT COUNT(*) FROM items WHERE is_active = 1')->fetchColumn();

    // Phiếu chờ duyệt: liên quan tới kho của user ở kho đi hoặc kho đến
    [$fromFilter, $fromParams] = warehouseFilter('v.warehouse_id', $allowedWarehouses);
    [$toFilter, $toParams]     = warehouseFilter('v.to_warehouse_id', $allowedWarehouses);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vouchers v WHERE v.status = 'PENDING' AND ($fromFilter OR $toFilter)");
    $stmt->execute(array_merge($fromParams, $toParams));
    $pendingCount = (int) $stmt->fetchColumn();

    [$smFilter, $smParams] = warehouseFilter('sm.warehouse_id', $allowedWarehouses);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_movements sm WHERE sm.moved_at >= NOW() - INTERVAL 30 DAY AND $smFilter");
    $stmt->execute($smParams);
    $movementCount = (int) $stmt->fetchColumn();

    return [
        'warehouses' => $warehouseCount,
        'items'      => $itemCount,
        'pending'    => $pendingCount,
        'movements'  => $movementCount,
    ];
}

function getRecentVouchers(PDO $pdo, ?array $allowedWarehouses, int $limit = 8): array
{
    [$fromFilter, $fromParams] = warehouseFilter('v.warehouse_id', $allowedWarehouses);
    [$toFilter, $toParams]     = warehouseFilter('v.to_warehouse_id', $allowedWarehouses);
    $limit = max(1, $limit);

    $stmt = $pdo->prepare("
        SELECT v.id, v.code, v.type, v.status, v.voucher_date,
               w.code AS warehouse_code, tw.code AS to_warehouse_code,
               COUNT(vl.id) AS line_count
        FROM vouchers v
        JOIN warehouses w       ON w.id = v.warehouse_id
        LEFT JOIN warehouses tw ON tw.id = v.to_warehouse_id
        LEFT JOIN voucher_lines vl ON vl.voucher_id = v.id
        WHERE ($fromFilter OR $toFilter)
        GROUP BY v.id, v.code, v.type, v.status, v.voucher_date, w.code, tw.code
        ORDER BY v.created_at DESC, v.id DESC
        LIMIT $limit
    ");
    $stmt->execute(array_merge($fromParams, $toParams));
    return $stmt->fetchAll();
}

/** Lô còn tồn đã hết hạn hoặc sẽ hết hạn trong $days ngày tới */
function getExpiringBatches(PDO $pdo, ?array $allowedWarehouses, int $days = 60, int $limit = 8): array
{
    [$whFilter, $whParams] = warehouseFilter('s.warehouse_id', $allowedWarehouses);
    $limit = max(1, $limit);

    $stmt = $pdo->prepare("
        SELECT w.code AS warehouse_code, i.sku, i.name AS item_name, i.unit,
               b.batch_no, b.expiry_date, s.quantity,
               DATEDIFF(b.expiry_date, CURDATE()) AS days_left
        FROM stocks s
        JOIN warehouses w ON w.id = s.warehouse_id
        JOIN items i      ON i.id = s.item_id
        JOIN batches b    ON b.id = s.batch_id
        WHERE s.quantity > 0
          AND b.expiry_date <= CURDATE() + INTERVAL ? DAY
          AND $whFilter
        ORDER BY b.expiry_date, w.code
        LIMIT $limit
    ");
    $stmt->execute(array_merge([$days], $whParams));
    return $stmt->fetchAll();
}

/** Đối chiếu tồn (stocks) với tổng sổ phát sinh: [tổng số dòng, số dòng lệch] */
function getReconciliationSummary(PDO $pdo, ?array $allowedWarehouses): array
{
    [$whFilter, $whParams] = warehouseFilter('r.warehouse_id', $allowedWarehouses);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total, COALESCE(SUM(r.is_matched = 0), 0) AS mismatched
        FROM v_stock_reconciliation r
        WHERE $whFilter
    ");
    $stmt->execute($whParams);
    $row = $stmt->fetch();
    return [(int) $row['total'], (int) $row['mismatched']];
}
