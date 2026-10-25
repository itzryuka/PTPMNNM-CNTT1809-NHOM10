<?php
// Các hàm dùng chung cho mọi trang danh sách

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Đọc tham số danh sách trên URL: ?q=...&size=...&page=...
 * Trả về [từ khóa, số dòng/trang, trang hiện tại].
 */
function readListParams(): array
{
    $keyword  = trim($_GET['q'] ?? '');
    $pageSize = (int) ($_GET['size'] ?? 30);
    if (!in_array($pageSize, [10, 20, 30, 50], true)) {
        $pageSize = 30;
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));
    return [$keyword, $pageSize, $page];
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

function voucherStatusBadge(string $status): string
{
    [$class, $label] = [
        'PENDING'  => ['badge-pending', 'Chờ duyệt'],
        'APPROVED' => ['badge-active', 'Đã ghi nhận'],
        'REJECTED' => ['badge-rejected', 'Từ chối'],
    ][$status] ?? ['badge-inactive', $status];
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
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
