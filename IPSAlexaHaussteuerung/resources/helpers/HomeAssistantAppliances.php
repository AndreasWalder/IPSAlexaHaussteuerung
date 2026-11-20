<?php
declare(strict_types=1);

/** Recognize proven Home Assistant appliance schemas, without site-specific IDs. */
final class IAHHomeAssistantAppliances
{
    public const MODULE_ID='{72D6A284-1870-4E11-92D8-0402C8233C29}';

    public static function kind(int $id): ?string
    {
        if (!IPS_InstanceExists($id) || strtoupper((string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??''))!==self::MODULE_ID) return null;
        $idents=[];
        foreach (IPS_GetChildrenIDs($id) as $child) {
            $o=IPS_GetObject($child);
            if ((int)$o['ObjectType']===2) $idents[(string)($o['ObjectIdent']??'')]=true;
        }
        if (isset($idents['sensor_cyclephase'],$idents['sensor_connectivitystate'],$idents['sensor_timetoend'],$idents['select_userselections_programuid'])) return 'ha_laundry';
        if (isset($idents['sensor_status'],$idents['number_sollwert'],$idents['switch_superfrost'])) return 'ha_freezer';
        if (isset($idents['sensor_status'],$idents['number_sollwert'],$idents['switch_supercool'])) return 'ha_fridge';
        return null;
    }

    /** Only metadata and bindings are discovered; no configuration or values copied into PROD. */
    public static function discover(): array
    {
        if (!function_exists('IPS_GetInstanceListByModuleID')) return [];
        $found=[]; $ids=IPS_GetInstanceListByModuleID(self::MODULE_ID); sort($ids,SORT_NUMERIC);
        foreach (array_slice($ids,0,100) as $id) {
            try {
                $kind=self::kind((int)$id); if ($kind===null) continue;
                $o=IPS_GetObject($id); $name=IAHHomeConnect::clean((string)$o['ObjectName']);
                if ($kind==='ha_laundry' && IAHHomeConnect::norm($name)==='waschtrockner' && (int)$o['ParentID']>0)
                    $name=IAHHomeConnect::clean((string)IPS_GetObject((int)$o['ParentID'])['ObjectName']).' '.$name;
                $aliases=match($kind) {
                    'ha_laundry'=>[$name,str_replace('Waschtrockner','Waschmaschine',$name),str_replace('Waschtrockner','Trockner',$name),'Waschtrockner','Waschmaschine'],
                    'ha_freezer'=>['Gefrierschrank','Tiefkühler','Tiefkühlschrank','Gefriergerät'],
                    default=>['Kühlschrank','Kühlgerät'],
                };
                $d=['id'=>(int)$id,'parent'=>(int)$o['ParentID'],'moduleId'=>self::MODULE_ID,
                    'name'=>$name,'aliases'=>array_values(array_unique(array_merge([$name],$aliases))),
                    'provider'=>'HomeAssistant','type'=>$kind,'enabled'=>true,'allowControl'=>false,
                    'roomKey'=>'__household__','roomLabel'=>'Haus','floor'=>'—','group'=>'hausgeraete','variables'=>[]];
                foreach (IPS_GetChildrenIDs($id) as $child) {
                    $v=IPS_GetObject($child); if ((int)$v['ObjectType']!==2) continue;
                    $m=IAHHouseholdDevices::metric((int)$child);
                    [$title,$role]=self::definition($m['ident'],$m['title']);
                    $m['title']=$title; $m['role']=$role;
                    $d['variables']['var_'.$child]=$m;
                }
                $found[]=$d;
            } catch (Throwable $ignored) {}
        }
        return $found;
    }

    public static function definition(string $ident,string $fallback): array
    {
        $known=[
            'sensor_status'=>['Isttemperatur','temperature'],'number_sollwert'=>['Solltemperatur','temperature'],
            'reachable'=>['Home Assistant erreichbar','status'],'switch_superfrost'=>['SuperFrost','other'],
            'switch_supercool'=>['SuperCool','other'],'switch_partymodus'=>['Partymodus','other'],
            'switch_nachtmodus'=>['Nachtmodus','other'],'select_eiswurfelbereiter'=>['Eiswürfelbereiter','other'],
            'sensor_cyclephase'=>['Programmphase','status'],'sensor_cyclesubphase'=>['Programmschritt','status'],
            'sensor_connectivitystate'=>['Geräteverbindung','status'],'sensor_remotecontrol'=>['Fernsteuerung','status'],
            'sensor_timetoend'=>['Restzeit','remaining'],'sensor_alerts'=>['Meldungen','event'],
            'binary_sensor_doorstate'=>['Tür','status'],'select_userselections_programuid'=>['Ausgewähltes Programm','program'],
            'select_userselections_analogtemperature'=>['Waschtemperatur','temperature'],
            'select_userselections_analogspinspeed'=>['Schleuderdrehzahl','other'],
            'sensor_totalcyclecounter'=>['Programmzähler gesamt','other'],'sensor_totalwashcyclescount'=>['Waschgänge','other'],
            'sensor_totaldrycyclescount'=>['Trockengänge','other'],'sensor_totalwashdrycyclescount'=>['Wasch-/Trockengänge','other'],
            'sensor_totalwashingtime'=>['Waschzeit gesamt','other'],'sensor_totaldryingtime'=>['Trockenzeit gesamt','other'],
            'sensor_appliancetotalworkingtime'=>['Betriebszeit gesamt','other'],'number_starttime'=>['Startverzögerung','other'],
            'number_userselections_dryingtime'=>['Trockenzeit','other'],'switch_uilockmode'=>['Bediensperre','other'],
            'switch_userselections_wetmode'=>['Waschen','other'],'switch_userselections_drymode'=>['Trocknen','other'],
            'switch_userselections_nightcycle'=>['Nachtprogramm','other'],'switch_userselections_prewashphase'=>['Vorwäsche','other'],
            'switch_userselections_rinsehold'=>['Spülstopp','other'],'switch_userselections_stain'=>['Fleckenoption','other'],
            'switch_userselections_wmeconomy'=>['Eco-Option','other'],'switch_userselections_anticreasenosteam'=>['Knitterschutz','other'],
            'select_defaultextrarinse'=>['Extraspülen','other'],'select_endofcyclesound'=>['Signal bei Programmende','other'],
            'select_userselections_steamvalue'=>['Dampfoption','other'],'select_userselections_humiditytarget'=>['Trockenziel','other'],
            'select_userselections_timemanagerlevel'=>['Zeitmanager','other'],'sensor_measuredloadweight'=>['Gemessene Beladung','other'],
            'sensor_washingnominalloadweight'=>['Nennbeladung Waschen','other'],'sensor_dryingnominalloadweight'=>['Nennbeladung Trocknen','other'],
        ];
        if (str_starts_with($ident,'button_')) $fallback=match($fallback) {
            'OFF'=>'Ausschalten (Befehlstaste)','OFF ON'=>'Einschalten (Befehlstaste)',
            'OFF START'=>'Start (Befehlstaste)','OFF PAUSE'=>'Pause (Befehlstaste)',
            'OFF RESUME'=>'Fortsetzen (Befehlstaste)','OFF STOPRESET'=>'Stopp/Zurücksetzen (Befehlstaste)',default=>$fallback,
        };
        return $known[$ident]??[$fallback,'other'];
    }

    public static function readings(array $d): array
    {
        if (self::kind((int)$d['id'])!==($d['type']??null)) return [];
        $result=[];
        foreach ((array)$d['variables'] as $m) {
            if (empty($m['enabled'])) continue;
            $r=IAHHouseholdDevices::reading($m);
            if ($r!==null && (int)$r['parent']===(int)$d['id']) $result[$m['ident']]=$r+['binding'=>$m];
        }
        return $result;
    }

    public static function format(array $r): string
    {
        $v=$r['raw']; $ident=$r['ident']; $q=IAHHomeConnect::norm((string)$v);
        if (str_starts_with($ident,'button_')) return 'Befehlstaste – Auslösewert noch ungeklärt';
        $sentinel=str_contains($ident,'loadweight')||in_array($ident,['number_userselections_dryingtime','sensor_timetoend','sensor_status','number_sollwert'],true);
        if (($sentinel&&is_numeric($v)&&(float)$v===(float)((256*256)-1)) || in_array($q,['not available','unavailable','unknown','none'],true)) return 'Nicht verfügbar';
        if ($ident==='binary_sensor_doorstate') return $v===true?'Offen':'Geschlossen';
        if ($ident==='reachable') return $v===true?'Ja':'Nein';
        if (is_bool($v)) return $v?'Ein':'Aus';
        if (in_array($ident,['sensor_status','number_sollwert'],true) && is_numeric($v)) return str_replace('.',',',(string)$v).' °C';
        if ($ident==='sensor_alerts' && is_numeric($v)) return (float)$v===0.0?'Keine Meldung':(string)$v.' (Meldungscode)';
        if ($ident==='sensor_timetoend') {
            if (is_numeric($v)&&(float)$v===0.0) return 'Keine laufende Restzeit gemeldet';
            return (string)$v.' (Zeiteinheit noch ungeklärt)';
        }
        if (preg_match('/^sensor_(?:totalwashingtime|totaldryingtime|appliancetotalworkingtime)$/D',$ident) && is_numeric($v)) {
            $seconds=max(0,(int)$v); return intdiv($seconds,3600).' h '.intdiv($seconds%3600,60).' min';
        }
        $labels=['disconnected'=>'Nicht verbunden','connected'=>'Verbunden','spin'=>'Schleudern','wash'=>'Waschen',
            'rinse'=>'Spülen','dry'=>'Trocknen','idle'=>'Bereit','off'=>'Aus','on'=>'Ein','paused'=>'Pausiert',
            'extra rinse off'=>'Extraspülen aus','steam off'=>'Dampf aus','short sound'=>'Kurzer Signalton',
            'cupboard'=>'Schranktrocken','normal'=>'Normal','not safety relevant enabled'=>'Freigegeben (ohne Startbestätigung)',
            'drum clean pr machineclean'=>'Trommelreinigung'];
        if (isset($labels[$q])) return $labels[$q];
        if (preg_match('/^(\d+) celsius$/D',$q,$m)) return $m[1].' °C';
        if (preg_match('/^(\d+) rpm$/D',$q,$m)) return $m[1].' U/min';
        return IAHHomeConnect::display($r);
    }

    public static function query(array $d,string $text,bool $allowWrites): array
    {
        $q=IAHHomeConnect::norm($text); $values=self::readings($d);
        $kind=($d['type']??'')==='ha_laundry'?'laundry':(($d['type']??'')==='ha_freezer'?'freezer':'fridge');
        $command=preg_match('/^(?:bitte )?(?:schalte|stelle|setze|starte|stoppe|pausiere)\b/',$q)===1;
        $rows=[];
        if ($command) {
            $answer='Diese Gerätefunktion ist nicht eindeutig zum Schalten freigegeben.';
            $online=($values['reachable']['raw']??false)===true
                && (int)(IPS_GetInstance((int)$d['id'])['InstanceStatus']??0)===102
                && ($kind!=='laundry'||IAHHomeConnect::norm((string)($values['sensor_connectivitystate']['raw']??''))==='connected');
            if (!$online) $answer='Das Gerät ist nicht verbunden. Der Auftrag wurde nicht gesendet.';
            if ($online && $allowWrites && !empty($d['allowControl']) && empty($d['approximate'])
                && preg_match('/^(?:bitte )?schalte (.+) (ein|an|aus)$/D',$q,$m)) {
                $target=$m[1];
                foreach (array_merge([$d['name']],(array)$d['aliases']) as $alias) {
                    $a=IAHHomeConnect::norm((string)$alias);
                    if ($a!=='') $target=preg_replace('/\b'.preg_quote($a,'/').'\b/',' ',$target)??$target;
                }
                $target=trim(preg_replace('/\b(?:den|die|das|dem|der|am|im|vom|beim|des|meinen|meine|meinem)\b/',' ',$target)??$target);
                $target=trim(preg_replace('/\s+/',' ',$target)??$target);
                $candidates=[];
                foreach ($values as $ident=>$r) {
                    if (!str_starts_with($ident,'switch_')||$r['type']!==0||empty($r['binding']['writable'])||!$r['action']) continue;
                    if (str_replace(' ','',IAHHomeConnect::norm($r['name']))===str_replace(' ','',$target)) $candidates[]=$r;
                }
                if (count($candidates)===1) {
                    try { $ok=RequestAction($candidates[0]['id'],$m[2]!=='aus'); $answer=$ok===false?'Auftrag abgelehnt.':'Auftrag für '.$d['name'].' übergeben.'; }
                    catch (Throwable $ignored) { $answer='Der Auftrag konnte nicht übergeben werden.'; }
                }
            }
            return self::reply($d,$kind,[['label'=>'Auftrag','value'=>$answer]],$answer)+['isCommand'=>true];
        }
        $details=preg_match('/alle werte|alle details|details|alles ueber/',$q)===1;
        $wanted=match(true) {
            $details=>array_keys($values),
            preg_match('/temperatur|wie warm|wie kalt/',$q)===1=>$kind==='laundry'?['select_userselections_analogtemperature']:['sensor_status','number_sollwert'],
            preg_match('/restzeit|wie lange|wann.*fertig|braucht.*noch/',$q)===1=>['sensor_connectivitystate','sensor_cyclephase','sensor_timetoend'],
            preg_match('/tuer|offen|geschlossen/',$q)===1=>['binary_sensor_doorstate'],
            preg_match('/meldung|alarm|stoerung/',$q)===1=>['sensor_alerts','sensor_connectivitystate','reachable'],
            preg_match('/zaehler|wie viele|wieviele|gesamt|betriebszeit/',$q)===1=>['sensor_totalcyclecounter','sensor_totalwashcyclescount','sensor_totaldrycyclescount','sensor_totalwashdrycyclescount','sensor_appliancetotalworkingtime','sensor_totalwashingtime','sensor_totaldryingtime'],
            default=>$kind==='laundry'?['sensor_connectivitystate','sensor_cyclephase','select_userselections_programuid','sensor_timetoend','binary_sensor_doorstate','sensor_alerts']
                :['sensor_status','number_sollwert','reachable','switch_superfrost','switch_supercool','switch_nachtmodus','switch_partymodus','select_eiswurfelbereiter'],
        };
        if (!$details) {
            $named=[];
            foreach ($values as $ident=>$r) {
                if (!str_starts_with($ident,'switch_')&&$ident!=='select_eiswurfelbereiter') continue;
                $named[]=['id'=>$r['id'],'name'=>$r['name'],'aliases'=>[$r['name']],'ident'=>$ident];
            }
            $specific=IAHHomeConnect::select($named,$q);
            if (count($specific)===1) $wanted=[$specific[0]['ident']];
        }
        foreach ($wanted as $ident) {
            if (!isset($values[$ident])) continue;
            $r=$values[$ident]; $label=$r['name'];
            if ($ident==='sensor_cyclephase' && IAHHomeConnect::norm((string)($values['sensor_connectivitystate']['raw']??''))!=='connected') $label='Letzte gemeldete Programmphase';
            $rows[]=['label'=>$label,'value'=>self::format($r)];
        }
        if ($rows===[]) $rows[]=['label'=>'Information','value'=>'Kein freigegebener passender Wert verfügbar'];
        $result=self::reply($d,$kind,$rows);
        if ($kind==='laundry' && preg_match('/\bfertig\b/',$q)) $result['answer']='Ein erfolgreiches Programmende ist mit den verfügbaren Werten nicht bestätigt. '.$result['answer'];
        if ($kind==='laundry') $result['preserveLocalAnswer']=true;
        return $result;
    }

    private static function reply(array $d,string $kind,array $rows,?string $answer=null): array
    {
        return ['handled'=>true,'localHouseQuery'=>true,'answerSource'=>'home_connect_'.$kind,
            'displayTitle'=>$d['name'],'displayRows'=>$rows,'answer'=>$answer??$d['name'].': '.implode('. ',array_map(
                static fn($r):string=>$r['label'].' '.$r['value'],array_slice($rows,0,5))).'.'];
    }
}
