<?php
declare(strict_types=1);
require __DIR__.'/test_irrigation_system_v3816.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/src/VoiceRuleEditorTrait.php';

// Replay the logged gate: the exact read question must not select the script bridge.
function IAH_QueryAlexaHouseReadOnly(...$args):string {throw new LogicException('Only capability detection in the route test');}
$action=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/resources/action_entry.php');
$start=strpos($action,'                    $iahReadOnlyQuery =');
$end=strpos($action,'                    if (isset($iahLastInteraction))',$start);
test($start!==false&&$end!==false,'Real Action backend gate is available');
$gate=substr($action,$start,$end-$start);
foreach (['Wie geht es meiner Bewässerung?','Wie ist meine Bewässerung?','Wann war die Gartenpumpe zuletzt eingeschaltet?','Heizstarts Rohrbegleitheizung heute'] as $spoken) {
    $homeAgentSpokenQuestion=$spoken;$iahVoiceContextDomain='bewaesserung';
    $iahPossibleFollowup=false;$homeAgentSimpleCommand=false;$homeAgentAllowCommands=true;$intentName='HausWieIntent';
    eval($gate);
    test($iahReadOnlyQuery&&$iahUseModuleQuery,'Irrigation status/history uses the module backend: '.$spoken);
}
$homeAgentSimpleCommand=true;$homeAgentAllowCommands=true;eval($gate);
test(!$iahUseModuleQuery,'Actual enabled commands retain their existing write-capable path');
$iahPossibleFollowup=true;eval($gate);
test($iahUseModuleQuery,'Follow-ups remain read-only even when command flags are enabled');

final class IrrigationReadOnlyModuleFixture
{
    use IAHVoiceRuleEditorTrait;
    public int $InstanceID=42;
    public function ReadPropertyBoolean(string $key):bool{return $key==='HomeAgentVoiceEnabled';}
    public function ReadPropertyInteger(string $key):int{return $key==='KIArchiveControlId'?17597:0;}
    public function ReadPropertyString(string $key):string{return '';}
    private function getObjectIDByIdentOrName(int $parent,string $ident,string $name):int{return $ident==='iahSettings'?1:10001;}
    private function getScriptContent(int $id):string{return IPS_GetScriptContent($id);}
}
$GLOBALS['irrigationReadonlyDir']=sys_get_temp_dir().'/iah-irrigation-readonly-'.bin2hex(random_bytes(6));
mkdir($GLOBALS['irrigationReadonlyDir'],0700);
function IPS_GetScriptFile(int $id):string{return $GLOBALS['irrigationReadonlyDir'].'/'.$id.'.php';}
$scripts[10001]='<?php return '.var_export($catalog,true).';';
file_put_contents(IPS_GetScriptFile(10001),$scripts[10001]);
register_shutdown_function(static function():void{
    @unlink(IPS_GetScriptFile(10001));@rmdir($GLOBALS['irrigationReadonlyDir']);
});
$module=new IrrigationReadOnlyModuleFixture();
$before=$requests;
foreach (['Wie geht es meiner Bewässerung?','Wie geht es mit der Bewässerung?','Wie geht es unserer Gartenbewässerung?','Geht es meiner Bewässerung gut?'] as $question) {
    $health=IAHIrrigationQuery::query($catalog,$question,'bewaesserung',17597,microtime(true)+4);
    test(($health['diagnostics']['operation']??'')==='irrigation_heater_health_summary',
        'Possessive/article variants produce the combined status: '.$question);
    ob_start();
    $reply=$module->QueryAlexaHouseReadOnly($question,10001,'null','bewaesserung');
    $noise=ob_get_clean();
    $result=json_decode($reply,true,512,JSON_THROW_ON_ERROR);
    test($noise===''&&$result['handled']&&$result['answerSource']==='irrigation'&&$result['displayRows']!==[],
        'Exact live module endpoint returns clean JSON and APL status rows without generated script stdout');
}
test($requests===$before,'Status queries and their archives never issue device actions');
// New irrigation root #52811 must resolve the verified power source as well
// as the legacy #41578, without accepting arbitrary unrelated roots.
$resolveHeater=new ReflectionMethod(IAHIrrigationQuery::class,'heaterMeter');
$meterNew=$resolveHeater->invoke(null,
    ['sourceRootId'=>52811,'points'=>[],'controllers'=>[]],
    [47232=>['AggregationActive'=>true]]);
$meterLegacy=$resolveHeater->invoke(null,
    ['sourceRootId'=>41578,'points'=>[],'controllers'=>[]],
    [47232=>['AggregationActive'=>true]]);
$meterUnrelated=$resolveHeater->invoke(null,
    ['sourceRootId'=>99999,'points'=>[],'controllers'=>[]],
    [47232=>['AggregationActive'=>true]]);
test(($meterNew['id']??0)===47232 && ($meterLegacy['id']??0)===47232
    && $meterUnrelated===null,
    'Heater meter is discovered at both irrigation roots but not unrelated devices');
// A single verified boolean heater feedback may provide a live status
// even if the energy meter is absent. It must never start an actuator.
$feedback=new ReflectionMethod(IAHIrrigationQuery::class,'heaterLiveWithFeedback');
$feedbackPoint=[
    'id'=>60108,'parent'=>61000,'ident'=>'Winterbetrieb','type'=>0,
    'role'=>'heaterStatus','enabled'=>true
];
$feedbackOn=$feedback->invoke(null,['points'=>[60108=>$feedbackPoint]],null,[]);
test(str_contains((string)($feedbackOn['speech']??''),'laut Statusrückmeldung ein'),
    'Verified boolean feedback can supplement missing meter');
$previous=$GLOBALS['values'][60108];
$GLOBALS['values'][60108]=false;
$feedbackOff=$feedback->invoke(null,['points'=>[60108=>$feedbackPoint]],null,[]);
$GLOBALS['values'][60108]=$previous;
test(str_contains((string)($feedbackOff['speech']??''),'laut Statusrückmeldung aus'),
    'Heater feedback off status is distinguished from missing meter');
test($requests===$before,'Heater feedback must be read-only');


// The old NLU fallback must receive the configured PROD catalog script,
// not rely on a hard-coded scripts/RoomsCatalog.php file.
$parserSource=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/KIIntentParser.php');
test(str_contains($parserSource,"rooms_catalog_script_id")
    && !str_contains($parserSource,"(__DIR__ . '/RoomsCatalog.php')"),
    'Legacy NLU uses configured production RoomsCatalog ID');
$actionSource=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/resources/action_entry.php');
test(str_contains($actionSource,"'rooms_catalog_script_id'=>(int)")
    && str_contains($actionSource,"KIFullDeviceDiagnosticsEnabled"),
    'Alexa route supplies PROD catalog and requires explicit device-log opt-in');
$formSource=json_decode(file_get_contents(
    __DIR__.'/../IPSAlexaHaussteuerung/form.json'),true,512,JSON_THROW_ON_ERROR);
test(str_contains(json_encode($formSource),"KIFullDeviceDiagnosticsEnabled"),
    'Diagnostic opt-in checkbox must be available in Alexa configuration');

echo "PASS logged irrigation backend regression, real module JSON replies, complete health rows, grammatical variants and read-only actions\n";
