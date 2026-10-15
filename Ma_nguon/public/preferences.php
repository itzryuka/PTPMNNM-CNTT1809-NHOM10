<?php
require __DIR__ . '/../src/Session.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST_REQUIRED');
}
checkCsrf($_POST);
$view = $_POST['view'] ?? '';
if (!is_string($view) || !in_array($view, ['compact', 'full'], true)) {
    http_response_code(422);
    exit('VIEW_INVALID');
}
setcookie('view', $view, [
    'expires' => time() + 3600,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
]);
header('Location: /register.php', true, 303);
exit;
