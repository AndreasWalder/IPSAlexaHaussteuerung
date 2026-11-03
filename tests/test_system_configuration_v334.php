<?php
declare(strict_types=1);

/** V3.3.4: nutzt dieselbe simulierte IP-Symcon-Installation wie V3.3.3. */
require __DIR__ . '/test_system_configuration_v333.php';

function sc334_check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$prodBefore = IPS_GetScriptContent(501);
$roomsBefore = IPS_GetScriptContent(502);
$backupBefore = IPS_GetScriptContent($backupId);
IPS_SetScriptContent($editId, $source);
$module->WriteAttributeString('SystemNavigationImportPreview', '');
$module->WriteAttributeString('SystemNavigationEditorNotice', '');

$editorClass = IAHSystemNavigationEditor::class;
sc334_check($editorClass::isLiteralArraySource($source), 'Existing simple PHP config should be editable');
sc334_check(!$editorClass::isLiteralArraySource('<?php $foo = 1; return ["x"=>$foo];'),
    'Dynamic PHP source must be protected from reserialization');
sc334_check(!$editorClass::isLiteralArraySource('<?php return ["x"=>system("echo abc")];'),
    'Side-effectful function calls must be rejected');

$form=json_decode($module->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$ui=json_encode($form,JSON_THROW_ON_ERROR);
sc334_check(str_contains($ui,'IAH_PreviewSystemNavigationImport')
    && str_contains($ui,'IAH_ApplySystemNavigationImport')
    && str_contains($ui,'IAH_SaveSystemNavigationTab')
    && str_contains($ui,'IAH_CreateSystemNavigationTab')
    && str_contains($ui,'SystemNavigationEditRows'),
    'New system navigation editor actions are missing');
sc334_check(!str_contains($ui,'PRIVATE') && !str_contains($ui,'SENSITIVE'),
    'No values from SystemConfiguration may be rendered in the form');

ob_start();
$module->PreviewSystemNavigationImport();
$previewText=ob_get_clean();
$preview=json_decode($module->ReadAttributeString('SystemNavigationImportPreview'),true,512,JSON_THROW_ON_ERROR);
sc334_check($preview['count']===4 && $preview['editId']===$editId,
    'Preview did not find four tabs');
sc334_check(str_contains($previewText,'4 Navigationseintraege'),
    'Preview message does not show number of tabs');
sc334_check(IPS_GetScriptContent($editId)===$source
    && IPS_GetScriptContent(501)===$prodBefore
    && IPS_GetScriptContent(502)===$roomsBefore, 'Preview unexpectedly wrote scripts');

// Stale inventory: changing RoomsCatalog must abort and NOT alter EDIT.
IPS_SetScriptContent(502, '<?php return '.var_export(['global'=>['domains'=>['robot'=>['tabs'=>['x'=>33]]]]],true).';');
ob_start();
$module->ApplySystemNavigationImport();
ob_end_clean();
sc334_check(IPS_GetScriptContent($editId)===$source
    && IPS_GetScriptContent(501)===$prodBefore, 'Stale preview changed a script');
IPS_SetScriptContent(502,$roomsBefore);

// Current preview may now be obtained and explicitly applied.
ob_start();
$module->PreviewSystemNavigationImport();
$module->ApplySystemNavigationImport();
ob_end_clean();
$edited=require IPS_GetScriptFile($editId);
$items=IAHSystemNavigationEditor::items($edited);
sc334_check(count($items)===4,'Not all Tabs have been imported to EDIT');
sc334_check(($edited['var']['Token']??null)==='PRIVATE',
    'Unrelated PROD settings were modified during import');
$legacyDock=array_values(array_filter($items,static fn(array $entry): bool =>
    $entry['tabKey']==='Dock'))[0];
sc334_check($legacyDock['legacyDefinition']['specialLegacyKey']['must']==='survive'
    && $legacyDock['legacyPath']==='global/domains/robot/tabs/Dock',
    'Original tab definition and source path were not preserved');
sc334_check(IPS_GetScriptContent(501)===$prodBefore
    && IPS_GetScriptContent(502)===$roomsBefore,
    'Live SystemConfiguration or RoomsCatalog changed after import');
sc334_check(!str_contains(json_encode(IAHSystemNavigationEditor::rows($edited)),'PRIVATE'),
    'Credentials leaked in the editable navigation table');

// Editing one entry affects only that entry, keeps its legacy raw definition.
$rows=IAHSystemNavigationEditor::rows($edited);
$dockRow=array_values(array_filter($rows,static fn(array $row): bool =>
    $row['tabKey']==='Dock'))[0];
$changed=$dockRow;
$changed['title']='Roboter andocken';
$changed['aliases']='Andocken, Ladestation';
$changed['sortOrder']=9;
$changed['objectId']='44390';
$changed['enabled']=false;
ob_start();
$module->SaveSystemNavigationTab(json_encode($changed,JSON_THROW_ON_ERROR));
ob_end_clean();
$after=require IPS_GetScriptFile($editId);
$items2=IAHSystemNavigationEditor::items($after);
sc334_check($items2[$dockRow['entryKey']]['title']==='Roboter andocken'
    && $items2[$dockRow['entryKey']]['enabled']===false
    && $items2[$dockRow['entryKey']]['aliases']===['Andocken','Ladestation'],
    'Edit fields were not saved');
sc334_check($items2[$dockRow['entryKey']]['legacyDefinition']===$legacyDock['legacyDefinition'],
    'Individual original tab fields were overwritten');
sc334_check(count($items2)===4, 'Editing a tab added or removed another tab');

// Stale row revision must not be accepted.
$changed['title']='Unerlaubter veralteter Edit';
ob_start();
$module->SaveSystemNavigationTab(json_encode($changed,JSON_THROW_ON_ERROR));
ob_end_clean();
$afterStale=require IPS_GetScriptFile($editId);
sc334_check(IAHSystemNavigationEditor::items($afterStale)[$dockRow['entryKey']]['title']==='Roboter andocken',
    'Stale form row overwrote latest data');

// Invalid IDs must fail without overwriting any value.
$currentRow=array_values(array_filter(IAHSystemNavigationEditor::rows($afterStale),
    static fn(array $row): bool => $row['tabKey']==='Dock'))[0];
$invalid=$currentRow;
$invalid['objectId']='1;DROP TABLE';
$editHash=hash('sha256',IPS_GetScriptContent($editId));
ob_start();
$module->SaveSystemNavigationTab(json_encode($invalid,JSON_THROW_ON_ERROR));
ob_end_clean();
sc334_check(hash('sha256',IPS_GetScriptContent($editId))===$editHash,
    'Invalid ID unexpectedly changed EDIT');

// Add a new entry with a known room and auto-generated key.
ob_start();
$module->CreateSystemNavigationTab(json_encode([
    'roomKey'=>'global','domainKey'=>'robot','title'=>'Roboter Diagnose',
    'objectId'=>57001,'sortOrder'=>120,'aliases'=>'Fehler, Status','enabled'=>true
],JSON_THROW_ON_ERROR));
ob_end_clean();
$extended=require IPS_GetScriptFile($editId);
$extendedItems=IAHSystemNavigationEditor::items($extended);
sc334_check(count($extendedItems)===5, 'New navigation tab not created in EDIT');
$newItems=array_values(array_filter($extendedItems,static fn(array $entry): bool =>
    $entry['title']==='Roboter Diagnose'));
sc334_check(count($newItems)===1 && str_starts_with($newItems[0]['entryKey'],'new_'),
    'Auto ID for new tab missing');
$editBeforeUnknownRoom=IPS_GetScriptContent($editId);
ob_start();
$module->CreateSystemNavigationTab(json_encode([
    'roomKey'=>'nonexistent','domainKey'=>'robot','title'=>'Wrong','objectId'=>1,
],JSON_THROW_ON_ERROR));
ob_end_clean();
sc334_check(IPS_GetScriptContent($editId)===$editBeforeUnknownRoom,
    'Unknown room was accepted');

// Never re-import over already edited navigation.
ob_start();
$module->PreviewSystemNavigationImport();
$module->ApplySystemNavigationImport();
ob_end_clean();
sc334_check(IPS_GetScriptContent($editId)===$editBeforeUnknownRoom,
    'Second import erased manually edited data');
$backups=require IPS_GetScriptFile($backupId);
sc334_check($backups['snapshots'][0]['phpSource']===$source
    && $backups['snapshots'][0]['sha256']===hash('sha256',$source),
    'The initial original backup was lost');
sc334_check(count($backups['snapshots'])>=2, 'EDIT changes were not snapshotted');

$method->invoke($module,200,501);
sc334_check(IPS_GetScriptContent($editId)===$editBeforeUnknownRoom,
    'Module update overwrote the user navigation draft');
sc334_check(IPS_GetScriptContent(501)===$prodBefore
    && IPS_GetScriptContent(502)===$roomsBefore,
    'PROD and source RoomsCatalog were modified');
echo "V3.3.4 Navigation editor: preview, import, edits, add, stale guards, backups, no PROD writes OK\n";
