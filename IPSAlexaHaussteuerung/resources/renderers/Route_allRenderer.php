<?php
/**
 * ============================================================
 * ROUTE_ALL — Zentrale Routenbehandlung (Main, Heizung, Licht, …)
 * ============================================================
 * Änderungsverlauf
 * 2025-11-10: Erste Version: Vereinigt Main-Launch, Heizung, Jalousie, Licht,
 *        Lüftung, Geräte, Bewässerung, Einstellungen in einem Skript. (+ Logging)
 * 2025-11-10: Log-Maskierung entfernt: Payload/Result werden komplett geloggt (keine Token-Ersetzung, kein substr()).
 * 2025-11-10: Robust-Decode: inneres payload (String/Array) wird ausgepackt; rooms/ROOMS/ACTIONS_ENABLED/aplArgs
 *              werden aus JSON-Strings automatisch zu Arrays normalisiert. Ist-Temperatur als formatierter Wert.
 * 2025-11-10: External: externalKey top-level akzeptiert; kontextabhängige Page-ID aus SystemConfiguration
 *              (EnergiePageId/KameraPageId) mit Fallback. DelayScript mit erweitertem Logging, Exists-Check
 *              und Übergabe ['page'=>$pageId,'wfc'=>$wfcId].
 *
 * Aufruf:
 *   $res = json_decode(IPS_RunScriptWaitEx($S['ROUTE_ALL'], ['payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]), true);
 *
 * Erwartetes payload (Auszug):
 *   route: 'main_launch'|'heizung'|'jalousie'|'licht'|'lueftung'|'geraete'|'bewaesserung'|'settings'|'external'
 *   S: ['RENDER_MAIN'=>id,'RENDER_HEIZUNG'=>id,'RENDER_JALOUSIE'=>id,'RENDER_LICHT'=>id,'RENDER_LUEFTUNG'=>id,'RENDER_GERAETE'=>id,'RENDER_BEWAESSERUNG'=>id,'RENDER_SETTINGS'=>id]
 *   V: Variablen-IDs (AUSSEN_TEMP, INFORMATION, MELDUNGEN, DOMAIN_FLAG, SKILL_ACTIVE, StartPage, WfcId, DelayScript,
 *                     EnergiePageId, KameraPageId, ...)
 *   rooms, ROOMS, ACTIONS_ENABLED
 *   args1v,args2v,args3v,args4v, aplSupported, action, device, room, object, alles, number, prozent, power,
 *   aplArgs, skillActive, alexaKey, alexa, baseUrl, source, token, room_raw, szene, externalKey
 *
 * Rückgabe:
 *   JSON: ['ok'=>true, 'route'=>'..', 'data'=>{rendererResult}, 'flags'=>{ setDomainFlag:'', resetDomainFlag:bool, setSkillActive:true|false }, 'aplToken'=>'...']
 */

declare(strict_types=1);

$JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

try {
    // ---- Eingabe lesen ----
    $raw = (string)($_IPS['payload'] ?? '');
    $payload = json_decode($raw, true);

    // Falls doppelt verpackt: { payload: "{...}" } oder { payload: { ... } }
    if (is_array($payload) && array_key_exists('payload', $payload)) {
        $inner = $payload['payload'];
        if (is_string($inner)) {
            $tmp = json_decode($inner, true);
            if (is_array($tmp)) { $payload = $tmp; }
        } elseif (is_array($inner)) {
            $payload = $inner;
        }
    }

    if (!is_array($payload)) {
        IPS_LogMessage('Alexa', 'ROUTE_ALL payload invalid or empty');
        echo json_encode(['ok'=>false,'err'=>'invalid payload'], $JSON);
        return;
    }

    // ---- Primitive Felder ----
    $route          = (string)($payload['route'] ?? '');
    $S              = (array)($payload['S'] ?? []);
    $V              = (array)($payload['V'] ?? []);

    // ---- Collections robust normalisieren (String->JSON->Array) ----
    $norm = static function($v) {
        if (is_string($v)) { $d = json_decode($v, true); return is_array($d) ? $d : []; }
        return is_array($v) ? $v : [];
    };
    $rooms           = $norm($payload['rooms'] ?? []);
    $ROOMS           = $norm($payload['ROOMS'] ?? []);
    $ACTIONS_ENABLED = $norm($payload['ACTIONS_ENABLED'] ?? []);
    $aplArgs         = $norm($payload['aplArgs'] ?? []);

    $args1v         = $payload['args1v'] ?? null;
    $args2v         = $payload['args2v'] ?? null;
    $args3v         = $payload['args3v'] ?? null;

    $aplSupported   = (bool)($payload['aplSupported'] ?? false);
    $action         = (string)($payload['action'] ?? '');
    $device         = (string)($payload['device'] ?? '');
    $room           = (string)($payload['room'] ?? '');
    $object         = (string)($payload['object'] ?? '');
    $alles          = (string)($payload['alles'] ?? '');
    $number         = $payload['number'] ?? null;
    $prozent        = $payload['prozent'] ?? null;
    $power          = $payload['power'] ?? null;
    $skillActive    = (bool)($payload['skillActive'] ?? false);

    $alexaKey       = (string)($payload['alexaKey'] ?? '');
    $alexa          = (string)($payload['alexa'] ?? '');
    $baseUrl        = (string)($payload['baseUrl'] ?? '');
    $source         = (string)($payload['source'] ?? '');
    $token          = (string)($payload['token'] ?? '');
    $room_raw       = (string)($payload['room_raw'] ?? '');
    $szene          = (string)($payload['szene'] ?? '');
    $CFG            = $payload['CFG'] ?? null;

    $mkCid = static function(): string { return substr(hash('crc32b', microtime(true) . mt_rand()), 0, 8); };
    $j = static function(array $a) use($JSON){ return json_encode($a, $JSON); };

    $cid = $mkCid();
    IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] entered route='.($route ?: '(leer)').' action='.$action.' device='.$device.' room='.$room.' alexaKey='.$alexaKey .' baseUrl='.$baseUrl .' token='.$token);

    $flags = ['setDomainFlag'=>null,'resetDomainFlag'=>false,'setSkillActive'=>null];

    // =============================
    // MAIN LAUNCH
    // =============================
    if ($route === 'main_launch') {
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] → main_launch');
        // heizung-Zweig pro alexaKey robust ermitteln
        $alexaHeizung = [];
        if (isset($rooms[$alexaKey]['heizung']) && is_array($rooms[$alexaKey]['heizung'])) {
            $alexaHeizung = $rooms[$alexaKey]['heizung'];
        }
        $alexaCircuits = array_keys($alexaHeizung);
        $primaryCid    = $alexaCircuits[0] ?? null;
        $alexaIstVarId = null;

        IPS_LogMessage('Alexa', 'primaryCid: ' . $primaryCid);

        if ($primaryCid !== null) {
            $alexaIstVarId = $alexaHeizung[$primaryCid]['ist'] ?? null;
        }

        $data = IPS_RunScriptWaitEx((int)$S['RENDER_MAIN'], [
            'payload' => json_encode([
                'aplSupported'        => $aplSupported,
                'action'              => $action,
                'kuecheIstTemperatur' => $alexaIstVarId,
                'meldungen'           => GetValueString((int)$V['MELDUNGEN']),
                'aussenTemperatur'    => GetValueFormatted((int)$V['AUSSEN_TEMP']),
                'information'         => GetValueString((int)$V['INFORMATION']),
                'args1'               => $args1v,
                'alexa'               => $alexa,
                'baseUrl'             => $baseUrl,
                'source'              => $source,
                'token'               => $token,
            ], $JSON),
        ]);
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] return script='.(int)$S['RENDER_MAIN'].' raw='.(string)$data);
        if (!$data) {
            IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] render_main failed');
            echo json_encode(['ok'=>false,'route'=>$route,'err'=>'render_main failed'], $JSON);
            return;
        }

        SetValueString((int)$V['DOMAIN_FLAG'], '');
        $flags['setSkillActive'] = true;

        $reprompt = ($skillActive === true || $action === 'zurück' || $args1v === 'zurück')
            ? 'Was noch?'
            : 'Wähle einen Eintrag oder sage einen Befehl!';

        $dataArr = json_decode((string)$data, true);
        if (!is_array($dataArr)) { $dataArr = []; }
        $dataArr['reprompt'] = $dataArr['reprompt'] ?? $reprompt;
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] ok main_launch reprompt='.($dataArr['reprompt'] ?? ''));
        echo json_encode(['ok'=>true,'route'=>$route,'data'=>$dataArr,'flags'=>$flags,'aplToken'=>'hv-main'], $JSON);
        return;
    }

    // =============================
    // HEIZUNG
    // =============================
    if ($route === 'heizung') {
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] → heizung');
        $flags['setDomainFlag'] = 'heizung';
        $data = IPS_RunScriptWaitEx((int)$S['RENDER_HEIZUNG'], [
            'payload' => json_encode([
                'aplSupported'    => $aplSupported,
                'action'          => $action,
                'device'          => $device,
                'room'            => $room,
                'object'          => $object,
                'alles'           => $alles,
                'number'          => $number,
                'args2'           => $args2v,
                'args1'           => $args1v,
                'rooms'           => $rooms,
                'roomMap'         => (function (array $ROOMS) { $m=[]; foreach ($ROOMS as $k=>$v) $m[$k]=[(array)($v['synonyms'] ?? []),(string)($v['display'] ?? $k)]; return $m; })($ROOMS),
                'roomsCatalog'    => $ROOMS,
                'CFG'             => $CFG,
                'ACTIONS_ENABLED' => $ACTIONS_ENABLED,
            ], $JSON),
        ]);
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] heizung result='.($data ? 'ok' : 'null'));
        $dataArr = is_string($data) ? (json_decode($data, true) ?: null) : $data;
        echo json_encode(['ok'=> (bool)$dataArr,'route'=>$route,'data'=>$dataArr,'flags'=>$flags,'aplToken'=>'hv-heizung'], $JSON);
        return;
    }

    // =============================
    // JALOUSIE
    // =============================
    if ($route === 'jalousie') {
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] → jalousie');
        $flags['setDomainFlag'] = 'jalousie';
        $data = IPS_RunScriptWaitEx((int)$S['RENDER_JALOUSIE'], [
            'payload' => json_encode([
                'aplSupported'    => $aplSupported,
                'action'          => $action,
                'szene'           => $szene,
                'device'          => $device,
                'room'            => $room,
                'object'          => $object,
                'number'          => $prozent !== null ? null : $number,
                'prozent'         => $prozent,
                'alles'           => $alles,
                'alexa'           => $alexa,
                'roomsCatalog'    => $ROOMS,
                'CFG'             => $CFG,
                'args1'           => $args1v,
                'args2'           => $args2v,
                'ACTIONS_ENABLED' => $ACTIONS_ENABLED,
            ], $JSON),
        ]);
        if (is_string($data)) { $tmp = json_decode($data, true); if (is_array($tmp) && !empty($tmp['resetDomainFlag'])) { $flags['setDomainFlag'] = ''; } }
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] jalousie result='.($data ? 'ok' : 'null'));
        $dataArr = is_string($data) ? (json_decode($data, true) ?: null) : $data;
        echo json_encode(['ok'=> (bool)$dataArr,'route'=>$route,'data'=>$dataArr,'flags'=>$flags,'aplToken'=>'hv-jalousie'], $JSON);
        return;
    }

    // =============================
    // LICHT
    // =============================
    if ($route === 'licht') {
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] → licht');
        $flags['setDomainFlag'] = 'licht';
        $data = IPS_RunScriptWaitEx((int)$S['RENDER_LICHT'], [
            'payload' => json_encode([
                'aplSupported'    => $aplSupported,
                'action'          => $action,
                'device'          => $device,
                'room'            => $room,
                'object'          => $object,
                'alles'           => $alles,
                'number'          => ($power ? null : $number),
                'args2'           => $args2v,
                'args1'           => $args1v,
                'rooms'           => $rooms,
                'roomMap'         => (function (array $ROOMS) { $m=[]; foreach ($ROOMS as $k=>$v) $m[$k]=[(array)($v['synonyms'] ?? []),(string)($v['display'] ?? $k)]; return $m; })($ROOMS),
                'roomsCatalog'    => $ROOMS,
                'CFG'             => $CFG,
                'ACTIONS_ENABLED' => $ACTIONS_ENABLED,
                'power'           => $power,
            ], $JSON),
        ]);
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] licht result='.($data ? 'ok' : 'null'));
        $dataArr = is_string($data) ? (json_decode($data, true) ?: null) : $data;
        echo json_encode(['ok'=> (bool)$dataArr,'route'=>$route,'data'=>$dataArr,'flags'=>$flags,'aplToken'=>'hv-licht'], $JSON);
        return;
    }

    // =============================
    // LUEFTUNG
    // =============================
    if ($route === 'lueftung') {
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] → lueftung');
        $flags['setDomainFlag'] = 'lueftung';
        $data = IPS_RunScriptWaitEx((int)$S['RENDER_LUEFTUNG'], [
            'payload' => json_encode([
                'aplSupported'    => $aplSupported,
                'action'          => $action,
                'device'          => $device,
                'room'            => $room,
                'object'          => $object,
                'alles'           => $alles,
                'number'          => ($power ? null : $number),
                'args2'           => $args2v,
                'args1'           => $args1v,
                'rooms'           => $rooms,
                'roomMap'         => (function (array $ROOMS) { $m=[]; foreach ($ROOMS as $k=>$v) $m[$k]=[(array)($v['synonyms'] ?? []),(string)($v['display'] ?? $k)]; return $m; })($ROOMS),
                'roomsCatalog'    => $ROOMS,
                'CFG'             => $CFG,
                'ACTIONS_ENABLED' => $ACTIONS_ENABLED,
                'power'           => $power,
                'aplArgs'         => array_values($aplArgs),
            ], $JSON),
        ]);
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] lueftung result='.($data ? 'ok' : 'null'));
        $dataArr = is_string($data) ? (json_decode($data, true) ?: null) : $data;
        echo json_encode(['ok'=> (bool)$dataArr,'route'=>$route,'data'=>$dataArr,'flags'=>$flags,'aplToken'=>'hv-lueftung'], $JSON);
        return;
    }

    // =============================
    // GERAETE
    // =============================
    if ($route === 'geraete') {
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] → geraete');
        $flags['setDomainFlag'] = 'geraete';
        $data = IPS_RunScriptWaitEx((int)$S['RENDER_GERAETE'], [
            'payload' => json_encode([
                'aplSupported'    => $aplSupported,
                'action'          => $action,
                'device'          => $device,
                'room'            => $room,
                'object'          => $object,
                'alles'           => $alles,
                'number'          => ($power ? null : $number),
                'args2'           => $args2v,
                'args1'           => $args1v,
                'rooms'           => $rooms,
                'roomMap'         => (function (array $ROOMS) { $m=[]; foreach ($ROOMS as $k=>$v) $m[$k]=[(array)($v['synonyms'] ?? []),(string)($v['display'] ?? $k)]; return $m; })($ROOMS),
                'roomsCatalog'    => $ROOMS,
                'CFG'             => $CFG,
                'ACTIONS_ENABLED' => $ACTIONS_ENABLED,
                'power'           => $power,
            ], $JSON),
        ]);
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] geraete result='.($data ? 'ok' : 'null'));
        $dataArr = is_string($data) ? (json_decode($data, true) ?: null) : $data;
        echo json_encode(['ok'=> (bool)$dataArr,'route'=>$route,'data'=>$dataArr,'flags'=>$flags,'aplToken'=>'hv-geraete'], $JSON);
        return;
    }

    // =============================
    // BEWAESSERUNG
    // =============================
    if ($route === 'bewaesserung') {
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] → bewaesserung');
        $flags['setDomainFlag'] = 'bewaesserung';
        $data = IPS_RunScriptWaitEx((int)$S['RENDER_BEWAESSERUNG'], [
            'payload' => json_encode([
                'aplSupported'    => $aplSupported,
                'action'          => $action,
                'device'          => $device,
                'room'            => $room,
                'object'          => $object,
                'alles'           => $alles,
                'number'          => ($power ? null : $number),
                'args2'           => $args2v,
                'args1'           => $args1v,
                'rooms'           => $rooms,
                'roomMap'         => (function (array $ROOMS) { $m=[]; foreach ($ROOMS as $k=>$v) $m[$k]=[(array)($v['synonyms'] ?? []),(string)($v['display'] ?? $k)]; return $m; })($ROOMS),
                'roomsCatalog'    => $ROOMS,
                'CFG'             => $CFG,
                'ACTIONS_ENABLED' => $ACTIONS_ENABLED,
                'power'           => $power,
            ], $JSON),
        ]);
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] bewaesserung result='.($data ? 'ok' : 'null'));
        $dataArr = is_string($data) ? (json_decode($data, true) ?: null) : $data;
        echo json_encode(['ok'=> (bool)$dataArr,'route'=>$route,'data'=>$dataArr,'flags'=>$flags,'aplToken'=>'hv-bewaesserung'], $JSON);
        return;
    }

    // =============================
    // SETTINGS
    // =============================
    if ($route === 'settings') {
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] → settings');
        $flags['setDomainFlag'] = 'einstellungen';
        $data = IPS_RunScriptWaitEx((int)$S['RENDER_SETTINGS'], [
            'payload' => json_encode([
                'aplSupported'    => $aplSupported,
                'action'          => $action,
                'device'          => $device,
                'room'            => $room,
                'object'          => $object,
                'alles'           => $alles,
                'number'          => $number,
                'args2'           => $args2v,
                'alexa'           => $alexa,
                'CFG'             => $CFG,
                'ACTIONS_ENABLED' => $ACTIONS_ENABLED,
            ], $JSON),
        ]);
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] settings result='.($data ? 'ok' : 'null'));
        $dataArr = is_string($data) ? (json_decode($data, true) ?: null) : $data;
        echo json_encode(['ok'=> (bool)$dataArr,'route'=>$route,'data'=>$dataArr,'flags'=>$flags,'aplToken'=>'hv-einstellungen'], $JSON);
        return;
    }

    // =============================
    // EXTERNAL
    // =============================
    if ($route === 'external') {
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] → external');

        // externalKey robust lesen (top-level ODER aus V)
        $externalKey = (string)($payload['externalKey'] ?? ($V['externalKey'] ?? ''));
        $EP          = (array)($ROOMS['global']['external_pages'] ?? []);
        $cfg         = (array)($EP[$externalKey] ?? []);

        $passwort    = (string)($V['Passwort'] ?? '');
        $startPage   = (string)($V['StartPage'] ?? '');
        $wfcId       = (int)   ($V['WfcId'] ?? 0);
        $delayScript = (int)   ($V['DelayScript'] ?? 0);

        // Page-ID kontextabhängig aus SystemConfiguration mit Fallbacks
        $pageIdMap = [
            'energie' => (string)($V['EnergiePageId'] ?? ''),
            'kamera'  => (string)($V['KameraPageId']  ?? ''),
        ];
        $pageId = $pageIdMap[$externalKey] ?? '';

        // Debug-Logging
        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] external.debug ' . $j([
            'externalKey'=>$externalKey,
            'cfg.keys'=>array_keys($cfg),
            'wfcId'=>$wfcId,
            'delayScript'=>$delayScript,
            'pageId'=>$pageId,
            'baseUrl'=>$baseUrl !== '' ? '(set)' : '(empty)',
            'token'=>$token !== '' ? '(set)' : '(empty)'
        ]));

        if ($externalKey === '' || $cfg === []) {
            IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] external.error missing config for key');
            echo json_encode(['ok'=>false,'route'=>$route,'err'=>'missing external config'], $JSON);
            return;
        }

        $tileBase = rtrim($baseUrl, '/').'/?password='.$passwort.$startPage;
        $logoFile = (string)($cfg['logo'] ?? '');
        $titleTxt = (string)($cfg['title'] ?? $externalKey);
        $logoUrl  = $logoFile !== '' ? (rtrim($baseUrl,'/').'/hook/icons?'.http_build_query([
            'f'=>$logoFile,'token'=>$token,'v'=>time()
        ], '', '&', PHP_QUERY_RFC3986)) : null;

        $shellUrl = rtrim($baseUrl,'/').'/hook/icons?'.http_build_query([
            'f'     => 'alexa-shell.html',
            'token' => $token,
            'title' => $titleTxt,
            'src'   => $tileBase,
            'logo'  => $logoUrl,
        ], '', '&', PHP_QUERY_RFC3986);

        IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] external.urls ' . $j([
            'tileBase'=>$tileBase,
            'logoUrl'=>$logoUrl,
            'shellUrl'=>$shellUrl,
        ]));

        // Delay-Script mit Existenz-Check + Detail-Logging
        if ($delayScript > 0) {
            $exists = function_exists('IPS_ScriptExists') ? IPS_ScriptExists($delayScript) : true; // Fallback
            if ($exists) {
                $argsDelay = ['page'=>$pageId, 'wfc'=>$wfcId];
                IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] external.delay dispatch ' . $j([
                    'scriptId'=>$delayScript,
                    'args'=>$argsDelay
                ]));
                try {
                    // Asynchron starten (nicht blockierend)
                    @$ok = IPS_RunScriptEx($delayScript, $argsDelay);
                    IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] external.delay dispatched ok='.(($ok===null||$ok===true)?'true':'false'));
                } catch (Throwable $e) {
                    IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] external.delay error '.$e->getMessage());
                }
            } else {
                IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] external.delay missing scriptId='.$delayScript);
            }
        } else {
            IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] external.delay skipped (id<=0)');
        }

        // HTML.Start zurückgeben
        $directives = [[
            'type'          => 'Alexa.Presentation.HTML.Start',
            'request'       => ['uri'=>$shellUrl, 'method'=>'GET'],
            'configuration' => ['timeoutInSeconds'=>300]
        ]];

        $flags['setSkillActive'] = true;

        echo json_encode([
            'ok'       => true,
            'route'    => $route,
            'data'     => ['speech'=>'', 'reprompt'=>'', 'directives'=>$directives],
            'flags'    => $flags,
            'aplToken' => 'hv-external'
        ], $JSON);
        return;
    }

    // Unbekannte Route
    IPS_LogMessage('Alexa', 'ROUTE_ALL['.$cid.'] unknown route: '.$route);
    echo json_encode(['ok'=>false,'err'=>'unknown route: '.$route], $JSON);
} catch (Throwable $e) {
    IPS_LogMessage('Alexa', 'ROUTE_ALL error: '.$e->getMessage().' trace='.substr($e->getTraceAsString(),0,4000));
    echo json_encode(['ok'=>false,'err'=>$e->getMessage()], $JSON);
}
