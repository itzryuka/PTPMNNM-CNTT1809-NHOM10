<?php
require __DIR__ . '/helper.php';
require __DIR__ . '/../src/RegistrationPolicy.php';
$p = new RegistrationPolicy();
same($p->decide(10, 9, true, true), 'confirmed', 'available');
same($p->decide(10, 10, true, true), 'waiting', 'full-wait');
same($p->decide(10, 10, true, false), 'rejected', 'full-no-wait');
same($p->decide(10, 2, false, true), 'rejected', 'closed');
same($p->decide(0, 0, true, true), 'rejected', 'invalid-capacity');
same($p->decide(10, -1, true, true), 'rejected', 'invalid-count');
same($p->decide(10, 11, true, true), 'rejected', 'corrupt-over-count');
same($p->decide(1, 0, true, false), 'confirmed', 'first-seat');
same($p->decide(10, 9, true, false), 'confirmed', 'available-without-waiting-list');
finish();
