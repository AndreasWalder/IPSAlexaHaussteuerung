<?php
declare(strict_types=1);
require dirname(__DIR__).'/IPSAlexaHaussteuerung/src/KICostAccounting.php';
function ok(bool $result,string $reason):void {if(!$result)throw new RuntimeException($reason);}
function near(float $a,float $b,string $reason):void {ok(abs($a-$b)<1e-10,$reason.": $a vs $b");}
$prices=iah_ki_price_table();
$usage=['model'=>'gpt-4.1-mini-2025-04-14','usage'=>[
    'prompt_tokens'=>5000,'completion_tokens'=>200,'prompt_tokens_details'=>['cached_tokens'=>1000]
]];
$u=iah_ki_usage_row($usage,$prices);
near($u['costUsd'],(4000*0.4+1000*0.1+200*1.6)/1e6,'price with cached tokens');
ok($u['priced']&&$u['measured']&&$u['totalTokens']===5200,'usage parsing');
$unknown=iah_ki_usage_row(['model'=>'new-future-model','usage'=>$usage['usage']],$prices);
ok(!$unknown['priced']&&$unknown['costUsd']===0.0,'unknown model must not claim priced');
$store=[];
$now=new DateTimeImmutable('2026-10-30T23:50:00+01:00');
function evt(array &$data,string $backend,string $id,string $type,array $more=[]):void {
    global $prices,$now;
    ok(iah_ki_apply_event($data,array_merge(['backend'=>$backend,'questionId'=>$id,'type'=>$type],$more),$prices,$now),"bad event $type");
}
evt($store,'RoomsCatalog','question-one-123','start',['model'=>'gpt-4.1-mini']);
evt($store,'RoomsCatalog','question-one-123','api',['callId'=>'one','response'=>$usage]);
evt($store,'RoomsCatalog','question-one-123','api',['callId'=>'one','response'=>$usage]); // retry dedup
evt($store,'RoomsCatalog','question-one-123','api',['callId'=>'two','response'=>$usage]);
evt($store,'RoomsCatalog','question-one-123','finish',['success'=>true]);
$c=iah_ki_section_snapshot($store,'RoomsCatalog',$now);
ok($c['total']['questions']===1&&$c['total']['apiCalls']===2,'two API calls counted once');
ok($c['total']['inputTokens']===10000&&$c['total']['cachedInputTokens']===2000,'tokens');
ok($c['last']['apiCalls']===2&&$c['last']['status']==='success','last question');
near($c['total']['costUsd'],2*$u['costUsd'],'combined answer cost');
evt($store,'HomeAgent','question-two-123','start',['model'=>'gpt-4.1-mini']);
evt($store,'HomeAgent','question-two-123','api',['callId'=>'one','response'=>['model'=>'unknown-model','usage'=>$usage['usage']]]);
evt($store,'HomeAgent','question-two-123','finish',['success'=>false]);
evt($store,'IntentParser','question-three-123','start',['model'=>'gpt-4.1-mini']);
evt($store,'IntentParser','question-three-123','api',['callId'=>'one','response'=>$usage]);
evt($store,'IntentParser','question-three-123','finish',['success'=>true]);
$all=iah_ki_section_snapshot($store,'Alle',$now);
ok($all['total']['questions']===3&&$all['total']['apiCalls']===4,'backend + parser totals');
ok($all['total']['callsWithoutPrice']===1,'unpriced request warning');
ok($all['total']['failures']===1,'failed question');
near($all['total']['costUsd'],3*$u['costUsd'],'sum no phantom fees');
$november=new DateTimeImmutable('2026-11-01T00:05:00+01:00');
$rollover=iah_ki_section_snapshot($store,'Alle',$november);
ok($rollover['today']['questions']===0&&$rollover['month']['questions']===0,'month/day rollover');
ok($rollover['total']['questions']===3,'lifetime across rollover');
ok(!iah_ki_apply_event($store,['type'=>'api','backend'=>'INVALID','questionId'=>'test-123456','callId'=>'1'],$prices,$now),'reject invalid backend');
echo "PASS: cached-token pricing, retry dedup, multiple rounds, backend separation, unknown model, fail, rollover\n";
