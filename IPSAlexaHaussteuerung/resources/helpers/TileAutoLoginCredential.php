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

$deny = static function (): never {
    http_response_code(403);
    echo '{"ok":false}';
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $deny();
}
$site = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
if ($site !== '' && $site !== 'same-origin') {
    $deny();
}
// Cross-site scripts do not get a CORS response. Reject explicit
// cross-origin requests as an additional defense-in-depth check.
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $host = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
    $serverHost = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
    if ($host === '' || $serverHost === '' || !hash_equals($serverHost, $host)
        || strtolower((string)parse_url($origin, PHP_URL_SCHEME)) !== 'https') {
        $deny();
    }
} elseif ($site !== 'same-origin') {
    $deny();
}
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    $deny();
}
$raw = file_get_contents('php://input', false, null, 0, 2049);
if (!is_string($raw) || $raw === '' || strlen($raw) > 2048) {
    $deny();
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    $deny();
}
$id = (int)($input['instanceId'] ?? 0);
$visId = (int)($input['visualizationId'] ?? 0);
$token = (string)($input['token'] ?? '');
$ticket = (string)($input['ticket'] ?? '');
if ($id <= 0 || $visId <= 0 || $token === '' ||
    strlen($token) > 512 || strlen($ticket) > 160 ||
    !preg_match('/^([0-9]{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D', $ticket, $match)
    || !IPS_InstanceExists($id)) {
    $deny();
}
try {
    $enabled = (bool)IPS_GetProperty($id, 'TileTestEnabled')
        && (bool)IPS_GetProperty($id, 'TileTestStorageCompat')
        && (bool)IPS_GetProperty($id, 'TileAutoLoginEnabled')
        && (string)IPS_GetProperty($id, 'TileTestBootstrapMode') === 'url';
    $expectedVisId = (int)IPS_GetProperty($id, 'TileTestVisualizationId');
    $expectedToken = (string)IPS_GetProperty($id, 'Token');
    $password = (string)IPS_GetProperty($id, 'TileAutoLoginPassword');
} catch (Throwable $e) {
    $deny();
}
if (!$enabled || $expectedVisId !== $visId ||
    $expectedToken === '' || $password === '' ||
    !hash_equals($expectedToken, $token)) {
    $deny();
}

$expiry = (int)$match[1];
$nonce = $match[2];
$signature = $match[3];
$now = time();
// Valid for at most 120 s, and never accepted after expiry.
if ($expiry < $now || $expiry > $now + 120) {
    $deny();
}
$signKey = hash('sha256', $password . "\0" . $expectedToken, true);
$correct = hash_hmac('sha256', $id . '|' . $visId . '|' . $expiry . '|' . $nonce, $signKey);
if (!hash_equals($correct, $signature)) {
    $deny();
}
http_response_code(200);
echo json_encode(['ok' => true, 'password' => $password],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
