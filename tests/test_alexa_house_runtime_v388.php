<?php
declare(strict_types=1);

require __DIR__.'/test_house_question_retry_v387.php';

$raw=json_decode($module->QueryAlexaHouseReadOnly('welche fenster sind offen',502,'null'),true,512,JSON_THROW_ON_ERROR);
ck38(!empty($raw['handled']) && str_contains($raw['answer'],'offen')
    && $raw['answerSource']==='local','live read-only module endpoint resolves the reported unscoped window question');
ck38(json_decode($module->QueryAlexaHouseReadOnly('welche fenster sind offen',801,'null'),true)['error']
    ==='house_query_catalog_not_available','module read path cannot include an unrelated script');
$oldNoApi=$raw['answer'];

$calls=0;
$wording=static function (array $request) use (&$calls,$oldNoApi):array {
    $calls++;
    ck38(!isset($request['tools']),'read facts have no tool calls or writes on the wording request');
    $payload=json_decode($request['messages'][1]['content'],true,512,JSON_THROW_ON_ERROR);
    ck38($payload['frage']==='welche fenster sind offen' && $payload['istwerte']===$oldNoApi,
        'AI gets the original question and only the locally read answer');
    return ['choices'=>[['message'=>['content'=>'Die Fensterkontakte melden: '.$oldNoApi]]]];
};
$ai=iah_rc_voice('welche fenster sind offen',['prefer_ai'=>true,'api_key'=>'mock'],$catalog,$wording);
ck38($calls===1 && $ai['answerSource']==='ai_verified_feedback' && !empty($ai['contextEligible'])
    && !empty($ai['readQueries'][0]['result']['closureCoverage']),'AI wording retains facts and follow-up context');

$invalid=static fn():array=>['choices'=>[['message'=>['content'=>preg_replace(
    '/\b(\d+) offen, (\d+) geschlossen\b/u','$1 geschlossen, $2 offen',$oldNoApi)
]]]];
$fallback=iah_rc_voice('welche fenster sind offen',['prefer_ai'=>true,'api_key'=>'mock'],$catalog,$invalid);
ck38($fallback['answer']===$oldNoApi && $fallback['answerSource']==='local_fallback',
    'same numbers assigned to wrong states cannot replace measured facts');
$calls=0;
$fallback=iah_rc_voice('welche fenster sind offen',['prefer_ai'=>true,'api_key'=>'mock'],$catalog,$noAnswer);
ck38($calls===2 && $fallback['answer']===$oldNoApi && $fallback['answerSource']==='local_fallback',
    'a failed model retry still gives a real answer when sensor data exists');

$disabled=iah_rc_voice('welche fenster sind offen',['prefer_ai'=>false,'api_key'=>'mock'],$catalog,$noNetwork);
ck38($disabled['answerSource']==='local','AI wording can be disabled without turning off house queries');

$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
ck38(str_contains($action,'$iahUseModuleQuery = $iahReadOnlyQuery')
    && str_contains($action,'IAH_QueryAlexaHouseReadOnly('),
    'real Alexa sends queries to the module runtime');
$template=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php');
ck38(!str_contains($template,'use ($_IPS)'),'generated helper never tries to capture the Symcon auto-global');

echo "PASS V3.8.8: live module route, AI wording with fixed sensor facts and local fallback\n";
