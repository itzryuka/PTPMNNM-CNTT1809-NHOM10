<?php
require __DIR__ . '/../src/Database.php';
try {
    $c = db();
    $r = queryParams($c, 'SELECT current_database() AS db, current_user AS u', []);
    $v = pg_fetch_assoc($r);
    echo 'CSDL=' . $v['db'] . ' | USER=' . $v['u'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'CHUA_SAN_SANG_CSDL: ' . $e->getMessage() . "\nChạy bash lab.sh db-config sau khi tạo CSDL lab.\n");
    exit(2);
}
