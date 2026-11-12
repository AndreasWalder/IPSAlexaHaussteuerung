<?php
declare(strict_types=1);

/**
 * V3.7 central Alexa voice rules and scene executor.
 * Source of truth: SystemConfiguration.voiceRules; editing in EDIT only.
 * Only exact, normalized phrase matches. No AI-generated script commands.
 * Writes are only accepted for explicitly reviewed RoomsCatalog capabilities.
 */
final class IAHVoiceRuleEngine
{
    public const VERSION=1;

    public static function normalize(string $text): string
    {
        $s=mb_strtolower(trim($text),'UTF-8');
        $s=str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],$s);
        return trim(preg_replace('/\s+/u',' ',preg_replace('/[^\p{L}\p{N}\s%-]/u',' ',$s)??'')??'');
    }

    public static function phrases(mixed $input): array
    {
        $list=is_array($input)?$input:explode(',',(string)$input);
        $out=[];
        foreach($list as $phrase) {
            if(!is_scalar($phrase))continue;
            $p=trim((string)$phrase);
            $key=self::normalize($p);
            if($key===''||mb_strlen($p,'UTF-8')>100)continue;
            $out[$key]=$p;
            if(count($out)>=20)break;
        }
        return array_values($out);
    }

    public static function rules(array $configuration): array
    {
        $root=$configuration['voiceRules']??[];
        if(!is_array($root) || (int)($root['schemaVersion']??0)!==self::VERSION)return [];
        return is_array($root['items']??null)?$root['items']:[];
    }

    public static function reservedPhrases(): array
    {
        // Protect existing single-word Alexa navigation and control semantics.
        return array_fill_keys(['licht','lichter','jalousie','jalousien','heizung',
            'geraete','geräte','kamera','energie','visu','kacheltest',
            'zurueck','zuruck','hauptmenue','home','ende','fertig',
            'einstellungen','bewaesserung','lueftung','szene','listen'],true);
    }

    public static function validateRule(array $rule): array
    {
        $kind=(string)($rule['kind']??'');
        if(!in_array($kind,['answer','script','scene'],true))
            throw new RuntimeException('Regeltyp muss Antwort, IPS-Skript oder Geraeteszene sein.');
        $title=trim((string)($rule['title']??''));
        if($title===''||mb_strlen($title,'UTF-8')>100)
            throw new RuntimeException('Titel fehlt oder ist zu lang.');
        $aliases=self::phrases($rule['aliases']??[]);
        if(!$aliases)throw new RuntimeException('Mindestens ein gesprochener Befehl erforderlich.');
        foreach($aliases as $alias) {
            if(isset(self::reservedPhrases()[self::normalize($alias)]))
                throw new RuntimeException('Alexa-Standardnavigation darf nicht ueberschrieben werden: '.$alias);
        }
        $answer=trim((string)($rule['answer']??''));
        if(mb_strlen($answer,'UTF-8')>800)throw new RuntimeException('Antworttext zu lang (maximal 800 Zeichen).');
        $image=trim((string)($rule['imageUrl']??''));
        if($image!=='' && (strlen($image)>500 ||
            !preg_match('~^https://[a-z0-9.-]+(?:/[^\s]*)?$~iD',$image) ||
            preg_match('~(?:localhost|127\.0\.0\.1|192\.168\.|10\.|172\.(?:1[6-9]|2\d|3[01])\.)~i',$image)))
            throw new RuntimeException('Bild muss eine oeffentlich erreichbare HTTPS-URL sein.');
        $scriptId=(int)($rule['scriptId']??0);
        $steps=is_array($rule['steps']??null)?array_values($rule['steps']):[];
        if(count($steps)>12)throw new RuntimeException('Maximal 12 Geraeteaktionen pro Szene.');
        if($kind==='answer' && $answer==='')
            throw new RuntimeException('Ein Fragetext benoetigt eine Antwort.');
        if($kind==='script' && $scriptId<=0)
            throw new RuntimeException('IP-Symcon-Skript auswaehlen.');
        if($kind==='scene' && !$steps)
            throw new RuntimeException('Eine Szene benoetigt mindestens ein Geraet.');
        if($kind==='script' && !function_exists('IPS_ScriptExists'))
            throw new RuntimeException('IPS-Skriptfunktionen nicht vorhanden.');
        if($kind==='script' && !IPS_ScriptExists($scriptId))
            throw new RuntimeException('Gewaehltetes IPS-Skript existiert nicht.');
        foreach($steps as $s){
            if(!is_array($s)||!isset($s['deviceKey'],$s['property'],$s['value']))
                throw new RuntimeException('Unvollstaendige Geraeteaktion.');
            if(strlen((string)$s['deviceKey'])>200 ||
                !in_array((string)$s['property'],['power','brightness','targetPosition','targetTemperature'],true))
                throw new RuntimeException('Unbekannte Geraetefaehigkeit.');
            if(!is_scalar($s['value']) || strlen((string)$s['value'])>30)
                throw new RuntimeException('Ungueltiger Geraetewert.');
        }
        return [
            'title'=>$title,'aliases'=>$aliases,'kind'=>$kind,'answer'=>$answer,
            'imageUrl'=>$image,'scriptId'=>$kind==='script'?$scriptId:0,
            'steps'=>$kind==='scene'?$steps:[],
            'enabled'=>!empty($rule['enabled']),
            'confirmed'=>!empty($rule['confirmed'])
        ];
    }

    public static function validateCollection(array $config): void
    {
        $seen=[];
        foreach(self::rules($config) as $key=>$r) {
            if(!is_string($key) || !preg_match('/^[a-z0-9][a-z0-9_-]{1,70}$/D',$key)
                || !is_array($r)) throw new RuntimeException('Ungueltiger Regelschluessel.');
            $r=self::validateRule($r);
            foreach($r['aliases'] as $phrase){
                $n=self::normalize($phrase);
                if(isset($seen[$n]) && $seen[$n]!==$key)
                    throw new RuntimeException('Mehrdeutiger Sprachbefehl: '.$phrase);
                $seen[$n]=$key;
            }
            if($r['enabled'] && !$r['confirmed'] && $r['kind']!=='answer')
                throw new RuntimeException('Szenen und Skripte benoetigen fachliche Bestaetigung: '.$r['title']);
        }
    }

    public static function change(array $config,string $key,array $values,bool $delete=false): array
    {
        $root=$config['voiceRules']??['schemaVersion'=>self::VERSION,'items'=>[]];
        if(!is_array($root)||(!empty($root) && (int)($root['schemaVersion']??0)!==self::VERSION))
            throw new RuntimeException('Unbekannte VoiceRules-Version: nicht automatisch konvertieren.');
        $root['schemaVersion']=self::VERSION;
        $items=is_array($root['items']??null)?$root['items']:[];
        if($key==='' || !preg_match('/^[a-z0-9][a-z0-9_-]{1,70}$/D',$key))
            throw new RuntimeException('Ungueltiger Regel-Key.');
        if($delete){
            if(!isset($items[$key]))throw new RuntimeException('Regel nicht vorhanden.');
            unset($items[$key]);
        }else{
            if(!isset($items[$key])&&count($items)>=75)
                throw new RuntimeException('Maximal 75 zentrale Sprachregeln.');
            $items[$key]=self::validateRule($values);
        }
        $root['items']=$items;
        $config['voiceRules']=$root;
        self::validateCollection($config);
        return $config;
    }

    public static function matching(array $configuration,string $text): ?array
    {
        $n=self::normalize($text);
        if($n===''||mb_strlen($text,'UTF-8')>160)return null;
        $matched=null;
        foreach(self::rules($configuration) as $key=>$rule){
            if(!is_array($rule) || empty($rule['enabled']))continue;
            foreach(self::phrases($rule['aliases']??[]) as $phrase){
                if(self::normalize($phrase)!==$n)continue;
                if($matched!==null)return ['status'=>'ambiguous'];
                $matched=['key'=>$key,'rule'=>$rule];
            }
        }
        return $matched;
    }

    /**
     * Read device capabilities from PRODUKTIVER RoomsCatalog, never from
     * arbitrary user-provided IPS-IDs. Excludes unconfirmed and read-only
     * devices as the current KIHouseController does.
     */
    public static function capabilities(array $catalog): array
    {
        $rooms=is_array($catalog['rooms']??null)?$catalog['rooms']:$catalog;
        $out=[];
        foreach($rooms as $rk=>$room) {
            if(!is_array($room)||$rk==='global'||$rk==='laptop'
                || !empty($room['aiTestRoom'])||!empty($room['excludeFromAI']))continue;
            foreach((array)($room['domains']??[]) as $domain=>$groups) {
                if(!is_array($groups))continue;
                $sets=in_array($domain,['heizung','jalousie'],true)
                    ?[$domain=>$groups]:$groups;
                foreach($sets as $group=>$devices) {
                    if(!is_array($devices))continue;
                    foreach($devices as $dk=>$dev) {
                        if(!is_array($dev))continue;
                        $ai=(array)($dev['ai']??[]);
                        $type=(string)($ai['type']??'');
                        $confirmed=($ai['review']['status']??'')==='confirmed';
                        $authorized=!empty($dev['kiEnabled'])
                            && array_key_exists('kiReadOnly',$dev)
                            && $dev['kiReadOnly']===false
                            && $confirmed
                            && (int)($ai['version']??0)===1;
                        $inputs=(array)($ai['variables']??[]);
                        $candidate=match($type) {
                            'light','switch','fan'=>['power'=>'control'],
                            'dimmer'=>['power'=>'control','brightness'=>'brightness'],
                            'heating'=>['targetTemperature'=>'targetTemperature'],
                            'blind'=>['targetPosition'=>'targetPosition'],
                            default=>[]
                        };
                        $roles=[];
                        foreach($candidate as $property=>$sourceRole) {
                            $id=(int)($inputs[$sourceRole]??0);
                            if(!$authorized || $id<=0 || !function_exists('IPS_VariableExists')
                                || !IPS_VariableExists($id)
                                || !function_exists('HasAction')||!HasAction($id))continue;
                            $typeId=(int)(IPS_GetVariable($id)['VariableType']??-1);
                            if($property==='power'?$typeId!==0:!in_array($typeId,[1,2],true))continue;
                            if($type==='blind' && in_array($id,[
                                (int)($inputs['position']??0),(int)($inputs['action']??0)],true))continue;
                            $roles[$property]=$id;
                        }
                        $key=$rk.'/'.$domain.'/'.$group.'/'.$dk;
                        $out[$key]=[
                            'deviceKey'=>$key,
                            'name'=>(string)($dev['title']??$dk),
                            'room'=>(string)($room['display']??$rk),
                            'type'=>$type,'roles'=>$roles,
                            'readOnly'=>!$authorized
                        ];
                    }
                }
            }
        }
        return $out;
    }

    public static function planScene(array $rule,array $catalog): array
    {
        $caps=self::capabilities($catalog);
        $plan=[];
        foreach((array)($rule['steps']??[]) as $step){
            if(!is_array($step))throw new RuntimeException('Ungueltige Szene.');
            $key=(string)($step['deviceKey']??'');
            $role=(string)($step['property']??'');
            if(!isset($caps[$key]['roles'][$role]))
                throw new RuntimeException('Geraet nicht bestaetigt/freigegeben: '.$key.'/'.$role);
            $id=(int)$caps[$key]['roles'][$role];
            if(!IPS_VariableExists($id)||!HasAction($id))
                throw new RuntimeException('Schaltaktion nicht vorhanden.');
            $vt=(int)(IPS_GetVariable($id)['VariableType']??-1);
            $v=$step['value']??null;
            if($role==='power'){
                $norm=self::normalize((string)$v);
                if(!in_array($norm,['true','false','1','0','ein','aus'],true)||$vt!==0)
                    throw new RuntimeException('Fuer Ein/Aus nur true oder false verwenden.');
                $val=in_array($norm,['true','1','ein'],true);
            }else{
                if(!is_numeric($v)||is_bool($v)||!in_array($vt,[1,2],true))
                    throw new RuntimeException('Zahlenwert fuer '.$role.' erwartet.');
                $val=(float)$v;
                $min=$role==='targetTemperature'?6.0:0.0;
                $max=$role==='targetTemperature'?30.0:100.0;
                if(!is_finite($val)||$val<$min||$val>$max)
                    throw new RuntimeException('Sollwert ausserhalb des sicheren Bereichs.');
                if($vt===1)$val=(int)round($val);
            }
            $plan[]=['id'=>$id,'value'=>$val,'name'=>$caps[$key]['name'],
                'role'=>$role,'deviceKey'=>$key];
        }
        if(!$plan||count($plan)>12)throw new RuntimeException('Leere oder zu grosse Szene.');
        return $plan;
    }

    public static function run(array $configuration,array $catalog,string $text,array $context=[]): array
    {
        $match=self::matching($configuration,$text);
        if($match===null)return ['matched'=>false];
        if(isset($match['status']))return ['matched'=>true,'ok'=>false,
            'answer'=>'Der Befehl ist mehrdeutig. Bitte die Sprachregeln pruefen.'];
        $rule=self::validateRule((array)$match['rule']);
        $key=(string)$match['key'];
        $answer=$rule['answer']!==''?$rule['answer']:
            ($rule['kind']==='answer'?'':'Die Aktion wurde gestartet.');
        $base=['matched'=>true,'ruleKey'=>$key,
            'kind'=>$rule['kind'],'imageUrl'=>$rule['imageUrl']];
        if($rule['kind']==='answer')return $base+['ok'=>true,'answer'=>$answer];
        if(!$rule['confirmed'])return $base+['ok'=>false,
            'answer'=>'Dieser Sprachbefehl ist noch nicht freigegeben.'];
        if($rule['kind']==='script'){
            $id=$rule['scriptId'];
            if($id<=0||!function_exists('IPS_ScriptExists')||!IPS_ScriptExists($id)
                || !function_exists('IPS_RunScript'))
                return $base+['ok'=>false,'answer'=>'Das ausgewaehlte Skript ist nicht verfuegbar.'];
            $forbidden=(array)($context['forbiddenScriptIds']??[]);
            if(in_array($id,$forbidden,true))
                return $base+['ok'=>false,'answer'=>'Systemskripte duerfen nicht als Szene gestartet werden.'];
            try{
                IPS_RunScript($id); // No arbitrary Alexa arguments / no LLM-generated PHP.
                return $base+['ok'=>true,'answer'=>$answer];
            }catch(Throwable $ignored){
                return $base+['ok'=>false,'answer'=>'Skript konnte nicht gestartet werden.'];
            }
        }
        try{
            $plan=self::planScene($rule,$catalog); // Validate ALL before first write.
        }catch(Throwable $ignored){
            return $base+['ok'=>false,'answer'=>'Szene nicht ausgefuehrt: '
                .'mindestens ein Geraet ist nicht freigegeben oder ungueltig.'];
        }
        $completed=0;
        foreach($plan as $action){
            try {
                if(RequestAction($action['id'],$action['value']))$completed++;
                else break;
            }catch(Throwable $ignored){break;}
        }
        if($completed!==count($plan))
            return $base+['ok'=>false,'executed'=>$completed,'total'=>count($plan),
                'answer'=>'Szene nur teilweise ausgefuehrt. Bitte den Geraetestatus pruefen.'];
        return $base+['ok'=>true,'executed'=>$completed,'total'=>count($plan),
            'answer'=>$answer];
    }

    public static function legacyDrafts(): array
    {
        // Existing hard-coded answers are suggestions, never auto-enabled.
        return [
            'wer_bist_du'=>[
                'title'=>'Wer bist du?',
                'aliases'=>['Wer bist du','Wie heißt du','Mit wem spreche ich'],
                'kind'=>'answer','answer'=>'Ich bin deine Alexa-Haussteuerung.',
                'imageUrl'=>'','scriptId'=>0,'steps'=>[],'enabled'=>false,'confirmed'=>false
            ],
            'wer_ist_andreas'=>[
                'title'=>'Wer ist Andreas?',
                'aliases'=>['Wer ist Andreas','Kennst du Andreas','Wer ist dein Programmierer'],
                'kind'=>'answer','answer'=>'Andreas hat diese Haussteuerung programmiert.',
                'imageUrl'=>'','scriptId'=>0,'steps'=>[],'enabled'=>false,'confirmed'=>false
            ],
            'wer_ist_andrea'=>[
                'title'=>'Wer ist Andrea?',
                'aliases'=>['Wer ist Andrea','Kennst du Andrea'],
                'kind'=>'answer','answer'=>'Diese Antwort kannst du hier frei festlegen.',
                'imageUrl'=>'','scriptId'=>0,'steps'=>[],'enabled'=>false,'confirmed'=>false
            ],
            'jalousien_oeffnen'=>[
                'title'=>'Jalousien öffnen',
                'aliases'=>['Jalousien öffnen','Jalousie auf'],
                'kind'=>'script','answer'=>'Die Jalousien werden geöffnet.',
                'imageUrl'=>'','scriptId'=>0,'steps'=>[],'enabled'=>false,'confirmed'=>false
            ],
            'jalousien_schliessen'=>[
                'title'=>'Jalousien schließen',
                'aliases'=>['Jalousien schließen','Jalousie ab'],
                'kind'=>'script','answer'=>'Die Jalousien werden geschlossen.',
                'imageUrl'=>'','scriptId'=>0,'steps'=>[],'enabled'=>false,'confirmed'=>false
            ]
        ];
    }
}
