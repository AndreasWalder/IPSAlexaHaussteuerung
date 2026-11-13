<?php
declare(strict_types=1);

require __DIR__ . '/test_last_alexa_interaction_v384.php';
require dirname(__DIR__) . '/IPSAlexaHaussteuerung/resources/helpers/RendererActionDiagnostics.php';
require_once dirname(__DIR__) . '/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';

function ck3812(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('V3.8.12: ' . $message);
}

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;

function IPS_CreateVariable(int $type): int
{
    $id = IPS_CreateScript(0);
    $GLOBALS['preview38Variables'][$id] = ['VariableType'=>$type];
    $GLOBALS['alexa384Values'][$id] = '';
    return $id;
}
function GetValueBoolean(int $id): bool { return $GLOBALS['v3812Bools'][$id] ?? false; }
function SetValueBoolean(int $id, bool $value): bool
{
    if ($id === 811) return false;
    $GLOBALS['v3812Bools'][$id] = $value;
    return true;
}
function IPS_GetName(int $id): string { return $id === 810 ? 'Licht Küche' : ''; }
function IAH_RecordAlexaVariableAction(int $instanceId, int $id, string $source, string $operation, string $value): void
{
    ck3812($instanceId === 100, 'diagnostic bound to the owning instance');
    $GLOBALS['preview38Alexa']->RecordAlexaVariableAction($id, $source, $operation, $value);
}

$ensure = new ReflectionMethod(IPSAlexaHaussteuerung::class, 'ensureVar');
$liveDefaults = [
    'dumpFile' => 'gespeicherte Vorschau',
    'lastVarDevice' => 'Licht',
    'lastVarAction' => 'RequestAction',
    'lastVarId' => '810',
    'lastVarValue' => 'false',
    'logRecent' => 'gespeicherte Meldung',
    'domainFlag' => 'licht',
    'activeTabState' => '{"geraete":"OG"}',
];
foreach ($liveDefaults as $ident=>$saved) {
    $id = $ensure->invoke($module, 100, $ident, $ident, VARIABLETYPE_STRING, '', '');
    SetValueString($id, $saved);
    ck3812($ensure->invoke($module, 100, $ident, $ident, VARIABLETYPE_STRING, '', '') === $id
        && GetValueString($id) === $saved, 'retain value and object ID: ' . $ident);
}
$legacy = IPS_CreateVariable(VARIABLETYPE_STRING);
IPS_SetParent($legacy, 100);
IPS_SetName($legacy, 'Legacy runtime value');
SetValueString($legacy, 'alt');
ck3812($ensure->invoke($module, 100, 'Legacy runtime value', 'legacyState', VARIABLETYPE_STRING, '', 'neu') === $legacy
    && GetValueString($legacy) === 'alt', 'adoption by name preserves value and ID');
$flag = $ensure->invoke($module, 200, 'licht_switches', 'lichtSwitches', VARIABLETYPE_BOOLEAN, '', true);
ck3812(GetValueBoolean($flag), 'default is used on first installation');
SetValueBoolean($flag, false);
$ensure->invoke($module, 200, 'licht_switches', 'lichtSwitches', VARIABLETYPE_BOOLEAN, '', true);
ck3812(!GetValueBoolean($flag), 'disabled switch is not enabled on refresh');
$skill = $ensure->invoke($module, 100, 'skillActive', 'skillActive', VARIABLETYPE_BOOLEAN, '', false);
SetValueBoolean($skill, true);
$ensure->invoke($module, 100, 'skillActive', 'skillActive', VARIABLETYPE_BOOLEAN, '', false);
ck3812(GetValueBoolean($skill), 'active flag is not reset by refresh');

$add(9400, 9100, 'iahLastVariableAction', '');
$renderer = iah_renderer_action_dispatch(100, 'Licht');
$rootValue = static fn(string $key): string => GetValueString(IPS_GetObjectIDByIdent($key, 100));
ck3812($renderer('RequestAction', 810, false) === true, 'renderer still returns the original dispatch result');
$snapshot = json_decode($value('iahLastVariableAction'), true, 512, JSON_THROW_ON_ERROR);
ck3812($snapshot['variableId'] === 810 && $snapshot['variableName'] === 'Licht Küche'
    && $snapshot['source'] === 'Licht' && $snapshot['status'] === 'accepted_by_symcon'
    && $snapshot['value'] === false && $rootValue('lastVarValue') === 'false'
    && $rootValue('lastVarDevice') === 'Licht' && $rootValue('lastVarId') === '810'
    && $rootValue('lastVarAction') === 'RequestAction', 'summary and existing runtime variables agree');
$writeTrace = $value('iahLastVariableAction');
$module->properties['VoiceRouterStoreTranscript'] = true;
$record(array_replace($event, ['spoken'=>'Welche Fenster sind offen?', 'deviceId'=>'test-echo-one',
    'answerSource'=>'local', 'durationMs'=>24, 'route'=>'Hausabfrage']));
$interaction = json_decode($value('iahLastAlexaInteraction'), true, 512, JSON_THROW_ON_ERROR);
ck3812($interaction['answerSource'] === 'local' && $interaction['durationMs'] === 24
    && $value('alexa') === 'Küche' && $value('iahLastVariableAction') === $writeTrace,
    'read-only question updates Echo, origin and latency without changing last write');
$module->GetAlexaVoiceTest('Wer bist du?', 'PROD');
ck3812($value('alexa') === 'Küche' && $value('iahLastVariableAction') === $writeTrace, 'preview does not write to either trace');
$renderer('SetValueBoolean', 810, true);
ck3812(str_contains($value('iahLastVariableAction'), 'value_set_in_symcon')
    && $rootValue('lastVarValue') === 'true', 'direct write distinguished from RequestAction');
$writeTrace = $value('iahLastVariableAction');
ck3812($renderer('SetValueBoolean', 811, false) === false && $value('iahLastVariableAction') === $writeTrace,
    'failed dispatch preserves the last successful trace');


$context = ['recordAction'=>static function (int $id, mixed $v): void {
    IAH_RecordAlexaVariableAction(100, $id, 'Sprachregel', 'RequestAction', json_encode($v, JSON_THROW_ON_ERROR));
}];
$rules = ['voiceRules'=>['schemaVersion'=>IAHVoiceRuleEngine::VERSION,'items'=>['diagnosetest'=>[
    'title'=>'Diagnosetest', 'aliases'=>['Diagnosetest'], 'kind'=>'variable',
    'variableId'=>810, 'variableValue'=>'false', 'enabled'=>true, 'confirmed'=>true,
]]]];
$ruleResult = IAHVoiceRuleEngine::run($rules, [], 'Diagnosetest', $context);
ck3812(($ruleResult['executed'] ?? 0) === 1 && $rootValue('lastVarDevice') === 'Sprachregel',
    'approved voice rule reports accepted write');
$dev = ['id'=>'light-kitchen','writable'=>true,'typed'=>true,'writeRoles'=>['power'=>810],
    'group'=>'switches','name'=>'Licht Küche','room'=>'Küche'];
$kiRecord = static fn(int $id, mixed $v) => IAH_RecordAlexaVariableAction(
    100, $id, 'KI-Haussteuerung', 'RequestAction', json_encode($v, JSON_THROW_ON_ERROR));
$kiResult = iah_rc_write($dev, true, 'power', $kiRecord);
ck3812(!empty($kiResult['ok']) && $rootValue('lastVarDevice') === 'KI-Haussteuerung'
    && !str_contains(json_encode($kiResult, JSON_THROW_ON_ERROR), '810'), 'accepted KI action is recorded locally, not in KI data');
$writeTrace = $value('iahLastVariableAction');
$dev['writable'] = false;
ck3812(empty(iah_rc_write($dev, false, 'power', $kiRecord)['ok'])
    && $value('iahLastVariableAction') === $writeTrace, 'unapproved action does not change trace');

$logger = new ReflectionMethod(IPSAlexaHaussteuerung::class, 'log');
$logger->invoke($module, 'info', 'Aktoraktion geprüft', ['context'=>'intern']);
ck3812(str_contains($rootValue('logRecent'), 'gespeicherte Meldung')
    && str_contains($rootValue('logRecent'), 'Aktoraktion geprüft')
    && !str_contains($rootValue('logRecent'), 'context'), 'module log appends to the existing canonical buffer');
$templates = new ReflectionMethod(IPSAlexaHaussteuerung::class, 'ensureScriptTemplate');
foreach (['Licht','Jalousie','Heizung','Lueftung','Geraete','Bewaesserung'] as $domain) {
    $id = $templates->invoke($module, 100, 'testRenderer' . $domain, 'testRenderer' . $domain,
        dirname(__DIR__) . '/IPSAlexaHaussteuerung/resources/renderers/Render' . $domain . '.php');
    $installed = IPS_GetScriptContent($id);
    token_get_all($installed, TOKEN_PARSE);
    ck3812(str_contains($installed, 'function iah_renderer_action_dispatch(')
        && !str_contains($installed, 'IAH_RENDERER_ACTION_DIAGNOSTICS_INSERT'), 'generated renderer embeds diagnostics: ' . $domain);
}

// The installed V3.8.11 Action still has its two original APL writes.
$v3811 = preg_replace('/^[ \t]*\/\/ IAH_LAST_ALEXA_(RUNTIME|ANSWER_SOURCE)_BEGIN\R.*?^[ \t]*\/\/ IAH_LAST_ALEXA_\1_END\R/ms', '', $action);
$v3811 = preg_replace('/^[ \t]*\/\/ IAH_LAST_ALEXA_APL_(GERAETE|BEWAESSERUNG)_BEGIN\R.*?^[ \t]*\/\/ IAH_LAST_ALEXA_APL_\1_END/ms',
    '            RequestAction((int)$APL[\'a2\'], (int)$APL[\'a3\']);', $v3811);
$fixture = IPS_CreateScript(0);
IPS_SetScriptContent($fixture, $v3811);
$patch->invoke($module, $fixture);
ck3812(IPS_GetScriptContent($fixture) === $action, 'upgrade replaces both legacy APL writes without duplicating actions');

echo "PASS V3.8.12: state survives refresh, canonical log, Echo on any processed request and separate accepted-write trace\n";
