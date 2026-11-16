<?php
declare(strict_types=1);

require_once __DIR__ . '/../IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
require_once __DIR__ . '/../IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';

function checkHeating(bool $condition, string $reason): void
{
    if (!$condition) throw new RuntimeException($reason);
}

function IPS_VariableExists(int $id): bool { return isset($GLOBALS['variables'][$id]); }
function IPS_GetObject(int $id): array { return $GLOBALS['variables'][$id]['object']; }
function IPS_GetVariable(int $id): array { return $GLOBALS['variables'][$id]['variable']; }
function GetValue(int $id): mixed { return $GLOBALS['variables'][$id]['value']; }
function GetValueFormatted(int $id): string { return $GLOBALS['variables'][$id]['display'] ?? (string)GetValue($id); }
function RequestAction(int $id, mixed $value): void { throw new RuntimeException('Heating cannot actuate'); }

$values = [
    'fern_status' => [2, 'Komfortbetrieb'], 'fern_heute' => [8.26], 'fern_gestern' => [11.5],
    'warm_temp' => [63.1], 'warm_oben' => [61.2], 'haus_pumpe' => [true], 'haus_pumpe_sperre' => [true],
    'haus_vorlauf' => [33.7], 'haus_fussboden_vorlauf' => [1638.3],
    'solar_leistung' => [0.46], 'solar_euro' => [0.4], 'solar_energie' => [0.5], 'solar_ost' => [3.6],
    'dach_ost_status' => [false], 'dach_west_status' => [true], 'dach_rinne_status' => [false],
    'dach_rohr_status' => [false], 'dach_ost_zeit' => [208800], 'dach_ost_strom' => [0],
    'ventil_kg_hobby' => [9], 'ventil_eg_buero' => [14], 'ventil_kg_gang' => [0],
    'radiator_og_kinder_klein' => [12],
];
$metrics = [];
$preset = require __DIR__ . '/../RoomsCatalogConfigurator/libs/HeatingSystemPreset.php';
checkHeating(count(array_column($preset, 1)) === count(array_unique(array_column($preset, 1))), 'preset contains distinct IDs');
checkHeating(!array_intersect([18516, 19941, 56951, 57854, 12131, 49600], array_column($preset, 1)),
    'the preset uses feedback, avoids roof/foot pump commands and leaves room temperature outside');
foreach ($values as $key => $fixture) {
    $value = $fixture[0];
    [$group, $id, $parent, $ident, $type, $label, $unit] = $preset[$key];
    $metrics[$key] = compact('group', 'id', 'parent', 'ident', 'type', 'label', 'unit') + ['enabled' => true];
    $GLOBALS['variables'][$id] = ['object' => ['ParentID' => $parent, 'ObjectIdent' => $ident],
        'variable' => ['VariableType' => $type], 'value' => $value, 'display' => $fixture[1] ?? null];
}
$source = ['provider' => 'ObjectTreeHeating', 'domain' => 'heizung', 'kiEnabled' => true, 'metrics' => $metrics];
$catalog = ['dataSources' => ['heating_system' => $source]];
$read = static fn(string $text): array => iah_rc_voice($text, ['api_key' => 'test', 'allow_writes' => true], $catalog,
    static fn() => throw new RuntimeException('Heating queries are local'));

$overview = $read('Wie geht es meiner Heizung?');
checkHeating($overview['answerSource'] === 'heating_anlage' && count($overview['displayRows']) === 10,
    'heating status from the normal controller returns an APL overview');
checkHeating(str_contains($overview['answer'], 'Komfortbetrieb') && str_contains($overview['answer'], '63,1 °C'),
    'voice output uses the profile label and current values');
checkHeating($read('Wie viel Fernwärme habe ich gestern verbraucht?')['displayRows'] === [
    ['label' => 'Fernwärmebezug gestern', 'value' => '11,5 kWh'],
], 'daily thermal consumption selects the correct period and unit');
checkHeating($read('Welche Fußbodenventile sind offen?')['displayRows'] === [
    ['label' => 'Fußbodenventil Keller Hobbyraum', 'value' => '9 %'],
    ['label' => 'Fußbodenventil EG Büro', 'value' => '14 %'],
], 'foot valves are filtered by command position, without treating radiators as foot valves');
checkHeating($read('Wie hoch ist die Stromaufnahme der Dachflächenheizung Ost?')['displayRows'][0]['value'] === '0 mA',
    'zero is a valid live reading');
checkHeating($read('Wie viele Betriebsstunden hat die Dachflächenheizung Ost?')['displayRows'][0]['value'] === '58 h',
    'runtime counter in seconds is shown as accumulated hours');
checkHeating($read('Läuft die Rohrbegleitheizung am Dach?')['displayRows'] === [
    ['label' => 'Rohrbegleitheizung am Dach', 'value' => 'Aus'],
], 'roof trace heater uses its own status and false is a valid reading');
checkHeating($read('Wie hoch ist der Fußboden Vorlauf?')['displayRows'][0]['value'] === 'Messwert unplausibel',
    'a sensor sentinel never becomes a reported temperature');
checkHeating($read('Wie viel kWh liefert die Solarheizung?')['displayRows'][0]['value'] === '0,5 kWh'
    && $read('Wie hoch ist der Tagesertrag der Solarheizung?')['displayRows'][0]['value'] === '0,4 €',
    'thermal energy is kept separate from the euro-valued metric');
checkHeating(str_contains($read('Wie hoch ist die Leistung der Fernwärme?')['answer'], 'kein Messwert in Kilowatt')
    && str_contains($read('Wann ist die Fußbodenpumpe zuletzt gelaufen?')['answer'], 'keine Archiv-Auswertung'),
    'unmapped live and historical facts are not estimated');
checkHeating(count($read('Zeige alle Werte der Heizungsanlage')['displayRows']) === count($metrics),
    'detail mode shows all enabled heating readings');

$GLOBALS['variables'][39785]['object']['ParentID']++;
checkHeating($read('Wie warm ist das Warmwasser?')['displayRows'][0]['value'] === 'Wert fehlt',
    'a replaced ID is never read as the configured source');
checkHeating(str_contains(IAHHeatingSystemQuery::query([], 'Heizung Status')['answer'], 'keine lesbare Heizungsanlage'),
    'the live provider must be published');

foreach (['Status Fernwärme', 'Wie geht es meiner Heizung?', 'Dachrinnenheizung Status', 'Wie warm ist das Warmwasser?'] as $text) {
    checkHeating(iah_vir_classify(['text' => $text, 'intent' => 'GetHaus'])['reason'] === 'heating_question', $text);
}
checkHeating(iah_vir_classify(['text' => 'Status Rohrbegleitheizung'])['reason'] === 'irrigation_question',
    'unqualified trace-heating questions still use the established irrigation route');
checkHeating(iah_vir_classify(['text' => 'Wie geht es meiner Solaranlage?'])['reason'] === 'energy_question',
    'generic Solaranlage retains the existing PV alias');
checkHeating(IAHHeatingSystemQuery::topic('Wie ist die Heizung im Wohnzimmer?') === null,
    'the existing room flow owns room heating questions');
checkHeating(iah_vir_classify(['text' => 'Schalte die Dachrinnenheizung aus'])['category'] === 'action',
    'the heating read-only matcher does not swallow a control request');
checkHeating(iah_vir_classify(['text' => 'Heizung'])['category'] === 'navigation', 'the bare tile is still navigation');

echo "PASS heating system status, detail groups, roof versus irrigation, and checked live variable identities\n";

require_once __DIR__ . '/../RoomsCatalogConfigurator/libs/HeatingSystemEditorTrait.php';

final class HeatingEditorFixture
{
    use RCCHeatingSystemEditorTrait;

    public array $data = [];
    public array $report = [];
    public string $edit = '<?php return [];';
    public array $catalog = ['dataSources' => ['irrigation' => ['provider' => 'ObjectTreeIrrigation']]];

    private function systemTreeEditContext(): array
    {
        return [100, $this->edit, $this->catalog];
    }

    private function heatingInventory(): array
    {
        return [array_fill_keys(array_keys($GLOBALS['variables']), []), []];
    }

    private function WriteAttributeString(string $key, string $value): void { $this->data[$key] = $value; }
    private function ReadAttributeString(string $key): string { return $this->data[$key] ?? ''; }
    private function UpdateFormField(string $name, string $property, string $value): void { $this->report[$name] = $value; }
    private function refreshSystemTreeCatalog(): void {}

    private function saveSystemTreeEdit(int $id, string $old, array $catalog): int
    {
        checkHeating($id === 100 && $old === $this->edit, 'only the current EDIT source is written');
        $this->catalog = $catalog;
        return 101;
    }
}

$editor = new HeatingEditorFixture();
ob_start();
$editor->PreviewHeatingSystem();
$report = json_decode($editor->report['HeatingSystemJson'], true, 512, JSON_THROW_ON_ERROR);
checkHeating($report['domain'] === 'heizung' && isset($report['nicht_validiert']['warm_temp']),
    'preview lists the imported domain and any mismatched live identities');
$editor->edit = '<?php return ["changed" => true];';
$editor->ImportHeatingSystemToEdit();
checkHeating(!isset($editor->catalog['dataSources']['heating_system']), 'stale EDIT preview cannot write');
$editor->PreviewHeatingSystem();
$editor->ImportHeatingSystemToEdit();
ob_end_clean();
checkHeating(isset($editor->catalog['dataSources']['irrigation'], $editor->catalog['dataSources']['heating_system'])
    && !isset($editor->catalog['dataSources']['heating_system']['metrics']['warm_temp'])
    && $editor->catalog['dataSources']['heating_system']['allowWrites'] === false,
    'EDIT preserves other providers and publishes only verified, read-only heating bindings');
