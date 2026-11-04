<?php
declare(strict_types=1);

/** V3.4.5: KI plant Abfragen statt für jede Frage eine eigene PHP-Regel. */
function ck345(bool $ok,string $msg):void {
    if (!$ok) throw new RuntimeException($msg);
}
function IPS_ScriptExists(int $id):bool {return $id===345;}
function IPS_GetScriptFile(int $id):string {return $GLOBALS['v345CatalogFile'];}
function IPS_VariableExists(int $id):bool {return isset($GLOBALS['v345Types'][$id]);}
function IPS_GetVariable(int $id):array {
    return ['VariableType'=>$GLOBALS['v345Types'][$id],
            'VariableChanged'=>$GLOBALS['v345Changed'][$id]??0];
}
function HasAction(int $id):bool {return false;}
function RequestAction(int $id,mixed $value):bool { $GLOBALS['v345Writes'][]=[$id,$value];return true; }
function GetValue(int $id):mixed {return $GLOBALS['v345Values'][$id];}
function IPS_LogMessage(string $channel,string $message):void {}

$GLOBALS['v345Types']=[
    101=>2,102=>2,103=>2,104=>2,105=>2,199=>2,201=>2,204=>2,
    501=>0,502=>2,503=>0,701=>0,801=>2,802=>2
];
$GLOBALS['v345Values']=[
    101=>23.0,102=>27.0,103=>25.5,104=>19.0,105=>23.0,199=>40.0,
    201=>30.0,204=>22.0,
    501=>true,502=>60.0,503=>false,701=>true,801=>520.5,802=>180.5
];
$GLOBALS['v345Changed']=[101=>time()-1500,701=>time()-500];
$GLOBALS['v345Writes']=[];
$dev=static fn(string $type,array $vars):array=>[
    'title'=>ucfirst($type),'kiEnabled'=>true,'kiReadOnly'=>true,
    'ai'=>['version'=>1,'type'=>$type,'variables'=>$vars]
];
$catalog=['rooms'=>[
    'wohnzimmer'=>['display'=>'Wohnzimmer','aliases'=>['Stube'],'domains'=>[
        'heizung'=>['wohnen'=>$dev('heating',['temperature'=>101,'targetTemperature'=>201])],
        'sensoren'=>['temperatur'=>[
            'sensor1'=>$dev('temperature',['temperature'=>102]),
            'sensor_duplicate'=>$dev('temperature',['temperature'=>101])]],
        'licht'=>['switches'=>['lampe'=>$dev('light',['power'=>501])],
            'dimmers'=>['led'=>$dev('dimmer',['brightness'=>502])]],
        'energie'=>['zaehler'=>['netz'=>$dev('energy',['powerWatts'=>801])]]
    ]],
    'schlafzimmer'=>['display'=>'Schlafzimmer','aliases'=>['Schlafen'],'domains'=>[
        'sensoren'=>['temperatur'=>['fuehler'=>$dev('temperature',['temperature'=>103])]],
        'licht'=>['switches'=>['lampe'=>$dev('light',['power'=>503])]]
    ]],
    'kueche'=>['display'=>'Küche','domains'=>[
        'heizung'=>['alt'=>['title'=>'Heizung Alt','ist'=>104,'soll'=>204,
            'kiEnabled'=>true,'kiReadOnly'=>true]],
        'sensoren'=>['fenster'=>['nord'=>$dev('window',['open'=>701])]],
        'energie'=>['zaehler'=>['pumpe'=>$dev('energy',['powerWatts'=>802])]]
    ]],
    'balkon'=>['display'=>'Balkon','domains'=>[
        'information'=>['wetter'=>['station'=>$dev('weather',['temperature'=>199])]]
    ]]
]];
$GLOBALS['v345CatalogFile']=tempnam(sys_get_temp_dir(),'iah_v345_');
file_put_contents($GLOBALS['v345CatalogFile'],'<?php return '.var_export($catalog,true).';');

require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
try {
    $devices=iah_rc_index(345);
    ck345(count($devices)===12,'expected twelve accessible readings');
    $schema=iah_ai_schema($devices);
    ck345(($schema['types']['temperature']??0)===3,'shared schema temperature type');
    ck345(($schema['properties']['temperature']??0)>=6,'temperature capabilities from all typed/legacy devices');

    // Aus ALLEN IST-Temperaturen den waermsten Raum finden: Wohnzimmer
    // hat 23 und 27 Grad, der doppelt referenzierte Sensor zaehlt einmal.
    // Durchschnitt Wohnzimmer=25, Schlafzimmer=25.5 -> Schlafzimmer.
    $warm=iah_ai_query_house($devices,[
        'property'=>'temperature','operation'=>'max','group_by'=>'room',
        'indoor_only'=>true
    ],'Welcher Raum ist der wärmste?');
    ck345(($warm['ok']??false)===true,'warmest query must succeed');
    ck345(($warm['best']['name']??'')==='Schlafzimmer',
        'warmest room must use mean of distinct actual values: '.json_encode($warm));
    ck345((float)$warm['best']['value']===25.5,'highest current room temperature');
    ck345(($warm['groups']??0)===3,'outdoor weather station must not count');
    $living=array_values(array_filter($warm['rows'],
        static fn(array $row):bool=>($row['name']??'')==='Wohnzimmer'));
    ck345(count($living)===1 && (float)$living[0]['value']===25.0,
        'living room average must deduplicate same IPS object');
    $cold=iah_ai_query_house($devices,[
        'property'=>'temperature','operation'=>'min','group_by'=>'room','indoor_only'=>true
    ],'Welcher Raum ist der kälteste?');
    ck345(($cold['best']['name']??'')==='Küche'&&(float)$cold['best']['value']===19.0,
        'legacy heating temperature must be compared');
    $outdoor=iah_ai_query_house($devices,[
        'property'=>'temperature','operation'=>'max','group_by'=>'room'
    ],'Wo ist es am wärmsten?');
    ck345(($outdoor['best']['name']??'')==='Balkon','outdoor opt-in should work');
    $restricted=iah_ai_query_house($devices,[
        'property'=>'temperature','operation'=>'max','group_by'=>'room',
        'rooms'=>['Stube','Schlafen'],'indoor_only'=>true
    ],'Welche meiner zwei Räume sind wärmer?');
    ck345($restricted['groups']===2,'room alias and multi-room comparison');

    // Mehrere Geraetearten mit einer Eigenschaft statt Sonderlogik je Typ.
    $lights=iah_ai_query_house($devices,[
        'property'=>'activity','operation'=>'list','group_by'=>'device',
        'domain'=>'licht','condition'=>'active'
    ],'Welche Lichter sind eingeschaltet?');
    ck345($lights['totalCount']===2 && $lights['matchedReadings']===2,
        'on/off and dimmer feedback count must include two active lights');
    $off=iah_ai_query_house($devices,[
        'property'=>'activity','operation'=>'count','group_by'=>'none',
        'domain'=>'licht','condition'=>'inactive'
    ],'Wie viele Lichter sind aus?');
    ck345($off['totalCount']===1,'inactive lights count');
    $window=iah_ai_query_house($devices,[
        'property'=>'open','operation'=>'count','group_by'=>'none',
        'type'=>'window','condition'=>'eq','value'=>'offen'
    ],'Wie viele Fenster sind offen?');
    ck345($window['totalCount']===1,'window status mapping');
    $power=iah_ai_query_house($devices,[
        'property'=>'powerWatts','operation'=>'sum','group_by'=>'none'
    ],'Wie hoch ist die gemeinsame Leistung?');
    ck345(abs((float)$power['best']['value']-701.0)<0.0001,'generic sum across devices');

    $wrong=iah_ai_query_house($devices,['property'=>'temperature','type'=>'Temperatur'], 'Test');
    ck345($wrong['ok']===false && $wrong['error']==='unknown_device_type',
        'unknown type must be explained rather than silently removing sensor readings');
    $missing=iah_ai_query_house($devices,['property'=>'humidity','operation'=>'max','group_by'=>'room'],'Test');
    ck345($missing['ok']===true && $missing['matchedReadings']===0
        && isset($missing['warning']),'no sensor is unknown, never zero');

    // Zeitstempel nur auf ausdrueckliche Nachfrage: keine Daten im Alltag.
    $plain=iah_ai_query_house($devices,['property'=>'open','type'=>'window'],'Fenster offen?');
    ck345(!str_contains(json_encode($plain),'lastChangedAt'),'no timestamp in normal response');
    $when=iah_ai_query_house($devices,['property'=>'open','type'=>'window'],'Wann wurde das Fenster zuletzt geändert?');
    ck345(str_contains(json_encode($when),'lastChangedAt'),'timestamp when explicitly requested');
    $tool=iah_rc_chat_payload('gpt-6-luna',[],[],0,true,true,'query_house');
    ck345($tool['tool_choice']['function']['name']==='query_house','prefer query_house for read questions');
    ck345($GLOBALS['v345Writes']===[],'AI query must never write any IPS value');

    $controller=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php');
    $installer=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/module.php');
    ck345(str_contains($controller,"'query_house'")&&str_contains($controller,'iah_ai_query_house('),
        'voice tool and dispatcher must use query_house');
    ck345(str_contains($installer,'IAH_HOUSE_QUERY_ENGINE_INSERT')
        && str_contains($installer,'HouseQueryEngine.php'),'installer must embed query engine');
    echo "PASS V3.4.5: KI generic property reasoning (warmest/coldest, sensors, lights, windows, sum, errors, timestamps), no writes\n";
} finally {
    unlink($GLOBALS['v345CatalogFile']);
}
