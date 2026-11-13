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


    /**
     * Static, reviewed literal parameters; NO expressions, interpolation,
     * callable PHP or user-utterance substitution.
     *
     * Input can be the JSON textbox from EDIT or a typed associative array.
     * Parameters land in $_IPS in the selected script via IPS_RunScriptEx.
     */
    public static function scriptParameters(mixed $input): array
    {
        if(is_string($input)){
            $input=trim($input);
            if($input==='' || $input==='{}')return [];
            if(strlen($input)>8192)
                throw new RuntimeException('Skriptparameter zu lang.');
            try{$input=json_decode($input,true,16,JSON_THROW_ON_ERROR);}
            catch(JsonException $e){throw new RuntimeException('Skriptparameter muessen gueltiges JSON sein.');}
        }
        if($input===null)return [];
        if(!is_array($input) || (array_is_list($input)&&$input!==[]))
            throw new RuntimeException('Skriptparameter muessen ein JSON-Objekt sein.');
        if(count($input)>12)throw new RuntimeException('Maximal 12 Skriptparameter erlaubt.');
        $result=[];
        foreach($input as $key=>$value){
            if(!is_string($key)||!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/D',$key))
                throw new RuntimeException('Ungueltiger Skriptparameter-Name.');
            if(in_array(strtoupper($key),[
                'SENDER','SELF','INSTANCE','EVENT','THREAD','SCRIPT','TARGET'
            ],true))
                throw new RuntimeException('Reservierte IPS-Systemvariable: '.$key);
            if(!is_string($value)&&!is_int($value)&&!is_float($value)&&!is_bool($value)
                && $value!==null)
                throw new RuntimeException('Nur feste Zeichenketten, Zahlen und Boolwerte als Parameter.');
            if(is_string($value)&&(strlen($value)>4096||str_contains($value,"\0")))
                throw new RuntimeException('Skriptparameter-Wert zu lang oder ungueltig.');
            if(is_float($value)&&!is_finite($value))
                throw new RuntimeException('Ungueltiger numerischer Parameter.');
            $result[$key]=$value;
        }
        return $result;
    }

    /** Filename under user/icons, served by the existing token-bound hook. */
    public static function iconFile(string $filename): string
    {
        $filename=trim($filename);
        if($filename==='' )return '';
        if(strlen($filename)>110 ||
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.(?:png|jpg|jpeg|gif)$/iD',$filename)
            || str_contains($filename,'..'))
            throw new RuntimeException('Bilddatei muss ein Dateiname aus user/icons sein.');
        return $filename;
    }

    /**
     * Validate the exact typed value without switching the device.
     * A Boolean true must never be silently cast to an integer blind command.
     */
    public static function validateVariableAction(int $id,string $raw): mixed
    {
        if($id<=0 || !function_exists('IPS_VariableExists')
            || !IPS_VariableExists($id))
            throw new RuntimeException('Die ausgewaehlte Schaltvariable existiert nicht.');
        if(!function_exists('HasAction')||!HasAction($id))
            throw new RuntimeException('Die Schaltvariable hat keine IP-Symcon Aktion.');
        $type=(int)(IPS_GetVariable($id)['VariableType']??-1);
        $value=trim($raw);
        if($type===0){
            if(!in_array(strtolower($value),['true','false','1','0'],true))
                throw new RuntimeException('Boolean-Variable: true/false oder 1/0 eingeben.');
            return in_array(strtolower($value),['true','1'],true);
        }
        if($type===1){
            if(!preg_match('/^[+-]?[0-9]+$/D',$value))
                throw new RuntimeException('Integer-Variable: ganze Zahl eingeben (Jalousie: 1=auf, 2=zu, falls so konfiguriert).');
            $int=filter_var($value,FILTER_VALIDATE_INT);
            if($int===false)
                throw new RuntimeException('Schaltwert ist keine gueltige Integer-Zahl.');
            return $int;
        }
        if($type===2){
            if(!is_numeric($value))
                throw new RuntimeException('Float-Variable: gueltige Zahl eingeben.');
            $float=(float)$value;
            if(!is_finite($float))
                throw new RuntimeException('Schaltwert liegt ausserhalb des gueltigen Zahlenbereichs.');
            return $float;
        }
        throw new RuntimeException('Nur Boolean-, Integer- oder Float-Schaltvariablen werden unterstuetzt.');
    }

    public static function validateRule(array $rule): array
    {
        $kind=(string)($rule['kind']??'');
        if(!in_array($kind,['answer','script','scene','variable','read'],true))
            throw new RuntimeException('Regeltyp muss Antwort, IPS-Skript, Geraeteszene, Variable schreiben oder Variable lesen sein.');
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
        $imageFile=self::iconFile((string)($rule['imageFile']??''));
        if($imageFile!==''&&$image!=='')
            throw new RuntimeException('Entweder lokale Bilddatei ODER Bild-URL verwenden.');
        $cardTitle=trim((string)($rule['cardTitle']??''));
        $cardText=trim((string)($rule['cardText']??''));
        if(mb_strlen($cardTitle,'UTF-8')>120 || mb_strlen($cardText,'UTF-8')>800)
            throw new RuntimeException('Titel/Text der Bildkarte ist zu lang.');
        $scriptParams=self::scriptParameters($rule['scriptParams']??[]);
        $intentName=trim((string)($rule['intentName']??''));
        if($intentName!=='' && (
            !preg_match('/^[A-Z][A-Za-z0-9_]{2,55}Intent$/D',$intentName)
            || in_array(strtolower($intentName),[
                'gethaus','visuintent','homeagentfrageintent','hauswelcheintent',
                'hauswieintent','hauswasintent','hausistintent','haussindintent'
            ],true)))
            throw new RuntimeException('Ungueltiger oder reservierter Alexa-Intent-Name.');
        $scriptId=(int)($rule['scriptId']??0);
        $steps=is_array($rule['steps']??null)?array_values($rule['steps']):[];
        if(count($steps)>12)throw new RuntimeException('Maximal 12 Geraeteaktionen pro Szene.');
        $variableId=(int)($rule['variableId']??0);
        $variableValue=$rule['variableValue']??'';
        $readVariableId=(int)($rule['readVariableId']??0);
        if($variableId<0||$readVariableId<0)
            throw new RuntimeException('Ungueltige Variablen-ID.');
        if(!is_scalar($variableValue) || strlen((string)$variableValue)>100)
            throw new RuntimeException('Ungueltiger fester Schaltwert.');
        if($kind==='variable' && !empty($rule['enabled']) && $variableId===0)
            throw new RuntimeException('Schaltvariable fuer aktive Regel waehlen.');
        if($kind==='read' && !empty($rule['enabled']) && $readVariableId===0)
            throw new RuntimeException('Lesevariable fuer aktive Regel waehlen.');
        if(in_array($kind,['variable','read'],true) && !empty($rule['enabled'])
            && (!function_exists('IPS_VariableExists')
                || !IPS_VariableExists($kind==='read'?$readVariableId:$variableId)))
            throw new RuntimeException('Ausgewaehlte Variable existiert nicht.');
        // Activation is checked before publishing; no hardware action occurs.
        if($kind==='variable' && !empty($rule['enabled']) && $variableId>0)
            self::validateVariableAction($variableId,(string)$variableValue);
        if($kind==='answer' && $answer==='')
            throw new RuntimeException('Ein Fragetext benoetigt eine Antwort.');
        // Unvollstaendige Vorlagen sind als deaktivierter EDIT-Entwurf erlaubt.
        // Bei Aktivierung muss der gewaehlte Ausfuehrungspfad vollstaendig sein.
        if($kind==='script' && $scriptId<=0 && !empty($rule['enabled']))
            throw new RuntimeException('Vor Aktivierung ein IP-Symcon-Skript auswaehlen.');
        if($kind==='scene' && !$steps && !empty($rule['enabled']))
            throw new RuntimeException('Vor Aktivierung mindestens eine Geraeteaktion anlegen.');
        if($kind==='script' && $scriptId>0
            && (!function_exists('IPS_ScriptExists')||!IPS_ScriptExists($scriptId)))
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
            'intentName'=>$intentName,
            'imageUrl'=>$image,'imageFile'=>$imageFile,
            'cardTitle'=>$cardTitle,'cardText'=>$cardText,
            'scriptId'=>$kind==='script'?$scriptId:0,
            'scriptParams'=>$kind==='script'?$scriptParams:[],
            'variableId'=>$kind==='variable'?$variableId:0,
            'variableValue'=>$kind==='variable'?(string)$variableValue:'',
            'readVariableId'=>$kind==='read'?$readVariableId:0,
            'origin'=>($rule['origin']??'')==='legacy'?'legacy':'custom',
            'migrationNote'=>mb_substr(trim((string)($rule['migrationNote']??'')),0,400,'UTF-8'),
            'steps'=>$kind==='scene'?$steps:[],
            'enabled'=>!empty($rule['enabled']),
            'confirmed'=>!empty($rule['confirmed'])
        ];
    }

    public static function validateCollection(array $config): void
    {
        $seen=[];$seenIntents=[];
        foreach(self::rules($config) as $key=>$r) {
            if(!is_string($key) || !preg_match('/^[a-z0-9][a-z0-9_-]{1,70}$/D',$key)
                || !is_array($r)) throw new RuntimeException('Ungueltiger Regelschluessel.');
            $r=self::validateRule($r);
            if($r['intentName']!==''){
                $intent=strtolower($r['intentName']);
                if(isset($seenIntents[$intent])&&$seenIntents[$intent]!==$key)
                    throw new RuntimeException('Alexa-Intent doppelt zugeordnet: '.$r['intentName']);
                $seenIntents[$intent]=$key;
            }
            foreach($r['aliases'] as $phrase){
                $n=self::normalize($phrase);
                if(isset($seen[$n]) && $seen[$n]!==$key)
                    throw new RuntimeException('Mehrdeutiger Sprachbefehl: '.$phrase);
                $seen[$n]=$key;
            }
            if($r['enabled'] && !$r['confirmed'] && !in_array($r['kind'],['answer','read'],true))
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

    public static function matching(array $configuration,string $text,string $intentName=''): ?array
    {
        $n=self::normalize($text);
        if(mb_strlen($text,'UTF-8')>160)return null;
        $matched=null;
        foreach(self::rules($configuration) as $key=>$rule){
            if(!is_array($rule) || empty($rule['enabled']))continue;
            if($n!==''){
                foreach(self::phrases($rule['aliases']??[]) as $phrase){
                    if(self::normalize($phrase)!==$n)continue;
                    if($matched!==null)return ['status'=>'ambiguous'];
                    $matched=['key'=>$key,'rule'=>$rule,'method'=>'alias','alias'=>$phrase];
                }
            }
        }
        if($matched!==null)return $matched;
        // Alexa may deliver only a verified slot-free custom Intent name.
        // No fallback to an LLM guess or arbitrary partial phrase match.
        if($intentName!=='' && preg_match('/^[A-Za-z][A-Za-z0-9_]{2,55}Intent$/D',$intentName))
            foreach(self::rules($configuration) as $key=>$rule)
                if(is_array($rule)&&!empty($rule['enabled'])
                    && (string)($rule['intentName']??'')!==''
                    && strcasecmp((string)$rule['intentName'],$intentName)===0)
                    return ['key'=>$key,'rule'=>$rule,'method'=>'intent'];
        return null;
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

    private static function presentation(string $key,array $rule): array
    {
        $answer=$rule['answer']!==''?$rule['answer']:
            ($rule['kind']==='answer'?'':'Die Aktion wurde gestartet.');
        return ['matched'=>true,'ruleKey'=>$key,'title'=>$rule['title'],
            'kind'=>$rule['kind'],'imageUrl'=>$rule['imageUrl'],'imageFile'=>$rule['imageFile'],
            'cardTitle'=>$rule['cardTitle']!==''?$rule['cardTitle']:$rule['title'],
            'cardText'=>$rule['cardText']!==''?$rule['cardText']:$answer,
            'answer'=>$answer];
    }

    private static function scriptStartError(array $rule,array $forbidden): ?string
    {
        $id=$rule['scriptId'];
        if($id<=0||!function_exists('IPS_ScriptExists')||!IPS_ScriptExists($id)
            || (!function_exists('IPS_RunScript')&&!function_exists('IPS_RunScriptEx')))
            return 'Das ausgewaehlte Skript ist nicht verfuegbar.';
        if(in_array($id,$forbidden,true))
            return 'Systemskripte duerfen nicht als Szene gestartet werden.';
        return null;
    }

    /** Describes a matched rule, with no route into live execution. */
    public static function preview(array $match,?array $catalog,array $forbidden=[]): array
    {
        if(isset($match['status']))return ['matched'=>true,'ok'=>false,'matchMethod'=>'ambiguous',
            'error'=>'Der Befehl ist mehrdeutig. Bitte die Sprachregeln prüfen.'];
        $key=(string)$match['key'];
        $raw=$match['rule'];
        $result=['matched'=>true,'ruleKey'=>$key,'matchMethod'=>$match['method'],
            'matchedAlias'=>$match['alias']??null,'title'=>(string)($raw['title']??$key),
            'kind'=>(string)($raw['kind']??'')];
        try {
            $rule=self::validateRule($raw);
            $result+=self::presentation($key,$rule);
            $action=match($rule['kind']){
                'answer'=>['type'=>'answer'],
                'read'=>['type'=>'read','variableId'=>$rule['readVariableId']],
                'variable'=>['type'=>'variable','variableId'=>$rule['variableId'],
                    'value'=>self::validateVariableAction($rule['variableId'],$rule['variableValue'])],
                'script'=>['type'=>'script','scriptId'=>$rule['scriptId'],
                    'parameterNames'=>array_keys($rule['scriptParams'])],
                'scene'=>['type'=>'scene','steps'=>$rule['steps']]
            };
            $result['plannedAction']=$action;
            if(!$rule['confirmed']&&!in_array($rule['kind'],['answer','read'],true))
                throw new RuntimeException('Dieser Sprachbefehl ist noch nicht freigegeben.');
            if($rule['kind']==='script'){
                $error=self::scriptStartError($rule,$forbidden);
                if($error!==null)throw new RuntimeException($error);
                if($rule['scriptParams']!==[]&&!function_exists('IPS_RunScriptEx'))
                    throw new RuntimeException('IPS_RunScriptEx nicht verfügbar.');
            }
            if($rule['kind']==='scene'){
                if($catalog===null)
                    throw new RuntimeException('RoomsCatalog PROD ist kein lesbares statisches Array. '
                        .'Die vorgesehenen Geräteschritte sind sichtbar, aber nicht geprüft.');
                $result['plannedAction']['steps']=self::planScene($rule,$catalog);
            }
            // For read rules, keep {value} in the answer template. The preview
            // only checks the variable exists, it does not read a live value.
            return $result+['ok'=>true];
        }catch(Throwable $error){
            return $result+['ok'=>false,'error'=>$error->getMessage()];
        }
    }

    public static function run(array $configuration,array $catalog,string $text,array $context=[]): array
    {
        $match=self::matching($configuration,$text,(string)($context['intent']??''));
        if($match===null)return ['matched'=>false];
        if(isset($match['status']))return ['matched'=>true,'ok'=>false,
            'answer'=>'Der Befehl ist mehrdeutig. Bitte die Sprachregeln pruefen.'];
        $rule=self::validateRule((array)$match['rule']);
        $key=(string)$match['key'];
        $base=self::presentation($key,$rule);
        $answer=$base['answer'];
        unset($base['answer']);
        if($rule['kind']==='answer')return $base+['ok'=>true,'answer'=>$answer];
        if($rule['kind']==='read'){
            $id=$rule['readVariableId'];
            if($id<=0||!function_exists('IPS_VariableExists')||!IPS_VariableExists($id))
                return $base+['ok'=>false,'answer'=>'Messwert ist nicht verfuegbar.'];
            try {
                $value=function_exists('GetValueFormatted')
                    ?GetValueFormatted($id):GetValue($id);
                $answer=str_replace('{value}',(string)$value,$answer);
                $base['cardText']=str_replace('{value}',(string)$value,$base['cardText']);
                return $base+['ok'=>true,'answer'=>$answer];
            }catch(Throwable $ignored){
                return $base+['ok'=>false,'answer'=>'Messwert konnte nicht gelesen werden.'];
            }
        }
        if(!$rule['confirmed'])return $base+['ok'=>false,
            'answer'=>'Dieser Sprachbefehl ist noch nicht freigegeben.'];
        if($rule['kind']==='variable'){
            try{
                $value=self::validateVariableAction($rule['variableId'],$rule['variableValue']);
                if(RequestAction($rule['variableId'],$value)!==true)
                    throw new RuntimeException('Variable nicht geschaltet.');
                return $base+['ok'=>true,'answer'=>$answer,'executed'=>1];
            }catch(Throwable $ignored){
                return $base+['ok'=>false,'answer'=>'Schaltaktion wurde nicht ausgefuehrt.'];
            }
        }
        if($rule['kind']==='script'){
            $id=$rule['scriptId'];
            $error=self::scriptStartError($rule,(array)($context['forbiddenScriptIds']??[]));
            if($error!==null)return $base+['ok'=>false,'answer'=>$error];
            try{
                // IPS_RunScriptEx forwards configured literals to $_IPS.
                // Runtime speech/input never reaches the script as a parameter.
                $params=$rule['scriptParams'];
                if($params!==[]){
                    if(!function_exists('IPS_RunScriptEx'))
                        throw new RuntimeException('IPS_RunScriptEx nicht verfuegbar.');
                    $started=IPS_RunScriptEx($id,$params);
                }elseif(function_exists('IPS_RunScript')){
                    $started=IPS_RunScript($id);
                }else{
                    $started=IPS_RunScriptEx($id,[]);
                }
                if($started===false)
                    throw new RuntimeException('Skriptstart abgelehnt.');
                // RunScript/RunScriptEx start asynchronously; report only started.
                return $base+['ok'=>true,'answer'=>$answer,'scriptStarted'=>true];
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
