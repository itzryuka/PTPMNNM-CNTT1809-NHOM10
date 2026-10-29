<?php
/**
 * Tiến trình con cho các phép thử tranh chấp đồng thời, do run_tests.php khởi chạy.
 * Ngủ tới đúng thời điểm hẹn rồi thực hiện 1 thao tác -> nhiều tiến trình làm CÙNG LÚC thật sự.
 *
 *   php tests/race_worker.php <voucherId> <startAtUnixFloat> [approve|ship|send]
 *
 * In ra 1 dòng: "APPROVED" (thao tác thành công) hoặc "REFUSED <lý do>". CSDL lấy từ biến môi trường DB_NAME.
 */
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';

[, $voucherId, $startAt, $action] = $argv + [null, 0, 0, 'approve'];

$pdo  = getDbConnection();
$user = loadUser($pdo, 2);                    // quanly: quản lý, cả 3 kho
time_sleep_until((float) $startAt);

try {
    match ($action) {
        'ship'  => shipExport($pdo, $user, (int) $voucherId, bin2hex(random_bytes(16))),
        'send'  => sendTransfer($pdo, $user, (int) $voucherId, bin2hex(random_bytes(16))),
        default => approveVoucher($pdo, $user, (int) $voucherId),
    };
    echo "APPROVED\n";
} catch (Throwable $e) {
    echo 'REFUSED ' . $e->getMessage() . "\n";
}
