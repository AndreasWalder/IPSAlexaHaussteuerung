<?php
declare(strict_types=1);

/** Voice and archive runtime for a published object-tree irrigation system. */
final class IAHIrrigationQuery
{
    private const CONTROLLER='{DEADBEAF-0000-0000-0000-000000000001}';
    private const HEATER_METER_GROUP='energiewerte bewaesserung';
    private const HEATER_METER_TITLE='schaltschrank verteiler und heizung pumpe';
    // Reference peaks read from the irrigation power chart, in watts.
    private const HEATER_REFERENCE_WATTS=[160.0,250.0];
    private const HEATER_TOLERANCE=0.15;
    private const HEATER_IDLE_WATTS=30.0;

    private static function norm(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/',' ',str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],mb_strtolower($s)))??'');
    }

    private static function points(array $system,string $role,?int $zone=null): array
    {
        return array_filter((array)($system['points']??[]),static fn($p)=>is_array($p)
            && !empty($p['enabled']) && ($p['role']??'')===$role
            && ($zone===null || (int)($p['zone']??0)===$zone));
    }

    public static function value(array $p): mixed
    {
        $id=(int)($p['id']??0);
        if ($id<=0 || !IPS_VariableExists($id))return null;
        try {
            $obj=IPS_GetObject($id);
            if ((string)($obj['ObjectIdent']??'')!==(string)($p['ident']??'')
                || (int)($obj['ParentID']??-1)!==(int)($p['parent']??0)
                || (int)(IPS_GetVariable($id)['VariableType']??-1)!==(int)($p['type']??-2))return null;
            return GetValue($id);
        } catch (Throwable $e) {return null;}
    }

    public static function mode(array $system,DateTimeImmutable $now): array
    {
        $from=max(1,min(12,(int)($system['winterFromMonth']??11)));
        $until=max(1,min(12,(int)($system['winterUntilMonth']??3)));
        $m=(int)$now->format('n');
        $calendar=$from<=$until?($m>=$from && $m<=$until):($m>=$from || $m<=$until);
        $points=self::points($system,'winterMode');
        $actual=count($points)===1?self::value(reset($points)):null;
        $start=$now->setDate((int)$now->format('Y'),$from,1)->setTime(0,0);
        if ($start>$now)$start=$start->modify('-1 year');
        return ['winter'=>is_bool($actual)?$actual:$calendar,'source'=>is_bool($actual)?'variable':'calendar',
            'from'=>$start,'calendar'=>$calendar];
    }

    /** Evaluate strictly observed OFF -> ON. The oldest sample never counts
     * by itself. Analog deadband holds the known previous state. */
    public static function edges(array $rows,array $signal,int $start,int $end,?int $readEnd=null): array
    {
        $states=[];$previous=null;$last=null;$count=0;
        usort($rows,static fn(array $a,array $b):int=>(int)($a['TimeStamp']??0)<=>(int)($b['TimeStamp']??0));
        foreach ($rows as $r) {
            $ts=(int)($r['TimeStamp']??0);$v=$r['Value']??null;
            if ($ts<=0 || $ts>($readEnd??$end))continue;
            $state=self::sampleState($v,$signal);
            // Opposing states in the same second have no proven order.
            if (array_key_exists($ts,$states) && $states[$ts]!==$state)$states[$ts]='ambiguous';
            else $states[$ts]=$state;
        }
        if (($signal['mode']??'')==='heater_power_band')return self::heaterEdges($states,$start,$end);
        foreach ($states as $ts=>$state) {
            if ($state==='ambiguous'||$state==='unknown'){$previous=null;continue;}
            if ($state===null)continue;
            if ($ts>=$start && $previous===false && $state===true) {
                $count++;$last=$ts;
            }
            $previous=$state;
        }
        return ['count'=>$count,'last'=>$last,'samples'=>count($states),'baselineKnown'=>self::hasBaseline($states,$start),'lastState'=>$previous];
    }

    private static function heaterEdges(array $states,int $start,int $end): array
    {
        $armed=false;$overshoot=false;$entry=null;$starts=[];$baseline=false;$uncertain=false;$live=null;
        foreach ($states as $time=>$state) {
            if ($state==='unknown' || $state==='ambiguous') {
                $armed=false;$overshoot=false;$entry=null;$live=null;
                $uncertain=$uncertain || ($time>=$start && $time<=$end);
            } elseif ($state===false) {
                if ($entry!==null && !$overshoot && $entry>=$start && $entry<=$end)$starts[]=$entry;
                $armed=true;$overshoot=false;$entry=null;$live=false;
            } elseif ($state==='above') {
                $overshoot=true;$live=null;
            } elseif ($state===true && !$overshoot) {
                if ($armed && $entry===null)$entry=$time;
                $live=true;
            }
            if ($time<$start)$baseline=$armed;
        }
        return ['count'=>count($starts),'starts'=>$starts,'last'=>$starts===[]?null:end($starts),'samples'=>count($states),
            'baselineKnown'=>$baseline,'lastState'=>$live,'overshoot'=>$overshoot,'uncertain'=>$uncertain];
    }

    private static function hasBaseline(array $states,int $start): bool
    {
        $base=null;
        foreach ($states as $ts=>$state) {
            if ($ts>=$start)break;
            if ($state!==null)$base=$state;
        }
        return is_bool($base);
    }

    private static function sampleState(mixed $value,array $signal): bool|string|null
    {
        if (($signal['mode']??'')==='binary') {
            if (is_bool($value))return $value;
            if (in_array($value,[0,0.0,'0'],true))return false;
            if (in_array($value,[1,1.0,'1'],true))return true;
            return 'unknown';
        }
        if (($signal['mode']??'')==='heater_power_band') {
            if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<0)return 'unknown';
            if ((float)$value<=$signal['off'])return false;
            if ((float)$value>$signal['max'])return 'above';
            return (float)$value>=$signal['on']?true:null;
        }
        if (($signal['mode']??'')!=='threshold'||!is_numeric($value)
            || !is_finite((float)$value) || (float)$value<0
            || !is_numeric($signal['on']??null)||!is_numeric($signal['off']??null)
            || (float)$signal['off']>=(float)$signal['on'])return 'unknown';
        if ((float)$value>=(float)$signal['on'])return true;
        if ((float)$value<=(float)$signal['off'])return false;
        return null;
    }

    /** Archive pages are newest-first. Read one overlap second and abort
     * if a page cannot advance without skipping same-second samples. */
    private static function archive(int $archive,int $id,array $signal,int $start,int $end,float $deadline,bool $latest,array $inventory,?int $readEnd=null): array
    {
        $base=['count'=>0,'last'=>null,'samples'=>0,'baselineKnown'=>false,'reason'=>''];
        if (!isset($inventory[$id])||!function_exists('AC_GetLoggedValues'))
            return array_replace($base,['reason'=>'not_archived']);
        $analog=($signal['mode']??'')!=='binary';
        $size=$analog?1000:400;$maxPages=$analog?24:12;
        $rows=[];$cursor=$readEnd??$end;$complete=false;$foundLatest=false;$ambiguousBoundary=false;
        try {
            for ($page=0;$page<$maxPages && microtime(true)<$deadline && $cursor>=max(1,$start);$page++) {
                $batch=AC_GetLoggedValues($archive,$id,$start,$cursor,$size);
                if (!is_array($batch))throw new RuntimeException('read');
                $rows=array_merge($rows,$batch);
                if ($latest && count($batch)>0) {
                    $boundary=(int)($batch[array_key_last($batch)]['TimeStamp']??0);
                    // Do not conclude from a potentially split timestamp at a full-page boundary.
                    $proof=count($batch)<$size?$rows:array_filter($rows,static fn($r)=>(int)($r['TimeStamp']??0)>$boundary);
                    if (self::edges($proof,$signal,$start,$end,$readEnd)['last']!==null) {
                        $rows=$proof;$foundLatest=true;break;
                    }
                }
                if (count($batch)<$size){$complete=true;break;}
                $earliest=(int)($batch[array_key_last($batch)]['TimeStamp']??0);
                if ($earliest<=0||$earliest>=$cursor){$ambiguousBoundary=true;break;}
                $cursor=$earliest;
            }
            if ($complete && !$latest && $start>1 && microtime(true)<$deadline) {
                $before=AC_GetLoggedValues($archive,$id,0,$start-1,200);
                if (is_array($before) && $before!==[]) {
                    $rows=array_merge($rows,$before);
                }
            }
        } catch (Throwable $e) {return array_replace($base,['reason'=>'archive_error']);}
        $out=self::edges($rows,$signal,$start,$end,$readEnd);
        if ($latest && !$foundLatest && !$complete)$out['last']=null;
        $out['reason']=$foundLatest||($complete && !$ambiguousBoundary && empty($out['uncertain']))?'':'partial';
        if (!$latest && $out['reason']==='' && function_exists('AC_GetLoggingStatus')) {
            try {if (!AC_GetLoggingStatus($archive,$id))$out['reason']='logging_inactive';}
            catch (Throwable $ignored) {$out['reason']='logging_status_unknown';}
        }
        if ($out['samples']===0 && $out['reason']==='')$out['reason']='no_samples';
        return $out;
    }

    /** Read archived temperature samples in a period. A capped or
     * ambiguous page is reported as partial, never as proof of no frost. */
    private static function temperatureRange(int $archive,int $id,int $start,int $end,float $deadline,array $inventory): array
    {
        $base=['samples'=>0,'min'=>null,'max'=>null,'belowThreshold'=>0,'complete'=>false,'reason'=>''];
        if (!isset($inventory[$id])||!function_exists('AC_GetLoggedValues'))
            return array_replace($base,['reason'=>'not_archived']);
        $rows=[];$cursor=$end;$complete=false;$size=400;
        try {
            for ($page=0;$page<96 && microtime(true)<$deadline && $cursor>=$start;$page++) {
                $batch=AC_GetLoggedValues($archive,$id,$start,$cursor,$size);
                if (!is_array($batch))throw new RuntimeException('read');
                $rows=array_merge($rows,$batch);
                if (count($batch)<$size){$complete=true;break;}
                $earliest=(int)($batch[array_key_last($batch)]['TimeStamp']??0);
                if ($earliest<=0||$earliest>=$cursor)break;
                if ($earliest<=$start){$complete=true;break;}
                $cursor=$earliest;
            }
        } catch (Throwable $e) {
            return array_replace($base,['reason'=>'archive_error']);
        }
        $seen=[];$values=[];
        foreach ($rows as $row) {
            $ts=(int)($row['TimeStamp']??0);$value=$row['Value']??null;
            if ($ts<$start||$ts>$end||!is_numeric($value)||!is_finite((float)$value))continue;
            $key=$ts.':'.(string)$value;
            if (isset($seen[$key]))continue;
            $seen[$key]=true;$values[]=(float)$value;
        }
        if ($values!==[]) {
            $base['samples']=count($values);
            $base['min']=min($values);$base['max']=max($values);
            $base['belowThreshold']=count(array_filter($values,static fn(float $v):bool=>$v<0.0));
        }
        $base['complete']=$complete;
        if (!$complete)$base['reason']='partial';
        elseif (function_exists('AC_GetLoggingStatus')) {
            try {if (!AC_GetLoggingStatus($archive,$id))$base['reason']='logging_inactive';}
            catch (Throwable $ignored) {$base['reason']='logging_status_unknown';}
        }
        if ($base['samples']===0&&$base['reason']==='')$base['reason']='no_samples';
        return $base;
    }

    private static function pumpShaftTemperatures(array $system): array
    {
        $selected=['oben'=>[],'unten'=>[]];
        foreach (self::points($system,'stationTemperature') as $point) {
            $label=self::norm((string)($point['label']??''));
            if (str_contains($label,'pumpenschacht oben'))$selected['oben'][]=$point;
            elseif (str_contains($label,'pumpenschacht unten'))$selected['unten'][]=$point;
        }
        return $selected;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value,1,',',''), '0'), ',');
    }

    private static function clean(string $s): string
    {
        return mb_substr(trim(strip_tags(preg_replace('/[\x00-\x1f]+/u',' ',$s)??'')),0,110);
    }

    private static function stationAlias(string $label): string
    {
        $label=self::norm(preg_replace('/\(\d+\)/','',$label)??$label);
        return trim(preg_replace('/^(?:temperatur|temperature) | (?:temperatur|temperature)$/','',$label)??$label);
    }

    private static function entries(array $system,string $role,?int $zone=null): array
    {
        return array_column(self::measurements($system,$role,$zone),'speech');
    }

    private static function measurements(array $system,string $role,?int $zone=null): array
    {
        $rows=[];
        foreach (self::points($system,$role,$zone) as $p) {
            $v=self::value($p);
            if ((!is_int($v)&&!is_float($v)) || !is_finite((float)$v))continue;
            $label=self::clean($p['label']);$number=self::number($v);
            [$unit,$spokenUnit]=match($role){
                'stationTemperature','outdoorTemperature'=>['°C','Grad'],
                'soilMoisture','rainSensor','sensorBattery'=>['%','Prozent'],
                'pumpPressure'=>['bar','bar'],
                default=>[$p['unit']??'', $p['unit']??''],
            };
            $rows[]=['label'=>$label,'value'=>trim($number.' '.$unit),'speech'=>trim($label.' '.$number.' '.$spokenUnit)];
        }
        return $rows;
    }

    private static function state(array $system,string $role,?int $zone=null): ?bool
    {
        $points=self::points($system,$role,$zone);
        if ($role==='pumpStatus') {
            // A single physically linked feedback outranks module software status.
            $feedback=array_filter($points,static fn(array $p):bool=>empty($p['owned'])
                && in_array('link',(array)($p['evidence']??[]),true));
            if ($feedback!==[])$points=$feedback;
        } elseif ($role==='zoneStatus') {
            $controller=array_filter($points,static fn(array $p):bool=>!empty($p['owned']));
            if ($controller!==[])$points=$controller;
        }
        $states=[];
        foreach ($points as $p) {
            $value=self::value($p);
            if (!is_bool($value))return null;
            $states[(int)$value]=$value;
        }
        return count($states)===1?reset($states):null;
    }

    private static function statusPoints(array $system): array
    {
        $zones=[];
        foreach ((array)($system['points']??[]) as $p)
            if (is_array($p) && (int)($p['zone']??0)>0
                && in_array($p['role']??'',['zoneStatus','zoneManual'],true))
                $zones[(int)$p['zone']]=true;
        ksort($zones,SORT_NUMERIC);
        return array_keys($zones);
    }

    private static function zoneMatches(array $system,string $text): array
    {
        if (preg_match('/\bkreis (\d{1,2})\b/',$text,$m))return [(int)$m[1]];
        $zones=[];
        foreach ((array)($system['points']??[]) as $p) {
            $zone=(int)($p['zone']??0);
            if ($zone<=0||!in_array($p['role']??'',['zoneStatus','zoneManual','zoneInfo'],true))continue;
            foreach (array_merge([(string)($p['label']??'')],(array)($p['aliases']??[])) as $label) {
                $alias=trim(preg_replace('/\b(?:status|info|manuell|automatik|kreis \d+|ein aus)\b/','',self::norm($label))??'');
                $alias=preg_replace('/\s+/',' ',$alias)??'';
                if (strlen($alias)>3 && str_contains(' '.$text.' ',' '.$alias.' '))
                    $zones[$zone]=max(strlen($alias),$zones[$zone]??0);
            }
        }
        return $zones===[]?[]:array_keys(array_filter($zones,static fn(int $n):bool=>$n===max($zones)));
    }

    private static function heaterMeter(array $system,array $inventory): ?array
    {
        $points=(array)($system['points']??[]);
        $garden=(int)($system['sourceRootId']??0)===41578 || in_array(52811,(array)($system['controllers']??[]),true);
        $namedArchive=[];
        if ($garden) {
            $points+=[47232=>[]];
            // An older catalog can omit the source or use a link caption rather than the variable name.
            if (function_exists('IPS_GetName')) {
                foreach (array_slice(array_keys($inventory),0,2000) as $id) {
                    if (IPS_VariableExists($id) && self::norm((string)IPS_GetName($id))===self::HEATER_METER_TITLE) {
                        $points[$id]??=[];
                        $namedArchive[$id]=true;
                    }
                }
            }
        }
        $candidates=[];
        foreach ($points as $id=>$point) {
            $id=(int)$id;
            $known=$garden && $id===47232;
            $selected=!empty($point['enabled']) && ($point['role']??'')==='heaterSignal'
                && ($system['historySignals']['heater'][$id]['mode']??'')==='heaterPower';
            $catalogPath=array_map([self::class,'norm'],explode('/',(string)($point['path']??'')));
            $inCatalogGroup=in_array(self::HEATER_METER_GROUP,$catalogPath,true);
            $labels=array_merge([(string)($point['label']??'')],(array)($point['aliases']??[]));
            $named=in_array(self::HEATER_METER_TITLE,array_map([self::class,'norm'],$labels),true)
                || isset($namedArchive[$id]);
            if (isset($system['excludedPoints'][$id])
                || (!empty($point['manualEdit']) && empty($point['enabled']))
                || (!$selected && !$known && !$named && (empty($point['enabled'])
                    || (!$inCatalogGroup && ($point['role']??'')!=='heaterSignal')))
                || !IPS_VariableExists($id))continue;
            try {
                $object=IPS_GetObject($id);
                $variable=IPS_GetVariable($id);
                $parent=(int)($object['ParentID']??0);
                $type=(int)($variable['VariableType']??-1);
                $ident=(string)($object['ObjectIdent']??'');
                if (!in_array($type,[1,2],true)
                    || ($point!==[] && ($parent!==(int)($point['parent']??-1)
                        || $type!==(int)($point['type']??-1) || $ident!==($point['ident']??null))))continue;

                $name=self::norm((string)($object['ObjectName']??''));
                $named=$named || $name===self::HEATER_METER_TITLE;
                $powerName=in_array($name,['leistung','wirkleistung','rohrbegleitheizung leistung'],true);
                $unit=(string)($point['unit']??'');
                if ($unit==='' && function_exists('IPS_GetVariablePresentation')) {
                    $unit=trim((string)(IPS_GetVariablePresentation($id)['SUFFIX']??''));
                }
                $profile=(string)(($variable['VariableCustomProfile']??'')?:($variable['VariableProfile']??''));
                if ($unit==='' && $profile!=='' && function_exists('IPS_VariableProfileExists') && IPS_VariableProfileExists($profile))
                    $unit=trim((string)(IPS_GetVariableProfile($profile)['Suffix']??''));
                if ($unit==='' && ($named || ($known && $powerName)))$unit='W';
                if (!in_array($unit,['W','kW'],true))continue;

                // The named chart and the known garden power ID can refer to linked/renamed objects.
                $inGroup=$inCatalogGroup && in_array('link',(array)($point['evidence']??[]),true);
                for ($ancestor=$parent,$depth=0;!$inGroup && !$named && !$known && $ancestor>0 && $depth<8;$depth++) {
                    $container=IPS_GetObject($ancestor);
                    $inGroup=self::norm((string)($container['ObjectName']??''))===self::HEATER_METER_GROUP;
                    $ancestor=(int)($container['ParentID']??0);
                }
                if (!$selected && !$named && !$known && (!$inGroup || (!$powerName && ($point['role']??'')!=='heaterSignal')))continue;
                $reading=GetValue($id);
                $value=is_numeric($reading) && is_finite((float)$reading) && (float)$reading>=0?(float)$reading:null;
            } catch (Throwable $ignored) {
                continue;
            }
            [$referenceMin,$referenceMax]=self::HEATER_REFERENCE_WATTS;
            $scale=$unit==='kW'?1000.0:1.0;
            $signal=['mode'=>'heater_power_band','off'=>self::HEATER_IDLE_WATTS/$scale,
                'on'=>$referenceMin*(1-self::HEATER_TOLERANCE)/$scale,
                'max'=>round($referenceMax*(1+self::HEATER_TOLERANCE),2)/$scale];
            $archived=isset($inventory[$id]) && ($inventory[$id]['AggregationActive']??true);
            $priority=[$selected,$named,$archived,$known,$value!==null];
            $candidates[]=['id'=>$id,'unit'=>$unit,'value'=>$value,'priority'=>$priority,'signal'=>$signal];
        }
        usort($candidates,static fn(array $a,array $b):int=>$b['priority']<=>$a['priority']);
        if (isset($candidates[1]) && $candidates[0]['priority']===$candidates[1]['priority'])return null;
        return $candidates[0]??null;
    }

    private static function heaterLive(?array $meter,array $series=[]): array
    {
        $measurement='';
        $state=null;
        if (($meter['value']??null)!==null) {
            $value=$meter['value'];
            $unit=$meter['unit'];
            $precision=$unit==='kW'?3:1;
            $measurement=rtrim(rtrim(number_format($value,$precision,',',''),'0'),',').' '.$unit;
            $sample=self::sampleState($value,$meter['signal']);
            if ($sample===false || ($sample===true && empty($series['overshoot'])))$state=$sample;
            elseif ($sample===null && ($series['reason']??null)==='')$state=$series['lastState']??null;
        }
        $status=match($state){true=>'Ein',false=>'Aus',null=>'Unbekannt'};
        $reading=match(true){
            $meter===null=>'Energiemesswert fehlt',
            $measurement===''=>'Messwert nicht verfügbar',
            default=>$status.' · '.$measurement,
        };
        $speech=$state===null
            ? 'Der Heizstatus ist aus den Energiewerten derzeit nicht bestimmbar'
            : 'Rohrbegleitheizung '.mb_strtolower($status).' bei '.$measurement;
        return ['label'=>'Rohrbegleitheizung','value'=>$reading,'speech'=>$speech];
    }

    private static function summary(array $s,bool $winter,bool $onlyState,?int $zone,array $heat=[],array $heating=[]): array
    {
        $parts=[];$rows=[];$on=[];$missing=0;
        $pump=self::state($s,'pumpStatus');
        $zones=self::statusPoints($s);
        foreach ($zone===null?$zones:[$zone] as $i) {
            $v=self::state($s,'zoneStatus',$i);
            if ($v===true) {
                $label=$i===11?'Nebenstelle (manuelles Wasserholen / manuelles Gießen)':'Kreis '.$i;
                $remaining=self::points($s,'remaining',$i);
                if ($remaining===[] && self::state($s,'automatic')===true)
                    $remaining=self::points($s,'duration',$i);
                if (count($remaining)===1) {
                    $p=reset($remaining);$left=self::value($p);
                    if (is_numeric($left) && $left>0 && ($p['unit']??'')==='min')
                        $label.=' noch '.self::number((float)$left).' Minuten';
                }
                $on[]=$label;
            } elseif ($v===null)$missing++;
        }
        if ($zone===null) {
            $parts[]=$pump===null?'Pumpenstatus unbekannt':'Pumpe '.($pump?'ein':'aus');
            $rows[]=['label'=>'Pumpe','value'=>match($pump){true=>'Ein',false=>'Aus',null=>'Unbekannt'}];
        }
        $pressurePoints=self::points($s,'pumpPressure');$pressureActive=false;
        foreach ($pressurePoints as $pressurePoint) {
            $pressureValue=self::value($pressurePoint);
            if (is_numeric($pressureValue) && (float)$pressureValue>4) {$pressureActive=true;break;}
        }
        $irrigationZones=array_filter($zones,static fn(int $zone):bool=>$zone!==11);
        $activeIrrigationZone=false;
        foreach ($irrigationZones as $irrigationZone) {
            if (self::state($s,'zoneStatus',$irrigationZone)===true) {$activeIrrigationZone=true;break;}
        }
        if ($pressureActive && $activeIrrigationZone)$parts[]='Bewässerung läuft (Pumpendruck über 4 bar und ein Bewässerungskreis aktiv)';
        if ($on!==[]) {
            $parts[]='Aktiv: '.implode(', ',$on);
            $rows[]=['label'=>'Aktive Kreise','value'=>implode(', ',$on)];
        } elseif ($missing===0 && ($zone!==null || $zones!==[])) {
            $parts[]=$zone!==null?'Kreis '.$zone.' ist aus':'alle erfassten Kreise aus';
            $rows[]=['label'=>$zone!==null?'Kreis '.$zone:'Erfasste Kreise','value'=>$zone!==null?'Aus':'Alle aus'];
        }
        if ($missing>0) {
            $parts[]=$zone!==null?'Status von Kreis '.$zone.' unbekannt':$missing.' Kreis'.($missing===1?'':'e').' ohne verlässlichen Status';
            $rows[]=['label'=>$zone!==null?'Kreis '.$zone:'Kreise ohne Rückmeldung','value'=>$zone!==null?'Unbekannt':(string)$missing];
        }
        if ($zone===null) {
            if ($winter) {
                $parts[]=$heat['speech'];
                $rows[]=$heat;
                if (!empty($heating['displayRows'])) {
                    $parts[]=rtrim($heating['answer'],'. ');
                    $rows=array_merge($rows,$heating['displayRows']);
                }
                if (!$onlyState) {
                    $measurements=self::measurements($s,'stationTemperature');
                    $parts=array_merge($parts,array_column($measurements,'speech'));
                    $rows=array_merge($rows,$measurements);
                }
            } elseif (!$onlyState && ($pump===false || $on!==[])) {
                $soil=array_slice(self::measurements($s,'soilMoisture'),0,4);
                if ($soil!==[])$parts[]='Bodenfeuchte: '.implode(', ',array_column($soil,'speech'));
                $rows=array_merge($rows,$soil);
            }
        }
        return ['answer'=>$parts!==[]?implode('. ',$parts).'.':'Keine eindeutigen Zustände gefunden.',
            'displayRows'=>$rows];
    }

    private static function period(string $q,array $mode,DateTimeImmutable $now): array
    {
        $mid=$now->setTime(0,0);
        if (preg_match('/\bheute\b/',$q))return [$mid->getTimestamp(),$now->getTimestamp(),'heute'];
        if (preg_match('/\bgestern\b/',$q))return [$mid->modify('-1 day')->getTimestamp(),$mid->getTimestamp()-1,'gestern'];
        if (preg_match('/\bletzten (\d{1,3}|sieben|dreissig|vierzehn) tage[n]?\b/',$q,$m)) {
            $days=max(1,min(366,match($m[1]){'sieben'=>7,'dreissig'=>30,'vierzehn'=>14,default=>(int)$m[1]}));
            return [$now->modify('-'.$days.' days')->getTimestamp(),$now->getTimestamp(),'in den letzten '.$days.' Tagen'];
        }
        if (preg_match('/\b(?:diesen|im) winter\b/',$q) || $mode['winter']) {
            $until=max(1,min(12,(int)($mode['until']??3)));
            $from=$mode['from'];
            $year=(int)$from->format('Y')+((int)$from->format('n')>$until?1:0);
            $end=$from->setDate($year,$until,1)->modify('+1 month')->getTimestamp()-1;
            if (!preg_match('/\b(?:diesen|im) winter\b/',$q))$end=$now->getTimestamp();
            return [$from->getTimestamp(),min($end,$now->getTimestamp()),'seit dem '.$from->format('d.m.Y')];
        }
        return [$now->modify('-30 days')->getTimestamp(),$now->getTimestamp(),'in den letzten 30 Tagen'];
    }

    private static function archiveInventory(int &$archive): array
    {
        if (!iah_archive_valid($archive) && function_exists('IPS_GetInstanceListByModuleID')) {
            $ids=IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
            if (count($ids)===1)$archive=(int)reset($ids);
        }
        return iah_archive_inventory($archive);
    }

    private static function heaterHistory(
        ?array $meter,int $archive,array $inventory,array $period,DateTimeImmutable $now,float $deadline,
        bool $includePrevious=false,bool $includeTimes=false,bool $perDay=false
    ): array {
        [$start,$end,$periodLabel]=$period;
        $diagnostics=['operation'=>'heater_energy_history','start'=>$start,'end'=>$end,'archiveId'=>$archive,
            'id'=>$meter['id']??0,'unit'=>$meter['unit']??'','thresholds'=>$meter['signal']??[]];
        if ($meter===null) {
            return ['answer'=>'Kein eindeutiger Leistungswert für den Heizungsverlauf.',
                'displayRows'=>[['label'=>'Heizstarts '.$periodLabel,'value'=>'Leistungsquelle fehlt']],
                'diagnostics'=>$diagnostics+['reason'=>'no_meter']];
        }

        $id=$meter['id'];
        $signal=$meter['signal'];
        // Observe the end of a spike after midnight, while counting the start on its own day.
        $readEnd=min($now->getTimestamp(),$end+3600);
        $series=self::archive($archive,$id,$signal,$start,$end,$deadline,false,$inventory,$readEnd);
        $latest=$series['last'];
        if ($latest===null && $includePrevious && $start>1 && isset($inventory[$id])) {
            $previous=self::archive($archive,$id,$signal,0,$start-1,$deadline,true,$inventory,
                min($now->getTimestamp(),$start+3599));
            $diagnostics['previousArchive']=$previous;
            $latest=$previous['last'];
        }
        $knownCount=$series['count']>0 || ($series['reason']==='' && $series['baselineKnown']);
        $count=(($series['reason']!=='' || !$series['baselineKnown'])?'Mindestens ':'').$series['count'];
        $availability=match($series['reason']) {
            'not_archived'=>'Messwert nicht archiviert',
            'logging_inactive'=>'Archivaufzeichnung deaktiviert',
            'partial','archive_error','logging_status_unknown'=>'Archivdaten unvollständig',
            default=>'Zu wenige Archivwerte',
        };
        $row=['label'=>'Heizstarts '.$periodLabel,'value'=>$knownCount?$count:$availability];
        $answers=[$knownCount?$count.($series['count']===1?' Einschaltung ':' Einschaltungen ').$periodLabel
            :'Heizstarts '.$periodLabel.' nicht bestimmbar. '.$availability];
        $rows=[$row];
        if ($latest!==null) {
            $time=self::eventTime($now,$latest);
            $rows[]=['label'=>'Letzter Heizstart','value'=>ucfirst($time)];
            $answers[]='Letzter Heizstart '.$time;
        }
        $starts=$series['starts']??[];
        if ($includeTimes && $starts!==[]) {
            $times=array_map(static fn(int $time):string=>$now->setTimestamp($time)->format('H:i'),$starts);
            $rows[]=['label'=>'Uhrzeiten '.$periodLabel,'value'=>implode(' · ',$times)];
        }
        if ($perDay && $end>=$start) {
            $byDay=[];
            foreach ($starts as $time) {
                $date=$now->setTimestamp($time)->format('Y-m-d');
                $byDay[$date]=($byDay[$date]??0)+1;
            }
            $firstDay=$now->setTimestamp($start)->setTime(0,0);
            $day=$now->setTimestamp($end)->setTime(0,0);
            $complete=$series['reason']==='' && $series['baselineKnown'];
            // The total still covers the full period on long requests.
            for ($index=0;$day>=$firstDay && $index<31;$index++,$day=$day->modify('-1 day')) {
                $number=$byDay[$day->format('Y-m-d')]??0;
                $value=$complete?(string)$number:($number>0?'Mindestens '.$number:'Unbekannt');
                $rows[]=['label'=>$day->format('d.m.Y'),'value'=>$value];
                if ($index<7)$answers[]=$day->format('d.m.').' '.$value;
            }
        }
        return ['answer'=>implode('. ',$answers).'.','displayRows'=>$rows,
            'diagnostics'=>$diagnostics+['archive'=>$series]];
    }

    private static function eventTime(DateTimeImmutable $now,int $timestamp): string
    {
        $event=$now->setTimestamp($timestamp);
        return ($event->format('Y-m-d')===$now->format('Y-m-d')?'heute':'am '.$event->format('d.m.Y'))
            .' um '.$event->format('H:i').' Uhr';
    }

    public static function matchesQuestion(array $catalog,string $text,string $domain=''): bool
    {
        $q=self::norm($text);
        if ($q===''||preg_match('/^(?:oeffne|starte|stoppe|schalte|aktiviere|deaktiviere|bewaessere)\b/',$q)
            || in_array($q,['bewaesserung','gartenbewaesserung'],true)||self::systems($catalog)===[])return false;
        $topic=preg_match('/bewae?sserung|garten\s*pumpe|pumpenschacht|rohr\s*(begleit\s*)?heiz|heizband|bodenfeucht|frost|\bregensensor\b|wasserholen|wasser holen|manuelles giessen|manuell giessen/',$q);
        $systemHealth=(bool)preg_match('/alles in ordnung|zusammenfassung|status.*(?:bewae?sserung|heiz)|(?:bewae?sserung|heiz).*status/',$q);
        $stations=preg_match('/(?:kalt|warm|temperatur).*station|station.*(?:kalt|warm|temperatur)/',$q);
        $pump=preg_match('/\bpumpe\b/',$q)&&preg_match('/\b(?:zuletzt|wann|laeuft|status|ein|aus)\b/',$q);
        $zone=preg_match('/\bkreis \d+\b/',$q)&&!preg_match('/heizkreis|heizung|stromkreis/',$q);
        $context=$domain==='bewaesserung' && preg_match('/(?:\b(?:kreis|kreise|ventil|ventile|restlaufzeit|wasserholen|wasser holen|manuelles giessen|manuell giessen|aussentemperatur|batterie[n]?|akkustand)\b|\b(?:noch )?alles aus\b|^wie geht es\b|wie feucht|trocken|temperatur draussen)/',$q);
        $soil=preg_match('/\b(?:wie feucht|boden am trockensten|welch\w* (?:beet|bereich|kreis).*trocken)\b/',$q);
        $knownStation=false;
        if (preg_match('/\b(?:kalt|warm|temperatur)\b/',$q)) {
            foreach (self::systems($catalog) as $s) foreach (self::points($s,'stationTemperature') as $p) {
                $name=self::stationAlias((string)$p['label']);
                if (mb_strlen($name)>=5&&str_contains(' '.$q.' ',' '.$name.' ')){$knownStation=true;break 2;}
            }
        }
        if (!$topic&&!$stations&&!$pump&&!$zone&&!$context&&!$soil&&!$knownStation&&!$systemHealth)return false;
        return (bool)preg_match('/^(?:und )?(?:wie|ist|sind|war|wann|was|welch\w*|laeuft|laufen|hat|wo|wieviel\w*|sag(?:e)? mir|zeige|nenne|pruefe|kontrolliere|status|heizstarts|alles|zusammenfassung|temperatur|bodenfeuchte|wasserholen|wasser|giessen)\b/',$q);
    }

    private static function systems(array $catalog): array
    {
        return array_filter((array)($catalog['dataSources']??[]),static fn($s):bool=>is_array($s)
            && ($s['provider']??'')==='ObjectTreeIrrigation'&&!empty($s['kiEnabled'])
            && is_array($s['points']??null));
    }

    public static function query(array $catalog,string $text,string $domain,int $archive,float $deadline,?DateTimeImmutable $now=null): ?array
    {
        if (!self::matchesQuestion($catalog,$text,$domain))return null;
        $q=self::norm($text);$systems=self::systems($catalog);
        $stationQuestion=(bool)preg_match('/(?:kalt|warm|temperatur).*station|station.*(?:kalt|warm|temperatur)/',$q);
        if (count($systems)>1) {
            $match=array_filter($systems,static fn(array $s):bool=>str_contains(' '.$q.' ',' '.self::norm((string)$s['title']).' '));
            if (count($match)!==1)return ['answer'=>'Welche Bewässerungsanlage meinst du?','diagnostics'=>['reason'=>'multiple_systems']];
            $systems=$match;
        }
        $s=reset($systems);$now=$now??new DateTimeImmutable();
        $mode=self::mode($s,$now)+['until'=>(int)($s['winterUntilMonth']??3)];
        $diag=['root'=>(int)$s['sourceRootId'],'winter'=>$mode['winter'],'modeSource'=>$mode['source'],'points'=>count((array)$s['points'])];
        $weatherRole=match (true) {
            preg_match('/\b(?:batterie[n]?|akkustand)\b/',$q)===1=>'sensorBattery',
            preg_match('/\bregensensor\b/',$q)===1=>'rainSensor',
            preg_match('/\baussen\s*temperatur\b|\btemperatur draussen\b/',$q)===1=>'outdoorTemperature',
            default=>null,
        };
        if ($weatherRole!==null) {
            $points=self::points($s,$weatherRole);
            $specific=array_filter($points,static function (array $p) use ($q): bool {
                $name=preg_replace('/^batterie\s+/','',self::norm($p['label']))??'';
                return $name!=='' && str_contains(' '.$q.' ',' '.$name.' ');
            });
            if ($specific!==[])$points=$specific;
            $entries=self::entries(['points'=>$points],$weatherRole);
            return ['answer'=>$entries===[]?'Kein passender Messwert zugeordnet.':implode('. ',$entries).'.',
                'diagnostics'=>$diag+['operation'=>$weatherRole,'count'=>count($entries)]];
        }
        if (preg_match('/durchschnitt.*temperatur|temperatur.*durchschnitt|mittelwert.*temperatur/',$q)) {
            $top=[];$bottom=[];
            foreach (self::points($s,'stationTemperature') as $point) {
                $label=self::norm($point['label']);
                if (str_contains($label,'pumpenschacht oben'))$top[]=$point;
                if (str_contains($label,'pumpenschacht unten'))$bottom[]=$point;
            }
            $values=[];
            foreach ([$top,$bottom] as $group)if(count($group)===1) {
                $value=self::value(reset($group));
                if(is_numeric($value))$values[]=(float)$value;
            }
            $answer=count($values)===2?'Die aktuelle Durchschnittstemperatur oben und unten beträgt '.self::number(array_sum($values)/2).' Grad.':'Für oben und unten fehlen aktuelle Temperaturwerte.';
            return ['answer'=>$answer,'diagnostics'=>$diag+['operation'=>'pump_shaft_temperature_average','samples'=>count($values)]];
        }
        $zones=self::zoneMatches($s,$q);
        if (count($zones)>1)return ['answer'=>'Welchen Bewässerungskreis meinst du?','diagnostics'=>$diag+['reason'=>'multiple_zones']];
        $zone=$zones[0]??null;
        $isHeater=(bool)preg_match('/rohr\s*(begleit\s*)?heiz|heizung|heizband|frostschutzheiz/',$q);
        $isPump=(bool)preg_match('/pumpe/',$q);
        $frostRequested=(bool)preg_match('/\bfrost(?:werte|bereich)?\b|unter null|unter 0|minusgrade|minus temperatur/',$q);
        $combinedStatus=(bool)preg_match('/alles in ordnung|zusammenfassung|status.*bewae?sserung|bewae?sserung.*status/',$q);
        $heaterLatest=$isHeater && preg_match('/\bzu\s*letzt\b|letzte[srn]? einschalt|\bwann\b.*(?:eingeschaltet|angesprungen|gelaufen|aktiv)/',$q);
        $heaterDaily=$isHeater && preg_match('/\b(?:pro tag|je tag|tageweise|taeglich)\b/',$q)===1;
        $heatingHistoryRequested=$isHeater && ($heaterLatest
            || $heaterDaily || preg_match('/wie oft|wie haeufig|einschaltungen|angesprungen|hat .*eingeschaltet/',$q));
        $pumpHistory=$isPump && preg_match('/\bzu\s*letzt\b|letzte einschaltung|\bwann\b.*eingeschaltet/',$q);
        $inventory=[];
        if ($isHeater || $pumpHistory || $frostRequested || $combinedStatus) {
            $inventory=self::archiveInventory($archive);
        }
        if ($isHeater && !$combinedStatus && !$frostRequested) {
            $meter=self::heaterMeter($s,$inventory);
            $explicitPeriod=preg_match('/\b(?:heute|gestern|winter|letzten)\b/',$q)===1;
            $period=self::period($heatingHistoryRequested?$q:'heute',$mode,$now);
            if ($heaterDaily && !$explicitPeriod) {
                $period=[$now->setTime(0,0)->modify('-6 days')->getTimestamp(),$now->getTimestamp(),'in den letzten 7 Tagen'];
            } elseif ($heaterDaily && str_starts_with($period[2],'in den letzten ')) {
                $period[0]=$now->setTimestamp($period[0])->setTime(0,0)->modify('+1 day')->getTimestamp();
            }
            $history=self::heaterHistory($meter,$archive,$inventory,$period,$now,$deadline,
                !$heatingHistoryRequested || ($heaterLatest&&!$explicitPeriod),
                in_array($period[2],['heute','gestern'],true),$heaterDaily);
            $history['diagnostics']=$diag+$history['diagnostics'];
            if ($heatingHistoryRequested)return $history;
            $heat=self::heaterLive($meter,$history['diagnostics']['archive']??[]);
            $history['answer']=$heat['speech'].'. '.$history['answer'];
            $heat['label']='Aktueller Status';
            array_unshift($history['displayRows'],$heat);
            return $history;
        }
        if ($combinedStatus || $frostRequested) {
            [$start,$end,$label]=self::period($q,$mode,$now);
            // A plain health-summary request describes today's operation.
            if ($combinedStatus && !preg_match('/\bheute\b|\bgestern\b|letzten \d+ tage|diesen winter|im winter/',$q)) {
                $start=$now->setTime(0,0)->getTimestamp();
                $end=$now->getTimestamp();
                $label='heute';
            }
            $stations=self::pumpShaftTemperatures($s);
            $archiveRows=[];$frostReadings=[];$sampleTotal=0;$allComplete=true;$hasFrost=false;
            foreach (['oben','unten'] as $position) {
                if (count($stations[$position])!==1) {
                    $allComplete=false;
                    $frostReadings[]=$position.': Sensor nicht eindeutig zugeordnet';
                    continue;
                }
                $point=reset($stations[$position]);$id=(int)$point['id'];
                $range=self::temperatureRange($archive,$id,$start,$end,$deadline,$inventory);
                $archiveRows[$position]=['id'=>$id]+$range;
                $sampleTotal+=(int)$range['samples'];
                $allComplete=$allComplete&&!empty($range['complete'])&&$range['reason']==='';
                if ((int)$range['belowThreshold']>0) {
                    $hasFrost=true;
                    $frostReadings[]=self::clean($point['label']).': Frostwerte unter 0 Grad, Minimum '
                        .self::number((float)$range['min']).' Grad';
                } elseif ($range['samples']>0) {
                    $frostReadings[]=self::clean($point['label']).': Minimum '
                        .self::number((float)$range['min']).' Grad';
                } else {
                    $frostReadings[]=self::clean($point['label']).': keine Archivwerte ('.$range['reason'].')';
                }
            }
            $avgParts=[];
            foreach (['oben','unten'] as $position) {
                if (count($stations[$position])===1) {
                    $value=self::value(reset($stations[$position]));
                    if (is_numeric($value))$avgParts[]=(float)$value;
                }
            }
            $parts=[];$displayRows=[];
            if ($combinedStatus) {
                $meter=self::heaterMeter($s,$inventory);
                $heater=self::heaterHistory($meter,$archive,$inventory,self::period('heute',$mode,$now),$now,$deadline,true);
                $heat=self::heaterLive($meter,$heater['diagnostics']['archive']??[]);
                $overview=self::summary($s,true,false,null,$heat,$heater);
                $parts[]=$overview['answer'];
                $displayRows=$overview['displayRows'];
                if (count($avgParts)===2)$parts[]='Aktueller Durchschnitt Pumpenschacht oben und unten '
                    .self::number(($avgParts[0]+$avgParts[1])/2).' Grad';
                elseif ($avgParts!==[])$parts[]='Nur ein aktueller Pumpenschacht-Temperaturwert verfügbar';
            }
            if ($frostReadings===[])$frostReadings[]='keine eindeutigen Pumpenschacht-Temperatursensoren';
            if ($allComplete&&$sampleTotal>0&&!$hasFrost)$frostSummary='keine Archivtemperatur unter 0 Grad';
            elseif ($hasFrost)$frostSummary=$allComplete?'Frostbereich erreicht; '.implode('; ',$frostReadings):'Frostbereich mindestens einmal erreicht, Archivprüfung unvollständig; '.implode('; ',$frostReadings);
            elseif (!$allComplete)$frostSummary='Frostprüfung unvollständig; '.implode('; ',$frostReadings);
            else $frostSummary='keine ausreichenden Archivwerte für die Frostprüfung';
            $parts[]='Temperaturprüfung '.$label.': '.$frostSummary;
            $parts=array_map(static fn(string $part):string=>rtrim(trim($part),'. '),$parts);
            if ($combinedStatus)$displayRows[]=['label'=>'Frostprüfung '.$label,'value'=>$frostSummary];
            return ['answer'=>implode('. ',$parts).'.','displayRows'=>$displayRows,'diagnostics'=>$diag+[
                'operation'=>$combinedStatus?'irrigation_heater_health_summary':'temperature_frost_archive',
                'period'=>$label,'start'=>$start,'end'=>$end,'archiveId'=>$archive,
                'frostThresholdC'=>0,'temperatureArchives'=>$archiveRows,
                'heaterSummary'=>$combinedStatus?($heater['diagnostics']??[]):null]];
        }
        if ($pumpHistory) {
            $p=self::points($s,'pumpStatus');
            $explicit=(int)($s['historySignals']['pumpId']??0);
            if ($explicit>0)$p=array_intersect_key($p,[$explicit=>true]);
            else {
                $p=array_filter(array_intersect_key($p,$inventory),static fn($point)=>($point['type']??-1)===0);
                $feedback=array_filter($p,static fn(array $pt):bool=>empty($pt['owned'])
                    && in_array('link',(array)($pt['evidence']??[]),true));
                if ($feedback!==[])$p=$feedback;
            }
            $pt=count($p)===1?reset($p):null;
            $a=is_array($pt)&&($pt['type']??-1)===0&&is_bool(self::value($pt))
                ?self::archive($archive,(int)$pt['id'],['mode'=>'binary'],0,$now->getTimestamp(),$deadline,true,$inventory):[];
            $date=empty($a['last'])?null:$now->setTimestamp((int)$a['last'])->format('d.m.Y');
            $answer=$date??match($a['reason']??'no_signal'){
                'not_archived','no_samples'=>'Für die Pumpe liegen keine Archivdaten vor.',
                'no_signal'=>'Der archivierte Pumpenstatus ist nicht eindeutig zugeordnet.',
                'partial','archive_error'=>'Das Pumpenarchiv konnte ich nicht vollständig prüfen.',
                default=>'Im Archiv ist kein Einschalten der Pumpe nachweisbar.'};
            return ['answer'=>$answer,'diagnostics'=>$diag+['operation'=>'pump_last_on','id'=>$pt['id']??0,
                'archiveId'=>$archive,'archive'=>$a]];
        }
        if ($stationQuestion||preg_match('/\b(?:kalt|warm|temperatur)\b/',$q)) {
            $temps=[];$matches=[];
            foreach (self::points($s,'stationTemperature') as $p) {
                $label=self::clean((string)$p['label']);$alias=self::stationAlias($label);
                $v=self::value($p);
                $answer=is_numeric($v)?$label.' '.self::number((float)$v).' Grad':$label.': Temperatur nicht verfügbar';
                if (is_numeric($v))$temps[]=$answer;
                if (mb_strlen($alias)>=5&&str_contains(' '.$q.' ',' '.$alias.' '))$matches[$answer]=mb_strlen($alias);
            }
            if ($matches!==[]) {
                $longest=max($matches);
                $temps=array_keys(array_filter($matches,static fn($len)=>$len===$longest));
            }
            return ['answer'=>$temps===[]?'Keine Stationstemperaturen zugeordnet.':implode('. ',$temps).'.',
                'diagnostics'=>$diag+['operation'=>'temperature_stations','count'=>count($temps)]];
        }
        if (preg_match('/\b(?:wann|naechst|zeitplan|startzeit)\b/',$q)) {
            $times=[];
            foreach (self::points($s,'nextStart',$zone) as $p) {
                $v=self::value($p);
                if (is_int($v)&&$v>$now->getTimestamp()&&$v<=$now->modify('+1 year')->getTimestamp())
                    $times[]=$v;
            }
            $answer=$times===[]?'Kein eindeutiger nächster Start hinterlegt.':
                'Der nächste hinterlegte Start ist am '.$now->setTimestamp(min($times))->format('d.m.Y').' um '
                .$now->setTimestamp(min($times))->format('H:i').' Uhr.';
            return ['answer'=>$answer,'diagnostics'=>$diag+['operation'=>'next_start','zone'=>$zone]];
        }
        if (preg_match('/pumpendruck|druck der pumpe/',$q)) {
            $values=self::entries($s,'pumpPressure');
            return ['answer'=>$values===[]?'Kein Pumpendruck zugeordnet.':implode('. ',$values).'.',
                'diagnostics'=>$diag+['operation'=>'pump_pressure','count'=>count($values)]];
        }
        if (preg_match('/bodenfeucht|trocken|wie feucht|giessempfehlung|giessen/',$q)) {
            $values=[];
            foreach (self::points($s,'soilMoisture') as $p) {
                $v=self::value($p);
                if (!is_numeric($v)||($p['unit']??'')!=='%')continue;
                $values[]=['label'=>self::clean($p['label']),'value'=>(float)$v,'zone'=>(int)$p['zone'],
                    'sensorType'=>(string)($p['sensorType']??'WH51')];
            }
            if (preg_match('/\bkreis \d+\b/',$q) && $zone!==null)
                $values=array_values(array_filter($values,static fn($p)=>$p['zone']===$zone));
            else {
                $specific=array_filter($values,static function($p)use($q):bool {
                $label=self::norm(preg_replace('/\(\d+\)/','',$p['label'])??$p['label']);
                return $label!==''&&str_contains(' '.$q.' ',' '.$label.' ');
            });
                if ($specific!==[])$values=array_values($specific);
                elseif ($zone!==null)$values=array_values(array_filter($values,static fn($p)=>$p['zone']===$zone));
            }
            if (str_contains($q,'trocken')) {
                usort($values,static fn($a,$b)=>$a['value']<=>$b['value']);
                if ($values!==[])$values=array_values(array_filter($values,static fn($p)=>$p['value']===$values[0]['value']));
            }
            return ['answer'=>$values===[]?'Keine passenden Bodenfeuchtesensoren zugeordnet.':
                implode('. ',array_map(static function($p):string {
                    $v=$p['value'];$advice=$v<10?'sehr trocken, sofort gießen':($v<20?'trocken, gießen empfohlen':
                        ($v<30?'leicht trocken, empfindliche Pflanzen gießen':($v<60?'optimal, kein Gießen nötig':
                        ($v<=80?'feucht, Gießen vermeiden':'sehr feucht, keine Bewässerung'))));
                    return $p['label'].' '.self::number($v).' Prozent, '.$advice;
                },$values)).'.',
                'diagnostics'=>$diag+['operation'=>'soil_moisture','count'=>count($values)]];
        }
        if ($zone===null && preg_match('/\b(?:ventil|ventile|kreis|kreise)\b/',$q)
            && !preg_match('/\b(?:welche|wieviele|viele|alle|alles|laufen)\b/',$q))
            return ['answer'=>'Welchen Bewässerungskreis meinst du?','diagnostics'=>$diag+['operation'=>'zone_missing']];
        $onlyState=preg_match('/alles aus|welche kreise|restlauf|^laeuft/',$q)===1;
        $heat=[];$heating=[];
        if ($mode['winter'] && $zone===null) {
            $inventory=self::archiveInventory($archive);
            $meter=self::heaterMeter($s,$inventory);
            $heating=self::heaterHistory($meter,$archive,$inventory,self::period('heute',$mode,$now),$now,$deadline,!$onlyState);
            $heat=self::heaterLive($meter,$heating['diagnostics']['archive']??[]);
        }
        return self::summary($s,$mode['winter'],$onlyState,$zone,$heat,$onlyState?[]:$heating)
            +['diagnostics'=>$diag+['operation'=>'status','zone'=>$zone,'heaterSummary'=>$heating['diagnostics']??null]];
    }

    /** Explicit controller actions only. Model output and detected scripts
     * never enter this path. Returns null for commands belonging elsewhere. */
    public static function command(array $catalog,string $spoken,bool $allow,bool $execute): ?array
    {
        $q=self::norm($spoken);
        if (!preg_match('/^(?:schalte|schalt|starte|start|stoppe|stop|aktiviere|deaktiviere|bewaessere)\b/',$q))return null;
        $named=(bool)preg_match('/bewae?sserung|garten\s*pumpe|\bkreis \d+\b/',$q);
        $systems=[];
        foreach ((array)($catalog['dataSources']??[]) as $s) {
            if (!is_array($s)||($s['provider']??'')!=='ObjectTreeIrrigation'||empty($s['kiEnabled']))continue;
            $zones=self::zoneMatches($s,$q);
            if ($named||($zones!==[]&&(str_starts_with($q,'bewaessere')||preg_match('/\bkreis\b/',$q))))$systems[]=[$s,$zones];
        }
        if ($systems===[])return null;
        $ret=static fn(string $answer,string $reason,bool $ok=false)=>['handled'=>true,'answer'=>$answer,'reason'=>$reason,'ok'=>$ok];
        if (count($systems)!==1 || count($systems[0][1])>1)return $ret('Welchen Bewässerungskreis meinst du?','ambiguous');
        [$s,$zones]=$systems[0];
        if (preg_match('/\b(?:minut\w*|sekund\w*|stunde\w*|uhr|morgen|spaeter|jetzt nicht|nicht|danach)\b/',$q))
            return $ret('Zeitgesteuertes oder mehrdeutiges Schalten ist für diese Anlage nicht verfügbar.','unsupported_command');
        $off=(bool)preg_match('/^(?:stoppe|stop|deaktiviere)\b|\b(?:aus|ab)\s*$/',$q);
        $on=(bool)preg_match('/^(?:starte|start|aktiviere|bewaessere)\b|\b(?:ein|an)\s*$/',$q);
        if ($on===$off)return $ret('Soll ich ein- oder ausschalten?','missing_value');
        // Exact grammar: never turn a conditional, multi-target or arbitrary
        // phrase into an activation based on a single matching substring.
        $subject=trim(preg_replace('/^(?:schalte|schalt|starte|start|stoppe|stop|aktiviere|deaktiviere|bewaessere) (?:bitte )?(?:(?:die|den|das|der) )?| (?:ein|an|aus|ab)(?: bitte)?$/','',$q)??'');
        $zone=$zones[0]??0;
        if ($zone>0) {
            $aliases=['kreis '.$zone,'bewaesserung kreis '.$zone];
            foreach ((array)($s['points']??[]) as $p) if ((int)($p['zone']??0)===$zone && ($p['role']??'')==='zoneManual') {
                foreach (array_merge([(string)($p['label']??'')],(array)($p['aliases']??[])) as $label)
                    $aliases[]=trim(preg_replace('/\s+/',' ',preg_replace('/\b(?:manuell|kreis '.$zone.')\b/','',self::norm($label))??'')??'');
            }
            if ($subject===''||!in_array($subject,$aliases,true))return $ret('Welchen Bewässerungskreis meinst du?','unverified_subject');
        } elseif (!in_array($subject,['gartenpumpe','garten pumpe','bewaesserungspumpe','pumpe der bewaesserung',
            'bewaesserung automatik','automatik der bewaesserung','bewaesserungsautomatik'],true))
            return $ret('Nenne bitte einen Kreis, die Gartenpumpe oder die Bewässerungsautomatik.','missing_target');
        $role=$zone>0?'zoneManual':(str_contains($subject,'automatik')?'automatic':'pumpManual');
        $pt=array_filter((array)$s['points'],static fn($p)=>is_array($p)&&($p['role']??'')===$role
            && (int)($p['zone']??0)===$zone&&!empty($p['actionCandidate'])
            && in_array((int)$p['id'],(array)($s['voiceActions']??[]),true));
        if (!$allow||count($pt)!==1)return $ret('Diese Bewässerungsaktion ist nicht für Alexa freigegeben.','not_allowed');
        $p=reset($pt);$id=(int)$p['id'];$parent=(int)$p['parent'];
        if (!$execute)return $ret('Vorschau: '.self::clean($p['label']).' '.($on?'einschalten':'ausschalten').'.','preview',true);
        if (self::value($p)===null)return $ret('Die Steuerung ist nicht verfügbar.','invalid_variable');
        try {
            $v=IPS_GetVariable($id);
            $ident=$zone>0?'Manuell'.$zone:($role==='automatic'?'GesamtAutomatik':'PumpeManuell');
            if ($p['ident']!==$ident || (int)$v['VariableType']!==0 || (int)($v['VariableAction']??0)!==$parent
                || (int)($v['VariableCustomAction']??0)!==0 || !empty($v['VariableIsLocked'])
                || strtoupper((string)(IPS_GetInstance($parent)['ModuleInfo']['ModuleID']??''))!==self::CONTROLLER)
                return $ret('Die freigegebene Steuerung hat sich geändert.','changed_action');
            if ($zone>0 && self::state($s,'automatic')!==false)
                return $ret('Einzelne Kreise kann ich nur bei ausgeschalteter Bewässerungsautomatik manuell schalten.','automatic_or_unknown');
            if (RequestAction($id,$on)===false)return $ret('Die Bewässerungssteuerung hat den Befehl nicht angenommen.','request_rejected');
            $state=$role==='automatic'?self::state($s,'automatic'):self::state($s,$zone>0?'zoneStatus':'pumpStatus',$zone>0?$zone:null);
            return $ret($state===$on?'Bestätigt. '.($zone>0?'Kreis '.$zone:($role==='automatic'?'Bewässerungsautomatik':'Gartenpumpe'))
                .' ist '.($on?'ein':'aus').'.':'Befehl gesendet. Der neue Zustand ist noch nicht bestätigt.',
                $state===$on?'confirmed':'unconfirmed',true);
        } catch (Throwable $e) {return $ret('Die Bewässerungssteuerung hat den Befehl nicht bestätigt.','request_failed');}
    }
}
