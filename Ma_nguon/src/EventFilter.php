<?php

declare(strict_types=1);

function filterEvents(array $events, string $query): array
{
    $result = [];
    foreach ($events as $event) {
        $matches = $query === ''
            || stripos($event['title'], $query) !== false;
        if ($matches) {
            $result[] = $event;
        }
    }
    return $result;
}
