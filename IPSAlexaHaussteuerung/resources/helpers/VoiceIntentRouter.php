<?php
declare(strict_types=1);

/**
 * Deterministischer Alexa-Sprach-/UI-Router (kein OpenAI-Aufruf).
 * Datenschutz: nicht den Sprachtext, Device-ID, User-ID, Session-ID,
 * APL-Presentation-Token oder volle Event-Arguments speichern/loggen.
 */
function iah_vir_norm(string $text): string
{
    $text=mb_strtolower(trim($text),'UTF-8');
    $text=str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],$text);
    $text=preg_replace('/[^\pL\pN\s_-]/u',' ',$text)??'';
    return trim(preg_replace('/\s+/u',' ',$text)??'');
}
function iah_vir_aliases(array $dynamic=[]): array
{
    $map=[
        'licht'=>'licht','lichter'=>'licht','beleuchtung'=>'licht',
        'jalousie'=>'jalousie','jalousien'=>'jalousie','rollladen'=>'jalousie','rolllaeden'=>'jalousie',
        'rollo'=>'jalousie','rollos'=>'jalousie','beschattung'=>'jalousie',
        'heizung'=>'heizung','temperatur'=>'heizung',
        'lueftung'=>'lueftung','luefter'=>'lueftung','ventilation'=>'lueftung',
        'geraete'=>'geraete','geraet'=>'geraete',
        'bewaesserung'=>'bewaesserung','gartenbewaesserung'=>'bewaesserung',
        'einstellungen'=>'einstellungen','einstellung'=>'einstellungen',
        'sicherheit'=>'sicherheit','listen'=>'listen','energie'=>'energie',
        'kamera'=>'kamera','kameras'=>'kamera','info'=>'info',
        'informationen'=>'info','szene'=>'szene','szenen'=>'szene',
    ];
    foreach ($dynamic as $item) {
        if (!is_array($item)) continue;
        $route=iah_vir_norm((string)($item['route']??''));
        if ($route==='' || !preg_match('/^[a-z0-9_-]{2,50}$/D',$route)) continue;
        foreach (array_merge([$route,(string)($item['title']??''),(string)($item['roomDomain']??'')],(array)($item['synonyms']??[])) as $label) {
            if(!is_scalar($label))continue;
            $key=iah_vir_norm((string)$label);
            if ($key!=='' && mb_strlen($key,'UTF-8')<=60 && !isset($map[$key])) $map[$key]=$route;
        }
    }
    return $map;
}
function iah_vir_classify(array $input,array $saved=[]): array
{
    $text=iah_vir_norm((string)($input['text']??''));
    $type=(string)($input['requestType']??'');
    $intent=(string)($input['intent']??'');
    $args=(array)($input['aplArgs']??[]);
    $aliases=iah_vir_aliases((array)($input['routes']??[]));
    $current=(string)($saved['domain']??'');
    // Kontext ist nur 30 Minuten gültig; niemals als Aktionsfreigabe behandeln.
    if (time()-(int)($saved['updatedUnix']??0)>1800) $current='';
    $result=['category'=>'passthrough','route'=>'','reason'=>'legacy',
        'contextDomain'=>$current,'apl'=>false,'clarify'=>false];
    if ($type==='Alexa.Presentation.APL.UserEvent' || count($args)>0) {
        $result['apl']=true;
        if (count($args)>=2 && (string)$args[0]==='GetHaus') {
            $key=iah_vir_norm((string)$args[1]);
            if ($key==='home' && isset($args[2])) $key=iah_vir_norm((string)$args[2]);
            if(in_array($key,['home','hauptmenue','startseite','zurueck'],true)){
                return array_replace($result,['category'=>'apl_navigation','route'=>'main_launch','reason'=>'apl_main']);
            }
            if(isset($aliases[$key]))return array_replace($result,
                ['category'=>'apl_navigation','route'=>$aliases[$key],'reason'=>'apl_nav']);
        }
        if($type==='Alexa.Presentation.APL.UserEvent')return array_replace($result,
            ['category'=>'apl_action','reason'=>'apl_other']);
    }
    if ($text==='')return $result;
    if (in_array($text,['zurueck','gehe zurueck','geh zurueck','hauptmenue','hauptmenue oeffnen',
        'startseite','zur hauptseite','zur startseite','home','hauptansicht'],true)) {
        return array_replace($result,['category'=>'navigation','route'=>'main_launch','reason'=>'voice_back']);
    }
    if(isset($aliases[$text]))return array_replace($result,
        ['category'=>'navigation','route'=>$aliases[$text],'reason'=>'bare_tile']);
    if (preg_match('/^(?:oeffne|oeffnen|zeige\s+(?:mir\s+)?(?:die\s+)?(?:kachel|seite|ansicht|menue)\s+(?:fuer\s+)?|gehe\s+(?:zu|zum|zur)\s+|geh\s+(?:zu|zum|zur)\s+|wechsle?\s+(?:zu|zum|zur)\s+|navigiere\s+(?:zu|zum|zur)\s+)(.+)$/u',$text,$m)) {
        $target=trim($m[1]);
        $target=preg_replace('/^(?:die|das|den|der|dem|zum|zur)\s+/u','',$target)??$target;
        if (in_array($target,['startseite','hauptmenue','home','hauptansicht'],true))
            return array_replace($result,['category'=>'navigation','route'=>'main_launch','reason'=>'explicit_home']);
        if(isset($aliases[$target]))return array_replace($result,
            ['category'=>'navigation','route'=>$aliases[$target],'reason'=>'explicit_tile']);
    }
    if(preg_match('/^(?:schalte|schalt|mach|stelle|stell|fahre|fahr|oeffne|schliesse|starte|start|stoppe|stop|dimme|dimm|aktiviere|deaktiviere|setze)\b/u',$text)) {
        return array_replace($result,['category'=>'action','reason'=>'explicit_action']);
    }
    if(preg_match('/^(?:wer|was|wann|wo|wie|wieviel|welche|welcher|welches|welchen|welchem|ist|sind|hat|haben|gibt|pruefe|pruef|kontrolliere|zaehle|zeige\s+mir|sag\s+mir|sage\s+mir|warum|kannst\s+du)\b/u',$text)) {
        return array_replace($result,['category'=>'query','reason'=>'explicit_query']);
    }
    // Kein erratenes Schalten bei unvollständigen Befehlen.
    if ($intent==='HomeAgentFrageIntent')return array_replace($result,
        ['category'=>'clarify','reason'=>'unclear_freetext','clarify'=>true]);
    return $result;
}
function iah_vir_execute(array $input,array &$data,int $now): array
{
    $op=(string)($input['op']??'');
    $device=(string)($input['deviceId']??'');
    $deviceHash=substr(hash('sha256','IAH-Voice-v1|'.$device),0,16);
    $devices=&$data['devices'];
    if(!is_array($devices??null))$devices=[];
    $old=(array)($devices[$deviceHash]??[]);
    if($op==='classify') {
        $decision=iah_vir_classify($input,$old);
        $kind=(string)$decision['category'];
        $diagnostic=[
            'at'=>date('c',$now),'deviceHash'=>$deviceHash,
            'category'=>$kind,'reason'=>(string)$decision['reason'],
            'route'=>(string)$decision['route'],
            'intent'=>substr(preg_replace('/[^a-zA-Z0-9_-]/','',(string)($input['intent']??''))??'',0,70),
            'requestType'=>substr(preg_replace('/[^a-zA-Z0-9_.-]/','',(string)($input['requestType']??''))??'',0,80),
            'chars'=>mb_strlen((string)($input['text']??''),'UTF-8'),
            'screen'=>(bool)($input['screen']??false),
            'viewportMode'=>substr(preg_replace('/[^a-zA-Z]/','',(string)($input['viewportMode']??''))??'',0,16),
            'viewportShape'=>substr(preg_replace('/[^a-zA-Z]/','',(string)($input['viewportShape']??''))??'',0,16),
            'aplSource'=>substr(preg_replace('/[^a-zA-Z]/','',(string)($input['aplSource']??''))??'',0,40),
            'aplArgsCount'=>min(20,count((array)($input['aplArgs']??[]))),
            'contextDomain'=>(string)$decision['contextDomain'],
        ];
        $data['last']=$diagnostic;
        $data['history']??=[];
        array_unshift($data['history'],$diagnostic);
        $data['history']=array_slice($data['history'],0,80);
        $data['counts'][$kind]=1+(int)($data['counts'][$kind]??0);
        $decision['deviceHash']=$deviceHash;
        return $decision;
    }
    if($op==='complete') {
        $route=(string)($input['route']??'');
        if (in_array($route,['main_launch','licht','jalousie','heizung','lueftung','geraete','bewaesserung',
            'einstellungen','sicherheit','listen','energie','kamera','info','szene'],true)
            || preg_match('/^[a-z][a-z0-9_-]{1,49}$/D',$route)===1) {
            $prior=(string)($old['domain']??'');
            $devices[$deviceHash]=['domain'=>$route,'previous'=>$prior,'updatedUnix'=>$now,
                'screen'=>(bool)($input['screen']??false)];
            $data['lastRoute']=['at'=>date('c',$now),'deviceHash'=>$deviceHash,
                'domain'=>$route,'previous'=>$prior,
                'source'=>substr(preg_replace('/[^a-zA-Z0-9_-]/','',(string)($input['source']??''))??'',0,40)];
        }
        return ['ok'=>true];
    }
    return ['ok'=>false,'reason'=>'invalid_operation'];
}
function iah_vir_ip_symcon(array $input): array
{
    $instanceId=(int)($input['instanceId']??0);
    if($instanceId<=0||!function_exists('IPS_GetObjectIDByIdent'))return ['ok'=>false];
    $category=(int)@IPS_GetObjectIDByIdent('iahVoiceDiag',$instanceId);
    $storage=$category>0?(int)@IPS_GetObjectIDByIdent('iahVoiceState',$category):0;
    if($storage<=0||!IPS_VariableExists($storage))return ['ok'=>false];
    $lock='IAH.VoiceRouter.'.$instanceId;
    if(!IPS_SemaphoreEnter($lock,1000))return ['ok'=>false,'reason'=>'busy'];
    try {
        $data=json_decode((string)GetValueString($storage),true);
        if(!is_array($data))$data=[];
        $now=time();
        $result=iah_vir_execute($input,$data,$now);
        // Ablaufende Geräte-Kontexte beschränken; keine Rohkennungen speichern.
        foreach ((array)($data['devices']??[]) as $hash=>$device) {
            if($now-(int)($device['updatedUnix']??0)>86400)unset($data['devices'][$hash]);
        }
        if(count((array)($data['devices']??[]))>32) {
            uasort($data['devices'],static fn($a,$b)=>(int)($b['updatedUnix']??0)<=>(int)($a['updatedUnix']??0));
            $data['devices']=array_slice($data['devices'],0,32,true);
        }
        SetValueString($storage,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
        foreach ([
            'iahVoiceLast'=>json_encode($data['last']??[],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),
            'iahVoiceHistory'=>json_encode($data['history']??[],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),
            'iahVoiceCounters'=>json_encode($data['counts']??[],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),
            'iahVoiceContexts'=>json_encode($data['devices']??[],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)
        ] as $ident=>$value) {
            $id=(int)@IPS_GetObjectIDByIdent($ident,$category);
            if($id>0&&IPS_VariableExists($id))SetValueString($id,$value);
        }
        if(($input['op']??'')==='classify') {
            $last=$data['last']??[];
            IPS_LogMessage('Alexa','VoiceRouter '.json_encode($last,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
        }
        return $result;
    } catch(Throwable $e) {
        IPS_LogMessage('Alexa','VoiceRouter.error type='.get_class($e));
        return ['ok'=>false];
    } finally { IPS_SemaphoreLeave($lock); }
}
if (isset($_IPS['voiceIntentRouterJson']) && is_string($_IPS['voiceIntentRouterJson'])) {
    try {
        $args=json_decode($_IPS['voiceIntentRouterJson'],true,512,JSON_THROW_ON_ERROR);
        echo json_encode(iah_vir_ip_symcon((array)$args),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    } catch(Throwable $e) {
        echo json_encode(['ok'=>false,'reason'=>'invalid_input']);
    }
}
