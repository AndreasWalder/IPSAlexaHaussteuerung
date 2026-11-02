<?php
declare(strict_types=1);

/** V3.3.1: alle Typen, IPS-Modulmetadaten, Profile, Vollstaendigkeit und Vorlagen. */
class IPSModule
{
    public array $properties = [];
    public array $updates = [];
    private array $attrs = [];
    public function ReadPropertyInteger(string $key): int { return (int)($this->properties[$key] ?? 0); }
    public function ReadPropertyString(string $key): string { return (string)($this->properties[$key] ?? ''); }
    public function ReadAttributeString(string $key): string { return (string)($this->attrs[$key] ?? ''); }
    public function WriteAttributeString(string $key,string $value): void { $this->attrs[$key] = $value; }
    public function UpdateFormField(string $name,string $field,mixed $value): void { $this->updates[$name . '.' . $field] = $value; }
    public function ReloadForm(): void { throw new RuntimeException('Inventory must not collapse the form'); }
}
$GLOBALS['inventoryDir'] = sys_get_temp_dir() . '/rcc-inventory-' . bin2hex(random_bytes(5));
mkdir($GLOBALS['inventoryDir'] . '/scripts',0700,true);
$GLOBALS['inventoryScripts'] = [];
$GLOBALS['inventoryObjects'] = [
    1000=>['ObjectType'=>0,'ParentID'=>0,'ObjectName'=>'Haus'],
    1100=>['ObjectType'=>0,'ParentID'=>1000,'ObjectName'=>'Wohnzimmer'],
    1200=>['ObjectType'=>0,'ParentID'=>1000,'ObjectName'=>'WC']
];
$GLOBALS['inventoryVariables'] = [];
$GLOBALS['inventoryInstances'] = [];
function addInvInstance(int $id,string $name,string $module,string $moduleId,int $parent): void
{
    $GLOBALS['inventoryObjects'][$id] = ['ObjectType'=>1,'ParentID'=>$parent,'ObjectName'=>$name];
    $GLOBALS['inventoryInstances'][$id] = ['ModuleInfo'=>['ModuleID'=>$moduleId,'ModuleName'=>$module]];
}
function addInvVar(int $id,int $parent,string $name,string $ident,int $type,string $profile,bool $action=false): void
{
    $GLOBALS['inventoryObjects'][$id] = ['ObjectType'=>2,'ParentID'=>$parent,'ObjectName'=>$name,'ObjectIdent'=>$ident];
    $GLOBALS['inventoryVariables'][$id] = [
        'VariableType'=>$type,'VariableProfile'=>$profile,'VariableCustomProfile'=>'',
        'VariableAction'=>$action ? $parent : 0,'VariableCustomAction'=>0,
        'VariableUpdated'=>1793600000,'VariableChanged'=>1793590000,
        'VariableValue'=>'PRIVATE_VALUE_MUST_NOT_BE_CACHED'
    ];
}
addInvInstance(17426,'EG WC','KNX Rollladen','{BLIND-MODULE}',1200);
addInvVar(39138,17426,'Status','ST01',1,'BlindStatus');
addInvVar(14327,17426,'Aktion','CMD01',1,'BlindAction',true);
addInvVar(55194,17426,'Position','POS01',1,'~Shutter');
addInvVar(36899,17426,'Position (Lamelle)','TILT01',1,'~Intensity.100');
// Gleicher KNX-Modultyp, aber unklare Namen: soll bestaetigtes Mapping lernen.
addInvInstance(17427,'EG WC zweiter','KNX Rollladen','{BLIND-MODULE}',1200);
addInvVar(39238,17427,'Kanal A','ST01',1,'BlindStatus');
addInvVar(14427,17427,'Kanal B','CMD01',1,'BlindAction',true);
addInvVar(55294,17427,'Kanal C','POS01',1,'~Shutter');
addInvVar(36999,17427,'Kanal D','TILT01',1,'~Intensity.100');

$types = [
    [2001,'Deckenlicht','KNX Lichtaktor','light',50101,'Status','~Switch',0],
    [2002,'Dimmer','KNX Dimmer','dimmer',50201,'Helligkeit','~Intensity.100',1],
    [2003,'Heizung','KNX Thermostat','heating',50301,'Isttemperatur','~Temperature',2],
    [2004,'Fenster','Fensterkontakt','window',50401,'Fensterkontakt','~Window',0],
    [2005,'Steckdose','Shelly Plug','switch',50501,'Status','~Switch',0],
    [2006,'Stromzaehler','SmartMeter','energy',50601,'Energie','~Electricity',2],
    [2007,'Bewegung','Bewegungsmelder','motion',50701,'Bewegung','~Motion',0],
    [2008,'Ventilator','Lueftung','fan',50801,'Status','~Switch',0],
    [2009,'Unbekannt','Universalsensor','generic',50901,'Kanal X','',2]
];
foreach ($types as [$id,$name,$module,$expect,$varId,$varName,$profile,$kind]) {
    addInvInstance($id,$name,$module,'{MODULE-' . $id . '}',1100);
    addInvVar($varId,$id,$varName,'V' . $varId,$kind,$profile);
}
function IPS_ScriptExists(int $id): bool { return isset($GLOBALS['inventoryScripts'][$id]); }
function IPS_GetKernelDir(): string { return $GLOBALS['inventoryDir'] . '/'; }
function IPS_GetScriptFile(int $id): string { return $id . '.php'; }
function IPS_SetScriptContent(int $id,string $php): bool
{
    $GLOBALS['inventoryScripts'][$id] = $php;
    file_put_contents($GLOBALS['inventoryDir'] . '/scripts/' . $id . '.php',$php);
    return true;
}
function IPS_GetScriptContent(int $id): string { return $GLOBALS['inventoryScripts'][$id] ?? ''; }
function IPS_ObjectExists(int $id): bool { return isset($GLOBALS['inventoryObjects'][$id]); }
function IPS_GetObject(int $id): array { return $GLOBALS['inventoryObjects'][$id] ?? []; }
function IPS_GetChildrenIDs(int $parent): array
{
    return array_keys(array_filter($GLOBALS['inventoryObjects'],
        static fn(array $object): bool => $object['ParentID'] === $parent));
}
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['inventoryVariables'][$id]); }
function IPS_GetVariable(int $id): array { return $GLOBALS['inventoryVariables'][$id] ?? []; }
function IPS_GetInstance(int $id): array { return $GLOBALS['inventoryInstances'][$id] ?? []; }
function IPS_GetVariableProfile(string $profile): array
{
    if ($profile === 'BlindStatus') return [
        'Suffix'=>'', 'Associations'=>[
            ['Value'=>0,'Name'=>'Offen'],['Value'=>1,'Name'=>'Geschlossen']]
    ];
    if ($profile === 'BlindAction') return [
        'Associations'=>[
            ['Value'=>0,'Name'=>'Stop'],['Value'=>1,'Name'=>'Auf'],['Value'=>2,'Name'=>'Ab']]
    ];
    return ['Suffix'=>str_contains($profile,'Intensity') || str_contains($profile,'Shutter') ? '%' : '',
        'MinValue'=>0,'MaxValue'=>100,'Associations'=>[]];
}

require dirname(__DIR__) . '/RoomsCatalogConfigurator/module.php';
function auditOk(bool $condition,string $msg): void { if (!$condition) throw new RuntimeException($msg); }
$base = [
    'wohnzimmer'=>['display'=>'Wohnzimmer','floor'=>'EG','domains'=>['licht'=>['switches'=>[
        'bestand'=>['title'=>'Bestandslicht','state'=>50101,'kiEnabled'=>false,'kiReadOnly'=>true]
    ]]]],
    'wc'=>['display'=>'WC','floor'=>'EG','aliases'=>[],'domains'=>[]]
];
IPS_SetScriptContent(40,'<?php return ' . var_export($base,true) . ';');
IPS_SetScriptContent(41,'<?php return ' . var_export($base,true) . ';');
$mod = new RoomsCatalogConfigurator();
$mod->properties = ['RoomsCatalogScriptID'=>40,'RoomsCatalogEditScriptID'=>41,
    'DiscoveryRootId'=>1000,'DiscoveryExtraRootIds'=>''];
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($mod);

ob_start(); $mod->ScanNewDevices(); $message = ob_get_clean();
$scan = json_decode($mod->ReadAttributeString('DiscoveryInventory'),true,512,JSON_THROW_ON_ERROR);
$preview = json_decode($mod->ReadAttributeString('DiscoveryCandidates'),true,512,JSON_THROW_ON_ERROR);
auditOk(($scan['devices'] ?? 0) === 11 && ($scan['known'] ?? 0) === 1
    && ($scan['new'] ?? 0) === 10 && !$scan['truncated'],'All ten types were not fully traversed');
auditOk(!str_contains($mod->ReadAttributeString('DiscoveryInventory'),'PRIVATE_VALUE_MUST_NOT_BE_CACHED'),
    'Inventory copied live variable contents');
ob_start(); $mod->ShowDiscoveryDetails(17426); $details = ob_get_clean();
auditOk(str_contains($details,'39138') && str_contains($details,'14327')
    && str_contains($details,'0=Offen') && !str_contains($details,'PRIVATE_VALUE'),
    'Read-only device details hide required metadata or expose private values');
ob_start(); $mod->ExportDiscoveryInventory(); $export = ob_get_clean();
auditOk(str_contains($export,'{BLIND-MODULE}') && !str_contains($export,'PRIVATE_VALUE'),
    'Copyable inventory export incomplete or insecure');
$bySource = [];
foreach ($preview['rows'] as $row) $bySource[$row['sourceId']] = $row;
foreach ($types as [$id,$name,$module,$expected]) {
    if ($id === 2001) { auditOk(!isset($bySource[$id]),'Known device was duplicated'); continue; }
    auditOk(($bySource[$id]['deviceType'] ?? '') === $expected,'Wrong type for ' . $name);
}
auditOk(($bySource[17426]['deviceType'] ?? '') === 'blind'
    && ($bySource[17426]['moduleId'] ?? '') === '{BLIND-MODULE}',
    'KNX Rollladen not classified by module identity');
auditOk(($bySource[2009]['readiness'] ?? '') === 'Unvollstaendig',
    'Unknown generic device was treated as complete');
auditOk(isset($mod->updates['DiscoverySummary.caption']) && str_contains($message,'Inventur'),
    'Inventory summary not updated');
$allRows = json_decode($mod->updates['DiscoveryInventoryList.values'] ?? '[]',true,512,JSON_THROW_ON_ERROR);
auditOk(count($allRows) === 11
    && count(array_filter($allRows,static fn($row): bool=>$row['knownLabel'] === 'Bestand')) === 1,
    'Full visible inventory omitted existing devices');
$form = json_decode($mod->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
auditOk(str_contains(json_encode($form,JSON_THROW_ON_ERROR),'DiscoveryInventoryList'),
    'Full inventory table not visible in configurator');
auditOk(IPS_GetScriptContent(41) === IPS_GetScriptContent(40),
    'Read-only inventory modified EDIT or PROD');

ob_start(); $mod->AuditDiscoveryCoverage(); $before = ob_get_clean();
auditOk(str_contains($before,'Nicht 100% bereit')
    && str_contains($before,'nicht im EDIT-Katalog'), 'Incomplete status was hidden');

$mod->UpdateDiscoveryCandidate(json_encode(['sourceId'=>17426,'selected'=>true,'roomKey'=>'wc'],JSON_THROW_ON_ERROR));
ob_start(); $mod->ApplyDiscoveredDevices(); $imported = ob_get_clean();
auditOk(str_contains($imported,'1 neue Geraete'),'KNX Rollladen not imported');
$edit = require $GLOBALS['inventoryDir'] . '/scripts/41.php';
$blind = $edit['wc']['domains']['jalousie']['egwc_17426'] ?? [];
auditOk(($blind['ai']['variables']['status'] ?? 0) === 39138
    && ($blind['ai']['variables']['action'] ?? 0) === 14327
    && ($blind['ai']['variables']['position'] ?? 0) === 55194
    && ($blind['ai']['variables']['tilt'] ?? 0) === 36899,
    'Distinct KNX status/action/position/tilt variables were not imported');
auditOk(($blind['ai']['stateMapping']['open'] ?? null) === 0
    && ($blind['ai']['stateMapping']['closed'] ?? null) === 1,
    'Open/closed association values not persisted');
auditOk(($blind['ai']['source']['moduleId'] ?? '') === '{BLIND-MODULE}'
    && ($blind['ai']['review']['status'] ?? '') === 'pending'
    && !$blind['kiEnabled'] && $blind['kiReadOnly'],
    'Source metadata/review state or permissions incorrect');
auditOk((require $GLOBALS['inventoryDir'] . '/scripts/40.php') === $base,'PROD changed');

// Nach manueller Bestaetigung als Vorlage fuer denselben KNX-Modultyp nutzen.
$mod->SaveToEdit(json_encode([
    'editorOriginalKey'=>'wc|jalousie|jalousie|egwc_17426',
    'roomKey'=>'wc','title'=>'EG WC','key'=>'egwc_17426',
    'aiType'=>'blind','aiReviewed'=>true
],JSON_THROW_ON_ERROR));
$confirmed = require $GLOBALS['inventoryDir'] . '/scripts/41.php';
auditOk(($confirmed['wc']['domains']['jalousie']['egwc_17426']['ai']['review']['status'] ?? '') === 'confirmed',
    'Explicit review was not saved');
ob_start(); $mod->ScanNewDevices(); ob_end_clean();
$repeated = json_decode($mod->ReadAttributeString('DiscoveryCandidates'),true,512,JSON_THROW_ON_ERROR);
$second = null;
foreach ($repeated['rows'] as $row) if ($row['sourceId'] === 17427) $second = $row;
auditOk($second !== null && isset($second['mappedRoles']['position'],$second['mappedRoles']['tilt'],
    $second['mappedRoles']['status'],$second['mappedRoles']['action']),
    'Confirmed module template did not recover opaque variables on second blind');

// Bereits produktiv vorhandene Legacy-Eintraege typisieren, ohne Feld-/Freigabe-Verlust.
ob_start(); $mod->AnalyzeLegacyDevices(); $legacyMessage = ob_get_clean();
$legacyPreview = json_decode($mod->ReadAttributeString('LegacyMigrationPreview'),true,512,JSON_THROW_ON_ERROR);
$legacyLight = null;
foreach ($legacyPreview['rows'] as $row) {
    if ($row['composite'] === 'wohnzimmer|licht|switches|bestand') $legacyLight = $row;
}
auditOk($legacyLight !== null && $legacyLight['deviceType'] === 'light'
    && $legacyLight['confidence'] === 'Hoch'
    && str_contains($legacyMessage,'Legacy-Analyse'),
    'Legacy device not classified using scope/type/profile');
$mod->UpdateLegacyCandidate(json_encode(['composite'=>$legacyLight['composite'],
    'selected'=>true,'deviceType'=>'light'],JSON_THROW_ON_ERROR));
ob_start(); $mod->ApplyLegacyCandidates(); $legacyImportMessage = ob_get_clean();
$legacyUpdated = require $GLOBALS['inventoryDir'] . '/scripts/41.php';
$legacyConfig = $legacyUpdated['wohnzimmer']['domains']['licht']['switches']['bestand'] ?? [];
auditOk(str_contains($legacyImportMessage,'1 bestehende Geraete')
    && ($legacyConfig['title'] ?? '') === 'Bestandslicht'
    && ($legacyConfig['state'] ?? 0) === 50101
    && $legacyConfig['kiEnabled'] === false && $legacyConfig['kiReadOnly'] === true
    && ($legacyConfig['ai']['type'] ?? '') === 'light'
    && ($legacyConfig['ai']['variables']['power'] ?? 0) === 50101
    && ($legacyConfig['ai']['source']['moduleId'] ?? '') === '{MODULE-2001}'
    && ($legacyConfig['ai']['review']['status'] ?? '') === 'pending',
    'Legacy migration changed permissions, discarded fields or omitted metadata/review');
auditOk((require $GLOBALS['inventoryDir'] . '/scripts/40.php') === $base,
    'Legacy migration unexpectedly changed production');

foreach (array_keys($GLOBALS['inventoryScripts']) as $id) @unlink($GLOBALS['inventoryDir'] . '/scripts/' . $id . '.php');
@rmdir($GLOBALS['inventoryDir'] . '/scripts');
@rmdir($GLOBALS['inventoryDir']);
echo "PASS V3.3.1: ten types, deep profile inference, no cached values, audit, review, module template and lossless legacy migration\n";
