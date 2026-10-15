<?php
require __DIR__.'/../src/Session.php';
require __DIR__.'/../src/Draft.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST_REQUIRED'); }
checkCsrf($_POST);
cancelDraft($_SESSION);
header('Location: /register.php', true, 303); exit;
