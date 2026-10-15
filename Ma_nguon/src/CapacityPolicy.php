<?php
declare(strict_types=1);

final class CapacityPolicy
{
    public function canRegister(int $capacity, int $registered): bool
    {
        if ($capacity <= 0 || $registered < 0) {
            return false;
        }
        return $registered < $capacity;
    }
}
