<?php
declare(strict_types=1);

/** V3.3: begrenzte Geraeteerkennung mit expliziter Uebernahme nur nach EDIT. */
class IPSModule
{
    public array $properties = [];
    public array $updates = [];
    private array $attrs = [];
    public function ReadPropertyInteger(string $key): int { return (int)($this->properties[$key] ?? 0); }
    public function ReadPropertyString(string $key): string { return (string)($this->properties[$key] ?? ''); }
    public function ReadAttributeString(string $key): string { return (string)($this->attrs[$key] ?? ''); }
    public function WriteAttributeString(string $key, string $value): void { $this->attrs[$key] = $value; }
    public function UpdateFormField(string $name, string $field, mixed $value): void { $this->updates[$name . '.' . $field] = $value; }
    public function ReloadForm(): void { throw new RuntimeException('Discovery must not reload and close expanded forms'); }
}

$GLOBALS['v33Temp'] = sys_get_temp_dir() . '/rcc-v33-' . bin2hex(random_bytes(5));
mkdir($GLOBALS['v33Temp'] . '/scripts', 0700, true);
$GLOBALS['v33Scripts'] = [];
$GLOBALS['v33Objects'] = [
    1000=>['ObjectType'=>0,'ParentID'=>0,'ObjectName'=>'Haus'],
    1100=>['ObjectType'=>0,'ParentID'=>1000,'ObjectName'=>'Stube'],
    1200=>['ObjectType'=>0,'ParentID'=>1000,'ObjectName'=>'Unbekanntes Zimmer'],
    2001=>['ObjectType'=>1,'ParentID'=>1100,'ObjectName'=>'Deckenlicht'],
    50101=>['ObjectType'=>2,'ParentID'=>2001,'ObjectName'=>'Status','ObjectIdent'=>'STATE'],
    50102=>['ObjectType'=>2,'ParentID'=>2001,'ObjectName'=>'Schalten','ObjectIdent'=>'SWITCH'],
    2002=>['ObjectType'=>1,'ParentID'=>1100,'ObjectName'=>'Schon erfasst'],
    60101=>['ObjectType'=>2,'ParentID'=>2002,'ObjectName'=>'Status','ObjectIdent'=>'STATE'],
    2003=>['ObjectType'=>1,'ParentID'=>1200,'ObjectName'=>'Fensterkontakt'],
    50301=>['ObjectType'=>2,'ParentID'=>2003,'ObjectName'=>'Fensterkontakt','ObjectIdent'=>'CONTACT'],
    4000=>['ObjectType'=>0,'ParentID'=>0,'ObjectName'=>'Andere Wurzel'],
    4001=>['ObjectType'=>1,'ParentID'=>4000,'ObjectName'=>'Aussenlicht'],
    40301=>['ObjectType'=>2,'ParentID'=>4001,'ObjectName'=>'Status','ObjectIdent'=>'STATE']
];
$GLOBALS['v33Variables'] = [
    50101=>['VariableType'=>0,'VariableProfile'=>'~Switch','VariableAction'=>0],
    50102=>['VariableType'=>0,'VariableProfile'=>'~Switch','VariableAction'=>2001],
    60101=>['VariableType'=>0,'VariableProfile'=>'~Switch','VariableAction'=>0],
    50301=>['VariableType'=>0,'VariableProfile'=>'~Window','VariableAction'=>0],
    40301=>['VariableType'=>0,'VariableProfile'=>'~Switch','VariableAction'=>0]
];
function IPS_ScriptExists(int $id): bool { return isset($GLOBALS['v33Scripts'][$id]); }
function IPS_GetKernelDir(): string { return $GLOBALS['v33Temp'] . '/'; }
function IPS_GetScriptFile(int $id): string { return $id . '.php'; }
function IPS_SetScriptContent(int $id, string $php): bool
{
    $GLOBALS['v33Scripts'][$id] = $php;
    file_put_contents($GLOBALS['v33Temp'] . '/scripts/' . $id . '.php', $php);
    return true;
}
function IPS_GetScriptContent(int $id): string { return $GLOBALS['v33Scripts'][$id] ?? ''; }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['v33Variables'][$id]); }
function IPS_ObjectExists(int $id): bool { return isset($GLOBALS['v33Objects'][$id]); }
function IPS_GetObject(int $id): array { return $GLOBALS['v33Objects'][$id] ?? []; }
function IPS_GetChildrenIDs(int $id): array
{
    $children = [];
    foreach ($GLOBALS['v33Objects'] as $childId=>$object) if (($object['ParentID'] ?? 0) === $id) $children[] = $childId;
    return $children;
}
function IPS_GetVariable(int $id): array { return $GLOBALS['v33Variables'][$id] ?? []; }

require dirname(__DIR__) . '/RoomsCatalogConfigurator/module.php';
function check33(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$catalog = [
    'wohnzimmer'=>[
        'display'=>'Wohnzimmer','floor'=>'EG','aliases'=>['Stube'],
        'extraRoomInfo'=>['doNotLose'=>true],
        'domains'=>['licht'=>['switches'=>['bestand'=>[
            'title'=>'Bestehendes Licht','state'=>60101,
            'kiEnabled'=>true,'kiReadOnly'=>true,'extraDeviceInfo'=>'keep'
        ]]]]
    ]
];
IPS_SetScriptContent(30, '<?php return ' . var_export($catalog, true) . ';');
IPS_SetScriptContent(31, '<?php return ' . var_export($catalog, true) . ';');
$mod = new RoomsCatalogConfigurator();
$mod->properties = [
    'RoomsCatalogScriptID'=>30,
    'RoomsCatalogEditScriptID'=>31,
    'DiscoveryRootId'=>1000,
    'DiscoveryExtraRootIds'=>''
];
$mod->WriteAttributeString('RuntimeEntries', '[]');
$mod->WriteAttributeString('FilterAI', 'all');
$form = json_decode($mod->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$ui=json_encode($form,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
check33(str_contains($ui,'IP-Symcon-Geraeteinventur')
    && str_contains($ui,'RCC_ScanNewDevices')
    && str_contains($ui,'DiscoveryRootId')
    && str_contains($ui,'DiscoveryDomainOverride')
    && str_contains($ui,'DiscoveryGroupOverride')
    && str_contains($ui,'DiscoveryUseAI')
    && str_contains($ui,'EDIT NACH PROD UEBERNEHMEN'),
    'Guided device-import settings must now be available in the module UI');

$editBefore = IPS_GetScriptContent(31);
ob_start(); $mod->ScanNewDevices(); $scanMessage = ob_get_clean();
$preview = json_decode($mod->ReadAttributeString('DiscoveryCandidates'), true, 512, JSON_THROW_ON_ERROR);
$candidates = [];
foreach ($preview['rows'] as $candidate) $candidates[$candidate['sourceId']] = $candidate;
check33(count($candidates) === 2 && isset($candidates[2001], $candidates[2003]), 'Scanner did not respect roots and known-device IDs');
check33(!isset($candidates[2002]) && !isset($candidates[4001]), 'Known or outside-root instance was suggested');
check33($candidates[2001]['roomKey'] === 'wohnzimmer' && $candidates[2001]['deviceType'] === 'light'
    && $candidates[2001]['confidence'] === 'Hoch', 'Room alias or light detection failed');
check33($candidates[2003]['roomKey'] === '' && $candidates[2003]['confidence'] === 'Pruefen', 'Ambiguous room was assigned');
check33(!$candidates[2001]['selected'] && !$candidates[2003]['selected'], 'Candidates must not start selected');
check33(IPS_GetScriptContent(31) === $editBefore && (require $GLOBALS['v33Temp'] . '/scripts/30.php') === $catalog,
    'Scanning changed EDIT or PROD');
check33(isset($mod->updates['DiscoveredDevices.values']) && str_contains($scanMessage, 'Kandidaten'), 'Discovery list did not update');

// Sammelauswahl nur fuer eindeutige Geraete; unsichere bleiben unmarkiert.
$mod->SelectDiscoveredDevices(true);
$marked = json_decode($mod->ReadAttributeString('DiscoveryCandidates'), true, 512, JSON_THROW_ON_ERROR);
check33($marked['rows'][0]['selected'] === true && $marked['rows'][1]['selected'] === false, 'Unsafe device auto-selected');
$mod->SelectDiscoveredDevices(false);
$mod->UpdateDiscoveryCandidate(json_encode([
    'sourceId'=>2001,'selected'=>true,'roomKey'=>'wohnzimmer','title'=>'Deckenlicht NEU','deviceType'=>'light'
], JSON_THROW_ON_ERROR));
$marked = json_decode($mod->ReadAttributeString('DiscoveryCandidates'), true, 512, JSON_THROW_ON_ERROR);
check33($marked['rows'][0]['selected'] && $marked['rows'][0]['title'] === 'Deckenlicht NEU', 'List row changes not retained');
ob_start(); $mod->ApplyDiscoveredDevices(); $applied = ob_get_clean();
check33(str_contains($applied, '1 neue Geraete'), 'Confirmed candidate was not imported');
$edited = require $GLOBALS['v33Temp'] . '/scripts/31.php';
$newKey = 'deckenlichtneu_2001';
$newDevice = $edited['wohnzimmer']['domains']['licht']['switches'][$newKey] ?? [];
check33(($newDevice['ai']['type'] ?? '') === 'light'
    && ($newDevice['ai']['variables']['power'] ?? 0) === 50101
    && ($newDevice['ai']['variables']['control'] ?? 0) === 50102,
    'Discovered device AI variables not stored in V3.0 model');
check33(($newDevice['state'] ?? 0) === 50101 && ($newDevice['toggle'] ?? 0) === 50102,
    'Legacy light bindings missing');
check33(($newDevice['discoverySourceId'] ?? 0) === 2001, 'Source ID lost, rescan duplicates possible');
check33(($newDevice['kiEnabled'] ?? null) === false && ($newDevice['kiReadOnly'] ?? null) === true,
    'New device got KI control rights');
check33(($edited['wohnzimmer']['extraRoomInfo']['doNotLose'] ?? false)
    && ($edited['wohnzimmer']['domains']['licht']['switches']['bestand']['extraDeviceInfo'] ?? '') === 'keep',
    'Existing data lost after EDIT import');
check33((require $GLOBALS['v33Temp'] . '/scripts/30.php') === $catalog, 'PROD was modified during EDIT import');

ob_start(); $mod->ScanNewDevices(); ob_end_clean();
$rescan = json_decode($mod->ReadAttributeString('DiscoveryCandidates'), true, 512, JSON_THROW_ON_ERROR);
check33(count($rescan['rows']) === 1 && $rescan['rows'][0]['sourceId'] === 2003,
    'Already imported device was suggested again');
$mod->UpdateDiscoveryCandidate(json_encode(['sourceId'=>2003,'selected'=>true,'roomKey'=>'wohnzimmer'], JSON_THROW_ON_ERROR));
$beforeStale = IPS_GetScriptContent(31);
IPS_SetScriptContent(31, $beforeStale . "\n// External edit");
ob_start(); $mod->ApplyDiscoveredDevices(); $stale = ob_get_clean();
check33(str_contains($stale, 'veraltet') && IPS_GetScriptContent(31) === $beforeStale . "\n// External edit",
    'Stale scan overwrote an external EDIT change');

$mod->properties['DiscoveryRootId'] = 0;
ob_start(); $mod->ScanNewDevices(); $noRoot = ob_get_clean();
check33(str_contains($noRoot, 'Suchwurzel'), 'Scanner searched without configured roots');

foreach (array_keys($GLOBALS['v33Scripts']) as $id) @unlink($GLOBALS['v33Temp'] . '/scripts/' . $id . '.php');
@rmdir($GLOBALS['v33Temp'] . '/scripts');
@rmdir($GLOBALS['v33Temp']);
echo "PASS RoomsCatalog V3.3: scoped discovery, alias room matching, review, duplicate guard, stale guard, EDIT-only import\n";
