<?php
declare(strict_types=1);

require __DIR__ . '/test_voice_rule_preview_v38.php';

function ck384(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('V3.8.4: ' . $message);
}
function IPS_GetChildrenIDs(int $parent): array
{
    return array_keys(array_filter($GLOBALS['sc333Objects'], static fn(array $item): bool => $item['parent'] === $parent));
}
function GetValueString(int $id): string { return $GLOBALS['alexa384Values'][$id]; }
function SetValueString(int $id, string $value): void { $GLOBALS['alexa384Values'][$id] = $value; }

$add = static function (int $id, int $parent, string $ident, ?string $value = null): void {
    $GLOBALS['sc333Objects'][$id] = ['parent'=>$parent, 'name'=>$ident, 'ident'=>$ident];
    if ($value !== null) {
        $GLOBALS['preview38Variables'][$id] = ['VariableType'=>3];
        $GLOBALS['alexa384Values'][$id] = $value;
    }
};
$add(9100, 100, 'iahDiag');
$add(9101, 100, 'iahHelper');
$add(9102, 9101, 'deviceMapJson', json_encode([
    'test-echo-one'=>['location'=>'Küche'],
    'test-echo-two'=>['location'=>'Büro'],
    'test-echo-unknown'=>['location'=>'NEU:abcdef'],
], JSON_THROW_ON_ERROR));
$fields = ['alexa','action','device','room','szene','object','number','prozent','alles',
    'iahLastAlexaSpoken','iahLastAlexaIntent','iahLastAlexaRoute','iahLastAlexaRule',
    'iahLastAlexaAnswer','iahLastAlexaStatus','iahLastAlexaAt','iahLastAlexaInteraction'];
foreach ($fields as $i=>$ident) $add(9120 + $i, 9100, $ident, $i === 0 ? 'Alter Echo' : '');
$value = static fn(string $ident): string => GetValueString(IPS_GetObjectIDByIdent($ident, 9100));
$module->properties['VoiceRouterStoreTranscript'] = true;
$published = IAHVoiceRuleLiteralReader::read(IPS_GetScriptContent(501));
$rule = $published['voiceRules']['items']['wer_bist_du'];
$install = static function (array $changes) use ($published, $rule): void {
    IPS_SetScriptContent(501, IAHSystemNavigationEditor::encode(
        IAHVoiceRuleEngine::change($published, 'wer_bist_du', array_replace($rule, $changes))));
};
$resolve = static fn(string $id): array => json_decode($module->ResolveVoiceRule(json_encode([
    'text'=>'wer bist du', 'intent'=>'GetHaus', 'deviceId'=>$id, 'skipLegacyFallback'=>true,
], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
$install(['answer'=>'Ich bin deine Alexa-Haussteuerung.']);
ck384($resolve('test-echo-one')['answer'] === 'Ich bin die Alexa Küche.'
    && $resolve('test-echo-two')['answer'] === 'Ich bin die Alexa Büro.'
    && $resolve('test-echo-unknown')['answer'] === 'Ich bin deine Alexa-Haussteuerung.'
    && $value('alexa') === 'Alter Echo', 'live answer uses only the current Echo, not previous diagnostics');
$install(['answer'=>'Hier spricht {alexa}.', 'cardText'=>'Echo {alexa}']);
$r = $resolve('test-echo-two');
ck384($r['answer'] === 'Hier spricht Büro.' && $r['cardText'] === 'Echo Büro', 'authored speech and card');
$install(['answer'=>'Hier spricht {value}.']);
ck384($resolve('test-echo-one')['answer'] === 'Hier spricht Küche.', 'identity answer compatibility placeholder');
$install(['kind'=>'read', 'readVariableId'=>9120, 'answer'=>'Ich bin die Alexa {value}.']);
ck384($resolve('test-echo-two')['answer'] === 'Ich bin die Alexa Büro.'
    && !str_contains($resolve('test-echo-unknown')['answer'], 'Alter Echo'), 'read of Diagnose/alexa is request-local');
$test = json_decode($module->GetAlexaVoiceTest('Wer bist du?', 'PROD'), true, 512, JSON_THROW_ON_ERROR);
ck384(!empty($test['dryRun']) && str_contains($test['answer'], '(Name des anfragenden Echo)')
    && $value('alexa') === 'Alter Echo', 'test does not invent an Echo or change diagnosis');

$record = static fn(array $entry) => $module->RecordAlexaInteraction(json_encode($entry, JSON_THROW_ON_ERROR));
$event = [
    'requestType'=>'IntentRequest', 'deviceId'=>'test-echo-two', 'spoken'=>'wer bist du',
    'intent'=>'GetHaus', 'route'=>'Sprachregel', 'status'=>'Verarbeitet',
    'ruleKey'=>'wer_bist_du', 'answer'=>'Ich bin die Alexa Büro.',
    'slots'=>['action'=>'wer bist du', 'device'=>'', 'room'=>''],
];
$record($event);
$snapshot = $value('iahLastAlexaInteraction');
$parsed = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
ck384($value('alexa') === 'Büro' && $value('action') === 'wer bist du'
    && $value('iahLastAlexaRule') === 'wer_bist_du' && $parsed['route'] === 'Sprachregel'
    && $parsed['answer'] === $value('iahLastAlexaAnswer') && $parsed['slots']['action'] === $value('action')
    && strtotime($parsed['at']) !== false && !str_contains($snapshot, 'test-echo-'),
    'coherent snapshot with existing strings and no raw Echo ID');
foreach (['LaunchRequest','SessionEndedRequest','TimerEvent'] as $type) {
    $record(array_replace($event, ['requestType'=>$type, 'deviceId'=>'test-echo-one']));
    ck384($value('iahLastAlexaInteraction') === $snapshot && $value('alexa') === 'Büro', 'last real action retained');
}
$record(array_replace($event, [
    'deviceId'=>'test-echo-one', 'spoken'=>'welche fenster sind offen',
    'route'=>'Hausabfrage', 'ruleKey'=>'', 'answer'=>'Ein Fenster ist offen.',
    'slots'=>['device'=>'Fenster','room'=>'EG','number'=>0],
]));
ck384($value('action') === '' && $value('number') === '0' && $value('room') === 'EG'
    && $value('device') === 'Fenster' && $value('alexa') === 'Küche' && $value('iahLastAlexaRule') === '',
    'next request clears absent slots but retains a numeric zero');
$record(array_replace($event, ['deviceId'=>'test-echo-unknown','spoken'=>'Passwort lautet abcdef',
    'answer'=>'https://example.test/?token=defghi']));
ck384($value('alexa') === '' && !str_contains($value('iahLastAlexaInteraction'), 'abcdef')
    && !str_contains($value('iahLastAlexaInteraction'), 'defghi'), 'unknown Echo and protected values');
$module->properties['VoiceRouterStoreTranscript'] = false;
$record($event);
ck384($value('alexa') === 'Büro' && $value('iahLastAlexaIntent') === 'GetHaus'
    && $value('action') === '' && $value('iahLastAlexaSpoken') === '' && $value('iahLastAlexaAnswer') === '',
    'retention setting keeps routing metadata but not spoken or response text');

$action = file_get_contents(dirname(__DIR__) . '/IPSAlexaHaussteuerung/resources/action_entry.php');
$unpatched = preg_replace('/^[ \t]*\/\/ IAH_LAST_ALEXA_(\w+)_BEGIN\R.*?^[ \t]*\/\/ IAH_LAST_ALEXA_\1_END\R/ms', '', $action);
$fallbackAnchor = '        $resetState($V);' . "\n\n" . '    } catch (Exception $e) {';
$oldFallback = "        return AskResponse::CreatePlainText('Konnte kein Gerät verstehen. Wie bitte?')->SetRepromptPlainText('Wie bitte?');";
$unpatched = str_replace($fallbackAnchor,
    '        $resetState($V);' . "\n" . $oldFallback . "\n\n" . '    } catch (Exception $e) {', $unpatched);
$old = $unpatched . "\n// Custom Action code retained\n";
$add(9190, 200, 'iahActionEntryV384Fixture');
IPS_SetScriptContent(9190, $old);
$scriptIdsBefore = array_keys($GLOBALS['sc333Scripts']);
$patch = new ReflectionMethod(IPSAlexaHaussteuerung::class, 'patchActionScriptLastAlexaInteraction');
$patch->invoke($module, 9190);
$added = array_values(array_diff(array_keys($GLOBALS['sc333Scripts']), $scriptIdsBefore));
$next = IPS_GetScriptContent(9190);
ck384($next === $action . "\n// Custom Action code retained\n" && count($added) === 1
    && IPS_GetScriptContent($added[0]) === $old && !empty($GLOBALS['sc333Hidden'][$added[0]]),
    'installed Action hooks update without replacing customization and backup verified');
$patch->invoke($module, 9190);
ck384(IPS_GetScriptContent(9190) === $next
    && array_values(array_diff(array_keys($GLOBALS['sc333Scripts']), $scriptIdsBefore)) === $added,
    'Action migration is idempotent');
echo "PASS V3.8.4: Echo-specific answer, persistent diagnosis, isolated preview and safe Action migration\n";
