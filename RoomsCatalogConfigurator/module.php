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
 * 2025-11-17: Globale Liste aller Räume/Domains
 * - Kein Kontext (SelectedRoom/Domain/Group) mehr
 * - Eine flache Liste mit allen Räumen + Domains + Gruppen + Einträgen
 * - Speichern schreibt den kompletten domains-Baum aller Räume zurück
 * - Logging vollständig über Kanal "Alexa" (RCC-DEBUG)
 */

declare(strict_types=1);

class RoomsCatalogConfigurator extends IPSModule
{
    // =====================================================================
    // Logging
    // =====================================================================

    private function log(string $msg): void
    {
        IPS_LogMessage('Alexa', 'RCC-DEBUG: ' . $msg);
    }

    // =====================================================================
    // IPS-Standard
    // =====================================================================

    public function Create()
    {
        parent::Create();

        // Scripte
        $this->RegisterPropertyInteger('RoomsCatalogScriptID', 0);
        $this->RegisterPropertyInteger('RoomsCatalogEditScriptID', 0);

        // Alte Property weiterführen (wird intern nicht mehr verwendet, schadet aber nicht)
        $this->RegisterPropertyString('Entries', '[]');

        // Runtime-State
        $this->RegisterAttributeInteger('RuntimeRoomsCatalogScriptID', 0);
        $this->RegisterAttributeInteger('RuntimeRoomsCatalogEditScriptID', 0);
        $this->RegisterAttributeString('RuntimeEntries', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->log('==============================');
        $this->log('ApplyChanges START');

        $this->synchronizeRuntimeStateFromProperties();

        // Wenn noch keine Runtime-Einträge vorhanden sind → aus Edit (Fallback Prod) laden und flach machen
        $entries = $this->getRuntimeEntries();
        if ($entries === []) {
            $this->log('ApplyChanges: RuntimeEntries leer → lade aus RoomsCatalogEdit/Prod');
            $catalog = $this->loadRoomsCatalogEdit(); // macht selbst Fallback auf Prod
            $flat    = $this->buildFlatEntriesFromCatalog($catalog);
            $this->setRuntimeEntries($flat);
        } else {
            $this->log('ApplyChanges: RuntimeEntries vorhanden, Anzahl=' . count($entries));
        }

        $this->log('ApplyChanges ENDE');
        $this->log('==============================');
    }

    public function RequestAction($Ident, $Value)
    {
        $this->log('RequestAction: ' . $Ident . ' = ' . json_encode($Value));

        switch ($Ident) {
            case 'RoomsCatalogScriptID':
                $this->setActiveRoomsCatalogScriptID((int)$Value);
                // bei Scriptwechsel Liste neu aus EDIT/PROD laden
                $catalog = $this->loadRoomsCatalogEdit();
                $flat    = $this->buildFlatEntriesFromCatalog($catalog);
                $this->setRuntimeEntries($flat);
                $this->ReloadForm();
                break;

            case 'RoomsCatalogEditScriptID':
                $this->setActiveRoomsCatalogEditScriptID((int)$Value);
                // bei Scriptwechsel Liste neu aus EDIT/PROD laden
                $catalog = $this->loadRoomsCatalogEdit();
                $flat    = $this->buildFlatEntriesFromCatalog($catalog);
                $this->setRuntimeEntries($flat);
                $this->ReloadForm();
                break;

            default:
                throw new Exception('Invalid Ident');
        }
    }

    public function GetConfigurationForm()
    {
        $this->log('START RoomsCatalog-Diagnose');

        $prodCatalog = $this->loadRoomsCatalog();
        $editCatalog = $this->loadRoomsCatalogEdit();

        $prodRooms = $this->extractRooms($prodCatalog);
        $editRooms = $this->extractRooms($editCatalog);

        $this->log(sprintf(
            'RoomsCatalog PROD: Räume=%d',
            count($prodRooms)
        ));
        $this->log(sprintf(
            'RoomsCatalog EDIT: Räume=%d',
            count($editRooms)
        ));

        $entries = $this->getRuntimeEntries();
        $this->log('GetConfigurationForm: RuntimeEntries=' . count($entries));

        // Row-Farben anhand der Domain pro Zeile
        $entries = $this->applyRowColors($entries);

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
                            'value'   => $this->getActiveRoomsCatalogScriptID(),
                            'onChange' => 'IPS_RequestAction($id, "RoomsCatalogScriptID", $RoomsCatalogScriptID);'
                        ],
                        [
                            'type'    => 'SelectScript',
                            'name'    => 'RoomsCatalogEditScriptID',
                            'caption' => 'RoomsCatalog Edit-Script',
                            'value'   => $this->getActiveRoomsCatalogEditScriptID(),
                            'onChange' => 'IPS_RequestAction($id, "RoomsCatalogEditScriptID", $RoomsCatalogEditScriptID);'
                        ]
                    ]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Alle Einträge (alle Räume / Domains / Gruppen)',
                    'items'   => [
                        [
                            'type'       => 'List',
                            'name'       => 'Entries',
                            'caption'    => 'Einträge',
                            'rowCount'   => 20,
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
                                    'caption' => 'Raum-Key',
                                    'name'    => 'roomKey',
                                    'width'   => '120px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'Raum-Label',
                                    'name'    => 'roomLabel',
                                    'width'   => '160px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'Domain',
                                    'name'    => 'domain',
                                    'width'   => '110px',
                                    'add'     => '',
                                    'edit'    => [
                                        'type' => 'ValidationTextBox'
                                    ]
                                ],
                                [
                                    'caption' => 'Gruppe',
                                    'name'    => 'group',
                                    'width'   => '140px',
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
                    'caption' => 'Katalog-Aktionen (gesamt)',
                    'items'   => [
                        [
                            'type'    => 'Button',
                            'caption' => 'RoomsCatalogEdit aus produktivem RoomsCatalog füllen (komplett)',
                            'onClick' => 'RCC_CopyProdToEdit($id);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Liste aus RoomsCatalogEdit neu laden',
                            'onClick' => 'RCC_ReloadFromEdit($id);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Einträge in RoomsCatalogEdit speichern (komplett)',
                            'onClick' => 'RCC_SaveAll($id, json_encode($Entries));'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Diff: produktiv vs. Edit (Textausgabe, gesamt)',
                            'onClick' => 'RCC_ShowDiff($id);'
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'RoomsCatalogEdit → produktiver RoomsCatalog kopieren (gesamtes Script)',
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
    // Public API – Buttons
    // =====================================================================

    public function ReloadFromEdit()
    {
        $this->log('RCC_ReloadFromEdit()');
        $catalog = $this->loadRoomsCatalogEdit();
        $flat    = $this->buildFlatEntriesFromCatalog($catalog);
        $this->setRuntimeEntries($flat);
        $this->ReloadForm();
        echo 'Liste wurde aus RoomsCatalogEdit neu geladen.';
    }

    public function CopyProdToEdit()
    {
        $this->log('RCC_CopyProdToEdit()');

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

        $prodContent = IPS_GetScriptContent($prodId);
        if ($prodContent === '') {
            echo 'Produktiver RoomsCatalog ist leer. Abbruch.';
            return;
        }

        IPS_SetScriptContent($editId, $prodContent);
        $this->log('CopyProdToEdit: PROD → EDIT kopiert');

        // Liste neu aus EDIT laden
        $catalog = $this->loadRoomsCatalogEdit();
        $flat    = $this->buildFlatEntriesFromCatalog($catalog);
        $this->setRuntimeEntries($flat);
        $this->ReloadForm();

        echo 'Produktiver RoomsCatalog wurde nach RoomsCatalogEdit kopiert und geladen.';
    }

    public function SaveAll(string $entriesJson)
    {
        $this->log('RCC_SaveAll() len=' . strlen($entriesJson));

        $entries = json_decode($entriesJson, true);
        if (!is_array($entries)) {
            $entries = [];
        }

        // Neue domains-Struktur pro Raum aufbauen
        $domainsByRoom = [];

        foreach ($entries as $row) {
            $roomKey   = trim((string)($row['roomKey'] ?? ''));
            $roomLabel = trim((string)($row['roomLabel'] ?? ''));
            $domain    = trim((string)($row['domain'] ?? ''));
            $group     = trim((string)($row['group'] ?? ''));
            $key       = trim((string)($row['key'] ?? ''));

            if ($roomKey === '' || $domain === '' || $group === '' || $key === '') {
                // unvollständige Zeilen ignorieren
                continue;
            }

            if (!isset($domainsByRoom[$roomKey])) {
                $domainsByRoom[$roomKey] = [
                    '_display' => $roomLabel !== '' ? $roomLabel : $roomKey,
                    'domains'  => []
                ];
            }

            if (!isset($domainsByRoom[$roomKey]['domains'][$domain])) {
                $domainsByRoom[$roomKey]['domains'][$domain] = [];
            }
            if (!isset($domainsByRoom[$roomKey]['domains'][$domain][$group])) {
                $domainsByRoom[$roomKey]['domains'][$domain][$group] = [];
            }

            $cfg = [
                'label'      => (string)($row['label'] ?? ''),
                'entityId'   => (int)($row['entityId'] ?? 0),
                'entityName' => (string)($row['entityName'] ?? ''),
                'controlId'  => (int)($row['controlId'] ?? 0),
                'statusId'   => (int)($row['statusId'] ?? 0),
                'tiltId'     => (int)($row['tiltId'] ?? 0),
                'speechKey'  => (string)($row['speechKey'] ?? ''),
                'icon'       => (string)($row['icon'] ?? ''),
                'order'      => (int)($row['order'] ?? 0)
            ];

            $domainsByRoom[$roomKey]['domains'][$domain][$group][$key] = $cfg;
        }

        $this->log('RCC_SaveAll: Räume in neuer Struktur=' . count($domainsByRoom));

        // Bestehenden Edit-Katalog holen
        $catalog = $this->loadRoomsCatalogEdit();
        $rooms   = $this->extractRooms($catalog);

        // Erst alle bestehenden domains leeren
        foreach (array_keys($rooms) as $roomKey) {
            if (isset($catalog[$roomKey]['domains'])) {
                $catalog[$roomKey]['domains'] = [];
            }
        }

        // Neue domains einsetzen / Räume ggf. neu anlegen
        foreach ($domainsByRoom as $roomKey => $roomData) {
            $display = $roomData['_display'];
            $domains = $roomData['domains'];

            if (!isset($catalog[$roomKey])) {
                $catalog[$roomKey] = [
                    'display' => $display,
                    'domains' => []
                ];
            }

            if (!isset($catalog[$roomKey]['display']) || $catalog[$roomKey]['display'] === '') {
                $catalog[$roomKey]['display'] = $display;
            }

            $catalog[$roomKey]['domains'] = $domains;
        }

        // Katalog zurückschreiben
        $this->writeRoomsCatalogEdit($catalog);

        // Liste aus gespeichertem Katalog neu bauen
        $flat = $this->buildFlatEntriesFromCatalog($catalog);
        $this->setRuntimeEntries($flat);
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

        $prod = $this->loadRoomsCatalog();
        $edit = $this->loadRoomsCatalogEdit();

        $prodRooms = $this->extractRooms($prod);
        $editRooms = $this->extractRooms($edit);

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

    private function applyRowColors(array $entries): array
    {
        foreach ($entries as &$row) {
            $key      = trim((string)($row['key'] ?? ''));
            $label    = trim((string)($row['label'] ?? ''));
            $entityId = (int)($row['entityId'] ?? 0);
            $control  = (int)($row['controlId'] ?? 0);
            $status   = (int)($row['statusId'] ?? 0);
            $tilt     = (int)($row['tiltId'] ?? 0);
            $domain   = trim((string)($row['domain'] ?? ''));

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

    private function extractRooms(array $catalog): array
    {
        $rooms = [];
        foreach ($catalog as $key => $cfg) {
            if (is_array($cfg) && isset($cfg['domains']) && is_array($cfg['domains'])) {
                $rooms[$key] = $cfg;
            }
        }
        return $rooms;
    }

    private function buildFlatEntriesFromCatalog(array $catalog): array
    {
        $rooms = $this->extractRooms($catalog);
        $rows  = [];

        foreach ($rooms as $roomKey => $roomCfg) {
            $roomLabel = $roomCfg['display'] ?? $roomKey;
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

                    foreach ($groupCfg as $entryKey => $cfg) {
                        if (!is_array($cfg)) {
                            $cfg = [];
                        }

                        $rows[] = [
                            'selected'   => false,
                            'roomKey'    => (string)$roomKey,
                            'roomLabel'  => (string)$roomLabel,
                            'domain'     => (string)$domainKey,
                            'group'      => (string)$groupKey,
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
                }
            }
        }

        $this->log('buildFlatEntriesFromCatalog(): rows=' . count($rows));

        return $rows;
    }

    private function loadRoomsCatalog(): array
    {
        $scriptId = $this->getActiveRoomsCatalogScriptID();
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $this->log('RoomsCatalog: ScriptID leer oder existiert nicht');
            return [];
        }

        $fileRel = IPS_GetScriptFile($scriptId);
        if ($fileRel === '') {
            $this->log('RoomsCatalog: ScriptFile leer für ID=' . $scriptId);
            return [];
        }

        $file = IPS_GetKernelDir() . 'scripts/' . $fileRel;
        $this->log('RoomsCatalog: ScriptFile=' . $file);

        if (!file_exists($file)) {
            $this->log('RoomsCatalog: ScriptFile nicht gefunden: ' . $file);
            return [];
        }

        $this->log('RoomsCatalog require() file=' . $fileRel);
        $catalog = @require $file;
        $this->log('RoomsCatalog require() type=' . gettype($catalog));

        if (!is_array($catalog)) {
            $this->log('RoomsCatalog: require() ergab kein Array');
            return [];
        }

        if (count($catalog) <= 100) {
            $this->log('RoomsCatalog Top-Level-Keys: ' . implode(', ', array_keys($catalog)));
        }

        return $catalog;
    }

    private function loadRoomsCatalogEdit(): array
    {
        $scriptId = $this->getActiveRoomsCatalogEditScriptID();
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $this->log('RoomsCatalogEdit: kein Script → Fallback PROD');
            return $this->loadRoomsCatalog();
        }

        $fileRel = IPS_GetScriptFile($scriptId);
        if ($fileRel === '') {
            $this->log('RoomsCatalogEdit: ScriptFile leer für ID=' . $scriptId . ' → Fallback PROD');
            return $this->loadRoomsCatalog();
        }

        $file = IPS_GetKernelDir() . 'scripts/' . $fileRel;
        $this->log('RoomsCatalogEdit: ScriptFile=' . $file);

        if (!file_exists($file)) {
            $this->log('RoomsCatalogEdit: ScriptFile nicht gefunden: ' . $file . ' → Fallback PROD');
            return $this->loadRoomsCatalog();
        }

        $this->log('RoomsCatalogEdit require() file=' . $fileRel);
        $catalog = @require $file;
        $this->log('RoomsCatalogEdit require() type=' . gettype($catalog));

        if (!is_array($catalog)) {
            $this->log('RoomsCatalogEdit: require() ergab kein Array → Fallback PROD');
            return $this->loadRoomsCatalog();
        }

        if (count($catalog) <= 100) {
            $this->log('RoomsCatalogEdit Top-Level-Keys: ' . implode(', ', array_keys($catalog)));
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
        $this->log('writeRoomsCatalogEdit(): ScriptID=' . $scriptId . ', length=' . strlen($php));
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

        // Property Entries nutzen wir aktuell nicht mehr aktiv; könnte später als Persistenz genutzt werden
        $entriesProp = $this->ReadPropertyString('Entries');
        if ($entriesProp !== '' && $entriesProp !== null) {
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
}
