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
 *   (Struktur: [roomKey].display/domains[domain][group][key] = cfg)
 */

class RoomsCatalogConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('RoomsCatalogScriptID', 0);
        $this->RegisterPropertyInteger('RoomsCatalogEditScriptID', 0);

        $this->RegisterAttributeString('RuntimeEntries', '[]');
        $this->RegisterAttributeString('FilterRoom', '');
        $this->RegisterAttributeString('FilterDomain', '');
        $this->RegisterAttributeString('FilterGroup', '');
        $this->RegisterAttributeString('FilterAI', 'all');
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
        $this->RefreshVisibleLists();
    }

    public function SetRoomFilter(string $roomKey)
    {
        $this->WriteAttributeString('FilterRoom', $roomKey);
        $this->logDebug('SetRoomFilter: roomKey="' . $roomKey . '"');
        $this->RefreshVisibleLists();
    }

    public function SetDomainFilter(string $domainKey)
    {
        $this->WriteAttributeString('FilterDomain', $domainKey);
               $this->logDebug('SetDomainFilter: domainKey="' . $domainKey . '"');
        $this->RefreshVisibleLists();
    }

    public function SetGroupFilter(string $groupKey)
    {
        $this->WriteAttributeString('FilterGroup', $groupKey);
        $this->logDebug('SetGroupFilter: groupKey="' . $groupKey . '"');
        $this->RefreshVisibleLists();
    }


    public function SetAIFilter(string $filter): void
    {
        if (!in_array($filter, ['all', 'enabled', 'writable', 'disabled'], true)) $filter = 'all';
        $this->WriteAttributeString('FilterAI', $filter);
        $this->RefreshVisibleLists();
    }

    /**
     * Auswahl ausschließlich in RuntimeEntries halten, niemals in PROD speichern.
     * Beim Markieren werden alle nicht sichtbaren Zeilen abgewählt, damit
     * Sammelaktionen nicht versehentlich auf versteckte Geräte wirken.
     */
    public function SetVisibleSelection(bool $selected): void
    {
        $rows = $this->getRuntimeEntries();
        $visibleKeys = [];
        if ($selected) {
            $visible = $this->filterByAI(
                $this->applyFilters(
                    $rows,
                    $this->ReadAttributeString('FilterRoom'),
                    $this->ReadAttributeString('FilterDomain'),
                    $this->ReadAttributeString('FilterGroup')
                ),
                $this->ReadAttributeString('FilterAI')
            );
            foreach ($visible as $row) {
                $key = $this->buildCompositeKey($row);
                if ($key !== null) $visibleKeys[$key] = true;
            }
        }
        foreach ($rows as &$row) {
            if (!is_array($row)) continue;
            $key = $this->buildCompositeKey($row);
            $row['selected'] = $selected && $key !== null && isset($visibleKeys[$key]);
        }
        unset($row);
        $this->WriteAttributeString('RuntimeEntries', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->RefreshVisibleLists();
    }

    /**
     * Generiert den IP-Symcon-List-Callback für die gerade bearbeitete Zeile.
     *
     * Symcon stellt $Entries als IPSList (ArrayAccess, Iterator) bereit.
     * $Entries["roomKey"] etc. bezeichnet die bearbeitete Zeile.
     * array_filter($Entries, ...) verursacht dagegen einen TypeError.
     * Eine explizit aufgebaute PHP-Array-Literal-Zeile bewahrt alle
     * dynamisch konfigurierten Felder inklusive false/0/Leerstrings.
     */
    private function buildListRowSaveCallback(array $entries): string
    {
        $keys = [
            'selected', 'roomKey', 'roomLabel', 'floor', 'domain', 'group',
            'key', 'title', 'synonyms', 'entityId', 'kiEnabled', 'kiReadOnly',
            'state', 'toggle'
        ];
        foreach ($entries as $row) {
            if (!is_array($row)) continue;
            foreach (array_keys($row) as $name) {
                if (!is_string($name) || in_array($name, ['rowColor','kiStatus','diffKeys','editable','deletable'], true)) continue;
                if (preg_match('/^[A-Za-z_][A-Za-z_0-9]*$/D', $name) !== 1) continue;
                if (!in_array($name, $keys, true)) $keys[] = $name;
            }
        }
        $parts = [];
        foreach ($keys as $key) {
            $quoted = var_export($key, true);
            $parts[] = $quoted . ' => ($Entries[' . $quoted . '] ?? null)';
        }
        // Ein echtes Array wird gefiltert, NICHT die IPSList.
        // null weglassen; false und 0 (z.B. Häkchen aus) behalten.
        return 'RCC_SaveToEdit($id, json_encode(array_filter(['
            . implode(', ', $parts)
            . '], static fn($v) => $v !== null), JSON_INVALID_UTF8_SUBSTITUTE));';
    }

    /** Markierte Zeilen erhalten die KI-Freigaben ausschließlich im EDIT-Katalog. */
    public function SetSelectedAI(bool $enabled, bool $readOnly): void
    {
        $rows = $this->getRuntimeEntries();
        $changed = 0;
        foreach ($rows as &$row) {
            if (!is_array($row) || empty($row['selected']) || !$this->isSupportedAIEntry($row)) continue;
            $row['kiEnabled'] = $enabled;
            $row['kiReadOnly'] = $readOnly;
            $row['selected'] = false;
            $changed++;
        }
        unset($row);
        if (!$changed) {
            echo 'Keine geeigneten Eintraege markiert.';
            return;
        }
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($editId <= 0 || !IPS_ScriptExists($editId)) {
            echo 'RoomsCatalogEdit ist nicht konfiguriert.';
            return;
        }
        $original = $this->loadRoomsCatalog($editId, 'EDIT');
        $updated = $this->rebuildRoomsCatalogFromEntries($rows, $original);
        IPS_SetScriptContent($editId, "<?php\nreturn " . var_export($updated, true) . ";\n");
        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
        echo $changed . ' KI-Freigaben im EDIT-Katalog gespeichert (noch nicht produktiv).';
    }

    public function PreviewChanges(): void
    {
        $prod = $this->getProdEntries();
        $edit = $this->getRuntimeEntries();
        $diff = $this->buildDiffEntries($prod, $edit);
        $new = $changed = $removed = 0;
        foreach ($diff as $entry) {
            $kind = (string)($entry['diffKeys'] ?? '');
            if ($kind === 'new') $new++;
            elseif ($kind === 'removed') $removed++;
            else $changed++;
        }
        echo sprintf('PROD: %d, EDIT: %d. Unterschiede: %d (neu %d, geaendert %d, geloescht %d).',
            count($prod),count($edit),count($diff),$new,$changed,$removed);
    }

    public function ValidateCatalog(): void
    {
        $errors = $this->validateEntries($this->getRuntimeEntries());
        echo $errors === []
            ? 'Katalogpruefung erfolgreich.'
            : count($errors) . " Problem(e):\n" . implode("\n", array_slice($errors,0,35));
    }

    private function validateEntries(array $rows, bool $checkReadIds = true): array
    {
        $errors = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = $this->buildCompositeKey($row);
            if ($key === null) {
                $errors[] = 'Unvollstaendige Adresse (Raum/Domain/Gruppe/Key).';
                continue;
            }
            if (isset($seen[$key])) $errors[] = 'Doppelter Key: ' . $key;
            $seen[$key] = true;
            if (empty($row['kiEnabled'])) continue;
            if (!$this->isSupportedAIEntry($row)) {
                $errors[] = $key . ': kein unterstuetztes KI-Geraet.';
                continue;
            }
            $read = $this->aiReadId($row);
            if ($checkReadIds && ($read <= 0 || !IPS_VariableExists($read))) {
                $errors[] = $key . ': ungueltige Lesevariable ' . $read;
            }
            if (empty($row['kiReadOnly'])) {
                $write = $this->aiWriteId($row);
                if ($write <= 0 || !IPS_VariableExists($write)) $errors[] = $key . ': ungueltige Schaltvariable.';
                elseif (function_exists('HasAction') && !HasAction($write)) $errors[] = $key . ': keine Schaltaktion.';
                if (!in_array((string)($row['group'] ?? ''), ['switches','dimmers','fans','heizung'],true)) {
                    $errors[] = $key . ': Schreiben fuer diese Gruppe gesperrt.';
                }
            }
        }
        return $errors;
    }

    private function aiReadId(array $row): int
    {
        $group = (string)($row['group'] ?? '');
        $fields = match ($group) {
            'heizung' => ['ist','soll','eingestellt'],
            'dimmers' => ['value','state'],
            'jalousie' => ['wert'],
            default => ['state','value']
        };
        foreach ($fields as $field) if ((int)($row[$field] ?? 0)>0) return (int)$row[$field];
        return 0;
    }

    private function aiWriteId(array $row): int
    {
        return match ((string)($row['group'] ?? '')) {
            'switches','fans' => (int)($row['toggle'] ?? 0),
            'dimmers' => (int)($row['set'] ?? 0),
            'heizung' => (int)($row['soll'] ?? $row['eingestellt'] ?? 0),
            default => 0
        };
    }

    private function isSupportedAIEntry(array $row): bool
    {
        $domain = (string)($row['domain'] ?? '');
        $group = (string)($row['group'] ?? '');
        return ($domain === 'licht' && in_array($group,['switches','dimmers','status'],true))
            || ($domain === 'heizung' && $group === 'heizung')
            || ($domain === 'jalousie' && $group === 'jalousie')
            || ($domain === 'lueftung' && $group === 'fans');
    }

    /**
     * Speichert die (gefilterten) Einträge zurück in das RoomsCatalogEdit-Script.
     * Wird über den Formular-Button mit RCC_($id, json_encode($Entries)) aufgerufen.
     */
    public function SaveToEdit(string $entriesJson)
    {
        $this->logDebug('SaveToEdit: aufgerufen, entriesJson.len=' . strlen($entriesJson));

        if ($entriesJson === '') {
            $entries = $this->getRuntimeEntries();
        } else {
            $decoded = json_decode($entriesJson, true);
            if (!is_array($decoded)) {
                $this->logDebug('SaveToEdit: json_decode fehlgeschlagen');
                return;
            }

            if (isset($decoded['roomKey']) || isset($decoded['domain']) || isset($decoded['key'])) {
                $entries = [$decoded];
            } else {
                $entries = $decoded;
            }
        }

        if (!is_array($entries)) {
            $this->logDebug('SaveToEdit: entries ist kein Array');
            return;
        }

        foreach ($entries as &$entry) {
            if (is_array($entry)) unset($entry['rowColor'], $entry['kiStatus'], $entry['diffKeys'], $entry['editable']);
        }
        unset($entry);
        $this->logDebug('SaveToEdit: START, rows=' . count($entries));

        $editScriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($editScriptId <= 0 || !IPS_ScriptExists($editScriptId)) {
            $this->logDebug('SaveToEdit: RoomsCatalogEditScriptID ungültig: ' . $editScriptId);
            return;
        }

        $all = $this->getRuntimeEntries();

        if (count($entries) === 1) {
            $row = $entries[0];
            $rk  = $this->buildCompositeKey($row);

            if ($rk !== null) {
                foreach ($all as $i => $orig) {
                    if (!is_array($orig)) {
                        continue;
                    }
                    if ($this->buildCompositeKey($orig) !== $rk) {
                        continue;
                    }

                    $changedNonSelection = false;

                    foreach ($row as $k => $v) {
                        if (in_array($k, ['selected', 'rowColor', 'kiStatus', 'diffKeys'], true)) {
                            continue;
                        }
                        $ov = $orig[$k] ?? null;
                        if ($ov != $v) {
                            $changedNonSelection = true;
                            break;
                        }
                    }

                    if (!$changedNonSelection) {
                        foreach ($orig as $k => $ov) {
                            if (in_array($k, ['selected', 'rowColor', 'kiStatus', 'diffKeys'], true)) {
                                continue;
                            }
                            if (!array_key_exists($k, $row)) {
                                if ($ov !== null && $ov !== '' && $ov !== 0 && $ov !== 0.0) {
                                    $changedNonSelection = true;
                                    break;
                                }
                            }
                        }
                    }

                    if (!$changedNonSelection) {
                        $all[$i]['selected'] = !empty($row['selected']);
                        $this->WriteAttributeString('RuntimeEntries', json_encode($all));
                        $this->logDebug('SaveToEdit: nur Markierung geändert, kein Write nach RoomsCatalogEdit, kein Reload');
                        return;
                    }

                    break;
                }
            }
        }

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

        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rk = $this->buildCompositeKey($row);
            if ($rk === null) {
                continue;
            }
            if (isset($index[$rk])) {
                $all[$index[$rk]] = $row;
            } else {
                $all[]      = $row;
                $index[$rk] = count($all) - 1;
            }
        }

        $this->WriteAttributeString('RuntimeEntries', json_encode($all));

        $existingEdit = $this->loadRoomsCatalog($editScriptId, 'EDIT');
        $newCatalog   = $this->rebuildRoomsCatalogFromEntries($all, $existingEdit);

        $php = "<?php\nreturn " . var_export($newCatalog, true) . ";\n";
        IPS_SetScriptContent($editScriptId, $php);

        $roomsArray = $this->extractRoomsFromCatalog($newCatalog);
        $roomCount  = count($roomsArray);
        $this->logDebug('SaveToEdit: ENDE, rooms=' . $roomCount);

        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
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
        $errors = $this->validateEntries($this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($editCatalog)), false);
        if ($errors !== []) {
            echo 'Veröffentlichung abgebrochen: ' . implode('; ',array_slice($errors,0,4));
            return;
        }
        if ($editCatalog === []) {
            $this->logDebug('SaveEditToProd: Edit-Katalog leer oder ungültig');
            return;
        }

        $php = "<?php\nreturn " . var_export($editCatalog, true) . ";\n";
        $backup = IPS_CreateScript(0);
        IPS_SetParent($backup, IPS_GetParent($prodId));
        IPS_SetName($backup, 'RoomsCatalog_Backup_' . date('Ymd_His') . '_' . $backup);
        IPS_SetScriptContent($backup, IPS_GetScriptContent($prodId));
        IPS_SetScriptContent($prodId, $php);
        echo 'Produktiver Katalog aktualisiert; Backup-Skript ' . $backup . '.';

        $roomsArray = $this->extractRoomsFromCatalog($editCatalog);
        $roomCount  = count($roomsArray);

        $this->logDebug('SaveEditToProd: Edit → Prod übernommen, rooms=' . $roomCount);

        $this->ReloadCatalog();
    }

    /**
     * Verwirft alle Änderungen im RoomsCatalogEdit und setzt ihn auf den Stand von PROD zurück.
     */
    public function DiscardEditChanges(): void
    {
        $prodId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');

        if ($prodId <= 0 || !IPS_ScriptExists($prodId)) {
            $this->logDebug('DiscardEditChanges: RoomsCatalogScriptID ungültig: ' . $prodId);
            return;
        }

        if ($editId <= 0 || !IPS_ScriptExists($editId)) {
            $this->logDebug('DiscardEditChanges: RoomsCatalogEditScriptID ungültig: ' . $editId);
            return;
        }

        $prodCatalog = $this->loadRoomsCatalog($prodId, 'PROD');
        if ($prodCatalog === []) {
            $this->logDebug('DiscardEditChanges: Prod-Katalog leer oder ungültig');
            return;
        }

        $php = "<?php\nreturn " . var_export($prodCatalog, true) . ";\n";
        IPS_SetScriptContent($editId, $php);

        $roomsArray = $this->extractRoomsFromCatalog($prodCatalog);
        $roomCount  = count($roomsArray);

        $this->logDebug('DiscardEditChanges: Prod → Edit übernommen, rooms=' . $roomCount);

        $this->ReloadCatalog();
    }

    /**
     * Klont die erste markierte Zeile in RuntimeEntries.
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
        foreach (['state','toggle','set','value','ist','soll','eingestellt','wert','stellung','id','var','controlId','statusId','tiltId'] as $idField) {
            if (isset($clone[$idField])) $clone[$idField] = 0;
        }
        $clone['kiEnabled'] = false;
        $clone['kiReadOnly'] = true;
        if (isset($clone['entityId'])) $clone['entityId'] = '';

        array_splice($entries, $cloneIndex + 1, 0, [$clone]);

        $this->WriteAttributeString('RuntimeEntries', json_encode($entries));

        $existingEdit = $this->loadRoomsCatalog($editScriptId, 'EDIT');
        $newCatalog   = $this->rebuildRoomsCatalogFromEntries($entries, $existingEdit);
        $php          = "<?php\nreturn " . var_export($newCatalog, true) . ";\n";
        IPS_SetScriptContent($editScriptId, $php);

        $this->logDebug('CloneSelectedEntry: Eintrag geklont');

        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
    }

    /**
     * Löscht alle markierten Zeilen aus RuntimeEntries.
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

        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
    }

    // =====================================================================
    // Konfigurationsformular
    // =====================================================================

    public function GetConfigurationForm()
    {
        $entriesProd  = $this->getProdEntries();
        $entriesEdit  = $this->getRuntimeEntries();
        $filterAI     = $this->ReadAttributeString('FilterAI');
        $filterRoom   = $this->ReadAttributeString('FilterRoom');
        $filterDomain = $this->ReadAttributeString('FilterDomain');
        $filterGroup  = $this->ReadAttributeString('FilterGroup');

        $editScriptId    = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $entriesEditFlat = [];
        if ($editScriptId > 0 && IPS_ScriptExists($editScriptId)) {
            $editCatalog     = $this->loadRoomsCatalog($editScriptId, 'EDIT');
            $editRooms       = $this->extractRoomsFromCatalog($editCatalog);
            $entriesEditFlat = $this->buildFlatEntriesFromRooms($editRooms);
        }

        [$roomOptions, $domainOptions, $groupOptions] = $this->buildFilterOptionsFromEntries(array_merge($entriesProd, $entriesEdit));

        $visibleProd    = $this->filterByAI($this->applyFilters($entriesEdit, $filterRoom, $filterDomain, $filterGroup), $filterAI);
        $visibleProd    = array_map(fn(array $r): array => $this->decorateRow($r), $visibleProd);
        $diffEntriesAll = $this->buildDiffEntries($entriesProd, $entriesEdit);
        $visibleDiff    = $this->filterByAI($this->applyFilters($diffEntriesAll, $filterRoom, $filterDomain, $filterGroup), $filterAI);

        $this->logDebug('GetConfigurationForm: sichtbare PROD-Einträge=' . count($visibleProd));
        $this->logDebug('GetConfigurationForm: sichtbare DIFF-Einträge=' . count($visibleDiff));

        $allForColumns = array_merge($visibleProd, $visibleDiff);
        $dynamicKeys   = $this->analyzeEntriesForDynamicColumns($allForColumns);

        $columnsProd = $this->buildOverviewColumns();
        // In den onEdit/onAdd-Skripten ist $Entries ein IPSList-Objekt.
        // Nur einzelne String-Felder aus der aktuellen Zeile auslesen; niemals
        // das gesamte Objekt direkt an array_filter() übergeben.
        $saveSelectedRowCallback = $this->buildListRowSaveCallback($entriesEdit);
        $columnsEdit = [];
        foreach ($columnsProd as $col) {
            if (($col['name'] ?? '') === 'selected') {
                continue;
            }
            $columnsEdit[] = $col;
        }

        $columnsEdit[] = ['caption'=>'Aenderung','name'=>'diffKeys','width'=>'130px','save'=>false,'quickFilter'=>true];
        $diffExpanded = count($visibleDiff) > 0;
        $aiReadable = count(array_filter($entriesEdit, static fn($r): bool => !empty($r['kiEnabled'])));
        $aiWritable = count(array_filter($entriesEdit, static fn($r): bool => !empty($r['kiEnabled']) && empty($r['kiReadOnly'])));

        $form = [
            'elements' => [
                [
                    'type'=>'Label',
                    'name'=>'CatalogSummary',
                    'caption'=>'RoomsCatalog EDIT: ' . count($entriesEdit) . ' Geraete, KI sichtbar: ' . $aiReadable
                        . ', KI schaltbar: ' . $aiWritable . '. Aenderungen werden erst mit der Freigabe produktiv.'
                ],
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
                    'caption' => 'Geraete bearbeiten (RoomsCatalog EDIT)',
                    'expanded' => true,
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
                                    'type'=>'Select','name'=>'FilterAI','caption'=>'KI-Filter',
                                    'options'=>[
                                        ['caption'=>'Alle Geraete','value'=>'all'],
                                        ['caption'=>'Mit KI','value'=>'enabled'],
                                        ['caption'=>'KI darf schalten','value'=>'writable'],
                                        ['caption'=>'Ohne KI','value'=>'disabled']
                                    ],
                                    'value'=>$filterAI,
                                    'onChange'=>'RCC_SetAIFilter($id, $FilterAI);'
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
                            'caption'  => 'Geraete und KI-Freigaben',
                            'rowCount' => 20,
                            'loadValuesFromConfiguration' => false,
                            'form' => 'return json_decode(RCC_GetEntryEditForm($id, (string)($Entries["roomKey"] ?? ""), (string)($Entries["domain"] ?? ""), (string)($Entries["group"] ?? ""), (string)($Entries["key"] ?? "")), true);',
                            'add'      => true,
                            'delete'   => false,
                            'sort'     => ['column' => 'roomLabel', 'direction' => 'ascending'],
                            'columns'  => $columnsProd,
                            'values'   => $visibleProd,
                            'rowColor' => 'rowColor',
                            'onEdit'   => $saveSelectedRowCallback,
                            'onAdd'    => $saveSelectedRowCallback
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Label',
                                    'caption' => 'Mehrfachauswahl: Häkchen in der Spalte Markiert setzen, oder alle sichtbaren markieren.'
                                ],
                                [
                                    'type'=>'Button',
                                    'caption'=>'ALLE SICHTBAREN MARKIEREN',
                                    'onClick'=>'RCC_SetVisibleSelection($id, true);'
                                ],
                                [
                                    'type'=>'Button',
                                    'caption'=>'MARKIERUNGEN AUFHEBEN',
                                    'onClick'=>'RCC_SetVisibleSelection($id, false);'
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'MARKIERTE CLONEN',
                                    'onClick' => 'RCC_CloneSelectedEntry($id);'
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'MARKIERTE LÖSCHEN',
                                    'confirm' => 'Markierte Eintraege im EDIT-Katalog wirklich loeschen?',
                                    'onClick' => 'RCC_DeleteSelectedEntries($id);'
                                ]
                            ]
                        ]
                    ]
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => 'Vorschau: Unterschiede EDIT zu PROD',
                    'expanded' => $diffExpanded,
                    'items'    => [
                        [
                            'type'     => 'List',
                            'name'     => 'EntriesEdit',
                            'caption'  => 'EDIT-zu-PROD-Aenderungen',
                            'rowCount' => 15,
                            'add'      => false,
                            'delete'   => false,
                            'sort'     => ['column'=>'roomLabel','direction'=>'ascending'],
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
                    'caption' => 'Farbe: Gruen = KI schaltbar, Blau = nur lesen, Grau = KI aus, Rot = pruefen. EDIT wird erst nach Freigabe produktiv.'
                ],
                [
                    'type'=>'RowLayout','items'=>[
                        ['type'=>'Button','caption'=>'MARKIERTE: MIT KI / NUR LESEN','onClick'=>'RCC_SetSelectedAI($id, true, true);'],
                        ['type'=>'Button','caption'=>'MARKIERTE: KI + SCHALTEN','confirm'=>'KI-Schreibrechte fuer markierte Eintraege im EDIT-Katalog freigeben?','onClick'=>'RCC_SetSelectedAI($id, true, false);'],
                        ['type'=>'Button','caption'=>'MARKIERTE: OHNE KI','onClick'=>'RCC_SetSelectedAI($id, false, true);']
                    ]
                ],
                [
                    'type'=>'RowLayout','items'=>[
                        ['type'=>'Button','caption'=>'DIFF-VORSCHAU','onClick'=>'RCC_PreviewChanges($id);'],
                        ['type'=>'Button','caption'=>'KATALOG PRUEFEN','onClick'=>'RCC_ValidateCatalog($id);']
                    ]
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'ÄNDERUNGEN IM ROOMS CATALOG EDIT VERWERFEN',
                    'onClick' => 'RCC_DiscardEditChanges($id);'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'EDIT NACH PROD UEBERNEHMEN',
                    'confirm' => 'Aenderungen produktiv uebernehmen? Es wird zuvor ein Backup angelegt.',
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

        $scriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $scriptId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        }
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
            $display                  = (string)($roomCfg['display'] ?? (string)$roomKey);
            $floor                    = (string)($roomCfg['floor'] ?? '');
            $result[(string)$roomKey] = [
                'display' => $display,
                'floor'   => $floor,
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
            $roomFloor = (string)($roomCfg['floor'] ?? '');
            $domains   = $roomCfg['domains'] ?? [];

            foreach ($domains as $domainKey => $domainCfg) {
                if (!is_array($domainCfg)) {
                    continue;
                }

                if ($domainKey === 'heizung') {
                    foreach ($domainCfg as $hkKey => $hkCfg) {
                        if (!is_array($hkCfg)) {
                            continue;
                        }

                        $rows[] = $this->buildEntryRow(
                            (string)$roomKey,
                            $roomLabel,
                            $roomFloor,
                            (string)$domainKey,
                            (string)$domainKey,
                            (string)$hkKey,
                            $hkCfg
                        );
                    }
                    continue;
                }

                if ($domainKey === 'jalousie') {
                    foreach ($domainCfg as $entryKey => $entryCfg) {
                        if (!is_array($entryCfg)) {
                            continue;
                        }

                        $rows[] = $this->buildEntryRow(
                            (string)$roomKey,
                            $roomLabel,
                            $roomFloor,
                            (string)$domainKey,
                            (string)$domainKey,
                            (string)$entryKey,
                            $entryCfg
                        );
                    }
                    continue;
                }

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
                            $roomFloor,
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
        string $roomFloor,
        string $domainKey,
        string $groupKey,
        string $entryKey,
        array $cfg
    ): array {
        $row = [
            'selected'  => false,
            'roomKey'   => $roomKey,
            'roomLabel' => $roomLabel,
            'floor'     => isset($cfg['floor']) ? (string)$cfg['floor'] : $roomFloor,
            'domain'    => $domainKey,
            'group'     => $groupKey,
            'key'       => $entryKey,
            'kiEnabled' => $roomKey !== 'global' && (($domainKey === 'licht' && in_array($groupKey, ['switches','dimmers','status'], true))
                || ($domainKey === 'heizung' && $groupKey === 'heizung')
                || ($domainKey === 'jalousie' && $groupKey === 'jalousie')
                || ($domainKey === 'lueftung' && $groupKey === 'fans')),
            'kiReadOnly' => true
        ];

        $forceIdKeys = ['id', 'controlId', 'statusId', 'tiltId', 'set', 'state', 'ist', 'soll'];

        foreach ($cfg as $k => $v) {
            // Persistierte KI-Flags muessen die sicheren Defaults uebersteuern koennen.
            if (in_array($k, ['kiEnabled','kiReadOnly'], true)) {
                $row[$k] = (bool)$v;
                continue;
            }
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
                $enc = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $row[$k] = $enc !== false ? $enc : '';
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

    private function analyzeEntriesForDynamicColumns(array $entries): array
    {
        $dynamicKeys = [];

        $baseMeta = [
            'selected',
            'roomKey',
            'roomLabel',
            'floor',
            'domain',
            'group',
            'key'
        ];

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

                if (!isset($dynamicKeys[$k]) && $forcedId) {
                    $dynamicKeys[$k] = [
                        'type' => 'number',
                        'isId' => true
                    ];
                }

                if ($v === null || $v === '' || (is_string($v) && trim($v) === '.') ||
                    (is_int($v) && $v === 0) || (is_float($v) && $v == 0.0)) {
                    continue;
                }

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
            'caption' => 'Floor',
            'name'    => 'floor',
            'width'   => '90px',
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
                $row             = $prodIndex[$k];
                $row['rowColor'] = '#ffcccc';
                $row['diffKeys'] = 'removed';
                $diffRows[]      = $row;
                continue;
            }

            if (!$inProd && $inEdit) {
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

                    $row             = $editIndex[$k];
                    $row['rowColor'] = '#fff5cc';
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
     * Wichtig: schreibt KEIN 'rooms'-Key mehr.
     * Räume werden als Top-Level-Keys im Katalog angelegt.
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
            $roomFloor = isset($row['floor']) ? (string)$row['floor'] : '';
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
                if ($roomFloor !== '') {
                    $rooms[$roomKey]['floor'] = $roomFloor;
                }
            }

            if (!isset($rooms[$roomKey]['domains'][$domain])) {
                $rooms[$roomKey]['domains'][$domain] = [];
            }

            $cfg = [];
            foreach ($row as $k => $v) {
                if (in_array($k, ['selected', 'roomKey', 'roomLabel', 'floor', 'domain', 'group', 'key', 'rowColor','kiStatus','diffKeys','editable'], true)) {
                    continue;
                }

                if (is_string($v)) {
                    $tv = trim($v);
                    if ($tv !== '' && ($tv[0] === '{' || $tv[0] === '[')) {
                        $decoded = json_decode($tv, true);
                        if (is_array($decoded)) {
                            $v = $decoded;
                        }
                    }
                }

                $cfg[$k] = $v;
            }

            if ($domain === 'heizung' || $domain === 'jalousie') {
                $rooms[$roomKey]['domains'][$domain][$key] = $cfg;
            } else {
                if (!isset($rooms[$roomKey]['domains'][$domain][$group])) {
                    $rooms[$roomKey]['domains'][$domain][$group] = [];
                }
                $rooms[$roomKey]['domains'][$domain][$group][$key] = $cfg;
            }
        }

        $existingRoomsMap = (isset($existingCatalog['rooms']) && is_array($existingCatalog['rooms']))
            ? $existingCatalog['rooms']
            : $existingCatalog;

        foreach ($rooms as $roomKey => $roomCfg) {
            $base = (isset($existingRoomsMap[$roomKey]) && is_array($existingRoomsMap[$roomKey]))
                ? $existingRoomsMap[$roomKey]
                : [];

            if ($base === []) {
                continue;
            }

            foreach ($base as $bk => $bv) {
                if ($bk === 'domains') {
                    continue;
                }
                if (!array_key_exists($bk, $rooms[$roomKey])) {
                    $rooms[$roomKey][$bk] = $bv;
                }
            }

            $baseDomains = isset($base['domains']) && is_array($base['domains']) ? $base['domains'] : [];
            if ($baseDomains === []) {
                continue;
            }

            if (!isset($rooms[$roomKey]['domains']) || !is_array($rooms[$roomKey]['domains'])) {
                $rooms[$roomKey]['domains'] = [];
            }

            foreach ($baseDomains as $domainKey => $domainCfg) {
                if (!is_array($domainCfg)) {
                    continue;
                }

                if (!array_key_exists($domainKey, $rooms[$roomKey]['domains'])) {
                    if (!$this->domainHasManagedEntries($domainCfg, (string)$domainKey)) {
                        $rooms[$roomKey]['domains'][$domainKey] = $domainCfg;
                    }
                    continue;
                }

                if (!is_array($rooms[$roomKey]['domains'][$domainKey])) {
                    continue;
                }

                $rooms[$roomKey]['domains'][$domainKey] = $this->mergeDomainUnmanagedKeys(
                    $rooms[$roomKey]['domains'][$domainKey],
                    $domainCfg,
                    (string)$domainKey
                );
            }
        }

        $roomKeys  = array_keys($rooms);
        $newCatalog = [];

        foreach ($existingCatalog as $k => $v) {
            if ($k === 'rooms') {
                continue;
            }
            if (in_array((string)$k, $roomKeys, true)) {
                continue;
            }
            $newCatalog[$k] = $v;
        }

        foreach ($rooms as $roomKey => $roomCfg) {
            $newCatalog[$roomKey] = $roomCfg;
        }

        return $newCatalog;
    }

    private function arrayHasNestedArray(array $a): bool
    {
        foreach ($a as $v) {
            if (is_array($v)) {
                return true;
            }
        }
        return false;
    }

    private function domainHasManagedEntries(array $domainCfg, string $domainKey): bool
    {
        if ($domainKey === 'heizung' || $domainKey === 'jalousie') {
            return true;
        }

        foreach ($domainCfg as $v) {
            if (is_array($v) && $this->arrayHasNestedArray($v)) {
                return true;
            }
        }

        return false;
    }

    private function mergeDomainUnmanagedKeys(array $newDomain, array $oldDomain, string $domainKey): array
    {
        if ($domainKey === 'heizung' || $domainKey === 'jalousie') {
            foreach ($oldDomain as $k => $v) {
                if (!array_key_exists($k, $newDomain) && !is_array($v)) {
                    $newDomain[$k] = $v;
                }
            }
            return $newDomain;
        }

        foreach ($oldDomain as $k => $v) {
            if (array_key_exists($k, $newDomain)) {
                continue;
            }

            if (!is_array($v)) {
                $newDomain[$k] = $v;
                continue;
            }

            if (!$this->arrayHasNestedArray($v)) {
                $newDomain[$k] = $v;
            }
        }

        return $newDomain;
    }



    /**
     * Aktualisiert sichtbare Tabellen/Statistik ohne das komplette Konfigurations-
     * formular zu ersetzen. Dadurch bleibt "Geraete bearbeiten" aufgeklappt.
     */
    private function RefreshVisibleLists(): void
    {
        $edit = $this->getRuntimeEntries();
        $prod = $this->getProdEntries();
        $room = $this->ReadAttributeString('FilterRoom');
        $domain = $this->ReadAttributeString('FilterDomain');
        $group = $this->ReadAttributeString('FilterGroup');
        $ai = $this->ReadAttributeString('FilterAI');

        $visible = $this->filterByAI($this->applyFilters($edit, $room, $domain, $group), $ai);
        $visible = array_map(fn(array $row): array => $this->decorateRow($row), $visible);
        $diff = $this->buildDiffEntries($prod, $edit);
        $visibleDiff = $this->filterByAI($this->applyFilters($diff, $room, $domain, $group), $ai);

        // Das Aktualisieren der Listen lässt ExpansionPanel-/Filterzustand intakt.
        $this->UpdateFormField('Entries', 'values', json_encode($visible, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('EntriesEdit', 'values', json_encode($visibleDiff, JSON_INVALID_UTF8_SUBSTITUTE));

        $readable = count(array_filter($edit, static fn($r): bool => !empty($r['kiEnabled'])));
        $writable = count(array_filter($edit, static fn($r): bool => !empty($r['kiEnabled']) && empty($r['kiReadOnly'])));
        $summary = 'RoomsCatalog EDIT: ' . count($edit) . ' Geraete, KI sichtbar: ' . $readable .
            ', KI schaltbar: ' . $writable . '. Aenderungen werden erst mit der Freigabe produktiv.';
        $this->UpdateFormField('CatalogSummary', 'caption', $summary);
    }

    private function getProdEntries(): array
    {
        $id = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        if ($id <= 0 || !IPS_ScriptExists($id)) return [];
        return $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($this->loadRoomsCatalog($id, 'PROD')));
    }

    private function filterByAI(array $rows, string $filter): array
    {
        return array_values(array_filter($rows, static function ($row) use ($filter): bool {
            $enabled = !empty($row['kiEnabled']);
            $readOnly = !empty($row['kiReadOnly']);
            return match ($filter) {
                'enabled' => $enabled,
                'writable' => $enabled && !$readOnly,
                'disabled' => !$enabled,
                default => true
            };
        }));
    }

    private function decorateRow(array $row): array
    {
        $enabled = !empty($row['kiEnabled']);
        $readOnly = !empty($row['kiReadOnly']);
        $row['kiStatus'] = !$enabled ? 'Ohne KI' : ($readOnly ? 'Nur lesen' : 'Schaltbar');
        $row['rowColor'] = !$enabled ? '#EEEEEE' : ($readOnly ? '#E2F2FF' : '#DDF4DE');
        if ($this->validateEntries([$row]) !== []) {
            $row['kiStatus'] = 'Pruefen';
            $row['rowColor'] = '#FFCCCC';
        }
        $row['title'] = (string)($row['title'] ?? $row['key'] ?? '');
        return $row;
    }

    private function buildOverviewColumns(): array
    {
        return [
            ['caption'=>'Markiert','name'=>'selected','width'=>'85px','add'=>false,'edit'=>['type'=>'CheckBox']],
            ['caption'=>'Raum','name'=>'roomLabel','width'=>'160px','add'=>'','quickFilter'=>true],
            ['caption'=>'Etage','name'=>'floor','width'=>'75px','add'=>'','quickFilter'=>true],
            ['caption'=>'Bereich','name'=>'domain','width'=>'105px','add'=>'licht','quickFilter'=>true],
            ['caption'=>'Gruppe','name'=>'group','width'=>'105px','add'=>'switches','quickFilter'=>true],
            ['caption'=>'Gerät','name'=>'title','width'=>'auto','add'=>'','quickFilter'=>true],
            ['caption'=>'Key','name'=>'key','width'=>'130px','add'=>'','quickFilter'=>true],
            ['caption'=>'Mit KI','name'=>'kiEnabled','width'=>'80px','add'=>false,'edit'=>['type'=>'CheckBox']],
            ['caption'=>'Nur abfragen','name'=>'kiReadOnly','width'=>'110px','add'=>true,'edit'=>['type'=>'CheckBox']],
            ['caption'=>'Freigabe','name'=>'kiStatus','width'=>'105px','add'=>'','save'=>false],
            ['caption'=>'Raum-Key','name'=>'roomKey','visible'=>false,'add'=>'','quickFilter'=>true],
            ['caption'=>'Synonyme','name'=>'synonyms','visible'=>false,'add'=>'','quickFilter'=>true]
        ];
    }

    /** Dynamischer Bearbeitungsdialog für Symcon-Listen, benötigt PHP-Form-Dialog-Support. */
    public function GetEntryEditForm(string $roomKey = '', string $domain = '', string $group = '', string $key = ''): string
    {
        // Im "form"-Skript der Symcon-Liste steht $Entries nicht nur für eine
        // einzelne Zeile: es enthält auch numerisch alle Tabellenzeilen.
        // Deswegen nur die vier Schlüssel übergeben und die Daten gezielt laden.
        $row = [];
        if ($roomKey !== '' && $domain !== '' && $group !== '' && $key !== '') {
            foreach ($this->getRuntimeEntries() as $candidate) {
                if (!is_array($candidate)) continue;
                if ((string)($candidate['roomKey'] ?? '') === $roomKey
                    && (string)($candidate['domain'] ?? '') === $domain
                    && (string)($candidate['group'] ?? '') === $group
                    && (string)($candidate['key'] ?? '') === $key) {
                    $row = $candidate;
                    break;
                }
            }
        }
        if ($row === []) {
            // Leerer "Hinzufügen"-Dialog mit sicheren Defaults und
            // Variablenfeldern für einen neuen Lichtschalter.
            $row = [
                'roomKey' => $roomKey, 'roomLabel' => '',
                'floor' => 'EG', 'domain' => $domain ?: 'licht',
                'group' => $group ?: 'switches', 'key' => $key,
                'title' => '', 'synonyms' => '', 'entityId' => '',
                'kiEnabled' => false, 'kiReadOnly' => true,
                'state' => 0, 'toggle' => 0
            ];
        }
        $items = [
            [
                'type'=>'ExpansionPanel','caption'=>'Raum und Geraet','expanded'=>true,
                'items'=>[
                    ['type'=>'RowLayout','items'=>[
                        ['type'=>'ValidationTextBox','name'=>'roomKey','caption'=>'Raum-Key'],
                        ['type'=>'ValidationTextBox','name'=>'roomLabel','caption'=>'Raumname'],
                        ['type'=>'Select','name'=>'floor','caption'=>'Etage','options'=>array_map(
                            static fn($x): array=>['caption'=>$x,'value'=>$x],
                            ['','Keller','EG','OG','DG','AUSSEN'])]
                    ]],
                    ['type'=>'RowLayout','items'=>[
                        ['type'=>'ValidationTextBox','name'=>'domain','caption'=>'Bereich'],
                        ['type'=>'ValidationTextBox','name'=>'group','caption'=>'Gruppe'],
                        ['type'=>'ValidationTextBox','name'=>'key','caption'=>'Geraete-Key']
                    ]],
                    ['type'=>'ValidationTextBox','name'=>'title','caption'=>'Anzeigename'],
                    ['type'=>'ValidationTextBox','name'=>'synonyms','caption'=>'Synonyme (Komma getrennt)'],
                    ['type'=>'ValidationTextBox','name'=>'entityId','caption'=>'Entity-ID (optional)']
                ]
            ],
            [
                'type'=>'ExpansionPanel','caption'=>'KI-Freigabe','expanded'=>true,'items'=>[
                    ['type'=>'CheckBox','name'=>'kiEnabled','caption'=>'Mit KI: Abfragen freigeben'],
                    ['type'=>'CheckBox','name'=>'kiReadOnly','caption'=>'Nur abfragen: Schalten gesperrt'],
                    ['type'=>'Label','caption'=>'Schaltbefehle nur mit globaler Freigabe, expliziter Geraetefreigabe und gueltiger IP-Symcon-Aktion.']
                ]
            ]
        ];
        $hidden = ['selected','roomKey','roomLabel','floor','domain','group','key','title',
            'synonyms','entityId','kiEnabled','kiReadOnly','rowColor','kiStatus','diffKeys','editable'];
        $idFields = ['id','controlId','statusId','tiltId','set','state','ist','soll','eingestellt',
            'stellung','toggle','value','wert','var'];
        $extra = [];
        foreach ($row as $name => $value) {
            if (in_array($name,$hidden,true)) continue;
            if (in_array($name,$idFields,true)) {
                $extra[]=['type'=>in_array($name,['id','var'],true)?'SelectObject':'SelectVariable',
                    'name'=>$name,'caption'=>'IP-Symcon: '.$name];
            } elseif (is_bool($value)) {
                $extra[]=['type'=>'CheckBox','name'=>$name,'caption'=>$name];
            } elseif (is_int($value)||is_float($value)) {
                $extra[]=['type'=>'NumberSpinner','name'=>$name,'caption'=>$name];
            } else {
                $extra[]=['type'=>'ValidationTextBox','name'=>$name,'caption'=>$name];
            }
        }
        $items[]=['type'=>'ExpansionPanel','caption'=>'Weitere Felder / Objekt-IDs','expanded'=>true,
            'items'=>$extra!==[]?$extra:[['type'=>'Label','caption'=>'Keine weiteren Eigenschaften vorhanden']]];
        return json_encode($items,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function logDebug(string $message): void
    {
        // IPS_LogMessage('Alexa', 'RCC-DEBUG: ' . $message);
    }
}
