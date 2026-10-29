<?php
/**
 * BỘ KIỂM THỬ TỰ ĐỘNG - phiếu nhập / xuất / điều chuyển (các phép thử bắt buộc của đề tài)
 *
 *   php tests/run_tests.php                 chạy tất cả
 *   php tests/run_tests.php --keep          giữ lại CSDL test sau khi chạy (để xem dữ liệu)
 *   php tests/run_tests.php --mysql=PATH    chỉ đường dẫn mysql client nếu không tự tìm được
 *
 * An toàn cho dữ liệu thật: bộ test KHÔNG chạy trên CSDL quanlykhohang.
 * Nó dựng CSDL riêng quanlykhohang_test từ database/quanlykhohang.sql, chạy test,
 * rồi xóa CSDL đó. CSDL đang dùng không bị thay đổi, kể cả khi test lỗi giữa chừng.
 *
 * Mã thoát: 0 = tất cả PASS, 1 = có FAIL, 2 = không dựng được CSDL test.
 */

const TEST_DB = 'quanlykhohang_test';
putenv('DB_NAME=' . TEST_DB);                       // getDbConnection() + tiến trình con dùng CSDL test

require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/stock_count_functions.php';
require_once __DIR__ . '/../functions/csv_functions.php';
require_once __DIR__ . '/../functions/item_functions.php';
require_once __DIR__ . '/../functions/warehouse_functions.php';
require_once __DIR__ . '/../functions/voucher_csv_functions.php';
require_once __DIR__ . '/../functions/transfer_request_functions.php';
require_once __DIR__ . '/../functions/report_functions.php';
require_once __DIR__ . '/../functions/dashboard_functions.php';
require_once __DIR__ . '/../functions/account_functions.php';
require_once __DIR__ . '/../functions/batch_functions.php';
require_once __DIR__ . '/../functions/chat_functions.php';

$options = getopt('', ['keep', 'mysql:']);
$results = ['pass' => 0, 'fail' => 0, 'skip' => 0];

// =====================================================================
// HẠ TẦNG: dựng / xóa CSDL test, hàm kiểm tra
// =====================================================================

function findMysqlClient(array $options): string
{
    $candidates = array_filter([
        $options['mysql'] ?? null,
        getenv('MYSQL_BIN') ?: null,
        dirname(PHP_BINARY) . '/../mysql/bin/mysql.exe',   // XAMPP Windows
        dirname(PHP_BINARY) . '/../mysql/bin/mysql',       // XAMPP Linux / macOS
    ]);
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return realpath($path);
        }
    }
    return 'mysql';                                         // trong PATH
}

/** Import quanlykhohang.sql vào CSDL test (đổi tên CSDL trong 3 lệnh DROP / CREATE / USE) */
function createTestDatabase(string $mysql): void
{
    $sql = file_get_contents(__DIR__ . '/../database/quanlykhohang.sql');
    foreach (['DROP DATABASE IF EXISTS %s;', 'CREATE DATABASE %s', 'USE %s;'] as $statement) {
        $from = sprintf($statement, 'quanlykhohang');
        if (substr_count($sql, $from) !== 1) {
            throw new RuntimeException("Không tìm thấy đúng 1 lệnh \"$from\" trong quanlykhohang.sql");
        }
        $sql = str_replace($from, sprintf($statement, TEST_DB), $sql);
    }

    $cfg = dbConfig();
    $cmd = [$mysql, '--default-character-set=utf8mb4', '-h', $cfg['host'], '-u', $cfg['username']];
    if ($cfg['password'] !== '') {
        $cmd[] = '-p' . $cfg['password'];
    }
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException("Không chạy được mysql client: $mysql");
    }
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException("Import CSDL test thất bại: $error");
    }
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[$ok ? 'pass' : 'fail']++;
    echo ($ok ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m ") . $name . ($detail !== '' ? "\n         → $detail" : '') . "\n";
}

function skip(string $name, string $reason): void
{
    global $results;
    $results['skip']++;
    echo "  \033[33mSKIP\033[0m $name\n         → $reason\n";
}

function section(string $title): void
{
    echo "\n\033[1m$title\033[0m\n";
}

/** Chạy hàm, trả về thông báo lỗi ('' nếu không lỗi) */
function errorOf(callable $fn): string
{
    try {
        $fn();
        return '';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function scalar(string $sql, array $params = [])
{
    $stmt = getDbConnection()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function stockOf(int $warehouseId, int $batchId): float
{
    return (float) scalar('SELECT COALESCE(SUM(quantity), 0) FROM stocks WHERE warehouse_id = ? AND batch_id = ?', [$warehouseId, $batchId]);
}

function reservedOf(int $warehouseId, int $batchId): float
{
    return (float) scalar('SELECT COALESCE(SUM(reserved), 0) FROM stocks WHERE warehouse_id = ? AND batch_id = ?', [$warehouseId, $batchId]);
}

function movementCount(): int
{
    return (int) scalar('SELECT COUNT(*) FROM stock_movements');
}

function ledgerMismatches(): int
{
    return (int) scalar('SELECT COALESCE(SUM(is_matched = 0), 0) FROM v_stock_reconciliation');
}

/** Lệch ở cả 3 phép đối chiếu: tồn = sổ, đang giữ = phiếu đã duyệt, đang đi đường = phiếu chưa nhận */
function allMismatches(): int
{
    return ledgerMismatches()
        + (int) scalar('SELECT COALESCE(SUM(is_matched = 0), 0) FROM v_reservation_check')
        + (int) scalar('SELECT COALESCE(SUM(is_matched = 0), 0) FROM v_transit_check');
}

function voucherStatus(int $id): string
{
    return (string) scalar('SELECT status FROM vouchers WHERE id = ?', [$id]);
}

function newToken(): string
{
    return bin2hex(random_bytes(16));
}

/** [id dòng phiếu => giá trị cột] */
function lineColumn(int $voucherId, string $column): array
{
    $stmt = getDbConnection()->prepare("SELECT id, $column FROM voucher_lines WHERE voucher_id = ? ORDER BY id");
    $stmt->execute([$voucherId]);
    return array_column($stmt->fetchAll(), $column, 'id');
}

/** Nhận đủ mọi dòng còn chờ của phiếu nhập trong 1 đợt */
function receiveAll(PDO $pdo, array $user, int $voucherId): void
{
    $stmt = $pdo->prepare('SELECT id, quantity - received_qty AS waiting FROM voucher_lines WHERE voucher_id = ? AND received_qty < quantity');
    $stmt->execute([$voucherId]);
    $entries = [];
    foreach ($stmt->fetchAll() as $line) {
        $entries[$line['id']] = ['good' => $line['waiting'], 'damaged' => '0'];
    }
    receiveImport($pdo, $user, $voucherId, $entries, '', newToken());
}

/** Lập + duyệt + nhận đủ 1 phiếu nhập lô mới. Trả về id lô. */
function importNewBatch(array $staff, array $manager, int $warehouseId, int $itemId, string $batchNo, int $qty, string $expiry = '2029-01-01'): int
{
    $pdo = getDbConnection();
    $id  = createVoucher($pdo, $staff, [
        'type' => 'IMPORT', 'warehouse_id' => $warehouseId, 'voucher_date' => date('Y-m-d'), 'reason' => "Chuẩn bị dữ liệu test $batchNo",
        'client_token' => newToken(),
        'lines' => [['item_id' => $itemId, 'batch_id' => 'new', 'new_batch_no' => $batchNo, 'new_expiry' => $expiry, 'quantity' => (string) $qty]],
    ]);
    approveVoucher($pdo, $manager, $id);
    receiveAll($pdo, $staff, $id);
    return (int) scalar('SELECT id FROM batches WHERE batch_no = ? AND item_id = ?', [$batchNo, $itemId]);
}

/** Điều chuyển trọn vẹn: duyệt (giữ hàng) -> gửi -> kho đến nhận đủ */
function completeTransfer(PDO $pdo, array $manager, int $voucherId): void
{
    approveVoucher($pdo, $manager, $voucherId);
    sendTransfer($pdo, $manager, $voucherId, newToken());
    receiveTransfer($pdo, $manager, $voucherId, lineColumn($voucherId, 'shipped_qty'), newToken());
}

/**
 * Chạy song song 1 tiến trình PHP cho mỗi phiếu, tất cả cùng làm $action ('approve' | 'ship' | 'send') tại 1 thời điểm.
 * Trả về [vị trí => 'APPROVED' | 'REFUSED ...']
 */
function runConcurrently(array $voucherIds, string $action = 'approve'): array
{
    $startAt = microtime(true) + 1.0;                // đủ thời gian để mọi tiến trình khởi động xong
    $procs = [];
    foreach ($voucherIds as $n => $id) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/race_worker.php') . " $id $startAt $action";
        $procs[$n] = popen($cmd, 'r');
    }
    $outcomes = [];
    foreach ($procs as $n => $proc) {
        $outcomes[$n] = trim(stream_get_contents($proc));
        pclose($proc);
    }
    return $outcomes;
}

/** Tạo lô mới tồn đúng $qty ở kho $warehouseId + $count phiếu xuất PENDING, mỗi phiếu xuất $exportQty */
function makeRaceFixture(array $staff, array $manager, int $warehouseId, string $batchNo, int $qty, int $exportQty, int $count): array
{
    $pdo     = getDbConnection();
    $batchId = importNewBatch($staff, $manager, $warehouseId, 3, $batchNo, $qty);
    $exportIds = [];
    for ($i = 1; $i <= $count; $i++) {
        $exportIds[] = createVoucher($pdo, $staff, [
            'type' => 'EXPORT', 'warehouse_id' => $warehouseId, 'voucher_date' => date('Y-m-d'),
            'reason' => "Phép thử 1 - yêu cầu $i", 'client_token' => newToken(),
            'lines' => [['item_id' => 3, 'batch_id' => $batchId, 'quantity' => (string) $exportQty]],
        ]);
    }
    return [$batchId, $exportIds];
}

// =====================================================================
// CHẠY
// =====================================================================

echo "Dựng CSDL test " . TEST_DB . " từ database/quanlykhohang.sql ...\n";
try {
    createTestDatabase(findMysqlClient($options));
    $pdo = getDbConnection();
} catch (Throwable $e) {
    fwrite(STDERR, 'LỖI: ' . $e->getMessage() . "\nGợi ý: chạy với --mysql=\"đường/dẫn/mysql.exe\"\n");
    exit(2);
}

$admin   = loadUser($pdo, 1);     // admin: quản trị hệ thống (tạo / xóa kho, cấp tài khoản)
$manager = loadUser($pdo, 2);     // quanly: quản lý, cả 3 kho
$staffA  = loadUser($pdo, 3);     // nhanvien_a: nhân viên, chỉ Kho HN (id 1)
$staffB  = loadUser($pdo, 4);     // nhanvien_b: nhân viên, chỉ Kho HCM (id 2)
$staffC  = loadUser($pdo, 5);     // nhanvien_c: nhân viên, chỉ Kho ĐN (id 3)
$transit = transitWarehouseId($pdo);

try {
    // -----------------------------------------------------------------
    section('PHÉP THỬ 1 - Kho còn 5, hai yêu cầu CÙNG LÚC xuất 4');
    // -----------------------------------------------------------------
    // Duyệt phiếu xuất = GIỮ CHỖ hàng. 2 quản lý duyệt cùng lúc: chỉ 1 phiếu giữ được, phiếu kia bị từ chối.
    // Vòng 1 dùng dữ liệu mẫu (lô L2610-TEST, PX-2026-0032/0033); vòng 2-3 tự tạo dữ liệu mới
    $raceBatch = (int) scalar("SELECT id FROM batches WHERE batch_no = 'L2610-TEST'");
    $raceIds   = array_map('intval', $pdo->query("SELECT id FROM vouchers WHERE reason LIKE 'Phép thử 1%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    $rounds    = [[$raceBatch, $raceIds]];
    for ($round = 2; $round <= 3; $round++) {
        $rounds[] = makeRaceFixture($staffA, $manager, 1, "L-RACE-$round", 5, 4, 2);
    }

    foreach ($rounds as $n => [$batchId, $voucherIds]) {
        $before   = movementCount();
        $outcomes = runConcurrently($voucherIds);
        $approved = array_values(array_intersect_key($voucherIds, array_filter($outcomes, fn ($o) => $o === 'APPROVED')));
        $refused  = array_values(array_filter($outcomes, fn ($o) => $o !== 'APPROVED'));
        check(sprintf('Vòng %d: chỉ 1 trong 2 phiếu được duyệt (giữ chỗ)', $n + 1), count($approved) === 1, implode(' | ', $outcomes));
        check(sprintf('Vòng %d: phiếu còn lại bị từ chối vì không đủ tồn có thể xuất', $n + 1),
            count($refused) === 1 && str_contains($refused[0], 'Không đủ tồn'));
        check(sprintf('Vòng %d: đang giữ 4, tồn thực vẫn 5 (hàng chưa rời kho), chưa ghi sổ', $n + 1),
            reservedOf(1, $batchId) == 4.0 && stockOf(1, $batchId) == 5.0 && movementCount() === $before,
            'tồn = ' . stockOf(1, $batchId) . ', đang giữ = ' . reservedOf(1, $batchId));
        shipExport($pdo, $staffA, $approved[0], newToken());
        check(sprintf('Vòng %d: xuất kho: tồn còn 1 (không âm), hết giữ chỗ, sổ phát sinh thêm đúng 1 dòng', $n + 1),
            stockOf(1, $batchId) == 1.0 && reservedOf(1, $batchId) == 0.0 && movementCount() === $before + 1,
            'tồn = ' . stockOf(1, $batchId) . ', số dòng phát sinh mới = ' . (movementCount() - $before));
    }

    // 2 thủ kho cùng bấm "Đã xuất kho" cho 1 phiếu
    [$shipBatch, [$shipId]] = makeRaceFixture($staffA, $manager, 1, 'L-RACE-SHIP', 5, 4, 1);
    approveVoucher($pdo, $manager, $shipId);
    $before   = movementCount();
    $outcomes = runConcurrently([$shipId, $shipId], 'ship');
    check('2 người cùng bấm "Đã xuất kho" 1 phiếu: chỉ 1 lần được ghi, tồn còn 1',
        count(array_filter($outcomes, fn ($o) => $o === 'APPROVED')) === 1 && movementCount() === $before + 1 && stockOf(1, $shipBatch) == 1.0,
        implode(' | ', $outcomes));

    // -----------------------------------------------------------------
    section('PHÉP THỬ 2 - Lỗi giữa lúc trừ kho A và cộng kho B');
    // -----------------------------------------------------------------
    // Điều chuyển HN -> HCM 10 (phiếu PC-2026-0018 trong dữ liệu mẫu), đi qua kho "Hàng đang đi đường"
    $transferId    = (int) scalar("SELECT id FROM vouchers WHERE reason LIKE 'Phép thử 2%'");
    $transferBatch = (int) scalar('SELECT batch_id FROM voucher_lines WHERE voucher_id = ?', [$transferId]);
    $transferLine  = (int) scalar('SELECT id FROM voucher_lines WHERE voucher_id = ?', [$transferId]);
    [$hnBefore, $hcmBefore, $transitBefore] = [stockOf(1, $transferBatch), stockOf(2, $transferBatch), stockOf($transit, $transferBatch)];
    approveVoucher($pdo, $manager, $transferId);
    check('Duyệt điều chuyển: giữ 10 ở kho đi, tồn chưa đổi', reservedOf(1, $transferBatch) == 10.0 && stockOf(1, $transferBatch) == $hnBefore);

    $snapshot = fn () => [stockOf(1, $transferBatch), reservedOf(1, $transferBatch), stockOf(2, $transferBatch), stockOf($transit, $transferBatch),
                          movementCount(), voucherStatus($transferId), (int) scalar('SELECT COUNT(*) FROM voucher_postings')];
    // Gây lỗi giữa chừng khi GỬI: kho đi đã bị trừ, chưa kịp cộng vào "Đang đi đường"
    $GLOBALS['FAILPOINTS']['posting.after_first_change'] = true;
    $before = $snapshot();
    $error  = errorOf(fn () => sendTransfer($pdo, $staffA, $transferId, newToken()));
    unset($GLOBALS['FAILPOINTS']);
    check('Gửi hàng lỗi giữa chừng: lỗi được báo ra', str_contains($error, 'Lỗi giả lập'), $error);
    check('Gửi hàng lỗi giữa chừng: tồn kho đi, đang giữ, đang đi đường, sổ phát sinh, trạng thái y nguyên', $snapshot() === $before);

    sendTransfer($pdo, $staffA, $transferId, newToken());
    check('Gửi hàng: kho đi −10 (hết giữ chỗ), "Đang đi đường" +10, phiếu đang đi đường',
        stockOf(1, $transferBatch) == $hnBefore - 10 && reservedOf(1, $transferBatch) == 0.0
        && stockOf($transit, $transferBatch) == $transitBefore + 10 && voucherStatus($transferId) === 'IN_TRANSIT',
        "HN $hnBefore → " . stockOf(1, $transferBatch) . ', đang đi đường ' . stockOf($transit, $transferBatch));
    $pair = $pdo->prepare('SELECT sm.warehouse_id, sm.quantity FROM stock_movements sm JOIN voucher_postings p ON p.id = sm.posting_id
                           WHERE sm.voucher_id = ? AND p.kind = ? ORDER BY sm.warehouse_id');
    $pair->execute([$transferId, 'SEND']);
    check('Bước gửi ghi đủ 2 đầu trong 1 đợt ghi sổ: (−) kho đi, (+) đang đi đường',
        array_map(fn ($r) => [(int) $r['warehouse_id'], (float) $r['quantity']], $pair->fetchAll()) == [[1, -10.0], [$transit, 10.0]]);

    // Gây lỗi giữa chừng khi NHẬN: đã trừ "Đang đi đường", chưa cộng kho đến
    $GLOBALS['FAILPOINTS']['posting.after_first_change'] = true;
    $before = $snapshot();
    $error  = errorOf(fn () => receiveTransfer($pdo, $staffB, $transferId, [$transferLine => '10'], newToken()));
    unset($GLOBALS['FAILPOINTS']);
    check('Nhận hàng lỗi giữa chừng: không có thay đổi dở dang', str_contains($error, 'Lỗi giả lập') && $snapshot() === $before, $error);

    receiveTransfer($pdo, $staffB, $transferId, [$transferLine => '10'], newToken());
    check('Kho đến nhận đủ: "Đang đi đường" −10, kho đến +10, phiếu hoàn tất',
        stockOf($transit, $transferBatch) == $transitBefore && stockOf(2, $transferBatch) == $hcmBefore + 10 && voucherStatus($transferId) === 'COMPLETED');
    $pair->execute([$transferId, 'ARRIVE']);
    check('Bước nhận ghi đủ 2 đầu trong 1 đợt ghi sổ: (−) đang đi đường, (+) kho đến',
        array_map(fn ($r) => [(int) $r['warehouse_id'], (float) $r['quantity']], $pair->fetchAll()) == [[2, 10.0], [$transit, -10.0]]);
    check('Tổng hàng của lô (2 kho + đang đi đường) được bảo toàn qua cả 2 bước',
        stockOf(1, $transferBatch) + stockOf(2, $transferBatch) + stockOf($transit, $transferBatch) == $hnBefore + $hcmBefore + $transitBefore);

    // Duyệt (giữ chỗ) phiếu 2 dòng, dòng 2 vượt tồn: không giữ chỗ dòng nào
    $okBatch  = (int) scalar('SELECT batch_id FROM stocks WHERE warehouse_id = 1 AND quantity - reserved >= 5 AND batch_id <> ? ORDER BY batch_id LIMIT 1', [$raceBatch]);
    $badBatch = (int) scalar('SELECT batch_id FROM stocks WHERE warehouse_id = 1 AND quantity > 0 AND batch_id > ? ORDER BY batch_id LIMIT 1', [$okBatch]);
    $pdo->exec("INSERT INTO vouchers (code, type, warehouse_id, to_warehouse_id, voucher_date, reason, created_by)
                VALUES ('PC-TEST-LOI', 'TRANSFER', 1, 3, CURDATE(), 'Phép thử 2 - gây lỗi giữa chừng', 3)");
    $brokenId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity)
                   SELECT ?, item_id, id, 5 FROM batches WHERE id = ?
                   UNION ALL SELECT ?, item_id, id, 999999 FROM batches WHERE id = ?')
        ->execute([$brokenId, $okBatch, $brokenId, $badBatch]);
    $before = [reservedOf(1, $okBatch), reservedOf(1, $badBatch), movementCount()];
    $error  = errorOf(fn () => approveVoucher($pdo, $manager, $brokenId));
    check('Giữ chỗ lỗi ở dòng 2: báo không đủ tồn, dòng 1 cũng không bị giữ, phiếu vẫn chờ duyệt',
        str_contains($error, 'Không đủ tồn') && [reservedOf(1, $okBatch), reservedOf(1, $badBatch), movementCount()] === $before && voucherStatus($brokenId) === 'PENDING', $error);

    // -----------------------------------------------------------------
    section('PHÉP THỬ 3 - Gửi lại chứng từ đã xác nhận');
    // -----------------------------------------------------------------
    $before = [movementCount(), stockOf(1, $transferBatch), stockOf(2, $transferBatch)];
    $error  = errorOf(fn () => approveVoucher($pdo, $manager, $transferId));
    check('Duyệt lại phiếu đã xử lý bị từ chối', str_contains($error, 'đã được xử lý'), $error);
    check('Gửi / nhận lại phiếu đã hoàn tất bị từ chối',
        str_contains(errorOf(fn () => sendTransfer($pdo, $manager, $transferId, newToken())), 'Không thể gửi hàng')
        && str_contains(errorOf(fn () => receiveTransfer($pdo, $manager, $transferId, [$transferLine => '1'], newToken())), 'Không thể nhận hàng'));
    check('Số tồn và sổ phát sinh không tăng thêm', [movementCount(), stockOf(1, $transferBatch), stockOf(2, $transferBatch)] === $before);

    $outcomes = runConcurrently([$transferId, $transferId]);
    check('Bấm duyệt lại 2 lần cùng lúc: cả 2 đều bị từ chối',
        count(array_filter($outcomes, fn ($o) => $o === 'APPROVED')) === 0 && movementCount() === $before[0]);

    // 2 người cùng bấm "Gửi hàng" 1 phiếu điều chuyển
    $sendBatch = importNewBatch($staffA, $manager, 1, 4, 'L-RACE-SEND', 5);
    $sendId = createVoucher($pdo, $staffA, [
        'type' => 'TRANSFER', 'warehouse_id' => 1, 'to_warehouse_id' => 3, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 4, 'batch_id' => $sendBatch, 'quantity' => '5']],
    ]);
    approveVoucher($pdo, $manager, $sendId);
    $before   = movementCount();
    $outcomes = runConcurrently([$sendId, $sendId], 'send');
    check('2 người cùng bấm "Gửi hàng": chỉ 1 lần được ghi (2 dòng phát sinh), không trừ kho 2 lần',
        count(array_filter($outcomes, fn ($o) => $o === 'APPROVED')) === 1 && movementCount() === $before + 2
        && stockOf(1, $sendBatch) == 0.0 && stockOf($transit, $sendBatch) == 5.0, implode(' | ', $outcomes));

    $token = newToken();
    $form  = [
        'type' => 'IMPORT', 'warehouse_id' => 1, 'voucher_date' => date('Y-m-d'), 'reason' => 'Test gửi form 2 lần',
        'client_token' => $token,
        'lines' => [['item_id' => 7, 'batch_id' => 'new', 'new_batch_no' => 'L-TEST-NEW', 'new_mfg' => '2026-10-01', 'new_expiry' => '2028-10-01', 'quantity' => '12.5']],
    ];
    $firstId  = createVoucher($pdo, $staffA, $form);
    $secondId = createVoucher($pdo, $staffA, $form);
    check('Gửi lại form lập phiếu (cùng client_token) không tạo phiếu thứ 2', $firstId === $secondId);

    // -----------------------------------------------------------------
    section('PHÉP THỬ 4 - Tài khoản chỉ phụ trách kho A không lập được phiếu xuất kho B');
    // -----------------------------------------------------------------
    $hcmBatch = (int) scalar('SELECT batch_id FROM stocks WHERE warehouse_id = 2 AND quantity - reserved > 5 ORDER BY batch_id LIMIT 1');
    $hcmItem  = (int) scalar('SELECT item_id FROM batches WHERE id = ?', [$hcmBatch]);
    $vouchersBefore = (int) scalar('SELECT COUNT(*) FROM vouchers');
    // Giả lập request gửi thẳng tới server (Postman / sửa HTML): warehouse_id = 2 (Kho HCM)
    $error = errorOf(fn () => createVoucher($pdo, $staffA, [
        'type' => 'EXPORT', 'warehouse_id' => 2, 'voucher_date' => date('Y-m-d'), 'reason' => 'Xuất trái phép',
        'client_token' => newToken(),
        'lines' => [['item_id' => $hcmItem, 'batch_id' => $hcmBatch, 'quantity' => '1']],
    ]));
    check('Server từ chối lập phiếu cho kho không được phân quyền', str_contains($error, 'không có quyền'), $error);
    check('Không có chứng từ nào được tạo', (int) scalar('SELECT COUNT(*) FROM vouchers') === $vouchersBefore);

    $pendingHcm = createVoucher($pdo, $manager, [
        'type' => 'EXPORT', 'warehouse_id' => 2, 'voucher_date' => date('Y-m-d'), 'reason' => 'Phiếu kho HCM',
        'client_token' => newToken(),
        'lines' => [['item_id' => $hcmItem, 'batch_id' => $hcmBatch, 'quantity' => '1']],
    ]);
    check('Nhân viên không duyệt được phiếu (kể cả kho của mình)',
        str_contains(errorOf(fn () => approveVoucher($pdo, $staffA, $firstId)), 'Chỉ quản lý'));
    $staffAsManager = ['role' => 'MANAGER'] + $staffA;   // giả sử nhân viên HN được nâng quyền quản lý
    check('Quản lý kho A cũng không duyệt được phiếu kho B',
        str_contains(errorOf(fn () => approveVoucher($pdo, $staffAsManager, $pendingHcm)), 'không có quyền'));
    approveVoucher($pdo, $manager, $pendingHcm);
    check('Nhân viên kho HN không bấm "Đã xuất kho" được phiếu của kho HCM',
        str_contains(errorOf(fn () => shipExport($pdo, $staffA, $pendingHcm, newToken())), 'không phụ trách'));
    $dnLot = $pdo->query('SELECT item_id, batch_id FROM stocks WHERE warehouse_id = 3 AND quantity - reserved >= 2 ORDER BY batch_id LIMIT 1')->fetch();
    $dnTransfer = createVoucher($pdo, $staffC, [
        'type' => 'TRANSFER', 'warehouse_id' => 3, 'to_warehouse_id' => 2, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => (int) $dnLot['item_id'], 'batch_id' => (int) $dnLot['batch_id'], 'quantity' => '2']],
    ]);
    approveVoucher($pdo, $manager, $dnTransfer);
    check('Nhân viên kho HN không gửi hàng được từ kho ĐN', str_contains(errorOf(fn () => sendTransfer($pdo, $staffA, $dnTransfer, newToken())), 'kho đi'));
    sendTransfer($pdo, $staffC, $dnTransfer, newToken());
    check('Nhân viên kho ĐN (kho đi) không tự nhận hàng thay kho HCM',
        str_contains(errorOf(fn () => receiveTransfer($pdo, $staffC, $dnTransfer, lineColumn($dnTransfer, 'shipped_qty'), newToken())), 'kho đến'));

    // -----------------------------------------------------------------
    section('TÌNH HUỐNG 2 + PHÉP THỬ 5 - Điều chuyển → kiểm kê → xác nhận chênh lệch → phiếu điều chỉnh');
    // -----------------------------------------------------------------
    // Bước 1: nhân viên Kho HN lập phiếu điều chuyển 20 sang Kho ĐN; quản lý duyệt; gửi; ĐN nhận đủ
    $moveBatch = (int) scalar('SELECT batch_id FROM stocks s JOIN batches b ON b.id = s.batch_id
                               WHERE s.warehouse_id = 1 AND s.quantity - s.reserved >= 50 AND b.expiry_date > CURDATE() AND s.batch_id <> ? ORDER BY s.batch_id LIMIT 1', [$transferBatch]);
    $moveItem  = (int) scalar('SELECT item_id FROM batches WHERE id = ?', [$moveBatch]);
    $dnBefore  = stockOf(3, $moveBatch);
    $moveId = createVoucher($pdo, $staffA, [
        'type' => 'TRANSFER', 'warehouse_id' => 1, 'to_warehouse_id' => 3, 'voucher_date' => date('Y-m-d'),
        'reason' => 'Tình huống 2 - chuyển hàng ra Đà Nẵng', 'client_token' => newToken(),
        'lines' => [['item_id' => $moveItem, 'batch_id' => $moveBatch, 'quantity' => '20']],
    ]);
    completeTransfer($pdo, $manager, $moveId);
    check('Điều chuyển HN → ĐN 20 (duyệt → gửi → nhận): kho ĐN nhận đủ', stockOf(3, $moveBatch) == $dnBefore + 20 && voucherStatus($moveId) === 'COMPLETED');

    // Bước 2: quản lý mở đợt kiểm kê Kho ĐN -> chụp số tồn
    $dnRows  = (int) scalar('SELECT COUNT(*) FROM stocks WHERE warehouse_id = 3 AND quantity > 0');
    $countId = createStockCount($pdo, $manager, 3, 'Kiểm kê sau khi nhận hàng điều chuyển');
    check('Mở đợt kiểm kê: chụp đủ mọi lô còn hàng của kho', (int) scalar('SELECT COUNT(*) FROM stock_count_lines WHERE count_id = ?', [$countId]) === $dnRows);
    check('Số tồn chụp lại = tồn hiện tại', (float) scalar('SELECT system_qty FROM stock_count_lines WHERE count_id = ? AND batch_id = ?', [$countId, $moveBatch]) == stockOf(3, $moveBatch));
    check('Không mở được đợt thứ 2 cho cùng kho', str_contains(errorOf(fn () => createStockCount($pdo, $manager, 3, '')), 'chưa chốt'));
    check('Nhân viên không được mở đợt kiểm kê', str_contains(errorOf(fn () => createStockCount($pdo, $staffC, 3, '')), 'Chỉ quản lý'));

    // Kho đang kiểm kê: duyệt phiếu nhập vẫn được (chưa đụng tồn) nhưng không nhận hàng vào kho được
    $dnImport = createVoucher($pdo, $staffC, [
        'type' => 'IMPORT', 'warehouse_id' => 3, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => $moveItem, 'batch_id' => $moveBatch, 'quantity' => '1']],
    ]);
    approveVoucher($pdo, $manager, $dnImport);
    check('Đang kiểm kê: không nhận hàng vào kho được (tồn đóng băng)', str_contains(errorOf(fn () => receiveAll($pdo, $staffC, $dnImport)), 'đang kiểm kê'));

    // Bước 3: chưa đếm xong -> không duyệt được
    check('Chưa đếm hết thì không duyệt được', str_contains(errorOf(fn () => approveStockCount($pdo, $manager, $countId, 'test')), 'chưa nhập số đếm'));

    // Nhân viên ĐN đếm: lô vừa nhận chỉ còn 18 (mất 2 trong kho), 1 lô khác dư 1, còn lại khớp
    $lines = $pdo->prepare('SELECT id, batch_id, system_qty FROM stock_count_lines WHERE count_id = ? ORDER BY batch_id');
    $lines->execute([$countId]);
    $lines = $lines->fetchAll();
    $plusLine = array_values(array_filter($lines, fn ($l) => (int) $l['batch_id'] !== $moveBatch))[0];
    $entries = [];
    foreach ($lines as $line) {
        $qty = (float) $line['system_qty'];
        if ((int) $line['batch_id'] === $moveBatch) {
            $qty -= 2;
        } elseif ($line['id'] === $plusLine['id']) {
            $qty += 1;
        }
        $entries[$line['id']] = ['counted_qty' => (string) $qty, 'reason' => ''];
    }
    check('Nhân viên kho khác (HN) không nhập được số đếm kho ĐN',
        str_contains(errorOf(fn () => saveStockCountEntries($pdo, $staffA, $countId, $entries)), 'không có quyền'));
    saveStockCountEntries($pdo, $staffC, $countId, $entries);
    $diffs = $pdo->prepare('SELECT batch_id, difference FROM stock_count_lines WHERE count_id = ? AND difference <> 0 ORDER BY batch_id');
    $diffs->execute([$countId]);
    $diffs = array_column($diffs->fetchAll(), 'difference', 'batch_id');
    check('Hệ thống tính chênh lệch = đếm − tồn hệ thống (−2 và +1)',
        count($diffs) === 2 && (float) $diffs[$moveBatch] == -2.0 && (float) $diffs[$plusLine['batch_id']] == 1.0);

    // Bước 4: dòng lệch chưa có lý do -> không duyệt được; bổ sung lý do rồi duyệt
    check('Dòng lệch chưa có lý do thì không duyệt được', str_contains(errorOf(fn () => approveStockCount($pdo, $manager, $countId, 'Điều chỉnh')), 'chưa có lý do'));
    check('Thiếu lý do điều chỉnh chung thì không duyệt được', str_contains(errorOf(fn () => approveStockCount($pdo, $manager, $countId, '  ')), 'lý do'));
    $entries[array_values(array_filter($lines, fn ($l) => (int) $l['batch_id'] === $moveBatch))[0]['id']]['reason'] = 'Mất 2 khi bốc xếp';
    $entries[$plusLine['id']]['reason'] = 'Hàng dư chưa ghi sổ';
    saveStockCountEntries($pdo, $staffC, $countId, $entries);
    check('Nhân viên không duyệt được đợt kiểm kê', str_contains(errorOf(fn () => approveStockCount($pdo, $staffC, $countId, 'x')), 'Chỉ quản lý'));

    $before       = movementCount();
    $plusBefore   = stockOf(3, (int) $plusLine['batch_id']);
    $adjustmentId = approveStockCount($pdo, $manager, $countId, 'Điều chỉnh tồn theo số đếm thực tế');
    $adjustment   = $pdo->query("SELECT type, status, reason FROM vouchers WHERE id = " . (int) $adjustmentId)->fetch();
    check('Duyệt kiểm kê sinh phiếu ADJUSTMENT đã ghi nhận, có lý do',
        $adjustment && $adjustment['type'] === 'ADJUSTMENT' && $adjustment['status'] === 'APPROVED' && str_contains($adjustment['reason'], 'Điều chỉnh tồn'),
        $adjustment['reason'] ?? '');
    $adjLines = $pdo->prepare('SELECT batch_id, quantity, note FROM voucher_lines WHERE voucher_id = ? ORDER BY batch_id');
    $adjLines->execute([$adjustmentId]);
    $adjLines = $adjLines->fetchAll();
    check('Phiếu điều chỉnh có đúng 2 dòng lệch, mỗi dòng kèm lý do',
        count($adjLines) === 2 && !array_filter($adjLines, fn ($l) => trim((string) $l['note']) === ''));
    check('Ghi sổ đúng 2 dòng phát sinh: −2 (xuất bỏ) và +1 (nhập thêm)',
        movementCount() === $before + 2
        && (float) scalar('SELECT quantity FROM stock_movements WHERE voucher_id = ? AND batch_id = ?', [$adjustmentId, $moveBatch]) == -2.0
        && (float) scalar('SELECT quantity FROM stock_movements WHERE voucher_id = ? AND batch_id = ?', [$adjustmentId, $plusLine['batch_id']]) == 1.0);
    check('Tồn sau điều chỉnh = số đếm thực tế',
        stockOf(3, $moveBatch) == $dnBefore + 18 && stockOf(3, (int) $plusLine['batch_id']) == $plusBefore + 1);
    check('Đợt kiểm kê APPROVED, gắn với phiếu điều chỉnh',
        scalar('SELECT status FROM stock_counts WHERE id = ?', [$countId]) === 'APPROVED'
        && (int) scalar('SELECT adjustment_voucher_id FROM stock_counts WHERE id = ?', [$countId]) === $adjustmentId);
    check('Tổng tồn sau điều chỉnh khớp lịch sử phát sinh', ledgerMismatches() === 0, 'số dòng lệch = ' . ledgerMismatches());

    $before = movementCount();
    check('Duyệt lại đợt đã chốt bị từ chối, không ghi thêm',
        str_contains(errorOf(fn () => approveStockCount($pdo, $manager, $countId, 'lần 2')), 'đã được chốt') && movementCount() === $before);
    check('Không sửa được số đếm sau khi chốt',
        str_contains(errorOf(fn () => $pdo->exec("UPDATE stock_count_lines SET counted_qty = 0 WHERE count_id = $countId")), 'da chot'));
    check('Chốt đợt xong kho mở khóa: nhận hàng vào kho được', errorOf(fn () => receiveAll($pdo, $staffC, $dnImport)) === '' && voucherStatus($dnImport) === 'COMPLETED');

    // Đợt không có chênh lệch: duyệt xong không sinh phiếu, không ghi phát sinh
    $cleanId = createStockCount($pdo, $manager, 2, 'Kiểm kê không lệch');
    $clean = $pdo->prepare('SELECT id, system_qty FROM stock_count_lines WHERE count_id = ?');
    $clean->execute([$cleanId]);
    saveStockCountEntries($pdo, $manager, $cleanId, array_map(fn ($r) => ['counted_qty' => $r['system_qty'], 'reason' => ''], array_column($clean->fetchAll(), null, 'id')));
    $before = movementCount();
    check('Không có chênh lệch: duyệt không sinh phiếu điều chỉnh', approveStockCount($pdo, $manager, $cleanId, 'Khớp') === null && movementCount() === $before);

    // Hủy đợt: không phát sinh, mở khóa kho
    $cancelId = createStockCount($pdo, $manager, 2, 'Sẽ hủy');
    cancelStockCount($pdo, $manager, $cancelId);
    check('Hủy đợt kiểm kê: CANCELLED, kho mở khóa', scalar('SELECT status FROM stock_counts WHERE id = ?', [$cancelId]) === 'CANCELLED'
        && getOpenStockCounts($pdo, [2]) === []);

    $error = errorOf(fn () => $pdo->exec("INSERT INTO vouchers (code, type, warehouse_id, voucher_date, created_by) VALUES ('DC-TEST', 'ADJUSTMENT', 1, CURDATE(), 2)"));
    check('CSDL bắt buộc phiếu điều chỉnh phải có lý do', str_contains($error, 'ck_vouchers_adjust_reason'), $error);

    // -----------------------------------------------------------------
    section('LẬP PHIẾU ĐIỀU CHUYỂN');
    // -----------------------------------------------------------------
    $transferInput = fn (array $override) => $override + [
        'type' => 'TRANSFER', 'warehouse_id' => 1, 'to_warehouse_id' => 2, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => $moveItem, 'batch_id' => $moveBatch, 'quantity' => '1']],
    ];
    check('Kho đến trùng kho đi bị từ chối', str_contains(errorOf(fn () => createVoucher($pdo, $staffA, $transferInput(['to_warehouse_id' => 1]))), 'khác kho đi'));
    check('Không lập được điều chuyển từ kho không phụ trách',
        str_contains(errorOf(fn () => createVoucher($pdo, $staffA, $transferInput(['warehouse_id' => 3, 'to_warehouse_id' => 1]))), 'không có quyền'));
    check('Không chọn được kho ảo "Hàng đang đi đường" làm kho đến',
        str_contains(errorOf(fn () => createVoucher($pdo, $staffA, $transferInput(['to_warehouse_id' => $transit]))), 'ngừng hoạt động'));
    check('Điều chuyển vượt tồn kho đi bị chặn khi lập',
        str_contains(errorOf(fn () => createVoucher($pdo, $staffA, $transferInput(['lines' => [['item_id' => $moveItem, 'batch_id' => $moveBatch, 'quantity' => '999999']]]))), 'không đủ tồn'));
    $pendingTransfer = createVoucher($pdo, $staffA, $transferInput([]));
    check('Nhân viên kho đến (HCM) thấy phiếu chuyển tới trong danh sách', (bool) array_filter(
        getVouchers($pdo, 'TRANSFER', ['keyword' => '', 'status' => 'PENDING', 'warehouse_id' => 0], [2], 50, 0),
        fn ($v) => (int) $v['id'] === $pendingTransfer
    ));
    check('Kho ảo không xuất hiện trong danh sách chọn kho', !array_filter(getWarehouseOptions($pdo, null), fn ($w) => (int) $w['id'] === $transit));

    // -----------------------------------------------------------------
    section('NGHIỆP VỤ PHIẾU NHẬP / XUẤT');
    // -----------------------------------------------------------------
    $newBatch = (int) scalar("SELECT id FROM batches WHERE batch_no = 'L-TEST-NEW'");
    approveVoucher($pdo, $manager, $firstId);
    check('Duyệt phiếu nhập: chỉ là đồng ý nhập, tồn chưa đổi', stockOf(1, $newBatch) == 0.0 && voucherStatus($firstId) === 'APPROVED');
    receiveAll($pdo, $staffA, $firstId);
    check('Nhận hàng lô mới: tồn lô mới = 12.5, phiếu đã nhận đủ', stockOf(1, $newBatch) == 12.5 && voucherStatus($firstId) === 'COMPLETED');
    check('Mã phiếu tự sinh đúng dạng PN-YYYY-NNNN', (bool) preg_match('/^PN-\d{4}-\d{4}$/', (string) scalar('SELECT code FROM vouchers WHERE id = ?', [$firstId])));

    $rejectId = createVoucher($pdo, $manager, [
        'type' => 'EXPORT', 'warehouse_id' => 2, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => $hcmItem, 'batch_id' => $hcmBatch, 'quantity' => '1']],
    ]);
    $before = movementCount();
    rejectVoucher($pdo, $manager, $rejectId);
    check('Từ chối phiếu: trạng thái REJECTED, không ghi phát sinh', voucherStatus($rejectId) === 'REJECTED' && movementCount() === $before);
    check('Phiếu đã từ chối không duyệt lại được', errorOf(fn () => approveVoucher($pdo, $manager, $rejectId)) !== '');

    $error = errorOf(fn () => createVoucher($pdo, $staffA, [
        'type' => 'EXPORT', 'warehouse_id' => 1, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 3, 'batch_id' => $raceBatch, 'quantity' => '50']],
    ]));
    check('Lập phiếu xuất vượt tồn bị chặn ngay khi lập', str_contains($error, 'không đủ tồn'), $error);

    $expired = $pdo->query("SELECT s.item_id, s.batch_id FROM stocks s JOIN batches b ON b.id = s.batch_id WHERE s.warehouse_id = 1 AND s.quantity > 0 AND b.expiry_date < CURDATE() LIMIT 1")->fetch();
    if ($expired) {
        $error = errorOf(fn () => createVoucher($pdo, $staffA, [
            'type' => 'EXPORT', 'warehouse_id' => 1, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
            'lines' => [['item_id' => $expired['item_id'], 'batch_id' => $expired['batch_id'], 'quantity' => '1']],
        ]));
        check('Không cho xuất lô đã hết hạn', str_contains($error, 'hết hạn'), $error);
    }

    $error = errorOf(fn () => createVoucher($pdo, $staffA, [
        'type' => 'IMPORT', 'warehouse_id' => 1, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 1, 'batch_id' => 1, 'quantity' => '1'], ['item_id' => 1, 'batch_id' => 1, 'quantity' => '2']],
    ]));
    check('Phiếu có 2 dòng trùng mặt hàng + lô bị từ chối', str_contains($error, 'trùng'), $error);

    // -----------------------------------------------------------------
    section('KHÓA KHO KHI ĐANG KIỂM KÊ');
    // -----------------------------------------------------------------
    $pendingHn = createVoucher($pdo, $staffA, [
        'type' => 'IMPORT', 'warehouse_id' => 1, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 1, 'batch_id' => 1, 'quantity' => '3']],
    ]);
    $exportHn = createVoucher($pdo, $staffA, [
        'type' => 'EXPORT', 'warehouse_id' => 1, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => $moveItem, 'batch_id' => $moveBatch, 'quantity' => '1']],
    ]);
    $pdo->exec("INSERT INTO stock_counts (code, warehouse_id, status, created_by) VALUES ('KK-TEST', 1, 'COUNTING', 2)");
    $before = movementCount();
    approveVoucher($pdo, $manager, $pendingHn);
    check('Kho đang kiểm kê: chặn nhận hàng', str_contains(errorOf(fn () => receiveAll($pdo, $staffA, $pendingHn)), 'đang kiểm kê'));
    check('Kho đang kiểm kê: chặn duyệt phiếu xuất (không giữ hàng được)', str_contains(errorOf(fn () => approveVoucher($pdo, $manager, $exportHn)), 'đang kiểm kê'));
    check('Không ghi phát sinh khi bị chặn', movementCount() === $before && voucherStatus($exportHn) === 'PENDING' && voucherStatus($pendingHn) === 'APPROVED');
    $pdo->exec("UPDATE stock_counts SET status = 'CANCELLED' WHERE code = 'KK-TEST'");
    check('Hủy đợt kiểm kê xong thì nhận hàng / duyệt xuất được',
        errorOf(fn () => receiveAll($pdo, $staffA, $pendingHn)) === '' && errorOf(fn () => approveVoucher($pdo, $manager, $exportHn)) === '');

    // -----------------------------------------------------------------
    section('(b) PHIẾU NHẬP NHẬN NHIỀU ĐỢT');
    // -----------------------------------------------------------------
    $milk   = importNewBatch($staffA, $manager, 1, 1, 'L-NHIEU-DOT-1', 1);   // tạo sẵn 2 lô để phiếu dùng
    $yogurt = importNewBatch($staffA, $manager, 1, 24, 'L-NHIEU-DOT-2', 1);
    $orderId = createVoucher($pdo, $staffA, [
        'type' => 'IMPORT', 'warehouse_id' => 1, 'voucher_date' => date('Y-m-d'), 'reason' => 'NCC Vinamilk giao nhiều đợt', 'client_token' => newToken(),
        'lines' => [['item_id' => 1, 'batch_id' => $milk, 'quantity' => '100'], ['item_id' => 24, 'batch_id' => $yogurt, 'quantity' => '48']],
    ]);
    [$milkLine, $yogurtLine] = array_keys(lineColumn($orderId, 'quantity'));
    approveVoucher($pdo, $manager, $orderId);
    check('Duyệt phiếu nhập không cộng tồn', stockOf(1, $milk) == 1.0 && voucherStatus($orderId) === 'APPROVED');
    check('Có hàng hỏng mà không ghi lý do: bị chặn',
        str_contains(errorOf(fn () => receiveImport($pdo, $staffA, $orderId, [$milkLine => ['good' => '60', 'damaged' => '2']], '', newToken())), 'lý do'));
    check('Nhận quá số trên phiếu: bị chặn',
        str_contains(errorOf(fn () => receiveImport($pdo, $staffA, $orderId, [$milkLine => ['good' => '101']], '', newToken())), 'không nhận quá'));
    check('Nhân viên kho khác không nhận hàng được',
        str_contains(errorOf(fn () => receiveImport($pdo, $staffB, $orderId, [$milkLine => ['good' => '1']], '', newToken())), 'không phụ trách'));
    $token  = newToken();
    $before = movementCount();
    receiveImport($pdo, $staffA, $orderId, [$milkLine => ['good' => '60', 'damaged' => '2'], $yogurtLine => ['good' => '48', 'damaged' => '']], '2 hộp móp, trả NCC', $token);
    check('Đợt 1: chỉ số nhận tốt vào tồn (sữa +60, sữa chua +48), hỏng 2 chỉ ghi lại',
        stockOf(1, $milk) == 61.0 && stockOf(1, $yogurt) == 49.0
        && (float) scalar('SELECT damaged_qty FROM voucher_lines WHERE id = ?', [$milkLine]) == 2.0 && movementCount() === $before + 2);
    check('Đợt 1: phiếu "Đã nhận một phần", còn chờ 40', voucherStatus($orderId) === 'PARTIAL'
        && (float) scalar('SELECT quantity - received_qty FROM voucher_lines WHERE id = ?', [$milkLine]) == 40.0);
    check('Gửi lại form đợt 1 (cùng mã) không ghi đúp',
        str_contains(errorOf(fn () => receiveImport($pdo, $staffA, $orderId, [$milkLine => ['good' => '10']], '', $token)), 'đã được ghi nhận') && stockOf(1, $milk) == 61.0);
    receiveImport($pdo, $staffA, $orderId, [$milkLine => ['good' => '40']], '', newToken());
    check('Đợt 2 nhận nốt 40: phiếu "Đã nhận đủ", có 2 đợt ghi sổ',
        voucherStatus($orderId) === 'COMPLETED' && stockOf(1, $milk) == 101.0
        && (int) scalar("SELECT COUNT(*) FROM voucher_postings WHERE voucher_id = ? AND kind = 'RECEIVE'", [$orderId]) === 2);
    check('Phiếu đã nhận đủ không nhận thêm / không đóng được',
        errorOf(fn () => receiveImport($pdo, $staffA, $orderId, [$milkLine => ['good' => '1']], '', newToken())) !== ''
        && errorOf(fn () => closeImport($pdo, $manager, $orderId, 'x')) !== '');

    $shortId = createVoucher($pdo, $staffA, [
        'type' => 'IMPORT', 'warehouse_id' => 1, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 1, 'batch_id' => $milk, 'quantity' => '30']],
    ]);
    approveVoucher($pdo, $manager, $shortId);
    receiveImport($pdo, $staffA, $shortId, [array_key_first(lineColumn($shortId, 'quantity')) => ['good' => '10']], '', newToken());
    check('Nhân viên không đóng phiếu thiếu được', str_contains(errorOf(fn () => closeImport($pdo, $staffA, $shortId, 'NCC hết hàng')), 'Chỉ quản lý'));
    check('Đóng phiếu phải có lý do', str_contains(errorOf(fn () => closeImport($pdo, $manager, $shortId, ' ')), 'lý do'));
    closeImport($pdo, $manager, $shortId, 'NCC báo hết hàng, không giao 20 còn lại');
    check('Đóng phiếu thiếu hàng: CLOSED, tồn giữ đúng số đã nhận (10)', voucherStatus($shortId) === 'CLOSED' && stockOf(1, $milk) == 111.0);

    // -----------------------------------------------------------------
    section('(c) PHIẾU XUẤT GIỮ CHỖ');
    // -----------------------------------------------------------------
    $lavie  = importNewBatch($staffA, $manager, 1, 13, 'L-GIU-CHO', 50);
    $export = fn (string $qty) => createVoucher($pdo, $staffA, [
        'type' => 'EXPORT', 'warehouse_id' => 1, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 13, 'batch_id' => $lavie, 'quantity' => $qty]],
    ]);
    [$exportA, $exportB] = [$export('30'), $export('30')];
    $before = movementCount();
    approveVoucher($pdo, $manager, $exportA);
    check('Duyệt phiếu A 30: giữ 30, tồn thực vẫn 50, không ghi sổ', reservedOf(1, $lavie) == 30.0 && stockOf(1, $lavie) == 50.0 && movementCount() === $before);
    $error = errorOf(fn () => approveVoucher($pdo, $manager, $exportB));
    check('Duyệt phiếu B 30: bị chặn ngay lúc duyệt (chỉ còn 20 có thể xuất)', str_contains($error, 'Không đủ tồn có thể xuất') && voucherStatus($exportB) === 'PENDING', $error);
    check('Lập phiếu mới 25 cũng bị chặn (có thể xuất 20)', str_contains(errorOf(fn () => $export('25')), 'không đủ tồn có thể xuất'));
    check('Form lập phiếu chỉ gợi ý số có thể xuất (20)', (float) array_values(array_filter(getStockOptions($pdo, [1]), fn ($s) => (int) $s['batch_id'] === $lavie))[0]['quantity'] == 20.0);
    $error = errorOf(fn () => $pdo->exec("UPDATE stocks SET reserved = quantity + 1 WHERE warehouse_id = 1 AND batch_id = $lavie"));
    check('CSDL chặn giữ chỗ vượt tồn thực (CHECK reserved <= quantity)', str_contains($error, 'ck_stocks_reserved'), $error);
    shipExport($pdo, $staffA, $exportA, newToken());
    check('Đã xuất kho phiếu A: tồn 20, hết giữ chỗ, ghi 1 dòng sổ, phiếu hoàn tất',
        stockOf(1, $lavie) == 20.0 && reservedOf(1, $lavie) == 0.0 && movementCount() === $before + 1 && voucherStatus($exportA) === 'COMPLETED');
    $exportD = $export('10');
    approveVoucher($pdo, $manager, $exportD);
    check('Nhân viên không hủy giữ chỗ được', str_contains(errorOf(fn () => cancelReservation($pdo, $staffA, $exportD, 'Khách hủy')), 'Chỉ quản lý'));
    cancelReservation($pdo, $manager, $exportD, 'Khách hủy đơn');
    check('Hủy giữ chỗ: hàng trở lại có thể xuất, phiếu CANCELLED, không ghi sổ',
        reservedOf(1, $lavie) == 0.0 && voucherStatus($exportD) === 'CANCELLED' && movementCount() === $before + 1);
    check('Phiếu đã hủy không xuất kho được', str_contains(errorOf(fn () => shipExport($pdo, $staffA, $exportD, newToken())), 'Không thể xuất kho'));

    // -----------------------------------------------------------------
    section('(a) ĐIỀU CHUYỂN QUA KHO TRUNG CHUYỂN: NHẬN THIẾU, CHỜ GIAO BỔ SUNG, GHI HAO HỤT');
    // -----------------------------------------------------------------
    $rice   = importNewBatch($staffA, $manager, 1, 4, 'L-DI-DUONG', 30);
    $roadId = createVoucher($pdo, $staffA, [
        'type' => 'TRANSFER', 'warehouse_id' => 1, 'to_warehouse_id' => 2, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 4, 'batch_id' => $rice, 'quantity' => '10']],
    ]);
    $roadLine = (int) array_key_first(lineColumn($roadId, 'quantity'));
    $totalOf  = fn () => stockOf(1, $rice) + stockOf(2, $rice) + stockOf($transit, $rice);
    approveVoucher($pdo, $manager, $roadId);
    sendTransfer($pdo, $staffA, $roadId, newToken());
    receiveTransfer($pdo, $staffB, $roadId, [$roadLine => '7'], newToken());
    check('Kho đến nhận 7/10: phiếu "Nhận thiếu", 3 vẫn nằm ở "Đang đi đường" (không mất dấu)',
        voucherStatus($roadId) === 'DISCREPANCY' && stockOf(2, $rice) == 7.0 && stockOf($transit, $rice) == 3.0 && $totalOf() == 30.0);
    check('Không nhận quá số đang trên đường', str_contains(errorOf(fn () => receiveTransfer($pdo, $staffB, $roadId, [$roadLine => '4'], newToken())), 'đang trên đường'));
    check('Nhân viên không xử lý chênh lệch được', str_contains(errorOf(fn () => resolveTransfer($pdo, $staffB, $roadId, 'lost', 'x')), 'Chỉ quản lý'));
    resolveTransfer($pdo, $manager, $roadId, 'wait', '');
    check('Chờ giao bổ sung: phiếu quay lại "Đang đi đường"', voucherStatus($roadId) === 'IN_TRANSIT');
    receiveTransfer($pdo, $staffB, $roadId, [$roadLine => '1'], newToken());
    check('Nhận bổ sung 1: còn 2 trên đường, lại "Nhận thiếu"', voucherStatus($roadId) === 'DISCREPANCY' && stockOf($transit, $rice) == 2.0);
    check('Ghi hao hụt phải có diễn giải', str_contains(errorOf(fn () => resolveTransfer($pdo, $manager, $roadId, 'lost', '')), 'diễn giải'));
    $lossId = resolveTransfer($pdo, $manager, $roadId, 'lost', 'Biên bản nhà xe số 12');
    $loss   = $pdo->query('SELECT type, status, warehouse_id, reference_voucher_id, reason FROM vouchers WHERE id = ' . (int) $lossId)->fetch();
    check('Ghi hao hụt: lập phiếu điều chỉnh đã ghi sổ ở kho trung chuyển, tham chiếu phiếu điều chuyển, có lý do',
        $loss['type'] === 'ADJUSTMENT' && $loss['status'] === 'APPROVED' && (int) $loss['warehouse_id'] === $transit
        && (int) $loss['reference_voucher_id'] === $roadId && str_contains($loss['reason'], 'Thất lạc'));
    check('Sau xử lý: "Đang đi đường" về 0, phiếu hoàn tất, hao hụt 2 ghi trên dòng phiếu',
        stockOf($transit, $rice) == 0.0 && voucherStatus($roadId) === 'COMPLETED' && (float) scalar('SELECT lost_qty FROM voucher_lines WHERE id = ?', [$roadLine]) == 2.0
        && $totalOf() == 28.0);
    check('Phiếu đã hoàn tất không xử lý / nhận thêm được', errorOf(fn () => resolveTransfer($pdo, $manager, $roadId, 'lost', 'x')) !== '');
    $holdId = createVoucher($pdo, $staffA, ['type' => 'TRANSFER', 'warehouse_id' => 1, 'to_warehouse_id' => 3, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 4, 'batch_id' => $rice, 'quantity' => '5']]]);
    approveVoucher($pdo, $manager, $holdId);
    $held = reservedOf(1, $rice);
    cancelReservation($pdo, $manager, $holdId, 'Kho đến không cần nữa');
    check('Hủy giữ chỗ phiếu điều chuyển chưa gửi', $held == 5.0 && reservedOf(1, $rice) == 0.0 && voucherStatus($holdId) === 'CANCELLED');
    check('Cảnh báo điều chuyển quá hạn: phiếu mẫu đi đường từ 21/10 được phát hiện',
        (bool) array_filter(getOverdueTransfers($pdo, [1, 2, 3]), fn ($t) => $t['code'] === 'PC-2026-0019'));
    $error = errorOf(fn () => $pdo->exec("UPDATE vouchers SET status = 'IN_TRANSIT' WHERE id = $exportB"));
    check('CSDL chặn bước chuyển trạng thái sai (phiếu xuất sang "Đang đi đường")', str_contains($error, 'khong hop le'), $error);
    $error = errorOf(fn () => $pdo->exec("UPDATE vouchers SET status = 'PENDING' WHERE id = $roadId"));
    check('CSDL chặn sửa phiếu đã kết thúc', str_contains($error, 'da ket thuc'), $error);

    // -----------------------------------------------------------------
    section('YÊU CẦU ĐIỀU CHUYỂN');
    // -----------------------------------------------------------------
    $reqItem = createItem($pdo, $manager, ['sku' => 'SP980', 'name' => 'Hàng test yêu cầu điều chuyển', 'unit' => 'Thùng']);
    $soon    = importNewBatch($staffA, $manager, 1, $reqItem, 'L-YC-SOM', 8, '2027-01-01');
    $late    = importNewBatch($staffA, $manager, 1, $reqItem, 'L-YC-MUON', 20, '2028-01-01');
    $request = fn (array $override) => $override + [
        'to_warehouse_id' => 2, 'from_warehouse_id' => 1, 'needed_by' => date('Y-m-d', strtotime('+3 days')),
        'reason' => 'Khách Minh Anh đặt 12 thùng', 'client_token' => newToken(),
        'lines' => [['item_id' => $reqItem, 'quantity' => '12']],
    ];
    check('Không lập yêu cầu cho kho mình không phụ trách', str_contains(errorOf(fn () => createTransferRequest($pdo, $staffB, $request(['to_warehouse_id' => 1, 'from_warehouse_id' => 2]))), 'kho mình phụ trách'));
    check('Yêu cầu phải có lý do', str_contains(errorOf(fn () => createTransferRequest($pdo, $staffB, $request(['reason' => '']))), 'lý do'));
    $form      = $request([]);
    $requestId = createTransferRequest($pdo, $staffB, $form);
    check('Nhân viên HCM lập yêu cầu xin hàng kho HN; gửi form 2 lần không tạo trùng',
        createTransferRequest($pdo, $staffB, $form) === $requestId && scalar('SELECT status FROM transfer_requests WHERE id = ?', [$requestId]) === 'PENDING');
    check('Ai cũng xem được "có thể xuất" ở các kho (con số tổng)', (float) (getNetworkAvailability($pdo)[$reqItem][1] ?? 0) == 28.0);
    check('Nhân viên kho HN không tự đồng ý được (chỉ quản lý)', str_contains(errorOf(fn () => acceptTransferRequest($pdo, $staffA, $requestId, '')), 'Chỉ quản lý'));
    check('Bên yêu cầu (HCM) không tự đồng ý thay kho HN', str_contains(errorOf(fn () => acceptTransferRequest($pdo, ['role' => 'MANAGER'] + $staffB, $requestId, '')), 'không có quyền'));
    $voucherId = acceptTransferRequest($pdo, $manager, $requestId, 'Gửi chuyến chiều');
    $alloc = $pdo->prepare('SELECT batch_id, quantity FROM voucher_lines WHERE voucher_id = ? ORDER BY batch_id');
    $alloc->execute([$voucherId]);
    check('Đồng ý: lập phiếu điều chuyển HN → HCM, chọn lô hết hạn trước (8 lô sớm + 4 lô muộn)',
        array_map(fn ($r) => [(int) $r['batch_id'], (float) $r['quantity']], $alloc->fetchAll()) == [[$soon, 8.0], [$late, 4.0]]);
    check('Phiếu sinh ra đã duyệt và GIỮ hàng ngay; yêu cầu ACCEPTED gắn phiếu',
        voucherStatus($voucherId) === 'APPROVED' && reservedOf(1, $soon) == 8.0 && reservedOf(1, $late) == 4.0
        && (int) scalar('SELECT transfer_voucher_id FROM transfer_requests WHERE id = ?', [$requestId]) === $voucherId);
    check('Đồng ý lần 2 bị từ chối', str_contains(errorOf(fn () => acceptTransferRequest($pdo, $manager, $requestId, '')), 'đã được xử lý'));
    sendTransfer($pdo, $staffA, $voucherId, newToken());
    receiveTransfer($pdo, $staffB, $voucherId, lineColumn($voucherId, 'shipped_qty'), newToken());
    check('Gửi → nhận: kho HCM có đủ 12 cho khách', stockOf(2, $soon) + stockOf(2, $late) == 12.0 && voucherStatus($voucherId) === 'COMPLETED');

    $vouchersBefore = (int) scalar('SELECT COUNT(*) FROM vouchers');
    $bigId = createTransferRequest($pdo, $staffB, $request(['lines' => [['item_id' => $reqItem, 'quantity' => '100']]]));
    $error = errorOf(fn () => acceptTransferRequest($pdo, $manager, $bigId, ''));
    check('Kho không đủ hàng: đồng ý bị chặn, báo thiếu bao nhiêu, không tạo phiếu nào',
        str_contains($error, 'thiếu 84') && (int) scalar('SELECT COUNT(*) FROM vouchers') === $vouchersBefore, $error);
    check('Từ chối phải có lý do', str_contains(errorOf(fn () => declineTransferRequest($pdo, $manager, $bigId, '')), 'lý do'));
    declineTransferRequest($pdo, $manager, $bigId, 'Kho HN chỉ còn 16');
    check('Từ chối: DECLINED kèm lý do', scalar('SELECT status FROM transfer_requests WHERE id = ?', [$bigId]) === 'DECLINED');
    $withdrawId = createTransferRequest($pdo, $staffB, $request([]));
    check('Nhân viên kho khác không rút lại được yêu cầu', str_contains(errorOf(fn () => cancelTransferRequest($pdo, $staffC, $withdrawId)), 'kho cần hàng'));
    cancelTransferRequest($pdo, $staffB, $withdrawId);
    check('Bên yêu cầu rút lại: CANCELLED, không đồng ý được nữa',
        scalar('SELECT status FROM transfer_requests WHERE id = ?', [$withdrawId]) === 'CANCELLED'
        && str_contains(errorOf(fn () => acceptTransferRequest($pdo, $manager, $withdrawId, '')), 'đã được xử lý'));

    // -----------------------------------------------------------------
    section('TRA CỨU & BÁO CÁO');
    // -----------------------------------------------------------------
    $closing = [];
    foreach (getMovementSummary($pdo, ['keyword' => '', 'warehouse_id' => 0, 'from' => date('Y-m-01'), 'to' => date('Y-m-d')], [1, 2, 3], ['sku', 'asc']) as $row) {
        if (toMilli($row['closing']) !== 0) {
            $closing[(int) $row['item_id']] = (float) $row['closing'];
        }
    }
    $current = [];
    foreach ($pdo->query('SELECT item_id, SUM(quantity) AS qty FROM stocks WHERE warehouse_id IN (1, 2, 3) GROUP BY item_id HAVING qty <> 0') as $row) {
        $current[(int) $row['item_id']] = (float) $row['qty'];
    }
    ksort($closing);
    ksort($current);
    check('Nhập – xuất – tồn: tồn cuối kỳ (tính từ sổ) = tồn hiện tại của từng mặt hàng', $closing == $current);
    $row = getMovementSummary($pdo, ['keyword' => 'SP980', 'warehouse_id' => 1, 'from' => date('Y-m-d'), 'to' => date('Y-m-d')], [1, 2, 3], ['sku', 'asc'])[0] ?? null;
    check('Nhập – xuất – tồn kho HN của SP980 hôm nay: nhập 28, xuất 12, tồn cuối = tồn đầu + nhập − xuất',
        $row && (float) $row['qty_in'] == 28.0 && (float) $row['qty_out'] == 12.0 && (float) $row['closing'] == (float) $row['opening'] + 28 - 12);
    $card = getLedgerEntries($pdo, ['keyword' => 'L-YC-SOM', 'warehouse_id' => 1, 'type' => '', 'from' => '2000-01-01', 'to' => date('Y-m-d')], [1, 2, 3], 50, 0);
    check('Thẻ kho lô L-YC-SOM ở HN: nhập 8 rồi gửi đi 8, số dư sau từng phát sinh 8 → 0',
        count($card) === 2 && (float) $card[0]['balance_after'] == 0.0 && (float) $card[1]['balance_after'] == 8.0);
    check('Tồn kho hiện tại: nhân viên HN chỉ thấy kho HN',
        !array_filter(getStockReport($pdo, ['keyword' => ''], $staffA['warehouses'], 1000, 0, ['warehouse', 'asc']), fn ($r) => (int) $r['warehouse_id'] !== 1));
    $recon = getReconciliationReport($pdo, [1, 2, 3]);
    check('Đối chiếu sổ: cả 4 phép kiểm tra đều đạt', !array_filter($recon, fn ($r) => $r['bad']),
        implode(', ', array_map(fn ($k, $r) => "$k: " . count($r['bad']), array_keys($recon), $recon)));

    // -----------------------------------------------------------------
    section('DANH MỤC MẶT HÀNG, KHO VÀ NHẬP FILE');
    // -----------------------------------------------------------------
    check('Nhân viên không thêm được mặt hàng',
        str_contains(errorOf(fn () => createItem($pdo, $staffA, ['sku' => 'SP900', 'name' => 'X', 'unit' => 'Hộp'])), 'Chỉ quản lý'));
    $newItem = createItem($pdo, $manager, ['sku' => 'sp900', 'name' => 'Nước cam test', 'unit' => 'Chai']);
    check('Thêm mặt hàng: mã được viết hoa', scalar('SELECT sku FROM items WHERE id = ?', [$newItem]) === 'SP900');
    check('Trùng mã hàng bị từ chối', str_contains(errorOf(fn () => createItem($pdo, $manager, ['sku' => 'SP900', 'name' => 'Y', 'unit' => 'Chai'])), 'đã tồn tại'));
    check('Mã hàng có dấu cách / ký tự lạ bị từ chối', str_contains(errorOf(fn () => createItem($pdo, $manager, ['sku' => 'SP 9', 'name' => 'Y', 'unit' => 'Chai'])), 'Mã hàng chỉ gồm'));
    check('Sửa sang mã đã thuộc mặt hàng khác bị từ chối',
        str_contains(errorOf(fn () => updateItem($pdo, $manager, $newItem, ['sku' => 'SP001', 'name' => 'Y', 'unit' => 'Chai'])), 'SP001 đã tồn tại'));
    check('Xóa mặt hàng chưa có lịch sử: xóa thật', removeItem($pdo, $manager, $newItem) === 'deleted' && getItem($pdo, $newItem) === null);
    check('Không xóa được mặt hàng còn tồn', str_contains(errorOf(fn () => removeItem($pdo, $manager, 1)), 'còn tồn'));

    $usedItem = createItem($pdo, $manager, ['sku' => 'SP901', 'name' => 'Hàng có lô, hết tồn', 'unit' => 'Gói']);
    $pdo->prepare("INSERT INTO batches (item_id, batch_no, expiry_date) VALUES (?, 'L-OLD', '2030-01-01')")->execute([$usedItem]);
    check('Mặt hàng đã có lịch sử (lô): chuyển ngừng dùng thay vì xóa',
        removeItem($pdo, $manager, $usedItem) === 'deactivated' && (int) scalar('SELECT is_active FROM items WHERE id = ?', [$usedItem]) === 0);
    $usedBatch = (int) scalar("SELECT id FROM batches WHERE batch_no = 'L-OLD'");
    check('Mặt hàng ngừng dùng không lập phiếu được', str_contains(errorOf(fn () => createVoucher($pdo, $staffA, [
        'type' => 'IMPORT', 'warehouse_id' => 1, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => $usedItem, 'batch_id' => $usedBatch, 'quantity' => '1']],
    ])), 'không tồn tại'));
    restoreItem($pdo, $manager, $usedItem);
    check('Dùng lại mặt hàng', (int) scalar('SELECT is_active FROM items WHERE id = ?', [$usedItem]) === 1);

    // Đọc CSV kiểu Excel: có BOM, dấu chấm phẩy, tiêu đề tiếng Việt, dòng trống
    $csv  = "\xEF\xBB\xBFMã hàng;Tên mặt hàng;Đơn vị tính;Mô tả\nsp950;Bánh test;Hộp;\n\n SP001 ;Sữa tươi Vinamilk 1L (mới);Hộp;cập nhật\n";
    $rows = parseCsv($csv, ITEM_CSV_COLUMNS, ['sku', 'name', 'unit']);
    check('Đọc CSV: BOM, dấu chấm phẩy, bỏ dòng trống, giữ số dòng gốc', array_keys($rows) === [2, 4] && $rows[4]['sku'] === 'SP001' && $rows[2]['name'] === 'Bánh test');
    $error = errorOf(fn () => parseCsv("Mã hàng,Tên mặt hàng\nA,B\n", ITEM_CSV_COLUMNS, ['sku', 'name', 'unit']));
    check('CSV thiếu cột bắt buộc bị từ chối', str_contains($error, 'Thiếu cột "đơn vị tính"'), $error);
    check('CSV không phải UTF-8 bị từ chối', str_contains(errorOf(fn () => parseCsv("M\xE3 h\xE0ng\n", ITEM_CSV_COLUMNS, ['sku'])), 'UTF-8'));

    $itemsBefore = (int) scalar('SELECT COUNT(*) FROM items');
    $badRows = $rows + [5 => ['sku' => 'SP951', 'name' => '', 'unit' => 'Hộp', 'description' => '']];
    $error = errorOf(fn () => importItems($pdo, $manager, $badRows));
    check('Nhập file có 1 dòng lỗi: báo đúng dòng, không ghi dòng nào',
        str_contains($error, 'Dòng 5') && (int) scalar('SELECT COUNT(*) FROM items') === $itemsBefore, $error);
    [$created, $updated] = importItems($pdo, $manager, $rows);
    check('Nhập file hợp lệ: 1 thêm mới, 1 cập nhật', $created === 1 && $updated === 1
        && scalar("SELECT name FROM items WHERE sku = 'SP001'") === 'Sữa tươi Vinamilk 1L (mới)');

    $dn = $pdo->query('SELECT code, name FROM warehouses WHERE id = 3')->fetch();
    check('Quản lý kho không tạo được kho mới', str_contains(errorOf(fn () => createWarehouse($pdo, $manager, ['code' => 'KHO-CT', 'name' => 'Kho Cần Thơ'])), 'Chỉ quản trị'));
    check('Quản lý kho không xóa / mở lại được kho', str_contains(errorOf(fn () => removeWarehouse($pdo, $manager, 3)), 'Chỉ quản trị')
        && str_contains(errorOf(fn () => restoreWarehouse($pdo, $manager, 3)), 'Chỉ quản trị'));
    check('Quản lý kho vẫn sửa được thông tin kho mình được giao',
        errorOf(fn () => updateWarehouse($pdo, $manager, 3, ['code' => $dn['code'], 'name' => $dn['name'], 'address' => 'Địa chỉ mới'])) === ''
        && scalar('SELECT address FROM warehouses WHERE id = 3') === 'Địa chỉ mới');

    $warehouseId = createWarehouse($pdo, $admin, ['code' => 'kho-ct', 'name' => 'Kho Cần Thơ', 'address' => '']);
    check('Quản trị thêm kho: quản trị có quyền ngay, quản lý được giao sau ở trang Phân quyền',
        (int) scalar('SELECT COUNT(*) FROM user_warehouses WHERE warehouse_id = ? AND user_id = 1', [$warehouseId]) === 1
        && (int) scalar('SELECT COUNT(*) FROM user_warehouses WHERE warehouse_id = ? AND user_id = 2', [$warehouseId]) === 0);
    check('Trùng mã kho bị từ chối', str_contains(errorOf(fn () => createWarehouse($pdo, $admin, ['code' => 'KHO-CT', 'name' => 'X'])), 'đã tồn tại'));
    $admin = loadUser($pdo, 1);                         // nạp lại quyền kho sau khi được cấp kho mới
    check('Không xóa được kho còn hàng', str_contains(errorOf(fn () => removeWarehouse($pdo, $admin, 2)), 'còn hàng'));
    check('Nhân viên kho HN không sửa được kho HCM',
        str_contains(errorOf(fn () => updateWarehouse($pdo, ['role' => 'MANAGER'] + $staffA, 2, ['code' => 'KHO-HCM', 'name' => 'X'])), 'không có quyền'));
    check('Xóa kho mới chưa có lịch sử: xóa thật', removeWarehouse($pdo, $admin, $warehouseId) === 'deleted' && getWarehouse($pdo, $warehouseId) === null);

    $hpCsv = "Mã kho,Tên kho,Địa chỉ\nKHO-HP,Kho Hải Phòng,Lê Hồng Phong\n";
    $error = errorOf(fn () => importWarehouses($pdo, $manager, parseCsv($hpCsv, WAREHOUSE_CSV_COLUMNS, ['code', 'name'])));
    check('Quản lý nhập file có mã kho mới: bị từ chối, không ghi gì',
        str_contains($error, 'chỉ quản trị') && (int) scalar("SELECT COUNT(*) FROM warehouses WHERE code = 'KHO-HP'") === 0, $error);
    [$created] = importWarehouses($pdo, $admin, parseCsv($hpCsv, WAREHOUSE_CSV_COLUMNS, ['code', 'name']));
    $hpId = (int) scalar("SELECT id FROM warehouses WHERE code = 'KHO-HP'");
    check('Quản trị nhập kho từ file: thêm kho và phân quyền', $created === 1 && (int) scalar('SELECT COUNT(*) FROM user_warehouses WHERE warehouse_id = ? AND user_id = 1', [$hpId]) === 1);
    $admin = loadUser($pdo, 1);
    $rejected = createVoucher($pdo, $admin, [
        'type' => 'IMPORT', 'warehouse_id' => $hpId, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 1, 'batch_id' => 1, 'quantity' => '1']],
    ]);
    check('Không xóa được kho còn phiếu chờ duyệt', str_contains(errorOf(fn () => removeWarehouse($pdo, $admin, $hpId)), 'chờ duyệt'));
    rejectVoucher($pdo, $admin, $rejected);
    check('Kho đã có chứng từ (dù trống): chuyển ngừng hoạt động thay vì xóa', removeWarehouse($pdo, $admin, $hpId) === 'deactivated');
    check('Kho ngừng hoạt động không lập phiếu được', str_contains(errorOf(fn () => createVoucher($pdo, $admin, [
        'type' => 'IMPORT', 'warehouse_id' => $hpId, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 1, 'batch_id' => 1, 'quantity' => '1']],
    ])), 'ngừng hoạt động'));

    // -----------------------------------------------------------------
    section('CHỐNG TRÙNG MÃ / TÊN DANH MỤC');
    // -----------------------------------------------------------------
    foreach (['SP-001', 'SP0001', 'sp.01'] as $nearCode) {
        check("Mã $nearCode gần giống SP001 bị từ chối", str_contains(errorOf(fn () => createItem($pdo, $manager, ['sku' => $nearCode, 'name' => 'Hàng mới', 'unit' => 'Hộp'])), 'gần giống mã SP001'));
    }
    $sp2Name = (string) scalar("SELECT name FROM items WHERE sku = 'SP002'");
    $error   = errorOf(fn () => createItem($pdo, $manager, ['sku' => 'SP960', 'name' => '  ' . mb_strtoupper($sp2Name) . '  ', 'unit' => 'Gói']));
    check('Cùng tên với mặt hàng khác (khác hoa / thường, thừa khoảng trắng) bị từ chối', str_contains($error, 'đã có ở mặt hàng mã SP002'), $error);
    $fish = createItem($pdo, $manager, ['sku' => 'SP961', 'name' => 'Cá hộp test', 'unit' => 'Hộp']);
    $eggplant = createItem($pdo, $manager, ['sku' => 'SP962', 'name' => 'Cà   hộp test', 'unit' => 'Hộp']);
    check('Tên chỉ khác dấu là 2 mặt hàng khác nhau; khoảng trắng thừa được gộp khi lưu',
        $fish > 0 && $eggplant > 0 && scalar('SELECT name FROM items WHERE id = ?', [$eggplant]) === 'Cà hộp test');
    removeItem($pdo, $manager, $usedItem);
    $error = errorOf(fn () => createItem($pdo, $manager, ['sku' => 'SP963', 'name' => 'Hàng có lô, hết tồn', 'unit' => 'Gói']));
    check('Trùng tên mặt hàng đang ngừng dùng: hướng dẫn bấm "Dùng lại" thay vì tạo mới', str_contains($error, 'Dùng lại'), $error);
    $error = errorOf(fn () => $pdo->exec("INSERT INTO items (sku, name, unit) VALUES ('ZZ-1', '" . mb_strtoupper($sp2Name) . "', 'Gói')"));
    check('CSDL tự chặn tên trùng kể cả khi ghi thẳng bằng SQL (UNIQUE name_key)', str_contains($error, 'uq_items_name'), $error);
    $error = errorOf(fn () => importItems($pdo, $manager, [2 => ['sku' => 'SP970', 'name' => 'A test', 'unit' => 'Hộp'], 3 => ['sku' => 'SP-970', 'name' => 'B test', 'unit' => 'Hộp']]));
    check('Nhập file: 2 mã gần giống nhau trong cùng file bị từ chối', str_contains($error, 'Dòng 3') && str_contains($error, 'gần giống'), $error);
    $error = errorOf(fn () => createWarehouse($pdo, $admin, ['code' => 'khohn', 'name' => 'Kho test']));
    check('Mã kho KHOHN gần giống KHO-HN bị từ chối', str_contains($error, 'gần giống mã KHO-HN'), $error);
    $error = errorOf(fn () => createWarehouse($pdo, $admin, ['code' => 'KHO-X', 'name' => 'kho hà nội']));
    check('Tên kho trùng bị từ chối', str_contains($error, 'đã có ở kho mã KHO-HN'), $error);
    $duplicate = new PDOException('dup');
    $duplicate->errorInfo = ['23000', 1062, 'Duplicate entry'];
    check('Lỗi UNIQUE (2 người tạo cùng lúc) báo dễ hiểu', str_contains(dbErrorMessage($duplicate), 'Dữ liệu bị trùng'));
    check('Gợi ý mã hàng tiếp theo', preg_match('/^SP\d{3}$/', nextCatalogCode($pdo, 'items', 'SP')) === 1);

    // -----------------------------------------------------------------
    section('NHẬP / XUẤT PHIẾU BẰNG FILE EXCEL (CSV)');
    // -----------------------------------------------------------------
    $staffA   = loadUser($pdo, 3);
    $exportOk = "Số phiếu,Ngày lập,Mã kho,Lý do,Mã hàng,Số lô,Số lượng\n"
              . "A,27/10/2026,KHO-HN,Bán lẻ,SP001,L2608-01,2\nA,27/10/2026,KHO-HN,Bán lẻ,SP003,L2606-03,1\nB,,KHO-HN,Bán sỉ,SP001,L2608-01,\"1,5\"\n";
    $vouchersBefore = (int) scalar('SELECT COUNT(*) FROM vouchers');
    $rows = parseCsv($exportOk, VOUCHER_CSV_COLUMNS, VOUCHER_CSV_REQUIRED);
    [$created, $skipped, $codes] = importVouchers($pdo, $staffA, 'EXPORT', $rows, md5($exportOk));
    check('Nhập phiếu xuất từ file: gộp dòng theo Số phiếu, phiếu ở trạng thái Chờ duyệt',
        $created === 2 && (int) scalar("SELECT COUNT(*) FROM vouchers v JOIN voucher_lines vl ON vl.voucher_id = v.id WHERE v.code = ? AND v.status = 'PENDING'", [$codes[0]]) === 2);
    check('Nhập phiếu: số lượng "1,5" đọc thành 1.5, ngày trống = hôm nay',
        (float) scalar('SELECT vl.quantity FROM vouchers v JOIN voucher_lines vl ON vl.voucher_id = v.id WHERE v.code = ?', [$codes[1]]) === 1.5
        && scalar('SELECT voucher_date FROM vouchers WHERE code = ?', [$codes[1]]) === date('Y-m-d'));
    [$created, $skipped] = importVouchers($pdo, $staffA, 'EXPORT', $rows, md5($exportOk));
    check('Nhập lại cùng file không tạo trùng phiếu', $created === 0 && $skipped === 2 && (int) scalar('SELECT COUNT(*) FROM vouchers') === $vouchersBefore + 2);

    $vouchersBefore = (int) scalar('SELECT COUNT(*) FROM vouchers');
    $bad = "Số phiếu,Ngày lập,Mã kho,Lý do,Mã hàng,Số lô,Số lượng\nC,27/10/2026,KHO-HN,Ok,SP001,L2608-01,1\nD,27/10/2026,KHO-HN,Ok,SP001,L2608-01,999999\n";
    $error = errorOf(fn () => importVouchers($pdo, $staffA, 'EXPORT', parseCsv($bad, VOUCHER_CSV_COLUMNS, VOUCHER_CSV_REQUIRED), md5($bad)));
    check('Nhập file có 1 phiếu thiếu tồn: báo lỗi, KHÔNG tạo phiếu nào (kể cả phiếu hợp lệ)',
        str_contains($error, 'không đủ tồn') && (int) scalar('SELECT COUNT(*) FROM vouchers') === $vouchersBefore, $error);
    $bad = "Số phiếu,Ngày lập,Mã kho,Lý do,Mã hàng,Số lô,Số lượng\nE,27/10/2026,KHO-HN,Ok,SP001,L2608-01,1\nE,27/10/2026,KHO-HCM,Ok,SP003,L2606-03,1\nF,27/10/2026,KHO-HN,Ok,SP999,X,1\n";
    $error = errorOf(fn () => importVouchers($pdo, $staffA, 'EXPORT', parseCsv($bad, VOUCHER_CSV_COLUMNS, VOUCHER_CSV_REQUIRED), md5($bad)));
    check('Nhập file: báo đủ các dòng lỗi (khác kho trong 1 phiếu, sai mã hàng)', str_contains($error, 'Dòng 3: Mã kho khác') && str_contains($error, 'SP999'), $error);
    $hcm = "Số phiếu,Ngày lập,Mã kho,Lý do,Mã hàng,Số lô,Số lượng\nG,27/10/2026,KHO-HCM,Ok,SP001,L2608-01,1\n";
    $error = errorOf(fn () => importVouchers($pdo, $staffA, 'EXPORT', parseCsv($hcm, VOUCHER_CSV_COLUMNS, VOUCHER_CSV_REQUIRED), md5($hcm)));
    check('Nhân viên kho HN nhập file phiếu của kho HCM bị chặn', str_contains($error, 'không có quyền'), $error);
    $import = "Số phiếu,Mã kho,Mã hàng,Số lô,Hạn sử dụng,Số lượng\n1,KHO-HN,SP001,LO-FILE-01,31/12/2027,20\n";
    [$created] = importVouchers($pdo, $staffA, 'IMPORT', parseCsv($import, VOUCHER_CSV_COLUMNS, VOUCHER_CSV_REQUIRED), md5($import));
    check('Nhập phiếu nhập từ file: tạo lô mới kèm hạn sử dụng', $created === 1 && scalar("SELECT expiry_date FROM batches WHERE batch_no = 'LO-FILE-01'") === '2027-12-31');
    $exported = getVoucherExportRows($pdo, 'EXPORT', ['keyword' => '', 'status' => 'PENDING', 'warehouse_id' => 0], $staffA['warehouses'], ['code', 'asc']);
    check('Xuất phiếu ra file: mỗi dòng hàng 1 dòng, đúng bộ lọc', count($exported) >= 3 && count($exported[0]) === count(VOUCHER_EXPORT_HEADER)
        && !array_filter($exported, fn ($r) => $r[2] !== 'Chờ duyệt'));

    // -----------------------------------------------------------------
    section('TÀI KHOẢN - QUẢN TRỊ CẤP, MẬT KHẨU TẠM, KHÓA');
    // -----------------------------------------------------------------
    $admin = loadUser($pdo, 1);
    check('Chỉ quản trị mới cấp tài khoản', str_contains(errorOf(fn () => createAccount($pdo, $manager, ['username' => 'nv.moi', 'full_name' => 'X', 'role' => 'STAFF'])), 'Chỉ quản trị'));
    [$newId, $tempPassword] = createAccount($pdo, $admin, ['username' => ' NV.Moi ', 'full_name' => 'Nguyễn  Văn Mới', 'role' => 'STAFF', 'warehouses' => ['1']]);
    $newUser = loadUser($pdo, $newId);
    check('Cấp tài khoản: tên đăng nhập chữ thường, mật khẩu tạm 10 ký tự, bắt đổi mật khẩu, đúng kho',
        $newUser['username'] === 'nv.moi' && $newUser['full_name'] === 'Nguyễn Văn Mới' && strlen($tempPassword) === 10
        && (int) $newUser['must_change_password'] === 1 && $newUser['warehouses'] === [1]
        && password_verify($tempPassword, (string) scalar('SELECT password_hash FROM users WHERE id = ?', [$newId])));
    check('Trùng tên đăng nhập bị từ chối', str_contains(errorOf(fn () => createAccount($pdo, $admin, ['username' => 'nv.moi', 'full_name' => 'X', 'role' => 'STAFF'])), 'đã có người dùng'));
    check('Không cấp được vai trò quản trị', str_contains(errorOf(fn () => createAccount($pdo, $admin, ['username' => 'nv.qt', 'full_name' => 'X', 'role' => 'ADMIN'])), 'Vai trò'));
    check('Không giao được kho đi đường cho người dùng', str_contains(errorOf(fn () => createAccount($pdo, $admin, ['username' => 'nv.dd', 'full_name' => 'X', 'role' => 'STAFF', 'warehouses' => [$transit]])), 'không tồn tại'));
    check('Không sửa / khóa / đặt lại mật khẩu tài khoản quản trị',
        str_contains(errorOf(fn () => updateAccount($pdo, $admin, 1, ['full_name' => 'X', 'role' => 'STAFF'])), 'quản trị hệ thống không')
        && str_contains(errorOf(fn () => resetAccountPassword($pdo, $admin, 1)), 'quản trị hệ thống không')
        && str_contains(errorOf(fn () => setAccountActive($pdo, $admin, 1, false)), 'Không tự khóa'));

    check('Đổi mật khẩu: sai mật khẩu hiện tại', str_contains(errorOf(fn () => changeOwnPassword($pdo, $newUser, 'sai', 'MatKhau123', 'MatKhau123')), 'không đúng'));
    check('Đổi mật khẩu: mật khẩu yếu bị từ chối', str_contains(errorOf(fn () => changeOwnPassword($pdo, $newUser, $tempPassword, '12345678', '12345678')), 'ít nhất 8'));
    check('Đổi mật khẩu: nhập lại không khớp', str_contains(errorOf(fn () => changeOwnPassword($pdo, $newUser, $tempPassword, 'MatKhau123', 'MatKhau124')), 'không khớp'));
    check('Đổi mật khẩu: không được giữ nguyên mật khẩu tạm', str_contains(errorOf(fn () => changeOwnPassword($pdo, $newUser, $tempPassword, $tempPassword, $tempPassword)), 'phải khác'));
    changeOwnPassword($pdo, $newUser, $tempPassword, 'MatKhau123', 'MatKhau123');
    check('Đổi mật khẩu thành công: hết bắt đổi, mật khẩu mới dùng được',
        (int) loadUser($pdo, $newId)['must_change_password'] === 0 && password_verify('MatKhau123', (string) scalar('SELECT password_hash FROM users WHERE id = ?', [$newId])));
    $resetPassword = resetAccountPassword($pdo, $admin, $newId);
    $hash = (string) scalar('SELECT password_hash FROM users WHERE id = ?', [$newId]);
    check('Đặt lại mật khẩu: mật khẩu tạm mới, mật khẩu cũ hết hiệu lực, lại bắt đổi',
        password_verify($resetPassword, $hash) && !password_verify('MatKhau123', $hash) && (int) loadUser($pdo, $newId)['must_change_password'] === 1);
    setAccountActive($pdo, $admin, $newId, false);
    check('Khóa tài khoản: không đăng nhập được, phiên đang mở bị đẩy ra (loadUser = null)',
        loadUser($pdo, $newId) === null && attemptLogin($pdo, 'nv.moi', $resetPassword) === false);
    check('Đăng nhập tài khoản bị khóa (đúng mật khẩu): báo "đang bị khóa"; sai mật khẩu: chỉ báo sai mật khẩu',
        isLockedAccount($pdo, 'nv.moi', $resetPassword) && !isLockedAccount($pdo, 'nv.moi', 'doan-mo-123') && !isLockedAccount($pdo, 'quanly', 'password'));
    setAccountActive($pdo, $admin, $newId, true);
    check('Mở khóa: dùng lại được', loadUser($pdo, $newId) !== null);

    // -----------------------------------------------------------------
    section('PHÂN QUYỀN KHO (SỬA TÀI KHOẢN) - QUẢN LÝ KHO NÀY KHÔNG ĐỤNG ĐƯỢC KHO KHÁC');
    // -----------------------------------------------------------------
    updateAccount($pdo, $admin, $newId, ['full_name' => 'Nguyễn Văn Mới', 'role' => 'MANAGER', 'warehouses' => ['1', '2']]);
    $hnManager = loadUser($pdo, $newId);
    check('Sửa tài khoản: đổi vai trò và kho phụ trách', $hnManager['role'] === 'MANAGER' && $hnManager['warehouses'] === [1, 2]);
    check('Không giao được kho không tồn tại (sửa HTML)', str_contains(errorOf(fn () => updateAccount($pdo, $admin, $newId, ['full_name' => 'X', 'role' => 'MANAGER', 'warehouses' => [9999]])), 'không tồn tại')
        && loadUser($pdo, $newId)['warehouses'] === [1, 2]);
    check('Nhân viên không sửa được tài khoản', str_contains(errorOf(fn () => updateAccount($pdo, $staffA, 3, ['full_name' => 'X', 'role' => 'MANAGER', 'warehouses' => [1, 2, 3]])), 'Chỉ quản trị'));
    check('Cảnh báo kho chưa có quản lý: KHO-ĐN vẫn có quản lý chung (quanly)', !in_array($dn['code'], array_column(warehousesWithoutManager($pdo), 'code'), true));

    $dnVoucher = createVoucher($pdo, $staffC, [
        'type' => 'IMPORT', 'warehouse_id' => 3, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 1, 'batch_id' => 1, 'quantity' => '1']],
    ]);
    check('Quản lý kho HN/HCM không duyệt được phiếu kho ĐN', str_contains(errorOf(fn () => approveVoucher($pdo, $hnManager, $dnVoucher)), 'không có quyền')
        && scalar('SELECT status FROM vouchers WHERE id = ?', [$dnVoucher]) === 'PENDING');
    check('Quản lý kho HN/HCM không từ chối được phiếu kho ĐN', str_contains(errorOf(fn () => rejectVoucher($pdo, $hnManager, $dnVoucher)), 'không có quyền'));
    check('Quản lý kho HN/HCM không mở kiểm kê kho ĐN', str_contains(errorOf(fn () => createStockCount($pdo, $hnManager, 3, '')), 'không có quyền'));
    check('Quản lý kho HN/HCM không sửa được thông tin kho ĐN',
        str_contains(errorOf(fn () => updateWarehouse($pdo, $hnManager, 3, ['code' => $dn['code'], 'name' => 'X'])), 'không có quyền'));
    rejectVoucher($pdo, $manager, $dnVoucher);
    updateAccount($pdo, $admin, $newId, ['full_name' => 'Nguyễn Văn Mới', 'role' => 'MANAGER', 'warehouses' => [3]]);
    $hnManager = loadUser($pdo, $newId);
    check('Đổi kho phụ trách có hiệu lực ngay ở lần tải trang sau (đọc lại quyền mỗi request)',
        canAccessWarehouse($hnManager, 3) && !canAccessWarehouse($hnManager, 1));

    // -----------------------------------------------------------------
    section('TRỢ LÝ AI (BETA) - CÔNG CỤ CHỈ ĐỌC, THEO QUYỀN KHO');
    // -----------------------------------------------------------------
    $stock = json_decode(runChatTool($pdo, $staffA, 'search_stock', ['keyword' => '']), true);
    check('Tra tồn qua trợ lý: nhân viên kho HN chỉ thấy KHO-HN', $stock['rows'] && !array_filter($stock['rows'], fn ($r) => $r['kho'] !== 'KHO-HN'));
    $other = json_decode(runChatTool($pdo, $staffA, 'search_stock', ['warehouse_code' => 'kho-hcm']), true);
    check('Hỏi kho không được phân quyền: báo không có quyền, không lộ số liệu', isset($other['error']) && !isset($other['rows']), $other['error'] ?? '');
    $pending = json_decode(runChatTool($pdo, $manager, 'list_vouchers', ['type' => 'IMPORT', 'status' => 'PENDING']), true);
    check('Liệt kê phiếu chờ duyệt qua trợ lý: đúng số với danh sách phiếu',
        $pending['total'] === countVouchers($pdo, 'IMPORT', ['keyword' => '', 'status' => 'PENDING', 'warehouse_id' => 0], $manager['warehouses'])
        && !array_filter($pending['rows'], fn ($r) => $r['trang_thai'] !== voucherStatusLabel('PENDING', 'IMPORT')));
    check('Công cụ lạ / tham số lạ bị từ chối', isset(json_decode(runChatTool($pdo, $manager, 'delete_all', []), true)['error'])
        && isset(json_decode(runChatTool($pdo, $manager, 'list_vouchers', ['type' => 'DROP']), true)['error']));
    $overview = json_decode(runChatTool($pdo, $staffA, 'get_overview', []), true);
    check('Tổng quan qua trợ lý khớp bảng điều khiển', $overview === getDashboardStats($pdo, $staffA['warehouses']));
    check('Lời dặn hệ thống nêu đúng người hỏi và kho được xem', str_contains($prompt = chatSystemPrompt($pdo, $staffA), $staffA['full_name'])
        && str_contains($prompt, 'KHO-HN') && !str_contains($prompt, 'KHO-HCM'));

    // -----------------------------------------------------------------
    section('LÔ HÀNG - TRA CỨU THEO KHO ĐƯỢC PHÂN QUYỀN');
    // -----------------------------------------------------------------
    $byStatus = [];
    foreach (array_keys(BATCH_STATUS_FILTERS) as $status) {
        $byStatus[$status] = countBatches($pdo, ['status' => $status], $staffA['warehouses']);
    }
    check('Lọc lô: còn hàng + hết hàng = tất cả; sắp hết hạn / hết hạn nằm trong còn hàng',
        $byStatus['in_stock'] + $byStatus['empty'] === $byStatus['all'] && $byStatus['soon'] + $byStatus['expired'] <= $byStatus['in_stock']
        && $byStatus['all'] === (int) scalar('SELECT COUNT(*) FROM batches'));
    $hnBatches = getBatches($pdo, ['status' => 'in_stock'], $staffA['warehouses'], 1000, 0);
    $wrong = array_filter($hnBatches, fn ($b) => toMilli($b['quantity']) !== toMilli(scalar('SELECT COALESCE(SUM(quantity), 0) FROM stocks WHERE batch_id = ? AND warehouse_id = 1', [$b['id']])));
    check('Số lượng lô của nhân viên kho HN chỉ tính ở kho HN', $hnBatches && !$wrong, count($wrong) . ' lô lệch');
    $expired = getBatches($pdo, ['status' => 'expired'], null, 1000, 0);
    check('Lọc lô đã hết hạn: chỉ lô quá hạn còn hàng', !array_filter($expired, fn ($b) => $b['days_left'] >= 0 || toMilli($b['quantity']) <= 0));
    $sample = (int) scalar('SELECT batch_id FROM stocks WHERE warehouse_id = 1 AND quantity > 0 AND batch_id IN (SELECT batch_id FROM stocks WHERE warehouse_id <> 1 AND quantity > 0) LIMIT 1');
    check('Chi tiết lô: nhân viên chỉ thấy tồn ở kho mình', $sample > 0 && array_map('intval', array_column(getBatchStock($pdo, $sample, $staffA['warehouses']), 'warehouse_id')) === [1]);
    $sampleVouchers = getBatchVouchers($pdo, $sample, $staffA['warehouses']);
    $foreign = array_filter($sampleVouchers, fn ($v) => !in_array(1, array_map('intval', [
        scalar('SELECT warehouse_id FROM vouchers WHERE id = ?', [$v['id']]), scalar('SELECT COALESCE(to_warehouse_id, 0) FROM vouchers WHERE id = ?', [$v['id']]),
    ]), true));
    check('Chi tiết lô: chỉ liệt kê phiếu liên quan kho mình', $sampleVouchers && !$foreign);
    $top = getBatches($pdo, ['status' => 'all'], null, 2, 0, ['available', 'desc']);
    check('Sắp xếp lô theo có thể xuất giảm dần', count($top) === 2 && toMilli($top[0]['available']) >= toMilli($top[1]['available']));

    // -----------------------------------------------------------------
    section('SỔ PHÁT SINH - BẤT BIẾN VÀ ĐỐI CHIẾU');
    // -----------------------------------------------------------------
    check('Không UPDATE được sổ phát sinh', str_contains(errorOf(fn () => $pdo->exec('UPDATE stock_movements SET quantity = 1 WHERE id = 1')), 'chi duoc INSERT'));
    check('Không DELETE được sổ phát sinh', str_contains(errorOf(fn () => $pdo->exec('DELETE FROM stock_movements WHERE id = 1')), 'chi duoc INSERT'));
    check('Không sửa được chứng từ đã duyệt', str_contains(errorOf(fn () => $pdo->exec("UPDATE vouchers SET reason = 'sửa' WHERE id = $firstId")), 'khong duoc sua'));
    check('Không tồn tại dòng tồn âm', (float) scalar('SELECT MIN(quantity) FROM stocks') >= 0);
    check('Tồn (stocks) khớp tổng sổ phát sinh ở mọi kho / lô', ledgerMismatches() === 0, 'số dòng lệch = ' . ledgerMismatches());
    check('Sau toàn bộ bộ test: hàng đang giữ và hàng đang đi đường cũng khớp phiếu', allMismatches() === 0, 'số dòng lệch = ' . allMismatches());
    check('Không sửa / xóa được đợt ghi sổ đã ghi', str_contains(errorOf(fn () => $pdo->exec('DELETE FROM voucher_postings WHERE id = 1')), 'chi duoc INSERT'));
} catch (Throwable $e) {
    check('Lỗi không mong đợi khi chạy test', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if (isset($options['keep'])) {
        echo "\nGiữ lại CSDL test " . TEST_DB . " (--keep).\n";
    } else {
        getDbConnection()->exec('DROP DATABASE IF EXISTS ' . TEST_DB);
        echo "\nĐã xóa CSDL test " . TEST_DB . ". CSDL quanlykhohang không bị thay đổi.\n";
    }
}

printf("\nKết quả: \033[32m%d PASS\033[0m, \033[31m%d FAIL\033[0m, \033[33m%d SKIP\033[0m\n", $results['pass'], $results['fail'], $results['skip']);
exit($results['fail'] === 0 ? 0 : 1);
