<?php
declare(strict_types=1);

/**
 * V3.5.3: Read-only Archive Control integration for the KI RoomsCatalog.
 * The configured archive is the only new setup input. Never activates
 * logging, writes variables, or exposes unrestricted IPS object lookup.
 *
 * This file is embedded in the generated KIHouseController script.
 */

/** Conservative Archive Control type check, including the actual IPS module GUID. */
function iah_archive_valid(int $archiveId): bool
{
    if ($archiveId <= 0 || !function_exists('IPS_InstanceExists')
        || !IPS_InstanceExists($archiveId) || !function_exists('AC_GetAggregationVariables'))
        return false;
    if (function_exists('IPS_GetInstance')) {
        try {
            $instance=IPS_GetInstance($archiveId);
            $moduleId=strtoupper((string)($instance['ModuleInfo']['ModuleID']??''));
            if ($moduleId!=='' && $moduleId!=='{43192F0B-135B-4CE7-A0A7-1475603F3060}')
                return false;
        } catch (Throwable $ignored) { return false; }
    }
    return true;
}

/** Inventory of archived variables, not just variables with currently active logging. */
function iah_archive_inventory(int $archiveId): array
{
    if (!iah_archive_valid($archiveId)) return [];
    try {
        $rows=AC_GetAggregationVariables($archiveId,false);
        if (!is_array($rows)) return [];
        $result=[];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id=(int)($row['VariableID']??0);
            if ($id>0) $result[$id]=$row;
        }
        return $result;
    } catch (Throwable $ignored) { return []; }
}

/** Identify a single archived child of a known source node, never a global fuzzy match. */
function iah_archive_discover_source(int $sourceId,string $property,array $inventory): int
{
    if ($sourceId<=0 || !function_exists('IPS_ObjectExists')
        || !function_exists('IPS_GetChildrenIDs') || !IPS_ObjectExists($sourceId))
        return 0;
    // Source may itself be a variable rather than an instance.
    if (isset($inventory[$sourceId]) && function_exists('IPS_VariableExists')
        && IPS_VariableExists($sourceId)) return $sourceId;
    $queue=[[$sourceId,0]];
    $visited=[];$choices=[];
    while ($queue!==[] && count($visited)<35 && count($choices)<30) {
        [$parent,$depth]=array_shift($queue);
        if (isset($visited[$parent]))continue;
        $visited[$parent]=true;
        foreach ((array)IPS_GetChildrenIDs($parent) as $child) {
            $child=(int)$child;
            if ($child<=0 || !IPS_ObjectExists($child)) continue;
            $obj=IPS_GetObject($child);
            $objectType=(int)($obj['ObjectType']??-1);
            if (($objectType===0 || $objectType===1) && $depth<2) {
                $queue[]=[$child,$depth+1];continue;
            }
            if ($objectType!==2 || !isset($inventory[$child])
                || !function_exists('IPS_VariableExists') || !IPS_VariableExists($child)
                || !function_exists('IPS_GetVariable'))continue;
            $variable=IPS_GetVariable($child);
            $kind=(int)($variable['VariableType']??-1);
            if ($property==='motion' && $kind!==0) continue;
            $profile=(string)(($variable['VariableCustomProfile']??'')
                ?:($variable['VariableProfile']??''));
            $label=mb_strtolower((string)($obj['ObjectName']??'').' '
                .(string)($obj['ObjectIdent']??'').' '.$profile,'UTF-8');
            // Explicit motion/presence profile or name has priority over generic Value.
            $motionScore=preg_match('/bewegung|motion|praesenz|präsenz|presence|pir/u',$label)?2:0;
            $choices[$child]=['id'=>$child,'score'=>$property==='motion'?$motionScore:0];
        }
    }
    if ($choices===[]) return 0;
    usort($choices,static fn(array $a,array $b):int=>
        $b['score']<=>$a['score'] ?: $a['id']<=>$b['id']);
    $first=$choices[0];$second=$choices[1]['score']??0;
    if (count($choices)===1 || ($first['score']>=2 && $second<2))return (int)$first['id'];
    return 0; // Multiple plausible signals: do not guess.
}

function iah_archive_period(string $period): array
{
    $now=time();
    $start=match($period){
        'today'=>(int)strtotime('today 00:00'),
        'yesterday'=>(int)strtotime('yesterday 00:00'),
        '7d'=>$now-7*86400,
        '30d'=>$now-30*86400,
        '90d'=>$now-90*86400,
        default=>0
    };
    $end=$period==='yesterday' ? ((int)strtotime('today 00:00')-1) : $now;
    return [$start,$end];
}

function iah_archive_safe_value(mixed $value): mixed
{
    if (is_bool($value)||is_int($value)||is_float($value)||$value===null)return $value;
    if (!is_string($value))return '[nicht darstellbar]';
    if (mb_strlen($value,'UTF-8')>60||preg_match('~[/:\\\\@{}]|https?~iu',$value))
        return '[Text ausgeblendet]';
    return $value;
}

function iah_archive_matches_active(mixed $value,array $dev,string $property): bool
{
    if ($property!=='motion' && !in_array($property,['power','open','online','alarm','waterLeak','armed'],true))
        return false;
    if (is_bool($value))return $value;
    $expected=$dev['stateMapping']['on']??null;
    return $expected!==null
        && get_debug_type($expected)===get_debug_type($value)
        && $expected===$value;
}

/** Find the latest logged ON feedback across all eligible lights, not just the first 30. */
function iah_archive_latest_light_on(array $devices,int $archiveId,float $deadline): array
{
    $summary=['ok'=>true,'operation'=>'last_light_on','mostRecent'=>null,
        'matchedDevices'=>0,'archivedDevices'=>0,'queriedVariables'=>0,'partial'=>false];
    if (!iah_archive_valid($archiveId))
        return array_replace($summary,['ok'=>false,'error'=>'archive_not_configured']);
    $inventory=iah_archive_inventory($archiveId);
    $candidates=[];
    foreach ($devices as $device) {
        if (($device['domain']??'')!=='licht'
            || !in_array($device['type']??'',['light','dimmer','switch','switches','dimmers'],true)) continue;
        $summary['matchedDevices']++;
        $reads=(array)($device['reads']??[]);
        if (empty($device['typed']) && ($device['group']??'')==='switches')
            $reads['power']=(int)($device['read']??0);
        foreach (['power','status'] as $role) {
            $id=(int)($reads[$role]??0);
            if ($id<=0 || !isset($inventory[$id])) continue;
            // A dimming value is not itself an ON/OFF transition.
            if ($id===($reads['brightness']??0)) {
                $type=(int)(IPS_GetVariable($id)['VariableType']??-1);
                if ($type!==0) continue;
            }
            $summary['archivedDevices']++;
            $candidates[$id]??=['device'=>$device,'upper'=>(int)($inventory[$id]['LastTime']??0)];
            break;
        }
    }
    // Unknown upper bounds first. Once the next archived value is older
    // than the winner, no later candidate can overtake it.
    uasort($candidates,static fn(array $a,array $b):int=>
        ($b['upper']?:PHP_INT_MAX)<=>($a['upper']?:PHP_INT_MAX));
    foreach ($candidates as $id=>$candidate) {
        $winner=(int)($summary['mostRecent']['timestamp']??0);
        if ($winner>0 && $candidate['upper']>0 && $candidate['upper']<$winner) break;
        if (microtime(true)>=$deadline) {$summary['partial']=true;break;}
        $device=$candidate['device'];
        $summary['queriedVariables']++;
        try {
            $end=time();
            for ($page=0;$page<4;$page++) {
                if (microtime(true)>=$deadline) {$summary['partial']=true;break;}
                $values=AC_GetLoggedValues($archiveId,$id,$winner,$end,100);
                if (!is_array($values)) {$summary['partial']=true;break;}
                if ($values===[]) break;
                foreach ($values as $row) {
                    $at=(int)($row['TimeStamp']??0);
                    if ($at<=0 || $at<$winner) continue;
                    $value=$row['Value']??null;
                    $mapping=(array)($device['stateMapping']??[]);
                    if (array_key_exists('on',$mapping)) {
                        $expected=$mapping['on'];
                        $on=$expected===$value || (is_numeric($expected) && is_numeric($value)
                            && (float)$expected===(float)$value);
                    } else {
                        $profile=iah_ai_profile_label($id,$value);
                        $on=($profile!==null?iah_ai_binary_feedback($profile)
                            :iah_ai_binary_feedback($value,true))==='an';
                    }
                    if (!$on) continue;
                    if ($at>$winner || $summary['mostRecent']===null) {
                        $summary['mostRecent']=['name'=>$device['name'],'room'=>$device['room'],
                            'timestamp'=>$at,'at'=>date('c',$at)];
                    }
                    continue 3;
                }
                if (count($values)<100) break;
                $last=end($values);
                $earliest=(int)($last['TimeStamp']??0);
                if ($earliest<=max(1,$winner) || $earliest>=$end) break;
                if ($page===3) $summary['partial']=true;
                $end=$earliest-1;
            }
        } catch (Throwable $error) {$summary['partial']=true;}
    }
    return $summary;
}

/**
 * Uses only devices already authorized by the productive RoomsCatalog.
 * A motion device with an empty binding can resolve a uniquely archived
 * Boolean child beneath its own discoverySourceId without modifying EDIT/PROD.
 */
function iah_archive_query(array $devices,array $args,string $spoken,int $archiveId): array
{
    if (!iah_archive_valid($archiveId))
        return ['ok'=>false,'error'=>'archive_not_configured',
            'message'=>'Archive Control in den Alexa-Modul-Einstellungen auswählen.'];
    $inventory=iah_archive_inventory($archiveId);
    if ($inventory===[])
        return ['ok'=>false,'error'=>'archive_empty',
            'message'=>'Im gewählten Archiv sind keine aufgezeichneten Variablen erkennbar.'];
    $op=(string)($args['operation']??'last_active');
    if (!in_array($op,['last_active','last_change','count_active','history','stats'],true))
        return ['ok'=>false,'error'=>'invalid_operation'];
    $property=(string)($args['property']??'');
    if ($property!=='' && $property!=='activity'
        && !array_key_exists($property,RCCDeviceTypeRegistry::fields()))
        return ['ok'=>false,'error'=>'invalid_property'];
    $period=(string)($args['period']??'');
    if (!in_array($period,['all','today','yesterday','7d','30d','90d'],true))
        $period=$op==='count_active'?'today':'all';
    [$start,$end]=iah_archive_period($period);
    $filters=[];
    foreach (['deviceKey','name','room','floor','domain','group','type'] as $field)
        if (isset($args[$field]) && is_scalar($args[$field]))
            $filters[$field]=(string)$args[$field];
    if ($property==='motion' || preg_match('/bewegung|bewegungsmelder|motion|praesenz|präsenz/ui',$spoken)) {
        // Never apply a motion event query to lights or blinds.
        $filters['type']='motion';
        if ($property==='')$property='motion';
    }
    $found=iah_rc_find($devices,$filters);
    if ($found===[] && $filters!==[])
        return ['ok'=>true,'matchedDevices'=>0,'foundHistory'=>0,
            'message'=>'Kein entsprechend für KI-Lesezugriff freigegebenes Gerät im RoomsCatalog gefunden.'];
    if (count($found)>30 && empty($filters['name']) && empty($filters['deviceKey'])
        && empty($filters['room']) && empty($filters['type']))
        return ['ok'=>false,'error'=>'too_many_devices','matches'=>count($found),
            'message'=>'Bitte Raum oder Gerät eingrenzen.'];
    $maxDevices=30;
    $maxResult=max(1,min(10,(int)($args['limit']??5)));
    $rows=[];$missing=[];$searched=0;$partial=false;$mostRecent=null;
    $started=microtime(true);
    foreach (array_slice($found,0,$maxDevices) as $dev) {
        if (microtime(true)-$started>2.0) {
            $partial=true;break;
        }
        $role=$property==='activity'?'motion':$property;
        if ($role==='') {
            if (($dev['type']??'')==='motion')$role='motion';
            elseif (count((array)($dev['reads']??[]))===1)
                $role=(string)array_key_first($dev['reads']);
        }
        if ($role==='' || in_array($role,['action','control','commandValue'],true)) {
            $missing[]=['name'=>$dev['name'],'reason'=>'property_ambiguous_or_command'];
            continue;
        }
        $variableId=(int)($dev['reads'][$role]??0);
        if ($variableId<=0 && $role==='motion')
            $variableId=iah_archive_discover_source(
                (int)($dev['archiveSourceId']??0),$role,$inventory);
        if ($variableId<=0 && $role==='status' && isset($dev['reads']['motion'])
            && ($dev['type']??'')==='motion')
            $variableId=(int)$dev['reads']['motion'];
        if ($variableId<=0 && $role==='state' && (int)($dev['read']??0)>0)
            $variableId=(int)$dev['read'];
        if ($variableId<=0 || !isset($inventory[$variableId])) {
            $missing[]=['name'=>$dev['name'],'reason'=>'no_archived_feedback',
                'sourceId'=>(int)($dev['archiveSourceId']??0)];
            continue;
        }
        $searched++;
        $entry=['deviceKey'=>$dev['id'],'name'=>$dev['name'],'room'=>$dev['room'],
            'property'=>$role,'firstArchivedAt'=>isset($inventory[$variableId]['FirstTime'])
                ? date('c',(int)$inventory[$variableId]['FirstTime']):null,
            'lastArchivedAt'=>isset($inventory[$variableId]['LastTime'])
                ? date('c',(int)$inventory[$variableId]['LastTime']):null,
            'loggingActive'=>(bool)($inventory[$variableId]['AggregationActive']??false)];
        try {
            if ($op==='stats') {
                if (!function_exists('AC_GetAggregatedValues')) {
                    $missing[]=['name'=>$dev['name'],'reason'=>'aggregates_unavailable'];continue;
                }
                $agg=AC_GetAggregatedValues($archiveId,$variableId,1,
                    $start,$end,31);
                if (!is_array($agg) || $agg===[]) {
                    $missing[]=['name'=>$dev['name'],'reason'=>'no_statistics'];continue;
                }
                $entry['dailyAggregates']=array_slice(array_map(
                    static fn(array $v):array=>['date'=>date('Y-m-d',(int)($v['TimeStamp']??0)),
                        'avg'=>$v['Avg']??null,'min'=>$v['Min']??null,'max'=>$v['Max']??null],
                    array_filter($agg,'is_array')),0,$maxResult);
                $entry['note']='Voraggregierte Tageswerte; keine Einzelereignisse.';
            } else {
                $events=[];$endPage=$end;$scanLimit=$op==='history'?$maxResult:
                    ($op==='count_active'?10000:250);
                $pages=$op==='last_active'?4:1;
                $sawMatch=false;$rowsScanned=0;
                for ($page=0;$page<$pages;$page++) {
                    $values=AC_GetLoggedValues($archiveId,$variableId,$start,$endPage,$scanLimit);
                    if (!is_array($values) || $values===[])break;
                    $rowsScanned+=count($values);
                    foreach ($values as $v) {
                        if (!is_array($v))continue;
                        $at=(int)($v['TimeStamp']??0);
                        if ($at<=0)continue;
                        $raw=$v['Value']??null;
                        $active=iah_archive_matches_active($raw,$dev,$role);
                        if (in_array($op,['last_active','count_active'],true) && !$active)
                            continue;
                        $sawMatch=true;
                        $events[]=['at'=>date('c',$at),'timestamp'=>$at,
                            'value'=>iah_archive_safe_value($raw)];
                        if ($op==='last_change'||$op==='last_active'||$op==='history'
                            && count($events)>=$maxResult)break;
                    }
                    if ($op!=='last_active' || $sawMatch || count($values)<$scanLimit)break;
                    $last=end($values);
                    $earliest=(int)($last['TimeStamp']??0);
                    if ($earliest<=max(1,$start)||$earliest>=$endPage)break;
                    $endPage=$earliest-1;
                }
                if (($op==='count_active' && $rowsScanned>=10000)
                    || ($op==='last_active' && !$sawMatch && $rowsScanned>=1000)) {
                    $entry['incomplete']=true;$partial=true;
                }
                if ($op==='count_active') {
                    $entry['count']=$rowsScanned>=10000?null:count($events);
                    $entry['countLowerBound']=count($events);
                } else {
                    $entry['events']=array_slice($events,0,$maxResult);
                    if ($events!==[] && ($mostRecent===null
                        || $events[0]['timestamp']>$mostRecent['timestamp']))
                        $mostRecent=['name'=>$dev['name'],'room'=>$dev['room'],
                            'timestamp'=>$events[0]['timestamp'],'at'=>$events[0]['at']];
                }
            }
            $rows[]=$entry;
        } catch(Throwable $err) {
            $missing[]=['name'=>$dev['name'],'reason'=>'archive_read_failed'];
        }
    }
    // No IPS IDs or raw token-like strings are transmitted to OpenAI.
    return ['ok'=>true,'archiveConfigured'=>true,'operation'=>$op,
        'property'=>$property,'period'=>$period,
        'matchedDevices'=>count($found),'queriedVariables'=>$searched,
        'shown'=>count($rows),'foundHistory'=>count(array_filter($rows,
            static fn(array $r):bool=>!empty($r['events']) || !empty($r['dailyAggregates'])
                || array_key_exists('count',$r))),
        'mostRecent'=>$mostRecent,
        'partial'=>$partial || count($found)>$maxDevices,
        'devices'=>array_slice($rows,0,$maxDevices),
        'missing'=>array_slice($missing,0,15),
        'note'=>'Archiv protokolliert WERTAENDERUNGEN, nicht jede Bewegungsaktualisierung. '
            .'Bewegung erkannt nur aus explizitem aktivem Ereignis, nicht aus letzter Aenderung.'];
}
