<?php
declare(strict_types=1);

require __DIR__.'/test_house_query_bridge_v386.php';

$config=['api_key'=>'test-key','timeout_seconds'=>6];
$readTool=['tool_calls'=>[['id'=>'reading','function'=>[
    'name'=>'query_house',
    'arguments'=>'{"property":"temperature","operation":"max","group_by":"room","indoor_only":true}',
]]]];
$reply=static fn(array $message):array=>['choices'=>[['message'=>$message]]];

$seen=[];
$model=static function (array $payload,string $key,float $deadline) use (&$seen,$reply,$readTool):array {
    $seen[]=['request'=>$payload,'deadline'=>$deadline];
    return match(count($seen)){
        1=>$reply(['content'=>'']),
        2=>$reply($readTool),
        3=>$reply(['content'=>'Im Wohnzimmer hat es 21,4 Grad.']),
        default=>throw new LogicException('Unexpected extra retry')
    };
};
$result=iah_rc_voice('Welcher Raum ist am wärmsten?', $config, $catalog, $model);
ck38(($result['answer']??'')==='Im Wohnzimmer hat es 21,4 Grad.'
    && count($seen)===3 && count($result['readQueries'])===1
    && $seen[0]['deadline']<$seen[1]['deadline']
    && $seen[1]['deadline']===$seen[2]['deadline']
    && $seen[0]['request']['messages'][1]===$seen[1]['request']['messages'][1]
    && str_contains($seen[1]['request']['messages'][0]['content'],'Ein erneuter Versuch'),
    'a blank first response gets exactly one retry in the original time budget and keeps the question');

$calls=0;
$noAnswer=static function () use (&$calls):array { $calls++; return ['choices'=>[]]; };
$result=iah_rc_voice('Welcher Raum ist am wärmsten?', $config, $catalog, $noAnswer);
ck38(!$result['handled'] && $calls===2, 'two empty responses fail without a third try');

$calls=0;
$readThenBlank=static function () use (&$calls,$reply,$readTool):array {
    return $reply(++$calls===1?$readTool:['content'=>'<b> </b>']);
};
$result=iah_rc_voice('Welcher Raum ist am wärmsten?', $config, $catalog, $readThenBlank);
ck38($result['handled'] && $calls===3 && count($result['readQueries'])===1
    && str_contains($result['answer'],'Grad Celsius'),
    'one retry of the final answer, no repeated tool execution, valid measurements still usable');

$calls=0;
$unauthorized=static function () use (&$calls):never {
    $calls++;
    throw new RuntimeException('OpenAI-HTTP-Fehler, Status 401',401);
};
$result=iah_rc_voice('Welcher Raum ist am wärmsten?', $config, $catalog, $unauthorized);
ck38(!$result['handled'] && $calls===1, 'authentication errors do not use the retry budget');

$calls=0;
$temporary=static function () use (&$calls,$reply):array {
    if(++$calls===1)throw new RuntimeException('OpenAI-HTTP-Fehler, Status 503',503);
    return $reply(['content'=>'Dazu fehlen mir Messwerte.']);
};
$result=iah_rc_voice('Welcher Raum ist am wärmsten?', $config, $catalog, $temporary);
ck38($result['handled'] && $calls===2, 'temporary server errors get one retry');

$calls=0;
$result=iah_rc_voice('Schalte das Licht ein', $config+[
    'catalog_script_id'=>502,'allow_writes'=>true
], null, $noAnswer);
ck38(!$result['handled'] && $calls===1 && $GLOBALS['preview38Actions']===[],
    'a possible write command is not retried');

$calls=0;
$result=iah_rc_voice('Wie warm ist es im Wohnzimmer?', $config, $catalog, $noAnswer);
ck38(($result['localHouseQuery']??false) && $calls===0,
    'local answers never call the model or retry');

$events=[];
$retry=true;
iah_rc_model_message(['messages'=>[['role'=>'system','content'=>'Test']]],'test',microtime(true)+5,
    $temporary,static function (array $response,string $id) use (&$events):void { $events[]=$id; },0,$retry);
ck38($events===['round-0-retry'] && !$retry,
    'successful retry has a separate accounting ID and consumes the one retry');
echo "PASS V3.8.7: exactly one bounded model retry for read-only questions, no replay of actions\n";
