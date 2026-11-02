<?php
declare(strict_types=1);

/** V3.2: sichere, lokal begrenzte IPS-Variablenvorschläge, niemals direkt nach PROD. */
class IPSModule
{
    public array $properties = [];
    public array $updates = [];
    private array $attrs = [];
    public function ReadPropertyInteger(string $key): int { return (int)($this->properties[$key] ?? 0); }
    public function ReadAttributeString(string $key): string { return (string)($this->attrs[$key] ?? ''); }
    public function WriteAttributeString(string $key, string $value): void { $this->attrs[$key] = $value; }
    public function UpdateFormField(string $name, string $field, mixed $value): void { $this->updates[$name . '.' . $field] = $value; }
    public function ReloadForm(): void { throw new RuntimeException('Suggestions must not collapse the form'); }
}

$GLOBALS['v32Temp'] = sys_get_temp_dir() . '/rcc-v32-' . bin2hex(random_bytes(5));
mkdir($GLOBALS['v32Temp'] . '/scripts', 0700, true);
$GLOBALS['v32Scripts'] = [];
$GLOBALS['v32Objects'] = [
    7001 => ['ObjectType'=>1,'ParentID'=>100,'ObjectName'=>'Licht Wohnzimmer','ObjectIdent'=>''],
    50101 => ['ObjectType'=>2,'ParentID'=>7001,'ObjectName'=>'Status','ObjectIdent'=>'STATE'],
    50102 => ['ObjectType'=>2,'ParentID'=>7001,'ObjectName'=>'Schalten','ObjectIdent'=>'SWITCH'],
    50103 => ['ObjectType'=>2,'ParentID'=>7001,'ObjectName'=>'Helligkeit','ObjectIdent'=>'BRIGHTNESS'],
    50104 => ['ObjectType'=>2,'ParentID'=>7001,'ObjectName'=>'Temperatur','ObjectIdent'=>'TEMP'],
    8001 => ['ObjectType'=>1,'ParentID'=>100,'ObjectName'=>'Andere Instanz','ObjectIdent'=>''],
    60101 => ['ObjectType'=>2,'ParentID'=>8001,'ObjectName'=>'Schalten','ObjectIdent'=>'SWITCH']
];
$GLOBALS['v32Variables'] = [
    50101 => ['VariableType'=>0,'VariableProfile'=>'~Switch','VariableAction'=>0,'VariableCustomAction'=>0],
    50102 => ['VariableType'=>0,'VariableProfile'=>'~Switch','VariableAction'=>7001,'VariableCustomAction'=>0],
    50103 => ['VariableType'=>1,'VariableProfile'=>'~Intensity.100','VariableAction'=>0,'VariableCustomAction'=>0],
    50104 => ['VariableType'=>2,'VariableProfile'=>'~Temperature','VariableAction'=>0,'VariableCustomAction'=>0],
    60101 => ['VariableType'=>0,'VariableProfile'=>'~Switch','VariableAction'=>8001,'VariableCustomAction'=>0]
];
function IPS_ScriptExists(int $id): bool { return isset($GLOBALS['v32Scripts'][$id]); }
function IPS_GetKernelDir(): string { return $GLOBALS['v32Temp'] . '/'; }
function IPS_GetScriptFile(int $id): string { return $id . '.php'; }
function IPS_SetScriptContent(int $id, string $php): bool
{
    $GLOBALS['v32Scripts'][$id] = $php;
    file_put_contents($GLOBALS['v32Temp'] . '/scripts/' . $id . '.php', $php);
    return true;
}
function IPS_GetScriptContent(int $id): string { return $GLOBALS['v32Scripts'][$id] ?? ''; }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['v32Variables'][$id]); }
function IPS_ObjectExists(int $id): bool { return isset($GLOBALS['v32Objects'][$id]); }
function IPS_GetObject(int $id): array { return $GLOBALS['v32Objects'][$id] ?? []; }
function IPS_GetChildrenIDs(int $id): array
{
    $children = [];
    foreach ($GLOBALS['v32Objects'] as $objectId=>$object) if ($object['ParentID'] === $id) $children[] = $objectId;
    return $children;
}
function IPS_GetVariable(int $id): array { return $GLOBALS['v32Variables'][$id] ?? []; }

require dirname(__DIR__) . '/RoomsCatalogConfigurator/module.php';
function ok32(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function priv32(RoomsCatalogConfigurator $mod, string $method, array $args = []): mixed
{
    return (new ReflectionMethod(RoomsCatalogConfigurator::class, $method))->invokeArgs($mod, $args);
}

$catalog = [
    'wohnzimmer' => [
        'display'=>'Wohnzimmer', 'floor'=>'EG', 'aliases'=>['Stube'],
        'customRoomMetadata'=>['safe'=>true],
        'domains'=>['licht'=>['switches'=>[
            'decke'=>[
                'title'=>'Deckenlicht', 'state'=>50101, 'toggle'=>0,
                'kiEnabled'=>false, 'kiReadOnly'=>true,
                'customDeviceData'=>['test'=>'keep']
            ]
        ]]]
    ]
];
IPS_SetScriptContent(20, '<?php return ' . var_export($catalog, true) . ';');
IPS_SetScriptContent(21, '<?php return ' . var_export($catalog, true) . ';');
$mod = new RoomsCatalogConfigurator();
$mod->properties = ['RoomsCatalogScriptID'=>20, 'RoomsCatalogEditScriptID'=>21];
$baseRow = priv32($mod, 'buildFlatEntriesFromRooms', [priv32($mod, 'extractRoomsFromCatalog', [$catalog])])[0];
$baseRow['selected'] = true;
$mod->WriteAttributeString('RuntimeEntries', json_encode([$baseRow], JSON_THROW_ON_ERROR));
$mod->WriteAttributeString('FilterAI', 'all');
$form = json_decode($mod->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$suggestionPanel = null;
foreach ($form['elements'] as $element) if (str_contains($element['caption'] ?? '', 'V3.2:')) $suggestionPanel = $element;
ok32(is_array($suggestionPanel) && ($suggestionPanel['expanded'] ?? false), 'V3.2 panel missing');
$formText = json_encode($suggestionPanel, JSON_THROW_ON_ERROR);
ok32(str_contains($formText, 'RCC_AnalyzeSelectedDevice') && str_contains($formText, 'RCC_ApplySelectedSuggestions'), 'V3.2 buttons missing');
ok32(str_contains($formText, 'Suggestions'), 'Suggestion preview list missing');

ob_start(); $mod->AnalyzeSelectedDevice(); $analysis = ob_get_clean();
ok32(str_contains($analysis, 'eindeutige Vorschlaege'), 'Analysis not executed');
$preview = json_decode($mod->ReadAttributeString('SuggestionPreview'), true, 512, JSON_THROW_ON_ERROR);
$byProperty = [];
foreach ($preview['suggestions'] as $suggestion) $byProperty[$suggestion['property']] = $suggestion;
ok32($byProperty['power']['confidence'] === 'Bereits belegt', 'Existing state was not preserved');
ok32($byProperty['control']['candidateId'] === 50102 && $byProperty['control']['confidence'] === 'Hoch', 'Actuated switch was not suggested');
ok32($byProperty['control']['candidateId'] !== 60101, 'Foreign instance leaked into suggestions');
ok32(isset($mod->updates['Suggestions.values']), 'Preview not updated without reloading form');
ok32((require $GLOBALS['v32Temp'] . '/scripts/21.php') === $catalog, 'Read-only analysis modified EDIT');

ob_start(); $mod->ApplySelectedSuggestions(); $applied = ob_get_clean();
ok32(str_contains($applied, '1 eindeutige'), 'High-confidence binding was not applied');
$edited = require $GLOBALS['v32Temp'] . '/scripts/21.php';
$cfg = $edited['wohnzimmer']['domains']['licht']['switches']['decke'];
ok32($cfg['state'] === 50101 && $cfg['toggle'] === 0, 'Legacy fields overwritten');
ok32($cfg['ai']['type'] === 'light' && $cfg['ai']['variables']['power'] === 50101
    && $cfg['ai']['variables']['control'] === 50102, 'AI binding not persisted in correct shape');
ok32($cfg['kiEnabled'] === false && $cfg['kiReadOnly'] === true, 'Permissions changed');
ok32($cfg['customDeviceData'] === ['test'=>'keep'] && $edited['wohnzimmer']['aliases'] === ['Stube'], 'Unrelated catalog metadata lost');
ok32((require $GLOBALS['v32Temp'] . '/scripts/20.php') === $catalog, 'PROD changed without approval');

// Zwei gleich starke Namen müssen als unklar angezeigt und dürfen nicht übernommen werden.
$GLOBALS['v32Objects'][50105] = ['ObjectType'=>2,'ParentID'=>7001,'ObjectName'=>'Schalten','ObjectIdent'=>'SWITCH2'];
$GLOBALS['v32Variables'][50105] = ['VariableType'=>0,'VariableProfile'=>'~Switch','VariableAction'=>7001,'VariableCustomAction'=>0];
$mod->WriteAttributeString('RuntimeEntries', json_encode([$baseRow], JSON_THROW_ON_ERROR));
ob_start(); $mod->AnalyzeSelectedDevice(); ob_end_clean();
$ambiguous = json_decode($mod->ReadAttributeString('SuggestionPreview'), true, 512, JSON_THROW_ON_ERROR);
$ambiguousControl = array_values(array_filter($ambiguous['suggestions'], fn($x)=>$x['property']==='control'))[0];
ok32($ambiguousControl['confidence'] === 'Pruefen', 'Ambiguous equal-score mapping automatically approved');
$beforeAmbiguous = IPS_GetScriptContent(21);
ob_start(); $mod->ApplySelectedSuggestions(); $none = ob_get_clean();
ok32(str_contains($none, 'Keine eindeutigen'), 'Ambiguous candidate was applied');
ok32(IPS_GetScriptContent(21) === $beforeAmbiguous, 'Ambiguous suggestion changed EDIT');
unset($GLOBALS['v32Objects'][50105], $GLOBALS['v32Variables'][50105]);

// Die Analyse wird ungültig, sobald die bearbeitete Zeile geändert wurde.
ob_start(); $mod->AnalyzeSelectedDevice(); ob_end_clean();
$changedRow = $baseRow;
$changedRow['title'] = 'Anderes Licht';
$mod->WriteAttributeString('RuntimeEntries', json_encode([$changedRow], JSON_THROW_ON_ERROR));
$beforeStale = IPS_GetScriptContent(21);
ob_start(); $mod->ApplySelectedSuggestions(); $stale = ob_get_clean();
ok32(str_contains($stale, 'neu analysieren'), 'Changed row reused stale preview');
ok32(IPS_GetScriptContent(21) === $beforeStale, 'Stale preview wrote EDIT');

// Kein bekannter Anker: nicht in allen Geräten des Systems suchen.
$noAnchor = $baseRow;
$noAnchor['state'] = 0;
foreach (array_keys($noAnchor) as $field) {
    if (str_starts_with($field, 'ai') && str_ends_with($field, 'Id')) $noAnchor[$field] = 0;
}
$mod->WriteAttributeString('RuntimeEntries', json_encode([$noAnchor], JSON_THROW_ON_ERROR));
ob_start(); $mod->AnalyzeSelectedDevice(); $missing = ob_get_clean();
ok32(str_contains($missing, 'mindestens eine bestehende IPS-Variable'), 'Unanchored analysis searched globally');

foreach (array_keys($GLOBALS['v32Scripts']) as $id) @unlink($GLOBALS['v32Temp'] . '/scripts/' . $id . '.php');
@rmdir($GLOBALS['v32Temp'] . '/scripts');
@rmdir($GLOBALS['v32Temp']);
echo "PASS RoomsCatalog V3.2: scoped suggestions, ranking, ambiguity, read-only, stale guard and EDIT-only apply\n";
