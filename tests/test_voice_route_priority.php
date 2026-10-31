<?php
declare(strict_types=1);

/**
 * Reproduziert den echten Fehler aus den Alexa-Meldungen:
 * GetHaus + Device="licht" -> VoiceRouter.navigate(licht)
 * -> alte $isPlainGetHaus-Regel traf "main" -> ROUTE_ALL(main_launch).
 *
 * Die Testfälle führen die konkreten Routing-Blöcke aus action_entry.php
 * aus, statt nur nach einem String im Quellcode zu suchen.
 */
function verifyRoute(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
}
$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
$start=strpos($action,'        $isPlainGetHaus = (');
$end=$start===false?false:strpos($action,'        );',$start);
verifyRoute($start!==false && $end!==false,'GetHaus plain fallback exists');
$isPlainBlock=substr($action,$start,$end+strlen('        );')-$start);

$routeStart=strpos($action,'        $ROUTES = [',$end);
$routeEnd=$routeStart===false?false:strpos($action,'        ];',$routeStart);
verifyRoute($routeStart!==false&&$routeEnd!==false,'Actual ROUTES definition exists');
$routeBlock=substr($action,$routeStart,$routeEnd+strlen('        ];')-$routeStart);

$detStart=strpos($action,'        // Route bestimmen',$routeEnd);
$detEnd=$detStart===false?false:strpos($action,'        // IAH_VOICE_ROUTE_PRIORITY_BEGIN',$detStart);
verifyRoute($detStart!==false&&$detEnd!==false,'Routing-selection and priority markers');
$selectBlock=substr($action,$detStart,$detEnd-$detStart);

$priorityEnd=strpos($action,'        // IAH_VOICE_ROUTE_PRIORITY_END',$detEnd);
verifyRoute($priorityEnd!==false,'Priority end marker');
$priorityBlock=substr($action,$detEnd,$priorityEnd+strlen('        // IAH_VOICE_ROUTE_PRIORITY_END')-$detEnd);

class TestGetHausRoutingRequest {
    public function __construct(private bool $launch) {}
    public function IsLaunchRequest():bool{return $this->launch;}
}
$simulate=function(string $voiceRoute,bool $voiceNavigation=true,
    bool $launch=false,?string $external=null,?string $explicitDomain=null)
    use($isPlainBlock,$routeBlock,$selectBlock,$priorityBlock):array {
    $request=new TestGetHausRoutingRequest($launch);
    $intentName='GetHaus';
    $action=$device=$room=$object=$szene=$alles='';
    $APL=['a1'=>null,'args'=>[]];
    $rawUserText='';
    $selected=$external;
    $navForce=$voiceNavigation && $voiceRoute!=='main_launch';
    $domain=$explicitDomain ?? ($navForce?$voiceRoute:null);
    $iahVoiceIsNavigation=$voiceNavigation;
    $iahVoiceNavKey=$voiceRoute;
    $rendererDomainMap=[];
    $rendererRoomDomainKeys=[];
    $__route=$external===null?null:'external';
    // Exakte mit dem gelieferten Modul ausgelieferte PHP-Routingblöcke.
    eval($isPlainBlock);
    eval($routeBlock);
    eval($selectBlock);
    $before=$__route;
    eval($priorityBlock);
    return ['fallback'=>$isPlainGetHaus,'before'=>$before,'route'=>$__route];
};
foreach(['licht','jalousie','heizung','lueftung','geraete','bewaesserung','einstellungen'] as $word) {
    $result=$simulate($word);
    $expected=$word==='einstellungen'?'settings':$word;
    verifyRoute($result['fallback']===false,"GetHaus/$word must not be plain welcome");
    verifyRoute($result['route']===$expected,"GetHaus/$word routed to ".$result['route']." instead of ".$expected);
    verifyRoute($result['route']!=='main_launch',"$word must not fall back to welcome");
}
// Simulate a future internal fallback that has no domain but voice route.
verifyRoute($simulate('licht',true,false,null,'unbekannt')['route']==='licht',
    'Explicit tile route overrides any incorrectly prioritized legacy fallback');
$home=$simulate('main_launch',true);
verifyRoute($home['route']==='main_launch','Explicit home navigation remains possible');
$plain=$simulate('',false);
verifyRoute($plain['fallback']===true && $plain['route']==='main_launch',
    'Plain GetHaus without a Device still opens main menu');
$launch=$simulate('',false,true);
verifyRoute($launch['route']==='main_launch','Alexa LaunchRequest still opens home');
$external=$simulate('energie',true,false,'energie');
verifyRoute($external['route']==='external','External pages retain their existing route');

$module=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/module.php');
verifyRoute(str_contains($module,'$oldPlain') && str_contains($module,'$newPlain'),
    'Existing user Action script migration contains the GetHaus fallback patch');
verifyRoute(str_contains($module,"'        // IAH_VOICE_ROUTE_PRIORITY_BEGIN'"),
    'Existing Action script gets the new route priority block on module update');
verifyRoute(str_contains($module,'patchActionScriptVoiceNavigation((int) $id)'),
    'Module update invokes action route migration');
verifyRoute(str_contains($action,"'VoiceRouter.renderRoute'"),
    'Final route is logged for diagnosis');
echo "PASS: GetHaus pure tiles render their target, Launch/home works, external pages unaffected, legacy Action migration exists\n";
