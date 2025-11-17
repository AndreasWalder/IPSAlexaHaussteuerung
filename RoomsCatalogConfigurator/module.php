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
 * 2025-11-17: Flat-Catalog + Global-Liste + Filter
 * - Unterstützt RoomsCatalog ohne 'rooms'-Top-Level (direkt: buero, kueche, ... , global)
 * - Lädt immer alle Räume/Domains in eine Liste (roomKey, domain, group, key)
 * - Raum-/Domain-Filter (Dropdowns) oberhalb der Liste
 * - Speichern aller bearbeiteten Einträge zurück in RoomsCatalogEdit
 *   (nur label/entityId/entityName/controlId/statusId/tiltId/speechKey/icon/order)
 * - Entity-Zuweisung auf markierte Zeilen bleibt erhalten
 */

declare(strict_types=1);

class RoomsCatalogConfigurator extends IPSModule
{
    // =====================================================================
    // IPS-Lebenszyklus
    // =====================================================================

    public function Create()
    {
        parent::Create();

        // Grundkonfiguration
        $this->RegisterPropertyInteger('RoomsCatalogScriptID', 0);
        $this->RegisterPropertyInteger('RoomsCatalogEditScriptID', 0);

        // Alte Property (nicht mehr aktiv verwendet, aber belassen zur Kompatibilität)
        $this->RegisterPropertyString('Entries', '[]');

        // Laufzeit-Status
        $this->RegisterAttributeString('RuntimeEntries', '[]');         // alle Zeilen (un-gefiltert)
        $this->RegisterAttributeString('RuntimeFilterRoom', '');        // Raum-Filter
        $this->RegisterAttributeString('RuntimeFilterDomain', '');      // Domain-Filter
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->logDebug('ApplyChanges START');
        $this->reloadAllFromCatalog('edit_first');
        $this->logDebug('ApplyChanges ENDE');
    }

    // =====================================================================
    // RequestAction: nur für Filter-Dropdowns
    // =====================================================================

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'FilterRoom':
                $this->WriteAttributeString('RuntimeFilterRoom', (string)$Value);
                $this->ReloadForm();
                break;

            case 'FilterDomain':
                $this->WriteAttributeString('RuntimeFilterDomain', (string)$Value);
                $this->ReloadForm();
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    // =====================================================================
    // Formular
    // =====================================================================

    public function GetConfigurationForm()
    {
        $entriesAll     = $this->getRuntimeEntries();
        $filterRoom     = $this->ReadAttributeString('RuntimeFilterRoom');
        $filterDomain   = $this->ReadAttributeString('RuntimeFilterDomain');

        // Falls noch nichts geladen wurde (z.B. nach Modul-Update)
        if ($entriesAll === []) {
            $this->logDebug('GetConfigurationForm: RuntimeEntries leer → lade aus Catalog');
            $this->reloadAllFromCatalog('edit_first');
            $entriesAll = $this->getRuntimeEntries();
        }

        // Filter-Optionen aus allen Einträgen ableiten
        $roomOptions   = $this->buildRoomFilterOptions($entriesAll);
        $domainOptions = $this->buildDomainFilterOptions($entriesAll);

        // Filter anwenden
        $visibleEntries = $this->applyFilters($entriesAll, $filterRoom, $filterDomain);
        $visibleEntries = $this->applyRowColors($visibleEntries);

        $this->logDebug(sprintf(
            'GetConfigurationForm: RuntimeEntries total=%d, FilterRoom="%s", FilterDomain="%s", sichtbare Einträge=%d',
            count($entriesAll),
            $filterRoom,
            $filterDomain,
            count($visibleEntries)
        ));

        $form = [
            'elements' => [
                // ---------------------------------------------------------
                // Script-Auswahl
                // ---------------------------------------------------------
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'RoomsCatalog Scripts',
                    'items'   => [
                        [
                            'type'    => 'SelectScript',
                            'name'    => 'RoomsCatalogScriptID',
                            'caption' => 'Produktiver RoomsCatalog',
                            'value'   => $this->ReadPropertyInteger('RoomsCatalogScriptID')
                        ],
                        [
                            'type'    => 'SelectScript',
                            'name'    => 'RoomsCatalogEditScriptID',
                            'caption' => 'RoomsCatalog Edit-Script',
                            'value'   => $this->ReadPropertyInteger('RoomsCatalogEditScriptID')
                        ]
                    ]
                ],

                // ---------------------------------------------------------
                // Einträge + Filter
                // ---------------------------------------------------------
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Einträge (alle Räume / Domains)',
                    'items'   => [
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Select',
                                    'name'    => 'FilterRoom',
                                    'caption' => 'Raum-Filter',
                                    'options' => $roomOptions,
                                    'value'   => $filterRoom,
                                    'onChange' => 'IPS_RequestAction($id, "FilterRoom", $FilterRoom);'
                                ],
                                [
                                    'type'    => 'Select',
                                    'name'    => 'FilterDomain',
                                    'caption' => 'Domain-Filter',
                                    'options' => $domainOptions,
                                    'value'   => $filterDomain,
                                    'onChange' => 'IPS_RequestAction($id, "FilterDomain", $FilterDomain);'
                                ]
                            ]
                        ],
                        [
                            'type'     => 'List',
                            'name'     => 'Entries',
                            'caption'  => 'Einträge',
                            'rowCount' => 20,
                            'add'      => false,
                            'delete'   => false,
                            'sort'     => false,
                            'columns'  => [
                                [
                                    'caption' => 'Markiert',
                                    'name'    => 'selected',
                                    'width'   => '60px',
                                    'edit'    => ['type' => 'CheckBox']
                                ],
                                [
                                    'caption' => 'Raum-Key',
                                    'name'    => 'roomKey',
                                    'width'   => '80px'
                                ],
                                [
                                    'caption' => 'Raum-Label',
                                    'name'    => 'roomLabel',
                                    'width'   => '120px'
                                ],
                                [
                                    'caption' => 'Domain',
                                    'name'    => 'domain',
                                    'width'   => '80px'
                                ],
                                [
                                    'caption' => 'Gruppe',
                                    'name'    => 'group',
                                    'width'   => '90px'
                                ],
                                [
                                    'caption' => 'Key',
                                    'name'    => 'key',
                                    'width'   => '120px'
                                ],
                                [
                                    'caption' => 'Label',
                                    'name'    => 'label',
                                    'width'   => '200px',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'EntityId',
                                    'name'    => 'entityId',
                                    'width'   => '80px',
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'Entity-Name',
                                    'name'    => 'entityName',
                                    'width'   => '200px',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'ControlId',
                                    'name'    => 'controlId',
                                    'width'   => '80px',
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'StatusId',
                                    'name'    => 'statusId',
                                    'width'   => '80px',
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'TiltId',
                                    'name'    => 'tiltId',
                                    'width'   => '80px',
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'Sprach-Key',
                                    'name'    => 'speechKey',
                                    'width'   => '150px',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Icon',
                                    'name'    => 'icon',
                                    'width'   => '140px',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Order',
                                    'name'    => 'order',
                                    'width'   => '70px',
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'Farbe',
                                    'name'    => 'rowColor',
                                    'width'   => '80px'
                                ]
                            ],
                            'values' => $visibleEntries
                        ]
                    ]
                ]
            ],

            'actions' => [
                // ---------------------------------------------------------
                // Laden / Speichern
                // ---------------------------------------------------------
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Laden / Speichern',
                    'items'   => [
                        [
                            'type'    => 'Button',
                            'caption' => 'Einträge aus RoomsCatalogEdit neu laden',
                            'onClick' => 'RCC_ReloadFromEdit($id);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Einträge aus produktivem RoomsCatalog neu laden',
                            'onClick' => 'RCC_ReloadFromProductive($id);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Einträge in RoomsCatalogEdit speichern',
                            'onClick' => 'RCC_SaveAll($id, json_encode($Entries));'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'RoomsCatalogEdit → produktiver RoomsCatalog kopieren',
                            'onClick' => 'RCC_ApplyEditToProductive($id);'
                        ]
                    ]
                ],

                // ---------------------------------------------------------
                // Entity-Zuweisung
                // ---------------------------------------------------------
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

    // =====================================================================
    // Öffentliche API für Buttons
    // =====================================================================

    public function ReloadFromEdit()
    {
        $this->reloadAllFromCatalog('edit_only');
        $this->ReloadForm();
        echo 'Einträge wurden aus RoomsCatalogEdit neu geladen.';
    }

    public function ReloadFromProductive()
    {
        $this->reloadAllFromCatalog('prod_only');
        $this->ReloadForm();
        echo 'Einträge wurden aus dem produktiven RoomsCatalog neu geladen.';
    }

    public function SaveAll(string $entriesJson)
    {
        $entries = json_decode($entriesJson, true);
        if (!is_array($entries)) {
            echo 'Keine gültigen Einträge übergeben.';
            return;
        }

        $catalog = $this->loadRoomsCatalogEditRaw();
        if ($catalog === []) {
            echo 'RoomsCatalogEdit konnte nicht geladen werden (Script nicht gesetzt?).';
            return;
        }

        $updatedCount = 0;

        foreach ($entries as $row) {
            $roomKey = trim((string)($row['roomKey'] ?? ''));
            $domain  = trim((string)($row['domain'] ?? ''));
            $group   = trim((string)($row['group'] ?? ''));
            $key     = trim((string)($row['key'] ?? ''));

            if ($roomKey === '' || $domain === '' || $group === '' || $key === '') {
                continue;
            }

            if (!isset($catalog[$roomKey]['domains'][$domain][$group][$key]) ||
                !is_array($catalog[$roomKey]['domains'][$domain][$group][$key])) {
                $cfg = [];
            } else {
                $cfg = $catalog[$roomKey]['domains'][$domain][$group][$key];
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

            $catalog[$roomKey]['domains'][$domain][$group][$key] = $cfg;
            $updatedCount++;
        }

        $this->writeRoomsCatalogEditRaw($catalog);

        $this->logDebug(sprintf('SaveAll: %d Einträge aktualisiert.', $updatedCount));

        // Nach dem Speichern neu laden (aus Edit)
        $this->reloadAllFromCatalog('edit_only');
        $this->ReloadForm();

        echo sprintf('Einträge wurden gespeichert (%d aktualisiert).', $updatedCount);
    }

    public function ApplyEditToProductive()
    {
        $prodId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');

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

    // =====================================================================
    // Interne Helfer
    // =====================================================================

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

        $this->WriteAttributeString('RuntimeEntries', json_encode($entries));
        $this->ReloadForm();

        echo 'Objekt wurde auf markierte Zeilen angewendet.';
    }

    private function reloadAllFromCatalog(string $mode): void
    {
        $this->logDebug('reloadAllFromCatalog: START (mode=' . $mode . ')');

        $catalog = [];

        if ($mode === 'edit_only' || $mode === 'edit_first') {
            $catalog = $this->loadRoomsCatalogEditRaw();
        }
        if (($catalog === [] || $mode === 'prod_only') && $mode !== 'edit_only') {
            $catalog = $this->loadRoomsCatalogRaw();
        }

        $rooms   = $this->extractRoomsFromCatalog($catalog);
        $entries = $this->buildEntriesFromRooms($rooms);

        $this->WriteAttributeString('RuntimeEntries', json_encode($entries));

        $this->logDebug(sprintf(
            'reloadAllFromCatalog: Räume=%d, erzeugte Einträge=%d',
            count($rooms),
            count($entries)
        ));
    }

    private function loadRoomsCatalogRaw(): array
    {
        $scriptId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $this->logDebug('loadRoomsCatalog: ScriptFile nicht gefunden (RoomsCatalogScriptID).');
            return [];
        }

        $file = IPS_GetScriptFile($scriptId);
        if ($file === '' || !file_exists($file)) {
            $this->logDebug('loadRoomsCatalog: ScriptFile nicht gefunden: ' . $file);
            return [];
        }

        $catalog = @require $file;
        if (!is_array($catalog)) {
            $this->logDebug('loadRoomsCatalog: require() lieferte kein Array.');
            return [];
        }

        return $catalog;
    }

    private function loadRoomsCatalogEditRaw(): array
    {
        $scriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            // Fallback: produktives Script
            $this->logDebug('loadRoomsCatalogEdit: ScriptFile nicht gefunden → Fallback PROD');
            return $this->loadRoomsCatalogRaw();
        }

        $file = IPS_GetScriptFile($scriptId);
        if ($file === '' || !file_exists($file)) {
            $this->logDebug('loadRoomsCatalogEdit: ScriptFile nicht gefunden: ' . $file . ' → Fallback PROD');
            return $this->loadRoomsCatalogRaw();
        }

        $catalog = @require $file;
        if (!is_array($catalog)) {
            $this->logDebug('loadRoomsCatalogEdit: require() lieferte kein Array → Fallback PROD');
            return $this->loadRoomsCatalogRaw();
        }

        return $catalog;
    }

    private function writeRoomsCatalogEditRaw(array $catalog): void
    {
        $scriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            echo 'RoomsCatalogEditScriptID ist nicht gesetzt oder Script existiert nicht.';
            return;
        }

        $php = "<?php\nreturn " . var_export($catalog, true) . ";\n";
        IPS_SetScriptContent($scriptId, $php);
    }

    /**
     * Erwartet deinen flachen Catalog:
     *  [ 'buero' => [...], 'kueche' => [...], 'global' => [...], ... ]
     */
    private function extractRoomsFromCatalog(array $catalog): array
    {
        $rooms = [];

        foreach ($catalog as $roomKey => $roomCfg) {
            if ($roomKey === 'global') {
                continue; // globale Sektion überspringen
            }
            if (!is_array($roomCfg)) {
                continue;
            }

            $display = (string)($roomCfg['display'] ?? $roomKey);
            $domains = $roomCfg['domains'] ?? [];
            if (!is_array($domains)) {
                $domains = [];
            }

            $rooms[$roomKey] = [
                'key'     => (string)$roomKey,
                'display' => $display,
                'domains' => $domains
            ];
        }

        $this->logDebug(sprintf('extractRoomsFromCatalog: Räume=%d', count($rooms)));

        return $rooms;
    }

    private function buildEntriesFromRooms(array $rooms): array
    {
        $rows = [];

        foreach ($rooms as $roomKey => $room) {
            $roomLabel = (string)($room['display'] ?? $roomKey);
            $domains   = $room['domains'] ?? [];

            foreach ($domains as $domainKey => $domainCfg) {
                if (!is_array($domainCfg)) {
                    continue;
                }

                foreach ($domainCfg as $groupKey => $groupCfg) {
                    if (!is_array($groupCfg)) {
                        continue;
                    }

                    foreach ($groupCfg as $entryKey => $cfg) {
                        if (!is_array($cfg)) {
                            $cfg = [];
                        }

                        $rows[] = [
                            'selected'   => false,
                            'roomKey'    => (string)$roomKey,
                            'roomLabel'  => $roomLabel,
                            'domain'     => (string)$domainKey,
                            'group'      => (string)$groupKey,
                            'key'        => (string)$entryKey,
                            'label'      => (string)($cfg['label'] ?? ($cfg['title'] ?? '')),
                            'entityId'   => (int)($cfg['entityId'] ?? 0),
                            'entityName' => (string)($cfg['entityName'] ?? ''),
                            'controlId'  => (int)($cfg['controlId'] ?? 0),
                            'statusId'   => (int)($cfg['statusId'] ?? 0),
                            'tiltId'     => (int)($cfg['tiltId'] ?? 0),
                            'speechKey'  => (string)($cfg['speechKey'] ?? ''),
                            'icon'       => (string)($cfg['icon'] ?? ''),
                            'order'      => (int)($cfg['order'] ?? 0),
                            'rowColor'   => ''
                        ];
                    }
                }
            }
        }

        return $rows;
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

    private function applyFilters(array $entries, string $filterRoom, string $filterDomain): array
    {
        if ($filterRoom === '' && $filterDomain === '') {
            return $entries;
        }

        return array_values(array_filter($entries, function ($row) use ($filterRoom, $filterDomain) {
            if ($filterRoom !== '' && ($row['roomKey'] ?? '') !== $filterRoom) {
                return false;
            }
            if ($filterDomain !== '' && ($row['domain'] ?? '') !== $filterDomain) {
                return false;
            }
            return true;
        }));
    }

    private function buildRoomFilterOptions(array $entries): array
    {
        $options   = [];
        $options[] = ['caption' => 'Alle', 'value' => ''];

        $seen = [];

        foreach ($entries as $row) {
            $key   = (string)($row['roomKey'] ?? '');
            $label = (string)($row['roomLabel'] ?? $key);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $options[]  = [
                'caption' => $label . ' [' . $key . ']',
                'value'   => $key
            ];
        }

        return $options;
    }

    private function buildDomainFilterOptions(array $entries): array
    {
        $options   = [];
        $options[] = ['caption' => 'Alle', 'value' => ''];

        $seen = [];

        foreach ($entries as $row) {
            $domain = (string)($row['domain'] ?? '');
            if ($domain === '' || isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;
            $options[]     = [
                'caption' => $domain,
                'value'   => $domain
            ];
        }

        return $options;
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

            // Harte Fehler: Key/Label fehlen oder keinerlei Entity/Control gesetzt
            if ($key === '' || $label === '') {
                $rowColor = '#FFCDD2'; // helles Rot
            } else {
                if ($domain === 'jalousie') {
                    if ($entityId === 0 && $control === 0) {
                        $rowColor = '#FFCDD2';
                    } elseif ($status === 0 || $tilt === 0) {
                        $rowColor = '#FFF9C4'; // Gelb
                    }
                } elseif ($domain === 'licht') {
                    if ($entityId === 0 && $control === 0) {
                        $rowColor = '#FFF9C4';
                    }
                }
            }

            $row['rowColor'] = $rowColor;
        }
        unset($row);

        return $entries;
    }

    private function logDebug(string $msg): void
    {
        IPS_LogMessage('Alexa', 'RCC-DEBUG: ' . $msg);
    }
}
