<?php
declare(strict_types=1);

require __DIR__ . '/test_alexa_question_slot_isolation_v385.php';
require_once dirname(__DIR__) . '/IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';

function ck3813(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('V3.8.13: ' . $message);
}

class TellResponse
{
    public string $text;
    public static function CreatePlainText(string $text): self
    {
        $r = new self();
        $r->text = $text;
        return $r;
    }
}

$root = dirname(__DIR__);
$package = json_decode(file_get_contents($root . '/docs/Alexa_Hausfragen_Intent_Paket.json'), true, 512, JSON_THROW_ON_ERROR);
$gateCode = $readBlock('IAH_KI_GATE_POLICY_BEGIN', 'IAH_KI_GATE_POLICY_END');
eval($gateCode);
$matchedPrefixes = [];
foreach ($package['intents'] as $intent) {
    $name = $intent['name'];
    if ($name === 'HausBefehlIntent') continue;
    $sample = $intent['samples'][0];
    $prefix = substr($sample, 0, -strlen(' {Frage}'));
    $spoken = $prefix . ' ein Test';
    $req = ['name'=>$name, 'slots'=>['Frage'=>['name'=>'Frage','value'=>'ein Test']]];
    ck3813($run($req)['spoken'] === $spoken, 'keep the exact carrier: ' . $name);
    $req['slots']['Frage']['value'] = $spoken;
    ck3813($run($req)['spoken'] === $spoken, 'do not add an already spoken carrier: ' . $name);
    $decision = $iahKiGatePolicy(['intent'=>$name,'category'=>'query','kiEnabled'=>false]);
    ck3813($decision === ['allowed'=>true,'reason'=>'dedicated_question_priority'], 'query gate: ' . $name);
    $matchedPrefixes[] = $prefix;
}
foreach (['welcher','welches','welchem','wo','wer','wann','seit wann','wieso','warum','in welchem'] as $prefix)
    ck3813(in_array($prefix, $matchedPrefixes, true), 'important prefix in package: ' . $prefix);
foreach (['Wieso leuchtet das Licht', 'Seit wann leuchtet das Licht', 'Kennst du das Gerät', 'Hast du eine Übersicht'] as $question) {
    ck3813(iah_vir_classify(['text'=>$question])['category'] === 'query'
        && IAHHouseQuestionContext::isNewQuestion($question), 'question is not legacy/navigation: ' . $question);
}

$rules = IAHVoiceRuleLiteralReader::read(IPS_GetScriptContent(501));
// Match the current PROD configuration, not the older fixture with bare "Staubsaugen".
$rules['voiceRules']['items']['roborock_reinigung']['aliases'] = ['Roborock', 'Staubsaugen oben', 'oben Staubsaugen'];
$rules = IAHVoiceRuleEngine::change($rules, 'test_mova', [
    'title'=>'Mova', 'aliases'=>['Staubsaugen unten', 'Mova'], 'kind'=>'answer',
    'answer'=>'Mova reinigt unten.', 'enabled'=>true, 'confirmed'=>true,
]);
IPS_SetScriptContent(501, IAHSystemNavigationEditor::encode($rules));
$command = static fn(?string $s): array => $run(['name'=>'HausBefehlIntent',
    'slots'=>$s===null ? [] : ['Frage'=>['name'=>'Frage','value'=>$s]]], 'stale GetHaus');
$out = $command('Staubsaugen unten');
ck3813($out['answer'] === 'Mova reinigt unten.' && $out['candidates'] === ['Staubsaugen unten'],
    'custom command uses full current text and returns the published answer');
$out = $command('Staubsaugen');
ck3813($out['answer'] === 'Diesen eigenen Befehl kenne ich nicht.' && count($out['events'])===1
    && !$out['events'][0]['aiFallback'] && $out['events'][0]['skipLegacyFallback'],
    'unknown command does not guess an action or use the legacy alias');
$out = $command(null);
ck3813($out['answer'] === 'Welchen eigenen Befehl meinst du?' && $out['events']===[],
    'missing command text does not borrow stale slots');
$out = $run(['name'=>'HomeAgentFrageIntent','slots'=>['Frage'=>['value'=>'Staubsaugen unten']]]);
ck3813($out['answer'] === 'Mova reinigt unten.', 'existing generic entry still matches custom rules');

foreach (['AMAZON.HelpIntent','AMAZON.FallbackIntent','AMAZON.StopIntent','AMAZON.CancelIntent'] as $name) {
    $out = $run(['name'=>$name, 'slots'=>[]]);
    ck3813(is_string($out['answer']) && $out['answer']!=='' && $out['events']===[],
        'built-in handles help/exit without dispatching a voice rule: ' . $name);
}

$installedBefore = str_replace("'hauswointent'=>'wo',", "'old_wo_mapping'=>'wie',", $action)
    . "\n// Custom Action outside the managed block\n";
$fixture = IPS_CreateScript(0);
IPS_SetScriptContent($fixture, $installedBefore);
$migration = new ReflectionMethod(IPSAlexaHaussteuerung::class, 'patchActionScriptKIVoice');
$migration->invoke($module, $fixture);
ck3813(IPS_GetScriptContent($fixture) === $action . "\n// Custom Action outside the managed block\n",
    'the existing Action keeps its ID and custom code on upgrade');
echo "PASS V3.8.13: distinct Alexa question beginnings, custom answer commands, built-in exits and installed Action migration\n";
