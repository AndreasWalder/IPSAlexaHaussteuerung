<?php

declare(strict_types=1);

/**
 * Alexa KI -> produktiver RoomsCatalog -> sichere IP-Symcon-Aktionen.
 * Keine Home-Agent-Installation, keine freien PHP-/Variablen-ID-Aufrufe.
 * Bestandsgeräte ohne kiReadOnly-Eintrag bleiben ausschließlich lesbar.
 */
function iah_rc_log(string $event, array $details = []): void
{
    IPS_LogMessage('Alexa', 'KIRoomsCatalog.' . $event .
        ($details ? ' ' . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : ''));
}
function iah_rc_norm(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    return trim(preg_replace('/[^a-z0-9]+/u', ' ', str_replace(
        ['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $text)) ?? '');
}
function iah_rc_index(int $scriptId): array
{
    if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) throw new RuntimeException('RoomsCatalog-Skript fehlt');
    $file = IPS_GetScriptFile($scriptId);
    if (!is_file($file)) $file = rtrim(IPS_GetKernelDir(), '\\/') . '/scripts/' . $file;
    if (!is_file($file)) throw new RuntimeException('RoomsCatalog-Datei fehlt');
    $rooms = require $file;
    if (!is_array($rooms)) throw new RuntimeException('RoomsCatalog ist ungültig');
    if (is_array($rooms['rooms'] ?? null)) $rooms = $rooms['rooms'];
    $devices = [];
    foreach ($rooms as $roomKey => $room) {
        if (!is_array($room) || $roomKey === 'global') continue;
        $roomName = (string)($room['display'] ?? $roomKey);
        $floor = (string)($room['floor'] ?? '');
        $domains = (array)($room['domains'] ?? []);
        foreach ($domains as $domain => $groups) {
            if (!is_array($groups)) continue;
            $sets = [];
            if (in_array($domain, ['heizung', 'jalousie'], true)) $sets[$domain] = $groups;
            if ($domain === 'licht') foreach (['switches', 'dimmers', 'status'] as $g)
                if (is_array($groups[$g] ?? null)) $sets[$g] = $groups[$g];
            if ($domain === 'lueftung' && is_array($groups['fans'] ?? null)) $sets['fans'] = $groups['fans'];
            foreach ($sets as $group => $items) {
                foreach ($items as $key => $dev) {
                    if (!is_array($dev) || (array_key_exists('kiEnabled',$dev) && !$dev['kiEnabled'])) continue;
                    $readFields = match ($group) {
                        'heizung' => ['ist','soll','eingestellt'],
                        'jalousie' => ['wert'],
                        'dimmers' => ['value','state'],
                        'status' => ['value'],
                        default => ['state','value']
                    };
                    $readId = 0;
                    foreach ($readFields as $field) if ((int)($dev[$field] ?? 0) > 0) {
                        $readId = (int)$dev[$field]; break;
                    }
                    if ($readId <= 0 || !IPS_VariableExists($readId)) continue;
                    $writeId = match ($group) {
                        'switches', 'fans' => (int)($dev['toggle'] ?? 0),
                        'dimmers' => (int)($dev['set'] ?? 0),
                        'heizung' => (int)($dev['soll'] ?? $dev['eingestellt'] ?? 0),
                        default => 0
                    };
                    $writable = array_key_exists('kiReadOnly',$dev) && !$dev['kiReadOnly']
                        && in_array($group,['switches','fans','dimmers','heizung'],true)
                        && $writeId > 0 && IPS_VariableExists($writeId)
                        && function_exists('HasAction') && HasAction($writeId);
                    $id = (string)$roomKey . '/' . $domain . '/' . $group . '/' . $key;
                    $devices[$id] = [
                        'id' => $id, 'name' => (string)($dev['title'] ?? $key),
                        'room' => $roomName, 'roomKey' => (string)$roomKey,
                        'floor' => $floor, 'domain' => (string)$domain, 'group' => (string)$group,
                        'synonyms' => array_filter(array_map('trim',explode(',',(string)($dev['synonyms'] ?? '')))),
                        'read' => $readId, 'write' => $writeId, 'writable' => $writable,
                        'setpoint' => (int)($dev['eingestellt'] ?? $dev['soll'] ?? 0),
                        'min' => is_numeric($dev['min'] ?? null) ? (float)$dev['min'] : null,
                        'max' => is_numeric($dev['max'] ?? null) ? (float)$dev['max'] : null
                    ];
                }
            }
        }
    }
    return $devices;
}
function iah_rc_find(array $devices, array $filters): array
{
    $found = [];
    $deviceKey = (string)($filters['deviceKey'] ?? '');
    if ($deviceKey !== '') return isset($devices[$deviceKey]) ? [$devices[$deviceKey]] : [];
    foreach ($devices as $dev) {
        $ok = true;
        foreach (['name','room','floor','domain','group'] as $field) {
            $wanted = iah_rc_norm((string)($filters[$field] ?? ''));
            if ($wanted === '' || $wanted === 'all' || $wanted === 'alle') continue;
            $choices = $field === 'name'
                ? array_merge([$dev['name'],$dev['id']],$dev['synonyms'])
                : ($field === 'room' ? [$dev['room'],$dev['roomKey']] : [$dev[$field]]);
            $match = false;
            foreach ($choices as $choice) {
                $value = iah_rc_norm((string)$choice);
                if ($value !== '' && ($value === $wanted || str_contains($value,$wanted))) $match = true;
            }
            if (!$match) { $ok = false; break; }
        }
        if ($ok) $found[] = $dev;
    }
    return $found;
}
function iah_rc_state(array $dev): array
{
    $v = GetValue($dev['read']);
    $state = is_bool($v) ? ($v ? 'an' : 'aus') : $v;
    $out = ['deviceKey'=>$dev['id'],'name'=>$dev['name'],'room'=>$dev['room'],
        'floor'=>$dev['floor'],'type'=>$dev['group'],'state'=>$state,'writable'=>$dev['writable']];
    if ($dev['group'] === 'heizung' && $dev['setpoint'] > 0 && IPS_VariableExists($dev['setpoint']))
        $out['targetTemperature'] = GetValue($dev['setpoint']);
    return $out;
}
function iah_rc_write(array $dev, mixed $value): array
{
    if (!$dev['writable']) return ['ok'=>false,'reason'=>'Dieses Gerät ist nur lesbar'];
    $id = (int)$dev['write'];
    if (!IPS_VariableExists($id) || !HasAction($id)) return ['ok'=>false,'reason'=>'Keine Schaltaktion'];
    $type = (int)(IPS_GetVariable($id)['VariableType'] ?? -1);
    if (in_array($dev['group'],['switches','fans'],true)) {
        if ($type !== 0 || !is_bool($value)) return ['ok'=>false,'reason'=>'Booleanwert erwartet'];
    } elseif (in_array($dev['group'],['dimmers','heizung'],true)) {
        if (!in_array($type,[1,2],true) || !is_numeric($value) || is_bool($value))
            return ['ok'=>false,'reason'=>'Numerischer Sollwert erwartet'];
        $num=(float)$value;
        $min=$dev['min'] ?? ($dev['group']==='dimmers'?0:6);
        $max=$dev['max'] ?? ($dev['group']==='dimmers'?100:30);
        if (!is_finite($num) || $num<$min || $num>$max)
            return ['ok'=>false,'reason'=>'Wert liegt außerhalb des erlaubten Bereichs'];
        $value=$type===1?(int)round($num):$num;
    } else return ['ok'=>false,'reason'=>'Schalten für diesen Gerätetyp nicht unterstützt'];
    try {
        $ok=RequestAction($id,$value);
        return ['ok'=>(bool)$ok,'name'=>$dev['name'],'room'=>$dev['room'],
            'result'=>$ok?'Aktion gesendet; Rückmeldung noch nicht verifiziert':'IP-Symcon hat die Aktion abgelehnt'];
    } catch (Throwable $e) {
        return ['ok'=>false,'reason'=>'Aktion fehlgeschlagen','name'=>$dev['name']];
    }
}
function iah_rc_tool(array $devices, string $name, array $args, bool $allowWrites, string $spoken): array
{
    if ($name === 'get_devices') {
        $found=iah_rc_find($devices,$args);
        return ['count'=>count($found),'truncated'=>count($found)>60,
            'devices'=>array_map(static fn($d):array=>['deviceKey'=>$d['id'],'name'=>$d['name'],
                'room'=>$d['room'],'type'=>$d['group'],'writable'=>$d['writable']],array_slice($found,0,60))];
    }
    if ($name === 'get_states') {
        $found=iah_rc_find($devices,$args);$active=0;$entries=[];$onlyActive=(bool)($args['only_active']??false);
        foreach ($found as $dev) {
            $row=iah_rc_state($dev);$on=$row['state']==='an';if($on)$active++;
            if (!$onlyActive || $on) if(count($entries)<60)$entries[]=$row;
        }
        return ['matched'=>count($found),'activeBooleanDevices'=>$active,
            'shown'=>count($entries),'truncated'=>count($found)>count($entries),
            'devices'=>$entries];
    }
    if (!$allowWrites) return ['ok'=>false,'reason'=>'KI-Schaltbefehle global nicht freigegeben'];
    if ($name === 'set_device') {
        $found=iah_rc_find($devices,$args);
        if (count($found)!==1) return ['ok'=>false,'reason'=>count($found)?'Gerätename ist mehrdeutig':'Gerät nicht gefunden','matches'=>count($found)];
        return iah_rc_write($found[0],$args['value']??null);
    }
    if ($name === 'set_multiple') {
        if (!preg_match('/\b(alle|alles|sämtliche|saemtliche|gesamten)\b/ui',$spoken))
            return ['ok'=>false,'reason'=>'Gruppenschaltung muss ausdrücklich verlangt sein'];
        if (!is_bool($args['value']??null) || !in_array((string)($args['domain']??''),['licht','lueftung'],true))
            return ['ok'=>false,'reason'=>'Nur Licht-/Lüftungsschalter als Gruppe'];
        $list=[];
        foreach (iah_rc_find($devices,$args) as $dev)
            if(in_array($dev['group'],['switches','fans'],true) && $dev['writable'])$list[$dev['write']]=$dev;
        if (!$list || count($list)>15) return ['ok'=>false,'reason'=>'Keine oder mehr als 15 freigegebene Geräte','count'=>count($list)];
        $results=[];foreach($list as $dev)$results[]=iah_rc_write($dev,$args['value']);
        return ['requested'=>count($results),'accepted'=>count(array_filter($results,static fn($r)=>$r['ok']??false)),
            'results'=>$results];
    }
    return ['ok'=>false,'reason'=>'Unbekanntes Werkzeug'];
}
function iah_rc_http(array $payload,string $apiKey,float $deadline): array
{
    $timeout=(int)(($deadline-microtime(true))*1000);
    if($timeout<250) throw new RuntimeException('Zeitbudget ausgeschöpft');
    $ch=curl_init('https://api.openai.com/v1/chat/completions');
    if($ch===false)throw new RuntimeException('cURL konnte nicht gestartet werden');
    try {
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],
            CURLOPT_CONNECTTIMEOUT_MS=>min(1000,$timeout),CURLOPT_TIMEOUT_MS=>$timeout,
            CURLOPT_FOLLOWLOCATION=>false]);
        $data=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        if(!is_string($data)||$code!==200)throw new RuntimeException('OpenAI-HTTP-Fehler, Status '.$code);
        return json_decode($data,true,512,JSON_THROW_ON_ERROR);
    } finally {curl_close($ch);}
}
function iah_rc_voice(string $text,array $cfg): array
{
    $key=trim((string)($cfg['api_key']??''));$catalogId=(int)($cfg['catalog_script_id']??0);
    if($key===''||$catalogId<=0||trim($text)==='')return ['handled'=>false,'error'=>'KI-Konfiguration unvollständig'];
    // Ereignisse an das persistente IPS-Modul melden, ohne den normalen Antwortweg zu beeinflussen.
    $costInstanceId=(int)($cfg['ki_cost_instance_id']??0);
    $costQuestionId=bin2hex(random_bytes(12));
    $costReport=static function (string $type,array $more=[]) use($costInstanceId,$costQuestionId):void {
        if ($costInstanceId<=0 || !function_exists('IAH_RecordKICostEvent')) return;
        try {
            $event=array_merge(['type'=>$type,'backend'=>'RoomsCatalog','questionId'=>$costQuestionId],$more);
            if (!IAH_RecordKICostEvent($costInstanceId,json_encode($event,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE))) {
                iah_rc_log('cost.warning',['stage'=>$type,'reason'=>'Event nicht gespeichert']);
            }
        } catch (Throwable $err) {
            iah_rc_log('cost.warning',['stage'=>$type,'reason'=>'Statistikfehler']);
        }
    };
    $costReport('start',['model'=>(string)($cfg['model']??'gpt-4.1-mini')]);
    $costFinish=static function (array $result) use($costReport):array {
        $costReport('finish',['success'=>(bool)($result['handled']??false)]);
        return $result;
    };
    $started=microtime(true);$deadline=$started+max(2,min(7,(int)($cfg['timeout_seconds']??6)));
    $maxWords=max(8,min(45,(int)($cfg['max_words']??20)));$allowWrites=(bool)($cfg['allow_writes']??false);
    try{
        $devices=iah_rc_index($catalogId);
        iah_rc_log('catalog.loaded',['count'=>count($devices),'writeEnabled'=>$allowWrites]);
        if(!$devices)return $costFinish(['handled'=>true,'answer'=>'Im RoomsCatalog sind keine KI-Geräte freigegeben.']);
        $props=[
            'deviceKey'=>['type'=>'string'],'name'=>['type'=>'string'],'room'=>['type'=>'string'],
            'floor'=>['type'=>'string'],'domain'=>['type'=>'string'],'group'=>['type'=>'string'],
        ];
        $make=static fn(string $n,string $d,array $p):array=>['type'=>'function','function'=>[
            'name'=>$n,'description'=>$d,'parameters'=>['type'=>'object','properties'=>$p]]];
        $tools=[
            $make('get_states','Liest aktuelle Gerätezustände; für eingeschaltete Lichter domain=licht, only_active=true.',
                $props+['only_active'=>['type'=>'boolean']]),
            $make('get_devices','Listet Geräte mit eindeutigen deviceKeys und Schreibrechten.',$props)
        ];
        if($allowWrites){
            $tools[]=$make('set_device','Schaltet EIN einzelnes ausdrücklich freigegebenes Gerät. Boolean für Licht/Lüfter, Zahlen für Dimmer/Heizung. Gerät eindeutig angeben.',
                $props+['value'=>['type'=>['boolean','number']]]);
            $tools[]=$make('set_multiple','Schaltet bis zu 15 freigegebene Lampen/Lüfter, nur wenn der Nutzer ausdrücklich ALLE verlangt.',
                $props+['value'=>['type'=>'boolean']]);
        }
        $messages=[
            ['role'=>'system','content'=>'Du bist eine deutsche Alexa-Haussteuerung. Alle Hausdaten nur über die Werkzeuge. Keine erfundenen Geräte, keine Schaltbestätigung ohne Werkzeugerfolg. Maximal '.$maxWords.' Wörter, ein kurzer deutscher Satz. Bei Abfragen niemals schalten. Bei unklaren Geräten nachfragen. Eine an IP-Symcon gesendete Aktion ist noch keine bestätigte Rückmeldung.'],
            ['role'=>'user','content'=>mb_substr($text,0,500,'UTF-8')]
        ];
        for($round=0;$round<2;$round++){
            iah_rc_log('openai.start',['round'=>$round+1]);
            $model=(string)($cfg['model']??'gpt-6-luna');
            $request=['model'=>$model,'messages'=>$messages,
                'tools'=>$tools,'tool_choice'=>$round===0?'required':'auto','max_completion_tokens'=>200];
            // GPT-6 Luna unter Chat Completions: Function Calling nur ohne Reasoning.
            // GPT-4.1 mini erhält weiterhin das bewährte Request-Format.
            if($model==='gpt-6-luna')$request['reasoning_effort']='none';
            $response=iah_rc_http($request,$key,$deadline);
            $costReport('api',['callId'=>'round-'.$round,'response'=>[
                'model'=>(string)($response['model']??($cfg['model']??'')),
                'usage'=>$response['usage']??null
            ]]);
            $msg=$response['choices'][0]['message']??null;
            if(!is_array($msg))throw new RuntimeException('Keine KI-Antwort');
            $calls=$msg['tool_calls']??[];
            if(!$calls){
                $words=preg_split('/\s+/u',trim(strip_tags((string)($msg['content']??''))),-1,PREG_SPLIT_NO_EMPTY)?:[];
                if(!$words)throw new RuntimeException('Keine Sprachantwort');
                $answer=implode(' ',array_slice($words,0,$maxWords));
                iah_rc_log('answer',['durationMs'=>round((microtime(true)-$started)*1000),'text'=>$answer]);
                return $costFinish(['handled'=>true,'answer'=>$answer]);
            }
            if($round>0)throw new RuntimeException('Zu viele Tool-Runden');
            $messages[]=$msg;
            foreach(array_slice($calls,0,3) as $call){
                $name=(string)($call['function']['name']??'');
                $args=json_decode((string)($call['function']['arguments']??'{}'),true);
                if(!is_array($args)||!in_array($name,array_column(array_column($tools,'function'),'name'),true))
                    throw new RuntimeException('Ungültiger Werkzeugaufruf');
                $result=iah_rc_tool($devices,$name,$args,$allowWrites,$text);
                iah_rc_log('tool',['name'=>$name,'ok'=>$result['ok']??true]);
                $messages[]=['role'=>'tool','tool_call_id'=>(string)$call['id'],
                    'content'=>mb_substr(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),0,12000,'UTF-8')];
            }
        }
    }catch(Throwable $e){
        iah_rc_log('error',['reason'=>$e->getMessage(),'durationMs'=>round((microtime(true)-$started)*1000)]);
        return $costFinish(['handled'=>false,'error'=>$e->getMessage()]);
    }
    return $costFinish(['handled'=>false,'error'=>'Keine Antwort']);
}
if(isset($_IPS)&&is_array($_IPS)&&array_key_exists('kiCatalogText',$_IPS)){
    $cfg=json_decode((string)($_IPS['kiCatalogConfigJson']??'{}'),true);
    $result=iah_rc_voice((string)$_IPS['kiCatalogText'],is_array($cfg)?$cfg:[]);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
