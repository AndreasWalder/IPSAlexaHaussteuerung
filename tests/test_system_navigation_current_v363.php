<?php
declare(strict_types=1);

/** V3.6.3: permanent SystemConfiguration navigation EDIT->PROD, without migration receipts. */
require __DIR__.'/test_system_configuration_v333.php';

function navCurrentCheck(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException('Nav V3.6.3: '.$message);
}
function IPS_ObjectExists(int $id):bool {
    return in_array($id,[53544,44390,38806,58379,57001,57002],true);
}
$entry1=[
    'tabKey'=>'licht','roomKey'=>'wohnzimmer','domainKey'=>'licht',
    'title'=>'Beleuchtung','objectId'=>53544,'sortOrder'=>100,
    'aliases'=>['Lichter'],'enabled'=>true,
    'legacyPath'=>'','legacyDefinition'=>null,
];
$entry2=[
    'tabKey'=>'status','roomKey'=>'wohnzimmer','domainKey'=>'licht',
    'title'=>'Lichtstatus','objectId'=>57001,'sortOrder'=>200,
    'aliases'=>[],'enabled'=>true,'legacyPath'=>'','legacyDefinition'=>null,
];
$prod=[
    'var'=>['Token'=>'PRIVATE','Flag'=>true],
    'navigation'=>['schemaVersion'=>1,'mode'=>'active','activatedAt'=>'2026-11-01T00:00:00Z',
        'items'=>['nav1'=>$entry1]]
];
$edit=$prod;
$edit['navigation']['items']['nav2']=$entry2;
IPS_SetScriptContent(501,IAHSystemNavigationEditor::encode($prod));
IPS_SetScriptContent($editId,IAHSystemNavigationEditor::encode($edit));
$prodSource=IPS_GetScriptContent(501);
$roomsSource=IPS_GetScriptContent(502);
$backupSource=IPS_GetScriptContent($backupId);

$module->WriteAttributeString('SystemNavigationMigrationReceipt',''); // deliberately no migration receipt
$form=json_decode($module->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$ui=json_encode($form,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
navCurrentCheck(str_contains($ui,'Navigation & Tabs')
    && str_contains($ui,'IAH_PreviewSystemNavigationUpdate')
    && str_contains($ui,'IAH_PublishSystemNavigationUpdate'),
    'the permanent Navigation & Tabs editor and publish controls must remain');
foreach(['IAH_PreviewSystemNavigationCutover','IAH_CleanupSystemNavigation',
    'IAH_RollbackSystemNavigation','IAH_PreviewRoomsCatalogAutoMigration',
    'IAH_ApplySystemNavigationImport','IAH_ScanSystemConfiguration'] as $retired)
    navCurrentCheck(!str_contains($ui,$retired),'retired control still visible: '.$retired);

ob_start();$module->PublishSystemNavigationUpdate();$blocked=ob_get_clean();
navCurrentCheck(str_contains($blocked,'Bitte eine neue Navigation-Vorschau')
    && IPS_GetScriptContent(501)===$prodSource
    && IPS_GetScriptContent($backupId)===$backupSource,
    'publishing without preview must not create backups or modify PROD');

ob_start();$module->PreviewSystemNavigationUpdate();$preview=ob_get_clean();
navCurrentCheck(str_contains($preview,'VORSCHAU OK'),
    'current preview must work without a migration receipt: '.$preview);
navCurrentCheck(IPS_GetScriptContent(501)===$prodSource
    && IPS_GetScriptContent(502)===$roomsSource,
    'preview must not write to PROD or RoomsCatalog');

// Stale EDIT invalidates a previously approved preview.
$editSource=IPS_GetScriptContent($editId);
IPS_SetScriptContent($editId,$editSource."\n");
ob_start();$module->PublishSystemNavigationUpdate();$stale=ob_get_clean();
navCurrentCheck(str_contains($stale,'seit Vorschau geaendert')
    && IPS_GetScriptContent(501)===$prodSource,
    'stale edited configuration must be rejected before publishing');
IPS_SetScriptContent($editId,$editSource);

ob_start();$module->PreviewSystemNavigationUpdate();$goodPreview=ob_get_clean();
navCurrentCheck(str_contains($goodPreview,'VORSCHAU OK'),'fresh preview must pass');
ob_start();$module->PublishSystemNavigationUpdate();$published=ob_get_clean();
navCurrentCheck(str_contains($published,'nach PROD uebernommen'),
    'previewed navigation must be publishable without old receipt: '.$published);
$actual=require IPS_GetScriptFile(501);
navCurrentCheck(isset($actual['navigation']['items']['nav2'])
    && $actual['navigation']['items']['nav2']['title']==='Lichtstatus'
    && $actual['var']['Token']==='PRIVATE'
    && $actual['var']['Flag']===true
    && IPS_GetScriptContent(502)===$roomsSource,
    'navigation-only publish must preserve other PROD values and RoomsCatalog');
$snapshots=(require IPS_GetScriptFile($backupId))['snapshots'];
$last=end($snapshots);
navCurrentCheck(count($snapshots)>=2
    && $last['sourceScriptId']===501
    && $last['sha256']===hash('sha256',$prodSource)
    && $last['phpSource']===$prodSource,
    'verified SystemConfiguration PROD source must be backed up');

ob_start();$module->PublishSystemNavigationUpdate();$second=ob_get_clean();
navCurrentCheck(str_contains($second,'Bitte eine neue Navigation-Vorschau'),
    'one-time preview must not be reused after publishing');
navCurrentCheck(IPS_GetScriptContent(501)===IAHSystemNavigationEditor::encode($actual),
    'completed publish remains unchanged');

echo "PASS V3.6.3: permanent navigation UI, guarded preview, backup, stale EDIT, no legacy receipt or Roomscatalog changes\n";
