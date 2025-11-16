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
 * 2025-11-16: Globale Listenansicht / Root-Schema
 * - Unterstützt RoomsCatalog ohne 'rooms'-Wrapper (Direkt 'buero','kueche',...,'global')
 * - Gesamt-Liste über alle Räume, wenn kein Kontext (Raum/Domain/Gruppe) gewählt ist
 * - Label-Spalte nutzt jetzt 'title' aus dem RoomsCatalog
 */

declare(strict_types=1);

class RoomsCatalogConfigurator extends IPSModule
{
    private bool $autoInitializingContext = false;

    public function Create()
    {
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

        // Laufzeit-Status (für sofortige Formular-Reaktionen ohne Apply)
        $this->RegisterAttributeInteger('RuntimeRoomsCatalogScriptID', 0);
        $this->RegisterAttributeInteger('RuntimeRoomsCatalogEditScriptID', 0);
        $this->RegisterAttributeString('RuntimeSelectedRoom', '');
        $this->RegisterAttributeString('RuntimeSelectedDomain', '');
        $this->RegisterAttributeString('RuntimeSelectedGroup', '');
        $this->RegisterAttributeString('RuntimeEntries', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->synchronizeRuntimeStateFromProperties();

        if ($this->autoInitializingContext) {
            return;
        }

        if ($this->shouldInitializeContextFromCatalog()) {
            $this->autoInitializingContext = true;
            $initialized                   = $this->autoPopulateContextFromCatalog();
            $this->autoInitializingContext = false;

            if ($initialized) {
                $this->ReloadForm();
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'RoomsCatalogScriptID':
                $this->setActiveRoomsCatalogScriptID((int)$Value);
                $this->resetContextSelection();
                $this->autoPopulateContextFromCatalog();
                break;

            case 'RoomsCatalogEditScriptID':
                $this->setActiveRoomsCatalogEditScriptID((int)$Value);
                $this->resetContextSelection();
                $this->autoPopulateContextFromCatalog();
                break;

            case 'SelectedRoom':
                $this->setSelectedRoomKey((string)$Value);
                $this->setSelectedDomainKey('');
                $this->setSelectedGroupKey('');
                $this->resetRuntimeEntries();
                break;

            case 'SelectedDomain':
                $this->setSelectedDomainKey((string)$Value);
                $this->setSelectedGroupKey('');
                $this->resetRuntimeEntries();
                break;

            case 'SelectedGroup':
                if ((string)$Value === '') {
                    $this->setSelectedGroupKey('');
                    $this->resetRuntimeEntries();
                } else {
                    $this->setSelectedGroupKey((string)$Value);
                    if (!$this->populateEntriesForCurrentSelection()) {
                        $this->resetRuntimeEntries();
                    }
                }
                break;

            default:
                throw new Exception('Invalid Ident');
        }

        $this->ReloadForm();
    }

    public function GetConfigurationForm()
    {
        // Für die Anzeige immer den Edit-Katalog bevorzugen
        $roomsCatalog = $this->loadRoomsCatalogEdit();

        // Räume = Root-Level ohne 'global'
        $rooms = $roomsCatalog;
        if (isset($rooms['global'])) {
            unset($rooms['global']);
        }

        $selectedRoom   = $this->getSelectedRoomKey();
        $selectedDomain = $this->getSelectedDomainKey();
        $selectedGroup  = $this->getSelectedGroupKey();

        $hasContext = $this->hasCompleteContextSelection();

        $roomOptions   = $this->buildRoomOptions($rooms);
        $domainOptions = $this->buildDomainOptions($rooms, $selectedRoom);
        $groupOptions  = $this->buildGroupOptions($rooms, $selectedRoom, $selectedDomain);

        if ($hasContext) {
            $entries = $this->getRuntimeEntries();
        } else {
            // Keine Kontext-Auswahl → globale Übersicht über alle Räume/Domains/Gruppen
            $entries = $this->buildEntriesGlobalFromCatalog($roomsCatalog);
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
                    'caption' => 'Einträge im aktuellen Kontext / Gesamtübersicht',
                    'items'   => [
                        [
                            'type'     => 'List',
                            'name'     => 'Entries',
                            'caption'  => 'Einträge',
                            'rowCount' => 15,
                            'add'      => true,
                            'delete'   => true,
                            'sort'     => false,
                            'columns'  => [
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
                                    'caption' => 'Raum',
                                    'name'    => 'room',
                                    'width'   => '120px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'Domain',
                                    'name'    => 'domain',
                                    'width'   => '90px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'Gruppe',
                                    'name'    => 'group',
                                    'width'   => '120px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
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
                                    'type'     => 'Select',
                                    'name'     => 'SelectedRoom',
                                    'caption'  => 'Raum',
                                    'options'  => $roomOptions,
                                    'value'    => $selectedRoom,
                                    'onChange' => 'IPS_RequestAction($id, "SelectedRoom", $SelectedRoom);'
                                ],
                                [
                                    'type'     => 'Select',
                                    'name'     => 'SelectedDomain',
                                    'caption'  => 'Funktion / Domain',
                                    'options'  => $domainOptions,
                                    'value'    => $selectedDomain,
                                    'onChange' => 'IPS_RequestAction($id, "SelectedDomain", $SelectedDomain);'
                                ],
                                [
                                    'type'     => 'Select',
                                    'name'     => 'SelectedGroup',
                                    'caption'  => 'Untergruppe',
                                    'options'  => $groupOptions,
                                    'value'    => $selectedGroup,
                                    'onChange' => 'IPS_RequestAction($id, "SelectedGroup", $SelectedGroup);'
                                ]
                            ]
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Kontext aus RoomsCatalogEdit laden',
                            'onClick' => 'RCC_LoadContext($id);',
                            'enabled' => $hasContext
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Kontext aus produktivem RoomsCatalog laden',
                            'onClick' => 'RCC_LoadContextFromProductive($id);',
                            'enabled' => $hasContext
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Kontext in RoomsCatalogEdit aus produktivem RoomsCatalog erstellen',
                            'onClick' => 'RCC_CreateContextFromProductive($id);',
                            'enabled' => $hasContext
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Einträge in RoomsCatalogEdit speichern',
                            'onClick' => 'RCC_SaveContext($id, json_encode($Entries));',
                            'enabled' => $hasContext
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
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'Hinweis: Ohne Raum/Domain/Untergruppe wird nur eine Gesamtübersicht angezeigt. Speichern/Laden ist dann deaktiviert.'
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
    // Public API (wird aus dem Formular aufgerufen)
    // ========================================================================================

    public function LoadContext()
    {
        if (!$this->ensureContextSelection()) {
            echo 'Bitte zuerst Raum, Domain und Untergruppe auswählen (RoomsCatalog Script wählen?).';
            return;
        }

        $roomsCatalog = $this->loadRoomsCatalogEdit();

        $roomKey = $this->getSelectedRoomKey();
        $domain  = $this->getSelectedDomainKey();
        $group   = $this->getSelectedGroupKey();

        if (!isset($roomsCatalog[$roomKey]['domains'][$domain][$group])) {
            $entries = [];
        } else {
            $entries = $this->buildEntriesFromCatalog(
                $roomsCatalog[$roomKey]['domains'][$domain][$group],
                $roomKey,
                $domain,
                $group
            );
        }

        $this->setRuntimeEntries($entries);
        $this->ReloadForm();

        echo 'Kontext geladen. Einträge können jetzt bearbeitet werden.';
    }

    public function LoadContextFromProductive()
    {
        if (!$this->ensureContextSelection()) {
            echo 'Bitte zuerst Raum, Domain und Untergruppe auswählen (RoomsCatalog Script wählen?).';
            return;
        }

        $roomsCatalog = $this->loadRoomsCatalog();

        $roomKey = $this->getSelectedRoomKey();
        $domain  = $this->getSelectedDomainKey();
        $group   = $this->getSelectedGroupKey();

        if (!isset($roomsCatalog[$roomKey]['domains'][$domain][$group])) {
            echo 'Im produktiven RoomsCatalog wurde dieser Kontext nicht gefunden.';
            return;
        }

        $entries = $this->buildEntriesFromCatalog(
            $roomsCatalog[$roomKey]['domains'][$domain][$group],
            $roomKey,
            $domain,
            $group
        );

        $this->setRuntimeEntries($entries);
        $this->ReloadForm();

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

        $roomKey = $this->getSelectedRoomKey();
        $domain  = $this->getSelectedDomainKey();
        $group   = $this->getSelectedGroupKey();

        $productiveGroup = $productiveCatalog[$roomKey]['domains'][$domain][$group] ?? [];

        if (!isset($editCatalog[$roomKey])) {
            $editCatalog[$roomKey] = [
                'display' => $productiveCatalog[$roomKey]['display'] ?? $roomKey,
                'domains' => []
            ];
        } elseif (!isset($editCatalog[$roomKey]['domains'])) {
            $editCatalog[$roomKey]['domains'] = [];
        }
        if (!isset($editCatalog[$roomKey]['domains'][$domain])) {
            $editCatalog[$roomKey]['domains'][$domain] = [];
        }

        $editCatalog[$roomKey]['domains'][$domain][$group] = $productiveGroup;

        $this->writeRoomsCatalogEdit($editCatalog);

        $entries = $this->buildEntriesFromCatalog(
            $productiveGroup,
            $roomKey,
            $domain,
            $group
        );

        $this->setRuntimeEntries($entries);
        $this->ReloadForm();

        echo 'Kontext wurde aus dem produktiven RoomsCatalog in das Edit-Script übernommen.';
    }

    public function SaveContext(string $entriesJson)
    {
        if (!$this->ensureContextSelection()) {
            echo 'Bitte zuerst Raum, Domain und Untergruppe auswählen (RoomsCatalog Script wählen?).';
            return;
        }

        $roomsCatalog = $this->loadRoomsCatalogEdit();

        $roomKey = $this->getSelectedRoomKey();
        $domain  = $this->getSelectedDomainKey();
        $group   = $this->getSelectedGroupKey();

        $entries = json_decode($entriesJson, true);
        if (!is_array($entries)) {
            $entries = [];
        }

        $oldGroup = $roomsCatalog[$roomKey]['domains'][$domain][$group] ?? [];
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

            // Label-Feld ist 'title' im RoomsCatalog
            $cfg['title']      = (string)($row['label'] ?? ($cfg['title'] ?? ''));
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

        $roomsCatalog[$roomKey]['domains'][$domain][$group] = $newGroup;

        $this->writeRoomsCatalogEdit($roomsCatalog);

        $this->setRuntimeEntries(
            $this->buildEntriesFromCatalog(
                $newGroup,
                $roomKey,
                $domain,
                $group
            )
        );
        $this->ReloadForm();

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

        // Root-Schema: Räume direkt am Root, 'global' ausklammern
        $prodRooms = $prod;
        $editRooms = $edit;
        unset($prodRooms['global'], $editRooms['global']);

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

        $this->setRuntimeEntriesFromJson($newJson);
        $this->ReloadForm();

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

            // Domain aus Kontext oder pro Zeile (für globale Ansicht)
            $rowDomain = (string)($row['domain'] ?? '');
            $effectiveDomain = $domain !== '' ? $domain : $rowDomain;

            $rowColor = '';

            // Harte Fehler: Key/Label fehlen oder gar keine steuernde Entity
            if ($key === '' || $label === '' || ($entityId === 0 && $control === 0)) {
                $rowColor = '#FFCDD2'; // helles Rot
            } else {
                // Domain-spezifische "Warnungen"
                if ($effectiveDomain === 'jalousie') {
                    if ($status === 0 || $tilt === 0) {
                        $rowColor = '#FFF9C4'; // helles Gelb
                    }
                } elseif ($effectiveDomain === 'licht') {
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
        $scriptId = $this->getActiveRoomsCatalogScriptID();
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
        $this->setSelectedRoomKey('');
        $this->setSelectedDomainKey('');
        $this->setSelectedGroupKey('');
        $this->resetRuntimeEntries();
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
            if (!is_array($catalog) || $catalog === []) {
                continue;
            }

            // Root-Schema: Räume direkt am Root, 'global' ignorieren
            $rooms = $catalog;
            if (isset($rooms['global'])) {
                unset($rooms['global']);
            }

            foreach ($rooms as $roomKey => $roomCfg) {
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

                        $this->setSelectedRoomKey((string)$roomKey);
                        $this->setSelectedDomainKey((string)$domainKey);
                        $this->setSelectedGroupKey((string)$groupKey);
                        $this->setRuntimeEntries($entries);
                        return true;
                    }
                }
            }
        }

        $this->resetRuntimeEntries();
        return false;
    }

    private function shouldInitializeContextFromCatalog(): bool
    {
        $hasScript = $this->getActiveRoomsCatalogScriptID() > 0
            || $this->getActiveRoomsCatalogEditScriptID() > 0;

        if (!$hasScript) {
            return false;
        }

        if (!$this->hasCompleteContextSelection()) {
            return true;
        }

        $entries = $this->getRuntimeEntries();

        if ($entries === []) {
            return true;
        }

        return false;
    }

    private function hasCompleteContextSelection(): bool
    {
        return $this->getSelectedRoomKey() !== ''
            && $this->getSelectedDomainKey() !== ''
            && $this->getSelectedGroupKey() !== '';
    }

    private function ensureContextSelection(): bool
    {
        if ($this->hasCompleteContextSelection()) {
            return true;
        }

        if (!$this->autoPopulateContextFromCatalog()) {
            return false;
        }

        $this->ReloadForm();

        return true;
    }

    private function populateEntriesForCurrentSelection(): bool
    {
        $roomKey = $this->getSelectedRoomKey();
        $domain  = $this->getSelectedDomainKey();
        $group   = $this->getSelectedGroupKey();

        if ($roomKey === '' || $domain === '' || $group === '') {
            return false;
        }

        $catalog  = $this->loadRoomsCatalogEdit();
        $groupCfg = $catalog[$roomKey]['domains'][$domain][$group] ?? null;

        if (!is_array($groupCfg)) {
            $catalog  = $this->loadRoomsCatalog();
            $groupCfg = $catalog[$roomKey]['domains'][$domain][$group] ?? null;
            if (!is_array($groupCfg)) {
                return false;
            }
        }

        $entries = $this->buildEntriesFromCatalog($groupCfg, $roomKey, $domain, $group);
        $this->setRuntimeEntries($entries);

        return true;
    }

    private function loadRoomsCatalogEdit(): array
    {
        $scriptId = $this->getActiveRoomsCatalogEditScriptID();
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
        $scriptId = $this->getActiveRoomsCatalogEditScriptID();
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
                'room'       => (string)$roomKey,
                'domain'     => (string)$domain,
                'group'      => (string)$group,
                'key'        => (string)$entryKey,
                'label'      => (string)($cfg['title'] ?? ($cfg['label'] ?? ($cfg['name'] ?? ''))),
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

    private function buildEntriesGlobalFromCatalog(array $catalog): array
    {
        $rows = [];

        // Root-Schema: Räume direkt am Root, 'global' ignorieren
        foreach ($catalog as $roomKey => $roomCfg) {
            if ($roomKey === 'global' || !is_array($roomCfg)) {
                continue;
            }

            $domains = $roomCfg['domains'] ?? [];
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

                    foreach ($groupCfg as $entryKey => $cfg) {
                        if (!is_array($cfg)) {
                            $cfg = [];
                        }

                        $rows[] = [
                            'selected'   => false,
                            'room'       => (string)$roomKey,
                            'domain'     => (string)$domainKey,
                            'group'      => (string)$groupKey,
                            'key'        => (string)$entryKey,
                            'label'      => (string)($cfg['title'] ?? ($cfg['label'] ?? ($cfg['name'] ?? ''))),
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
                }
            }
        }

        $this->SendDebug('RoomsCatalogConfigurator', 'buildEntriesGlobalFromCatalog count=' . count($rows), 0);

        return $rows;
    }

    private function normalizeCfg(array $cfg): array
    {
        // Nur die relevanten Keys sortiert vergleichen
        $keys = [
            'title', 'entityId', 'entityName',
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

    private function synchronizeRuntimeStateFromProperties(): void
    {
        $this->syncAttributeInteger('RuntimeRoomsCatalogScriptID', $this->ReadPropertyInteger('RoomsCatalogScriptID'));
        $this->syncAttributeInteger('RuntimeRoomsCatalogEditScriptID', $this->ReadPropertyInteger('RoomsCatalogEditScriptID'));
        $this->syncAttributeString('RuntimeSelectedRoom', $this->ReadPropertyString('SelectedRoom'));
        $this->syncAttributeString('RuntimeSelectedDomain', $this->ReadPropertyString('SelectedDomain'));
        $this->syncAttributeString('RuntimeSelectedGroup', $this->ReadPropertyString('SelectedGroup'));

        $entriesProp = $this->ReadPropertyString('Entries');
        if ($entriesProp === '' || $entriesProp === null) {
            $entriesProp = '[]';
        }
        $this->syncAttributeString('RuntimeEntries', $entriesProp);
    }

    private function syncAttributeInteger(string $attribute, int $value): void
    {
        if ($this->ReadAttributeInteger($attribute) !== $value) {
            $this->WriteAttributeInteger($attribute, $value);
        }
    }

    private function syncAttributeString(string $attribute, string $value): void
    {
        if ($this->ReadAttributeString($attribute) !== $value) {
            $this->WriteAttributeString($attribute, $value);
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

    private function getSelectedRoomKey(): string
    {
        $room = $this->ReadAttributeString('RuntimeSelectedRoom');
        if ($room !== '') {
            return $room;
        }
        $room = $this->ReadPropertyString('SelectedRoom');
        $this->WriteAttributeString('RuntimeSelectedRoom', $room);
        return $room;
    }

    private function getSelectedDomainKey(): string
    {
        $domain = $this->ReadAttributeString('RuntimeSelectedDomain');
        if ($domain !== '') {
            return $domain;
        }
        $domain = $this->ReadPropertyString('SelectedDomain');
        $this->WriteAttributeString('RuntimeSelectedDomain', $domain);
        return $domain;
    }

    private function getSelectedGroupKey(): string
    {
        $group = $this->ReadAttributeString('RuntimeSelectedGroup');
        if ($group !== '') {
            return $group;
        }
        $group = $this->ReadPropertyString('SelectedGroup');
        $this->WriteAttributeString('RuntimeSelectedGroup', $group);
        return $group;
    }

    private function setSelectedRoomKey(string $room): void
    {
        $this->WriteAttributeString('RuntimeSelectedRoom', $room);
    }

    private function setSelectedDomainKey(string $domain): void
    {
        $this->WriteAttributeString('RuntimeSelectedDomain', $domain);
    }

    private function setSelectedGroupKey(string $group): void
    {
        $this->WriteAttributeString('RuntimeSelectedGroup', $group);
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

    private function resetRuntimeEntries(): void
    {
        $this->WriteAttributeString('RuntimeEntries', '[]');
    }
}
