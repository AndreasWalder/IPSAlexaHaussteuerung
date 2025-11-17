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
 * 2025-11-16: RoomsCatalog-Normalisierung + Logging
 * - Unterstützt Top-Level-Räume (buero, kueche, …, global) ODER ['rooms'=>[…]]
 * - Alle wichtigen Schritte loggen auf Kanal "Alexa" (RCC-DEBUG)
 */

declare(strict_types=1);

class RoomsCatalogConfigurator extends IPSModule
{
    private bool $autoInitializingContext = false;

    // =====================================================================
    // Logging-Helfer
    // =====================================================================

    private function log(string $msg): void
    {
        IPS_LogMessage('Alexa', 'RCC-DEBUG: ' . $msg);
    }

    // =====================================================================
    // Standard IPS-Methoden
    // =====================================================================

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

        // Laufzeit-Status
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

        $this->log('==============================');
        $this->log('ApplyChanges START');

        $this->synchronizeRuntimeStateFromProperties();

        $this->log(sprintf(
            'Instanz-Konfiguration: {"Entries":"%s","RoomsCatalogEditScriptID":%d,"RoomsCatalogScriptID":%d,"SelectedDomain":"%s","SelectedGroup":"%s","SelectedRoom":"%s"}',
            $this->ReadPropertyString('Entries'),
            $this->ReadPropertyInteger('RoomsCatalogEditScriptID'),
            $this->ReadPropertyInteger('RoomsCatalogScriptID'),
            $this->ReadPropertyString('SelectedDomain'),
            $this->ReadPropertyString('SelectedGroup'),
            $this->ReadPropertyString('SelectedRoom')
        ));

        if ($this->autoInitializingContext) {
            $this->log('ApplyChanges: autoInitializingContext=true → Abbruch');
            return;
        }

        if ($this->shouldInitializeContextFromCatalog()) {
            $this->log('ApplyChanges: shouldInitializeContextFromCatalog() = true → autoPopulateContextFromCatalog()');
            $this->autoInitializingContext = true;
            $initialized                   = $this->autoPopulateContextFromCatalog();
            $this->autoInitializingContext = false;

            $this->log('ApplyChanges: autoPopulateContextFromCatalog() result=' . ($initialized ? 'true' : 'false'));

            if ($initialized) {
                $this->ReloadForm();
            }
        } else {
            $this->log('ApplyChanges: Kein Initialisierungsbedarf.');
        }

        $this->log('ApplyChanges ENDE');
        $this->log('==============================');
    }

    public function RequestAction($Ident, $Value)
    {
        $this->log('RequestAction: ' . $Ident . ' = ' . json_encode($Value));

        switch ($Ident) {
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
        $this->log('START RoomsCatalog-Diagnose');

        $roomsCatalog = $this->loadRoomsCatalog();
        $rooms        = $roomsCatalog['rooms'] ?? [];

        $this->log('RoomsCatalog Top-Level-Keys: ' . implode(', ', array_keys($roomsCatalog)));
        $this->log(sprintf(
            'RoomsCatalog: Räume=%d Domains=%d',
            count($rooms),
            array_sum(array_map(static function ($r) {
                return isset($r['domains']) && is_array($r['domains']) ? count($r['domains']) : 0;
            }, $rooms))
        ));

        $selectedRoom   = $this->getSelectedRoomKey();
        $selectedDomain = $this->getSelectedDomainKey();
        $selectedGroup  = $this->getSelectedGroupKey();

        $roomOptions   = $this->buildRoomOptions($rooms);
        $domainOptions = $this->buildDomainOptions($rooms, $selectedRoom);
        $groupOptions  = $this->buildGroupOptions($rooms, $selectedRoom, $selectedDomain);

        $entries = $this->getRuntimeEntries();

        $this->log(sprintf(
            'RoomsCatalogEdit Top-Level-Keys: %s',
            implode(', ', array_keys($this->loadRoomsCatalogEdit()))
        ));
        $this->log(sprintf(
            'Aktueller Kontext: room="%s", domain="%s", group="%s", entries=%d',
            $selectedRoom,
            $selectedDomain,
            $selectedGroup,
            count($entries)
        ));

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
                            'value'   => $this->getActiveRoomsCatalogScriptID()
                        ],
                        [
                            'type'    => 'SelectScript',
                            'name'    => 'RoomsCatalogEditScriptID',
                            'caption' => 'RoomsCatalog Edit-Script',
                            'value'   => $this->getActiveRoomsCatalogEditScriptID()
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

    // =====================================================================
    // Public API (Form-Buttons)
    // =====================================================================

    public function LoadContext()
    {
        $this->log('RCC_LoadContext()');

        if (!$this->ensureContextSelection()) {
            echo 'Bitte zuerst Raum, Domain und Untergruppe auswählen (RoomsCatalog Script wählen?).';
            return;
        }

        $roomsCatalog = $this->loadRoomsCatalogEdit();

        $roomKey = $this->getSelectedRoomKey();
        $domain  = $this->getSelectedDomainKey();
        $group   = $this->getSelectedGroupKey();

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

        $this->setRuntimeEntries($entries);
        $this->ReloadForm();

        echo 'Kontext geladen. Einträge können jetzt bearbeitet werden.';
    }

    public function LoadContextFromProductive()
    {
        $this->log('RCC_LoadContextFromProductive()');

        if (!$this->ensureContextSelection()) {
            echo 'Bitte zuerst Raum, Domain und Untergruppe auswählen (RoomsCatalog Script wählen?).';
            return;
        }

        $roomsCatalog = $this->loadRoomsCatalog();

        $roomKey = $this->getSelectedRoomKey();
        $domain  = $this->getSelectedDomainKey();
        $group   = $this->getSelectedGroupKey();

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

        $this->setRuntimeEntries($entries);
        $this->ReloadForm();

        echo 'Kontext aus produktivem RoomsCatalog geladen. Änderungen werden nicht automatisch gespeichert.';
    }

    public function CreateContextFromProductive()
    {
        $this->log('RCC_CreateContextFromProductive()');

        if (!$this->ensureContextSelection()) {
            echo 'Bitte zuerst Raum, Domain und Untergruppe auswählen (RoomsCatalog Script wählen?).';
            return;
        }

        $productiveCatalog = $this->loadRoomsCatalog();
        $editCatalog       = $this->loadRoomsCatalogEdit();

        $roomKey = $this->getSelectedRoomKey();
        $domain  = $this->getSelectedDomainKey();
        $group   = $this->getSelectedGroupKey();

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

        $this->setRuntimeEntries($entries);
        $this->ReloadForm();

        echo 'Kontext wurde aus dem produktiven RoomsCatalog in das Edit-Script übernommen.';
    }

    public function SaveContext(string $entriesJson)
    {
        $this->log('RCC_SaveContext() entriesJson length=' . strlen($entriesJson));

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

        $this->setRuntimeEntries($this->buildEntriesFromCatalog(
            $newGroup,
            $roomKey,
            $domain,
            $group
        ));
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
        $this->log('RCC_ApplyEditToProductive()');

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
        $this->log('RCC_ShowDiff()');

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

    // =====================================================================
    // Interne Helfer
    // =====================================================================

    private function applySelectedObjectToEntries(int $objectId, string $entriesJson, string $mode): void
    {
        $this->log(sprintf(
            'applySelectedObjectToEntries(mode=%s, objectId=%d, len=%d)',
            $mode,
            $objectId,
            strlen($entriesJson)
        ));

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
                if (isset($row['rowColor'])) {
                    unset($row['rowColor']);
                }
            }
        }
        unset($row);

        return $entries;
    }

    private function normalizeRoomsCatalogArray(array $catalog): array
    {
        // Falls es schon ein ['rooms'=>…] ist → direkt verwenden
        if (isset($catalog['rooms']) && is_array($catalog['rooms'])) {
            return $catalog;
        }

        // Ansonsten nehmen wir den gesamten Array als rooms
        // (deine Struktur: buero, kueche, …, global)
        $this->log('normalizeRoomsCatalogArray(): Top-Level ohne "rooms" → wrap in rooms[]');

        return [
            'rooms' => $catalog
        ];
    }

    private function loadRoomsCatalog(): array
    {
        $scriptId = $this->getActiveRoomsCatalogScriptID();
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $this->log('RoomsCatalog: ScriptID leer oder existiert nicht');
            return ['rooms' => []];
        }

        $file = IPS_GetScriptFile($scriptId);
        if ($file === '' || !file_exists($file)) {
            $this->log('RoomsCatalog: ScriptFile nicht gefunden: ' . $file);
            return ['rooms' => []];
        }

        $this->log('RoomsCatalog require() file=' . $file);
        $catalog = @require $file;
        $this->log('RoomsCatalog require() type=' . gettype($catalog));

        if (!is_array($catalog)) {
            $this->log('RoomsCatalog: require() ergab kein Array');
            return ['rooms' => []];
        }

        if (count($catalog) <= 50) {
            $this->log('RoomsCatalog Top-Level-Keys: ' . implode(', ', array_keys($catalog)));
        }

        $catalog = $this->normalizeRoomsCatalogArray($catalog);

        $rooms = $catalog['rooms'] ?? [];
        $this->log(sprintf(
            'RoomsCatalog: Räume=%d Domains=%d Licht-Einträge=%d Jalousie-Einträge=%d',
            count($rooms),
            array_sum(array_map(static function ($r) {
                return isset($r['domains']) && is_array($r['domains']) ? count($r['domains']) : 0;
            }, $rooms)),
            array_sum(array_map(static function ($r) {
                $d = $r['domains']['licht'] ?? [];
                if (!is_array($d)) {
                    return 0;
                }
                $sum = 0;
                foreach ($d as $g) {
                    if (is_array($g)) {
                        $sum += count($g);
                    }
                }
                return $sum;
            }, $rooms)),
            array_sum(array_map(static function ($r) {
                $d = $r['domains']['jalousie'] ?? [];
                if (!is_array($d)) {
                    return 0;
                }
                return count($d);
            }, $rooms))
        ));

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
        $this->log('autoPopulateContextFromCatalog() START');

        if ($this->populateEntriesForCurrentSelection()) {
            $this->log('autoPopulateContextFromCatalog(): aktueller Kontext war gültig');
            return true;
        }

        $catalogsToInspect = [
            $this->loadRoomsCatalogEdit(),
            $this->loadRoomsCatalog()
        ];

        foreach ($catalogsToInspect as $idx => $catalog) {
            $src = $idx === 0 ? 'EDIT' : 'PROD';

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

                        $this->setSelectedRoomKey((string)$roomKey);
                        $this->setSelectedDomainKey((string)$domainKey);
                        $this->setSelectedGroupKey((string)$groupKey);
                        $this->setRuntimeEntries($entries);

                        $this->log(sprintf(
                            'autoPopulateContextFromCatalog(): erster Kontext gefunden (%s): room="%s", domain="%s", group="%s", entries=%d',
                            $src,
                            $roomKey,
                            $domainKey,
                            $groupKey,
                            count($entries)
                        ));

                        return true;
                    }
                }
            }
        }

        $this->log('autoPopulateContextFromCatalog(): kein Kontext gefunden');
        $this->resetRuntimeEntries();
        return false;
    }

    private function shouldInitializeContextFromCatalog(): bool
    {
        $hasScript = $this->getActiveRoomsCatalogScriptID() > 0
            || $this->getActiveRoomsCatalogEditScriptID() > 0;

        if (!$hasScript) {
            $this->log('shouldInitializeContextFromCatalog(): kein Script gesetzt');
            return false;
        }

        if (!$this->hasCompleteContextSelection()) {
            $this->log('shouldInitializeContextFromCatalog(): Kontext unvollständig → true');
            return true;
        }

        $entries = $this->getRuntimeEntries();

        if ($entries === []) {
            $this->log('shouldInitializeContextFromCatalog(): Kontext leer (entries==[]) → true');
            return true;
        }

        $this->log('shouldInitializeContextFromCatalog(): Kontext vorhanden → false');
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

        $this->log(sprintf(
            'populateEntriesForCurrentSelection(): room="%s", domain="%s", group="%s"',
            $roomKey,
            $domain,
            $group
        ));

        if ($roomKey === '' || $domain === '' || $group === '') {
            $this->log('populateEntriesForCurrentSelection(): Kontext unvollständig → false');
            return false;
        }

        $catalog  = $this->loadRoomsCatalogEdit();
        $groupCfg = $catalog['rooms'][$roomKey]['domains'][$domain][$group] ?? null;

        if (!is_array($groupCfg)) {
            $this->log('populateEntriesForCurrentSelection(): im EDIT nicht gefunden, versuche PROD');
            $catalog  = $this->loadRoomsCatalog();
            $groupCfg = $catalog['rooms'][$roomKey]['domains'][$domain][$group] ?? null;
            if (!is_array($groupCfg)) {
                $this->log('populateEntriesForCurrentSelection(): auch in PROD nicht gefunden → false');
                return false;
            }
        }

        $entries = $this->buildEntriesFromCatalog($groupCfg, $roomKey, $domain, $group);
        $this->setRuntimeEntries($entries);

        $this->log('populateEntriesForCurrentSelection(): entries=' . count($entries));

        return true;
    }

    private function loadRoomsCatalogEdit(): array
    {
        $scriptId = $this->getActiveRoomsCatalogEditScriptID();
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $this->log('RoomsCatalogEdit: kein Script → Fallback PROD');
            return $this->loadRoomsCatalog();
        }

        $file = IPS_GetScriptFile($scriptId);
        if ($file === '' || !file_exists($file)) {
            $this->log('RoomsCatalogEdit: ScriptFile nicht gefunden: ' . $file . ' → Fallback PROD');
            return $this->loadRoomsCatalog();
        }

        $this->log('RoomsCatalogEdit require() file=' . $file);
        $catalog = @require $file;
        $this->log('RoomsCatalogEdit require() type=' . gettype($catalog));

        if (!is_array($catalog)) {
            $this->log('RoomsCatalogEdit: require() ergab kein Array → Fallback PROD');
            return $this->loadRoomsCatalog();
        }

        if (count($catalog) <= 50) {
            $this->log('RoomsCatalogEdit Top-Level-Keys: ' . implode(', ', array_keys($catalog)));
        }

        $catalog = $this->normalizeRoomsCatalogArray($catalog);

        $rooms = $catalog['rooms'] ?? [];
        $this->log(sprintf(
            'RoomsCatalogEdit: Räume=%d Domains=%d',
            count($rooms),
            array_sum(array_map(static function ($r) {
                return isset($r['domains']) && is_array($r['domains']) ? count($r['domains']) : 0;
            }, $rooms))
        ));

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
        $this->log('writeRoomsCatalogEdit(): ScriptID=' . $scriptId . ', length=' . strlen($php));
    }

    private function buildRoomOptions(array $rooms): array
    {
        $options   = [];
        $options[] = [
            'caption' => '– bitte wählen –',
            'value'   => ''
        ];

        foreach ($rooms as $key => $room) {
            $caption = $room['display'] ?? (string)$key;
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
