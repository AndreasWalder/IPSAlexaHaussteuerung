<?php
declare(strict_types=1);

/** V3.7.1: voice-only copyable JSON export, last trace, exact slot matching bridge. */
require __DIR__.'/test_voice_rules_generic_editor_v371.php';

function checkVoiceDiag(bool $pass,string $reason): void
{
    if(!$pass)throw new RuntimeException('V3.7.1 voice diagnostic: '.$reason);
}

$form=json_decode($module->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$formJson=json_encode($form,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
checkVoiceDiag(str_contains($formJson,'IAH_ExportVoiceRulesDiagnostic')
    && str_contains($formJson,'VoiceRulesDiagnosticJson'),
    'export button and copyable textbox visible in Alexa rule editor');

$editBefore=IPS_GetScriptContent($editId);
$prodBefore=IPS_GetScriptContent(501);
$backupBefore=IPS_GetScriptContent($backupId);
ob_start();$module->ExportVoiceRulesDiagnostic();$message=ob_get_clean();
$payload=$module->ReadAttributeString('VoiceRulesDiagnosticJson');
$data=json_decode($payload,true,512,JSON_THROW_ON_ERROR);
checkVoiceDiag(str_contains($message,'kopierbares JSON')
    && $data['schema']==='IAHVoiceRulesDiagnostics/1'
    && !empty($data['edit']) && !empty($data['prod'])
    && isset($data['summary'],$data['bridge'],$data['recentRecognition']),
    'readable JSON includes both collections and bridge status');
checkVoiceDiag(str_contains($payload,'Abendroutine')
    && !str_contains($payload,'PRIVATE')
    && !str_contains($payload,'otherPluginValue')
    && !str_contains($payload,'"Token"')
    && !str_contains($payload,'"navigation"')
    && !str_contains($payload,'"Passwort"'),
    'only voice rules exported, no token, navigation or unrelated config');
checkVoiceDiag(IPS_GetScriptContent($editId)===$editBefore
    && IPS_GetScriptContent(501)===$prodBefore
    && IPS_GetScriptContent($backupId)===$backupBefore,
    'export neither changes EDIT/PROD/backup nor executes actions');

$beforeTrace=count($data['recentRecognition']);
$noMatch=json_decode($module->ResolveVoiceRule(json_encode([
    'text'=>'Nicht konfigurierte Sprachphrase','intent'=>'GetHaus',
    'aplEvent'=>false
],JSON_THROW_ON_ERROR)),true);
checkVoiceDiag(empty($noMatch['matched']),'unknown phrase is not executed');
$found=json_decode($module->ResolveVoiceRule(json_encode([
    'text'=>'Starte die Abendroutine','intent'=>'GetHaus',
    'aplEvent'=>false
],JSON_THROW_ON_ERROR)),true);
checkVoiceDiag(!empty($found['matched']) && !empty($found['ok']),
    'PROD configured alias still matches after new export');

ob_start();$module->ExportVoiceRulesDiagnostic();ob_end_clean();
$data=json_decode($module->ReadAttributeString('VoiceRulesDiagnosticJson'),true,512,JSON_THROW_ON_ERROR);
$trace=$data['recentRecognition'];
checkVoiceDiag(count($trace)>$beforeTrace
    && ($trace[count($trace)-1]['ruleKey']??'')===$found['ruleKey']
    && ($trace[count($trace)-2]['reason']??'')==='no_exact_prod_phrase_or_custom_intent',
    'export contains recorded matched/unmatched rule resolution');
$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
checkVoiceDiag(str_contains($action,'VoiceRules.bridge_probe')
    && str_contains($action,'$iahRuleCandidates')
    && str_contains($action,'$iahDeviceSpoken'),
    'GetHaus Action+Device exact variants diagnosed and checked');
echo "PASS V3.7.1 voice diagnostic: safe JSON export, trace and Alexa slot bridge\n";
