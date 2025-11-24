<?php
declare(strict_types=1);

/**
 * ============================================================
 * KI INTENT PARSER — OpenAI-basierte Anfrage-Analyse
 * ============================================================
 *
 * Änderungsverlauf
 * 2025-11-24: v1.5 — Stabiler Rate-Limit / Loop-Schutz über Hilfs-Variable (funktioniert auch bei "Ausführen")
 * 2025-11-24: v1.4 — Rate-Limit zentral in ki_parse_intent (gilt auch für Testmodus)
 * 2025-11-24: v1.3 — Rate-Limit nur über LastExecute des Skripts (keine Zusatzvariable)
 * 2025-11-24: v1.2 — Rate-Limit / Loop-Schutz (max. 1 Ausführung pro X Sekunden)
 * 2025-11-24: v1.1 — Testmodus bei Direkt-Ausführung im Editor
 * 2025-11-24: v1   — Erste Version (OpenAI-Chat + JSON-Intent-Parser)
 */

$CFG = [
    'api_key'        => '',
    'api_key_var'    => 0,
    'model'          => 'gpt-4.1-mini',
    'log_channel'    => 'Alexa',
    'rate_limit_sec' => 60,
];

// Allow overrides via IPS_RunScriptWaitEx(['cfg' => [...]]).
if (isset($_IPS) && is_array($_IPS) && array_key_exists('cfg', $_IPS) && is_array($_IPS['cfg'])) {
    $CFG = array_merge($CFG, $_IPS['cfg']);
}

/**
 * Hilfs-Logging, läuft in IP-Symcon über IPS_LogMessage, sonst error_log.
 */
function ki_log(string $message): void
{
    global $CFG;

    $prefix = $CFG['log_channel'] ?? 'KIIntentParser';

    if (function_exists('IPS_LogMessage')) {
        IPS_LogMessage($prefix, $message);
    } else {
        error_log('[' . $prefix . '] ' . $message);
    }
}

/**
 * API-Key aus Konfiguration oder IPS-Variable holen.
 */
function ki_get_api_key(array $cfg): string
{
    if (!empty($cfg['api_key'])) {
        $key = trim((string)$cfg['api_key']);
        ki_log('API-Key aus CFG[api_key], Länge=' . strlen($key));
        return $key;
    }

    if (!empty($cfg['api_key_var']) && function_exists('IPS_VariableExists') && function_exists('GetValueString')) {
        $varId = (int)$cfg['api_key_var'];
        ki_log('Versuche API-Key aus Variable ' . $varId . ' zu lesen.');

        if ($varId > 0 && IPS_VariableExists($varId)) {
            $val = trim(GetValueString($varId));
            ki_log('API-Key aus Variable ' . $varId . ' gelesen, Länge=' . strlen($val));
            if ($val !== '') {
                return $val;
            }
            ki_log('API-Key-Variable ' . $varId . ' ist leer.');
        } else {
            ki_log('API-Key-Variable ' . $varId . ' existiert nicht.');
        }
    }

    ki_log('Kein OpenAI API-Key konfiguriert (api_key/api_key_var leer).');
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

$testText   = 'mach bitte das licht im wohnzimmer auf 50 prozent an';
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
