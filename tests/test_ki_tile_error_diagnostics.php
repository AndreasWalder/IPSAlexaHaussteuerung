<?php
declare(strict_types=1);

/**
 * Safe KI tile diagnostics regression tests.
 * Run: php tests/test_ki_tile_error_diagnostics.php
 */
require_once dirname(__DIR__).'/IPSAlexaHaussteuerung/src/VisualNavigationEditorTrait.php';
final class KIDiagnosticHarness { use IAHVisualNavigationEditorTrait; }
$method=new ReflectionMethod(KIDiagnosticHarness::class,'visualTileOutputDiagnostic');
$harness=new KIDiagnosticHarness();

$secret='private-api-key-do-not-log';
$sample="PHP Fatal error: Uncaught RuntimeException: {$secret} in /var/private/internal/KIHouseController.php on line 1337\nStack trace...";
$result=$method->invoke($harness,$sample);
if (!str_contains($result,'Kategorie=php_error')
    || !str_contains($result,'Datei=KIHouseController.php')
    || !str_contains($result,'Zeile=1337')
    || str_contains($result,$secret)
    || str_contains($result,'/var/private/')) {
    throw new RuntimeException('Fatal classification or privacy masking failed: '.$result);
}
$warning="Warning: Undefined variable \$config in /var/data/SecretHandler.php on line 57";
$result=$method->invoke($harness,$warning);
if (!str_contains($result,'Kategorie=php_warning')
    || !str_contains($result,'Datei=SecretHandler.php')
    || !str_contains($result,'Zeile=57')
    || str_contains($result,'/var/data/')) {
    throw new RuntimeException('Warning classification or path masking failed.');
}
foreach ([
    '<html><body>Unexpected output</body></html>'=>'html',
    '{"handled":true,"answer":'=>'malformed_json',
    ''=>'empty',
    'Unrecognized output'=>'unexpected_text',
] as $raw=>$kind) {
    $value=$method->invoke($harness,$raw);
    if (!str_contains($value,'Kategorie='.$kind)) {
        throw new RuntimeException('Expected '.$kind.' diagnostic.');
    }
}
echo "PASS: KI tile failure diagnostics classify safely without leaking raw output\n";
