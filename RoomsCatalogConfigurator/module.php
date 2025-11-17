<?php

/**
 * ============================================================
 * ROOMS CATALOG CONFIGURATOR — IP-Symcon Modul
 * ============================================================
 *
 * Änderungsverlauf
 * 2025-11-18: Plain-Root-Schema + Filter (READ-ONLY)
 * - Erwartet RoomsCatalog als: return ['buero'=>[...], 'kueche'=>[...], ..., 'global'=>[...]];
 * - Kein ['rooms']-Container mehr, 'global' wird beim Flatten ignoriert
 * - Lädt immer alle Räume/Domains in eine flache Liste (RuntimeEntries)
 * - Raum- und Domain-Filter oberhalb der Liste (Attribute, nicht Properties)
 * - Nur Lesemodus: keine Schreibzugriffe zurück ins Script (Edit-Funktionen folgen)
 */

declare(strict_types=1);

class RoomsCatalogConfigurator extends IPSModule
{
    private const LOG_TAG = 'Alexa';

    public function Create()
    {
        parent::Create();

        // Haupt-Konfiguration: Script-IDs
        $this->RegisterPropertyInteger('RoomsCatalogScriptID', 0);
        $this->RegisterPropertyInteger('RoomsCatalogEditScriptID', 0);

        // Laufzeitdaten (werden nur in Attributen gehalten)
        $this->RegisterAttributeString('RuntimeEntries', '[]');
        $this->RegisterAttributeString('RuntimeRoomFilter', '');
        $this->RegisterAttributeString('RuntimeDomainFilter', '');
        $this->RegisterAttributeString('RuntimeRoomOptions', '[]');
        $this->RegisterAttributeString('RuntimeDomainOptions', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->debug('ApplyChanges START');

        // Bei jeder Konfig-Änderung einmal den Katalog neu einlesen
        $this->reloadAllFromCatalog();

        $this->debug('ApplyChanges ENDE');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'FilterRoom':
                $this->WriteAttributeString('RuntimeRoomFilter', (string)$Value);
                $this->ReloadForm();
                break;

            case 'FilterDomain':
                $this->WriteAttributeString('RuntimeDomainFilter', (string)$Value);
                $this->ReloadForm();
                break;

            case 'ReloadCatalog':
                $this->reloadAllFromCatalog();
                $this->ReloadForm();
                echo 'RoomsCatalog neu geladen.';
                break;

            default:
                throw new Exception('Invalid Ident in RequestAction: ' . $Ident);
        }
    }

    public function GetConfigurationForm()
    {
        $entriesAll = $this->getRuntimeEntries();

        $filterRoom   = $this->ReadAttributeString('RuntimeRoomFilter');
        $filterDomain = $this->ReadAttributeString('RuntimeDomainFilter');

        // Optionen (bereits bei reloadAllFromCatalog() berechnet)
        $roomOptions   = $this->getRuntimeRoomOptions();
        $domainOptions = $this->getRuntimeDomainOptions();

        // Fallback, falls noch nichts geladen wurde
        if ($roomOptions === []) {
            $roomOptions[] = ['caption' => 'Alle', 'value' => ''];
        }
        if ($domainOptions === []) {
            $domainOptions[] = ['caption' => 'Alle', 'value' => ''];
        }

        // Filter anwenden
        $visibleEntries = [];
        foreach ($entriesAll as $row) {
            $roomKey   = (string)($row['roomKey'] ?? '');
            $domainKey = (string)($row['domain'] ?? '');

            if ($filterRoom !== '' && $roomKey !== $filterRoom) {
                continue;
            }
            if ($filterDomain !== '' && $domainKey !== $filterDomain) {
                continue;
            }

            // Zeilenfarbe nach Vollständigkeit (einfach gehalten)
            $row['rowColor'] = $this->determineRowColor($row);
            $visibleEntries[] = $row;
        }

        $this->debug(sprintf(
            'GetConfigurationForm: RuntimeEntries total=%d, FilterRoom="%s", FilterDomain="%s", sichtbare Einträge=%d',
            count($entriesAll),
            $filterRoom,
            $filterDomain,
            count($visibleEntries)
        ));

        $form = [
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'RoomsCatalog Scripts',
                    'items'   => [
                        [
                            'type'    => 'SelectScript',
                            'name'    => 'RoomsCatalogScriptID',
                            'caption' => 'Produktiver RoomsCatalog (plain root)',
                            'value'   => $this->ReadPropertyInteger('RoomsCatalogScriptID')
                            // KEIN onChange → Wert wird nur über "Übernehmen" gespeichert
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
                                    'type'    => 'Select',
                                    'name'    => 'RoomFilter',
                                    'caption' => 'Raum-Filter',
                                    'options' => $roomOptions,
                                    'value'   => $filterRoom,
                                    'onChange' => 'IPS_RequestAction($id, "FilterRoom", $RoomFilter);'
                                ],
                                [
                                    'type'    => 'Select',
                                    'name'    => 'DomainFilter',
                                    'caption' => 'Domain-Filter',
                                    'options' => $domainOptions,
                                    'value'   => $filterDomain,
                                    'onChange' => 'IPS_RequestAction($id, "FilterDomain", $DomainFilter);'
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'RoomsCatalog neu laden',
                                    'onClick' => 'IPS_RequestAction($id, "ReloadCatalog", 0);'
                                ]
                            ]
                        ],
                        [
                            'type'       => 'List',
                            'name'       => 'Entries',
                            'caption'    => 'Einträge',
                            'rowCount'   => 20,
                            'add'        => false,
                            'delete'     => false,
                            'sort'       => true,
                            'columns'    => [
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
                                    'edit'    => ['type' => 'ValidationTextBox', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'Raum-Label',
                                    'name'    => 'roomLabel',
                                    'width'   => '120px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'Domain',
                                    'name'    => 'domain',
                                    'width'   => '80px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'Gruppe',
                                    'name'    => 'group',
                                    'width'   => '90px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'Key',
                                    'name'    => 'key',
                                    'width'   => '100px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'Label',
                                    'name'    => 'label',
                                    'width'   => '180px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'EntityId',
                                    'name'    => 'entityId',
                                    'width'   => '80px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'Entity-Name',
                                    'name'    => 'entityName',
                                    'width'   => '160px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'ControlId',
                                    'name'    => 'controlId',
                                    'width'   => '80px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'StatusId',
                                    'name'    => 'statusId',
                                    'width'   => '80px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'TiltId',
                                    'name'    => 'tiltId',
                                    'width'   => '80px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'Sprach-Key',
                                    'name'    => 'speechKey',
                                    'width'   => '130px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'Icon',
                                    'name'    => 'icon',
                                    'width'   => '120px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'Order',
                                    'name'    => 'order',
                                    'width'   => '70px',
                                    'add'     => 0,
                                    'edit'    => ['type' => 'NumberSpinner', 'enabled' => false]
                                ],
                                [
                                    'caption' => 'Farbe',
                                    'name'    => 'rowColor',
                                    'width'   => '80px',
                                    'add'     => '',
                                    'edit'    => ['type' => 'ValidationTextBox', 'enabled' => false]
                                ]
                            ],
                            'values' => $visibleEntries
                        ]
                    ]
                ]
            ],
            'actions' => [
                [
                    'type'    => 'Label',
                    'caption' => 'Hinweis: Aktuell ist der Konfigurator read-only. ' .
                        'Änderungen an den Einträgen werden noch nicht ins RoomsCatalogEdit-Script zurückgeschrieben.'
                ]
            ]
        ];

        return json_encode($form);
    }

    // ========================================================================================
    // Interne Helfer
    // ========================================================================================

    private function reloadAllFromCatalog(): void
    {
        $this->debug('reloadAllFromCatalog: START');

        $prodId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');

        // 1) Edit-Katalog versuchen
        $catalog = [];
        if ($editId > 0) {
            $catalog = $this->loadCatalogFromScript($editId, 'EDIT');
        }

        // 2) Fallback produktiv
        if ($catalog === []) {
            $catalog = $this->loadCatalogFromScript($prodId, 'PROD');
        }

        $roomsCountBefore = $this->countRoomsInCatalog($catalog);
        $this->debug('reloadAllFromCatalog: Räume im geladenen Katalog=' . $roomsCountBefore);

        $entries = $this->extractRoomsFromCatalog($catalog);

        $this->WriteAttributeString('RuntimeEntries', json_encode($entries));

        // Filter-Optionen aus den Einträgen bauen
        $this->buildFilterOptionsFromEntries($entries);

        $this->debug(sprintf(
            'reloadAllFromCatalog: erzeugte Einträge=%d',
            count($entries)
        ));
    }

    private function loadCatalogFromScript(int $scriptId, string $label): array
    {
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $this->debug("loadRoomsCatalog($label): ScriptID ungültig oder Script existiert nicht: {$scriptId}");
            return [];
        }

        $file = IPS_GetScriptFile($scriptId);

        // Kein file_exists() mehr – wir verlassen uns auf IPS_GetScriptFile
        $this->debug("loadRoomsCatalog($label): ScriptFile={$file}");

        $catalog = @require $file;

        if (!is_array($catalog)) {
            $type = gettype($catalog);
            $this->debug("loadRoomsCatalog($label): require() lieferte kein Array (Typ={$type})");
            return [];
        }

        // Erwartet: return ['buero'=>[...], 'kueche'=>[...], ..., 'global'=>[...]];
        $topKeys = implode(', ', array_keys($catalog));
        $this->debug("loadRoomsCatalog($label): Top-Level-Keys: {$topKeys}");

        return $catalog;
    }


    private function countRoomsInCatalog(array $catalog): int
    {
        if ($catalog === []) {
            return 0;
        }
        $count = 0;
        foreach ($catalog as $key => $_cfg) {
            if ($key === 'global') {
                continue;
            }
            $count++;
        }
        return $count;
    }

    /**
     * Flacht den Katalog in eine Liste von Einträgen ab.
     *
     * Jeder Leaf in domains[*][*][*] wird zu einer Tabellenzeile.
     */
    private function extractRoomsFromCatalog(array $catalog): array
    {
        $roomsCount = $this->countRoomsInCatalog($catalog);
        $this->debug("extractRoomsFromCatalog: Räume={$roomsCount}");

        $entries = [];

        foreach ($catalog as $roomKey => $roomCfg) {
            if ($roomKey === 'global') {
                continue; // global-Konfiguration auslassen
            }

            if (!is_array($roomCfg)) {
                continue;
            }

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
                        continue;
                    }

                    foreach ($groupCfg as $entryKey => $entryCfg) {
                        if (!is_array($entryCfg)) {
                            continue;
                        }

                        $label = (string)($entryCfg['label'] ?? ($entryCfg['title'] ?? $entryKey));

                        $entries[] = [
                            'selected'   => false,
                            'roomKey'    => (string)$roomKey,
                            'roomLabel'  => $roomLabel,
                            'domain'     => (string)$domainKey,
                            'group'      => (string)$groupKey,
                            'key'        => (string)$entryKey,
                            'label'      => $label,
                            // Numeric IDs — in deinem jetzigen Katalog sind das meist andere Felder
                            // (ist/stellung/soll/wert/state/toggle/...). Hier bleiben sie 0, bis
                            // wir eine Schreib-/Mappinglogik ergänzen.
                            'entityId'   => (int)($entryCfg['entityId'] ?? 0),
                            'entityName' => (string)($entryCfg['entityName'] ?? ''),
                            'controlId'  => (int)($entryCfg['controlId'] ?? 0),
                            'statusId'   => (int)($entryCfg['statusId'] ?? 0),
                            'tiltId'     => (int)($entryCfg['tiltId'] ?? 0),
                            'speechKey'  => (string)($entryCfg['speechKey'] ?? ''),
                            'icon'       => (string)($entryCfg['icon'] ?? ''),
                            'order'      => (int)($entryCfg['order'] ?? 0),
                            'rowColor'   => '' // wird später im Filterlauf gesetzt
                        ];
                    }
                }
            }
        }

        return $entries;
    }

    private function buildFilterOptionsFromEntries(array $entries): void
    {
        $rooms   = [];
        $domains = [];

        foreach ($entries as $row) {
            $rKey   = (string)($row['roomKey'] ?? '');
            $rLabel = (string)($row['roomLabel'] ?? $rKey);
            $dKey   = (string)($row['domain'] ?? '');

            if ($rKey !== '' && !isset($rooms[$rKey])) {
                $rooms[$rKey] = $rLabel;
            }

            if ($dKey !== '' && !isset($domains[$dKey])) {
                $domains[$dKey] = $dKey;
            }
        }

        $roomOptions = [
            ['caption' => 'Alle', 'value' => '']
        ];
        foreach ($rooms as $key => $caption) {
            $roomOptions[] = [
                'caption' => $caption . ' [' . $key . ']',
                'value'   => $key
            ];
        }

        $domainOptions = [
            ['caption' => 'Alle', 'value' => '']
        ];
        foreach ($domains as $key => $_caption) {
            $domainOptions[] = [
                'caption' => $key,
                'value'   => $key
            ];
        }

        $this->WriteAttributeString('RuntimeRoomOptions', json_encode($roomOptions));
        $this->WriteAttributeString('RuntimeDomainOptions', json_encode($domainOptions));

        $this->debug(sprintf(
            'buildFilterOptionsFromEntries: rooms=%d, domains=%d',
            count($roomOptions) - 1,
            count($domainOptions) - 1
        ));
    }

    private function getRuntimeEntries(): array
    {
        $json = $this->ReadAttributeString('RuntimeEntries');
        if ($json === '' || $json === null) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function getRuntimeRoomOptions(): array
    {
        $json = $this->ReadAttributeString('RuntimeRoomOptions');
        if ($json === '' || $json === null) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function getRuntimeDomainOptions(): array
    {
        $json = $this->ReadAttributeString('RuntimeDomainOptions');
        if ($json === '' || $json === null) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function determineRowColor(array $row): string
    {
        $label = trim((string)($row['label'] ?? ''));
        $room  = trim((string)($row['roomKey'] ?? ''));
        $dom   = trim((string)($row['domain'] ?? ''));
        $key   = trim((string)($row['key'] ?? ''));

        if ($room === '' || $dom === '' || $key === '' || $label === '') {
            // Sehr grobe "Fehler"-Markierung
            return '#FFCDD2'; // helles Rot
        }

        // Aktuell keine Domain-spezifische Logik → neutral
        return '';
    }

    private function debug(string $msg): void
    {
        IPS_LogMessage(self::LOG_TAG, 'RCC-DEBUG: ' . $msg);
    }
}
