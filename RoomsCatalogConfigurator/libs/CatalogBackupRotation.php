<?php
declare(strict_types=1);

/**
 * A maximum of five RoomsCatalog script backups across PROD and EDIT.
 * Only positively identified backup *scripts* under the configured
 * source-script folders may be removed. Active sources are never candidates.
 */
final class RCCBackupRotation
{
    public const KEEP=5;

    public static function isCatalogBackup(string $name): bool
    {
        return preg_match('/^(?:\\d{4}-\\d{2}-\\d{2}_\\d{2}-\\d{2}-\\d{2}\\s+)?RoomsCatalog(?:Edit)?[_ ]/i',$name)===1
            && (stripos($name,'Backup')!==false || stripos($name,'before_repair')!==false);
    }

    public static function sortDate(string $name,int $id): string
    {
        if (preg_match('/(20\\d{2})-(\\d{2})-(\\d{2})_(\\d{2})-(\\d{2})-(\\d{2})/',$name,$m))
            return implode('',array_slice($m,1,6)).sprintf('%010d',$id);
        if (preg_match('/(20\\d{6})_(\\d{6})/',$name,$m))
            return $m[1].$m[2].sprintf('%010d',$id);
        // Legacy undated scripts: use creation order (smaller IDs first).
        return '00000000000000'.sprintf('%010d',$id);
    }

    /** @return array<int,string> newest first */
    public static function candidates(array $sourceIds): array
    {
        if (!function_exists('IPS_GetChildrenIDs')||!function_exists('IPS_GetParent')
            ||!function_exists('IPS_ScriptExists')||!function_exists('IPS_GetName'))return [];
        $active=array_fill_keys(array_map('intval',$sourceIds),true);
        $found=[];$seenParents=[];
        foreach($sourceIds as $sourceId){
            $sourceId=(int)$sourceId;
            if($sourceId<=0||!IPS_ScriptExists($sourceId))continue;
            $parent=(int)IPS_GetParent($sourceId);
            if($parent<=0||isset($seenParents[$parent]))continue;
            $seenParents[$parent]=true;
            foreach((array)IPS_GetChildrenIDs($parent) as $id){
                $id=(int)$id;
                if($id<=0||isset($active[$id])||!IPS_ScriptExists($id))continue;
                $name=(string)IPS_GetName($id);
                if(!self::isCatalogBackup($name))continue;
                $found[$id]=$name;
            }
        }
        uksort($found,static fn(int $a,int $b):int=>
            strcmp(self::sortDate($found[$b],$b),self::sortDate($found[$a],$a)));
        return $found;
    }

    public static function create(
        int $sourceId,string $original,string $kind,string $reason,array $sourceIds
    ): int {
        if($sourceId<=0 || !function_exists('IPS_ScriptExists')
            || !IPS_ScriptExists($sourceId)
            || !function_exists('IPS_CreateScript')
            || !function_exists('IPS_GetScriptContent')
            || !function_exists('IPS_SetScriptContent')
            || !function_exists('IPS_GetParent')
            || !function_exists('IPS_SetParent')
            || !function_exists('IPS_SetName')
            || IPS_GetScriptContent($sourceId)!==$original)
            throw new \RuntimeException('Backup blockiert: Quelle geaendert oder Symcon-Funktion fehlt.');
        $id=(int)IPS_CreateScript(0);
        if($id<=0||in_array($id,$sourceIds,true))
            throw new \RuntimeException('Backup konnte nicht erstellt werden.');
        $safeReason=preg_replace('/[^A-Za-z0-9_-]/','_',substr($reason,0,56))?:'Sicherung';
        IPS_SetParent($id,(int)IPS_GetParent($sourceId));
        $base=$kind==='EDIT'?'RoomsCatalogEdit_Backup_':'RoomsCatalog_Backup_';
        IPS_SetName($id,date('Y-m-d_H-i-s').' '.$base.$safeReason.'_#'.$id);
        IPS_SetScriptContent($id,$original);
        if(IPS_GetScriptContent($id)!==$original)
            throw new \RuntimeException('Backup #'.$id.' nicht verifiziert; Quelldaten unveraendert.');
        self::prune($sourceIds);
        return $id;
    }

    public static function prune(array $sourceIds): void
    {
        if(!function_exists('IPS_DeleteScript'))return;
        $backups=self::candidates($sourceIds);
        foreach(array_slice($backups,self::KEEP,null,true) as $id=>$name){
            // Recheck protection immediately before deletion.
            if(in_array((int)$id,array_map('intval',$sourceIds),true)
                || !IPS_ScriptExists((int)$id)
                || !self::isCatalogBackup((string)IPS_GetName((int)$id)))continue;
            try {
                if(!IPS_DeleteScript((int)$id,true) && function_exists('IPS_LogMessage'))
                    IPS_LogMessage('RoomsCatalog Backup','Altes Backup #'.$id.' konnte nicht entfernt werden.');
            } catch(\Throwable $error) {
                if(function_exists('IPS_LogMessage'))
                    IPS_LogMessage('RoomsCatalog Backup','Aufraeumen von #'.$id.': '.$error->getMessage());
            }
        }
    }
}
