<?php
declare(strict_types=1);

/** Voice and archive runtime for a published object-tree irrigation system. */
final class IAHIrrigationQuery
{
    private const CONTROLLER='{DEADBEAF-0000-0000-0000-000000000001}';

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
                || (int)($obj['ParentID']??$obj['ObjectParentID']??0)!==(int)($p['parent']??0)
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
    public static function edges(array $rows,array $signal,int $start,int $end): array
    {
        $states=[];$previous=null;$last=null;$count=0;
        usort($rows,static fn(array $a,array $b):int=>(int)($a['TimeStamp']??0)<=>(int)($b['TimeStamp']??0));
        foreach ($rows as $r) {
            $ts=(int)($r['TimeStamp']??0);$v=$r['Value']??null;
            if ($ts<=0 || $ts>$end)continue;
            $state=self::sampleState($v,$signal);
            // Opposing states in the same second have no proven order.
            if (array_key_exists($ts,$states) && $states[$ts]!==$state)$states[$ts]='ambiguous';
            else $states[$ts]=$state;
        }
        foreach ($states as $ts=>$state) {
            if ($state==='ambiguous'||$state==='unknown'){$previous=null;continue;}
            if ($state===null)continue;
            if ($ts>=$start && $previous===false && $state===true) {
                $count++;$last=$ts;
            }
            $previous=$state;
        }
        return ['count'=>$count,'last'=>$last,'samples'=>count($states),'baselineKnown'=>self::hasBaseline($states,$start)];
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
        if (($signal['mode']??'')!=='threshold'||!is_numeric($value)
            || !is_numeric($signal['on']??null)||!is_numeric($signal['off']??null)
            || (float)$signal['off']>=(float)$signal['on'])return 'unknown';
        if ((float)$value>=(float)$signal['on'])return true;
        if ((float)$value<=(float)$signal['off'])return false;
        return null;
    }

    /** Archive pages are newest-first. Read one overlap second and abort
     * if a page cannot advance without skipping same-second samples. */
    private static function archive(int $archive,int $id,array $signal,int $start,int $end,float $deadline,bool $latest): array
    {
        $base=['count'=>0,'last'=>null,'samples'=>0,'baselineKnown'=>false,'reason'=>''];
        if (!isset(iah_archive_inventory($archive)[$id])||!function_exists('AC_GetLoggedValues'))
            return array_replace($base,['reason'=>'not_archived']);
        $size=400;$rows=[];$cursor=$end;$complete=false;$foundLatest=false;$ambiguousBoundary=false;
        try {
            for ($page=0;$page<12 && microtime(true)<$deadline && $cursor>=max(1,$start);$page++) {
                $batch=AC_GetLoggedValues($archive,$id,$start,$cursor,$size);
                if (!is_array($batch))throw new RuntimeException('read');
                $rows=array_merge($rows,$batch);
                if ($latest && count($batch)>0) {
                    $boundary=(int)($batch[array_key_last($batch)]['TimeStamp']??0);
                    // Do not conclude from a potentially split timestamp at a full-page boundary.
                    $proof=count($batch)<$size?$rows:array_filter($rows,static fn($r)=>(int)($r['TimeStamp']??0)>$boundary);
                    if (self::edges($proof,$signal,$start,$end)['last']!==null) {
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
        $out=self::edges($rows,$signal,$start,$end);
        if ($latest && !$foundLatest && !$complete)$out['last']=null;
        $out['reason']=$foundLatest||($complete && !$ambiguousBoundary)?'':'partial';
        if (!$latest && $out['reason']==='' && function_exists('AC_GetLoggingStatus')) {
            try {if (!AC_GetLoggingStatus($archive,$id))$out['reason']='logging_inactive';}
            catch (Throwable $ignored) {$out['reason']='logging_status_unknown';}
        }
        if ($out['samples']===0 && $out['reason']==='')$out['reason']='no_samples';
        return $out;
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
        $entries=[];
        foreach (self::points($system,$role,$zone) as $p) {
            $v=self::value($p);
            if (is_int($v)||is_float($v))$entries[]=self::clean($p['label']).' '.self::number($v)
                .match($role){'stationTemperature'=>' Grad','soilMoisture'=>' Prozent',default=>' '.($p['unit']??'')};
        }
        return $entries;
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

    private static function heaterState(array $s): ?bool
    {
        $states=[];
        $signals=(array)($s['historySignals']['heater']??[]);
        if ($signals===[])return self::state($s,'heaterStatus');
        foreach ($signals as $id=>$signal) {
            $p=$s['points'][$id]??null;
            if (!is_array($p)||empty($p['enabled'])||!in_array($p['role']??'',['heaterStatus','heaterSignal'],true))return null;
            $v=self::sampleState(self::value($p),$signal);
            if ($v===true)return true;
            if ($v!==false)$states[]=null;
        }
        return $states===[]?false:null;
    }

    private static function summary(array $s,bool $winter,bool $onlyState,?int $zone): string
    {
        $parts=[];$on=[];$missing=0;
        $pump=self::state($s,'pumpStatus');
        foreach ($zone===null?self::statusPoints($s):[$zone] as $i) {
            $v=self::state($s,'zoneStatus',$i);
            if ($v===true) {
                $label='Kreis '.$i;
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
        if ($zone===null)$parts[]=$pump===null?'Pumpenstatus unbekannt':'Pumpe '.($pump?'ein':'aus');
        if ($on!==[])$parts[]='Aktiv: '.implode(', ',$on);
        elseif ($missing===0 && ($zone!==null || self::statusPoints($s)!==[]))
            $parts[]=$zone!==null?'Kreis '.$zone.' ist aus':'alle erfassten Kreise aus';
        if ($missing>0)$parts[]=$zone!==null?'Status von Kreis '.$zone.' unbekannt'
            :$missing.' Kreis'.($missing===1?'':'e').' ohne verlässlichen Status';
        if ($onlyState && $winter && $zone===null) {
            $heat=self::heaterState($s);
            $parts[]=$heat===null?'Rohrbegleitheizung nicht eindeutig':'Rohrbegleitheizung '.($heat?'ein':'aus');
        }
        if (!$onlyState && $zone===null) {
            if ($winter) {
                $heat=self::heaterState($s);
                $parts[]=$heat===null?'Rohrbegleitheizung nicht eindeutig':'Rohrbegleitheizung '.($heat?'ein':'aus');
                $parts=array_merge($parts,self::entries($s,'stationTemperature'));
            } elseif ($pump===false || $on!==[]) {
                $soil=self::entries($s,'soilMoisture');
                if ($soil!==[])$parts[]='Bodenfeuchte: '.implode(', ',array_slice($soil,0,4));
            }
        }
        return $parts!==[]?implode('. ',$parts).'.':'Keine eindeutigen Zustände gefunden.';
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

    public static function matchesQuestion(array $catalog,string $text,string $domain=''): bool
    {
        $q=self::norm($text);
        if ($q===''||preg_match('/^(?:oeffne|starte|stoppe|schalte|aktiviere|deaktiviere|bewaessere)\b/',$q)
            || in_array($q,['bewaesserung','gartenbewaesserung'],true)||self::systems($catalog)===[])return false;
        $topic=preg_match('/bewae?sserung|garten\s*pumpe|pumpenschacht|rohr\s*(begleit\s*)?heiz|heizband|bodenfeucht|frostschutz/',$q);
        $stations=preg_match('/(?:kalt|warm|temperatur).*station|station.*(?:kalt|warm|temperatur)/',$q);
        $pump=preg_match('/\bpumpe\b/',$q)&&preg_match('/\b(?:zuletzt|wann|laeuft|status|ein|aus)\b/',$q);
        $zone=preg_match('/\bkreis \d+\b/',$q)&&!preg_match('/heizkreis|heizung|stromkreis/',$q);
        $context=$domain==='bewaesserung' && preg_match('/(?:\b(?:kreis|kreise|ventil|ventile|restlaufzeit)\b|\b(?:noch )?alles aus\b|^wie geht es\b|wie feucht|trocken)/',$q);
        $soil=preg_match('/\b(?:wie feucht|boden am trockensten|welch\w* (?:beet|bereich|kreis).*trocken)\b/',$q);
        $knownStation=false;
        if (preg_match('/\b(?:kalt|warm|temperatur)\b/',$q)) {
            foreach (self::systems($catalog) as $s) foreach (self::points($s,'stationTemperature') as $p) {
                $name=self::stationAlias((string)$p['label']);
                if (mb_strlen($name)>=5&&str_contains(' '.$q.' ',' '.$name.' ')){$knownStation=true;break 2;}
            }
        }
        if (!$topic&&!$stations&&!$pump&&!$zone&&!$context&&!$soil&&!$knownStation)return false;
        return (bool)preg_match('/^(?:und )?(?:wie|ist|sind|wann|was|welch\w*|laeuft|laufen|hat|wo|wieviel\w*|sag(?:e)? mir|zeige|nenne|pruefe|kontrolliere|status|temperatur|bodenfeuchte)\b/',$q);
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
        $zones=self::zoneMatches($s,$q);
        if (count($zones)>1)return ['answer'=>'Welchen Bewässerungskreis meinst du?','diagnostics'=>$diag+['reason'=>'multiple_zones']];
        $zone=$zones[0]??null;
        $isHeater=(bool)preg_match('/rohr\s*(begleit\s*)?heiz|heizband|frostschutzheiz/',$q);
        $isPump=(bool)preg_match('/pumpe/',$q);
        if ($isHeater && preg_match('/wie oft|wie haeufig|einschaltungen|anstieg/',$q)) {
            [$start,$end,$label]=self::period($q,$mode,$now);
            $rows=[];$errors=[];$log=[];$set=(array)($s['historySignals']['heater']??[]);
            if ($set===[] && empty($s['historySignals']['heaterAutomaticDisabled'])) {
                $p=self::points($s,'heaterStatus');
                if (count($p)===1) $set[(int)reset($p)['id']]=['mode'=>'binary'];
            }
            foreach ($set as $id=>$signal) {
                if (count($log)>=8 || microtime(true)>=$deadline){$errors[]='weitere Signale nicht geprüft';break;}
                $p=$s['points'][(int)$id]??null;
                if (!is_array($p)||empty($p['enabled'])||!in_array($p['role']??'',['heaterStatus','heaterSignal'],true)
                    || self::value($p)===null)continue;
                $a=self::archive($archive,(int)$id,$signal,$start,$end,$deadline,false);
                $log[]=['id'=>(int)$id]+$a;
                if ($a['samples']>0 && ($a['count']>0 || ($a['reason']==='' && $a['baselineKnown']))) {
                    $n=$a['count'];
                    $rows[]=(count($set)>1?self::clean($p['label']).': ':'')
                        .(($a['reason']!==''||!$a['baselineKnown'])?'mindestens ':'')
                        .$n.' '.($n===1?'Einschaltung':'Einschaltungen');
                } elseif ($a['samples']>0) $errors[]='Kein vollständiger Nachweis für '.self::clean($p['label']);
                else $errors[]=self::clean($p['label']).' ohne Archivdaten';
            }
            $answer=$rows===[]?($errors===[]?'Für die Rohrbegleitheizung ist kein eindeutiges Archivsignal zugeordnet.':implode('. ',$errors).'.')
                :implode('. ',array_merge($rows,$errors)).' '.$label.'.';
            return ['answer'=>$answer,'diagnostics'=>$diag+['operation'=>'heater_edges','start'=>$start,'end'=>$end,
                'archiveId'=>$archive,'signals'=>$log,'configuredSignals'=>count($set)]];
        }
        if ($isPump && preg_match('/\bzu\s*letzt\b|letzte einschaltung|\bwann\b.*eingeschaltet/',$q)) {
            $p=self::points($s,'pumpStatus');
            $explicit=(int)($s['historySignals']['pumpId']??0);
            if ($explicit>0)$p=array_intersect_key($p,[$explicit=>true]);
            else {
                $feedback=array_filter($p,static fn(array $pt):bool=>empty($pt['owned'])
                    && in_array('link',(array)($pt['evidence']??[]),true));
                if ($feedback!==[])$p=$feedback;
            }
            $pt=count($p)===1?reset($p):null;
            $a=is_array($pt)&&($pt['type']??-1)===0&&is_bool(self::value($pt))
                ?self::archive($archive,(int)$pt['id'],['mode'=>'binary'],0,$now->getTimestamp(),$deadline,true):[];
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
        if (preg_match('/bodenfeucht|trocken|wie feucht/',$q)) {
            $values=[];
            foreach (self::points($s,'soilMoisture') as $p) {
                $v=self::value($p);
                if (!is_numeric($v)||($p['unit']??'')!=='%')continue;
                $values[]=['label'=>self::clean($p['label']),'value'=>(float)$v,'zone'=>(int)$p['zone']];
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
                implode('. ',array_map(static fn($p)=>$p['label'].' '.self::number($p['value']).' Prozent',$values)).'.',
                'diagnostics'=>$diag+['operation'=>'soil_moisture','count'=>count($values)]];
        }
        if ($isHeater) {
            $v=self::heaterState($s);
            return ['answer'=>$v===null?'Der Zustand der Rohrbegleitheizung ist nicht eindeutig.':
                'Die Rohrbegleitheizung ist '.($v?'ein':'aus').'.','diagnostics'=>$diag+['operation'=>'heater_status']];
        }
        if ($zone===null && preg_match('/\b(?:ventil|ventile|kreis|kreise)\b/',$q)
            && !preg_match('/\b(?:welche|wieviele|viele|alle|alles|laufen)\b/',$q))
            return ['answer'=>'Welchen Bewässerungskreis meinst du?','diagnostics'=>$diag+['operation'=>'zone_missing']];
        return ['answer'=>self::summary($s,$mode['winter'],(bool)preg_match('/alles aus|welche kreise|restlauf|laeuft/',$q),$zone),
            'diagnostics'=>$diag+['operation'=>'status','zone'=>$zone]];
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
