<?php
require_once __DIR__ . '/../src/Database.php';
try {
    $result = queryParams(
        db(),
        'SELECT e.id,e.title,e.category,e.capacity,COUNT(r.id) AS registered
         FROM events e LEFT JOIN registrations r ON r.event_id=e.id
         GROUP BY e.id,e.title,e.category,e.capacity ORDER BY e.id',
        []
    );
    $rows = pg_fetch_all($result) ?: [];
    return array_map(static function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['capacity'] = (int)$row['capacity'];
        $row['registered'] = (int)$row['registered'];
        return $row;
    }, $rows);
} catch (Throwable $e) {
    http_response_code(503);
    exit('DATABASE_UNAVAILABLE: check the local lab configuration.');
}
