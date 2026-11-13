<?php
declare(strict_types=1);

require __DIR__.'/test_last_alexa_interaction_v384.php';

function ck3815(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('V3.8.15: '.$message);
}

$GLOBALS['iahTestLogSink'] = static function (string $channel,string $message): void {
    if ($channel==='Alexa') $GLOBALS['iah3815Lines'][]=$message;
};
$summary = static function (): array {
    $last=end($GLOBALS['iah3815Lines']);
    ck3815(str_starts_with($last,'IAH.Request '),'module emits one compact request line');
    return json_decode(substr($last,strlen('IAH.Request ')),true,512,JSON_THROW_ON_ERROR);
};
$module->properties['VoiceRouterStoreTranscript']=true;
$details=[
    'requestType'=>'IntentRequest','deviceId'=>'test-echo-one','intent'=>'HausUndIntent',
    'spoken'=>'und inge','frageslot'=>'inge','slotPresent'=>true,'contextFollowup'=>true,
    'route'=>'Hausabfrage','status'=>'Rückfrage','answer'=>'Welchen Raum oder welche Etage meinst du?',
    'answerSource'=>'local','router'=>'query','reason'=>'possible_location_followup',
    'detail'=>'unclear_followup_location','durationMs'=>9,'backend'=>'module_read_only',
    'candidateCount'=>1,'candidates'=>['und inge'],'prodScriptId'=>36265,'prodRuleCount'=>9,
    'prodSourceSha256'=>str_repeat('e',64),'unknown'=>'must not be exported',
];
$record($details);
$first=$summary();
ck3815($first['alexa']==='Küche' && $first['spoken']==='und inge' && $first['frageslot']==='inge'
    && $first['answer']===$details['answer'] && $first['durationMs']===9 && $first['detail']===$details['detail']
    && $first['prodRevision']===str_repeat('e',12) && $first['tested']===['und inge']
    && !isset($first['deviceId'],$first['unknown']), 'summary combines the input, route, result and safe correlation');
$record(array_replace($details,['spoken'=>'Passwort lautet geheimeswort',
    'answer'=>'https://example.test/?one=x&token=private-token','frageslot'=>'192.168.2.11']));
$redacted=end($GLOBALS['iah3815Lines']);
ck3815(!str_contains($redacted,'geheimeswort') && !str_contains($redacted,'private-token')
    && !str_contains($redacted,'192.168.2.11') && !str_contains($redacted,'test-echo'), 'private patterns masked');
$lastSnapshot=$value('iahLastAlexaInteraction');
$record(array_replace($details,['requestType'=>'LaunchRequest','logOnly'=>true]));
ck3815($summary()['requestType']==='LaunchRequest' && $value('iahLastAlexaInteraction')===$lastSnapshot,
    'session event included in the export without replacing the previous voice snapshot');
$module->properties['VoiceRouterStoreTranscript']=false;
$record($details+['scopeName'=>'private room']);
$disabled=$summary();
ck3815($disabled['transcript']===false && !isset($disabled['spoken'],$disabled['answer'],$disabled['frageslot'],
    $disabled['tested'],$disabled['scopeName'],$disabled['slots'])
    && $disabled['intent']==='HausUndIntent','no spoken content in the new summary when the setting is disabled');

$log=tempnam(sys_get_temp_dir(),'iah3815');
try {
    $raw='13.11.2026 21:26:00 | 00000 | CUSTOM | Alexa | ';
    file_put_contents($log,$raw.$redacted."\n".str_repeat(
        $raw."SLOTSTRUCT_Action={\"value\":\"old duplicate\"}\n".$raw."APL.DS {\"large\":\"data\"}\n",650)
        .$raw."KIRoomsCatalog.openai.start {\"round\":1}\n");
    $tail=iah_alexalog_tail($log,500);
    ck3815(count($tail)===2 && str_contains($tail[0],'IAH.Request') && str_contains($tail[1],'openai.start'),
        'repeated debug lines do not evict the useful compact lines');
    json_decode(substr($tail[0],strpos($tail[0],'IAH.Request ')+strlen('IAH.Request ')),true,512,JSON_THROW_ON_ERROR);
} finally {
    unlink($log);
}
$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
ck3815(strpos($action,"if (isset(\$housePlan['answer']))")<strpos($action,'IAH_QueryAlexaHouseReadOnly(')
    && str_contains($action,"'followup_planner_unavailable'"),'clarifies before invoking the AI backend');
echo "PASS V3.8.15: compact request diagnostics, bounded log export, privacy and clarification guard\n";
