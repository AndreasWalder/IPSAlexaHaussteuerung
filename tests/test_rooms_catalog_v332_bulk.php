<?php
declare(strict_types=1);

/** V3.3.2: Alle/sichtbare markieren, gemeinsame Felder, sichere Sammelbearbeitung. */
class IPSModule
{
    private array $attributes = [];
    public array $properties = [];
    public array $updates = [];
    public function ReadPropertyInteger(string $name): int { return (int)($this->properties[$name] ?? 0); }
    public function ReadPropertyString(string $name): string { return (string)($this->properties[$name] ?? ''); }
    public function ReadAttributeString(string $name): string { return (string)($this->attributes[$name] ?? ''); }
    public function WriteAttributeString(string $name,string $value): void { $this->attributes[$name]=$value; }
    public function UpdateFormField(string $name,string $field,mixed $value): void { $this->updates[$name . '.' . $field]=$value; }
    public function ReloadForm(): void { throw new RuntimeException('Editor must remain open'); }
}
$GLOBALS['bulkDir']=sys_get_temp_dir() . '/rcc-bulk-' . bin2hex(random_bytes(5));
mkdir($GLOBALS['bulkDir'] . '/scripts',0700,true);
$GLOBALS['bulkScripts']=[];
$GLOBALS['bulkObjects']=[
    1000=>['ObjectType'=>0,'ParentID'=>0,'ObjectName'=>'Haus'],
    1100=>['ObjectType'=>0,'ParentID'=>1000,'ObjectName'=>'Wohnzimmer'],
    1200=>['ObjectType'=>0,'ParentID'=>1000,'ObjectName'=>'Bad']
];
$GLOBALS['bulkVars']=[];
function putBulkVar(int $id,int $parent,string $name,string $profile): void
{
    $GLOBALS['bulkObjects'][$id]=['ObjectType'=>2,'ParentID'=>$parent,'ObjectName'=>$name,'ObjectIdent'=>$name];
    $GLOBALS['bulkVars'][$id]=['VariableType'=>str_contains($profile,'Temperature') ? 2 : 0,
        'VariableProfile'=>$profile,'VariableCustomProfile'=>'','VariableAction'=>0,'VariableCustomAction'=>0];
}
foreach ([501,502,503] as $instance) {
    $GLOBALS['bulkObjects'][$instance]=['ObjectType'=>1,'ParentID'=>$instance===503?1200:1100,
        'ObjectName'=>'KNX ' . $instance];
}
putBulkVar(90001,501,'Status','~Switch');
putBulkVar(90002,502,'Status','~Switch');
putBulkVar(90005,503,'Isttemperatur','~Temperature');
putBulkVar(90006,503,'Solltemperatur','~Temperature');
function IPS_GetKernelDir(): string { return $GLOBALS['bulkDir'] . '/'; }
function IPS_GetScriptFile(int $id): string { return $id . '.php'; }
function IPS_ScriptExists(int $id): bool { return isset($GLOBALS['bulkScripts'][$id]); }
function IPS_GetScriptContent(int $id): string { return $GLOBALS['bulkScripts'][$id] ?? ''; }
function IPS_SetScriptContent(int $id,string $php): bool
{
    $GLOBALS['bulkScripts'][$id]=$php;
    file_put_contents($GLOBALS['bulkDir'] . '/scripts/' . $id . '.php',$php);
    return true;
}
function IPS_ObjectExists(int $id): bool { return isset($GLOBALS['bulkObjects'][$id]); }
function IPS_GetObject(int $id): array { return $GLOBALS['bulkObjects'][$id] ?? []; }
function IPS_GetChildrenIDs(int $id): array
{
    return array_keys(array_filter($GLOBALS['bulkObjects'],static fn($object): bool=>$object['ParentID']===$id));
}
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['bulkVars'][$id]); }
function IPS_GetVariable(int $id): array { return $GLOBALS['bulkVars'][$id] ?? []; }
function IPS_GetInstance(int $id): array { return ['ModuleInfo'=>['ModuleID'=>'KNX-BULK','ModuleName'=>'KNX']]; }
function IPS_GetVariableProfile(string $name): array { return ['Suffix'=>'','Associations'=>[]]; }

require dirname(__DIR__) . '/RoomsCatalogConfigurator/module.php';
function bulkCheck(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$catalog=[
    'wohnzimmer'=>['display'=>'Wohnzimmer','floor'=>'EG','domains'=>['licht'=>['switches'=>[
        'decke'=>['title'=>'Deckenlicht','state'=>90001,'kiEnabled'=>false,'kiReadOnly'=>true,'legacyExtra'=>'keep-decke'],
        'wand'=>['title'=>'Wandlicht','state'=>90002,'kiEnabled'=>false,'kiReadOnly'=>true,'legacyExtra'=>'keep-wand']
    ]]]],
    'bad'=>['display'=>'Bad','floor'=>'OG','domains'=>['heizung'=>[
        'bad'=>['title'=>'Bad Heizung','ist'=>90005,'soll'=>90006,
            'kiEnabled'=>true,'kiReadOnly'=>true,'legacyExtra'=>'keep-heizung']
    ]]]
];
IPS_SetScriptContent(40,'<?php return ' . var_export($catalog,true) . ';');
IPS_SetScriptContent(41,'<?php return ' . var_export($catalog,true) . ';');
$mod=new RoomsCatalogConfigurator();
$mod->properties=['RoomsCatalogScriptID'=>40,'RoomsCatalogEditScriptID'=>41];
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($mod);
$form=json_decode($mod->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$ui=json_encode($form,JSON_THROW_ON_ERROR);
foreach (['RCC_SetAllSelection','RCC_PreviewBulkEdit','RCC_ApplyBulkEdit',
    'BulkDeviceType','BulkAIMode','BulkSuggestions','BulkReview','BulkSelectionSummary'] as $element) {
    bulkCheck(str_contains($ui,$element),'Bulk editor UI missing ' . $element);
}

$mod->SetRoomFilter('wohnzimmer');
$mod->SetVisibleSelection(true);
$selected=json_decode($mod->ReadAttributeString('RuntimeEntries'),true,512,JSON_THROW_ON_ERROR);
bulkCheck(count(array_filter($selected,static fn($r): bool=>!empty($r['selected'])))===2,
    'Select visible marked devices outside the filter');
$mod->SetBulkOption('BulkDeviceType','light');
$mod->SetBulkOption('BulkAIMode','read');
$mod->SetBulkOption('BulkSuggestions','yes');
ob_start(); $mod->PreviewBulkEdit(); $previewMessage=ob_get_clean();
bulkCheck(str_contains($previewMessage,'2 ausgewaehlte Geraete')
    && IPS_GetScriptContent(40)===IPS_GetScriptContent(41),
    'Bulk preview wrote to scripts or selected an incorrect scope');
ob_start(); $mod->ApplyBulkEdit(); $applied=ob_get_clean();
bulkCheck(str_contains($applied,'2 Geraete'),'Filtered bulk apply failed');
$updated=require $GLOBALS['bulkDir'] . '/scripts/41.php';
foreach (['decke','wand'] as $key) {
    $cfg=$updated['wohnzimmer']['domains']['licht']['switches'][$key];
    bulkCheck(($cfg['ai']['type'] ?? '')==='light'
        && ($cfg['ai']['review']['status'] ?? '')==='pending'
        && ($cfg['kiEnabled'] ?? null)===true && ($cfg['kiReadOnly'] ?? null)===true
        && ($cfg['legacyExtra'] ?? '')==='keep-' . $key
        && (int)($cfg['state'] ?? 0)>0, 'Bulk typing modified legacy/device identity or permissions');
}
bulkCheck($updated['bad']['domains']['heizung']['bad']===$catalog['bad']['domains']['heizung']['bad'],
    'Filtered bulk apply modified hidden heater');
bulkCheck((require $GLOBALS['bulkDir'] . '/scripts/40.php')===$catalog,'Bulk modified PROD');

$mod->SetAllSelection(true);
$selected=json_decode($mod->ReadAttributeString('RuntimeEntries'),true,512,JSON_THROW_ON_ERROR);
bulkCheck(count(array_filter($selected,static fn($r): bool=>!empty($r['selected'])))===3,
    'All-device button did not select hidden entries');
bulkCheck(str_contains($mod->updates['BulkSelectionSummary.caption'] ?? '','3 Geraete markiert'),
    'Selected count not updated');
$mod->SetBulkOption('BulkDeviceType','keep');
$mod->SetBulkOption('BulkAIMode','disabled');
$mod->SetBulkOption('BulkSuggestions','no');
ob_start(); $mod->PreviewBulkEdit(); ob_end_clean();
$staleContent=IPS_GetScriptContent(41) . "\n// a separate user edited the catalog";
IPS_SetScriptContent(41,$staleContent);
ob_start(); $mod->ApplyBulkEdit(); $stale=ob_get_clean();
bulkCheck(str_contains($stale,'veraltet') && IPS_GetScriptContent(41)===$staleContent,
    'Stale-preview guard missed concurrent EDIT change');
ob_start(); $mod->PreviewBulkEdit(); ob_end_clean();
ob_start(); $mod->ApplyBulkEdit(); $allMessage=ob_get_clean();
bulkCheck(str_contains($allMessage,'3 Geraete'),'Global multi-edit failed');
$all=require $GLOBALS['bulkDir'] . '/scripts/41.php';
bulkCheck($all['wohnzimmer']['domains']['licht']['switches']['decke']['kiEnabled']===false
    && $all['bad']['domains']['heizung']['bad']['kiEnabled']===false
    && $all['bad']['domains']['heizung']['bad']['ist']===90005
    && $all['bad']['domains']['heizung']['bad']['legacyExtra']==='keep-heizung',
    'Global AI mode change affected device data');
bulkCheck((require $GLOBALS['bulkDir'] . '/scripts/40.php')===$catalog,'Global bulk unexpectedly updated PROD');

// Changing type resets confirmation, but never moves an existing legacy leaf.
$mod->SetAllSelection(false);
$mod->SetRoomFilter('wohnzimmer');
$mod->SetVisibleSelection(true);
$mod->SetBulkOption('BulkDeviceType','blind');
$mod->SetBulkOption('BulkAIMode','keep');
ob_start(); $mod->PreviewBulkEdit(); ob_end_clean();
ob_start(); $mod->ApplyBulkEdit(); ob_end_clean();
$changed=require $GLOBALS['bulkDir'] . '/scripts/41.php';
bulkCheck(($changed['wohnzimmer']['domains']['licht']['switches']['wand']['ai']['type'] ?? '')==='blind'
    && ($changed['wohnzimmer']['domains']['licht']['switches']['wand']['ai']['review']['status'] ?? '')==='pending'
    && ($changed['wohnzimmer']['domains']['licht']['switches']['wand']['state'] ?? 0)===90002,
    'Changing shared type did not preserve legacy location or reset review');

foreach (array_keys($GLOBALS['bulkScripts']) as $id) @unlink($GLOBALS['bulkDir'] . '/scripts/' . $id . '.php');
@rmdir($GLOBALS['bulkDir'] . '/scripts');
@rmdir($GLOBALS['bulkDir']);
echo "PASS V3.3.2: full/visible selection, shared edits, individual legacy fields, pending review, stale guard and EDIT-only changes\n";
