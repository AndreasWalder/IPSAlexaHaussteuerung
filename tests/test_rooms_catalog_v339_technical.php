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
    ck339(!str_contains($ui,$token),'Obsolete advanced controls must not clutter the simple UI: '.$token);
}
ck339(str_contains($ui,'EDIT NACH PROD UEBERNEHMEN'),
    'Simple complete EDIT to PROD publishing action is missing');
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


// V3.3.15: "Nur Steuerung" is a reporting capability, NOT successful
// technical validation or a physical device confirmation.
$capMethod=new ReflectionMethod(RoomsCatalogConfigurator::class,'classifyTechnicalCapability');
$onlyCommand=[
    'aiType'=>'blind','wert'=>55194
];
$commandIssues=[
    'status'=>'Manuell pruefen',
    'issueCodes'=>['missing_required_role','state_mapping_missing']
];
$actionProfile=[
    ['id'=>55194,'name'=>'Test Rolladen 100%','ident'=>'','profile'=>'Rolladen_Steuerung',
        'type'=>1,'action'=>true,
        'associations'=>[
            ['value'=>0,'name'=>'Oeffnen'],
            ['value'=>50,'name'=>'Mitte'],
            ['value'=>100,'name'=>'Schliessen'],
            ['value'=>999,'name'=>'Stop']
        ]]
];
ck339($capMethod->invoke($mod,$onlyCommand,$commandIssues,$actionProfile,['action'=>55194])
    ==='Nur Steuerung (Ist-Rueckmeldung nicht nachgewiesen)',
    'Real KNX command-only shutter not correctly separated from mapping error');
ck339($capMethod->invoke($mod,$onlyCommand,$commandIssues,$actionProfile,[
        'action'=>55194,'position'=>55194])
    ==='Technische Zuordnung pruefen',
    'Misclassified command as physically measured position');
$invalidProfile=$actionProfile;
$invalidProfile[0]['profile']='~Shutter';
ck339($capMethod->invoke($mod,$onlyCommand,$commandIssues,$invalidProfile,['action'=>55194])
    ==='Technische Zuordnung pruefen',
    'Shutter control-only label must require a control action profile');
$readOnlyAction=$actionProfile;
$readOnlyAction[0]['action']=false;
ck339($capMethod->invoke($mod,$onlyCommand,$commandIssues,$readOnlyAction,['action'=>55194])
    ==='Technische Zuordnung pruefen',
    'No IPS action must prevent command-only category');
$ambiguousIssues=$commandIssues;
$ambiguousIssues['issueCodes'][]='role_name_ambiguous';
ck339($capMethod->invoke($mod,$onlyCommand,$ambiguousIssues,$actionProfile,['action'=>55194])
    ==='Technische Zuordnung pruefen',
    'Technical ambiguity should never be silently treated as command-only');
ck339($capMethod->invoke($mod, ['aiType'=>'blind','wert'=>55194],
        ['status'=>'Technisch plausibel','issueCodes'=>[]],
        $actionProfile,['action'=>55194])==='Technisch plausibel (nicht fachlich bestaetigt)',
    'Fully plausible category should remain separate');

$newForm=json_decode($mod->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$serialized=json_encode($newForm,JSON_UNESCAPED_UNICODE);
ck339(!str_contains($serialized,'"name":"capability"')
    && !str_contains($serialized,'Funktionsumfang')
    && str_contains($serialized,'EDIT NACH PROD UEBERNEHMEN'),
    'Technical analysis UI must be removed from the simple configurator');
$oldSnapshot=['rows'=>[1,2,3],'counts'=>['Technisch plausibel'=>1,'Manuell pruefen'=>2],
    'capabilityCounts'=>[
        'Technisch plausibel (nicht fachlich bestaetigt)'=>1,
        'Nur Steuerung (Ist-Rueckmeldung nicht nachgewiesen)'=>1,
        'Technische Zuordnung pruefen'=>1],
    'diffCounts'=>['new'=>0,'removed'=>0,'changed'=>0]];
$summaryMethod=new ReflectionMethod(RoomsCatalogConfigurator::class,'technicalDeviceSummaryFromSnapshot');
$capSummary=$summaryMethod->invoke($mod,$oldSnapshot);
ck339(str_contains($capSummary,'1 Geraete nur mit Steuerbefehl')
    && str_contains($capSummary,'1 technische Zuordnungen gesondert pruefen')
    && str_contains($capSummary,'2 manuell pruefen'),
    'Capabilities should never inflate technical plausibility counts');

// Snapshot classification never executes device actions or mutates catalogs.
$beforeCapabilityCheck=IPS_GetScriptContent(40);
ck339(IPS_GetScriptContent(40)===$beforeCapabilityCheck,
    'Classifier unexpectedly wrote production script');


/** V3.3.16 – user-facing discrepancy between migration report and
 * previous technical snapshot must be explainable by exact field names,
 * without copying PHP source bodies or current IPS variable values. */
$beforeDiff=IPS_GetScriptContent(41);
ck339($beforeDiff===IPS_GetScriptContent(40),'Diff fixture must start with identical scripts');
ob_start();$mod->ExportAIRepairDiagnostics();$identicalData=ob_get_clean();
$exportPrefix='data:application/json;charset=utf-8;base64,';
$identicalJson=base64_decode(substr($identicalData,strlen($exportPrefix)),true);
$identicalReport=json_decode($identicalJson===false?'':$identicalJson,true,512,JSON_THROW_ON_ERROR);
ck339(($identicalReport['editProdComparison']['total']??null)===0
    && ($identicalReport['editProdComparison']['counts']['changed']??null)===0,
    'A byte-identical PROD/EDIT pair should have no reported device differences');
$editDiff=require $GLOBALS['inventoryDir'].'/scripts/41.php';
$editDiff['wohnzimmer']['domains']['heizung']['wohnzimmer']['ai']['review']['technical']=['status'=>'validated'];
IPS_SetScriptContent(41,'<?php return '.var_export($editDiff,true).';');
ob_start();$mod->ExportAIRepairDiagnostics();$differentData=ob_get_clean();
$differentJson=base64_decode(substr($differentData,strlen($exportPrefix)),true);
$differentReport=json_decode($differentJson===false?'':$differentJson,true,512,JSON_THROW_ON_ERROR);
$diffItem=$differentReport['editProdComparison']['items'][0]??null;
ck339(($differentReport['sources']['identical']??true)===false
    && ($differentReport['editProdComparison']['total']??0)===1
    && ($differentReport['editProdComparison']['counts']['changed']??0)===1
    && ($diffItem['path']??'')==='wohnzimmer|heizung|heizung|wohnzimmer'
    && in_array('ai.review.technical',$diffItem['changedFields']??[],true),
    'Diagnostic must export changed AI review metadata field paths, not just a generic ai diff: '
        .json_encode($diffItem));
ck339(!str_contains($differentJson,'PRIVATE_VALUE_MUST_NOT_BE_CACHED')
    && !str_contains($differentJson,"'status' => 'validated'"),
    'Export must not leak live variable data or raw source PHP');
$originalProd=IPS_GetScriptContent(40);
$originalEdit=IPS_GetScriptContent(41);
ob_start();$mod->ApplySafeAutoRepair();$refusal=ob_get_clean();
ck339(str_contains($refusal,'EDIT und PROD unterscheiden sich')
    && IPS_GetScriptContent(40)===$originalProd
    && IPS_GetScriptContent(41)===$originalEdit,
    'Repair MUST refuse any outstanding EDIT/PROD diff, without writing');


/** V3.3.17 – one top-level reset clears only temporary reports,
 * inventories and selected checkboxes; never rewrites EDIT or PROD. */
$resetForm=json_decode($mod->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
ck339(
    ($resetForm['elements'][0]['items'][0]['onClick']??'')==='RCC_ResetAllCheckCaches($id);'
    && !empty($resetForm['elements'][0]['items'][0]['confirm']),
    'The reset button must be FIRST in the RoomsCatalogConfigurator and require confirmation'
);
$attributesToClear=[
    'SuggestionPreview'=>'{}','DiscoveryCandidates'=>'{}','DiscoveryInventory'=>'{}',
    'LegacyMigrationPreview'=>'{}','BulkPreview'=>'{}',
    'MigrationReadinessDetails'=>'{}','TechnicalDeviceSnapshot'=>'{}',
    'TechnicalDevicePreview'=>'{}','AutoRepairPreview'=>'{}'
];
foreach ($attributesToClear as $name=>$unused) {
    $mod->WriteAttributeString($name,'{"stale":true}');
}
$mod->WriteAttributeString('MigrationReadinessReport','Ein alter Migrationsbericht');
$mod->WriteAttributeString('DiscoveryAuditReport','Alte Inventur');
$mod->WriteAttributeString('TechnicalDeviceSummary','Alte technische Bewertung');
$mod->WriteAttributeString('TechnicalDeviceActionMessage','Alte Aktion');
$mod->WriteAttributeString('AutoRepairNotice','Alte Reparaturvorschau');
$mod->WriteAttributeString('FilterRoom','wohnzimmer');
$mod->WriteAttributeString('FilterDomain','licht');
$mod->WriteAttributeString('FilterGroup','switches');
$mod->WriteAttributeString('FilterAI','writable');
$mod->WriteAttributeString('BulkDeviceType','blind');
$mod->WriteAttributeString('BulkAIMode','enabled');
$mod->WriteAttributeString('BulkSuggestions','no');
$mod->WriteAttributeString('BulkReview','approve');
$mod->WriteAttributeString('RuntimeEntries','[{"selected":true,"key":"dummy"}]');
$mod->WriteAttributeString('RuntimeEditHash','stale');

$resetOriginalEdit=IPS_GetScriptContent(41);
$resetOriginalProd=IPS_GetScriptContent(40);
$resetCountScripts=count($GLOBALS['inventoryScripts']);
ob_start();$mod->ResetAllCheckCaches();$resetMsg=ob_get_clean();
ck339(str_contains($resetMsg,'Zwischenspeicher')
    && str_contains($resetMsg,'UNVERAENDERT')
    && IPS_GetScriptContent(40)===$resetOriginalProd
    && IPS_GetScriptContent(41)===$resetOriginalEdit
    && count($GLOBALS['inventoryScripts'])===$resetCountScripts,
    'Reset modified a data script or created a backup unexpectedly');
foreach ($attributesToClear as $name=>$expected) {
    ck339($mod->ReadAttributeString($name)===$expected,
        'Reset did not clear temporary attribute '.$name);
}
foreach ([
    'FilterRoom'=>'','FilterDomain'=>'','FilterGroup'=>'','FilterAI'=>'all',
    'BulkDeviceType'=>'keep','BulkAIMode'=>'keep',
    'BulkSuggestions'=>'yes','BulkReview'=>'keep',
    'MigrationReadinessReport'=>'Noch nicht geprueft.',
    'TechnicalDeviceActionMessage'=>'',
    'AutoRepairNotice'=>'Noch kein Diagnoseexport erstellt.'
] as $name=>$expected) {
    ck339($mod->ReadAttributeString($name)===$expected,
        'Reset left a stale filter, cache message or selection '.$name);
}
ck339(($mod->updates['TechnicalDeviceChecks.values']??'')==='[]'
    && ($mod->updates['TechnicalDiffRows.values']??'')==='[]'
    && ($mod->updates['DiscoveryInventoryList.values']??'')==='[]'
    && ($mod->updates['TechnicalDevicesPhysicallyChecked.value']??null)===false,
    'The open form still displays stale technical, diff, discovery or manual-review selections');
$resetLiveForm=json_decode($mod->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
ck339($mod->ReadAttributeString('RuntimeEntries')!=='[]'
    && $mod->ReadAttributeString('RuntimeEditHash')!=='stale'
    && str_contains(json_encode($resetLiveForm,JSON_UNESCAPED_UNICODE),'RoomsCatalog EDIT'),
    'Reset failed to reload the visible device editor from persisted catalog');
ob_start();$mod->ApplySafeAutoRepair();$afterResetApply=ob_get_clean();
ck339(str_contains($afterResetApply,'Bitte zuerst eine aktuelle Diagnose')
    && IPS_GetScriptContent(40)===$resetOriginalProd
    && IPS_GetScriptContent(41)===$resetOriginalEdit,
    'Reset must invalidate approvals and disallow stale automatic repair');
ob_start();$mod->ResetAllCheckCaches();ob_end_clean();
ck339(IPS_GetScriptContent(40)===$resetOriginalProd
    && IPS_GetScriptContent(41)===$resetOriginalEdit,
    'Reset should be idempotent and preserve catalog scripts');


/**
 * V3.3.18: Publish ONLY technically valid selected domains, without
 * ever copying invalid blind state mappings or unrelated bad devices.
 */
$domainProd=$catalog;
$domainEdit=$catalog;
$domainProd['wohnzimmer']['display']='Wohnzimmer PROD'; // preserve room metadata
$domainEdit['wohnzimmer']['display']='Wohnzimmer EDIT'; // never copied by partial publication
$domainProd['wohnzimmer']['domains']['licht']['switches']['decke']['title']='Vorige Decke';
$domainEdit['wohnzimmer']['domains']['licht']['switches']['decke']['title']='Neue Decke';
$domainEdit['wohnzimmer']['domains']['licht']['switches']['defekt']['title']='Ungueltige neue Zuordnung';
unset($domainEdit['wohnzimmer']['domains']['licht']['switches']['alt']); // destructive removal forbidden
$domainEdit['wc']['domains']['jalousie']['fenster']['ai']['stateMapping']=[];
$domainEdit['wc']['domains']['jalousie']['fenster']['title']='Ungültige Jalousie in EDIT';
IPS_SetScriptContent(40,'<?php return '.var_export($domainProd,true).';');
IPS_SetScriptContent(41,'<?php return '.var_export($domainEdit,true).';');
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($mod);

$partialForm=json_decode($mod->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$partialUI=json_encode($partialForm,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
ck339(!str_contains($partialUI,'RCC_PreviewScopedProdPublish')
    && !str_contains($partialUI,'RCC_ApplyScopedProdPublish')
    && !str_contains($partialUI,'BEREICHSWEISE PROD-UEBERNAHME')
    && !str_contains($partialUI,'NUR EINE JALOUSIE-POSITIONS-ID')
    && str_contains($partialUI,'EDIT NACH PROD UEBERNEHMEN'),
    'Obsolete scoped publishing UI must be replaced by one full-copy button');

$originalPartialProd=IPS_GetScriptContent(40);
$originalPartialEdit=IPS_GetScriptContent(41);
ck339(IPS_GetScriptContent(40)===$originalPartialProd,
    'Old scoped scenario should not change PROD before testing partial APIs');
ob_start();$mod->PreviewScopedProdPublish('licht');$partialPreview=ob_get_clean();
$planRows=json_decode($mod->ReadAttributeString('PartialProdPublishRows'),true,512,JSON_THROW_ON_ERROR);
$approvedRows=array_values(array_filter($planRows,static fn(array $row):bool=>($row['status']??'')==='Gueltig'));
$rejectedRows=array_values(array_filter($planRows,static fn(array $row):bool=>($row['status']??'')==='Gesperrt'));
ck339(str_contains($partialPreview,'1 gueltige Aenderungen')
    && count($approvedRows)===1
    && $approvedRows[0]['key']==='wohnzimmer|licht|switches|decke'
    && count($rejectedRows)===2,
    'Licht scope should accept only good light and reject invalid and removed devices: '.json_encode($planRows));
$beforePartialBackupCount=count($GLOBALS['inventoryScripts']);
ob_start();$mod->ApplyScopedProdPublish('licht');$publishedLight=ob_get_clean();
ck339(str_contains($publishedLight,'ERFOLG: 1')
    && count($GLOBALS['inventoryScripts'])===$beforePartialBackupCount+1
    && IPS_GetScriptContent(41)===$originalPartialEdit,
    'Selective publication did not create verified backup or unexpectedly modified EDIT: '.$publishedLight);
$afterLight=require $GLOBALS['inventoryDir'].'/scripts/40.php';
ck339($afterLight['wohnzimmer']['domains']['licht']['switches']['decke']['title']==='Neue Decke'
    && $afterLight['wohnzimmer']['domains']['licht']['switches']['defekt']['title']===$domainProd['wohnzimmer']['domains']['licht']['switches']['defekt']['title']
    && isset($afterLight['wohnzimmer']['domains']['licht']['switches']['alt'])
    && $afterLight['wohnzimmer']['display']==='Wohnzimmer PROD'
    && $afterLight['wc']['domains']['jalousie']['fenster']===$domainProd['wc']['domains']['jalousie']['fenster'],
    'Invalid light, deleted device, room metadata or wrong blind leaked into PROD');

$beforeInvalid=IPS_GetScriptContent(40);
$beforeInvalidBackup=count($GLOBALS['inventoryScripts']);
ob_start();$mod->PreviewScopedProdPublish('jalousie');$blindPreview=ob_get_clean();
$blindPlan=json_decode($mod->ReadAttributeString('PartialProdPublishRows'),true,512,JSON_THROW_ON_ERROR);
ck339(str_contains($blindPreview,'0 gueltige Aenderungen')
    && count($blindPlan)===1 && $blindPlan[0]['status']==='Gesperrt',
    'Blind with missing real state mapping must not pass partial validator');
ob_start();$mod->ApplyScopedProdPublish('jalousie');$blindBlocked=ob_get_clean();
ck339(str_contains($blindBlocked,'KEINE UEBERNAHME')
    && IPS_GetScriptContent(40)===$beforeInvalid
    && count($GLOBALS['inventoryScripts'])===$beforeInvalidBackup,
    'No valid blind may cause zero PROD writes or backups: '.$blindBlocked);

// Scope all valid OTHER domains, while leaving Jalousie and invalid Licht untouched.
$extraEdit=$domainEdit;
$extraEdit['wohnzimmer']['domains']['dimmer']['dimmers']['dimmer']['title']='Dimmer in EDIT';
IPS_SetScriptContent(41,'<?php return '.var_export($extraEdit,true).';');
ob_start();$mod->PreviewScopedProdPublish('__all_without_jalousie__');$multiPreview=ob_get_clean();
$multiPlan=json_decode($mod->ReadAttributeString('PartialProdPublishRows'),true,512,JSON_THROW_ON_ERROR);
$multiValid=array_values(array_filter($multiPlan,static fn(array $row):bool=>($row['status']??'')==='Gueltig'));
ck339(count($multiValid)===1 && $multiValid[0]['key']==='wohnzimmer|dimmer|dimmers|dimmer',
    'All-without-blind scope should publish one technically sound dimmer only: '.json_encode($multiPlan));
$beforeMultiEdit=IPS_GetScriptContent(41);
ob_start();$mod->ApplyScopedProdPublish('__all_without_jalousie__');$multiApplied=ob_get_clean();
$afterMulti=require $GLOBALS['inventoryDir'].'/scripts/40.php';
ck339(str_contains($multiApplied,'ERFOLG: 1')
    && $afterMulti['wohnzimmer']['domains']['dimmer']['dimmers']['dimmer']['title']==='Dimmer in EDIT'
    && $afterMulti['wc']['domains']['jalousie']['fenster']===$domainProd['wc']['domains']['jalousie']['fenster']
    && IPS_GetScriptContent(41)===$beforeMultiEdit,
    'All-valid non-blind scope touched a blind or failed dimmer publication');

// A different selection, re-edited catalog, and cleared cache all invalidate previews.
ob_start();$mod->PreviewScopedProdPublish('licht');ob_end_clean();
$beforeStale=IPS_GetScriptContent(40);
$staleSource=IPS_GetScriptContent(41)."\n";
IPS_SetScriptContent(41,$staleSource);
ob_start();$mod->ApplyScopedProdPublish('licht');$staleBlocked=ob_get_clean();
ck339(str_contains($staleBlocked,'hat sich seit der Vorschau veraendert')
    && IPS_GetScriptContent(40)===$beforeStale,
    'Stale preview should be blocked without modifying PROD: '.$staleBlocked);
ob_start();$mod->PreviewScopedProdPublish('licht');ob_end_clean();
ob_start();$mod->ApplyScopedProdPublish('heizung');$wrongDomain=ob_get_clean();
ck339(str_contains($wrongDomain,'fuer denselben Bereich')
    && IPS_GetScriptContent(40)===$beforeStale,
    'Preview approved for one domain cannot be replayed for another domain');
ob_start();$mod->ResetAllCheckCaches();ob_end_clean();
ob_start();$mod->ApplyScopedProdPublish('licht');$resetBlocked=ob_get_clean();
ck339(str_contains($resetBlocked,'Bitte zuerst eine aktuelle Bereichs-Vorschau')
    && IPS_GetScriptContent(40)===$beforeStale,
    'Reset must invalidate all partial PROD publish approvals');



/** V3.3.19: simpler domain-specific editor and a shared fan control/status ID. */
$layout=json_decode($mod->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$topCaptions=array_column($layout['elements'],'caption');
ck339($topCaptions===array_values(array_filter($topCaptions,static fn($v):bool=>$v!==null))
    && in_array('Raeume & Etagen',$topCaptions,true)
    && in_array('RoomsCatalog Scripts',$topCaptions,true)
    && in_array('Geraete bearbeiten (RoomsCatalog EDIT)',$topCaptions,true),
    'Daily RoomsCatalog functions must remain visible');
$simpleUi=json_encode($layout,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
ck339(!str_contains($simpleUi,'Weitere Funktionen (Experten)')
    && !str_contains($simpleUi,'RCC_ApplyScopedProdPublish')
    && !str_contains($simpleUi,'RCC_RunTechnicalDeviceAudit')
    && str_contains($simpleUi,'EDIT NACH PROD UEBERNEHMEN'),
    'Only core device editing and one full EDIT-to-PROD action should be shown');

$mod->UpdateEntryTypeForm('fan');
ck339(($mod->updates['aiPowerId.visible']??null)===true
    && ($mod->updates['aiControlId.visible']??null)===true
    && ($mod->updates['aiStatusId.visible']??null)===false
    && ($mod->updates['aiOnValue.visible']??null)===false
    && ($mod->updates['aiOffValue.visible']??null)===false,
    'Fan editor should show just optional On/Off value plus control, no extra device status');
$mod->UpdateEntryTypeForm('heating');
ck339(($mod->updates['aiTemperatureId.visible']??null)===true
    && ($mod->updates['aiTargetTemperatureId.visible']??null)===true
    && ($mod->updates['aiControlId.visible']??null)===true
    && ($mod->updates['aiStatusId.visible']??null)===false,
    'Heating editor needs only actual temperature, setpoint and writable control');

$fanId=50811;
addInvVar($fanId,2008,'Ein/Aus','Value',0,'~Switch',true);
$fanMeta=[
    ['id'=>$fanId,'name'=>'Ein/Aus','ident'=>'Value','type'=>0,
        'profile'=>'~Switch','action'=>true,'associations'=>[]]
];
$fanScore=static fn(array $v,string $role):int=>130;
$fanExists=static fn(int $id):bool=>$id===$fanId;
$fanFieldMap=['power'=>'aiPowerId','control'=>'aiControlId'];
$fanShared=RCCDeviceTechnicalAudit::assess(
    ['aiType'=>'fan','aiPowerId'=>$fanId,'aiControlId'=>$fanId],
    $fanFieldMap,$fanMeta,[['power','control']],$fanScore,$fanExists
);
ck339($fanShared['status']==='Technisch plausibel'
    && !in_array('duplicate_mapping',$fanShared['issueCodes'],true)
    && !in_array('missing_required_role',$fanShared['issueCodes'],true),
    'Shared control and On/Off-value must be valid without invented fan status');
$fanOnlyControl=RCCDeviceTechnicalAudit::assess(
    ['aiType'=>'fan','aiControlId'=>$fanId],
    $fanFieldMap,$fanMeta,[['power','control']],$fanScore,$fanExists
);
ck339($fanOnlyControl['status']==='Technisch plausibel',
    'One fan control variable alone must be sufficient');

$validator=new ReflectionMethod(RoomsCatalogConfigurator::class,'validateEntries');
$fanCatalogRow=[
    'roomKey'=>'wohnzimmer','domain'=>'lueftung','group'=>'fans','key'=>'ventilator',
    'title'=>'Ventilator','aiType'=>'fan',
    'aiPowerId'=>0,'aiControlId'=>$fanId,
    'kiEnabled'=>true,'kiReadOnly'=>true
];
ck339($validator->invoke($mod,[$fanCatalogRow],true)===[],
    'Read-only fan with just one control variable should pass ordinary catalog validation');
$fanCatalogRow['kiReadOnly']=false;
ck339($validator->invoke($mod,[$fanCatalogRow],true)===[],
    'Fan write role must reuse its existing action ID without an extra device status');
$fanModeMethod=new ReflectionMethod(RoomsCatalogConfigurator::class,'classifyTechnicalCapability');
ck339($fanModeMethod->invoke($mod,['aiType'=>'fan'],['status'=>'Technisch plausibel'],
        $fanMeta,['power'=>$fanId,'control'=>$fanId])
    ==='Nur Steuerungswert (kein separater Iststatus)',
    'Fan using same command/read ID must never be labelled real physical feedback');
$proposal=RCCSafeAutoRepair::propose(
    ['aiType'=>'fan','ai'=>['variables'=>['control'=>$fanId]]],
    $fanMeta,static fn(array $v,string $role):int=>140
);
ck339($proposal===[],'Auto-repair must not invent missing fan feedback when control alone is valid');
$fanMeta[0]['action']=false;
$badFan=RCCDeviceTechnicalAudit::assess(
    ['aiType'=>'fan','aiPowerId'=>$fanId,'aiControlId'=>$fanId],
    $fanFieldMap,$fanMeta,[['power','control']],$fanScore,$fanExists
);
ck339($badFan['status']==='Manuell pruefen'
    && in_array('no_action',$badFan['issueCodes'],true),
    'A fan without a real write action must not silently be treated as controllable');

$heaterId=50304;
addInvVar($heaterId,2003,'Solltemperatur','Value',2,'~Temperature.Room',true);
$heaterMeta=[
    ['id'=>50301,'name'=>'Isttemperatur','type'=>2,'profile'=>'~Temperature','action'=>false],
    ['id'=>$heaterId,'name'=>'Solltemperatur','type'=>2,'profile'=>'~Temperature.Room','action'=>true]
];
$heaterTest=RCCDeviceTechnicalAudit::assess(
    ['aiType'=>'heating','aiTemperatureId'=>50301,
        'aiTargetTemperatureId'=>$heaterId,'aiControlId'=>$heaterId],
    ['temperature'=>'aiTemperatureId','targetTemperature'=>'aiTargetTemperatureId','control'=>'aiControlId'],
    $heaterMeta,[['temperature']],
    static fn(array $v,string $role):int=>130,
    static fn(int $id):bool=>in_array($id,[50301,50304],true)
);
ck339($heaterTest['status']==='Technisch plausibel'
    && !in_array('duplicate_mapping',$heaterTest['issueCodes'],true),
    'Heater may use the same writable setpoint for targetTemperature and control');
$catalogSourceBeforeSimpleUI=IPS_GetScriptContent(40);
$editSourceBeforeSimpleUI=IPS_GetScriptContent(41);
$mod->GetConfigurationForm();
ck339(IPS_GetScriptContent(40)===$catalogSourceBeforeSimpleUI
    && IPS_GetScriptContent(41)===$editSourceBeforeSimpleUI,
    'Reorganized UI and profile checks must be read-only');


/** V3.3.20: field-only Jalousie position transfer must work even when
 * the rest of the device is NOT technically complete. No other AI data
 * or controller settings may leak from EDIT to PROD. */
$positionProd=$catalog;
$positionEdit=$catalog;
$positionOriginal=$positionProd['wc']['domains']['jalousie']['fenster'];
$positionEdit['wc']['domains']['jalousie']['fenster']['ai']['variables']['position']=59001;
$positionEdit['wc']['domains']['jalousie']['fenster']['aiPositionId']=59001;
$positionEdit['wc']['domains']['jalousie']['fenster']['ai']['stateMapping']=[];
$positionEdit['wc']['domains']['jalousie']['fenster']['ai']['review']['technical']=['status'=>'validated'];
$positionEdit['wc']['domains']['jalousie']['fenster']['title']='NICHT NACH PROD KOPIEREN';
$positionEdit['wc']['domains']['jalousie']['fenster']['kiEnabled']=true;
addInvVar(59001,17426,'Position','POS02',1,'~Shutter',false);
IPS_SetScriptContent(40,'<?php return '.var_export($positionProd,true).';');
IPS_SetScriptContent(41,'<?php return '.var_export($positionEdit,true).';');
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($mod);
$singleKey='wc|jalousie|jalousie|fenster';
$singleForm=json_decode($mod->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$singleFormText=json_encode($singleForm,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
ck339(!str_contains($singleFormText,'NUR EINE JALOUSIE-POSITIONS-ID')
    && !str_contains($singleFormText,'RCC_PreviewSinglePositionProd')
    && !str_contains($singleFormText,'RCC_ApplySinglePositionProd')
    && str_contains($singleFormText,'EDIT NACH PROD UEBERNEHMEN'),
    'Field-only UI must be replaced with the simple full-catalog action');
$beforePositionProd=IPS_GetScriptContent(40);
$beforePositionEdit=IPS_GetScriptContent(41);
ck339(IPS_GetScriptContent(40)===$beforePositionProd,
    'The single-position legacy test must not alter PROD before invoking the old API');
$singleOption='wc|jalousie|jalousie|fenster';
$mod->SetSinglePositionProdKey($singleOption);
ob_start();$mod->PreviewSinglePositionProd($singleOption);$previewPosition=ob_get_clean();
ck339(str_contains($previewPosition,'EINZELFELD GÜLTIG')
    && str_contains($previewPosition,'59001')
    && IPS_GetScriptContent(40)===$beforePositionProd,
    'Individual valid position should preview despite incomplete open/closed mapping: '.$previewPosition);
$oldBackupNumber=count($GLOBALS['inventoryScripts']);
ob_start();$mod->ApplySinglePositionProd($singleOption);$appliedPosition=ob_get_clean();
ck339(str_contains($appliedPosition,'ERFOLG')
    && count($GLOBALS['inventoryScripts'])===$oldBackupNumber+1
    && IPS_GetScriptContent(41)===$beforePositionEdit,
    'Single field not applied with verified backup or changed EDIT: '.$appliedPosition);
$nowPosition=require $GLOBALS['inventoryDir'].'/scripts/40.php';
$pubLeaf=$nowPosition['wc']['domains']['jalousie']['fenster'];
ck339(($pubLeaf['ai']['variables']['position']??0)===59001
    && ($pubLeaf['aiPositionId']??0)===59001,
    'Only the intended position binding should be written');
unset($pubLeaf['aiPositionId']);
$comparison=$positionOriginal;
$comparison['ai']['variables']['position']=59001;
ck339($pubLeaf===$comparison
    && $nowPosition['wohnzimmer']===$positionProd['wohnzimmer']
    && $nowPosition['wc']['display']===$positionProd['wc']['display'],
    'Field-only position write changed room, command, title, status, AI review or KI rights');
ck339($positionEdit['wc']['domains']['jalousie']['fenster']['ai']['stateMapping']===[],
    'Field-only copy must not correct or alter incomplete EDIT data');
$beforeSecondApply=IPS_GetScriptContent(40);
ob_start();$mod->ApplySinglePositionProd($singleOption);$replayBlocked=ob_get_clean();
ck339(str_contains($replayBlocked,'Bitte zuerst') && IPS_GetScriptContent(40)===$beforeSecondApply,
    'Committed preview cannot be replayed');

$posChangedAgain=$positionEdit;
$posChangedAgain['wc']['domains']['jalousie']['fenster']['ai']['variables']['position']=59002;
$posChangedAgain['wc']['domains']['jalousie']['fenster']['aiPositionId']=59002;
addInvVar(59002,17426,'Position','POS03',1,'~Shutter',true);
IPS_SetScriptContent(41,'<?php return '.var_export($posChangedAgain,true).';');
ob_start();$mod->PreviewSinglePositionProd($singleOption);$commandRefusal=ob_get_clean();
ck339(str_contains($commandRefusal,'EINZELFELD GESPERRT')
    && str_contains($commandRefusal,'beschreibbar')
    && IPS_GetScriptContent(40)===$beforeSecondApply,
    'Writable command variable should not be published as real position');
$posChangedAgain['wc']['domains']['jalousie']['fenster']['ai']['variables']['position']=59001;
$posChangedAgain['wc']['domains']['jalousie']['fenster']['aiPositionId']=59003;
addInvVar(59003,17426,'Position','POS04',0,'~Switch');
IPS_SetScriptContent(41,'<?php return '.var_export($posChangedAgain,true).';');
ob_start();$mod->PreviewSinglePositionProd($singleOption);$conflictRefusal=ob_get_clean();
ck339(str_contains($conflictRefusal,'EINZELFELD GESPERRT')
    && str_contains($conflictRefusal,'unterschiedliche neue IDs'),
    'Inconsistent typed/legacy position IDs must not be silently chosen');
$posChangedAgain['wc']['domains']['jalousie']['fenster']['ai']['variables']['position']=59004;
$posChangedAgain['wc']['domains']['jalousie']['fenster']['aiPositionId']=59004;
addInvVar(59004,17426,'Position','POS05',0,'~Switch');
IPS_SetScriptContent(41,'<?php return '.var_export($posChangedAgain,true).';');
ob_start();$mod->PreviewSinglePositionProd($singleOption);$wrongType=ob_get_clean();
ck339(str_contains($wrongType,'Integer-/Float-Wert'),
    'Boolean position must not pass isolated field verification');

$posChangedAgain['wc']['domains']['jalousie']['fenster']['ai']['variables']['position']=59005;
$posChangedAgain['wc']['domains']['jalousie']['fenster']['aiPositionId']=59005;
addInvVar(59005,17426,'Position','POS06',1,'~Shutter');
IPS_SetScriptContent(41,'<?php return '.var_export($posChangedAgain,true).';');
ob_start();$mod->PreviewSinglePositionProd($singleOption);$validAgain=ob_get_clean();
ck339(str_contains($validAgain,'EINZELFELD GÜLTIG'), 'Fresh valid field preview missing');
$staleOrigin=IPS_GetScriptContent(40);
IPS_SetScriptContent(41,IPS_GetScriptContent(41)."\n");
ob_start();$mod->ApplySinglePositionProd($singleOption);$stalePosition=ob_get_clean();
ck339(str_contains($stalePosition,'seit Vorschau veraendert')
    && IPS_GetScriptContent(40)===$staleOrigin,
    'Stale edit hash must block field-only PROD write');
ob_start();$mod->PreviewSinglePositionProd($singleOption);ob_end_clean();
ob_start();$mod->ResetAllCheckCaches();ob_end_clean();
ob_start();$mod->ApplySinglePositionProd($singleOption);$afterResetPosition=ob_get_clean();
ck339(str_contains($afterResetPosition,'Bitte zuerst')
    && IPS_GetScriptContent(40)===$staleOrigin,
    'Reset must invalidate old field-only approvals without writing');



/** V3.3.21 – The only visible publish button copies ALL device types
 * and fields, including incomplete blinds and mismatched dimmer/fan roles,
 * without validation. Backup and failure guards still apply. */
$fullProd=$catalog;
$fullEdit=$catalog;
$fullProd['wc']['domains']['jalousie']['fenster']['title']='PROD alter Titel';
$fullEdit['wc']['domains']['jalousie']['fenster']['title']='EDIT neuer Titel';
$fullEdit['wc']['domains']['jalousie']['fenster']['ai']['stateMapping']=[];
$fullEdit['wc']['domains']['jalousie']['fenster']['ai']['variables']['position']=59001;
$fullEdit['wc']['domains']['jalousie']['fenster']['aiPositionId']=59001;
$fullEdit['wohnzimmer']['domains']['licht']['switches']['decke']['title']='EDIT Licht';
$fullEdit['wohnzimmer']['domains']['licht']['switches']['defekt']['title']='EDIT ungültiges Licht';
$fullEdit['wohnzimmer']['domains']['dimmer']['dimmers']['dimmer']['title']='EDIT Dimmer';
$fullEdit['wohnzimmer']['display']='EDIT Raumdaten';
$fullEdit['wc']['domains']['jalousie']['fenster']['kiEnabled']=true;
$fullEdit['wc']['domains']['jalousie']['fenster']['kiReadOnly']=true;
$prodOriginal='<?php return '.var_export($fullProd,true).';';
$editOriginal='<?php return '.var_export($fullEdit,true).';';
IPS_SetScriptContent(40,$prodOriginal);
IPS_SetScriptContent(41,$editOriginal);
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($mod);
$fullForm=json_decode($mod->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$fullFormJson=json_encode($fullForm,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
ck339(substr_count($fullFormJson,'RCC_SaveEditToProd($id);')===1
    && !str_contains($fullFormJson,'RCC_PreviewScopedProdPublish')
    && !str_contains($fullFormJson,'RCC_ApplySinglePositionProd'),
    'Exactly one explicit EDIT to PROD publish action should be visible');

/** V3.3.22: editable pending diff, row-level explanations, copyable
 * read-only diagnostic; all remain independent from full publication. */
$pendingUi=json_encode($fullForm,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
ck339(str_contains($pendingUi,'PendingChanges')
    && str_contains($pendingUi,'RCC_GetEntryEditForm')
    && str_contains($pendingUi,'$PendingChanges["roomKey"]')
    && str_contains($pendingUi,'RCC_SaveToEdit')
    && str_contains($pendingUi,'ROOMSCATALOG UEBERPRUEFEN')
    && str_contains($pendingUi,'CatalogCheckDebugOutput')
    && substr_count($pendingUi,'RCC_SaveEditToProd($id);')===1,
    'Single PROD button, editable pending diff and read-only check must coexist');
$pendingTable=null;
$deviceTable=null;
$findTable339=function(array $nodes) use (&$findTable339,&$pendingTable,&$deviceTable):void {
    foreach($nodes as $item) {
        if(($item['name']??'')==='PendingChanges')$pendingTable=$item;
        if(($item['name']??'')==='Entries')$deviceTable=$item;
        if(is_array($item['items']??null))$findTable339($item['items']);
    }
};
$findTable339($fullForm['elements']);
ck339(is_array($pendingTable) && is_array($deviceTable)
    && count($pendingTable['values'])>=3,
    'Pending changes must show edits across blind and light/dimmer');
$changesByKey=[];
foreach($pendingTable['values'] as $row) {
    $changesByKey[implode('|',[$row['roomKey'],$row['domain'],$row['group'],$row['key']])]=$row;
}
ck339(($changesByKey['wc|jalousie|jalousie|fenster']['changeKind']??null)==='Geaendert'
    && str_contains($changesByKey['wc|jalousie|jalousie|fenster']['changeDetails'],'ai.variables.position')
    && ($changesByKey['wohnzimmer|licht|switches|decke']['editable']??false)===true,
    'Changed field paths are not precise or editable entries lack gear');
$badRow=array_values(array_filter($deviceTable['values'],
    static fn(array $row):bool=>($row['key']??'')==='defekt'))[0];
ck339(($badRow['checkStatus']??'')==='Pruefen'
    && str_contains((string)($badRow['checkDetails']??''),'Variable')
    && ($badRow['rowColor']??'')==='#FFCCCC',
    'Red invalid device must explain which variable needs checking');
$beforeReadOnlyEdit=IPS_GetScriptContent(41);
$beforeReadOnlyProd=IPS_GetScriptContent(40);
ob_start();$mod->CheckRoomsCatalog();$checkNotice=ob_get_clean();
$debug=json_decode($mod->ReadAttributeString('CatalogCheckDebugOutput'),true,512,JSON_THROW_ON_ERROR);
ck339(($debug['schema']??'')==='RoomsCatalogCheck/1'
    && ($debug['statistics']['total']??-1)===5
    && !empty($debug['problems'])
    && ($debug['editProdDifferences']['total']??0)>=3
    && str_contains($checkNotice,'Geraete')
    && str_contains($mod->updates['CatalogCheckDebugOutput.value']??'','RoomsCatalogCheck/1'),
    'One-click diagnostic must populate copyable metadata-only JSON for current EDIT');
ck339(IPS_GetScriptContent(41)===$beforeReadOnlyEdit
    && IPS_GetScriptContent(40)===$beforeReadOnlyProd,
    'RoomsCatalog check or pre-prod change review must not write to catalog');
$beforeResetDebug=$mod->ReadAttributeString('CatalogCheckDebugOutput');
ck339($beforeResetDebug!=='' && !str_contains($beforeResetDebug,'PRIVATE_VALUE_MUST_NOT_BE_CACHED'),
    'Debug must avoid current live IPS variable values');

$preBackupCount=count($GLOBALS['inventoryScripts']);
ob_start();$mod->SaveEditToProd();$fullOutcome=ob_get_clean();
ck339(str_contains($fullOutcome,'ERFOLG')
    && IPS_GetScriptContent(40)===$editOriginal
    && IPS_GetScriptContent(41)===$editOriginal
    && count($GLOBALS['inventoryScripts'])===$preBackupCount+1,
    'All-device EDIT->PROD copy failed despite incomplete KNX blind: '.$fullOutcome);
$backupScriptId=array_key_last($GLOBALS['inventoryScripts']);
ck339(IPS_GetScriptContent($backupScriptId)===$prodOriginal,
    'Full catalog publish backup must preserve the exact previous PROD source');
ck339((require $GLOBALS['inventoryDir'].'/scripts/40.php')===$fullEdit,
    'All device types, room metadata, KI rights and even unvalidated fields must be copied 1:1');
ck339(($mod->updates['PendingChanges.values']??'x')==='[]',
    'After full EDIT->PROD copy the pending-device list must refresh to empty');
$prodBeforeNoop=IPS_GetScriptContent(40);
$backupBeforeNoop=count($GLOBALS['inventoryScripts']);
ob_start();$mod->SaveEditToProd();$noop=ob_get_clean();
ck339(str_contains($noop,'bereits identisch')
    && count($GLOBALS['inventoryScripts'])===$backupBeforeNoop
    && IPS_GetScriptContent(40)===$prodBeforeNoop,
    'Unchanged catalog should not create pointless backups or rewrite PROD');
IPS_SetScriptContent(41,"<?php return [];");
$prodBeforeEmpty=IPS_GetScriptContent(40);
ob_start();$mod->SaveEditToProd();$emptyRefusal=ob_get_clean();
ck339(str_contains($emptyRefusal,'EDIT NACH PROD ABGEBROCHEN')
    && IPS_GetScriptContent(40)===$prodBeforeEmpty,
    'Empty EDIT must never replace a working PROD catalog');
IPS_SetScriptContent(41,'<?php return system("echo unsafe");');
ob_start();$mod->SaveEditToProd();$unsafeRefusal=ob_get_clean();
ck339(str_contains($unsafeRefusal,'statisches PHP-Array')
    && IPS_GetScriptContent(40)===$prodBeforeEmpty,
    'Dynamic executable PHP must never be copied into a production catalog');

foreach(array_keys($GLOBALS['inventoryScripts']) as $id) @unlink($GLOBALS['inventoryDir'].'/scripts/'.$id.'.php');
@rmdir($GLOBALS['inventoryDir'].'/scripts');@rmdir($GLOBALS['inventoryDir']);
echo "PASS V3.3.9: all types, IPS evidence, explicit approval, stale guard, EDIT-only updates, backups, legacy + diff reporting\n";
