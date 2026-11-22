<?php
declare(strict_types=1);

/**
 * Regression for PHP class redeclaration when KIHouseController embeds
 * HouseQuestionContext before the IPSAlexaHaussteuerung module is loaded.
 *
 * Run: php tests/test_house_context_double_include.php
 */
$root=dirname(__DIR__).'/IPSAlexaHaussteuerung/';
$module=file_get_contents($root.'module.php');
$helper=file_get_contents($root.'resources/helpers/KIHouseController.php');
$source=file_get_contents($root.'src/HouseQuestionContext.php');
if (!is_string($module)||!is_string($helper)||!is_string($source)) {
    throw new RuntimeException('Required source file is missing.');
}
$guard="if (!class_exists('IAHHouseQuestionContext', false)) {\n"
    ."    require_once __DIR__ . '/src/HouseQuestionContext.php';\n}";
if (!str_contains(str_replace("\r\n","\n",$module),$guard)) {
    throw new RuntimeException('Module must guard the context class include.');
}
if (!str_contains($helper,"if (!class_exists('IAHHouseQuestionContext', false))")) {
    throw new RuntimeException('Generated helper must use a guarded context class.');
}
require_once $root.'src/HouseQuestionContext.php';
if (!class_exists('IAHHouseQuestionContext', false)) {
    throw new RuntimeException('Context class not loaded.');
}
// Simulate the guarded import in a PHP runtime where the embedded class
// has already registered. A second unconditional require would be fatal.
if (!class_exists('IAHHouseQuestionContext', false)) {
    require_once $root.'src/HouseQuestionContext.php';
}
echo "PASS: preloaded HouseQuestionContext is not redeclared by module import\n";
