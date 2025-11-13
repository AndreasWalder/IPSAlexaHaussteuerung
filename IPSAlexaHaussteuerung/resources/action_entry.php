<?php 
/**
 * ============================================================
 * ALEXA ACTION SCRIPT — Routing Heizung / Jalousie / Licht / Lüftung / Geräte
 * ============================================================
 *
 * Änderungsverlauf
 * 2025-11-11: Schlank-Refactor (CoreHelpers)
 * - Slot-Zugriffe über CoreHelpers:getSlot()
 * - APL-Args über CoreHelpers:parseAplArgs()
 * - Raumauflösung über CoreHelpers:room_key_by_spoken()
 * - Domain-Erkennung aus APL-Args über CoreHelpers:domainFromAplArgs()
 * - Tabs-Fallback über CoreHelpers:fallbackTabDomain() + findTabId/buildTabsCache
 * - Nummernextraktion über CoreHelpers:extractNumberOnly()/maybeMergeDecimalFromPercent()
 * - Lokale Helper entfernt, Logging/Routes unverändert
 *
 * 2025-11-10: Router-Refactor
 * - Alles durch ROUTE_ALL geleitet (kein Direkt-Render mehr)
 * - EXTERNAL PAGES Block ersetzt durch Route-Markierung 'external'
 * - HTML.Back-Handler setzt nur $__route='main_launch'
 * - Tabellen-Router statt if/elseif-Kaskade
 * - Doppelte/undefinierte Variablen aufgeräumt (mkCid nur 1x; maskToken/ hitApl/ settingsCall/ nav definiert)
 * - Lazy rooms-Bau nur wenn benötigt
 * - Device-Tabs Matching mit Cache
 * - Logging-Wrapper mit LOG_LEVEL
 *
 * 2025-11-11: Code-Hygiene
 * - Konstanten für 'pendingStage'-Werte (z.B. $STAGE_AWAIT_NAME)
 * - str_starts_with() statt strpos()===0 für Prefix-Prüfungen
 * - @-Fehlerunterdrückung bei RequestAction entfernt
 *
 * 2025-11-11: Refactor
 * - Device-Map-Helfer (dm_...) in 'AlexaHelpers_DeviceMap.php' ausgelagert.
 *
 * 2025-11-11: Anpassung
 * - Lade-Pfad für Helfer-Skript an Config angepasst (liest $V['DeviceMap'] statt $S['DEVICE_MAP_HELPERS'])
 */

function iah_get_instance_id(): int
{
    $self = (int) ($_IPS['SELF'] ?? 0);
    return (int) @IPS_GetParent($self);
}

function iah_get_instance_properties(int $instanceId): array
{
    if ($instanceId <= 0) {
        return [];
    }
    $rawConfig = IPS_GetConfiguration($instanceId);
    $props = json_decode((string) $rawConfig, true);
    return is_array($props) ? $props : [];
}

function iah_get_child_object(int $parent, string $ident, string $name): int
{
    if ($parent <= 0) {
        return 0;
    }
    if ($ident !== '') {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id) {
            return (int) $id;
        }
    }
    if ($name !== '') {
        $id = @IPS_GetObjectIDByName($name, $parent);
        if ($id) {
            return (int) $id;
        }
    }

    return 0;
}

function iah_resolve_configured_var(array $props, string $propKey, int $fallback = 0): int
{
    $configured = (int) ($props[$propKey] ?? 0);
    if ($configured > 0 && IPS_VariableExists($configured)) {
        return $configured;
    }

    return $fallback;
}

function iah_find_script_by_name(int $instanceId, string $name): int
{
    $local = @IPS_GetObjectIDByName($name, $instanceId);
    if ($local) {
        return (int) $local;
    }

    $global = @IPS_GetObjectIDByName($name, 0);
    return (int) $global;
}

function iah_build_system_configuration(int $instanceId): array
{
    $props = iah_get_instance_properties($instanceId);
    $settings = iah_get_child_object($instanceId, 'iahSettings', 'Einstellungen');
    $helper = iah_get_child_object($instanceId, 'iahHelper', 'Alexa new devices helper');

    $var = [
        'BaseUrl'       => (string) ($props['BaseUrl'] ?? ''),
        'Source'        => (string) ($props['Source'] ?? ''),
        'Token'         => (string) ($props['Token'] ?? ''),
        'Passwort'      => (string) ($props['Passwort'] ?? ''),
        'StartPage'     => (string) ($props['StartPage'] ?? '#45315'),
        'LOG_LEVEL'     => (string) ($props['LOG_LEVEL'] ?? 'info'),
        'WfcId'         => (int) ($props['WfcId'] ?? 0),
        'EnergiePageId' => (string) ($props['EnergiePageId'] ?? ''),
        'KameraPageId'  => (string) ($props['KameraPageId'] ?? ''),
        'ActionsEnabled' => [
            'heizung_stellen'   => iah_get_child_object($settings, 'heizungStellen', 'heizung_stellen'),
            'jalousie_steuern'  => iah_get_child_object($settings, 'jalousieSteuern', 'jalousie_steuern'),
            'licht_switches'    => iah_get_child_object($settings, 'lichtSwitches', 'licht_switches'),
            'licht_dimmers'     => iah_get_child_object($settings, 'lichtDimmers', 'licht_dimmers'),
            'lueftung_toggle'   => iah_get_child_object($settings, 'lueftungToggle', 'lueftung_toggle'),
            'geraete_toggle'    => iah_get_child_object($settings, 'geraeteToggle', 'geraete_toggle'),
            'bewaesserung_toggle' => iah_get_child_object($settings, 'bewaesserungToggle', 'bewaesserung_toggle'),
        ],
        'DEVICE_MAP'     => iah_get_child_object($helper, 'deviceMapJson', 'DeviceMapJson'),
        'PENDING_DEVICE' => iah_get_child_object($helper, 'pendingDeviceId', 'PendingDeviceId'),
        'PENDING_STAGE'  => iah_get_child_object($helper, 'pendingStage', 'PendingStage'),
        'DOMAIN_FLAG'    => iah_get_child_object($instanceId, 'domainFlag', 'domain_flag'),
        'SKILL_ACTIVE'   => iah_get_child_object($instanceId, 'skillActive', 'skillActive'),
        'AUSSEN_TEMP'    => iah_resolve_configured_var($props, 'VarAussenTemp', iah_get_child_object($instanceId, 'aussenTemp', 'Außentemperatur')),
        'INFORMATION'    => iah_resolve_configured_var($props, 'VarInformation', iah_get_child_object($instanceId, 'informationText', 'Information')),
        'MELDUNGEN'      => iah_resolve_configured_var($props, 'VarMeldungen', iah_get_child_object($instanceId, 'meldungenText', 'Meldungen')),
        'HEIZRAUM_IST'   => iah_resolve_configured_var($props, 'VarHeizraumIst'),
        'OG_GANG_IST'    => iah_resolve_configured_var($props, 'VarOgGangIst'),
        'TECHNIK_IST'    => iah_resolve_configured_var($props, 'VarTechnikIst'),
        'CoreHelpers'        => iah_get_child_object($helper, 'coreHelpersScript', 'CoreHelpers'),
        'DeviceMap'          => iah_get_child_object($helper, 'deviceMapScript', 'DeviceMap'),
        'RoomBuilderHelpers' => iah_get_child_object($helper, 'roomBuilderHelpersScript', 'RoomBuilderHelpers'),
        'DeviceMapWizard'    => iah_get_child_object($helper, 'deviceMapWizardScript', 'DeviceMapWizard'),
        'Lexikon'            => iah_get_child_object($helper, 'lexikonScript', 'Lexikon'),
    ];

    $scripts = [
        'ROUTE_ALL'           => iah_find_script_by_name($instanceId, 'Route_allRenderer'),
        'RENDER_MAIN'         => iah_find_script_by_name($instanceId, 'LaunchRequest'),
        'RENDER_HEIZUNG'      => iah_find_script_by_name($instanceId, 'HeizungRenderer'),
        'RENDER_JALOUSIE'     => iah_find_script_by_name($instanceId, 'JalousieRenderer'),
        'RENDER_LICHT'        => iah_find_script_by_name($instanceId, 'LichtRenderer'),
        'RENDER_LUEFTUNG'     => iah_find_script_by_name($instanceId, 'LüftungRenderer'),
        'RENDER_GERAETE'      => iah_find_script_by_name($instanceId, 'GeraeteRenderer'),
        'RENDER_BEWAESSERUNG' => iah_find_script_by_name($instanceId, 'BewaesserungRenderer'),
        'RENDER_SETTINGS'     => iah_find_script_by_name($instanceId, 'EinstellungsRender'),
        'ROOMS_CATALOG'       => iah_get_child_object($settings, 'roomsCatalog', 'RoomsCatalog'),
        'NORMALIZER'          => iah_get_child_object($helper, 'normalizerScript', 'Normalizer'),
    ];

    $requiredVars = ['CoreHelpers', 'DeviceMap', 'RoomBuilderHelpers', 'DeviceMapWizard', 'Lexikon', 'DEVICE_MAP', 'PENDING_DEVICE', 'PENDING_STAGE', 'DOMAIN_FLAG', 'SKILL_ACTIVE'];
    $requiredScripts = ['ROOMS_CATALOG', 'NORMALIZER'];
    $missing = [];

    foreach ($requiredVars as $key) {
        $val = $var[$key] ?? 0;
        if ((int) $val === 0) {
            $missing[] = $key;
        }
    }

    foreach ($requiredScripts as $key) {
        $val = $scripts[$key] ?? 0;
        if ((int) $val === 0) {
            $missing[] = $key;
        }
    }

    return ['var' => $var, 'script' => $scripts, 'missing' => $missing];
}

function Execute($request = null)
{
    // --- Konstanten für Wizard-Status ---
    $STAGE_AWAIT_NAME = 'await_name';
    $STAGE_AWAIT_APL  = 'await_apl';

    try {
        $JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ($request === null) {
            IPS_LogMessage('Alexa', 'Script manuell ausgeführt – kein Request vorhanden. Beende ohne Fehler.');
            return;
        }

        // --------- Config laden ---------
        $instanceId = iah_get_instance_id();
        $CFG = iah_build_system_configuration($instanceId);
        if (!empty($CFG['missing'])) {
            return TellResponse::CreatePlainText('Fehler: Folgende IDs fehlen oder konnten nicht erzeugt werden: ' . implode(', ', $CFG['missing']));
        }

        $V = $CFG['var'];
        $S = $CFG['script'];
        $baseUrl = (string)($V['BaseUrl'] ?? '');
        $token   = (string)($V['Token']   ?? '');
        $source  = (string)($V['Source']  ?? '');

        // --- Core-Helper laden (applyApl/makeCard/matchDomain/…)
        if (!isset($V['CoreHelpers']) || (int)$V['CoreHelpers'] === 0) {
            return TellResponse::CreatePlainText('Fehler: Die ID für "CoreHelpers" (Helfer-Skript) ist nicht in der Konfiguration gesetzt. (var.CoreHelpers)');
        }

        $CORE = require IPS_GetScriptFile((int)$V['CoreHelpers']);
        $applyApl       = $CORE['applyApl'];
        $makeCard       = $CORE['makeCard'];
        $maskToken      = $CORE['maskToken'];
        $matchDomain    = $CORE['matchDomain'];
        $resetState     = $CORE['resetState'];
        $getDomainPref  = $CORE['getDomainPref'];
        $mkCid          = $CORE['mkCid'];
        $logExt         = $CORE['logExt'];
        $getSlotCH      = $CORE['getSlot'];
        $parseAplArgs   = $CORE['parseAplArgs'];
        $roomSpokenCH   = $CORE['room_key_by_spoken'];
        $domainFromAPL  = $CORE['domainFromAplArgs'];
        $buildTabsCache = $CORE['buildTabsCache'];
        $findTabIdCH    = $CORE['findTabId'];
        $fallbackTabCH  = $CORE['fallbackTabDomain'];
        $extractNumber  = $CORE['extractNumberOnly'];
        $mergeDecimal   = $CORE['maybeMergeDecimalFromPercent'];

        // Helfer-Skripte
        $DM_HELPERS = require IPS_GetScriptFile((int)$V['DeviceMap']);
        $RBUILDER = require IPS_GetScriptFile((int)$V['RoomBuilderHelpers']);
        $WZ = require IPS_GetScriptFile((int)$V['DeviceMapWizard']);

        // --------- Lexikon & Normalizer ---------
        $LEX  = require IPS_GetScriptFile($V['Lexikon']);
        $PAT  = $LEX['patterns'];

        $NORM = require IPS_GetScriptFile($S['NORMALIZER']);
        $lc                  = $NORM['lc'];
        $normalizeRoom       = $NORM['room_slug'];
        $token_norm          = $NORM['token_norm'];
        $normalize_action    = $NORM['action'];
        $normalize_decimal_words = static function (string $s) use ($NORM, $PAT) {return $NORM['decimal_words']($s, $PAT['decimal_words']);};

        // --------- Config laden Ende ---------

        // --------- Logging Wrapper ---------
        $LOG_LEVEL = (string)($V['LOG_LEVEL'] ?? 'info');
        $rank = ['off'=>0,'warn'=>1,'info'=>2,'debug'=>3];
        $log = static function(string $lvl, string $msg, $ctx=null) use ($LOG_LEVEL,$rank,$JSON) {
            if (($rank[$lvl]??2) <= ($rank[$LOG_LEVEL]??2)) {
                IPS_LogMessage('Alexa', $msg . ($ctx!==null?(' '.json_encode($ctx,$JSON)):''));
            }
        };

        $log('debug', 'SupportedInterfaces', array_keys((array)($request->context->System->device->supportedInterfaces ?? [])));
        $intentName = method_exists($request,'GetIntentName') ? (string)$request->GetIntentName() : '';
        $log('debug','Intent', $intentName);

        // --------- ActionsEnabled ---------
        $IDS  = (array)($V['ActionsEnabled'] ?? []);
        $read = static function ($id) { return $id ? (bool)GetValue($id) : false; };
        $ACTIONS_ENABLED = [
            'heizung'       => ['stellen_aendern' => $read($IDS['heizung_stellen']      ?? 0)],
            'jalousie'      => ['steuern'         => $read($IDS['jalousie_steuern']     ?? 0)],
            'licht'         => ['switches'        => $read($IDS['licht_switches']       ?? 0), 'dimmers'         => $read($IDS['licht_dimmers']        ?? 0)],
            'lueftung'      => ['toggle'          => $read($IDS['lueft_stellen']        ?? 0)],
            'geraete'       => ['toggle'          => $read($IDS['geraete_toggle']       ?? 0)],
            'bewaesserung'  => ['toggle'          => $read($IDS['bewaesserung_toggle']  ?? 0)],
        ];
        $log('debug','AE(main)', $ACTIONS_ENABLED);

        // --------- RoomsCatalog ---------
        $ROOMS = require IPS_GetScriptFile($S['ROOMS_CATALOG']);
        if (!is_array($ROOMS) || $ROOMS === []) {
            return TellResponse::CreatePlainText('Fehler: RoomsCatalog leer oder ungültig.');
        }

        // ---------- Frühe Slots (für Exit) ----------
        $action1  = $lc($getSlotCH($request,'Action')  ?? '');
        $szene1   = $lc($getSlotCH($request,'Szene')   ?? '');
        $device1  = $lc($getSlotCH($request,'Device')  ?? '');
        $room1    = $lc($getSlotCH($request,'Room')    ?? '');
        $object1  = $lc($getSlotCH($request,'Object')  ?? '');
        $number1  = $getSlotCH($request,'Number')      ?? null;
        $prozent1 = $getSlotCH($request,'Prozent')     ?? null;
        $alles1   = $lc($getSlotCH($request,'Alles')   ?? '');

        $log('debug','RawSlots', ["a"=>$action1,"s"=>$szene1,"d"=>$device1,"r"=>$room1,"o"=>$object1,"n"=>$number1,"p"=>$prozent1,"al"=>$alles1]);

        // ---------- Exit ----------
        $wantsExit = in_array($action1, ['ende','fertig','exit'], true) ||
            ($action1 === 'ende' && $device1 === '' && $room1 === '' && $object1 === '' && $szene1 === '' && $alles1 === '' &&
            ($number1 === null || $number1 === '' || $number1 === '?') && ($prozent1 === null || $prozent1 === '' || $prozent1 === '?'));
        if ($wantsExit) {
            $resetState($V);
            SetValueBoolean($V['SKILL_ACTIVE'], false);
            return TellResponse::CreatePlainText('ok, bis bald!');
        }

        // ---------- Launch/Zurück ----------
        $IS_LAUNCH = $request->IsLaunchRequest();
        $IS_BACK   = ($lc($getSlotCH($request,'Action') ?? '') === 'zurück');
        if ($IS_LAUNCH || $IS_BACK) { $resetState($V); }

        // ---------- APL_ARGS zentral ----------
        $APL = $parseAplArgs($request);
        $log('debug','APL_ARGS', $APL['args']);

        // ============================================================
        // FRÜHER, HARTER UI-OVERRIDE (Buttons aus der Main-Ansicht)
        // ============================================================
        $navForce = false;
        $forcedDomain = null;
        $forcedDevice = null;

        if (is_array($APL['args']) && count($APL['args']) >= 2 && (string)$APL['args'][0] === 'GetHaus') {
            $navId  = $lc((string)($APL['a1'] ?? ''));
            $navId2 = $lc((string)($APL['a2'] ?? ''));
            if ($navId === 'home' && $navId2 !== '') { $navId = $navId2; }

            $NAV_MAP = [
                'licht'          => ['domain' => 'licht',         'device' => 'licht'],
                'jalousie'       => ['domain' => 'jalousie',      'device' => 'jalousie'],
                'heizung'        => ['domain' => 'heizung',       'device' => 'temperatur'],
                'lueftung'       => ['domain' => 'lueftung',      'device' => 'lueftung'],
                'einstellungen'  => ['domain' => 'einstellungen', 'device' => 'einstellungen'],
                'bewaesserung'   => ['domain' => 'bewaesserung',  'device' => 'bewaesserung'],
                'geraete'        => ['domain' => 'geraete',       'device' => 'geraete'],
                'sicherheit'     => ['domain' => 'sicherheit',    'device' => 'sicherheit'],
                'listen'         => ['domain' => 'listen',        'device' => 'listen'],
                'energie'        => ['domain' => 'energie',       'device' => 'energie'],
                'kamera'         => ['domain' => 'kamera',        'device' => 'kamera'],
                'info'           => ['domain' => 'info',          'device' => 'info'],
                'szene'          => ['domain' => 'szene',         'device' => 'szene'],
            ];

            if (isset($NAV_MAP[$navId])) {
                $forcedDomain = $NAV_MAP[$navId]['domain'];
                $forcedDevice = $NAV_MAP[$navId]['device'];
                $navForce     = true;
                SetValueString($V['DOMAIN_FLAG'], $forcedDomain);
                IPS_LogMessage('Alexa', 'UI_FORCE → navId=' . $navId . ' / domain=' . $forcedDomain . ' / device=' . $forcedDevice);
            }
        }

        // --------- HTML.Message → nur Route setzen ---------
        $__route = null;
        if (($request->type ?? '') === 'Alexa.Presentation.HTML.Message') {
            $msg = (array)($request->message ?? []);
            if (($msg['type'] ?? null) === 'back') {
                $__route = 'main_launch';
            }
        }

        // --------- External Pages → nur Route markieren ---------
        $EP = (array)($ROOMS['global']['external_pages'] ?? []);
        $nav = $APL['a1'] ?? '';
        $selected = null;
        foreach ($EP as $key => $cfg) {
            $actions = (array)($cfg['actions'] ?? []);
            $navs    = (array)($cfg['navs'] ?? []);
            $hit = in_array($action1,$actions,true) || in_array((string)$nav,$navs,true) || ($navForce && $forcedDomain===$key);
            if ($hit) { $selected = $key; break; }
        }
        if ($selected !== null) { $__route = 'external'; }

        // --------- Hauptslots (zweite Runde) ---------
        $deviceId = $request->GetDeviceId();
        $action   = $lc($getSlotCH($request,'Action') ?? '');
        $szene    = $lc($getSlotCH($request,'Szene')  ?? '');
        $device   = $lc($getSlotCH($request,'Device') ?? '');
        $room     = $lc($getSlotCH($request,'Room')   ?? '');

        // Etagen normalisieren
        $room = preg_replace('/(*UTF8)(*UCP)(?<!\pL)(untergeschoss|ug)(?!\pL)/u', 'keller', $room);
        $room = preg_replace('/(*UTF8)(*UCP)(?<!\pL)obergeschoss(?!\pL)/u', 'og', $room);
        $room = preg_replace('/(*UTF8)(*UCP)(?<!\pL)erdgeschoss(?!\pL)/u', 'eg', $room);
        $room = trim(preg_replace('/\s{2,}/u', ' ', $room));

        $object  = $lc($getSlotCH($request,'Object') ?? '');
        $number  = $getSlotCH($request,'Number') ?? null;
        $prozent = $getSlotCH($request,'Prozent') ?? null;
        $alles   = $lc($getSlotCH($request,'Alles')  ?? '');

        // Falls UI-Override aktiv: Device & Domain hart setzen
        $domain = null;
        if ($navForce) {
            $domain = $forcedDomain;
            $device = $forcedDevice;
        }

        if (in_array($action, ['ende','fertig','exit','zurück'], true)) {
            SetValueString($V['DOMAIN_FLAG'], "");
        }

        // --------- Device-Map Wizard Flow (ausgelagert) ---------
        $resp = $WZ['handle_wizard'](
            $V,
            $intentName,
            (string)$action,
            (string)$alles,
            (string)$room,
            (string)$device,
            $DM_HELPERS,
            $STAGE_AWAIT_NAME,
            $STAGE_AWAIT_APL
        );
        if ($resp !== null) {
            return $resp;
        }

        // --------- Room/Number/Action Normalisierung ---------
        $room_raw = $room;
        [$room, $powerHint] = $NORM['extract_power_from_room']($room);
        $room_candidates_text = trim(($room ? $room . ' ' : '') . $object . ' ' . $action . ' ' . $alles . ' ' . $device);
        $room = $roomSpokenCH($ROOMS, $room, $token_norm) ?? $roomSpokenCH($ROOMS, $room_candidates_text, $token_norm) ?? '';

        if (!$navForce) {
            $device = preg_replace('/(*UTF8)(*UCP)\s+\d+(?:[.,]\d+)?$/u', '', $device);
            $device = trim(preg_replace('/\s{2,}/u', ' ', $device));
            $device = preg_replace('/(*UTF8)(*UCP)(?<!\pL)heizung(?!\pL)/u', 'temperatur', $device);
            if (preg_match('/(*UTF8)(*UCP)(?<!\pL)temperatur(?!\pL)/u', $device)) $device = 'temperatur';
        }

        // Nummer/Prozent extrahieren
        [$number, $target]  = $extractNumber($action, $device, $alles, $room, $object, $szene, $number, $normalize_decimal_words, $PAT);
        [$number, $prozent] = $mergeDecimal($number, $prozent, $action, $device, $alles);
        if ($number !== null && $number !== '' && $number !== '?') $target = (float)str_replace(',', '.', (string)$number);

        $action = $normalize_action($action, $device, $alles, $object, $room);
        $power  = $NORM['power_from_tokens']($action, $device, $alles, $object, $room, $powerHint);
        if (!$power) { if (in_array($action, ['ein','an'], true)) $power = 'on'; elseif ($action === 'aus') $power = 'off'; }

        // APL args1/args2/args3 (nur Info / leichte Korrekturen)
        if ($APL['a1'] !== null) {
            IPS_LogMessage('Alexa', "APL entity args1 parsed → " . $APL['a1']);
            if (is_numeric($APL['a1']))       { $number = $APL['a1']; }
            else if ($lc((string)$APL['a1']) !== 'zurück') { $action = $lc((string)$APL['a1']) ?: $action; }
        }

        // --- Domain aus APL-Args ableiten ---
        if ($domain === null) {
            $d = $domainFromAPL($APL, $lc);
            if ($d !== null) {
                $domain = $d;
                if (!$navForce && $device === '') {
                    $device = ($domain === 'heizung') ? 'temperatur' : $domain;
                }
            }
        }

        // --------- Tabs-Matching + Fallback (über CoreHelpers) ---------
        $findTabIdWithCache = static function(string $spoken) use ($buildTabsCache, $findTabIdCH, $ROOMS, $token_norm) : ?string {
            $cache = $buildTabsCache($ROOMS);
            return $findTabIdCH($spoken, $cache, $token_norm);
        };
        $loggerForFallback = static function(string $lvl, string $msg, $ctx=null) use ($log) { $log($lvl,$msg,$ctx); };

        // Globaler Geräte-Tab-Fallback
        if ($domain === null && !$navForce) {
            $fallbackTabCH($domain, $device, 'geraete', $action, $device, $object, $alles, $findTabIdWithCache, $loggerForFallback);
        }
        // Globaler Bewässerung-Tab-Fallback
        if ($domain === null && !$navForce) {
            $fallbackTabCH($domain, $device, 'bewaesserung', $action, $device, $object, $alles, $findTabIdWithCache, $loggerForFallback);
        }

        // --- Fallback: DOMAIN_FLAG als Präferenz
        if ($domain === null) {
            $pref = $getDomainPref($V);
            if ($pref !== '') {
                $domain = $pref;
                if (!$navForce && $device === '') {
                    $device = ($pref === 'heizung') ? 'temperatur' : $pref;
                }
            }
        }

        // Dimmer-Ereignis
        $domainPrefNow = $getDomainPref($V);
        $aplDimmerEvent = (is_numeric($APL['a1'])) && (
            (is_string($APL['a2']) && stripos($APL['a2'], 'licht.') === 0) ||
            (is_string($APL['a2']) && preg_match('/(*UTF8)(*UCP)(?<!\pL)licht(?!\pL)/u', $APL['a2']) === 1) ||
            ($domainPrefNow === 'licht')
        );
        if ($aplDimmerEvent && !$navForce) { $device = 'licht'; $action = ''; }

        // Geräte/Bewässerung: Domain-Erzwingung durch APL-Events
        if ($domain === null && is_string($APL['a1']) && str_starts_with((string)$APL['a1'], 'geraete.')) {
            $domain = 'geraete';
        }
        if (($APL['a1'] ?? null) === 'geraete.setEnum' && is_numeric($APL['a2'] ?? null) && is_numeric($APL['a3'] ?? null)) {
            RequestAction((int)$APL['a2'], (int)$APL['a3']);
        }
        if ($domain === null && is_string($APL['a1']) && str_starts_with((string)$APL['a1'], 'bewaesserung.')) {
            $domain = 'bewaesserung';
        }
        if (($APL['a1'] ?? null) === 'bewaesserung.setEnum' && is_numeric($APL['a2'] ?? null) && is_numeric($APL['a3'] ?? null)) {
            RequestAction((int)$APL['a2'], (int)$APL['a3']);
        }

        // --------- Domain-Autodetect (nur wenn nicht navForce) ---------
        if ($domain === null && !$navForce) {
            $domain = $matchDomain($action, $device, $alles, $room);
        }
        $log('debug','domain', ($domain ?? '(auto)'));

        // --------- Außentemperatur-Shortcut ---------
        $AUSSEN_ALIASES = ['außentemperatur','aussentemperatur'];
        if (in_array($action,$AUSSEN_ALIASES,true) || in_array($device,$AUSSEN_ALIASES,true) ||
            in_array($object,$AUSSEN_ALIASES,true) || in_array($alles,$AUSSEN_ALIASES,true)) {
            $text = 'Die Außentemperatur beträgt ' . GetValueFormatted($V['AUSSEN_TEMP']);
            $img  = 'https://media.istockphoto.com/id/1323823418/de/foto/niedrigwinkelansicht-thermometer-am-blauen-himmel-mit-sonnenschein.jpg?s=612x612&w=0&k=20&c=iFJaAAxJ_chcBz5Bnjy20HSlULU7AWIW16d_bwlB0Ss=';
            $resetState($V);
            return AskResponse::CreatePlainText($text)
                ->SetStandardCard($text, $text, $img, $img)
                ->SetRepromptPlainText('möchtest du zu Heizung oder zurück? - oder soll ich noch eine Temperatur ansagen?');
        }

        // --------- Device-Map Pflege ---------
        [$alexa, $aplSupported, $isNewDevice] = $DM_HELPERS['ensure']($V['DEVICE_MAP'], (string)$deviceId);
        IPS_LogMessage("Alexa", 'Alexa: ' . $alexa);
        IPS_LogMessage("Alexa", 'APL Supported: ' . ($aplSupported ? 'true' : 'false'));

        if (($APL['a1'] ?? null) === 'delete') {
            $map = $DM_HELPERS['load']($V['DEVICE_MAP']); $deleted = false;
            if ($alexa !== '') {
                $alexaLc = mb_strtolower($alexa, 'UTF-8');
                foreach ($map as $k => $entry) {
                    $locLc = mb_strtolower((string)($entry['location'] ?? ''), 'UTF-8');
                    if ($locLc !== '' && $locLc === $alexaLc) { unset($map[$k]); $deleted = true; }
                }
            }
            if (!$deleted && isset($map[$deviceId])) { unset($map[$deviceId]); $deleted = true; }
            $DM_HELPERS['save']($V['DEVICE_MAP'], $map);
            $msg = $deleted ? ('Eintrag „' . $alexa . '“ wurde gelöscht.') : 'Kein passender Eintrag gefunden.';
            return AskResponse::CreatePlainText($msg)->SetRepromptPlainText('Was möchtest du als Nächstes?');
        }

        if ($isNewDevice && ($request->IsLaunchRequest() || $action === '')) {
            SetValueString($V['PENDING_DEVICE'], (string)$deviceId);
            SetValueString($V['PENDING_STAGE'], $STAGE_AWAIT_NAME);
            $msg = 'Neues Gerät erkannt. Wie soll ich das Gerät nennen?';
            return AskResponse::CreatePlainText($msg)->SetRepromptPlainText('Sag mir einen Namen, zum Beispiel Küche oder Wohnzimmer.');
        }

        $alexaKey = $roomSpokenCH($ROOMS, $alexa, $token_norm) ?? $normalizeRoom($alexa);
        if (!isset($ROOMS[$alexaKey])) {
            $fallback = isset($ROOMS['wohnzimmer']) ? 'wohnzimmer' : array_key_first($ROOMS);
            IPS_LogMessage('Alexa', "alexaKey '".($alexaKey ?: '(leer)')."' nicht gefunden – Fallback: '$fallback'");
            $alexaKey = $fallback;
        }

        if ($action === 'wer bist du') {
            return AskResponse::CreatePlainText('Ich bin die Alexa für ' . $alexa)->SetRepromptPlainText('wie kann ich helfen?');
        }

        // ===== Tabellen-Router =====
        $ROUTES = [
            'main'         => fn()=> $request->IsLaunchRequest() || ($action==='zurück' && !is_numeric($APL['a1'])) || ($APL['a1']==='zurück'),
            'main_home'    => fn()=> is_array($APL['args']) && (($APL['args'][0]??'')==='GetHaus') && (($APL['args'][1]??'')==='home'),
            'heizung'      => fn()=> $domain==='heizung' || $device==='heizung' || $device==='temperatur',
            'jalousie'     => fn()=> $domain==='jalousie' || $device==='jalousie',
            'licht'        => fn()=> $domain==='licht'    || $device==='licht',
            'lueftung'     => fn()=> $domain==='lueftung' || $device==='lueftung',
            'geraete'      => fn()=> $domain==='geraete'  || $device==='geraete'  || (is_string($APL['a1']) && str_starts_with((string)$APL['a1'],'geraete.')),
            'bewaesserung' => fn()=> $domain==='bewaesserung' || $device==='bewaesserung' || (is_string($APL['a1']) && str_starts_with((string)$APL['a1'],'bewaesserung.')),
            'external'     => fn()=> isset($selected),
            'settings'     => fn()=> $domain==='einstellungen' || $device==='einstellungen',
        ];

        // Route bestimmen
        $__route = $__route ?? null;
        foreach ($ROUTES as $r=>$cond) {
            if ($cond()) { $__route = ($r==='main' || $r==='main_home') ? 'main_launch' : $r; break; }
        }

        // --------- Lazy rooms nur wenn benötigt ---------
        $needsRooms = in_array($__route ?? '', ['main_launch','heizung','jalousie','licht','lueftung','geraete','bewaesserung'], true);
        $rooms = [];
        if ($needsRooms) {
            if (isset($RBUILDER['build_rooms_status']) && is_callable($RBUILDER['build_rooms_status'])) {
                $rooms = $RBUILDER['build_rooms_status']($ROOMS);
            }
        }

        // --------- ROUTE_ALL Call ---------
        if ($__route !== null) {
            // APL override aus Tab-Fallback übernehmen (falls gesetzt)
            $aplArgs = is_array($APL['args']) ? array_values($APL['args']) : [];
            if (isset($GLOBALS['_APL_OVERRIDE'])) {
                $aplArgs[1] = $GLOBALS['_APL_OVERRIDE'][0] ?? ($aplArgs[1] ?? null);
                $aplArgs[2] = $GLOBALS['_APL_OVERRIDE'][1] ?? ($aplArgs[2] ?? null);
            }

            $payload = [
                'route'           => $__route,
                'S'               => [
                    'RENDER_MAIN'         => $S['RENDER_MAIN'],
                    'RENDER_HEIZUNG'      => $S['RENDER_HEIZUNG'],
                    'RENDER_JALOUSIE'     => $S['RENDER_JALOUSIE'],
                    'RENDER_LICHT'        => $S['RENDER_LICHT'],
                    'RENDER_LUEFTUNG'     => $S['RENDER_LUEFTUNG'],
                    'RENDER_GERAETE'      => $S['RENDER_GERAETE'],
                    'RENDER_BEWAESSERUNG' => $S['RENDER_BEWAESSERUNG'],
                    'RENDER_SETTINGS'     => $S['RENDER_SETTINGS'],
                ],
                'V'               => [
                    'AUSSEN_TEMP'   => $V['AUSSEN_TEMP'],
                    'INFORMATION'   => $V['INFORMATION'],
                    'MELDUNGEN'     => $V['MELDUNGEN'],
                    'DOMAIN_FLAG'   => $V['DOMAIN_FLAG'],
                    'SKILL_ACTIVE'  => $V['SKILL_ACTIVE'],
                    'Passwort'      => $V['Passwort'],
                    'StartPage'     => $V['StartPage'],
                    'WfcId'         => $V['WfcId'],
                    'EnergiePageId' => $V['EnergiePageId'],
                    'KameraPageId'  => $V['KameraPageId'],
                    'InstanceID'    => $instanceId,
                    'externalKey'   => $selected ?? null,
                ],
                'rooms'           => $rooms,
                'ROOMS'           => $ROOMS,
                'ACTIONS_ENABLED' => $ACTIONS_ENABLED,
                'args1v'          => $APL['a1'],
                'args2v'          => $APL['a2'],
                'args3v'          => $APL['a3'],
                'aplSupported'    => (bool)($aplSupported ?? false),
                'action'          => (string)($action ?? ''),
                'device'          => (string)($device ?? ''),
                'room'            => (string)($room ?? ''),
                'object'          => (string)($object ?? ''),
                'alles'           => (string)($alles ?? ''),
                'number'          => $number,
                'prozent'         => $prozent,
                'power'           => $power,
                'aplArgs'         => $aplArgs,
                'skillActive'     => (bool)GetValueBoolean($V['SKILL_ACTIVE']),
                'alexaKey'        => (string)$alexaKey,
                'alexa'           => (string)$alexa,
                'baseUrl'         => (string)$baseUrl,
                'source'          => (string)$source,
                'token'           => (string)$token,
                'room_raw'        => (string)$room_raw,
                'szene'           => (string)$szene,
                'CFG'             => $CFG,
            ];

            $res = json_decode(IPS_RunScriptWaitEx($S['ROUTE_ALL'], ['payload'=>json_encode($payload, $JSON)]), true);

            if (!is_array($res) || empty($res['ok'])) {
                return TellResponse::CreatePlainText('Fehler beim Rendern.');
            }

            if (array_key_exists('setDomainFlag', (array)($res['flags'] ?? []))) {
                $val = $res['flags']['setDomainFlag'];
                if ($val === '') { SetValueString($V['DOMAIN_FLAG'], ""); }
                elseif (is_string($val)) { SetValueString($V['DOMAIN_FLAG'], $val); }
            }
            if (array_key_exists('setSkillActive', (array)($res['flags'] ?? []))) {
                $s = $res['flags']['setSkillActive'];
                if ($s === true)  { SetValueBoolean($V['SKILL_ACTIVE'], true); }
                if ($s === false) { SetValueBoolean($V['SKILL_ACTIVE'], false); }
            }

            $data       = (array)$res['data'];
            $tokenApl   = (string)($res['aplToken'] ?? 'hv-main');
            $endSession = !empty($data['endSession']);
            $speech     = (string)($data['speech'] ?? '');
            $reprompt   = (string)($data['reprompt'] ?? '');
            $r = $endSession
                ? TellResponse::CreatePlainText($speech)
                : AskResponse::CreatePlainText($speech)->SetRepromptPlainText($reprompt);
            $makeCard($r, $data['card'] ?? []);
            $applyApl($r, $data['apl'] ?? [], $tokenApl);

            if (!empty($data['directives']) && is_array($data['directives'])) {
                foreach ($data['directives'] as $d) {
                    $r->AddDirective($d);
                }
            }
            return $r;
        }

        // --------- Fallback ---------
        if (($APL['a2'] ?? null) != null) {
            return AskResponse::CreatePlainText($APL['a2'] . " ausgewählt")->SetRepromptPlainText('');
        }
        $resetState($V);
        return AskResponse::CreatePlainText('Konnte kein Gerät verstehen. Wie bitte?')->SetRepromptPlainText('Wie bitte?');

    } catch (Exception $e) {
        IPS_LogMessage('Alexa', "Fehler: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return TellResponse::CreatePlainText('Ein Fehler ist aufgetreten! Andreas sagen!');
    } finally {
        IPS_LogMessage('Alexa', "Ende");
    }
}
