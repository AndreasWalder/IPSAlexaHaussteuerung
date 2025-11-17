<?php

/**
 * ============================================================
 * ROOMS CATALOG CONFIGURATOR — IP-Symcon Modul
 * ============================================================
 *
 * Änderungsverlauf
 * 2025-11-16: Basisversion
 * - Kontext-Auswahl: Raum / Domain / Gruppe
 * - Eintragsliste (Key, Label, Entity, Icon, Order)
 * - Laden/Speichern aus/in RoomsCatalogEdit-Script
 *
 * 2025-11-16: Erweiterung Jalousie / Entity-Felder
 * - Zusätzliche Spalten: selected, controlId, statusId, tiltId, speechKey
 * - Entity-Zuweisung über SelectObject auf markierte Zeilen
 *   (Haupt-Entity / Steuer-ID / Status-ID / Tilt-ID)
 * - Button zum Kopieren von RoomsCatalogEdit → produktiver RoomsCatalog
 *
 * 2025-11-16: Validierungsfarben & Diff-Vorschau
 * - Zeilenfärbung (rowColor) nach Vollständigkeit: rot/gelb/normal
 * - Diff-Vorschau produktiver RoomsCatalog vs. Edit (Textausgabe)
 *
 * 2025-11-17: Globaler Listenmodus + Filter
 * - Kein SelectedRoom/SelectedDomain/SelectedGroup mehr
 * - Liste enthält alle Räume / Domains / Gruppen
 * - Filter: Raum + Domain (arbeiten nur auf Anzeige)
 * - Speichern aktualisiert RoomsCatalogEdit strukturiert je Raum/Domain/Gruppe
 *
 * 2025-11-17: Fix WritePropertyString / Filter-Position
 * - WritePropertyString-Aufrufe entfernt (nur Attribute für Runtime)
 * - Filterzeile unter "Einträge (alle Räume / Domains)" verschoben
 *
 * 2025-11-17: Filter aus RuntimeEntries
 * - Raum-/Domain-Filter werden aus der Eintragsliste gebaut
 *   (unabhängig vom geladenen Catalog)
 */

declare(strict_types=1);

class RoomsCatalogConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('RoomsCatalogScriptID', 0);
        $this->RegisterPropertyInteger('RoomsCatalogEditScriptID', 0);

        $this->RegisterPropertyString('Entries', '[]');
        $this->RegisterPropertyString('FilterRoom', '');
        $this->RegisterPropertyString('FilterDomain', '');

        $this->RegisterAttributeInteger('RuntimeRoomsCatalogScriptID', 0);
        $this->RegisterAttributeInteger('RuntimeRoomsCatalogEditScriptID', 0);
        $this->RegisterAttributeString('RuntimeEntries', '[]');
        $this->RegisterAttributeString('RuntimeFilterRoom', '');
        $this->RegisterAttributeString('RuntimeFilterDomain', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->logDebug('ApplyChanges START');

        $this->synchronizeRuntimeStateFromProperties();

        $entries = $this->getRuntimeEntries();
        if ($entries === []) {
            $this->logDebug('ApplyChanges: RuntimeEntries leer → lade aus Catalog');
            $this->reloadAllFromCatalog();
        } else {
            $this->logDebug('ApplyChanges: RuntimeEntries bereits vorhanden, count=' . count($entries));
        }

        $this->diagnoseCatalog();

        $this->logDebug('ApplyChanges ENDE');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'RoomsCatalogScriptID':
                $this->setActiveRoomsCatalogScriptID((int)$Value);
                $this->reloadAllFromCatalog();
                break;

            case 'RoomsCatalogEditScriptID':
                $this->setActiveRoomsCatalogEditScriptID((int)$Value);
                $this->reloadAllFromCatalog();
                break;

            case 'FilterRoom':
                $this->setFilterRoom((string)$Value);
                break;

            case 'FilterDomain':
                $this->setFilterDomain((string)$Value);
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }

        $this->ReloadForm();
    }

    public function GetConfigurationForm()
    {
        $allEntries = $this->getRuntimeEntries();
        $this->logDebug('GetConfigurationForm: RuntimeEntries total=' . count($allEntries));

        $filterRoom   = $this->getFilterRoom();
        $filterDomain = $this->getFilterDomain();

        $roomOptions    = $this->buildRoomFilterOptionsFromEntries($allEntries);
        $domainOptions  = $this->buildDomainFilterOptionsFromEntries($allEntries);

        $this->logDebug(
            'GetConfigurationForm: FilterRoom="' . $filterRoom .
            '", FilterDomain="' . $filterDomain .
            '", RoomsOptionCount=' . count($roomOptions) .
            ', DomainOptionCount=' . count($domainOptions)
        );

        $entries = $this->applyFilters($allEntries, $filterRoom, $filterDomain);
        $this->logDebug('GetConfigurationForm: sichtbare Einträge=' . count($entries));

        $entries = $this->applyRowColors($entries);

        $form = [
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'RoomsCatalog Scripts',
                    'items'   => [
                        [
                            'type'     => 'SelectScript',
                            'name'     => 'RoomsCatalogScriptID',
                            'caption'  => 'Produktiver RoomsCatalog',
                            'value'    => $this->getActiveRoomsCatalogScriptID(),
                            'onChange' => 'IPS_RequestAction($id, "RoomsCatalogScriptID", $RoomsCatalogScriptID);'
                        ],
                        [
                            'type'     => 'SelectScript',
                            'name'     => 'RoomsCatalogEditScriptID',
                            'caption'  => 'RoomsCatalog Edit-Script',
                            'value'    => $this->getActiveRoomsCatalogEditScriptID(),
                            'onChange' => 'IPS_RequestAction($id, "RoomsCatalogEditScriptID", $RoomsCatalogEditScriptID);'
                        ]
                    ]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Einträge (alle Räume / Domains)',
                    'items'   => [
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'     => 'Select',
                                    'name'     => 'FilterRoom',
                                    'caption'  => 'Raum-Filter',
                                    'options'  => $roomOptions,
                                    'value'    => $filterRoom,
                                    'onChange' => 'IPS_RequestAction($id, "FilterRoom", $FilterRoom);'
                                ],
                                [
                                    'type'     => 'Select',
                                    'name'     => 'FilterDomain',
                                    'caption'  => 'Domain-Filter',
                                    'options'  => $domainOptions,
                                    'value'    => $filterDomain,
                                    'onChange' => 'IPS_RequestAction($id, "FilterDomain", $FilterDomain);'
                                ]
                            ]
                        ],
                        [
                            'type'     => 'List',
                            'name'     => 'Entries',
                            'caption'  => 'Einträge',
                            'rowCount' => 20,
                            'add'      => true,
                            'delete'   => true,
                            'sort'     => false,
                            'columns'  => [
                                [
                                    'caption' => 'Markiert',
                                    'name'    => 'selected',
                                    'width'   => '60px',
                                    'add'     => false,
                                    'edit'    => ['type' => 'CheckBox']
                                ],
                                [
                                    'caption' => 'Raum-Key',
                                    'name'    => 'roomKey',
                                    'width'   => '100px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Raum-Label',
                                    'name'    => 'roomLabel',
                                    'width'   => '150px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Domain',
                                    'name'    => 'domain',
                                    'width'   => '90px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Gruppe',
                                    'name'    => 'group',
                                    'width'   => '110px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Key',
                                    'name'    => 'key',
                                    'width'   => '120px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Label',
                                    'name'    => 'label',
                                    'width'   => '180px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'EntityId',
                                    'name'    => 'entityId',
                                    'width'   => '90px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'Entity-Name',
                                    'name'    => 'entityName',
                                    'width'   => '200px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'ControlId',
                                    'name'    => 'controlId',
                                    'width'   => '90px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'StatusId',
                                    'name'    => 'statusId',
                                    'width'   => '90px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'TiltId',
                                    'name'    => 'tiltId',
                                    'width'   => '90px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'Sprach-Key',
                                    'name'    => 'speechKey',
                                    'width'   => '160px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Icon',
                                    'name'    => 'icon',
                                    'width'   => '140px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Order',
                                    'name'    => 'order',
                                    'width'   => '70px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'Farbe',
                                    'name'    => 'rowColor',
                                    'width'   => '80px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ]
                            ],
                            'values' => $entries
                        ]
                    ]
                ]
            ],
            'actions'  => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Laden/Speichern & Diff',
                    'items'   => [
                        [
                            'type'    => 'Button',
                            'caption' => 'Alle Einträge aus RoomsCatalogEdit / produktiv neu laden',
                            'onClick' => 'RCC_ReloadAll($id);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Alle Einträge in RoomsCatalogEdit speichern',
                            'onClick' => 'RCC_SaveAll($id, json_encode($Entries));'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Diff: produktiv vs. Edit (Textausgabe)',
                            'onClick' => 'RCC_ShowDiff($id);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'RoomsCatalogEdit → produktiver RoomsCatalog kopieren',
                            'onClick' => 'RCC_ApplyEditToProductive($id);'
                        ]
                    ]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Entity-Zuweisung (auf markierte Zeilen)',
                    'items'   => [
                        [
                            'type'    => 'SelectObject',
                            'name'    => 'SelectedObject',
                            'caption' => 'IP-Symcon Objekt wählen'
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'Hinweis: In der Liste Zeilen über "Markiert" auswählen und dann eine der Aktionen ausführen.'
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Button',
                                    'caption' => 'Als Haupt-Entity setzen',
                                    'onClick' => 'RCC_ApplySelectedObjectMain($id, $SelectedObject, json_encode($Entries));'
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'Als Steuer-ID setzen',
                                    'onClick' => 'RCC_ApplySelectedObjectControl($id, $SelectedObject, json_encode($Entries));'
                                ]
                            ]
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Button',
                                    'caption' => 'Als Status-ID setzen',
                                    'onClick' => 'RCC_ApplySelectedObjectStatus($id, $SelectedObject, json_encode($Entries));'
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'Als Tilt-ID setzen',
                                    'onClick' => 'RCC_ApplySelectedObjectTilt($id, $SelectedObject, json_encode($Entries));'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        return json_encode($form);
    }

    // ========================================================================================
    // Public API (Buttons)
    // ========================================================================================

    public function ReloadAll()
    {
        $this->reloadAllFromCatalog();
        $this->ReloadForm();
        echo 'Alle Einträge wurden aus RoomsCatalogEdit / produktiv neu geladen.';
    }

    public function SaveAll(string $entriesJson)
    {
        $entries = json_decode($entriesJson, true);
        if (!is_array($entries)) {
            $entries = [];
        }

        $this->logDebug('SaveAll: Einträge aus UI count=' . count($entries));

        $catalog = $this->loadRoomsCatalogEdit();
        if ($catalog === []) {
            $this->logDebug('SaveAll: RoomsCatalogEdit leer → versuche produktiven Catalog als Basis');
            $catalog = $this->loadRoomsCatalog();
        }
        if ($catalog === []) {
            echo 'Weder RoomsCatalogEdit noch produktiver RoomsCatalog konnten geladen werden.';
            return;
        }

        foreach ($entries as $row) {
            $roomKey   = trim((string)($row['roomKey'] ?? ''));
            $domainKey = trim((string)($row['domain'] ?? ''));
            $groupKey  = trim((string)($row['group'] ?? ''));
            $entryKey  = trim((string)($row['key'] ?? ''));

            if ($roomKey === '' || $domainKey === '' || $groupKey === '' || $entryKey === '') {
                continue;
            }

            if (!isset($catalog[$roomKey])) {
                $catalog[$roomKey] = [
                    'display' => $roomKey,
                    'domains' => []
                ];
            }
            if (!isset($catalog[$roomKey]['domains'])) {
                $catalog[$roomKey]['domains'] = [];
            }
            if (!isset($catalog[$roomKey]['domains'][$domainKey])) {
                $catalog[$roomKey]['domains'][$domainKey] = [];
            }

            $domainRef = &$catalog[$roomKey]['domains'][$domainKey];

            if (!isset($domainRef[$groupKey])) {
                $domainRef[$groupKey] = [];
            }

            $groupRef = &$domainRef[$groupKey];

            if ($this->isEntryList($groupRef)) {
                if (!isset($groupRef[$entryKey]) || !is_array($groupRef[$entryKey])) {
                    $groupRef[$entryKey] = [];
                }
                $cfg = &$groupRef[$entryKey];
            } else {
                $cfg = &$groupRef;
            }

            $cfg['label']      = (string)($row['label'] ?? '');
            $cfg['entityId']   = (int)($row['entityId'] ?? 0);
            $cfg['entityName'] = (string)($row['entityName'] ?? '');
            $cfg['controlId']  = (int)($row['controlId'] ?? 0);
            $cfg['statusId']   = (int)($row['statusId'] ?? 0);
            $cfg['tiltId']     = (int)($row['tiltId'] ?? 0);
            $cfg['speechKey']  = (string)($row['speechKey'] ?? '');
            $cfg['icon']       = (string)($row['icon'] ?? '');
            $cfg['order']      = (int)($row['order'] ?? 0);
        }

        $this->writeRoomsCatalogEdit($catalog);

        $this->setRuntimeEntries($entries);

        $this->ReloadForm();

        echo 'Alle Einträge wurden in RoomsCatalogEdit gespeichert.';
    }

    public function ApplySelectedObjectMain(int $SelectedObject, string $entriesJson)
    {
        $this->applySelectedObjectToEntries($SelectedObject, $entriesJson, 'main');
    }

    public function ApplySelectedObjectControl(int $SelectedObject, string $entriesJson)
    {
        $this->applySelectedObjectToEntries($SelectedObject, $entriesJson, 'control');
    }

    public function ApplySelectedObjectStatus(int $SelectedObject, string $entriesJson)
    {
        $this->applySelectedObjectToEntries($SelectedObject, $entriesJson, 'status');
    }

    public function ApplySelectedObjectTilt(int $SelectedObject, string $entriesJson)
    {
        $this->applySelectedObjectToEntries($SelectedObject, $entriesJson, 'tilt');
    }

    public function ApplyEditToProductive()
    {
        $prodId = $this->getActiveRoomsCatalogScriptID();
        $editId = $this->getActiveRoomsCatalogEditScriptID();

        if ($prodId <= 0 || !IPS_ScriptExists($prodId)) {
            echo 'Produktiver RoomsCatalog (RoomsCatalogScriptID) ist nicht gesetzt oder existiert nicht.';
            return;
        }
        if ($editId <= 0 || !IPS_ScriptExists($editId)) {
            echo 'RoomsCatalogEditScriptID ist nicht gesetzt oder Script existiert nicht.';
            return;
        }

        $editContent = IPS_GetScriptContent($editId);
        if ($editContent === '') {
            echo 'RoomsCatalogEdit ist leer. Abbruch.';
            return;
        }

        IPS_SetScriptContent($prodId, $editContent);

        echo 'RoomsCatalogEdit wurde in den produktiven RoomsCatalog kopiert.';
    }

    public function ShowDiff()
    {
        $prod = $this->loadRoomsCatalog();
        $edit = $this->loadRoomsCatalogEdit();

        $prodRooms = $this->extractRoomsFromCatalog($prod);
        $editRooms = $this->extractRoomsFromCatalog($edit);

        $lines = [];

        $allRoomKeys = array_unique(array_merge(array_keys($prodRooms), array_keys($editRooms)));
        sort($allRoomKeys);

        foreach ($allRoomKeys as $roomKey) {
            $prodDomains = $prodRooms[$roomKey]['domains'] ?? [];
            $editDomains = $editRooms[$roomKey]['domains'] ?? [];

            $allDomainKeys = array_unique(array_merge(array_keys($prodDomains), array_keys($editDomains)));
            sort($allDomainKeys);

            foreach ($allDomainKeys as $domainKey) {
                $prodGroups = $prodDomains[$domainKey] ?? [];
                $editGroups = $editDomains[$domainKey] ?? [];

                $allGroupKeys = array_unique(array_merge(array_keys($prodGroups), array_keys($editGroups)));
                sort($allGroupKeys);

                foreach ($allGroupKeys as $groupKey) {
                    $prodEntries = $prodGroups[$groupKey] ?? [];
                    $editEntries = $editGroups[$groupKey] ?? [];

                    if (!is_array($prodEntries)) {
                        $prodEntries = [];
                    }
                    if (!is_array($editEntries)) {
                        $editEntries = [];
                    }

                    $allEntryKeys = array_unique(array_merge(array_keys($prodEntries), array_keys($editEntries)));
                    sort($allEntryKeys);

                    foreach ($allEntryKeys as $entryKey) {
                        $path = $roomKey . '.' . $domainKey . '.' . $groupKey . '.' . $entryKey;

                        $hasProd = array_key_exists($entryKey, $prodEntries);
                        $hasEdit = array_key_exists($entryKey, $editEntries);

                        if ($hasProd && !$hasEdit) {
                            $lines[] = '[DEL] ' . $path;
                        } elseif (!$hasProd && $hasEdit) {
                            $lines[] = '[NEW] ' . $path;
                        } else {
                            $prodCfg = $prodEntries[$entryKey];
                            $editCfg = $editEntries[$entryKey];

                            if (!is_array($prodCfg)) {
                                $prodCfg = [];
                            }
                            if (!is_array($editCfg)) {
                                $editCfg = [];
                            }

                            $prodJson = json_encode($this->normalizeCfg($prodCfg));
                            $editJson = json_encode($this->normalizeCfg($editCfg));

                            if ($prodJson !== $editJson) {
                                $lines[] = '[CHG] ' . $path;
                            }
                        }
                    }
                }
            }
        }

        if ($lines === []) {
            echo "Keine Unterschiede zwischen produktivem RoomsCatalog und Edit gefunden.";
        } else {
            echo implode("\n", $lines);
        }
    }

    // ========================================================================================
    // Interne Helfer
    // ========================================================================================

    private function reloadAllFromCatalog(): void
    {
        $this->logDebug('reloadAllFromCatalog: START');

        $catalog = $this->loadRoomsCatalogEdit();
        if ($catalog === []) {
            $catalog = $this->loadRoomsCatalog();
        }

        $rooms   = $this->extractRoomsFromCatalog($catalog);
        $entries = $this->buildEntriesFromRooms($rooms);

        $this->logDebug('reloadAllFromCatalog: erzeugte Einträge=' . count($entries));

        $this->setRuntimeEntries($entries);
    }

    private function loadRoomsCatalog(): array
    {
        $scriptId = $this->getActiveRoomsCatalogScriptID();
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $this->logDebug('loadRoomsCatalog: ScriptID leer oder Script existiert nicht');
            return [];
        }

        $file = IPS_GetScriptFile($scriptId);
        if ($file === '' || !file_exists($file)) {
            $this->logDebug('loadRoomsCatalog: ScriptFile nicht gefunden: ' . $file);
            return [];
        }

        $catalog = @require $file;
        if (!is_array($catalog)) {
            $this->logDebug('loadRoomsCatalog: require() lieferte kein Array');
            return [];
        }

        $this->logDebug('loadRoomsCatalog: Top-Level-Keys: ' . implode(', ', array_keys($catalog)));
        return $catalog;
    }

    private function loadRoomsCatalogEdit(): array
    {
        $scriptId = $this->getActiveRoomsCatalogEditScriptID();
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $this->logDebug('loadRoomsCatalogEdit: Edit-ScriptID leer oder Script existiert nicht → Fallback PROD');
            return [];
        }

        $file = IPS_GetScriptFile($scriptId);
        if ($file === '' || !file_exists($file)) {
            $this->logDebug('loadRoomsCatalogEdit: ScriptFile nicht gefunden: ' . $file . ' → Fallback PROD');
            return [];
        }

        $catalog = @require $file;
        if (!is_array($catalog)) {
            $this->logDebug('loadRoomsCatalogEdit: require() lieferte kein Array → Fallback PROD');
            return [];
        }

        $this->logDebug('loadRoomsCatalogEdit: Top-Level-Keys: ' . implode(', ', array_keys($catalog)));
        return $catalog;
    }

    private function writeRoomsCatalogEdit(array $catalog): void
    {
        $scriptId = $this->getActiveRoomsCatalogEditScriptID();
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            echo 'RoomsCatalogEditScriptID ist nicht gesetzt oder Script existiert nicht.';
            return;
        }

        $php = "<?php\nreturn " . var_export($catalog, true) . ";\n";
        IPS_SetScriptContent($scriptId, $php);
    }

    private function extractRoomsFromCatalog(array $catalog): array
    {
        $rooms = [];

        foreach ($catalog as $key => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            if (!isset($cfg['display'])) {
                continue;
            }
            $rooms[$key] = $cfg;
        }

        $this->logDebug('extractRoomsFromCatalog: Räume=' . count($rooms));

        return $rooms;
    }

    private function buildEntriesFromRooms(array $rooms): array
    {
        $rows = [];

        foreach ($rooms as $roomKey => $roomCfg) {
            $roomLabel = (string)($roomCfg['display'] ?? $roomKey);
            $domains   = $roomCfg['domains'] ?? [];

            foreach ($domains as $domainKey => $domainCfg) {
                if (!is_array($domainCfg)) {
                    continue;
                }

                foreach ($domainCfg as $groupKey => $groupCfg) {
                    if (!is_array($groupCfg)) {
                        continue;
                    }

                    if ($this->isEntryList($groupCfg)) {
                        foreach ($groupCfg as $entryKey => $cfg) {
                            if (!is_array($cfg)) {
                                $cfg = [];
                            }
                            $rows[] = $this->rowFromCfg(
                                (string)$roomKey,
                                $roomLabel,
                                (string)$domainKey,
                                (string)$groupKey,
                                (string)$entryKey,
                                $cfg
                            );
                        }
                    } else {
                        $entryKey = (string)$groupKey;
                        $cfg      = $groupCfg;
                        $rows[]   = $this->rowFromCfg(
                            (string)$roomKey,
                            $roomLabel,
                            (string)$domainKey,
                            (string)$groupKey,
                            $entryKey,
                            $cfg
                        );
                    }
                }
            }
        }

        return $rows;
    }

    private function rowFromCfg(string $roomKey, string $roomLabel, string $domain, string $group, string $entryKey, array $cfg): array
    {
        return [
            'selected'   => false,
            'roomKey'    => $roomKey,
            'roomLabel'  => $roomLabel,
            'domain'     => $domain,
            'group'      => $group,
            'key'        => $entryKey,
            'label'      => (string)($cfg['label'] ?? ($cfg['title'] ?? $entryKey)),
            'entityId'   => (int)($cfg['entityId'] ?? 0),
            'entityName' => (string)($cfg['entityName'] ?? ''),
            'controlId'  => (int)($cfg['controlId'] ?? 0),
            'statusId'   => (int)($cfg['statusId'] ?? 0),
            'tiltId'     => (int)($cfg['tiltId'] ?? 0),
            'speechKey'  => (string)($cfg['speechKey'] ?? ''),
            'icon'       => (string)($cfg['icon'] ?? ''),
            'order'      => (int)($cfg['order'] ?? 0)
        ];
    }

    private function isEntryList(array $groupCfg): bool
    {
        if ($groupCfg === []) {
            return false;
        }

        $allArrays    = true;
        $hasTitleLike = false;

        foreach ($groupCfg as $v) {
            if (!is_array($v)) {
                $allArrays = false;
                break;
            }
            if (array_key_exists('title', $v) || array_key_exists('label', $v)) {
                $hasTitleLike = true;
            }
        }

        return $allArrays && $hasTitleLike;
    }

    private function applySelectedObjectToEntries(int $objectId, string $entriesJson, string $mode): void
    {
        if ($objectId <= 0 || !IPS_ObjectExists($objectId)) {
            echo 'Kein gültiges Objekt ausgewählt.';
            return;
        }

        $entries = json_decode($entriesJson, true);
        if (!is_array($entries)) {
            $entries = [];
        }

        foreach ($entries as &$row) {
            $selected = !empty($row['selected']);
            if (!$selected) {
                continue;
            }

            switch ($mode) {
                case 'main':
                    $row['entityId']   = $objectId;
                    $row['entityName'] = IPS_GetName($objectId);
                    break;

                case 'control':
                    $row['controlId'] = $objectId;
                    break;

                case 'status':
                    $row['statusId'] = $objectId;
                    break;

                case 'tilt':
                    $row['tiltId'] = $objectId;
                    break;
            }
        }
        unset($row);

        $newJson = json_encode($entries);
        $this->setRuntimeEntriesFromJson($newJson);
        $this->ReloadForm();

        echo 'Objekt wurde auf markierte Zeilen angewendet.';
    }

    private function applyRowColors(array $entries): array
    {
        foreach ($entries as &$row) {
            $key      = trim((string)($row['key'] ?? ''));
            $label    = trim((string)($row['label'] ?? ''));
            $entityId = (int)($row['entityId'] ?? 0);
            $control  = (int)($row['controlId'] ?? 0);
            $status   = (int)($row['statusId'] ?? 0);
            $tilt     = (int)($row['tiltId'] ?? 0);
            $domain   = (string)($row['domain'] ?? '');

            $rowColor = '';

            if ($key === '' || $label === '' || ($entityId === 0 && $control === 0)) {
                $rowColor = '#FFCDD2';
            } else {
                if ($domain === 'jalousie') {
                    if ($status === 0 || $tilt === 0) {
                        $rowColor = '#FFF9C4';
                    }
                } elseif ($domain === 'licht') {
                    if ($entityId === 0 && $control === 0) {
                        $rowColor = '#FFF9C4';
                    }
                }
            }

            if ($rowColor !== '') {
                $row['rowColor'] = $rowColor;
            } else {
                unset($row['rowColor']);
            }
        }
        unset($row);

        return $entries;
    }

    private function normalizeCfg(array $cfg): array
    {
        $keys = [
            'label', 'entityId', 'entityName',
            'controlId', 'statusId', 'tiltId',
            'speechKey', 'icon', 'order'
        ];
        $norm = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $cfg)) {
                $norm[$k] = $cfg[$k];
            }
        }
        ksort($norm);
        return $norm;
    }

    private function applyFilters(array $entries, string $filterRoom, string $filterDomain): array
    {
        if ($filterRoom === '' && $filterDomain === '') {
            return $entries;
        }

        $out = [];
        foreach ($entries as $row) {
            if ($filterRoom !== '' && (string)($row['roomKey'] ?? '') !== $filterRoom) {
                continue;
            }
            if ($filterDomain !== '' && (string)($row['domain'] ?? '') !== $filterDomain) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function buildRoomFilterOptionsFromEntries(array $entries): array
    {
        $rooms = [];
        foreach ($entries as $row) {
            $key = trim((string)($row['roomKey'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = trim((string)($row['roomLabel'] ?? $key));
            $rooms[$key] = $label;
        }

        ksort($rooms);

        $options   = [];
        $options[] = ['caption' => 'Alle', 'value' => ''];

        foreach ($rooms as $key => $label) {
            $options[] = [
                'caption' => $label . ' [' . $key . ']',
                'value'   => $key
            ];
        }

        return $options;
    }

    private function buildDomainFilterOptionsFromEntries(array $entries): array
    {
        $domains = [];
        foreach ($entries as $row) {
            $d = trim((string)($row['domain'] ?? ''));
            if ($d === '') {
                continue;
            }
            $domains[$d] = true;
        }

        $keys = array_keys($domains);
        sort($keys);

        $options   = [];
        $options[] = ['caption' => 'Alle', 'value' => ''];

        foreach ($keys as $d) {
            $options[] = [
                'caption' => $d,
                'value'   => $d
            ];
        }

        return $options;
    }

    private function diagnoseCatalog(): void
    {
        $this->logDebug('===============================');
        $this->logDebug('START RoomsCatalog-Diagnose');

        $prod      = $this->loadRoomsCatalog();
        $roomsProd = $this->extractRoomsFromCatalog($prod);
        $this->logDebug('RoomsCatalog: Räume=' . count($roomsProd));

        $edit      = $this->loadRoomsCatalogEdit();
        $roomsEdit = $this->extractRoomsFromCatalog($edit);
        $this->logDebug('RoomsCatalogEdit: Räume=' . count($roomsEdit));

        $entries = $this->getRuntimeEntries();
        $this->logDebug('Aktuelle Liste: entries=' . count($entries));

        $this->logDebug('ENDE RoomsCatalog-Diagnose');
        $this->logDebug('===============================');
    }

    // ========================================================================================
    // Runtime-Sync & Getter/Setter
    // ========================================================================================

    private function synchronizeRuntimeStateFromProperties(): void
    {
        $this->syncAttributeInteger('RuntimeRoomsCatalogScriptID', $this->ReadPropertyInteger('RoomsCatalogScriptID'));
        $this->syncAttributeInteger('RuntimeRoomsCatalogEditScriptID', $this->ReadPropertyInteger('RoomsCatalogEditScriptID'));

        $attr = $this->ReadAttributeString('RuntimeEntries');
        if ($attr === '' || $attr === null) {
            $entriesProp = $this->ReadPropertyString('Entries');
            if ($entriesProp === '' || $entriesProp === null) {
                $entriesProp = '[]';
            }
            $this->WriteAttributeString('RuntimeEntries', $entriesProp);
        }
    }

    private function syncAttributeInteger(string $attribute, int $value): void
    {
        if ($this->ReadAttributeInteger($attribute) !== $value) {
            $this->WriteAttributeInteger($attribute, $value);
        }
    }

    private function getActiveRoomsCatalogScriptID(): int
    {
        $scriptId = $this->ReadAttributeInteger('RuntimeRoomsCatalogScriptID');
        if ($scriptId > 0) {
            return $scriptId;
        }
        $scriptId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        $this->WriteAttributeInteger('RuntimeRoomsCatalogScriptID', $scriptId);
        return $scriptId;
    }

    private function setActiveRoomsCatalogScriptID(int $scriptId): void
    {
        $this->WriteAttributeInteger('RuntimeRoomsCatalogScriptID', $scriptId);
    }

    private function getActiveRoomsCatalogEditScriptID(): int
    {
        $scriptId = $this->ReadAttributeInteger('RuntimeRoomsCatalogEditScriptID');
        if ($scriptId > 0) {
            return $scriptId;
        }
        $scriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $this->WriteAttributeInteger('RuntimeRoomsCatalogEditScriptID', $scriptId);
        return $scriptId;
    }

    private function setActiveRoomsCatalogEditScriptID(int $scriptId): void
    {
        $this->WriteAttributeInteger('RuntimeRoomsCatalogEditScriptID', $scriptId);
    }

    private function getRuntimeEntries(): array
    {
        $json = $this->ReadAttributeString('RuntimeEntries');
        if ($json === '' || $json === null) {
            $json = '[]';
        }
        $entries = json_decode($json, true);
        if (!is_array($entries)) {
            return [];
        }
        return $entries;
    }

    private function setRuntimeEntries(array $entries): void
    {
        $this->WriteAttributeString('RuntimeEntries', json_encode($entries));
    }

    private function setRuntimeEntriesFromJson(string $entriesJson): void
    {
        $entries = json_decode($entriesJson, true);
        if (!is_array($entries)) {
            $entries = [];
        }
        $this->setRuntimeEntries($entries);
    }

    private function getFilterRoom(): string
    {
        return $this->ReadAttributeString('RuntimeFilterRoom');
    }

    private function setFilterRoom(string $room): void
    {
        $this->WriteAttributeString('RuntimeFilterRoom', $room);
    }

    private function getFilterDomain(): string
    {
        return $this->ReadAttributeString('RuntimeFilterDomain');
    }

    private function setFilterDomain(string $domain): void
    {
        $this->WriteAttributeString('RuntimeFilterDomain', $domain);
    }

    private function logDebug(string $msg): void
    {
        IPS_LogMessage('Alexa', 'RCC-DEBUG: ' . $msg);
    }
}
