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

        // Beim ersten Aufruf oder nach Konfig-Änderung: komplett neu laden
        $this->reloadAllFromCatalog();

        $this->logDebug('ApplyChanges ENDE');
    }

    // =====================================================================
    // Public API (für Formular-Buttons / -Events)
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

    // =====================================================================
    // Konfigurationsformular
    // =====================================================================

    public function GetConfigurationForm()
    {
        $entries      = $this->getRuntimeEntries();
        $filterRoom   = $this->ReadAttributeString('FilterRoom');
        $filterDomain = $this->ReadAttributeString('FilterDomain');
        $filterGroup  = $this->ReadAttributeString('FilterGroup');

        $this->logDebug(sprintf(
            'GetConfigurationForm: RuntimeEntries total=%d, FilterRoom="%s", FilterDomain="%s", FilterGroup="%s"',
            count($entries),
            $filterRoom,
            $filterDomain,
            $filterGroup
        ));

        // Filter-Optionen aus den vorhandenen Einträgen aufbauen
        [$roomOptions, $domainOptions, $groupOptions] = $this->buildFilterOptionsFromEntries($entries);

        // Sichtbare Einträge nach Filter
        $visibleEntries = $this->applyFilters($entries, $filterRoom, $filterDomain, $filterGroup);

        $this->logDebug('GetConfigurationForm: sichtbare Einträge=' . count($visibleEntries));

        // Analyse, welche dynamischen Spalten in den sichtbaren Einträgen vorkommen
        $dynamicKeys = $this->analyzeEntriesForDynamicColumns($visibleEntries);

        // Dynamische Spaltenliste
        $columns = $this->buildColumns($dynamicKeys);

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
                            'caption' => 'RoomsCatalog Edit-Script (optional)',
                            'value'   => $this->ReadPropertyInteger('RoomsCatalogEditScriptID')
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
                            'caption'  => 'Einträge',
                            'rowCount' => 25,
                            'add'      => false,
                            'delete'   => false,
                            'sort'     => true,
                            'columns'  => $columns,
                            'values'   => $visibleEntries
                        ]
                    ]
                ]
            ],
            'actions' => [
                [
                    'type'    => 'Label',
                    'caption' => 'Hinweis: Aktuell ist der Konfigurator read-only. ' .
                                 'Änderungen in der Liste werden noch nicht ins RoomsCatalogEdit-Script zurückgeschrieben.'
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
            $this->logDebug(
                'loadRoomsCatalog(' . $mode . '): ScriptFile nicht gefunden für ScriptID=' . $scriptId
            );
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
                // "global" etc. überspringen
                continue;
            }
            $display = (string)($roomCfg['display'] ?? (string)$roomKey);
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

                // Heizung: eine Zeile pro Heizkreis (buero, kueche, …)
                if ($domainKey === 'heizung') {
                    foreach ($domainCfg as $hkKey => $hkCfg) {
                        if (!is_array($hkCfg)) {
                            continue;
                        }

                        $rows[] = $this->buildEntryRow(
                            (string)$roomKey,
                            $roomLabel,
                            (string)$domainKey,   // domain = heizung
                            (string)$domainKey,   // group  = heizung
                            (string)$hkKey,       // key    = buero, kueche, …
                            $hkCfg
                        );
                    }
                    continue;
                }

                // Jalousie: eine Zeile pro Objekt (fenster, tuer, ostlinks, …)
                if ($domainKey === 'jalousie') {
                    foreach ($domainCfg as $entryKey => $entryCfg) {
                        if (!is_array($entryCfg)) {
                            continue;
                        }

                        $rows[] = $this->buildEntryRow(
                            (string)$roomKey,
                            $roomLabel,
                            (string)$domainKey,   // domain = jalousie
                            (string)$domainKey,   // group  = jalousie
                            (string)$entryKey,    // key    = fenster, tuer, …
                            $entryCfg
                        );
                    }
                    continue;
                }

                // Standard-Fall: licht, lueftung, devices, sprinkler, been, …
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
        // Basis-Metadaten
        $row = [
            'selected'   => false,
            'roomKey'    => $roomKey,
            'roomLabel'  => $roomLabel,
            'domain'     => $domainKey,
            'group'      => $groupKey,
            'key'        => $entryKey
        ];

        // Alle Original-Keys aus dem RoomsCatalog als zusätzliche Felder übernehmen
        foreach ($cfg as $k => $v) {
            if (array_key_exists($k, $row)) {
                continue;
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
        if (!is_array($entries)) {
            return [];
        }
        return $entries;
    }

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

        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $k => $v) {
                if (in_array($k, $baseMeta, true)) {
                    continue;
                }

                // Nur Felder berücksichtigen, die irgendwo einen sinnvollen Wert haben
                if ($v === null || $v === '' || (is_int($v) && $v === 0) || (is_float($v) && $v == 0.0)) {
                    continue;
                }

                if (!isset($dynamicKeys[$k])) {
                    if (is_bool($v)) {
                        $dynamicKeys[$k] = 'bool';
                    } elseif (is_int($v) || is_float($v)) {
                        $dynamicKeys[$k] = 'number';
                    } else {
                        $dynamicKeys[$k] = 'string';
                    }
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

        // Basis-Spalten immer sichtbar
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

        // Dynamische Spalten: alle echten RoomsCatalog-Felder (title, icon, order, entityId, …)
        foreach ($dynamicKeys as $key => $type) {
            $col = [
                'caption' => $key,
                'name'    => $key,
                'width'   => '130px',
                'visible' => true
            ];

            if ($type === 'number') {
                $col['add']  = 0;
                $col['edit'] = ['type' => 'NumberSpinner'];
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

        $roomOptions = [
            ['caption' => 'Alle', 'value' => '']
        ];
        foreach ($rooms as $key => $label) {
            $roomOptions[] = [
                'caption' => $label . ' [' . $key . ']',
                'value'   => $key
            ];
        }

        $domainOptions = [
            ['caption' => 'Alle', 'value' => '']
        ];
        foreach (array_keys($domains) as $domainKey) {
            $domainOptions[] = [
                'caption' => $domainKey,
                'value'   => $domainKey
            ];
        }

        $groupOptions = [
            ['caption' => 'Alle', 'value' => '']
        ];
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

    private function logDebug(string $message): void
    {
        IPS_LogMessage('Alexa', 'RCC-DEBUG: ' . $message);
    }
}
