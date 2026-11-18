<?php
declare(strict_types=1);

require_once __DIR__ . '/../IPSAlexaHaussteuerung/src/SymconScriptSource.php';

function checkScriptNumbers(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

// Tests execute in the CLI. Five-digit fixtures must reach the normalizer unchanged.
$source = <<<'SOURCE'
<?php
// Literal source text is preserved: 12345.
return [
    10000 => [16159, -86400, 65536, 131072, 1200000, 10485760, PHP_INT_MAX],
    'message' => 'Code 12345',
    'pattern' => '/^[0-9]{5}$/D',
    'float' => 4.25,
    'small' => 9999,
];
SOURCE;
$normalized = IAHSymconScriptSource::normalize($source);
checkScriptNumbers($source !== $normalized, 'Object-like decimal literals are converted');
checkScriptNumbers(IAHSymconScriptSource::normalize($normalized) === $normalized, 'Repeated updates are stable');
$execute = static fn(string $php): mixed => eval(substr($php, 5));
checkScriptNumbers($execute($source) === $execute($normalized), 'Keys, signed integers, strings, floats and limits retain their exact PHP values');
checkScriptNumbers(str_contains($normalized, "'Code 12345'") && str_contains($normalized, '/^[0-9]{5}$/D'),
    'Text and regular expressions are preserved');
foreach ([10000, 16384, 32768, 99999, 1000000, PHP_INT_MAX] as $number) {
    $code = '<?php return ' . $number . ';';
    checkScriptNumbers($execute(IAHSymconScriptSource::normalize($code)) === $number,
        'Generated instance IDs and limits retain their full integer precision');
}
$assertNoLiteral = static function (string $code, string $path): void {
    foreach (token_get_all($code, TOKEN_PARSE) as $token) {
        checkScriptNumbers(!is_array($token) || $token[0] !== T_LNUMBER
            || !preg_match('/^[1-9][0-9]{4,}$/D', $token[1]), 'Plain decimal literal remains in ' . $path);
    }
};
$assertNoLiteral($normalized, 'normalized fixture');
foreach (['IPSAlexaHaussteuerung', 'RoomsCatalogConfigurator'] as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $root));
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') $assertNoLiteral(file_get_contents($file->getPathname()), $file->getPathname());
    }
}
echo "PASS: production PHP has no object-like decimal literals; generated sources retain types and exact values\n";
