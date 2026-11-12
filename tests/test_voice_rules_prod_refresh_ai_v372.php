<?php
declare(strict_types=1);

/**
 * Exact reported incident 12.11.2026:
 * GetHaus/"wer bist du" was retired although eight valid active PROD rules
 * were present. Guard against regression without Alexa or a network call.
 */
require __DIR__.'/test_voice_rules_json_diagnostics_v371.php';

function ckVoice372(bool $pass,string $description):void
{
    if(!$pass)throw new RuntimeException('V3.7.2 voice fix: '.$description);
}
$prod=require IPS_GetScriptFile(501);
$legacy=IAHVoiceLegacyMigration::drafts();
foreach($legacy as $key=>$rule){
    // Fixture rules are safe text answers: don't perform physical operations.
    $rule['kind']='answer';
    $rule['answer']='Antwort '.$key;
    $rule['scriptId']=0;
    $rule['variableId']=0;
    $rule['readVariableId']=0;
    $rule['variableValue']='';
    $rule['steps']=[];
    $rule['enabled']=true;
    $rule['confirmed']=true;
    $prod=IAHVoiceRuleEngine::change($prod,$key,$rule);
}
IPS_SetScriptContent(501,IAHSystemNavigationEditor::encode($prod));
$rules=IAHVoiceRuleEngine::rules(require IPS_GetScriptFile(501));
ckVoice372(count($rules)>=8,'eight active published rules available');

$event=['text'=>'wer bist du','intent'=>'GetHaus','aplEvent'=>false,
    'skipLegacyFallback'=>true,'candidateIndex'=>0];
$r=json_decode($module->ResolveVoiceRule(json_encode($event,JSON_THROW_ON_ERROR)),true);
ckVoice372(!empty($r['matched']) && !empty($r['ok'])
    && ($r['ruleKey']??'')==='wer_bist_du'
    && ($r['kind']??'')==='answer',
    'GetHaus wer bist du resolves configured PROD instead of retired fallback');
$event['text']='Wer ist Andreas?';
$r=json_decode($module->ResolveVoiceRule(json_encode($event,JSON_THROW_ON_ERROR)),true);
ckVoice372(!empty($r['ok']) && ($r['ruleKey']??'')==='wer_ist_andreas'
    && str_contains((string)($r['answer']??''),'Antwort wer_ist_andreas'),
    'Wer ist Andreas? uses authored answer instead of generic AI response');

ob_start();$module->ExportVoiceRulesDiagnostic();ob_end_clean();
$dump=json_decode($module->ReadAttributeString('VoiceRulesDiagnosticJson'),true,512,JSON_THROW_ON_ERROR);
ckVoice372(($dump['selfTests']['wer_bist_du']['matchedKey']??'')==='wer_bist_du'
    && ($dump['selfTests']['wer_ist_andreas']['ok']??false)
    && ($dump['runtimeSource']['prodRulesRead']??0)>=8
    && ($dump['runtimeSource']['prodSchemaVersion']??0)===1
    && ($dump['recentRecognition'][count($dump['recentRecognition'])-1]['prodRuleCount']??0)>=8,
    'JSON reveals same PROD source and successful self-tests');
ckVoice372(($dump['recentRecognition'][count($dump['recentRecognition'])-1]['prodScriptId']??0)===501,
    'runtime trace identifies exact published script');

$ignored=['name'=>'Steuere Jalousien','kind'=>'variable','enabled'=>true,'confirmed'=>true,
    'aliases'=>['Jalousien an'],'variableId'=>12345,'variableValue'=>'true'];
$aiConfiguration=['voiceRules'=>['schemaVersion'=>1,'items'=>[
    'wer_ist_andreas'=>$rules['wer_ist_andreas'],
    'jalousie_aktion'=>$ignored,
    'roborock_reinigung'=>$rules['roborock_reinigung']
]]];
$available=IAHVoiceRuleAiMatcher::candidates($aiConfiguration,
    'Wer ist denn mein Programmierer?');
ckVoice372(isset($available['wer_ist_andreas'])
    && !isset($available['jalousie_aktion'])
    && !isset($available['roborock_reinigung']),
    'AI shortlist only contains allowed question/read rules, never actuators');

$selected=IAHVoiceRuleAiMatcher::choose($aiConfiguration,
    'Wer ist denn mein Programmierer?',
    static fn(array $allowed,string $spoken):array=>[
        'key'=>'wer_ist_andreas','confidence'=>0.96]);
ckVoice372($selected==='wer_ist_andreas',
    'strong semantic match selects only a published informational rule');
ckVoice372(IAHVoiceRuleAiMatcher::choose($aiConfiguration,
    'Wer ist denn mein Programmierer?',
    static fn(array $allowed,string $spoken):array=>[
        'key'=>'jalousie_aktion','confidence'=>1.0])===null,
    'even a malicious AI output cannot select a control rule');
ckVoice372(IAHVoiceRuleAiMatcher::choose($aiConfiguration,
    'Wer ist denn mein Programmierer?',
    static fn(array $allowed,string $spoken):array=>[
        'key'=>'wer_ist_andreas','confidence'=>0.72])===null,
    'low-confidence AI output cannot trigger an unreviewed response');
ckVoice372(IAHVoiceRuleAiMatcher::candidates($aiConfiguration,
    'Jalousien schliessen')===[],
    'control speech never reaches AI fallback');

$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
ckVoice372(str_contains($action,"'skipLegacyFallback'=>true")
    && str_contains($action,"'aiFallback'=>true")
    && str_contains($action,"'skipLegacyFallback'=>false"),
    'Action checks all exact candidates first, once for AI, old block last');
echo "PASS V3.7.2: published Alexa FAQ, 8 rules, AI read-only allowlist, safe fallback\n";
