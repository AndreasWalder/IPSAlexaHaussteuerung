<?php
declare(strict_types=1);

/** Bounded local discovery. No script execution, evaluation or archive writes. */
final class RCCSystemTreeDiscovery
{
    public const CONTROLLER='{DEADBEAF-0000-0000-0000-000000000001}';
    public const GROUPS=['pump'=>'Pumpe','zones'=>'Bewässerungskreise','climate'=>'Boden und Wetter',
        'winter'=>'Winter und Stationen','schedule'=>'Zeitpläne','unknown'=>'Zuordnung prüfen'];
    private const SECRET='/passw|token|secret|api.?key|credential|authorization|login|benutzername|username|private.?key/i';

    public static function norm(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/',' ',str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],mb_strtolower($value)))??'');
    }

    private static function label(string $value): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F]+/u',' ',$value)??''),0,100);
    }

    /** Only literal first arguments of selected IPS calls. Strings, comments,
     * methods and arbitrary five-digit constants are not object references. */
    public static function scriptRefs(string $code): array
    {
        $ops=['getvalue'=>'read','getvalueboolean'=>'read','getvalueinteger'=>'read',
            'getvaluefloat'=>'read','getvaluestring'=>'read','setvalue'=>'write',
            'setvalueboolean'=>'write','setvalueinteger'=>'write','setvaluefloat'=>'write',
            'setvaluestring'=>'write','requestaction'=>'action','ips_runscript'=>'script',
            'ips_runscriptwait'=>'script','ips_runscriptex'=>'script','ips_runscriptwaitex'=>'script'];
        $tokens=array_values(array_filter(token_get_all($code),static fn($t)=>!is_array($t)
            || !in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));
        $refs=[];$locals=[];
        // A single straight-line binding is a discovery hint, never an action.
        // Any second assignment, parameter/reference use or mutation disqualifies it.
        foreach ($tokens as $i=>$t) {
            if (!is_array($t)||$t[0]!==T_VARIABLE)continue;
            $key=$t[1];$next=$tokens[$i+1]??null;$value=$tokens[$i+2]??null;
            $prev=$tokens[$i-1]??null;
            $startOfStatement=$prev===';' || (is_array($prev)&&$prev[0]===T_OPEN_TAG);
            if ($startOfStatement && $next==='=' && is_array($value) && $value[0]===T_LNUMBER
                && preg_match('/^[1-9][0-9]{4}$/D',$value[1]) && ($tokens[$i+3]??null)===';') {
                $locals[$key]=isset($locals[$key])?[0,0]:[(int)$value[1],$i];
            } elseif ($next==='=' || $next==='[' || $prev==='&'
                || (is_array($prev)&&in_array($prev[0],[T_INC,T_DEC,T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG],true))
                || (is_array($next)&&in_array($next[0],[T_INC,T_DEC,T_PLUS_EQUAL,T_MINUS_EQUAL,T_MUL_EQUAL,
                    T_DIV_EQUAL,T_MOD_EQUAL,T_CONCAT_EQUAL,T_AND_EQUAL,T_OR_EQUAL,T_XOR_EQUAL,T_SL_EQUAL,T_SR_EQUAL,
                    T_POW_EQUAL,T_COALESCE_EQUAL],true))) {
                $locals[$key]=[0,0];
            }
        }
        foreach ($tokens as $i=>$t) {
            if (!is_array($t)||!in_array($t[0],[T_STRING,T_NAME_FULLY_QUALIFIED],true))continue;
            $op=$ops[strtolower(ltrim($t[1],'\\'))]??null;
            $prev=$tokens[$i-1]??null;
            if ($op===null||$prev==='::'||(is_array($prev)
                && in_array($prev[0],[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON,T_FUNCTION],true)))continue;
            $id=$tokens[$i+2]??null;
            if (($tokens[$i+1]??null)!=='(' || !is_array($id)
                || !in_array($tokens[$i+3]??null,[',',')'],true))continue;
            if ($id[0]===T_LNUMBER && preg_match('/^[1-9][0-9]{4}$/D',$id[1]))
                $refs[(int)$id[1]][$op]=true;
            elseif ($id[0]===T_VARIABLE && isset($locals[$id[1]])
                && $locals[$id[1]][0]>0 && $locals[$id[1]][1]<$i)
                $refs[$locals[$id[1]][0]][$op.'_binding']=true;
        }
        return $refs;
    }

    private static function role(string $label,string $ident,string $path,string $unit,int $type,bool $owned): array
    {
        $identWords=preg_replace('/(?<=[a-z])(?=[A-Z])/',' ',$ident)??$ident;
        $s=self::norm($label.' '.$identWords);
        $context=self::norm($label.' '.$identWords.' '.$path);
        $zone=0;
        if ($owned && preg_match('/^(Manuell|Automatik|DauerStart|Dauer|Prio|Status|Info)([1-9][0-9]?)$/D',$ident,$m)) {
            $roles=['Manuell'=>'zoneManual','Automatik'=>'zoneAutomatic','DauerStart'=>'configuredDuration',
                'Dauer'=>'duration','Prio'=>'priority','Status'=>'zoneStatus','Info'=>'zoneInfo'];
            return ['zones',$roles[$m[1]],(int)$m[2]];
        }
        if (preg_match('/\bkreis\s*(\d{1,2})\b/',$s,$m))$zone=(int)$m[1];
        if ($owned) {
            $map=['PumpeStatus'=>['pump','pumpStatus'],'PumpeInfo'=>['pump','pumpInfo'],
                'PumpeManuell'=>['pump','pumpManual'],'GesamtAutomatik'=>['pump','automatic'],
                'ManualNextStep'=>['zones','manualStep']];
            if (isset($map[$ident]))return [...$map[$ident],0];
        }
        if (preg_match('/naechst|next/',$s) && preg_match('/start|lauf|termin|bewae|zeit|einschalt/',$s))
            return ['schedule','nextStart',$zone];
        if (preg_match('/einschaltzeit|ausschaltzeit|zeitplan|wochenplan|startzeit|endzeit|schaltzeit/',$s))
            return ['schedule','scheduleValue',$zone];
        if (preg_match('/restlauf|restzeit|remaining/',$s))return ['zones','remaining',$zone];
        if (preg_match('/rohr\s*(begleit\s*)?heiz|begleitheiz|heizband|frostschutzheiz/',$context)) {
            if ($unit==='°C'||preg_match('/temperatur|sollwert|grenzwert|schwellwert/',$s))
                return $unit==='°C' && !preg_match('/soll|grenz|schwell/',$s)
                    ? ['winter','stationTemperature',0] : ['winter','winterSetting',0];
            if ($type===0 && preg_match('/status|rueckmeldung|istzustand/',$s) && !preg_match('/freigabe|manuell|automatik|hand|schalter|befehl|soll/',$s))
                return ['winter','heaterStatus',0];
            if (in_array($type,[1,2],true) && in_array($unit,['','W','kW','A','mA'],true)
                && !preg_match('/freigabe|grenz|soll|schwell|zaehler|verbrauch gesamt/',$s))return ['winter','heaterSignal',0];
            return ['winter','heaterOther',0];
        }
        if ($type===0 && preg_match('/winter\s*(betrieb|modus)|sommer\s*winter|frost\s*(betrieb|modus)/',$s))
            return ['winter','winterMode',0];
        if (in_array($type,[1,2],true) && $unit==='°C' && preg_match('/aussen\s*(?:temperatur|sensor)|outdoor\s*temperature/',$s))
            return ['climate','outdoorTemperature',0];
        if ($unit==='°C' || preg_match('/temperatur|temperature/',$s)) {
            if (in_array($type,[1,2],true)&&!preg_match('/soll|grenz|schwell|minimal|maximal/',$s))
                return ['winter','stationTemperature',0];
            return ['winter','winterSetting',0];
        }
        if (preg_match('/pumpe|pumpen/',$s)) {
            if (preg_match('/druck/',$s))return ['pump','pumpPressure',0];
            if (preg_match('/status|rueckmeldung|istzustand/',$s))return ['pump','pumpStatus',0];
            if (preg_match('/info|meldung|stoerung/',$s))return ['pump','pumpInfo',0];
            return ['pump','pumpSetting',0];
        }
        if ($zone===0 && $owned && str_contains($s,'nebenstelle'))$zone=11;
        if ($owned && preg_match('/automatik gesamtsystem|gesamt automatik/',$s))return ['pump','automatic',0];
        if ($owned && preg_match('/manuell(er)? schritt/',$s))return ['zones','manualStep',0];
        if ($zone>0) {
            foreach (['status|rueckmeldung'=>'zoneStatus','info'=>'zoneInfo','manuell'=>'zoneManual',
                'automatik'=>'zoneAutomatic','dauer\s*start'=>'configuredDuration','dauer'=>'duration',
                'prioritaet|prio'=>'priority'] as $pattern=>$role) {
                if (preg_match('/\b(?:'.$pattern.')\b/',$s))return ['zones',$role,$zone];
            }
            if (preg_match('/\b(?:status|rueckmeldung)\b/',$context))return ['zones','zoneStatus',$zone];
        }
        if (preg_match('/\b(?:batterie|battery)\b/',$s) && preg_match('/bodenfeucht|soil\s*moisture|regensensor/',$context))
            return ['climate','sensorBattery',0];
        if (preg_match('/regen\s*sensor|rain\s*sensor/',$s))return ['climate','rainSensor',0];
        if (preg_match('/bodenfeucht|soil\s*moisture/',$context))return ['climate','soilMoisture',$zone];
        if (preg_match('/regen|luftfeucht|uv\s*index/',$s))return ['climate','weather',0];
        if ($unit==='W')return ['climate','power',0];
        return ['unknown','unknown',0];
    }

    public static function variableUnit(int $id, string $profile): string
    {
        if (function_exists('IPS_GetVariablePresentation')) {
            try {
                $unit=trim((string)(@IPS_GetVariablePresentation($id)['SUFFIX']??''));
                if ($unit!=='')return preg_replace('/^°\s+C$/u','°C',$unit)??$unit;
            } catch (Throwable $ignored) {}
        }
        if ($profile!=='' && function_exists('IPS_VariableProfileExists') && IPS_VariableProfileExists($profile)) {
            $unit=trim((string)(IPS_GetVariableProfile($profile)['Suffix']??''));
            if ($unit!=='')return preg_replace('/^°\s+C$/u','°C',$unit)??$unit;
        }
        return match (true) {
            preg_match('/temperature/i',$profile)===1=>'°C',
            preg_match('/humidity|percent/i',$profile)===1=>'%',
            preg_match('/DurationMin/i',$profile)===1=>'min',default=>'',
        };
    }

    public static function supplementalPoint(int $id, string $label): array
    {
        if (!IPS_VariableExists($id))throw new RuntimeException('Bitte eine bestehende Variable auswählen.');
        $object=IPS_GetObject($id);
        $label=self::label(trim($label)!==''?$label:(string)$object['ObjectName']);
        $ident=(string)($object['ObjectIdent']??'');
        if (preg_match(self::SECRET,$label.' '.$object['ObjectName'].' '.$ident))
            throw new RuntimeException('Diese Variable kann der Anlage nicht zugeordnet werden.');
        $v=IPS_GetVariable($id);
        $type=(int)$v['VariableType'];
        if (!in_array($type,[0,1,2],true))throw new RuntimeException('Bitte eine boolesche oder numerische Variable auswählen.');
        $profile=(string)(($v['VariableCustomProfile']??'')?:($v['VariableProfile']??''));
        return ['id'=>$id,'label'=>$label,'ident'=>$ident,'parent'=>(int)$object['ParentID'],
            'path'=>'','group'=>'unknown','role'=>'unknown','zone'=>0,'type'=>$type,
            'unit'=>self::label(self::variableUnit($id,$profile)),'profile'=>self::label($profile),
            'owned'=>false,'enabled'=>false,'actionCandidate'=>false,'aliases'=>[],
            'evidence'=>['manual'],'manualEdit'=>true,'manualSource'=>true];
    }

    public static function scan(int $root): array
    {
        if ($root<=0||!IPS_ObjectExists($root))throw new RuntimeException('Bitte den Hauptordner der Anlage auswählen.');
        $obj=IPS_GetObject($root);
        if (!in_array((int)$obj['ObjectType'],[0,1],true))throw new RuntimeException('Bitte eine Kategorie oder Instanz auswählen.');
        $rootName=self::label((string)$obj['ObjectName']);
        $queue=[[$root,0,'','',true,'root',0]];
        $points=[];$seen=[];$controllers=[];$scripts=[];$events=[];$actuators=[];$zoneNames=[];$notes=[];$visited=0;
        for ($head=0;isset($queue[$head]) && $visited<900;$head++) {
            [$id,$depth,$path,$alias,$expand,$evidence,$hops]=$queue[$head];
            if ($id<=0 || !IPS_ObjectExists($id))continue;
            if ($depth>16) {$notes[]='Maximale Suchtiefe erreicht.';continue;}
            $o=IPS_GetObject($id);
            $name=self::label((string)($o['ObjectName']??''));
            $ident=(string)($o['ObjectIdent']??'');
            if (preg_match(self::SECRET,$name.' '.$ident.' '.$alias))continue;
            $type=(int)$o['ObjectType'];
            if (isset($seen[$id][$evidence])) {
                if ($type===2 && isset($points[$id]) && $alias!=='')$points[$id]['aliases'][]=$alias;
                continue;
            }
            $seen[$id][$evidence]=true;
            $visited++;
            $parent=(int)($o['ParentID']??0);
            if ($type===6 && $hops<8) {
                $target=(int)(IPS_GetLink($id)['TargetID']??0);
                if ($target<=0 || !IPS_ObjectExists($target)) {$notes[]='Link #'.$id.' hat kein erreichbares Ziel.';continue;}
                if ($target===$root)continue;
                $queue[]=[$target,$depth+1,$path,$alias?:$name,$expand,'link',$hops+1];
                continue;
            }
            if ($type===0||$type===1) {
                if (!$expand)continue;
                if ($type===1 && strtoupper((string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??''))===self::CONTROLLER) {
                    $controllers[$id]=true;
                    if (function_exists('IPS_GetConfiguration')) {
                        $conf=json_decode((string)IPS_GetConfiguration($id),true);
                        foreach (is_array($conf)?$conf:[] as $prop=>$target) {
                            if (is_string($target) && preg_match('/^ZoneName(10|[1-9])$/D',$prop,$zoneMatch)) {
                                $zoneNames[$id][(int)$zoneMatch[1]]=self::label($target);
                                continue;
                            }
                            if (!is_int($target)||$target<=0||!preg_match('/^(PumpeAktorID|AktorID([1-9][0-9]?))$/D',$prop,$m))continue;
                            if (!IPS_ObjectExists($target))continue;
                            $actuators[$id][$prop]=$target;
                            $queue[]=[$target,$depth+1,$name,'',false,'controller_config:'.$id.':'.$prop,$hops+1];
                        }
                    }

                    if (function_exists('IPS_GetReferenceList')) {
                        foreach (array_slice((array)@IPS_GetReferenceList($id),0,80) as $ref)
                            $queue[]=[(int)$ref,$depth+1,$name,'',false,'instance_ref:'.$id,$hops+1];
                    }
                }
                $children=IPS_GetChildrenIDs($id);
                foreach (array_slice($children,0,300) as $child)
                    $queue[]=[(int)$child,$depth+1,$id===$root?'':trim($path.' / '.$name,' /'),'',
                        true,$evidence==='root'?'tree':$evidence,$hops];
                if (count($children)>300)$notes[]='Teilbaum mit über 300 direkten Objekten gekürzt.';
                continue;
            }
            if ($type===4 && function_exists('IPS_GetEvent')) {
                $event=IPS_GetEvent($id);
                if (in_array((int)($event['EventType']??-1),[1,2],true))
                    $events[$id]=['id'=>$id,'label'=>$name,'scriptId'=>isset($scripts[$parent])?$parent:0];
                if ((int)($event['TriggerVariableID']??0)>0)
                    $queue[]=[(int)$event['TriggerVariableID'],$depth+1,$path,'',false,'event:'.$id,$hops];
                $inline=$event['EventScript']??null;
                if (is_string($inline)&&$inline!==''&&strlen($inline)<=131072) {
                    if (!str_contains($inline,'<?'))$inline='<?php '.$inline;
                    foreach (array_slice(self::scriptRefs($inline),0,80,true) as $ref=>$ops)
                        $queue[]=[$ref,$depth+1,$name,'',false,'event_script:'.$id.':'.implode('+',array_keys($ops)),$hops+1];
                }
                continue;
            }
            if ($type===3 && !isset($scripts[$id]) && count($scripts)<30) {
                if (!function_exists('IPS_GetScriptContent'))continue;
                $code=@IPS_GetScriptContent($id);
                $refs=is_string($code)&&strlen($code)<=131072?self::scriptRefs($code):[];
                if (!is_string($code)||strlen($code)>131072)$notes[]='Skript #'.$id.' nicht lesbar oder über 128 KiB.';

                $scripts[$id]=['id'=>$id,'label'=>$name,'references'=>count($refs)];
                foreach (array_slice($refs,0,80,true) as $ref=>$ops) {
                    if (!IPS_ObjectExists($ref))continue;
                    $refType=(int)IPS_GetObject($ref)['ObjectType'];
                    if ($refType===2 || ($refType===3 && $hops<3 && (isset($ops['script'])||isset($ops['script_binding']))))
                        $queue[]=[$ref,$depth+1,$name,'',false,'script:'.$id.':'.implode('+',array_keys($ops)),$hops+1];
                }
                if (count($refs)>80)$notes[]='Skript #'.$id.' hat mehr als 80 Referenzen.';
                foreach (array_slice(IPS_GetChildrenIDs($id),0,80) as $child)
                    if (IPS_ObjectExists($child)&&(int)IPS_GetObject($child)['ObjectType']===4)
                        $queue[]=[(int)$child,$depth+1,$name,'',false,'script_event:'.$id,$hops];
                continue;
            }
            if ($type!==2)continue;
            $v=IPS_GetVariable($id);
            $vt=(int)($v['VariableType']??-1);
            if (!in_array($vt,[0,1,2,3],true))continue;
            $owned=$parent>0 && IPS_ObjectExists($parent) && (int)IPS_GetObject($parent)['ObjectType']===1
                && strtoupper((string)(IPS_GetInstance($parent)['ModuleInfo']['ModuleID']??''))===self::CONTROLLER;
            if ($owned && !isset($controllers[$parent]))
                $queue[]=[$parent,$depth+1,'','',true,'controller',0];
            $profile=(string)(($v['VariableCustomProfile']??'')?:($v['VariableProfile']??''));
            $unit=self::variableUnit($id,$profile);
            [$group,$role,$zone]=self::role($alias?:$name,$ident,$path,$unit,$vt,$owned);
            if ($alias!=='' && $role==='unknown')
                [$group,$role,$zone]=self::role($name,$ident,$path,$unit,$vt,$owned);
            // An external reference is evidence, not permission to read an arbitrary value.
            $direct=in_array($evidence,['tree','link','controller'],true);
            if (!$direct && $role==='unknown' && $vt===3)continue;
            if ($role==='soilMoisture' && (!in_array($vt,[1,2],true)||$unit!=='%'))$role='unknown';
            if ($role==='stationTemperature' && (!in_array($vt,[1,2],true)||$unit!=='°C'))$role='unknown';
            if ($role==='unknown')$group='unknown';
            $station=$role==='stationTemperature' && preg_match('/^(temperatur|temperature|wert|value)$/i',$alias?:$name)
                && $parent>0 && IPS_ObjectExists($parent)
                ? self::label((string)(IPS_GetObject($parent)['ObjectName']??$name)):$alias;
            $custom=(int)($v['VariableCustomAction']??0);
            if ($custom>1 && $hops<3 && IPS_ObjectExists($custom) && (int)IPS_GetObject($custom)['ObjectType']===3)
                $queue[]=[$custom,$depth+1,$path,'',false,'action_script:'.$id,$hops+1];
            // Prefer explicit visualization labels, then direct module variables,
            // then script/event references. Preserve all provenance on duplicates.
            $rank=($role!=='unknown'?4:0)+($alias!==''?2:0)+($direct?1:0);
            $old=$points[$id]??[];
            if ($old===[]||$rank>$old['_rank']) {
                $safeRead=$direct && $role!=='unknown' && !in_array($role,['zoneManual','pumpManual','manualStep','heaterOther','pumpSetting'],true);
                if ($vt===3 && !in_array($role,['zoneInfo','pumpInfo'],true))$safeRead=false;
                if ($role==='heaterStatus' && ($custom!==0 || (int)($v['VariableAction']??0)>0 || !$direct))$safeRead=false;
                $points[$id]=['id'=>$id,'label'=>$station?:($alias?:$name),'ident'=>$ident,'parent'=>$parent,
                    'path'=>$path,'group'=>$group,'role'=>$role,'zone'=>$zone,'type'=>$vt,'unit'=>self::label($unit),
                    'profile'=>self::label($profile),'owned'=>$owned,'enabled'=>$safeRead,
                    'actionCandidate'=>$owned && $vt===0 && $custom===0
                        && (int)($v['VariableAction']??0)===$parent
                        && preg_match('/^(?:Manuell[1-9][0-9]?|Automatik[1-9][0-9]?|PumpeManuell|GesamtAutomatik)$/D',$ident)===1,
                    '_rank'=>$rank,'aliases'=>$old['aliases']??[],'evidence'=>$old['evidence']??[]];
            }
            $points[$id]['evidence'][]=$evidence;
            if ($alias!=='')$points[$id]['aliases'][]=$alias;
        }
        foreach ($actuators as $mapping) {
            foreach ($mapping as $property=>$id) {
                if (!isset($points[$id]) || !in_array($points[$id]['role'],['unknown','pumpSetting','zoneActuator','pumpActuator'],true))continue;
                $zone=$property==='PumpeAktorID'?0:(int)substr($property,7);
                $point=&$points[$id];
                if (($point['role']==='zoneActuator' && $point['zone']!==$zone) || ($point['role']==='pumpActuator' && $zone>0)) {
                    $point['group']='unknown';$point['role']='sharedActuator';$point['zone']=0;
                } else {
                    $point['group']=$zone>0?'zones':'pump';
                    $point['role']=$zone>0?'zoneActuator':'pumpActuator';
                    $point['zone']=$zone;
                }
                $point['enabled']=false;
                unset($point);
            }
        }
        if (count($controllers)===1) {
            $zoneNames=($zoneNames[array_key_first($controllers)]??[])+[11=>'Nebenstelle'];
            ksort($zoneNames,SORT_NUMERIC);
        } else $zoneNames=[];
        if (count($scripts)>=30)$notes[]='Maximal 30 Skripte geprüft.';
        if (isset($queue[$head]))$notes[]='Scanlimit erreicht. Weitere Objekte fehlen.';
        ksort($points,SORT_NUMERIC);ksort($controllers,SORT_NUMERIC);ksort($scripts,SORT_NUMERIC);ksort($events,SORT_NUMERIC);
        foreach ($points as &$p) {
            unset($p['_rank']);
            $p['aliases']=array_values(array_unique($p['aliases']));
            $p['evidence']=array_values(array_unique($p['evidence']));
            sort($p['aliases']);sort($p['evidence']);
        }
        unset($p);
        if ($points===[])throw new RuntimeException('Keine Variablen unter dieser Wurzel gefunden.');
        return ['version'=>1,'provider'=>'ObjectTreeIrrigation','sourceRootId'=>$root,'title'=>$rootName,
            'domain'=>'bewaesserung','kiEnabled'=>true,'points'=>$points,'controllers'=>array_keys($controllers),
            'scripts'=>$scripts,'events'=>$events,'actuators'=>$actuators,'zoneNames'=>$zoneNames,
            'notes'=>array_values(array_unique($notes)),'historySignals'=>[],
            'voiceActions'=>[],'winterFromMonth'=>11,'winterUntilMonth'=>3];
    }
}
