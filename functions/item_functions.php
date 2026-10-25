<?php
// Các hàm truy vấn danh sách mặt hàng (kèm số lô còn tồn và tổng tồn)

/**
 * Điều kiện WHERE dùng chung cho đếm và lấy danh sách.
 * Trả về [chuỗi SQL, mảng tham số].
 */
function buildItemSearchCondition(string $keyword): array
{
    $sql    = 'i.is_active = 1';
    $params = [];
    if ($keyword !== '') {
        $sql     .= ' AND (i.sku LIKE ? OR i.name LIKE ?)';
        $params[] = "%$keyword%";
        $params[] = "%$keyword%";
    }
    return [$sql, $params];
}

function countItems(PDO $pdo, string $keyword = ''): int
{
    [$where, $params] = buildItemSearchCondition($keyword);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM items i WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Lấy 1 trang mặt hàng.
 * $allowedWarehouses: danh sách kho user được xem (null = tất cả kho).
 * Tổng tồn và số lô chỉ tính trên các kho đó.
 */
function getItems(PDO $pdo, string $keyword, int $limit, int $offset, ?array $allowedWarehouses = null): array
{
    $stockJoin   = 's.item_id = i.id';
    $stockParams = [];
    if ($allowedWarehouses !== null) {
        if (empty($allowedWarehouses)) {
            $stockJoin .= ' AND 1 = 0';             // không có kho nào -> tồn = 0
        } else {
            $placeholders = implode(',', array_fill(0, count($allowedWarehouses), '?'));
            $stockJoin   .= " AND s.warehouse_id IN ($placeholders)";
            $stockParams  = array_map('intval', $allowedWarehouses);
        }
    }

    [$where, $whereParams] = buildItemSearchCondition($keyword);
    $limit  = max(1, $limit);
    $offset = max(0, $offset);

    $sql = "
        SELECT i.id, i.sku, i.name, i.unit,
               COALESCE(SUM(s.quantity), 0) AS total_qty,
               GROUP_CONCAT(DISTINCT CASE WHEN s.quantity > 0 THEN b.batch_no END
                            ORDER BY b.batch_no SEPARATOR ', ') AS batch_list
        FROM items i
        LEFT JOIN stocks s  ON $stockJoin
        LEFT JOIN batches b ON b.id = s.batch_id
        WHERE $where
        GROUP BY i.id, i.sku, i.name, i.unit
        ORDER BY i.sku
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($stockParams, $whereParams));
    return $stmt->fetchAll();
}
