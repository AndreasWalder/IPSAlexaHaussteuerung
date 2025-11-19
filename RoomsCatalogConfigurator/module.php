<?php

declare(strict_types=1);

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
 * 2025-11-18: Flat-View über alle Räume/Domains
 * - RoomsCatalog wird flach aufgelöst (Raum + Domain + Gruppe + Key)
 * - Raum- und Domain-Filter im Kopf der Liste
 * - Automatisches Mapping alter Felder → ControlId/StatusId/TiltId
 *   (heizung: ist/soll, jalousie: wert, licht: state/toggle/value/set,
 *    lueftung.fans: state/toggle, devices/sprinkler/been.tabs: id)
 * - EntityId bleibt String (z.B. "light.eg_buero_all")
 * - Zeilenfärbung (rot/gelb) nach Vollständigkeit
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
        $this->RegisterAttributeString('FilterGroup', '');   // NEU
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
        // Kein echo → keine "C:\Windows\System32\-" Meldung mehr
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
    
        // Analyse, welche Spalten in den sichtbaren Einträgen vorkommen
        [$metaStats, $dynamicKeys] = $this->analyzeEntriesForColumns($visibleEntries);
    
        // Dynamische Spaltenliste
        $columns = $this->buildColumnsForStats($metaStats, $dynamicKeys);
    
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
    
        // NEU: über loadRoomsCatalog() + resolveScriptPath()
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
                            $hkCfg                // enthält ist/stellung/eingestellt/soll/…
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
                            $entryCfg             // enthält title/wert/order
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
        $label = (string)($cfg['title'] ?? $cfg['label'] ?? $entryKey);
        $icon  = (string)($cfg['icon'] ?? ($cfg['iconOn'] ?? ''));
        $order = (int)($cfg['order'] ?? 0);
        $speechKey = (string)($cfg['speechKey'] ?? '');
    
        $entityId   = '';
        $entityName = '';
    
        if (array_key_exists('entityId', $cfg)) {
            $entityId   = is_string($cfg['entityId']) ? $cfg['entityId'] : (string)$cfg['entityId'];
            $entityName = $entityId;
        }
    
        $controlId = (int)($cfg['controlId'] ?? 0);
        $statusId  = (int)($cfg['statusId'] ?? 0);
        $tiltId    = (int)($cfg['tiltId'] ?? 0);
    
        $this->deriveLegacyIds($domainKey, $groupKey, $entryKey, $cfg, $controlId, $statusId, $tiltId);
    
        $row = [
            'selected'   => false,
            'roomKey'    => $roomKey,
            'roomLabel'  => $roomLabel,
            'domain'     => $domainKey,
            'group'      => $groupKey,
            'key'        => $entryKey,
            'label'      => $label,
            'entityId'   => $entityId,
            'entityName' => $entityName,
            'controlId'  => $controlId,
            'statusId'   => $statusId,
            'tiltId'     => $tiltId,
            'speechKey'  => $speechKey,
            'icon'       => $icon,
            'order'      => $order
        ];
    
        $rowColor = $this->deriveRowColor($domainKey, $row);
        if ($rowColor !== '') {
            $row['rowColor'] = $rowColor;
        }
    
        // HIER: alle Original-Keys aus dem RoomsCatalog als zusätzliche Felder übernehmen
        // z.B. ist, stellung, eingestellt, soll, wert, state, toggle, value, set, min, max, unit, id, ...
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

    private function deriveLegacyIds(
        string $domain,
        string $group,
        string $entryKey,
        array $cfg,
        int &$controlId,
        int &$statusId,
        int &$tiltId
    ): void {
        $scalar = $cfg['_scalar'] ?? null;
    
        switch ($domain) {
            case 'jalousie':
            if ($controlId === 0 && isset($cfg['wert'])) {
                $controlId = (int)$cfg['wert'];
            }
            break;
    
            case 'licht':
                if ($group === 'switches') {
                    if ($statusId === 0 && isset($cfg['state'])) {
                        $statusId = (int)$cfg['state'];
                    }
                    if ($controlId === 0 && isset($cfg['toggle'])) {
                        $controlId = (int)$cfg['toggle'];
                    }
                } elseif ($group === 'dimmers') {
                    if ($statusId === 0 && isset($cfg['value'])) {
                        $statusId = (int)$cfg['value'];
                    }
                    if ($controlId === 0 && isset($cfg['set'])) {
                        $controlId = (int)$cfg['set'];
                    }
                } elseif ($group === 'status') {
                    if ($statusId === 0 && isset($cfg['value'])) {
                        $statusId = (int)$cfg['value'];
                    }
                }
                break;
    
            case 'heizung':
            if ($statusId === 0 && isset($cfg['ist'])) {
                $statusId = (int)$cfg['ist'];
            }
            if ($controlId === 0 && isset($cfg['soll'])) {
                $controlId = (int)$cfg['soll'];
            }
            break;
    
           case 'lueftung':
            if ($group === 'fans') {
                if ($statusId === 0 && isset($cfg['state'])) {
                    $statusId = (int)$cfg['state'];
                }
                if ($controlId === 0 && isset($cfg['toggle'])) {
                    $controlId = (int)$cfg['toggle'];
                }
            }
            break;
    
            case 'devices':
            case 'sprinkler':
            case 'been':
                if ($group === 'tabs') {
                    if ($controlId === 0 && isset($cfg['id'])) {
                        $controlId = (int)$cfg['id'];
                    }
                }
                break;
            }
    }


    private function deriveRowColor(string $domain, array $row): string
    {
        $key    = trim((string)($row['key'] ?? ''));
        $label  = trim((string)($row['label'] ?? ''));
        $control = (int)($row['controlId'] ?? 0);
        $status  = (int)($row['statusId'] ?? 0);

        // Harte Fehler: Key/Label fehlen
        if ($key === '' || $label === '') {
            return '#FFCDD2'; // Rot
        }

        // Warnung: wichtige Domains ohne irgendwelche IDs
        $importantDomains = ['heizung', 'jalousie', 'licht', 'lueftung', 'devices', 'sprinkler', 'been'];

        if (in_array($domain, $importantDomains, true)) {
            if ($control === 0 && $status === 0) {
                return '#FFF9C4'; // Gelb
            }
        }

        return '';
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

   private function analyzeEntriesForColumns(array $entries): array
    {
        $metaStats = [
            'hasEntityId'   => false,
            'hasEntityName' => false,
            'hasTiltId'     => false,
            'hasSpeechKey'  => false,
            'hasIcon'       => false,
            'hasOrder'      => false,
            'hasRowColor'   => false
        ];
    
        $dynamicKeys = [];
    
        $baseMeta = [
            'selected',
            'roomKey',
            'roomLabel',
            'domain',
            'group',
            'key',
            'label',
            'entityId',
            'entityName',
            'controlId',   // intern vorhanden, aber kein eigener Header
            'statusId',    // dto.
            'tiltId',
            'speechKey',
            'icon',
            'order',
            'rowColor'
        ];
    
        foreach ($entries as $row) {
            if (!empty($row['entityId'] ?? '')) {
                $metaStats['hasEntityId']   = true;
                $metaStats['hasEntityName'] = true;
            }
            if ((int)($row['tiltId'] ?? 0) !== 0) {
                $metaStats['hasTiltId'] = true;
            }
            if (trim((string)($row['speechKey'] ?? '')) !== '') {
                $metaStats['hasSpeechKey'] = true;
            }
            if (trim((string)($row['icon'] ?? '')) !== '') {
                $metaStats['hasIcon'] = true;
            }
            if ((int)($row['order'] ?? 0) !== 0) {
                $metaStats['hasOrder'] = true;
            }
            if (trim((string)($row['rowColor'] ?? '')) !== '') {
                $metaStats['hasRowColor'] = true;
            }
    
            foreach ($row as $k => $v) {
                if (in_array($k, $baseMeta, true)) {
                    continue;
                }
    
                if ($v === null || $v === '' || (is_int($v) && $v === 0)) {
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
    
        return [$metaStats, $dynamicKeys];
    }

     private function buildColumnsForStats(array $metaStats, array $dynamicKeys): array
    {
        $columns = [];
    
        // Basis-Spalten immer sichtbar
        $columns[] = [
            'caption' => 'Markiert',
            'name'    => 'selected',
            'width'   => '60px',
            'add'     => false,
            'edit'    => ['type' => 'CheckBox'],
            'visible' => true
        ];
        $columns[] = [
            'caption' => 'Raum-Key',
            'name'    => 'roomKey',
            'width'   => '90px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => true
        ];
        $columns[] = [
            'caption' => 'Raum-Label',
            'name'    => 'roomLabel',
            'width'   => '120px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => true
        ];
        $columns[] = [
            'caption' => 'Domain',
            'name'    => 'domain',
            'width'   => '80px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => true
        ];
        $columns[] = [
            'caption' => 'Gruppe',
            'name'    => 'group',
            'width'   => '90px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => true
        ];
        $columns[] = [
            'caption' => 'Key',
            'name'    => 'key',
            'width'   => '120px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => true
        ];
        $columns[] = [
            'caption' => 'Label',
            'name'    => 'label',
            'width'   => '200px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => true
        ];
    
        // Optionale Meta-Spalten
        $columns[] = [
            'caption' => 'EntityId',
            'name'    => 'entityId',
            'width'   => '180px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => $metaStats['hasEntityId']
        ];
        $columns[] = [
            'caption' => 'Entity-Name',
            'name'    => 'entityName',
            'width'   => '180px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => $metaStats['hasEntityName']
        ];
        $columns[] = [
            'caption' => 'TiltId',
            'name'    => 'tiltId',
            'width'   => '90px',
            'add'     => 0,
            'edit'    => ['type' => 'NumberSpinner'],
            'visible' => $metaStats['hasTiltId']
        ];
        $columns[] = [
            'caption' => 'Sprach-Key',
            'name'    => 'speechKey',
            'width'   => '140px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => $metaStats['hasSpeechKey']
        ];
        $columns[] = [
            'caption' => 'Icon',
            'name'    => 'icon',
            'width'   => '120px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => $metaStats['hasIcon']
        ];
        $columns[] = [
            'caption' => 'Order',
            'name'    => 'order',
            'width'   => '70px',
            'add'     => 0,
            'edit'    => ['type' => 'NumberSpinner'],
            'visible' => $metaStats['hasOrder']
        ];
        $columns[] = [
            'caption' => 'Farbe',
            'name'    => 'rowColor',
            'width'   => '80px',
            'add'     => '',
            'edit'    => ['type' => 'ValidationTextBox'],
            'visible' => $metaStats['hasRowColor']
        ];
    
        // Dynamische Spalten: alle echten RoomsCatalog-Felder
        foreach ($dynamicKeys as $key => $type) {
            $col = [
                'caption' => $key,
                'name'    => $key,
                'width'   => '110px',
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

    private function logDebug(string $message): void
    {
        IPS_LogMessage('Alexa', 'RCC-DEBUG: ' . $message);
    }
}
