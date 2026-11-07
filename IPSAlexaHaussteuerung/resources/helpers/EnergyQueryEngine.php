<?php
declare(strict_types=1);

/**
 * RoomsCatalog V3.6 – general source-indexed, read-only metric query engine.
 * Current provider HuaweiPV; future providers may supply the same
 * dataSources.<key>.metrics schema without adding one RoomsCatalog device
 * per metric. All IPS IDs come from the published, allowlisted catalog.
 */

function iah_energy_catalog(int $scriptId): array
{
    if($scriptId<=0 || !function_exists('IPS_ScriptExists') || !IPS_ScriptExists($scriptId))
        return [];
    $path=IPS_GetScriptFile($scriptId);
    if(!is_file($path))$path=rtrim(IPS_GetKernelDir(),'\\/').'/scripts/'.$path;
    if(!is_file($path))return [];
    $catalog=require $path;
    return is_array($catalog)?$catalog:[];
}

/** Verify each IPS id is really a descendant of the configured source node. */
function iah_energy_belongs_to_source(int $variableId,int $sourceId,string $ident): bool
{
    if ($variableId<=0 || $sourceId<=0 || $ident===''
        || !function_exists('IPS_VariableExists') || !IPS_VariableExists($variableId)
        || !function_exists('IPS_GetObject') || !function_exists('IPS_ObjectExists'))
        return false;
    try {
        $obj=IPS_GetObject($variableId);
        if((int)($obj['ObjectType']??-1)!==2
            || (string)($obj['ObjectIdent']??'')!==$ident) return false;
        $parent=(int)($obj['ObjectParentID']??$obj['ParentID']??0);
        for($depth=0;$depth<7 && $parent>0;$depth++) {
            if($parent===$sourceId)return true;
            if(!IPS_ObjectExists($parent))break;
            $parentObj=IPS_GetObject($parent);
            $parent=(int)($parentObj['ObjectParentID']??$parentObj['ParentID']??0);
        }
    } catch(Throwable $ignored) {}
    return false;
}

function iah_energy_sources(int $catalogScriptId): array
{
    $catalog=iah_energy_catalog($catalogScriptId);
    $rows=(array)($catalog['dataSources']??[]);
    $sources=[];
    foreach ($rows as $key=>$source) {
        if (!is_string($key) || !is_array($source)
            || ($source['provider']??'')!=='HuaweiPV'
            || empty($source['kiEnabled']) || empty($source['kiReadOnly'])===false
            || !empty($source['allowWrites']) || empty($source['readOnly'])) continue;
        $id=(int)($source['sourceInstanceId']??0);
        if($id<=0 || !function_exists('IPS_InstanceExists')
            || !IPS_InstanceExists($id))continue;
        if(function_exists('IPS_GetInstance')) {
            try {
                $info=IPS_GetInstance($id);
                if(strtoupper((string)($info['ModuleInfo']['ModuleID']??''))
                    !=='{7C3D7EE3-1DE2-4C16-9BC4-AC1F091695E4}')continue;
            } catch(Throwable $ignored){continue;}
        }
        $metrics=[];
        foreach ((array)($source['metrics']??[]) as $ident=>$m) {
            if(!is_string($ident) || !preg_match('/^[A-Za-z][A-Za-z0-9]{1,60}$/D',$ident)
                || !is_array($m) || empty($m['enabled']) || empty($m['readOnly']))continue;
            $vid=(int)($m['id']??0);
            if(!iah_energy_belongs_to_source($vid,$id,$ident)) continue;
            $metrics[$ident]=[
                'id'=>$vid,'ident'=>$ident,
                'label'=>mb_substr((string)($m['label']??$ident),0,100,'UTF-8'),
                'unit'=>mb_substr((string)($m['unit']??''),0,12,'UTF-8'),
                'topic'=>(string)($m['topic']??''),
                'kind'=>(string)($m['kind']??'gauge'),
                'valueType'=>(int)($m['valueType']??-1)
            ];
        }
        if($metrics===[])continue;
        $sources[$key]=[
            'name'=>(string)($source['title']??'HuaweiPV'),
            'provider'=>'HuaweiPV','sourceInstanceId'=>$id,
            'signConventions'=>(array)($source['signConventions']??[]),
            'metrics'=>$metrics
        ];
    }
    return $sources;
}

function iah_energy_schema(array $sources): array
{
    $schema=[];
    foreach($sources as $key=>$source) {
        $metricSchema=[];
        foreach($source['metrics'] as $ident=>$metric)
            $metricSchema[]=['ident'=>$ident,'name'=>$metric['label'],
                'unit'=>$metric['unit'],'category'=>$metric['topic'],
                'kind'=>$metric['kind']];
        $schema[]=['key'=>$key,'source'=>$source['name'],
            'metrics'=>$metricSchema];
    }
    return $schema;
}

function iah_energy_safe_value(mixed $raw): mixed
{
    if(is_bool($raw)||is_int($raw)||is_float($raw)||$raw===null)return $raw;
    if(!is_string($raw))return null;
    // No serials, secrets, URLs, log blocks or arbitrary HTML via LLM.
    if(mb_strlen($raw,'UTF-8')>75 || preg_match('~[<>@{}\\\\]|https?://|(?:\d{1,3}\.){3}\d{1,3}~iu',$raw))
        return '[ausgeblendet]';
    return $raw;
}

function iah_energy_current(array $metric,array $source): array
{
    $id=(int)$metric['id'];
    $variable=IPS_GetVariable($id);
    $raw=iah_energy_safe_value(GetValue($id));
    if($raw===null)
        return ['ok'=>false,'reason'=>'value_unavailable'];
    $entry=[
        'metric'=>$metric['ident'],'name'=>$metric['label'],
        'topic'=>$metric['topic'],'unit'=>$metric['unit'],
        'value'=>$raw,
        'updatedAt'=>(int)($variable['VariableUpdated']??0)>0
            ?date('c',(int)$variable['VariableUpdated']):null
    ];
    if(function_exists('GetValueFormatted')) {
        try {
            $formatted=iah_energy_safe_value(GetValueFormatted($id));
            if(is_string($formatted)&&$formatted!=='[ausgeblendet]')
                $entry['formatted']=$formatted;
        } catch(Throwable $ignored) {}
    }
    $sign=(array)($source['signConventions']??[]);
    if(is_numeric($raw) && !is_bool($raw)) {
        if($metric['ident']==='GridPower') {
            $mode=(int)($sign['gridSign']??0);
            $entry['direction']=abs((float)$raw)<0.0001?'ausgeglichen':
                (((float)$raw>0)===($mode===0) ? 'Einspeisung' : 'Netzbezug');
        }
        if($metric['ident']==='BatteryPower') {
            $mode=(int)($sign['batterySign']??0);
            $entry['direction']=abs((float)$raw)<0.0001?'Ruhe':
                (((float)$raw>0)===($mode===0) ? 'Laden' : 'Entladen');
        }
    }
    return $entry;
}

function iah_energy_period(string $name): array
{
    $now=time();
    return match($name) {
        'today'=>[(int)strtotime('today'),$now],
        'yesterday'=>[(int)strtotime('yesterday'),(int)strtotime('today')-1],
        '7d'=>[$now-7*86400,$now],
        '30d'=>[$now-30*86400,$now],
        'this_month'=>[(int)strtotime('first day of this month 00:00'),$now],
        'last_month'=>[(int)strtotime('first day of last month 00:00'),
            (int)strtotime('first day of this month 00:00')-1],
        default=>[0,$now]
    };
}

function iah_energy_archive_available(int $archiveId): bool
{
    if($archiveId<=0 || !function_exists('IPS_InstanceExists')
        || !IPS_InstanceExists($archiveId) || !function_exists('AC_GetLoggedValues'))
        return false;
    if(function_exists('iah_archive_valid'))
        return iah_archive_valid($archiveId);
    return true;
}

function iah_energy_archive_has_variable(int $archiveId,int $variableId): bool
{
    if(!function_exists('AC_GetAggregationVariables'))return false;
    try {
        foreach((array)AC_GetAggregationVariables($archiveId,false) as $row)
            if((int)($row['VariableID']??0)===$variableId)return true;
    }catch(Throwable $ignored){}
    return false;
}

function iah_energy_archive_last(int $archiveId,int $varId,int $time): ?array
{
    try {
        $rows=AC_GetLoggedValues($archiveId,$varId,0,$time,1);
        if(!is_array($rows) || !$rows)return null;
        $entry=$rows[0];
        if(!is_array($entry)||!isset($entry['TimeStamp']))return null;
        return ['at'=>(int)$entry['TimeStamp'],'value'=>iah_energy_safe_value($entry['Value']??null)];
    }catch(Throwable $ignored){return null;}
}

/**
 * Deterministic operations; source and metric must exist in productive,
 * read-only catalog, never supplied directly as IP-Symcon IDs by the model.
 */
function iah_energy_query(array $sources,array $args,int $archiveId): array
{
    $key=(string)($args['source']??'');
    if($key==='')$key=(string)(array_key_first($sources)??'');
    if(!isset($sources[$key]))
        return ['ok'=>false,'error'=>'unknown_source',
            'availableSources'=>array_keys($sources)];
    $source=$sources[$key];
    $op=(string)($args['operation']??'current');
    $validOps=['current','overview','history','delta','stats'];
    if(!in_array($op,$validOps,true))
        return ['ok'=>false,'error'=>'unknown_operation','available'=>$validOps];
    $metricName=(string)($args['metric']??'');
    if($op==='overview') {
        $priority=['PVPower','DailyYield','HouseConsumption','GridFeedIn',
            'GridImport','GridPower','BatterySOC','BatteryPower',
            'SelfSufficiency','DeviceStatus','Online'];
        $values=[];
        foreach($priority as $ident) {
            if(!isset($source['metrics'][$ident]))continue;
            try {$values[] = iah_energy_current($source['metrics'][$ident],$source);}
            catch(Throwable $ignored){$values[]=['metric'=>$ident,'ok'=>false];}
        }
        return ['ok'=>true,'operation'=>'overview','source'=>$source['name'],
            'values'=>$values,'readOnly'=>true,
            'note'=>'0 W nachts ist nicht automatisch ein Fehler; keine Einstellung wurde geaendert.'];
    }
    if(!isset($source['metrics'][$metricName])) {
        $matching=[];
        $needle=function_exists('iah_rc_norm') ? iah_rc_norm($metricName) : strtolower($metricName);
        foreach($source['metrics'] as $ident=>$m) {
            $label=function_exists('iah_rc_norm') ? iah_rc_norm($m['label']) : strtolower($m['label']);
            if($needle!=='' && ($label===$needle || strtolower($ident)===$needle))
                $matching[]=$ident;
        }
        if(count($matching)===1)$metricName=$matching[0];
    }
    if(!isset($source['metrics'][$metricName]))
        return ['ok'=>false,'error'=>'unknown_metric',
            'requested'=>$metricName,
            'available'=>array_map(static fn(array $m):array=>[
                'ident'=>$m['ident'],'name'=>$m['label'],'unit'=>$m['unit']],
                array_values($source['metrics']))];
    $metric=$source['metrics'][$metricName];
    if($op==='current') {
        try{return ['ok'=>true,'operation'=>'current','source'=>$source['name'],
            'reading'=>iah_energy_current($metric,$source),'readOnly'=>true];}
        catch(Throwable $e){return ['ok'=>false,'error'=>'ips_value_unavailable'];}
    }
    $period=(string)($args['period']??'');
    if(!in_array($period,['today','yesterday','7d','30d','this_month','last_month'],true))
        return ['ok'=>false,'error'=>'invalid_period'];
    // Daily yield is not the proper source for monthly totals.
    if($op==='delta' && in_array($metricName,['DailyYield','GridExportToday',
        'GridImportToday','BatteryChargeToday','BatteryDischargeToday'],true)) {
        $counter=match($metricName) {
            'DailyYield'=>'TotalYield','GridExportToday'=>'GridExportTotal',
            'GridImportToday'=>'GridImportTotal',
            'BatteryChargeToday'=>'BatteryChargeTotal',
            'BatteryDischargeToday'=>'BatteryDischargeTotal'
        };
        if(isset($source['metrics'][$counter])) {
            $metricName=$counter;$metric=$source['metrics'][$counter];
        }
    }
    if(!iah_energy_archive_available($archiveId))
        return ['ok'=>false,'error'=>'archive_not_configured',
            'message'=>'Archive Control fuer historische Energiewerte konfigurieren.'];
    $varId=(int)$metric['id'];
    if(!iah_energy_archive_has_variable($archiveId,$varId))
        return ['ok'=>false,'error'=>'not_archived',
            'metric'=>$metricName,'message'=>'Variable ist nicht in Archive Control aufgezeichnet.'];
    [$start,$end]=iah_energy_period($period);
    if($op==='delta') {
        if($metric['kind']!=='counter')
            return ['ok'=>false,'error'=>'not_a_cumulative_counter',
                'message'=>'Eine periodenuebergreifende Energiesumme braucht einen monotonen Gesamtzaehler.'];
        $from=iah_energy_archive_last($archiveId,$varId,$start);
        $to=iah_energy_archive_last($archiveId,$varId,$end);
        if($from===null || $to===null || !is_numeric($from['value'])
            || !is_numeric($to['value']) || $from['at']>$start || $to['at']<$start)
            return ['ok'=>false,'error'=>'insufficient_archive_samples',
                'message'=>'Kein verlaesslicher Zaehlerstand vor Periodenbeginn und -ende vorhanden.'];
        $diff=(float)$to['value']-(float)$from['value'];
        if($diff<0)
            return ['ok'=>false,'error'=>'counter_reset',
                'message'=>'Gesamtzaehler ist gefallen; eine Differenz waere ungueltig.'];
        return ['ok'=>true,'operation'=>'delta','source'=>$source['name'],
            'metric'=>$metricName,'period'=>$period,'unit'=>$metric['unit'],
            'value'=>round($diff,3),
            'from'=>date('c',$from['at']),'to'=>date('c',$to['at']),
            'samplingNote'=>'Differenz zweier archivierter Zaehlerstaende, keine minutengenaue Messung.'];
    }
    $limit=max(1,min(12,(int)($args['limit']??6)));
    if($op==='history') {
        try {
            $records=AC_GetLoggedValues($archiveId,$varId,$start,$end,$limit);
            $values=[];
            foreach((array)$records as $v) {
                if(!is_array($v) || (int)($v['TimeStamp']??0)<=0)continue;
                $values[]=['at'=>date('c',(int)$v['TimeStamp']),
                    'value'=>iah_energy_safe_value($v['Value']??null)];
            }
            return ['ok'=>true,'operation'=>'history','metric'=>$metricName,
                'period'=>$period,'unit'=>$metric['unit'],'samples'=>$values,
                'note'=>'Nur die letzten archivierten Stichproben, keine vollstaendige Zeitreihe.'];
        } catch(Throwable $ignored) {return ['ok'=>false,'error'=>'archive_read_failed'];}
    }
    if($op==='stats') {
        if(!function_exists('AC_GetAggregatedValues') || $metric['kind']==='text')
            return ['ok'=>false,'error'=>'aggregates_unavailable'];
        try {
            $rows=AC_GetAggregatedValues($archiveId,$varId,1,$start,$end,31);
            $days=[];
            foreach(array_slice((array)$rows,0,31) as $v) {
                if(!is_array($v))continue;
                $days[]=['date'=>date('Y-m-d',(int)($v['TimeStamp']??0)),
                    'average'=>$v['Avg']??null,'minimum'=>$v['Min']??null,'maximum'=>$v['Max']??null];
            }
            return ['ok'=>true,'operation'=>'stats','metric'=>$metricName,
                'period'=>$period,'unit'=>$metric['unit'],
                'days'=>array_slice($days,0,$limit),
                'note'=>'Tagesmittelwert/Minimum/Maximum, kein Tagesenergieertrag.'];
        }catch(Throwable $ignored){return ['ok'=>false,'error'=>'archive_read_failed'];}
    }
    return ['ok'=>false,'error'=>'unsupported_operation'];
}
