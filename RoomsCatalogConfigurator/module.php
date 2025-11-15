<?php
declare(strict_types=1);

class RoomsCatalogConfigurator extends IPSModule
{
    private const COLOR_NEW = '#DCFCE7';
    private const COLOR_REMOVED = '#FEF3C7';
    private const COLOR_CHANGED = '#FEE2E2';
    private const LOG_CHANNEL = 'Alexa';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('RoomsCatalogScriptId', 0);
        $this->RegisterPropertyInteger('RoomsCatalogEditScriptId', 0);
        $this->RegisterAttributeString('TreeFilter', '');
    }

    public function ApplyChanges()
    {
        $this->RegisterAttributeString('TreeFilter', $this->ReadAttributeString('TreeFilter'));
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $this->log('GetConfigurationForm invoked', [
            'roomsCatalogId'     => $this->ReadPropertyInteger('RoomsCatalogScriptId'),
            'roomsCatalogEditId' => $this->ReadPropertyInteger('RoomsCatalogEditScriptId'),
        ]);

        $values = $this->buildDiffRows();
        $errorMessage = $this->determineDataErrorMessage($values);

        $roomOptions = $this->buildRoomOptions();
        $defaultRoom = $roomOptions[0]['value'] ?? '';
        $domainOptions = $this->buildDomainOptions($defaultRoom);
        $defaultDomain = $domainOptions[0]['value'] ?? '';
        $elementOptions = $this->buildElementOptions($defaultRoom, $defaultDomain);
        $defaultElement = $elementOptions[0]['value'] ?? '';
        $treeVisible = $errorMessage === null;
        $treeElement = $this->buildTreeElement($treeVisible);
        $treeFilter = $this->ReadAttributeString('TreeFilter');
        $treeToolbar = $this->buildTreeToolbar($treeVisible, $treeFilter);

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
                $treeToolbar,
                $treeElement,
                [
                    'type'    => 'Label',
                    'name'    => 'TreeErrorLabel',
                    'caption' => $errorMessage ?? '',
                    'visible' => $errorMessage !== null,
                ],
                [
                    'type'    => 'PopupButton',
                    'caption' => 'Eintrag aus RoomsCatalog hinzufügen',
                    'visible' => $roomOptions !== [],
                    'popup'   => [
                        'caption' => 'Element übernehmen',
                        'items'   => [
                            [
                                'type'    => 'Select',
                                'name'    => 'AddRoomSelect',
                                'caption' => 'Raum',
                                'options' => $roomOptions,
                                'value'   => $defaultRoom,
                                'onChange' => 'IPS_RequestAction($id, "SelectAddRoom", $AddRoomSelect);',
                            ],
                            [
                                'type'    => 'Select',
                                'name'    => 'AddDomainSelect',
                                'caption' => 'Domain',
                                'options' => $domainOptions,
                                'value'   => $defaultDomain,
                                'enabled' => $domainOptions !== [],
                                'onChange' => 'IPS_RequestAction($id, "SelectAddDomain", json_encode(["room" => $AddRoomSelect, "domain" => $AddDomainSelect]));',
                            ],
                            [
                                'type'    => 'Select',
                                'name'    => 'AddElementSelect',
                                'caption' => 'Bereich/Element',
                                'options' => $elementOptions,
                                'value'   => $defaultElement,
                                'enabled' => $elementOptions !== [],
                            ],
                            [
                                'type'    => 'CheckBox',
                                'name'    => 'AddCopyRoom',
                                'caption' => 'Kompletten Raum übernehmen',
                                'value'   => false,
                            ],
                            [
                                'type'    => 'ValidationTextBox',
                                'name'    => 'AddCustomKey',
                                'caption' => 'Neuer Schlüssel (optional)',
                                'value'   => '',
                            ],
                        ],
                        'buttons' => [
                            [
                                'caption' => 'Übernehmen',
                                'onClick' => 'IPS_RequestAction($id, "AddElementFromCatalog", json_encode(["room" => $AddRoomSelect, "domain" => $AddDomainSelect, "element" => $AddElementSelect, "customKey" => $AddCustomKey, "copyRoom" => $AddCopyRoom]));',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    public function RequestAction($Ident, $Value)
    {
        $this->log('RequestAction received', ['ident' => $Ident]);
        try {
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
                case 'UpdateTreeFilter':
                    $this->handleTreeFilterUpdate((string) $Value);
                    break;
                case 'RefreshTree':
                    $this->updateTreeView($this->hasValidTreeData());
                    break;
                case 'SelectAddRoom':
                    $this->handleSelectAddRoom((string) $Value);
                    break;
                case 'SelectAddDomain':
                    $this->handleSelectAddDomain((string) $Value);
                    break;
                case 'AddElementFromCatalog':
                    $this->handleAddElementFromCatalog((string) $Value);
                    break;
                default:
                    throw new Exception(sprintf('Unsupported action "%s"', $Ident));
            }
        } catch (Throwable $exception) {
            $this->log('RequestAction failed', [
                'ident' => $Ident,
                'error' => $exception->getMessage(),
                'file'  => $exception->getFile(),
                'line'  => $exception->getLine(),
            ]);
            throw $exception;
        }
    }

    private function refreshDiffList(): void
    {
        $values = $this->buildDiffRows();
        $errorMessage = $this->determineDataErrorMessage($values);

        $this->UpdateFormField('TreeErrorLabel', 'caption', $errorMessage ?? '');
        $this->UpdateFormField('TreeErrorLabel', 'visible', $errorMessage !== null);

        $hasData = $errorMessage === null;
        $this->updateTreeView($hasData);
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
        $this->log('RoomsCatalogEdit updated', ['scriptId' => $editId]);
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
        $this->log('RoomsCatalog overwritten from edit script', ['roomsCatalogId' => $sourceId, 'editId' => $editId]);
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

    private function buildDomainRows(string $roomKey, ?array $orig, ?array $edit, bool $includeRoomColumn = true): array
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
            $row = [
                'id'       => sprintf('domain:%s:%s', $roomKey, $domain),
                'domain'   => $domain,
                'details'  => $this->buildDomainDetails($origDomain ?? $editDomain ?? []),
                'status'   => $state['label'],
                'rowColor' => $state['color'],
            ];
            if ($includeRoomColumn) {
                $row['room'] = '↳ ' . $this->resolveRoomTitle($roomKey, $orig, $edit);
            }
            $rows[] = $row;
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

        $file = $this->resolveScriptPath(IPS_GetScriptFile($scriptId));
        if ($file === '' || !is_file($file)) {
            return [];
        }

        $result = include $file;
        if (!is_array($result)) {
            return [];
        }

        return $result;
    }

    private function buildDiffTreeListValues(): array
    {
        $roomsCatalog = $this->loadRoomsCatalog($this->ReadPropertyInteger('RoomsCatalogScriptId'));
        $roomsEdit = $this->loadRoomsCatalog($this->ReadPropertyInteger('RoomsCatalogEditScriptId'));

        $keys = array_unique(array_merge(array_keys($roomsCatalog), array_keys($roomsEdit)));
        sort($keys);

        $values = [];
        $filter = mb_strtolower($this->ReadAttributeString('TreeFilter'));
        $nextId = 1;
        foreach ($keys as $key) {
            $orig = $roomsCatalog[$key] ?? null;
            $edit = $roomsEdit[$key] ?? null;
            $state = $this->diffState($orig, $edit);
            $rowId = $nextId++;
            $children = $this->buildDomainTreeValues($rowId, $orig, $edit, $filter, $nextId);
            $row = [
                'id'       => $rowId,
                'parent'   => 0,
                'label'    => $this->resolveRoomTitle($key, $orig, $edit),
                'details'  => $this->buildRoomDetails($orig ?? $edit ?? []),
                'status'   => $state['label'],
                'rowColor' => $state['color'],
            ];

            if ($this->passesTreeFilter($row, $filter) || $children !== []) {
                $values[] = $row;
                $values = array_merge($values, $children);
            }
        }

        return $values;
    }

    private function buildDomainTreeValues(int $parentId, ?array $orig, ?array $edit, string $filter, int &$nextId): array
    {
        $domainsOrig = is_array($orig['domains'] ?? null) ? array_keys($orig['domains']) : [];
        $domainsEdit = is_array($edit['domains'] ?? null) ? array_keys($edit['domains']) : [];
        $domainKeys = array_unique(array_merge($domainsOrig, $domainsEdit));
        sort($domainKeys);

        $children = [];
        foreach ($domainKeys as $domain) {
            $origDomain = $orig['domains'][$domain] ?? null;
            $editDomain = $edit['domains'][$domain] ?? null;
            $state = $this->diffState($origDomain, $editDomain);
            $row = [
                'id'       => $nextId++,
                'parent'   => $parentId,
                'label'    => $domain,
                'details'  => $this->buildDomainDetails($origDomain ?? $editDomain ?? []),
                'status'   => $state['label'],
                'rowColor' => $state['color'],
            ];
            if ($this->passesTreeFilter($row, $filter)) {
                $children[] = $row;
            }
        }

        return $children;
    }

    private function passesTreeFilter(array $row, string $filter): bool
    {
        if ($filter === '') {
            return true;
        }

        foreach (['label', 'details', 'status'] as $field) {
            $value = mb_strtolower((string) ($row[$field] ?? ''));
            if ($value !== '' && mb_strpos($value, $filter) !== false) {
                return true;
            }
        }

        return false;
    }

    private function handleTreeFilterUpdate(string $value): void
    {
        $normalized = trim($value);
        $this->WriteAttributeString('TreeFilter', $normalized);
        $this->UpdateFormField('TreeFilterInput', 'value', $normalized);
        $this->updateTreeView($this->hasValidTreeData());
    }

    private function hasValidTreeData(): bool
    {
        $values = $this->buildDiffRows();
        return $this->determineDataErrorMessage($values) === null;
    }

    private function resolveScriptPath(string $file): string
    {
        if ($file === '') {
            return '';
        }

        if ($this->isAbsolutePath($file)) {
            return $file;
        }

        return IPS_GetKernelDir() . 'scripts' . DIRECTORY_SEPARATOR . $file;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    private function updateProperty(string $name, $value): void
    {
        if (IPS_GetProperty($this->InstanceID, $name) === $value) {
            return;
        }
        IPS_SetProperty($this->InstanceID, $name, $value);
        IPS_ApplyChanges($this->InstanceID);
    }

    private function determineDataErrorMessage(array $values): ?string
    {
        if ($this->ReadPropertyInteger('RoomsCatalogScriptId') === 0) {
            return 'RoomsCatalog nicht ausgewählt. Bitte ursprüngliches RoomsCatalog-Skript auswählen.';
        }
        if ($values !== []) {
            return null;
        }
        return 'Keine Räume gefunden oder Skripte liefern kein Array.';
    }

    private function buildRoomOptions(): array
    {
        $roomsCatalog = $this->loadRoomsCatalog($this->ReadPropertyInteger('RoomsCatalogScriptId'));
        $roomsEdit = $this->loadRoomsCatalog($this->ReadPropertyInteger('RoomsCatalogEditScriptId'));

        $keys = array_unique(array_merge(array_keys($roomsCatalog), array_keys($roomsEdit)));
        sort($keys);

        $options = [];
        foreach ($keys as $key) {
            $title = $this->resolveRoomTitle($key, $roomsCatalog[$key] ?? null, $roomsEdit[$key] ?? null);
            $options[] = ['caption' => sprintf('%s (%s)', $title, $key), 'value' => $key];
        }

        return $options;
    }

    private function buildDomainOptions(string $roomKey): array
    {
        if ($roomKey === '') {
            return [];
        }

        $room = $this->getRoomData($roomKey);
        if (!isset($room['domains']) || !is_array($room['domains'])) {
            return [];
        }

        $domains = array_keys($room['domains']);
        sort($domains);
        $options = [];
        foreach ($domains as $domain) {
            $options[] = ['caption' => sprintf('%s (%s)', ucfirst($domain), $domain), 'value' => $domain];
        }

        return $options;
    }

    private function buildElementOptions(string $roomKey, string $domainKey): array
    {
        if ($roomKey === '' || $domainKey === '') {
            return [];
        }

        $room = $this->getRoomData($roomKey);
        if (!isset($room['domains'][$domainKey]) || !is_array($room['domains'][$domainKey])) {
            return [];
        }

        $domain = $room['domains'][$domainKey];
        $options = [
            ['caption' => 'Gesamte Domain übernehmen', 'value' => 'domain|' . $domainKey],
        ];

        foreach ($domain as $sectionKey => $sectionValue) {
            if (!is_array($sectionValue)) {
                continue;
            }

            if ($this->isLeafItem($sectionValue)) {
                $options[] = [
                    'caption' => sprintf('Element: %s', $this->resolveItemCaption($sectionValue, $sectionKey)),
                    'value'   => sprintf('item|%s|__root__|%s', $domainKey, $sectionKey),
                ];
                continue;
            }

            $options[] = [
                'caption' => sprintf('Bereich: %s', $sectionKey),
                'value'   => sprintf('section|%s|%s', $domainKey, $sectionKey),
            ];
            foreach ($sectionValue as $itemKey => $itemValue) {
                if (!is_array($itemValue)) {
                    continue;
                }
                if (!$this->isLeafItem($itemValue)) {
                    continue;
                }
                $options[] = [
                    'caption' => sprintf('  • %s', $this->resolveItemCaption($itemValue, $itemKey)),
                    'value'   => sprintf('item|%s|%s|%s', $domainKey, $sectionKey, $itemKey),
                ];
            }
        }

        return $options;
    }

    private function resolveItemCaption(array $item, string $fallback): string
    {
        if (isset($item['title']) && is_string($item['title'])) {
            return $item['title'];
        }
        if (isset($item['display']) && is_string($item['display'])) {
            return $item['display'];
        }
        if (isset($item['entityId']) && is_string($item['entityId'])) {
            return $item['entityId'];
        }
        if (isset($item['id'])) {
            return (string) $item['id'];
        }
        return $fallback;
    }

    private function getRoomData(string $roomKey): array
    {
        $roomsCatalog = $this->loadRoomsCatalog($this->ReadPropertyInteger('RoomsCatalogScriptId'));
        $roomsEdit = $this->loadRoomsCatalog($this->ReadPropertyInteger('RoomsCatalogEditScriptId'));

        if (isset($roomsCatalog[$roomKey])) {
            return $roomsCatalog[$roomKey];
        }
        if (isset($roomsEdit[$roomKey])) {
            return $roomsEdit[$roomKey];
        }
        return [];
    }

    private function isLeafItem(array $value): bool
    {
        if (isset($value['title']) || isset($value['entityId']) || isset($value['display']) || isset($value['id'])) {
            return true;
        }

        foreach ($value as $child) {
            if (is_array($child) && $this->isAssoc($child)) {
                return false;
            }
        }

        return true;
    }

    private function handleSelectAddRoom(string $roomKey): void
    {
        $this->log('handleSelectAddRoom', ['room' => $roomKey]);
        $domainOptions = $this->buildDomainOptions($roomKey);
        $domainValue = $domainOptions[0]['value'] ?? '';
        $this->UpdateFormField('AddDomainSelect', 'options', json_encode($domainOptions, JSON_THROW_ON_ERROR));
        $this->UpdateFormField('AddDomainSelect', 'value', $domainValue);
        $this->UpdateFormField('AddDomainSelect', 'enabled', $domainOptions !== []);

        $elementOptions = $this->buildElementOptions($roomKey, $domainValue);
        $this->UpdateFormField('AddElementSelect', 'options', json_encode($elementOptions, JSON_THROW_ON_ERROR));
        $this->UpdateFormField('AddElementSelect', 'value', $elementOptions[0]['value'] ?? '');
        $this->UpdateFormField('AddElementSelect', 'enabled', $elementOptions !== []);
    }

    private function handleSelectAddDomain(string $payload): void
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return;
        }

        $room = (string) ($data['room'] ?? '');
        $domain = (string) ($data['domain'] ?? '');

        $this->log('handleSelectAddDomain', ['room' => $room, 'domain' => $domain]);

        $elementOptions = $this->buildElementOptions($room, $domain);
        $this->UpdateFormField('AddElementSelect', 'options', json_encode($elementOptions, JSON_THROW_ON_ERROR));
        $this->UpdateFormField('AddElementSelect', 'value', $elementOptions[0]['value'] ?? '');
        $this->UpdateFormField('AddElementSelect', 'enabled', $elementOptions !== []);
    }

    private function handleAddElementFromCatalog(string $payload): void
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            throw new Exception('Ungültige Eingabe.');
        }

        $room = (string) ($data['room'] ?? '');
        $domain = (string) ($data['domain'] ?? '');
        $element = (string) ($data['element'] ?? '');
        $customKey = trim((string) ($data['customKey'] ?? ''));
        $copyRoom = !empty($data['copyRoom']);

        $this->log('handleAddElementFromCatalog', [
            'room'      => $room,
            'domain'    => $domain,
            'element'   => $element,
            'customKey' => $customKey,
            'copyRoom'  => $copyRoom,
        ]);

        $this->addElementToEditScript($room, $domain, $element, $customKey, $copyRoom);
        $this->refreshDiffList();
    }

    private function addElementToEditScript(string $roomKey, string $domainKey, string $elementSelection, string $customKey, bool $copyRoom): void
    {
        $sourceId = $this->ReadPropertyInteger('RoomsCatalogScriptId');
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptId');

        if ($sourceId <= 0 || !IPS_ScriptExists($sourceId)) {
            throw new Exception('RoomsCatalog Skript ungültig.');
        }
        if ($editId <= 0 || !IPS_ScriptExists($editId)) {
            throw new Exception('RoomsCatalogEdit Skript ungültig.');
        }

        $sourceRooms = $this->loadRoomsCatalog($sourceId);
        $editRooms = $this->loadRoomsCatalog($editId);

        if ($roomKey === '' || !isset($sourceRooms[$roomKey])) {
            throw new Exception('Raum im RoomsCatalog nicht gefunden.');
        }

        if ($copyRoom) {
            $targetRoomKey = $customKey !== '' ? $customKey : $roomKey;
            $editRooms[$targetRoomKey] = $sourceRooms[$roomKey];
            $this->writeRoomsCatalogScript($editId, $editRooms);
            $this->log('Room copied into edit script', ['room' => $roomKey, 'targetKey' => $targetRoomKey]);
            return;
        }

        if ($domainKey === '') {
            throw new Exception('Bitte eine Domain auswählen.');
        }

        [$type, $domainValue, $sectionKey, $itemKey] = array_pad(explode('|', $elementSelection), 4, '');
        if ($type === '') {
            throw new Exception('Bitte ein Element auswählen.');
        }

        $domainSourceKey = $domainValue !== '' ? $domainValue : $domainKey;

        if (!isset($sourceRooms[$roomKey]['domains'][$domainSourceKey])) {
            throw new Exception('Domain im RoomsCatalog nicht vorhanden.');
        }

        if (!isset($editRooms[$roomKey])) {
            $editRooms[$roomKey] = $sourceRooms[$roomKey];
        }
        if (!isset($editRooms[$roomKey]['domains']) || !is_array($editRooms[$roomKey]['domains'])) {
            $editRooms[$roomKey]['domains'] = [];
        }

        switch ($type) {
            case 'domain':
                $domainTargetKey = $customKey !== '' ? $customKey : $domainSourceKey;
                $editRooms[$roomKey]['domains'][$domainTargetKey] = $sourceRooms[$roomKey]['domains'][$domainSourceKey];
                $this->log('Domain copied', ['room' => $roomKey, 'sourceDomain' => $domainSourceKey, 'targetDomain' => $domainTargetKey]);
                break;
            case 'section':
                if (!isset($sourceRooms[$roomKey]['domains'][$domainSourceKey][$sectionKey])) {
                    throw new Exception('Bereich im RoomsCatalog nicht vorhanden.');
                }
                if (!isset($editRooms[$roomKey]['domains'][$domainSourceKey]) || !is_array($editRooms[$roomKey]['domains'][$domainSourceKey])) {
                    $editRooms[$roomKey]['domains'][$domainSourceKey] = [];
                }
                $sectionTargetKey = $customKey !== '' ? $customKey : $sectionKey;
                $editRooms[$roomKey]['domains'][$domainSourceKey][$sectionTargetKey] = $sourceRooms[$roomKey]['domains'][$domainSourceKey][$sectionKey];
                $this->log('Section copied', ['room' => $roomKey, 'domain' => $domainSourceKey, 'section' => $sectionKey, 'targetSection' => $sectionTargetKey]);
                break;
            case 'item':
                $targetSection = $sectionKey;
                $sourceDomain = $sourceRooms[$roomKey]['domains'][$domainSourceKey];
                $sectionExists = $targetSection === '__root__' ? true : isset($sourceDomain[$targetSection]);
                if (!$sectionExists) {
                    throw new Exception('Element im RoomsCatalog nicht vorhanden.');
                }
                $itemTargetKey = $customKey !== '' ? $customKey : $itemKey;
                if ($targetSection === '__root__') {
                    if (!isset($sourceDomain[$itemKey])) {
                        throw new Exception('Element im RoomsCatalog nicht vorhanden.');
                    }
                    $editRooms[$roomKey]['domains'][$domainSourceKey][$itemTargetKey] = $sourceDomain[$itemKey];
                    $this->log('Root item copied', ['room' => $roomKey, 'domain' => $domainSourceKey, 'item' => $itemKey, 'target' => $itemTargetKey]);
                    break;
                }
                if (!isset($editRooms[$roomKey]['domains'][$domainSourceKey][$targetSection]) || !is_array($editRooms[$roomKey]['domains'][$domainSourceKey][$targetSection])) {
                    $editRooms[$roomKey]['domains'][$domainSourceKey][$targetSection] = [];
                }
                if (!isset($sourceDomain[$targetSection][$itemKey])) {
                    throw new Exception('Element im RoomsCatalog nicht vorhanden.');
                }
                $editRooms[$roomKey]['domains'][$domainSourceKey][$targetSection][$itemTargetKey] = $sourceDomain[$targetSection][$itemKey];
                $this->log('Section item copied', ['room' => $roomKey, 'domain' => $domainSourceKey, 'section' => $targetSection, 'item' => $itemKey, 'target' => $itemTargetKey]);
                break;
            default:
                throw new Exception('Element-Auswahl unbekannt.');
        }

        ksort($editRooms[$roomKey]['domains']);
        $this->writeRoomsCatalogScript($editId, $editRooms);
    }

    private function writeRoomsCatalogScript(int $scriptId, array $data): void
    {
        $export = $this->exportArray($data);
        $content = "<?php\nreturn {$export};\n";
        IPS_SetScriptContent($scriptId, $content);
    }

    private function exportArray($value, int $indent = 0): string
    {
        if (!is_array($value)) {
            return $this->exportScalar($value);
        }

        if ($value === []) {
            return '[]';
        }

        $indentStr = str_repeat('    ', $indent);
        $nextIndent = str_repeat('    ', $indent + 1);
        $parts = [];
        $assoc = $this->isAssoc($value);
        foreach ($value as $key => $item) {
            $prefix = $assoc ? $nextIndent . $this->exportScalar($key) . ' => ' : $nextIndent;
            $parts[] = $prefix . $this->exportArray($item, $indent + 1);
        }

        return "[\n" . implode(",\n", $parts) . "\n" . $indentStr . ']';
    }

    private function exportScalar($value): string
    {
        if (is_string($value)) {
            return var_export($value, true);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        return (string) $value;
    }

    private function log(string $message, array $context = []): void
    {
        $contextString = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        IPS_LogMessage(self::LOG_CHANNEL, sprintf('RoomsCatalogConfigurator[%d] %s%s', $this->InstanceID, $message, $contextString));
    }

    private function buildTreeElement(bool $visible): array
    {
        return [
            'type'    => 'Configurator',
            'name'    => 'DiffTree',
            'caption' => 'Strukturierte Ansicht',
            'visible' => $visible,
            'rowCount' => 20,
            'columns' => [
                ['caption' => 'Raum / Bereich', 'name' => 'label', 'width' => '40%'],
                ['caption' => 'Details', 'name' => 'details', 'width' => '40%'],
                ['caption' => 'Status', 'name' => 'status', 'width' => '20%'],
            ],
            'values' => $visible ? $this->buildDiffTreeListValues() : [],
        ];
    }

    private function updateTreeView(bool $hasData): void
    {
        $this->UpdateFormField('DiffTree', 'visible', $hasData);
        $this->UpdateFormField('TreeToolbar', 'visible', $hasData);
        if ($hasData) {
            $this->UpdateFormField(
                'DiffTree',
                'values',
                json_encode($this->buildDiffTreeListValues(), JSON_THROW_ON_ERROR)
            );
        }
    }

    private function buildTreeToolbar(bool $visible, string $treeFilter): array
    {
        return [
            'type'    => 'RowLayout',
            'name'    => 'TreeToolbar',
            'visible' => $visible,
            'items'   => [
                [
                    'type'    => 'PopupButton',
                    'name'    => 'TreeFilterPopup',
                    'caption' => 'Filter',
                    'popup'   => [
                        'caption' => 'Struktur-Filter',
                        'items'   => [
                            [
                                'type'    => 'ValidationTextBox',
                                'name'    => 'TreeFilterInput',
                                'caption' => 'Filter',
                                'value'   => $treeFilter,
                                'onChange' => 'IPS_RequestAction($id, "UpdateTreeFilter", $TreeFilterInput);',
                            ],
                        ],
                        'buttons' => [
                            ['caption' => 'Schließen'],
                        ],
                    ],
                ],
                [
                    'type'    => 'Label',
                    'caption' => '|',
                ],
                [
                    'type'    => 'Button',
                    'name'    => 'TreeRefreshButton',
                    'caption' => 'Aktualisieren',
                    'onClick' => 'IPS_RequestAction($id, "RefreshTree", 0);',
                ],
            ],
        ];
    }
}
