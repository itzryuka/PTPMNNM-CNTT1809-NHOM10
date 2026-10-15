<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (!extension_loaded('pgsql')) {
    fwrite(STDERR, "THIEU_PGSQL\n");
    exit(2);
}
function ask(string $label, string $default): string
{
    echo "$label [$default]: ";
    $line = fgets(STDIN);
    if ($line === false) {
        throw new RuntimeException('Khong doc duoc du lieu');
    }
    $value = trim($line);
    return $value === '' ? $default : $value;
}
try {
    $c = [
        'PGHOST' => ask('Máy chủ', '127.0.0.1'),
        'PGPORT' => ask('Cổng', '5432'),
        'PGDATABASE' => ask('CSDL lab riêng', 'communitypass_lab_v5'),
        'PGUSER' => ask('Tài khoản lab', 'communitypass_lab_v5')
    ];
    if (!ctype_digit($c['PGPORT']) || (int)$c['PGPORT'] < 1 || (int)$c['PGPORT'] > 65535) {
        throw new RuntimeException('Cong khong hop le');
    }
    if (!stream_isatty(STDIN)) {
        throw new RuntimeException('Mo terminal tuong tac de nhap mat khau');
    }
    echo 'Mật khẩu CSDL (không hiển thị): ';
    exec('stty -echo', $out, $rc);
    if ($rc !== 0) {
        throw new RuntimeException('Khong an duoc mat khau');
    }
    try {
        $password = fgets(STDIN);
    } finally {
        exec('stty echo');
        echo "\n";
    }
    if ($password === false || rtrim($password, "\r\n") === '') {
        throw new RuntimeException('Mat khau rong');
    }
    $c['PGPASSWORD'] = rtrim($password, "\r\n");
    foreach ($c as $key => $value) {
        putenv($key . '=' . $value);
    }
    $db = @pg_connect('connect_timeout=3');
    if (!$db) {
        throw new RuntimeException('Ket noi that bai; chua luu cau hinh');
    }
    $dir = __DIR__ . '/../.local';
    $old = umask(0077);
    try {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new RuntimeException('Khong tao duoc thu muc');
        }
        $json = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($dir . '/db.json.tmp', $json) === false) {
            throw new RuntimeException('Khong ghi duoc cau hinh');
        }
        chmod($dir . '/db.json.tmp', 0600);
        if (!rename($dir . '/db.json.tmp', $dir . '/db.json')) {
            throw new RuntimeException('Khong luu duoc cau hinh');
        }
    } finally {
        umask($old);
    }
    echo "CAU_HINH=DA_LUU | Chỉ lưu trên máy này. Không gửi thư mục .local.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
