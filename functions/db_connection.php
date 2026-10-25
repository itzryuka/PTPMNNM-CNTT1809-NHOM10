<?php
// Kết nối PDO tới CSDL quanlykhohang (XAMPP mặc định: root / không mật khẩu)

// PHP (XAMPP mặc định Europe/Berlin) và MySQL phải cùng múi giờ Việt Nam,
// nếu không ngày lập phiếu (PHP) và thời điểm ghi sổ NOW() (MySQL) có thể lệch nhau 1 ngày
date_default_timezone_set('Asia/Ho_Chi_Minh');

/**
 * Thông số kết nối. Mặc định theo XAMPP; có thể ghi đè bằng biến môi trường
 * DB_HOST / DB_NAME / DB_USER / DB_PASS (bộ test dùng DB_NAME để chạy trên CSDL riêng).
 */
function dbConfig(): array
{
    return [
        'host'     => getenv('DB_HOST') ?: 'localhost',
        'dbname'   => getenv('DB_NAME') ?: 'quanlykhohang',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
    ];
}

function getDbConnection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    ['host' => $host, 'dbname' => $dbname, 'username' => $username, 'password' => $password] = dbConfig();

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // bắt buộc để rollBack hoạt động
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}
