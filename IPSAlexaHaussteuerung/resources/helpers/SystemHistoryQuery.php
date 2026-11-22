<?php
declare(strict_types=1);

/** Discover history through published, identity-checked read bindings, never a global variable-name scan. */
final class IAHSystemHistory
{
    public static function historical(string $text): bool
    {
        $q=IAHHouseQuestionContext::normalize($text);
        if (preg_match('/\b(?:morgen|uebermorgen|naechst\w*|geplant|zeitplan)\b/',$q)) return false;
        return preg_match('/\b(?:wann|zuletzt|letzte[rsnm]?|wie oft|wie haeufig|wie lange|seit wann|gestern|vorgestern|verlauf|historie|vergangenheit|war|waren|lag|lagen|durchschnitt|minimum|maximum|tiefst\w*|hoechst\w*)\b/',$q)===1;
    }

    public static function bindings(array $catalog): array
    {
        $out=[];
        $add=static function(array $m,string $domain,string $name,string $kind,string $property,string $room='')use(&$out):void {
            if (empty($m['enabled'])) return;
            $id=(int)($m['id']??0);
            try {
                if ($id<=0||!IPS_VariableExists($id))return;
                $object=IPS_GetObject($id);$v=IPS_GetVariable($id);
                if ((int)($object['ParentID']??$object['ObjectParentID']??-1)!==($m['parent']??null)
                    ||(string)($object['ObjectIdent']??'')!==($m['ident']??null)
                    ||(int)($v['VariableType']??-1)!==($m['type']??null))return;
            } catch (Throwable $ignored) {return;}
            $profile=(string)(($v['VariableCustomProfile']??'')?:($v['VariableProfile']??''));$unit='';
            if ($profile!==''&&function_exists('IPS_VariableProfileExists')&&IPS_VariableProfileExists($profile)) $unit=trim((string)(IPS_GetVariableProfile($profile)['Suffix']??''));
            if ($unit===''&&function_exists('IPS_GetVariablePresentation')) {
                $presentation=IPS_GetVariablePresentation($id);$unit=trim((string)($presentation['SUFFIX']??$presentation['suffix']??''));
            }
            $out[$id]=['id'=>$id,'name'=>$name,'label'=>$m['title'],'ident'=>$m['ident'],'domain'=>$domain,
                'kind'=>$kind,'property'=>$property,'unit'=>$unit,'room'=>$room,'type'=>$m['type']];
        };
        $weather=IAHWeatherStation::source($catalog);
        foreach ((array)($weather['devices']??[]) as $d) {
            if (empty($weather['kiEnabled'])||empty($d['enabled'])||!IAHWeatherStation::station((int)$d['id'])) continue;
            foreach ((array)$d['variables'] as $m) {
                foreach ((array)$weather['devices'] as $owner) if ((int)$owner['id']===(int)$m['parent']&&empty($owner['enabled'])) continue 2;
                $role=IAHWeatherStation::role((string)$m['ident']);
                $property=match($role){'temperature','feels','dewpoint'=>'temperature','soil'=>'soilMoisture','humidity'=>'humidity','wind','gust'=>'windSpeed',default=>''};
                if ($property!=='') $add($m,'wetterstation',IAHWeatherStation::speechName($m),'numeric',$property);
            }
        }
        foreach ((array)($catalog['dataSources']??[]) as $s) {
            if (!is_array($s)||($s['provider']??'')!=='ObjectTreeIrrigation'||empty($s['kiEnabled'])) continue;
            foreach ((array)($s['points']??[]) as $p) {
                $role=$p['role']??''; $property=match($role){'pumpStatus','zoneStatus'=>'power','stationTemperature'=>'temperature','soilMoisture'=>'soilMoisture',default=>''};
                if ($property===''||($property==='power'&&($p['type']??-1)!==0)) continue;
                $m=$p; $m['title']=(string)($p['label']??'Messwert');
                $add($m,'bewaesserung',$m['title'],$property==='power'?'binary':'numeric',$property);
                if (isset($out[$p['id']])) $out[$p['id']]+= ['irrigationRole'=>$role,'zone'=>(int)($p['zone']??0)];
            }
        }
        foreach (iah_rc_index_catalog($catalog,true) as $dev) foreach ((array)$dev['reads'] as $property=>$id) {
            if (isset($out[$id])||!in_array($property,['temperature','humidity','soilMoisture','windSpeed','motion','power','open','alarm'],true)) continue;
            try {
                $object=IPS_GetObject((int)$id);$variable=IPS_GetVariable((int)$id);
                $m=['id'=>(int)$id,'parent'=>(int)($object['ParentID']??$object['ObjectParentID']??-1),
                    'ident'=>(string)($object['ObjectIdent']??''),'type'=>(int)$variable['VariableType'],
                    'title'=>(string)$dev['name'],'enabled'=>true];
                if (in_array($property,['motion','power','open','alarm'],true)&&$m['type']!==0) continue;
                $add($m,(string)$dev['domain'],(string)$dev['name'],$m['type']===0?'binary':'numeric',$property,(string)$dev['roomKey']);
            } catch (Throwable $ignored) {}
        }
        return $out;
    }

    /** Provider metrics also become visible to the existing read-only archive tool. */
    public static function devices(array $catalog): array
    {
        $devices=[];$known=[];
        foreach (iah_rc_index_catalog($catalog,true) as $d) foreach ((array)$d['reads'] as $id) $known[(int)$id]=true;
        foreach (self::bindings($catalog) as $b) {
            if(isset($known[$b['id']]))continue;
            $key='history_'.substr(hash('sha256',(string)$b['id']),0,16);
            $devices[$key]=['id'=>$key,'name'=>$b['name'],'room'=>$b['room']!==''?$b['room']:'Anlage',
                'roomKey'=>$b['room'],'floor'=>'','domain'=>$b['domain'],'group'=>'archiv','type'=>$b['kind']==='binary'?'switch':'sensor',
                'reads'=>[$b['property']=>$b['id']],'read'=>$b['id'],'writes'=>[],'write'=>0,'writeRoles'=>[],'writable'=>false,
                'roomAliases'=>[],'synonyms'=>[$b['name'],$b['label']],'typed'=>false,'archiveOnly'=>false,'archiveSourceId'=>$b['id'],
                'min'=>null,'max'=>null,'setpoint'=>0,'legacyTemperatureRead'=>false,
                'readOnly'=>true,'kiReadOnly'=>true,'aliases'=>[$b['name'],$b['label']],'stateMapping'=>['on'=>true,'off'=>false]];
        }
        return $devices;
    }

    private static function reply(string $answer,array $rows=[],string $icon='weather'): array
    {
        return ['handled'=>true,'answer'=>$answer,'answerSource'=>'house_status','localHouseQuery'=>true,
            'displayTitle'=>'Archiv-Auswertung','displayIcon'=>$icon,'displayRows'=>$rows];
    }

    public static function period(string $q,DateTimeImmutable $now,bool $latest): array
    {
        $day=$now->setTime(0,0);
        if (str_contains($q,'vorgestern')) return [$day->modify('-2 days')->getTimestamp(),$day->modify('-1 day')->getTimestamp()-1,'Vorgestern'];
        if (str_contains($q,'gestern')) return [$day->modify('-1 day')->getTimestamp(),$day->getTimestamp()-1,'Gestern'];
        foreach ([1=>'montag','dienstag','mittwoch','donnerstag','freitag','samstag','sonntag'] as $n=>$name) if(preg_match('/\b'.$name.'\b/',$q)) {
            $offset=((int)$day->format('N')-$n+7)%7;$date=$day->modify('-'.$offset.' days');
            return [$date->getTimestamp(),$offset===0?$now->getTimestamp():$date->modify('+1 day')->getTimestamp()-1,'Am '.$date->format('d.m.Y')];
        }
        if (preg_match('/letzten (\d{1,3}|sieben|dreissig) tage/',$q,$m)) {
            $n=match($m[1]){'sieben'=>7,'dreissig'=>30,default=>max(1,min(365,(int)$m[1]))};
            return [$day->modify('-'.($n-1).' days')->getTimestamp(),$now->getTimestamp(),'In den letzten '.$n.' Tagen'];
        }
        return $latest&&!str_contains($q,'heute')?[$now->modify('-365 days')->getTimestamp(),$now->getTimestamp(),'In den letzten 365 Tagen']:[$day->getTimestamp(),$now->getTimestamp(),'Heute'];
    }

    public static function series(int $archive,int $id,int $start,int $end,float $deadline): array
    {
        $values=[];$cursor=$end;$complete=false;$seed=null;
        try {
            for ($page=0;$page<20&&microtime(true)<$deadline;$page++) {
                $rows=AC_GetLoggedValues($archive,$id,$start,$cursor,1000);
                if (!is_array($rows)) break;
                if ($rows===[]) {$complete=true;break;}
                $oldest=$cursor;
                foreach ($rows as $r) {
                    $t=(int)($r['TimeStamp']??0);$v=$r['Value']??null;
                    if ($t<$start||$t>$end) continue;
                    $oldest=min($oldest,$t);$key=get_debug_type($v).':'.(is_scalar($v)?(string)$v:'invalid');
                    $values[$t][$key]=$v;
                }
                if (count($rows)<1000) {$complete=true;break;}
                if ($oldest>=$cursor) break;
                $cursor=$oldest; // overlap the boundary second, deduplicating equal values above
            }
            if (microtime(true)<$deadline) {
                $before=AC_GetLoggedValues($archive,$id,0,$start-1,1);
                if (is_array($before)&&isset($before[0])) $seed=$before[0]['Value']??null;
            } else $complete=false;
            if (function_exists('AC_GetLoggingStatus')&&!AC_GetLoggingStatus($archive,$id)) {
                $complete=false;
                if ($values===[])$seed=null;
            }
        } catch (Throwable $ignored) {$complete=false;}
        ksort($values,SORT_NUMERIC);
        return ['values'=>$values,'seed'=>$seed,'complete'=>$complete];
    }

    /** Count episodes/edges, not individual samples; retain ambiguity and incomplete baseline. */
    public static function evaluate(array $series,array $b,int $start,int $end,bool $frost): array
    {
        $convert=static function($raw)use($b,$frost):?float {
            if ((!is_int($raw)&&!is_float($raw)&&!is_bool($raw))||!is_finite((float)$raw)) return null;
            $v=(float)$raw;
            if ($b['kind']==='binary') return in_array($v,[0.0,1.0],true)?$v:null;
            if (is_bool($raw))return null;
            if ($frost) {
                $unit=mb_strtolower(str_replace(' ','',$b['unit']));
                if (in_array($unit,['°f','f','℉'],true)) $v=($v-32)/1.8;
                elseif (!in_array($unit,['°c','c','℃'],true)) return null;
                if ($v < -100||$v>100) return null;
            }
            return $v;
        };
        $seed=$convert($series['seed']);$previous=$seed===null?null:($frost?$seed<0:$seed>0);
        $partial=!$series['complete']||$previous===null;$cursor=$start;$duration=0;$count=0;$last=null;$min=null;$max=null;$samples=0;
        if ($previous===true&&$frost)$count=1;
        foreach ($series['values'] as $t=>$atTime) {
            if ($previous===true)$duration+=max(0,$t-$cursor);
            $cursor=$t;
            if (count($atTime)!==1) {$previous=null;$partial=true;continue;}
            $v=$convert(reset($atTime));
            if ($v===null) {$previous=null;$partial=true;continue;}
            $samples++;$min=min($min??$v,$v);$max=max($max??$v,$v);
            $state=$frost?$v<0:$v>0;
            if ($state&&($previous===false||($previous===null&&$frost&&$count===0)))$count++;
            if ($state)$last=$t;
            $previous=$state;
        }
        if ($seed!==null) {$min=min($min??$seed,$seed);$max=max($max??$seed,$seed);}
        if ($previous===true)$duration+=max(0,$end-$cursor);
        return compact('count','duration','last','min','max','samples','partial');
    }

    public static function query(array $catalog,string $text,int $archive,float $deadline,?array $scope=null,?DateTimeImmutable $now=null): ?array
    {
        if (!self::historical($text)) return null;
        $q=IAHHouseQuestionContext::normalize($text);$frost=str_contains($q,'frost');
        if (preg_match('/rohr|heizband|pumpenschacht/',$q))return null; // existing energy/plant-health reader
        $watering=preg_match('/gegossen|gewaessert|bewaessert|bewaesserung|gartenpumpe|\bpumpe\b|\bkreis \d+\b/',$q)===1;
        $property=$frost?'temperature':(preg_match('/bodenfeucht|rasen.*(?:nass|feucht)|hecke.*feucht/',$q)?'soilMoisture':(preg_match('/temperatur|kalt|warm|tiefst|hoechst/',$q)?'temperature':''));
        if (!$watering&&$property==='')return null;
        $all=self::bindings($catalog);
        $candidates=array_filter($all,static fn($b):bool=>$watering?$b['domain']==='bewaesserung'&&$b['kind']==='binary':$b['property']===$property);
        if (($scope['type']??'')==='domain') $candidates=array_filter($candidates,static fn($b):bool=>$b['domain']===$scope['domain']);
        elseif (in_array($scope['type']??'',['room','floor'],true)) $candidates=array_filter($candidates,static fn($b):bool=>in_array($b['room'],(array)$scope['rooms'],true));
        if (($frost&&$scope===null)||preg_match('/draussen|aussen/',$q)) $candidates=array_filter($candidates,static fn($b):bool=>$b['ident']==='tempf');
        foreach (['rasen','hecke','beet','ost','sued','west','mitte'] as $name) if (preg_match('/\b'.$name.'\b/',$q)) $candidates=array_filter($candidates,static fn($b):bool=>str_contains(IAHHouseQuestionContext::normalize($b['name']),$name));
        if (preg_match('/\bkreis (\d+)\b/',$q,$z)) $candidates=array_filter($candidates,static fn($b):bool=>($b['zone']??0)===(int)$z[1]);
        if (str_contains($q,'pumpe')) $candidates=array_filter($candidates,static fn($b):bool=>($b['irrigationRole']??'')==='pumpStatus');
        if ($candidates===[])return self::reply('Für diese Archivfrage ist kein passender freigegebener Mess- oder Rückmeldewert zugeordnet.');
        if (!iah_archive_valid($archive)&&function_exists('IPS_GetInstanceListByModuleID')) {
            $ids=IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');if(count($ids)===1)$archive=(int)reset($ids);
        }
        $inventory=iah_archive_inventory($archive);$eligible=array_intersect_key($candidates,$inventory);
        if ($eligible===[]) return self::reply('Für die passenden freigegebenen Werte finde ich keine Archivaufzeichnung. Bitte deren Archivierung in Symcon prüfen.');
        if ($watering&&!str_contains($q,'pumpe')) {
            $zones=array_filter($eligible,static fn($b):bool=>($b['irrigationRole']??'')==='zoneStatus');
            if($zones!==[]){$eligible=$zones;$candidates=array_filter($candidates,static fn($b):bool=>($b['irrigationRole']??'')==='zoneStatus');}
        }
        $now=$now??new DateTimeImmutable('now',new DateTimeZone('Europe/Vienna'));
        $latest=$watering&&!preg_match('/wie oft|wie haeufig|wie lange/',$q);
        [$start,$end,$period]=self::period($q,$now,$latest);
        $results=[];$partial=count($eligible)<count($candidates);
        foreach ($eligible as $id=>$b) {
            if (microtime(true)>=$deadline){$partial=true;break;}
            $series=self::series($archive,(int)$id,$start,$end,$deadline);$r=self::evaluate($series,$b,$start,$end,$frost);
            $partial=$partial||($latest?!$series['complete']:$r['partial']);$results[]=$b+$r;
        }
        $rows=[];$answer='';
        if ($latest) {
            $active=array_filter($results,static fn($r)=>$r['last']!==null);usort($active,static fn($a,$b)=>$b['last']<=>$a['last']);
            if ($active!==[]) {$r=$active[0];$when=$now->setTimestamp($r['last'])->format('d.m.Y H:i');$answer=($partial?'Unter den geprüften Werten wurde ':'Zuletzt wurde ').$r['name'].' am '.$when.' Uhr als aktiv protokolliert.';}
            else $answer=$partial?'Das Bewässerungsarchiv konnte ich nicht vollständig prüfen. Einen letzten Gießvorgang kann ich nicht sicher nennen.':$period.' ist in den ausgewählten Rückmeldungen keine aktive Bewässerung protokolliert.';
        } else foreach ($results as $r) {
            if ($r['min']===null)continue;
            $unit=$frost?'°C':$r['unit'];
            $min=str_replace('.',',',(string)round((float)$r['min'],1));$max=str_replace('.',',',(string)round((float)$r['max'],1));
            if (str_contains($q,'wie lange')&&($frost||$watering)) {
                $minutes=(int)floor($r['duration']/60);
                $answer.=$r['name'].': '.($partial?'mindestens ':'').intdiv($minutes,60).' Stunden '.($minutes%60).' Minuten '.($frost?'Frost':'aktiv').' '.$period.'. ';
            }
            elseif ($frost&&preg_match('/wann|zuletzt/',$q)) {
                $answer.=$r['last']!==null?'Der letzte aufgezeichnete Frostwert im geprüften Zeitraum stammt vom '.$now->setTimestamp($r['last'])->format('d.m.Y H:i').' Uhr. ':$period.' ist kein Frostwert aufgezeichnet. ';
            }
            elseif ($frost) $answer.=$period.' zeigen die '.($partial?'verfügbaren ':'').'Archivwerte '.($partial&&$r['count']===0?'keine belegte Frostphase':(($partial?'mindestens ':'').$r['count'].' Frostphasen')).'. Tiefstwert '.$min.' Grad. ';
            elseif ($watering) $answer.=$r['name'].': '.($partial?'mindestens ':'').$r['count'].' nachgewiesene Einschaltungen '.$period.'. ';
            else $answer.=($partial?'Die verfügbaren Archivwerte von ':'').$r['name'].': '.$min.' bis '.$max.' '.$unit.' '.$period.'. ';
            $rows[]=['label'=>$r['name'],'value'=>$frost?$r['count'].' Frostphasen · Minimum '.$min.' °C':($watering?$r['count'].' Einschaltungen':$min.' bis '.$max.' '.$unit)];
        }
        if ($answer==='')$answer='Im angefragten Zeitraum liegen keine auswertbaren Archivwerte vor.';
        if ($partial&&!$latest)$answer.=($frost||$watering)?' Die Aufzeichnung ist nicht vollständig; die Anzahl ist nur eine Untergrenze.':' Die Aufzeichnung ist nicht vollständig.';
        $rows[]=['label'=>'Zeitraum','value'=>$period];$rows[]=['label'=>'Archivprüfung','value'=>count($results).'/'.count($candidates).' Variablen · '.($partial?'unvollständig':'vollständig')];
        return self::reply(trim($answer),$rows,$watering?'water':'weather')+['historyDiagnostics'=>['ids'=>array_column($results,'id'),'partial'=>$partial,'start'=>$start,'end'=>$end]];
    }
}
