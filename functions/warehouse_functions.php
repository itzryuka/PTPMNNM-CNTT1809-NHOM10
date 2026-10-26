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

const WAREHOUSE_CSV_COLUMNS = [
    'code'    => ['mã kho', 'ma kho', 'code'],
    'name'    => ['tên kho', 'ten kho', 'name'],
    'address' => ['địa chỉ', 'dia chi', 'address'],
];

function getWarehouse(PDO $pdo, int $warehouseId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM warehouses WHERE id = ?');
    $stmt->execute([$warehouseId]);
    return $stmt->fetch() ?: null;
}

/** Hàng đang có trong kho (theo lô), hết hạn trước xếp trước */
function getWarehouseStock(PDO $pdo, int $warehouseId): array
{
    $stmt = $pdo->prepare('
        SELECT item_id, sku, item_name, unit, batch_no, expiry_date, quantity
        FROM v_current_stock
        WHERE warehouse_id = ? AND quantity > 0
        ORDER BY sku, expiry_date
    ');
    $stmt->execute([$warehouseId]);
    return $stmt->fetchAll();
}

/** Số phiếu chờ duyệt (kho đi hoặc kho đến) và đợt kiểm kê đang mở của kho */
function getWarehouseActivity(PDO $pdo, int $warehouseId): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vouchers WHERE status = 'PENDING' AND (warehouse_id = ? OR to_warehouse_id = ?)");
    $stmt->execute([$warehouseId, $warehouseId]);
    $pending = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT id, code FROM stock_counts WHERE warehouse_id = ? AND status = 'COUNTING'");
    $stmt->execute([$warehouseId]);
    return ['pending' => $pending, 'open_count' => $stmt->fetch() ?: null];
}

// =====================================================================
// THÊM / SỬA / XÓA
// =====================================================================

function normalizeWarehouseInput(array $input): array
{
    $warehouse = [
        'code'    => strtoupper(trim((string) ($input['code'] ?? ''))),
        'name'    => trim((string) ($input['name'] ?? '')),
        'address' => trim((string) ($input['address'] ?? '')),
    ];
    if (!preg_match('/^[A-Z0-9_-]{1,20}$/', $warehouse['code'])) {
        throw new AppException('Mã kho chỉ gồm chữ không dấu, số và _ - (tối đa 20 ký tự).');
    }
    if ($warehouse['name'] === '' || mb_strlen($warehouse['name']) > 100) {
        throw new AppException('Tên kho không được trống (tối đa 100 ký tự).');
    }
    if (mb_strlen($warehouse['address']) > 255) {
        throw new AppException('Địa chỉ tối đa 255 ký tự.');
    }
    $warehouse['address'] = $warehouse['address'] === '' ? null : $warehouse['address'];
    return $warehouse;
}

function findWarehouseIdByCode(PDO $pdo, string $code): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM warehouses WHERE code = ?');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * Thêm kho. Người tạo và mọi tài khoản ADMIN được phân quyền kho mới ngay,
 * nếu không sẽ không ai thấy kho này (mọi danh sách đều lọc theo quyền kho).
 */
function createWarehouse(PDO $pdo, array $user, array $input): int
{
    assertCatalogManager($user);
    $warehouse = normalizeWarehouseInput($input);
    $pdo->beginTransaction();
    try {
        $warehouseId = insertWarehouse($pdo, $user, $warehouse);
        $pdo->commit();
        return $warehouseId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Ghi kho mới + phân quyền (gọi trong transaction) */
function insertWarehouse(PDO $pdo, array $user, array $warehouse): int
{
    if (findWarehouseIdByCode($pdo, $warehouse['code']) !== null) {
        throw new AppException("Mã kho {$warehouse['code']} đã tồn tại.");
    }
    $stmt = $pdo->prepare('INSERT INTO warehouses (code, name, address) VALUES (?, ?, ?)');
    $stmt->execute([$warehouse['code'], $warehouse['name'], $warehouse['address']]);
    $warehouseId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO user_warehouses (user_id, warehouse_id)
        SELECT id, ? FROM users WHERE role = 'ADMIN' OR id = ?
    ");
    $stmt->execute([$warehouseId, $user['id']]);
    return $warehouseId;
}

function updateWarehouse(PDO $pdo, array $user, int $warehouseId, array $input): void
{
    assertCatalogManager($user);
    if (!canAccessWarehouse($user, $warehouseId)) {
        throw new AppException('Lỗi bảo mật: bạn không có quyền trên kho này.');
    }
    $warehouse = normalizeWarehouseInput($input);
    $existing  = findWarehouseIdByCode($pdo, $warehouse['code']);
    if ($existing !== null && $existing !== $warehouseId) {
        throw new AppException("Mã kho {$warehouse['code']} đã được dùng cho kho khác.");
    }
    $stmt = $pdo->prepare('UPDATE warehouses SET code = ?, name = ?, address = ? WHERE id = ?');
    $stmt->execute([$warehouse['code'], $warehouse['name'], $warehouse['address'], $warehouseId]);
}

/**
 * Xóa kho. Kho đã có lịch sử thì chuyển "ngừng hoạt động" thay vì xóa.
 * Không cho xóa / ngừng khi kho còn hàng, còn phiếu chờ duyệt hoặc đang kiểm kê.
 * Trả về 'deleted' hoặc 'deactivated'.
 */
function removeWarehouse(PDO $pdo, array $user, int $warehouseId): string
{
    assertCatalogManager($user);
    if (!canAccessWarehouse($user, $warehouseId)) {
        throw new AppException('Lỗi bảo mật: bạn không có quyền trên kho này.');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT code FROM warehouses WHERE id = ? FOR UPDATE');
        $stmt->execute([$warehouseId]);
        $code = $stmt->fetchColumn();
        if ($code === false) {
            throw new AppException('Không tìm thấy kho.');
        }

        $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM stocks WHERE warehouse_id = ?');
        $stmt->execute([$warehouseId]);
        if (toMilli($stmt->fetchColumn()) > 0) {
            throw new AppException("Kho $code vẫn còn hàng: hãy điều chuyển / xuất hết trước khi xóa.");
        }
        $activity = getWarehouseActivity($pdo, $warehouseId);
        if ($activity['pending'] > 0) {
            throw new AppException("Kho $code còn {$activity['pending']} phiếu chờ duyệt.");
        }
        if ($activity['open_count']) {
            throw new AppException("Kho $code đang kiểm kê (đợt {$activity['open_count']['code']}).");
        }

        $stmt = $pdo->prepare('
            SELECT EXISTS(SELECT 1 FROM vouchers WHERE warehouse_id = ? OR to_warehouse_id = ?)
                OR EXISTS(SELECT 1 FROM stocks WHERE warehouse_id = ?)
                OR EXISTS(SELECT 1 FROM stock_movements WHERE warehouse_id = ?)
                OR EXISTS(SELECT 1 FROM stock_counts WHERE warehouse_id = ?)
        ');
        $stmt->execute(array_fill(0, 5, $warehouseId));
        if ((int) $stmt->fetchColumn() === 1) {
            $pdo->prepare('UPDATE warehouses SET is_active = 0 WHERE id = ?')->execute([$warehouseId]);
            $result = 'deactivated';
        } else {
            $pdo->prepare('DELETE FROM warehouses WHERE id = ?')->execute([$warehouseId]);   // user_warehouses tự xóa theo (CASCADE)
            $result = 'deleted';
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function restoreWarehouse(PDO $pdo, array $user, int $warehouseId): void
{
    assertCatalogManager($user);
    if (!canAccessWarehouse($user, $warehouseId)) {
        throw new AppException('Lỗi bảo mật: bạn không có quyền trên kho này.');
    }
    $pdo->prepare('UPDATE warehouses SET is_active = 1 WHERE id = ?')->execute([$warehouseId]);
}

/**
 * Nhập kho từ CSV: mã mới -> thêm (kèm phân quyền), mã đã có -> cập nhật tên / địa chỉ
 * (chỉ kho mình có quyền). Tất cả hoặc không. Trả về [số thêm, số cập nhật].
 */
function importWarehouses(PDO $pdo, array $user, array $rows): array
{
    assertCatalogManager($user);
    $errors = [];
    $valid  = [];
    $seen   = [];
    foreach ($rows as $line => $row) {
        try {
            $warehouse = normalizeWarehouseInput($row);
            if (isset($seen[$warehouse['code']])) {
                throw new AppException("trùng mã {$warehouse['code']} với dòng {$seen[$warehouse['code']]}");
            }
            $existing = findWarehouseIdByCode($pdo, $warehouse['code']);
            if ($existing !== null && !canAccessWarehouse($user, $existing)) {
                throw new AppException("không có quyền sửa kho {$warehouse['code']}");
            }
            $seen[$warehouse['code']] = $line;
            $valid[$line] = $warehouse + ['id' => $existing];
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
        $update = $pdo->prepare('UPDATE warehouses SET name = ?, address = ? WHERE id = ?');
        foreach ($valid as $warehouse) {
            if ($warehouse['id'] === null) {
                insertWarehouse($pdo, $user, $warehouse);
                $created++;
            } else {
                $update->execute([$warehouse['name'], $warehouse['address'], $warehouse['id']]);
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
