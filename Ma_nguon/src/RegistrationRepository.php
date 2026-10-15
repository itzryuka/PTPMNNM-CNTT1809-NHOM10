<?php

declare(strict_types=1);
require_once __DIR__ . '/Database.php';
final class RegistrationRepository
{
    public function __construct(private PgSql\Connection $connection) {}
    public function save(int $eventId, string $name, string $email): string
    {
        $email = strtolower(trim($email));
        $name = trim($name);
        if (
            $eventId <= 0 || $name === '' || strlen($name) > 120
            || str_contains($name, "\0") || strlen($email) > 200
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            throw new InvalidArgumentException('REGISTRATION_INVALID');
        }
        $result = queryParams(
            $this->connection,
            'INSERT INTO registrations (event_id,name,email) VALUES ($1,$2,$3)
             ON CONFLICT (event_id,email) DO NOTHING RETURNING id',
            [$eventId, $name, $email]
        ); 
        
        return pg_num_rows($result) === 1 ? 'CREATED' : 'DUPLICATE';
    }
    public function listForEvent(int $eventId): array
    {
        $result = queryParams(
            $this->connection,
            'SELECT id,event_id,name,email FROM registrations WHERE event_id=$1 ORDER BY id',
            [$eventId]
        );
        return pg_fetch_all($result) ?: [];
    }
}
