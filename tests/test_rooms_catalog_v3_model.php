<?php
declare(strict_types=1);

/** V3.0 Regressionstest ohne laufenden IP-Symcon-Server. */
class IPSModule
{
    private array $attributes = [];
    public array $properties = [];
    public function ReadPropertyInteger(string $key): int { return (int)($this->properties[$key] ?? 0); }
    public function ReadAttributeString(string $key): string { return (string)($this->attributes[$key] ?? ''); }
    public function WriteAttributeString(string $key, string $value): void { $this->attributes[$key] = $value; }
    public function UpdateFormField(string $name, string $field, mixed $value): void {}
}

$GLOBALS['rccTemp'] = sys_get_temp_dir() . '/rcc-v3-' . bin2hex(random_bytes(5));
mkdir($GLOBALS['rccTemp'] . '/scripts', 0700, true);
$GLOBALS['rccScripts'] = [];
$GLOBALS['rccNextId'] = 1000;
$GLOBALS['rccBackupFails'] = false;

function IPS_ScriptExists(int $id): bool { return array_key_exists($id, $GLOBALS['rccScripts']); }
function IPS_GetKernelDir(): string { return $GLOBALS['rccTemp'] . '/'; }
function IPS_GetScriptFile(int $id): string { return $id . '.php'; }
function IPS_VariableExists(int $id): bool { return in_array($id, [12345, 23456, 34567, 45678, 56789], true); }
function IPS_SetScriptContent(int $id, string $content): bool
{
    if ($GLOBALS['rccBackupFails'] && $id >= 1000) return false;
    $GLOBALS['rccScripts'][$id] = $content;
    file_put_contents($GLOBALS['rccTemp'] . '/scripts/' . $id . '.php', $content);
    return true;
}
function IPS_GetScriptContent(int $id): string { return $GLOBALS['rccScripts'][$id] ?? ''; }
function IPS_CreateScript(int $type): int
{
    $id = ++$GLOBALS['rccNextId'];
    IPS_SetScriptContent($id, '<?php return [];');
    return $id;
}
function IPS_GetParent(int $id): int { return 10; }
function IPS_SetParent(int $id, int $parent): void {}
function IPS_SetName(int $id, string $name): void {}

require dirname(__DIR__) . '/RoomsCatalogConfigurator/module.php';

function v3Assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function v3Private(RoomsCatalogConfigurator $instance, string $method, array $args = []): mixed
{
    return (new ReflectionMethod(RoomsCatalogConfigurator::class, $method))->invokeArgs($instance, $args);
}
function v3Rows(RoomsCatalogConfigurator $instance, array $catalog): array
{
    return v3Private($instance, 'buildFlatEntriesFromRooms', [
        v3Private($instance, 'extractRoomsFromCatalog', [$catalog])
    ]);
}
function v3Lost(RoomsCatalogConfigurator $instance, array $before, array $after): array
{
    $errors = [];
    (new ReflectionMethod(RoomsCatalogConfigurator::class, 'findLostCatalogFields'))
        ->invokeArgs($instance, [$before, $after, 'catalog', &$errors]);
    return $errors;
}
function v3Fields(array $form): array
{
    $names = [];
    $walk = static function (array $items) use (&$walk, &$names): void {
        foreach ($items as $item) {
            if (isset($item['name'])) $names[] = $item['name'];
            if (isset($item['items']) && is_array($item['items'])) $walk($item['items']);
        }
    };
    $walk($form);
    return $names;
}

$room = [
    'display' => 'Wohnzimmer', 'floor' => 'EG',
    'aliases' => ['Stube', 'Wohnbereich'],
    'futureRoom' => ['nested' => ['never' => 'delete']],
    'domains' => [
        'licht' => [
            'switches' => [
                'decke' => [
                    'title' => 'Deckenlicht', 'state' => '12345', 'toggle' => 23456,
                    'synonyms' => ['Decke', 'Lampe'], 'emptyArray' => [],
                    'stringJson' => '[literal string]',
                    'custom' => ['zero' => 0, 'flag' => false],
                    'kiEnabled' => false, 'kiReadOnly' => true
                ]
            ],
            'display' => 'Lichtdaten'
        ],
        'jalousie' => [
            'sued' => [
                'title' => 'Jalousie Sued', 'wert' => 34567,
                'kiEnabled' => false, 'kiReadOnly' => true,
                'futureDevice' => ['deep' => ['x' => 1]]
            ]
        ]
    ]
];
$catalog = [
    'global' => ['display' => 'Global', 'domains' => ['jalousie' => ['open_close_var' => 45678]]],
    'wohnzimmer' => $room,
    'customRoot' => ['config' => 'remain']
];
$instance = new RoomsCatalogConfigurator();
$rows = v3Rows($instance, $catalog);
v3Assert(count($rows) === 2, 'Unexpected device count');
$same = v3Private($instance, 'rebuildRoomsCatalogFromEntries', [$rows, $catalog]);
v3Assert(v3Lost($instance, $catalog, $same) === [], 'Legacy roundtrip lost fields');
v3Assert(!array_key_exists('rooms', $same), 'Plain root was converted');
v3Assert(!isset($same['wohnzimmer']['domains']['licht']['switches']['decke']['ai']), 'Legacy model migrated implicitly');
v3Assert($same['wohnzimmer']['domains']['licht']['switches']['decke']['state'] === '12345', 'Numeric string changed type');
v3Assert($same['wohnzimmer']['domains']['licht']['switches']['decke']['stringJson'] === '[literal string]', 'JSON-like text changed type');

$rows[0]['aiType'] = 'light';
$rows[0]['aiPowerId'] = 12345;
$rows[0]['aiControlId'] = 23456;
$rows[0]['aiOnValue'] = 'true';
$rows[0]['aiOffValue'] = 'false';
$extended = v3Private($instance, 'rebuildRoomsCatalogFromEntries', [$rows, $catalog]);
$light = $extended['wohnzimmer']['domains']['licht']['switches']['decke'];
v3Assert($light['state'] === '12345' && $light['toggle'] === 23456, 'Legacy control fields changed');
v3Assert($light['ai']['version'] === 1 && $light['ai']['type'] === 'light', 'Versioned AI model missing');
v3Assert($light['ai']['variables']['power'] === 12345 && $light['ai']['variables']['control'] === 23456, 'Multiple variables missing');
v3Assert($light['ai']['stateMapping']['on'] === true && $light['ai']['stateMapping']['off'] === false, 'Boolean state mapping changed');
v3Assert(v3Lost($instance, $catalog, $extended) === [], 'Extended model lost legacy values');

$extended['wohnzimmer']['domains']['licht']['switches']['decke']['ai']['futureFeature'] = ['nested' => ['a' => 1]];
$extended['wohnzimmer']['domains']['licht']['switches']['decke']['ai']['variables']['futureVar'] = 56789;
$extendedRows = v3Rows($instance, $extended);
$extendedRows[0]['title'] = 'Deckenlicht neu';
$again = v3Private($instance, 'rebuildRoomsCatalogFromEntries', [$extendedRows, $extended]);
$expectedExtended = $extended;
$expectedExtended['wohnzimmer']['domains']['licht']['switches']['decke']['title'] = 'Deckenlicht neu';
v3Assert(v3Lost($instance, $expectedExtended, $again) === [], 'Unknown AI fields were lost');
v3Assert($again['wohnzimmer']['domains']['licht']['switches']['decke']['title'] === 'Deckenlicht neu', 'Edited title not saved');

$wrapped = ['schema' => 'keep', 'rooms' => ['wohnzimmer' => $room]];
$wrappedRows = v3Rows($instance, $wrapped);
$wrappedAgain = v3Private($instance, 'rebuildRoomsCatalogFromEntries', [$wrappedRows, $wrapped]);
v3Assert(isset($wrappedAgain['rooms']['wohnzimmer']) && !isset($wrappedAgain['wohnzimmer']), 'Wrapped catalog structure lost');
v3Assert(v3Lost($instance, $wrapped, $wrappedAgain) === [], 'Wrapped legacy data lost');

$instance->WriteAttributeString('RuntimeEntries', json_encode($extendedRows, JSON_THROW_ON_ERROR));
$lightForm = json_decode($instance->GetEntryEditForm('wohnzimmer', 'licht', 'switches', 'decke'), true, 512, JSON_THROW_ON_ERROR);
$lightFields = v3Fields($lightForm);
v3Assert(in_array('aiType', $lightFields, true) && in_array('aiPowerId', $lightFields, true) && in_array('aiControlId', $lightFields, true), 'Light editor incomplete');
v3Assert(!in_array('ai', $lightFields, true), 'Raw AI JSON should not be edited directly');
$instance->WriteAttributeString('RuntimeEntries', json_encode(v3Rows($instance, $catalog), JSON_THROW_ON_ERROR));
$blindForm = json_decode($instance->GetEntryEditForm('wohnzimmer', 'jalousie', 'jalousie', 'sued'), true, 512, JSON_THROW_ON_ERROR);
$blindFields = v3Fields($blindForm);
v3Assert(in_array('aiPositionId', $blindFields, true) && in_array('aiTiltId', $blindFields, true)
    && in_array('aiOpenValue', $blindFields, true) && in_array('aiClosedValue', $blindFields, true), 'Blind editor incomplete');

IPS_SetScriptContent(20, '<?php return ' . var_export($catalog, true) . ';');
IPS_SetScriptContent(21, '<?php return ' . var_export($extended, true) . ';');
$instance->properties = ['RoomsCatalogScriptID' => 20, 'RoomsCatalogEditScriptID' => 21];
$instance->WriteAttributeString('RuntimeEntries', json_encode($extendedRows, JSON_THROW_ON_ERROR));
$partial = [
    'roomKey' => 'wohnzimmer', 'domain' => 'licht', 'group' => 'switches',
    'key' => 'decke', 'title' => 'Deckenlicht umbenannt'
];
$instance->SaveToEdit(json_encode($partial, JSON_THROW_ON_ERROR));
$saved = require $GLOBALS['rccTemp'] . '/scripts/21.php';
$savedLight = $saved['wohnzimmer']['domains']['licht']['switches']['decke'];
v3Assert($savedLight['ai']['futureFeature']['nested']['a'] === 1, 'Partial IPSList save dropped future AI fields');
v3Assert($savedLight['state'] === '12345' && $savedLight['title'] === 'Deckenlicht umbenannt', 'Partial row dropped old data');

// Die bewusste Gesamtkopie uebernimmt auch unvollstaendige Jalousien.
$withBlindAI = $saved;
$withBlindAI['wohnzimmer']['domains']['jalousie']['sued']['ai'] = [
    'version' => 1, 'type' => 'blind', 'variables' => ['position' => 34567]
];
$withBlindAI['wohnzimmer']['domains']['jalousie']['sued']['kiEnabled'] = true;
IPS_SetScriptContent(21, '<?php return ' . var_export($withBlindAI, true) . ';');
$originalProd = IPS_GetScriptContent(20);
ob_start();$instance->SaveEditToProd();$publishedIncomplete=ob_get_clean();
v3Assert(str_contains($publishedIncomplete,'ERFOLG')
    && IPS_GetScriptContent(20)===IPS_GetScriptContent(21),
    'An incomplete blind must not block deliberate full EDIT-to-PROD copy');
$firstBackup=$GLOBALS['rccNextId'];
v3Assert(IPS_GetScriptContent($firstBackup)===$originalProd,
    'Original PROD was not backed up before incomplete catalog copy');

$withBlindAI['wohnzimmer']['domains']['jalousie']['sued']['ai']['stateMapping'] = ['open' => 0, 'closed' => 100];
IPS_SetScriptContent(21, '<?php return ' . var_export($withBlindAI, true) . ';');
$originalProd = IPS_GetScriptContent(20);
$GLOBALS['rccBackupFails'] = true;
ob_start(); $instance->SaveEditToProd(); $failed = ob_get_clean();
v3Assert(str_contains($failed, 'Backup'), 'Failed backup not detected');
v3Assert(IPS_GetScriptContent(20) === $originalProd, 'PROD changed without verified backup');
$GLOBALS['rccBackupFails'] = false;
ob_start(); $instance->SaveEditToProd(); $published = ob_get_clean();
v3Assert(str_contains($published, 'ERFOLG'), 'Whole EDIT catalog not published');
v3Assert(IPS_GetScriptContent($GLOBALS['rccNextId']) === $originalProd, 'Backup content mismatch');
v3Assert((require $GLOBALS['rccTemp'] . '/scripts/20.php') === $withBlindAI, 'Publish modified EDIT data');

foreach (array_keys($GLOBALS['rccScripts']) as $id) @unlink($GLOBALS['rccTemp'] . '/scripts/' . $id . '.php');
@rmdir($GLOBALS['rccTemp'] . '/scripts');
@rmdir($GLOBALS['rccTemp']);
echo "PASS RoomsCatalog V3.0: typed device editor, lossless legacy, partial saves, validation, backup\n";
