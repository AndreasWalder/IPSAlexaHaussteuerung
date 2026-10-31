<?php
declare(strict_types=1);
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';
function tGet(bool $value,string $reason):void {if(!$value)throw new RuntimeException($reason);}
$aliases=iah_vir_aliases([
    ['route'=>'pv','title'=>'Photovoltaik','synonyms'=>['Solarstrom']]
]);
$testCases=[
    [['Device'=>'jalousie'], '', 'jalousie'],
    [['Device'=>'licht'], '', 'licht'],
    [['Device'=>'lüftung'], '', 'lueftung'],
    [['Device'=>'heizung'], '', 'heizung'],
    [['Device'=>'Photovoltaik'], '', 'pv'],
    [['Action'=>'licht'], '', 'licht'],
    [['Action'=>'runter','Device'=>'jalousie'], '', null],
    [['Action'=>'aus','Device'=>'licht'], '', null],
    [['Device'=>'jalousie','Room'=>'wohnzimmer'], '', null],
    [['Device'=>'jalousie','Number'=>25], '', null],
    [['Device'=>'jalousie'], 'jalousie herunterfahren', null],
    [['Room'=>'wohnzimmer'], '', null],
];
foreach ($testCases as [$slots,$raw,$want]) {
    $input=['intent'=>'GetHaus','legacySlots'=>$slots,'rawText'=>$raw];
    $actual=iah_vir_legacy_gethaus_route($input,$aliases);
    tGet($actual===$want,'GetHaus: '.json_encode($slots).' -> '.var_export($actual,true)
        .' expected '.var_export($want,true));
    $decision=iah_vir_classify($input+['text'=>trim(implode(' ',array_values(array_filter($slots,'is_string')))),
        'routes'=>[['route'=>'pv','title'=>'Photovoltaik','synonyms'=>['Solarstrom']]]]);
    if($want!==null)tGet(($decision['route']??'')===$want
        && $decision['category']==='navigation','GetHaus classifier must navigate '.$want);
}
tGet(iah_vir_legacy_gethaus_route([
    'intent'=>'GetHaus','legacySlots'=>['Device'=>'jalousie'],'aplArgs'=>['GetHaus','licht']
],$aliases)===null,'APL event not mistaken for voice slot');
tGet(iah_vir_legacy_gethaus_route([
    'intent'=>'HomeAgentFrageIntent','legacySlots'=>['Device'=>'jalousie']
],$aliases)===null,'Dedicated questions not converted from legacy slots');

// Tatsächliche Inline-Fallback-Logik des Action-Skripts auswerten,
// damit GetHaus/Device auch bei fehlendem IPS-Helper lokal navigiert.
$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
$begin=strpos($action,'        // PRIO 1: GetHaus mit genau einem Device-/Action-Slot');
$end=strpos($action,'        $iahVoiceDecision = $iahVoiceRouter([',$begin===false?0:$begin);
tGet($begin!==false&&$end!==false&&$end>$begin,'Inline legacy fallback must exist');
$inline=substr($action,$begin,$end-$begin);
$simulate=static function(string $device,string $actionId='',string $room='',string $spoken='',
    array $aplArgs=[],array $domains=[]) use($inline):string {
    $action1Id=$actionId; $action1Val=$actionId;
    $device1Id=$device;$device1Val=$device;
    $room1Id=$room;$room1Val=$room;
    $object1Id=$object1Val=$szene1Id=$szene1Val=$alles1Id=$alles1Val='';
    $number1=$prozent1=null;
    $intentName='GetHaus';
    $APL=['args'=>$aplArgs];$IS_LAUNCH=$IS_BACK=$wantsExit=false;
    $rawUserText=$spoken;
    $rendererDomainMap=$domains;
    eval($inline);
    return $iahLegacyNavKey;
};
tGet($simulate('jalousie')==='jalousie','GetHaus single Jalousie must navigate without external helper');
tGet($simulate('licht')==='licht','GetHaus single Licht must navigate without external helper');
tGet($simulate('lüftung')==='lueftung','GetHaus single Lüftung must navigate without external helper');
tGet($simulate('jalousie','runter')==='','GetHaus action + device is not bare tile');
tGet($simulate('licht','aus')==='','GetHaus licht aus must not navigate');
tGet($simulate('licht','','wohnzimmer')==='','GetHaus room + device must not navigate');
tGet($simulate('jalousie','','','jalousie hoch')==='','GetHaus raw phrase must not be shortened');
tGet($simulate('jalousie','','','',['GetHaus','licht'])==='','APL clicks stay separate');
tGet($simulate('Photovoltaik','','','',[],[
    'pv'=>['route'=>'pv','title'=>'Photovoltaik','roomDomain'=>'pv']
])==='pv','Dynamic renderer title routes directly');
tGet(str_contains($action,'&& ($iahLegacyNavKey === \'\')'),
    'KI interception must explicitly reject local legacy navigation');
tGet(str_contains($action,'$homeAgentShouldRoute = $homeAgentDedicatedIntent')
    && str_contains($action,'&& $isHomeAgentQuestion'),
    'Legacy questions must require an actual question, not a lone tile');
$module=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/module.php');
tGet(str_contains($module,'patchActionScriptVoiceNavigation((int) $id)'),
    'Update must patch downstream UI navigation in existing Action scripts');
tGet(str_contains($module,'IAH_VOICE_NAVIGATION_BEGIN'),
    'Downstream UI patch must locate the bounded template section');
echo "PASS: GetHaus Licht/Jalousie/Lüftung safely navigate without KI or helper, action exceptions, dynamic tiles and Action update migration\n";
