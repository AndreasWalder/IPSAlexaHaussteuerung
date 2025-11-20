<?php

declare(strict_types=1);

/**
 * ============================================================
 * ROOMS CATALOG CONFIGURATOR — IP-Symcon Modul (V2, dynamisch)
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
 * 2025-11-18: Flat-View über alle Räume/Domains
 * - RoomsCatalog wird flach aufgelöst (Raum + Domain + Gruppe + Key)
 * - Raum- und Domain-Filter im Kopf der Liste
 * - Automatisches Mapping alter Felder → ControlId/StatusId/TiltId
 *   (heizung: ist/soll, jalousie: wert, licht: state/toggle/value/set,
 *    lueftung.fans: state/toggle, devices/sprinkler/been.tabs: id)
 * - EntityId bleibt String (z.B. "light.eg_buero_all")
 * - Zeilenfärbung (rot/gelb) nach Vollständigkeit
 *
 * 2025-11-19: V2 — Vollständig dynamische Spalten
 * - Alle inhaltlichen Felder werden 1:1 aus dem RoomsCatalog übernommen
 * - Keine festen Feldnamen mehr außer: selected, roomKey, roomLabel, domain, group, key
 * - Keine Domain-spezifischen Sonderlogiken (heizung/jalousie/…)
 * - Spalten entstehen dynamisch aus den sichtbaren Einträgen
 *
 * 2025-11-19: V2.1 — IPS-IDs & Speichern nach RoomsCatalogEdit
 * - Zahlenspalten mit überwiegend 5-stelligen Werten werden als SelectObject editierbar
 * - Speichern-Button: aktualisiert/legt Einträge im RoomsCatalogEdit-Script an
 *   (Struktur: rooms[roomKey].display/domains[domain][group][key] = cfg)
 */

class RoomsCatalogConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Grundkonfiguration (Scripts)
        $this->RegisterPropertyInteger('RoomsCatalogScriptID', 0);
        $this->RegisterPropertyInteger('RoomsCatalogEditScriptID', 0);

        // Laufzeit-Daten
        $this->RegisterAttributeString('RuntimeEntries', '[]');
        $this->RegisterAttributeString('FilterRoom', '');
        $this->RegisterAttributeString('FilterDomain', '');
        $this->RegisterAttributeString('FilterGroup', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->logDebug('ApplyChanges START');

        $this->reloadAllFromCatalog();

        $this->logDebug('ApplyChanges ENDE');
    }

    // =====================================================================
    // Public API (Buttons / Events)
    // =====================================================================

    public function ReloadCatalog()
    {
        $this->logDebug('ReloadCatalog: Button gedrückt');
        $this->reloadAllFromCatalog();
        $this->ReloadForm();
    }

    public function SetRoomFilter(string $roomKey)
    {
        $this->WriteAttributeString('FilterRoom', $roomKey);
        $this->logDebug('SetRoomFilter: roomKey="' . $roomKey . '"');
        $this->ReloadForm();
    }

    public function SetDomainFilter(string $domainKey)
    {
        $this->WriteAttributeString('FilterDomain', $domainKey);
        $this->logDebug('SetDomainFilter: domainKey="' . $domainKey . '"');
        $this->ReloadForm();
    }

    public function SetGroupFilter(string $groupKey)
    {
        $this->WriteAttributeString('FilterGroup', $groupKey);
        $this->logDebug('SetGroupFilter: groupKey="' . $groupKey . '"');
        $this->ReloadForm();
    }

    /**
     * Speichert die (gefilterten) Einträge zurück in das RoomsCatalogEdit-Script.
     * Wird über den Formular-Button mit RCC_($id, json_encode($Entries)) aufgerufen.
     */
    public function SaveToEdit(string $entriesJson)
    {
        $this->logDebug('SaveToEdit: aufgerufen, entriesJson.len=' . strlen($entriesJson));

        if ($entriesJson === '') {
            // Vollspeicherung (Button unten): komplette Runtime-Liste
            $entries = $this->getRuntimeEntries();
        } else {
            $decoded = json_decode($entriesJson, true);
            if (!is_array($decoded)) {
                $this->logDebug('SaveToEdit: json_decode fehlgeschlagen');
                return;
            }

            // onEdit/onAdd liefern eine EINZELNE Zeile, nicht die ganze Liste
            if (isset($decoded['roomKey']) || isset($decoded['domain']) || isset($decoded['key'])) {
                $entries = [$decoded];
            } else {
                // Fallback: bereits eine Liste von Zeilen
                $entries = $decoded;
            }
        }

        if (!is_array($entries)) {
            $this->logDebug('SaveToEdit: entries ist kein Array');
            return;
        }

        $this->logDebug('SaveToEdit: START, rows=' . count($entries));

        $editScriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($editScriptId <= 0 || !IPS_ScriptExists($editScriptId)) {
            $this->logDebug('SaveToEdit: RoomsCatalogEditScriptID ungültig: ' . $editScriptId);
            return;
        }

        // Vollständige Runtime-Liste laden
        $all = $this->getRuntimeEntries();

        // Index nach room|domain|group|key aufbauen
        $index = [];
        foreach ($all as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rk = $this->buildCompositeKey($row);
            if ($rk === null) {
                continue;
            }
            $index[$rk] = $i;
        }

        // Geänderte/neu angelegte Einträge in Runtime mergen
        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rk = $this->buildCompositeKey($row);
            if ($rk === null) {
                continue;
            }
            if (isset($index[$rk])) {
                $all[$index[$rk]] = $row; // Update
            } else {
                $all[]      = $row;       // Neu
                $index[$rk] = count($all) - 1;
            }
        }

        // RuntimeEntries updaten (für nächstes Öffnen)
        $this->WriteAttributeString('RuntimeEntries', json_encode($all));

        // Bestehenden Edit-Katalog laden (um z.B. "global" zu erhalten)
        $existingEdit = $this->loadRoomsCatalog($editScriptId, 'EDIT');
        $newCatalog   = $this->rebuildRoomsCatalogFromEntries($all, $existingEdit);

        // PHP-Content generieren & in Script schreiben
        $php = "<?php\nreturn " . var_export($newCatalog, true) . ";\n";
        IPS_SetScriptContent($editScriptId, $php);

        $roomCount = (isset($newCatalog['rooms']) && is_array($newCatalog['rooms'])) ? count($newCatalog['rooms']) : 0;
        $this->logDebug('SaveToEdit: ENDE, rooms=' . $roomCount);
    }

    /**
     * Speichert den Edit-Katalog 1:1 nach PROD.
     */
    public function SaveEditToProd(): void
    {
        $prodId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');

        if ($prodId <= 0 || !IPS_ScriptExists($prodId)) {
            $this->logDebug('SaveEditToProd: RoomsCatalogScriptID ungültig: ' . $prodId);
            return;
        }

        if ($editId <= 0 || !IPS_ScriptExists($editId)) {
            $this->logDebug('SaveEditToProd: RoomsCatalogEditScriptID ungültig: ' . $editId);
            return;
        }

        $editCatalog = $this->loadRoomsCatalog($editId, 'EDIT');
        if ($editCatalog === []) {
            $this->logDebug('SaveEditToProd: Edit-Katalog leer oder ungültig');
            return;
        }

        $php = "<?php\nreturn " . var_export($editCatalog, true) . ";\n";
        IPS_SetScriptContent($prodId, $php);

        $roomCount = 0;
        if (isset($editCatalog['rooms']) && is_array($editCatalog['rooms'])) {
            $roomCount = count($editCatalog['rooms']);
        }

        $this->logDebug('SaveEditToProd: Edit → Prod übernommen, rooms=' . $roomCount);

        // Prod neu einlesen und Formular updaten
        $this->reloadAllFromCatalog();
        $this->ReloadForm();
    }

    /**
     * Klont die erste markierte Zeile (selected=true) in RuntimeEntries
     * und schreibt den neuen Stand nach RoomsCatalogEdit.
     */
    public function CloneSelectedEntry(): void
    {
        $editScriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($editScriptId <= 0 || !IPS_ScriptExists($editScriptId)) {
            $this->logDebug('CloneSelectedEntry: RoomsCatalogEditScriptID ungültig: ' . $editScriptId);
            return;
        }

        $entries = $this->getRuntimeEntries();
        if ($entries === []) {
            $this->logDebug('CloneSelectedEntry: keine Entries vorhanden');
            return;
        }

        $cloneIndex = null;
        foreach ($entries as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['selected'])) {
                $cloneIndex = $i;
                break;
            }
        }

        if ($cloneIndex === null) {
            $this->logDebug('CloneSelectedEntry: keine markierte Zeile gefunden');
            return;
        }

        $source = $entries[$cloneIndex];
        if (!is_array($source)) {
            $this->logDebug('CloneSelectedEntry: markierte Zeile ist kein Array');
            return;
        }

        $clone             = $source;
        $clone['selected'] = false;

        // neuen Key erzeugen, damit room|domain|group|key eindeutig bleibt
        $origKey = (string)($clone['key'] ?? '');
        if ($origKey !== '') {
            $room   = (string)($clone['roomKey'] ?? '');
            $domain = (string)($clone['domain'] ?? '');
            $group  = (string)($clone['group'] ?? '');

            $suffix   = 1;
            $newKey   = $origKey . '_copy';
            $usedKeys = [];

            foreach ($entries as $r) {
                if (!is_array($r)) {
                    continue;
                }
                if ((string)($r['roomKey'] ?? '') === $room
                    && (string)($r['domain'] ?? '') === $domain
                    && (string)($r['group'] ?? '') === $group) {
                    $usedKeys[] = (string)($r['key'] ?? '');
                }
            }

            while (in_array($newKey, $usedKeys, true)) {
                $suffix++;
                $newKey = $origKey . '_copy' . $suffix;
            }

            $clone['key'] = $newKey;
        }

        // Clone direkt nach der Quelle einfügen
        array_splice($entries, $cloneIndex + 1, 0, [$clone]);

        // RuntimeEntries aktualisieren
        $this->WriteAttributeString('RuntimeEntries', json_encode($entries));

        // RoomsCatalogEdit neu aufbauen
        $existingEdit = $this->loadRoomsCatalog($editScriptId, 'EDIT');
        $newCatalog   = $this->rebuildRoomsCatalogFromEntries($entries, $existingEdit);
        $php          = "<?php\nreturn " . var_export($newCatalog, true) . ";\n";
        IPS_SetScriptContent($editScriptId, $php);

        $this->logDebug('CloneSelectedEntry: Eintrag geklont');
        $this->ReloadForm();
    }

    /**
     * Löscht alle markierten Zeilen (selected=true) aus RuntimeEntries
     * und schreibt den neuen Stand nach RoomsCatalogEdit.
     */
    public function DeleteSelectedEntries(): void
    {
        $editScriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($editScriptId <= 0 || !IPS_ScriptExists($editScriptId)) {
            $this->logDebug('DeleteSelectedEntries: RoomsCatalogEditScriptID ungültig: ' . $editScriptId);
            return;
        }

        $entries = $this->getRuntimeEntries();
        if ($entries === []) {
            $this->logDebug('DeleteSelectedEntries: keine Entries vorhanden');
            return;
        }

        $remaining = [];
        $deleted   = 0;

        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['selected'])) {
                $deleted++;
                continue;
            }
            $remaining[] = $row;
        }

        if ($deleted === 0) {
            $this->logDebug('DeleteSelectedEntries: keine markierten Zeilen gefunden');
            return;
        }

        $this->WriteAttributeString('RuntimeEntries', json_encode($remaining));

        $existingEdit = $this->loadRoomsCatalog($editScriptId, 'EDIT');
        $newCatalog   = $this->rebuildRoomsCatalogFromEntries($remaining, $existingEdit);
        $php          = "<?php\nreturn " . var_export($newCatalog, true) . ";\n";
        IPS_SetScriptContent($editScriptId, $php);

        $this->logDebug('DeleteSelectedEntries: gelöschte Zeilen=' . $deleted);
        $this->ReloadForm();
    }

    // =====================================================================
    // Konfigurationsformular
    // =====================================================================

    public function GetConfigurationForm()
    {
        // --- Basisdaten / Filter ---
        $entriesProd  = $this->getRuntimeEntries(); // Prod-Liste aus Attribut
        $filterRoom   = $this->ReadAttributeString('FilterRoom');
        $filterDomain = $this->ReadAttributeString('FilterDomain');
        $filterGroup  = $this->ReadAttributeString('FilterGroup');

        // --- Edit-Katalog flach laden ---
        $editScriptId    = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $entriesEditFlat = [];
        if ($editScriptId > 0 && IPS_ScriptExists($editScriptId)) {
            $editCatalog     = $this->loadRoomsCatalog($editScriptId, 'EDIT');
            $editRooms       = $this->extractRoomsFromCatalog($editCatalog);
            $entriesEditFlat = $this->buildFlatEntriesFromRooms($editRooms);
        }

        // --- Filteroptionen aus PROD (reicht für beide Listen) ---
        [$roomOptions, $domainOptions, $groupOptions] = $this->buildFilterOptionsFromEntries($entriesProd);

        // --- Sichtbare PROD-Einträge (oben) ---
        $visibleProd = $this->applyFilters($entriesProd, $filterRoom, $filterDomain, $filterGroup);

        // --- Diff-Einträge (Edit vs. Prod) für unten ---
        $diffEntriesAll = $this->buildDiffEntries($entriesProd, $entriesEditFlat);
        $visibleDiff    = $this->applyFilters($diffEntriesAll, $filterRoom, $filterDomain, $groupFilter = $filterGroup);

        $this->logDebug('GetConfigurationForm: sichtbare PROD-Einträge=' . count($visibleProd));
        $this->logDebug('GetConfigurationForm: sichtbare DIFF-Einträge=' . count($visibleDiff));

        // --- Spalten NUR aus den sichtbaren Zeilen bauen (Prod + Diff) ---
        $allForColumns = array_merge($visibleProd, $visibleDiff);
        $dynamicKeys   = $this->analyzeEntriesForDynamicColumns($allForColumns);

        // Spalten für PROD (inkl. selected) und EDIT (ohne selected)
        $columnsProd = $this->buildColumns($dynamicKeys);
        $columnsEdit = [];
        foreach ($columnsProd as $col) {
            if (($col['name'] ?? '') === 'selected') {
                continue; // Markiert-Spalte unten entfernen
            }
            $columnsEdit[] = $col;
        }

        $form = [
            'elements' => [
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
                            'value'   => $editScriptId
                        ]
                    ]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Einträge (RoomsCatalog PROD)',
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
                                    'onChange' => 'RCC_SetRoomFilter($id, $FilterRoom);'
                                ],
                                [
                                    'type'     => 'Select',
                                    'name'     => 'FilterDomain',
                                    'caption'  => 'Domain-Filter',
                                    'options'  => $domainOptions,
                                    'value'    => $filterDomain,
                                    'onChange' => 'RCC_SetDomainFilter($id, $FilterDomain);'
                                ],
                                [
                                    'type'     => 'Select',
                                    'name'     => 'FilterGroup',
                                    'caption'  => 'Gruppen-Filter',
                                    'options'  => $groupOptions,
                                    'value'    => $filterGroup,
                                    'onChange' => 'RCC_SetGroupFilter($id, $FilterGroup);'
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'ROOMSCATALOG NEU LADEN',
                                    'onClick' => 'RCC_ReloadCatalog($id);'
                                ]
                            ]
                        ],
                        [
                            'type'     => 'List',
                            'name'     => 'Entries',
                            'caption'  => 'Einträge PROD',
                            'rowCount' => 25,
                            'add'      => true,
                            'delete'   => false,
                            'sort'     => true,
                            'columns'  => $columnsProd,
                            'values'   => $visibleProd,
                            'onEdit'   => 'RCC_SaveToEdit($id, json_encode($Entries));',
                            'onAdd'    => 'RCC_SaveToEdit($id, json_encode($Entries));'
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Label',
                                    'caption' => 'Aktionen für markierte PROD-Einträge:'
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'MARKIERTE CLONEN',
                                    'onClick' => 'RCC_CloneSelectedEntry($id);'
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'MARKIERTE LÖSCHEN',
                                    'onClick' => 'RCC_DeleteSelectedEntries($id);'
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'RoomsCatalogEdit / Diff zu PROD',
                    'items'   => [
                        [
                            'type'     => 'List',
                            'name'     => 'EntriesEdit',
                            'caption'  => 'Einträge EDIT (Diff)',
                            'rowCount' => 25,
                            'add'      => false,
                            'delete'   => false,
                            'sort'     => true,
                            'columns'  => $columnsEdit,
                            'values'   => $visibleDiff,
                            'rowColor' => 'rowColor'
                        ]
                    ]
                ]
            ],
            'actions' => [
                [
                    'type'    => 'Label',
                    'caption' => 'Hinweis: Änderungen in der oberen Liste werden automatisch in den RoomsCatalogEdit geschrieben.'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'SPEICHERN NACH ROOMS CATALOG EDIT',
                    'onClick' => 'RCC_SaveToEdit($id, "");'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'SPEICHERN NACH ROOMS CATALOG',
                    'onClick' => 'RCC_SaveEditToProd($id);'
                ]
            ]
        ];

        return json_encode($form);
    }

    // =====================================================================
    // Interne Logik
    // =====================================================================

    private function reloadAllFromCatalog(): void
    {
        $this->logDebug('reloadAllFromCatalog: START');

        $scriptId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $this->logDebug('reloadAllFromCatalog: kein gültiges ScriptID gesetzt');
            $this->WriteAttributeString('RuntimeEntries', '[]');
            return;
        }

        $catalog = $this->loadRoomsCatalog($scriptId, 'PROD');

        if ($catalog === []) {
            $this->logDebug('reloadAllFromCatalog: Katalog leer oder ungültig');
            $this->WriteAttributeString('RuntimeEntries', '[]');
            return;
        }

        $rooms = $this->extractRoomsFromCatalog($catalog);
        $this->logDebug('reloadAllFromCatalog: Räume im geladenen Katalog=' . count($rooms));

        $entries = $this->buildFlatEntriesFromRooms($rooms);
        $this->logDebug('reloadAllFromCatalog: erzeugte Einträge=' . count($entries));

        $this->WriteAttributeString('RuntimeEntries', json_encode($entries));
    }

    private function loadRoomsCatalog(int $scriptId, string $mode): array
    {
        $path = $this->resolveScriptPath($scriptId);

        if ($path === null) {
            $this->logDebug('loadRoomsCatalog(' . $mode . '): ScriptFile nicht gefunden für ScriptID=' . $scriptId);
            return [];
        }

        $this->logDebug('loadRoomsCatalog(' . $mode . '): lade ' . $path);

        $data = require $path;

        if (!is_array($data)) {
            $this->logDebug('loadRoomsCatalog(' . $mode . '): Rückgabewert ist kein Array');
            return [];
        }

        return $data;
    }

    /**
     * Akzeptiert sowohl:
     *   return ['buero'=>[...], 'kueche'=>[...], 'global'=>[...]];
     * als auch:
     *   return ['rooms' => ['buero'=>[...], ...], 'global' => [...]];
     */
    private function extractRoomsFromCatalog(array $catalog): array
    {
        if (isset($catalog['rooms']) && is_array($catalog['rooms'])) {
            $rooms = $catalog['rooms'];
        } else {
            $rooms = $catalog;
        }

        $result = [];
        $count  = 0;

        foreach ($rooms as $roomKey => $roomCfg) {
            if (!is_array($roomCfg)) {
                continue;
            }
            if (!isset($roomCfg['domains']) || !is_array($roomCfg['domains'])) {
                continue;
            }
            $display                 = (string)($roomCfg['display'] ?? (string)$roomKey);
            $result[(string)$roomKey] = [
                'display' => $display,
                'domains' => $roomCfg['domains']
            ];
            $count++;
        }

        $this->logDebug('extractRoomsFromCatalog: Räume=' . $count);

        return $result;
    }

    private function buildFlatEntriesFromRooms(array $rooms): array
    {
        $rows = [];

        foreach ($rooms as $roomKey => $roomCfg) {
            if (!is_array($roomCfg)) {
                continue;
            }

            $roomLabel = (string)($roomCfg['display'] ?? (string)$roomKey);
            $domains   = $roomCfg['domains'] ?? [];

            foreach ($domains as $domainKey => $domainCfg) {
                if (!is_array($domainCfg)) {
                    continue;
                }

                // Heizung: domain[hkKey] = cfg
                if ($domainKey === 'heizung') {
                    foreach ($domainCfg as $hkKey => $hkCfg) {
                        if (!is_array($hkCfg)) {
                            continue;
                        }

                        $rows[] = $this->buildEntryRow(
                            (string)$roomKey,
                            $roomLabel,
                            (string)$domainKey,
                            (string)$domainKey,
                            (string)$hkKey,
                            $hkCfg
                        );
                    }
                    continue;
                }

                // Jalousie: domain[entryKey] = cfg
                if ($domainKey === 'jalousie') {
                    foreach ($domainCfg as $entryKey => $entryCfg) {
                        if (!is_array($entryCfg)) {
                            continue;
                        }

                        $rows[] = $this->buildEntryRow(
                            (string)$roomKey,
                            $roomLabel,
                            (string)$domainKey,
                            (string)$domainKey,
                            (string)$entryKey,
                            $entryCfg
                        );
                    }
                    continue;
                }

                // Standard: domain[group][entryKey] = cfg
                foreach ($domainCfg as $groupKey => $groupCfg) {
                    if (!is_array($groupCfg)) {
                        continue;
                    }

                    foreach ($groupCfg as $entryKey => $cfg) {
                        if (!is_array($cfg)) {
                            continue;
                        }

                        $rows[] = $this->buildEntryRow(
                            (string)$roomKey,
                            $roomLabel,
                            (string)$domainKey,
                            (string)$groupKey,
                            (string)$entryKey,
                            $cfg
                        );
                    }
                }
            }
        }

        return $rows;
    }

    private function buildEntryRow(
        string $roomKey,
        string $roomLabel,
        string $domainKey,
        string $groupKey,
        string $entryKey,
        array $cfg
    ): array {
        $row = [
            'selected'  => false,
            'roomKey'   => $roomKey,
            'roomLabel' => $roomLabel,
            'domain'    => $domainKey,
            'group'     => $groupKey,
            'key'       => $entryKey
        ];

        $forceIdKeys = ['id', 'controlId', 'statusId', 'tiltId', 'set', 'state', 'ist', 'soll'];

        foreach ($cfg as $k => $v) {
            if (array_key_exists($k, $row)) {
                continue;
            }

            if (in_array($k, $forceIdKeys, true)) {
                if (is_string($v)) {
                    $trim = trim($v);
                    if ($trim === '' || $trim === '.') {
                        continue;
                    }
                    if (ctype_digit($trim)) {
                        $v = (int)$trim;
                    }
                }
            }

            if (is_array($v)) {
                if ($v === []) {
                    continue;
                }
                $row[$k] = implode(', ', array_map('strval', $v));
            } elseif (is_bool($v) || is_int($v) || is_float($v) || is_string($v)) {
                $row[$k] = $v;
            }
        }

        return $row;
    }

    private function getRuntimeEntries(): array
    {
        $json = $this->ReadAttributeString('RuntimeEntries');
        if ($json === '' || $json === null) {
            return [];
        }
        $entries = json_decode($json, true);
        return is_array($entries) ? $entries : [];
    }

    /**
     * Ermittelt dynamische Spalten; erkennt auch Spalten, die wie IPS-IDs aussehen.
     */
    private function analyzeEntriesForDynamicColumns(array $entries): array
    {
        $dynamicKeys = [];

        $baseMeta = [
            'selected',
            'roomKey',
            'roomLabel',
            'domain',
            'group',
            'key'
        ];

        // Keys, die wir grundsätzlich als ID-Spalten behandeln wollen
        $forceIdKeys = ['id', 'controlId', 'statusId', 'tiltId', 'set', 'state', 'ist', 'soll'];

        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $k => $v) {
                if (in_array($k, $baseMeta, true)) {
                    continue;
                }

                $forcedId = in_array($k, $forceIdKeys, true);

                // Falls noch kein Meta und es ein erzwungener ID-Key ist:
                if (!isset($dynamicKeys[$k]) && $forcedId) {
                    $dynamicKeys[$k] = [
                        'type' => 'number',
                        'isId' => true
                    ];
                }

                // Platzhalter "." und leere Werte generell ignorieren
                if ($v === null || $v === '' || (is_string($v) && trim($v) === '.') ||
                    (is_int($v) && $v === 0) || (is_float($v) && $v == 0.0)) {
                    continue;
                }

                // Numerische Strings wie "12345" für die Typanalyse in int wandeln
                if (is_string($v)) {
                    $trim = trim($v);
                    if (ctype_digit($trim)) {
                        $v = (int)$trim;
                    }
                }

                if (!isset($dynamicKeys[$k])) {
                    if (is_bool($v)) {
                        $dynamicKeys[$k] = [
                            'type' => 'bool',
                            'isId' => false
                        ];
                    } elseif (is_int($v) || is_float($v)) {
                        $dynamicKeys[$k] = [
                            'type' => 'number',
                            'isId' => $this->looksLikeIpsId($v)
                        ];
                    } else {
                        $dynamicKeys[$k] = [
                            'type' => 'string',
                            'isId' => false
                        ];
                    }
                } else {
                    $meta = $dynamicKeys[$k];

                    if ($meta['type'] === 'number') {
                        if (!(is_int($v) || is_float($v))) {
                            $meta['type'] = 'string';
                            $meta['isId'] = false;
                        } else {
                            if ($meta['isId'] && !$this->looksLikeIpsId($v)) {
                                $meta['isId'] = false;
                            }
                        }
                    } elseif ($meta['type'] === 'bool') {
                        if (!is_bool($v)) {
                            $meta['type'] = 'string';
                            $meta['isId'] = false;
                        }
                    } else {
                        $meta['isId'] = false;
                    }

                    $dynamicKeys[$k] = $meta;
                }
            }
        }

        ksort($dynamicKeys);

        $this->logDebug('analyzeEntriesForDynamicColumns: dynamische Keys=' . implode(',', array_keys($dynamicKeys)));

        return $dynamicKeys;
    }

    private function buildColumns(array $dynamicKeys): array
    {
        $columns = [];

        // Basis-Spalten
        $columns[] = [
            'caption' => 'Markiert',
            'name'    => 'selected',
            'width'   => '70px',
            'add'     => false,
            'edit'    => ['type' => 'CheckBox'],
            'visible' => true
        ];
        $columns[] = [
            'caption' => 'Raum-Key',
            'name'    => 'roomKey',
            'width'   => '100px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => true
        ];
        $columns[] = [
            'caption' => 'Raum-Label',
            'name'    => 'roomLabel',
            'width'   => '140px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => true
        ];
        $columns[] = [
            'caption' => 'Domain',
            'name'    => 'domain',
            'width'   => '100px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => true
        ];
        $columns[] = [
            'caption' => 'Gruppe',
            'name'    => 'group',
            'width'   => '110px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => true
        ];
        $columns[] = [
            'caption' => 'Key',
            'name'    => 'key',
            'width'   => '140px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => true
        ];

        // Dynamische Spalten
        foreach ($dynamicKeys as $key => $meta) {
            $type = $meta['type'];
            $isId = !empty($meta['isId']);

            $col = [
                'caption' => $key,
                'name'    => $key,
                'width'   => '130px',
                'visible' => true
            ];

            if ($type === 'number') {
                $col['add'] = 0;
                if ($isId) {
                    $col['edit'] = ['type' => 'SelectObject'];
                } else {
                    $col['edit'] = ['type' => 'NumberSpinner'];
                }
            } elseif ($type === 'bool') {
                $col['add']  = false;
                $col['edit'] = ['type' => 'CheckBox'];
            } else {
                $col['add']  = '';
                $col['edit'] = ['type' => 'ValidationTextBox'];
            }

            $columns[] = $col;
        }

        return $columns;
    }

    /**
     * Baut eine Diff-Liste aus PROD- und EDIT-Einträgen.
     * - new:     nur in EDIT vorhanden        → grün
     * - removed: nur in PROD vorhanden        → rot
     * - changed: in beiden vorhanden, aber cfg anders → gelb
     */
    private function buildDiffEntries(array $prodEntries, array $editEntries): array
    {
        $prodIndex = [];
        foreach ($prodEntries as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $this->buildCompositeKey($row);
            if ($key === null) {
                continue;
            }
            $prodIndex[$key] = $row;
        }

        $editIndex = [];
        foreach ($editEntries as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $this->buildCompositeKey($row);
            if ($key === null) {
                continue;
            }
            $editIndex[$key] = $row;
        }

        $allKeys  = array_unique(array_merge(array_keys($prodIndex), array_keys($editIndex)));
        $diffRows = [];

        foreach ($allKeys as $k) {
            $inProd = array_key_exists($k, $prodIndex);
            $inEdit = array_key_exists($k, $editIndex);

            if ($inProd && !$inEdit) {
                // entfernt → rot
                $row             = $prodIndex[$k];
                $row['rowColor'] = '#ffcccc';
                $row['diffKeys'] = 'removed';
                $diffRows[]      = $row;
                continue;
            }

            if (!$inProd && $inEdit) {
                // neu → grün
                $row             = $editIndex[$k];
                $row['rowColor'] = '#ccffcc';
                $row['diffKeys'] = 'new';
                $diffRows[]      = $row;
                continue;
            }

            if ($inProd && $inEdit) {
                $p = $prodIndex[$k];
                $e = $editIndex[$k];

                foreach (['selected', 'rowColor', 'diffKeys'] as $meta) {
                    unset($p[$meta], $e[$meta]);
                }

                if ($p != $e) {
                    // geänderte Felder ermitteln
                    $changed = [];
                    foreach ($e as $ck => $cv) {
                        if (in_array($ck, ['selected','roomKey','roomLabel','domain','group','key'], true)) {
                            continue;
                        }
                        $pv = $p[$ck] ?? null;
                        if ($pv != $cv) {
                            $changed[] = $ck;
                        }
                    }

                    $row             = $editIndex[$k]; // EDIT als Basis
                    $row['rowColor'] = '#fff5cc';      // gelb
                    $row['diffKeys'] = implode(', ', $changed);
                    $diffRows[]      = $row;
                }
            }
        }

        return $diffRows;
    }

    private function buildFilterOptionsFromEntries(array $entries): array
    {
        $rooms   = [];
        $domains = [];
        $groups  = [];

        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }

            $roomKey   = (string)($row['roomKey'] ?? '');
            $roomLabel = (string)($row['roomLabel'] ?? $roomKey);
            $domain    = (string)($row['domain'] ?? '');
            $group     = (string)($row['group'] ?? '');

            if ($roomKey !== '') {
                $rooms[$roomKey] = $roomLabel;
            }
            if ($domain !== '') {
                $domains[$domain] = true;
            }
            if ($group !== '') {
                $groups[$group] = true;
            }
        }

        ksort($rooms);
        ksort($domains);
        ksort($groups);

        $roomOptions = [['caption' => 'Alle', 'value' => '']];
        foreach ($rooms as $key => $label) {
            $roomOptions[] = [
                'caption' => $label . ' [' . $key . ']',
                'value'   => $key
            ];
        }

        $domainOptions = [['caption' => 'Alle', 'value' => '']];
        foreach (array_keys($domains) as $domainKey) {
            $domainOptions[] = [
                'caption' => $domainKey,
                'value'   => $domainKey
            ];
        }

        $groupOptions = [['caption' => 'Alle', 'value' => '']];
        foreach (array_keys($groups) as $groupKey) {
            $groupOptions[] = [
                'caption' => $groupKey,
                'value'   => $groupKey
            ];
        }

        $this->logDebug(sprintf(
            'buildFilterOptionsFromEntries: rooms=%d, domains=%d, groups=%d',
            count($roomOptions) - 1,
            count($domainOptions) - 1,
            count($groupOptions) - 1
        ));

        return [$roomOptions, $domainOptions, $groupOptions];
    }

    private function applyFilters(array $entries, string $roomFilter, string $domainFilter, string $groupFilter): array
    {
        if ($roomFilter === '' && $domainFilter === '' && $groupFilter === '') {
            return $entries;
        }

        $filtered = [];

        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }

            $room   = (string)($row['roomKey'] ?? '');
            $domain = (string)($row['domain'] ?? '');
            $group  = (string)($row['group'] ?? '');

            if ($roomFilter !== '' && $room !== $roomFilter) {
                continue;
            }
            if ($domainFilter !== '' && $domain !== $domainFilter) {
                continue;
            }
            if ($groupFilter !== '' && $group !== $groupFilter) {
                continue;
            }

            $filtered[] = $row;
        }

        return $filtered;
    }

    private function resolveScriptPath(int $scriptId): ?string
    {
        if ($scriptId <= 0) {
            return null;
        }

        $file = @IPS_GetScriptFile($scriptId);
        if ($file === '' || $file === false) {
            return null;
        }

        $path = IPS_GetKernelDir() . 'scripts' . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            $this->logDebug('resolveScriptPath: Datei nicht gefunden: ' . $path);
            return null;
        }

        return $path;
    }

    private function looksLikeIpsId($v): bool
    {
        if (!is_int($v)) {
            return false;
        }
        return ($v >= 10000 && $v <= 99999);
    }

    private function buildCompositeKey(array $row): ?string
    {
        $room   = isset($row['roomKey']) ? (string)$row['roomKey'] : '';
        $domain = isset($row['domain']) ? (string)$row['domain'] : '';
        $group  = isset($row['group']) ? (string)$row['group'] : '';
        $key    = isset($row['key']) ? (string)$row['key'] : '';

        if ($room === '' || $domain === '' || $group === '' || $key === '') {
            return null;
        }

        return $room . '|' . $domain . '|' . $group . '|' . $key;
    }

    /**
     * Baut aus der flachen Liste wieder einen RoomsCatalog (rooms[...]...),
     * vorhandene Top-Level-Keys wie "global" aus $existingCatalog bleiben erhalten.
     */
    private function rebuildRoomsCatalogFromEntries(array $entries, array $existingCatalog): array
    {
        $rooms = [];

        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }

            $roomKey   = isset($row['roomKey']) ? (string)$row['roomKey'] : '';
            $roomLabel = isset($row['roomLabel']) ? (string)$row['roomLabel'] : $roomKey;
            $domain    = isset($row['domain']) ? (string)$row['domain'] : '';
            $group     = isset($row['group']) ? (string)$row['group'] : '';
            $key       = isset($row['key']) ? (string)$row['key'] : '';

            if ($roomKey === '' || $domain === '' || $group === '' || $key === '') {
                continue;
            }

            if (!isset($rooms[$roomKey])) {
                $rooms[$roomKey] = [
                    'display' => $roomLabel !== '' ? $roomLabel : $roomKey,
                    'domains' => []
                ];
            }

            if (!isset($rooms[$roomKey]['domains'][$domain])) {
                $rooms[$roomKey]['domains'][$domain] = [];
            }

            $cfg = [];
            foreach ($row as $k => $v) {
                if (in_array($k, ['selected', 'roomKey', 'roomLabel', 'domain', 'group', 'key'], true)) {
                    continue;
                }
                $cfg[$k] = $v;
            }

            if ($domain === 'heizung' || $domain === 'jalousie') {
                // flache Struktur: domain[key] = cfg
                $rooms[$roomKey]['domains'][$domain][$key] = $cfg;
            } else {
                // Standardstruktur: domain[group][key] = cfg
                if (!isset($rooms[$roomKey]['domains'][$domain][$group])) {
                    $rooms[$roomKey]['domains'][$domain][$group] = [];
                }
                $rooms[$roomKey]['domains'][$domain][$group][$key] = $cfg;
            }
        }

        // bestehenden Katalog-Basisteil übernehmen (global, floors, …)
        $newCatalog = [];

        foreach ($existingCatalog as $k => $v) {
            if ($k === 'rooms') {
                continue;
            }
            $newCatalog[$k] = $v;
        }

        $newCatalog['rooms'] = $rooms;

        return $newCatalog;
    }

    private function logDebug(string $message): void
    {
        IPS_LogMessage('Alexa', 'RCC-DEBUG: ' . $message);
    }
}
