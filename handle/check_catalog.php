<?php
/**
 * Kiểm tra trùng ngay khi gõ trong popup thêm / sửa (chỉ đọc, trả JSON).
 *   GET ?entity=items|warehouses&field=code|name&value=...&id=<id đang sửa, 0 khi thêm>
 *   -> {"ok": true} hoặc {"ok": false, "message": "..."}
 * Chỉ để báo sớm cho người dùng; khi lưu, server vẫn kiểm tra lại đầy đủ (và CSDL có UNIQUE).
 */
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';

requireLogin('../');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$entity = (string) ($_GET['entity'] ?? '');
$field  = (string) ($_GET['field'] ?? '');
$value  = (string) ($_GET['value'] ?? '');
$id     = (int) ($_GET['id'] ?? 0);

if (!array_key_exists($entity, CATALOG_TABLES) || !in_array($field, ['code', 'name'], true)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'message' => 'Tham số không hợp lệ.']));
}

$value = $field === 'code' ? strtoupper(trim($value)) : normalizeName($value);
if ($value === '') {
    exit(json_encode(['ok' => true]));
}

$pdo     = getDbConnection();
$message = $field === 'code'
    ? catalogCodeConflict($pdo, $entity, $value, $id)
    : catalogNameConflict($pdo, $entity, $value, $id);

echo json_encode($message === null ? ['ok' => true] : ['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
