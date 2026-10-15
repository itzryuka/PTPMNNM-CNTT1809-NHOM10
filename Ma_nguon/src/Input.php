<?php
declare(strict_types=1);
function field(array $input, string $key): string {
    $value = $input[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function validateRegistration(array $input, array $events): array {
    $eventId = filter_var(field($input, 'event_id'), FILTER_VALIDATE_INT);
    $name = field($input, 'name');
    $email = strtolower(field($input, 'email')); // Quy ước riêng của mẫu lớp học.
    $errors = [];
    if ($eventId === false || !in_array($eventId, array_column($events, 'id'), true)) {
        $errors[] = 'EVENT_INVALID';
    }
    if ($name === '' || strlen($name) > 120 || str_contains($name, "\0")) {
        $errors[] = 'NAME_INVALID';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
        $errors[] = 'EMAIL_INVALID';
    }
    return ['errors'=>$errors, 'data'=>[
        'event_id'=>(int)$eventId, 'name'=>$name, 'email'=>$email
    ]];
}
