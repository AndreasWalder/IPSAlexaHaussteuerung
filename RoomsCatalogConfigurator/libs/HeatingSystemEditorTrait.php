<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/IPSAlexaHaussteuerung/resources/helpers/HeatingSystemQuery.php';

trait RCCHeatingSystemEditorTrait
{
    private const HEATING_ROOTS = [59776 => 'Heizung', 22841 => 'Heizung', 14246 => 'Danfoss Fernwärme', 30381 => 'Solar Anlage'];

    private function heatingInventory(): array
    {
        $points = [];
        $notes = [];
        foreach (self::HEATING_ROOTS as $id => $name) {
            if (!IPS_ObjectExists($id) || strcasecmp((string)(IPS_GetObject($id)['ObjectName'] ?? ''), $name) !== 0) {
                $notes[] = 'Erwarteter Heizungszweig fehlt: ' . $name . ' #' . $id;
                continue;
            }
            $scan = RCCSystemTreeDiscovery::scan($id);
            foreach ($scan['points'] as $point) {
                $points[$point['id']][$id] = $point;
            }
            foreach ($scan['notes'] as $note) {
                $notes[] = '#' . $id . ' ' . $note;
            }
        }
        return [$points, $notes];
    }

    private function heatingSource(array $points): array
    {
        $metrics = [];
        $preset = require __DIR__ . '/HeatingSystemPreset.php';
        foreach ($preset as $key => [$group, $id, $parent, $ident, $type, $label, $unit]) {
            $metric = compact('group', 'id', 'parent', 'ident', 'type', 'label', 'unit') + ['enabled' => true];
            if (isset($points[$id]) && IAHHeatingSystemQuery::matchesVariable($metric)) {
                $metrics[$key] = $metric;
            }
        }
        return [
            'version' => 1, 'domain' => 'heizung', 'provider' => IAHHeatingSystemQuery::PROVIDER,
            'title' => 'Heizungsanlage', 'kiEnabled' => true, 'kiReadOnly' => true,
            'allowWrites' => false, 'metrics' => $metrics,
        ];
    }

    public function PreviewHeatingSystem(): void
    {
        $this->WriteAttributeString('HeatingSystemPreview', '{}');
        $json = '';
        try {
            [, $original, $catalog] = $this->systemTreeEditContext();
            [$points, $notes] = $this->heatingInventory();
            $source = $this->heatingSource($points);
            $key = IAHHeatingSystemQuery::KEY;
            $removed = array_diff_key((array)($catalog['dataSources'][$key]['metrics'] ?? []), $source['metrics']);
            $preset = require __DIR__ . '/HeatingSystemPreset.php';
            $missing = array_diff_key($preset, $source['metrics']);
            $groups = [];
            foreach ($source['metrics'] as $metric) {
                $groups[$metric['group']][] = $metric['label'] . ' #' . $metric['id'];
            }
            $report = [
                'domain' => 'heizung', 'roots' => self::HEATING_ROOTS,
                'zugeordnet' => $groups, 'hinweise' => $notes,
                'nicht_validiert' => array_map(static fn(array $p): string => $p[5] . ' #' . $p[1], $missing),
                'in_dieser_vorschau_nicht_mehr_enthalten' => array_column($removed, 'label'),
            ];
            if ($source['metrics'] !== []) {
                $this->WriteAttributeString('HeatingSystemPreview', json_encode([
                    'until' => time() + 1800, 'editHash' => hash('sha256', $original),
                    'sourceHash' => hash('sha256', serialize($source)),
                ], JSON_THROW_ON_ERROR));
            }
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $notice = count($source['metrics']) . ' Mess- und Statuswerte für die Heizungsanlage validiert.';
        } catch (Throwable $e) {
            $notice = $e->getMessage();
        }
        $this->heatingFormReport($notice, $json);
    }

    public function ImportHeatingSystemToEdit(): void
    {
        try {
            $snapshot = json_decode($this->ReadAttributeString('HeatingSystemPreview'), true);
            [$id, $original, $catalog] = $this->systemTreeEditContext();
            if (($snapshot['until'] ?? 0) < time() || ($snapshot['editHash'] ?? '') !== hash('sha256', $original)) {
                throw new RuntimeException('Bitte die Heizungsanlage erneut prüfen.');
            }
            [$points] = $this->heatingInventory();
            $source = $this->heatingSource($points);
            if ($source['metrics'] === [] || ($snapshot['sourceHash'] ?? '') !== hash('sha256', serialize($source))) {
                throw new RuntimeException('Die Variablenzuordnung hat sich geändert. Bitte erneut prüfen.');
            }
            $key = IAHHeatingSystemQuery::KEY;
            $previous = $catalog['dataSources'][$key] ?? null;
            if ($previous !== null && ($previous['provider'] ?? '') !== IAHHeatingSystemQuery::PROVIDER) {
                throw new RuntimeException('Der Katalogschlüssel für die Heizungsanlage ist bereits belegt.');
            }
            if (($previous['kiEnabled'] ?? true) === false) $source['kiEnabled'] = false;
            foreach ($source['metrics'] as $metricKey => &$metric) {
                if (($previous['metrics'][$metricKey]['enabled'] ?? true) === false) $metric['enabled'] = false;
            }
            unset($metric);
            $catalog['dataSources'][$key] = $source;
            $backup = $this->saveSystemTreeEdit($id, $original, $catalog);
            $this->WriteAttributeString('HeatingSystemPreview', '{}');
            $this->refreshSystemTreeCatalog();
            $notice = count($source['metrics']) . ' Heizungswerte in EDIT gespeichert. Backup #' . $backup
                . '. Anschließend den RoomsCatalog nach PROD übernehmen.';
        } catch (Throwable $e) {
            $notice = $e->getMessage();
        }
        $this->heatingFormReport($notice);
    }

    public function ExportHeatingSystemJson(): void
    {
        $json = '';
        try {
            [$points, $notes] = $this->heatingInventory();
            $source = $this->heatingSource($points);
            [$archiveId, $archive] = $this->systemTreeArchive();
            $assigned = array_column($source['metrics'], null, 'id');
            $rows = [];
            foreach ($points as $id => $found) {
                $point = reset($found);
                $row = array_intersect_key($point, array_flip(['id', 'label', 'path', 'parent', 'ident', 'type', 'profile', 'unit']));
                $row['paths'] = array_map(static fn(array $p): array => [
                    'label' => $p['label'], 'path' => $p['path'], 'evidence' => $p['evidence'],
                ], $found);
                $row['zuordnung'] = isset($assigned[$id])
                    ? [$assigned[$id]['group'], $assigned[$id]['label']] : null;
                $history = $archive[$id] ?? null;
                $row['archive'] = $history === null ? null : [
                    'loggingActive' => $history['AggregationActive'] ?? null,
                    'recordCount' => $history['RecordCount'] ?? null, 'lastTime' => $history['LastTime'] ?? null,
                ];
                // Text variables can include URLs or HTML. Export numeric values and metadata only.
                if ($point['type'] !== 3 && IPS_VariableExists($id)) {
                    try {
                        $variable = IPS_GetVariable($id);
                        $value = GetValue($id);
                        $row['updatedAt'] = $variable['VariableUpdated'] ?? null;
                        $row['hasAction'] = (int)($variable['VariableCustomAction'] ?? 0) > 0
                            || (int)($variable['VariableAction'] ?? 0) > 0;
                        if (is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
                            $row['rawValue'] = $value;
                            if (function_exists('GetValueFormatted')) {
                                $row['formattedValue'] = self::systemTreeAiLabel(strip_tags(GetValueFormatted($id)));
                            }
                        }
                    } catch (Throwable $ignored) {
                        $row['status'] = 'Wert konnte nicht gelesen werden';
                    }
                }
                $rows[] = $row;
            }
            $json = json_encode([
                'domain' => 'heizung', 'roots' => self::HEATING_ROOTS,
                'archiveId' => $archiveId ?: null, 'hinweise' => $notes, 'werte' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $notice = count($rows) . ' Variablen exportiert. Inhalt aus „Heizungsdaten als JSON“ kopieren.';
        } catch (Throwable $e) {
            $notice = $e->getMessage();
        }
        $this->heatingFormReport($notice, $json);
    }

    private function heatingFormReport(string $notice, ?string $json = null): void
    {
        $this->UpdateFormField('HeatingSystemNotice', 'caption', $notice);
        if ($json !== null) $this->UpdateFormField('HeatingSystemJson', 'value', $json);
        echo $notice;
    }

    private function buildHeatingSystemPanel(array $edit, array $prod): array
    {
        $key = IAHHeatingSystemQuery::KEY;
        return ['type' => 'ExpansionPanel', 'caption' => 'HEIZUNGSANLAGE – Fernwärme, Warmwasser, Solarheizung', 'expanded' => false, 'items' => [
            ['type' => 'Label', 'caption' => 'Vorlage aus den vier Heizungs-Auszügen. EDIT: '
                . count((array)($edit['dataSources'][$key]['metrics'] ?? [])) . ' · PROD: '
                . count((array)($prod['dataSources'][$key]['metrics'] ?? [])) . ' Heizungswerte.'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Button', 'caption' => '1 – Heizungsanlage prüfen', 'onClick' => 'RCC_PreviewHeatingSystem($id);'],
                ['type' => 'Button', 'caption' => '2 – Heizungsanlage nach EDIT',
                    'confirm' => 'Geprüfte Variablen als lesende Heizungsdatenquelle nach EDIT übernehmen?',
                    'onClick' => 'RCC_ImportHeatingSystemToEdit($id);'],
            ]],
            ['type' => 'Button', 'caption' => 'Alle Heizungswerte als JSON ausgeben', 'onClick' => 'RCC_ExportHeatingSystemJson($id);'],
            ['type' => 'Label', 'name' => 'HeatingSystemNotice', 'caption' => ''],
            ['type' => 'ValidationTextBox', 'name' => 'HeatingSystemJson', 'caption' => 'Heizungsdaten als JSON',
                'multiline' => true, 'width' => '100%', 'value' => ''],
        ]];
    }
}
