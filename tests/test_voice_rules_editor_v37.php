<?php
declare(strict_types=1);

/** V3.7 module integration: SystemConfiguration EDIT -> preview -> PROD. */
require __DIR__.'/test_system_configuration_v333.php';
function IPS_LogMessage(string $module,string $message):void
{
    if (isset($GLOBALS['iahTestLogSink'])) ($GLOBALS['iahTestLogSink'])($module,$message);
}
function ck37ui(bool $value,string $message):void{
    if(!$value)throw new RuntimeException('V3.7 UI '.$message);
}
$prod=['var'=>['Token'=>'PRIVATE','keep'=>'YES'],
    'navigation'=>['schemaVersion'=>1,'mode'=>'active','items'=>[]],
    'otherPluginValue'=>['keep'=>true]];
IPS_SetScriptContent(501,IAHSystemNavigationEditor::encode($prod));
IPS_SetScriptContent($editId,IAHSystemNavigationEditor::encode($prod));
$sourceProd=IPS_GetScriptContent(501);
$sourceCatalog=IPS_GetScriptContent(502);
$initialBackup=IPS_GetScriptContent($backupId);
$form=json_decode($module->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$html=json_encode($form,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
ck37ui(str_contains($html,'V3.7.1 – Szenen und eigene Alexa-Befehle')
    && str_contains($html,'VoiceRuleRows')
    && str_contains($html,'VoiceNewKind')
    && str_contains($html,'IAH_ImportLegacyVoiceRuleDrafts')
    && str_contains($html,'IAH_PublishVoiceRules'),
    'form must expose central rule table, new rules, legacy import and PROD approval');
ob_start();$module->PublishVoiceRules();$refused=ob_get_clean();
ck37ui(str_contains($refused,'aktuelle Vorschau')
    && IPS_GetScriptContent(501)===$sourceProd
    && IPS_GetScriptContent($backupId)===$initialBackup,
    'publishing without fresh preview must not mutate PROD');
ob_start();$module->ImportLegacyVoiceRuleDrafts();$draftMessage=ob_get_clean();
$edit=require IPS_GetScriptFile($editId);
$drafts=IAHVoiceRuleEngine::rules($edit);
ck37ui(str_contains($draftMessage,'DEAKTIVIERTE')
    && count($drafts)>=5
    && empty($drafts['wer_ist_andreas']['enabled'])
    && IPS_GetScriptContent(501)===$sourceProd
    && IPS_GetScriptContent(502)===$sourceCatalog,
    'import existing replies/old blinds as disabled EDIT-only templates');
ob_start();$module->CreateVoiceRule('Guten Abend','Guten Abend','answer');$created=ob_get_clean();
$edit=require IPS_GetScriptFile($editId);
$rules=IAHVoiceRuleEngine::rules($edit);
$newKeys=array_values(array_filter(array_keys($rules),static fn(string $key):bool=>
    str_starts_with($key,'voice_')));
ck37ui(str_contains($created,'angelegt')&&count($newKeys)===1
    && IPS_GetScriptContent(501)===$sourceProd,
    'creating custom rule must not change PROD');
$key=$newKeys[0];$rule=$rules[$key];
$getForm=json_decode($module->GetVoiceRuleEditForm($key),true,512,JSON_THROW_ON_ERROR);
ck37ui(str_contains(json_encode($getForm),'VoiceRuleSteps')
    && str_contains(json_encode($getForm),'VoiceRuleStepDevice')
    && str_contains(json_encode($getForm),'intentName')
    && str_contains(json_encode($getForm),'SelectScript')
    && str_contains(json_encode($getForm),'imageUrl'),
    'gear must provide editable aliases, intent, script, image and device actions');
$payload=[
    'key'=>$key,'revision'=>hash('sha256',serialize($rule)),
    'title'=>'Guten Abend','aliases'=>'Guten Abend, Schönen Abend',
    'kind'=>'answer','answer'=>'Einen schönen Abend!',
    'intentName'=>'GutenAbendIntent',
    'imageUrl'=>'https://example.org/alexa/abend.png','scriptId'=>0,
    'enabled'=>true,'confirmed'=>false
];
ob_start();$module->SaveVoiceRule(json_encode($payload,JSON_THROW_ON_ERROR));$saved=ob_get_clean();
$edit=require IPS_GetScriptFile($editId);
ck37ui(str_contains($saved,'gespeichert')
    && $edit['voiceRules']['items'][$key]['enabled']===true
    && $edit['voiceRules']['items'][$key]['aliases']===
        ['Guten Abend','Schönen Abend']
    && IPS_GetScriptContent(501)===$sourceProd,
    'editing aliases, answer and card URL applies to EDIT only');
ob_start();$module->PreviewVoiceRules();$preview=ob_get_clean();
ck37ui(str_contains($preview,'geprüft') && IPS_GetScriptContent(501)===$sourceProd,
    'preview must not activate rules');
$changed=IPS_GetScriptContent($editId);
IPS_SetScriptContent($editId,$changed."\n");
ob_start();$module->PublishVoiceRules();$stale=ob_get_clean();
ck37ui(str_contains($stale,'seit Vorschau verändert')
    && IPS_GetScriptContent(501)===$sourceProd,
    'changed EDIT must invalidate approved preview');
IPS_SetScriptContent($editId,$changed);
ob_start();$module->PreviewVoiceRules();ob_end_clean();
ob_start();$module->PublishVoiceRules();$published=ob_get_clean();
$prodNow=require IPS_GetScriptFile(501);
ck37ui(str_contains($published,'nach PROD veröffentlicht')
    && $prodNow['otherPluginValue']===$prod['otherPluginValue']
    && $prodNow['var']===$prod['var']
    && $prodNow['navigation']===$prod['navigation']
    && $prodNow['voiceRules']['items'][$key]['answer']==='Einen schönen Abend!'
    && IPS_GetScriptContent(502)===$sourceCatalog,
    'PROD publishes only rules preserving navigation, secrets and RoomsCatalog');
$backupNow=require IPS_GetScriptFile($backupId);
$found=false;
foreach($backupNow['snapshots'] as $snap){
    if(($snap['sourceScriptId']??0)===501
        && ($snap['sha256']??'')===hash('sha256',$sourceProd)
        && ($snap['phpSource']??'')===$sourceProd){$found=true;break;}
}
ck37ui($found,'PROD source must be backed up before activation');
$result=json_decode($module->ResolveVoiceRule(json_encode([
    'text'=>'Schönen Abend','intent'=>'HomeAgentFrageIntent',
    'aplEvent'=>false],JSON_THROW_ON_ERROR)),true);
ck37ui(($result['ok']??false) && $result['answer']==='Einen schönen Abend!'
    && $result['imageUrl']==='https://example.org/alexa/abend.png',
    'published central question should resolve via public Alexa module method');
$intent=json_decode($module->ResolveVoiceRule(
    '{"text":"","intent":"GutenAbendIntent","aplEvent":false}'),true);
ck37ui(($intent['ok']??false)===true
    && ($intent['answer']??'')==='Einen schönen Abend!',
    'slot-free Alexa intent must resolve verified rule without an utterance slot');
$apl=json_decode($module->ResolveVoiceRule('{"text":"Schönen Abend","aplEvent":true}'),true);
ck37ui(($apl['matched']??true)===false,'APL tap never triggers voice rule');
$repeat=$module->ReadAttributeString('VoiceRulesPublishPreview');
ck37ui($repeat==='', 'published preview cannot be reused');
echo "PASS V3.7 UI: edit rule, gear device selector, legacy drafts, hash-bound preview, backup, live rule resolution\n";
