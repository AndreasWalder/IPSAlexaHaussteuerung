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
// Ein stabiler Einwort-Sprachbefehl, ohne den VoiceRouter fuer spaetere
// experimentelle Testziele erneut aendern zu muessen.
foreach (['Action','Device'] as $slot) {
    $decision=iah_vir_classify([
        'intent'=>'GetHaus','legacySlots'=>[$slot=>'Test'],'text'=>'Test'
    ]);
    tileTestCheck(($decision['category']??'')==='navigation'
        && ($decision['route']??'')==='test',
        'Alexa GetHaus/' . $slot . ' must recognize the plain Test command');
}
tileTestCheck(iah_vir_legacy_gethaus_route([
    'intent'=>'GetHaus',
    'legacySlots'=>['Action'=>'Test','Room'=>'Wohnzimmer']
],$alias)===null,'Do not steal ordinary multi-slot actions with Test');


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
tileTestCheck(str_contains($module, "RegisterPropertyString('AlexaTestTarget', 'kacheltest')"),
    'Generic Alexa Test destination configured as module property');
tileTestCheck(str_contains($module, 'patchActionScriptTileTestNavigation((int) $id)'),
    'Existing Action must be migrated for Test');
tileTestCheck(str_contains($module, 'str_contains($current, "\'test\'=>\'test\'")'),
    'Action script migration checks the new Test alias, not the old Kacheltest alias');
tileTestCheck(str_contains($action, "'test'=>'test'"), 'Direct Test alias in Action template');
tileTestCheck(str_contains($renderer, "if (\$route === 'test')"),
    'Renderer dispatches generic Test route first');
tileTestCheck(str_contains($renderer, "'Test.route '"),
    'Generic test destination observable in Alexa log');

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
    && !str_contains($shell, "searchParams.set('password'")
    && !str_contains($shell, "'?password='"),
    'Compatibility bootstrap never places a login password in URLs');

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

// Regressions for the white screen despite 19 successful 2xx responses.
tileTestCheck(str_contains($shell,"report('tile_location'")
    && str_contains($shell,"window.history.replaceState(")
    && str_contains($shell,"'history_blocked'"),
    'Log srcdoc context and whether path restoration is browser-blocked');
tileTestCheck(str_contains($shell,"'responseJson'")
    && str_contains($shell,"'responseHtml'")
    && str_contains($shell,"'responseOther'"),
    'Count only MIME types of fetched resources, never their body or URL');
tileTestCheck(str_contains($receiver,"'tile_location'")
    && str_contains($receiver,"'tile_history'")
    && str_contains($receiver,"'responseJson'"),
    'Allowlist Tile location diagnostic and response MIME counters');

// A real same-origin HTTPS document may use history.replaceState('/tile/#ID'),
// while about:srcdoc cannot. Compatibility remains isolated and opt-in.
$realUrlBootstrap=file_get_contents($base.'resources/html/alexa-tile-url-bootstrap.html');
tileTestCheck(str_contains($module, "RegisterPropertyString('TileTestBootstrapMode', 'url')"),
    'New real URL compatibility bootstrap is default only for enabled test mode');
tileTestCheck(str_contains($module, "'alexa-tile-url-bootstrap.html'"),
    'Module automatically installs the new Tile compatibility HTML');
tileTestCheck(str_contains($renderer, "'tileBootstrapMode'") &&
    str_contains($renderer, "'boot' =>"),
    'Renderer forwards selected bootstrap method in test-only URL');
tileTestCheck(str_contains($shell, 'loadTileWithRealUrl()') &&
    str_contains($shell, "bootMode==='url'") &&
    str_contains($shell, 'loadTileWithStorageCompatibility()'),
    'New real URL method keeps old srcdoc fallback');
tileTestCheck(str_contains($shell, "bootstrap.searchParams.set('src',src)") &&
    str_contains($shell, "'alexa-tile-url-bootstrap.html'"),
    'New bootstrap uses fixed authenticated module file');
tileTestCheck(str_contains($realUrlBootstrap, "history.replaceState(history.state,'',replacement)") &&
    str_contains($realUrlBootstrap, "document.write(patched)") &&
    str_contains($realUrlBootstrap, 'document.close()'),
    'Real URL method uses same-origin history before writing patched Tile HTML');
tileTestCheck(str_contains($realUrlBootstrap, "target.origin!==window.location.origin") &&
    str_contains($realUrlBootstrap, "target.search!==''") &&
    str_contains($realUrlBootstrap, '/^#[0-9]{1,10}$/'),
    'The bootstrap only fetches a same-origin /tile/ URL and numeric ID');
tileTestCheck(str_contains($realUrlBootstrap, "window.parent.__iahTileStorageShim") &&
    str_contains($realUrlBootstrap, "window.parent.__iahTileBootstrapDiagnostics"),
    'Existing early localStorage shim and error instrumentation remain reused');
tileTestCheck(str_contains($receiver, "'tile_origin'") &&
    str_contains($receiver, "'origin_injected'") &&
    str_contains($receiver, "'origin_failed'"),
    'Only fixed diagnostic status codes may leave real URL iframe');
tileTestCheck(!str_contains($realUrlBootstrap, 'password=') &&
    !str_contains($realUrlBootstrap, 'console.log('),
    'No URL passwords or full request logs in compatibility HTML');

// Password convenience must be explicit opt-in, short-lived, and NEVER
// embed the password in Alexa HTML.Start URL, logs, or GitHub contents.
$credential = file_get_contents($base.'resources/helpers/TileAutoLoginCredential.php');
tileTestCheck(str_contains($module, "RegisterPropertyBoolean('TileAutoLoginEnabled', false)")
    && str_contains($module, "RegisterPropertyString('TileAutoLoginPassword', '')"),
    'Tile autologin must default to disabled with no stored password');
tileTestCheck(str_contains($module, "'/hook/iah-alexa-tile-credential' => \$tileCredential")
    && str_contains($module, "'iahTileAutoLoginCredential'"),
    'Secure POST credential hook must be installed by the module');
tileTestCheck(str_contains($renderer, "'tileAutoLoginTicket'") &&
    str_contains($renderer, "hash_hmac('sha256'") &&
    str_contains($renderer, "random_bytes(16)"),
    'Renderer must sign short-lived random credential tickets');
tileTestCheck(str_contains($renderer, "time() + 120") &&
    str_contains($renderer, "unset(\$tileAutoLoginPassword)") &&
    !str_contains($renderer, "'authPassword'"),
    'Credential stays server-side, never appears in Alexa request URL');
tileTestCheck(str_contains($shell, "function tileTryAutoLogin()") &&
    str_contains($shell, "input[type=\"password\"]") &&
    str_contains($shell, "fetch('/hook/iah-alexa-tile-credential'") &&
    str_contains($shell, "method:'POST'"),
    'Tile login detects the password field and calls same-origin POST');
tileTestCheck(str_contains($shell, 'autoLoginUsed=true') &&
    str_contains($shell, 'autoLoginTicks>75') &&
    str_contains($shell, "route==='kacheltest'"),
    'Avoid multiple login attempts and preserve Kamera/Energie');
tileTestCheck(str_contains($receiver, "'tile_autologin'") &&
    str_contains($receiver, "'credential_unavailable'"),
    'Diagnostic events never contain password, token, or input value');
tileTestCheck(str_contains($credential, "hash_equals(\$expectedToken, \$token)") &&
    str_contains($credential, "hash_equals(\$correct, \$signature)") &&
    str_contains($credential, "(\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'") &&
    str_contains($credential, "Cache-Control: no-store"),
    'Credential endpoint checks authenticated signed POST without cache');
tileTestCheck(str_contains($credential, "TileAutoLoginEnabled") &&
    str_contains($credential, "'TileTestBootstrapMode') === 'url'") &&
    str_contains($credential, "\$expiry < \$now") &&
    str_contains($credential, "TileTestVisualizationId"),
    'Endpoint refuses disabled, expired or mismatched Tile auth requests');
tileTestCheck(str_contains($credential, "HTTP_ORIGIN") &&
    str_contains($credential, "HTTP_SEC_FETCH_SITE") &&
    !str_contains($credential, "IPS_LogMessage("),
    'Endpoint enforces same-origin browser security and never logs secrets');
$tileAutologinPanel = null;
foreach (($form['elements'] ?? []) as $element) {
    if (str_contains((string)($element['caption'] ?? ''), 'Kachelvisualisierung testen')) {
        $tileAutologinPanel = $element;
        break;
    }
}
$fields = [];
foreach (($tileAutologinPanel['items'] ?? []) as $item) {
    if (isset($item['name'])) $fields[$item['name']] = $item;
}
tileTestCheck(($fields['TileAutoLoginPassword']['type'] ?? '') === 'PasswordTextBox'
    && ($fields['TileAutoLoginEnabled']['type'] ?? '') === 'CheckBox',
    'Password must be masked and autofill must be opt-in in Symcon form');

// Signing contract: generated ticket and provider must use identical HMAC
// messages. A tampered ID/password changes signature and cannot authenticate.
$id=16159; $vis=54321; $expiry=time()+90; $nonce=bin2hex(random_bytes(16));
$secret='not-an-actual-password'; $token='not-an-actual-token';
$key=hash('sha256',$secret . "\0" . $token,true);
$sig=hash_hmac('sha256',$id.'|'.$vis.'|'.$expiry.'|'.$nonce,$key);
tileTestCheck(hash_equals($sig,hash_hmac('sha256',$id.'|'.$vis.'|'.$expiry.'|'.$nonce,$key)),
    'Valid ticket signature matches');
tileTestCheck(!hash_equals($sig,hash_hmac('sha256',$id.'|'.($vis+1).'|'.$expiry.'|'.$nonce,$key)),
    'Ticket cannot be used for different Tile visualization');

// Regressions: Alexa sends a same-origin POST through a reverse proxy where
// HTTP_HOST may be an INTERNAL name while HTTP_ORIGIN is the public BaseUrl.
// Never allow an explicit foreign Origin or cross-site Fetch metadata.
tileTestCheck(str_contains($credential, "IPS_GetProperty(\$id, 'BaseUrl')")
    && str_contains($credential, "\$expectedHost = strtolower((string)\$base['host'])")
    && str_contains($credential, "\$receivedHost = strtolower((string)\$originParts['host'])")
    && str_contains($credential, "\$expectedPort = (int)(\$base['port'] ?? 443)"),
    'Origin validation uses externally configured HTTPS BaseUrl host and port');
tileTestCheck(!str_contains($credential, "\$_SERVER['HTTP_HOST']")
    && str_contains($credential, "'origin_mismatch'")
    && str_contains($credential, "'fetch_cross_site'"),
    'Reverse proxy internal HTTP_HOST must not block correct external Origin');
tileTestCheck(str_contains($credential, "\$origin !== ''")
    && str_contains($credential, "'origin_invalid'")
    && str_contains($credential, "hash_equals(\$expectedHost, \$receivedHost)"),
    'Origin mismatch or invalid HTTPS must continue to fail closed');
tileTestCheck(str_contains($credential, "HTTP_SEC_FETCH_SITE")
    && str_contains($credential, "request_invalid")
    && str_contains($credential, "ticket_expired")
    && str_contains($credential, "ticket_invalid")
    && str_contains($credential, "hash_equals(\$correct, \$signature)"),
    'Optional missing Alexa fetch metadata never bypasses token and signed expiry');
tileTestCheck(str_contains($credential, "X-IAH-Tile-Auth-Reason:")
    && str_contains($credential, "in_array(\$reason, \$allowed, true)"),
    'Response only exposes fixed diagnostic rejection codes');
tileTestCheck(str_contains($shell, "response.status===403")
    && str_contains($shell, "response.status===404")
    && str_contains($shell, "credential_invalid_response")
    && str_contains($shell, "credential_network")
    && str_contains($shell, "X-IAH-Tile-Auth-Reason"),
    'Browser reports safe auth HTTP categories instead of catch-all unavailable');
tileTestCheck(str_contains($receiver, "'origin_mismatch'")
    && str_contains($receiver, "'credential_http_403'")
    && str_contains($receiver, "'credential_network'"),
    'Existing log receiver allows fixed credential failure diagnoses');
tileTestCheck(!str_contains($credential, 'IPS_LogMessage(')
    && !str_contains($shell, "emit('tile_autologin','error',response.text(")
    && !str_contains($shell, "emit('tile_autologin','error',data.password"),
    'Never send user credentials, request URL or raw response text into logs');

// Echo-WebHook regression (31.10.2026, 23:31:41):
// Browser sends JSON, but $_SERVER['CONTENT_TYPE'] is absent in the
// IP-Symcon webhook/proxy environment and used to cause immediate 403.
tileTestCheck(!str_contains($credential, "if (stripos((string)(\$_SERVER['CONTENT_TYPE']")
    && !str_contains($credential, "\$deny('content_type_invalid')"),
    'Credential webhook must not require PHP CONTENT_TYPE metadata');
tileTestCheck(str_contains($credential, "file_get_contents('php://input'")
    && str_contains($credential, "json_decode(\$raw, true)")
    && str_contains($credential, "strlen(\$raw) > 2048")
    && str_contains($credential, "'payload_invalid'"),
    'Credential POST still must contain bounded valid JSON');
tileTestCheck(str_contains($credential, "(\$_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'")
    && str_contains($credential, "HTTP_ORIGIN")
    && str_contains($credential, "HTTP_SEC_FETCH_SITE")
    && str_contains($credential, "hash_equals(\$expectedToken, \$token)")
    && str_contains($credential, "hash_equals(\$correct, \$signature)")
    && str_contains($credential, "\$expiry < \$now"),
    'Removing Content-Type gate does not weaken method, origin, token or short-lived HMAC validation');

// Actual Echo log, 31.10.2026 23:36:09: field_detected -> filled
// -> submitted, but the visible OK still requires a manual tap.
// Clicking generic submit immediately is NOT equivalent to the Tile
// application's SPA OK button, so wait until input/change state updates.
tileTestCheck(str_contains($shell, "function findTileLoginOkButton(doc,active,win)")
    && str_contains($shell, "'ok','anmelden','login'")
    && str_contains($shell, "button,[role=\"button\"],input[type=\"submit\"]"),
    'Login chooses explicit OK/Anmelden button including non-submit SPA buttons');
tileTestCheck(str_contains($shell, "nodes.length===1")
    && str_contains($shell, "style.visibility==='hidden'")
    && str_contains($shell, "node.hidden")
    && str_contains($shell, "el.getAttribute('aria-disabled')==='true'"),
    'Button discovery is scoped, unique, visible and enabled');
tileTestCheck(str_contains($shell, "setTimeout(function(){")
    && str_contains($shell, "},450);")
    && str_contains($shell, "match.element.click();")
    && str_contains($shell, "'button_clicked'"),
    'SPA events settle before the real login button is clicked');
tileTestCheck(str_contains($shell, "'enter_sent'")
    && str_contains($shell, "'input_reset'")
    && str_contains($shell, "'login_still_visible'")
    && str_contains($shell, "'login_closed'"),
    'Enter fallback and real login outcome are diagnosed separately');
tileTestCheck(str_contains($receiver, "'button_clicked'")
    && str_contains($receiver, "'form_submit_requested'")
    && str_contains($receiver, "'login_still_visible'"),
    'Only safe, fixed login-button diagnostic states may be logged');
tileTestCheck(str_contains($shell, 'autoLoginUsed=true')
    && str_contains($shell, "if(!autoLoginAllowed || autoLoginUsed")
    && !str_contains($shell, "emit('tile_autologin','ok','submitted');"),
    'AutoLogin remains one-shot, no unsupported successful-submit claim');
tileTestCheck(str_contains($shell, "matching(el.getAttribute('aria-label'))")
    && !str_contains($shell, "emit('tile_autologin','ok',caption")
    && !str_contains($shell, "emit('tile_autologin','error',current.value"),
    'Button texts and password inputs must not enter log payloads');

// Echo 23:42:17: form_submit_requested did NOT close the login.
// Support exact OK text inside nested SPAN/DIV and inconsistent
// getBoundingClientRect() values from the embedded Alexa browser.
tileTestCheck(str_contains($shell, "matching(el.textContent)")
    && str_contains($shell, "'span,div,label,p,strong,b,small,a,button'")
    && str_contains($shell, "firstElementChild")
    && str_contains($shell, "match.element.click();"),
    'Custom Tile OK text is clicked with delegated event bubbling');
tileTestCheck(str_contains($shell, "button_control")
    && str_contains($shell, "button_text")
    && str_contains($shell, "button_submit")
    && str_contains($shell, "button_not_found")
    && str_contains($shell, "button_ambiguous"),
    'No arbitrary button clicks; report safe matcher category');
tileTestCheck(!str_contains($shell, "rect.width<=0")
    && str_contains($shell, "style.display==='none'")
    && str_contains($shell, "node.hidden"),
    'Skip hidden controls without relying on Echo zero-sized rect');
tileTestCheck(str_contains($receiver, "'button_text'")
    && str_contains($receiver, "'button_not_found'")
    && str_contains($receiver, "'button_ambiguous'"),
    'Only allowlisted matcher codes appear in diagnostic logs');

// After #189 the actual Echo still logged:
// button_submit -> button_clicked -> login_still_visible.
// The *documented* Tile password confirmation is Enter.
// Send Enter to the password field rather than click generic submit.
tileTestCheck(str_contains($shell, 'function tileSendEnter(win,input)')
    && str_contains($shell, "['keydown','keypress','keyup']")
    && str_contains($shell, "key:'Enter',code:'Enter'")
    && str_contains($shell, "input.dispatchEvent(event)"),
    'Tile confirmation sends complete Enter key sequence on the input');
tileTestCheck(str_contains($shell, "match.kind!=='button_submit'")
    && str_contains($shell, "'button_submit_skipped'")
    && str_contains($shell, "tileSendEnter(win,current)")
    && str_contains($shell, "'enter_sent'"),
    'Echo generic submit button is skipped for documented Enter route');
tileTestCheck(str_contains($shell, "function tileObserveManualLoginTap")
    && str_contains($shell, "event.isTrusted!==true")
    && str_contains($shell, "removeEventListener('click',onManualClick,true)")
    && str_contains($shell, "'manual_button'"),
    'Observes only one real manual tap inside the auth dialog');
tileTestCheck(!str_contains($shell, "emit('tile_autologin','ok',target.textContent")
    && !str_contains($shell, "emit('tile_autologin','ok',target.outerHTML")
    && str_contains($receiver, "'manual_button'")
    && str_contains($receiver, "'manual_role'"),
    'Manual tap diagnostic reports only fixed control categories');

echo "PASS: Kacheltest voice recognition, separate /tile/ rendering, no WFC switch, setup and diagnostics\n";
