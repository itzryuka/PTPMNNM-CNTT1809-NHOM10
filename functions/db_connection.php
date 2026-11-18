<?php
// Kết nối PDO tới CSDL quanlykhohang (XAMPP mặc định: root / không mật khẩu)

// PHP (XAMPP mặc định Europe/Berlin) và MySQL phải cùng múi giờ Việt Nam,
// nếu không ngày lập phiếu (PHP) và thời điểm ghi sổ NOW() (MySQL) có thể lệch nhau 1 ngày
date_default_timezone_set('Asia/Ho_Chi_Minh');

/**
 * Thông số kết nối. Mặc định theo XAMPP; có thể ghi đè bằng biến môi trường
 * DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS (bộ test dùng DB_NAME để chạy trên CSDL riêng).
 * MySQL trên cloud thường bắt buộc SSL: DB_SSL=1 (dùng chứng chỉ gốc của hệ thống) hoặc DB_SSL=/đường/dẫn/ca.pem.
 */
function dbConfig(): array
{
    return [
        'host'     => getenv('DB_HOST') ?: 'localhost',
        'port'     => (int) (getenv('DB_PORT') ?: 3306),
        'dbname'   => getenv('DB_NAME') ?: 'quanlykhohang',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'ssl'      => (string) (getenv('DB_SSL') ?: ''),
    ];
}

function getDbConnection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    ['host' => $host, 'port' => $port, 'dbname' => $dbname, 'username' => $username, 'password' => $password, 'ssl' => $ssl] = dbConfig();

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // bắt buộc để rollBack hoạt động
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if ($ssl !== '' && $ssl !== '0') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ssl === '1' ? '/etc/ssl/certs/ca-certificates.crt' : $ssl;
    }

    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password, $options);
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}
