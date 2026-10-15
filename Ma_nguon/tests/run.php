<?php
require __DIR__ . '/helper.php';
require __DIR__ . '/../src/CapacityPolicy.php';
$p = new CapacityPolicy();
same($p->canRegister(10, 9), true, 'one-seat-left');
same($p->canRegister(10, 10), false, 'full');
same($p->canRegister(10, 11), false, 'over-capacity');
same($p->canRegister(0, 0), false, 'invalid-capacity');
same($p->canRegister(10, -1), false, 'invalid-count');
finish();
