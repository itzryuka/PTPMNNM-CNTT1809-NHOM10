<?php
/**
 * Tiến trình con cho phép thử 1 (tranh chấp đồng thời), do run_tests.php khởi chạy.
 * Ngủ tới đúng thời điểm hẹn rồi duyệt phiếu -> 2 tiến trình duyệt CÙNG LÚC thật sự.
 *
 *   php tests/race_worker.php <voucherId> <startAtUnixFloat>
 *
 * In ra 1 dòng: "APPROVED" hoặc "REFUSED <lý do>". CSDL lấy từ biến môi trường DB_NAME.
 */
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';

[, $voucherId, $startAt] = $argv + [null, 0, 0];

$pdo     = getDbConnection();
$manager = loadUser($pdo, 2);                 // quanly
time_sleep_until((float) $startAt);

try {
    approveVoucher($pdo, $manager, (int) $voucherId);
    echo "APPROVED\n";
} catch (Throwable $e) {
    echo 'REFUSED ' . $e->getMessage() . "\n";
}
