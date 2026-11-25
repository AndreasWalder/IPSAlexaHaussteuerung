<?php
declare(strict_types=1);
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/CatalogBackupRotation.php';

$scriptContent=[101=>'<?php return ["prod"=>true];',102=>'<?php return ["edit"=>true];'];
$parents=[101=>301,102=>301];$names=[101=>'RoomsCatalog',102=>'RoomsCatalogEdit'];
$nextId=200;$deleted=[];
function IPS_ScriptExists(int $id): bool{return isset($GLOBALS['scriptContent'][$id]);}
function IPS_GetScriptContent(int $id): string{return $GLOBALS['scriptContent'][$id];}
function IPS_SetScriptContent(int $id,string $content): bool{
    $GLOBALS['scriptContent'][$id]=$content;return true;
}
function IPS_CreateScript(int $type): int{
    $id=++$GLOBALS['nextId'];$GLOBALS['scriptContent'][$id]='';
    return $id;
}
function IPS_SetParent(int $id,int $parent): bool{
    $GLOBALS['parents'][$id]=$parent;return true;
}
function IPS_SetName(int $id,string $name): bool{
    $GLOBALS['names'][$id]=$name;return true;
}
function IPS_GetParent(int $id): int{return $GLOBALS['parents'][$id]??0;}
function IPS_GetName(int $id): string{return $GLOBALS['names'][$id]??'';}
function IPS_GetChildrenIDs(int $parent): array{
    return array_keys(array_filter($GLOBALS['parents'],fn($p)=>$p===$parent));
}
function IPS_DeleteScript(int $id,bool $deleteFile): bool{
    $GLOBALS['deleted'][]=$id;
    unset($GLOBALS['scriptContent'][$id],$GLOBALS['parents'][$id],$GLOBALS['names'][$id]);
    return $deleteFile;
}
function checkBackup(bool $ok,string $message): void{
    if(!$ok)throw new RuntimeException($message);
}
// A non-backup script in the same folder is never touched.
$scriptContent[120]='<?php return "user content";';$parents[120]=301;$names[120]='RoomsCatalogCustomScript';
for($i=0;$i<9;$i++){
    $sourceId=$i%2===0?101:102;
    $source=$scriptContent[$sourceId];
    $id=RCCBackupRotation::create($sourceId,$source,$sourceId===101?'PROD':'EDIT',
        'Run'.$i,[101,102]);
    checkBackup(IPS_GetScriptContent($id)===$source,'Backup source must be identical');
    checkBackup(preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2} RoomsCatalog_/',$names[$id])===1,
        'Time should appear first in every backup name');
    checkBackup(count(RCCBackupRotation::candidates([101,102]))<=5,
        'Combined PROD and EDIT backups must never exceed five');
}
checkBackup(count(RCCBackupRotation::candidates([101,102]))===5,
    'Keep exactly five newest backup scripts');
checkBackup(count($deleted)===4,'The four oldest backups must be recycled');
checkBackup(isset($scriptContent[101],$scriptContent[102],$scriptContent[120]),
    'Active PROD, EDIT and unrelated scripts must never be deleted');
echo "PASS five-slot timestamp-first RoomsCatalog backup ring\n";
