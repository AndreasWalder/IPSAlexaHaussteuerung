<?php
declare(strict_types=1);

require __DIR__.'/test_voice_rule_preview_v38.php';
require_once dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';

function IAH_GetAlexaVoiceTest(int $id,string $text,string $source,string $conversation=''): string
{
    ck38($id===100,'unified test calls the selected Alexa instance');
    return $GLOBALS['preview38Alexa']->GetAlexaVoiceTest($text,$source,$conversation);
}

$catalog['wohnzimmer']['domains']['licht']['switches']['decke']['ai']['variables']['power']=810;
$catalog['wohnzimmer']['domains']['licht']['switches']['wand']=[
    'title'=>'Wandlicht','state'=>811,'kiEnabled'=>true,'kiReadOnly'=>true
];
$catalog['wohnzimmer']['domains']['licht']['switches']['privat']=[
    'title'=>'Privates Licht','state'=>811,'kiEnabled'=>false
];
$catalog['global']['domains']['musik']['tabs']['Radio']=['id'=>1234,'synonyms'=>['Musiksender']];
IPS_SetScriptContent(502,IAHSystemNavigationEditor::encode($catalog));
$before=$GLOBALS['sc333Scripts'];
$GLOBALS['preview38Values']=[810=>true,811=>false];
$GLOBALS['preview38Reads']=[];
$module->properties['HomeAgentVoiceEnabled']=true;
$test=static fn(string $text,string $source='PROD'):array=>
    json_decode($module->GetAlexaVoiceTest($text,$source),true,512,JSON_THROW_ON_ERROR);

$lights=$test('Welche Lichter sind eingeschaltet?','EDIT');
ck38($lights['ok'] && $lights['category']==='house_question'
    && $lights['catalogSource']==='PROD' && $lights['matchMethod']==='local_house_query'
    && str_contains($lights['answer'],'Deckenlicht') && !str_contains($lights['answer'],'Wandlicht')
    && $lights['readQueries'][0]['result']['statusCoverage']['on']===1
    && $lights['readQueries'][0]['result']['statusCoverage']['off']===1
    && $GLOBALS['preview38Reads']===[810,811],
    'lights use real published and allowlisted feedback without AI or an Intent');
$GLOBALS['preview38Values'][810]=false;
ck38($test('Wie viele Lampen sind an?')['readQueries'][0]['result']['matchedReadings']===0,
    'fresh feedback, no cache');
$GLOBALS['preview38Values'][810]=17;
$GLOBALS['preview38Values'][811]=35;
$unknown=$test('Welche Leuchten sind an?');
ck38(str_contains($unknown['answer'],'keine eindeutig lesbare')
    && $unknown['readQueries'][0]['result']['statusCoverage']['unknown']===2,
    'unknown status is not reported as off');
$GLOBALS['preview38Values']=[810=>true,811=>false];
$module->properties['HomeAgentVoiceEnabled']=false;
ck38(str_contains($test('Welche Lichter sind eingeschaltet?')['error'],'nicht aktiviert'),
    'disabled live house queries are reported');
$module->properties['HomeAgentVoiceEnabled']=true;

file_put_contents(IPS_GetScriptFile(502),'<?php $GLOBALS["v381CatalogExecuted"]=true; return [];');
try {
    ck38($test('Welche Lichter sind eingeschaltet?')['ok']&&!isset($GLOBALS['v381CatalogExecuted']),
        'house question never includes the on-disk catalog or trusts a stale file');
}finally{
    file_put_contents(IPS_GetScriptFile(502),$before[502]);
}
IPS_SetScriptContent(502,'<?php $GLOBALS["v381CatalogExecuted"]=true; return [];');
ck38(!$test('Welche Lichter sind eingeschaltet?')['ok']&&!isset($GLOBALS['v381CatalogExecuted']),
    'dynamic catalog is rejected without execution');
IPS_SetScriptContent(502,$before[502]);

ck38($test('Abends testen','EDIT')['configuredIntent']==='AbendPreviewIntent'
    && $test('Abends testen','EDIT')['plannedAction']['scriptId']===801,
    'voice alias tests a custom rule without supplying its configured Intent');
ck38($test('Wie ist der Abendmesswert?','EDIT')['answer']==='Aktuell ',
    'existing configured read rule uses its read target');
ck38($test('Licht')['route']==='licht' && $test('Visu')['route']==='kacheltest'
    && $test('Musiksender')['category']==='navigation' && $test('Zurück')['route']==='main_launch'
    && $test('Ende')['route']==='exit',
    'standard and custom navigation and session commands are simulated');
ck38($test('Schalte das Licht im Wohnzimmer ein')['plannedAction']['operation']==='ein'
    && $test('Licht aus')['category']==='action'
    && $test('Öffne die Jalousien')['category']==='retired'
    && $test('Frage')['category']==='clarify'
    && !$test('ein blauer Planet im Weltall')['matched'],
    'classic controls, retired shortcuts, clarification and unknown speech remain non-executing');
ck38(!IAHVoiceLegacyMigration::isRetiredPhrase('Wann wurden die Jalousien geschlossen?')
    && !IAHVoiceLegacyMigration::isRetiredPhrase('Wie hoch ist die Außentemperatur?')
    && IAHVoiceLegacyMigration::isRetiredPhrase('Jalousien schließen'),
    'retired control guard must not steal status and history questions');

$answerCalls=0;
$http=static function(array $request)use(&$answerCalls):array{
    $names=array_column(array_column($request['tools'],'function'),'name');
    ck38(!in_array('set_device',$names,true)&&!in_array('set_multiple',$names,true),
        'a preview must never offer write tools, even when allow_writes is true');
    $message=$answerCalls++===0?['tool_calls'=>[['id'=>'q1','type'=>'function','function'=>[
        'name'=>'query_house','arguments'=>json_encode(['property'=>'activity','operation'=>'list',
            'condition'=>'active','domain'=>'licht','room'=>'Wohnzimmer'])
    ]]]]:['content'=>'Im Wohnzimmer ist das Deckenlicht eingeschaltet.'];
    return ['choices'=>[['message'=>$message]]];
};
$answer=iah_rc_voice('Leuchtet im Wohnzimmer noch etwas?',['api_key'=>'fake-test-key',
    'allow_writes'=>true,'catalog_script_id'=>999999],$catalog,$http);
ck38($answer['handled']&&$answerCalls===2&&count($answer['readQueries'])===1
    && $answer['readQueries'][0]['result']['matchedReadings']===1
    && str_contains($answer['answer'],'Deckenlicht'),
    'read-only AI uses catalog snapshot and returns actual query results');
$rejected=iah_rc_voice('Schalte das Licht ein',['api_key'=>'fake-test-key','allow_writes'=>true],
    $catalog,static fn():array=>['choices'=>[['message'=>['tool_calls'=>[[
        'id'=>'invalid','function'=>['name'=>'set_device','arguments'=>'{}']
    ]]]]]]);
ck38(!$rejected['handled']&&$rejected['readQueries']===[],
    'even a model-requested write must not reach an actuator');

$module->ShowAlexaVoiceTest('Welche Lichter sind eingeschaltet?','PROD');
$rcc->TestAlexaVoice(100,'Welche Lichter sind eingeschaltet?','PROD');
ck38(str_contains($module->updates['VoiceTestSummary.caption'],'Deckenlicht')
    && str_contains($rcc->updates['VoiceTestSummary.caption'],'Deckenlicht')
    && str_contains($rcc->updates['VoiceTestResult.value'],'statusCoverage'),
    'both forms show the answer and the used device data');
$form=$module->GetConfigurationForm();
ck38(str_contains($form,'IAH_ShowAlexaVoiceTest')
    && !str_contains($form,'VoiceTestIntent') && !str_contains($form,'VoiceTestAi')
    && !str_contains(json_encode($rccPanel),'VoiceTestIntent'),
    'no Intent input or manual choice of KI vs own rule in the test UI');
ck38($before===$GLOBALS['sc333Scripts']&&$trace===$module->ReadAttributeString('VoiceRulesDiagnosticTrace')
    && $scriptCalls===$GLOBALS['vr371Calls']&&$GLOBALS['preview38Actions']===[],
    'no writes, script starts, live trace changes or catalog publications');
echo "PASS V3.8.1: one text field, real light feedback, read-only AI, navigation and dry-run actions\n";
