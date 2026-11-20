<?php
declare(strict_types=1);

require_once __DIR__ . '/HomeConnectExport.php';

trait RCCHomeConnectEditorTrait
{
    /** Also callable from scripts; returns JSON without changing the catalog. */
    public function GetHomeConnectJson(int $rootId): string
    {
        return json_encode(RCCHomeConnectExport::scan($rootId), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    public function ExportHomeConnectJson(int $rootId): void
    {
        $json = '';
        try {
            $json = $this->GetHomeConnectJson($rootId);
            $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $notice = $report['deviceCount'] . ' Geräte, ' . $report['variableCount'] . ' Variablen. '
                . ($report['complete'] ? '' : 'Teilinventur – Hinweise im JSON beachten. ')
                . 'JSON unten zum Kopieren markieren.';
        } catch (Throwable $e) {
            $notice = $e->getMessage();
        }
        $this->UpdateFormField('HomeConnectExportJson', 'value', $json);
        $this->UpdateFormField('HomeConnectExportNotice', 'caption', $notice);
        echo $notice;
    }

    private function buildHomeConnectPanel(): array
    {
        return ['type' => 'ExpansionPanel', 'caption' => 'HOME CONNECT – Geräte als JSON exportieren',
            'expanded' => false, 'items' => [
                ['type' => 'Label', 'caption' => 'Die Hauptkategorie Home Connect reicht aus. Alle enthaltenen offiziellen Home-Connect-Geräte werden mit Variablen, aktuellen Werten, Profilen und vorhandenen Aktionen gruppiert ausgegeben. Der Export erteilt keine Schaltfreigaben.'],
                ['type' => 'SelectObject', 'name' => 'HomeConnectRootId', 'caption' => 'Home-Connect-Hauptkategorie oder Gerät', 'objectTypes' => [0, 1]],
                ['type' => 'Button', 'caption' => 'Home-Connect-JSON erzeugen',
                    'onClick' => 'RCC_ExportHomeConnectJson($id, (int)$HomeConnectRootId);'],
                ['type' => 'Label', 'name' => 'HomeConnectExportNotice', 'caption' => ''],
                ['type' => 'ValidationTextBox', 'name' => 'HomeConnectExportJson', 'caption' => 'Home-Connect-Daten als JSON',
                    'multiline' => true, 'width' => '100%', 'value' => ''],
            ]];
    }
}
