<?php
/**
 * ============================================================
 * JalousieRenderer.php — RoomsCatalog-only (APL + Aktionen)
 * ============================================================
 * - Nimmt Sprach-Slots UND APL-Events entgegen (args1/args2/args)
 * - Führt Jalousie- und Szenen-Aktionen aus (wenn freigegeben)
 * - Liest Live-Prozentwerte (lesen/posRead → wert Fallback)
 * - Rendert APL Document-Link "doc://alexa/apl/documents/Jalousie"
 * - Floors (EG/OG/DG/UG/AUSSEN/…) aus RoomsCatalog['global']['floors']
 * - Icons via /hook/icons (BaseUrl/Token)
 * - Globaler Payload-Limiter für AlexaCustomSkill-Buffer
 *
 * ---------------------------
 * Änderungsverlauf (Changelog)
 * ---------------------------
 * 2025-10-29: Unified ACTION FLAG → nur noch CFG.var.ActionsEnabled['jalousie_steuern']
 * 2025-10-29: Logging-Flags ($LOG_BASIC/$LOG_VERBOSE) + $logB/$logV
 * 2025-10-29: APL-Args: args1/args2/args robust; EntityId + Action auswerten
 * 2025-10-29: TARGET_PRE/TARGET/WRITE Logs (immer)
 * 2025-10-29: **Neu**: Individuelle Sprachsätze immer mit Titel:
 *             • Prozent: „Fahre Jalousie <Titel> auf X %."
 *             • Aktionen: „Jalousie <Titel> öffnet/schließt/…"
 *             (Global-Ansage unverändert)
 * 2025-10-29: **Guards ergänzt**: Actions disabled & fehlende Variable → harte Abbrüche (APL=null)
 * 2025-10-29: **Szene-Support**: args/args2/EntityId akzeptiert nun "szene.*";
 *             Aktionen schreiben via einheitlichem Flag; Sprachsätze für Szenen.
 */

$in = json_decode($_IPS['payload'] ?? '{}', true) ?: [];

/* =========================
   CFG / ACTION FLAG (Unified)
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

/* Ein einziges Flag für das gesamte Script */
$CAN_JAL = $readBool((int)($VAR['jalousie_steuern'] ?? 0), false);

/* =========================
   Logging
   ========================= */
$LOG_BASIC   = isset($in['logBasic'])   ? (bool)$in['logBasic']   : (isset($CFG['flags']['log_basic'])   ? (bool)$CFG['flags']['log_basic']   : true);
$LOG_VERBOSE = isset($in['logVerbose']) ? (bool)$in['logVerbose'] : (isset($CFG['flags']['log_verbose']) ? (bool)$CFG['flags']['log_verbose'] : true);
$LOG_TAG     = 'Alexa';

$logB = static function(string $msg) use ($LOG_BASIC, $LOG_TAG)  { if ($LOG_BASIC)  IPS_LogMessage($LOG_TAG, $msg); };
$logV = static function(string $msg) use ($LOG_VERBOSE, $LOG_TAG){ if ($LOG_VERBOSE) IPS_LogMessage($LOG_TAG, $msg); };
$logKV = static function(string $prefix, array $kv) use ($LOG_TAG) {
    IPS_LogMessage($LOG_TAG, $prefix.' '.json_encode($kv, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
};

/* =========================
   Eingaben (Slots & APL)
   ========================= */
$aplSupported = !empty($in['aplSupported']);
$action       = (string)($in['action'] ?? '');
$szene        = (string)($in['szene'] ?? '');
$device       = (string)($in['device'] ?? '');
$room         = (string)($in['room'] ?? '');
$object       = (string)($in['object'] ?? '');
$number       = $in['number'] ?? null;
$prozent      = $in['prozent'] ?? null;
$alles        = (string)($in['alles'] ?? '');
$alexa        = (string)($in['alexa'] ?? '');
$entityIdIn   = (string)($in['entityId'] ?? '');

/* APL-Args akzeptieren (Array oder getrennte args1/args2) */
$rawArgs  = $in['args']  ?? null;
$rawA1    = $in['args1'] ?? null;
$rawA2    = $in['args2'] ?? null;

$aplArgs = null;
if (is_string($rawArgs)) {
    $tmp = @json_decode($rawArgs, true);
    if (is_array($tmp)) $aplArgs = $tmp;
} elseif (is_array($rawArgs)) {
    $aplArgs = $rawArgs;
}

$logV("JalousieRenderer ENTER");
$logV("Raw Input → a:$action s:$szene d:$device r:$room o:$object n:$number p:$prozent al:$alles ent:$entityIdIn rawA1:$rawA1 rawA2:$rawA2");

/* =========================
   Globale Konfig / Katalog
   ========================= */
$S = is_array($CFG['script'] ?? null) ? $CFG['script'] : [];
$V = is_array($CFG['var']    ?? null) ? $CFG['var']    : [];

$readVarValue = static function($entry) {
    if (is_int($entry) && $entry > 0) {
        $val = @GetValueString($entry);
        if (is_string($val)) return trim($val);
        $mixed = @GetValue($entry);
        return is_scalar($mixed) ? trim((string)$mixed) : '';
    }
    if (is_string($entry)) return trim($entry);
    return '';
};

$baseUrl = trim((string)($CFG['baseUrl'] ?? ''));
$token   = trim((string)($CFG['token']   ?? ''));
if ($baseUrl === '' && isset($V['BaseUrl'])) $baseUrl = $readVarValue($V['BaseUrl']);
if ($token   === '' && isset($V['Token']))   $token   = $readVarValue($V['Token']);

$roomsFileId = $S['ROOMS_CATALOG'] ?? null;
if ($roomsFileId === null) {
    IPS_LogMessage($LOG_TAG, 'ROOMS_CATALOG not set.');
    echo json_encode(['speech'=>'Konfiguration fehlt.','reprompt'=>'','apl'=>null,'endSession'=>false], JSON_UNESCAPED_UNICODE);
    return;
}
$ROOMS = require IPS_GetScriptFile($roomsFileId);

/* =========================
   Normalizer / Utils
   ========================= */
function j_norm(string $s): string {
    $t = mb_strtolower(trim($s), 'UTF-8');
    return str_replace(['oe','ae','ue','ss'], ['ö','ä','ü','ß'], $t);
}
$jal_starts_with = static function(string $hay, string $needle): bool {
    return $needle === '' || strncmp($hay, $needle, strlen($needle)) === 0;
};

$iconUrl = static function(string $file, string $baseUrl, string $token): string {
    $path = IPS_GetKernelDir().'user'.DIRECTORY_SEPARATOR.'icons'.DIRECTORY_SEPARATOR.$file;
    $v = @filemtime($path) ?: 1;
    return rtrim($baseUrl,'/').'/hook/icons?f='.rawurlencode($file).'&token='.rawurlencode($token).'&v='.$v;
};
$icon = static function(string $f) use ($iconUrl,$baseUrl,$token){ return $iconUrl($f,$baseUrl,$token); };

function getPercentSafe(int $varId): ?int {
    if ($varId <= 0) return null;
    $v = @GetValue($varId);
    if (!is_numeric($v)) return null;
    $p = (int)round((float)$v);
    return max(0, min(100, $p));
}
function stateFromPercent(int $p): string {
    if ($p <= 5)  return 'öffnen';
    if ($p >= 95) return 'schliessen';
    if (abs($p - 50) <= 5) return 'mitte';
    if (abs($p - 70) <= 5) return 'beschatten';
    if ($p <= 25) return 'lueften';
    return 'mitte';
}
function applyLivePercentAndState(array $rows, array $entity2PosRead, array $entity2Var): array {
    foreach ($rows as &$r) {
        if (empty($r['entityId'])) continue;
        $eid = $r['entityId'];
        $pid = (int)($entity2PosRead[$eid] ?? 0);
        if ($pid <= 0) $pid = (int)($entity2Var[$eid] ?? 0);
        $p = $pid ? getPercentSafe($pid) : null;
        if ($p !== null) {
            $title = (string)($r['title'] ?? '');
            if (preg_match('/^(.*?)(\s+\d+%)(\s*\(B\))?$/u', $title, $m)) {
                $base = rtrim($m[1]); $suffixB = $m[3] ?? '';
                $r['title'] = $base.' '.$p.'%'.$suffixB;
            } else {
                $r['title'] = rtrim($title).' '.$p.'%';
            }
            $r['state'] = stateFromPercent($p);
        }
    }
    unset($r);
    return $rows;
}
function findEntityId(array $rows, string $roomNorm, string $deviceNorm, string $objectNorm): ?string {
    if ($roomNorm === '' && $deviceNorm === '' && $objectNorm === '') return null;
    foreach ($rows as $r) {
        $t = j_norm($r['title'] ?? '');
        if ($roomNorm !== ''   && mb_strpos($t, $roomNorm, 0, 'UTF-8')   !== false) return $r['entityId'] ?? null;
        if ($deviceNorm !== '' && mb_strpos($t, $deviceNorm, 0, 'UTF-8') !== false) return $r['entityId'] ?? null;
        if ($objectNorm !== '' && mb_strpos($t, $objectNorm, 0, 'UTF-8') !== false) return $r['entityId'] ?? null;
    }
    return null;
}
/* Titel ohne Prozent aus aktueller Row-Liste holen */
function titleByEntity(array $rows, string $entityId): string {
    foreach ($rows as $r) {
        if (($r['entityId'] ?? '') === $entityId) {
            $t = trim((string)($r['title'] ?? ''));
            $t = preg_replace('/\s+\d+%\s*(?:\(B\))?$/u', '', $t) ?? $t;
            return $t;
        }
    }
    return '';
}

/* =========================
   Floors
   ========================= */
$GF = is_array($ROOMS['global']['floors'] ?? null) ? $ROOMS['global']['floors'] : [];
$floorOrder = [];
if (isset($GF['order']) && is_array($GF['order'])) foreach ($GF['order'] as $fk) $floorOrder[] = strtoupper((string)$fk);
$floorLabels = [];
if (isset($GF['labels']) && is_array($GF['labels'])) foreach ($GF['labels'] as $fk => $lab) $floorLabels[strtoupper((string)$fk)] = (string)$lab;
$floorSectionDefault = ['height'=>'4.4vw','fontSize'=>'1.9vw','bold'=>false,'padY'=>'0.8vw'];
if (isset($GF['section']) && is_array($GF['section'])) $floorSectionDefault = array_merge($floorSectionDefault, $GF['section']);

/* =========================
   Rows/Maps aus Catalog
   ========================= */
$entity2Var     = [];
$entity2PosRead = [];
$baseRows       = [];

$defaultIconFile = (string)($ROOMS['global']['jalousie']['icon'] ?? '');
$iconUrlDefault  = $defaultIconFile !== '' ? $icon($defaultIconFile) : null;

foreach ($ROOMS as $roomKey => $def) {
    if ($roomKey === 'global') continue;
    if (!isset($def['domains']['jalousie']) || !is_array($def['domains']['jalousie'])) continue;

    $roomDisplay = (string)($def['display'] ?? $roomKey);
    $roomFloor   = strtoupper((string)($def['floor'] ?? ''));

    foreach ($def['domains']['jalousie'] as $unitKey => $u) {
        $title = (string)($u['title'] ?? ($roomDisplay.' '.$unitKey));
        $wert  = isset($u['wert'])  ? (int)$u['wert']  : 0;
        $lesen = isset($u['lesen']) ? (int)$u['lesen'] : (int)($u['posRead'] ?? 0);
        $order = isset($u['order']) ? (int)$u['order'] : 9999;
        if ($wert <= 0) continue;

        $iconUse = $iconUrlDefault;
        if (isset($u['icon']) && is_string($u['icon']) && $u['icon'] !== '') {
            $ic = trim($u['icon']);
            $iconUse = preg_match('#^https?://#i', $ic) ? $ic : $icon($ic);
        }

        $floorKeyUnit = strtoupper((string)($u['floor'] ?? ''));
        $floorKey = $floorKeyUnit !== '' ? $floorKeyUnit : ($roomFloor !== '' ? $roomFloor : '');

        $entityId = 'jalousie.'.$roomKey.'.'.$unitKey;
        $entity2Var[$entityId] = $wert;
        if ($lesen > 0) $entity2PosRead[$entityId] = $lesen;

        $baseRows[] = [
            'icon'      => $iconUse,
            'title'     => $title,
            'entityId'  => $entityId,
            'state'     => 'mitte',
            'order'     => $order,
            'floorKey'  => $floorKey,
        ];
    }
}

/* =========================
   Szenen / Global
   ========================= */
$GJ = is_array($ROOMS['global']['jalousie'] ?? null) ? $ROOMS['global']['jalousie'] : [];
$GLOBAL_OPEN_CLOSE_VAR = (int)($GJ['open_close_var'] ?? 0);

$scene2Var = [];
$sceneTitles = [];
$sceneOrderKs = [];
if (isset($GJ['scenes']) && is_array($GJ['scenes'])) {
    foreach ($GJ['scenes'] as $key => $def) {
        $sceneOrderKs[] = (string)$key;
        if (is_array($def)) {
            $scene2Var['szene.'.$key] = (int)($def['var'] ?? 0);
            if (isset($def['title'])) $sceneTitles[$key] = (string)$def['title'];
        } else {
            $scene2Var['szene.'.$key] = (int)$def;
        }
    }
}

/* =========================
   Eingaben normalisieren + APL-Map
   ========================= */
$speech   = '';
$reprompt = 'Noch etwas ändern?';

$actionNorm = j_norm($action);
$roomNorm   = j_norm($room);
$deviceNorm = j_norm($device);
$objectNorm = j_norm($object);
$allesNorm  = j_norm($alles);
$targetEntityId = $entityIdIn;

/* APL-Array-Args */
if (is_array($aplArgs) && count($aplArgs) >= 3) {
    $maybeAction = j_norm((string)$aplArgs[1]);
    $maybeId     = (string)$aplArgs[2];
    if (in_array($maybeAction, ['öffnen','oeffnen','schließen','schliessen','stop','mitte','lueften','beschatten'], true)) {
        $actionNorm = $maybeAction;
    }
    if ($targetEntityId === '' && $maybeId !== '' && ($jal_starts_with($maybeId, 'jalousie.') || $jal_starts_with($maybeId, 'szene.'))) {
        $targetEntityId = $maybeId;
    }
}
/* Getrennte args1/args2 Strings */
if (is_string($rawA1) && $rawA1 !== '') {
    $maybeAction = j_norm($rawA1);
    if (in_array($maybeAction, ['öffnen','oeffnen','schließen','schliessen','stop','mitte','lueften','beschatten'], true)) {
        $actionNorm = $maybeAction;
    }
}
if ($targetEntityId === '' && is_string($rawA2) && ($jal_starts_with($rawA2, 'jalousie.') || $jal_starts_with($rawA2, 'szene.'))) {
    $targetEntityId = $rawA2;
}
/* entityId Feld */
if ($targetEntityId === '' && $entityIdIn !== '' && ($jal_starts_with($entityIdIn, 'jalousie.') || $jal_starts_with($entityIdIn, 'szene.'))) {
    $targetEntityId = $entityIdIn;
}

/* Synonyme + Prozent */
if (in_array($actionNorm, ['zu','zumachen','close'], true))  { $actionNorm = 'schliessen'; }
if (in_array($actionNorm, ['auf','aufmachen','open'], true)) { $actionNorm = 'öffnen'; }
$percent = ($number !== null)  ? max(0, min(100, (int)$number))
         : (($prozent !== null) ? max(0, min(100, (int)$prozent)) : null);

/* ===== Frühes TARGET_PRE-Log (immer) ===== */
$preVarId = null;
if ($targetEntityId !== '') {
    if (isset($entity2Var[$targetEntityId])) {
        $preVarId = (int)$entity2Var[$targetEntityId];
    } elseif (isset($scene2Var[$targetEntityId])) {
        $preVarId = (int)$scene2Var[$targetEntityId];
    }
} elseif ($percent === null && in_array($actionNorm, ['öffnen','oeffnen','schließen','schliessen'], true)) {
    $preVarId = (int)$GLOBAL_OPEN_CLOSE_VAR ?: null;
}

SetValueString($V['lastVariableDevice'], 'Jalousie');
SetValueString($V['lastVariableId'], $preVarId);
SetValueString($V['lastVariableAction'], $actionNorm);
SetValueString($V['lastVariableValue'], $percent);

$logKV('TARGET_PRE', [
    'entityId'       => $targetEntityId !== '' ? $targetEntityId : null,
    'wertVarId'      => $preVarId,
    'action'         => $actionNorm,
    'percent'        => $percent,
    'actionsEnabled' => $CAN_JAL
]);

/* =========================
   Rows + Floors sortieren
   ========================= */
$rows = applyLivePercentAndState($baseRows, $entity2PosRead, $entity2Var);

$rowsByFloor = [];
$unknown = 'Z';
$unknownLabel = $GF['unknownLabel'] ?? 'Weitere';

foreach ($rows as $r) {
    $fk = strtoupper((string)($r['floorKey'] ?? ''));
    if ($fk === '') $fk = $unknown;
    $rowsByFloor[$fk][] = $r;
}
$cmpRows = function(array $a, array $b): int {
    $oa = (int)($a['order'] ?? 9999);
    $ob = (int)($b['order'] ?? 9999);
    if ($oa !== $ob) return $oa <=> $ob;
    return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
};
foreach ($rowsByFloor as $fk => &$list) usort($list, $cmpRows);
unset($list);

$presentFloors = array_keys($rowsByFloor);
$orderedFloors = [];
foreach ($floorOrder as $fk) if (isset($rowsByFloor[$fk])) $orderedFloors[] = $fk;
$remaining = array_diff($presentFloors, $orderedFloors);
sort($remaining, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($remaining as $fk) if ($fk !== $unknown) $orderedFloors[] = $fk;
if (isset($rowsByFloor[$unknown])) $orderedFloors[] = $unknown;

/* =========================
   Device-Erkennung + Titel-Fallback (vor Global)
   ========================= */
$deviceIsJalousie = ($deviceNorm === '' || in_array($deviceNorm, ['jalousie','jalousien','rollo','rollladen','rollläden','raffstore','beschattung'], true));

if ($targetEntityId === '' && $roomNorm === '' && $objectNorm === '' && $deviceIsJalousie) {
    $titleKeyword = '';
    if ($room !== '') {
        $titleKeyword = j_norm($room);
    } elseif ($object !== '') {
        $titleKeyword = j_norm($object);
    } elseif (is_array($aplArgs) && count($aplArgs) >= 3 && $jal_starts_with((string)$aplArgs[2], 'jalousie.')) {
        $parts = explode('.', (string)$aplArgs[2]);
        if (count($parts) >= 3) $titleKeyword = j_norm($parts[1] ?? '');
    }

    if ($titleKeyword !== '') {
        $flatRows = [];
        foreach ($orderedFloors as $fk) foreach ($rowsByFloor[$fk] as $rr) $flatRows[] = $rr;

        $cand = array_values(array_filter($flatRows, static function($r) use ($titleKeyword) {
            $t = j_norm((string)($r['title'] ?? ''));
            return $t !== '' && mb_strpos($t, $titleKeyword, 0, 'UTF-8') !== false;
        }));

        if (count($cand) === 1 && !empty($cand[0]['entityId'])) {
            $targetEntityId = (string)$cand[0]['entityId'];
            $logV("Titel-Fallback gewählt → ".$targetEntityId);
        }
    }
}

/* =========================
   Global: "jalousie auf/zu" ohne Ziel → Karte (unverändert)
   ========================= */
if (
    !$targetEntityId &&
    $roomNorm === '' &&
    $objectNorm === '' &&
    $deviceIsJalousie &&
    $percent === null &&
    in_array($actionNorm, ['öffnen','oeffnen','schliessen','schließen'], true)
) {
    $isOpen = in_array($actionNorm, ['öffnen','oeffnen'], true);
    $text   = $isOpen ? 'Die Jalousien fahren auf' : 'Die Jalousien fahren zu';

    $varId = (int)$GLOBAL_OPEN_CLOSE_VAR;

    SetValueString($V['lastVariableDevice'], 'Jalousie');
    SetValueString($V['lastVariableId'], $varId);
    SetValueString($V['lastVariableAction'], $actionNorm);
    SetValueString($V['lastVariableValue'], $percent);

    $logKV('TARGET', [
        'entityId'       => null,
        'wertVarId'      => $varId ?: null,
        'action'         => $actionNorm,
        'percent'        => null,
        'actionsEnabled' => $CAN_JAL
    ]);

    if ($CAN_JAL) {
        $speech = $text . '.';
        if ($varId > 0) {
            $val = $isOpen ? 1 : 2;
            $logKV('WRITE', ['entityId'=>null,'wertVarId'=>$varId,'value'=>$val]);
            RequestAction($varId, $val);
        } else {
            $logB("GLOBAL var not set → no write");
        }
    } else {
        $speech   = 'Jalousien global steuern ist derzeit nicht freigegeben. Ich habe nichts umgestellt.';
        $reprompt = '';
        $logB("ACTIONS_DISABLED(jalousie_steuern - global): skip write");

        echo json_encode([
            'speech'      => $speech,
            'reprompt'    => $reprompt,
            'apl'         => null,
            'card'        => null,
            'endSession'  => false
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    $textVisu  = "- Küche \n- Wohnzimmer \n- Elternzimmer \n- Bad \n- Stiegenhaus \n\n";
    $textVisu .= $isOpen ? "Schönen Tag! \n\n" : "Schönen Abend! \n\n";
    $textVisu .= "\n von: ".$alexa;
    $img = $icon($isOpen ? 'JalousieTag.png' : 'JalousieAbend.png');

    $card = [
        'type'          => 'Standard',
        'title'         => $text,
        'text'          => $textVisu,
        'smallImageUrl' => $img,
        'largeImageUrl' => $img
    ];

    echo json_encode([
        'speech'      => $speech,
        'reprompt'    => $reprompt,
        'apl'         => null,
        'card'        => $card,
        'endSession'  => false
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

/* =========================
   Aktionen (Unified Gating)
   ========================= */
function applyBlindAction(?int $wertVarId, ?string $action, ?int $percent, bool $canAct, callable $logB): bool {
    if (!$wertVarId) return false;
    if (!$canAct) { $logB("ACTIONS_DISABLED(jalousie_steuern): skip write"); return false; }

    if ($percent !== null) { RequestAction($wertVarId, max(0, min(100, (int)$percent))); return true; }
    switch ($action) {
        case 'öffnen': case 'oeffnen':       RequestAction($wertVarId, 0);   break;
        case 'schließen': case 'schliessen': RequestAction($wertVarId, 100); break;
        case 'stop':       RequestAction($wertVarId, 999); break;
        case 'mitte':      RequestAction($wertVarId, 50);  break;
        case 'lueften':    RequestAction($wertVarId, 20);  break;
        case 'beschatten': RequestAction($wertVarId, 70);  break;
        default: return false;
    }
    return true;
}
function applySceneAction(array $scene2Var, string $sceneId, string $action, bool $canAct, callable $logB): bool {
    $varId = $scene2Var[$sceneId] ?? 0;
    if (!$varId) return false;
    if (!$canAct) { $logB("ACTIONS_DISABLED(jalousie_steuern - scenes): skip write"); return false; }

    $a = j_norm($action);
    $val = ($a === 'öffnen' || $a === 'oeffnen') ? 1 : 2;
    RequestAction($varId, $val);
    return true;
}

/* =========================
   Einzel-Jalousie oder Szene (mit Sprachsatz)
   ========================= */
$validAct = ['öffnen','oeffnen','schließen','schliessen','auf','zu','stop','mitte','lueften','beschatten'];
if ($percent !== null || in_array($actionNorm, $validAct, true)) {
    if (!$CAN_JAL) {
        echo json_encode([
            'speech'     => 'Jalousie steuern ist derzeit nicht freigegeben. Ich habe nichts umgestellt.',
            'reprompt'   => '',
            'apl'        => null,
            'endSession' => false
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    /* Szene-Ziel? */
    if ($targetEntityId === '' && $szene !== '') {
        $maybeScene = 'szene.'.j_norm($szene);
        if (isset($scene2Var[$maybeScene])) $targetEntityId = $maybeScene;
    }

    /* Wenn noch kein Ziel: über Titel/Raum/Objekt versuchen (nur Jalousie) */
    if ($targetEntityId === '') {
        $flatRows = [];
        foreach ($orderedFloors as $fk) foreach ($rowsByFloor[$fk] as $rr) $flatRows[] = $rr;
        $targetEntityId = findEntityId($flatRows, $roomNorm, $deviceNorm, $objectNorm);
    }

    /* TARGET-Log */
    $wertIdForLog = null;
    if ($targetEntityId !== '') {
        if (isset($entity2Var[$targetEntityId])) $wertIdForLog = (int)$entity2Var[$targetEntityId];
        elseif (isset($scene2Var[$targetEntityId])) $wertIdForLog = (int)$scene2Var[$targetEntityId];
    }
    $logKV('TARGET', [
        'entityId'       => $targetEntityId !== '' ? $targetEntityId : null,
        'wertVarId'      => $wertIdForLog,
        'action'         => $actionNorm,
        'percent'        => $percent,
        'actionsEnabled' => $CAN_JAL
    ]);

    /* Szene schreiben */
    if ($targetEntityId !== '' && isset($scene2Var[$targetEntityId])) {
        $ok = applySceneAction($scene2Var, $targetEntityId, $actionNorm, $CAN_JAL, $logB);
        if ($ok) {
            $key = (string)mb_substr($targetEntityId, 6);
            $title = $sceneTitles[$key] ?? mb_convert_case(str_replace(['_','-'],' ',$key), MB_CASE_TITLE, 'UTF-8');
            $speech = ($actionNorm === 'öffnen' || $actionNorm === 'oeffnen')
                ? 'Szene '.$title.' wird geöffnet.'
                : 'Szene '.$title.' wird geschlossen.';
        }
        /* APL bleibt regulär; keine Live-Row-Änderung nötig */
    }
    /* Einzel-Jalousie schreiben */
    elseif ($targetEntityId !== '' && isset($entity2Var[$targetEntityId])) {
        $wertId = (int)$entity2Var[$targetEntityId];

        if ($wertId <= 0) {
            echo json_encode([
                'speech'     => 'Schaltvorgang nicht möglich. Variable nicht vorhanden.',
                'reprompt'   => '',
                'apl'        => null,
                'endSession' => false
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $valueToWrite = null;
        if ($percent !== null) {
            $valueToWrite = max(0, min(100, (int)$percent));
        } else {
            switch ($actionNorm) {
                case 'öffnen': case 'oeffnen':       $valueToWrite = 0;   break;
                case 'schließen': case 'schliessen': $valueToWrite = 100; break;
                case 'stop':       $valueToWrite = 999; break;
                case 'mitte':      $valueToWrite = 50;  break;
                case 'lueften':    $valueToWrite = 20;  break;
                case 'beschatten': $valueToWrite = 70;  break;
            }
        }
        if ($valueToWrite !== null) {
            $logKV('WRITE', ['entityId'=>$targetEntityId,'wertVarId'=>$wertId,'value'=>$valueToWrite]);
            RequestAction($wertId, $valueToWrite);
        }

        $title  = titleByEntity($rows, $targetEntityId);
        $prefix = 'Jalousie'.($title !== '' ? ' '.$title : '');

        if ($percent !== null) {
            $speech = 'Fahre '.$prefix.' auf '.$percent.' Prozent.';
        } else {
            $verb = ($actionNorm === 'oeffnen') ? 'öffnen' : $actionNorm;
            if ($verb === 'schliessen' || $verb === 'schließen') $speech = $prefix.' schließt.';
            elseif ($verb === 'öffnen')                            $speech = $prefix.' öffnet.';
            else                                                   $speech = $prefix.' '.$verb.'.';
        }

        /* Visual re-sync */
        $rows = applyLivePercentAndState($rows, $entity2PosRead, $entity2Var);
        $rowsByFloor = [];
        foreach ($rows as $r) {
            $fk = strtoupper((string)($r['floorKey'] ?? ''));
            if ($fk === '') $fk = $unknown;
            $rowsByFloor[$fk][] = $r;
        }
        foreach ($rowsByFloor as $fk => &$list) usort($list, $cmpRows);
        unset($list);
        $presentFloors = array_keys($rowsByFloor);
        $orderedFloors = [];
        foreach ($floorOrder as $fk) if (isset($rowsByFloor[$fk])) $orderedFloors[] = $fk;
        $remaining = array_diff($presentFloors, $orderedFloors);
        sort($remaining, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($remaining as $fk) if ($fk !== $unknown) $orderedFloors[] = $fk;
        if (isset($rowsByFloor[$unknown])) $orderedFloors[] = $unknown;
    } else {
        /* Kein Ziel gefunden → keine Änderung */
    }
}

/* =========================
   APL Doc + Datasource
   ========================= */
$doc = [ 'type' => 'Link', 'src' => 'doc://alexa/apl/documents/Jalousie' ];

$rowsComposed = [];
foreach ($orderedFloors as $fk) {
    $label = $floorLabels[$fk] ?? ($fk === $unknown ? $unknownLabel : $fk);
    $rowsComposed[] = [
        'section'     => $label,
        'sectionH'    => $floorSectionDefault['height'],
        'sectionFont' => $floorSectionDefault['fontSize'],
        'sectionBold' => (bool)$floorSectionDefault['bold'],
        'sectionPadY' => $floorSectionDefault['padY']
    ];
    foreach ($rowsByFloor[$fk] as $r) {
        $out = $r;
        unset($out['floorKey']);
        $rowsComposed[] = $out;
    }
}

$sceneTitleFromKey = static function(string $key, array $sceneTitles): string {
    if (!empty($sceneTitles[$key])) return $sceneTitles[$key];
    $t = str_replace(['_', '-'], ' ', $key);
    return mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
};
$scenesForDs = [];
foreach ($sceneOrderKs as $k) {
    $id = 'szene.'.$k;
    if (isset($scene2Var[$id])) $scenesForDs[] = ['id'=>$id,'title'=>$sceneTitleFromKey($k, $sceneTitles)];
}
$already = array_column($scenesForDs, 'id');
foreach ($scene2Var as $id => $_varId) {
    if (in_array($id, $already, true)) continue;
    $k = (string)mb_substr($id, 6);
    $scenesForDs[] = ['id'=>$id,'title'=>$sceneTitleFromKey($k, $sceneTitles)];
}

$ds = [
    'imageListData' => [
        'title'     => 'Jalousien',
        'subtitle'  => 'Steuern und Szenen',
        'activeTab' => 'Jalousien',
        'rows'      => $rowsComposed,
        'scenes'    => $scenesForDs
    ]
];

/* =========================
   Payload-Limiter
   ========================= */
$shrinkApl = static function(array $doc, array $ds, int $limitBytes = 240000) {
    $calc = static function(array $d, array $s): int {
        return strlen(json_encode(['apl'=>['doc'=>$d,'ds'=>$s,'token'=>'hv-jalousie']], JSON_UNESCAPED_UNICODE));
    };
    $size = $calc($doc,$ds);
    if ($size <= $limitBytes) return [$doc,$ds];

    $ds2 = $ds;
    if (isset($ds2['imageListData']['rows'])) {
        foreach ($ds2['imageListData']['rows'] as &$row) if (is_array($row) && isset($row['icon'])) unset($row['icon']);
        unset($row);
    }
    if (isset($ds2['imageListData']['rows']))   $ds2['imageListData']['rows']   = array_slice($ds2['imageListData']['rows'], 0, 120);
    if (isset($ds2['imageListData']['scenes'])) $ds2['imageListData']['scenes'] = array_slice($ds2['imageListData']['scenes'], 0, 10);
    if (isset($ds2['imageListData']['subtitle'])) unset($ds2['imageListData']['subtitle']);

    if ($calc($doc,$ds2) <= $limitBytes) return [$doc,$ds2];

    $ds3 = ['imageListData'=>['title'=>'Jalousien','activeTab'=>'Jalousien','rows'=>[]]];
    return [$doc,$ds3];
};

if ($aplSupported) {
    list($doc, $ds) = $shrinkApl($doc, $ds);
    $dbgSize = strlen(json_encode(['apl'=>['doc'=>$doc,'ds'=>$ds,'token'=>'hv-jalousie']], JSON_UNESCAPED_UNICODE));
    $logV('APL payload bytes='.$dbgSize);
}

/* =========================
   Antwort
   ========================= */
echo json_encode([
    'speech'      => $speech,
    'reprompt'    => $reprompt,
    'apl'         => $aplSupported ? ['doc' => $doc, 'ds' => $ds, 'token' => 'hv-jalousie'] : null,
    'endSession'  => false
], JSON_UNESCAPED_UNICODE);
