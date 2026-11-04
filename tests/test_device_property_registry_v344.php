<?php
declare(strict_types=1);

/** V3.4.4: einzige Typ/Property-Registry, Livewerte, letzte Aenderung nur auf Frage. */
require dirname(__DIR__).'/RoomsCatalogConfigurator/libs/DeviceTypeRegistry.php';
$assert=static function(bool $pass,string $why):void{
    if(!$pass)throw new RuntimeException($why);
};
$defs=RCCDeviceTypeRegistry::definitions();
$assert(count($defs)===27,'expected 11 existing + 16 new device types');
$assert(!isset($defs['presence'])&&!isset($defs['co2']),'no presence/CO2 type');
$assert(isset($defs['window'],$defs['door'],$defs['weather'],$defs['water_leak'],$defs['network']), 'important types');
$fields=RCCDeviceTypeRegistry::fields();
foreach($defs as $name=>$entry){
    $assert($entry['label']!==''&&$entry['location']!==[], 'definition incomplete '.$name);
    foreach($entry['roles'] as $role){
        $assert(isset($fields[$role]),'missing editor field '.$name.'/'.$role);
        $assert(isset(RCCDeviceTypeRegistry::dataTypes()[$role]), 'missing IPS type '.$name.'/'.$role);
        $assert(isset(RCCDeviceTypeRegistry::propertyCaptions()[$role]), 'missing caption '.$name.'/'.$role);
    }
}
function IPS_ScriptExists(int $id):bool {return $id===31;}
function IPS_GetScriptFile(int $id):string {return $GLOBALS['v344Catalog'];}
function IPS_VariableExists(int $id):bool {return isset($GLOBALS['v344Meta'][$id]);}
function IPS_GetVariable(int $id):array {return $GLOBALS['v344Meta'][$id];}
function IPS_LogMessage(string $channel,string $message):void {}
function GetValue(int $id):mixed {return $GLOBALS['v344Values'][$id];}
function HasAction(int $id):bool {return false;}

$base=time()-1800;
$GLOBALS['v344Meta']=[
    101=>['VariableType'=>0,'VariableChanged'=>$base,'VariableUpdated'=>time()],
    102=>['VariableType'=>0,'VariableChanged'=>$base+100,'VariableUpdated'=>time()],
    103=>['VariableType'=>2,'VariableChanged'=>$base+200,'VariableUpdated'=>time()],
    104=>['VariableType'=>2,'VariableChanged'=>$base+300,'VariableUpdated'=>time()],
    105=>['VariableType'=>0,'VariableChanged'=>$base+400,'VariableUpdated'=>time()],
];
$GLOBALS['v344Values']=[101=>true,102=>false,103=>56.8,104=>23.5,105=>true];
$typed=static fn(string $type,array $roles):array=>[
    'title'=>'Geraet '.$type,'kiEnabled'=>true,'kiReadOnly'=>false,
    'ai'=>['version'=>1,'type'=>$type,'variables'=>$roles,'review'=>['status'=>'confirmed']]
];
$catalog=['rooms'=>['buero'=>['display'=>'Büro','domains'=>[
    'sensoren'=>[
        'fenster'=>['west'=>$typed('window',['open'=>101])],
        'tueren'=>['eingang'=>$typed('door',['open'=>102])],
        'luftfeuchtigkeit'=>['sensor'=>$typed('humidity',['humidity'=>103])]],
    'information'=>['wetter'=>['station'=>$typed('weather',['temperature'=>104,'rain'=>105])]]
]]]];
$GLOBALS['v344Catalog']=tempnam(sys_get_temp_dir(),'iah_v344');
file_put_contents($GLOBALS['v344Catalog'],'<?php return '.var_export($catalog,true).';');
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
try {
    $devices=iah_rc_index(31);
    $assert(count($devices)===4,'all new types loaded');
    foreach($devices as $device){
        $assert($device['writable']===false,'new types must be read-only even when user enabled writing');
        $state=iah_rc_state($device);
        $assert(!isset($state['lastChangedAt'])&&!isset($state['lastChangedProperty']),
            'last changed leaked into default state');
    }
    $window=$devices['buero/sensoren/fenster/west'];
    $door=$devices['buero/sensoren/tueren/eingang'];
    $assert(iah_rc_state($window)['properties']['open']==='offen','window bool meaning');
    $assert(iah_rc_state($door)['properties']['open']==='geschlossen','door bool meaning');
    $normal=iah_rc_tool($devices,'get_states',[],false,'Welche Fenster sind offen?');
    $assert($normal['matched']===1,'window question scope changed');
    $assert(!isset($normal['devices'][0]['lastChangedAt']),'timestamp on normal query');
    $when=iah_rc_tool($devices,'get_states',['type'=>'window'],false,
        'Wann wurde das Fenster zuletzt geändert?');
    $assert($when['matched']===1 && $when['devices'][0]['lastChangedProperty']==='open',
        'explicit timing question must identify status source');
    $assert($when['devices'][0]['lastChangedAt']===date('c',$base),
        'VariableChanged must win over VariableUpdated');
    $since=iah_rc_tool($devices,'get_states',['type'=>'door'],false,
        'Seit wann ist die Tür geschlossen?');
    $assert(isset($since['devices'][0]['lastChangedAt']), 'door since when');
    $inventory=iah_rc_tool($devices,'get_devices',[],false,'Liste die Geräte auf');
    $assert(!isset($inventory['devices'][0]['lastChangedAt']),'default device list leaks timestamp');
    $inventoryWhen=iah_rc_tool($devices,'get_devices',['type'=>'humidity'],false,
        'Wann wurde die Luftfeuchtigkeit zuletzt geändert?');
    $assert(isset($inventoryWhen['devices'][0]['lastChangedAt']), 'explicit get_devices timing');
    $assert(!iah_rc_wants_last_change('Wie warm ist es im Büro?'),'ordinary value is not a change question');
    $assert(iah_rc_wants_last_change('Wie lange ist das Fenster offen?'),'elapsed time request');
    $assert(iah_rc_wants_last_change('Wann war die letzte Änderung?'),'change request');
    // No fabricated time when IPS does not provide a timestamp.
    $GLOBALS['v344Meta'][101]['VariableChanged']=0;
    $missing=iah_rc_tool($devices,'get_states',['type'=>'window'],false,'Wann zuletzt?');
    $assert(!isset($missing['devices'][0]['lastChangedAt']),'unknown timestamp must stay unknown');
    // Installer must replace central Registry marker in copied IPS script.
    $module=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/module.php');
    $assert(str_contains($module,"$"."ident === 'kiHouseControllerScript'")
        && str_contains($module,'IAH_DEVICETYPE_REGISTRY_INSERT'),
        'installer must embed registry into runtime IP-Symcon script');
    echo "PASS: 27 central device types (without presence/CO2), mapped read-only, timestamps only on request\n";
} finally {
    unlink($GLOBALS['v344Catalog']);
}
