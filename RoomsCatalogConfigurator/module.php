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
 * 2025-11-17: Flat-Viewer für alle Räume/Domains + Filter
 * - Lädt alle Räume/Domains aus RoomsCatalog (Edit bevorzugt, sonst produktiv)
 * - Flache Liste mit Spalten: roomKey, roomLabel, domain, group, key, label,
 *   entityId, entityName, controlId, statusId, tiltId, speechKey, icon, order
 * - Raum- und Domain-Filter (Dropdowns) + farbliche Markierung
 * - Heuristik zum Befüllen von EntityId / ControlId / StatusId / TiltId
 *   (jalousie, licht.switches/dimmers/status, generische numerische Werte)
 * - Achtung: Aktuell NUR Anzeige/Filter, kein Zurückschreiben ins Script!
 */

declare(strict_types=1);

class RoomsCatalogConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Konfiguration: Script-IDs
        $this->RegisterPropertyInteger('RoomsCatalogScriptID', 0);
        $this->RegisterPropertyInteger('RoomsCatalogEditScriptID', 0);

        // Alte Property (Liste) – bleibt für spätere Schreibfunktionen reserviert
        $this->RegisterPropertyString('Entries', '[]');

        // Runtime-Zustand: komplette Eintragsliste (ungefiltert)
        $this->RegisterAttributeString('RuntimeEntries', '[]');

        // Filter (Raum / Domain)
        $this->RegisterAttributeString('FilterRoom', '');
        $this->RegisterAttributeString('FilterDomain', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        IPS_LogMessage('Alexa', 'RCC-DEBUG: ApplyChanges START');

        // Bei erster Initialisierung oder wenn noch keine RuntimeEntries vorhanden:
        $entries = $this->getRuntimeEntries();
        if ($entries === []) {
            $this->reloadAllFromCatalog();
        }

        IPS_LogMessage('Alexa', 'RCC-DEBUG: ApplyChanges ENDE');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'RoomsCatalogScriptID':
            case 'RoomsCatalogEditScriptID':
                // Script-Auswahl geändert → Runtime neu laden
                $this->reloadAllFromCatalog();
                $this->ReloadForm();
                break;

            case 'FilterRoom':
                $this->WriteAttributeString('FilterRoom', (string)$Value);
                $this->ReloadForm();
                break;

            case 'FilterDomain':
                $this->WriteAttributeString('FilterDomain', (string)$Value);
                $this->ReloadForm();
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm()
    {
        $allEntries   = $this->getRuntimeEntries();
        $filterRoom   = $this->ReadAttributeString('FilterRoom');
        $filterDomain = $this->ReadAttributeString('FilterDomain');

        // Optionen für Filter aus der Liste ableiten
        $roomOptions   = $this->buildRoomFilterOptions($allEntries);
        $domainOptions = $this->buildDomainFilterOptions($allEntries);

        // Filter anwenden
        $visible = [];
        foreach ($allEntries as $row) {
            $roomKey = (string)($row['roomKey'] ?? '');
            $domain  = (string)($row['domain'] ?? '');

            if ($filterRoom !== '' && $roomKey !== $filterRoom) {
                continue;
            }
            if ($filterDomain !== '' && $domain !== $filterDomain) {
                continue;
            }
            $visible[] = $row;
        }

        // Zeilen einfärben
        $visible = $this->applyRowColors($visible);

        IPS_LogMessage(
            'Alexa',
            sprintf(
                'RCC-DEBUG: GetConfigurationForm: RuntimeEntries total=%d, FilterRoom="%s", FilterDomain="%s", sichtbare Einträge=%d',
                count($allEntries),
                $filterRoom,
                $filterDomain,
                count($visible)
            )
        );

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
                            'value'    => $this->ReadPropertyInteger('RoomsCatalogScriptID'),
                            'onChange' => 'IPS_RequestAction($id, "RoomsCatalogScriptID", $RoomsCatalogScriptID);'
                        ],
                        [
                            'type'     => 'SelectScript',
                            'name'     => 'RoomsCatalogEditScriptID',
                            'caption'  => 'RoomsCatalog Edit-Script (bevorzugt zum Laden)',
                            'value'    => $this->ReadPropertyInteger('RoomsCatalogEditScriptID'),
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
                            'add'      => false,
                            'delete'   => false,
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
                                    'width'   => '90px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Raum-Label',
                                    'name'    => 'roomLabel',
                                    'width'   => '120px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Domain',
                                    'name'    => 'domain',
                                    'width'   => '80px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Gruppe',
                                    'name'    => 'group',
                                    'width'   => '90px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Key',
                                    'name'    => 'key',
                                    'width'   => '110px',
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
                                    'width'   => '80px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'Entity-Name',
                                    'name'    => 'entityName',
                                    'width'   => '180px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'ControlId',
                                    'name'    => 'controlId',
                                    'width'   => '80px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'StatusId',
                                    'name'    => 'statusId',
                                    'width'   => '80px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'TiltId',
                                    'name'    => 'tiltId',
                                    'width'   => '80px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner']
                                ],
                                [
                                    'caption' => 'Sprach-Key',
                                    'name'    => 'speechKey',
                                    'width'   => '130px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox']
                                ],
                                [
                                    'caption' => 'Icon',
                                    'name'    => 'icon',
                                    'width'   => '120px',
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
                            'values' => $visible
                        ]
                    ]
                ]
            ],
            'actions' => [
                [
                    'type'    => 'Label',
                    'caption' => 'Hinweis: Aktuell ist der Konfigurator read-only. Änderungen in der Liste werden noch nicht ins RoomsCatalogEdit-Script zurückgeschrieben.'
                ]
            ]
        ];

        return json_encode($form);
    }

    // =========================================================================
    // Laufzeit-Daten
    // =========================================================================

    private function getRuntimeEntries(): array
    {
        $json = $this->ReadAttributeString('RuntimeEntries');
        if ($json === '' || $json === null) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }

    private function setRuntimeEntries(array $entries): void
    {
        $this->WriteAttributeString('RuntimeEntries', json_encode($entries));
    }

    // =========================================================================
    // Laden / Parsen des Catalogs
    // =========================================================================

    private function reloadAllFromCatalog(): void
    {
        IPS_LogMessage('Alexa', 'RCC-DEBUG: reloadAllFromCatalog: START');

        $catalog = $this->loadActiveCatalog();
        $rooms   = $this->extractRoomsFromCatalog($catalog);

        IPS_LogMessage(
            'Alexa',
            sprintf(
                'RCC-DEBUG: extractRoomsFromCatalog: Räume=%d',
                count($rooms)
            )
        );

        $entries = $this->buildFlatEntries($rooms);

        IPS_LogMessage(
            'Alexa',
            sprintf(
                'RCC-DEBUG: reloadAllFromCatalog: erzeugte Einträge=%d',
                count($entries)
            )
        );

        $this->setRuntimeEntries($entries);
    }

    /**
     * Bevorzugt RoomsCatalogEdit, sonst produktiver RoomsCatalog.
     */
    private function loadActiveCatalog(): array
    {
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $prodId = $this->ReadPropertyInteger('RoomsCatalogScriptID');

        $catalog = $this->loadCatalogByScriptId($editId);
        if ($catalog !== []) {
            return $catalog;
        }
        return $this->loadCatalogByScriptId($prodId);
    }

    private function loadCatalogByScriptId(int $scriptId): array
    {
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            return [];
        }

        $file = IPS_GetScriptFile($scriptId);
        if ($file === '' || !file_exists($file)) {
            return [];
        }

        $cfg = @require $file;
        if (!is_array($cfg)) {
            return [];
        }

        // Dein Format: direkt Räume als Top-Level, plus "global"
        return $cfg;
    }

    /**
     * Top-Level-Array → nur Räume extrahieren (global u.ä. ignorieren).
     */
    private function extractRoomsFromCatalog(array $catalog): array
    {
        $rooms = [];
        foreach ($catalog as $key => $value) {
            if ($key === 'global') {
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            if (!isset($value['domains']) || !is_array($value['domains'])) {
                continue;
            }
            $rooms[$key] = $value;
        }
        return $rooms;
    }

    /**
     * Erzeugt eine flache Liste aller Einträge aus allen Räumen/Domains/Groups.
     */
    private function buildFlatEntries(array $rooms): array
    {
        $rows = [];

        foreach ($rooms as $roomKey => $roomCfg) {
            $roomLabel = (string)($roomCfg['display'] ?? $roomKey);
            $domains   = $roomCfg['domains'] ?? [];
            if (!is_array($domains)) {
                continue;
            }

            foreach ($domains as $domainKey => $domainCfg) {
                if (!is_array($domainCfg)) {
                    continue;
                }

                foreach ($domainCfg as $groupKey => $groupCfg) {
                    if (!is_array($groupCfg)) {
                        // z.B. direkt 'wert' o.ä. – machen wir zu einem Pseudo-Eintrag
                        $rows[] = $this->buildRow(
                            (string)$roomKey,
                            $roomLabel,
                            (string)$domainKey,
                            (string)$groupKey,
                            (string)$groupKey,
                            $groupCfg
                        );
                        continue;
                    }

                    // Hier: heizung hat z.B. 'buero' => [ title, ist, stellung, ... ]
                    // licht.switches: 'switches' => [ key => cfg, ... ]
                    // Wir behandeln IMMER die inneren Keys als einzelne Zeilen.
                    foreach ($groupCfg as $key => $cfg) {
                        $rows[] = $this->buildRow(
                            (string)$roomKey,
                            $roomLabel,
                            (string)$domainKey,
                            (string)$groupKey,
                            (string)$key,
                            $cfg
                        );
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Eine einzelne Zeile (Entry) erzeugen + Heuristik für IDs.
     *
     * @param mixed $cfg  entweder Array (z.B. ['title'=>..., 'wert'=>...]) oder Skalar (z.B. 28944)
     */
    private function buildRow(string $roomKey, string $roomLabel, string $domain, string $group, string $key, $cfg): array
    {
        $label      = '';
        $entityId   = 0;
        $entityName = '';
        $controlId  = 0;
        $statusId   = 0;
        $tiltId     = 0;
        $speechKey  = '';
        $icon       = '';
        $order      = 0;

        if (is_array($cfg)) {
            $label = (string)($cfg['title'] ?? $cfg['label'] ?? '');
            $icon  = (string)($cfg['icon'] ?? $cfg['iconOn'] ?? $cfg['iconOff'] ?? '');
            $order = (int)($cfg['order'] ?? 0);

            // Domain-spezifische Heuristik
            if ($domain === 'jalousie') {
                if ($key === 'wert' && is_numeric($cfg)) {
                    $entityId = (int)$cfg;
                } elseif (isset($cfg['wert']) && is_numeric($cfg['wert'])) {
                    $entityId = (int)$cfg['wert'];
                }

                $controlId = (int)($cfg['controlId'] ?? 0);
                $statusId  = (int)($cfg['statusId'] ?? 0);
                $tiltId    = (int)($cfg['tiltId'] ?? 0);
            } elseif ($domain === 'licht') {
                if ($group === 'switches') {
                    // state = Status, toggle = Control
                    if ($key === 'state' && is_numeric($cfg)) {
                        $statusId = (int)$cfg;
                    }
                    if ($key === 'toggle' && is_numeric($cfg)) {
                        $controlId = (int)$cfg;
                    }
                    if (isset($cfg['state']) && is_numeric($cfg['state'])) {
                        $statusId = (int)$cfg['state'];
                    }
                    if (isset($cfg['toggle']) && is_numeric($cfg['toggle'])) {
                        $controlId = (int)$cfg['toggle'];
                    }
                } elseif ($group === 'dimmers') {
                    if ($key === 'value' && is_numeric($cfg)) {
                        $statusId = (int)$cfg;
                    }
                    if ($key === 'set' && is_numeric($cfg)) {
                        $controlId = (int)$cfg;
                    }
                    if (isset($cfg['value']) && is_numeric($cfg['value'])) {
                        $statusId = (int)$cfg['value'];
                    }
                    if (isset($cfg['set']) && is_numeric($cfg['set'])) {
                        $controlId = (int)$cfg['set'];
                    }
                } elseif ($group === 'status') {
                    if ($key === 'value' && is_numeric($cfg)) {
                        $statusId = (int)$cfg;
                    }
                    if (isset($cfg['value']) && is_numeric($cfg['value'])) {
                        $statusId = (int)$cfg['value'];
                    }
                }
            }

            $speechKey = (string)($cfg['speechKey'] ?? '');
        } else {
            // Skalar (int/string) → primär als EntityId interpretieren
            if (is_numeric($cfg)) {
                $entityId = (int)$cfg;
            }
        }

        // Generische Fallbacks:
        // - Wenn key „entityId“ heißt → Wert direkt in EntityId
        if ($key === 'entityId' && is_numeric($cfg)) {
            $entityId = (int)$cfg;
        }
        if ($key === 'controlId' && is_numeric($cfg)) {
            $controlId = (int)$cfg;
        }
        if ($key === 'statusId' && is_numeric($cfg)) {
            $statusId = (int)$cfg;
        }
        if ($key === 'tiltId' && is_numeric($cfg)) {
            $tiltId = (int)$cfg;
        }
        if ($key === 'order' && is_numeric($cfg)) {
            $order = (int)$cfg;
        }

        if ($entityId > 0 && IPS_ObjectExists($entityId)) {
            $entityName = IPS_GetName($entityId);
        }

        return [
            'selected'   => false,
            'roomKey'    => $roomKey,
            'roomLabel'  => $roomLabel,
            'domain'     => $domain,
            'group'      => $group,
            'key'        => $key,
            'label'      => $label,
            'entityId'   => $entityId,
            'entityName' => $entityName,
            'controlId'  => $controlId,
            'statusId'   => $statusId,
            'tiltId'     => $tiltId,
            'speechKey'  => $speechKey,
            'icon'       => $icon,
            'order'      => $order,
            'rowColor'   => ''
        ];
    }

    // =========================================================================
    // Filter-Optionen & Zeilenfärbung
    // =========================================================================

    private function buildRoomFilterOptions(array $entries): array
    {
        $options = [
            [
                'caption' => 'Alle',
                'value'   => ''
            ]
        ];

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
        $options = [
            [
                'caption' => 'Alle',
                'value'   => ''
            ]
        ];

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
            $entityId  = (int)($row['entityId'] ?? 0);
            $controlId = (int)($row['controlId'] ?? 0);
            $statusId  = (int)($row['statusId'] ?? 0);
            $tiltId    = (int)($row['tiltId'] ?? 0);

            $rowColor = '';

            // Sehr grobe Heuristik:
            // - Wenn alles 0 → rot
            // - Wenn etwas 0, aber nicht alles → gelb
            // - Sonst keine Farbe
            $nonZero = 0;
            foreach ([$entityId, $controlId, $statusId, $tiltId] as $v) {
                if ($v !== 0) {
                    $nonZero++;
                }
            }

            if ($nonZero === 0) {
                $rowColor = '#FFCDD2'; // rot
            } elseif ($nonZero < 2) {
                $rowColor = '#FFF9C4'; // gelb
            }

            $row['rowColor'] = $rowColor;
        }
        unset($row);

        return $entries;
    }
}
