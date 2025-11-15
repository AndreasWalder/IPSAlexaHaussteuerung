<?php
declare(strict_types=1);

class RoomsCatalogConfigurator extends IPSModule
{
    private const COLOR_NEW = '#DCFCE7';
    private const COLOR_REMOVED = '#FEF3C7';
    private const COLOR_CHANGED = '#FEE2E2';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('RoomsCatalogScriptId', 0);
        $this->RegisterPropertyInteger('RoomsCatalogEditScriptId', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $values = $this->buildDiffRows();
        $error = $this->buildErrorRowIfNeeded($values);
        if ($error !== null) {
            $values = [$error];
        }

        $form = [
            'elements' => [
                [
                    'name'    => 'RoomsCatalogScriptId',
                    'type'    => 'SelectScript',
                    'caption' => 'RoomsCatalog Skript',
                    'value'   => $this->ReadPropertyInteger('RoomsCatalogScriptId'),
                ],
                [
                    'name'    => 'RoomsCatalogEditScriptId',
                    'type'    => 'SelectScript',
                    'caption' => 'RoomsCatalog Edit Skript',
                    'value'   => $this->ReadPropertyInteger('RoomsCatalogEditScriptId'),
                ],
            ],
            'actions'  => [
                [
                    'type'    => 'Button',
                    'caption' => 'RoomsCatalogEdit erstellen/aktualisieren',
                    'onClick' => 'IPS_RequestAction($id, "CreateEditScript", 0);',
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'RoomsCatalog mit Edit überschreiben',
                    'onClick' => 'IPS_RequestAction($id, "ApplyEditToRoomsCatalog", 0);',
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Differenzen aktualisieren',
                    'onClick' => 'IPS_RequestAction($id, "RefreshDiff", 0);',
                ],
                [
                    'type'    => 'List',
                    'name'    => 'DiffList',
                    'caption' => 'Räume, Domains & Status',
                    'rowCount' => 20,
                    'columns' => [
                        ['caption' => 'Raum', 'name' => 'room', 'width' => '220px'],
                        ['caption' => 'Domain', 'name' => 'domain', 'width' => '160px'],
                        ['caption' => 'Details', 'name' => 'details', 'width' => '320px'],
                        ['caption' => 'Status', 'name' => 'status', 'width' => '140px'],
                    ],
                    'values' => $values,
                ],
            ],
        ];

        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'CreateEditScript':
                $editId = $this->createOrUpdateEditScript();
                $this->UpdateFormField('RoomsCatalogEditScriptId', 'value', $editId);
                $this->refreshDiffList();
                break;
            case 'ApplyEditToRoomsCatalog':
                $this->applyEditToRoomsCatalog();
                $this->refreshDiffList();
                break;
            case 'RefreshDiff':
                $this->refreshDiffList();
                break;
            default:
                throw new Exception(sprintf('Unsupported action "%s"', $Ident));
        }
    }

    private function refreshDiffList(): void
    {
        $values = $this->buildDiffRows();
        $error = $this->buildErrorRowIfNeeded($values);
        if ($error !== null) {
            $values = [$error];
        }

        $this->UpdateFormField(
            'DiffList',
            'values',
            json_encode($values, JSON_THROW_ON_ERROR)
        );
    }

    private function createOrUpdateEditScript(): int
    {
        $sourceId = $this->ReadPropertyInteger('RoomsCatalogScriptId');
        if ($sourceId <= 0 || !IPS_ScriptExists($sourceId)) {
            throw new Exception('RoomsCatalog Skript ungültig.');
        }

        $content = IPS_GetScriptContent($sourceId);
        $parent = IPS_GetParent($sourceId);

        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptId');
        if ($editId <= 0 || !IPS_ScriptExists($editId)) {
            $editId = @IPS_GetObjectIDByName('RoomsCatalogEdit', $parent);
            if (!$editId) {
                $editId = IPS_CreateScript(0);
                IPS_SetParent($editId, $parent);
                IPS_SetName($editId, 'RoomsCatalogEdit');
                IPS_SetIdent($editId, 'roomsCatalogEdit');
            }
        }

        IPS_SetScriptContent($editId, $content);
        $this->updateProperty('RoomsCatalogEditScriptId', $editId);

        return (int) $editId;
    }

    private function applyEditToRoomsCatalog(): void
    {
        $sourceId = $this->ReadPropertyInteger('RoomsCatalogScriptId');
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptId');

        if ($sourceId <= 0 || !IPS_ScriptExists($sourceId)) {
            throw new Exception('RoomsCatalog Skript ungültig.');
        }
        if ($editId <= 0 || !IPS_ScriptExists($editId)) {
            throw new Exception('RoomsCatalogEdit Skript ungültig.');
        }

        $content = IPS_GetScriptContent($editId);
        IPS_SetScriptContent($sourceId, $content);
    }

    private function buildDiffRows(): array
    {
        $roomsCatalog = $this->loadRoomsCatalog($this->ReadPropertyInteger('RoomsCatalogScriptId'));
        $roomsEdit = $this->loadRoomsCatalog($this->ReadPropertyInteger('RoomsCatalogEditScriptId'));

        $keys = array_unique(array_merge(array_keys($roomsCatalog), array_keys($roomsEdit)));
        sort($keys);

        $rows = [];
        foreach ($keys as $key) {
            $orig = $roomsCatalog[$key] ?? null;
            $edit = $roomsEdit[$key] ?? null;
            $rows[] = $this->buildRoomRow($key, $orig, $edit);
            $rows = array_merge($rows, $this->buildDomainRows($key, $orig, $edit));
        }

        return $rows;
    }

    private function buildRoomRow(string $key, ?array $orig, ?array $edit): array
    {
        $state = $this->diffState($orig, $edit);
        $details = $this->buildRoomDetails($orig ?? $edit ?? []);

        return [
            'id'      => 'room:' . $key,
            'room'    => $this->resolveRoomTitle($key, $orig, $edit),
            'domain'  => '',
            'details' => $details,
            'status'  => $state['label'],
            'rowColor' => $state['color'],
        ];
    }

    private function buildDomainRows(string $roomKey, ?array $orig, ?array $edit): array
    {
        $domainsOrig = is_array($orig['domains'] ?? null) ? array_keys($orig['domains']) : [];
        $domainsEdit = is_array($edit['domains'] ?? null) ? array_keys($edit['domains']) : [];
        $domainKeys = array_unique(array_merge($domainsOrig, $domainsEdit));
        sort($domainKeys);

        $rows = [];
        foreach ($domainKeys as $domain) {
            $origDomain = $orig['domains'][$domain] ?? null;
            $editDomain = $edit['domains'][$domain] ?? null;
            $state = $this->diffState($origDomain, $editDomain);
            $rows[] = [
                'id'       => sprintf('domain:%s:%s', $roomKey, $domain),
                'room'     => '↳ ' . $this->resolveRoomTitle($roomKey, $orig, $edit),
                'domain'   => $domain,
                'details'  => $this->buildDomainDetails($origDomain ?? $editDomain ?? []),
                'status'   => $state['label'],
                'rowColor' => $state['color'],
            ];
        }

        return $rows;
    }

    private function buildRoomDetails(array $room): string
    {
        $domains = isset($room['domains']) && is_array($room['domains']) ? array_keys($room['domains']) : [];
        if ($domains === []) {
            return '';
        }
        return 'Domains: ' . implode(', ', $domains);
    }

    private function buildDomainDetails($domainData): string
    {
        if (!is_array($domainData)) {
            return '';
        }

        $parts = [];
        foreach ($domainData as $key => $value) {
            if (is_array($value)) {
                $parts[] = sprintf('%s (%d)', $key, count($value));
            } else {
                $parts[] = (string) $key;
            }
        }

        return implode(', ', $parts);
    }

    private function resolveRoomTitle(string $key, ?array $orig, ?array $edit): string
    {
        $room = $edit ?? $orig ?? [];
        if (is_array($room) && isset($room['display'])) {
            return (string) $room['display'];
        }
        return $key;
    }

    private function diffState($orig, $edit): array
    {
        if ($orig === null && $edit === null) {
            return ['label' => '', 'color' => ''];
        }
        if ($orig === null) {
            return ['label' => 'Neu (Edit)', 'color' => self::COLOR_NEW];
        }
        if ($edit === null) {
            return ['label' => 'Fehlt in Edit', 'color' => self::COLOR_REMOVED];
        }
        if ($this->normalizedJson($orig) === $this->normalizedJson($edit)) {
            return ['label' => 'Unverändert', 'color' => ''];
        }
        return ['label' => 'Geändert', 'color' => self::COLOR_CHANGED];
    }

    private function normalizedJson($value): string
    {
        return json_encode($this->normalizeValue($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function normalizeValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isAssoc($value)) {
            ksort($value);
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeValue($item);
            }
            return $value;
        }

        $normalized = [];
        foreach ($value as $item) {
            $normalized[] = $this->normalizeValue($item);
        }
        return $normalized;
    }

    private function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function loadRoomsCatalog(int $scriptId): array
    {
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            return [];
        }

        $file = IPS_GetScriptFile($scriptId);
        if (!is_file($file)) {
            return [];
        }

        $result = include $file;
        if (!is_array($result)) {
            return [];
        }

        return $result;
    }

    private function updateProperty(string $name, $value): void
    {
        if (IPS_GetProperty($this->InstanceID, $name) === $value) {
            return;
        }
        IPS_SetProperty($this->InstanceID, $name, $value);
        IPS_ApplyChanges($this->InstanceID);
    }

    private function buildErrorRowIfNeeded(array $values): ?array
    {
        if ($this->ReadPropertyInteger('RoomsCatalogScriptId') === 0) {
            return [
                'id'      => 'error',
                'room'    => 'RoomsCatalog nicht ausgewählt',
                'domain'  => '',
                'details' => 'Bitte ursprüngliches RoomsCatalog-Skript auswählen.',
                'status'  => 'Fehlende Konfiguration',
                'rowColor' => self::COLOR_REMOVED,
            ];
        }
        if ($values !== []) {
            return null;
        }
        return [
            'id'      => 'empty',
            'room'    => 'Keine Daten',
            'domain'  => '',
            'details' => 'Keine Räume gefunden oder Skripte liefern kein Array.',
            'status'  => 'Leer',
            'rowColor' => self::COLOR_CHANGED,
        ];
    }
}
