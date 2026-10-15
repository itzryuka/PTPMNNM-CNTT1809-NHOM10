<?php
require __DIR__ . '/../src/Database.php';
try {
    db();
} catch (Throwable $e) {
    fwrite(STDERR, "Chưa kết nối được CSDL.\n");
    exit(2);
}
$proc = proc_open(['psql', '-v', 'ON_ERROR_STOP=1'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($proc)) {
    exit(2);
}
exit(proc_close($proc));
