<?php
require __DIR__.'/helper.php';
require __DIR__.'/../src/RegistrationRepository.php';
try { $connection = db(); }
catch (Throwable $e) { fwrite(STDERR,"NOT_RUN: PostgreSQL unavailable or unconfigured\n"); exit(2); }
// All test data is inserted in a transaction, then rolled back.
if (!pg_query($connection,'BEGIN')) { exit(2); }
try {
    $eventId = 100000000 + random_int(1,100000000);
    queryParams($connection,
        'INSERT INTO events(id,title,category,capacity) VALUES ($1,$2,$3,$4)',
        [$eventId,'Integration fixture','test',5]);
    $repo = new RegistrationRepository($connection);
    same($repo->save($eventId,"O'Neil",'A@example.test'),'CREATED','create');
    same($repo->save($eventId,'Again',' a@example.test '),'DUPLICATE','normalized-duplicate');
    same(count($repo->listForEvent($eventId)),1,'one-row');
    same($repo->save($eventId,"<b>Test</b>",'b@example.test'),'CREATED','literal-data');
    same(count($repo->listForEvent($eventId)),2,'two-people');
    same($repo->listForEvent($eventId)[0]['name'],"O'Neil",'quote-preserved');
    same($repo->listForEvent(-1),[],'empty-event');
} catch (Throwable $e) {
    $failed++; echo 'FAIL integration: ' . $e->getMessage() . "\n";
} finally { pg_query($connection,'ROLLBACK'); }
finish();
