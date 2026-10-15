<?php
declare(strict_types=1);
function canComplete(array $session, int $requestedEventId, int $now, int $ttl): bool {
    if ($requestedEventId <= 0 || $ttl <= 0 || !isset($session['draft']) || !is_array($session['draft'])) {
        return false;
    }

    $draft = $session['draft'];
    $data = $draft['data'] ?? null;
    $createdAt = $draft['created_at'] ?? null;
    if (!is_array($data) || !is_int($data['event_id'] ?? null) || !is_int($createdAt)) {
        return false;
    }

    if ($data['event_id'] !== $requestedEventId || $createdAt > $now) {
        return false;
    }

    return $now - $createdAt < $ttl;
}
