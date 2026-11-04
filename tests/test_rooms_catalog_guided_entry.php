<?php
declare(strict_types=1);

/** Gefuehrter Neu-/Bearbeiten-Dialog: Typ, Raumwahl, Ableitung und Verschieben. */
class IPSModule
{
    public array $properties = [];
    public array $updates = [];
    private array $attrs = [];
    public function ReadPropertyInteger(string $key): int { return (int)($this->properties[$key] ?? 0); }
    public function ReadAttributeString(string $key): string { return (string)($this->attrs[$key] ?? ''); }
    public function WriteAttributeString(string $key, string $value): void { $this->attrs[$key] = $value; }
    public function UpdateFormField(string $name, string $field, mixed $value): void { $this->updates[$name . '.' . $field] = $value; }
    public function ReloadForm(): void { throw new RuntimeException('Guided form must not trigger a full reload'); }
}

$GLOBALS['guidedTemp'] = sys_get_temp_dir() . '/rcc-guided-' . bin2hex(random_bytes(5));
mkdir($GLOBALS['guidedTemp'] . '/scripts', 0700, true);
$GLOBALS['guidedScripts'] = [];
function IPS_ScriptExists(int $id): bool { return isset($GLOBALS['guidedScripts'][$id]); }
function IPS_GetKernelDir(): string { return $GLOBALS['guidedTemp'] . '/'; }
function IPS_GetScriptFile(int $id): string { return $id . '.php'; }
function IPS_SetScriptContent(int $id, string $php): bool
{
    $GLOBALS['guidedScripts'][$id] = $php;
    file_put_contents($GLOBALS['guidedTemp'] . '/scripts/' . $id . '.php', $php);
    return true;
}
function IPS_GetScriptContent(int $id): string { return $GLOBALS['guidedScripts'][$id] ?? ''; }
function IPS_VariableExists(int $id): bool { return in_array($id,[50001,50002,50101],true); }

require dirname(__DIR__) . '/RoomsCatalogConfigurator/module.php';
function guidedOk(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function findGuidedField(array $items, string $name): ?array
{
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (($item['name'] ?? '') === $name) return $item;
        if (isset($item['items']) && is_array($item['items'])) {
            $child = findGuidedField($item['items'], $name);
            if ($child !== null) return $child;
        }
    }
    return null;
}
$catalog = [
    'wohnzimmer'=>['display'=>'Wohnzimmer','floor'=>'EG','aliases'=>['Stube'],
        'customRoom'=>'keep',
        'domains'=>['licht'=>['switches'=>['alt'=>[
            'title'=>'Altes Licht','state'=>50001,'customDevice'=>'keep',
            'kiEnabled'=>true,'kiReadOnly'=>true
        ]]]]],
    'kueche'=>['display'=>'Küche','floor'=>'EG','aliases'=>[],'domains'=>[]],
    'bad'=>['display'=>'Badezimmer','floor'=>'OG','aliases'=>['Bad'],'domains'=>[
        'licht'=>['switches'=>['beleuchtung'=>[
            'title'=>'Badlicht','state'=>50101,'note'=>['nested'=>true],
            'kiEnabled'=>false,'kiReadOnly'=>true
        ]]]
    ]]
];
IPS_SetScriptContent(50,'<?php return ' . var_export($catalog,true) . ';');
IPS_SetScriptContent(51,'<?php return ' . var_export($catalog,true) . ';');
$mod = new RoomsCatalogConfigurator();
$mod->properties = ['RoomsCatalogScriptID'=>50,'RoomsCatalogEditScriptID'=>51];
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($mod);

$addForm = json_decode($mod->GetEntryEditForm(), true, 512, JSON_THROW_ON_ERROR);
$typeSelect = findGuidedField($addForm,'aiType');
guidedOk(($typeSelect['type'] ?? '') === 'Select', 'Device type must be first-class selection');
guidedOk(!in_array('',array_column($typeSelect['options'],'value'),true), 'New devices must not allow legacy model');
guidedOk(($typeSelect['value'] ?? null) === 'light', 'New device type default should be light');
$roomSelect = findGuidedField($addForm,'roomKey');
guidedOk(($roomSelect['type'] ?? '') === 'Select', 'Room must be selected from configured rooms');
$options = array_column($roomSelect['options'],'caption','value');
guidedOk(str_contains($options['kueche'] ?? '', 'Küche') && str_contains($options['bad'] ?? '', 'OG'),
    'Known rooms and floors absent from room dropdown');
guidedOk(findGuidedField($addForm,'roomLabel') === null
    && findGuidedField($addForm,'floor') === null
    && findGuidedField($addForm,'domain') === null
    && findGuidedField($addForm,'group') === null,
    'Redundant room and domain fields must not be manually edited');
guidedOk(findGuidedField($addForm,'aiPowerId')['visible'] === true
    && findGuidedField($addForm,'aiPositionId')['visible'] === false,
    'Default type did not control variable visibility');
guidedOk(findGuidedField($addForm,'editorOriginalKey')['visible'] === false, 'Source identity not tracked safely');
$mod->UpdateEntryTypeForm('blind');
guidedOk(($mod->updates['aiPositionId.visible'] ?? null) === true
    && ($mod->updates['aiPowerId.visible'] ?? null) === false
    && str_contains($mod->updates['EntryTargetPreview.caption'] ?? '', 'jalousie / jalousie'),
    'Changing type did not update relevant fields and mapped domain');

$add = [
    'editorOriginalKey'=>'','roomKey'=>'wohnzimmer','roomLabel'=>'FALSCH',
    'floor'=>'OG','domain'=>'irgendeinbereich','group'=>'falsch','key'=>'',
    'title'=>'Licht Decke','aiType'=>'light','aiPowerId'=>50001,
    'aiControlId'=>50002,'kiEnabled'=>true,'kiReadOnly'=>false
];
ob_start(); $mod->SaveToEdit(json_encode($add,JSON_THROW_ON_ERROR)); $response = ob_get_clean();
$edited = require $GLOBALS['guidedTemp'] . '/scripts/51.php';
$lamp = $edited['wohnzimmer']['domains']['licht']['switches']['licht_decke'] ?? [];
guidedOk(($lamp['ai']['type'] ?? '') === 'light'
    && ($lamp['ai']['variables']['power'] ?? 0) === 50001
    && ($lamp['ai']['variables']['control'] ?? 0) === 50002,
    'New typed device did not persist AI bindings');
guidedOk(($lamp['state'] ?? 0) === 50001 && ($lamp['toggle'] ?? 0) === 50002,
    'Safe legacy fields were not derived');
guidedOk(($lamp['kiEnabled'] ?? null) === false && ($lamp['kiReadOnly'] ?? null) === true,
    'New device incorrectly received KI control permission');
guidedOk($edited['wohnzimmer']['display'] === 'Wohnzimmer'
    && $edited['wohnzimmer']['floor'] === 'EG'
    && !isset($edited['wohnzimmer']['domains']['irgendeinbereich']),
    'Untrusted user-entered room/domain values overrode catalog metadata');
guidedOk((require $GLOBALS['guidedTemp'] . '/scripts/50.php') === $catalog, 'PROD changed while adding a device');

ob_start(); $mod->SaveToEdit(json_encode($add,JSON_THROW_ON_ERROR)); ob_end_clean();
$edited = require $GLOBALS['guidedTemp'] . '/scripts/51.php';
guidedOk(isset($edited['wohnzimmer']['domains']['licht']['switches']['licht_decke_2']),
    'Automatic key generation must resolve same-name collision');

$stale = IPS_GetScriptContent(51);
$badAdd = $add;
$badAdd['roomKey'] = 'fantasieraum';
ob_start(); $mod->SaveToEdit(json_encode($badAdd,JSON_THROW_ON_ERROR)); $invalid = ob_get_clean();
guidedOk(str_contains($invalid, 'gueltigen Raum') && IPS_GetScriptContent(51) === $stale,
    'Invalid selected room should reject write');

$move = [
    'editorOriginalKey'=>'bad|licht|switches|beleuchtung',
    'roomKey'=>'kueche','title'=>'Badlicht','key'=>'beleuchtung',
    'aiType'=>'blind','aiPositionId'=>50101
];
ob_start(); $mod->SaveToEdit(json_encode($move,JSON_THROW_ON_ERROR)); $moveResult = ob_get_clean();
$movedCatalog = require $GLOBALS['guidedTemp'] . '/scripts/51.php';
$moved = $movedCatalog['kueche']['domains']['jalousie']['beleuchtung'] ?? [];
guidedOk(($moved['note']['nested'] ?? false) === true && ($moved['ai']['type'] ?? '') === 'blind',
    'Move dropped custom legacy metadata or typed device');
guidedOk(($movedCatalog['bad']['domains'] ?? null) === [],
    'Last device was not removed from old room or room metadata was lost');
guidedOk($movedCatalog['bad']['aliases'] === ['Bad']
    && $movedCatalog['wohnzimmer']['customRoom'] === 'keep',
    'Move deleted room metadata');
guidedOk((require $GLOBALS['guidedTemp'] . '/scripts/50.php') === $catalog, 'PROD modified on move');


/** V3.3.24: A stale invalid optional targetPosition must show an
 * explicit warning instead of silently looking like "Keine", and
 * selecting "Keine" must remove the nested ai.variables key. */
$oldBlind=$movedCatalog['kueche']['domains']['jalousie']['beleuchtung'];
$withInvalidTarget=$movedCatalog;
$withInvalidTarget['kueche']['domains']['jalousie']['beleuchtung']['ai']['variables']['targetPosition']=1;
$withInvalidTarget['kueche']['domains']['jalousie']['beleuchtung']['ai']['variables']['position']=50101;
$withInvalidTarget['kueche']['domains']['jalousie']['beleuchtung']['ai']['keepCustomRole']=true;
IPS_SetScriptContent(51,'<?php return '.var_export($withInvalidTarget,true).';');
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($mod);
$invalidForm=json_decode($mod->GetEntryEditForm('kueche','jalousie','jalousie','beleuchtung'),
    true,512,JSON_THROW_ON_ERROR);
$invalidChoice=findGuidedField($invalidForm,'aiTargetPositionId');
guidedOk(($invalidChoice['value']??-1)===0
    && str_contains(json_encode($invalidForm,JSON_UNESCAPED_UNICODE),'targetPosition (#1)')
    && str_contains(json_encode($invalidForm,JSON_UNESCAPED_UNICODE),'ungueltige Variablen-IDs'),
    'Invalid ID 1 must be disclosed but the dropdown should be explicitly preset to Keine');
$prodBeforeClearing=IPS_GetScriptContent(50);
ob_start();
$mod->SaveToEdit(json_encode([
    'editorOriginalKey'=>'kueche|jalousie|jalousie|beleuchtung',
    'roomKey'=>'kueche','title'=>'Badlicht','key'=>'beleuchtung',
    'aiType'=>'blind','aiTargetPositionId'=>0,
],JSON_THROW_ON_ERROR));
$clearOutcome=ob_get_clean();
$afterClearing=require $GLOBALS['guidedTemp'].'/scripts/51.php';
$clearLeaf=$afterClearing['kueche']['domains']['jalousie']['beleuchtung'];
guidedOk(!array_key_exists('targetPosition',$clearLeaf['ai']['variables'])
    && ($clearLeaf['ai']['variables']['position']??0)===50101
    && ($clearLeaf['ai']['keepCustomRole']??false)===true,
    'Choosing Keine must remove exactly targetPosition and preserve other AI and legacy fields');
guidedOk(IPS_GetScriptContent(50)===$prodBeforeClearing,
    'Clearing an optional role in EDIT must not modify PROD');
$validTarget=50101;
ob_start();
$mod->SaveToEdit(json_encode([
    'editorOriginalKey'=>'kueche|jalousie|jalousie|beleuchtung',
    'roomKey'=>'kueche','title'=>'Badlicht','key'=>'beleuchtung',
    'aiType'=>'blind','aiTargetPositionId'=>$validTarget,
],JSON_THROW_ON_ERROR));
ob_end_clean();
$afterAssign=require $GLOBALS['guidedTemp'].'/scripts/51.php';
guidedOk(($afterAssign['kueche']['domains']['jalousie']['beleuchtung']['ai']['variables']['targetPosition']??0)===$validTarget,
    'Selecting an explicit valid targetPosition must still store its chosen ID');

foreach (array_keys($GLOBALS['guidedScripts']) as $id) @unlink($GLOBALS['guidedTemp'] . '/scripts/' . $id . '.php');
@rmdir($GLOBALS['guidedTemp'] . '/scripts');
@rmdir($GLOBALS['guidedTemp']);
echo "PASS guided RoomsCatalog editor: typed defaults, room enum, dependent fields, key inference, no KI rights and lossless move\n";
