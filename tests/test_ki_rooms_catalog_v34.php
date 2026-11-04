<?php
declare(strict_types=1);

/** V3.4: Typisierte Live-Werte, Raum-Aliase und fail-closed Schaltrechte. */
function IPS_ScriptExists(int $id): bool { return $id === 999; }
function IPS_GetScriptFile(int $id): string { return $GLOBALS['catalogFile']; }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['types'][$id]); }
function IPS_GetVariable(int $id): array { return ['VariableType'=>$GLOBALS['types'][$id]]; }
function HasAction(int $id): bool { return in_array($id,$GLOBALS['actions'],true); }
function GetValue(int $id): mixed { return $GLOBALS['values'][$id]; }
function RequestAction(int $id,mixed $value): bool { $GLOBALS['writes'][]=[$id,$value]; return true; }
function IPS_LogMessage(string $channel,string $message): void {}

$GLOBALS['types'] = [
    100=>0,101=>0, 200=>0,201=>0, 300=>1,301=>1,302=>1,303=>1,
    400=>2,401=>2, 500=>0, 600=>2,601=>2, 700=>0,710=>2,
    800=>0, 900=>0,901=>1,902=>0, 1000=>0,1001=>0, 1100=>1,1101=>1
];
$GLOBALS['values'] = [
    100=>true,101=>false,200=>true,201=>false,
    300=>52,301=>45,302=>1,303=>999,
    400=>21.5,401=>22.0,500=>true,600=>350.5,601=>2345.7,
    700=>true,710=>125.0,800=>true,900=>true,901=>75,902=>false,
    1000=>false,1001=>false,1100=>0,1101=>100
];
$GLOBALS['actions'] = [101,201,301,401,800,901,902,1001,1101];
$GLOBALS['writes'] = [];

$typed = static function(string $type,array $vars,array $other=[]): array {
    return array_merge(['title'=>ucfirst($type),'kiEnabled'=>true,'kiReadOnly'=>true,
        'ai'=>['version'=>1,'type'=>$type,'variables'=>$vars]],$other);
};
$confirmed = static function(array $dev): array {
    $dev['kiReadOnly']=false;
    $dev['ai']['review']=['status'=>'confirmed'];
    return $dev;
};
$catalog = ['rooms'=>[
    'buero'=>['display'=>'Büro','aliases'=>['Arbeitszimmer','Office'],'floor'=>'OG','domains'=>[
        'licht'=>['switches'=>[
            'legacy'=>['title'=>'Altlicht','state'=>100,'toggle'=>101,'kiReadOnly'=>false],
            'future'=>['title'=>'Unbekanntes Schema','state'=>100,'toggle'=>101,
                'ai'=>['version'=>2,'type'=>'light','variables'=>['power'=>100]]],
            'led'=>$confirmed($typed('light',['power'=>200,'control'=>201],['title'=>'LED']))
        ],'dimmers'=>[
            'tisch'=>$confirmed($typed('dimmer',['power'=>900,'brightness'=>901,'control'=>902]))
        ]],
        'jalousie'=>[
            'sued'=>$confirmed($typed('blind',['position'=>300,'targetPosition'=>301,'status'=>302,'action'=>303],
                ['ai'=>['version'=>1,'type'=>'blind',
                    'variables'=>['position'=>300,'targetPosition'=>301,'status'=>302,'action'=>303],
                    'stateMapping'=>['open'=>1,'closed'=>2],
                    'review'=>['status'=>'confirmed']]])),
            'gefährlich'=>$confirmed($typed('blind',['position'=>1100,'targetPosition'=>1101,'action'=>1101]))
        ],
        'heizung'=>['heizkreis'=>$confirmed($typed('heating',['temperature'=>400,'targetTemperature'=>401]))],
        'sensoren'=>['fenster'=>['nord'=>$typed('window',['open'=>500])],
            'bewegung'=>['flur'=>$typed('motion',['motion'=>700])]],
        'energie'=>['zaehler'=>['netz'=>$typed('energy',['powerWatts'=>600,'energy'=>601])]],
        'geraete'=>['switches'=>['steckdose'=>$typed('switch',['power'=>1000,'control'=>1001],
            ['kiReadOnly'=>false])]],
        'lueftung'=>['fans'=>['ventilator'=>$confirmed($typed('fan',['control'=>800]))]],
        'lichtstatus'=>['sensor'=>['lux'=>$typed('illuminance',['illuminance'=>710])]]
    ]],
    'global'=>['domains'=>['licht'=>['switches'=>['irrelevant'=>$typed('light',['power'=>200])]]]]
]];
$GLOBALS['catalogFile']=tempnam(sys_get_temp_dir(),'iah_v34_');
file_put_contents($GLOBALS['catalogFile'],'<?php return '.var_export($catalog,true).';');

require __DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
try {
    $devices=iah_rc_index(999);
    if(count($devices)!==12)throw new RuntimeException('Index must include 12 devices, no global UI');
    $room=iah_rc_find($devices,['room'=>'Arbeitszimmer']);
    if(count($room)!==12)throw new RuntimeException('Room alias not matched');
    $window=iah_rc_find($devices,['type'=>'window']);
    if(count($window)!==1)throw new RuntimeException('Typed sensor not found');
    $blind=$devices['buero/jalousie/jalousie/sued'];
    $state=iah_rc_state($blind);
    if($state['properties']['position']!==52 || $state['properties']['targetPosition']!==45
        || $state['properties']['status']!=='offen' || isset($state['properties']['action']))
        throw new RuntimeException('Blind readbacks or KNX command leaked into state');
    if(!$blind['writable'] || $blind['writeRoles']!==['targetPosition'=>301])
        throw new RuntimeException('Explicit confirmed blind target position');
    $fan=iah_rc_state($devices['buero/lueftung/fans/ventilator']);
    if($fan['state']!=='Istzustand nicht bestätigt' || $fan['properties']['commandValue']!=='an')
        throw new RuntimeException('Control-only fan falsely reported as running');
    $energy=iah_rc_state($devices['buero/energie/zaehler/netz']);
    if($energy['properties']!==['powerWatts'=>350.5,'energy'=>2345.7])
        throw new RuntimeException('Energy properties');
    $heater=iah_rc_state($devices['buero/heizung/heizung/heizkreis']);
    if($heater['properties']['temperature']!==21.5 || $heater['properties']['targetTemperature']!==22.0)
        throw new RuntimeException('Multi-property heating');
    if($devices['buero/geraete/switches/steckdose']['writable'])
        throw new RuntimeException('Unreviewed device must remain read-only');
    if($devices['buero/jalousie/jalousie/gefährlich']['writable'])
        throw new RuntimeException('Shared KNX action/target must be blocked');
    $no=iah_rc_tool($devices,'set_device',['deviceKey'=>$blind['id'],'value'=>45],false,'Jalousie auf 45');
    if($no['ok']??false)throw new RuntimeException('Global write lock');
    $no=iah_rc_tool($devices,'set_device',['deviceKey'=>$blind['id'],'property'=>'action','value'=>100],true,'Jalousie runter');
    if($no['ok']??false)throw new RuntimeException('Arbitrary KNX command should be denied');
    $no=iah_rc_tool($devices,'set_device',['type'=>'blind','value'=>70],true,'Jalousien');
    if($no['ok']??false)throw new RuntimeException('Ambiguous device must not actuate');
    $no=iah_rc_tool($devices,'set_device',['deviceKey'=>$blind['id'],'value'=>101],true,'Jalousie auf 101');
    if($no['ok']??false)throw new RuntimeException('Blind range');
    $many=[];
    for($i=0;$i<100;$i++) {
        $copy=$devices['buero/licht/switches/led'];
        $copy['id']='duplicate-'.$i;
        $copy['name']=str_repeat('Testgeraet',5).$i;
        $many[$copy['id']]=$copy;
    }
    foreach (['get_devices','get_states'] as $tool) {
        $result=iah_rc_tool($many,$tool,[],false,'Liste');
        $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (!$result['truncated'] || strlen($json)>=12000 || count($result['devices'])>=100)
            throw new RuntimeException('Unbounded tool answer: '.$tool);
    }
    $yes=iah_rc_tool($devices,'set_device',['deviceKey'=>$blind['id'],'property'=>'targetPosition','value'=>40],true,'Jalousie auf 40');
    if(!($yes['ok']??false))throw new RuntimeException('Confirmed targetPosition action');
    $yes=iah_rc_tool($devices,'set_device',['deviceKey'=>'buero/heizung/heizung/heizkreis','value'=>22.5],true,'Heizung auf 22,5');
    if(!($yes['ok']??false))throw new RuntimeException('Confirmed heating action');
    $no=iah_rc_tool($devices,'set_device',['deviceKey'=>'buero/heizung/heizung/heizkreis','value'=>45],true,'Heizung auf 45');
    if($no['ok']??false)throw new RuntimeException('Heating range');
    $yes=iah_rc_tool($devices,'set_device',['deviceKey'=>'buero/licht/dimmers/tisch','property'=>'brightness','value'=>60],true,'Dimmer 60');
    if(!($yes['ok']??false))throw new RuntimeException('Brightness action');
    $yes=iah_rc_tool($devices,'set_device',['deviceKey'=>'buero/licht/switches/legacy','value'=>false],true,'Altlicht aus');
    if(!($yes['ok']??false))throw new RuntimeException('Legacy action must remain supported');
    if($GLOBALS['writes']!==[[301,40],[401,22.5],[901,60],[101,false]])
        throw new RuntimeException('Unexpected RequestAction calls: '.json_encode($GLOBALS['writes']));
    echo "PASS: V3.4 typed multi-property, aliases, readback safety, write guards and legacy\n";
} finally {
    unlink($GLOBALS['catalogFile']);
}
