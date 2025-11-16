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
 */

declare(strict_types=1);

class RoomsCatalogConfigurator extends IPSModule
{
    private bool $autoInitializingContext = false;

    public function Create()
    {
        // Diese Zeile nicht löschen
        parent::Create();

        // Grundkonfiguration
        $this->RegisterPropertyInteger('RoomsCatalogScriptID', 0);
        $this->RegisterPropertyInteger('RoomsCatalogEditScriptID', 0);

        // Kontext-Auswahl
        $this->RegisterPropertyString('SelectedRoom', '');
        $this->RegisterPropertyString('SelectedDomain', '');
        $this->RegisterPropertyString('SelectedGroup', '');

        // Eintragsliste für aktuellen Kontext (als JSON)
        $this->RegisterPropertyString('Entries', '[]');
    }

    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        if ($this->autoInitializingContext) {
            return;
        }

        if ($this->shouldInitializeContextFromCatalog()) {
            $this->autoInitializingContext = true;
            $initialized                  = $this->autoPopulateContextFromCatalog();
            $this->autoInitializingContext = false;

            if ($initialized) {
                IPS_ApplyChanges($this->InstanceID);
                $this->ReloadForm();
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'RoomsCatalogScriptID':
            case 'RoomsCatalogEditScriptID':
                IPS_SetProperty($this->InstanceID, $Ident, (int)$Value);
                $this->resetContextSelection();
                $this->autoPopulateContextFromCatalog();
                break;
            case 'SelectedRoom':
                IPS_SetProperty($this->InstanceID, 'SelectedRoom', (string)$Value);
                IPS_SetProperty($this->InstanceID, 'SelectedDomain', '');
                IPS_SetProperty($this->InstanceID, 'SelectedGroup', '');
                IPS_SetProperty($this->InstanceID, 'Entries', '[]');
                break;
            case 'SelectedDomain':
                IPS_SetProperty($this->InstanceID, 'SelectedDomain', (string)$Value);
                IPS_SetProperty($this->InstanceID, 'SelectedGroup', '');
                IPS_SetProperty($this->InstanceID, 'Entries', '[]');
                break;
            case 'SelectedGroup':
                IPS_SetProperty($this->InstanceID, 'SelectedGroup', (string)$Value);
                if ((string)$Value === '') {
                    IPS_SetProperty($this->InstanceID, 'Entries', '[]');
                } else {
                    if (!$this->populateEntriesForCurrentSelection()) {
                        IPS_SetProperty($this->InstanceID, 'Entries', '[]');
                    }
                }
                break;
            default:
                throw new Exception('Invalid Ident');
        }

        IPS_ApplyChanges($this->InstanceID);
        $this->ReloadForm();
    }

    public function GetConfigurationForm()
    {
        $roomsCatalog = $this->loadRoomsCatalog();
        $rooms        = $roomsCatalog['rooms'] ?? [];

        $selectedRoom   = $this->ReadPropertyString('SelectedRoom');
        $selectedDomain = $this->ReadPropertyString('SelectedDomain');
        $selectedGroup  = $this->ReadPropertyString('SelectedGroup');

        $roomOptions   = $this->buildRoomOptions($rooms);
        $domainOptions = $this->buildDomainOptions($rooms, $selectedRoom);
        $groupOptions  = $this->buildGroupOptions($rooms, $selectedRoom, $selectedDomain);

        $entriesJson = $this->ReadPropertyString('Entries');
        if ($entriesJson === '' || $entriesJson === null) {
            $entriesJson = '[]';
        }
        $entries = json_decode($entriesJson, true);
        if (!is_array($entries)) {
            $entries = [];
        }

        // Zeilenfärbung nach Vollständigkeit anwenden
        $entries = $this->applyRowColors($entries, $selectedDomain, $selectedGroup);

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
                            'value'   => $this->ReadPropertyInteger('RoomsCatalogScriptID'),
                            'onChange' => 'IPS_RequestAction($id, "RoomsCatalogScriptID", $RoomsCatalogScriptID);'
                        ],
                        [
                            'type'    => 'SelectScript',
                            'name'    => 'RoomsCatalogEditScriptID',
                            'caption' => 'RoomsCatalog Edit-Script',
                            'value'   => $this->ReadPropertyInteger('RoomsCatalogEditScriptID'),
                            'onChange' => 'IPS_RequestAction($id, "RoomsCatalogEditScriptID", $RoomsCatalogEditScriptID);'
                        ]
                    ]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Einträge im aktuellen Kontext',
                    'items'   => [
                        [
                            'type'       => 'List',
                            'name'       => 'Entries',
                            'caption'    => 'Einträge',
                            'rowCount'   => 15,
                            'add'        => true,
                            'delete'     => true,
                            'sort'       => false,
                            'columns'    => [
                                [
                                    'caption' => 'Markiert',
                                    'name'    => 'selected',
                                    'width'   => '60px',
                                    'add'     => false,
                                    'edit'    => [
                                        'type' => 'CheckBox'
                                    ]
                                ],
                                [
                                    'caption' => 'Key',
                                    'name'    => 'key',
                                    'width'   => '150px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'Label',
                                    'name'    => 'label',
                                    'width'   => '200px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'EntityId',
                                    'name'    => 'entityId',
                                    'width'   => '90px',
                                    'add'     => 0,
                                    'edit'    => [
                                        'type' => 'NumberSpinner'
                                    ]
                                ],
                                [
                                    'caption' => 'Entity-Name',
                                    'name'    => 'entityName',
                                    'width'   => '200px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'ControlId',
                                    'name'    => 'controlId',
                                    'width'   => '90px',
                                    'add'     => 0,
                                    'edit'    => [
                                        'type' => 'NumberSpinner'
                                    ]
                                ],
                                [
                                    'caption' => 'StatusId',
                                    'name'    => 'statusId',
                                    'width'   => '90px',
                                    'add'     => 0,
                                    'edit'    => [
                                        'type' => 'NumberSpinner'
                                    ]
                                ],
                                [
                                    'caption' => 'TiltId',
                                    'name'    => 'tiltId',
                                    'width'   => '90px',
                                    'add'     => 0,
                                    'edit'    => [
                                        'type' => 'NumberSpinner'
                                    ]
                                ],
                                [
                                    'caption' => 'Sprach-Key',
                                    'name'    => 'speechKey',
                                    'width'   => '160px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'Icon',
                                    'name'    => 'icon',
                                    'width'   => '140px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'Order',
                                    'name'    => 'order',
                                    'width'   => '70px',
                                    'add'     => 0,
                                    'edit'    => [
                                        'type' => 'NumberSpinner'
                                    ]
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
                    'caption' => 'Kontext laden / speichern',
                    'items'   => [
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Select',
                                    'name'    => 'SelectedRoom',
                                    'caption' => 'Raum',
                                    'options' => $roomOptions,
                                    'value'   => $selectedRoom,
                                    'onChange' => 'IPS_RequestAction($id, "SelectedRoom", $SelectedRoom);'
                                ],
                                [
                                    'type'    => 'Select',
                                    'name'    => 'SelectedDomain',
                                    'caption' => 'Funktion / Domain',
                                    'options' => $domainOptions,
                                    'value'   => $selectedDomain,
                                    'onChange' => 'IPS_RequestAction($id, "SelectedDomain", $SelectedDomain);'
                                ],
                                [
                                    'type'    => 'Select',
                                    'name'    => 'SelectedGroup',
                                    'caption' => 'Untergruppe',
                                    'options' => $groupOptions,
                                    'value'   => $selectedGroup,
                                    'onChange' => 'IPS_RequestAction($id, "SelectedGroup", $SelectedGroup);'
                                ]
                            ]
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Kontext aus RoomsCatalogEdit laden',
                            'onClick' => 'RCC_LoadContext($id);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Kontext aus produktivem RoomsCatalog laden',
                            'onClick' => 'RCC_LoadContextFromProductive($id);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Kontext in RoomsCatalogEdit aus produktivem RoomsCatalog erstellen',
                            'onClick' => 'RCC_CreateContextFromProductive($id);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Einträge in RoomsCatalogEdit speichern',
                            'onClick' => 'RCC_SaveContext($id, json_encode($Entries));'
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
                            'type'    => 'RowLayout',
                            'items'   => [
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
                            'type'    => 'RowLayout',
                            'items'   => [
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
    // Public API (wird aus dem Formular aufgerufen)
    // ========================================================================================

    public function LoadContext()
    {
        if (!$this->ensureContextSelection()) {
            echo 'Bitte zuerst Raum, Domain und Untergruppe auswählen (RoomsCatalog Script wählen?).';
            return;
        }

        $roomsCatalog = $this->loadRoomsCatalogEdit();

        $roomKey = $this->ReadPropertyString('SelectedRoom');
        $domain  = $this->ReadPropertyString('SelectedDomain');
        $group   = $this->ReadPropertyString('SelectedGroup');

        if (!isset($roomsCatalog['rooms'][$roomKey]['domains'][$domain][$group])) {
            $entries = [];
        } else {
            $entries = $this->buildEntriesFromCatalog(
                $roomsCatalog['rooms'][$roomKey]['domains'][$domain][$group],
                $roomKey,
                $domain,
                $group
            );
        }

        $entriesJson = json_encode($entries);

        IPS_SetProperty($this->InstanceID, 'Entries', $entriesJson);
        IPS_ApplyChanges($this->InstanceID);

        echo 'Kontext geladen. Einträge können jetzt bearbeitet werden.';
    }

    public function LoadContextFromProductive()
    {
        if (!$this->ensureContextSelection()) {
            echo 'Bitte zuerst Raum, Domain und Untergruppe auswählen (RoomsCatalog Script wählen?).';
            return;
        }

        $roomsCatalog = $this->loadRoomsCatalog();

        $roomKey = $this->ReadPropertyString('SelectedRoom');
        $domain  = $this->ReadPropertyString('SelectedDomain');
        $group   = $this->ReadPropertyString('SelectedGroup');

        if (!isset($roomsCatalog['rooms'][$roomKey]['domains'][$domain][$group])) {
            echo 'Im produktiven RoomsCatalog wurde dieser Kontext nicht gefunden.';
            return;
        }

        $entries = $this->buildEntriesFromCatalog(
            $roomsCatalog['rooms'][$roomKey]['domains'][$domain][$group],
            $roomKey,
            $domain,
            $group
        );

        IPS_SetProperty($this->InstanceID, 'Entries', json_encode($entries));
        IPS_ApplyChanges($this->InstanceID);

        echo 'Kontext aus produktivem RoomsCatalog geladen. Änderungen werden nicht automatisch gespeichert.';
    }

    public function CreateContextFromProductive()
    {
        if (!$this->ensureContextSelection()) {
            echo 'Bitte zuerst Raum, Domain und Untergruppe auswählen (RoomsCatalog Script wählen?).';
            return;
        }

        $productiveCatalog = $this->loadRoomsCatalog();
        $editCatalog       = $this->loadRoomsCatalogEdit();

        $roomKey = $this->ReadPropertyString('SelectedRoom');
        $domain  = $this->ReadPropertyString('SelectedDomain');
        $group   = $this->ReadPropertyString('SelectedGroup');

        $productiveGroup = $productiveCatalog['rooms'][$roomKey]['domains'][$domain][$group] ?? [];

        if (!isset($editCatalog['rooms'])) {
            $editCatalog['rooms'] = [];
        }
        if (!isset($editCatalog['rooms'][$roomKey])) {
            $editCatalog['rooms'][$roomKey] = [
                'display' => $productiveCatalog['rooms'][$roomKey]['display'] ?? $roomKey,
                'domains' => []
            ];
        } elseif (!isset($editCatalog['rooms'][$roomKey]['domains'])) {
            $editCatalog['rooms'][$roomKey]['domains'] = [];
        }
        if (!isset($editCatalog['rooms'][$roomKey]['domains'][$domain])) {
            $editCatalog['rooms'][$roomKey]['domains'][$domain] = [];
        }

        $editCatalog['rooms'][$roomKey]['domains'][$domain][$group] = $productiveGroup;

        $this->writeRoomsCatalogEdit($editCatalog);

        $entries = $this->buildEntriesFromCatalog(
            $productiveGroup,
            $roomKey,
            $domain,
            $group
        );

        IPS_SetProperty($this->InstanceID, 'Entries', json_encode($entries));
        IPS_ApplyChanges($this->InstanceID);

        echo 'Kontext wurde aus dem produktiven RoomsCatalog in das Edit-Script übernommen.';
    }

    public function SaveContext(string $entriesJson)
    {
        if (!$this->ensureContextSelection()) {
            echo 'Bitte zuerst Raum, Domain und Untergruppe auswählen (RoomsCatalog Script wählen?).';
            return;
        }

        $roomsCatalog = $this->loadRoomsCatalogEdit();

        $roomKey = $this->ReadPropertyString('SelectedRoom');
        $domain  = $this->ReadPropertyString('SelectedDomain');
        $group   = $this->ReadPropertyString('SelectedGroup');

        $entries = json_decode($entriesJson, true);
        if (!is_array($entries)) {
            $entries = [];
        }

        $oldGroup = $roomsCatalog['rooms'][$roomKey]['domains'][$domain][$group] ?? [];
        if (!is_array($oldGroup)) {
            $oldGroup = [];
        }

        $newGroup = [];

        foreach ($entries as $row) {
            $key = trim((string)($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $cfg = $oldGroup[$key] ?? [];

            $cfg['label']      = (string)($row['label'] ?? '');
            $cfg['entityId']   = (int)($row['entityId'] ?? 0);
            $cfg['entityName'] = (string)($row['entityName'] ?? '');
            $cfg['controlId']  = (int)($row['controlId'] ?? 0);
            $cfg['statusId']   = (int)($row['statusId'] ?? 0);
            $cfg['tiltId']     = (int)($row['tiltId'] ?? 0);
            $cfg['speechKey']  = (string)($row['speechKey'] ?? '');
            $cfg['icon']       = (string)($row['icon'] ?? '');
            $cfg['order']      = (int)($row['order'] ?? 0);

            $newGroup[$key] = $cfg;
        }

        $roomsCatalog['rooms'][$roomKey]['domains'][$domain][$group] = $newGroup;

        $this->writeRoomsCatalogEdit($roomsCatalog);

        // Entries-Property aktualisieren (damit bei nächstem Öffnen konsistent)
        IPS_SetProperty($this->InstanceID, 'Entries', json_encode($entries));
        IPS_ApplyChanges($this->InstanceID);

        echo 'Einträge wurden in RoomsCatalogEdit gespeichert.';
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

    public function ShowDiff()
    {
        $prod  = $this->loadRoomsCatalog();
        $edit  = $this->loadRoomsCatalogEdit();

        $prodRooms = $prod['rooms'] ?? [];
        $editRooms = $edit['rooms'] ?? [];

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
                            // beides vorhanden → vergleichen
                            $prodCfg = $prodEntries[$entryKey];
                            $editCfg = $editEntries[$entryKey];

                            if (!is_array($prodCfg)) {
                                $prodCfg = [];
                            }
                            if (!is_array($editCfg)) {
                                $editCfg = [];
                            }

                            // einfache Normalisierung: sortierte JSONs vergleichen
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

        if (empty($lines)) {
            echo "Keine Unterschiede zwischen produktivem RoomsCatalog und Edit gefunden.";
        } else {
            echo implode("\n", $lines);
        }
    }

    // ========================================================================================
    // Interne Helfer
    // ========================================================================================

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

        // Property & Formular aktualisieren
        IPS_SetProperty($this->InstanceID, 'Entries', $newJson);
        IPS_ApplyChanges($this->InstanceID);

        echo 'Objekt wurde auf markierte Zeilen angewendet.';
    }

    private function applyRowColors(array $entries, string $domain, string $group): array
    {
        foreach ($entries as &$row) {
            $key      = trim((string)($row['key'] ?? ''));
            $label    = trim((string)($row['label'] ?? ''));
            $entityId = (int)($row['entityId'] ?? 0);
            $control  = (int)($row['controlId'] ?? 0);
            $status   = (int)($row['statusId'] ?? 0);
            $tilt     = (int)($row['tiltId'] ?? 0);

            $rowColor = '';

            // Harte Fehler: Key/Label fehlen oder gar keine steuernde Entity
            if ($key === '' || $label === '' || ($entityId === 0 && $control === 0)) {
                $rowColor = '#FFCDD2'; // helles Rot
            } else {
                // Domain-spezifische "Warnungen"
                if ($domain === 'jalousie') {
                    if ($status === 0 || $tilt === 0) {
                        $rowColor = '#FFF9C4'; // helles Gelb
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
                if (isset($row['rowColor'])) {
                    unset($row['rowColor']);
                }
            }
        }
        unset($row);

        return $entries;
    }

    private function loadRoomsCatalog(): array
    {
        $scriptId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            return [];
        }

        $file = IPS_GetScriptFile($scriptId);
        if ($file === '' || !file_exists($file)) {
            return [];
        }

        $catalog = @require $file;
        if (!is_array($catalog)) {
            return [];
        }

        return $catalog;
    }

    private function resetContextSelection(): void
    {
        IPS_SetProperty($this->InstanceID, 'SelectedRoom', '');
        IPS_SetProperty($this->InstanceID, 'SelectedDomain', '');
        IPS_SetProperty($this->InstanceID, 'SelectedGroup', '');
        IPS_SetProperty($this->InstanceID, 'Entries', '[]');
    }

    private function autoPopulateContextFromCatalog(): bool
    {
        if ($this->populateEntriesForCurrentSelection()) {
            return true;
        }

        $catalogsToInspect = [
            $this->loadRoomsCatalogEdit(),
            $this->loadRoomsCatalog()
        ];

        foreach ($catalogsToInspect as $catalog) {
            if (!isset($catalog['rooms']) || !is_array($catalog['rooms'])) {
                continue;
            }

            foreach ($catalog['rooms'] as $roomKey => $roomCfg) {
                $domains = $roomCfg['domains'] ?? [];
                if (!is_array($domains) || $domains === []) {
                    continue;
                }

                foreach ($domains as $domainKey => $domainCfg) {
                    if (!is_array($domainCfg) || $domainCfg === []) {
                        continue;
                    }

                    foreach ($domainCfg as $groupKey => $groupCfg) {
                        $entries = $this->buildEntriesFromCatalog(
                            is_array($groupCfg) ? $groupCfg : [],
                            (string)$roomKey,
                            (string)$domainKey,
                            (string)$groupKey
                        );

                        IPS_SetProperty($this->InstanceID, 'SelectedRoom', (string)$roomKey);
                        IPS_SetProperty($this->InstanceID, 'SelectedDomain', (string)$domainKey);
                        IPS_SetProperty($this->InstanceID, 'SelectedGroup', (string)$groupKey);
                        IPS_SetProperty($this->InstanceID, 'Entries', json_encode($entries));
                        return true;
                    }
                }
            }
        }

        IPS_SetProperty($this->InstanceID, 'Entries', '[]');
        return false;
    }

    private function shouldInitializeContextFromCatalog(): bool
    {
        $hasScript = $this->ReadPropertyInteger('RoomsCatalogScriptID') > 0
            || $this->ReadPropertyInteger('RoomsCatalogEditScriptID') > 0;

        if (!$hasScript) {
            return false;
        }

        if (!$this->hasCompleteContextSelection()) {
            return true;
        }

        $entriesJson = $this->ReadPropertyString('Entries');
        $entries     = json_decode($entriesJson, true);

        if (!is_array($entries) || $entries === []) {
            return true;
        }

        return false;
    }

    private function hasCompleteContextSelection(): bool
    {
        return $this->ReadPropertyString('SelectedRoom') !== ''
            && $this->ReadPropertyString('SelectedDomain') !== ''
            && $this->ReadPropertyString('SelectedGroup') !== '';
    }

    private function ensureContextSelection(): bool
    {
        if ($this->hasCompleteContextSelection()) {
            return true;
        }

        if (!$this->autoPopulateContextFromCatalog()) {
            return false;
        }

        IPS_ApplyChanges($this->InstanceID);
        $this->ReloadForm();

        return true;
    }

    private function populateEntriesForCurrentSelection(): bool
    {
        $roomKey = $this->ReadPropertyString('SelectedRoom');
        $domain  = $this->ReadPropertyString('SelectedDomain');
        $group   = $this->ReadPropertyString('SelectedGroup');

        if ($roomKey === '' || $domain === '' || $group === '') {
            return false;
        }

        $catalog = $this->loadRoomsCatalogEdit();
        $groupCfg = $catalog['rooms'][$roomKey]['domains'][$domain][$group] ?? null;

        if (!is_array($groupCfg)) {
            $catalog  = $this->loadRoomsCatalog();
            $groupCfg = $catalog['rooms'][$roomKey]['domains'][$domain][$group] ?? null;
            if (!is_array($groupCfg)) {
                return false;
            }
        }

        $entries = $this->buildEntriesFromCatalog($groupCfg, $roomKey, $domain, $group);
        IPS_SetProperty($this->InstanceID, 'Entries', json_encode($entries));

        return true;
    }

    private function loadRoomsCatalogEdit(): array
    {
        $scriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            // Fallback: produktives Script
            return $this->loadRoomsCatalog();
        }

        $file = IPS_GetScriptFile($scriptId);
        if ($file === '' || !file_exists($file)) {
            return $this->loadRoomsCatalog();
        }

        $catalog = @require $file;
        if (!is_array($catalog)) {
            return $this->loadRoomsCatalog();
        }

        return $catalog;
    }

    private function writeRoomsCatalogEdit(array $catalog): void
    {
        $scriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            echo 'RoomsCatalogEditScriptID ist nicht gesetzt oder Script existiert nicht.';
            return;
        }

        $php = "<?php\nreturn " . var_export($catalog, true) . ";\n";
        IPS_SetScriptContent($scriptId, $php);
    }

    private function buildRoomOptions(array $rooms): array
    {
        $options   = [];
        $options[] = [
            'caption' => '– bitte wählen –',
            'value'   => ''
        ];

        foreach ($rooms as $key => $room) {
            $caption   = $room['display'] ?? (string)$key;
            $options[] = [
                'caption' => $caption . ' [' . $key . ']',
                'value'   => (string)$key
            ];
        }

        return $options;
    }

    private function buildDomainOptions(array $rooms, string $selectedRoom): array
    {
        $options   = [];
        $options[] = [
            'caption' => '– bitte wählen –',
            'value'   => ''
        ];

        if ($selectedRoom === '' || !isset($rooms[$selectedRoom]['domains'])) {
            return $options;
        }

        $domains = $rooms[$selectedRoom]['domains'];

        foreach ($domains as $key => $_domainCfg) {
            $options[] = [
                'caption' => (string)$key,
                'value'   => (string)$key
            ];
        }

        return $options;
    }

    private function buildGroupOptions(array $rooms, string $selectedRoom, string $selectedDomain): array
    {
        $options   = [];
        $options[] = [
            'caption' => '– bitte wählen –',
            'value'   => ''
        ];

        if ($selectedRoom === '' || $selectedDomain === '') {
            return $options;
        }

        if (!isset($rooms[$selectedRoom]['domains'][$selectedDomain])) {
            return $options;
        }

        $domainCfg = $rooms[$selectedRoom]['domains'][$selectedDomain];

        foreach ($domainCfg as $groupKey => $_groupCfg) {
            $options[] = [
                'caption' => (string)$groupKey,
                'value'   => (string)$groupKey
            ];
        }

        return $options;
    }

    private function buildEntriesFromCatalog(array $groupCfg, string $roomKey, string $domain, string $group): array
    {
        $rows = [];

        foreach ($groupCfg as $entryKey => $cfg) {
            if (!is_array($cfg)) {
                $cfg = [];
            }

            $rows[] = [
                'selected'   => false,
                'key'        => (string)$entryKey,
                'label'      => (string)($cfg['label'] ?? ($cfg['name'] ?? '')),
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

        return $rows;
    }

    private function normalizeCfg(array $cfg): array
    {
        // Nur die relevanten Keys sortiert vergleichen
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
}
