<?php
declare(strict_types=1);

/**
 * Regression for Symcon runtime where generated KIHouseController has already
 * defined its embedded helper classes before module traits are evaluated.
 *
 * Run: php tests/test_embedded_helper_load_guards.php
 */
$root=dirname(__DIR__).'/IPSAlexaHaussteuerung/';
require_once $root.'resources/helpers/TauernwetterForecast.php';
require_once $root.'resources/helpers/IrrigationQueryEngine.php';
require_once $root.'src/HouseQuestionContext.php';

// These traits and the module must not blindly include already-defined classes.
require_once $root.'src/WeatherForecastTrait.php';
require_once $root.'src/VoiceRuleEditorTrait.php';
foreach (['IAHTauernwetterForecast','IAHIrrigationQuery','IAHHouseQuestionContext'] as $class) {
    if (!class_exists($class, false)) {
        throw new RuntimeException('Expected embedded class was lost: '.$class);
    }
}
if (!trait_exists('IAHWeatherForecastTrait',false)
    || !trait_exists('IAHVoiceRuleEditorTrait',false)) {
    throw new RuntimeException('Module traits not available after helper preloading.');
}
$weather=file_get_contents($root.'src/WeatherForecastTrait.php');
$voice=file_get_contents($root.'src/VoiceRuleEditorTrait.php');
$module=file_get_contents($root.'module.php');
$controller=file_get_contents($root.'resources/helpers/KIHouseController.php');
if (!is_string($weather)||!is_string($voice)||!is_string($module)||!is_string($controller)) {
    throw new RuntimeException('Source file could not be read.');
}
foreach ([
    'IAHTauernwetterForecast'=>$weather,
    'IAHIrrigationQuery'=>$voice,
    'IAHHouseQuestionContext'=>$module
] as $class=>$source) {
    if (!str_contains($source,"if (!class_exists('".$class."', false))")) {
        throw new RuntimeException('Missing helper redeclaration guard: '.$class);
    }
}
foreach ([
    'IAHTauernwetterForecast'=>'IAH_TAUERNWETTER_FORECAST_INSERT',
    'IAHHouseQuestionContext'=>'IAH_HOUSE_QUESTION_CONTEXT_INSERT',
    'IAHIrrigationQuery'=>'IAH_IRRIGATION_QUERY_INSERT'
] as $class=>$marker) {
    if (!str_contains($controller, '// '.$marker)
        || !str_contains($controller, "if (!class_exists('".$class."', false))")) {
        throw new RuntimeException('Generated controller template missing embedded helper guard: '.$class);
    }
}
if (!str_contains($voice,"if (!function_exists('iah_rc_voice'))")
    || !str_contains($voice,"if (!function_exists('iah_vir_classify'))")
    || !str_contains($voice,"if (!class_exists('IAHHeatingSystemQuery', false))")) {
    throw new RuntimeException('Additional generated helper import guard missing.');
}
echo "PASS: embedded house, weather and irrigation helpers coexist with module traits\n";
