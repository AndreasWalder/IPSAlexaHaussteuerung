<?php
declare(strict_types=1);

/**
 * Regression: HomeAgentFrageIntent/AMAZON.SearchQuery darf trotz
 * veralteter UI-/APL-Flags nicht mehr in main_launch landen.
 * Wir fuehren die echte reine Gate-Closure aus action_entry.php aus.
 */
require dirname(__DIR__) . '/IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';

function checkV342(bool $ok, string $name): void
{
    if (!$ok) throw new RuntimeException($name);
}

$action = file_get_contents(dirname(__DIR__) . '/IPSAlexaHaussteuerung/resources/action_entry.php');
checkV342(is_string($action), 'Action template must be readable');
$begin = strpos($action, '        // IAH_KI_GATE_POLICY_BEGIN');
$end = strpos($action, '        // IAH_KI_GATE_POLICY_END');
checkV342($begin !== false && $end !== false && $end > $begin, 'Gate policy markers missing');

// PHP-Test laedt exakt den policy-relevanten Originalblock der Installation.
$policyPhp = substr($action, $begin, $end - $begin);
eval($policyPhp);
checkV342(isset($iahKiGatePolicy) && is_callable($iahKiGatePolicy), 'Gate policy not callable');

$base = [
    'intent'=>'HomeAgentFrageIntent',
    'category'=>'query',
    'aplEvent'=>false, 'aplArgsCount'=>0, 'legacyNav'=>'',
    'launch'=>false, 'back'=>false, 'exit'=>false, 'kiEnabled'=>true
];
$gate = static function (array $overrides) use ($base, $iahKiGatePolicy): array {
    return $iahKiGatePolicy(array_replace($base, $overrides));
};

foreach ([
    'Welche Fenster sind offen?',
    'Welche Jalousien sind zu?',
    'Wie warm ist es im Büro?',
    'Was verbraucht die Steckdose?',
    'Sind alle Lichter aus?',
    'Wo ist das Fenster offen?',
] as $spoken) {
    $decision = iah_vir_classify([
        'intent'=>'HomeAgentFrageIntent',
        'text'=>$spoken,
        'requestType'=>'IntentRequest',
    ]);
    checkV342($decision['category']==='query', 'Recognition of question: ' . $spoken);
    checkV342($gate(['category'=>$decision['category']])['allowed'], 'Routing of question: ' . $spoken);
}

// Dieser Fehler ist am 04.11.2026 in den Live-Logs aufgetreten:
// Alexa meldete category=query, aber KIVoice.received blieb aus.
// Ein fehlerhaftes SDK-Flag darf einen dedizierten Query-Intent nicht abfangen.
foreach ([
    ['aplEvent'=>true],
    ['aplArgsCount'=>2],
    ['launch'=>true],
    ['back'=>true],
    ['exit'=>true],
    ['kiEnabled'=>false],
    ['legacyNav'=>'licht'],
] as $stale) {
    checkV342($gate($stale)['allowed'], 'Dedicated query wrongly dropped: '.json_encode($stale));
}

// Legacy GetHaus: erkannte Fragen gehen zur KI; UI-/APL-Ereignisse nicht.
checkV342($gate(['intent'=>'GetHaus','category'=>'query','kiEnabled'=>true])['allowed'], 'GetHaus question');
checkV342($gate(['intent'=>'GetHaus','category'=>'query','kiEnabled'=>false])['allowed'], 'Disabled KI must explain instead of rendering main');
checkV342(!$gate(['intent'=>'GetHaus','category'=>'query','aplEvent'=>true])['allowed'], 'APL event must not be a question');
checkV342(!$gate(['intent'=>'GetHaus','category'=>'query','aplArgsCount'=>2])['allowed'], 'APL args must not be a question');
checkV342(!$gate(['intent'=>'GetHaus','category'=>'query','legacyNav'=>'licht'])['allowed'], 'Legacy navigation wins');

// Explizite Navigation, Zureuck und echte Schaltbefehle behalten eigene Pfade.
foreach (['navigation','apl_navigation','apl_action','clarify'] as $kind) {
    checkV342(!$gate(['category'=>$kind])['allowed'], 'Must not intercept '.$kind);
}
foreach (['Licht','Jalousie','Lüftung','Zurück'] as $spoken) {
    $decision=iah_vir_classify(['intent'=>'HomeAgentFrageIntent','text'=>$spoken]);
    checkV342($decision['category']==='navigation', 'Classic navigation recognition: '.$spoken);
    checkV342(!$gate(['category'=>$decision['category']])['allowed'], 'Classic navigation intercepted: '.$spoken);
}
foreach (['Schalte das Licht ein','Fahre die Jalousie herunter'] as $spoken) {
    $decision=iah_vir_classify(['intent'=>'GetHaus','text'=>$spoken]);
    checkV342($decision['category']==='action', 'Action recognition: '.$spoken);
    checkV342(!$gate(['intent'=>'GetHaus','category'=>'action','kiEnabled'=>false])['allowed'],
        'Classic action sent to disabled KI: '.$spoken);
}

// Der Hook muss bei Modulupdates wieder in das bestehende IPS-Aktionsskript
// synchronisiert werden; die Diagnose muss vor dem klassischen Renderer sein.
$module = file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/module.php');
checkV342(str_contains($module, '$this->patchActionScriptKIVoice((int) $id);'),
    'Module update must refresh voice hook');
checkV342(str_contains($action, "if ((bool)\$iahKiGate['allowed']) {"), 'Live action does not use the tested gate');
checkV342(str_contains($action, "'KIVoice.route_gate'"), 'Missing reason logging');
checkV342(str_contains($action, "'KIVoice.query_blocked'"), 'Missing blocked-query diagnosis');
checkV342(strpos($action, '// HOME_AGENT_VOICE_HOOK_END') < strpos($action, '// IAH_VOICE_NAVIGATION_BEGIN'),
    'Voice queries must route before the classic UI override');

echo "PASS: Alexa question priority, stale SDK flag tolerance, navigation/action isolation and update migration\n";
