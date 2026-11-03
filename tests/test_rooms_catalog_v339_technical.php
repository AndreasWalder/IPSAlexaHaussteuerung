<?php
declare(strict_types=1);

/** V3.3.9 – reuse 10-type simulated IPS hierarchy and existing regression tests. */
function IPS_CreateScript(int $type): int
{
    $id = ++$GLOBALS['v339NewScriptId'];
    $GLOBALS['inventoryScripts'][$id] = '';
    return $id;
}
function IPS_GetParent(int $id): int { return 1000; }
function IPS_SetParent(int $id,int $parent): bool { return true; }
function IPS_SetName(int $id,string $name): bool
{
    $GLOBALS['v339BackupNames'][$id]=$name;
    return true;
}
$GLOBALS['v339NewScriptId'] = 9000;
$GLOBALS['v339BackupNames'] = [];
require __DIR__ . '/test_rooms_catalog_v331_inventory.php';

mkdir($GLOBALS['inventoryDir'] . '/scripts',0700,true);
$catalog=[
    'wohnzimmer'=>['display'=>'Wohnzimmer','floor'=>'EG','domains'=>[
        'licht'=>['switches'=>[
            'alt'=>['title'=>'Alt-Licht','state'=>50101,'kiEnabled'=>false,'kiReadOnly'=>true,'customField'=>'KEEP'],
            'decke'=>[
                'title'=>'Deckenlicht','state'=>50101,'kiEnabled'=>true,'kiReadOnly'=>true,'customField'=>'KEEP-LIGHT',
                'ai'=>['version'=>2,'type'=>'light',
                    'variables'=>['power'=>50101],'review'=>['status'=>'pending']]
            ],
            'defekt'=>[
                'title'=>'Falscher Sensor','state'=>88888,'kiEnabled'=>false,'kiReadOnly'=>true,
                'ai'=>['version'=>2,'type'=>'light','variables'=>['power'=>88888],
                    'review'=>['status'=>'pending']]
            ]
        ]],
        'dimmer'=>['dimmers'=>['dimmer'=>[
            'title'=>'Dimmer','value'=>50201,'kiEnabled'=>false,'kiReadOnly'=>true,
            'ai'=>['version'=>2,'type'=>'dimmer','variables'=>['brightness'=>50201],
                'review'=>['status'=>'pending']]
        ]]]
    ]],
    'wc'=>['display'=>'WC','floor'=>'EG','domains'=>[
        'jalousie'=>['fenster'=>[
            'title'=>'EG WC','wert'=>55194,'statusId'=>39138,'kiEnabled'=>false,'kiReadOnly'=>true,
            'ai'=>['version'=>2,'type'=>'blind',
                'variables'=>['status'=>39138,'action'=>14327,'position'=>55194,'tilt'=>36899],
                'stateMapping'=>['open'=>0,'closed'=>1],
                'review'=>['status'=>'pending']]
        ]]
    ]]
];
$prod=$catalog;
unset($prod['wohnzimmer']['domains']['dimmer']); // one new EDIT device
$prod['wohnzimmer']['domains']['licht']['switches']['decke']['title']='Alter Lichtname';
IPS_SetScriptContent(40,'<?php return '.var_export($prod,true).';');
IPS_SetScriptContent(41,'<?php return '.var_export($catalog,true).';');
$mod=new RoomsCatalogConfigurator();
$mod->properties=['RoomsCatalogScriptID'=>40,'RoomsCatalogEditScriptID'=>41];
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($mod);
function ck339(bool $test,string $reason): void { if(!$test)throw new RuntimeException($reason); }

$beforeEdit=IPS_GetScriptContent(41);
$beforeProd=IPS_GetScriptContent(40);
ob_start();$mod->RunTechnicalDeviceAudit();$summary=ob_get_clean();
$scan=json_decode($mod->ReadAttributeString('TechnicalDeviceSnapshot'),true,512,JSON_THROW_ON_ERROR);
ck339(count($scan['rows'])===5,'Not all devices were scanned');
ck339(($scan['diffCounts']['new']??null)===1 && $scan['diffCounts']['changed']>=1,
    'Device diff report does not distinguish new and changed entries');
ck339(count($scan['legacyRows'])===1 && $scan['legacyRows'][0]['title']==='Alt-Licht',
    'Legacy devices must be shown separately');
$byKey=[];
foreach($scan['rows'] as $item) $byKey[$item['key']]=$item;
ck339(($byKey['wohnzimmer|licht|switches|decke']['status']??'')==='Technisch plausibel',
    'Known, valid light status not accepted as technically plausible');
ck339(($byKey['wohnzimmer|dimmer|dimmers|dimmer']['status']??'')==='Technisch plausibel',
    'Known dimmer profile was not accepted as technically plausible');
ck339(($byKey['wohnzimmer|licht|switches|defekt']['status']??'')==='Ungueltig',
    'Missing IPS variable not rejected');
ck339(($byKey['wc|jalousie|jalousie|fenster']['status']??'')==='Technisch plausibel',
    'KNX blind with genuine open/closed enum associations not recognized');
ck339(!str_contains($mod->ReadAttributeString('TechnicalDeviceSnapshot'),'PRIVATE_VALUE_MUST_NOT_BE_CACHED'),
    'Live variable values leaked into scan snapshots');
ck339(IPS_GetScriptContent(40)===$beforeProd && IPS_GetScriptContent(41)===$beforeEdit,
    'Read-only scan touched PROD/EDIT');
$form=json_decode($mod->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$ui=json_encode($form,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
foreach(['TechnicalDeviceChecks','TechnicalLegacyDevices','TechnicalDiffRows',
    'RCC_PreviewTechnicalDeviceReview','RCC_ApplyTechnicalDeviceReview',
    'RCC_PreviewManualDeviceConfirmation','RCC_ApplyManualDeviceConfirmation'] as $token) {
    ck339(str_contains($ui,$token),'V3.3.9 UI control missing: '.$token);
}
ck339(str_contains($summary,'EDIT/PROD:'),'Summary missing diff categories');

$mod->SelectTechnicalDevices(true);
$selected=json_decode($mod->ReadAttributeString('TechnicalDeviceSnapshot'),true,512,JSON_THROW_ON_ERROR);
$selectedRows=array_values(array_filter($selected['rows'],static fn($row):bool=>!empty($row['selected'])));
ck339(count($selectedRows)>=2,'Technical candidates not selected');
ck339(!in_array('wohnzimmer|licht|switches|defekt',array_column($selectedRows,'key'),true),
    'Invalid device was selected for approval');
ob_start();$mod->PreviewTechnicalDeviceReview();$previewMsg=ob_get_clean();
ck339(str_contains($previewMsg,'Nur RoomsCatalogEdit'),'Explicit edit-only preview missing');

// Simulated concurrent edit should block; no backup or mutations.
IPS_SetScriptContent(41,$beforeEdit."\n");
ob_start();$mod->ApplyTechnicalDeviceReview();$stale=ob_get_clean();
ck339(IPS_GetScriptContent(41)===$beforeEdit."\n"
    && IPS_GetScriptContent(40)===$beforeProd
    && str_contains($stale,'veraltet'),'Stale technical preview was incorrectly accepted');
IPS_SetScriptContent(41,$beforeEdit);
ob_start();$mod->RunTechnicalDeviceAudit();ob_end_clean();
$mod->SelectTechnicalDevices(true);
ob_start();$mod->PreviewTechnicalDeviceReview();ob_end_clean();
ob_start();$mod->ApplyTechnicalDeviceReview();$updatedMsg=ob_get_clean();
ck339(str_contains($updatedMsg,'Nur EDIT geaendert'),'Technical update failed: '.$updatedMsg);
$edit=require $GLOBALS['inventoryDir'].'/scripts/41.php';
$light=$edit['wohnzimmer']['domains']['licht']['switches']['decke'];
ck339(($light['ai']['review']['technical']['status']??'')==='validated'
    && ($light['ai']['review']['status']??'')==='pending'
    && $light['kiEnabled']===true && $light['kiReadOnly']===true
    && $light['customField']==='KEEP-LIGHT',
    'Technical review changed manual confirmation, permissions or unknown legacy fields');
ck339($edit['wohnzimmer']['domains']['licht']['switches']['alt']['customField']==='KEEP'
    && IPS_GetScriptContent(40)===$beforeProd,'Legacy / PROD was modified');
ck339(count($GLOBALS['v339BackupNames'])===1,'Before-edit snapshot missing');
$backupId=(int)array_key_first($GLOBALS['v339BackupNames']);
ck339(IPS_GetScriptContent($backupId)===$beforeEdit,'Backup is not byte-equal to original EDIT');

// Manual confirmation is a different, explicit action and cannot be bypassed.
ob_start();$mod->RunTechnicalDeviceAudit();ob_end_clean();
$mod->MarkTechnicalDevice('wohnzimmer|licht|switches|decke',true);
ob_start();$mod->PreviewManualDeviceConfirmation(false);$refusal=ob_get_clean();
ck339(str_contains($refusal,'echten System'),'Manual confirmation must require a real device test');
ob_start();$mod->PreviewManualDeviceConfirmation(true);$manualPreview=ob_get_clean();
ck339(str_contains($manualPreview,'Vorschau: 1 Geraete'),'Manual preview not set');
$beforeManual=IPS_GetScriptContent(41);
ob_start();$mod->ApplyManualDeviceConfirmation(false);$gate=ob_get_clean();
ck339(IPS_GetScriptContent(41)===$beforeManual && str_contains($gate,'gesperrt'),
    'Unchecked manual review modified EDIT');
ob_start();$mod->ApplyManualDeviceConfirmation(true);$manualMsg=ob_get_clean();
ck339(str_contains($manualMsg,'manueller Pruef-Erklaerung'),'Manual review not applied: '.$manualMsg);
$confirmed=require $GLOBALS['inventoryDir'].'/scripts/41.php';
ck339(($confirmed['wohnzimmer']['domains']['licht']['switches']['decke']['ai']['review']['status']??'')
    ==='confirmed','Explicit manual confirmation status missing');
ck339(($confirmed['wohnzimmer']['domains']['licht']['switches']['defekt']['ai']['review']['status']??'')
    ==='pending','Unselected invalid device was incorrectly confirmed');
ck339(IPS_GetScriptContent(40)===$beforeProd,'PROD changed during manual review');

foreach(array_keys($GLOBALS['inventoryScripts']) as $id) @unlink($GLOBALS['inventoryDir'].'/scripts/'.$id.'.php');
@rmdir($GLOBALS['inventoryDir'].'/scripts');@rmdir($GLOBALS['inventoryDir']);
echo "PASS V3.3.9: all types, IPS evidence, explicit approval, stale guard, EDIT-only updates, backups, legacy + diff reporting\n";
