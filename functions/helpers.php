<?php
// Các hàm dùng chung cho mọi trang danh sách

// Tên ứng dụng: hiển thị trên tab trình duyệt, thanh bên và trang đăng nhập
const APP_NAME    = 'OmniStock';
const APP_TAGLINE = 'Quản lý kho đa điểm';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

const PAGE_SIZES        = [10, 20, 30, 50];
const DEFAULT_PAGE_SIZE = 10;

/**
 * Đọc tham số danh sách trên URL: ?q=...&size=...&page=...
 * Trả về [từ khóa, số dòng/trang, trang hiện tại].
 */
function readListParams(): array
{
    $keyword  = trim($_GET['q'] ?? '');
    $pageSize = (int) ($_GET['size'] ?? DEFAULT_PAGE_SIZE);
    if (!in_array($pageSize, PAGE_SIZES, true)) {
        $pageSize = DEFAULT_PAGE_SIZE;
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));
    return [$keyword, $pageSize, $page];
}

/**
 * Sắp xếp theo cột: ?sort=<khóa>&dir=asc|desc.
 * $columns: khóa => biểu thức SQL. Chỉ nhận khóa có trong $columns (whitelist), nên ghép vào ORDER BY an toàn.
 * Trả về [khóa, hướng].
 */
function readSort(array $columns, string $defaultKey, string $defaultDir = 'asc'): array
{
    $key = array_key_exists($_GET['sort'] ?? '', $columns) ? $_GET['sort'] : $defaultKey;
    $dir = in_array($_GET['dir'] ?? '', ['asc', 'desc'], true) ? $_GET['dir'] : $defaultDir;
    return [$key, $dir];
}

/** Mệnh đề ORDER BY từ [khóa, hướng]; $tieBreaker giữ thứ tự ổn định giữa các trang khi giá trị trùng */
function orderByClause(array $columns, array $sort, string $tieBreaker): string
{
    [$key, $dir] = $sort;
    $column = $columns[$key] ?? reset($columns);
    return $column . ($dir === 'desc' ? ' DESC' : ' ASC') . ', ' . $tieBreaker;
}

/**
 * Tiêu đề cột bấm được để sắp xếp: bấm lần 1 theo $firstDir, bấm lại thì đảo chiều.
 * Giữ nguyên bộ lọc đang có trên URL, quay về trang 1.
 */
function sortHeader(string $label, string $key, array $sort, string $class = '', string $firstDir = 'asc'): string
{
    [$currentKey, $currentDir] = $sort;
    $active = $currentKey === $key;
    $next   = $active ? ($currentDir === 'asc' ? 'desc' : 'asc') : $firstDir;
    $url    = '?' . http_build_query(array_merge($_GET, ['sort' => $key, 'dir' => $next, 'page' => 1]));
    $icon   = $active ? ($currentDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    $title  = 'Sắp xếp ' . ($next === 'asc' ? 'tăng dần' : 'giảm dần');
    return '<th class="sortable' . ($active ? ' sorted' : '') . ($class !== '' ? " $class" : '') . '">'
        . '<a href="' . e($url) . '" title="' . $title . '">' . e($label) . ' <i class="fas ' . $icon . '"></i></a></th>';
}

/** Ô ẩn trong form lọc (GET) để đổi bộ lọc không làm mất kiểu sắp xếp đang chọn */
function sortHiddenInputs(array $sort): string
{
    return '<input type="hidden" name="sort" value="' . e($sort[0]) . '"><input type="hidden" name="dir" value="' . e($sort[1]) . '">';
}

// Tạo link phân trang, giữ nguyên từ khóa, số dòng/trang và các bộ lọc khác đang có trên URL
function pageUrl(int $targetPage, string $keyword, int $pageSize): string
{
    return '?' . http_build_query(array_merge($_GET, ['q' => $keyword, 'size' => $pageSize, 'page' => $targetPage]));
}

// 1500 -> "1.500" ; 2.5 -> "2,5"
function formatQty($qty): string
{
    $formatted = number_format((float) $qty, 3, ',', '.');
    return rtrim(rtrim($formatted, '0'), ',');
}

// Giá trị cho ô <input type="number">: 12.500 -> "12.5" (dấu chấm thập phân, không phân cách nghìn)
function plainQty($qty): string
{
    return rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.');
}

// 2026-10-20 -> 20/10/2026
function formatDate(?string $date): string
{
    return $date ? date('d/m/Y', strtotime($date)) : '';
}

function voucherTypeLabel(string $type): string
{
    return [
        'IMPORT'     => 'Nhập kho',
        'EXPORT'     => 'Xuất kho',
        'TRANSFER'   => 'Điều chuyển',
        'ADJUSTMENT' => 'Điều chỉnh',
    ][$type] ?? $type;
}

// Trạng thái chứng từ: [lớp badge, nhãn chung]; nhãn riêng theo loại phiếu nằm trong VOUCHER_STATUS_BY_TYPE
const VOUCHER_STATUS = [
    'PENDING'     => ['badge-pending', 'Chờ duyệt'],
    'APPROVED'    => ['badge-info', 'Đã duyệt'],
    'PARTIAL'     => ['badge-warn', 'Đã nhận một phần'],
    'IN_TRANSIT'  => ['badge-transit', 'Đang đi đường'],
    'DISCREPANCY' => ['badge-rejected', 'Nhận thiếu · chờ xử lý'],
    'COMPLETED'   => ['badge-active', 'Hoàn tất'],
    'CLOSED'      => ['badge-inactive', 'Đóng phiếu (thiếu hàng)'],
    'CANCELLED'   => ['badge-inactive', 'Đã hủy'],
    'REJECTED'    => ['badge-rejected', 'Từ chối'],
];
const VOUCHER_STATUS_BY_TYPE = [
    'IMPORT:APPROVED'     => 'Đã duyệt · chờ hàng về',
    'IMPORT:COMPLETED'    => 'Đã nhận đủ',
    'EXPORT:APPROVED'     => 'Đã duyệt · đang giữ hàng',
    'EXPORT:COMPLETED'    => 'Đã xuất kho',
    'TRANSFER:APPROVED'   => 'Đã duyệt · chờ gửi hàng',
    'ADJUSTMENT:APPROVED' => 'Đã ghi nhận',
];
// Trạng thái có thể có của từng loại phiếu (bộ lọc danh sách), theo thứ tự quy trình
const VOUCHER_TYPE_STATUSES = [
    'IMPORT'     => ['PENDING', 'APPROVED', 'PARTIAL', 'COMPLETED', 'CLOSED', 'REJECTED'],
    'EXPORT'     => ['PENDING', 'APPROVED', 'COMPLETED', 'CANCELLED', 'REJECTED'],
    'TRANSFER'   => ['PENDING', 'APPROVED', 'IN_TRANSIT', 'DISCREPANCY', 'COMPLETED', 'CANCELLED', 'REJECTED'],
    'ADJUSTMENT' => ['PENDING', 'APPROVED'],
];

function voucherStatusLabel(string $status, string $type = ''): string
{
    return VOUCHER_STATUS_BY_TYPE["$type:$status"] ?? VOUCHER_STATUS[$status][1] ?? $status;
}

function voucherStatusBadge(string $status, string $type = ''): string
{
    $class = $type === 'ADJUSTMENT' && $status === 'APPROVED' ? 'badge-active' : (VOUCHER_STATUS[$status][0] ?? 'badge-inactive');
    return '<span class="badge ' . $class . '">' . e(voucherStatusLabel($status, $type)) . '</span>';
}

/**
 * Điểm gây lỗi giả lập cho bộ kiểm thử (fault injection). Ứng dụng thật không bật điểm nào nên hàm không làm gì;
 * test bật bằng $GLOBALS['FAILPOINTS']['tên'] = true để chứng minh lỗi giữa chừng thì transaction hoàn tác hết.
 */
function failpoint(string $name): void
{
    if (!empty($GLOBALS['FAILPOINTS'][$name])) {
        throw new RuntimeException("Lỗi giả lập tại bước \"$name\"");
    }
}

/**
 * Điều kiện lọc theo kho user được phép (kiểm tra ở server).
 * $allowedWarehouses = null -> không giới hạn. Trả về [chuỗi SQL, mảng tham số].
 */
function warehouseFilter(string $column, ?array $allowedWarehouses): array
{
    if ($allowedWarehouses === null) {
        return ['1 = 1', []];
    }
    if (empty($allowedWarehouses)) {
        return ['1 = 0', []];
    }
    $placeholders = implode(',', array_fill(0, count($allowedWarehouses), '?'));
    return ["$column IN ($placeholders)", array_map('intval', $allowedWarehouses)];
}

/**
 * Link tới file tĩnh kèm ?v=<thời điểm sửa file>.
 * Mỗi lần sửa CSS/JS, URL đổi -> trình duyệt buộc tải bản mới thay vì dùng bản cache cũ.
 */
function asset(string $basePath, string $path): string
{
    $file = dirname(__DIR__) . '/' . $path;
    $version = is_file($file) ? filemtime($file) : 0;
    return $basePath . $path . '?v=' . $version;
}

/** Chạy $fn trong 1 transaction: lỗi ở bất kỳ đâu -> rollBack toàn bộ */
function inTransaction(PDO $pdo, callable $fn)
{
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Lỗi nghiệp vụ: thông báo được hiển thị thẳng cho người dùng (lỗi CSDL thì không) */
class AppException extends RuntimeException
{
}

// =====================================================================
// CHỐNG TRÙNG MÃ / TÊN DANH MỤC (kho, mặt hàng)
// Lớp cuối là UNIQUE trong CSDL (code/sku, name_key); các hàm dưới đây báo lỗi dễ hiểu trước khi chạm tới CSDL
// và chặn thêm trường hợp CSDL không biết: mã "gần giống" (SP001 / SP-001 / SP0001).
// =====================================================================

const CATALOG_TABLES = [
    // bảng => [cột mã, nhãn mã, nhãn bản ghi, nhãn ngừng dùng, thao tác dùng lại, gợi ý khi trùng tên]
    'items'      => ['sku',  'Mã hàng', 'mặt hàng', 'đang ngừng dùng', 'Dùng lại', 'Nếu là quy cách khác, hãy ghi rõ trong tên (vd: thêm "thùng 24 lon").'],
    'warehouses' => ['code', 'Mã kho',  'kho',      'đang ngừng hoạt động', 'Hoạt động lại', 'Hãy đặt tên khác để phân biệt (vd: thêm tên quận / khu công nghiệp).'],
];

/** Gộp khoảng trắng thừa trong tên (đầu / cuối / giữa) trước khi lưu và so sánh */
function normalizeName(string $name): string
{
    return trim(preg_replace('/\s+/u', ' ', $name));
}

/** Dạng rút gọn để so mã gần giống: bỏ . _ - và số 0 đứng đầu mỗi cụm số. SP-001, SP0001, sp.01 -> SP1 */
function codeKey(string $code): string
{
    $code = preg_replace('/[._\-]/', '', strtoupper($code));
    return preg_replace('/(?<!\d)0+(?=\d)/', '', $code);
}

/**
 * Kiểm tra mã: trùng hẳn hoặc gần giống một mã đã có. Trả về thông báo lỗi, hoặc null nếu dùng được.
 * $excludeId: id bản ghi đang sửa (bỏ qua chính nó).
 */
function catalogCodeConflict(PDO $pdo, string $table, string $code, int $excludeId = 0): ?string
{
    [$column, $codeLabel, , $inactiveLabel] = CATALOG_TABLES[$table];
    $key  = codeKey($code);
    $rows = $pdo->query("SELECT id, $column AS code, name, is_active FROM $table")->fetchAll();
    foreach ($rows as $row) {
        if ((int) $row['id'] === $excludeId) {
            continue;
        }
        $inactive = $row['is_active'] ? '' : " ($inactiveLabel)";
        if (strcasecmp($row['code'], $code) === 0) {
            return "$codeLabel $code đã tồn tại: {$row['name']}$inactive.";
        }
        if (codeKey($row['code']) === $key) {
            return "$codeLabel $code gần giống mã {$row['code']} đã có ({$row['name']}$inactive). Hãy đặt mã khác để tránh nhầm lẫn.";
        }
    }
    return null;
}

/** Kiểm tra tên: không phân biệt hoa / thường và khoảng trắng thừa, nhưng phân biệt dấu (giống UNIQUE name_key) */
function catalogNameConflict(PDO $pdo, string $table, string $name, int $excludeId = 0): ?string
{
    [$column, , $noun, $inactiveLabel, $restoreLabel, $hint] = CATALOG_TABLES[$table];
    $stmt = $pdo->prepare("SELECT id, $column AS code, is_active FROM $table WHERE name_key = ? AND id <> ?");
    $stmt->execute([mb_strtolower(normalizeName($name)), $excludeId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return $row['is_active']
        ? "Tên này đã có ở $noun mã {$row['code']}. $hint"
        : "Tên này đã có ở $noun mã {$row['code']} ($inactiveLabel). Hãy mở $noun đó và bấm \"$restoreLabel\" thay vì tạo mới.";
}

/**
 * Mã gợi ý tiếp theo theo mẫu <tiền tố><số>, vd SP030 -> SP031. Chỉ là gợi ý cho ô nhập;
 * người dùng vẫn sửa được và vẫn qua đủ các kiểm tra trùng ở trên.
 */
function nextCatalogCode(PDO $pdo, string $table, string $prefix, int $digits = 3): string
{
    $column = CATALOG_TABLES[$table][0];
    $max = 0;
    foreach ($pdo->query("SELECT $column FROM $table")->fetchAll(PDO::FETCH_COLUMN) as $code) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/i', $code, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return $prefix . str_pad((string) ($max + 1), $digits, '0', STR_PAD_LEFT);
}

/** Thông báo lỗi CSDL cho người dùng: vi phạm UNIQUE (2 người cùng tạo một lúc) thì nói rõ là trùng */
function dbErrorMessage(PDOException $e): string
{
    if (($e->errorInfo[1] ?? 0) === 1062) {
        return 'Dữ liệu bị trùng: mã hoặc tên này vừa được tạo (có thể bởi người khác cùng lúc). Thao tác đã được hoàn tác, hãy kiểm tra lại.';
    }
    return 'Lỗi CSDL, thao tác đã được hoàn tác: ' . $e->getMessage();
}

/** Thay đổi danh mục (kho, mặt hàng) chỉ dành cho quản lý / quản trị */
function assertCatalogManager(array $user): void
{
    if (!in_array($user['role'], ['ADMIN', 'MANAGER'], true)) {
        throw new AppException('Chỉ quản lý / quản trị mới được thay đổi danh mục.');
    }
}

// So sánh số lượng DECIMAL(15,3) bằng số nguyên phần nghìn, tránh sai số float
function toMilli($qty): int
{
    return (int) round((float) $qty * 1000);
}

/**
 * Trang quay về sau khi xử lý form danh mục:
 *   return_to=detail -> trang chi tiết của bản ghi
 *   còn lại          -> trang danh sách, giữ nguyên bộ lọc / trang đang xem (return_query)
 */
function catalogReturnUrl(string $listPage, string $detailPage, array $post, int $id): string
{
    if (($post['return_to'] ?? '') === 'detail' && $id > 0) {
        return "../views/$detailPage?id=$id";
    }
    parse_str((string) ($post['return_query'] ?? ''), $query);
    $query = array_intersect_key($query, array_flip(['q', 'status', 'warehouse', 'sort', 'dir', 'size', 'page']));
    return "../views/$listPage" . ($query ? '?' . http_build_query($query) : '');
}

/** Giữ dữ liệu đã nhập + lỗi của popup qua lần chuyển trang, để trang mở lại popup kèm lỗi */
function keepModalState(string $key, array $values, string $error): void
{
    startSession();
    $_SESSION[$key] = ['values' => $values, 'error' => $error];
}

function takeModalState(string $key): ?array
{
    startSession();
    $state = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);
    return is_array($state) ? $state : null;
}
