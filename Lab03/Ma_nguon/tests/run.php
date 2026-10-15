<?php
require __DIR__.'/helper.php';
require __DIR__.'/../src/Input.php';
require __DIR__.'/../src/EventFilter.php';
$events = require __DIR__.'/../data/events.php';
same(count(filterEvents($events,'')),3,'all');
same(count(filterEvents($events,'LAPTOP')),1,'keyword-ignore-case');
same(count(filterEvents($events,'xyz')),0,'not-found');
same(field(['name'=>['bad']], 'name'),'','array-input');
same(e('<b>'),'&lt;b&gt;','escape-html');
same(validateRegistration(['event_id'=>'1','name'=>"O'Neil",'email'=>'thukho@example.test'],$events)['errors'],[],'valid-form');
same(count(validateRegistration(['event_id'=>'999','name'=>'','email'=>'bad'],$events)['errors']),3,'invalid-form');
finish();
