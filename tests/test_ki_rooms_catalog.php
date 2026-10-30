<?php
declare(strict_types=1);

$values=[100=>false,101=>false,200=>true,201=>false];
function IPS_ScriptExists(int $id): bool{return $id===999;}
function IPS_GetScriptFile(int $id): string{return $GLOBALS['catalogTestFile'];}
function IPS_VariableExists(int $id): bool{return isset($GLOBALS['varTypes'][$id]);}
function HasAction(int $id): bool{return in_array($id,[101,201],true);}
function IPS_GetVariable(int $id): array{return ['VariableType'=>$GLOBALS['varTypes'][$id]];}
function GetValue(int $id): mixed{return $GLOBALS['values'][$id];}
function RequestAction(int $id, mixed $value): bool{$GLOBALS['writes'][]=[$id,$value];return true;}
function IPS_LogMessage(string $channel,string $message):void{}
$GLOBALS['varTypes']=[100=>0,101=>0,200=>0,201=>0];
$GLOBALS['values']=$values;
$GLOBALS['writes']=[];
$GLOBALS['catalogTestFile']=tempnam(sys_get_temp_dir(),'catalog_');
$catalog=[
    'buero'=>['display'=>'Büro','floor'=>'EG','domains'=>[
        'licht'=>['switches'=>[
            'decke'=>['title'=>'Büro Deckenlicht','state'=>100,'toggle'=>101,'synonyms'=>'decke'],
            'exklusiv'=>['title'=>'Nicht in KI','state'=>100,'toggle'=>101,'kiEnabled'=>false],
        ]]
    ]],
    'kueche'=>['display'=>'Küche','floor'=>'EG','domains'=>[
        'licht'=>['switches'=>[
            'decke'=>['title'=>'Küche Deckenlicht','state'=>200,'toggle'=>201,
                'kiEnabled'=>true,'kiReadOnly'=>false],
        ]]
    ]]
];
file_put_contents($GLOBALS['catalogTestFile'],"<?php return ".var_export($catalog,true).";");
require __DIR__ . '/../IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
try{
    $devices=iah_rc_index(999);
    if(count($devices)!==2)throw new RuntimeException('Index/kiEnabled');
    if($devices['buero/licht/switches/decke']['writable'])throw new RuntimeException('Default read only');
    if(!$devices['kueche/licht/switches/decke']['writable'])throw new RuntimeException('Explicit writable');
    $states=iah_rc_tool($devices,'get_states',['domain'=>'licht','only_active'=>true],false,'welche Lichter an');
    if($states['matched']!==2||$states['activeBooleanDevices']!==1)throw new RuntimeException('State filtering');
    $no=iah_rc_tool($devices,'set_device',['deviceKey'=>'kueche/licht/switches/decke','value'=>true],false,'schalte Küche');
    if($no['ok']??false)throw new RuntimeException('Global lock failed');
    $no=iah_rc_tool($devices,'set_device',['deviceKey'=>'buero/licht/switches/decke','value'=>true],true,'schalte Büro');
    if($no['ok']??false)throw new RuntimeException('Row lock failed');
    $yes=iah_rc_tool($devices,'set_device',['deviceKey'=>'kueche/licht/switches/decke','value'=>true],true,'schalte Küche');
    if(!($yes['ok']??false)||$GLOBALS['writes']!==[[201,true]])throw new RuntimeException('Write failed');
    echo "PASS: catalog indexing, read lock, writable lock, states, action\n";
}finally{unlink($GLOBALS['catalogTestFile']);}
