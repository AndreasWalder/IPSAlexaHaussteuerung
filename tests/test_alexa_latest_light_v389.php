<?php
declare(strict_types=1);

require __DIR__.'/test_ai_archive_history_v353.php';

$now=time();
$rooms=[];
for ($i=0;$i<109;$i++) {
    $id=71000+$i;
    $GLOBALS['archiveVars'][$id]=['VariableType'=>0];
    $rooms['room'.$i]=['display'=>'Raum '.$i,'domains'=>['licht'=>['switches'=>[
        'main'=>['title'=>'Leuchte '.$i,'kiEnabled'=>true,
            'ai'=>['type'=>'light','version'=>1,'variables'=>['power'=>$id]]],
    ]]]];
    $GLOBALS['archiveValues'][$id]=[
        ['TimeStamp'=>$now-1,'Value'=>false],
        ['TimeStamp'=>$now-180+$i,'Value'=>true],
    ];
}
// A more recent false event must never be mistaken for an activation.
$GLOBALS['archiveValues'][71108]=[
    ['TimeStamp'=>$now-1,'Value'=>false],
    ['TimeStamp'=>$now-2,'Value'=>true],
];
$rooms['room0']['domains']['licht']['switches']['main']['kiEnabled']=false;
$GLOBALS['archiveValues'][71000]=[['TimeStamp'=>$now,'Value'=>true]];
$rooms['room0']['domains']['licht']['dimmers']['dim']=[
    'title'=>'Dimmer-Rückmeldung','kiEnabled'=>true,
    'ai'=>['type'=>'dimmer','version'=>1,'variables'=>['brightness'=>72000,'power'=>72000]],
];
$GLOBALS['archiveVars'][72000]=['VariableType'=>1];
$GLOBALS['archiveValues'][72000]=[['TimeStamp'=>$now,'Value'=>1]];
$noModel=static fn():never=>throw new LogicException('Expected verified local data');
foreach (['Wo wurde zuletzt das Licht eingeschaltet?', 'wie wurde zuletzt das licht eingeschaltet',
    'Welches Licht wurde zuletzt eingeschaltet?', 'In welchem Raum wurde zuletzt das Licht eingeschaltet?'] as $text) {
    $result=iah_rc_voice($text,['archive_control_id'=>17597],$rooms,$noModel);
    $query=$result['readQueries'][0]['result']??[];
    ck353(!empty($result['handled']) && ($query['matchedDevices']??0)===109
        && $query['mostRecent']['timestamp']===$now-2 && $query['mostRecent']['room']==='Raum 108'
        && str_contains($result['answer'],'Leuchte 108') && !$query['partial'],
        'finds the last logged ON beyond device 30, never disabled lights, latest OFF or dimmer level');
}
$noArchive=iah_rc_voice('Wo wurde zuletzt das Licht eingeschaltet?',[],$rooms,$noModel);
ck353(str_contains($noArchive['answer'],'Archivverbindung'),'missing archive is explicit');
$partial=iah_archive_latest_light_on(iah_rc_index_catalog($rooms),17597,microtime(true)-1);
ck353($partial['partial'] && $partial['mostRecent']===null,'bounded scan reports incomplete coverage');
echo "PASS V3.8.9: Alexa Wo/Wie disambiguation and latest archived light across all rooms\n";
