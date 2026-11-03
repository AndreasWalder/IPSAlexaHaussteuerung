<?php
declare(strict_types=1);

/** V3.3.5 integration tests using the V3.3.4 simulated IP-Symcon server. */
require __DIR__ . '/test_system_configuration_v334.php';

function sc335_assert(bool $ok,string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

// Connect the RoomsCatalogEdit script as an auto-detectable sibling. A real
// installation can alternatively resolve it through RoomsCatalogConfigurator.
$GLOBALS['sc333Objects'][503] = ['parent'=>200,'name'=>'RoomsCatalogEdit','ident'=>'roomsCatalogEdit'];
$GLOBALS['sc333Scripts'][503] = IPS_GetScriptContent(502);
file_put_contents(IPS_GetScriptFile(503), IPS_GetScriptContent(502));
$module->properties['NavigationRoomsCatalogEditScriptId'] = 503;

// The active Alexa action must have both v335 runtime patches installed.
$GLOBALS['sc333Objects'][507] = ['parent'=>100,'name'=>'Renderers','ident'=>'iahRenderers'];
$GLOBALS['sc333Objects'][504] = ['parent'=>507,'name'=>'Action (Haus\\Übersicht/Einstellungen Entry)','ident'=>'iahActionEntry'];
$GLOBALS['sc333Scripts'][504] =
    "<?php\n// IAH_NAVIGATION_CONFIG_V335\n// IAH_NAVIGATION_OVERLAY_V335_END\n";
file_put_contents(IPS_GetScriptFile(504), IPS_GetScriptContent(504));
$GLOBALS['sc333Objects'][505] = ['parent'=>100,'name'=>'Alexa new devices helper','ident'=>'iahHelper'];
$GLOBALS['sc333Objects'][506] = ['parent'=>505,'name'=>'NavigationRuntime','ident'=>'navigationRuntimeScript'];
$GLOBALS['sc333Scripts'][506] = file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/NavigationRuntime.php');
file_put_contents(IPS_GetScriptFile(506), IPS_GetScriptContent(506));
function IPS_GetParent(int $id): int { return (int)($GLOBALS['sc333Objects'][$id]['parent'] ?? 0); }
function IPS_ObjectExists(int $id): bool
{
    return in_array($id, [53544,44390,38806,58379,57001],true);
}

$prodOld = IPS_GetScriptContent(501);
$catalogOld = IPS_GetScriptContent(502);
$catalogEditOld = IPS_GetScriptContent(503);
$editNavBefore = require IPS_GetScriptFile($editId);
$nav = $editNavBefore['navigation'];

$sampleKey = array_key_first($nav['items']);
$sampleEntry = $nav['items'][$sampleKey];
$sampleUnicode = IAHSystemNavigationEditor::update(['navigation'=>$nav],[
    'entryKey'=>$sampleKey,
    'revision'=>IAHSystemNavigationEditor::entryHash($sampleEntry),
    'roomKey'=>'Außen',
    'domainKey'=>$sampleEntry['domainKey'],
    'title'=>$sampleEntry['title'],
    'objectId'=>$sampleEntry['objectId'],
    'sortOrder'=>$sampleEntry['sortOrder'],
    'aliases'=>$sampleEntry['aliases'],
    'enabled'=>$sampleEntry['enabled']
]);
sc335_assert($sampleUnicode['navigation']['items'][$sampleKey]['roomKey']==='Außen',
    'Unicode room keys must be editable without conversion');

$audit = IAHSystemNavigationMigration::audit($legacyCatalog, $nav, 'IPS_ObjectExists');
sc335_assert($audit['errors']===[] && $audit['total']===5 && $audit['legacy']===4,
    'Navigation source mapping validation failed');
$activatedNav=$nav;
$activatedNav['mode']='active';
$projected = IAHSystemNavigationMigration::project($legacyCatalog,$activatedNav);
sc335_assert(($projected['global']['domains']['robot']['tabs']['Roboter Diagnose']['id']??null)===57001,
    'New navigation entry missing in runtime overlay');
sc335_assert(!isset($projected['global']['domains']['robot']['tabs']['Dock']),
    'Disabled Dock tab should disappear from runtime overlay');
sc335_assert($projected['wohnzimmer']['domains']['licht']['switches']['decke']['state']===12,
    'Runtime overlay modified actual device states');

$invalid=$activatedNav;
$invalid['items'][array_key_first($invalid['items'])]['objectId']=987654;
$audit=IAHSystemNavigationMigration::audit($legacyCatalog,$invalid,'IPS_ObjectExists');
sc335_assert(count($audit['errors'])>=1,'Missing IPS object was accepted');
$wrongSource=$activatedNav;
$first=array_key_first($wrongSource['items']);
$wrongSource['items'][$first]['legacyDefinition']=4444;
sc335_assert(IAHSystemNavigationMigration::audit($legacyCatalog,$wrongSource)['errors']!==[],
    'Stale source definition was accepted');
$unmanaged=$legacyCatalog;
$unmanaged['global']['domains']['robot']['tabs']['EXTRA']=9001;
sc335_assert(IAHSystemNavigationMigration::audit($unmanaged,$activatedNav)['errors']!==[],
    'Unmanaged tab was silently removed');

// UI and strict runtime preflight.
$form=json_decode($module->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$ui=json_encode($form,JSON_THROW_ON_ERROR);
sc335_assert(str_contains($ui,'PreviewSystemNavigationCutover')
    && str_contains($ui,'CleanupSystemNavigation')
    && str_contains($ui,'RollbackSystemNavigation')
    && str_contains($ui,'SystemNavigationLiveTested'), 'V3.3.5 controls are missing');
sc335_assert(!str_contains($ui,'PRIVATE'), 'SystemConfiguration secrets leaked into UI');

// No confirmation: no writes.
ob_start();
$module->PreviewSystemNavigationCleanup(false);
ob_end_clean();
sc335_assert(IPS_GetScriptContent(501)===$prodOld,'Cleanup preview unexpectedly changed PROD');

ob_start();
$module->PreviewSystemNavigationCutover();
$previewResult=ob_get_clean();
sc335_assert(str_contains($previewResult,'Aktivierung bereit'), 'Productive activation blocked unexpectedly');
$preview=json_decode($module->ReadAttributeString('SystemNavigationMigrationPreview'),true,512,JSON_THROW_ON_ERROR);
sc335_assert($preview['phase']==='activate','Activation preview not persisted');
// Stale source change must block the activation.
IPS_SetScriptContent(502,$catalogOld . " \n");
ob_start(); $module->ActivateSystemNavigationCutover(); ob_end_clean();
sc335_assert(IPS_GetScriptContent(501)===$prodOld, 'Stale activation modified PROD');
IPS_SetScriptContent(502,$catalogOld);

ob_start();
$module->PreviewSystemNavigationCutover();
$module->ActivateSystemNavigationCutover();
ob_end_clean();
$live=require IPS_GetScriptFile(501);
sc335_assert(($live['navigation']['mode']??'')==='active'
    && ($live['var']['Token']??null)==='PRIVATE',
    'Activation did not preserve unrelated PROD keys');
sc335_assert(IPS_GetScriptContent(502)===$catalogOld
    && IPS_GetScriptContent(503)===$catalogEditOld,
    'Activation prematurely cleaned RoomsCatalog');
$receipt=json_decode($module->ReadAttributeString('SystemNavigationMigrationReceipt'),true,512,JSON_THROW_ON_ERROR);
sc335_assert(($receipt['stage']??'')==='active','Activation receipt missing');

// Cleanup only after explicit actual-device test confirmation.
ob_start();$module->PreviewSystemNavigationCleanup(false);ob_end_clean();
sc335_assert($module->ReadAttributeString('SystemNavigationMigrationPreview')==='',
    'Cleanup allowed without live-tested checkbox');
ob_start();
$module->PreviewSystemNavigationCleanup(true);
$cleanupPreviewResult=ob_get_clean();
sc335_assert(str_contains($cleanupPreviewResult,'Bereinigung bereit'),
    'Cleanup preview does not pass full parity checks: '.$cleanupPreviewResult);
ob_start();$module->CleanupSystemNavigation(false);ob_end_clean();
sc335_assert(IPS_GetScriptContent(502)===$catalogOld,'Missing user confirmation allowed cleanup');
ob_start();$module->CleanupSystemNavigation(true);$cleanupResult=ob_get_clean();
sc335_assert(str_contains($cleanupResult,'bereinigt'), 'Cleanup failed: '.$cleanupResult);
$cleanCatalog=require IPS_GetScriptFile(502);
$cleanCatalogEdit=require IPS_GetScriptFile(503);
sc335_assert(count(IAHSystemConfigurationManager::inventoryNavigation($cleanCatalog)['entries'])===0,
    'PROD RoomsCatalog still contains migrated tabs');
sc335_assert(count(IAHSystemConfigurationManager::inventoryNavigation($cleanCatalogEdit)['entries'])===0,
    'RoomsCatalogEdit still contains migrated tabs');
sc335_assert($cleanCatalog['wohnzimmer']['domains']['licht']['switches']['decke']['state']===12,
    'Device field lost during cleanup');
IAHSystemNavigationMigration::assertProjectionParity($legacyCatalog,$cleanCatalog,$live['navigation']);
$receipt=json_decode($module->ReadAttributeString('SystemNavigationMigrationReceipt'),true,512,JSON_THROW_ON_ERROR);
sc335_assert(($receipt['stage']??'')==='cleaned'
    && IPS_GetScriptContent((int)$receipt['backupProdId'])===$catalogOld
    && IPS_GetScriptContent((int)$receipt['backupEditId'])===$catalogEditOld,
    'Catalog source backups not retained');

// After cleanup, a new tab edit can be published through the main module.
// No manual SystemConfiguration script editor is required.
$editable = require IPS_GetScriptFile($editId);
$updatedKeys = array_keys($editable['navigation']['items']);
$changedKey = array_values(array_filter($updatedKeys, static function(string $key) use ($editable): bool {
    return ($editable['navigation']['items'][$key]['title'] ?? '') === 'Roboter Diagnose';
}))[0] ?? '';
sc335_assert($changedKey !== '', 'No editable new tab available for live update');
$editable['navigation']['items'][$changedKey]['title'] = 'Roboter Diagnose Live';
IPS_SetScriptContent($editId, IAHSystemNavigationEditor::encode($editable));
ob_start();
$module->PreviewSystemNavigationUpdate();
$updateMsg=ob_get_clean();
sc335_assert(str_contains($updateMsg,'Update bereit'), 'Post-cutover EDIT preview failed: '.$updateMsg);
ob_start();
$module->PublishSystemNavigationUpdate();
$publishMsg=ob_get_clean();
sc335_assert(str_contains($publishMsg,'aktualisiert'), 'Post-cutover EDIT publish failed: '.$publishMsg);
$liveUpdated = require IPS_GetScriptFile(501);
sc335_assert($liveUpdated['navigation']['items'][$changedKey]['title'] === 'Roboter Diagnose Live',
    'Post-cutover navigation did not update in PROD');
sc335_assert($liveUpdated['var']['Token'] === 'PRIVATE'
    && IPS_GetScriptContent(502) === IAHSystemNavigationEditor::encode($cleanCatalog),
    'Incremental update modified non-navigation system values or RoomsCatalog');

// No accidental rollback if the catalog changed after cleanup.
IPS_SetScriptContent(502, IPS_GetScriptContent(502)."\n");
ob_start();$module->RollbackSystemNavigation();ob_end_clean();
sc335_assert(($module->ReadAttributeString('SystemNavigationMigrationReceipt')??'')!=='',
    'Rollback overwrote a changed live catalog');
IPS_SetScriptContent(502, IAHSystemNavigationEditor::encode($cleanCatalog));
ob_start();$module->RollbackSystemNavigation();$rollbackResult=ob_get_clean();
sc335_assert(str_contains($rollbackResult,'rueckgaengig'), 'Rollback failed: '.$rollbackResult);
sc335_assert(IPS_GetScriptContent(501)===$prodOld
    && IPS_GetScriptContent(502)===$catalogOld
    && IPS_GetScriptContent(503)===$catalogEditOld,
    'Rollback did not exactly restore original PHP scripts');
sc335_assert($module->ReadAttributeString('SystemNavigationMigrationReceipt')==='',
    'Rollback receipt was not cleared');

// Required patches in source (all future installations).
$source=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
sc335_assert(str_contains($source,'IAH_NAVIGATION_CONFIG_V335')
    && str_contains($source,'IAH_NAVIGATION_OVERLAY_V335_BEGIN')
    && str_contains($source,'IAH_NAVIGATION_OVERLAY_V335_END'),
    'Alexa action template missing navigation integration');

echo "V3.3.5 PROD activation, runtime parity, protected cleanup and full rollback OK\n";
