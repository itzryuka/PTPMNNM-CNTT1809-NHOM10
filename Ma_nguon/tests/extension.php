<?php
require __DIR__.'/helper.php';
require __DIR__.'/../src/RegistrationStats.php';
require __DIR__.'/../src/RegistrationRepository.php';
try { $connection = db(); }
catch(Throwable $e) { fwrite(STDERR,"NOT_RUN: PostgreSQL unavailable\n"); exit(2); }
pg_query($connection,'BEGIN');
try {
    $id = 300000000 + random_int(1,100000000);
    queryParams($connection,'INSERT INTO events(id,title,category,capacity) VALUES ($1,$2,$3,$4)',[$id,'Stats fixture','test',5]);
    same(registrationStats($connection,$id),['event_id'=>$id,'title'=>'Stats fixture','total'=>0],'zero-included');
    $repo=new RegistrationRepository($connection);
    $repo->save($id,'A','a@example.test');
    same(registrationStats($connection,$id)['total'] ?? null,1,'one');
    $repo->save($id,'Duplicate','a@example.test');
    same(registrationStats($connection,$id)['total'] ?? null,1,'duplicate-no-change');
    same(registrationStats($connection,-1),[],'missing');
} catch(Throwable $e) { $failed++; echo "FAIL stats: " . $e->getMessage() . "\n"; }
finally { pg_query($connection,'ROLLBACK'); }
finish();
