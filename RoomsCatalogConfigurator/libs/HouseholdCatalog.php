<?php
declare(strict_types=1);
if (!class_exists('IAHHouseholdDevices',false)) require_once dirname(__DIR__,2).'/IPSAlexaHaussteuerung/resources/helpers/HouseholdDevices.php';

final class RCCHouseholdCatalog
{
    public static function merge(array $catalog,array $discovery): array
    {
        if (isset($catalog['dataSources'][IAHHouseholdDevices::KEY])&&IAHHouseholdDevices::source($catalog)===null) throw new RuntimeException('Der Katalogschlüssel für Hausgeräte ist bereits anders belegt.');
        $source=IAHHouseholdDevices::source($catalog)??['provider'=>IAHHouseholdDevices::PROVIDER,'schemaVersion'=>1,'domain'=>'hausgeraete','kiEnabled'=>true,'devices'=>[]];
        foreach ((array)($discovery['devices']??[]) as $d) {
            $key='hc_'.$d['id']; $old=$source['devices'][$key]??null;
            if ($old!==null&&($old['provider']??'')!=='HomeConnect') continue;
            $entry=$old??($d+['provider'=>'HomeConnect','roomKey'=>'__household__','roomLabel'=>'Haus','floor'=>'—','group'=>'hausgeraete','variables'=>[]]);
            foreach (IAHHomeConnect::variables((int)$d['id']) as $ident=>$v) {
                if (isset($entry['variables'][$ident])) continue;
                $metric=IAHHouseholdDevices::metric($v['id'],$v['name']);
                $metric['writable']=!empty($d['allowControl'])&&!empty($v['action']);
                $metric['role']=match($ident){'OperationState'=>'status','PowerState'=>'power','CurrentCavityTemperature'=>'temperature','ProgramProgress'=>'progress','RemainingProgramTime'=>'remaining','SelectedProgram','ActiveProgram'=>'program','Event'=>'event',default=>'other'};
                $entry['variables'][$ident]=$metric;
            }
            $source['devices'][$key]=$entry;
        }
        if ($source['devices']!==[]||IAHHouseholdDevices::source($catalog)!==null) $catalog['dataSources'][IAHHouseholdDevices::KEY]=$source;
        return $catalog;
    }

    public static function rows(array $catalog): array
    {
        $rows=[]; $source=IAHHouseholdDevices::source($catalog);
        foreach ((array)($source['devices']??[]) as $key=>$d) {
            $vars=(array)($d['variables']??[]); $read=!empty($source['kiEnabled'])&&!empty($d['enabled']);
            $valid=count(array_filter($vars,[IAHHouseholdDevices::class,'matches']));
            $writable=!empty($d['allowControl'])&&array_filter($vars,static fn($m):bool=>!empty($m['enabled'])&&!empty($m['writable']));
            $rows[]=['selected'=>false,'isDataSource'=>true,'sourceKey'=>IAHHouseholdDevices::KEY,
                'roomKey'=>$d['roomKey'],'roomLabel'=>$d['roomLabel'],'floor'=>$d['floor'],'domain'=>'hausgeraete',
                'group'=>$d['group']??'hausgeraete','key'=>'household_'.$key,'title'=>$d['name'],'synonyms'=>implode(', ',(array)$d['aliases']),
                'kiEnabled'=>$read,'kiReadOnly'=>!$writable,'kiStatus'=>$read?($writable?'Lesen + Schalten':'Nur lesen'):'Ohne KI',
                'ipsObjectIds'=>implode(', ',array_column($vars,'id')),
                'ipsInstanceIds'=>function_exists('IPS_InstanceExists')&&IPS_InstanceExists((int)$d['id'])?(string)$d['id']:'',
                'checkStatus'=>$valid===count($vars)?'Hausgerät':'Zuordnung prüfen',
                'checkDetails'=>$valid.'/'.count($vars).' gültige Variablen · '.($d['provider']??'Allgemein'),
                'metricCount'=>count($vars),'rowColor'=>$read?'#E2F2FF':'#EEEEEE'];
        }
        return $rows;
    }

    public static function pendingRows(array $edit,array $prod): array
    {
        $next=IAHHouseholdDevices::source($edit)['devices']??[];
        $old=IAHHouseholdDevices::source($prod)['devices']??[];
        $rows=[];
        foreach (array_unique(array_merge(array_keys($next),array_keys($old))) as $key) {
            if (($next[$key]??null)===($old[$key]??null)) continue;
            $device=$next[$key]??$old[$key];
            $catalog=['dataSources'=>[IAHHouseholdDevices::KEY=>['provider'=>IAHHouseholdDevices::PROVIDER,'schemaVersion'=>1,'kiEnabled'=>true,'devices'=>[$key=>$device]]]];
            $row=self::rows($catalog)[0];
            $row['changeKind']=!isset($old[$key])?'Neu':(!isset($next[$key])?'Entfernt':'Geändert');
            $row['changeDetails']='Hausgerät: Variablen, Verknüpfungen und Freigaben';
            $row['editable']=isset($next[$key]);
            $rows[]=$row;
        }
        return $rows;
    }
}
