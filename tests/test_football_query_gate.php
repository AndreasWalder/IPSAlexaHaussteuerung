<?php
declare(strict_types=1);

/**
 * Regression: cached Bundesliga standings must never swallow unrelated
 * APL tile questions such as tomorrow's Sillian weather.
 *
 * Run: php tests/test_football_query_gate.php
 */
$source = file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php');
if (!is_string($source)) throw new RuntimeException('Backend source missing');
$start = strpos($source, 'function iah_rc_football_answer(');
$stop = strpos($source, 'function iah_rc_voice(', $start);
if ($start === false || $stop === false) throw new RuntimeException('Football helper not found');
eval(substr($source, $start, $stop - $start));

foreach ([
    'wie wird das wetter morgen in sillian?',
    'Wann regnet es morgen?',
    'Wie geht es meiner PV Anlage?',
    'Welches Fenster ist offen?',
    'Wie warm ist es im Wohnzimmer?'
] as $question) {
    if (iah_rc_football_answer($question) !== null) {
        throw new RuntimeException('Football hijacked non-football query: '.$question);
    }
}

// Football must still reach the module lookup. The test process has no real
// Symcon instances, hence the helper returns null *after* its query gate.
$sourceGuard = substr($source, $start, 600);
if (!str_contains($sourceGuard, 'preg_match(')
    || !str_contains($sourceGuard, 'bundesliga')
    || !str_contains($sourceGuard, 'IPS_GetInstanceListByModuleID')) {
    throw new RuntimeException('Football query gate removed or misplaced');
}
echo "PASS: unrelated APL KI tile questions cannot use Bundesliga standings\n";
