<?php
declare(strict_types=1);

/**
 * ============================================================
 * KI INTENT PARSER — OpenAI-basierte Anfrage-Analyse
 * ============================================================
 *
 * Änderungsverlauf
 * 2025-12-02: v1.9 — SystemConfiguration rekursiv unterhalb des Projekt-Roots suchen
 * 2025-12-02: v1.8 — Konfiguration ausschließlich über SystemConfiguration, Logging fix auf Kanal "Alexa"
 * 2025-12-02: v1.7 — SystemConfiguration-ID automatisch über Parent-/Child-Hierarchie ermitteln (Fallback: Konstante)
 * 2025-12-02: v1.6 — Konfiguration aus SystemConfiguration (var.KIIntentParser) laden
 * 2025-11-24: v1.5 — Stabiler Rate-Limit / Loop-Schutz über Hilfs-Variable (funktioniert auch bei "Ausführen")
 * 2025-11-24: v1.4 — Rate-Limit zentral in ki_parse_intent (gilt auch bei Testmodus)
 * 2025-11-24: v1.3 — Rate-Limit nur über LastExecute des Skripts (keine Zusatzvariable)
 * 2025-11-24: v1.2 — Rate-Limit / Loop-Schutz (max. 1 Ausführung pro X Sekunden)
 * 2025-11-24: v1.1 — Testmodus bei Direkt-Ausführung im Editor
 * 2025-11-24: v1   — Erste Version (OpenAI-Chat + JSON-Intent-Parser)
 */

/**
 * Hilfs-Logging, läuft in IP-Symcon über IPS_LogMessage (Kanal "Alexa"), sonst error_log.
 */
function ki_log(string $message): void
{
    if (function_exists('IPS_LogMessage')) {
        IPS_LogMessage('Alexa', $message);
    } else {
        error_log('[Alexa] ' . $message);
    }
}

/**
 * Rekursive Suche nach einem Objekt mit bestimmtem Namen unterhalb einer Wurzel-ID.
 */
function ki_find_child_recursive(int $rootId, string $name): int
{
    $children = IPS_GetChildrenIDs($rootId);
    foreach ($children as $childId) {
        if (IPS_GetName($childId) === $name) {
            return $childId;
        }
        $found = ki_find_child_recursive($childId, $name);
        if ($found > 0) {
            return $found;
        }
    }
    return 0;
}

/**
 * Versucht, die SystemConfiguration-Script-ID automatisch zu finden.
 * Strategie:
 * - Start bei diesem Skript (SELF)
 * - Nach oben laufen (Parent), bis zum Projekt-Root
 * - Für jeden Parent: rekursiv nach einem Kind mit Namen "SystemConfiguration" suchen
 * - Beim ersten Treffer ID zurückgeben
 */
function ki_find_system_configuration_script_id(): int
{
    if (!function_exists('IPS_GetChildrenIDs')) {
        return 0;
    }

    global $_IPS;
    if (!isset($_IPS['SELF'])) {
        return 0;
    }

    $currentId = (int)$_IPS['SELF'];
    if ($currentId <= 0) {
        return 0;
    }

    $parentId = IPS_GetParent($currentId);
    while ($parentId > 0) {
        $found = ki_find_child_recursive($parentId, 'SystemConfiguration');
        if ($found > 0) {
            ki_log('SystemConfiguration gefunden (ID=' . $found . ').');
            return $found;
        }
        $parentId = IPS_GetParent($parentId);
    }

    ki_log('SystemConfiguration wurde in der Parent-Hierarchie nicht gefunden.');
    return 0;
}

/**
 * Lädt das KIIntentParser-Config-Array aus der SystemConfiguration (var.KIIntentParser).
 * Wenn SystemConfiguration oder der Eintrag fehlt, wird der Parser deaktiviert (enabled=false).
 */
function ki_load_cfg_from_system_configuration(): array
{
    if (!function_exists('IPS_GetKernelDir')) {
        ki_log('IP-Symcon-Umgebung nicht verfügbar, KIIntentParser wird deaktiviert.');
        return ['enabled' => false];
    }

    $systemConfigId = ki_find_system_configuration_script_id();
    if ($systemConfigId <= 0) {
        ki_log('Konnte SystemConfiguration-Script nicht finden, KIIntentParser wird deaktiviert.');
        return ['enabled' => false];
    }

    $file = @IPS_GetScriptFile($systemConfigId);
    if ($file === false || $file === '') {
        ki_log('Konnte SystemConfiguration-Script-Datei nicht ermitteln, KIIntentParser wird deaktiviert.');
        return ['enabled' => false];
    }

    $path = IPS_GetKernelDir() . 'scripts/' . $file;
    $sc   = @include $path;

    if (!is_array($sc) || !isset($sc['var']) || !is_array($sc['var'])) {
        ki_log('SystemConfiguration hat unerwartetes Format, KIIntentParser wird deaktiviert.');
        return ['enabled' => false];
    }

    if (!isset($sc['var']['KIIntentParser']) || !is_array($sc['var']['KIIntentParser'])) {
        ki_log('SystemConfiguration[var]["KIIntentParser"] fehlt, KIIntentParser wird deaktiviert.');
        return ['enabled' => false];
    }

    ki_log('KIIntentParser-Konfiguration erfolgreich aus SystemConfiguration geladen.');
    return $sc['var']['KIIntentParser'];
}

/**
 * Lädt den produktiven RoomsCatalog aus dem Helper-Verzeichnis.
 *
 * Erlaubt optional einen Override über $cfg['rooms_catalog_path'].
 */
function ki_load_rooms_catalog(array $cfg): array
{
    static $cachedCatalog = null;

    if ($cachedCatalog !== null) {
        return $cachedCatalog;
    }

    $path = (string)($cfg['rooms_catalog_path'] ?? (__DIR__ . '/RoomsCatalog.php'));

    if (!is_file($path)) {
        ki_log('RoomsCatalog wurde nicht gefunden unter ' . $path);
        $cachedCatalog = [];
        return $cachedCatalog;
    }

    $roomsCatalog = @include $path;
    if (!is_array($roomsCatalog)) {
        ki_log('RoomsCatalog hat ein unerwartetes Format.');
        $cachedCatalog = [];
        return $cachedCatalog;
    }

    ki_log('RoomsCatalog erfolgreich geladen (' . count($roomsCatalog) . ' Räume inklusive global).');
    $cachedCatalog = $roomsCatalog;
    return $roomsCatalog;
}

/**
 * Baue einen kompakten NLU-Context aus dem produktiven RoomsCatalog.
 *
 * Ziel: möglichst wenig Tokens für die KI verbrauchen, aber trotzdem
 *       dynamisch alle aktuell bekannten Räume / Geräte / Szenen abbilden.
 *
 * @param array $roomsCatalog Voller produktiver RoomsCatalog
 * @param array $opts         Optionale Einstellungen:
 *                            - 'limitRooms'   => string[]|null  (nur diese Room-Keys berücksichtigen)
 *                            - 'devices'      => string[]|null  (Device-Liste explizit setzen)
 *                            - 'includeScenes'=> bool           (default: true)
 *
 * @return array{
 *   rooms: string[],
 *   devices: string[],
 *   scenes: string[]
 * }
 */
function ki_build_nlu_context(array $roomsCatalog, array $opts = []): array
{
    $rooms = [];

    foreach ($roomsCatalog as $roomKey => $roomDef) {
        if (!is_array($roomDef)) {
            continue;
        }

        if ($roomKey === 'global') {
            continue;
        }

        if (isset($opts['limitRooms']) && is_array($opts['limitRooms']) && !in_array($roomKey, $opts['limitRooms'], true)) {
            continue;
        }

        $label = $roomDef['display'] ?? $roomKey;
        if (!is_string($label) || $label === '') {
            $label = (string)$roomKey;
        }

        $rooms[] = $label;
    }

    $rooms = array_values(array_unique($rooms, SORT_STRING));
    sort($rooms, SORT_NATURAL | SORT_FLAG_CASE);

    if (isset($opts['devices']) && is_array($opts['devices']) && $opts['devices'] !== []) {
        $devices = array_values(array_unique(array_map('strval', $opts['devices']), SORT_STRING));
    } else {
        $deviceSet = [];

        if (isset($roomsCatalog['global']) && is_array($roomsCatalog['global'])) {
            foreach ($roomsCatalog['global'] as $domainKey => $domainDef) {
                if (!is_string($domainKey) || $domainKey === '') {
                    continue;
                }
                $deviceSet[$domainKey] = true;
            }
        }

        foreach ($roomsCatalog as $roomKey => $roomDef) {
            if (!is_array($roomDef)) {
                continue;
            }
            if (!isset($roomDef['domains']) || !is_array($roomDef['domains'])) {
                continue;
            }

            foreach ($roomDef['domains'] as $domainKey => $_domainDef) {
                if (!is_string($domainKey) || $domainKey === '') {
                    continue;
                }
                $deviceSet[$domainKey] = true;
            }
        }

        $devices = array_keys($deviceSet);
    }

    sort($devices, SORT_NATURAL | SORT_FLAG_CASE);

    $includeScenes = array_key_exists('includeScenes', $opts) ? (bool)$opts['includeScenes'] : true;
    $sceneSet = [];

    if ($includeScenes) {
        $collectScenes = static function ($sceneDef) use (&$sceneSet, &$collectScenes): void {
            if (is_array($sceneDef)) {
                if (isset($sceneDef['title']) && is_string($sceneDef['title']) && $sceneDef['title'] !== '') {
                    $sceneSet[$sceneDef['title']] = true;
                }
                foreach ($sceneDef as $value) {
                    if (is_array($value) || is_string($value)) {
                        $collectScenes($value);
                    }
                }
                return;
            }

            if (is_string($sceneDef) && $sceneDef !== '') {
                $sceneSet[$sceneDef] = true;
            }
        };

        if (isset($roomsCatalog['global']) && is_array($roomsCatalog['global'])) {
            foreach ($roomsCatalog['global'] as $domainDef) {
                if (!is_array($domainDef) || !isset($domainDef['scenes'])) {
                    continue;
                }
                $collectScenes($domainDef['scenes']);
            }
        }

        foreach ($roomsCatalog as $roomDef) {
            if (!is_array($roomDef) || !isset($roomDef['domains']) || !is_array($roomDef['domains'])) {
                continue;
            }
            foreach ($roomDef['domains'] as $domainDef) {
                if (!is_array($domainDef) || !isset($domainDef['scenes'])) {
                    continue;
                }
                $collectScenes($domainDef['scenes']);
            }
        }
    }

    $scenes = array_keys($sceneSet);
    sort($scenes, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'rooms'   => $rooms,
        'devices' => $devices,
        'scenes'  => $scenes,
    ];
}

/**
 * Erzeugt einen NLU-Kontext aus dem RoomsCatalog, falls verfügbar.
 */
function ki_build_rooms_context(array $cfg): array
{
    $roomsCatalog = ki_load_rooms_catalog($cfg);

    if ($roomsCatalog === []) {
        return [];
    }

    $context = ki_build_nlu_context($roomsCatalog, [
        'includeScenes' => true,
    ]);

    ki_log('NLU-Context erstellt: ' . count($context['rooms']) . ' Räume, ' . count($context['devices']) . ' Geräte-Typen, ' . count($context['scenes']) . ' Szenen.');

    return $context;
}

// Basis-Konfiguration aus SystemConfiguration laden.
$CFG = ki_load_cfg_from_system_configuration();

// Allow overrides via IPS_RunScriptWaitEx(['cfg' => [...]]).
if (isset($_IPS) && is_array($_IPS) && array_key_exists('cfg', $_IPS) && is_array($_IPS['cfg'])) {
    $CFG = array_merge($CFG, $_IPS['cfg']);
}

/**
 * API-Key aus Konfiguration holen.
 */
function ki_get_api_key(array $cfg): string
{
    if (!empty($cfg['api_key'])) {
        $key = trim((string)$cfg['api_key']);
        ki_log('API-Key aus CFG[api_key], Länge=' . strlen($key));
        return $key;
    }

    ki_log('Kein OpenAI API-Key konfiguriert (api_key leer).');
    return '';
}

/**
 * Rate-Limit / Loop-Schutz.
 */
function ki_rate_limiter_exceeded(array $cfg): bool
{
    if (!function_exists('IPS_CreateVariable')) {
        return false;
    }

    $minInterval = (int)($cfg['rate_limit_sec'] ?? 0);
    if ($minInterval <= 0) {
        return false;
    }

    global $_IPS;

    if (!isset($_IPS) || !is_array($_IPS) || !isset($_IPS['SELF'])) {
        return false;
    }

    $scriptId = (int)$_IPS['SELF'];
    if ($scriptId <= 0) {
        return false;
    }

    $parentId = IPS_GetParent($scriptId);
    if ($parentId <= 0) {
        return false;
    }

    $ident = 'KIIntent_LastRun';
    $varId = @IPS_GetObjectIDByIdent($ident, $parentId);
    if ($varId === false || $varId === 0) {
        $varId = IPS_CreateVariable(1);
        IPS_SetParent($varId, $parentId);
        IPS_SetName($varId, 'KI IntentParser - LastRun');
        IPS_SetIdent($varId, $ident);
        SetValueInteger($varId, 0);
        ki_log('Rate-Limit-Variable angelegt (ID=' . $varId . ').');
    }

    $last = GetValueInteger($varId);
    $now  = time();
    $diff = $last > 0 ? ($now - $last) : null;

    ki_log(
        'Rate-Limit-Check: last=' . $last .
        ', now=' . $now .
        ', diff=' . ($diff === null ? 'null' : $diff) .
        ', min=' . $minInterval
    );

    if ($last > 0 && $diff < $minInterval) {
        ki_log('Rate-Limit ausgelöst: letzter Aufruf vor ' . $diff . 's (min=' . $minInterval . 's). Request wird verworfen.');
        return true;
    }

    SetValueInteger($varId, $now);
    return false;
}

/**
 * Generischer Chat-Request an die OpenAI-API.
 */
function ki_chat_request(array $body, array $cfg): array
{
    $apiKey = ki_get_api_key($cfg);
    if ($apiKey === '') {
        ki_log('ABBRUCH: Kein API-Key -> Authorization-Header wird nicht gesetzt.');
        return [];
    }

    if (!isset($body['model'])) {
        $body['model'] = $cfg['model'] ?? 'gpt-4.1-mini';
    }

    $url = 'https://api.openai.com/v1/chat/completions';

    ki_log('API-Call startet: URL=' . $url . ', Modell=' . $body['model']);

    $ch = curl_init($url);
    if ($ch === false) {
        ki_log('cURL konnte nicht initialisiert werden.');
        return [];
    }

    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        ki_log('json_encode für Request fehlgeschlagen.');
        curl_close($ch);
        return [];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        ki_log('cURL-Fehler: ' . $err);
        return [];
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $len    = strlen($response);
    curl_close($ch);

    ki_log('API-Antwort erhalten: HTTP-Status=' . $status . ', Response-Länge=' . $len);

    if ($status < 200 || $status >= 300) {
        ki_log('OpenAI HTTP-Fehler ' . $status . ': ' . $response);
        return [];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        ki_log('json_decode für API-Antwort fehlgeschlagen.');
        return [];
    }

    ki_log('API-Antwort erfolgreich dekodiert.');

    return $data;
}

/**
 * Intenterkennung für Haussteuerungs-Anfragen.
 */
function ki_parse_intent(string $rawText, array $cfg): array
{
    if (isset($cfg['enabled']) && $cfg['enabled'] === false) {
        ki_log('KI Intent Parser ist deaktiviert, Anfrage wird ignoriert.');
        return [
            'action'       => null,
            'device'       => null,
            'room'         => null,
            'number'       => null,
            'raw'          => $rawText,
            'rate_limited' => false,
        ];
    }

    if (ki_rate_limiter_exceeded($cfg)) {
        return [
            'action'       => null,
            'device'       => null,
            'room'         => null,
            'number'       => null,
            'raw'          => $rawText,
            'rate_limited' => true,
        ];
    }

    $nluContext = ki_build_rooms_context($cfg);

    $contextDescription = '';
    if ($nluContext !== []) {
        $contextRooms   = $nluContext['rooms'];
        $contextDevices = $nluContext['devices'];
        $contextScenes  = $nluContext['scenes'];

        $contextDescription = '\n\nKontext aus RoomsCatalog (immer bevorzugt verwenden):\n' .
            '- Räume: ' . ($contextRooms === [] ? 'keine' : implode(', ', $contextRooms)) . "\n" .
            '- Geräte-Typen: ' . ($contextDevices === [] ? 'keine' : implode(', ', $contextDevices)) . "\n" .
            '- Szenen: ' . ($contextScenes === [] ? 'keine' : implode(', ', $contextScenes));
    }

    $systemPrompt = <<<PROMPT
Du bist ein Intent-Parser für eine Haussteuerung.

Analysiere eine deutsche Sprachanfrage und extrahiere:
- "action": Art der Aktion, z.B. "einschalten", "ausschalten", "dimmen", "stellen", "erhöhen", "verringern", "status".
- "device": Gerätetyp, z.B. "licht", "heizung", "jalousie", "jalousien", "szenen" usw.
- "room"  : Raumbezeichnung in einfacher Form, z.B. "wohnzimmer", "kueche", "buero".
- "number": Zahl (z.B. Prozent oder Grad) oder null, falls keine Zahl enthalten ist.

Antwort-Format:
{
  "action": "... oder null",
  "device": "... oder null",
  "room":   "... oder null",
  "number": 123 oder null
}

Wichtige Regeln:
- Wenn etwas nicht eindeutig ermittelbar ist, setze das Feld auf null.
- Antworte ausschließlich mit einem einzigen JSON-Objekt im oben beschriebenen Format.
$contextDescription
PROMPT;

    $body = [
        'model'           => $cfg['model'] ?? 'gpt-4.1-mini',
        'response_format' => [
            'type' => 'json_object',
        ],
        'messages' => [
            [
                'role'    => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role'    => 'user',
                'content' => $rawText,
            ],
        ],
    ];

    $apiResponse = ki_chat_request($body, $cfg);
    if (empty($apiResponse)) {
        return [
            'action'       => null,
            'device'       => null,
            'room'         => null,
            'number'       => null,
            'raw'          => $rawText,
            'rate_limited' => false,
        ];
    }

    $content = $apiResponse['choices'][0]['message']['content'] ?? '{}';

    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        ki_log('Intent-JSON aus Modell konnte nicht geparst werden: ' . $content);
        $decoded = [];
    }

    $action = isset($decoded['action']) ? (string)$decoded['action'] : null;
    $device = isset($decoded['device']) ? (string)$decoded['device'] : null;
    $room   = isset($decoded['room'])   ? (string)$decoded['room']   : null;
    $number = null;

    if (array_key_exists('number', $decoded) && $decoded['number'] !== null && $decoded['number'] !== '') {
        if (is_numeric($decoded['number'])) {
            $number = 0 + $decoded['number'];
        }
    }

    return [
        'action'       => $action !== '' ? $action : null,
        'device'       => $device !== '' ? $device : null,
        'room'         => $room   !== '' ? $room   : null,
        'number'       => $number,
        'raw'          => $rawText,
        'rate_limited' => false,
    ];
}

if (isset($_IPS) && is_array($_IPS) && array_key_exists('text', $_IPS)) {
    $text   = (string)$_IPS['text'];
    $result = ki_parse_intent($text, $CFG);

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}


// Testmodus bei Direkt-Ausführung im Editor oder via CLI.
$testText = 'wie warm ist es draußen';
if (PHP_SAPI === 'cli' && isset($argv) && count($argv) > 1) {
    $testText = (string)$argv[1];
}

$testResult = ki_parse_intent($testText, $CFG);

echo "Testeingabe:\n";
echo $testText . "\n\n";
echo "Erkannter Intent:\n";
echo json_encode($testResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n\n";

if ($testResult['rate_limited'] === true) {
    echo "HINWEIS: Rate-Limit aktiv – API-Aufruf wurde unterdrückt.\n";
} elseif (ki_get_api_key($CFG) === '') {
    echo "HINWEIS: Kein API-Key konfiguriert, die OpenAI-API wurde nicht aufgerufen.\n";
} elseif ($testResult['action'] === null && $testResult['device'] === null && $testResult['room'] === null && $testResult['number'] === null) {
    echo "HINWEIS: API-Aufruf war vermutlich fehlerhaft (siehe Meldungen-Log mit Filter 'KIIntentParser').\n";
} else {
    echo "HINWEIS: API-Aufruf scheint erfolgreich gewesen zu sein.\n";
}
