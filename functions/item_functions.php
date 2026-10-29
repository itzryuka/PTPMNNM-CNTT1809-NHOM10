<?php
// Mặt hàng: tra cứu (kèm số lô còn tồn và tổng tồn), thêm / sửa / xóa hoặc ngừng dùng, nhập từ CSV

const ITEM_CSV_COLUMNS = [
    'sku'         => ['mã hàng', 'ma hang', 'sku'],
    'name'        => ['tên mặt hàng', 'ten mat hang', 'tên hàng', 'name'],
    'unit'        => ['đơn vị tính', 'don vi tinh', 'đvt', 'unit'],
    'description' => ['mô tả', 'mo ta', 'ghi chú', 'description'],
];

// Bộ lọc "Tình trạng" của danh sách mặt hàng (giá trị ?status=...)
const ITEM_STATUS_FILTERS = [
    'active'       => 'Đang dùng',
    'in_stock'     => 'Còn hàng',
    'out_of_stock' => 'Hết hàng',
    'expiring'     => 'Có lô sắp hết hạn (≤ 30 ngày)',
    'expired'      => 'Có lô đã hết hạn còn tồn',
    'inactive'     => 'Ngừng dùng',
    'all'          => 'Tất cả',
];
const ITEM_EXPIRING_DAYS = 30;

// Cột sắp xếp được (khóa trên URL => biểu thức SQL)
const ITEM_SORT_COLUMNS = [
    'sku'       => 'i.sku',
    'name'      => 'i.name',
    'unit'      => 'i.unit',
    'batches'   => 'batch_count',
    'total_qty' => 'total_qty',
];

/**
 * Phạm vi tồn kho tính cho danh sách: các kho user được xem, thu hẹp thêm theo kho đang lọc.
 * Trả về [điều kiện SQL trên s.warehouse_id, tham số].
 */
function itemStockScope(?array $allowedWarehouses, int $warehouseId): array
{
    [$sql, $params] = warehouseFilter('s.warehouse_id', $allowedWarehouses);
    if ($warehouseId > 0) {
        $sql     .= ' AND s.warehouse_id = ?';
        $params[] = $warehouseId;
    }
    return [$sql, $params];
}

/**
 * Điều kiện WHERE dùng chung cho đếm và lấy danh sách.
 * $status: một khóa của ITEM_STATUS_FILTERS. Các lọc theo tồn / hạn dùng chỉ xét kho trong phạm vi itemStockScope().
 * Trả về [chuỗi SQL, mảng tham số].
 */
function buildItemSearchCondition(string $keyword, string $status = 'active', ?array $allowedWarehouses = null, int $warehouseId = 0): array
{
    [$scope, $scopeParams] = itemStockScope($allowedWarehouses, $warehouseId);
    $hasStock = "EXISTS (SELECT 1 FROM stocks s JOIN batches b ON b.id = s.batch_id
                         WHERE s.item_id = i.id AND s.quantity > 0 AND $scope%s)";
    $soon     = (int) ITEM_EXPIRING_DAYS;

    [$sql, $params] = match ($status) {
        'inactive'     => ['i.is_active = 0', []],
        'all'          => ['1 = 1', []],
        'in_stock'     => ['i.is_active = 1 AND ' . sprintf($hasStock, ''), $scopeParams],
        'out_of_stock' => ['i.is_active = 1 AND NOT ' . sprintf($hasStock, ''), $scopeParams],
        'expiring'     => ['i.is_active = 1 AND ' . sprintf($hasStock, " AND b.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL $soon DAY"), $scopeParams],
        'expired'      => ['i.is_active = 1 AND ' . sprintf($hasStock, ' AND b.expiry_date < CURDATE()'), $scopeParams],
        default        => ['i.is_active = 1', []],
    };
    if ($keyword !== '') {
        $sql     .= ' AND (i.sku LIKE ? OR i.name LIKE ?)';
        $params[] = "%$keyword%";
        $params[] = "%$keyword%";
    }
    return [$sql, $params];
}

function countItems(PDO $pdo, string $keyword = '', string $status = 'active', ?array $allowedWarehouses = null, int $warehouseId = 0): int
{
    [$where, $params] = buildItemSearchCondition($keyword, $status, $allowedWarehouses, $warehouseId);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM items i WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Lấy 1 trang mặt hàng.
 * $allowedWarehouses: danh sách kho user được xem (null = tất cả kho); $warehouseId > 0: chỉ tính kho đó.
 * Tổng tồn và số lô chỉ tính trên các kho đó. $sort = [khóa ITEM_SORT_COLUMNS, 'asc'|'desc'].
 */
function getItems(PDO $pdo, string $keyword, int $limit, int $offset, ?array $allowedWarehouses = null, string $status = 'active',
                  int $warehouseId = 0, array $sort = ['sku', 'asc']): array
{
    [$scope, $scopeParams] = itemStockScope($allowedWarehouses, $warehouseId);
    [$where, $whereParams] = buildItemSearchCondition($keyword, $status, $allowedWarehouses, $warehouseId);
    $orderBy = orderByClause(ITEM_SORT_COLUMNS, $sort, 'i.sku');
    $limit   = max(1, $limit);
    $offset  = max(0, $offset);

    $sql = "
        SELECT i.id, i.sku, i.name, i.unit, i.description, i.is_active,
               COALESCE(SUM(s.quantity), 0) AS total_qty,
               COUNT(DISTINCT CASE WHEN s.quantity > 0 THEN s.batch_id END) AS batch_count,
               GROUP_CONCAT(DISTINCT CASE WHEN s.quantity > 0 THEN b.batch_no END
                            ORDER BY b.batch_no SEPARATOR ', ') AS batch_list
        FROM items i
        LEFT JOIN stocks s  ON s.item_id = i.id AND $scope
        LEFT JOIN batches b ON b.id = s.batch_id
        WHERE $where
        GROUP BY i.id, i.sku, i.name, i.unit, i.description, i.is_active
        ORDER BY $orderBy
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($scopeParams, $whereParams));
    return $stmt->fetchAll();
}

function getItem(PDO $pdo, int $itemId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM items WHERE id = ?');
    $stmt->execute([$itemId]);
    return $stmt->fetch() ?: null;
}

/** Các đơn vị tính đang dùng, để gợi ý khi nhập */
function getUnitSuggestions(PDO $pdo): array
{
    return $pdo->query('SELECT DISTINCT unit FROM items ORDER BY unit')->fetchAll(PDO::FETCH_COLUMN);
}

/** Tồn của mặt hàng theo kho / lô (chỉ các kho user được xem) */
function getItemStock(PDO $pdo, int $itemId, array $allowedWarehouses): array
{
    [$whFilter, $whParams] = warehouseFilter('s.warehouse_id', $allowedWarehouses);
    $stmt = $pdo->prepare("
        SELECT w.code AS warehouse_code, w.name AS warehouse_name, b.batch_no, b.mfg_date, b.expiry_date, s.quantity, s.updated_at
        FROM stocks s
        JOIN warehouses w ON w.id = s.warehouse_id
        JOIN batches b    ON b.id = s.batch_id
        WHERE s.item_id = ? AND s.quantity > 0 AND $whFilter
        ORDER BY w.code, b.expiry_date
    ");
    $stmt->execute(array_merge([$itemId], $whParams));
    return $stmt->fetchAll();
}

// =====================================================================
// THÊM / SỬA / XÓA
// =====================================================================

/** Kiểm tra dữ liệu nhập; trả về [sku, name, unit, description] đã chuẩn hóa */
function normalizeItemInput(array $input): array
{
    $item = [
        'sku'         => strtoupper(trim((string) ($input['sku'] ?? ''))),
        'name'        => normalizeName((string) ($input['name'] ?? '')),
        'unit'        => trim((string) ($input['unit'] ?? '')),
        'description' => trim((string) ($input['description'] ?? '')),
    ];
    if (!preg_match('/^[A-Z0-9._-]{1,30}$/', $item['sku'])) {
        throw new AppException('Mã hàng chỉ gồm chữ không dấu, số và . _ - (tối đa 30 ký tự).');
    }
    if ($item['name'] === '' || mb_strlen($item['name']) > 150) {
        throw new AppException('Tên mặt hàng không được trống (tối đa 150 ký tự).');
    }
    if ($item['unit'] === '' || mb_strlen($item['unit']) > 20) {
        throw new AppException('Đơn vị tính không được trống (tối đa 20 ký tự).');
    }
    if (mb_strlen($item['description']) > 255) {
        throw new AppException('Mô tả tối đa 255 ký tự.');
    }
    $item['description'] = $item['description'] === '' ? null : $item['description'];
    return $item;
}

function findItemIdBySku(PDO $pdo, string $sku): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM items WHERE sku = ?');
    $stmt->execute([$sku]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** Mã trùng / gần giống, tên trùng (bỏ qua chính bản ghi đang sửa) -> AppException */
function assertItemUnique(PDO $pdo, array $item, int $excludeId = 0): void
{
    $error = catalogCodeConflict($pdo, 'items', $item['sku'], $excludeId) ?? catalogNameConflict($pdo, 'items', $item['name'], $excludeId);
    if ($error !== null) {
        throw new AppException($error);
    }
}

function createItem(PDO $pdo, array $user, array $input): int
{
    assertCatalogManager($user);
    $item = normalizeItemInput($input);
    assertItemUnique($pdo, $item);
    $stmt = $pdo->prepare('INSERT INTO items (sku, name, unit, description) VALUES (?, ?, ?, ?)');
    $stmt->execute([$item['sku'], $item['name'], $item['unit'], $item['description']]);
    return (int) $pdo->lastInsertId();
}

function updateItem(PDO $pdo, array $user, int $itemId, array $input): void
{
    assertCatalogManager($user);
    $item = normalizeItemInput($input);
    if (getItem($pdo, $itemId) === null) {
        throw new AppException('Không tìm thấy mặt hàng.');
    }
    assertItemUnique($pdo, $item, $itemId);
    $stmt = $pdo->prepare('UPDATE items SET sku = ?, name = ?, unit = ?, description = ? WHERE id = ?');
    $stmt->execute([$item['sku'], $item['name'], $item['unit'], $item['description'], $itemId]);
}

/**
 * Xóa mặt hàng. Mặt hàng đã có lịch sử (lô, chứng từ, sổ phát sinh) thì KHÔNG xóa được
 * vì sẽ phá lịch sử -> chuyển sang "ngừng dùng" (ẩn khỏi form lập phiếu, vẫn tra cứu được).
 * Trả về 'deleted' hoặc 'deactivated'.
 */
function removeItem(PDO $pdo, array $user, int $itemId): string
{
    assertCatalogManager($user);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT sku, is_active FROM items WHERE id = ? FOR UPDATE');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            throw new AppException('Không tìm thấy mặt hàng.');
        }

        $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM stocks WHERE item_id = ?');
        $stmt->execute([$itemId]);
        $stock = $stmt->fetchColumn();
        if (toMilli($stock) > 0) {
            throw new AppException("Mặt hàng {$item['sku']} còn tồn " . formatQty($stock) . ': hãy xuất / điều chuyển / điều chỉnh hết trước khi xóa.');
        }
        $stmt = $pdo->prepare("SELECT v.code FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id WHERE vl.item_id = ? AND v.status = 'PENDING' LIMIT 1");
        $stmt->execute([$itemId]);
        if (($pending = $stmt->fetchColumn()) !== false) {
            throw new AppException("Mặt hàng {$item['sku']} đang nằm trong phiếu chờ duyệt $pending.");
        }

        $stmt = $pdo->prepare('
            SELECT EXISTS(SELECT 1 FROM batches WHERE item_id = ?)
                OR EXISTS(SELECT 1 FROM voucher_lines WHERE item_id = ?)
                OR EXISTS(SELECT 1 FROM stock_movements WHERE item_id = ?)
                OR EXISTS(SELECT 1 FROM stock_count_lines WHERE item_id = ?)
        ');
        $stmt->execute([$itemId, $itemId, $itemId, $itemId]);
        if ((int) $stmt->fetchColumn() === 1) {
            $pdo->prepare('UPDATE items SET is_active = 0 WHERE id = ?')->execute([$itemId]);
            $result = 'deactivated';
        } else {
            $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$itemId]);
            $result = 'deleted';
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function restoreItem(PDO $pdo, array $user, int $itemId): void
{
    assertCatalogManager($user);
    $stmt = $pdo->prepare('UPDATE items SET is_active = 1 WHERE id = ?');
    $stmt->execute([$itemId]);
}

/**
 * Nhập danh mục từ CSV: mã đã có -> cập nhật tên / ĐVT / mô tả, mã mới -> thêm.
 * Tất cả hoặc không: chỉ cần 1 dòng lỗi là không ghi dòng nào. Trả về [số thêm, số cập nhật].
 */
function importItems(PDO $pdo, array $user, array $rows): array
{
    assertCatalogManager($user);
    $errors    = [];
    $valid     = [];
    $seenCode  = [];                                    // dạng rút gọn của mã -> dòng (bắt cả SP001 / SP-001 trong cùng file)
    $seenName  = [];
    foreach ($rows as $line => $row) {
        try {
            $item    = normalizeItemInput($row);
            $codeKey = codeKey($item['sku']);
            $nameKey = mb_strtolower($item['name']);
            if (isset($seenCode[$codeKey])) {
                throw new AppException("mã {$item['sku']} trùng hoặc gần giống mã ở dòng {$seenCode[$codeKey]}");
            }
            if (isset($seenName[$nameKey])) {
                throw new AppException("tên \"{$item['name']}\" trùng với dòng {$seenName[$nameKey]}");
            }
            // Mã đã có -> dòng này là cập nhật: chỉ cần tên không đụng mặt hàng khác.
            // Mã mới -> không được gần giống mã nào đã có.
            $existingId = findItemIdBySku($pdo, $item['sku']);
            if ($existingId === null) {
                assertItemUnique($pdo, $item);
            } elseif (($error = catalogNameConflict($pdo, 'items', $item['name'], $existingId)) !== null) {
                throw new AppException($error);
            }
            $seenCode[$codeKey] = $line;
            $seenName[$nameKey] = $line;
            $valid[$line] = $item;
        } catch (AppException $e) {
            $errors[] = "Dòng $line: " . $e->getMessage();
        }
    }
    if ($errors) {
        throw new AppException(importErrorMessage($errors));
    }

    $created = $updated = 0;
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT INTO items (sku, name, unit, description) VALUES (?, ?, ?, ?)');
        $update = $pdo->prepare('UPDATE items SET name = ?, unit = ?, description = ? WHERE id = ?');
        foreach ($valid as $item) {
            $id = findItemIdBySku($pdo, $item['sku']);
            if ($id === null) {
                $insert->execute([$item['sku'], $item['name'], $item['unit'], $item['description']]);
                $created++;
            } else {
                $update->execute([$item['name'], $item['unit'], $item['description'], $id]);
                $updated++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$created, $updated];
}
