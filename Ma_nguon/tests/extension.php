<?php
require __DIR__.'/helper.php';
require __DIR__.'/../src/DraftGuard.php';
$s=['draft'=>['data'=>['event_id'=>1],'created_at'=>1000]];
same(canComplete($s,1,1100,300),true,'valid');
same(canComplete([],1,1100,300),false,'missing');
same(canComplete($s,2,1100,300),false,'wrong-event');
same(canComplete($s,1,1299,300),true,'before-expiry');
same(canComplete($s,1,1300,300),false,'at-expiry');
same(canComplete($s,1,999,300),false,'future-created-at');
same(canComplete($s,1,1100,0),false,'invalid-ttl');
finish();
