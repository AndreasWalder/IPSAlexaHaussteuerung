<?php
declare(strict_types=1);

require dirname(__DIR__) . '/IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';
function tileTestCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$alias = iah_vir_aliases();
foreach (['Kacheltest', 'Kachelvisualisierung', 'Kachel Visualisierung', 'Kacheln testen'] as $phrase) {
    $name = iah_vir_norm($phrase);
    tileTestCheck(($alias[$name] ?? '') === 'kacheltest', 'Missing voice alias: ' . $phrase);
    $decision = iah_vir_classify([
        'intent' => 'GetHaus',
        'legacySlots' => ['Action' => $phrase],
        'text' => $phrase
    ]);
    tileTestCheck(($decision['route'] ?? '') === 'kacheltest'
        && ($decision['category'] ?? '') === 'navigation',
        'GetHaus must route the test phrase: ' . $phrase);
}
tileTestCheck(iah_vir_legacy_gethaus_route([
    'intent' => 'GetHaus',
    'legacySlots' => ['Action' => 'Kacheltest', 'Room' => 'Wohnzimmer']
], $alias) === null, 'Do not steal multi-slot commands');
tileTestCheck(iah_vir_classify([
    'intent' => 'GetHaus', 'legacySlots' => ['Action' => 'Kamera'], 'text' => 'Kamera'
])['route'] === 'kamera', 'Existing Kamera route preserved');

$base = dirname(__DIR__) . '/IPSAlexaHaussteuerung/';
$module = file_get_contents($base . 'module.php');
$renderer = file_get_contents($base . 'resources/renderers/Route_allRenderer.php');
$action = file_get_contents($base . 'resources/action_entry.php');
$shell = file_get_contents($base . 'resources/html/alexa-diagnostic-shell.html');
$receiver = file_get_contents($base . 'resources/helpers/ExternalHtmlBrowserReceiver.php');
$form = json_decode(file_get_contents($base . 'form.json'), true, 512, JSON_THROW_ON_ERROR);
$panel = null;
foreach ($form['elements'] ?? [] as $element) {
    if (str_contains((string)($element['caption'] ?? ''), 'Kachelvisualisierung testen')) {
        $panel = $element;
        break;
    }
}
tileTestCheck(is_array($panel), 'Test setting must be visible in module form');
tileTestCheck(str_contains($module, "RegisterPropertyBoolean('TileTestEnabled', true)"), 'Enable test by default');
tileTestCheck(str_contains($module, "RegisterPropertyInteger('TileTestVisualizationId', 0)"), 'Visualization selector supported');
tileTestCheck(str_contains($module, 'patchActionScriptTileTestNavigation((int) $id)'), 'Existing Action scripts migrated');
tileTestCheck(str_contains($action, "'kacheltest'=>'kacheltest'"), 'New Action fallback has Kacheltest');
tileTestCheck(str_contains($renderer, "if (\$route === 'kacheltest')"), 'Dedicated tiletest route present');
tileTestCheck(str_contains($renderer, "'/tile/'"), 'Tile Visualization URL used instead of old WebFront URL');
tileTestCheck(str_contains($renderer, "['type'=>'external_url'") || str_contains($renderer, "'type' => 'external_url'"),
    'Tile route uses external URL to prevent WFC page switch');
tileTestCheck(str_contains($renderer, "['kamera', 'energie', 'kacheltest']"),
    'Existing diagnostic shell handles new route without altering Kamera/Energie');
tileTestCheck(str_contains($renderer, "TileTest.start"), 'Tile route startup logged');
tileTestCheck(str_contains($shell, "tile_capability") && str_contains($shell, "tile_render"),
    'Tile-specific browser diagnostics present');
tileTestCheck(str_contains($receiver, "'kacheltest'"), 'Receiver allowlists tiletest');
tileTestCheck(!str_contains($renderer, "tileTestUrl .= '?password='"),
    'Tile login password must not be embedded in URL');

// Regression: Existing generated Action scripts omitted new settings from
// their 'V' payload, so the renderer incorrectly said "deaktiviert" even
// while the module property was enabled. The renderer must be independent
// of whether that legacy script has already been migrated.
tileTestCheck(str_contains($renderer,"IPS_GetProperty(\$tileTestInstanceId, 'TileTestEnabled')"),
    'Kacheltest must read enabled flag from actual installed module');
tileTestCheck(str_contains($renderer,"IPS_GetProperty(\$tileTestInstanceId, 'TileTestVisualizationId')"),
    'Kacheltest must read target instance ID from actual installed module');
tileTestCheck(str_contains($renderer,'TileTest.settings'),
    'Settings source and enabled status must be safely logged');
tileTestCheck(substr_count($action, "'TileTestEnabled' => (bool)(\$V['TileTestEnabled'] ?? true)") === 2,
    'New Action template must pass property in both renderer payloads');
tileTestCheck(str_contains($action, "\$var['TileTestEnabled'] = (bool)(\$props['TileTestEnabled'] ?? true)"),
    'Custom SystemConfiguration must get live module flag');

$begin = strpos($renderer, "    if (\$route === 'kacheltest') {");
$end = strpos($renderer, "    // =============================\n    // EXTERNAL", $begin);
tileTestCheck($begin !== false && $end !== false, 'Renderer test block locatable');
$block = substr($renderer, $begin, $end - $begin);
$GLOBALS['tileTestProps'] = ['TileTestEnabled'=>true, 'TileTestVisualizationId'=>54321];
function IPS_InstanceExists(int $instance): bool { return $instance === 16159; }
function IPS_GetProperty(int $instance, string $name): mixed
{
    return $GLOBALS['tileTestProps'][$name] ?? null;
}
function IPS_LogMessage(string $channel, string $message): void {}
$execute = static function(array $v) use ($block): array {
    $V = $v + ['InstanceID'=>16159];
    $route = 'kacheltest';
    $baseUrl = 'https://example.org';
    $payload = []; $pageMappings = []; $externalPages = [];
    $flags = []; $JSON = JSON_UNESCAPED_SLASHES;
    $j = static fn(array $a) => json_encode($a, JSON_UNESCAPED_SLASHES);
    $cid = 'test';
    ob_start();
    try { eval($block); $response = ob_get_clean(); }
    catch(Throwable $e) { ob_end_clean(); throw $e; }
    return compact('route', 'pageMappings', 'externalPages', 'response');
};
$ok = $execute(['InstanceID'=>16159]);
tileTestCheck($ok['route']==='external', 'Existing Action missing flag must not disable enabled tile mode');
tileTestCheck(($ok['pageMappings']['kacheltest']['value']??'')==='https://example.org/tile/#54321',
    'Configured Kachelvisualisierungs-ID must come from module instance');
$disabledProp = $GLOBALS['tileTestProps']; $GLOBALS['tileTestProps']['TileTestEnabled']=false;
$off = $execute(['InstanceID'=>16159,'TileTestEnabled'=>true]);
tileTestCheck(str_contains($off['response'],'deaktiviert'),
    'Explicit module property false must disable test even when stale payload says true');
$GLOBALS['tileTestProps']=$disabledProp;

// SPA startup is not proven by iframe.onload: capture safe, time-scoped
// inner-frame snapshots and storage support with strict receiver allowlists.
tileTestCheck(str_contains($shell,"tileSnapshot('onload')")
    && str_contains($shell,"tileSnapshot('after_3s')")
    && str_contains($shell,"tileSnapshot('after_8s')")
    && str_contains($shell,"tileSnapshot('after_15s')"),
    'HTML diagnostic captures SPA progress after iframe load');
tileTestCheck(str_contains($shell,"tile_inner_storage")
    && str_contains($shell,'var store=win.localStorage'),
    'Test inner iframe localStorage separately from Alexa outer shell');
tileTestCheck(str_contains($shell,"tile_document")
    && str_contains($shell,"doc.readyState"),
    'Capture readiness of iframe document');
tileTestCheck(str_contains($shell,"scriptRequests")
    && str_contains($shell,"rootChildren")
    && str_contains($shell,"passwordInputs"),
    'Snapshot captures sanitized DOM, network and login counters');
tileTestCheck(str_contains($receiver,"if (is_int(\$value) && \$value >= 0 && \$value <= 9999)"),
    'Server must reject unbounded and non-integer diagnostic metrics');
tileTestCheck(str_contains($receiver,"\$key === 'kacheltest' && \$stage === 'tile_snapshot'"),
    'Only Kacheltest snapshots are allowed to contain diagnostic metrics');
tileTestCheck(!str_contains($receiver, "\$input['metrics']['url']")
    && !str_contains($receiver, "\$input['message']"),
    'Browser diagnostic must not accept URLs or raw exception messages');

// Opt-in localStorage compatibility experiment: default-off and strictly
// limited to the Kacheltest HTML route; all other routes stay unchanged.
tileTestCheck(str_contains($module,"RegisterPropertyBoolean('TileTestStorageCompat', false)"),
    'Compatibility experiment must default to OFF');
tileTestCheck(str_contains($renderer,"IPS_GetProperty(\$tileTestInstanceId, 'TileTestStorageCompat')"),
    'Compatibility setting read live from module instance');
tileTestCheck(str_contains($renderer,"\$externalKey === 'kacheltest' && !empty(\$payload['tileStorageCompat'])"),
    'Compatibility mode only passed to Kacheltest shell');
tileTestCheck(str_contains($shell,"params.get('compat')==='1'")
    && str_contains($shell,"route==='kacheltest'"),
    'No polyfill in Kamera or Energie');
tileTestCheck(str_contains($shell,'function installTileStorageShim()')
    && str_contains($shell,'sessionStorage')
    && str_contains($shell,"Object.defineProperty(window,'localStorage'"),
    'LocalStorage shim installed early using sessionStorage only when supported');
tileTestCheck(str_contains($shell,'frame.srcdoc=patched')
    && str_contains($shell,"frame.src=src; // Bei Fehlern Original, ohne Shim."),
    'Isolated srcdoc bootstrap has a normal-iframe fallback');
tileTestCheck(str_contains($shell,"target.origin!==location.origin")
    && str_contains($shell,"target.pathname"),
    'Compatibility fetch restricted to same origin and tile URL');
tileTestCheck(str_contains($receiver,"'tile_compat'")
    && str_contains($receiver,"'shim_session'"),
    'Compatibility telemetry is allowlisted');
tileTestCheck(!str_contains($shell, 'prompt(')
    && !str_contains($shell, 'password='),
    'Compatibility bootstrap does not auto-submit a password');

// Regression: the previous localStorage shim starts the spinner but the
// Tile application can still hang, e.g. because srcdoc drops /tile/#ID.
// Verify early, allowlisted errors and HTTP status counts without capturing
// credentials, JS error messages, URLs or DOM contents.
tileTestCheck(str_contains($shell,"installTileBootstrapDiagnostics")
    && str_contains($shell,"window.location.hash=wanted"),
    'Compat bootstrap preserves validated visualization fragment');
tileTestCheck(str_contains($shell,"selectedHash=/^#[0-9]{1,10}$/")
    && str_contains($shell,"JSON.stringify(selectedHash)"),
    'Only numeric visualization fragments are injected into bootstrap');
tileTestCheck(str_contains($shell,"tile_early_error")
    && str_contains($shell,"tile_early_rejection"),
    'First Tile JS errors observed BEFORE original page scripts execute');
tileTestCheck(str_contains($shell,"ev.source!==frame.contentWindow")
    && str_contains($shell,"ev.origin!==location.origin"),
    'Only messages from target iframe and same origin accepted');
tileTestCheck(str_contains($shell,"response4xx")
    && str_contains($shell,"response5xx")
    && str_contains($shell,"responseFailed"),
    'Tile response status buckets are aggregated for the SPA');
tileTestCheck(str_contains($receiver,"'tile_boot'")
    && str_contains($receiver,"'hash_match'")
    && str_contains($receiver,"'security_error'"),
    'Early diagnostics are receiver-allowlisted');
tileTestCheck(str_contains($receiver,"'response4xx'")
    && str_contains($receiver,"'responseFailed'"),
    'HTTP response counts are receiver-allowlisted as bounded integers');

echo "PASS: Kacheltest voice recognition, separate /tile/ rendering, no WFC switch, setup and diagnostics\n";
