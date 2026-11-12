<?php
declare(strict_types=1);

/** Regression: real legacy images/targets, EDIT delete and no old fallbacks. */
require __DIR__.'/test_voice_rules_generic_editor_v371.php';

function ckLegacy371(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException('Legacy migration V3.7.1: '.$label);
}
// The module-editor fixture has no live IPS variable mock. Create three
// legitimate action/reading variables for this test, without hardware.
$GLOBALS['vrVars']=[
    60003=>['VariableType'=>1,'action'=>true],
    60005=>['VariableType'=>2,'action'=>false]
];
$GLOBALS['vrWrites']=[];
if(!function_exists('IPS_VariableExists')){
    function IPS_VariableExists(int $id):bool{return isset($GLOBALS['vrVars'][$id]);}
}
if(!function_exists('HasAction')){
    function HasAction(int $id):bool{return !empty($GLOBALS['vrVars'][$id]['action']);}
}
if(!function_exists('IPS_GetVariable')){
    function IPS_GetVariable(int $id):array{return $GLOBALS['vrVars'][$id]??[];}
}
if(!function_exists('RequestAction')){
    function RequestAction(int $id,mixed $value):bool {
        $GLOBALS['vrWrites'][]=[$id,$value];
        return true;
    }
}
$withGlobal=['global'=>['domains'=>['jalousie'=>['open_close_var'=>60003]]]];
$withGlobal['global']=['domains'=>['jalousie'=>['open_close_var'=>60003]]];
$old=IAHVoiceLegacyMigration::drafts($withGlobal,60005);
ckLegacy371(count($old)>=8,'all historical FAQ, blinds, sensor and robot responses included');
ckLegacy371($old['jalousien_oeffnen']['imageFile']==='JalousieTag.png'
    && $old['jalousien_schliessen']['imageFile']==='JalousieAbend.png'
    && $old['jalousien_oeffnen']['variableId']===60003
    && $old['jalousien_oeffnen']['variableValue']==='1'
    && $old['jalousien_schliessen']['variableValue']==='2',
    'real global blind target and two original card images migrate');
ckLegacy371(($old['aussentemperatur']['readVariableId']??null)===60005
    && str_contains($old['aussentemperatur']['imageUrl'],'istockphoto')
    && $old['aussentemperatur']['cardText']==='Die Außentemperatur beträgt {value}.',
    'original outside-temperature URL and dynamic reading migrate');
ckLegacy371(empty($old['jalousien_oeffnen']['enabled'])
    && empty($old['jalousien_oeffnen']['confirmed'])
    && $old['jalousien_oeffnen']['origin']==='legacy',
    'no migrated actuator enabled without user review');

$conf=[];
$conf=IAHVoiceRuleEngine::change($conf,'test_old_blind',$old['jalousien_oeffnen']);
$action=$old['jalousien_oeffnen'];
$action['enabled']=true;$action['confirmed']=true;
$conf=IAHVoiceRuleEngine::change($conf,'test_old_blind',$action);
$GLOBALS['vrWrites']=[];
$write=IAHVoiceRuleEngine::run($conf,$withGlobal,'Jalousien öffnen');
ckLegacy371($write['matched'] && $write['ok']
    && $GLOBALS['vrWrites']===[[60003,1]],
    'reviewed rule writes directly without invoking old renderer');

$sourceBefore=IPS_GetScriptContent(501);
ob_start();$module->PreviewLegacyVoiceRuleDrafts();$preview=ob_get_clean();
ckLegacy371(str_contains($preview,'ALT-Befehle gefunden')
    && IPS_GetScriptContent(501)===$sourceBefore,
    'migration preview never touches PROD');
$edit=require IPS_GetScriptFile($editId);
$legacyKeys=array_keys(array_filter(IAHVoiceRuleEngine::rules($edit),
    static fn(array $r):bool=>($r['origin']??'')==='legacy'));
ckLegacy371(count($legacyKeys)>=5,'existing migration added source-tagged drafts');
$first=$legacyKeys[0];
ob_start();$module->DeleteVoiceRule($first,str_repeat('f',64));$stale=ob_get_clean();
ckLegacy371(str_contains($stale,'zwischenzeitlich')
    && isset(IAHVoiceRuleEngine::rules(require IPS_GetScriptFile($editId))[$first]),
    'stale inline deletion rejected');
$real=IAHVoiceRuleEngine::rules($edit)[$first];
ob_start();$module->DeleteVoiceRule($first,hash('sha256',serialize($real)));$deleted=ob_get_clean();
ckLegacy371(str_contains($deleted,'gelöscht')
    && !isset(IAHVoiceRuleEngine::rules(require IPS_GetScriptFile($editId))[$first])
    && IPS_GetScriptContent(501)===$sourceBefore,
    'existing rule deleted from EDIT only');

ob_start();$module->DeleteLegacyVoiceRuleDrafts();$bulk=ob_get_clean();
$editAfter=require IPS_GetScriptFile($editId);
$remain=IAHVoiceRuleEngine::rules($editAfter);
ckLegacy371(str_contains($bulk,'deaktivierte importierte')
    && !array_filter($remain,static fn(array $r):bool=>($r['origin']??'')==='legacy'
        && empty($r['enabled']))
    && isset($remain[$found])
    && IPS_GetScriptContent(501)===$sourceBefore,
    'bulk removal does not delete own rules or PROD');

ob_start();$module->PreviewVoiceRules();$newPreview=ob_get_clean();
ckLegacy371(str_contains($newPreview,'geprüft'),'post-delete preview generated');
ob_start();$module->PublishVoiceRules();$published=ob_get_clean();
ckLegacy371(str_contains($published,'nach PROD veröffentlicht'),
    'deleted rules removed from PROD only after explicit publish');
$live=IAHVoiceRuleEngine::rules(require IPS_GetScriptFile(501));
ckLegacy371(!isset($live[$first]) && isset($live[$found]),
    'PROD removes deleted imported key and keeps independently authored rule');

$noFallback=json_decode($module->ResolveVoiceRule(
    '{"text":"Wer ist Andreas","intent":"GetHaus","aplEvent":false}'),true);
ckLegacy371(!empty($noFallback['matched']) && empty($noFallback['ok'])
    && ($noFallback['kind']??'')==='retired',
    'removed old phrase is blocked rather than executed by the old Action');

$ui=json_encode(json_decode($module->GetConfigurationForm(),true),JSON_UNESCAPED_UNICODE);
ckLegacy371(str_contains($ui,'IAH_PreviewLegacyVoiceRuleDrafts')
    && str_contains($ui,'IAH_DeleteLegacyVoiceRuleDrafts')
    && str_contains($ui,'IAH_DeleteVoiceRule('),
    'UI contains inventory preview, bulk-delete and list-row delete');
echo "PASS V3.7.1 legacy migration: images, real target, preview, deletion, PROD and no old fallback\n";
