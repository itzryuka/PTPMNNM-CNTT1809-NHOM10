<?php
// TÀI KHOẢN & PHÂN QUYỀN (chỉ quản trị hệ thống).
//   - Doanh nghiệp không cho tự đăng ký: quản trị cấp tài khoản cho nhân viên mới, kèm MẬT KHẨU TẠM hiện 1 lần;
//     lần đăng nhập đầu bắt buộc đổi mật khẩu (requireLogin chặn mọi trang khác).
//   - Giao diện chỉ cấp 2 mức: Quản lý kho / Nhân viên kho. Tài khoản quản trị không tạo / sửa / khóa qua giao diện.
//   - Quyền theo kho (user_warehouses): quản lý chỉ duyệt / giữ hàng / kiểm kê / sửa kho ở các kho được giao;
//     nhân viên chỉ lập phiếu, nhận / xuất / gửi hàng ở kho được giao. Mọi hàm nghiệp vụ kiểm tra lại ở server.
//   - Nghỉ việc thì KHÓA, không xóa: chứng từ cũ vẫn ghi tên người lập / người duyệt.
// Cần nạp trước: helpers.php, auth.php

const ACCOUNT_ROLES = ['MANAGER' => 'Quản lý kho', 'STAFF' => 'Nhân viên kho'];   // vai trò quản trị cấp được
const ACCOUNT_STATUS_FILTERS = ['active' => 'Đang hoạt động', 'locked' => 'Đã khóa', 'all' => 'Tất cả'];
const ACCOUNT_SORT_COLUMNS = [
    'username'   => 'u.username',
    'name'       => 'u.full_name',
    'role'       => 'u.role',
    'last_login' => 'u.last_login_at',
    'created'    => 'u.created_at',
];

function assertAdmin(array $user): void
{
    if (!isAdmin($user)) {
        throw new AppException('Chỉ quản trị hệ thống mới được quản lý tài khoản và phân quyền.');
    }
}

/** $filters = [keyword, role ('' | MANAGER | STAFF | ADMIN), status (active | locked | all)] */
function buildAccountCondition(array $filters): array
{
    $sql = match ($filters['status'] ?? 'active') {
        'locked' => 'u.is_active = 0',
        'all'    => '1 = 1',
        default  => 'u.is_active = 1',
    };
    $params = [];
    if (($filters['role'] ?? '') !== '') {
        $sql     .= ' AND u.role = ?';
        $params[] = $filters['role'];
    }
    if (($filters['keyword'] ?? '') !== '') {
        $sql     .= ' AND (u.username LIKE ? OR u.full_name LIKE ?)';
        $params[] = "%{$filters['keyword']}%";
        $params[] = "%{$filters['keyword']}%";
    }
    return [$sql, $params];
}

function countAccounts(PDO $pdo, array $filters): int
{
    [$where, $params] = buildAccountCondition($filters);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Tài khoản kèm danh sách kho phụ trách (mã kho, id kho) */
function getAccounts(PDO $pdo, array $filters, int $limit, int $offset, array $sort = ['role', 'asc']): array
{
    [$where, $params] = buildAccountCondition($filters);
    $orderBy = orderByClause(ACCOUNT_SORT_COLUMNS, $sort, 'u.username');
    $limit   = max(1, $limit);
    $offset  = max(0, $offset);
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.role, u.is_active, u.must_change_password, u.last_login_at, u.created_at,
               GROUP_CONCAT(w.code ORDER BY w.code SEPARATOR ', ') AS warehouse_codes,
               GROUP_CONCAT(w.id ORDER BY w.code) AS warehouse_ids
        FROM users u
        LEFT JOIN user_warehouses uw ON uw.user_id = u.id
        LEFT JOIN warehouses w ON w.id = uw.warehouse_id AND w.is_transit = 0
        WHERE $where
        GROUP BY u.id, u.username, u.full_name, u.role, u.is_active, u.must_change_password, u.last_login_at, u.created_at
        ORDER BY $orderBy
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getAccount(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT id, username, full_name, role, is_active, must_change_password, last_login_at, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $account = $stmt->fetch();
    if (!$account) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT warehouse_id FROM user_warehouses WHERE user_id = ? ORDER BY warehouse_id');
    $stmt->execute([$userId]);
    $account['warehouses'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return $account;
}

/** Kho giao được cho tài khoản: đang hoạt động, không phải kho trung chuyển */
function assignableWarehouses(PDO $pdo): array
{
    return $pdo->query('SELECT id, code, name FROM warehouses WHERE is_active = 1 AND is_transit = 0 ORDER BY code')->fetchAll();
}

/** Danh sách id kho gửi lên -> chỉ giữ kho hợp lệ; kho lạ (sửa HTML) -> báo lỗi */
function normalizeWarehouseIds(PDO $pdo, $raw): array
{
    $ids   = array_values(array_unique(array_map('intval', is_array($raw) ? $raw : [])));
    $valid = array_map('intval', array_column(assignableWarehouses($pdo), 'id'));
    if (array_diff($ids, $valid)) {
        throw new AppException('Có kho không tồn tại hoặc đã ngừng hoạt động.');
    }
    sort($ids);
    return $ids;
}

function normalizeAccountInput(array $input, bool $withUsername): array
{
    $account = [
        'full_name' => normalizeName((string) ($input['full_name'] ?? '')),
        'role'      => (string) ($input['role'] ?? ''),
    ];
    if ($withUsername) {
        $account['username'] = strtolower(trim((string) ($input['username'] ?? '')));
        if (!preg_match('/^[a-z0-9._]{3,30}$/', $account['username'])) {
            throw new AppException('Tên đăng nhập 3–30 ký tự, chỉ gồm chữ thường không dấu, số, dấu chấm và gạch dưới.');
        }
    }
    if ($account['full_name'] === '' || mb_strlen($account['full_name']) > 100) {
        throw new AppException('Họ tên không được trống (tối đa 100 ký tự).');
    }
    if (!array_key_exists($account['role'], ACCOUNT_ROLES)) {
        throw new AppException('Vai trò chỉ được là Quản lý kho hoặc Nhân viên kho.');
    }
    return $account;
}

/** Mật khẩu tạm 10 ký tự, bỏ các ký tự dễ nhầm (0/O, 1/l/I) để đọc cho nhân viên */
function generateTempPassword(): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $password = '';
        for ($i = 0; $i < 10; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
    } while (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/\d/', $password));
    return $password;
}

function replaceWarehouseAssignments(PDO $pdo, int $userId, array $warehouseIds): void
{
    $pdo->prepare('DELETE FROM user_warehouses WHERE user_id = ?')->execute([$userId]);
    $insert = $pdo->prepare('INSERT INTO user_warehouses (user_id, warehouse_id) VALUES (?, ?)');
    foreach ($warehouseIds as $warehouseId) {
        $insert->execute([$userId, $warehouseId]);
    }
}

/** Khóa dòng tài khoản để sửa; tài khoản quản trị không sửa qua giao diện */
function lockEditableAccount(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, username, role, is_active FROM users WHERE id = ? FOR UPDATE');
    $stmt->execute([$userId]);
    $account = $stmt->fetch();
    if (!$account) {
        throw new AppException('Không tìm thấy tài khoản.');
    }
    if ($account['role'] === 'ADMIN') {
        throw new AppException('Tài khoản quản trị hệ thống không sửa / khóa qua giao diện.');
    }
    return $account;
}

/** Cấp tài khoản mới. Trả về [id, mật khẩu tạm] (mật khẩu tạm chỉ hiện 1 lần cho quản trị). */
function createAccount(PDO $pdo, array $admin, array $input): array
{
    assertAdmin($admin);
    $account    = normalizeAccountInput($input, true);
    $warehouses = normalizeWarehouseIds($pdo, $input['warehouses'] ?? []);
    $password   = generateTempPassword();

    return inTransaction($pdo, function () use ($pdo, $account, $warehouses, $password) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$account['username']]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new AppException("Tên đăng nhập {$account['username']} đã có người dùng.");
        }
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, must_change_password) VALUES (?, ?, ?, ?, 1)');
        $stmt->execute([$account['username'], password_hash($password, PASSWORD_DEFAULT), $account['full_name'], $account['role']]);
        $userId = (int) $pdo->lastInsertId();
        replaceWarehouseAssignments($pdo, $userId, $warehouses);
        return [$userId, $password];
    });
}

/** Sửa họ tên, vai trò (2 mức), kho phụ trách. Tên đăng nhập giữ nguyên. Có hiệu lực ngay ở request tiếp theo của người đó. */
function updateAccount(PDO $pdo, array $admin, int $userId, array $input): void
{
    assertAdmin($admin);
    $account    = normalizeAccountInput($input, false);
    $warehouses = normalizeWarehouseIds($pdo, $input['warehouses'] ?? []);
    inTransaction($pdo, function () use ($pdo, $userId, $account, $warehouses) {
        lockEditableAccount($pdo, $userId);
        $pdo->prepare('UPDATE users SET full_name = ?, role = ? WHERE id = ?')->execute([$account['full_name'], $account['role'], $userId]);
        replaceWarehouseAssignments($pdo, $userId, $warehouses);
    });
}

/** Khóa (nghỉ việc) / mở khóa. Bị khóa thì bị đẩy ra ngay ở lần tải trang tiếp theo. */
function setAccountActive(PDO $pdo, array $admin, int $userId, bool $active): void
{
    assertAdmin($admin);
    if ($userId === $admin['id']) {
        throw new AppException('Không tự khóa tài khoản của chính mình.');
    }
    inTransaction($pdo, function () use ($pdo, $userId, $active) {
        lockEditableAccount($pdo, $userId);
        $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $userId]);
    });
}

/** Đặt lại mật khẩu khi nhân viên quên: sinh mật khẩu tạm mới, bắt đổi khi đăng nhập. Trả về mật khẩu tạm. */
function resetAccountPassword(PDO $pdo, array $admin, int $userId): string
{
    assertAdmin($admin);
    $password = generateTempPassword();
    inTransaction($pdo, function () use ($pdo, $userId, $password) {
        lockEditableAccount($pdo, $userId);
        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    });
    return $password;
}

/** Kho đang hoạt động chưa có quản lý kho nào (đang hoạt động) phụ trách: không ai duyệt phiếu ở kho đó được */
function warehousesWithoutManager(PDO $pdo): array
{
    return $pdo->query("
        SELECT w.code, w.name FROM warehouses w
        WHERE w.is_active = 1 AND w.is_transit = 0 AND NOT EXISTS (
            SELECT 1 FROM user_warehouses uw JOIN users u ON u.id = uw.user_id
            WHERE uw.warehouse_id = w.id AND u.role = 'MANAGER' AND u.is_active = 1
        )
        ORDER BY w.code
    ")->fetchAll();
}

/** Mỗi người tự đổi mật khẩu (bắt buộc với mật khẩu tạm). Mật khẩu mới >= 8 ký tự, có chữ và số, khác mật khẩu cũ. */
function changeOwnPassword(PDO $pdo, array $user, string $current, string $new, string $confirm): void
{
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    if (!password_verify($current, (string) $stmt->fetchColumn())) {
        throw new AppException('Mật khẩu hiện tại không đúng.');
    }
    if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        throw new AppException('Mật khẩu mới phải có ít nhất 8 ký tự, gồm cả chữ và số.');
    }
    if ($new !== $confirm) {
        throw new AppException('Nhập lại mật khẩu mới không khớp.');
    }
    if ($new === $current) {
        throw new AppException('Mật khẩu mới phải khác mật khẩu hiện tại.');
    }
    $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
}
