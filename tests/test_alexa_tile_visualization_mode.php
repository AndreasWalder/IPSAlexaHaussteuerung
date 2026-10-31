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

echo "PASS: Kacheltest voice recognition, separate /tile/ rendering, no WFC switch, setup and diagnostics\n";
