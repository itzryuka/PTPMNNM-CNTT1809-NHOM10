<?php
// Đăng nhập, phiên làm việc, quyền theo kho, chống CSRF và thông báo flash

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/** Kiểm tra tài khoản + mật khẩu; đúng thì lưu user_id vào session */
function attemptLogin(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return false;
    }
    startSession();
    session_regenerate_id(true);               // chống chiếm phiên (session fixation)
    $_SESSION['user_id'] = (int) $row['id'];
    return true;
}

function logoutUser(): void
{
    startSession();
    $_SESSION = [];
    session_destroy();
}

/**
 * Đọc user + danh sách kho được phân quyền từ CSDL.
 * Trả về null nếu user không tồn tại hoặc đã bị khóa.
 */
function loadUser(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT id, username, full_name, role FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT warehouse_id FROM user_warehouses WHERE user_id = ? ORDER BY warehouse_id');
    $stmt->execute([$userId]);
    $user['id']         = (int) $user['id'];
    $user['warehouses'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return $user;
}

/**
 * Bắt buộc đăng nhập, trả về user hiện tại.
 * Quyền kho được đọc lại từ CSDL ở MỖI request, nên khi quản trị thu hồi quyền
 * thì có hiệu lực ngay (không phải chờ user đăng nhập lại).
 */
function requireLogin(string $basePath): array
{
    startSession();
    try {
        $user = loadUser(getDbConnection(), (int) ($_SESSION['user_id'] ?? 0));
    } catch (PDOException $e) {
        http_response_code(500);
        exit('Không kết nối được CSDL: ' . e($e->getMessage()));
    }
    if ($user === null) {
        header('Location: ' . $basePath . 'views/login.php');
        exit;
    }
    return $user;
}

/** Quyền theo kho: kho phải nằm trong danh sách user_warehouses của user */
function canAccessWarehouse(array $user, int $warehouseId): bool
{
    return in_array($warehouseId, $user['warehouses'], true);
}

/** Chỉ quản lý / quản trị mới được duyệt chứng từ */
function isManager(array $user): bool
{
    return in_array($user['role'], ['ADMIN', 'MANAGER'], true);
}

function roleLabel(string $role): string
{
    return ['ADMIN' => 'Quản trị', 'MANAGER' => 'Quản lý kho', 'STAFF' => 'Nhân viên kho'][$role] ?? $role;
}

// "Nguyễn Văn Quản Lý" -> "NL"
function userInitials(string $fullName): string
{
    $words = preg_split('/\s+/u', trim(preg_replace('/\(.*?\)/u', '', $fullName)));
    $first = mb_substr($words[0] ?? '', 0, 1);
    $last  = count($words) > 1 ? mb_substr(end($words), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

// ===== CHỐNG CSRF: mọi form POST phải gửi kèm token của phiên =====
function csrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    startSession();
    $sent = (string) ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(400);
        exit('Phiên làm việc đã hết hạn, vui lòng tải lại trang và thử lại.');
    }
}

// ===== THÔNG BÁO FLASH: hiện 1 lần sau khi chuyển trang =====
function setFlash(string $type, string $message): void
{
    startSession();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function takeFlash(): ?array
{
    startSession();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
