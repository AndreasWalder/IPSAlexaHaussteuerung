<?php
declare(strict_types=1);

/** V3.3.3: bestehende Konfiguration exakt übernehmen; Tabs nur inventarisieren. */
require_once dirname(__DIR__) . '/IPSAlexaHaussteuerung/src/SystemConfigurationManager.php';

function sc333_check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$legacyCatalog = [
    'global' => ['display' => 'Global', 'domains' => [
        'robot' => ['tabs' => [
            'Roborock' => 53544,
            'Dock' => ['id' => 44390, 'order' => 3, 'synonyms' => ['Andocken'],
                'specialLegacyKey' => ['must' => 'survive']],
        ]],
        'list' => ['tabs' => ['Einkaufsliste' => ['nodeId' => 38806]]],
    ]],
    'wohnzimmer' => ['display' => 'Wohnzimmer', 'domains' => [
        'licht' => ['switches' => ['decke' => ['state' => 12]]],
    ]],
    'heizraum' => ['display' => 'Heizraum', 'domains' => [
        'devices' => ['tabs' => ['Waschtrockner' => ['id' => 58379]]],
    ]],
];
$before = serialize($legacyCatalog);
$scan = IAHSystemConfigurationManager::inventoryNavigation($legacyCatalog);
sc333_check(count($scan['entries']) === 4, 'Device entries were included or tabs were lost');
sc333_check($scan['entries'][1]['legacyDefinition']['specialLegacyKey']['must'] === 'survive',
    'Tab-specific legacy metadata was lost');
sc333_check($scan['entries'][0]['objectId'] === 53544
    && $scan['entries'][2]['objectId'] === 38806, 'Numeric and nodeId tab references not detected');
sc333_check($scan['entries'][3]['roomKey'] === 'heizraum', 'Room-bound navigation was skipped');
$draft = IAHSystemConfigurationManager::navigationDraft($legacyCatalog);
sc333_check($draft['importStatus'] === 'review_required' && count($draft['tabs']) === 4,
    'Unreviewed navigation must never be marked ready for PROD');
sc333_check(serialize($legacyCatalog) === $before, 'Inventory or draft changed legacy data');
$conf = ['var' => ['Token' => 'SENSITIVE', 'ActionsEnabled' => true],
    'script' => ['Main' => 123], 'custom' => ['secret' => 'PRIVATE']];
$structure = IAHSystemConfigurationManager::configurationStructure($conf);
sc333_check($structure[0] === ['name' => 'var', 'entries' => 2],
    'Configuration metadata was not identified');
sc333_check(!str_contains(json_encode($structure), 'SENSITIVE')
    && !str_contains(json_encode($structure), 'PRIVATE'), 'Credentials leaked to inventory');

// Main module's staging methods are verified using a tiny simulated IPS object tree.
class IPSModule
{
    public int $InstanceID = 100;
    public array $properties = ['SystemConfigScriptId' => 501];
    private array $attributes = [];
    public array $updates = [];
    public function ReadPropertyInteger(string $name): int { return (int) ($this->properties[$name] ?? 0); }
    public function ReadAttributeString(string $name): string { return $this->attributes[$name] ?? ''; }
    public function WriteAttributeString(string $name, string $value): void { $this->attributes[$name] = $value; }
    public function UpdateFormField(string $name, string $field, mixed $value): void
    { $this->updates[$name . '.' . $field] = $value; }
}
$GLOBALS['sc333Next'] = 700;
$GLOBALS['sc333Objects'] = [
    200 => ['parent' => 100, 'name' => 'Einstellungen', 'ident' => 'iahSettings'],
    501 => ['parent' => 200, 'name' => 'SystemConfiguration', 'ident' => 'iahSystemConfiguration'],
    502 => ['parent' => 200, 'name' => 'RoomsCatalog', 'ident' => 'roomsCatalog'],
];
$source = "<?php\n// Custom comments and spacing survive\nreturn ['var'=>['Token'=>'PRIVATE']];\n";
$GLOBALS['sc333Scripts'] = [501 => $source, 502 => '<?php return ' . var_export($legacyCatalog, true) . ';'];
$GLOBALS['sc333Dir'] = sys_get_temp_dir() . '/sc333-' . bin2hex(random_bytes(4));
mkdir($GLOBALS['sc333Dir']);
foreach ($GLOBALS['sc333Scripts'] as $id => $content) {
    file_put_contents($GLOBALS['sc333Dir'] . '/' . $id . '.php', $content);
}
function IPS_GetObjectIDByIdent(string $ident, int $parent): int|false
{
    foreach ($GLOBALS['sc333Objects'] as $id => $obj) {
        if ($obj['parent'] === $parent && $obj['ident'] === $ident) return $id;
    }
    return false;
}
function IPS_GetObjectIDByName(string $name, int $parent): int|false
{
    foreach ($GLOBALS['sc333Objects'] as $id => $obj) {
        if ($obj['parent'] === $parent && $obj['name'] === $name) return $id;
    }
    return false;
}
function IPS_CreateScript(int $type): int
{
    $id = ++$GLOBALS['sc333Next'];
    $GLOBALS['sc333Objects'][$id] = ['parent' => 0, 'name' => '', 'ident' => ''];
    return $id;
}
function IPS_SetParent(int $id, int $parent): void { $GLOBALS['sc333Objects'][$id]['parent'] = $parent; }
function IPS_SetName(int $id, string $name): void { $GLOBALS['sc333Objects'][$id]['name'] = $name; }
function IPS_SetIdent(int $id, string $ident): void { $GLOBALS['sc333Objects'][$id]['ident'] = $ident; }
function IPS_ScriptExists(int $id): bool { return array_key_exists($id, $GLOBALS['sc333Scripts']); }
function IPS_GetScriptContent(int $id): string { return $GLOBALS['sc333Scripts'][$id]; }
function IPS_GetScriptFile(int $id): string { return $GLOBALS['sc333Dir'] . '/' . $id . '.php'; }
function IPS_SetScriptContent(int $id, string $content): void
{
    $GLOBALS['sc333Scripts'][$id] = $content;
    file_put_contents(IPS_GetScriptFile($id), $content);
}

require_once dirname(__DIR__) . '/IPSAlexaHaussteuerung/module.php';
$module = new IPSAlexaHaussteuerung();
$method = new ReflectionMethod(IPSAlexaHaussteuerung::class, 'ensureSystemConfigurationStagingScripts');
$method->invoke($module, 200, 501);
$editId = IPS_GetObjectIDByIdent('iahSystemConfigurationEdit', 200);
$backupId = IPS_GetObjectIDByIdent('iahSystemConfigurationBackup', 200);
sc333_check($editId !== false && $backupId !== false, 'EDIT and Backup were not created');
sc333_check(IPS_GetScriptContent($editId) === $source, 'EDIT did not copy original PHP source exactly');
$backup = require IPS_GetScriptFile($backupId);
sc333_check($backup['snapshots'][0]['phpSource'] === $source
    && $backup['snapshots'][0]['sha256'] === hash('sha256', $source),
    'Initial backup is not lossless');
IPS_SetScriptContent($editId, '<?php return ["custom_edit"=>true];');
$initialBackup = IPS_GetScriptContent($backupId);
$module->properties['SystemConfigScriptId'] = 501;
$method->invoke($module, 200, 501);
sc333_check(IPS_GetScriptContent($editId) === '<?php return ["custom_edit"=>true];'
    && IPS_GetScriptContent($backupId) === $initialBackup
    && IPS_GetScriptContent(501) === $source, 'Module update overwrote EDIT, Backup or PROD');

$form = json_decode($module->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$ui = json_encode($form, JSON_THROW_ON_ERROR);
sc333_check(str_contains($ui, 'Navigation & Tabs')
    && str_contains($ui, 'IAH_PreviewSystemNavigationUpdate')
    && !str_contains($ui, 'IAH_ScanSystemConfiguration')
    && !str_contains($ui, 'IAH_CleanupSystemNavigation')
    && !str_contains($ui, 'RoomsCatalog automatisch bereinigen'),
    'Current navigation editor missing or retired migration tools still visible');
sc333_check(IPS_GetScriptContent(501) === $source,
    'Showing permanent navigation UI may not modify SystemConfiguration');

echo "SystemConfiguration staging/backup and permanent navigation UI without migrations: OK\n";
