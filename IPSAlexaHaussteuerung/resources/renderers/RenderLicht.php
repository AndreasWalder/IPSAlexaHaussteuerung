<?php
/**
 * ============================================================
 * LICHT RENDERER — RoomsCatalog-only (Aktionen + Visual-Logik)
 * ============================================================
 * - Eindeutige Zielauflösung pro Raum (nur wenn genau 1 Ziel)
 * - Aktionen (Switch/Dim) -> kurzer Delay -> Re-Read -> Render
 * - Visual-Regeln:
 *     • Schalten: nur gewählte Zeile setzen
 *     • Dimmen:   nur gewählten Dimmer setzen; zugehöriger Switch EIN, wenn Wert > 1 %
 *     • Danach sanfte Gesamtsynchronisierung (ohne Dimmer auf 100 % zu ziehen)
 *
 * Änderungsverlauf
 * 2025-10-29: Aktionen reaktiviert + Re-Read vor Render
 * 2025-10-29: Dimmen setzt Switch nicht mehr auf 100 %
 * 2025-10-29: Zielgerichtetes Visual-Update
 * 2025-10-29: **Fix Referenz-Bug** in Apply-Funktionen (kein „erster Eintrag flippt“ mehr)
 * 2025-10-29: **Override-Fix** in der Sync-Schleife (priorisiert lastSw/lastDim gegenüber Re-Read)
 * 2025-10-29: **Map-basierte Visual-Updates** (kein Scan/Fallback auf ersten Eintrag mehr)
 * 2025-10-29: **Action-Guards** wie Jalousie (ActionsEnabled + fehlende Variable → harte Abbrüche)
 * 2025-10-29: **Scenes-Guards** ergänzt (args2/device = szene.* oder Titel)
 */

const RENDER_DELAY_MS = 50;

$in = json_decode($_IPS['payload'] ?? '{}', true) ?: [];

/* =========================
   CFG / ACTION FLAGS
   ========================= */
$CFG = is_array($in['CFG'] ?? null) ? $in['CFG'] : [];

$VAR = [];
if (is_array($CFG['actions_vars'] ?? null)) {
    $VAR = $CFG['actions_vars'];
} elseif (is_array($CFG['var']['ActionsEnabled'] ?? null)) {
    $VAR = $CFG['var']['ActionsEnabled'];
}

$readBool = static function($varId, bool $default=false): bool {
    if (!is_int($varId) || $varId <= 0) return $default;
    return (bool)@GetValue($varId);
};

$ACTIONS_ENABLED = [
    'licht' => [
        'switches' => $readBool((int)($VAR['licht_switches'] ?? 0), false),
        'dimmers'  => $readBool((int)($VAR['licht_dimmers']  ?? 0), false),
    ],
];

$CAN_SWITCH = (bool)($ACTIONS_ENABLED['licht']['switches'] ?? false);
$CAN_DIM    = (bool)($ACTIONS_ENABLED['licht']['dimmers']  ?? false);

/* =========================
   Logging
   ========================= */
$LOG_BASIC   = isset($in['logBasic'])   ? (bool)$in['logBasic']   : (isset($CFG['flags']['log_basic'])   ? (bool)$CFG['flags']['log_basic']   : true);
$LOG_VERBOSE = isset($in['logVerbose']) ? (bool)$in['logVerbose'] : (isset($CFG['flags']['log_verbose']) ? (bool)$CFG['flags']['log_verbose'] : true);
$LOG_TAG     = 'Alexa';
$logB = static function(string $msg) use ($LOG_BASIC, $LOG_TAG) { if ($LOG_BASIC)  IPS_LogMessage($LOG_TAG, $msg); };
$logV = static function(string $msg) use ($LOG_VERBOSE, $LOG_TAG){ if ($LOG_VERBOSE) IPS_LogMessage($LOG_TAG, $msg); };
$J   = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;

/* =========================
   Inputs
   ========================= */
$aplSupported = !empty($in['aplSupported']);
$action       = (string)($in['action'] ?? '');
$device       = (string)($in['device'] ?? '');
$roomSpoken   = (string)($in['room']   ?? '');
$object       = (string)($in['object'] ?? '');
$alles        = (string)($in['alles']  ?? '');
$number       = $in['number'] ?? null;
$args2        = $in['args2'] ?? null;
$args1        = $in['args1'] ?? null;

$powerStr = is_string($in['power'] ?? null) ? trim((string)$in['power']) : '';
if ($powerStr !== 'on' && $powerStr !== 'off') $powerStr = '';

$arg1Num = null;
if (is_string($args1) && is_numeric($args1))       $arg1Num = (float)$args1;
elseif (is_numeric($args1))                         $arg1Num = (float)$args1;
if ($number === null && $arg1Num !== null) { $number = $arg1Num; $powerStr = ''; }

$RID    = strtoupper(substr(hash('crc32b', microtime(true) . mt_rand()), 0, 8));
$JFLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$logV("[$RID] LichtRenderer ENTER");
$logV("[$RID] INPUT ".json_encode([
    'aplSupported'=>(bool)$aplSupported,'action'=>$action,'device'=>$device,'room'=>$roomSpoken,
    'object'=>$object,'alles'=>$alles,'number'=>$number,'args1'=>$args1,'args2'=>$args2,'power'=>$powerStr
], $JFLAGS));

/* =========================
   Catalog & icons
   ========================= */
$CATALOG = is_array($in['roomsCatalog'] ?? null) ? $in['roomsCatalog'] : [];
if (empty($CATALOG)) {
    echo json_encode(['speech'=>'Konfiguration fehlt.','reprompt'=>'','apl'=>null,'endSession'=>false], $JFLAGS);
    return;
}

$V = is_array($CFG['var'] ?? null) ? $CFG['var'] : [];
$readVarValue = static function($entry) {
    if (is_int($entry) && $entry > 0) {
        $s = @GetValueString($entry);
        if (is_string($s) && $s !== '') return trim($s);
        $m = @GetValue($entry);
        return is_scalar($m) ? trim((string)$m) : '';
    }
    if (is_string($entry)) return trim($entry);
    return '';
};
$baseUrl = trim((string)($CFG['baseUrl'] ?? ''));
$token   = trim((string)($CFG['token']   ?? ''));
if ($baseUrl === '' && isset($V['BaseUrl'])) { $baseUrl = $readVarValue($V['BaseUrl']); }
if ($token   === '' && isset($V['Token']))   { $token   = $readVarValue($V['Token']);   }
$iconUrl = static function(string $file, string $baseUrl, string $token): string {
    $path = IPS_GetKernelDir().'user'.DIRECTORY_SEPARATOR.'icons'.DIRECTORY_SEPARATOR.$file;
    $v = @filemtime($path) ?: 1;
    return rtrim($baseUrl,'/').'/hook/icons?f='.rawurlencode($file).'&token='.rawurlencode($token).'&v='.$v;
};
$resolveIcon = static function (?string $raw) use ($baseUrl,$token,$iconUrl): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (preg_match('#^https?://#i', $raw)) return $raw;
    if ($baseUrl !== '' && $token !== '') return $iconUrl($raw, $baseUrl, $token);
    return null;
};

/* =========================
   Helpers
   ========================= */
function l_norm(string $s): string {
    $t = mb_strtolower(trim($s), 'UTF-8');
    return str_replace(['oe','ae','ue','ss'], ['ö','ä','ü','ß'], $t);
}
function readVal($varId) { if (!is_int($varId) || $varId <= 0) return null; return @GetValue($varId); }
function asBool($v): bool {
    if (is_bool($v)) return $v;
    if (is_numeric($v)) return ((float)$v) != 0.0;
    if (is_string($v)) { $n = l_norm($v); return in_array($n, ['1','true','an','ein','on','ja','yes'], true); }
    return false;
}
function asNumber($v): ?float { return is_numeric($v) ? (float)$v : null; }
$fail = static function(string $text) use ($JFLAGS) {
    echo json_encode(['speech'=>$text,'reprompt'=>'','apl'=>null,'endSession'=>false], $JFLAGS);
    return false;
};

/* =========================
   Floors
   ========================= */
$GF = is_array($CATALOG['global']['domains']['floors'] ?? null) ? $CATALOG['global']['domains']['floors'] : [];
$floorOrder = [];
if (isset($GF['order']) && is_array($GF['order'])) foreach ($GF['order'] as $fk) $floorOrder[] = strtoupper((string)$fk);
$floorLabels = [];
if (isset($GF['labels']) && is_array($GF['labels'])) foreach ($GF['labels'] as $fk=>$lab) $floorLabels[strtoupper((string)$fk)] = (string)$lab;
$floorSectionStyle = ['height'=>'3.4vw','fontSize'=>'1.6vw','bold'=>false,'padY'=>'0.8vw'];
if (isset($GF['section']) && is_array($GF['section'])) $floorSectionStyle = array_merge($floorSectionStyle, $GF['section']);
$unknownFloorKey   = 'Z';
$unknownFloorLabel = (string)($GF['unknownLabel'] ?? 'Weitere');

/* =========================
   Build rows & flat lists (1. Read)
   ========================= */
$switchRowsByFloor = [];
$dimRowsByFloor    = [];
$statusRowsByFloor = [];
$switchFlat = [];
$dimFlat    = [];

foreach ($CATALOG as $roomKey => $def) {
    if ($roomKey === 'global') continue;
    $roomDisplay = (string)($def['display'] ?? ucfirst($roomKey));
    $roomFloor   = strtoupper((string)($def['floor'] ?? ''));
    $L = $def['domains']['licht'] ?? null;
    if (!is_array($L)) continue;

    if (isset($L['switches']) && is_array($L['switches'])) {
        foreach ($L['switches'] as $swKey => $meta) {
            if (!is_array($meta)) continue;
            $stateId  = $meta['state']  ?? null;
            if (!is_int($stateId) || $stateId <= 0) continue;

            $row = [
                'title'      => (string)($meta['title'] ?? $roomDisplay.' '.$swKey),
                'entityId'   => (string)($meta['entityId'] ?? ('light.'.$roomKey.'_'.$swKey)),
                'isOn'       => asBool(readVal($stateId)),
                'iconOn'     => $resolveIcon((string)($meta['iconOn']  ?? '')) ?: '',
                'iconOff'    => $resolveIcon((string)($meta['iconOff'] ?? '')) ?: '',
                'order'      => (int)($meta['order'] ?? 9999),
                'roomKey'    => $roomKey,
                'room'       => $roomDisplay,
                'floorKey'   => strtoupper((string)($meta['floor'] ?? '')) ?: ($roomFloor !== '' ? $roomFloor : $unknownFloorKey),
                'stateVarId' => (int)$stateId,
                'toggleVarId'=> (is_int($meta['toggle'] ?? null) ? (int)$meta['toggle'] : 0),
            ];
            $switchRowsByFloor[$row['floorKey']][] = $row;
            $switchFlat[] = $row;
        }
    }

    if (isset($L['dimmers']) && is_array($L['dimmers'])) {
        foreach ($L['dimmers'] as $dimKey => $meta) {
            if (!is_array($meta)) continue;
            $valueId = $meta['value'] ?? null;
            if (!is_int($valueId) || $valueId <= 0) continue;

            $stateId = (is_int($meta['state'] ?? null) && (int)$meta['state'] > 0) ? (int)$meta['state'] : 0;
            $isOn    = null;
            if ($stateId > 0) {
                $isOn = asBool(readVal($stateId));
            }

            $value = (asNumber(readVal($valueId)) ?? 0);
            if ($isOn === false) {
                $value = 0.0;
            }

            $row = [
                'title'      => (string)($meta['title'] ?? $roomDisplay.' '.$dimKey),
                'entityId'   => (string)($meta['entityId'] ?? ('dim.'.$roomKey.'_'.$dimKey)),
                'value'      => $value,
                'icon'       => $resolveIcon((string)($meta['icon'] ?? '')) ?: '',
                'min'        => isset($meta['min'])  ? (float)$meta['min']  : 0.0,
                'max'        => isset($meta['max'])  ? (float)$meta['max']  : 100.0,
                'step'       => isset($meta['step']) ? (float)$meta['step'] : 1.0,
                'order'      => (int)($meta['order'] ?? 9999),
                'roomKey'    => $roomKey,
                'room'       => $roomDisplay,
                'floorKey'   => strtoupper((string)($meta['floor'] ?? '')) ?: ($roomFloor !== '' ? $roomFloor : $unknownFloorKey),
                'valueVarId' => (int)$valueId,
                'setVarId'   => (is_int($meta['set'] ?? null) ? (int)$meta['set'] : 0),
                'pairedSwitch'=> (string)($meta['pairedSwitch'] ?? ''),
                'stateVarId'  => $stateId,
            ];
            if ($isOn !== null) {
                $row['isOn'] = $isOn;
            }
            if (empty($row['floorKey'])) $row['floorKey'] = 'Z';
            $dimRowsByFloor[$row['floorKey']][] = $row;
            $dimFlat[] = $row;
        }
    }

    if (isset($L['status']) && is_array($L['status'])) {
        foreach ($L['status'] as $stKey => $meta) {
            if (!is_array($meta)) continue;
            $valueId = $meta['value'] ?? null;
            if (!is_int($valueId) || $valueId <= 0) continue;
            $val   = readVal($valueId);
            $statusRowsByFloor[strtoupper((string)($meta['floor'] ?? '')) ?: ($roomFloor !== '' ? $roomFloor : 'Z')][] = [
                'title'=>(string)($meta['title'] ?? $roomDisplay.' '.$stKey),
                'value'=>$val,'valueText'=>trim((string)$val.' '.(string)($meta['unit'] ?? '')),
                'unit'=>(string)($meta['unit'] ?? ''),'icon'=>$resolveIcon((string)($meta['icon'] ?? '')) ?: '',
                'order'=>(int)($meta['order'] ?? 9999),
                'roomKey'=>$roomKey,'room'=>$roomDisplay
            ];
        }
    }
}

/* =========================
   Scenes mapping (global->licht->scenes)
   ========================= */
$sceneItems = [];
$scene2Var = [];
$sceneTitleMap = [];
if (isset($CATALOG['global']['domains']['licht']['scenes']) && is_array($CATALOG['global']['domains']['licht']['scenes'])) {
    $sceneItems = $CATALOG['global']['domains']['licht']['scenes'];
    foreach ($sceneItems as $key => $def) {
        $id = 'szene.'.$key;
        if (is_array($def)) {
            $scene2Var[$id] = (int)($def['var'] ?? 0);
            if (isset($def['title'])) $sceneTitleMap[l_norm((string)$def['title'])] = $id;
        } else {
            $scene2Var[$id] = (int)$def;
        }
        // NEU: akzeptiere auch 'scene.' Alias für APL-Buttons
        $scene2Var['scene.'.$key] = $scene2Var[$id];
    }
}

// NEU: Reiner Scene-Tap? -> nur quittieren, nichts schalten
if (is_string($args2) && preg_match('/^(szene|scene)\.[a-z0-9_]+$/i', $args2)) {
    $sceneId   = preg_replace('/^scene\./i', 'szene.', trim($args2));
    $sceneKey  = substr($sceneId, 6);
    $sceneVar  = (int)($scene2Var[$sceneId] ?? 0);
    $sceneTitle = isset($sceneItems[$sceneKey]['title'])
        ? (string)$sceneItems[$sceneKey]['title']
        : ucwords(str_replace('_',' ', $sceneKey));

    SetValueString($V['lastVariableDevice'], 'Licht');
    SetValueString($V['lastVariableId'], $sceneVar);
    SetValueString($V['lastVariableAction'], 'tap');
    SetValueString($V['lastVariableValue'], $sceneTitle);

    $logV("[$RID] TARGET_RESOLVED ".json_encode([
        'sceneId'=>$sceneId, 'szeneVarId'=>$sceneVar, 'title'=>$sceneTitle
    ], $J));

    if (!$CAN_SWITCH) {
        echo json_encode(['speech'=>'Schalten ist aktuell deaktiviert. Ich habe nichts umgestellt.','reprompt'=>'','apl'=>null,'endSession'=>false], $JFLAGS);
        return;
    }

    echo json_encode([
        'speech'=>'Szene '.$sceneTitle.' gedrückt.',
        'reprompt'=>'',
        'apl'=>null,
        'endSession'=>false
    ], $JFLAGS);
    return;
}

/* =========================
   Pairing Dimmer → Switch
   ========================= */
$getPairedSwitchEntity = static function(?string $dimEntityId, array $switchFlat, ?string $dimTitle=null, ?string $roomKey=null): string {
    if (!is_string($dimEntityId) || $dimEntityId === '' || strpos($dimEntityId, 'dim.') !== 0) return '';
    $base = substr($dimEntityId, 4);
    $base = preg_replace('/(_?(dim(men|mer)?|level|wert|value|brightness|helligkeit|pct|percent)(_[a-z0-9]+)*)$/iu', '', $base);
    $candidate = 'light.' . $base;
    foreach ($switchFlat as $sw)
        if (isset($sw['entityId']) && mb_strtolower($sw['entityId'],'UTF-8') === mb_strtolower($candidate,'UTF-8'))
            return $candidate;
    if ($dimTitle !== null) {
        $needle = mb_strtolower(trim($dimTitle), 'UTF-8');
        foreach ($switchFlat as $sw) {
            $okTitle = isset($sw['title']) && mb_strtolower(trim((string)$sw['title']), 'UTF-8') === $needle;
            $okRoom  = ($roomKey === null) || (isset($sw['roomKey']) && (string)$sw['roomKey'] === (string)$roomKey);
            if ($okTitle && $okRoom) return (string)$sw['entityId'];
        }
    }
    return '';
};
foreach ($dimFlat as $i=>$d) {
    if (($d['pairedSwitch'] ?? '') === '')
        $dimFlat[$i]['pairedSwitch'] = $getPairedSwitchEntity($d['entityId'], $switchFlat, $d['title'] ?? null, $d['roomKey'] ?? null);
}

$parseLightAlias = static function (?string $s): ?array {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    if ($s === '') return null;
    if (strpos($s, 'licht.') !== 0 && strpos($s, 'light.') !== 0) return null;
    $s = preg_replace('/^light\./u', 'licht.', $s);            // Alias akzeptieren
    $rest  = substr($s, 6);                                     // nach "licht."
    $parts = array_values(array_filter(explode('.', $rest)));
    if (count($parts) < 2) return null;                         // erwartet: raum.key
    $roomKey = $parts[0];
    $key     = end($parts);
    return [$roomKey, $key, $rest];
};

$endsWithUnderscoreKey = static function (string $entityId, string $key): bool {
    $e = mb_strtolower($entityId, 'UTF-8');
    $k = mb_strtolower($key, 'UTF-8');
    return (bool)preg_match('/(^|_)'.preg_quote($k,'/').'$/u', $e);
};


/* =========================
   Find helpers
   ========================= */
$findSwitchByLabel = static function(string $label) use ($switchFlat): ?array {
    $needle = l_norm($label);
    foreach ($switchFlat as $r) if (l_norm((string)$r['title']) === $needle) return $r;
    return null;
};
$findSwitchByEntity = static function(string $entityOrAlias) use ($switchFlat, $parseLightAlias, $endsWithUnderscoreKey): ?array {
    $s = mb_strtolower(trim($entityOrAlias), 'UTF-8');
    if ($s === '') return null;

    // Alias: licht.<room>.<key> oder light.<room>.<key>
    if ($alias = $parseLightAlias($s)) {
        [$roomKey, $key] = $alias;
        foreach ($switchFlat as $r) {
            $rk = mb_strtolower((string)($r['roomKey'] ?? ''), 'UTF-8');
            if ($rk === $roomKey && $endsWithUnderscoreKey((string)$r['entityId'], $key)) return $r;
        }
        // Fallback: nur Key prüfen (Suffix)
        foreach ($switchFlat as $r) if ($endsWithUnderscoreKey((string)$r['entityId'], $key)) return $r;
    }

    // Exakt vergleichen
    foreach ($switchFlat as $r)
        if (mb_strtolower((string)$r['entityId'],'UTF-8') === $s) return $r;

    // Suffix-Match (z. B. nur „…deckenlicht“ übergeben)
    foreach ($switchFlat as $r)
        if (str_ends_with(mb_strtolower((string)$r['entityId'],'UTF-8'), $s)) return $r;

    return null;
};

$findDimByEntity = static function(string $entityOrAlias) use ($dimFlat, $parseLightAlias, $endsWithUnderscoreKey): ?array {
    $s = mb_strtolower(trim($entityOrAlias), 'UTF-8');
    if ($s === '') return null;

    if ($alias = $parseLightAlias($s)) {
        [$roomKey, $key] = $alias;
        foreach ($dimFlat as $r) {
            $rk = mb_strtolower((string)($r['roomKey'] ?? ''), 'UTF-8');
            if ($rk === $roomKey && $endsWithUnderscoreKey((string)$r['entityId'], $key)) return $r;
        }
        foreach ($dimFlat as $r) if ($endsWithUnderscoreKey((string)$r['entityId'], $key)) return $r;
    }

    foreach ($dimFlat as $r)
        if (mb_strtolower((string)$r['entityId'],'UTF-8') === $s) return $r;

    foreach ($dimFlat as $r)
        if (str_ends_with(mb_strtolower((string)$r['entityId'],'UTF-8'), $s)) return $r;

    return null;
};

$findSwitchUniqueByRoom = static function(string $room) use ($switchFlat): ?array {
    $rk = l_norm($room);
    $list = [];
    foreach ($switchFlat as $r) {
        if (l_norm((string)$r['room']) === $rk || l_norm((string)$r['roomKey']) === $rk) $list[] = $r;
    }
    if (count($list) === 1) return $list[0];
    return null;
};

$findDimByLabel = static function(string $label) use ($dimFlat): ?array {
    $needle = l_norm($label);
    foreach ($dimFlat as $r) if (l_norm((string)$r['title']) === $needle) return $r;
    return null;
};
$findDimUniqueByRoom = static function(string $room) use ($dimFlat): ?array {
    $rk = l_norm($room);
    $list = [];
    foreach ($dimFlat as $r) {
        if (l_norm((string)$r['room']) === $rk || l_norm((string)$r['roomKey']) === $rk) $list[] = $r;
    }
    if (count($list) === 1) return $list[0];
    return null;
};

/* =========================
   Aktionen ausführen (vor Render)
   ========================= */
$didAction = false;
$lastSwEntity   = null; $lastSwDesired = null;
$lastDimEntity  = null; $lastDimValue  = null;

/* Scenes (Guards + optional execute) */
$sceneTargetId = null;
if (is_string($args2) && strpos($args2, 'szene.') === 0) $sceneTargetId = $args2;
elseif (strpos((string)$device, 'szene.') === 0) $sceneTargetId = $device;
elseif (is_string($args2) && trim($args2) !== '' && isset($sceneTitleMap[l_norm((string)$args2)])) $sceneTargetId = $sceneTitleMap[l_norm((string)$args2)];

if ($sceneTargetId !== null) {
    $sceneVar = (int)($scene2Var[$sceneTargetId] ?? 0);
    if ($sceneVar <= 0) {
        echo json_encode(['speech'=>'Schaltvorgang nicht möglich. Variable nicht vorhanden.','reprompt'=>'','apl'=>null,'endSession'=>false], $JFLAGS);
        return;
    }
    $ON_WORDS  = ['an','ein','einschalten','on'];
    $OFF_WORDS = ['aus','ausschalten','off'];
    $actNorm = l_norm($action);
    $arg1Str = is_string($args1) ? l_norm($args1) : '';
    $wantOn = true;
    if (in_array($actNorm, $ON_WORDS, true)  || in_array($arg1Str, $ON_WORDS, true)  || $powerStr === 'on')  $wantOn = true;
    if (in_array($actNorm, $OFF_WORDS, true) || in_array($arg1Str, $OFF_WORDS, true) || $powerStr === 'off') $wantOn = false;
    
    SetValueString($V['lastVariableDevice'], 'Licht');
    SetValueString($V['lastVariableId'], $sceneVar);
    SetValueString($V['lastVariableAction'], $wantOn);
    SetValueString($V['lastVariableValue'], 'Scenes');

    $logV("[$RID] TARGET_RESOLVED ".json_encode([
        'title'=>(string)($target['title']??''),
        'entityId'=>(string)($target['entityId']??''),
        'szeneVarId'=>$sceneVar, 'desired'=>$wantOn
    ],$J));

    if (!$CAN_SWITCH) {
        echo json_encode(['speech'=>'Schaltvorgang ist aktuell deaktiviert. Ich habe nichts umgestellt.','reprompt'=>'','apl'=>null,'endSession'=>false], $JFLAGS);
        return;
    }
  
    @RequestAction($sceneVar, $wantOn ? 1 : 0);
    $didAction = true;
}

/* Dimmen */
if ($number !== null) {
    $target = null;
    if (is_string($args2) && trim($args2) !== '') {
        $target = $findDimByLabel($args2);
        if (!$target && strpos($args2, '.') !== false) $target = $findDimByEntity($args2);
    }
    if (!$target && strpos($device, '.') !== false) $target = $findDimByEntity($device);
    if (!$target && $roomSpoken !== '') $target = $findDimUniqueByRoom($roomSpoken);

    if ($target) {
        $setVarId = (int)($target['setVarId'] ?? 0);
        if ($setVarId <= 0) {
            echo json_encode(['speech'=>'Schaltvorgang nicht möglich. Variable nicht vorhanden.','reprompt'=>'','apl'=>null,'endSession'=>false], $JFLAGS);
            return;
        }

        $min = isset($target['min']) ? (float)$target['min'] : 0.0;
        $max = isset($target['max']) ? (float)$target['max'] : 100.0;
        $valClamped = max($min, min($max, (float)$number));

        SetValueString($V['lastVariableDevice'], 'Licht');
        SetValueString($V['lastVariableId'], $setVarId);
        SetValueString($V['lastVariableAction'], 'dimmen');
        SetValueString($V['lastVariableValue'], $valClamped);

         $logV("[$RID] TARGET_RESOLVED ".json_encode([
            'title'=>(string)($target['title']??''),
            'entityId'=>(string)($target['entityId']??''),
            'dimVarId'=>$setVarId, 'desired'=>$valClamped
        ],$J));

        if (!$CAN_DIM) {
          echo json_encode(['speech'=>'Dimmen ist aktuell deaktiviert. Ich habe nichts umgestellt.','reprompt'=>'','apl'=>null,'endSession'=>false], $JFLAGS);
          return;
        }
    
        @RequestAction($setVarId, $valClamped);
        $didAction     = true;
        $lastDimEntity = (string)$target['entityId'];
        $lastDimValue  = $valClamped;
    }
}
/* Schalten */
else {
    $actNorm = l_norm($action);
    $devNorm = l_norm($device);
    $arg1Str = is_string($args1) ? l_norm($args1) : '';

    $wantSwitchOp = false;
    $targetState  = null;

    $ON_WORDS  = ['an','ein','einschalten','on'];
    $OFF_WORDS = ['aus','ausschalten','off'];

    if (in_array($actNorm, $ON_WORDS, true)  || in_array($devNorm, $ON_WORDS, true)  || in_array($arg1Str, $ON_WORDS, true))  { $wantSwitchOp = true; $targetState = true; }
    if (in_array($actNorm, $OFF_WORDS, true) || in_array($devNorm, $OFF_WORDS, true) || in_array($arg1Str, $OFF_WORDS, true)) { $wantSwitchOp = true; $targetState = false; }

    if (!$wantSwitchOp && $powerStr !== '') { $wantSwitchOp = true; $targetState = ($powerStr === 'on'); }

    $preselect = null;
    if (!$wantSwitchOp && is_string($args2) && trim($args2) !== '') {
        $preselect = $findSwitchByLabel($args2);
        if (!$preselect && strpos($args2, '.') !== false) $preselect = $findSwitchByEntity($args2);
        if ($preselect) { $wantSwitchOp = true; $targetState = null; }
    }

    if ($wantSwitchOp) {
       
        $target = $preselect;
        if (!$target && is_string($args2) && trim($args2) !== '') {
            $target = $findSwitchByLabel($args2);
            if (!$target && strpos($args2, '.') !== false) $target = $findSwitchByEntity($args2);
        }
        if (!$target && strpos($device, '.') !== false)  $target = $findSwitchByEntity($device);
        if (!$target && $roomSpoken !== '')              $target = $findSwitchUniqueByRoom($roomSpoken);

        

        if ($target) {
            $stateVar = (int)($target['stateVarId']  ?? 0);
            $toggleVar= (int)($target['toggleVarId'] ?? 0);

            if ($toggleVar <= 0) {
                echo json_encode(['speech'=>'Schaltvorgang nicht möglich. Variable nicht vorhanden.','reprompt'=>'','apl'=>null,'endSession'=>false], $JFLAGS);
                return;
            }
            $now      = asBool(readVal($stateVar));
            $setTo    = ($targetState === null) ? !$now : (bool)$targetState;

            SetValueString($V['lastVariableDevice'], 'Licht');
            SetValueString($V['lastVariableId'], $toggleVar);
            SetValueString($V['lastVariableAction'], $setTo);
            SetValueString($V['lastVariableValue'], 'schalten');

            $logV("[$RID] TARGET_RESOLVED ".json_encode([
                'title'=>(string)($target['title']??''),
                'entityId'=>(string)($target['entityId']??''),
                'toggleVarId'=>$toggleVar, 'desired'=>$setTo
            ],$J));

            if (!$CAN_SWITCH) {
                echo json_encode(['speech'=>'Schaltvorgang ist aktuell deaktiviert. Ich habe nichts umgestellt.','reprompt'=>'','apl'=>null,'endSession'=>false], $JFLAGS);
                return;
            }

            @RequestAction($toggleVar, $setTo ? 1 : 0);
            $didAction     = true;
            $lastSwEntity  = (string)$target['entityId'];
            $lastSwDesired = $setTo ? true : false;
        }
    }
}

/* (Optional) Ziel-Logging nach Auflösung */
if ($lastSwEntity !== null)  $logV("[$RID] SWITCH target=".$lastSwEntity." desired=".($lastSwDesired?'true':'false'));
if ($lastDimEntity !== null) $logV("[$RID] DIMMER target=".$lastDimEntity." value=".$lastDimValue);

/* Nach Aktion kurz warten und Werte neu holen */
if ($didAction) {
    IPS_Sleep(RENDER_DELAY_MS);
foreach ($switchFlat as $i=>$sw) { $switchFlat[$i]['isOn'] = asBool(readVal((int)$sw['stateVarId'])); }
foreach ($dimFlat as $i=>$d) {
    $stateVarId = isset($d['stateVarId']) ? (int)$d['stateVarId'] : 0;
    $isOn       = $stateVarId > 0 ? asBool(readVal($stateVarId)) : null;
    $value      = (asNumber(readVal((int)$d['valueVarId'])) ?? 0);
    if ($isOn === false) {
        $value = 0.0;
    }
    $dimFlat[$i]['value'] = $value;
    if ($isOn !== null) {
        $dimFlat[$i]['isOn'] = $isOn;
    }
}
}

/* =========================
   Index-Maps für sichere Visual-Updates
   ========================= */
$swIndexMap  = [];
foreach ($switchRowsByFloor as $fk => $list) {
    foreach ($list as $i => $r) {
        if (!isset($r['section']) && isset($r['entityId']) && $r['entityId'] !== '') {
            $swIndexMap[$r['entityId']] = [$fk, $i];
        }
    }
}
$dimIndexMap = [];
foreach ($dimRowsByFloor as $fk => $list) {
    foreach ($list as $i => $r) {
        if (!isset($r['section']) && isset($r['entityId']) && $r['entityId'] !== '') {
            $dimIndexMap[$r['entityId']] = [$fk, $i];
        }
    }
}

$applySwitchByMap = static function(array &$rowsByFloor, array $indexMap, string $entityId, bool $isOn): void {
    if ($entityId === '' || !isset($indexMap[$entityId])) return;
    [$fk, $idx] = $indexMap[$entityId];
    if (isset($rowsByFloor[$fk][$idx])) $rowsByFloor[$fk][$idx]['isOn'] = $isOn;
};
$applyDimByMap = static function(array &$rowsByFloor, array $indexMap, string $entityId, float $value): void {
    if ($entityId === '' || !isset($indexMap[$entityId])) return;
    [$fk, $idx] = $indexMap[$entityId];
    if (isset($rowsByFloor[$fk][$idx])) $rowsByFloor[$fk][$idx]['value'] = $value;
};

/* =========================
   Gezieltes Visual-Update (per Map)
   ========================= */
if (is_string($lastSwEntity) && $lastSwEntity !== '' && $lastSwDesired !== null) {
    $applySwitchByMap($switchRowsByFloor, $swIndexMap, $lastSwEntity, (bool)$lastSwDesired);
    if ($lastSwDesired === false) {
        foreach ($dimFlat as $d) {
            if (($d['pairedSwitch'] ?? '') === $lastSwEntity) {
                $applyDimByMap($dimRowsByFloor, $dimIndexMap, (string)$d['entityId'], 0.0);
            }
        }
    }
}

if (is_string($lastDimEntity) && $lastDimEntity !== '' && $lastDimValue !== null) {
    $applyDimByMap($dimRowsByFloor, $dimIndexMap, $lastDimEntity, (float)$lastDimValue);
    $pairedSwitch = '';
    foreach ($dimFlat as $d) if ($d['entityId'] === $lastDimEntity) { $pairedSwitch = (string)($d['pairedSwitch'] ?? ''); break; }
    if ($pairedSwitch !== '') {
        $applySwitchByMap($switchRowsByFloor, $swIndexMap, $pairedSwitch, ($lastDimValue > 1.0));
    }
}

/* =========================
   Sanfte Gesamtsynchronisierung (mit Overrides, map-basiert)
   ========================= */
$dimBySwitch = [];
foreach ($dimFlat as $idx => $d) {
    $paired = (string)($d['pairedSwitch'] ?? '');
    if ($paired !== '') $dimBySwitch[$paired][] = $idx;
}

$overrideSwEntity  = (is_string($lastSwEntity)  && $lastSwEntity  !== '' && $lastSwDesired !== null) ? $lastSwEntity  : null;
$overrideSwState   = $overrideSwEntity ? (bool)$lastSwDesired : null;

$overrideDimEntity = (is_string($lastDimEntity) && $lastDimEntity !== '' && $lastDimValue  !== null) ? $lastDimEntity : null;
$overrideDimValue  = $overrideDimEntity ? (float)$lastDimValue : null;

foreach ($switchFlat as $sw) {
    $entityId   = (string)$sw['entityId'];
    $realState  = (bool)$sw['isOn'];

    if ($overrideSwEntity !== null && $entityId === $overrideSwEntity) {
        $realState = (bool)$overrideSwState;
    }

    $anyDimVal = 0.0;
    if (!empty($dimBySwitch[$entityId])) {
        foreach ($dimBySwitch[$entityId] as $didx) {
            $val = (float)($dimFlat[$didx]['value'] ?? 0);
            if ($overrideDimEntity !== null && isset($dimFlat[$didx]['entityId']) && $dimFlat[$didx]['entityId'] === $overrideDimEntity) {
                $val = (float)$overrideDimValue;
            }
            if ($val > $anyDimVal) $anyDimVal = $val;
        }
    }

    if ($realState || $anyDimVal > 1.0) {
        $applySwitchByMap($switchRowsByFloor, $swIndexMap, $entityId, true);
    } else {
        $applySwitchByMap($switchRowsByFloor, $swIndexMap, $entityId, false);
        if (!empty($dimBySwitch[$entityId])) {
            foreach ($dimBySwitch[$entityId] as $didx) {
                $applyDimByMap($dimRowsByFloor, $dimIndexMap, (string)$dimFlat[$didx]['entityId'], 0.0);
            }
        }
    }
}

/* =========================
   Compose sections
   ========================= */
$compose = static function(array &$byFloor, string $labelSuffix = '') use ($floorOrder,$floorLabels,$floorSectionStyle,$unknownFloorKey,$unknownFloorLabel) {
    foreach ($byFloor as $fk=>&$list) {
        usort($list, static function($a,$b){
            $oa = (int)($a['order'] ?? 9999); $ob = (int)($b['order'] ?? 9999);
            if ($oa !== $ob) return $oa <=> $ob;
            return strcmp((string)($a['title'] ?? ''),(string)($b['title'] ?? ''));
        });
    }
    unset($list);

    $present = array_keys($byFloor);
    $ordered = [];
    foreach ($floorOrder as $fk) if (isset($byFloor[$fk])) $ordered[] = $fk;
    $remain = array_diff($present, $ordered);
    sort($remain, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($remain as $fk) if ($fk !== $unknownFloorKey) $ordered[] = $fk;
    if (isset($byFloor[$unknownFloorKey])) $ordered[] = $unknownFloorKey;

    $out = [];
    foreach ($ordered as $fk) {
        $label = $floorLabels[$fk] ?? ($fk === $unknownFloorKey ? $unknownFloorLabel : $fk);
        $title = $labelSuffix !== '' ? ($label.' '.$labelSuffix) : $label;
        $out[] = [
            'section'=>$title,'sectionH'=>$floorSectionStyle['height'],'sectionFont'=>$floorSectionStyle['fontSize'],
            'sectionBold'=>(bool)$floorSectionStyle['bold'],'sectionPadY'=>$floorSectionStyle['padY']
        ];
        foreach ($byFloor[$fk] as $row) $out[] = $row;
    }
    return $out;
};

$switchItems = $compose($switchRowsByFloor, '');
$dimItems    = $compose($dimRowsByFloor,   '');
$statusItems = $compose($statusRowsByFloor,'');

/* =========================
   APL render
   ========================= */
$doc = ['type'=>'Link','src'=>'doc://alexa/apl/documents/Licht'];
$ds  = ['imageListData'=>[
    'title'=>'LICHT','subtitle'=>'Schalten · Dimmen · Helligkeit','activeTab'=>'Schalten',
    'switchItems'=>$switchItems,'dimItems'=>$dimItems,'statusItems'=>$statusItems,'sceneItems'=>$sceneItems
]];
$apl = $aplSupported ? ['doc'=>$doc,'ds'=>$ds,'token'=>'hv-licht'] : null;

echo json_encode(['speech'=>'','reprompt'=>'','apl'=>$apl,'endSession'=>false], $JFLAGS);
