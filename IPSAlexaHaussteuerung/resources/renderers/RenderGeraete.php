<?php
/**
 * ============================================================
 * GERÄTE RENDERER — RoomsCatalog-only (Tabs je Raum → Kategorie)
 * ============================================================
 *
 * Änderungsverlauf (Changelog)
 * 2025-11-23: v14 — RouteKey in DS + dynamische Event-Normalisierung
 *             • deviceTableData enthält jetzt zusätzlich 'route' (z. B. "geraete", "sicherheit", "bewaesserung")
 *             • gr_normalize_event akzeptiert jetzt generisch "<domain>.tab", "<domain>.(t|toggle).<id>", "<domain>.set*.<id>"
 *             • Datum und Zeitspalten Filter über alle Zeilen dynamisch ergänzt
 * 2025-11-05: v13.2 — Vollständiges APL-DS Logging + optionaler Dump
 *             • Komplettes Datasource-JSON ins Log (PRETTY + kompakt)
 *             • Optionaler Dump in String-Variable (CFG.flags.dump_ds_var)
 *             • Rubriken/Sorting/Link-Namen wie v13 beibehalten
 * 2025-11-05: v13 — Positions-Sortierung + Dummy-Rubriken
 *             • Sortierung pro Ebene: zuerst ObjectPosition, dann alphabetisch
 *             • „Dummy Modul“-Instanzen werden als Rubrik-Zeilen ausgegeben
 *               (isSection=true) und innerhalb der Rubrik neu sortiert
 *             • Link-Namen werden als Anzeigename verwendet, Wert von Ziel-Variable
 * 2025-11-04: v12 — String-Enums auch über Variablenprofile
 *             • Wenn ObjectInfo (enum/enumOpts/enumMap/enumProfile) fehlt,
 *               werden bei String-Variablen die Associations des
 *               Variablenprofils als Enum verwendet.
 *             • gr_resolve_set_value mappt Profil-Labels → Value (auch String).
 * 2025-11-04: v11 — Profil-Cache + striktes Action-Handling
 *             • Globaler Profil-Dump via IPS_GetVariableProfileList() (Cache pro Lauf)
 *             • canSetNumber folgt der Action; String-Enums bleiben mit Fallback setzbar
 *             • Kleinere Logs (enumRows) für Debugging
 * 2025-11-04: v10 — Dynamische Enums je Variable
 *             • enumOpts werden automatisch ermittelt:
 *               - Integer/Float: aus VariableProfile-Associations
 *               - String: aus ObjectInfo-JSON (enum / enumOpts / enumMap / enumProfile)
 *             • gr_resolve_set_value versteht jetzt ObjectInfo-Enum-Mapping
 *             • canSetNumber/canToggle beachten weiterhin vorhandene Action
 * 2025-11-02: v9 — Tab-Voice-Fallback + setEnum
 * 2025-11-02: v8 — Tab-Synonyme & Name-Matching
 * 2025-11-02: v7 — Tab-Persistenz + „Steuern“-Enum
 * 2025-11-02: v6 — Enum/Dauer-Resolver + Warning-Capture
 * 2025-11-02: v5 — APL "dot-args" Normalisierung
 * 2025-10-31: v4/v3/v2/v1 — erste Fassungen
 */

declare(strict_types=1);

const GR_JF        = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
const GR_DELAY_MS  = 50;
const GR_MAX_DEPTH = 8;

/* =========================
   Input
   ========================= */
$in = json_decode($_IPS['payload'] ?? '{}', true) ?: [];

$aplSupported = (bool)($in['aplSupported'] ?? false);
$ROOMS        = (array)($in['roomsCatalog'] ?? []);
$roomSpoken   = (string)($in['room'] ?? '');
$roomMap      = (array)($in['roomMap'] ?? []);
$args1_raw    = (string)($in['args1'] ?? '');
$args2_raw    = (string)($in['args2'] ?? '');
$numberRaw    = $in['number'] ?? ($in['value'] ?? null);

/* v9: zusätzliche Voice-Kandidaten für Tab-Auswahl */
$voice_action = (string)($in['action'] ?? '');
$voice_device = (string)($in['device'] ?? '');
$voice_alles  = (string)($in['alles']  ?? '');
$voice_object = (string)($in['object'] ?? '');
$voice_szene  = (string)($in['szene'] ?? '');

/* =========================
   CFG / ACTION FLAGS / LOGGING
   ========================= */

$CFG = is_array($in['CFG'] ?? null) ? $in['CFG'] : [];
$routeKey = strtolower((string)($in['route'] ?? ''));
if ($routeKey === '') { $routeKey = 'geraete'; }
$rendererCfg = gr_renderer_config($routeKey, $CFG);
$rendererRouteKey = (string)($rendererCfg['route'] ?? 'geraete');
if ($rendererRouteKey === '') { $rendererRouteKey = 'geraete'; }
// Room-Scope: nur klassische Geräte/Bewässerung sind raumbezogen
$respectRoomFilter = in_array($rendererRouteKey, ['geraete', 'bewaesserung', 'szene'], true);
$rendererToggleVarKey = (string)($rendererCfg['toggleVarKey'] ?? ($rendererRouteKey . '_toggle'));
if ($rendererToggleVarKey === '') { $rendererToggleVarKey = $rendererRouteKey . '_toggle'; }
$rendererRoomDomain = (string)($rendererCfg['roomDomain'] ?? 'devices');
if ($rendererRoomDomain === '') { $rendererRoomDomain = 'devices'; }
$rendererSpeechEmpty = (string)($rendererCfg['speechEmpty'] ?? 'Keine Geräte im RoomsCatalog konfiguriert.');
$rendererDefaultTitle = (string)($rendererCfg['title'] ?? 'Geräte');
if ($rendererDefaultTitle === '') { $rendererDefaultTitle = 'Geräte'; }
$rendererSubtitle = (string)($rendererCfg['subtitle'] ?? 'Steckdosen & mehr');
if ($rendererSubtitle === '') { $rendererSubtitle = 'Steckdosen & mehr'; }
$rendererLogName = (string)($rendererCfg['logName'] ?? 'Geraete');
if ($rendererLogName === '') { $rendererLogName = 'Geraete'; }
$rendererAplDoc = (string)($rendererCfg['aplDoc'] ?? 'doc://alexa/apl/documents/Geraete');
if ($rendererAplDoc === '') { $rendererAplDoc = 'doc://alexa/apl/documents/Geraete'; }
$rendererAplToken = (string)($rendererCfg['aplToken'] ?? 'hv-geraete');
if ($rendererAplToken === '') { $rendererAplToken = 'hv-geraete'; }

// ActionsEnabled-IDs (kompatibel zu alter/ neuer Struktur)
$VAR = [];
if (is_array(($CFG['actions_vars'] ?? null))) {
    $VAR = $CFG['actions_vars'];
} elseif (is_array(($CFG['var']['ActionsEnabled'] ?? null))) {
    $VAR = $CFG['var']['ActionsEnabled'];
}

// Profile optional vorladen (Cache)
$PRELOAD_PROFILES = isset($CFG['flags']['preload_profiles']) ? (bool)$CFG['flags']['preload_profiles'] : true;
if ($PRELOAD_PROFILES) { gr_get_profile_associations(''); }

$readBool = static function($varId, bool $default=false): bool {
    if (!is_int($varId) || $varId <= 0) return $default;
    return (bool)@GetValue($varId);
};

// ACTIONS_ENABLED bevorzugt aus Payload übernehmen, sonst aus Konfiguration ableiten
$ACTIONS_ENABLED_IN = is_array($in['ACTIONS_ENABLED'] ?? null) ? $in['ACTIONS_ENABLED'] : [];
if ($ACTIONS_ENABLED_IN) {
    $ACTIONS_ENABLED = $ACTIONS_ENABLED_IN;
} else {
    $ACTIONS_ENABLED = [
        $rendererRouteKey => [
            'toggle' => $readBool((int)($VAR[$rendererToggleVarKey] ?? 0), false)
        ],
    ];
}

if (!isset($ACTIONS_ENABLED[$rendererRouteKey]) || !is_array($ACTIONS_ENABLED[$rendererRouteKey])) {
    $ACTIONS_ENABLED[$rendererRouteKey] = [
        'toggle' => $readBool((int)($VAR[$rendererToggleVarKey] ?? 0), false)
    ];
} elseif (!array_key_exists('toggle', $ACTIONS_ENABLED[$rendererRouteKey])) {
    $ACTIONS_ENABLED[$rendererRouteKey]['toggle'] = $readBool((int)($VAR[$rendererToggleVarKey] ?? 0), false);
}

$CAN_TOGGLE  = (bool)($ACTIONS_ENABLED[$rendererRouteKey]['toggle'] ?? false);

$LOG_BASIC   = isset($in['logBasic'])   ? (bool)$in['logBasic']   : (isset($CFG['flags']['log_basic'])   ? (bool)$CFG['flags']['log_basic']   : true);
$LOG_VERBOSE = isset($in['logVerbose']) ? (bool)$in['logVerbose'] : (isset($CFG['flags']['log_verbose']) ? (bool)$CFG['flags']['log_verbose'] : true);
$LOG_TAG     = 'Alexa';
$LOG_APL     = isset($CFG['flags']['log_apl_ds']) ? (bool)$CFG['flags']['log_apl_ds'] : true; // neu: DS komplett loggen
$DUMP_VAR    = (int)($CFG['flags']['dump_ds_var'] ?? 0); // optional: DS in String-Variable dumpen

$logB = static function(string $msg) use ($LOG_BASIC, $LOG_TAG)  { if ($LOG_BASIC)  IPS_LogMessage($LOG_TAG, $msg); };
$logV = static function(string $msg) use ($LOG_VERBOSE, $LOG_TAG){ if ($LOG_VERBOSE) IPS_LogMessage($LOG_TAG, $msg); };

$RID = strtoupper(substr(hash('crc32b', microtime(true) . mt_rand()), 0, 8));
$logV("[$RID][{$rendererLogName}] ENTER");
$logV("[$RID][{$rendererLogName}] INPUT ".json_encode([
    'aplSupported'=>$aplSupported,'room'=>$roomSpoken,'args1'=>$args1_raw,'args2'=>$args2_raw,
    'numberRaw'=>$numberRaw,'AE'=>$ACTIONS_ENABLED,
    'voice'=>['action'=>$voice_action,'device'=>$voice_device,'alles'=>$voice_alles,'object'=>$voice_object,'szene'=>$voice_szene]
], GR_JF));

/* =========================
   Event-Normalisierung (dot-args)
   ========================= */
$ev = gr_normalize_event($args1_raw, $args2_raw, $numberRaw);
if (!is_array($ev)) {
    $ev = ['action'=>'','varId'=>0,'tabId'=>'','value'=>null,'toggleTo'=>null,'rawText'=>''];
}
$action    = (string)$ev['action'];
$varId     = (int)$ev['varId'];
$tabIdArg  = (string)$ev['tabId'];
$numberIn  = $ev['value'];
$toggleTo  = $ev['toggleTo'];
$rawText   = (string)$ev['rawText'];

$logV("[$RID][{$rendererLogName}] NORMALIZED ".json_encode($ev, GR_JF));

/* =========================
   Room-Resolve (optional)
   ========================= */
$roomKeyFilter = gr_resolveRoomKey($roomSpoken, $roomMap, $ROOMS);
$logV("[$RID][{$rendererLogName}] domainMap.beforeCollect=" . json_encode(
    gr_debug_domain_map($ROOMS, $rendererRoomDomain),
    GR_JF
));
/* =========================
   Tabs & aktive Kategorie
   ========================= */
// Für Spezial-Domains (bienen, sicherheit, …) wird der Room-Filter ignoriert
$tabs = gr_collectRoomDeviceTabs($ROOMS, $roomKeyFilter, $rendererRoomDomain, $respectRoomFilter);

$logV("[$RID][{$rendererLogName}] roomSummary=" . json_encode(gr_rooms_domain_summary($ROOMS, $roomKeyFilter), GR_JF));


// Fallback: Wenn keine Tabs gefunden wurden, versuche die Domäne über den
// RoomsCatalog (Tab-Titel → slug) herzuleiten und neu zu sammeln. Dadurch
// greifen dynamische Domains wie "Bienen" (been) oder "Sicherheit" (save)
// auch dann, wenn die Renderer-Konfiguration keine oder eine andere Domäne
// liefert.
// Fallback: Wenn keine Tabs gefunden wurden, versuche die Domäne über den
// RoomsCatalog (Tab-Titel → slug) herzuleiten und neu zu sammeln. Dadurch
// greifen dynamische Domains wie "Bienen" (been) oder "Sicherheit" (save)
// auch dann, wenn die Renderer-Konfiguration keine oder eine andere Domäne
// liefert.
if (!$tabs) {
    $logV("[$RID][{$rendererLogName}] domainMap.noTabs=" . json_encode(
        gr_debug_domain_map($ROOMS, $rendererRoomDomain),
        GR_JF
    ));

    $logV("[$RID][{$rendererLogName}] noTabs domain={$rendererRoomDomain} routeKey={$rendererRouteKey} tryingInference");
    $guessedDomain = gr_infer_room_domain_from_rooms($rendererRouteKey, $ROOMS);
    if ($guessedDomain !== '' && $guessedDomain !== $rendererRoomDomain) {
        $rendererRoomDomain = $guessedDomain;
        $tabs = gr_collectRoomDeviceTabs($ROOMS, $roomKeyFilter, $rendererRoomDomain);
        $logV("[$RID][{$rendererLogName}] inferredDomain={$rendererRoomDomain} tabsAfterInference=".count($tabs));
    } else {
        $logV("[$RID][{$rendererLogName}] inferenceUnchanged domain={$rendererRoomDomain} tabsAfterInference=0");
    }
}


$logV("[$RID][{$rendererLogName}] tabs=".count($tabs)." domain={$rendererRoomDomain}");

if (!$tabs) {
    echo json_encode([
        'speech'     => $rendererSpeechEmpty,
        'reprompt'   => '',
        'apl'        => null,
        'endSession' => false
    ], GR_JF);
    return;
}

/* =========================
   Name-basierte Var-Resolve (Voice)
   ========================= */
if ($varId <= 0) {
    $voiceCandidates = gr_collect_voice_candidates([
        $args2_raw,
        $rawText,
        $args1_raw,
        $voice_szene,
        $voice_device,
        $voice_object,
        $voice_action,
        $voice_alles
    ]);
    foreach ($voiceCandidates as $candidate) {
        $parsed = gr_extract_name_and_toggle($candidate);
        $nameCandidate = $parsed['name'];
        if ($nameCandidate === '') {
            continue;
        }
        $match = gr_find_var_by_name_from_tabs($tabs, $nameCandidate, $CAN_TOGGLE);
        if ($match !== null) {
            $varId = (int)$match['varId'];
            $slotToggle = gr_toggle_from_action_word($voice_action);
            if ($parsed['toggleTo'] !== null || $slotToggle !== null) {
                $action = $action !== '' ? $action : 'toggle';
                $toggleTo = $parsed['toggleTo'] ?? $slotToggle;
            }
            $logV("[$RID][{$rendererLogName}] nameMatch name={$nameCandidate} varId={$varId} toggleTo=" . ($toggleTo ?? ''));
            break;
        }
    }
}

/* v9: aktiven Tab bestimmen */
if ($action === 'tab' && $tabIdArg !== '') {
    $activeId = ctype_digit($tabIdArg) ? $tabIdArg : (gr_match_tab_by_name_or_synonym($tabs, $tabIdArg) ?? (string)$tabs[0]['id']);
} elseif (($action === 'toggle' || $action === 'set') && $varId > 0) {
    $activeId = gr_find_tab_for_var($tabs, $varId) ?? (string)$tabs[0]['id'];
} else {
    $activeId = ($args2_raw !== '' && !ctype_digit($args2_raw))
        ? (gr_match_tab_by_name_or_synonym($tabs, $args2_raw) ?? null)
        : null;
    if ($activeId === null) {
        foreach ([$voice_action, $voice_device, $voice_alles, $voice_object] as $cand) {
            $cand = trim((string)$cand); if ($cand==='') continue;
            $id = gr_match_tab_by_name_or_synonym($tabs, $cand);
            if ($id !== null) { $activeId = $id; break; }
        }
    }
    if ($activeId === null) $activeId = (string)$tabs[0]['id'];
}

$activeTitle = '';
foreach ($tabs as $t) { if ((string)$t['id'] === (string)$activeId) { $activeTitle = (string)$t['title']; break; } }
if ($activeTitle === '') $activeTitle = (string)($tabs[0]['title'] ?? $rendererDefaultTitle);
$logV("[$RID][{$rendererLogName}] activeTab=$activeId title=$activeTitle");

/* =========================
   ACTIONS (APL)
   ========================= */
$didAction = false;

if ($action === 'toggle' && $varId > 0) {
    if (!gr_can_toggle_var($varId, $CAN_TOGGLE)) {
        echo json_encode([
            'speech'     => gr_format_value_speech($varId),
            'reprompt'   => '',
            'apl'        => null,
            'endSession' => false
        ], GR_JF);
        return;
    }
    if (IPS_ObjectExists($varId)) {
        $var = @IPS_GetVariable($varId);
        if (is_array($var)) {
            $set = null;
            if ($toggleTo === 'on' || $toggleTo === 'off') {
                $set = ($toggleTo === 'on') ? 1 : 0;
            } else {
                $now = @GetValue($varId);
                $set = gr_asBool($now) ? 0 : 1;
            }
            if (gr_hasAction($var)) { @RequestAction($varId, $set); } else { @SetValueBoolean($varId, (bool)$set); }
            $didAction = true; $logB("[$RID][{$rendererLogName}] TOGGLE var=$varId set=".$set);
        } else {
            echo json_encode(['speech'=>'Schaltvorgang nicht möglich. Variable nicht gefunden.','reprompt'=>'','apl'=>null,'endSession'=>false], GR_JF);
            return;
        }
    }
}
elseif ($action === 'set' && $varId > 0) {
    if (!$CAN_TOGGLE) {
        echo json_encode(['speech'=>'Setzen ist aktuell deaktiviert.','reprompt'=>'','apl'=>null,'endSession'=>false], GR_JF);
        return;
    }
    if (IPS_ObjectExists($varId)) {
        $var = @IPS_GetVariable($varId); $obj = @IPS_GetObject($varId);
        if (!is_array($var) || !is_array($obj)) {
            echo json_encode(['speech'=>'Setzen nicht möglich. Variable nicht gefunden.','reprompt'=>'','apl'=>null,'endSession'=>false], GR_JF);
            return;
        }
        $resolved = gr_resolve_set_value($varId, $numberIn, (string)$rawText, $var, $obj);
        if (!$resolved['ok']) { echo json_encode(['speech'=>$resolved['why'],'reprompt'=>'','apl'=>null,'endSession'=>false], GR_JF); return; }
        $finalValue = $resolved['value'];
        if (gr_hasAction($var)) {
            $lastWarn = null;
            set_error_handler(function($no,$str) use (&$lastWarn){ $lastWarn = $str; return true; });
            @RequestAction($varId, $finalValue);
            restore_error_handler();
            if ($lastWarn) { echo json_encode(['speech'=>gr_hc_humanize_error($lastWarn),'reprompt'=>'','apl'=>null,'endSession'=>false], GR_JF); return; }
        } else {
            $vt = (int)($var['VariableType'] ?? 3);
            switch ($vt) {
                case 0: @SetValueBoolean($varId, gr_asBool($finalValue)); break;
                case 1: @SetValueInteger($varId, (int)round((float)$finalValue)); break;
                case 2: @SetValueFloat($varId, (float)$finalValue); break;
                default: @SetValueString($varId, (string)$finalValue); break;
            }
        }
        $didAction = true; $logB("[$RID][{$rendererLogName}] SET var=$varId value=".json_encode($finalValue, GR_JF));
    }
}

/* =========================
   Rows bauen (ggf. reread)
   ========================= */
if ($didAction) IPS_Sleep(GR_DELAY_MS);

$rows = gr_buildRowsFromNode((int)$activeId, $CAN_TOGGLE);
$rows = gr_maybe_sort_rows_uniform_type($rows);

$logV("[$RID][{$rendererLogName}] rows=".count($rows));
$enumDebug = array_values(array_filter($rows, static function($r){
    $n = gr_norm((string)($r['name'] ?? ''));
    $v = gr_norm((string)($r['value'] ?? ''));
    return (!empty($r['isSection'])) || ($n==='steuern') || in_array($v,['start','starten','stop','stoppen','fortsetzen','pause','continue','resume'], true)
        || (!empty($r['isEnum'])) || (!empty($r['hasEnum']) && !empty($r['isNumber']));
}));
$logV("[$RID][{$rendererLogName}] enumRows ".json_encode($enumDebug, GR_JF));

/* =========================
   APL (Doc + Datasources)
   ========================= */
$doc = ['type' => 'Link', 'src' => $rendererAplDoc];
$ds  = [
    'deviceTableData' => [
        'title'     => $activeTitle,
        'subtitle'  => $rendererSubtitle,
        'tabs'      => $tabs,
        'activeTab' => (string)$activeId,
        'headers'   => ['name'=>'Name','value'=>'Wert','updated'=>'Aktualisiert'],
        'rows'      => $rows,
        'route'     => $rendererRouteKey
    ]
];

// Vollständiges DS-Logging + optionaler Dump in String-Variable
if ($LOG_APL) {
    @IPS_LogMessage($LOG_TAG, "[$RID][{$rendererLogName}] APL.DS.PRETTY\n".json_encode($ds, GR_JF | JSON_PRETTY_PRINT));
    $logV("[$RID][{$rendererLogName}] APL.DS ".json_encode($ds, GR_JF));
}
if ($DUMP_VAR > 0 && @IPS_ObjectExists($DUMP_VAR)) {
    $v = @IPS_GetVariable($DUMP_VAR);
    if (is_array($v) && (int)($v['VariableType'] ?? 3) === 3) {
        @SetValueString($DUMP_VAR, json_encode($ds, GR_JF | JSON_PRETTY_PRINT));
    }
}

$apl = $aplSupported ? ['doc' => $doc, 'ds' => $ds, 'token' => $rendererAplToken] : null;

echo json_encode([
    'speech'     => '',
    'reprompt'   => '',
    'apl'        => $apl,
    'endSession' => false
], GR_JF);


/* ============================================================
   Helpers (gr_ Präfix)
   ============================================================ */

function gr_is_html_doc(string $s): bool {
    $t = ltrim($s);
    return stripos($t, '<!doctype html') === 0;
}

function gr_is_placeholder_name(string $name): bool {
    $n = trim($name);
    if ($n === '') return true;
    return (bool)preg_match('/^Unnamed Object\b/i', $n);
}

function gr_display_name(string $name): string {
    $name = trim($name);
    if ($name === '' || gr_is_placeholder_name($name)) return '';
    $name = preg_replace('/^\s*link\s*:\s*/i', '', $name);
    $name = preg_replace('/\s*\(link:.*$/i', '', $name);
    return trim($name);
}

function gr_fallback_name_from_hierarchy(int $objectId): string {
    $pid = @IPS_GetParentID($objectId);
    while (is_int($pid) && $pid > 0 && IPS_ObjectExists($pid)) {
        $p = @IPS_GetObject($pid);
        $nm = gr_display_name((string)($p['ObjectName'] ?? ''));
        if ($nm !== '') return $nm;
        $pid = @IPS_GetParentID($pid);
    }
    return '';
}

function gr_info_is_writable(array $obj, array $var): bool {
    if (gr_hasAction($var)) return true;
    $info = gr_info_json($obj);
    foreach (['writable','writeable','isWritable','canSet','settable','editable','readOnly'] as $k) {
        if (!array_key_exists($k, $info)) continue;
        $v = $info[$k];
        if ($k === 'readOnly') return !filter_var($v, FILTER_VALIDATE_BOOLEAN);
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) === 1;
        if (is_string($v)) return in_array(strtolower($v), ['1','true','yes','ja'], true);
    }
    return false;
}

function gr_collect_voice_candidates(array $candidates): array
{
    $out = [];
    foreach ($candidates as $cand) {
        $cand = trim((string)$cand);
        if ($cand === '') {
            continue;
        }
        $out[] = $cand;
    }
    return array_values(array_unique($out));
}

function gr_extract_name_and_toggle(string $text): array
{
    $raw = trim($text);
    if ($raw === '') {
        return ['name' => '', 'toggleTo' => null];
    }
    $lower = gr_norm($raw);
    $tokens = preg_split('/\s+/', $lower);
    if (!$tokens) {
        return ['name' => $raw, 'toggleTo' => null];
    }

    $last = end($tokens);
    $toggleTo = null;
    if (in_array($last, ['ein','an','on','einschalten','start','starten','aktivieren'], true)) {
        $toggleTo = 'on';
        array_pop($tokens);
    } elseif (in_array($last, ['aus','off','ausschalten','stop','stoppen','deaktivieren'], true)) {
        $toggleTo = 'off';
        array_pop($tokens);
    }

    $name = trim(implode(' ', $tokens));
    if ($name === '') {
        $name = $raw;
    }

    return ['name' => $name, 'toggleTo' => $toggleTo];
}

function gr_toggle_from_action_word(string $action): ?string
{
    $actionNorm = gr_norm($action);
    if ($actionNorm === '') {
        return null;
    }
    if (in_array($actionNorm, ['ein','an','on','einschalten','start','starten','aktivieren'], true)) {
        return 'on';
    }
    if (in_array($actionNorm, ['aus','off','ausschalten','stop','stoppen','deaktivieren'], true)) {
        return 'off';
    }
    return null;
}

function gr_find_var_by_name_from_tabs(array $tabs, string $name, bool $aeToggle): ?array
{
    $nameKey = gr_keynorm($name);
    if ($nameKey === '') {
        return null;
    }
    foreach ($tabs as $tab) {
        $tabId = (int)($tab['id'] ?? 0);
        if ($tabId <= 0) {
            continue;
        }
        $rows = gr_buildRowsFromNode($tabId, $aeToggle);
        foreach ($rows as $row) {
            if (!empty($row['isSection'])) {
                continue;
            }
            $rowName = (string)($row['name'] ?? '');
            if ($rowName === '') {
                continue;
            }
            if (gr_keynorm($rowName) === $nameKey) {
                return [
                    'varId' => (int)($row['targetId'] ?? 0),
                    'row'   => $row
                ];
            }
        }
    }
    return null;
}

function gr_can_toggle_var(int $varId, bool $aeToggle): bool
{
    if (!$aeToggle || $varId <= 0 || !IPS_ObjectExists($varId)) {
        return false;
    }
    $var = @IPS_GetVariable($varId);
    if (!is_array($var)) {
        return false;
    }
    if ((int)($var['VariableType'] ?? 3) !== 0) {
        return false;
    }
    return gr_hasAction($var);
}

function gr_format_value_speech(int $varId): string
{
    if ($varId <= 0 || !IPS_ObjectExists($varId)) {
        return 'Schaltvorgang nicht möglich. Variable nicht gefunden.';
    }
    $var = @IPS_GetVariable($varId);
    if (!is_array($var)) {
        return 'Schaltvorgang nicht möglich. Variable nicht gefunden.';
    }
    $type = (int)($var['VariableType'] ?? 3);
    $value = gr_formatValueHuman($varId, $type, @GetValue($varId));
    return 'Aktueller Wert ist ' . $value . '.';
}

function gr_normalize_event(string $a1, string $a2, $numRaw): array {
    $a1l = mb_strtolower($a1, 'UTF-8');
    $out = ['action'=>'', 'varId'=>0, 'tabId'=>'', 'value'=>null, 'toggleTo'=>null, 'rawText'=>''];

    // "<domain>.tab"
    if (preg_match('/^[^.]+\.tab$/i', $a1l)) {
        $out['action']  = 'tab';
        $out['tabId']   = (string)$a2;
        $out['rawText'] = (string)$a2;
        return $out;
    }

    // "<domain>.(t|toggle).<id>"
    if (preg_match('/^[^.]+\.(?:t|toggle)\.(\d+)$/i', $a1, $m)) {
        $out['action'] = 'toggle';
        $out['varId']  = (int)$m[1];
        $arg2 = mb_strtolower(trim((string)$a2), 'UTF-8');
        if ($arg2 === 'on' || $arg2 === 'off') {
            $out['toggleTo'] = $arg2;
        }
        $out['rawText'] = (string)$a2;
        return $out;
    }

    // "<domain>.set<number|enum>.<id>"
    if (preg_match('/^[^.]+\.set(?:number|enum)?\.(\d+)$/i', $a1, $m)) {
        $out['action']  = 'set';
        $out['varId']   = (int)$m[1];
        $out['rawText'] = (string)$a2;
        if ($numRaw !== null && $numRaw !== '') {
            $out['value'] = is_numeric($numRaw) ? (float)$numRaw : null;
        } elseif ($a2 !== '' && is_numeric($a2)) {
            $out['value'] = (float)$a2;
        }
        return $out;
    }

    // "<domain>.set" oder "<domain>.setenum" mit varId in args2
    if (preg_match('/^[^.]+\.set(?:enum)?$/i', $a1l)) {
        if (ctype_digit($a2)) {
            $out['action'] = 'set';
            $out['varId']  = (int)$a2;
            $out['value']  = ($numRaw !== null && $numRaw !== '') ? (float)$numRaw : null;
            $out['rawText']= (string)$numRaw;
            return $out;
        }
    }

    return $out;
}

function gr_resolveRoomKey(string $spoken, array $roomMap, array $ROOMS): ?string
{
    $spoken = gr_norm($spoken);
    if ($spoken === '') return null;

    foreach ($roomMap as $key => $arr) {
        $syn  = array_map('gr_norm', (array)($arr[0] ?? []));
        $disp = gr_norm((string)($arr[1] ?? $key));
        if ($spoken === $disp || in_array($spoken, $syn, true)) return (string)$key;
    }

    foreach ($ROOMS as $key => $def) {
        $disp = gr_norm((string)($def['display'] ?? $key));
        if ($spoken === $disp || $spoken === gr_norm((string)$key)) return (string)$key;
    }
    return null;
}

function gr_collectRoomDeviceTabs(
    array $ROOMS,
    ?string $onlyRoomKey = null,
    string $domainKey = 'devices',
    bool $respectRoomFilter = true
): array
{
    $tabs = [];
    $domainKey = $domainKey !== '' ? $domainKey : 'devices';

    $addTab = static function(array &$tabs, ?array $t): void {
        if (!$t) {
            return;
        }
        $key = (string)($t['id'] ?? '') . '|' . (string)($t['title'] ?? '');
        static $seen = [];
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $tabs[] = $t;
    };

    // 1) Optional: global-Bereich immer mitnehmen (z.B. globale Bienen / Sicherheit)
    if (isset($ROOMS['global']) && is_array($ROOMS['global'])) {
        $global = $ROOMS['global'];

        $dev = $global['domains'][$domainKey]['tabs'] ?? null;
        if (is_array($dev)) {
            foreach ($dev as $title => $def) {
                $addTab($tabs, gr_normalize_tab_def((string)$title, $def));
            }
        }

        $domains = $global['domains'] ?? [];
        if (is_array($domains)) {
            foreach ($domains as $domVal) {
                if (!is_array($domVal)) {
                    continue;
                }
                $nested = $domVal[$domainKey]['tabs'] ?? null;
                if (!is_array($nested)) {
                    continue;
                }
                foreach ($nested as $title => $def) {
                    $addTab($tabs, gr_normalize_tab_def((string)$title, $def));
                }
            }
        }
    }

    // 2) Alle Rooms durchsuchen
    foreach ($ROOMS as $roomKey => $room) {
        if ($roomKey === 'global') {
            continue;
        }

        // Klassische Geräte/Bewässerung: Room-Filter beachten
        if ($respectRoomFilter && $onlyRoomKey !== null && (string)$roomKey !== (string)$onlyRoomKey) {
            continue;
        }

        $dev = $room['domains'][$domainKey]['tabs'] ?? null;
        if (is_array($dev)) {
            foreach ($dev as $title => $def) {
                $addTab($tabs, gr_normalize_tab_def((string)$title, $def));
            }
        }

        $domains = $room['domains'] ?? [];
        if (is_array($domains)) {
            foreach ($domains as $domVal) {
                if (!is_array($domVal)) {
                    continue;
                }
                $nested = $domVal[$domainKey]['tabs'] ?? null;
                if (!is_array($nested)) {
                    continue;
                }
                foreach ($nested as $title => $def) {
                    $addTab($tabs, gr_normalize_tab_def((string)$title, $def));
                }
            }
        }
    }

    usort($tabs, static function ($a, $b) {
        $oa = (int)($a['order'] ?? 9999); $ob = (int)($b['order'] ?? 9999);
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        return strcasecmp((string)$a['title'], (string)$b['title']);
    });

    return $tabs;
}




function gr_rooms_domain_summary(array $ROOMS, ?string $onlyRoomKey = null): array
{
    $summary = [];

    foreach ($ROOMS as $roomKey => $roomDef) {
        if ($roomKey === 'global') {
            continue;
        }
        if ($onlyRoomKey !== null && (string)$roomKey !== (string)$onlyRoomKey) {
            continue;
        }

        $domains = is_array($roomDef['domains'] ?? null) ? $roomDef['domains'] : [];
        $domainSummary = [];
        foreach ($domains as $domainKey => $domainDef) {
            if (!is_array($domainDef)) {
                continue;
            }
            $tabs = $domainDef['tabs'] ?? null;
            $domainSummary[(string)$domainKey] = [
                'hasTabs'   => is_array($tabs),
                'tabCount'  => is_array($tabs) ? count($tabs) : 0,
                'tabTitles' => is_array($tabs) ? array_keys($tabs) : [],
            ];
        }

        $summary[] = [
            'roomKey'  => (string)$roomKey,
            'display'  => (string)($roomDef['display'] ?? $roomKey),
            'domains'  => $domainSummary,
        ];
    }

    return $summary;
}



function gr_normalize_tab_def(string $title, $def): ?array
{
    if (is_int($def)) {
        return ['id' => (string)$def, 'title' => $title, 'order' => 9999, 'synonyms' => []];
    }
    if (is_array($def)) {
        $id = (int)($def['id'] ?? $def['nodeId'] ?? $def['var'] ?? 0);
        if ($id > 0) {
            $order = isset($def['order']) ? (int)$def['order'] : 9999;
            $syn   = [];
            if (!empty($def['synonyms'])) {
                $raw = is_array($def['synonyms']) ? $def['synonyms'] : explode(',', (string)$def['synonyms']);
                foreach ($raw as $s) { $s = trim((string)$s); if ($s !== '') $syn[] = $s; }
            }
            return ['id' => (string)$id, 'title' => $title, 'order' => $order, 'synonyms' => $syn];
        }
    }
    return null;
}

/* =============================
 *  Neue, gruppierte Row-Generierung
 *  — Sortierung: Position, dann Alphabet
 *  — Dummy-Module als Rubriken
 * ============================= */
function gr_buildRowsFromNode(int $rootId, bool $aeToggle): array
{
    if ($rootId <= 0 || !IPS_ObjectExists($rootId)) return [];

    $rows = [];
    foreach (gr_sorted_children($rootId) as $cid) {
        $obj = @IPS_GetObject($cid); if (!is_array($obj)) continue;
        $otype = (int)($obj['ObjectType'] ?? -1);

        // Rubrik: Dummy Modul
        if ($otype === 1 && gr_is_dummy_module_instance($cid)) {
            $rows[] = [
                'isSection' => true,
                'name'      => (string)($obj['ObjectName'] ?? 'Rubrik'),
                'value'     => '',
                'updated'   => ''
            ];
            foreach (gr_sorted_children($cid) as $ccid) {
                $rows = array_merge($rows, gr_rows_for_child($ccid, $aeToggle));
            }
            continue;
        }

        // Normale Kinder
        $rows = array_merge($rows, gr_rows_for_child($cid, $aeToggle));
    }

    return $rows;
}

/**
 * Wenn alle nicht-Section-Zeilen denselben typeName haben und einen updatedTs besitzen,
 * sortiere nach updatedTs absteigend (neueste zuerst). Sonst Reihenfolge unverändert.
 */
function gr_maybe_sort_rows_uniform_type(array $rows): array
{
    if (count($rows) <= 1) {
        return $rows;
    }

    $types = [];
    $allHaveTs = true;

    foreach ($rows as $r) {
        if (!empty($r['isSection'])) {
            continue;
        }
        $t = (string)($r['typeName'] ?? '');
        $types[$t] = true;

        if (!isset($r['updatedTs']) || !is_int($r['updatedTs'])) {
            $allHaveTs = false;
            break;
        }
    }

    // Nur sortieren, wenn genau ein Typ + überall Timestamp
    if (!$allHaveTs || count($types) !== 1) {
        return $rows;
    }

    usort($rows, static function ($a, $b) {
        $aSection = !empty($a['isSection']);
        $bSection = !empty($b['isSection']);

        // Sections bleiben oben in Original-Reihenfolge
        if ($aSection && $bSection) return 0;
        if ($aSection) return -1;
        if ($bSection) return 1;

        $ta = (int)($a['updatedTs'] ?? 0);
        $tb = (int)($b['updatedTs'] ?? 0);

        if ($ta === $tb) {
            return 0;
        }
        // neueste zuerst
        return $tb <=> $ta;
    });

    return $rows;
}

function gr_rows_for_child(int $childId, bool $aeToggle): array
{
    $obj = @IPS_GetObject($childId); if (!is_array($obj)) return [];
    $otype = (int)($obj['ObjectType'] ?? -1);

    if ($otype === 2) { // Variable
        // NEU: HTML-Tabellen (Fingerprint-Logs) als eigene Liste ausgeben
        $special = gr_rows_for_html_table_var($childId, $aeToggle, null);
        if ($special !== null) {
            return $special;
        }

        $row = gr_make_row_for_var($childId, $aeToggle, null);
        return $row ? [$row] : [];
    }

    if ($otype === 6) { // Link → Zielvariable, Name vom Link (Fallbacks)
        $link = @IPS_GetLink($childId);
        $tgt  = (int)($link['TargetID'] ?? 0);
        if ($tgt > 0 && IPS_ObjectExists($tgt)) {
            // 1) Link-Name
            $name = gr_display_name((string)($obj['ObjectName'] ?? ''));
            // 2) Ziel-Variablenname
            if ($name === '') {
                $tgtObj = @IPS_GetObject($tgt);
                $name = gr_display_name((string)($tgtObj['ObjectName'] ?? ''));
            }
            // 3) Elternname (nächstgelegener nicht-Platzhalter)
            if ($name === '') {
                $name = gr_fallback_name_from_hierarchy($childId);
                if ($name === '') { $name = gr_fallback_name_from_hierarchy($tgt); }
            }
            if ($name === '') { $name = null; } // leeres Override als null behandeln

            // NEU: auch bei Links zuerst prüfen, ob HTML-Tabelle vorliegt
            $special = gr_rows_for_html_table_var($tgt, $aeToggle, $name);
            if ($special !== null) {
                return $special;
            }

            $row = gr_make_row_for_var($tgt, $aeToggle, $name);
            return $row ? [$row] : [];
        }
        return [];
    }

    // category/instance → recurse (sorted)
    $rows = [];
    foreach (gr_sorted_children($childId) as $cid) {
        $rows = array_merge($rows, gr_rows_for_child($cid, $aeToggle));
    }
    return $rows;
}

function gr_sorted_children(int $parentId): array
{
    $children = (array)@IPS_GetChildrenIDs($parentId);
    $list = [];
    foreach ($children as $cid) {
        $obj = @IPS_GetObject($cid); if (!is_array($obj)) continue;
        $list[] = [
            'id' => (int)$cid,
            'pos'=> (int)($obj['ObjectPosition'] ?? 0),
            'name'=> (string)($obj['ObjectName'] ?? '')
        ];
    }
    usort($list, static function($a,$b){
        if ($a['pos'] !== $b['pos']) return $a['pos'] <=> $b['pos'];
        return strcasecmp($a['name'], $b['name']);
    });
    return array_column($list, 'id');
}

function gr_is_dummy_module_instance(int $instanceId): bool
{
    if (!IPS_ObjectExists($instanceId)) return false;
    $obj = @IPS_GetObject($instanceId); if (!is_array($obj)) return false;
    if ((int)($obj['ObjectType'] ?? -1) !== 1) return false;
    $inst = @IPS_GetInstance($instanceId);
    if (!is_array($inst)) return false;
    $name = (string)($inst['ModuleInfo']['ModuleName'] ?? '');
    $guid = (string)($inst['ModuleInfo']['ModuleID'] ?? '');
    if ($name === 'Dummy Module') return true;
    return (strtoupper($guid) === '{485D0419-B567-4B4E-8F61-CEE4D2A3EAB8}');
}

/**
 * HTML-Tabellen (z.B. Ekey-Logs) in normale Geräte-Renderer-Zeilen umwandeln.
 * Erwartet einfache Struktur mit <tr><td>...</td>...</tr>.
 * Gibt bei Nicht-Tabelle null zurück, sonst eine fertige Row-Liste.
 */
function gr_rows_for_html_table_var(int $varId, bool $aeToggle, ?string $nameOverride): ?array
{
    if (!IPS_ObjectExists($varId)) return null;

    $obj = @IPS_GetObject($varId);
    $var = @IPS_GetVariable($varId);
    if (!is_array($obj) || !is_array($var)) return null;

    if ((int)($var['VariableType'] ?? 3) !== 3) {
        return null; // nur String-Variablen
    }

    $raw = @GetValue($varId);
    if (!is_string($raw)) {
        return null;
    }

    $lower = mb_strtolower($raw, 'UTF-8');
    if (strpos($lower, '<table') === false || strpos($lower, '<tr') === false || strpos($lower, '<td') === false) {
        return null; // keine Tabelle
    }

    if (!preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $raw, $m)) {
        return null;
    }

    $rowsOut = [];

    $defaultName = $nameOverride !== null && trim($nameOverride) !== ''
        ? $nameOverride
        : gr_display_name((string)($obj['ObjectName'] ?? ''));

    foreach ($m[1] as $rowHtml) {
        // Header-Zeile mit <th> überspringen
        if (stripos($rowHtml, '<th') !== false) {
            continue;
        }

        if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $rowHtml, $cellsMatch) || empty($cellsMatch[1])) {
            continue;
        }

        $cells = [];
        foreach ($cellsMatch[1] as $cellHtml) {
            $text = strip_tags($cellHtml);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim($text);
            if ($text !== '') {
                $cells[] = $text;
            }
        }

        if (!$cells) {
            continue;
        }

        // 1. Spalte = Name, letzte = Datum/Zeit, dazwischen = Aktion
        $first  = $cells[0] ?? '';
        $last   = $cells[count($cells) - 1] ?? '';
        $middle = '';
        if (count($cells) > 2) {
            $midArr = array_slice($cells, 1, -1);
            $middle = implode(' · ', $midArr);
        } elseif (isset($cells[1])) {
            $middle = $cells[1];
        }

        $rowName = $first !== '' ? $first : $defaultName;

        $updated = $last;
        $ts = strtotime($last);
        if ($ts !== false) {
            $updated = gr_formatUpdated($ts);   // → 23.11. 10:44
        } else {
            $ts = 0;
        }

        $rowsOut[] = [
            'name'        => $rowName,
            'value'       => $middle,
            'typeName'    => 'Log',
            'updated'     => $updated,
            'updatedTs'   => $ts,
            'rowBg'       => null,
            'valueColor'  => null,
            'isBool'      => false,
            'boolOn'      => false,
            'canToggle'   => false,
            'isNumber'    => false,
            'canSetNumber'=> false,
            'targetId'    => '',
            'hasEnum'     => false,
            'enumOpts'    => [],
        ];
    }

    return $rowsOut ?: null;
}

// --- REPLACE this function ---
function gr_make_row_for_var(int $varId, bool $aeToggle, ?string $nameOverride): ?array
{
    if (!IPS_ObjectExists($varId)) return null;
    $obj = @IPS_GetObject($varId); $var = @IPS_GetVariable($varId);
    if (!is_array($obj) || !is_array($var)) return null;

    $type = (int)($var['VariableType'] ?? 3);

    $useOverride = ($nameOverride !== null && trim((string)$nameOverride) !== '') ? (string)$nameOverride : null;
    $name = $useOverride !== null
        ? gr_display_name($useOverride)
        : gr_display_name((string)($obj['ObjectName'] ?? ''));

    // weitere Fallbacks, wenn noch leer
    if ($name === '') {
        // a) nächster Elternname
        $name = gr_fallback_name_from_hierarchy($varId);
    }
    if ($name === '') {
        // b) ObjectIdent hübschen
        $ident = (string)($obj['ObjectIdent'] ?? '');
        if ($ident !== '') {
            $name = gr_display_name(str_replace(['_', '.'], ' ', $ident));
        }
    }
    if ($name === '') {
        // c) letzte Rettung
        $fallback = @IPS_GetName($varId);
        $name = gr_display_name(is_string($fallback) ? $fallback : '');
        if ($name === '') $name = 'ID '.$varId;
    }

    $raw       = @GetValue($varId);
    $profile   = gr_get_profile_name($var);
    $assoc     = gr_get_profile_associations($profile);
    $enumFromInfo = gr_info_enum_for_var($obj, $var);

    $value     = gr_formatValueHuman($varId, $type, $raw);
    $typeName  = gr_typeName($type);
    $updatedTs = (int)($var['VariableUpdated'] ?? time());
    $updated   = gr_formatUpdated($updatedTs);
    $boolOn    = ($type === 0) ? gr_asBool($raw) : false;
    $controllable = gr_hasAction($var);

    if ($type === 3 && is_string($raw) && gr_is_html_doc($raw)) {
        return null;
    }

    $valueColor = null;
    if ($type !== 0) {
        $vNorm = mb_strtolower(trim((string)$value), 'UTF-8');
        if ($vNorm === 'aus' || $vNorm === 'nein' || $vNorm === 'off') { $valueColor = '#FF6B6B'; }
    }

    $base = [
        'name'       => $name,
        'value'      => $value,
        'typeName'   => $typeName,
        'updated'    => $updated,
        'updatedTs'  => $updatedTs,
        'rowBg'      => null,
        'valueColor' => $valueColor
    ];

    if ($type === 0) {
        return $base + [
            'isBool'    => true,
            'boolOn'    => $boolOn,
            'canToggle' => ($controllable && $aeToggle),
            'hasEnum'   => false,
            'enumOpts'  => [],
            'targetId'  => (string)$varId
        ];
    }

    if ($type === 1 || $type === 2) {
        $enumOpts = [];
        if (!empty($assoc['byValue'])) {
            foreach ($assoc['byValue'] as $valKey => $label) {
                $num = (strpos((string)$valKey, '.') !== false) ? (float)$valKey : (int)$valKey;
                $enumOpts[] = ['label'=>$label, 'value'=>$num];
            }
        }
        return $base + [
            'isBool'       => false,
            'boolOn'       => false,
            'isNumber'     => true,
            'canSetNumber' => ($controllable && $aeToggle),
            'targetId'     => (string)$varId,
            'hasEnum'      => !empty($enumOpts),
            'enumOpts'     => $enumOpts,
            'rawValue'     => $raw
        ];
    }

    // String (incl. enums/steuern/profiles)
    $nameNorm = gr_norm($name);
    $valNorm  = gr_norm((string)@GetValue($varId));
    $isSteuernLike = ($nameNorm === 'steuern') || in_array($valNorm, ['start','starten','stop','stoppen','fortsetzen','pause','continue','resume'], true);

    $infoEnum   = $enumFromInfo['opts'];
    $isWritable = gr_info_is_writable($obj, $var);
    $canSet     = (($isWritable && $aeToggle) || $isSteuernLike);

    if (!empty($infoEnum)) {
        return $base + [
            'isBool'=>false,'boolOn'=>false,'isEnum'=>true,'isNumber'=>false,
            'canSetNumber'=>$canSet,'readOnly'=>!$canSet,'targetId'=>(string)$varId,
            'hasEnum'=>true,'enumOpts'=>$infoEnum,'rawValue'=>(string)@GetValue($varId)
        ];
    }
    
    if ($isSteuernLike) {
        return $base + [
            'isBool'=>false,'boolOn'=>false,'isEnum'=>true,'isNumber'=>false,
            'canSetNumber'=>true,'readOnly'=>false,'targetId'=>(string)$varId,
            'hasEnum'=>true,'enumOpts'=>[
                ['label'=>'Start','value'=>'Start'],
                ['label'=>'Stoppen','value'=>'Stoppen'],
                ['label'=>'Fortsetzen','value'=>'Fortsetzen']
            ],
            'rawValue'=>(string)@GetValue($varId)
        ];
    }
    

    $enumOpts = [];
    if (!empty($assoc['byValue'])) {
        foreach ($assoc['byValue'] as $valKey => $label) { $enumOpts[] = ['label'=>$label, 'value'=>$valKey]; }
    }
    if ($enumOpts) {
        return $base + [
            'isBool'=>false,'boolOn'=>false,'isEnum'=>true,'isNumber'=>false,
            'canSetNumber'=>$canSet,'readOnly'=>!$canSet,'targetId'=>(string)$varId,
            'hasEnum'=>true,'enumOpts'=>$enumOpts,'rawValue'=>(string)@GetValue($varId)
        ];
    }

    return $base + [
        'isBool'=>false,'boolOn'=>false,'isEnum'=>false,'hasEnum'=>false,'enumOpts'=>[],
        'isNumber'=>false,'canSetNumber'=>false,'readOnly'=>true,'targetId'=>(string)$varId
    ];
}

function gr_collectVarsDeep(int $nodeId, int $depth, int $maxDepth): array
{
    if ($depth > $maxDepth || !IPS_ObjectExists($nodeId)) return [];

    $obj  = @IPS_GetObject($nodeId);
    if (!is_array($obj)) return [];

    $type = (int)($obj['ObjectType'] ?? -1);

    // Variable → return entry with its own name/position
    if ($type === 2) {
        return [[
            'id'   => $nodeId,
            'name' => (string)($obj['ObjectName'] ?? ''),
            'pos'  => (int)($obj['ObjectPosition'] ?? 0),
        ]];
    }

    // Link → resolve target but override name/position with link's values
    if ($type === 6) {
        $link = @IPS_GetLink($nodeId);
        $tgt  = (int)($link['TargetID'] ?? 0);
        if ($tgt <= 0) return [];

        $list         = gr_collectVarsDeep($tgt, $depth + 1, $maxDepth);
        $nameOverride = (string)($obj['ObjectName'] ?? '');
        $posOverride  = (int)($obj['ObjectPosition'] ?? 0);

        foreach ($list as &$e) {
            if (!is_array($e)) { $e = ['id' => (int)$e, 'name' => '', 'pos' => 9999]; }
            if ($nameOverride !== '') $e['name'] = $nameOverride;
            $e['pos'] = $posOverride;
        }
        unset($e);

        return $list;
    }

    // Category/Instance/… → collect from children
    $out = [];
    foreach ((array)@IPS_GetChildrenIDs($nodeId) as $cid) {
        foreach (gr_collectVarsDeep((int)$cid, $depth + 1, $maxDepth) as $e) {
            $out[] = $e;
        }
    }
    return $out;
}

function gr_find_tab_for_var(array $tabs, int $varId): ?string {
    foreach ($tabs as $t) {
        $root = (int)$t['id'];
        if ($root > 0 && gr_node_contains_var($root, $varId, 0, GR_MAX_DEPTH)) { return (string)$t['id']; }
    }
    return null;
}

function gr_node_contains_var(int $nodeId, int $needle, int $depth, int $maxDepth): bool {
    if ($depth > $maxDepth || !IPS_ObjectExists($nodeId)) return false;
    $obj = @IPS_GetObject($nodeId); if (!is_array($obj)) return false;
    $type = (int)($obj['ObjectType'] ?? -1);
    if ($type === 2) return $nodeId === $needle;
    if ($type === 6) { $link = @IPS_GetLink($nodeId); $tgt = (int)($link['TargetID'] ?? 0); return $tgt > 0 ? gr_node_contains_var($tgt, $needle, $depth + 1, $maxDepth) : false; }
    foreach ((array)@IPS_GetChildrenIDs($nodeId) as $cid) { if (gr_node_contains_var((int)$cid, $needle, $depth + 1, $maxDepth)) return true; }
    return false;
}

/* ---------- Value Formatting / Profiles ---------- */

function gr_get_profile_name(array $var): string {
    $p = (string)($var['VariableCustomProfile'] ?? ''); if ($p !== '') return $p;
    $p = (string)($var['VariableProfile'] ?? ''); return $p ?: '';
}

function gr_get_profile_associations(string $profile): array {
    static $CACHE = null;
    if ($CACHE === null) {
        $CACHE = [];
        if (function_exists('IPS_GetVariableProfileList')) {
            $list = @IPS_GetVariableProfileList();
            if (is_array($list)) {
                foreach ($list as $p) {
                    $prof = @IPS_GetVariableProfile($p); if (!is_array($prof)) { continue; }
                    $byValue = []; $byName = [];
                    foreach ((array)($prof['Associations'] ?? []) as $a) {
                        $v = $a['Value'] ?? null; $n = (string)($a['Name'] ?? '');
                        if ($v === null || $n === '') continue;
                        $byValue[(string)$v] = $n;
                        $byName[gr_norm($n)] = $v;
                    }
                    $CACHE[$p] = ['byValue'=>$byValue, 'byName'=>$byName];
                }
            }
        }
    }

    if ($profile === '') return ['byValue'=>[], 'byName'=>[]];

    if (!isset($CACHE[$profile])) {
        $prof = @IPS_GetVariableProfile($profile);
        $byValue = []; $byName = [];
        if (is_array($prof)) {
            foreach ((array)($prof['Associations'] ?? []) as $a) {
                $v = $a['Value'] ?? null; $n = (string)($a['Name'] ?? '');
                if ($v === null || $n === '') continue;
                $byValue[(string)$v] = $n; $byName[gr_norm($n)] = $v;
            }
        }
        $CACHE[$profile] = ['byValue'=>$byValue, 'byName'=>$byName];
    }

    return $CACHE[$profile] ?? ['byValue'=>[], 'byName'=>[]];
}

function gr_var_expects_iso_duration(int $varId, array $var): bool {
    $type = (int)($var['VariableType'] ?? 3);
    if ($type === 3) {
        $cur = @GetValue($varId);
        if (is_string($cur) && preg_match('/^P(T(\d+H)?(\d+M)?(\d+S)?)$/i', $cur)) return true;
        $p = gr_get_profile_name($var);
        if ($p !== '' && stripos($p, 'duration') !== false) return true;
    }
    return false;
}

function gr_parse_duration_to_seconds(string $raw): ?int {
    $s = gr_norm($raw); if ($s === '') return null;
    $s = str_replace(['sekunden','sek','second','seconds','minuten','minute','min','stunden','stunde','std','hour','hours','h'],
                     ['s','s','s','s','m','m','m','h','h','h','h','h'], $s);
    $h = $m = $sec = 0;
    if (preg_match('/(\d+)\s*h/', $s, $mm)) $h   = (int)$mm[1];
    if (preg_match('/(\d+)\s*m/', $s, $mm)) $m   = (int)$mm[1];
    if (preg_match('/(\d+)\s*s?(\b|$)/', $s, $mm)) $sec = (int)$mm[1];
    if ($h===0 && $m===0 && $sec===0) { if (preg_match('/^\d+$/', $s)) $sec = (int)$s; else return null; }
    return $h*3600 + $m*60 + $sec;
}

function gr_seconds_to_iso(int $total): string {
    $h = intdiv($total, 3600); $m = intdiv($total % 3600, 60); $s = $total % 60;
    $out = 'PT'; if ($h>0) $out .= $h.'H'; if ($m>0) $out .= $m.'M'; if ($s>0 || ($h==0 && $m==0)) $out .= $s.'S';
    return $out;
}

function gr_resolve_set_value(int $varId, $numberIn, string $rawText, array $var, array $obj): array {
    $rawText = trim((string)$rawText);

    if ($numberIn !== null && $numberIn !== '') { return ['ok'=>true, 'value'=>(float)$numberIn, 'why'=>null]; }
    if ($rawText !== '' && is_numeric($rawText)) { return ['ok'=>true, 'value'=>(float)$rawText, 'why'=>null]; }

    $info = gr_info_json($obj);
    if (!empty($info)) {
        if (isset($info['enumMap']) && is_array($info['enumMap']) && $rawText !== '') {
            $key = gr_norm($rawText);
            foreach ($info['enumMap'] as $label => $val) { if (gr_norm((string)$label) === $key) { return ['ok'=>true, 'value'=>$val, 'why'=>null]; } }
        }
        if ($rawText !== '') {
            if (!empty($info['enum']) && is_array($info['enum'])) {
                foreach ($info['enum'] as $label) { if (gr_norm((string)$label) === gr_norm($rawText)) { return ['ok'=>true, 'value'=>(string)$label, 'why'=>null]; } }
            }
            if (!empty($info['enumOpts']) && is_array($info['enumOpts'])) {
                foreach ($info['enumOpts'] as $opt) {
                    $lab = (string)($opt['label'] ?? ''); $val = $opt['value'] ?? $lab;
                    if ($lab !== '' && gr_norm($lab) === gr_norm($rawText)) { return ['ok'=>true, 'value'=>$val, 'why'=>null]; }
                }
            }
        }
        if (!empty($info['enumProfile']) && is_string($info['enumProfile']) && $rawText !== '') {
            $assoc = gr_get_profile_associations($info['enumProfile']);
            $key = gr_norm($rawText); if (isset($assoc['byName'][$key])) { return ['ok'=>true, 'value'=>$assoc['byName'][$key], 'why'=>null]; }
        }
    }

    $profile = gr_get_profile_name($var); $assoc = gr_get_profile_associations($profile);
    if ($rawText !== '' && !empty($assoc['byName'])) {
        $key = gr_norm($rawText); if (array_key_exists($key, $assoc['byName'])) { return ['ok'=>true, 'value'=>$assoc['byName'][$key], 'why'=>null]; }
    }

    if ($rawText !== '') {
        $secs = gr_parse_duration_to_seconds($rawText);
        if ($secs !== null) {
            if (gr_var_expects_iso_duration($varId, $var)) return ['ok'=>true, 'value'=>gr_seconds_to_iso($secs), 'why'=>null];
            $type = (int)($var['VariableType'] ?? 3); if ($type === 1 || $type === 2) return ['ok'=>true, 'value'=>$secs, 'why'=>null];
        }
    }

    $type = (int)($var['VariableType'] ?? 3);
    if ($type === 3 && $rawText !== '') { return ['ok'=>true, 'value'=>$rawText, 'why'=>null]; }

    if (!empty($assoc['byValue'])) { $allowed = implode(', ', array_values($assoc['byValue'])); return ['ok'=>false, 'why'=>'Ungültiger Wert. Erlaubt: '.$allowed]; }
    $infoAllowed = gr_info_allowed_list($obj); if ($infoAllowed !== '') return ['ok'=>false, 'why'=>'Ungültiger Wert. Erlaubt: '.$infoAllowed];

    return ['ok'=>false, 'why'=>'Ungültiger Wert.'];
}

function gr_hc_humanize_error(string $s): string {
    $t = gr_norm($s);
    if (strpos($t, 'remotestart not active') !== false)  return 'Fernstart ist nicht aktiviert. Bitte am Gerät freigeben.';
    if (strpos($t, 'remotecontrol not active') !== false) return 'Fernbedienung ist nicht aktiviert. Bitte am Gerät erlauben.';
    if (strpos($t, 'localcontrol active') !== false)      return 'Lokale Bedienung ist aktiv. Bitte am Gerät bestätigen.';
    return $s;
}

function gr_formatValueHuman(int $varId, int $type, $val): string
{
    if ($type === 1 || $type === 2) {
        $var = @IPS_GetVariable($varId);
        if (is_array($var)) {
            $profile = gr_get_profile_name($var); $assoc = gr_get_profile_associations($profile);
            if (!empty($assoc['byValue'])) { $k = (string)(is_bool($val)?(int)$val:$val); if (isset($assoc['byValue'][$k])) return $assoc['byValue'][$k]; }
        }
    }

    switch ($type) {
        case 0: return gr_asBool($val) ? 'Ja' : 'Nein';
        case 1:
        case 2:
            $f = @GetValueFormatted($varId); if (is_string($f) && $f !== '') return $f;
            if (is_float($val) || is_double($val)) return number_format((float)$val, 2, ',', '');
            return (string)$val;
        default:
            $s = trim((string)$val); if ($s === '') return '—';
            $enum = gr_tryHumanizeEnum($s); if ($enum !== null) return $enum; return $s;
    }
}

function gr_tryHumanizeEnum(string $s): ?string
{
    $t = trim($s);
    if (preg_match('/^P(T?(?:\d+H)?(?:\d+M)?(?:\d+S)?)$/i', $t)) {
        $parts = []; if (preg_match('/(\d+)H/i', $t, $m)) $parts[] = (int)$m[1].' h';
        if (preg_match('/(\d+)M/i', $t, $m)) $parts[] = (int)$m[1].' m';
        if (preg_match('/(\d+)S/i', $t, $m)) $parts[] = (int)$m[1].' s';
        return $parts ? implode(' ', $parts) : '0 s';
    }
    $last = (strpos($t, '.') !== false) ? preg_replace('/^.*\./', '', $t) : $t;
    $last = str_replace('_', ' ', $last); $last = trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $last));
    $de = gr_de_status_word($last); if ($de !== null) return $de; return $last !== '' ? $last : null;
}

function gr_de_status_word(string $word): ?string
{
    $w = mb_strtolower(trim($word), 'UTF-8'); if ($w === '') return null;
    if ($w === 'inactive') return 'Inaktiv'; if ($w === 'active') return 'Aktiv'; if ($w === 'standby') return 'Standby';
    if ($w === 'on') return 'Ein'; if ($w === 'off') return 'Aus'; if ($w === 'open' || $w === 'opened') return 'Offen'; if ($w === 'close' || $w === 'closed') return 'Geschlossen';
    if ($w === 'running') return 'Läuft'; if ($w === 'paused') return 'Pausiert'; if ($w === 'ready' || $w === 'idle') return 'Bereit';
    if ($w === 'busy') return 'Beschäftigt'; if ($w === 'error' || $w === 'fault' || $w === 'failure') return 'Fehler'; if ($w === 'warning') return 'Warnung';
    if ($w === 'ok' || $w === 'success') return 'OK'; if ($w === 'connected') return 'Verbunden'; if ($w === 'disconnected') return 'Getrennt'; if ($w === 'unknown') return 'Unbekannt';
    if (preg_match('/inactive$/', $w)) return mb_convert_case(preg_replace('/inactive$/', 'inaktiv', $w), MB_CASE_TITLE, 'UTF-8');
    if (preg_match('/active$/',   $w)) return mb_convert_case(preg_replace('/active$/',   'aktiv',   $w), MB_CASE_TITLE, 'UTF-8');
    return null;
}

function gr_hasAction(array $var) : bool {
    $custom  = (int)($var['VariableCustomAction'] ?? 0);
    $std     = (int)($var['VariableAction']       ?? 0);
    return ($custom > 0) || ($std > 0);
}

function gr_typeName(int $t): string { if ($t === 0) return 'Boolean'; if ($t === 1) return 'Integer'; if ($t === 2) return 'Float'; return 'String'; }

function gr_asBool($v): bool {
    if (is_bool($v)) return $v; if (is_numeric($v)) return ((float)$v) != 0.0;
    if (is_string($v)) { $s = gr_norm($v); return in_array($s, ['1','true','an','ein','on','ja','yes'], true); }
    return false;
}

function gr_formatUpdated(int $ts): string {
    return date('d.m. H:i', $ts);
}

function gr_compact_datetime_label(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return $s;
    }

    // Erwartet z.B. "23.11.2025 10:44:31" → "23.11. 10:44"
    if (preg_match('/^(\d{1,2}\.\d{1,2})\.\d{2,4}\s+(\d{1,2}:\d{2})(?::\d{2})?$/', $s, $m)) {
        return $m[1] . '. ' . $m[2];
    }

    return $s;
}

function gr_norm(string $s): string { $s = mb_strtolower(trim($s), 'UTF-8'); $s = str_replace(['ae','oe','ue','ss'], ['ä','ö','ü','ß'], $s); return $s; }

/* --- Synonym-Matching für Tabs --- */
function gr_keynorm(string $s): string { $s = gr_norm($s); $s = preg_replace('/[^a-z0-9äöüß]+/u', '', $s); return $s ?? ''; }
function gr_match_tab_by_name_or_synonym(array $tabs, string $spoken): ?string {
    $k = gr_keynorm($spoken); if ($k === '') return null;
    foreach ($tabs as $t) {
        if (gr_keynorm((string)$t['title']) === $k) return (string)$t['id'];
        foreach ((array)($t['synonyms'] ?? []) as $syn) { if (gr_keynorm((string)$syn) === $k) return (string)$t['id']; }
    }
    return null;
}

/* --- ObjectInfo-basierte Enums --- */
function gr_info_json(array $obj): array { $infoRaw = (string)($obj['ObjectInfo'] ?? ''); if ($infoRaw === '') return []; $j = json_decode($infoRaw, true); return is_array($j) ? $j : []; }
function gr_info_enum_for_var(array $obj, array $var): array {
    $out = ['opts'=>[]]; $info = gr_info_json($obj); if (empty($info)) return $out;
    if (!empty($info['enumOpts']) && is_array($info['enumOpts'])) {
        $opts = []; foreach ($info['enumOpts'] as $opt) { $lab = (string)($opt['label'] ?? ''); if ($lab==='') continue; $val = $opt['value'] ?? $lab; $opts[] = ['label'=>$lab, 'value'=>$val]; }
        if ($opts) return ['opts'=>$opts];
    }
    if (!empty($info['enum']) && is_array($info['enum'])) {
        $map = is_array($info['enumMap'] ?? null) ? $info['enumMap'] : [];
        $opts = []; foreach ($info['enum'] as $lab) { $labS = (string)$lab; if ($labS==='') continue; $val = array_key_exists($labS, $map) ? $map[$labS] : $labS; $opts[] = ['label'=>$labS, 'value'=>$val]; }
        if ($opts) return ['opts'=>$opts];
    }
    if (!empty($info['enumProfile']) && is_string($info['enumProfile'])) {
        $assoc = gr_get_profile_associations($info['enumProfile']);
        if (!empty($assoc['byValue'])) { $opts = []; foreach ($assoc['byValue'] as $valKey => $label) { $opts[] = ['label'=>$label, 'value'=>$valKey]; } if ($opts) return ['opts'=>$opts]; }
    }
    return $out;
}
function gr_info_allowed_list(array $obj): string {
    $info = gr_info_json($obj); $labels = [];
    if (!empty($info['enumOpts']) && is_array($info['enumOpts'])) { foreach ($info['enumOpts'] as $opt) { $lab = (string)($opt['label'] ?? ''); if ($lab !== '') $labels[] = $lab; } }
    elseif (!empty($info['enum']) && is_array($info['enum'])) { foreach ($info['enum'] as $lab) { $lab = (string)$lab; if ($lab !== '') $labels[] = $lab; } }
    elseif (!empty($info['enumProfile']) && is_string($info['enumProfile'])) { $assoc = gr_get_profile_associations($info['enumProfile']); if (!empty($assoc['byValue'])) $labels = array_values($assoc['byValue']); }
    return $labels ? implode(', ', $labels) : '';
}

function gr_debug_domain_map(array $ROOMS, string $domainKey): array
{
    $out = [];

    foreach ($ROOMS as $roomKey => $roomDef) {
        $domains = is_array($roomDef['domains'] ?? null) ? $roomDef['domains'] : [];

        $entry = [
            'hasDomain'   => array_key_exists($domainKey, $domains),
            'domainKeys'  => array_keys($domains),
            'tabTitles'   => [],
        ];

        if (isset($domains[$domainKey]['tabs']) && is_array($domains[$domainKey]['tabs'])) {
            $entry['tabTitles'] = array_keys($domains[$domainKey]['tabs']);
        }

        $out[(string)$roomKey] = $entry;
    }

    return $out;
}

function gr_renderer_config(string $routeKey, array $CFG): array {
    global $ROOMS;

    $list = [];
    if (isset($CFG['rendererDomains']) && is_array($CFG['rendererDomains'])) {
        $list = $CFG['rendererDomains'];
    } elseif (isset($CFG['renderer_domains']) && is_array($CFG['renderer_domains'])) {
        $list = $CFG['renderer_domains'];
    }

    $routeKey = strtolower($routeKey);
    $defaults = gr_renderer_default_entry($routeKey);

    foreach ($list as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $route = strtolower(trim((string)($entry['route'] ?? '')));
        if ($route === '' || $route !== $routeKey) {
            continue;
        }
        $normalized = [];
        foreach (['logName', 'roomDomain', 'title', 'subtitle', 'speechEmpty', 'aplDoc', 'aplToken', 'toggleVarKey'] as $field) {
            if (!array_key_exists($field, $entry)) {
                continue;
            }
            $val = trim((string)$entry[$field]);
            if ($val === '') {
                continue;
            }
            $normalized[$field] = $val;
        }

        return array_merge($defaults, $normalized);
    }

    if ($defaults['roomDomain'] === 'devices') {
        $guessedDomain = gr_infer_room_domain_from_rooms($routeKey, is_array($ROOMS) ? $ROOMS : []);
        if ($guessedDomain !== '') {
            $defaults['roomDomain'] = $guessedDomain;
        }
    }

    return $defaults;
}

function gr_renderer_default_entry(string $routeKey): array {
    $normalizedRoute = strtolower($routeKey);
    if ($normalizedRoute === '') {
        $normalizedRoute = 'geraete';
    }
    $title = ucfirst($normalizedRoute);
    $tokenSlug = preg_replace('/[^a-z0-9]+/i', '', $normalizedRoute);
    if ($tokenSlug === '') {
        $tokenSlug = 'renderer';
    }

    $base = [
        'route'        => $normalizedRoute,
        'logName'      => $title,
        'roomDomain'   => 'devices',
        'title'        => $title,
        'subtitle'     => 'Steckdosen & mehr',
        'speechEmpty'  => 'Keine Einträge im RoomsCatalog konfiguriert.',
        'aplDoc'       => 'doc://alexa/apl/documents/' . $title,
        'aplToken'     => 'hv-' . $tokenSlug,
        'toggleVarKey' => $normalizedRoute . '_toggle',
    ];

    $overrides = [
        'geraete' => [
            'logName'     => 'Geraete',
            'roomDomain'  => 'devices',
            'title'       => 'Geräte',
            'speechEmpty' => 'Keine Geräte im RoomsCatalog konfiguriert.',
            'aplDoc'      => 'doc://alexa/apl/documents/Geraete',
            'aplToken'    => 'hv-geraete',
            'toggleVarKey'=> 'geraete_toggle',
        ],
        'bewaesserung' => [
            'logName'     => 'Bewaesserung',
            'roomDomain'  => 'sprinkler',
            'title'       => 'Bewässerung',
            'speechEmpty' => 'Keine Bewässerung im RoomsCatalog konfiguriert.',
            'aplDoc'      => 'doc://alexa/apl/documents/Bewaesserung',
            'aplToken'    => 'hv-bewaesserung',
            'toggleVarKey'=> 'bewaesserung_toggle',
        ],
    ];

    if (isset($overrides[$normalizedRoute])) {
        $base = array_merge($base, $overrides[$normalizedRoute]);
    }

    return $base;
}

function gr_infer_room_domain_from_rooms(string $routeKey, array $ROOMS): string {
    $slugKey = gr_slugify($routeKey);
    if ($slugKey === '') {
        return '';
    }

    foreach ($ROOMS as $room) {
        if (!is_array($room)) {
            continue;
        }
        $domains = (array)($room['domains'] ?? []);
        foreach ($domains as $domainKey => $definition) {
            $normalizedDomainKey = gr_slugify((string)$domainKey);
            $tabs = (array)($definition['tabs'] ?? []);

            // 1) Direkter Treffer auf den Domänenschlüssel (z. B. route "been" → domain "been")
            if ($normalizedDomainKey !== '' && $normalizedDomainKey === $slugKey && $tabs) {
                return (string)$domainKey;
            }

            // 2) Fallback über Tab-Titel (z. B. route "bienen" → Tab "Bienen")
            if (!$tabs) {
                continue;
            }
            foreach ($tabs as $tabKey => $tabDef) {
                $title = '';
                if (is_array($tabDef)) {
                    $title = trim((string)($tabDef['title'] ?? ''));
                }
                if ($title === '') {
                    $title = trim((string)$tabKey);
                }
                if ($title !== '' && gr_slugify($title) === $slugKey) {
                    return (string)$domainKey;
                }
            }
        }
    }

    return '';
}

function gr_slugify(string $raw): string {
    $slug = strtolower(trim($raw));
    $slug = strtr($slug, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);

    return trim((string)$slug, '-');
}
