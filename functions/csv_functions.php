<?php
// Xuất / nhập danh mục bằng file CSV (UTF-8 có BOM) - mở và lưu trực tiếp bằng Excel

const CSV_MAX_BYTES = 2 * 1024 * 1024;
const CSV_MAX_ROWS  = 2000;

/** Gửi file CSV về trình duyệt rồi dừng */
function sendCsv(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                     // BOM: Excel đọc đúng tiếng Việt
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, array_map('csvSafe', $row));
    }
    fclose($out);
    exit;
}

/** Chống chèn công thức (CSV injection): ô bắt đầu bằng = + - @ sẽ bị Excel chạy như công thức */
function csvSafe($value): string
{
    $value = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $value) && !is_numeric($value) ? "'" . $value : $value;
}

/**
 * Đọc file CSV người dùng tải lên.
 * $columns  = [khóa => [tên cột chấp nhận, viết thường]], tên đầu tiên dùng trong thông báo lỗi
 * $required = các khóa bắt buộc phải có cột
 * Trả về [số dòng trong file => [khóa => giá trị]].
 */
function readCsvUpload(?array $file, array $columns, array $required): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new AppException('Chưa chọn file.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new AppException("Tải file lên thất bại (mã lỗi $error).");
    }
    if ($file['size'] > CSV_MAX_BYTES) {
        throw new AppException('File quá lớn (tối đa 2 MB).');
    }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        throw new AppException('Chỉ nhận file .csv. Trong Excel chọn File > Save As > "CSV UTF-8 (Comma delimited)".');
    }
    return parseCsv((string) file_get_contents($file['tmp_name']), $columns, $required);
}

function parseCsv(string $content, array $columns, array $required): array
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    if (!mb_check_encoding($content, 'UTF-8')) {
        throw new AppException('File không phải mã UTF-8 nên sẽ lỗi dấu tiếng Việt. Trong Excel hãy lưu dạng "CSV UTF-8 (Comma delimited)".');
    }

    // Excel cấu hình kiểu Việt Nam / châu Âu có thể lưu bằng dấu chấm phẩy
    $firstLine = strtok($content, "\n") ?: '';
    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header || $header === [null]) {
        throw new AppException('File trống.');
    }
    $map = [];
    foreach ($header as $index => $name) {
        $name = mb_strtolower(trim((string) $name));
        foreach ($columns as $key => $aliases) {
            if (in_array($name, $aliases, true)) {
                $map[$key] = $index;
            }
        }
    }
    foreach ($required as $key) {
        if (!isset($map[$key])) {
            throw new AppException('Thiếu cột "' . $columns[$key][0] . '". Hãy dùng file mẫu (nút "Tải mẫu").');
        }
    }

    $rows = [];
    $line = 1;
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $line++;
        if (trim(implode('', array_map('strval', $data))) === '') {
            continue;                                   // bỏ dòng trống
        }
        $row = [];
        foreach ($map as $key => $index) {
            $row[$key] = trim((string) ($data[$index] ?? ''));
        }
        $rows[$line] = $row;
        if (count($rows) > CSV_MAX_ROWS) {
            throw new AppException('File quá nhiều dòng (tối đa ' . CSV_MAX_ROWS . ').');
        }
    }
    fclose($handle);

    if (empty($rows)) {
        throw new AppException('File không có dòng dữ liệu nào.');
    }
    return $rows;
}

/** Gộp lỗi theo dòng thành 1 thông báo (tối đa 8 dòng đầu) */
function importErrorMessage(array $errors): string
{
    $shown = array_slice($errors, 0, 8);
    $more  = count($errors) - count($shown);
    return 'Không nhập file vì có ' . count($errors) . ' dòng lỗi (chưa có dữ liệu nào được ghi): '
        . implode(' | ', $shown) . ($more > 0 ? " | … và $more lỗi khác" : '');
}
