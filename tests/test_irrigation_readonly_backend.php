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
echo "PASS logged irrigation backend regression, real module JSON replies, complete health rows, grammatical variants and read-only actions\n";
