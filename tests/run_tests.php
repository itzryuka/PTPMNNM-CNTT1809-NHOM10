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

function movementCount(): int
{
    return (int) scalar('SELECT COUNT(*) FROM stock_movements');
}

function ledgerMismatches(): int
{
    return (int) scalar('SELECT COALESCE(SUM(is_matched = 0), 0) FROM v_stock_reconciliation');
}

function voucherStatus(int $id): string
{
    return (string) scalar('SELECT status FROM vouchers WHERE id = ?', [$id]);
}

function newToken(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Chạy song song 1 tiến trình PHP cho mỗi phiếu, tất cả cùng bấm "duyệt" tại 1 thời điểm.
 * Trả về [voucherId => 'APPROVED' | 'REFUSED ...']
 */
function approveConcurrently(array $voucherIds): array
{
    $startAt = microtime(true) + 1.0;                // đủ thời gian để mọi tiến trình khởi động xong
    $procs = [];
    foreach ($voucherIds as $id) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/race_worker.php') . " $id $startAt";
        $procs[$id] = popen($cmd, 'r');
    }
    $outcomes = [];
    foreach ($procs as $id => $proc) {
        $outcomes[$id] = trim(stream_get_contents($proc));
        pclose($proc);
    }
    return $outcomes;
}

/** Tạo lô mới tồn đúng $qty ở kho $warehouseId + $count phiếu xuất PENDING, mỗi phiếu xuất $exportQty */
function makeRaceFixture(array $staff, array $manager, int $warehouseId, string $batchNo, int $qty, int $exportQty, int $count): array
{
    $pdo = getDbConnection();
    $importId = createVoucher($pdo, $staff, [
        'type' => 'IMPORT', 'warehouse_id' => $warehouseId, 'voucher_date' => date('Y-m-d'),
        'reason' => 'Chuẩn bị phép thử 1', 'client_token' => newToken(),
        'lines' => [['item_id' => 3, 'batch_id' => 'new', 'new_batch_no' => $batchNo, 'new_expiry' => '2029-01-01', 'quantity' => (string) $qty]],
    ]);
    approveVoucher($pdo, $manager, $importId);
    $batchId = (int) scalar('SELECT id FROM batches WHERE batch_no = ?', [$batchNo]);

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

$manager = loadUser($pdo, 2);     // quanly: quản lý, cả 3 kho
$staffA  = loadUser($pdo, 3);     // nhanvien_a: nhân viên, chỉ Kho HN (id 1)
$staffC  = loadUser($pdo, 5);     // nhanvien_c: nhân viên, chỉ Kho ĐN (id 3)

try {
    // -----------------------------------------------------------------
    section('PHÉP THỬ 1 - Kho còn 5, hai yêu cầu CÙNG LÚC xuất 4');
    // -----------------------------------------------------------------
    // Vòng 1 dùng dữ liệu mẫu (lô L2610-TEST, PX-2026-0032/0033); vòng 2-3 tự tạo dữ liệu mới
    $raceBatch = (int) scalar("SELECT id FROM batches WHERE batch_no = 'L2610-TEST'");
    $raceIds   = array_map('intval', $pdo->query("SELECT id FROM vouchers WHERE reason LIKE 'Phép thử 1%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    $rounds    = [[$raceBatch, $raceIds]];
    for ($round = 2; $round <= 3; $round++) {
        $rounds[] = makeRaceFixture($staffA, $manager, 1, "L-RACE-$round", 5, 4, 2);
    }

    foreach ($rounds as $n => [$batchId, $voucherIds]) {
        $before   = movementCount();
        $outcomes = approveConcurrently($voucherIds);
        $approved = array_keys(array_filter($outcomes, fn ($o) => $o === 'APPROVED'));
        $refused  = array_values(array_filter($outcomes, fn ($o) => $o !== 'APPROVED'));
        check(sprintf('Vòng %d: chỉ 1 trong 2 phiếu được ghi nhận', $n + 1), count($approved) === 1, implode(' | ', $outcomes));
        check(sprintf('Vòng %d: phiếu còn lại bị từ chối vì không đủ tồn', $n + 1),
            count($refused) === 1 && str_contains($refused[0], 'Không đủ tồn'));
        check(sprintf('Vòng %d: tồn còn 1 (không âm), sổ phát sinh thêm đúng 1 dòng', $n + 1),
            stockOf(1, $batchId) == 1.0 && movementCount() === $before + 1,
            'tồn = ' . stockOf(1, $batchId) . ', số dòng phát sinh mới = ' . (movementCount() - $before));
    }

    // -----------------------------------------------------------------
    section('PHÉP THỬ 2 - Lỗi giữa lúc trừ kho A và cộng kho B');
    // -----------------------------------------------------------------
    // Điều chuyển hợp lệ: HN -> HCM 10 (phiếu PC-2026-0018 trong dữ liệu mẫu)
    $transferId    = (int) scalar("SELECT id FROM vouchers WHERE reason LIKE 'Phép thử 2%'");
    $transferBatch = (int) scalar('SELECT batch_id FROM voucher_lines WHERE voucher_id = ?', [$transferId]);
    [$hnBefore, $hcmBefore] = [stockOf(1, $transferBatch), stockOf(2, $transferBatch)];
    approveVoucher($pdo, $manager, $transferId);
    check('Điều chuyển thành công: kho đi -10, kho đến +10 trong 1 giao dịch',
        stockOf(1, $transferBatch) == $hnBefore - 10 && stockOf(2, $transferBatch) == $hcmBefore + 10,
        "HN $hnBefore → " . stockOf(1, $transferBatch) . ", HCM $hcmBefore → " . stockOf(2, $transferBatch));
    $pair = $pdo->prepare('SELECT warehouse_id, quantity FROM stock_movements WHERE voucher_id = ? ORDER BY warehouse_id');
    $pair->execute([$transferId]);
    check('Chứng từ ghi đủ 2 đầu: (-) tại kho đi và (+) tại kho đến',
        array_map(fn ($r) => [(int) $r['warehouse_id'], (float) $r['quantity']], $pair->fetchAll()) == [[1, -10.0], [2, 10.0]]);

    // Gây lỗi giữa chừng: dòng 1 hợp lệ (kho HN bị trừ trước), dòng 2 vượt tồn -> lỗi trước khi cộng kho ĐN
    $okBatch  = (int) scalar('SELECT batch_id FROM stocks WHERE warehouse_id = 1 AND quantity >= 5 AND batch_id <> ? ORDER BY batch_id LIMIT 1', [$raceBatch]);
    $badBatch = (int) scalar('SELECT batch_id FROM stocks WHERE warehouse_id = 1 AND quantity > 0 AND batch_id > ? ORDER BY batch_id LIMIT 1', [$okBatch]);
    $pdo->exec("INSERT INTO vouchers (code, type, warehouse_id, to_warehouse_id, voucher_date, reason, created_by)
                VALUES ('PC-TEST-LOI', 'TRANSFER', 1, 3, CURDATE(), 'Phép thử 2 - gây lỗi giữa chừng', 3)");
    $brokenId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity)
                   SELECT ?, item_id, id, 5 FROM batches WHERE id = ?
                   UNION ALL SELECT ?, item_id, id, 999999 FROM batches WHERE id = ?')
        ->execute([$brokenId, $okBatch, $brokenId, $badBatch]);
    $snapshot = fn () => [stockOf(1, $okBatch), stockOf(3, $okBatch), stockOf(1, $badBatch), stockOf(3, $badBatch), movementCount()];
    $before = $snapshot();
    $error  = errorOf(fn () => approveVoucher($pdo, $manager, $brokenId));
    check('Lỗi giữa chừng được báo ra', str_contains($error, 'Không đủ tồn'), $error);
    check('Không có thay đổi dở dang: tồn 2 kho và sổ phát sinh y nguyên', $snapshot() === $before);
    check('Phiếu lỗi vẫn ở trạng thái chờ duyệt', voucherStatus($brokenId) === 'PENDING');

    // -----------------------------------------------------------------
    section('PHÉP THỬ 3 - Gửi lại chứng từ đã xác nhận');
    // -----------------------------------------------------------------
    $approvedId = $transferId;
    $before     = [movementCount(), stockOf(1, $transferBatch), stockOf(2, $transferBatch)];
    $error      = errorOf(fn () => approveVoucher($pdo, $manager, $approvedId));
    check('Duyệt lại phiếu đã xác nhận bị từ chối', str_contains($error, 'đã được xử lý'), $error);
    check('Số tồn và sổ phát sinh không tăng thêm',
        [movementCount(), stockOf(1, $transferBatch), stockOf(2, $transferBatch)] === $before);

    $outcomes = approveConcurrently([$approvedId, $approvedId]);
    check('Bấm duyệt lại 2 lần cùng lúc: cả 2 đều bị từ chối',
        count(array_filter($outcomes, fn ($o) => $o === 'APPROVED')) === 0 && movementCount() === $before[0]);

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
    $hcmBatch = (int) scalar('SELECT batch_id FROM stocks WHERE warehouse_id = 2 AND quantity > 5 ORDER BY batch_id LIMIT 1');
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

    // -----------------------------------------------------------------
    section('TÌNH HUỐNG 2 + PHÉP THỬ 5 - Điều chuyển → kiểm kê → xác nhận chênh lệch → phiếu điều chỉnh');
    // -----------------------------------------------------------------
    // Bước 1: nhân viên Kho HN lập phiếu điều chuyển 20 sang Kho ĐN, quản lý duyệt
    $moveBatch = (int) scalar('SELECT batch_id FROM stocks s JOIN batches b ON b.id = s.batch_id
                               WHERE s.warehouse_id = 1 AND s.quantity >= 50 AND b.expiry_date > CURDATE() AND s.batch_id <> ? ORDER BY s.batch_id LIMIT 1', [$transferBatch]);
    $moveItem  = (int) scalar('SELECT item_id FROM batches WHERE id = ?', [$moveBatch]);
    $dnBefore  = stockOf(3, $moveBatch);
    $moveId = createVoucher($pdo, $staffA, [
        'type' => 'TRANSFER', 'warehouse_id' => 1, 'to_warehouse_id' => 3, 'voucher_date' => date('Y-m-d'),
        'reason' => 'Tình huống 2 - chuyển hàng ra Đà Nẵng', 'client_token' => newToken(),
        'lines' => [['item_id' => $moveItem, 'batch_id' => $moveBatch, 'quantity' => '20']],
    ]);
    approveVoucher($pdo, $manager, $moveId);
    check('Điều chuyển HN → ĐN 20: kho ĐN nhận đủ', stockOf(3, $moveBatch) == $dnBefore + 20);

    // Bước 2: quản lý mở đợt kiểm kê Kho ĐN -> chụp số tồn
    $dnRows  = (int) scalar('SELECT COUNT(*) FROM stocks WHERE warehouse_id = 3 AND quantity > 0');
    $countId = createStockCount($pdo, $manager, 3, 'Kiểm kê sau khi nhận hàng điều chuyển');
    check('Mở đợt kiểm kê: chụp đủ mọi lô còn hàng của kho', (int) scalar('SELECT COUNT(*) FROM stock_count_lines WHERE count_id = ?', [$countId]) === $dnRows);
    check('Số tồn chụp lại = tồn hiện tại', (float) scalar('SELECT system_qty FROM stock_count_lines WHERE count_id = ? AND batch_id = ?', [$countId, $moveBatch]) == stockOf(3, $moveBatch));
    check('Không mở được đợt thứ 2 cho cùng kho', str_contains(errorOf(fn () => createStockCount($pdo, $manager, 3, '')), 'chưa chốt'));
    check('Nhân viên không được mở đợt kiểm kê', str_contains(errorOf(fn () => createStockCount($pdo, $staffC, 3, '')), 'Chỉ quản lý'));

    // Kho đang kiểm kê: phiếu của kho bị khóa
    $dnImport = createVoucher($pdo, $staffC, [
        'type' => 'IMPORT', 'warehouse_id' => 3, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => $moveItem, 'batch_id' => $moveBatch, 'quantity' => '1']],
    ]);
    check('Đang kiểm kê: không duyệt được phiếu nhập vào kho', str_contains(errorOf(fn () => approveVoucher($pdo, $manager, $dnImport)), 'đang kiểm kê'));

    // Bước 3: chưa đếm xong -> không duyệt được
    check('Chưa đếm hết thì không duyệt được', str_contains(errorOf(fn () => approveStockCount($pdo, $manager, $countId, 'test')), 'chưa nhập số đếm'));

    // Nhân viên ĐN đếm: lô vừa nhận chỉ còn 18 (mất 2 khi vận chuyển), 1 lô khác dư 1, còn lại khớp
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
    $entries[array_values(array_filter($lines, fn ($l) => (int) $l['batch_id'] === $moveBatch))[0]['id']]['reason'] = 'Mất 2 khi vận chuyển từ HN';
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
    check('Chốt đợt xong kho mở khóa: duyệt được phiếu nhập', errorOf(fn () => approveVoucher($pdo, $manager, $dnImport)) === '');

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
    check('Điều chuyển vượt tồn kho đi bị chặn khi lập',
        str_contains(errorOf(fn () => createVoucher($pdo, $staffA, $transferInput(['lines' => [['item_id' => $moveItem, 'batch_id' => $moveBatch, 'quantity' => '999999']]]))), 'không đủ tồn'));
    $pendingTransfer = createVoucher($pdo, $staffA, $transferInput([]));
    check('Nhân viên kho đến (HCM) thấy phiếu chuyển tới trong danh sách', (bool) array_filter(
        getVouchers($pdo, 'TRANSFER', ['keyword' => '', 'status' => 'PENDING', 'warehouse_id' => 0], [2], 50, 0),
        fn ($v) => (int) $v['id'] === $pendingTransfer
    ));

    // -----------------------------------------------------------------
    section('NGHIỆP VỤ PHIẾU NHẬP / XUẤT');
    // -----------------------------------------------------------------
    $newBatch = (int) scalar("SELECT id FROM batches WHERE batch_no = 'L-TEST-NEW'");
    approveVoucher($pdo, $manager, $firstId);
    check('Duyệt phiếu nhập có lô mới: tồn lô mới = 12.5', stockOf(1, $newBatch) == 12.5);
    check('Mã phiếu tự sinh đúng dạng PN-YYYY-NNNN', (bool) preg_match('/^PN-\d{4}-\d{4}$/', (string) scalar('SELECT code FROM vouchers WHERE id = ?', [$firstId])));

    $before = movementCount();
    rejectVoucher($pdo, $manager, $pendingHcm);
    check('Từ chối phiếu: trạng thái REJECTED, không ghi phát sinh', voucherStatus($pendingHcm) === 'REJECTED' && movementCount() === $before);
    check('Phiếu đã từ chối không duyệt lại được', errorOf(fn () => approveVoucher($pdo, $manager, $pendingHcm)) !== '');

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
    $pdo->exec("INSERT INTO stock_counts (code, warehouse_id, status, created_by) VALUES ('KK-TEST', 1, 'COUNTING', 2)");
    $before = movementCount();
    $error  = errorOf(fn () => approveVoucher($pdo, $manager, $pendingHn));
    check('Kho đang kiểm kê: chặn duyệt phiếu', str_contains($error, 'đang kiểm kê'), $error);
    check('Không ghi phát sinh khi bị chặn, phiếu vẫn chờ duyệt', movementCount() === $before && voucherStatus($pendingHn) === 'PENDING');
    $pdo->exec("UPDATE stock_counts SET status = 'CANCELLED' WHERE code = 'KK-TEST'");
    check('Hủy đợt kiểm kê xong thì duyệt được', errorOf(fn () => approveVoucher($pdo, $manager, $pendingHn)) === '');

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
        str_contains(errorOf(fn () => updateItem($pdo, $manager, $newItem, ['sku' => 'SP001', 'name' => 'Y', 'unit' => 'Chai'])), 'mặt hàng khác'));
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

    $warehouseId = createWarehouse($pdo, $manager, ['code' => 'kho-ct', 'name' => 'Kho Cần Thơ', 'address' => '']);
    check('Thêm kho: người tạo và ADMIN được phân quyền ngay',
        (int) scalar('SELECT COUNT(*) FROM user_warehouses WHERE warehouse_id = ? AND user_id IN (1, 2)', [$warehouseId]) === 2);
    check('Trùng mã kho bị từ chối', str_contains(errorOf(fn () => createWarehouse($pdo, $manager, ['code' => 'KHO-CT', 'name' => 'X'])), 'đã tồn tại'));
    $manager = loadUser($pdo, 2);                       // nạp lại quyền kho sau khi được cấp kho mới
    check('Không xóa được kho còn hàng', str_contains(errorOf(fn () => removeWarehouse($pdo, $manager, 2)), 'còn hàng'));
    check('Nhân viên kho HN không sửa được kho HCM',
        str_contains(errorOf(fn () => updateWarehouse($pdo, ['role' => 'MANAGER'] + $staffA, 2, ['code' => 'KHO-HCM', 'name' => 'X'])), 'không có quyền'));
    check('Xóa kho mới chưa có lịch sử: xóa thật', removeWarehouse($pdo, $manager, $warehouseId) === 'deleted' && getWarehouse($pdo, $warehouseId) === null);

    [$created] = importWarehouses($pdo, $manager, parseCsv("Mã kho,Tên kho,Địa chỉ\nKHO-HP,Kho Hải Phòng,Lê Hồng Phong\n", WAREHOUSE_CSV_COLUMNS, ['code', 'name']));
    $hpId = (int) scalar("SELECT id FROM warehouses WHERE code = 'KHO-HP'");
    check('Nhập kho từ file: thêm kho và phân quyền', $created === 1 && (int) scalar('SELECT COUNT(*) FROM user_warehouses WHERE warehouse_id = ? AND user_id = 2', [$hpId]) === 1);
    $manager = loadUser($pdo, 2);
    $rejected = createVoucher($pdo, $manager, [
        'type' => 'IMPORT', 'warehouse_id' => $hpId, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 1, 'batch_id' => 1, 'quantity' => '1']],
    ]);
    check('Không xóa được kho còn phiếu chờ duyệt', str_contains(errorOf(fn () => removeWarehouse($pdo, $manager, $hpId)), 'chờ duyệt'));
    rejectVoucher($pdo, $manager, $rejected);
    check('Kho đã có chứng từ (dù trống): chuyển ngừng hoạt động thay vì xóa', removeWarehouse($pdo, $manager, $hpId) === 'deactivated');
    check('Kho ngừng hoạt động không lập phiếu được', str_contains(errorOf(fn () => createVoucher($pdo, $manager, [
        'type' => 'IMPORT', 'warehouse_id' => $hpId, 'voucher_date' => date('Y-m-d'), 'client_token' => newToken(),
        'lines' => [['item_id' => 1, 'batch_id' => 1, 'quantity' => '1']],
    ])), 'ngừng hoạt động'));

    // -----------------------------------------------------------------
    section('SỔ PHÁT SINH - BẤT BIẾN VÀ ĐỐI CHIẾU');
    // -----------------------------------------------------------------
    check('Không UPDATE được sổ phát sinh', str_contains(errorOf(fn () => $pdo->exec('UPDATE stock_movements SET quantity = 1 WHERE id = 1')), 'chi duoc INSERT'));
    check('Không DELETE được sổ phát sinh', str_contains(errorOf(fn () => $pdo->exec('DELETE FROM stock_movements WHERE id = 1')), 'chi duoc INSERT'));
    check('Không sửa được chứng từ đã duyệt', str_contains(errorOf(fn () => $pdo->exec("UPDATE vouchers SET reason = 'sửa' WHERE id = $firstId")), 'khong duoc sua'));
    check('Không tồn tại dòng tồn âm', (float) scalar('SELECT MIN(quantity) FROM stocks') >= 0);
    check('Tồn (stocks) khớp tổng sổ phát sinh ở mọi kho / lô', ledgerMismatches() === 0, 'số dòng lệch = ' . ledgerMismatches());
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
