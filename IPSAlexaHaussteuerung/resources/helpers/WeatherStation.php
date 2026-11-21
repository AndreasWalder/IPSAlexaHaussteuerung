<?php
declare(strict_types=1);

if (!class_exists('IAHHouseholdDevices', false)) require_once __DIR__.'/HouseholdDevices.php';

/** Froggit/Ecowitt measurements, shared by EDIT discovery and read-only Alexa queries. */
final class IAHWeatherStation
{
    public const MODULE_ID = '{499F8100-B051-E713-CEC0-499D795B2639}';
    public const KEY = 'weather_stations';
    public const PROVIDER = 'FroggitWeather';

    public static function source(array $catalog): ?array
    {
        $s=$catalog['dataSources'][self::KEY]??null;
        return is_array($s)&&($s['provider']??'')===self::PROVIDER&&($s['schemaVersion']??0)===1?$s:null;
    }

    public static function station(int $id): bool
    {
        try { return IPS_InstanceExists($id)&&strtoupper((string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??''))===self::MODULE_ID; }
        catch (Throwable $ignored) { return false; }
    }

    /** Clocks are never catalog readings. dateutc is used only as reception metadata. */
    public static function excluded(string $ident): bool
    {
        return preg_match('/passkey|password|passwort|token|secret|cookie|credential|authorization|^(?:dateutc|runtime|interval|freq|heap|lightning_time)$/i',$ident)===1;
    }

    public static function role(string $ident): string
    {
        $i=strtolower($ident);
        return match (true) {
            str_contains($i,'batt')=>'battery',
            preg_match('/^soil(?:moisture|_ec_hum)/',$i)===1=>'soil',
            preg_match('/^soil(?:ad|_ec\d)/',$i)===1=>'raw',
            str_starts_with($i,'leafwetness')=>'leaf',
            $i==='dewpoint'=>'dewpoint', $i==='windchill'=>'feels',
            str_starts_with($i,'temp')||str_starts_with($i,'tf_')||in_array($i,['bgt','wbgt'],true)=>'temperature',
            str_starts_with($i,'humidity')||$i==='humi_co2'=>'humidity',
            str_starts_with($i,'barom')=>'pressure',
            str_starts_with($i,'winddir')=>'direction',
            str_contains($i,'gust')=>'gust', str_starts_with($i,'wind')=>'wind',
            str_contains($i,'rain')=>'rain',
            $i==='solarradiation'=>'light', $i==='uv'=>'uv', $i==='vpd'=>'vpd',
            str_starts_with($i,'lightning')=>'lightning',
            str_contains($i,'co2')||str_starts_with($i,'pm')=>'air',
            in_array($i,['model','stationtype','ws90_ver'],true)=>'device',
            default=>'other',
        };
    }

    public static function label(string $ident,string $name): string
    {
        // Preserve user names (especially sensor locations); translate only untouched protocol keys.
        $labels=['tempf'=>'Außentemperatur','tempinf'=>'Innentemperatur','humidity'=>'Luftfeuchtigkeit außen',
            'humidityin'=>'Luftfeuchtigkeit innen','baromabsin'=>'Luftdruck absolut','baromrelin'=>'Luftdruck relativ',
            'winddir_int'=>'Windrichtung','winddir_avg10m_int'=>'Windrichtung · 10 Minuten',
            'windspeedmph'=>'Windgeschwindigkeit','windspdmph_avg10m'=>'Wind · 10 Minuten',
            'windgustmph'=>'Windböe','maxdailygust'=>'Stärkste Böe heute','solarradiation'=>'Solare Einstrahlung',
            'uv'=>'UV-Index','dewpoint'=>'Taupunkt','windchill'=>'Gefühlte Temperatur',
            'model'=>'Modell','stationtype'=>'Stationstyp','console_batt'=>'Batterie Wetterstation',
            'rainratein'=>'Regenrate','rainrate_piezo'=>'Regenrate · Piezo',
            'eventrainin'=>'Regen · Ereignis','erain_piezo'=>'Regen · Ereignis Piezo',
            'hourlyrainin'=>'Regen · Stunde','hrain_piezo'=>'Regen · Stunde Piezo',
            'dailyrainin'=>'Regen · heute','drain_piezo'=>'Regen · heute Piezo',
            'weeklyrainin'=>'Regen · Woche','wrain_piezo'=>'Regen · Woche Piezo',
            'monthlyrainin'=>'Regen · Monat','mrain_piezo'=>'Regen · Monat Piezo',
            'yearlyrainin'=>'Regen · Jahr','yrain_piezo'=>'Regen · Jahr Piezo',
            'totalrainin'=>'Regen · gesamt','srain_piezo'=>'Regen · gesamt Piezo',
            'last24hrainin'=>'Regen · 24 Stunden','last24hrain_piezo'=>'Regen · 24 Stunden Piezo'];
        return $name===$ident?($labels[$ident]??$name):$name;
    }

    public static function metric(int $id,string $title=''): array
    {
        $m=IAHHouseholdDevices::metric($id,$title);
        if (!self::station($m['parent'])||self::excluded($m['ident'])) throw new RuntimeException('Bitte eine Messvariable einer Froggit-Wetterstation auswählen; Uhrzeit und Zugangsdaten sind ausgeschlossen.');
        $m['title']=self::label($m['ident'],$m['title']); $m['role']=self::role($m['ident']);
        return $m;
    }

    public static function discover(): array
    {
        if (!function_exists('IPS_GetInstanceListByModuleID')) return [];
        $devices=[];
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_ID) as $id) {
            if (!self::station((int)$id)) continue;
            $o=IPS_GetObject($id); $name=IAHHomeConnect::clean((string)$o['ObjectName']); $vars=[];
            foreach (IPS_GetChildrenIDs($id) as $child) {
                try {
                    $object=IPS_GetObject($child); $target=$child;
                    if (($object['ObjectType']??-1)===6&&function_exists('IPS_GetLink')) $target=(int)(IPS_GetLink($child)['TargetID']??0);
                    if (!IPS_VariableExists($target)) continue;
                    $m=self::metric($target,($object['ObjectType']??-1)===6?(string)$object['ObjectName']:'');
                    if (in_array($target,array_column($vars,'id'),true)) continue;
                    // The textual direction variable is redundant and not the measured degree value.
                    if (preg_match('/^winddir(?:_avg10m)?_txt$/D',$m['ident'])) continue;
                    $m['role']=self::role($m['ident']); $vars['var_'.$target]=$m;
                } catch (Throwable $ignored) {}
            }
            $devices['station_'.$id]=['id'=>(int)$id,'name'=>$name,'aliases'=>[$name],
                'enabled'=>true,'roomKey'=>'__weather__','roomLabel'=>'Außen','floor'=>'—','group'=>'wetterstation',
                'variables'=>$vars];
        }
        return $devices;
    }

    /** Merge additions only. A rescan never resets names, links or denials. */
    public static function merge(array $catalog): array
    {
        if (isset($catalog['dataSources'][self::KEY])&&self::source($catalog)===null) throw new RuntimeException('Der Katalogschlüssel für Wetterstationen ist bereits anders belegt.');
        $s=self::source($catalog)??['provider'=>self::PROVIDER,'schemaVersion'=>1,'domain'=>'wetterstation','kiEnabled'=>true,'devices'=>[]];
        foreach (self::discover() as $key=>$d) {
            if (!isset($s['devices'][$key])) { $s['devices'][$key]=$d; continue; }
            foreach ($d['variables'] as $mkey=>$m) {
                if (!isset($s['devices'][$key]['variables'][$mkey])&&!in_array($m['id'],array_column((array)$s['devices'][$key]['variables'],'id'),true)) $s['devices'][$key]['variables'][$mkey]=$m;
            }
        }
        if ($s['devices']!==[]||self::source($catalog)!==null) $catalog['dataSources'][self::KEY]=$s;
        return $catalog;
    }

    private static function reception(int $station): array
    {
        $updated=0; $all=false;
        try {
            // Froggit writes dateutc on EVERY upload, even with SaveAllValues disabled.
            foreach (IPS_GetChildrenIDs($station) as $child) {
                if ((IPS_GetObject($child)['ObjectIdent']??'')==='dateutc'&&IPS_VariableExists($child)) {
                    $updated=(int)(IPS_GetVariable($child)['VariableUpdated']??0); break;
                }
            }
            if (function_exists('IPS_GetProperty')) $all=(bool)IPS_GetProperty($station,'SaveAllValues');
        } catch (Throwable $ignored) {}
        return ['updated'=>$updated,'all'=>$all];
    }

    public static function reading(array $m,array &$receptions=[]): array
    {
        $out=['label'=>(string)($m['title']??''),'value'=>'Nicht verfügbar','quality'=>'missing','id'=>(int)($m['id']??0)];
        if (!self::station((int)($m['parent']??0))||self::excluded((string)($m['ident']??''))) return $out;
        $r=IAHHouseholdDevices::reading($m);
        if ($r===null) return $out;
        $raw=$r['raw']; $role=self::role($r['ident']); $numeric=is_int($raw)||is_float($raw);
        if ($numeric&&((in_array($role,['battery','humidity','soil','leaf'],true)&&($raw<0||$raw>100))
            ||(in_array($role,['wind','gust','rain','light','uv','air'],true)&&$raw<0)
            ||(in_array($role,['temperature','feels','dewpoint'],true)&&($raw < -150||$raw>200))
            ||($role==='pressure'&&$raw<=0))) return $out+['raw'=>null];
        if ($role==='battery'&&is_bool($raw)) $value=$raw?'Batterie schwach':'Batterie OK';
        elseif ($role==='rain'&&is_bool($raw)) $value=$raw?'Regen erkannt':'Kein Regen';
        elseif ($role==='direction'&&$numeric) {
            $directions=['Nord','Nordost','Ost','Südost','Süd','Südwest','West','Nordwest'];
            if ($raw<0||$raw>360) return $out;
            $value=$directions[((int)round($raw/45))%8].' · '.str_replace('.',',',(string)$raw).'°';
        } else {
            $value=is_string($raw)?IAHHomeConnect::clean($raw):IAHHomeConnect::display($r);
            // Use the ACTUAL configured profile. Rain rate shares Froggit's amount profile.
            if ($role==='rain'&&str_contains($r['ident'],'rate')&&!str_contains($value,'/h')) $value.='/h';
        }
        $quality='ok'; $reception=$receptions[$r['parent']]??=self::reception($r['parent']); $age=time()-$r['updated'];
        if ($role!=='device') {
            if ($reception['updated']>0&&time()-$reception['updated']>15*60) $quality='stale';
            elseif ($reception['all']&&$age>15*60) $quality='stale';
            elseif (!$reception['all']&&$age>24*60*60) $quality='unconfirmed';
            elseif ($reception['updated']===0&&!$reception['all']) $quality='unconfirmed';
        }
        if ($quality!=='ok') $value.=($quality==='stale'?' · veraltet':' · Aktualität unbestätigt');
        return ['label'=>$out['label'],'value'=>$value,'quality'=>$quality,'id'=>$r['id'],'raw'=>$raw,'updated'=>$r['updated'],'role'=>$role,'ident'=>$r['ident']];
    }

    public static function recognizes(string $text): bool
    {
        $q=IAHHomeConnect::norm($text);
        return preg_match('/\b(?:wetter\w*|froggit|frogit|ecowitt|eco witt|433\s*station|wind\w*|boeen?|regen\w*|regnet|geregnet|niederschlag\w*|taupunkt|luftdruck|uv\s*(?:index)?|bodenfeucht\w*|aussentemperatur|luftfeuchtigkeit (?:draussen|aussen))\b/',$q)===1
            ||preg_match('/\b(?:warm|kalt|temperatur|feucht)\b.*\b(?:draussen|aussen|hecke|rasen|beet|eckbeet|maehroboter)\b/',$q)===1;
    }

    private static function reply(string $title,array $rows,?string $answer=null): array
    {
        $display=array_map(static fn($r):array=>['label'=>$r['label'],'value'=>$r['value']],$rows);
        return ['handled'=>true,'localHouseQuery'=>true,'answerSource'=>'house_status','displayIcon'=>'weather',
            'displayTitle'=>$title,'displayRows'=>$display,
            'answer'=>$answer??$title.': '.implode('. ',array_map(static fn($r):string=>$r['label'].' '.$r['value'],array_slice($display,0,4))).'.'];
    }

    public static function query(array $catalog,string $text,int $selectedId=0): ?array
    {
        $q=IAHHomeConnect::norm($text); $source=self::source($catalog); $all=(array)($source['devices']??[]);
        $named=[];
        foreach ($all as $key=>$d) foreach (array_merge([(string)$d['name']],(array)$d['aliases']) as $alias) {
            $a=IAHHomeConnect::norm((string)$alias);
            $a=preg_replace('/^(?:froggit|frogit)\s*(?:\-\s*)?/','',$a)??$a;
            if ($a!==''&&!in_array($a,['wetterstation','wetter','ecowitt'],true)
                &&(str_contains(' '.$q.' ',' '.$a.' ')||($a==='433station'&&preg_match('/\b433 station\b/',$q)))) $named[$key]=$d;
        }
        $selected=array_filter($all,static fn($d):bool=>(int)$d['id']===$selectedId);
        if (!self::recognizes($text)&&$named===[]&&$selected===[]) return null;
        if ($source===null) return preg_match('/\b(?:wetter\w*|froggit|frogit|ecowitt|433\s*station)\b/',$q)
            ?self::reply('Wetterstation',[],'Es ist noch keine Wetterstation im veröffentlichten RoomsCatalog eingerichtet.'):null;
        $allowed=array_filter($all,static fn($d):bool=>!empty($source['kiEnabled'])&&!empty($d['enabled'])&&self::station((int)$d['id']));
        $wanted=$selectedId>0&&$selected!==[]?$selected:$named;
        if ($wanted!==[]&&array_diff_key($wanted,$allowed)!==[]) return self::reply('Wetterstation',[],'Diese Wetterstation ist nicht mehr für Alexa verfügbar.');
        if ($allowed===[]) return self::reply('Wetterstation',[],'Es ist keine Wetterstation für Alexa freigegeben.');
        if (preg_match('/\b(?:morgen|uebermorgen|vorhersage|prognose|naechste[nsr]?)\b/',$q)) return self::reply('Wetterstation',[],'Meine Wetterstation liefert Messwerte, aber keine Wettervorhersage.');
        if (preg_match('/^(?:bitte )?(?:schalte|stelle|setze|starte|stoppe|aktiviere|deaktiviere)\b/',$q)) return self::reply('Wetterstation',[],'Die Wetterstation kann ich ausschließlich abfragen.');
        $roles=[]; $period='';
        if (preg_match('/batter|akku/',$q)) $roles=['battery'];
        elseif (preg_match('/bodenfeucht|boden.*feucht|feucht.*(?:hecke|rasen|beet|eckbeet)/',$q)) $roles=['soil'];
        elseif (str_contains($q,'taupunkt')) $roles=['dewpoint'];
        elseif (preg_match('/gefuehlt|windchill/',$q)) $roles=['feels'];
        elseif (str_contains($q,'luftdruck')) $roles=['pressure'];
        elseif (preg_match('/luftfeucht|feucht.*draussen/',$q)) $roles=['humidity'];
        elseif (preg_match('/boee|windboe/',$q)) $roles=['gust'];
        elseif (str_contains($q,'windrichtung')) $roles=['direction'];
        elseif (str_contains($q,'wind')) $roles=['wind','gust','direction'];
        elseif (preg_match('/regen|regnet|geregnet|niederschlag/',$q)) {
            $roles=['rain'];
            foreach (['24 Stunden'=>'24|vierundzwanzig','heute'=>'heute|tag|taeglich','Stunde'=>'stunde','Woche'=>'woche','Monat'=>'monat','Jahr'=>'jahr','Ereignis'=>'ereignis','Rate'=>'rate|regnet|gerade|aktuell'] as $p=>$pattern)
                if (preg_match('/\b(?:'.$pattern.')\b/',$q)) { $period=$p; break; }
        }
        elseif (preg_match('/\buv\s*(?:index)?\b/',$q)) $roles=['uv'];
        elseif (preg_match('/einstrahlung|sonnenstrahlung|hell/',$q)) $roles=['light'];
        elseif (preg_match('/temperatur|warm|kalt/',$q)) $roles=['temperature'];
        elseif (preg_match('/\bvpd\b/',$q)) $roles=['vpd'];
        elseif (preg_match('/blitz|gewitter/',$q)) $roles=['lightning'];
        elseif (preg_match('/\bco2\b|feinstaub|luftqualitaet/',$q)) $roles=['air'];
        if (preg_match('/\b(?:gestern|vorgestern|zuletzt|verlauf|historie|durchschnitt|minimum|maximum|waermsten|kaeltesten)\b/',$q)
            &&!($roles===['gust']&&str_contains($q,'heute'))) return self::reply('Wetterstation',[],'Für diese Wetterfrage ist noch keine Archivauswertung eingerichtet. Ich kann die aktuellen Messwerte und die vorhandenen Regenzähler abfragen.');
        $allValues=preg_match('/\b(?:alle|saemtliche|details|detailliert|vollstaendig|beide)\b/',$q)===1;
        $stations=$wanted!==[]?array_intersect_key($allowed,$wanted):$allowed;
        // The full meteorological station is the default status page; sensor-only gateways remain individually accessible.
        if ($roles===[]&&!$allValues&&$wanted===[]) {
            uasort($stations,static function($a,$b):int {
                $score=static fn($d):int=>count(array_filter($d['variables'],static fn($m):bool=>in_array($m['role'],['wind','rain','uv','temperature','pressure'],true)));
                return $score($b)<=>$score($a);
            });
            $stations=array_slice($stations,0,1,true);
        }
        $rows=[]; $seen=[]; $receptions=[];
        foreach ($stations as $d) foreach ((array)$d['variables'] as $m) {
            if (empty($m['enabled'])||isset($seen[$m['id']])) continue;
            // A linked sensor cannot bypass its own station's published denial.
            foreach ($all as $ownerKey=>$owner) if ((int)$owner['id']===(int)$m['parent']&&!isset($allowed[$ownerKey])) continue 2;
            $role=self::role((string)$m['ident']);
            if ($roles!==[]&&!in_array($role,$roles,true)) continue;
            if (!$allValues&&$roles===[]&&in_array($role,['raw','device','other'],true)) continue;
            if ($roles===['gust']&&preg_match('/\b(?:heute|staerkste|maximal|maximum)\b/',$q)&&$m['ident']!=='maxdailygust') continue;
            if ($roles===['temperature']&&preg_match('/\b(?:draussen|aussen)\b/',$q)&&$m['ident']!=='tempf') continue;
            if ($roles===['temperature']&&preg_match('/\b(?:innen|drinnen)\b/',$q)&&$m['ident']!=='tempinf') continue;
            if ($period!=='') {
                $ident=$m['ident'];
                $matches=match($period){'24 Stunden'=>str_contains($ident,'24h'),'heute'=>preg_match('/daily|^drain/',$ident),
                    'Stunde'=>preg_match('/hourly|^hrain/',$ident),'Woche'=>preg_match('/weekly|^wrain/',$ident),
                    'Monat'=>preg_match('/monthly|^mrain/',$ident),'Jahr'=>preg_match('/yearly|^yrain/',$ident),
                    'Ereignis'=>preg_match('/event|^erain/',$ident),'Rate'=>str_contains($ident,'rate')||$m['type']===0,default=>false};
                if (!$matches) continue;
            }
            if (in_array($roles,[['soil'],['battery'],['temperature']],true)&&preg_match('/\b(hecke|rasen|beet|eckbeet|troege|maehroboter)\b/',$q,$location)) {
                $label=IAHHomeConnect::norm($m['title']);
                if (!str_contains($label,$location[1])) continue;
                foreach (['ost','west','sued','nord','mitte'] as $side) if (preg_match('/\b'.$side.'\b/',$q)&&!str_contains($label,$side)) continue 2;
            }
            $r=self::reading($m,$receptions); $seen[$m['id']]=true;
            if (count($stations)>1) $r['label']=$d['name'].' · '.$r['label'];
            $rows[]=$r;
        }
        // Keep status speech focused while the display can show every requested measurement.
        $priority=['wind','humidity','rain','feels','pressure','gust','direction','light','uv','soil','battery'];
        $rank=static fn($r):int=>($r['ident']??'')==='tempf'?-2:(($r['ident']??'')==='tempinf'?50:
            (array_search($r['role']??'', $priority,true)===false?99:array_search($r['role'],$priority,true)));
        if ($roles===[]) usort($rows,static fn($a,$b):int=>$rank($a)<=>$rank($b));
        $title=count($stations)===1?(string)reset($stations)['name']:'Wetterstationen';
        return self::reply($title,$rows,$rows===[]?'Für diese Frage ist kein freigegebener Messwert zugeordnet.':null);
    }
}
