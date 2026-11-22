<?php
declare(strict_types=1);

/** Interpret only published detector feedback and the existing security/window roles. */
final class IAHSecurityQuery
{
    public static function recognizes(string $text): bool
    {
        return preg_match('/\b(?:x ?sense|x ?sens|ex ?sense|brandmelde\w*|rauchmelde\w*|rauchalarm\w*|wassermelde\w*|wasseralarm\w*|sicherheit|sicherhait|sicherheits\w*)\b/',IAHHomeConnect::norm($text))===1;
    }

    private static function reply(string $answer,array $rows,string $title,string $icon,array $diag=[]): array
    {
        return ['handled'=>true,'answer'=>$answer,'answerSource'=>'house_status','localHouseQuery'=>true,
            'displayTitle'=>$title,'displayIcon'=>$icon,'displayRows'=>$rows,'securityDiagnostics'=>$diag];
    }

    private static function when(int $time): string
    {
        return (new DateTimeImmutable('@'.$time))->setTimezone(new DateTimeZone('Europe/Vienna'))->format('d.m.Y').' um '
            .(new DateTimeImmutable('@'.$time))->setTimezone(new DateTimeZone('Europe/Vienna'))->format('H:i').' Uhr';
    }

    private static function names(array $names,int $limit=3): string
    {
        return implode(', ',array_slice(array_unique($names),0,$limit)).(count(array_unique($names))>$limit?' und '.(count(array_unique($names))-$limit).' weitere':'');
    }

    private static function archive(int $id): int
    {
        if(iah_archive_valid($id))return $id;
        if(function_exists('IPS_GetInstanceListByModuleID')){
            $ids=IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
            if(count($ids)===1&&iah_archive_valid((int)reset($ids)))return (int)reset($ids);
        }
        return 0;
    }

    /** All windows, including explicitly enabled windows whose feedback is missing. */
    private static function otherSecurity(array $catalog,array &$rows,array &$alarms,array &$warnings,array &$diag,?array $scope=null): array
    {
        $windows=[];$open=[];$unknown=[];
        foreach(iah_rc_index_catalog($catalog,false,true) as $dev){
            if(($dev['provider']??'')==='xsense')continue;
            if(in_array($scope['type']??'',['room','floor'],true)&&!in_array($dev['roomKey'],(array)$scope['rooms'],true))continue;
            $type=$dev['type']??'';
            if(!in_array($type,['window','door','smoke','water_leak','alarm','lock','camera','motion'],true)
                &&($dev['domain']??'')!=='sicherheit')continue;
            try{$state=iah_rc_state($dev);}catch(Throwable $ignored){$state=['properties'=>[]];}
            $props=(array)($state['properties']??[]);$parts=[];
            if(in_array($type,['window','door'],true)){
                $closure=iah_ai_closure_feedback($state);
                if(($props['online']??null)===false)$closure=null;
                if($type==='window'){
                    $windows[]=$dev;
                    if($closure==='offen')$open[]=$dev['name'];elseif($closure!=='geschlossen')$unknown[]=$dev['name'];
                }
                if($closure==='offen')$warnings[]=$dev['name'].' ist offen';
                elseif($closure===null)$warnings[]=$dev['name'].': Schließzustand unklar';
                $parts[]=$closure??'Schließzustand unklar';
            }
            foreach(['alarm','waterLeak','error'] as $role)if(array_key_exists($role,$props)){
                $v=$props[$role];
                if($v===true){$alarms[]=$dev['name'].' meldet '.($role==='waterLeak'?'Wasser':($role==='error'?'einen Fehler':'Alarm'));$parts[]='Meldung aktiv';}
                elseif($v===false)$parts[]='Keine '.match($role){'waterLeak'=>'Wassermeldung','error'=>'Fehlermeldung',default=>'Alarmmeldung'};
                else{$warnings[]=$dev['name'].': Meldestatus unklar';$parts[]='Meldestatus unklar';}
            }
            if(array_key_exists('locked',$props)){
                if($props['locked']===false)$warnings[]=$dev['name'].' ist nicht verriegelt';
                $parts[]=$props['locked']===true?'Verriegelt':($props['locked']===false?'Nicht verriegelt':'Verriegelung unklar');
            }
            if(array_key_exists('armed',$props)){
                $parts[]=$props['armed']===true?'Scharf':($props['armed']===false?'Unscharf':'Scharfschaltung unklar');
                if($props['armed']===false)$warnings[]=$dev['name'].' ist unscharf';
            }
            if($type==='motion'&&isset($props['motion']))$parts[]=in_array($props['motion'],[true,'an'],true)?'Bewegung gemeldet':(in_array($props['motion'],[false,'aus'],true)?'Keine Bewegung gemeldet':'Bewegungsstatus unklar');
            if(($props['online']??null)===false){$warnings[]=$dev['name'].' ist nicht erreichbar';$parts[]='Nicht erreichbar';}
            if(isset($props['batteryLevel'])&&!is_bool($props['batteryLevel'])&&is_numeric($props['batteryLevel'])&&$props['batteryLevel']<20)$warnings[]=$dev['name'].': Batterie niedrig';
            if($parts===[])$parts[]='Kein eindeutig auswertbarer Sicherheitsstatus';
            $rows[]=['label'=>$dev['name'],'value'=>implode(' · ',array_unique($parts))];
        }
        $diag['windows']=['count'=>count($windows),'open'=>$open,'unknown'=>$unknown];
        return $windows;
    }

    public static function windowState(mixed $raw,array $dev,int $id,string $role): ?bool
    {
        if((IPS_GetVariable($id)['VariableType']??-1)===0&&(is_int($raw)||is_float($raw))&&in_array((float)$raw,[0.0,1.0],true))$raw=(bool)$raw;
        foreach(['open'=>true,'closed'=>false] as $key=>$value)if(array_key_exists($key,(array)$dev['stateMapping'])){
            $expected=$dev['stateMapping'][$key];
            if($expected===$raw||(!is_bool($expected)&&!is_bool($raw)&&is_numeric($expected)&&is_numeric($raw)&&(float)$expected===(float)$raw))return $value;
        }
        if(array_key_exists('open',(array)$dev['stateMapping'])||array_key_exists('closed',(array)$dev['stateMapping']))return null;
        $label=iah_ai_profile_label($id,$raw);$closure=iah_ai_closure_label($label??(is_string($raw)?$raw:''));
        if($closure!==null)return $closure==='offen';
        if($label===null&&$role==='open'&&(IPS_GetVariable($id)['VariableType']??-1)===0&&is_bool($raw))return $raw;
        return null;
    }

    /** Uses the shared paginated archive reader, with per-binding normalization. */
    private static function history(array $bindings,int $archive,int $start,int $end,float $deadline): array
    {
        $inventory=iah_archive_inventory($archive);$out=[];$partial=false;$seen=[];
        foreach($bindings as $b){
            $id=(int)$b['id'];if(isset($seen[$id]))continue;$seen[$id]=true;
            if(!isset($inventory[$id])||microtime(true)>=$deadline){$partial=true;continue;}
            $s=IAHSystemHistory::series($archive,$id,$start,$end,$deadline);
            $convert=static function($v)use($b,$id):?bool{
                return isset($b['window'])?self::windowState($v,$b['window'],$id,$b['role']):IAHXSense::archiveBinary($v,$b['metric']);
            };
            $s['seed']=$convert($s['seed']);$last=null;$invalid=false;
            foreach($s['values'] as $t=>&$values){
                $converted=[];foreach($values as $v){$v=$convert($v);if($v===null)$invalid=true;$converted[$v===null?'unknown':($v?'true':'false')]=$v;}
                $values=$converted;if(count($values)!==1)$invalid=true;
                if(count($values)===1&&reset($values)===true)$last=$t;
            }unset($values);
            $r=IAHSystemHistory::evaluate($s,['kind'=>'binary','unit'=>''], $start,$end,false);
            $out[]=['name'=>$b['name'],'last'=>$last,'count'=>$r['count'],'partial'=>$r['partial'],'id'=>$id];
            $partial=$partial||!$s['complete']||$invalid;
        }
        $latest=null;
        foreach($out as $r)if($r['last']!==null&&($latest===null||$r['last']>$latest['last']))$latest=$r;
        return ['latest'=>$latest,'results'=>$out,'partial'=>$partial,'matched'=>count($seen),'checked'=>count($out)];
    }

    private static function batteryHistory(array $items,int $archive,float $deadline,array &$rows,string $text): string
    {
        $inventory=iah_archive_inventory($archive);$deltas=[];
        $end=time();$start=$end-30*24*60*60;
        foreach($items as $item){
            $id=(int)$item['metric']['id'];if(!isset($inventory[$id])||microtime(true)>=$deadline)continue;
            $s=IAHSystemHistory::series($archive,$id,$start,$end,$deadline);$points=[];
            foreach($s['values'] as $at=>$values){
                if(count($values)!==1)continue;$v=reset($values);
                if(!is_bool($v)&&is_numeric($v)&&is_finite((float)$v)&&$v>=0&&$v<=100)$points[$at]=(float)$v;
            }
            if(count($points)<2)continue;
            $first=(int)array_key_first($points);$last=(int)array_key_last($points);if($last<=$first)continue;
            $drop=reset($points)-end($points);$span=round(($last-$first)/(24*60*60),1);
            $label=$drop>0?'−'.$drop.' Prozentpunkte':($drop<0?'+'.abs($drop).' Prozentpunkte':'Unverändert');
            $rows[]=['label'=>$item['name'].' · Batterieverlauf','value'=>$label.' zwischen '.self::when($first).' und '.self::when($last).(!$s['complete']?' · Teilauswertung':'')];
            $deltas[]=['name'=>$item['name'],'drop'=>$drop,'rate'=>$drop/max($span,0.01),'span'=>$span,'last'=>$last,'complete'=>$s['complete']];
        }
        if($deltas===[])return ' Für einen Batterievergleich im Verlauf fehlen ausreichend archivierte Messpunkte.';
        if(preg_match('/schnell|abgenommen|gefallen|verloren|verbraucht/',IAHHomeConnect::norm($text))){
            usort($deltas,static fn($a,$b)=>$b['rate']<=>$a['rate']);$d=$deltas[0];
            return $d['drop']>0?' Unter den auswertbaren Aufzeichnungen zeigt '.$d['name'].' den größten mittleren Rückgang: '.$d['drop'].' Prozentpunkte in '.$d['span'].' Tagen. Die Messzeiträume können sich unterscheiden.':' In den auswertbaren Aufzeichnungen ist kein Rückgang belegt.';
        }
        return ' Den aufgezeichneten Batterieverlauf der letzten 30 Tage findest du auf der Statusseite; daraus lässt sich keine verbleibende Lebensdauer ableiten.';
    }

    public static function query(array $catalog,string $text,int $archive,float $deadline,?array $scope=null): ?array
    {
        $q=IAHHomeConnect::norm($text);
        $lastWindow=preg_match('/fenster/',$q)&&preg_match('/wann|zuletzt|zum schluss/',$q);
        if(!self::recognizes($text)&&!$lastWindow)return null;
        $general=preg_match('/sicherheit|sicherhait|sicherheits/',$q)===1;
        $water=preg_match('/wassermeld|wasseralarm/',$q)===1;
        $fire=preg_match('/brandmeld|rauchmeld|rauchalarm/',$q)===1;
        $battery=preg_match('/batterie|akku/',$q)===1;
        $title=$general?'Sicherheit im Haus':($battery?'X-Sense · Batterien':($water?'Wassermelder':($fire?'Brandmeldestation':($lastWindow?'Fenster · Archiv':'X-Sense'))));
        $icon=$general||$lastWindow?'shield':($battery?'battery':($water?'water':'smoke'));
        $devices=IAHXSense::devices($catalog);
        if($lastWindow&&!$general)$devices=[];
        $selected=(int)(($scope['type']??'')==='household_device'?($scope['deviceId']??0):0);
        if($selected>0)$devices=array_filter($devices,static fn($d):bool=>$d['id']===$selected);
        else{
            $named=array_filter($devices,static function($d)use($q):bool{
                foreach(array_merge([$d['name']],(array)$d['aliases']) as $name){$n=IAHHomeConnect::norm($name);if($n!==''&&str_contains($q,$n))return true;}return false;
            });
            if($named!==[])$devices=$named;
            elseif(!$general&&($water||$fire))$devices=array_filter($devices,static fn($d):bool=>in_array($d['kind'],[$water?'water':'smoke','base'],true));
        }
        if(($scope['type']??'')==='room'||($scope['type']??'')==='floor')$devices=array_filter($devices,static fn($d):bool=>in_array($d['roomKey'],(array)$scope['rooms'],true));
        $rows=[];$alarms=[];$warnings=[];$batteries=[];$alarmBindings=[];$counts=['smoke'=>0,'water'=>0,'base'=>0,'unknown'=>0];$diag=[];$allClear=0;
        foreach($devices as $d){
            $kind=$d['kind'];if($kind==='unknown')$warnings[]=$d['name'].': Geräteart ungeklärt';$counts[$kind]=($counts[$kind]??0)+1;$v=IAHXSense::values($d);$parts=[];
            $offline=(int)(IPS_GetInstance($d['id'])['InstanceStatus']??0)!==102;
            foreach(['online','connected'] as $r)if(isset($v[$r])&&$v[$r]['raw']!==true)$offline=true;
            $r=match($kind){'smoke'=>'smoke','water'=>'waterLeak',default=>'alarm'};$state=$v[$r]['raw']??null;
            if(!empty($v[$r]['anyActive']))$state=true;
            if(!empty($v[$r]['conflict']))$warnings[]=$d['name'].': widersprüchliche Alarmrückmeldungen';
            $status=$state===true?($kind==='water'?'Wasser gemeldet':($kind==='smoke'?'Rauch gemeldet':'Alarm gemeldet')):($state===false?($kind==='water'?'Trocken gemeldet':($kind==='smoke'?'Kein Rauch gemeldet':'Kein Basisalarm gemeldet')):'Alarmstatus unklar');
            if($state===true)$alarms[]=$d['name'].': '.$status;elseif($state===false&&!$offline)$allClear++;elseif($kind!=='unknown')$warnings[]=$d['name'].': Alarmstatus unklar';
            $parts[]=$status;
            if($offline){$warnings[]=$d['name'].' ist nicht verbunden';$parts[]='Nicht verbunden';}
            elseif(!isset($v['online'])&&!isset($v['connected'])){$warnings[]=$d['name'].': Erreichbarkeit unbestätigt';$parts[]='Erreichbarkeit unbestätigt';}
            $reported=$v['reportTime']['raw']??null;
            if(isset($v['reportTime'])&&(!is_int($reported)||$reported<=0||$reported>time()+5*60)){$warnings[]=$d['name'].': Geräte-Meldungszeit unklar';$parts[]='Meldungszeit unklar';}
            if(is_int($reported)&&$reported>0&&$reported<time()-48*60*60){$warnings[]=$d['name'].': letzte Sensormeldung älter als 48 Stunden';$parts[]='Sensormeldung alt';}
            foreach(['lifeEnd'=>'Lebensdauer beendet','batteryLow'=>'Batteriewarnung','muted'=>'Stummgeschaltet','testActive'=>'Test aktiv'] as $role=>$label)if(!empty($v[$role]['anyActive'])){$warnings[]=$d['name'].': '.$label;$parts[]=$label;}
            if(isset($v['status'])&&is_string($v['status']['raw'])&&!in_array(IAHHomeConnect::norm($v['status']['raw']),['normal','ok','ready','bereit'],true)){$warnings[]=$d['name'].': Gerätestatus prüfen';$parts[]='Gerätestatus prüfen';}
            $b=$v['batteryLevel']??null;
            if($b!==null&&$b['raw']!==null){$batteries[]=['name'=>$d['name'],'value'=>$b['raw'],'metric'=>$b['metric']];$parts[]='Batterie '.$b['raw'].' %';if($b['raw']<20)$warnings[]=$d['name'].': Batterie unter 20 Prozent';}
            elseif($battery&&$kind!=='base')$parts[]='Batteriestand unbekannt';
            $rows[]=['label'=>$d['name'],'value'=>implode(' · ',$parts)];
            if(isset($v[$r])&&!$v[$r]['conflict'])$alarmBindings[]=['id'=>$v[$r]['metric']['id'],'name'=>$d['name'],'metric'=>$v[$r]['metric']];
            if(preg_match('/alle (?:werte|details)|einzelwerte/',$q))foreach($d['variables'] as $m){
                if(empty($m['enabled']))continue;$rows[]=['label'=>$d['name'].' · '.$m['title'],'value'=>IAHXSense::format($m,IAHXSense::reading($m,$d['id']))];
            }
        }
        $windows=($general||$lastWindow)?self::otherSecurity($catalog,$rows,$alarms,$warnings,$diag,$scope):[];
        $archive=self::archive($archive);$now=new DateTimeImmutable('now',new DateTimeZone('Europe/Vienna'));
        $diag['detectors']=$counts;$diag['alarms']=$alarms;$diag['warnings']=$warnings;
        if($battery){
            usort($batteries,static fn($a,$b)=>$a['value']<=>$b['value']);
            if($batteries===[])$answer='Für die ausgewählten X-Sense-Geräte ist kein eindeutig auswertbarer Batteriestand in Prozent freigegeben.';
            else{$minimum=$batteries[0]['value'];$lowest=array_filter($batteries,static fn($b)=>$b['value']===$minimum);
                $answer=(count($lowest)===count($batteries)&&count($batteries)>1?'Alle '.count($batteries).' auswertbaren Melder melden denselben Ladestand von '.$minimum.' Prozent.':self::names(array_column($lowest,'name')).' '.(count($lowest)>1?'melden':'meldet').' den niedrigsten Ladestand: '.$minimum.' Prozent.');
                $missing=$counts['smoke']+$counts['water']-count($batteries);if($missing>0)$answer.=' Bei '.$missing.' Meldern fehlt ein vergleichbarer Prozentwert.';
                $answer.=self::batteryHistory($batteries,$archive,$deadline,$rows,$text);
            }
            if($warnings!==[])$answer.=' Hinweise: '.self::names($warnings,2).'.';
        }else{
            $answer=$alarms!==[]?'Achtung: '.self::names($alarms).'.':($allClear>0?'Die auswertbaren X-Sense-Rückmeldungen enthalten keinen Rauch-, Wasser- oder Basisalarm.':'Für X-Sense liegt kein eindeutig auswertbarer freigegebener Alarmstatus vor.');
            if($devices===[]&&!$general&&!$lastWindow)$answer='Keine passenden X-Sense-Geräte für Alexa veröffentlicht. Bitte den X-Sense-Abgleich in EDIT prüfen und nach PROD übernehmen.';
            if($warnings!==[])$answer.=' Prüfen: '.self::names($warnings).'.';
            elseif($devices!==[])$answer.=' Erfasst sind '.$counts['smoke'].' Rauchmelder, '.$counts['water'].' Wassermelder und '.$counts['base'].' Basisstationen.';
            if($lastWindow)$answer='';
            if($general||$lastWindow){$w=$diag['windows'];$answer.=$w['count']===0?' Es sind keine Fensterkontakte für den Bericht freigegeben.':($w['open']!==[]?' Offen: '.self::names($w['open']).'.':($w['unknown']!==[]?' Bei '.count($w['unknown']).' Fenstern ist der Zustand unklar.':' Alle '.$w['count'].' erfassten Fenster sind geschlossen.'));}
            if(($general&&$windows!==[]&&empty($diag['windows']['open'])&&empty($diag['windows']['unknown']))||$lastWindow){
                $bindings=[];
                foreach($windows as $dev)foreach(['open','status'] as $role){$id=(int)($dev['reads'][$role]??0);if($id>0){$bindings[]=['id'=>$id,'name'=>$dev['name'],'window'=>$dev,'role'=>$role];break;}}
                $h=self::history($bindings,$archive,$now->modify('-365 days')->getTimestamp(),$now->getTimestamp(),$deadline);
                if($h['latest']!==null){$last=$h['latest'];$sentence=($h['partial']?'Unter den geprüften Werten zuletzt offen protokolliert: ':'Zuletzt offen protokolliert war ').$last['name'].' am '.self::when($last['last']).'.';}
                else $sentence='Wann zuletzt ein Fenster offen war, lässt sich aus den verfügbaren Archivwerten der letzten 365 Tage nicht belegen.';
                $answer.=' '.$sentence;$rows[]=['label'=>'Fenster zuletzt offen · Archiv','value'=>$sentence];$diag['windowHistory']=$h;
            }
            if(!$lastWindow&&$alarmBindings!==[]){
                $historical=preg_match('/wann|zuletzt|wie oft|gestern|verlauf|alarm.*(?:heute|tage)/',$q)===1;
                if($historical)[$start,$end,$period]=IAHSystemHistory::period($q,$now,true);
                else{$start=$now->modify('-30 days')->getTimestamp();$end=$now->getTimestamp();$period='Letzte 30 Tage';}
                $h=self::history($alarmBindings,$archive,$start,$end,$deadline);$diag['alarmHistory']=$h;
                $rows[]=['label'=>'Alarmarchiv · Abdeckung','value'=>$h['checked'].'/'.$h['matched'].' Variablen · '.($h['partial']?'Teilauswertung':'ausgelesen')];
                if(preg_match('/wie oft|wie viele alarme/',$q)){
                    $sentences=[];foreach($h['results'] as $r){if($r['count']>0)$sentences[]=$r['name'].': '.($r['partial']?'mindestens ':'').$r['count'].' nachgewiesene Alarmstarts';$rows[]=['label'=>$r['name'].' · Alarmstarts','value'=>($r['partial']?'Mindestens ':'').$r['count'].' · '.$period];}
                    $answer.=' '.($sentences!==[]?self::names($sentences).'.':($h['checked']>0?'Keine Alarmstarts in den auswertbaren Zustandswechseln belegt.':'Keine ausreichenden Archivaufzeichnungen für eine Alarmzählung.'));
                    if($h['checked']<$h['matched'])$answer.=' Nicht alle passenden Melder haben auswertbare Aufzeichnungen.';
                }elseif($h['latest']!==null){$sentence=($h['partial']?'Letzte gefundene':'Letzte protokollierte').' Alarmmeldung: '.$h['latest']['name'].' am '.self::when($h['latest']['last']).'.';$answer.=' '.$sentence;$rows[]=['label'=>'Alarmarchiv','value'=>$sentence];}
                else{$sentence=$h['checked']===0?'Keine passenden Alarmaufzeichnungen verfügbar.':($h['partial']?'Im teilweise geprüften Archiv keine aktive Alarmmeldung gefunden.':'Im geprüften Zeitraum keine aktive Alarmmeldung aufgezeichnet.');$answer.=' '.$sentence;$rows[]=['label'=>'Alarmarchiv · '.$period,'value'=>$sentence];}
            }
        }
        if(preg_match('/^(?:bitte )?(?:schalte|teste|starte|stoppe|deaktiviere|aktiviere|setze|stelle|mute|stumm)/',$q))$answer='X-Sense ist hier ausschließlich zum Abfragen eingebunden. Es wurde kein Test, Stummschalten oder Gerätebefehl ausgeführt. '.$answer;
        return self::reply($answer,$rows,$title,$icon,$diag);
    }
}
