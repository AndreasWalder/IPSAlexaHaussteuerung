<?php
/**
 * ============================================================
 * HEIZUNG RENDERER — RoomsCatalog-only (ohne Fallbacks)
 * ============================================================
 * - Liest ausschließlich aus dem RoomsCatalog
 * - Aktionen (Soll-Temperatur setzen) sind NUR erlaubt, wenn
 *   die zugehörige IPS-Variable (CFG.actions_vars.heizung_stellen) TRUE ist
 * - Keine Fallbacks:
 *   • Kein „erstes Element“ wählen, wenn Ziel unklar
 *   • Bei fehlender Ziel-Variable → klare Sprachausgabe
 *     "Schaltvorgang nicht möglich. Variable nicht vorhanden."
 *
 * ÄNDERUNGSVERLAUF
 * 2025-10-29: Fallbacks entfernt; klare Fehlertexte bei fehlenden Variablen;
 *             Set-Temp nur mit explizitem Ziel (args2/room); Tippfehler behoben.
 * 2025-10-30: Set/Detail nur bei args2 als eindeutige entityId; keine Label/Room-Auflösung.
 */

$in = json_decode($_IPS['payload'] ?? '{}', true) ?: [];
IPS_LogMessage('Alexa', 'AE(in)=' . json_encode($in['ACTIONS_ENABLED'] ?? null, JSON_UNESCAPED_UNICODE));

/* =========================
   Feature-Flags (nur Variablen)
   ========================= */
$CFG = is_array($in['CFG'] ?? null) ? $in['CFG'] : [];

// Nur über CFG.actions_vars → IPS-Variablen lesen (kein Fallback mehr)
$VAR = [];
if (is_array($CFG['actions_vars'] ?? null)) {
    $VAR = $CFG['actions_vars'];
} elseif (is_array($CFG['var']['ActionsEnabled'] ?? null)) {
    $VAR = $CFG['var']['ActionsEnabled'];
}

$readBool = static function($id){
    if (!is_int($id) || $id <= 0) return false;
    return (bool)@GetValue($id);
};

// ACTIONS_ENABLED ausschließlich aus Variablen aufbauen
$ACTIONS_ENABLED = [
    'heizung'  => ['stellen_aendern' => $readBool((int)($VAR['heizung_stellen']  ?? 0))],
];

// Komfort-Flag für diesen Renderer
$CAN_SET_TEMP = (bool)($ACTIONS_ENABLED['heizung']['stellen_aendern'] ?? false);

$LOG_BASIC = isset($in['logBasic'])
    ? (bool)$in['logBasic']
    : (isset($CFG['flags']['log_basic']) ? (bool)$CFG['flags']['log_basic'] : true);

$LOG_VERBOSE = isset($in['logVerbose'])
    ? (bool)$in['logVerbose']
    : (isset($CFG['flags']['log_verbose']) ? (bool)$CFG['flags']['log_verbose'] : true);

$LOG_TAG = 'Alexa';
$logB = static function(string $msg) use ($LOG_BASIC, $LOG_TAG) { if ($LOG_BASIC)  IPS_LogMessage($LOG_TAG, $msg); };
$logV = static function(string $msg) use ($LOG_VERBOSE, $LOG_TAG){ if ($LOG_VERBOSE) IPS_LogMessage($LOG_TAG, $msg); };
$logE = static function(string $msg) use ($LOG_TAG)              { IPS_LogMessage($LOG_TAG, $msg); };

/* =========================
   Eingaben
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
if ($number === null && is_numeric($args1)) { $number = (float)$args1; }

$logV("Heizungsrenderer ENTER");
$logV("Raw Input → a:$action d:$device r:$roomSpoken o:$object n:$number al:$alles args1:$args1 args2:$args2");

/* =========================
   RoomsCatalog
   ========================= */
$CATALOG = is_array($in['roomsCatalog'] ?? null) ? $in['roomsCatalog'] : [];
if (empty($CATALOG)) {
    $logE('roomsCatalog fehlt in Payload.');
    echo json_encode(['speech'=>'Konfiguration fehlt.','reprompt'=>'','apl'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return;
}

/* ===== Logging-Helpers ===== */
$RID = strtoupper(substr(hash('crc32b', microtime(true) . mt_rand()), 0, 8));
$JFLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$fmt = static function ($v) use ($JFLAGS) {
    if (is_string($v) || is_numeric($v) || is_bool($v) || $v === null) return json_encode($v, $JFLAGS);
    return json_encode($v, $JFLAGS);
};
$logKV = static function (string $title, array $data) use ($logV, $RID, $JFLAGS) {
    $logV("[$RID] $title " . json_encode($data, $JFLAGS));
};
$logB("[$RID] HeizungRenderer ENTER");
$logKV('INPUT', [
    'aplSupported' => (bool)$aplSupported,
    'action'       => $action,
    'device'       => $device,
    'room'         => $roomSpoken,
    'object'       => $object,
    'alles'        => $alles,
    'number'       => $number
]);
$logKV('ACTIONS_ENABLED', $ACTIONS_ENABLED);
$logV("[$RID] CAN_SET_TEMP=" . ($CAN_SET_TEMP ? '1' : '0'));
$logV("[$RID] APL args1=" . $fmt($args1));
$logV("[$RID] APL args2=" . $fmt($args2));

/* =========================
   Config für Icon-Hooks
   ========================= */
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
if ($baseUrl === '' || $token === '') {
    $logB('BaseUrl oder Token fehlt – Icons aus Dateinamen können nicht aufgelöst werden.');
}
$iconUrl = static function(string $file, string $baseUrl, string $token): string {
    $path = IPS_GetKernelDir().'user'.DIRECTORY_SEPARATOR.'icons'.DIRECTORY_SEPARATOR.$file;
    $v = @filemtime($path) ?: 1;
    return rtrim($baseUrl,'/').'/hook/icons?f='.rawurlencode($file).'&token='.rawurlencode($token).'&v='.$v;
};
$resolveIcon = static function (?string $raw) use ($baseUrl,$token,$iconUrl,$logB): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (preg_match('#^https?://#i', $raw)) return $raw;
    if ($baseUrl !== '' && $token !== '') return $iconUrl($raw, $baseUrl, $token);
    $logB("Icon-Dateiname '{$raw}' kann ohne BaseUrl/Token nicht aufgelöst werden.");
    return null;
};
$defaultHeaterIcon = null;
if (isset($CATALOG['global']['heizung']['icon'])) {
    $defaultHeaterIcon = $resolveIcon((string)$CATALOG['global']['heizung']['icon']);
}

/* =========================
   Helpers
   ========================= */
if (!function_exists('h_norm')) {
    function h_norm(string $s): string {
        $t = mb_strtolower(trim($s), 'UTF-8');
        return str_replace(['oe','ae','ue','ss'], ['ö','ä','ü','ß'], $t);
    }
}
if (!function_exists('readVal')) {
    function readVal($varId) {
        if (!is_int($varId) || $varId <= 0) { return null; }
        return @GetValue($varId);
    }
}
if (!function_exists('readNumber')) {
    function readNumber($varId): ?float {
        $v = readVal($varId);
        if (is_numeric($v)) return (float)$v;
        return null;
    }
}
if (!function_exists('formatValvePercent')) {
    function formatValvePercent(?float $p): string {
        if ($p === null) return '';
        $s = rtrim((string)$p, "%");
        return $s . '%';
    }
}
if (!function_exists('readFormatted')) {
    function readFormatted($varId): string {
        if (!is_int($varId) || $varId <= 0) return '';
        return (string)@GetValueFormatted($varId);
    }
}

/* =========================
   Floors aus Catalog
   ========================= */
$GF = is_array($CATALOG['global']['floors'] ?? null) ? $CATALOG['global']['floors'] : [];
$floorOrder = [];
if (isset($GF['order']) && is_array($GF['order'])) {
    foreach ($GF['order'] as $fk) { $floorOrder[] = strtoupper((string)$fk); }
}
$floorLabels = [];
if (isset($GF['labels']) && is_array($GF['labels'])) {
    foreach ($GF['labels'] as $fk => $lab) { $floorLabels[strtoupper((string)$fk)] = (string)$lab; }
}
$floorSectionStyle = [
    'height'   => '4.4vw',
    'fontSize' => '1.9vw',
    'bold'     => false,
    'padY'     => '0.8vw'
];
if (isset($GF['section']) && is_array($GF['section'])) {
    $floorSectionStyle = array_merge($floorSectionStyle, $GF['section']);
}
$unknownFloorKey   = 'Z';
$unknownFloorLabel = (string)($GF['unknownLabel'] ?? 'Weitere');

/* =========================
   Heizung-Rows aus Catalog
   ========================= */
$rowsRaw = [];
$roomIndexByKey = [];

foreach ($CATALOG as $roomKey => $def) {
    if ($roomKey === 'global') continue;
    if (!isset($def['domains']['heizung']) || !is_array($def['domains']['heizung'])) continue;

    $roomDisplay = (string)($def['display'] ?? ucfirst($roomKey));
    $roomFloor   = strtoupper((string)($def['floor'] ?? ''));
    $roomIndexByKey[$roomKey] = $roomDisplay;

    $isFirstCircuit = true;

    foreach ($def['domains']['heizung'] as $circuitId => $meta) {
        if (!is_array($meta)) continue;

        foreach (['ist','stellung','eingestellt','soll'] as $req) {
            if (!isset($meta[$req]) || !is_int($meta[$req]) || $meta[$req] <= 0) {
                $logB("Raum '$roomKey' Kreis '$circuitId': fehlende/ungültige ID '$req'. Überspringe.");
                continue 2;
            }
        }

        $istId         = (int)$meta['ist'];
        $stellungId    = (int)$meta['stellung'];
        $eingestelltId = (int)$meta['eingestellt'];
        $sollId        = (int)$meta['soll'];

        $display =
            isset($meta['title']) ? (string)$meta['title']
          : (isset($meta['display']) ? (string)$meta['display']
          : ($isFirstCircuit ? $roomDisplay : ($roomDisplay.'.'.ucfirst((string)$circuitId))));

        $iconFinal = $resolveIcon((string)($meta['icon'] ?? ''));
        if (!$iconFinal && $defaultHeaterIcon) $iconFinal = $defaultHeaterIcon;

        $entityId  = (string)($meta['entityId'] ?? ('heizung.'.$display));
        $order     = (int)   ($meta['order']    ?? 9999);
        $floorKeyU = strtoupper((string)($meta['floor'] ?? ''));
        $floorKey  = $floorKeyU !== '' ? $floorKeyU : ($roomFloor !== '' ? $roomFloor : $unknownFloorKey);

        $istVal         = readFormatted($istId);
        $stellungPct    = readNumber($stellungId);
        $eingestelltVal = readFormatted($eingestelltId);
        $sollVal        = readFormatted($sollId);

        $rowsRaw[] = [
            'id'        => (string)$circuitId,
            'circuitId' => (string)$circuitId,
            'roomKey'   => (string)$roomKey,
            'room'      => $display,
            'icon'      => $iconFinal ?: '',
            'entityId'  => $entityId,
            'order'     => $order,
            'floorKey'  => $floorKey,

            'statOn'      => ($stellungPct !== null && $stellungPct > 0) ? 1 : 0,
            'valve'       => formatValvePercent($stellungPct),
            'valveNum'    => $stellungPct,
            'valveText'   => formatValvePercent($stellungPct),
            'ist'         => $istVal,
            'eingestellt' => $eingestelltVal,
            'soll'        => $sollVal,

            'istVarId'         => $istId,
            'stellungVarId'    => $stellungId,
            'eingestelltVarId' => $eingestelltId,
            'sollVarId'        => $sollId,
        ];

        $isFirstCircuit = false;
    }
}

/* =========================
   Quick-Reply: Ist-Temperatur (unverändert)
   ========================= */
$devNorm = h_norm($device);
$actNorm = h_norm($action);
$askIst  = ($number === null)
        && $roomSpoken !== ''
        && (
             $devNorm === 'temperatur'
          || $devNorm === 'isttemperatur'
          || $devNorm === 'ist'
          || $actNorm === 'temperatur'
          || $actNorm === 'isttemperatur'
          || $actNorm === 'ist'
        );

$pickFirstRowByRoomKey = static function(string $roomKey) use ($rowsRaw): ?array {
    $cands = array_values(array_filter($rowsRaw, static function($r) use ($roomKey){ return $r['roomKey'] === $roomKey; }));
    if (empty($cands)) return null;
    usort($cands, static function($a,$b){
        $oa = (int)($a['order'] ?? 9999);
        $ob = (int)($b['order'] ?? 9999);
        if ($oa !== $ob) return $oa <=> $ob;
        return strcmp((string)($a['room'] ?? ''), (string)($b['room'] ?? ''));
    });
    return $cands[0];
};

if ($askIst) {
    $resolveRoomKey = function(string $spoken) use ($CATALOG): ?string {
        $n = h_norm($spoken);
        foreach ($CATALOG as $key => $def) {
            if ($key === 'global') continue;
            if (isset($def['display']) && h_norm((string)$def['display']) === $n) return $key;
            if (h_norm($key) === $n) return $key;
            foreach ((array)($def['synonyms'] ?? []) as $syn) {
                if (h_norm((string)$syn) === $n) return $key;
            }
        }
        return null;
    };
    $roomKey = $resolveRoomKey($roomSpoken);

    if ($roomKey && isset($CATALOG[$roomKey]['domains']['heizung']) && is_array($CATALOG[$roomKey]['domains']['heizung'])) {
        $firstMeta = null;
        $bestOrder = PHP_INT_MAX;
        foreach ($CATALOG[$roomKey]['domains']['heizung'] as $meta) {
            if (!is_array($meta)) continue;
            $ord = isset($meta['order']) ? (int)$meta['order'] : 9999;
            if ($ord < $bestOrder) { $bestOrder = $ord; $firstMeta = $meta; }
        }
        if ($firstMeta === null) {
            foreach ($CATALOG[$roomKey]['domains']['heizung'] as $meta) { if (is_array($meta)) { $firstMeta = $meta; break; } }
        }

        if (is_array($firstMeta) && isset($firstMeta['ist']) && is_int($firstMeta['ist']) && $firstMeta['ist'] > 0) {
            $istStr  = readFormatted((int)$firstMeta['ist']);
            $roomLbl = (string)($CATALOG[$roomKey]['display'] ?? ucfirst($roomKey));
            $speech  = 'Die Temperatur in '.$roomLbl.' beträgt '.$istStr.'.';

            $rowApl = $pickFirstRowByRoomKey($roomKey);
            if ($aplSupported && $rowApl) {
                $doc = ['type'=>'Link','src'=>'doc://alexa/apl/documents/Temperatur'];
                $ds  = (static function(array $row) use ($CAN_SET_TEMP){
                    return [
                        'heatingTableData' => [
                            'title'    => 'Heizung',
                            'subtitle' => (string)$row['room'],
                            'rows'     => [[
                                'id'            => 'sel_room',
                                'icon'          => $row['icon'],
                                'room'          => $row['room'],
                                'entityId'      => $row['entityId'],
                                'statOn'        => $row['statOn'],
                                'valve'         => $row['valve'],
                                'valveNum'      => $row['valveNum'],
                                'valveText'     => $row['valveText'],
                                'ist'           => $row['ist'],
                                'eingestellt'   => $row['eingestellt'],
                                'soll'          => $row['soll'],
                            ]],
                        ],
                        'ui' => [
                            'showTempPicker' => $CAN_SET_TEMP,
                            'focus'          => 'ist',
                            'highlight'      => 'ist',
                            'scrollToId'     => 'sel_room'
                        ],
                        'tempPicker' => ['title'=>'Temperatur wählen','value'=>21,'min'=>15,'max'=>30,'step'=>0.5],
                    ];
                })($rowApl);

                echo json_encode([
                    'speech'     => $speech,
                    'reprompt'   => '',
                    'apl'        => ['doc'=>$doc, 'ds'=>$ds, 'token'=>'hv-heizung-detail'],
                    'endSession' => false
                ], $JFLAGS);
                return;
            }

            echo json_encode([
                'speech'     => $speech,
                'reprompt'   => '',
                'apl'        => null,
                'endSession' => false
            ], $JFLAGS);
            return;
        }
    }
}

/* =========================
   Gruppieren & Sortieren
   ========================= */
$rowsByFloor = [];
foreach ($rowsRaw as $r) {
    $fk = strtoupper((string)($r['floorKey'] ?? ''));
    if ($fk === '') $fk = $unknownFloorKey;
    $rowsByFloor[$fk][] = $r;
}
$cmpRows = static function(array $a, array $b): int {
    $oa = (int)($a['order'] ?? 9999);
    $ob = (int)($b['order'] ?? 9999);
    if ($oa !== $ob) return $oa <=> $ob;
    return strcmp((string)($a['room'] ?? ''), (string)($b['room'] ?? ''));
};
foreach ($rowsByFloor as $fk => &$list) { usort($list, $cmpRows); }
unset($list);

$presentFloors = array_keys($rowsByFloor);
$orderedFloors = [];
foreach ($floorOrder as $fk) { if (isset($rowsByFloor[$fk])) $orderedFloors[] = $fk; }
$remaining = array_diff($presentFloors, $orderedFloors);
sort($remaining, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($remaining as $fk) { if ($fk !== $unknownFloorKey) $orderedFloors[] = $fk; }
if (isset($rowsByFloor[$unknownFloorKey])) $orderedFloors[] = $unknownFloorKey;

/* =========================
   Row-Komposition APL
   ========================= */
$rowsComposed = [];
foreach ($orderedFloors as $fk) {
    $label = $floorLabels[$fk] ?? ($fk === $unknownFloorKey ? $unknownFloorLabel : $fk);
    $rowsComposed[] = [
        'section'     => $label,
        'sectionH'    => $floorSectionStyle['height'],
        'sectionFont' => $floorSectionStyle['fontSize'],
        'sectionBold' => (bool)$floorSectionStyle['bold'],
        'sectionPadY' => $floorSectionStyle['padY']
    ];
    foreach ($rowsByFloor[$fk] as $r) {
        $out = $r;
        unset($out['floorKey']);
        $rowsComposed[] = $out;
    }
}

/* =========================
   Detail-Helfer
   ========================= */
$buildDetailDs = static function(array $row, array $uiExtras = []) use ($CAN_SET_TEMP) {
    return [
        'heatingTableData' => [
            'title'    => 'Heizung',
            'subtitle' => (string)$row['room'],
            'rows'     => [[
                'id'            => 'sel_room',
                'icon'          => $row['icon'],
                'room'          => $row['room'],
                'entityId'      => $row['entityId'],
                'statOn'        => $row['statOn'],
                'valve'         => $row['valve'],
                'valveNum'      => $row['valveNum'],
                'valveText'     => $row['valveText'],
                'ist'           => $row['ist'],
                'eingestellt'   => $row['eingestellt'],
                'soll'          => $row['soll'],
            ]],
        ],
        'ui' => array_merge(['showTempPicker' => $CAN_SET_TEMP], $uiExtras),
        'tempPicker' => ['title'=>'Temperatur wählen','value'=>21,'min'=>15,'max'=>30,'step'=>0.5],
    ];
};

$findRowByRoomKey = static function(string $roomKey) use ($rowsRaw): ?array {
    foreach ($rowsRaw as $row) if (($row['roomKey'] ?? '') === $roomKey) return $row;
    return null;
};
$findRowByLabel = static function(string $label) use ($rowsRaw): ?array {
    $needle = h_norm($label);
    foreach ($rowsRaw as $row) {
        if (h_norm((string)($row['room'] ?? '')) === $needle) return $row;
    }
    return null;
};
$findRowByEntityId = static function(string $entityId) use ($rowsRaw): ?array {
    $n = mb_strtolower(trim($entityId), 'UTF-8');
    $hit = null; $count = 0;
    foreach ($rowsRaw as $row) {
        if (mb_strtolower((string)($row['entityId'] ?? ''), 'UTF-8') === $n) {
            $hit = $row; $count++;
        }
    }
    return $count === 1 ? $hit : null;
};

/* =========================
   Zahl gesetzt → Aktion + Detail (nur args2=entityId)
   ========================= */

/* === Resolver: Raum finden & ersten Heizkreis wählen (case-insensitiv) === */
$resolveRoomKey = static function(string $spoken) use ($CATALOG): ?string {
    $n = h_norm($spoken);
    foreach ($CATALOG as $key => $def) {
        if ($key === 'global') continue;
        if (h_norm($key) === $n) return $key;
        if (isset($def['display']) && h_norm((string)$def['display']) === $n) return $key;
        foreach ((array)($def['synonyms'] ?? []) as $syn) {
            if (h_norm((string)$syn) === $n) return $key;
        }
    }
    return null;
};

$pickFirstRowByRoomKey = static function(string $roomKey) use ($rowsRaw): ?array {
    $cands = array_values(array_filter($rowsRaw, static function($r) use ($roomKey){
        return ($r['roomKey'] ?? '') === $roomKey;
    }));
    if (empty($cands)) return null;
    usort($cands, static function($a,$b){
        $oa = (int)($a['order'] ?? 9999);
        $ob = (int)($b['order'] ?? 9999);
        return $oa <=> $ob ?: strcmp((string)($a['room'] ?? ''),(string)($b['room'] ?? ''));
    });
    return $cands[0];
};

if ($number !== null) {
    $targetRow = null;

    if (is_string($args2) && trim($args2) !== '' && strpos($args2, '.') !== false) {
        $targetRow = $findRowByEntityId($args2);
        $logV("[$RID] Set-Temp via args2 entityId=" . trim((string)$args2));
    }

    // --- Neu: Ziel über Raum (falls args2 leer) ---
    if (!$targetRow && $roomSpoken !== '') {
        $roomKey = $resolveRoomKey($roomSpoken);
        if ($roomKey) {
            $targetRow = $pickFirstRowByRoomKey($roomKey);
            $logV("[$RID] Set-Temp via room='$roomSpoken' → roomKey='$roomKey'");
        }
    }

    // Kein Label-/Room-Fallback
    if (!$targetRow) {
        echo json_encode(['speech'=>'Schaltvorgang nicht möglich. Variable nicht vorhanden.','reprompt'=>'','apl'=>null], $JFLAGS);
        return;
    }

    $valClamped = max(5, min(35, (float)$number));
    $speech   = 'Habe die Temperatur von '.$targetRow['room'].' auf '.$valClamped.' Grad geändert!';
    $reprompt = 'Soll ich noch etwas ändern?';

    if (!empty($targetRow['eingestelltVarId']) && is_int($targetRow['eingestelltVarId']) && $targetRow['eingestelltVarId'] > 0) {
        $eingestelltVarId = (int)$targetRow['eingestelltVarId'];

        SetValueString($V['lastVariableDevice'], 'Heizung');
        SetValueString($V['lastVariableId'], $eingestelltVarId);
        SetValueString($V['lastVariableAction'], $targetRow['room']);
        SetValueString($V['lastVariableValue'], $valClamped);

        $logKV('TARGET', [
            'room'             => $targetRow['room'] ?? null,
            'entityId'         => $targetRow['entityId'] ?? null,
            'eingestelltVarId' => $eingestelltVarId,
            'valClamped'       => $valClamped,
            'actionsEnabled'   => $ACTIONS_ENABLED
        ]);
        $logV("[$RID] INTENT write Soll=" . $valClamped . " -> VarId " . $eingestelltVarId);

        if ($CAN_SET_TEMP) {
            if (function_exists('EIB_Value')) {
                RequestAction($eingestelltVarId, $valClamped);
            } else {
                RequestAction($eingestelltVarId, $valClamped);
            }
            IPS_Sleep(200);
            $logB("[$RID] ACTION Heizungs-Soll gesetzt");
        } else {
            $logB("[$RID] ACTIONS_DISABLED(heizung.stellen_aendern): skip write");
            $speech   = 'Temperatur ändern ist derzeit nicht freigegeben. Ich habe nichts umgestellt.';
            $reprompt = '';
        }

        if ($aplSupported) {
            $rowRefreshed = $targetRow;
            $rowRefreshed['soll']        = readFormatted($rowRefreshed['sollVarId']);
            $rowRefreshed['eingestellt'] = readFormatted($rowRefreshed['eingestelltVarId']);
            $doc = ['type'=>'Link','src'=>'doc://alexa/apl/documents/Temperatur'];
            $ds  = $buildDetailDs($rowRefreshed, [
                'focus'      => 'eingestellt',
                'highlight'  => 'eingestellt',
                'scrollToId' => 'sel_room'
            ]);
            echo json_encode([
                'speech'   => $speech,
                'reprompt' => $reprompt,
                'apl'      => ['doc'=>$doc, 'ds'=>$ds, 'token'=>'hv-heizung-detail'],
            ], $JFLAGS);
            return;
        }
    } else {
        $logE("[$RID] Kein Ziel für EINGESTELLT gefunden.");
        echo json_encode(['speech'=>'Schaltvorgang nicht möglich. Variable nicht vorhanden.','reprompt'=>'','apl'=>null], $JFLAGS);
        return;
    }

    echo json_encode(['speech'=>$speech,'reprompt'=>$reprompt,'apl'=>null], $JFLAGS);
    return;
}

/* =========================
   APL-Row gewählt (args2 nur entityId)
   ========================= */
if ($args2 !== null && is_string($args2) && trim($args2) !== '') {
    $rowSel = null;
    if (strpos($args2, '.') !== false) {
        $rowSel = $findRowByEntityId($args2);
        $logV("[$RID] Detail via args2 entityId=" . trim((string)$args2));
    }
    // Kein Label-Fallback
    if ($aplSupported && $rowSel) {
        $doc = ['type'=>'Link','src'=>'doc://alexa/apl/documents/Temperatur'];
        $ds  = $buildDetailDs($rowSel);
        echo json_encode([
            'speech'   => '',
            'reprompt' => '',
            'apl'      => ['doc'=>$doc, 'ds'=>$ds, 'token'=>'hv-heizung-detail'],
        ], $JFLAGS);
        return;
    }
}

/* =========================
   Hauptliste
   ========================= */
$doc = ['type'=>'Link','src'=>'doc://alexa/apl/documents/Temperatur'];
$datasource = [
    'heatingTableData' => [
        'title'    => 'Heizung',
        'subtitle' => 'Übersicht & schnelle Aktionen',
        'rows'     => $rowsComposed,
    ],
    'ui' => ['showTempPicker' => $CAN_SET_TEMP],
    'tempPicker' => ['title'=>'Temperatur wählen','value'=>21,'min'=>15,'max'=>30,'step'=>0.5],
];

echo json_encode([
    'speech'   => '',
    'reprompt' => '',
    'apl'      => ['doc'=>$doc, 'ds'=>$datasource, 'token'=>'hv-heizung'],
], $JFLAGS);

$logB("[$RID] HeizungRenderer EXIT");
