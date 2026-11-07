<?php
declare(strict_types=1);

/**
 * V3.6.1 – Virtual table rows and read-only diagnostics for module data sources.
 *
 * A source is deliberately NOT expanded into normal RoomsCatalog device rows:
 * the existing mass-editor and SaveToEdit would otherwise treat metric IDs as
 * independent smart-home actuators. Only dedicated callbacks can edit a
 * dataSources entry, and all changes are routed through the EDIT script.
 */
final class RCCDataSourceEditor
{
    public static function topics(): array
    {
        return [
            'pv'=>'PV-Anlage',
            'battery'=>'Akku',
            'grid'=>'Netz & Smartmeter',
            'house'=>'Verbrauch & Bilanz',
            'inverter'=>'Wechselrichter',
            'device'=>'Geräteinformationen',
            'diag'=>'Diagnose',
            'settings'=>'Akku-Einstellungen (nur lesen)',
            'force'=>'Zwangsladen (nur lesen)',
            'powerctl'=>'Einspeisebegrenzung (nur lesen)'
        ];
    }

    public static function sourceRows(array $catalog): array
    {
        $rows=[];
        foreach ((array)($catalog['dataSources']??[]) as $key=>$source) {
            if (!is_string($key) || !is_array($source)
                || ($source['provider']??'')!=='HuaweiPV')continue;
            $metrics=(array)($source['metrics']??[]);
            $enabled=count(array_filter($metrics,
                static fn($m):bool=>is_array($m) && !empty($m['enabled'])));
            $all=count($metrics);
            $readAllowed=!empty($source['kiEnabled']);
            $id=(int)($source['sourceInstanceId']??0);
            $valid=$id>0 && function_exists('IPS_InstanceExists')
                && IPS_InstanceExists($id);
            $rows[]=[
                'selected'=>false,
                'isDataSource'=>true,
                'roomKey'=>'__energy_source__',
                'roomLabel'=>'Haus',
                'floor'=>'—',
                'domain'=>'energie',
                'group'=>'solar',
                'key'=>$key,
                'title'=>(string)($source['title']??'HuaweiPV'),
                'kiEnabled'=>$readAllowed,
                'kiReadOnly'=>true,
                'kiStatus'=>$readAllowed?'Nur lesen':'Ohne KI',
                'ipsObjectIds'=>(string)$id,
                'ipsInstanceIds'=>(string)$id,
                'checkStatus'=>$valid?'Datenquelle':'Quelle fehlt',
                'checkDetails'=>$enabled.'/'.$all.' Messwerte fuer KI freigegeben'
                    .($valid?'':' – IPS-Instanz nicht vorhanden'),
                'rowColor'=>!$valid?'#FFCCCC':($readAllowed?'#E2F2FF':'#EEEEEE'),
                'sourceKey'=>$key,
                'sourceInstanceId'=>$id,
                'metricCount'=>$all
            ];
        }
        return $rows;
    }

    public static function belongsToSource(int $variableId,int $sourceId,string $ident): bool
    {
        if ($variableId<=0 || $sourceId<=0 || $ident===''
            || !function_exists('IPS_VariableExists')
            || !IPS_VariableExists($variableId)
            || !function_exists('IPS_GetObject')
            || !function_exists('IPS_ObjectExists'))return false;
        try {
            $object=IPS_GetObject($variableId);
            if (!is_array($object) || (int)($object['ObjectType']??-1)!==2
                || (string)($object['ObjectIdent']??'')!==$ident)return false;
            $parent=(int)($object['ObjectParentID']??$object['ParentID']??0);
            for ($depth=0;$depth<8 && $parent>0;$depth++) {
                if ($parent===$sourceId)return true;
                if(!IPS_ObjectExists($parent))return false;
                $p=IPS_GetObject($parent);
                if(!is_array($p))return false;
                $parent=(int)($p['ObjectParentID']??$p['ParentID']??0);
            }
        } catch(Throwable $ignored){}
        return false;
    }

    public static function archiveInventory(int $archiveId): ?array
    {
        if ($archiveId<=0 || !function_exists('IPS_InstanceExists')
            || !IPS_InstanceExists($archiveId) || !function_exists('AC_GetAggregationVariables'))return null;
        if (function_exists('IPS_GetInstance')) {
            try {
                $instance=IPS_GetInstance($archiveId);
                if(strtoupper((string)($instance['ModuleInfo']['ModuleID']??''))
                    !=='{43192F0B-135B-4CE7-A0A7-1475603F3060}')return null;
            } catch(Throwable $ignored){return null;}
        }
        try {
            $variables=AC_GetAggregationVariables($archiveId,false);
            if(!is_array($variables))return null;
            $index=[];
            foreach($variables as $var) {
                if(!is_array($var))continue;
                $id=(int)($var['VariableID']??0);
                if($id>0)$index[$id]=$var;
            }
            return $index;
        } catch(Throwable $ignored){return null;}
    }

    public static function metricRows(array $source,string $topic,?array $inventory): array
    {
        $rows=[];
        $sourceId=(int)($source['sourceInstanceId']??0);
        foreach ((array)($source['metrics']??[]) as $ident=>$metric) {
            if(!is_string($ident)||!is_array($metric)||
                (string)($metric['topic']??'')!==$topic)continue;
            $id=(int)($metric['id']??0);
            $valid=self::belongsToSource($id,$sourceId,$ident);
            $value='–';$updated='–';
            if($valid) {
                try {
                    $v=GetValue($id);
                    if (is_bool($v))$value=$v?'Ja':'Nein';
                    elseif(is_int($v)||is_float($v))$value=(string)round((float)$v,4);
                    elseif(is_string($v) && $ident!=='SerialNumber'
                        && mb_strlen($v,'UTF-8')<=70
                        && !preg_match('~[<>@{}\\\\]|https?://|(?:\d{1,3}\.){3}\d{1,3}~iu',$v))
                        $value=$v;
                    else $value='[Wert ausgeblendet]';
                    if(function_exists('IPS_GetVariable')) {
                        $vinfo=IPS_GetVariable($id);
                        if((int)($vinfo['VariableUpdated']??0)>0)
                            $updated=date('d.m.Y H:i:s',(int)$vinfo['VariableUpdated']);
                    }
                } catch(Throwable $ignored){$value='Lesefehler';}
            }
            $archive=$inventory===null?'Archiv nicht konfiguriert'
                :(isset($inventory[$id])?'Archiviert':'Nicht archiviert');
            $rows[]=[
                'ident'=>$ident,
                'title'=>(string)($metric['label']??$ident),
                'id'=>$id,
                'unit'=>(string)($metric['unit']??''),
                'enabled'=>!empty($metric['enabled']),
                'value'=>$value,
                'updated'=>$updated,
                'archive'=>$archive,
                'status'=>$valid?'OK':'Ungueltige Objekt-ID',
                'rowColor'=>$valid?'': '#FFCCCC'
            ];
        }
        return $rows;
    }

    public static function totals(array $source,int $archiveId): array
    {
        $inventory=self::archiveInventory($archiveId);
        $enabled=0;$invalid=0;$archived=0;$total=0;
        foreach ((array)($source['metrics']??[]) as $ident=>$metric) {
            if(!is_array($metric))continue;
            $total++;
            if(!empty($metric['enabled']))$enabled++;
            $id=(int)($metric['id']??0);
            if(!self::belongsToSource($id,(int)($source['sourceInstanceId']??0),(string)$ident))
                $invalid++;
            if($inventory!==null && isset($inventory[$id]))$archived++;
        }
        return ['total'=>$total,'enabled'=>$enabled,'invalid'=>$invalid,
            'archived'=>$archived,'archiveConfigured'=>$inventory!==null];
    }
}
