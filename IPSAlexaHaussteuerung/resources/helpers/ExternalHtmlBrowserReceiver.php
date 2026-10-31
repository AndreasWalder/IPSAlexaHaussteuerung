<?php
declare(strict_types=1);

/**
 * Browserdiagnose fuer Kamera/Energie. Wird ausschliesslich vom Modul
 * automatisch als /hook/alexa-html-debug registriert.
 * Niemals URLs, Tokens, Cookies, DOM-Inhalte oder Browser-Fehlertexte speichern.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$raw = file_get_contents('php://input', false, null, 0, 4097);
if (!is_string($raw) || strlen($raw) > 4096) {
    http_response_code(413);
    exit;
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    exit;
}
$instanceId = (int)($input['instanceId'] ?? 0);
$provided = (string)($input['token'] ?? '');
if ($instanceId <= 0 || !IPS_InstanceExists($instanceId) || $provided === '' || strlen($provided) > 256) {
    http_response_code(403);
    exit;
}
$secret = (string)@IPS_GetProperty($instanceId, 'Token');
if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(403);
    exit;
}
$allow = static function ($value, array $valid): string {
    $candidate = is_string($value) ? $value : '';
    return in_array($candidate, $valid, true) ? $candidate : 'unknown';
};
$key = $allow($input['route'] ?? '', ['kamera', 'energie', 'kacheltest']);
$stage = $allow($input['stage'] ?? '', [
    'shell_boot', 'iframe_start', 'iframe_load', 'iframe_error',
    'iframe_timeout', 'frame_dom', 'js_error', 'promise_error',
    'resource_error', 'visibility_hidden', 'pagehide', 'missing_src',
    'tile_capability', 'tile_render', 'tile_frame_js_error', 'tile_frame_rejection',
    'tile_frame_resource_error', 'tile_snapshot', 'tile_inner_storage', 'tile_document'
]);
$status = $allow($input['status'] ?? '', [
    'ok', 'error', 'timeout', 'empty', 'not_empty', 'cross_origin',
    'unknown', 'hidden', 'invalid', 'supported', 'unsupported',
    'login', 'loading', 'rendered', 'needs_selection', 'complete', 'interactive'
]);
$code = $allow($input['code'] ?? '', [
    'none', 'image', 'script', 'stylesheet', 'iframe', 'other',
    'unavailable', 'unknown', 'local_storage', 'session_storage',
    'websocket', 'indexed_db', 'selection', 'password', 'elements',
    'onload', 'after_3s', 'after_8s', 'after_15s', 'ready_state'
]);
$event = [
    'time' => date('c'),
    'route' => $key,
    'stage' => $stage,
    'status' => $status,
    'code' => $code
];
$diag = (int)@IPS_GetObjectIDByIdent('iahDiag', $instanceId);
$category = $diag > 0 ? (int)@IPS_GetObjectIDByIdent('iahExternalHtmlDiag', $diag) : 0;
if ($category <= 0) {
    http_response_code(503);
    exit;
}
$last = (int)@IPS_GetObjectIDByIdent('iahHtmlBrowserLast', $category);
$history = (int)@IPS_GetObjectIDByIdent('iahHtmlBrowserHistory', $category);
// Optionales, streng begrenztes Zahlenprofil des iframe.
 // Explizit KEINE beliebigen Strings, DOM-Inhalte, URLs oder Netzwerkpfade.
if ($key === 'kacheltest' && $stage === 'tile_snapshot' && is_array($input['metrics'] ?? null)) {
    $safeMetrics = [];
    foreach ([
        'nodes', 'bodyChildren', 'scripts', 'stylesheets', 'images',
        'frames', 'passwordInputs', 'rootChildren', 'scriptRequests',
        'styleRequests', 'apiRequests', 'otherRequests'
    ] as $metric) {
        $value = $input['metrics'][$metric] ?? null;
        if (is_int($value) && $value >= 0 && $value <= 9999) {
            $safeMetrics[$metric] = $value;
        }
    }
    if ($safeMetrics !== []) {
        $event['metrics'] = $safeMetrics;
    }
}
$line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($last > 0 && IPS_VariableExists($last)) {
    SetValueString($last, $line);
}
if ($history > 0 && IPS_VariableExists($history)) {
    $lock = 'IAH.HtmlBrowser.' . $instanceId;
    if (IPS_SemaphoreEnter($lock, 500)) {
        try {
            $old = trim((string)GetValueString($history));
            $rows = $old === '' ? [] : explode("\n", $old);
            $rows[] = $line;
            SetValueString($history, implode("\n", array_slice($rows, -100)));
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }
}
IPS_LogMessage('Alexa', 'ExternalHTML.browser ' . $line);
http_response_code(204);
exit;
