<?php
declare(strict_types=1);

/** V3.1: Räume/Etagen/Aliase, IP-Symcon-List-Callbacks und EDIT/PROD-Sicherheit. */
class IPSList implements ArrayAccess, IteratorAggregate
{
    public function __construct(private array $row) {}
    public function offsetExists(mixed $offset): bool { return array_key_exists($offset, $this->row); }
    public function offsetGet(mixed $offset): mixed { return $this->row[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->row[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->row[$offset]); }
    public function getIterator(): Traversable { return new ArrayIterator($this->row); }
}
class IPSModule
{
    public array $properties = [];
    public array $updated = [];
    private array $attrs = [];
    public function ReadPropertyInteger(string $key): int { return (int)($this->properties[$key] ?? 0); }
    public function ReadAttributeString(string $key): string { return (string)($this->attrs[$key] ?? ''); }
    public function WriteAttributeString(string $key, string $value): void { $this->attrs[$key] = $value; }
    public function UpdateFormField(string $name, string $field, mixed $value): void { $this->updated[$name . '.' . $field] = $value; }
    public function ReloadForm(): void { throw new RuntimeException('Room editing must not collapse the form'); }
}

$GLOBALS['v31Temp'] = sys_get_temp_dir() . '/rcc-v31-' . bin2hex(random_bytes(5));
mkdir($GLOBALS['v31Temp'] . '/scripts', 0700, true);
$GLOBALS['v31Scripts'] = [];
$GLOBALS['v31NextId'] = 1000;
function IPS_ScriptExists(int $id): bool { return array_key_exists($id, $GLOBALS['v31Scripts']); }
function IPS_GetKernelDir(): string { return $GLOBALS['v31Temp'] . '/'; }
function IPS_GetScriptFile(int $id): string { return $id . '.php'; }
function IPS_VariableExists(int $id): bool { return in_array($id, [12345, 23456], true); }
function IPS_SetScriptContent(int $id, string $code): bool
{
    $GLOBALS['v31Scripts'][$id] = $code;
    file_put_contents($GLOBALS['v31Temp'] . '/scripts/' . $id . '.php', $code);
    return true;
}
function IPS_GetScriptContent(int $id): string { return $GLOBALS['v31Scripts'][$id] ?? ''; }
function IPS_CreateScript(int $type): int { $id = ++$GLOBALS['v31NextId']; IPS_SetScriptContent($id, '<?php return [];'); return $id; }
function IPS_GetParent(int $id): int { return 10; }
function IPS_SetParent(int $id, int $parent): void {}
function IPS_SetName(int $id, string $name): void {}

require dirname(__DIR__) . '/RoomsCatalogConfigurator/module.php';
function ok31(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function read31(int $id): array { return require $GLOBALS['v31Temp'] . '/scripts/' . $id . '.php'; }
function priv31(RoomsCatalogConfigurator $mod, string $method, array $args = []): mixed
{
    return (new ReflectionMethod(RoomsCatalogConfigurator::class, $method))->invokeArgs($mod, $args);
}

$base = [
    'global' => ['display' => 'Global', 'domains' => ['jalousie' => ['open_close_var' => 12345]]],
    'wohnzimmer' => [
        'display' => 'Wohnzimmer', 'floor' => 'EG', 'aliases' => ['Stube'],
        'futureMetadata' => ['keep' => ['key' => 'value']],
        'domains' => ['licht' => ['switches' => [
            'decke' => ['title' => 'Decke', 'state' => 12345, 'toggle' => 23456,
                'kiEnabled' => false, 'kiReadOnly' => true,
                'ai' => ['version' => 1, 'type' => 'light', 'variables' => ['power' => 12345],
                    'futureProperty' => ['untouched' => true]]]
        ]]]
    ],
    'kueche' => [
        'display' => 'Küche', 'floor' => 'EG', 'aliases' => ['Kochbereich'],
        'domains' => []
    ]
];
IPS_SetScriptContent(20, '<?php return ' . var_export($base, true) . ';');
IPS_SetScriptContent(21, '<?php return ' . var_export($base, true) . ';');
$mod = new RoomsCatalogConfigurator();
$GLOBALS['v31Mod'] = $mod;
$mod->properties = ['RoomsCatalogScriptID' => 20, 'RoomsCatalogEditScriptID' => 21];
$mod->WriteAttributeString('RuntimeEntries', json_encode(priv31($mod, 'buildFlatEntriesFromRooms', [
    priv31($mod, 'extractRoomsFromCatalog', [$base])
]), JSON_THROW_ON_ERROR));
$mod->WriteAttributeString('FilterAI', 'all');

function RCC_SaveRoomToEdit(int $id, string $json): void
{
    ok31($id === 77, 'Room edit callback ID mismatch');
    $GLOBALS['v31Mod']->SaveRoomToEdit($json);
}
function RCC_CreateRoom(int $id, string $json): void
{
    ok31($id === 77, 'Room creation callback ID mismatch');
    $GLOBALS['v31Mod']->CreateRoom($json);
}

$form = json_decode($mod->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$panel = null;
$roomsList = null;
$createAction = null;
$roomDiffList = null;
foreach ($form['elements'] as $element) {
    if (str_contains($element['caption'] ?? '', 'Raeume & Etagen')) {
        $panel = $element;
        foreach ($element['items'] as $item) {
            if (($item['name'] ?? '') === 'Rooms') $roomsList = $item;
            if (($item['caption'] ?? '') === 'NEUEN RAUM IM EDIT ANLEGEN') $createAction = $item['onClick'];
        }
    }
    if (str_contains($element['caption'] ?? '', 'Unterschiede EDIT')) {
        foreach ($element['items'] as $item) if (($item['name'] ?? '') === 'RoomsDiff') $roomDiffList = $item;
    }
}
// The room diff is intentionally now inside the collapsed expert panel.
$findRoomsDiff31 = function(array $nodes)use(&$findRoomsDiff31,&$roomDiffList):void {
    foreach ($nodes as $node) {
        if (($node['name']??'')==='RoomsDiff') $roomDiffList=$node;
        if (isset($node['items']) && is_array($node['items'])) $findRoomsDiff31($node['items']);
    }
};
$findRoomsDiff31($form['elements']);
ok31(($panel['expanded'] ?? false) === true, 'Rooms panel must start expanded');
ok31(is_array($roomsList) && count($roomsList['values']) === 2, 'Rooms list should exclude global and include empty rooms');
ok31($roomDiffList===null, 'Obsolete room diff UI must be removed from simple configurator');
ok31(str_contains($roomsList['onEdit'], '$Rooms["roomKey"]'), 'Callback not reading IPSList row fields');
ok31(!str_contains($roomsList['onEdit'], 'array_filter($Rooms'), 'IPSList must not be passed to array_filter');

$id = 77;
$sourceRoom = null;
foreach ($roomsList['values'] as $row) if ($row['roomKey'] === 'wohnzimmer') $sourceRoom = $row;
ok31(is_array($sourceRoom), 'Living room missing from editor');
$sourceRoom['display'] = 'Wohnbereich';
$sourceRoom['floor'] = 'OG';
$sourceRoom['aliases'] = 'Stube, Wohnen, Stube';
$Rooms = new IPSList($sourceRoom);
ob_start(); eval($roomsList['onEdit']); $saveText = ob_get_clean();
ok31(str_contains($saveText, 'gespeichert'), 'Room edit did not run');
$edited = read31(21);
ok31($edited['wohnzimmer']['display'] === 'Wohnbereich' && $edited['wohnzimmer']['floor'] === 'OG', 'Room name/floor not saved');
ok31($edited['wohnzimmer']['aliases'] === ['Stube', 'Wohnen'], 'Room aliases not normalized and deduplicated');
ok31($edited['wohnzimmer']['futureMetadata'] === $base['wohnzimmer']['futureMetadata'], 'Unknown room metadata lost');
ok31($edited['wohnzimmer']['domains'] === $base['wohnzimmer']['domains'], 'Devices changed when editing a room');
ok31(read31(20) === $base, 'PROD changed before explicit publish');
ok31(isset($mod->updated['Rooms.values']) && isset($mod->updated['RoomsDiff.values']), 'Room tables did not refresh');
ok31(isset($mod->updated['FilterRoom.options']) && isset($mod->updated['Entries.values']), 'Device view and room filter did not refresh');
$deviceRows = json_decode($mod->updated['Entries.values'], true, 512, JSON_THROW_ON_ERROR);
ok31(($deviceRows[0]['roomLabel'] ?? '') === 'Wohnbereich', 'Room display not reflected in devices');

// Keine mehrdeutigen Raum-Aliase zulassen; weder EDIT noch PROD verändern.
$beforeCollision = IPS_GetScriptContent(21);
ob_start(); $mod->SaveRoomToEdit(json_encode(['roomKey'=>'wohnzimmer', 'aliases'=>'Kochbereich'], JSON_THROW_ON_ERROR)); $collision = ob_get_clean();
ok31(str_contains($collision, 'nicht gespeichert'), 'Conflicting room alias was accepted');
ok31(IPS_GetScriptContent(21) === $beforeCollision, 'Conflicting alias changed EDIT');

$NewRoomKey = 'gaestezimmer';
$NewRoomDisplay = 'Gästezimmer';
$NewRoomFloor = 'OG';
$NewRoomAliases = 'Gäste, Gastzimmer';
ob_start(); eval($createAction); $created = ob_get_clean();
ok31(str_contains($created, 'angelegt'), 'New room button callback failed');
$editNew = read31(21);
ok31($editNew['gaestezimmer']['domains'] === [] && $editNew['gaestezimmer']['aliases'] === ['Gäste','Gastzimmer'], 'New empty room malformed');
ok31(read31(20) === $base, 'Creating EDIT room changed PROD');

// Ein späterer Geräte-Speichervorgang darf neue, noch leere Räume nicht entfernen.
$roundTrip = priv31($mod, 'rebuildRoomsCatalogFromEntries', [
    priv31($mod, 'buildFlatEntriesFromRooms', [priv31($mod, 'extractRoomsFromCatalog', [$editNew])]),
    $editNew
]);
ok31($roundTrip['gaestezimmer'] === $editNew['gaestezimmer'], 'Empty room was lost during device roundtrip');
ok31($roundTrip['wohnzimmer']['aliases'] === ['Stube','Wohnen'], 'Room aliases lost during device roundtrip');

$oldProd = IPS_GetScriptContent(20);
ob_start(); $mod->SaveEditToProd(); $publish = ob_get_clean();
ok31(str_contains($publish, 'ERFOLG'), 'All rooms and devices must be published 1:1');
ok31(read31(20) === $editNew, 'PROD mismatch after explicit publish');
ok31(IPS_GetScriptContent($GLOBALS['v31NextId']) === $oldProd, 'Backup lost original PROD');

// Ein bestehender rooms-Wrapper muss erhalten bleiben.
$wrapped = ['meta' => 'keep', 'rooms' => ['wohnzimmer' => $base['wohnzimmer']]];
IPS_SetScriptContent(21, '<?php return ' . var_export($wrapped, true) . ';');
ob_start(); $mod->SaveRoomToEdit(json_encode(['roomKey'=>'wohnzimmer','display'=>'Stube','aliases'=>'Wohnbereich'], JSON_THROW_ON_ERROR)); ob_end_clean();
ob_start(); $mod->CreateRoom(json_encode(['roomKey'=>'balkon','display'=>'Balkon','floor'=>'OG'], JSON_THROW_ON_ERROR)); ob_end_clean();
$newWrapped = read31(21);
ok31($newWrapped['meta'] === 'keep' && isset($newWrapped['rooms']['balkon'])
    && !isset($newWrapped['balkon']) && $newWrapped['rooms']['wohnzimmer']['display'] === 'Stube', 'Wrapped root changed shape');

foreach (array_keys($GLOBALS['v31Scripts']) as $scriptId) @unlink($GLOBALS['v31Temp'] . '/scripts/' . $scriptId . '.php');
@rmdir($GLOBALS['v31Temp'] . '/scripts');
@rmdir($GLOBALS['v31Temp']);
echo "PASS RoomsCatalog V3.1: central rooms/floors/aliases, IPSList, preview, EDIT/PROD and backup\n";
