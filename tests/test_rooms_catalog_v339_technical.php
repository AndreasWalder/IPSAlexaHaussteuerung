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
ob_start();$mod->AuditMigrationReadiness();ob_end_clean();
ob_start();$mod->RunTechnicalDeviceAudit();$summary=ob_get_clean();
$scan=json_decode($mod->ReadAttributeString('TechnicalDeviceSnapshot'),true,512,JSON_THROW_ON_ERROR);
ck339(count($scan['rows'])===5,'Not all devices were scanned');
ck339(($scan['source']['typed']??-1)===4 && ($scan['source']['legacy']??-1)===1,
    'Typed/Legacy source audit should match the previous migration audit');
ck339(($scan['source']['editId']??0)===41 && ($scan['source']['prodId']??0)===40,
    'Source script IDs not shown');
ck339(($scan['issueCounts']['missing_ips']??0)>=1,
    'Structured reasons missing for invalid IPS variable');
ck339(str_contains($mod->updates['TechnicalDeviceSourceSummary.caption']??'','EDIT #41')
    && str_contains($mod->updates['TechnicalDeviceSourceSummary.caption']??'','PROD #40'),
    'Source labels with real script IDs missing');
ck339(($scan['diffCounts']['new']??null)===1 && $scan['diffCounts']['changed']>=1,
    'Device diff report does not distinguish new and changed entries');
ck339(count($scan['legacyRows'])===1 && $scan['legacyRows'][0]['title']==='Alt-Licht',
    'Legacy devices must be shown separately');
$byKey=[];
foreach($scan['rows'] as $item) $byKey[$item['key']]=$item;
ck339(($byKey['wohnzimmer|licht|switches|decke']['status']??'')==='Technisch plausibel',
    'Known light not plausible: '.json_encode($byKey['wohnzimmer|licht|switches|decke']??null));
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

// Writable KI devices need a real write action, not merely a readable state.
$editAfterManual = IPS_GetScriptContent(41);
$unsafe = $confirmed;
$unsafe['wohnzimmer']['domains']['licht']['switches']['decke']['kiReadOnly']=false;
$unsafe['wohnzimmer']['domains']['licht']['switches']['decke']['toggle']=50101;
IPS_SetScriptContent(41,'<?php return '.var_export($unsafe,true).';');
ob_start();$mod->RunTechnicalDeviceAudit();ob_end_clean();
$unsafeScan=json_decode($mod->ReadAttributeString('TechnicalDeviceSnapshot'),true,512,JSON_THROW_ON_ERROR);
$unsafeRow=array_values(array_filter($unsafeScan['rows'],static fn(array $r):bool=>
    $r['key']==='wohnzimmer|licht|switches|decke'))[0];
ck339($unsafeRow['status']==='Manuell pruefen' && !$unsafeRow['manualEligible']
    && str_contains($unsafeRow['reason'],'IPS-Aktion'),
    'Writable KI device with no action was wrongly approved as technically safe');
IPS_SetScriptContent(41,$editAfterManual);

// Reproduce the reported sudden 8 -> 67 Legacy pattern on a small fixture.
// The classifier must highlight an actual external EDIT change, not silently
// contradict an older cached migration summary.
$priorData = json_decode($mod->ReadAttributeString('MigrationReadinessDetails'),true,512,JSON_THROW_ON_ERROR);
ck339(($priorData['statistics']['typed']??-1)===4,'Old audit baseline missing');
$changedSource=$confirmed;
unset($changedSource['wohnzimmer']['domains']['dimmer']['dimmers']['dimmer']['ai']);
IPS_SetScriptContent(41,'<?php return '.var_export($changedSource,true).';');
ob_start();$mod->RunTechnicalDeviceAudit();ob_end_clean();
$changedAudit=json_decode($mod->ReadAttributeString('TechnicalDeviceSnapshot'),true,512,JSON_THROW_ON_ERROR);
$sourceLabel=$mod->updates['TechnicalDeviceSourceSummary.caption']??'';
ck339(($changedAudit['source']['typed']??-1)===3
    && ($changedAudit['source']['legacy']??-1)===2
    && ($changedAudit['source']['previousAudit']['changed']??false) === true
    && str_contains($sourceLabel,'Vorher: 4 typisiert, 1 Legacy')
    && str_contains($sourceLabel,'Jetzt: 3 typisiert, 2 Legacy'),
    'Changed catalog must explain old vs new typed/legacy figures');
IPS_SetScriptContent(41,$editAfterManual);

// A misconfigured module must never write to PROD through an EDIT alias.
$mod->properties['RoomsCatalogScriptID']=41;
ob_start();$mod->RunTechnicalDeviceAudit();ob_end_clean();
$sameIdSummary=$mod->updates['TechnicalDeviceSourceSummary.caption']??'';
ck339(str_contains($sameIdSummary,'DIESELBE Skript-ID'),
    'Same PROD/EDIT script ID must produce a clear configuration warning');
$mod->MarkTechnicalDevice('wohnzimmer|licht|switches|decke',true);
ob_start();$mod->PreviewTechnicalDeviceReview();ob_end_clean();
$sameBefore=IPS_GetScriptContent(41);
ob_start();$mod->ApplyTechnicalDeviceReview();$sameMessage=ob_get_clean();
ck339(str_contains($sameMessage,'SICHERHEITSSTOPP')
    && IPS_GetScriptContent(41)===$sameBefore,
    'Technical approval wrote data into a shared PROD/EDIT script');
$mod->properties['RoomsCatalogScriptID']=40;

// Missing a required typed role must be shown as INCOMPLETE, not as an
// unavailable/broken IP-Symcon variable; it can never be confirmed blindly.
$incomplete=RCCDeviceTechnicalAudit::assess(
    ['aiType'=>'heating'], ['temperature'=>'aiTemperatureId'],
    [['id'=>91001,'name'=>'Ist','ident'=>'Ist','profile'=>'~Temperature','type'=>2]],
    [['temperature']], static fn(array $var,string $role):int=>120,static fn(int $id):bool=>true);
ck339($incomplete['status']==='Manuell pruefen' && !$incomplete['manualEligible']
    && in_array('missing_required_role',$incomplete['issueCodes'],true),
    'Missing typed role should request mapping, not claim defective hardware');





ck339(RCCDeviceTechnicalAudit::isLiteralArraySource('<?php return ["ok"=>true,"id"=>52];'),
    'Static RoomsCatalog literal arrays must be writable');
ck339(!RCCDeviceTechnicalAudit::isLiteralArraySource('<?php $secret=123; return ["s"=>$secret];')
    && !RCCDeviceTechnicalAudit::isLiteralArraySource('<?php return ["s"=>system("whoami")];'),
    'Executable PHP may not be rewritten as a static data array');


/** V3.3.12 – an actual simulated module must produce a downloadable,
 * credential-safe JSON report and never write without provable changes. */
$mod->properties['RoomsCatalogScriptID']=40;
IPS_SetScriptContent(40,$editAfterManual);
IPS_SetScriptContent(41,$editAfterManual);
$beforeExport=IPS_GetScriptContent(40);
ob_start();$mod->ExportAIRepairDiagnostics();$download=ob_get_clean();
$prefix='data:application/json;charset=utf-8;base64,';
ck339(str_starts_with($download,$prefix),'Diagnostic action did not return a Data-URL for download');
$json=base64_decode(substr($download,strlen($prefix)),true);
$report=json_decode($json===false ? '' : $json,true,512,JSON_THROW_ON_ERROR);
ck339(($report['schema']??'')==='RoomsCatalogSafeRepair/1'
    && ($report['summary']['total']??0)===5
    && count($report['devices']??[])===5
    && ($report['sources']['identical']??false)===true,
    'Diagnostic report does not cover entire catalog and source hashes');
ck339(!str_contains($json,'PRIVATE_VALUE_MUST_NOT_BE_CACHED'),
    'Diagnostic must not expose live IPS variable values');
ck339(IPS_GetScriptContent(40)===$beforeExport && IPS_GetScriptContent(41)===$beforeExport,
    'Read-only diagnostic modified scripts');
ob_start();$mod->ApplySafeAutoRepair();$safeAction=ob_get_clean();
ck339(str_contains($safeAction,'Keine eindeutigen weiteren Korrekturen')
    && IPS_GetScriptContent(40)===$beforeExport && IPS_GetScriptContent(41)===$beforeExport,
    'Safe auto-repair changed catalog without an unambiguous high-confidence mapping');


/** V3.3.13: the flattened-list ai JSON must be decoded in exports;
 * one unique writable KNX target is corrected with independent verified
 * backups for EDIT/PROD, without granting user-control permissions. */
addInvVar(50303,2003,'Wohnzimmer','Value',2,'~Temperature',false);
addInvVar(50304,2003,'Wohnzimmer Solltemperatur','Value',2,'~Temperature.Room',true);
$heaterCatalog=require $GLOBALS['inventoryDir'].'/scripts/41.php';
$heaterCatalog['wohnzimmer']['domains']['heizung']['wohnzimmer']=[
    'title'=>'Wohnzimmer','ist'=>50301,'soll'=>50303,'stellung'=>50301,
    'kiEnabled'=>true,'kiReadOnly'=>true,
    'ai'=>['version'=>1,'type'=>'heating',
        'variables'=>['temperature'=>50301,'targetTemperature'=>50303,'control'=>50303],
        'review'=>['status'=>'pending']]
];
$heaterSource='<?php return '.var_export($heaterCatalog,true).';';
IPS_SetScriptContent(40,$heaterSource);
IPS_SetScriptContent(41,$heaterSource);
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($mod);
ob_start();$mod->ExportAIRepairDiagnostics();$heaterDownload=ob_get_clean();
$prefix='data:application/json;charset=utf-8;base64,';
$heaterJson=base64_decode(substr($heaterDownload,strlen($prefix)),true);
$heaterReport=json_decode($heaterJson===false?'':$heaterJson,true,512,JSON_THROW_ON_ERROR);
$heaterRow=null;
foreach($heaterReport['devices'] as $row) {
    if($row['key']==='wohnzimmer|heizung|heizung|wohnzimmer') $heaterRow=$row;
}
ck339($heaterRow!==null
    && ($heaterRow['roles']['temperature']??0)===50301
    && ($heaterRow['roles']['targetTemperature']??0)===50303
    && ($heaterRow['roles']['control']??0)===50303
    && ($heaterRow['reviewStatus']??'')==='pending',
    'Diagnostic export lost ai.variables or review status after list serialization');
ck339(($heaterRow['safeSuggestedBindings']['control']??0)===50304,
    'Unique writable KNX setpoint must be proposed instead of read-only control');
ck339(($heaterReport['summary']['typedRolesExported']??0)>0,
    'Role export integrity count is zero for typed device');
ob_start();$mod->ApplySafeAutoRepair();$heaterApplied=ob_get_clean();
ck339(str_contains($heaterApplied,'ERFOLG: 1')
    && str_contains($heaterApplied,'Backups: EDIT #')
    && (IPS_GetScriptContent(40)===IPS_GetScriptContent(41)),
    'Guarded EDIT+PROD repair failed: '.$heaterApplied);
$heaterAfter=require $GLOBALS['inventoryDir'].'/scripts/41.php';
$fixed=$heaterAfter['wohnzimmer']['domains']['heizung']['wohnzimmer'];
ck339(($fixed['ai']['variables']['control']??0)===50304
    && ($fixed['ai']['variables']['targetTemperature']??0)===50303
    && ($fixed['ai']['variables']['temperature']??0)===50301
    && ($fixed['ai']['review']['status']??'')==='pending'
    && $fixed['kiReadOnly']===true
    && $fixed['kiEnabled']===true
    && $fixed['soll']===50303,
    'Control correction changed unrelated fields, read-only permissions or existing temperature roles');
ob_start();$mod->RunTechnicalDeviceAudit();ob_end_clean();
$newScan=json_decode($mod->ReadAttributeString('TechnicalDeviceSnapshot'),true,512,JSON_THROW_ON_ERROR);
$updatedStatus='';
foreach($newScan['rows'] as $row) {
    if($row['key']==='wohnzimmer|heizung|heizung|wohnzimmer') $updatedStatus=$row['status'];
}
ck339($updatedStatus==='Technisch plausibel',
    'Corrected KNX Solltemperatur control still reported uncertain');

foreach(array_keys($GLOBALS['inventoryScripts']) as $id) @unlink($GLOBALS['inventoryDir'].'/scripts/'.$id.'.php');
@rmdir($GLOBALS['inventoryDir'].'/scripts');@rmdir($GLOBALS['inventoryDir']);
echo "PASS V3.3.9: all types, IPS evidence, explicit approval, stale guard, EDIT-only updates, backups, legacy + diff reporting\n";
