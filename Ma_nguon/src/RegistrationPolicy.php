<?php
declare(strict_types=1);
final class RegistrationPolicy {
    public function decide(int $capacity, int $registered,
        bool $open, bool $waitingEnabled): string {
        if ($capacity <= 0 || $registered < 0 || $registered > $capacity) {
            return 'rejected';
        }
        if (!$open) {
            return 'rejected';
        }
        if ($registered < $capacity) {
            return 'confirmed';
        }
        return $waitingEnabled ? 'waiting' : 'rejected';
    }
}
