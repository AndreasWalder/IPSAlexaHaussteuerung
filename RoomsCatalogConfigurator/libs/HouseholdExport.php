<?php
declare(strict_types=1);

require_once __DIR__.'/HomeConnectExport.php';

/** Read-only device inventory for any provider, without configuration or script contents. */
final class RCCHouseholdExport
{
    public static function scan(int $root): array
    {
        if ($root<=0 || !IPS_ObjectExists($root)
            || !in_array((int)IPS_GetObject($root)['ObjectType'],[0,1,2],true))
            throw new RuntimeException('Bitte eine Geräteinstanz, Kategorie oder Variable auswählen.');
        $device=self::object($root)+['variables'=>[],'objects'=>[]];
        $result=['schemaVersion'=>1,'provider'=>'ObjectTree','domain'=>'hausgeraete',
            'exportedAt'=>date('c'),'root'=>self::object($root),'complete'=>true,'notes'=>[],
            'devices'=>[],'deviceCount'=>1,'variableCount'=>0];
        $queue=[[$root,0]]; $seen=[];
        while ($queue!==[]) {
            [$id,$depth]=array_pop($queue);
            if (isset($seen[$id])) continue;
            if (count($seen)>=500 || $depth>8) {
                self::incomplete($result,'Scan-Limit erreicht. Bitte eine einzelne Geräteinstanz auswählen.');
                break;
            }
            $seen[$id]=true;
            try {
                $object=self::object($id);
                $type=$object['objectType'];
                if (!in_array($type,[0,1,2],true)) continue;
                $device['objects'][]=$object;
                if ($type===2) $device['variables'][]=RCCHomeConnectExport::variable($id);
                $children=IPS_GetChildrenIDs($id);
                if ($depth===8 && $children!==[]) {
                    self::incomplete($result,'Maximale Tiefe bei Objekt #'.$id.' erreicht.');
                    continue;
                }
                if (count($queue)+count($children)>1000) {
                    self::incomplete($result,'Zu viele Unterobjekte. Bitte eine kleinere Geräteauswahl verwenden.');
                    $children=array_slice($children,0,max(0,1000-count($queue)));
                }
                foreach ($children as $child) $queue[]=[(int)$child,$depth+1];
            } catch (Throwable $ignored) {
                self::incomplete($result,'Objekt #'.$id.' konnte nicht vollständig gelesen werden.');
            }
        }
        foreach (['variables','objects'] as $key)
            usort($device[$key],static fn(array $a,array $b):int=>$a['id']<=>$b['id']);
        $result['devices'][]=$device;
        $result['variableCount']=count($device['variables']);
        if ($result['variableCount']===0) $result['notes'][]='Keine Variablen unter dieser Auswahl gefunden.';
        $result['notes'][]='Momentaufnahme einschließlich unbekannter Werte. Einzelne Geräte separat exportieren; ihre Kategorien müssen nicht zusammenliegen. Es wurden keine Gerätebefehle ausgeführt und keine Alexa-Freigaben verändert.';
        return $result;
    }

    private static function object(int $id): array
    {
        $object=IPS_GetObject($id);
        $row=['id'=>$id,'name'=>self::text((string)$object['ObjectName']),
            'parentId'=>(int)$object['ParentID'],'ident'=>self::text((string)($object['ObjectIdent']??'')),
            'objectType'=>(int)$object['ObjectType']];
        if ($row['objectType']===1) {
            $instance=IPS_GetInstance($id);
            $row['moduleId']=(string)($instance['ModuleInfo']['ModuleID']??'');
            $row['moduleName']=self::text((string)($instance['ModuleInfo']['ModuleName']??''));
            $row['instanceStatus']=(int)($instance['InstanceStatus']??0);
        }
        return $row;
    }

    private static function text(string $value): string
    {
        return mb_substr(trim(strip_tags($value)),0,200);
    }

    private static function incomplete(array &$result,string $message): void
    {
        $result['complete']=false;
        if (count($result['notes'])<20 && !in_array($message,$result['notes'],true)) $result['notes'][]=$message;
    }
}
