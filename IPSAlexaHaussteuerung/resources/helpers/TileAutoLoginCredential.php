<?php
/**
 * Optionaler Kurzzeit-Credential-Transfer fuer den EIGENEN Alexa-Kacheltest.
 *
 * Weder Passwort noch Passwort-Hash werden in eine URL, JS-Datei oder
 * ins IP-Symcon-/Alexa-Protokoll geschrieben. Der Browser ruft diesen
 * WebHook per same-origin HTTPS POST nur beim sichtbaren Login-Dialog auf.
 *
 * Sicherheit: Ein Autologin-Passwort ist eine explizite Zugriffsfreigabe
 * fuer das jeweilige Echo. Die Modul-Property wird persistent gespeichert.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// Nur feste, harmlose Fehlerkategorien an die bestehende Browserdiagnose.
 // Keine Passwoerter, URLs, Tokens, Headerwerte oder Requestdaten.
$deny = static function (string $reason): never {
    $allowed = [
        'method_invalid', 'fetch_cross_site', 'origin_invalid',
        'origin_mismatch', 'content_type_invalid', 'payload_invalid',
        'request_invalid', 'settings_unavailable', 'configuration_disabled',
        'token_invalid', 'ticket_expired', 'ticket_invalid'
    ];
    header('X-IAH-Tile-Auth-Reason: ' . (
        in_array($reason, $allowed, true) ? $reason : 'request_invalid'
    ));
    http_response_code(403);
    echo '{"ok":false}';
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $deny('method_invalid');
}
// Echo / reverse proxies koennen Origin und Sec-Fetch-Site optional
// auslassen. Vorhandene Cross-Site-Werte werden IMMER verworfen.
$site = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
if ($site !== '' && $site !== 'same-origin' && $site !== 'none') {
    $deny('fetch_cross_site');
}
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
// IP-Symcon-WebHooks bzw. Reverse-Proxys stellen den Request-Header
// Content-Type nicht immer in $_SERVER['CONTENT_TYPE'] bereit.
// Deshalb NICHT allein aufgrund dieses optionalen PHP-Metadatenfelds
// ablehnen: der POST-Body muss darunter weiterhin gueltiges JSON mit
// korrekter Instanz-ID, Token und zeitlich begrenztem HMAC-Ticket sein.
// Ein fremder Origin/Sec-Fetch-Site wird weiterhin explizit verworfen.
$raw = file_get_contents('php://input', false, null, 0, 2049);
if (!is_string($raw) || $raw === '' || strlen($raw) > 2048) {
    $deny('payload_invalid');
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    $deny('payload_invalid');
}
$id = (int)($input['instanceId'] ?? 0);
$visId = (int)($input['visualizationId'] ?? 0);
$token = (string)($input['token'] ?? '');
$ticket = (string)($input['ticket'] ?? '');
if ($id <= 0 || $visId <= 0 || $token === '' ||
    strlen($token) > 512 || strlen($ticket) > 160 ||
    !preg_match('/^([0-9]{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D', $ticket, $match)
    || !IPS_InstanceExists($id)) {
    $deny('request_invalid');
}
try {
    $enabled = (bool)IPS_GetProperty($id, 'TileTestEnabled')
        && (bool)IPS_GetProperty($id, 'TileTestStorageCompat')
        && (bool)IPS_GetProperty($id, 'TileAutoLoginEnabled')
        && (string)IPS_GetProperty($id, 'TileTestBootstrapMode') === 'url';
    $expectedVisId = (int)IPS_GetProperty($id, 'TileTestVisualizationId');
    $expectedToken = (string)IPS_GetProperty($id, 'Token');
    $password = (string)IPS_GetProperty($id, 'TileAutoLoginPassword');
    // Public/extern URL der Instanz ist die Browser-Origin.
    // HTTP_HOST ist hinter Reverse Proxy oft nur ein interner Host:
    // darauf darf die Herkunftskontrolle NICHT basieren.
    $configuredBase = (string)IPS_GetProperty($id, 'BaseUrl');
} catch (Throwable $e) {
    $deny('settings_unavailable');
}
if (!$enabled || $expectedVisId !== $visId || $password === '') {
    $deny('configuration_disabled');
}
if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
    $deny('token_invalid');
}
// Vorhandene Origin muss der tatsaechlich konfigurierten externen
// HTTPS-Adresse entsprechen, inklusive Port. Fehlende Header sind
// bei manchen Alexa-Browsern normal; die signierte 120-Sekunden-Freigabe,
// das Modul-Token, der JSON-POST und die Same-Origin-Policy bleiben Pflicht.
$base = parse_url($configuredBase);
if (!is_array($base) ||
    strtolower((string)($base['scheme'] ?? '')) !== 'https' ||
    empty($base['host'])) {
    $deny('origin_invalid');
}
if ($origin !== '') {
    $originParts = parse_url($origin);
    if (!is_array($originParts) ||
        strtolower((string)($originParts['scheme'] ?? '')) !== 'https' ||
        empty($originParts['host']) ||
        isset($originParts['user']) || isset($originParts['pass']) ||
        isset($originParts['path']) || isset($originParts['query']) ||
        isset($originParts['fragment'])) {
        $deny('origin_invalid');
    }
    $expectedHost = strtolower((string)$base['host']);
    $receivedHost = strtolower((string)$originParts['host']);
    $expectedPort = (int)($base['port'] ?? 443);
    $receivedPort = (int)($originParts['port'] ?? 443);
    if (!hash_equals($expectedHost, $receivedHost) ||
        $expectedPort !== $receivedPort) {
        $deny('origin_mismatch');
    }
}

$expiry = (int)$match[1];
$nonce = $match[2];
$signature = $match[3];
$now = time();
// Valid for at most 120 s, and never accepted after expiry.
if ($expiry < $now || $expiry > $now + 120) {
    $deny('ticket_expired');
}
$signKey = hash('sha256', $password . "\0" . $expectedToken, true);
$correct = hash_hmac('sha256', $id . '|' . $visId . '|' . $expiry . '|' . $nonce, $signKey);
if (!hash_equals($correct, $signature)) {
    $deny('ticket_invalid');
}
http_response_code(200);
echo json_encode(['ok' => true, 'password' => $password],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
