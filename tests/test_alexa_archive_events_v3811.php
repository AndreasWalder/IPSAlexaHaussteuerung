<?php
declare(strict_types=1);
require __DIR__.'/test_ai_archive_history_v353.php';

$now=time();
$catalog=[];
for($i=0;$i<36;$i++){
    $id=73000+$i;
    $GLOBALS['archiveVars'][$id]=['VariableType'=>0];
    $GLOBALS['archiveValues'][$id]=[
        ['TimeStamp'=>$now-1,'Value'=>false],
        ['TimeStamp'=>$now-110+$i,'Value'=>true],
    ];
    $catalog['room'.$i]=['display'=>'Raum '.$i,'domains'=>[
        'sensoren'=>['bewegung'=>['m'=>['title'=>'Sensor '.$i,'kiEnabled'=>true,
            'ai'=>['type'=>'motion','version'=>1,'variables'=>['motion'=>$id]]]]],
    ]];
}
$GLOBALS['archiveValues'][73035][1]['TimeStamp']=$now-3;
$GLOBALS['archiveValues'][73034][1]['TimeStamp']=$now-3;
$catalog['aussen']=['display'=>'Außen','domains'=>['sensoren'=>['bewegung'=>[
    'door'=>['title'=>'Außen Tür','kiEnabled'=>true,'ai'=>[
        'type'=>'motion','version'=>1,'variables'=>['motion'=>73036]]],
]]]];
$GLOBALS['archiveVars'][73036]=['VariableType'=>0];
$GLOBALS['archiveValues'][73036]=[['TimeStamp'=>$now-2,'Value'=>true]];
$catalog['room0']['domains']['sensoren']['bewegung']['excluded']=[
    'title'=>'Ausgeschlossen','kiEnabled'=>false,
    'ai'=>['type'=>'motion','version'=>1,'variables'=>['motion'=>73037]],
];
$GLOBALS['archiveVars'][73037]=['VariableType'=>0];
$GLOBALS['archiveValues'][73037]=[['TimeStamp'=>$now,'Value'=>true]];

$catalog['wohnzimmer']=['display'=>'Wohnzimmer','domains'=>['licht'=>['switches'=>[
    'a'=>['title'=>'Decke','kiEnabled'=>true,'ai'=>['type'=>'light','version'=>1,'variables'=>['power'=>73100]]],
]]]];
$catalog['buero']=['display'=>'Büro','domains'=>['licht'=>['switches'=>[
    'b'=>['title'=>'Schreibtisch','kiEnabled'=>true,'ai'=>['type'=>'light','version'=>1,'variables'=>['power'=>73101]]],
]]]];
$GLOBALS['archiveVars'][73100]=$GLOBALS['archiveVars'][73101]=['VariableType'=>0];
$GLOBALS['archiveValues'][73100]=[['TimeStamp'=>$now-1,'Value'=>false],['TimeStamp'=>$now-6,'Value'=>true]];
$GLOBALS['archiveValues'][73101]=[['TimeStamp'=>$now-4,'Value'=>true]];
$cfg=['archive_control_id'=>17597];
$noModel=static fn():never=>throw new LogicException('Archive selection must not need an API');

$light='wie wurde im haus zuletzt das licht eingeschaltet';
$l=iah_rc_voice($light,$cfg,$catalog,$noModel);
ck353($l['readQueries'][0]['result']['operation']==='last_light_on'
    && $l['readQueries'][0]['result']['mostRecent']['room']==='Büro',
    'the actual live transcript with im Haus uses the specialized archive search');
$room=IAHHouseQuestionContext::scope('Wohnzimmer',$catalog);
$l=iah_rc_voice($light,$cfg+['question_scope'=>$room],$catalog,$noModel);
ck353($l['readQueries'][0]['result']['mostRecent']['name']==='Decke'
    && $l['readQueries'][0]['result']['mostRecent']['timestamp']===$now-6,
    'the same question after a room reply looks for ON, not the newer OFF');

$q='Wo wurde zuletzt Bewegung erkannt?';
$all=iah_rc_voice($q,$cfg,$catalog,$noModel);
$data=$all['readQueries'][0]['result'];
ck353($data['operation']==='last_motion' && $data['matchedDevices']===37
    && $data['mostRecent']['name']==='Außen Tür' && !$data['partial'],
    'the all-house result includes outdoor and candidates beyond the old 30-device limit');
foreach (['wie wurde zuletzt eine bewegung erkannt',
    'wie wurde im haus innen eine bewegung erkannt',
    'wie wurde außer außen noch eine bewegung bekannt',
    'Wo wurde zuletzt drinnen Bewegung erkannt?'] as $i=>$question) {
    $result=iah_rc_voice($question,$cfg,array_reverse($catalog,true),$noModel);
    $data=$result['readQueries'][0]['result'];
    ck353($data['area']===($i===0?'all':'indoor')
        && $data['mostRecent']['name']===($i===0?'Außen Tür':'Sensor 34')
        && $data['mostRecent']['timestamp']===$now-($i===0?2:3),
        'live ASR variant keeps historical meaning and stable ordering: '.$question);
}
$inside=iah_rc_voice($q,$cfg+['question_scope'=>['type'=>'motion_area','area'=>'indoor']],$catalog,$noModel);
ck353($inside['readQueries'][0]['result']['mostRecent']['name']==='Sensor 34',
    'indoor follow-up preserves historical event selection');
$root=dirname(__DIR__);
require_once $root.'/IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';
ck353(iah_vir_classify(['text'=>'Und innen?','intent'=>'GetHaus'])['reason']==='possible_location_followup'
    && IAHHouseQuestionContext::followUp('Und außer außen?')==='ausser aussen',
    'short indoor follow-ups reach the house-question planner');
foreach(['Wo ist gerade Bewegung?', 'Wie oft wurde Bewegung erkannt?',
    'Wo wurde gestern Bewegung erkannt?', 'Wie wurde die Bewegung erkannt?'] as $other)
    ck353(!IAHHouseQuestionContext::latestMotionQuestion($other),'do not reinterpret: '.$other);

$stamp=date('d.m.Y',$now-2);
$time=date('H:i:s',$now-2);
$calls=0;
$wording=static function(array $payload)use($stamp,$time,&$calls):array {
    $calls++;
    ck353(!isset($payload['tools']),'AI only formulates a verified event');
    return ['choices'=>[['message'=>['content'=>"Außen Tür in Außen meldete am $stamp um $time Uhr Bewegung."]]]];
};
$ai=iah_rc_voice($q,$cfg+['api_key'=>'mock','prefer_ai'=>true],$catalog,$wording);
ck353($calls===1 && $ai['answerSource']==='ai_verified_feedback','AI may formulate the correct archive result');
$wrong=static fn():array=>['choices'=>[['message'=>['content'=>
    "Außen Tür in Außen meldete am $stamp um ".implode(':',array_reverse(explode(':',$time))).' Uhr Bewegung.']]]];
if (implode(':',array_reverse(explode(':',$time)))!==$time)
    ck353(iah_rc_voice($q,$cfg+['api_key'=>'mock','prefer_ai'=>true],$catalog,$wrong)['answer']===$all['answer'],
        'AI cannot change the timestamp by reordering the same numbers');
echo "PASS V3.8.11: whole-house and scoped archived ON, deterministic indoor/outdoor motion, safe AI wording\n";
