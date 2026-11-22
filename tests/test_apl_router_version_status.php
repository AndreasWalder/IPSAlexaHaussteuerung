<?php
declare(strict_types=1);

// Run: php tests/test_apl_router_version_status.php
$root=dirname(__DIR__).'/IPSAlexaHaussteuerung/';
$trait=file_get_contents($root.'src/VisualNavigationEditorTrait.php');
$module=file_get_contents($root.'module.php');
$action=file_get_contents($root.'resources/action_entry.php');
foreach (['trait'=>$trait,'module'=>$module,'action'=>$action] as $name=>$source) {
    if (!is_string($source)) throw new RuntimeException('Missing '.$name);
}
$expected='IAH_APL_TILE_ROUTE_V6_BEGIN';
if (!str_contains($module,$expected) || !str_contains($action,$expected)) {
    throw new RuntimeException('V6 marker missing in module/action.');
}
if (!str_contains($trait,'for ($candidate=6; $candidate>=1; --$candidate)')
    || !str_contains($trait,"'V6 INSTALLIERT'")) {
    throw new RuntimeException('Router status must detect real installed V6.');
}
if (str_contains($trait,"APL-Klickrouter V5: ")) {
    throw new RuntimeException('Stale V5-only status check detected.');
}
if (!str_contains($trait,'$this->visualNavigationRows()')) {
    throw new RuntimeException('Status should use resolved tile bindings.');
}
echo "PASS: configured/router/status version aligned to V6\n";
