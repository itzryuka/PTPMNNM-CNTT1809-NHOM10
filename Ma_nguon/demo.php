<?php
require __DIR__ . '/src/CapacityPolicy.php';

$policy = new CapacityPolicy();
$available = $policy->canRegister(10, 9);
if ($available) {
    echo "AVAILABLE\n";
} else {
    echo "FULL\n";
}
