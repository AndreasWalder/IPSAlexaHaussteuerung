<?php
declare(strict_types=1);

/** V3.4.7 – Light status int enum can have a real IPS association profile. */
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
function chk347(bool $ok,string $why):void {
    if (!$ok) throw new RuntimeException('V3.4.7: '.$why);
}
$GLOBALS['v347values']=[11=>17,12=>35,13=>9,14=>1,15=>42,16=>72,17=>false,18=>0];
$GLOBALS['v347profile']=[
    11=>'Lighting.Enum',12=>'Lighting.Enum',13=>'Lighting.Enum',
    14=>'',15=>'',16=>'',17=>'',18=>''
];
function IPS_VariableExists(int $id):bool { return array_key_exists($id,$GLOBALS['v347values']); }
function IPS_GetVariable(int $id):array {
    return [
        'VariableType'=>is_bool($GLOBALS['v347values'][$id])?0:1,
        'VariableChanged'=>time()-120,
        'VariableCustomProfile'=>$GLOBALS['v347profile'][$id]??'',
        'VariableProfile'=>''
    ];
}
function IPS_GetVariableProfile(string $name):array {
    if ($name!=='Lighting.Enum') throw new RuntimeException('Unknown profile');
    return [
        'Associations'=>[
            ['Value'=>17,'Name'=>'Ein','Icon'=>'','Color'=>0],
            ['Value'=>35,'Name'=>'Aus','Icon'=>'','Color'=>0],
            ['Value'=>9,'Name'=>'Wartung','Icon'=>'','Color'=>0]
        ]
    ];
}
function GetValue(int $id):mixed {return $GLOBALS['v347values'][$id];}
function IPS_LogMessage(string $channel,string $message):void {}

$make=static function(string $key,int $var, array $stateMapping=[]):array {
    return ['id'=>$key,'name'=>$key,'room'=>'Flur','roomKey'=>'flur','floor'=>'EG',
        'domain'=>'licht','group'=>'switches','roomAliases'=>[],'synonyms'=>[],
        'read'=>$var,'write'=>0,'writable'=>false,'typed'=>true,
        'type'=>'light','reads'=>['status'=>$var],'writeRoles'=>[],
        'stateMapping'=>$stateMapping,'setpoint'=>0,'min'=>null,'max'=>null
    ];
};
$devices=[
    'einProfil'=>$make('einProfil',11),
    'ausProfil'=>$make('ausProfil',12),
    'wartung'=>$make('wartung',13),
    'integerEin'=>$make('integerEin',14),
    'mapping'=>$make('mapping',15,['on'=>42,'off'=>0]),
    'unknown'=>$make('unknown',16),
    'boolAus'=>$make('boolAus',17),
    'integerAus'=>$make('integerAus',18)
];
$on=iah_ai_query_house($devices,[
    'property'=>'activity','operation'=>'list','group_by'=>'device',
    'domain'=>'licht','condition'=>'active'
], 'Welche Lampen sind eingeschaltet?');
chk347(($on['matchedDevices']??0)===8,'all eight lights must be found by catalog');
chk347(($on['matchedReadings']??0)===3,
    '17=Ein by IPS profile, 1=Ein numeric, 42=Ein by explicit stateMapping');
chk347(($on['withProperty']??0)===6,'six confirmed readable feedback values');
chk347(($on['statusCoverage']['readable']??0)===6
    && ($on['statusCoverage']['unknown']??0)===2,
    'must show readable vs unknown instead of 0 active');
chk347(($on['statusCoverage']['on']??0)===3
    && ($on['statusCoverage']['off']??0)===3,
    'count known on and off independently');
chk347(($on['statusCoverage']['unrecognizedNumericStatus']['9']??0)===1
    && ($on['statusCoverage']['unrecognizedNumericStatus']['72']??0)===1,
    'unmapped status numeric codes must be visible in anonymized diagnostics');

$off=iah_ai_query_house($devices,[
    'property'=>'activity','operation'=>'count','group_by'=>'none',
    'domain'=>'licht','condition'=>'inactive'
], 'Wie viele Lampen sind aus?');
chk347(($off['matchedReadings']??0)===3,'35 by IPS profile, false and 0');
$unknown=iah_ai_query_house($devices,[
    'property'=>'activity','operation'=>'list','group_by'=>'device',
    'domain'=>'licht','name'=>'wartung','condition'=>'active'
], 'Ist die Wartungslampe eingeschaltet?');
chk347(($unknown['matchedReadings']??-1)===0
    && ($unknown['withProperty']??-1)===0,
    'profile label Wartung must not be interpreted as an');
$missingProfile=iah_ai_profile_feedback(16,72);
chk347($missingProfile===null,'arbitrary number 72 must not become on');
$source=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php');
chk347(str_contains($source,"'statusCoverage'") && str_contains($source,"'withProperty'"),
    'Voice logs must contain coverage and readable count');
echo "PASS V3.4.7: IPS status profiles + exact state mappings, 0/1, privacy-safe light coverage, unknown enumerations\n";
