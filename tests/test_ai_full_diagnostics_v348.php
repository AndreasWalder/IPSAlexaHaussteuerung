<?php
declare(strict_types=1);

/** V3.4.8 – all-purpose, local IPS diagnosis, model priorities, exclude test rooms. */
$GLOBALS['v348Values']=[101=>false,102=>1,103=>155.0,104=>true,105=>true];
$GLOBALS['v348Messages']=[];
function IPS_LogMessage(string $channel,string $message):void {
    $GLOBALS['v348Messages'][]=$message;
}
function IPS_ScriptExists(int $id):bool { return $id===98761; }
function IPS_GetScriptFile(int $id):string { return $GLOBALS['v348CatalogFile']; }
function IPS_VariableExists(int $id):bool { return array_key_exists($id,$GLOBALS['v348Values']); }
function IPS_GetVariable(int $id):array {
    return ['VariableType'=> $id===103?2:($id===102?1:0),
        'VariableProfile'=>$id===102?'ReverseLight':'',
        'VariableCustomProfile'=>'','VariableChanged'=>1700000000,
        'VariableUpdated'=>1700000010
    ];
}
function IPS_GetVariableProfile(string $name):array {
    if ($name!=='ReverseLight')throw new RuntimeException('unknown profile');
    return ['Associations'=>[
        ['Value'=>0,'Name'=>'Ein'],['Value'=>1,'Name'=>'Aus']
    ]];
}
function GetValue(int $id):mixed {return $GLOBALS['v348Values'][$id];}
function GetValueFormatted(int $id):string {
    return $id===102?'Aus':($id===101?'Aus':'Demo');
}
function IPS_GetObject(int $id):array {
    return ['ObjectName'=>'V'.$id,'ObjectParentID'=>9001];
}
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
function chk348(bool $b,string $reason):void {
    if (!$b)throw new RuntimeException($reason);
}
$leaf=static fn($type,$id,$mapping=[]):array=>[
    'title'=>'Geraet '.$id,'kiEnabled'=>true,'kiReadOnly'=>true,
    'ai'=>['version'=>1,'type'=>$type,'variables'=>[
        ($type==='illuminance'?'illuminance':'status')=>$id
    ],'stateMapping'=>$mapping]
];
$GLOBALS['v348CatalogFile']=sys_get_temp_dir().'/iah-v348-'.bin2hex(random_bytes(5)).'.php';
$rooms=[
    'wohnzimmer'=>['display'=>'Wohnzimmer','floor'=>'EG','domains'=>[
        'licht'=>[
            'switches'=>[
                'on'=> $leaf('light',101,['on'=>false,'off'=>true]),
                'off'=>$leaf('light',102)
            ],
            'status'=>['lux'=>$leaf('illuminance',103)]
        ]
    ]],
    'laptop'=>['display'=>'Laptop','domains'=>[
        'licht'=>['switches'=>['test'=>$leaf('light',104)]]
    ]],
    'testlabor'=>['display'=>'Testlabor','aiTestRoom'=>true,'domains'=>[
        'licht'=>['switches'=>['test'=>$leaf('light',105)]]
    ]]
];
file_put_contents($GLOBALS['v348CatalogFile'],'<?php return '.var_export($rooms,true).';');
try {
    $indexed=iah_rc_index(98761);
    chk348(count($indexed)===3,'only 2 lights and 1 lux may enter the real KI catalog');
    chk348(!in_array('Laptop',iah_ai_schema($indexed)['rooms'],true)
        && !in_array('Testlabor',iah_ai_schema($indexed)['rooms'],true),
        'Laptop/testlabor should not be normal rooms');
    chk348(iah_rc_is_test_room('laptop',[]) && iah_rc_is_test_room('testlabor',['aiTestRoom'=>true]),
        'testroom central selector');
    $args=['property'=>'activity','operation'=>'count','group_by'=>'device',
        'domain'=>'licht','condition'=>'active'];
    $result=iah_ai_query_house($indexed,$args,'Wie viele Lampen sind an?');
    chk348(($result['matchedReadings']??0)===1,
        'explicit inverted stateMapping false=on must be honored');
    chk348(($result['statusCoverage']['devices']??0)===2
        && ($result['statusCoverage']['on']??0)===1
        && ($result['statusCoverage']['off']??0)===1
        && ($result['statusCoverage']['unknown']??-1)===0,
        'lux-only sensors must not count among lamp states');
    $int=iah_ai_property_value(iah_rc_state($indexed['wohnzimmer/licht/switches/off']),
        'activity',$indexed['wohnzimmer/licht/switches/off']);
    chk348($int==='aus', 'Profile 1=Aus must win over default 1=Ein');
    iah_ai_log_device_diagnostics($indexed,'query_house',$args,$result);
    $log=implode("\n",$GLOBALS['v348Messages']);
    chk348(str_contains($log,'catalog.test_rooms_excluded')
        && str_contains($log,'diagnostics.query')
        && str_contains($log,'diagnostics.devices'),
        'diagnostics are logged for copy-paste without other tools');
    chk348(str_contains($log,'"id":101') && str_contains($log,'"id":102')
        && str_contains($log,'ReverseLight') && str_contains($log,'"mapping"'),
        'full source IDs, IP profiles, mappings and current values needed for diagnosis');
    chk348(!str_contains($log,'"id":103') && !str_contains($log,'"id":104')
        && !str_contains($log,'"id":105'), 'no irrelevant lux sensors or testroom in activity trace');
    echo "PASS V3.4.8: Laptop omitted, live profiles and inverted mappings respected, lux excluded, copyable per-device IPS diagnostics\n";
} finally {
    @unlink($GLOBALS['v348CatalogFile']);
}
