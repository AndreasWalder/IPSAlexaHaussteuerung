<?php
declare(strict_types=1);

require __DIR__ . '/test_last_alexa_interaction_v384.php';
require_once dirname(__DIR__) . '/IPSAlexaHaussteuerung/resources/action_entry.php';

function ck385(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('V3.8.5: ' . $message);
}
class AskResponse
{
    public string $text;
    public static function CreatePlainText(string $text): self
    {
        $r = new self();
        $r->text = $text;
        return $r;
    }
    public function SetRepromptPlainText(string $prompt): self { return $this; }
}
function IAH_ResolveVoiceRule(int $id, string $eventJson): string
{
    $GLOBALS['v385BridgeEvents'][] = json_decode($eventJson, true, 512, JSON_THROW_ON_ERROR);
    return $GLOBALS['preview38Alexa']->ResolveVoiceRule($eventJson);
}

$readBlock = static function (string $begin, string $end) use ($action): string {
    $start = strpos($action, '// ' . $begin);
    $stop = strpos($action, '// ' . $end, $start + 1);
    ck385($start !== false && $stop !== false, 'extract installed Action section ' . $begin);
    return substr($action, $start, $stop - $start);
};
$slotCode = $readBlock('IAH_LAST_ALEXA_CURRENT_SLOTS_BEGIN', 'IAH_LAST_ALEXA_CURRENT_SLOTS_END');
$questionCode = $readBlock('IAH_ALEXA_QUESTION_CAPTURE_BEGIN', 'IAH_ALEXA_QUESTION_CAPTURE_END');
$bridgeCode = $readBlock('IAH_VOICE_RULES_V37_BEGIN', 'IAH_VOICE_RULES_V37_END');
$run = static function (array $data, string $sdkQuestion = '') use ($slotCode, $questionCode, $bridgeCode): array {
    $rawData = ['request'=>['type'=>'IntentRequest', 'intent'=>$data],
        'session'=>['attributes'=>['slots'=>['Action'=>['value'=>'werbistdu']]]]];
    $intentName = $data['name'];
    $request = new stdClass();
    // Deliberately dirty compatibility view, similar to the reported request
    // with SLOTSTRUCT_Action=null but a second rule candidate.
    $getSlotCH = static fn($r, string $name) => $name === 'Action' ? 'wer bist du' : ($name === 'Frage' ? $sdkQuestion : null);
    $getSlotId = static fn($r, string $name) => $name === 'Action' ? 'werbistdu' : null;
    eval($slotCode);
    $action1Val = (string)$getSlotCH($request, 'Action');
    $action1Id = (string)$getSlotId($request, 'Action');
    $device1Val = $device1Id = '';
    $rawSlots = $rawSlotIds = ['a'=>$action1Id];
    $rawSlotValues = ['a'=>$action1Val];
    $rawUserText = 'wer bist du';
    $rawSlotsToText = static fn(array $slots): string => implode(' ', $slots);
    $log = $iahLastOutcome = static function (...$args): void {};
    $IS_LAUNCH = $IS_BACK = $wantsExit = $iahVoiceIsAplEvent = false;
    $iahVoiceRequestType = 'IntentRequest';
    $iahHouseSessionId = '';
    $iahVoiceDeviceId = 'test-echo-two';
    $instanceId = 100;
    $APL = ['args'=>[]];
    $GLOBALS['v385BridgeEvents'] = [];
    eval($questionCode);
    $result = eval($bridgeCode);
    return ['answer'=>$result?->text, 'spoken'=>$iahVoiceSpoken, 'slots'=>$iahLastInteraction['slots'],
        'candidates'=>$iahRuleCandidates, 'events'=>$GLOBALS['v385BridgeEvents']];
};

$install(['answer'=>'Ich bin deine Alexa {alexa}.']);
$identity = $run(['name'=>'GetHaus','slots'=>['Action'=>['name'=>'Action','value'=>'wer bist du']]]);
ck385($identity['answer'] === 'Ich bin deine Alexa Büro.'
    && $identity['slots']['action'] === 'wer bist du', 'real identity intent still names Echo and records the slot');
foreach ([
    ['HausWieIntent','viele Fenster sind im Erdgeschoss offen','wie viele Fenster sind im Erdgeschoss offen'],
    ['HausWelcheIntent','Lichter sind eingeschaltet','welche Lichter sind eingeschaltet'],
    ['HausUndIntent','im Obergeschoss','und im Obergeschoss'],
    ['HomeAgentFrageIntent','Welche Räume sind belegt','Welche Räume sind belegt'],
] as [$name,$slot,$question]) {
    $out = $run(['name'=>$name,'slots'=>['Frage'=>['name'=>'Frage','value'=>$slot]]], 'wer bist du');
    ck385($out['answer'] === null && $out['spoken'] === $question && $out['candidates'] === [$question]
        && $out['slots'] === []
        && array_unique(array_column($out['events'],'text')) === [$question],
        'house query or follow-up reaches the rest of Action without old identity candidates: ' . $name);
}
$missing = $run(['name'=>'HausWieIntent','slots'=>[]], 'wer bist du');
ck385($missing['answer'] === null && $missing['candidates'] === [] && $missing['events'] === [],
    'an absent question never falls back to the previous phrase');
$emptyGetHaus = $run(['name'=>'GetHaus']);
ck385($emptyGetHaus['answer'] === null && $emptyGetHaus['candidates'] === [],
    'an empty GetHaus request also cannot reuse old Action');
$currentGetHaus = $run(['name'=>'GetHaus','slots'=>['Device'=>['value'=>'licht']]]);
ck385($currentGetHaus['answer'] === null && !in_array('wer bist du', $currentGetHaus['candidates'], true),
    'current structured GetHaus request cannot be shadowed by old SDK text');
$questionAboutEcho = $run(['name'=>'HausWieIntent','slots'=>['Frage'=>['slotValue'=>['value'=>'heißt du']]]]);
ck385($questionAboutEcho['answer'] === 'Ich bin deine Alexa Büro.',
    'a current SearchQuery may explicitly ask for the Echo name');
$configuration = IAHVoiceRuleLiteralReader::read(IPS_GetScriptContent(501));
ck385(!isset(IAHVoiceRuleAiMatcher::candidates($configuration, 'In welchem Raum sind Fenster offen?')['wer_bist_du']),
    'AI cannot guess identity for a house question sharing words with the alias');
echo "PASS V3.8.5: current Alexa slots only, identity followed by house questions and follow-up\n";
