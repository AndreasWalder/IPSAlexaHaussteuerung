<?php
declare(strict_types=1);

require __DIR__.'/test_alexa_house_runtime_v388.php';
require_once dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';

$classify=static fn(string $text,string $intent='HomeAgentFrageIntent'):array=>
    iah_vir_classify(['text'=>$text,'intent'=>$intent,'requestType'=>'IntentRequest']);
foreach ([
    'In welchem Raum ist es am hellsten?',
    'In welchen Raum ist es am hellsten?',
    'In welchen Räumen ist es am dunkelsten?',
    'Und in welchem Raum ist es am wärmsten?',
    'Seit welchem Zeitpunkt ist das Fenster offen?',
    'Auf welcher Etage sind noch Lichter eingeschaltet?',
] as $phrase) {
    ck38($classify($phrase)['category']==='query' && IAHHouseQuestionContext::isNewQuestion($phrase)
        && IAHHouseQuestionContext::followUp($phrase)===null,'recognizes a new complete question: '.$phrase);
}
ck38($classify('Und im Obergeschoss?')['reason']==='possible_location_followup'
    && IAHHouseQuestionContext::followUp('Und im Obergeschoss?')==='obergeschoss',
    'location-only follow-up is still recognized');
ck38($classify('Schalte das Licht im Büro aus')['category']==='action'
    && $classify('Licht')['category']==='navigation','navigation and action are not turned into questions');

$sensor=static fn(string $name,int $id,bool $enabled=true):array=>[
    'title'=>$name,'kiEnabled'=>$enabled,
    'ai'=>['type'=>'illuminance','version'=>1,'variables'=>['illuminance'=>$id]],
];
$catalog['wohnzimmer']['domains']['licht']['status']=[
    'vorn'=>$sensor('Wohnzimmer vorn',840),
    'hinten'=>$sensor('Wohnzimmer hinten',841),
    'duplicate'=>$sensor('Wohnzimmer doppelt',841),
];
$catalog['buero']['domains']['licht']['status']=['lux'=>$sensor('Büro',842)];
$catalog['oben']['domains']['licht']['status']=['lux'=>$sensor('Oben',843)];
$catalog['wohnzimmer']['domains']['licht']['status']['disabled']=$sensor('Nicht freigegeben',844,false);
$catalog['garten']=['display'=>'Garten','domains'=>['licht'=>['status'=>['lux'=>$sensor('Außen',845)]]]];
$catalog['laptop']['domains']['licht']['status']=['test'=>$sensor('Test',846)];
foreach ([840=>20.0,841=>40.0,842=>32.5,843=>0.0,844=>500.0,845=>4000.0,846=>10000.0] as $id=>$value) {
    $GLOBALS['preview38Variables'][$id]=['VariableType'=>2];
    $GLOBALS['preview38Values'][$id]=$value;
}
IPS_SetScriptContent(502,IAHSystemNavigationEditor::encode($catalog));
$question='In welchem Raum ist es am hellsten?';
$expected='Am hellsten laut Lux-Sensoren: Büro mit 32,5 Lux.';
$comparison=iah_rc_voice($question,[],$catalog,$noNetwork);
$query=$comparison['readQueries'][0]['result'];
ck38($comparison['answer']===$expected && $query['property']==='illuminance'
    && $query['operation']==='max' && $query['groupBy']==='room' && $query['matchedReadings']===4,
    'uses actual indoor lux, per-room mean and deduplication, excludes private, outdoor and test sensors');
ck38(iah_rc_voice('Wo ist es am dunkelsten?',[],$catalog,$noNetwork)['answer']
    ==='Am dunkelsten laut Lux-Sensoren: oben mit 0 Lux.',
    'zero Lux is a valid measurement, not an unknown sensor');
$calls=0;
$wording=static function(array $payload)use(&$calls,$expected):array{
    $calls++;
    $data=json_decode($payload['messages'][1]['content'],true,512,JSON_THROW_ON_ERROR);
    ck38($data['istwerte']===$expected && !isset($payload['tools']),'AI only receives read result for wording');
    return ['choices'=>[['message'=>['content'=>'Im Büro ist es mit 32,5 Lux am hellsten.']]]];
};
$ai=iah_rc_voice($question,['api_key'=>'mock','prefer_ai'=>true],$catalog,$wording);
ck38($calls===1 && $ai['answerSource']==='ai_verified_feedback' && str_contains($ai['answer'],'Büro'),
    'AI phrases the answer using the real measured winner');
$wrong=static fn():array=>['choices'=>[['message'=>['content'=>'Im Wohnzimmer ist es mit 32,5 Lux am hellsten.']]]];
ck38(iah_rc_voice($question,['api_key'=>'mock','prefer_ai'=>true],$catalog,$wrong)['answer']===$expected,
    'AI cannot replace the measured room with a different room');
$session=str_repeat('d',32);
$test=static fn(string $text):array=>json_decode($module->GetAlexaVoiceTest($text,'PROD',$session),true,512,JSON_THROW_ON_ERROR);
ck38($test($question)['answer']===$expected,'text test reaches house questions without editing Alexa intents');
$follow=$test('Und im Obergeschoss?');
ck38($follow['questionScope']['rooms']===['oben'] && str_contains($follow['answer'],'0 Lux'),
    'next follow-up keeps the question and narrows the sensor comparison');
$live=json_decode($module->QueryAlexaHouseReadOnly($question,502,'null'),true,512,JSON_THROW_ON_ERROR);
ck38($live['answer']===$expected,'live module entry and preview agree');
echo "PASS V3.8.9: prepositional house questions, measured Lux comparison and AI wording\n";
