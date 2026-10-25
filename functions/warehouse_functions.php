<?php
// Các hàm truy vấn danh sách kho (kèm số mặt hàng / số lô đang tồn)

/**
 * Điều kiện WHERE dùng chung cho đếm và lấy danh sách.
 * $allowedWarehouses: kho user được xem (null = tất cả kho) -> lọc ở server, không chỉ ẩn ở giao diện.
 * Trả về [chuỗi SQL, mảng tham số].
 */
function buildWarehouseCondition(string $keyword, ?array $allowedWarehouses): array
{
    $sql    = '1 = 1';
    $params = [];

    if ($allowedWarehouses !== null) {
        if (empty($allowedWarehouses)) {
            $sql .= ' AND 1 = 0';                   // không được xem kho nào
        } else {
            $placeholders = implode(',', array_fill(0, count($allowedWarehouses), '?'));
            $sql   .= " AND w.id IN ($placeholders)";
            $params = array_map('intval', $allowedWarehouses);
        }
    }

    if ($keyword !== '') {
        $sql     .= ' AND (w.code LIKE ? OR w.name LIKE ? OR w.address LIKE ?)';
        $params[] = "%$keyword%";
        $params[] = "%$keyword%";
        $params[] = "%$keyword%";
    }
    return [$sql, $params];
}

function countWarehouses(PDO $pdo, string $keyword = '', ?array $allowedWarehouses = null): int
{
    [$where, $params] = buildWarehouseCondition($keyword, $allowedWarehouses);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM warehouses w WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Lấy 1 trang kho, kèm số mặt hàng và số lô còn tồn (> 0) trong từng kho.
 */
function getWarehouses(PDO $pdo, string $keyword, int $limit, int $offset, ?array $allowedWarehouses = null): array
{
    [$where, $params] = buildWarehouseCondition($keyword, $allowedWarehouses);
    $limit  = max(1, $limit);
    $offset = max(0, $offset);

    $sql = "
        SELECT w.id, w.code, w.name, w.address, w.is_active,
               COUNT(DISTINCT CASE WHEN s.quantity > 0 THEN s.item_id END)  AS item_count,
               COUNT(DISTINCT CASE WHEN s.quantity > 0 THEN s.batch_id END) AS batch_count
        FROM warehouses w
        LEFT JOIN stocks s ON s.warehouse_id = w.id
        WHERE $where
        GROUP BY w.id, w.code, w.name, w.address, w.is_active
        ORDER BY w.code
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
