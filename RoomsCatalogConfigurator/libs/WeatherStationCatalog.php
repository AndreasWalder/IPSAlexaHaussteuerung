<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/IPSAlexaHaussteuerung/resources/helpers/WeatherStation.php';

final class RCCWeatherStationCatalog
{
    public static function rows(array $catalog): array
    {
        $rows=[]; $s=IAHWeatherStation::source($catalog);
        foreach ((array)($s['devices']??[]) as $key=>$d) {
            $vars=(array)$d['variables']; $read=!empty($s['kiEnabled'])&&!empty($d['enabled']);
            $valid=count(array_filter($vars,static fn($m):bool=>IAHWeatherStation::station((int)$m['parent'])&&IAHHouseholdDevices::matches($m)));
            $rows[]=['selected'=>false,'isDataSource'=>true,'sourceKey'=>IAHWeatherStation::KEY,
                'roomKey'=>$d['roomKey'],'roomLabel'=>$d['roomLabel'],'floor'=>$d['floor'],'domain'=>'wetterstation',
                'group'=>$d['group'],'key'=>'weather_'.$key,'title'=>$d['name'],'synonyms'=>implode(', ',(array)$d['aliases']),
                'kiEnabled'=>$read,'kiReadOnly'=>true,'kiStatus'=>$read?'Nur lesen':'Ohne KI',
                'ipsObjectIds'=>implode(', ',array_column($vars,'id')),'ipsInstanceIds'=>(string)$d['id'],
                'checkStatus'=>$valid===count($vars)&&IAHWeatherStation::station((int)$d['id'])?'Wetterstation':'Zuordnung prüfen',
                'checkDetails'=>$valid.'/'.count($vars).' gültige Messwerte · Froggit/Ecowitt',
                'metricCount'=>count($vars),'rowColor'=>$read?'#E2F2FF':'#EEEEEE'];
        }
        return $rows;
    }

    public static function pendingRows(array $edit,array $prod): array
    {
        $next=IAHWeatherStation::source($edit)['devices']??[]; $old=IAHWeatherStation::source($prod)['devices']??[]; $rows=[];
        foreach (array_unique(array_merge(array_keys($next),array_keys($old))) as $key) {
            if (($next[$key]??null)===($old[$key]??null)) continue;
            $s=['provider'=>IAHWeatherStation::PROVIDER,'schemaVersion'=>1,'kiEnabled'=>true,'devices'=>[$key=>$next[$key]??$old[$key]]];
            $row=self::rows(['dataSources'=>[IAHWeatherStation::KEY=>$s]])[0];
            $row['changeKind']=!isset($old[$key])?'Neu':(!isset($next[$key])?'Entfernt':'Geändert');
            $row['changeDetails']='Wetterstation: Messwerte, Namen und Lesefreigaben'; $row['editable']=isset($next[$key]); $rows[]=$row;
        }
        return $rows;
    }
}
