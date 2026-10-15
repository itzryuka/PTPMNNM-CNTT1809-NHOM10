<?php
$lab = dirname(__DIR__);
require "$lab/templates/event-presentation.php";
$demo=require "$lab/data/ui-demo-events.php";
$assert=function(bool $v,string $label){if(!$v)throw new RuntimeException($label);};
$assert(count($demo)===6,'six events');$gallery=0;
foreach($demo as $id=>$event){
 $assert(str_starts_with($id,'demo-')&&$event['id']===$id,'demo namespace');
 foreach(['title','category','summary','intro','alt','start','end','timezone','venue','organizer','audience','prepare'] as $key)$assert(is_string($event[$key])&&$event[$key]!=='',"content $id $key");
 $assert(count($event['agenda'])>=3&&count($event['agenda'])<=5,'agenda');
 $assert(count($event['policy'])>=2&&count($event['faq'])>=2,'rules and FAQ');
 $assert(is_file($lab.'/public'.uiImage($event['image'])),'cover file');
 foreach($event['tickets'] as $ticket)$assert(is_int($ticket['price'])&&$ticket['price']>=0,'integer VND');
 if(count($event['gallery'])>=2)$gallery++;
 foreach($event['gallery'] as $image)$assert(is_file($lab.'/public'.uiImage($image['image'])),'gallery image');
}
$assert($gallery>=2,'two galleries');
$seats=uiSeats();$assert(count($seats)===96&&count(array_unique(array_column($seats,'id')))===96,'unique seats');
foreach(['vip'=>[24,8,16],'standard'=>[72,20,52]] as $tier=>[$total,$blocked,$open]){
 $subset=array_filter($seats,fn($s)=>$s['tier']===$tier);$available=array_filter($subset,fn($s)=>$s['available']);
 $assert(count($subset)===$total&&count($available)===$open&&$total-count($available)===$blocked,'seat totals '.$tier);
}
$assert($demo['demo-open-source']['tickets'][1]['price']*2===700000,'VIP total');
$assert(uiImage('../../secret')==='/assets/images/fallback.svg','image allowlist');
echo "DEMO_DATA_ASSERTIONS=PASS | EVENTS=6 | SEATS=96 | AVAILABLE=68 | VIP=16 | STANDARD=52\n";