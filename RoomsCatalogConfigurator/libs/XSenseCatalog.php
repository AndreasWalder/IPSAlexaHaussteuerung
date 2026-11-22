<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/IPSAlexaHaussteuerung/resources/helpers/XSense.php';

final class RCCXSenseCatalog
{
    public static function rows(array $catalog): array
    {
        $s=IAHXSense::source($catalog);$rows=[];
        foreach((array)($s['devices']??[]) as $key=>$d){
            $vars=(array)$d['variables'];$enabled=!empty($s['kiEnabled'])&&!empty($d['enabled']);
            $valid=count(array_filter($vars,static fn($m):bool=>($m['parent']??0)===$d['id']&&IAHHouseholdDevices::matches($m)));
            $rows[]=['selected'=>false,'isDataSource'=>true,'sourceKey'=>IAHXSense::KEY,'roomKey'=>$d['roomKey'],
                'roomLabel'=>$d['roomLabel'],'floor'=>$d['floor'],'domain'=>'devices','group'=>$d['group'],
                'key'=>'xsense_'.$key,'title'=>$d['name'],'synonyms'=>implode(', ',(array)$d['aliases']),
                'kiEnabled'=>$enabled,'kiReadOnly'=>true,'kiStatus'=>$enabled?'Nur lesen':'Ohne KI',
                'ipsObjectIds'=>implode(', ',array_column($vars,'id')),'ipsInstanceIds'=>(string)$d['id'],
                'checkStatus'=>$valid===count($vars)&&IAHXSense::instance($d['id'])&&$d['kind']!=='unknown'?'X-Sense':'Zuordnung prüfen',
                'checkDetails'=>$valid.'/'.count($vars).' gültige Variablen · '.$d['kind'].' · nur lesen',
                'metricCount'=>count($vars),'rowColor'=>$enabled?'#E2F2FF':'#EEEEEE'];
        }
        return $rows;
    }

    public static function pendingRows(array $edit,array $prod): array
    {
        $s=IAHXSense::source($edit);$p=IAHXSense::source($prod);$next=$s['devices']??[];$old=$p['devices']??[];$rows=[];
        foreach(array_unique(array_merge(array_keys($next),array_keys($old))) as $key){
            if(($next[$key]??null)===($old[$key]??null)&&($s['kiEnabled']??false)===($p['kiEnabled']??false))continue;
            $source=$s??$p;$source['devices']=[$key=>$next[$key]??$old[$key]];
            $row=self::rows(['dataSources'=>[IAHXSense::KEY=>$source]])[0];
            $row['changeKind']=!isset($old[$key])?'Neu':(!isset($next[$key])?'Entfernt':'Geändert');
            $row['changeDetails']='X-Sense: Geräte, Zuordnungen, Namen und Lesefreigaben';$row['editable']=isset($next[$key]);$rows[]=$row;
        }
        return $rows;
    }
}
