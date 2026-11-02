<?php

declare(strict_types=1);

/**
 * ============================================================
 * ROOMS CATALOG CONFIGURATOR — IP-Symcon Modul (V3.2)
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
 *
 * 2026-11-02: V3.0 — additive AI-Gerätebeschreibung
 * - Typabhängiger Dialog mit mehreren IP-Symcon-Variablen und Zustandszuordnung
 * - ai.version/type/variables/stateMapping ergänzen die unveränderten Legacy-Felder
 * - Unbekannte Geräteeigenschaften und Raum-Metadaten verlustfrei erhalten
 * - EDIT -> PROD nach Prüfung und verifiziertem Backup, keine Auto-Migration
 *
 * 2026-11-02: V3.1 — zentrale Raumverwaltung
 * - Raumname, Etage und alternative Namen nur einmal pro Raum bearbeiten
 * - Neue, leere Räume zuerst ausschließlich im EDIT-Katalog anlegen
 * - Alias-Konflikte prüfen, unbekannte Raum-/Gerätedaten beibehalten
 *
 * 2026-11-02: V3.2 — sichere Variablenvorschläge pro Gerät
 * - Analysiert nur die Instanz/Kategorie bereits zugeordneter Gerätevariablen
 * - Bewertet Namen, Ident, Profile, Typ und Schaltaktion; zeigt Konflikte an
 * - Übernimmt nur eindeutige Treffer nach expliziter Bestätigung nach EDIT
 */

class RoomsCatalogConfigurator extends IPSModule
{
    /** Die Editor-Felder gehören nicht zum Katalog, sondern werden in ai.variables gespeichert. */
    private const AI_VARIABLE_FIELDS = [
        'power' => 'aiPowerId',
        'brightness' => 'aiBrightnessId',
        'position' => 'aiPositionId',
        'targetPosition' => 'aiTargetPositionId',
        'tilt' => 'aiTiltId',
        'moving' => 'aiMovingId',
        'control' => 'aiControlId',
        'temperature' => 'aiTemperatureId',
        'targetTemperature' => 'aiTargetTemperatureId',
        'open' => 'aiOpenId',
        'powerWatts' => 'aiWattsId',
        'energy' => 'aiEnergyId',
        'motion' => 'aiMotionId'
    ];

    private const AI_STATE_FIELDS = [
        'on' => 'aiOnValue', 'off' => 'aiOffValue',
        'open' => 'aiOpenValue', 'closed' => 'aiClosedValue'
    ];

    private const AI_TYPE_FIELDS = [
        'light' => ['power', 'control'],
        'dimmer' => ['power', 'brightness', 'control'],
        'blind' => ['position', 'targetPosition', 'tilt', 'moving', 'control'],
        'heating' => ['temperature', 'targetTemperature', 'control'],
        'window' => ['open'],
        'switch' => ['power', 'powerWatts', 'energy', 'control'],
        'energy' => ['powerWatts', 'energy'],
        'motion' => ['motion'],
        'fan' => ['power', 'control'],
        'generic' => ['power', 'control']
    ];

    /** Normalisierte Namen/Ident-Beispiele. Mehrdeutige Treffer werden nicht übernommen. */
    private const AI_SUGGESTION_NAMES = [
        'power' => ['status','state','schaltstatus','schaltzustand','ein','onoff','powerstate'],
        'brightness' => ['helligkeit','brightness','dimmwert','dimmerlevel','dimming'],
        'position' => ['position','istposition','behangposition','rollladenposition','actualposition'],
        'targetPosition' => ['sollposition','targetposition','positionvorgabe','positiontarget'],
        'tilt' => ['lamellen','lamellenwinkel','lamellenposition','tilt','slatposition'],
        'moving' => ['fahrstatus','fahrrichtung','moving','bewegungstatus','ismoving'],
        'control' => ['schalten','schaltbefehl','steuern','steuerung','switch','command','control'],
        'temperature' => ['isttemperatur','temperaturist','raumtemperatur','actualtemperature','temperature'],
        'targetTemperature' => ['solltemperatur','temperatursoll','zieltemperatur','targettemperature','setpoint'],
        'open' => ['fensterkontakt','fensterstatus','kontakt','contact','openstate'],
        'powerWatts' => ['leistung','wirkleistung','powerw','powerwatts','currentpower'],
        'energy' => ['energie','energieverbrauch','zaehlerstand','energy','consumption','kwh'],
        'motion' => ['bewegung','bewegungsmelder','motion','presence','praesenz']
    ];

    /** Auflösung der übergeordneten Instanz-ID je IP-Symcon-Objekt innerhalb des PHP-Laufs. */
    private array $ipsInstanceLookupCache = [];

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
        $this->RegisterAttributeString('SuggestionPreview', '{}');
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
        $this->clearSuggestionPreview();
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

    /** Ein Gerät markieren; Analyse verändert weder EDIT noch PROD. */
    public function AnalyzeSelectedDevice(): void
    {
        $selected = $this->singleSelectedDevice();
        if ($selected === null) {
            $this->clearSuggestionPreview();
            echo 'Bitte genau ein Geraet in der Spalte Markiert auswaehlen.';
            return;
        }
        $row = $selected['row'];
        $type = (string)($row['aiType'] ?? '');
        if (!array_key_exists($type, self::AI_TYPE_FIELDS)) $type = $this->inferredAIType($row);
        $scopedVariables = $this->getScopedIpsVariables($row);
        if ($scopedVariables === []) {
            $this->clearSuggestionPreview();
            echo 'Keine analysierbare Geraeteinstanz gefunden. Zuerst mindestens eine bestehende IPS-Variable beim Geraet zuordnen.';
            return;
        }
        $suggestions = $this->suggestAIFields($row, $type, $scopedVariables);
        $preview = [
            'key' => $this->buildCompositeKey($row),
            'fingerprint' => $this->suggestionFingerprint($row),
            'time' => time(), 'type' => $type, 'suggestions' => $suggestions
        ];
        $this->WriteAttributeString('SuggestionPreview', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('Suggestions', 'values', json_encode($suggestions, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $high = count(array_filter($suggestions, static fn($r): bool => $r['confidence'] === 'Hoch'));
        echo sprintf('%s: %d Variablen im Geraeteumfeld geprueft, %d eindeutige Vorschlaege. Details in V3.2 ansehen.',
            (string)($row['title'] ?? $row['key'] ?? 'Geraet'), count($scopedVariables), $high);
    }

    /** Nur bestätigte, eindeutige und noch unbelegte Bindungen nach EDIT schreiben. */
    public function ApplySelectedSuggestions(): void
    {
        $selected = $this->singleSelectedDevice();
        $preview = json_decode($this->ReadAttributeString('SuggestionPreview'), true);
        if ($selected === null || !is_array($preview) || !isset($preview['suggestions'])
            || !is_array($preview['suggestions'])) {
            echo 'Keine gueltige Vorschau. Bitte genau ein Geraet markieren und zuerst analysieren.';
            return;
        }
        $row = $selected['row'];
        if (($preview['key'] ?? null) !== $this->buildCompositeKey($row)
            || ($preview['fingerprint'] ?? '') !== $this->suggestionFingerprint($row)
            || time() - (int)($preview['time'] ?? 0) > 600) {
            echo 'Geraet oder Konfiguration hat sich geaendert. Bitte Vorschlaege neu analysieren.';
            return;
        }
        $type = (string)($preview['type'] ?? '');
        if (!array_key_exists($type, self::AI_TYPE_FIELDS)) return;
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($editId <= 0 || !IPS_ScriptExists($editId)) {
            echo 'RoomsCatalogEdit ist nicht konfiguriert.';
            return;
        }
        $rows = $this->getRuntimeEntries();
        $index = $selected['index'];
        // Vorschlags-IDs auch bei der Übernahme erneut anhand der aktuellen
        // IPS-Objekte/Typen/Aktionen und ihres Geraeteumfelds verifizieren.
        $liveSuggestions = $this->suggestAIFields($row, $type, $this->getScopedIpsVariables($row));
        $liveIndex = [];
        foreach ($liveSuggestions as $live) $liveIndex[$live['property']] = $live;
        $used = [];
        $changed = 0;
        foreach ($preview['suggestions'] as $suggestion) {
            if (!is_array($suggestion) || ($suggestion['confidence'] ?? '') !== 'Hoch') continue;
            $property = (string)($suggestion['property'] ?? '');
            if (!in_array($property, self::AI_TYPE_FIELDS[$type], true)) continue;
            $field = self::AI_VARIABLE_FIELDS[$property];
            $candidate = (int)($suggestion['candidateId'] ?? 0);
            if (($liveIndex[$property]['confidence'] ?? '') !== 'Hoch'
                || (int)($liveIndex[$property]['candidateId'] ?? 0) !== $candidate) continue;
            if ((int)($rows[$index][$field] ?? 0) > 0 || $candidate <= 0 || isset($used[$candidate])
                || !function_exists('IPS_VariableExists') || !IPS_VariableExists($candidate)) continue;
            $used[$candidate] = true;
            $rows[$index][$field] = $candidate;
            $changed++;
        }
        if ($changed === 0) {
            echo 'Keine eindeutigen, freien Variablen zu uebernehmen. Unsichere Treffer bitte manuell pruefen.';
            return;
        }
        $rows[$index]['aiType'] = $type;
        $original = $this->loadRoomsCatalog($editId, 'EDIT');
        if ($original === []) {
            echo 'RoomsCatalogEdit nicht lesbar; keine Aenderung gespeichert.';
            return;
        }
        $updated = $this->rebuildRoomsCatalogFromEntries($rows, $original);
        $errors = [];
        $this->findLostCatalogFields($original, $updated, 'RoomsCatalogEdit', $errors);
        if ($errors !== []) {
            echo 'Vorschlaege nicht gespeichert: ' . implode('; ', array_slice($errors, 0, 3));
            return;
        }
        if (IPS_SetScriptContent($editId, "<?php\nreturn " . var_export($updated, true) . ";\n") === false) {
            echo 'RoomsCatalogEdit konnte nicht geschrieben werden.';
            return;
        }
        $this->clearSuggestionPreview();
        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
        echo $changed . ' eindeutige Variablenvorschlaege in RoomsCatalogEdit uebernommen (PROD unveraendert).';
    }

    private function singleSelectedDevice(): ?array
    {
        $found = null;
        foreach ($this->getRuntimeEntries() as $index => $row) {
            if (!is_array($row) || empty($row['selected'])) continue;
            if ($found !== null) return null;
            $found = ['index' => $index, 'row' => $row];
        }
        return $found;
    }

    private function suggestionFingerprint(array $row): string
    {
        foreach (['selected','rowColor','kiStatus','diffKeys','editable','ipsObjectIds','ipsInstanceIds'] as $field) unset($row[$field]);
        ksort($row);
        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function clearSuggestionPreview(): void
    {
        $this->WriteAttributeString('SuggestionPreview', '{}');
        $this->UpdateFormField('Suggestions', 'values', '[]');
    }

    /** Keine globale IPS-Suche: nur eine bestehende zugehörige Instanz/Kategorie. */
    private function getScopedIpsVariables(array $row): array
    {
        foreach (['IPS_GetObject','IPS_GetChildrenIDs','IPS_GetVariable','IPS_VariableExists'] as $api) {
            if (!function_exists($api)) return [];
        }
        $anchorFields = array_merge(
            ['state','toggle','value','set','wert','stellung','ist','soll','eingestellt','statusId','controlId','tiltId','id','var'],
            array_values(self::AI_VARIABLE_FIELDS)
        );
        $scopeCounts = [];
        $seenAnchors = [];
        foreach ($anchorFields as $field) {
            $id = (int)($row[$field] ?? 0);
            if ($id <= 0 || isset($seenAnchors[$id]) || !IPS_VariableExists($id)) continue;
            $seenAnchors[$id] = true;
            $object = IPS_GetObject($id);
            if (!is_array($object) || (int)($object['ObjectType'] ?? -1) !== 2) continue;
            $scope = $this->lookupIpsInstanceId($id);
            if ($scope <= 0) $scope = (int)($object['ParentID'] ?? $object['ObjectParentID'] ?? 0);
            if ($scope > 0) $scopeCounts[$scope] = ($scopeCounts[$scope] ?? 0) + 1;
        }
        if ($scopeCounts === []) return [];
        arsort($scopeCounts, SORT_NUMERIC);
        $scope = (int)array_key_first($scopeCounts);
        $queue = [[$scope, 0]];
        $visited = [];
        $found = [];
        while ($queue !== [] && count($visited) < 250) {
            [$parent, $depth] = array_shift($queue);
            if ($parent <= 0 || isset($visited[$parent])) continue;
            $visited[$parent] = true;
            $children = IPS_GetChildrenIDs($parent);
            if (!is_array($children)) continue;
            sort($children, SORT_NUMERIC);
            foreach ($children as $childId) {
                $childId = (int)$childId;
                if ($childId <= 0) continue;
                if (count($found) >= 200) break;
                $object = IPS_GetObject($childId);
                if (!is_array($object)) continue;
                $objectType = (int)($object['ObjectType'] ?? -1);
                if ($objectType === 0 && $depth < 2) {
                    $queue[] = [$childId, $depth + 1];
                    continue;
                }
                if ($objectType !== 2 || !IPS_VariableExists($childId)) continue;
                $variable = IPS_GetVariable($childId);
                if (!is_array($variable)) continue;
                $customProfile = (string)($variable['VariableCustomProfile'] ?? '');
                $profile = $customProfile !== '' ? $customProfile : (string)($variable['VariableProfile'] ?? '');
                $customAction = (int)($variable['VariableCustomAction'] ?? 0);
                $action = $customAction !== 1 && ($customAction > 1 || (int)($variable['VariableAction'] ?? 0) > 0);
                $found[$childId] = [
                    'id' => $childId,
                    'name' => (string)($object['ObjectName'] ?? ''),
                    'ident' => (string)($object['ObjectIdent'] ?? ''),
                    'profile' => $profile,
                    'type' => isset($variable['VariableType']) ? (int)$variable['VariableType'] : -1,
                    'action' => $action
                ];
            }
        }
        return array_values($found);
    }

    private function normalizeSuggestionText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $text);
        return preg_replace('/[^a-z0-9]+/u', '', $text) ?? '';
    }

    private function suggestionScore(array $variable, string $property): int
    {
        $names = self::AI_SUGGESTION_NAMES[$property] ?? [];
        $name = $this->normalizeSuggestionText((string)$variable['name']);
        $ident = $this->normalizeSuggestionText((string)$variable['ident']);
        $profile = $this->normalizeSuggestionText((string)$variable['profile']);
        $score = 0;
        foreach ($names as $alias) {
            if ($alias === $name || ($ident !== '' && $alias === $ident)) {
                $score = max($score, 115);
            } elseif (strlen($alias) >= 6 && (str_contains($name, $alias) || str_contains($ident, $alias))) {
                $score = max($score, 65);
            }
        }
        // Ein Profil alleine reicht bewusst nie zur automatischen Zuordnung.
        $profileHint = match ($property) {
            'power','control' => ['switch','boolean'],
            'brightness' => ['intensity','brightness'],
            'position','targetPosition','tilt' => ['shutter','blind','intensity'],
            'temperature','targetTemperature' => ['temperature'],
            'open' => ['window','contact'],
            'powerWatts' => ['watt','power'],
            'energy' => ['energy','electricity','kwh'],
            'motion','moving' => ['motion','boolean'],
            default => []
        };
        foreach ($profileHint as $hint) {
            if ($profile !== '' && str_contains($profile, $hint)) {
                $score += 15;
                break;
            }
        }
        $type = (int)($variable['type'] ?? -1);
        $compatible = match ($property) {
            'power','open','motion','moving' => in_array($type, [0,1], true),
            'brightness','position','targetPosition','tilt','temperature','targetTemperature','powerWatts','energy' => in_array($type, [1,2], true),
            'control' => in_array($type, [0,1,2], true),
            default => false
        };
        if (!$compatible) return 0;
        $score += 10;
        if ($property === 'control') {
            if (empty($variable['action'])) return 0;
            $score += 20;
        }
        return $score;
    }

    private function suggestAIFields(array $row, string $type, array $variables): array
    {
        $suggestions = [];
        foreach (self::AI_TYPE_FIELDS[$type] as $property) {
            $field = self::AI_VARIABLE_FIELDS[$property];
            $current = (int)($row[$field] ?? 0);
            $ranked = [];
            if ($current <= 0) {
                foreach ($variables as $variable) {
                    $score = $this->suggestionScore($variable, $property);
                    if ($score > 0) $ranked[] = ['variable' => $variable, 'score' => $score];
                }
                usort($ranked, static fn($a,$b): int => $b['score'] <=> $a['score'] ?: $a['variable']['id'] <=> $b['variable']['id']);
            }
            $best = $ranked[0] ?? null;
            $runnerUp = $ranked[1]['score'] ?? 0;
            $confidence = $best === null ? 'Keine' : (
                $best['score'] >= 105 && $best['score'] - $runnerUp >= 25 ? 'Hoch' : 'Pruefen'
            );
            $suggestions[] = [
                'property' => $property,
                'currentId' => $current,
                'candidateId' => (int)($best['variable']['id'] ?? 0),
                'name' => (string)($best['variable']['name'] ?? ''),
                'profile' => (string)($best['variable']['profile'] ?? ''),
                'confidence' => $current > 0 ? 'Bereits belegt' : $confidence,
                'reason' => $current > 0 ? 'Bestehende Zuordnung bleibt erhalten' : (
                    $best === null ? 'Kein passender Name/Typ gefunden' :
                    'Namens-/Profiltreffer, Score ' . $best['score'] .
                        ($runnerUp > 0 ? ', zweiter Treffer ' . $runnerUp : '')
                )
            ];
        }
        return $suggestions;
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
        $keys = array_merge($keys, ['aiType'], array_values(self::AI_VARIABLE_FIELDS), array_values(self::AI_STATE_FIELDS));
        foreach ($entries as $row) {
            if (!is_array($row)) continue;
            foreach (array_keys($row) as $name) {
                if (!is_string($name) || in_array($name, ['rowColor','kiStatus','diffKeys','editable','deletable','ipsObjectIds','ipsInstanceIds'], true)) continue;
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
        $roomsDiff = $this->buildRoomDiffEntries(
            $this->loadConfiguredCatalog('RoomsCatalogScriptID', 'PROD'),
            $this->loadConfiguredCatalog('RoomsCatalogEditScriptID', 'EDIT')
        );
        echo sprintf('PROD: %d, EDIT: %d. Geraete-Unterschiede: %d (neu %d, geaendert %d, geloescht %d). Raum-Aenderungen: %d.',
            count($prod),count($edit),count($diff),$new,$changed,$removed,count($roomsDiff));
    }

    public function ValidateCatalog(): void
    {
        $errors = $this->validateEntries($this->getRuntimeEntries());
        $catalog = $this->loadConfiguredCatalog('RoomsCatalogEditScriptID', 'EDIT');
        $errors = array_merge($errors, $this->validateRoomMetadata($catalog));
        echo $errors === []
            ? 'Katalogpruefung erfolgreich.'
            : count($errors) . " Problem(e):\n" . implode("\n", array_slice($errors,0,35));
    }

    /** Ein bestehender Raum wird ausschließlich auf oberster Raum-Ebene geändert. */
    public function SaveRoomToEdit(string $roomJson): void
    {
        $input = json_decode($roomJson, true);
        if (!is_array($input)) {
            echo 'Raum nicht gespeichert: ungueltige Formularwerte.';
            return;
        }
        $roomKey = trim((string)($input['roomKey'] ?? ''));
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($editId <= 0 || !IPS_ScriptExists($editId)) {
            echo 'Raum nicht gespeichert: RoomsCatalogEdit fehlt.';
            return;
        }
        $catalog = $this->loadRoomsCatalog($editId, 'EDIT');
        $wrapped = isset($catalog['rooms']) && is_array($catalog['rooms']);
        $rooms = $wrapped ? $catalog['rooms'] : $catalog;
        if ($roomKey === 'global' || !isset($rooms[$roomKey]['domains']) || !is_array($rooms[$roomKey]['domains'])) {
            echo 'Raum nicht gespeichert: der Raum-Key ist unbekannt oder reserviert.';
            return;
        }

        $room = $rooms[$roomKey];
        if (array_key_exists('display', $input) && $input['display'] !== null) {
            $display = trim((string)$input['display']);
            if ($display === '') {
                echo 'Raum nicht gespeichert: Anzeigename darf nicht leer sein.';
                return;
            }
            $room['display'] = $display;
        }
        if (array_key_exists('floor', $input) && $input['floor'] !== null) {
            $floor = trim((string)$input['floor']);
            if ($floor === '') unset($room['floor']);
            else $room['floor'] = $floor;
        }
        if (array_key_exists('aliases', $input) && $input['aliases'] !== null) {
            try {
                $room['aliases'] = $this->parseRoomAliases($input['aliases']);
            } catch (InvalidArgumentException $e) {
                echo 'Raum nicht gespeichert: ' . $e->getMessage();
                return;
            }
        }
        $rooms[$roomKey] = $room;
        if ($wrapped) $catalog['rooms'] = $rooms;
        else $catalog = $rooms;
        $errors = array_merge($this->validateRoomMetadata($catalog), $this->validateRoomAliasUniqueness($catalog, $roomKey));
        if ($errors !== []) {
            echo 'Raum nicht gespeichert: ' . implode('; ', array_slice($errors, 0, 3));
            return;
        }
        if (IPS_SetScriptContent($editId, "<?php\nreturn " . var_export($catalog, true) . ";\n") === false) {
            echo 'Raum nicht gespeichert: RoomsCatalogEdit konnte nicht geschrieben werden.';
            return;
        }
        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
        echo 'Raum ' . $roomKey . ' in RoomsCatalogEdit gespeichert (noch nicht produktiv).';
    }

    /** Neue Räume sind zunächst leer; Geräte können danach zugeordnet werden. */
    public function CreateRoom(string $roomJson): void
    {
        $input = json_decode($roomJson, true);
        if (!is_array($input)) {
            echo 'Raum nicht angelegt: ungueltige Formularwerte.';
            return;
        }
        $roomKey = trim((string)($input['roomKey'] ?? ''));
        $display = trim((string)($input['display'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/iD', $roomKey) !== 1
            || in_array(strtolower($roomKey), ['rooms', 'global'], true) || $display === '') {
            echo 'Raum nicht angelegt: gueltigen Raum-Key (Buchstaben/Zahlen/_/-) und Namen eingeben.';
            return;
        }
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($editId <= 0 || !IPS_ScriptExists($editId)) {
            echo 'Raum nicht angelegt: RoomsCatalogEdit fehlt.';
            return;
        }
        $catalog = $this->loadRoomsCatalog($editId, 'EDIT');
        if ($catalog === []) {
            echo 'Raum nicht angelegt: RoomsCatalogEdit ist leer oder nicht lesbar.';
            return;
        }
        $wrapped = isset($catalog['rooms']) && is_array($catalog['rooms']);
        $rooms = $wrapped ? $catalog['rooms'] : $catalog;
        if (array_key_exists($roomKey, $rooms) || array_key_exists($roomKey, $catalog)) {
            echo 'Raum nicht angelegt: Raum-Key existiert bereits.';
            return;
        }
        try {
            $aliases = $this->parseRoomAliases($input['aliases'] ?? '');
        } catch (InvalidArgumentException $e) {
            echo 'Raum nicht angelegt: ' . $e->getMessage();
            return;
        }
        $room = ['display' => $display, 'domains' => [], 'aliases' => $aliases];
        $floor = trim((string)($input['floor'] ?? ''));
        if ($floor !== '') $room['floor'] = $floor;
        $rooms[$roomKey] = $room;
        if ($wrapped) $catalog['rooms'] = $rooms;
        else $catalog = $rooms;
        $errors = array_merge($this->validateRoomMetadata($catalog), $this->validateRoomAliasUniqueness($catalog, $roomKey));
        if ($errors !== []) {
            echo 'Raum nicht angelegt: ' . implode('; ', array_slice($errors, 0, 3));
            return;
        }
        if (IPS_SetScriptContent($editId, "<?php\nreturn " . var_export($catalog, true) . ";\n") === false) {
            echo 'Raum nicht angelegt: RoomsCatalogEdit konnte nicht geschrieben werden.';
            return;
        }
        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
        echo 'Neuer Raum ' . $roomKey . ' in RoomsCatalogEdit angelegt.';
    }

    private function loadConfiguredCatalog(string $property, string $mode): array
    {
        $id = $this->ReadPropertyInteger($property);
        return $id > 0 && IPS_ScriptExists($id) ? $this->loadRoomsCatalog($id, $mode) : [];
    }

    private function parseRoomAliases(mixed $input): array
    {
        if (is_array($input)) $parts = $input;
        elseif (is_string($input)) $parts = preg_split('/[,;\r\n]+/u', $input) ?: [];
        else throw new InvalidArgumentException('Aliase muessen eine Textliste sein.');
        $seen = [];
        $aliases = [];
        foreach ($parts as $part) {
            if (!is_string($part)) throw new InvalidArgumentException('Aliase muessen Texte sein.');
            $alias = trim($part);
            if ($alias === '') continue;
            if (mb_strlen($alias, 'UTF-8') > 80) throw new InvalidArgumentException('Ein Alias ist laenger als 80 Zeichen.');
            $normalized = mb_strtolower($alias, 'UTF-8');
            if (isset($seen[$normalized])) continue;
            $seen[$normalized] = true;
            $aliases[] = $alias;
        }
        if (count($aliases) > 20) throw new InvalidArgumentException('Maximal 20 Aliase pro Raum.');
        return $aliases;
    }

    private function roomMap(array $catalog): array
    {
        return isset($catalog['rooms']) && is_array($catalog['rooms']) ? $catalog['rooms'] : $catalog;
    }

    private function validateRoomMetadata(array $catalog): array
    {
        $errors = [];
        foreach ($this->roomMap($catalog) as $key => $room) {
            if ($key === 'global' || !is_array($room) || !isset($room['domains'])) continue;
            if (!is_array($room['domains'])) {
                $errors[] = 'Raum ' . $key . ': domains muss ein Array sein.';
                continue;
            }
            if (isset($room['display']) && !is_string($room['display'])) $errors[] = 'Raum ' . $key . ': display ist kein Text.';
            if (isset($room['floor']) && !is_string($room['floor'])) $errors[] = 'Raum ' . $key . ': floor ist kein Text.';
            if (isset($room['aliases'])) {
                if (!is_array($room['aliases']) && !is_string($room['aliases'])) {
                    $errors[] = 'Raum ' . $key . ': aliases ist keine Liste.';
                } elseif (is_array($room['aliases'])) {
                    foreach ($room['aliases'] as $alias) {
                        if (!is_string($alias)) {
                            $errors[] = 'Raum ' . $key . ': aliases enthaelt einen Nicht-Textwert.';
                            break;
                        }
                    }
                }
            }
        }
        return $errors;
    }

    private function validateRoomAliasUniqueness(array $catalog, string $targetRoom): array
    {
        $rooms = $this->roomMap($catalog);
        $target = $rooms[$targetRoom] ?? [];
        $targetAliases = array_merge([$targetRoom, (string)($target['display'] ?? $targetRoom)],
            $this->parseRoomAliases($target['aliases'] ?? []));
        $normalized = static fn(string $s): string => mb_strtolower(trim($s), 'UTF-8');
        $own = array_fill_keys(array_map($normalized, $targetAliases), true);
        $errors = [];
        foreach ($rooms as $roomKey => $room) {
            if ($roomKey === $targetRoom || $roomKey === 'global'
                || !is_array($room) || !isset($room['domains'])) continue;
            try {
                $otherAliases = is_array($room['aliases'] ?? null) || is_string($room['aliases'] ?? null)
                    ? $this->parseRoomAliases($room['aliases']) : [];
            } catch (InvalidArgumentException $e) {
                continue; // Der gesonderte Metadata-Validator meldet den Altbestand.
            }
            $others = array_merge([(string)$roomKey, (string)($room['display'] ?? $roomKey)], $otherAliases);
            foreach ($others as $name) {
                if (isset($own[$normalized($name)])) {
                    $errors[] = 'Name/Alias "' . $name . '" wird auch von Raum ' . $roomKey . ' verwendet.';
                }
            }
        }
        return array_values(array_unique($errors));
    }

    private function buildRoomListRows(array $catalog): array
    {
        $rooms = $this->roomMap($catalog);
        $deviceCounts = [];
        foreach ($this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($catalog)) as $entry) {
            $key = (string)($entry['roomKey'] ?? '');
            $deviceCounts[$key] = ($deviceCounts[$key] ?? 0) + 1;
        }
        $rows = [];
        foreach ($rooms as $key => $room) {
            if ($key === 'global' || !is_array($room) || !isset($room['domains']) || !is_array($room['domains'])) continue;
            $aliases = $room['aliases'] ?? [];
            $rows[] = [
                'roomKey' => (string)$key,
                'display' => (string)($room['display'] ?? $key),
                'floor' => (string)($room['floor'] ?? ''),
                'aliases' => is_array($aliases) ? implode(', ', array_filter($aliases, 'is_string')) : (string)$aliases,
                'devices' => $deviceCounts[(string)$key] ?? 0,
                'domainsCount' => count($room['domains'])
            ];
        }
        usort($rows, static fn($a, $b): int => strcasecmp($a['display'], $b['display']));
        return $rows;
    }

    private function buildRoomDiffEntries(array $prod, array $edit): array
    {
        $diff = [];
        $prodRooms = $this->roomMap($prod);
        $editRooms = $this->roomMap($edit);
        foreach (array_unique(array_merge(array_keys($prodRooms), array_keys($editRooms))) as $key) {
            $before = $prodRooms[$key] ?? null;
            $after = $editRooms[$key] ?? null;
            if ($key === 'global' || ($before === null && !isset($after['domains']))
                || ($after === null && !isset($before['domains']))) continue;
            $oldMeta = is_array($before) ? $before : [];
            $newMeta = is_array($after) ? $after : [];
            unset($oldMeta['domains'], $newMeta['domains']);
            if ($oldMeta == $newMeta) continue;
            $room = $after ?? $before;
            $diff[] = [
                'roomKey' => (string)$key,
                'display' => (string)($room['display'] ?? $key),
                'floor' => (string)($room['floor'] ?? ''),
                'aliases' => is_array($room['aliases'] ?? null) ? implode(', ', $room['aliases']) : (string)($room['aliases'] ?? ''),
                'change' => $before === null ? 'Neu' : ($after === null ? 'Entfernt' : 'Geaendert')
            ];
        }
        return $diff;
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

            $aiType = trim((string)($row['aiType'] ?? ''));
            if ($aiType !== '') {
                if (!array_key_exists($aiType, self::AI_TYPE_FIELDS)) {
                    $errors[] = $key . ': unbekannter KI-Geraetetyp ' . $aiType;
                } else {
                    foreach (self::AI_TYPE_FIELDS[$aiType] as $property) {
                        $field = self::AI_VARIABLE_FIELDS[$property];
                        $id = (int)($row[$field] ?? 0);
                        if ($id < 0 || ($id > 0 && !IPS_VariableExists($id))) {
                            $errors[] = $key . ': ungueltige KI-Variable ' . $property . ' (' . $id . ')';
                        }
                    }
                    if ($aiType === 'blind' && !empty($row['kiEnabled'])) {
                        $open = trim((string)($row['aiOpenValue'] ?? ''));
                        $closed = trim((string)($row['aiClosedValue'] ?? ''));
                        if ($open === '' || $closed === '' || $this->parseAIStateValue($open) === $this->parseAIStateValue($closed)) {
                            $errors[] = $key . ': Offen/Geschlossen-Werte der Jalousie fehlen oder sind gleich.';
                        }
                    }
                }
            }
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

    private function inferredAIType(array $row): string
    {
        return match ((string)($row['domain'] ?? '')) {
            'jalousie' => 'blind',
            'heizung' => 'heating',
            'lueftung' => 'fan',
            'licht' => (string)($row['group'] ?? '') === 'dimmers' ? 'dimmer' : 'light',
            'energie' => 'energy',
            default => 'generic'
        };
    }

    /** Vorschläge aus bisherigen Feldern, ohne die bestehende Konfiguration zu verändern. */
    private function legacyAIVariable(array $row, string $property): int
    {
        $candidates = match ($property) {
            'power' => ['state', 'value'],
            'brightness' => ['value'],
            'position' => ['wert', 'stellung', 'value'],
            'targetPosition' => ['set'],
            'tilt' => ['tiltId'],
            'moving' => ['moving'],
            'control' => ['toggle', 'set', 'controlId', 'soll', 'eingestellt'],
            'temperature' => ['ist'],
            'targetTemperature' => ['soll', 'eingestellt'],
            'open' => ['state', 'value'],
            'powerWatts' => ['leistung'],
            'energy' => ['verbrauch'],
            'motion' => ['state'],
            default => []
        };
        foreach ($candidates as $candidate) {
            $value = $row[$candidate] ?? null;
            if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value > 0) {
                return (int)$value;
            }
        }
        return 0;
    }

    /** Zusatzfelder sind virtuelle Editorwerte; ai bleibt die einzige persistente Quelle. */
    private function withAIEditorFields(array $row, array $cfg): array
    {
        $ai = isset($cfg['ai']) && is_array($cfg['ai']) ? $cfg['ai'] : [];
        $row['aiType'] = is_string($ai['type'] ?? null) ? $ai['type'] : '';
        $variables = isset($ai['variables']) && is_array($ai['variables']) ? $ai['variables'] : [];
        foreach (self::AI_VARIABLE_FIELDS as $property => $field) {
            $configured = $variables[$property] ?? null;
            $row[$field] = is_numeric($configured) ? (int)$configured
                : $this->legacyAIVariable($row, $property);
        }
        $mapping = isset($ai['stateMapping']) && is_array($ai['stateMapping']) ? $ai['stateMapping'] : [];
        foreach (self::AI_STATE_FIELDS as $property => $field) {
            $value = $mapping[$property] ?? null;
            $row[$field] = $value === null ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value);
        }
        return $row;
    }

    private function parseAIStateValue(string $value): mixed
    {
        $value = trim($value);
        if (strcasecmp($value, 'true') === 0) return true;
        if (strcasecmp($value, 'false') === 0) return false;
        if (preg_match('/^-?\d+$/D', $value) === 1) return (int)$value;
        if (is_numeric($value)) return (float)$value;
        return $value;
    }

    private function mergeAIEditorFields(array $row, array $cfg): array
    {
        $type = trim((string)($row['aiType'] ?? ''));
        // Ohne explizite Typwahl bleibt ein Legacy-Gerät ein Legacy-Gerät.
        if ($type === '' || !array_key_exists($type, self::AI_TYPE_FIELDS)) return $cfg;

        $ai = isset($cfg['ai']) && is_array($cfg['ai']) ? $cfg['ai'] : [];
        $ai['version'] = max(1, (int)($ai['version'] ?? 1));
        $ai['type'] = $type;
        $variables = isset($ai['variables']) && is_array($ai['variables']) ? $ai['variables'] : [];
        foreach (self::AI_VARIABLE_FIELDS as $property => $field) {
            if (!in_array($property, self::AI_TYPE_FIELDS[$type], true)) continue;
            if (!array_key_exists($field, $row)) continue;
            $value = (int)$row[$field];
            if ($value > 0 || array_key_exists($property, $variables)) {
                $variables[$property] = $value;
            }
        }
        if ($variables !== [] || array_key_exists('variables', $ai)) $ai['variables'] = $variables;

        $mapping = isset($ai['stateMapping']) && is_array($ai['stateMapping']) ? $ai['stateMapping'] : [];
        foreach (self::AI_STATE_FIELDS as $property => $field) {
            if (!array_key_exists($field, $row)) continue;
            $value = trim((string)$row[$field]);
            if ($value !== '') $mapping[$property] = $this->parseAIStateValue($value);
        }
        if ($mapping !== [] || array_key_exists('stateMapping', $ai)) $ai['stateMapping'] = $mapping;
        $cfg['ai'] = $ai;
        return $cfg;
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
            if (is_array($entry)) unset($entry['rowColor'], $entry['kiStatus'], $entry['diffKeys'], $entry['editable'], $entry['ipsObjectIds'], $entry['ipsInstanceIds']);
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
                // IPSList liefert je nach Symcon-Version nur sichtbare Felder.
                // Unbekannte Metadaten (insbesondere verschachteltes ai) nie löschen.
                $all[$index[$rk]] = array_replace($all[$index[$rk]], $row);
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
        $editEntries = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($editCatalog));
        $errors = array_merge($this->validateEntries($editEntries), $this->validateRoomMetadata($editCatalog));
        $roundTrip = $this->rebuildRoomsCatalogFromEntries($editEntries, $editCatalog);
        $this->findLostCatalogFields($editCatalog, $roundTrip, 'RoomsCatalogEdit', $errors);
        if ($errors !== []) {
            echo 'Veröffentlichung abgebrochen: ' . implode('; ',array_slice($errors,0,4));
            return;
        }
        if ($editCatalog === []) {
            $this->logDebug('SaveEditToProd: Edit-Katalog leer oder ungültig');
            return;
        }

        // Keine Migration: die geprüfte EDIT-Quelle wird unverändert in PROD geschrieben.
        $php = "<?php\nreturn " . var_export($editCatalog, true) . ";\n";
        $originalProd = IPS_GetScriptContent($prodId);
        $backup = IPS_CreateScript(0);
        if (!is_int($backup) || $backup <= 0) {
            echo 'Veröffentlichung abgebrochen: Backup konnte nicht angelegt werden.';
            return;
        }
        IPS_SetParent($backup, IPS_GetParent($prodId));
        IPS_SetName($backup, 'RoomsCatalog_Backup_' . date('Ymd_His') . '_' . $backup);
        IPS_SetScriptContent($backup, $originalProd);
        if (IPS_GetScriptContent($backup) !== $originalProd) {
            echo 'Veröffentlichung abgebrochen: Backup konnte nicht verifiziert werden.';
            return;
        }
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
        // Geklontes Gerät darf niemals heimlich die KI-Variablen des Originals nutzen.
        unset($clone['ai']);
        foreach (self::AI_VARIABLE_FIELDS as $virtualField) $clone[$virtualField] = 0;
        foreach (self::AI_STATE_FIELDS as $virtualField) $clone[$virtualField] = '';
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

        $editScriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $editCatalog = $this->loadConfiguredCatalog('RoomsCatalogEditScriptID', 'EDIT');
        $prodCatalog = $this->loadConfiguredCatalog('RoomsCatalogScriptID', 'PROD');
        $roomRows = $this->buildRoomListRows($editCatalog);
        $roomDiffRows = $this->buildRoomDiffEntries($prodCatalog, $editCatalog);
        $suggestionPreview = json_decode($this->ReadAttributeString('SuggestionPreview'), true);
        $suggestionRows = is_array($suggestionPreview) && is_array($suggestionPreview['suggestions'] ?? null)
            ? $suggestionPreview['suggestions'] : [];

        [$roomOptions, $domainOptions, $groupOptions] = $this->buildFilterOptionsFromEntries(array_merge($entriesProd, $entriesEdit));
        $knownRooms = array_column($roomOptions, 'value');
        foreach ($roomRows as $roomRow) {
            if (!in_array($roomRow['roomKey'], $knownRooms, true)) {
                $roomOptions[] = ['caption' => $roomRow['display'] . ' (' . $roomRow['roomKey'] . ')', 'value' => $roomRow['roomKey']];
            }
        }

        $visibleProd    = $this->filterByAI($this->applyFilters($entriesEdit, $filterRoom, $filterDomain, $filterGroup), $filterAI);
        $visibleProd    = array_map(fn(array $r): array => $this->decorateRow($r), $visibleProd);
        $diffEntriesAll = $this->buildDiffEntries($entriesProd, $entriesEdit);
        $visibleDiff    = $this->filterByAI($this->applyFilters($diffEntriesAll, $filterRoom, $filterDomain, $filterGroup), $filterAI);
        $visibleDiff    = array_map(fn(array $r): array => $this->decorateIpsIds($r), $visibleDiff);

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
                    'type' => 'ExpansionPanel',
                    'caption' => 'V3.1: Raeume, Etagen und alternative Namen',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Jeden Raum nur einmal bearbeiten. Raum-Key bleibt unveraendert, damit bestehende Geraetezuordnungen erhalten bleiben.'],
                        [
                            'type' => 'List', 'name' => 'Rooms', 'caption' => 'Raeume im RoomsCatalogEdit',
                            'rowCount' => 12, 'loadValuesFromConfiguration' => false,
                            'add' => false, 'delete' => false,
                            'sort' => ['column' => 'display', 'direction' => 'ascending'],
                            'columns' => [
                                ['caption'=>'Raum-Key','name'=>'roomKey','width'=>'145px','save'=>false,'quickFilter'=>true],
                                ['caption'=>'Raumname','name'=>'display','width'=>'auto','edit'=>['type'=>'ValidationTextBox'],'quickFilter'=>true],
                                ['caption'=>'Etage','name'=>'floor','width'=>'85px','edit'=>['type'=>'ValidationTextBox'],'quickFilter'=>true],
                                ['caption'=>'Alternative Namen (Komma getrennt)','name'=>'aliases','width'=>'260px','edit'=>['type'=>'ValidationTextBox']],
                                ['caption'=>'Geraete','name'=>'devices','width'=>'75px','save'=>false],
                                ['caption'=>'Bereiche','name'=>'domainsCount','width'=>'75px','save'=>false]
                            ],
                            'values' => $roomRows,
                            // $Rooms ist in Symcon eine IPSList-Zeile, kein PHP-Array.
                            'onEdit' => 'RCC_SaveRoomToEdit($id, json_encode(array_filter(['
                                . '\'roomKey\' => ($Rooms["roomKey"] ?? null), '
                                . '\'display\' => ($Rooms["display"] ?? null), '
                                . '\'floor\' => ($Rooms["floor"] ?? null), '
                                . '\'aliases\' => ($Rooms["aliases"] ?? null)'
                                . '], static fn($v) => $v !== null), JSON_INVALID_UTF8_SUBSTITUTE));'
                        ],
                        ['type'=>'Label','caption'=>'Neuer Raum (wird ohne Geraete nur in RoomsCatalogEdit angelegt):'],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'ValidationTextBox','name'=>'NewRoomKey','caption'=>'Neuer Raum-Key'],
                            ['type'=>'ValidationTextBox','name'=>'NewRoomDisplay','caption'=>'Raumname'],
                            ['type'=>'ValidationTextBox','name'=>'NewRoomFloor','caption'=>'Etage (z.B. EG, OG, KG)'],
                            ['type'=>'ValidationTextBox','name'=>'NewRoomAliases','caption'=>'Alternative Namen']
                        ]],
                        ['type'=>'Button','caption'=>'NEUEN RAUM IM EDIT ANLEGEN',
                            'onClick'=>'RCC_CreateRoom($id, json_encode(['
                                . '\'roomKey\' => ($NewRoomKey ?? \'\'), '
                                . '\'display\' => ($NewRoomDisplay ?? \'\'), '
                                . '\'floor\' => ($NewRoomFloor ?? \'\'), '
                                . '\'aliases\' => ($NewRoomAliases ?? \'\')'
                                . '], JSON_INVALID_UTF8_SUBSTITUTE));']
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
                    'type' => 'ExpansionPanel',
                    'caption' => 'V3.2: Intelligente Variablenvorschlaege',
                    'expanded' => true,
                    'items' => [
                        ['type'=>'Label','caption'=>'Genau ein Geraet oben markieren. Die Analyse durchsucht nur seine bekannte Instanz/Kategorie. Bestehende IDs und KI-Freigaben werden nie ueberschrieben.'],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'Button','caption'=>'MARKIERTES GERAET ANALYSIEREN',
                                'onClick'=>'RCC_AnalyzeSelectedDevice($id);'],
                            ['type'=>'Button','caption'=>'EINDEUTIGE VORSCHLAEGE NACH EDIT UEBERNEHMEN',
                                'confirm'=>'Nur eindeutige, noch leere KI-Variablenbindungen im EDIT-Katalog hinzufuegen? PROD bleibt unveraendert.',
                                'onClick'=>'RCC_ApplySelectedSuggestions($id);']
                        ]],
                        [
                            'type'=>'List','name'=>'Suggestions','caption'=>'Vorschau pro Eigenschaft',
                            'rowCount'=>13,'add'=>false,'delete'=>false,
                            'columns'=>[
                                ['caption'=>'Eigenschaft','name'=>'property','width'=>'135px'],
                                ['caption'=>'Aktuelle ID','name'=>'currentId','width'=>'90px'],
                                ['caption'=>'Vorschlag ID','name'=>'candidateId','width'=>'100px'],
                                ['caption'=>'Gefundene Variable','name'=>'name','width'=>'160px'],
                                ['caption'=>'Profil','name'=>'profile','width'=>'125px'],
                                ['caption'=>'Bewertung','name'=>'confidence','width'=>'100px'],
                                ['caption'=>'Begruendung','name'=>'reason','width'=>'auto']
                            ],
                            'values'=>$suggestionRows
                        ],
                        ['type'=>'Label','caption'=>'Nur eindeutige Treffer werden uebernommen. Unklare Treffer bitte im V3.0-Geraeteeditor manuell auswaehlen. Offen/Geschlossen-Statuswerte werden nie geraten.']
                    ]
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => 'Vorschau: Unterschiede EDIT zu PROD',
                    'expanded' => $diffExpanded || $roomDiffRows !== [],
                    'items'    => [
                        [
                            'type' => 'List', 'name' => 'RoomsDiff',
                            'caption' => 'V3.1: Raum-Aenderungen EDIT zu PROD',
                            'rowCount' => 8, 'add' => false, 'delete' => false,
                            'columns' => [
                                ['caption'=>'Raum-Key','name'=>'roomKey','width'=>'135px'],
                                ['caption'=>'Raumname','name'=>'display','width'=>'auto'],
                                ['caption'=>'Etage','name'=>'floor','width'=>'80px'],
                                ['caption'=>'Alternative Namen','name'=>'aliases','width'=>'250px'],
                                ['caption'=>'Aenderung','name'=>'change','width'=>'110px']
                            ],
                            'values' => $roomDiffRows
                        ],
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
                    if (ctype_digit($trim)) {
                        $v = (int)$trim;
                    }
                }
            }

            if (is_array($v)) {
                $enc = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $row[$k] = $enc !== false ? $enc : '';
            } elseif (is_bool($v) || is_int($v) || is_float($v) || is_string($v)) {
                $row[$k] = $v;
            }
        }

        return $this->withAIEditorFields($row, $cfg);
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
     * Erhält die vorhandene Root-Form: normale Raum-Keys oder bestehenden
     * 'rooms'-Wrapper. Es findet keine automatische Konvertierung statt.
     */
    private function rebuildRoomsCatalogFromEntries(array $entries, array $existingCatalog): array
    {
        $rooms = [];
        $hasRoomsWrapper = isset($existingCatalog['rooms']) && is_array($existingCatalog['rooms']);
        $existingRoomsMap = $hasRoomsWrapper ? $existingCatalog['rooms'] : $existingCatalog;
        $virtualAIFields = array_merge(
            ['aiType'], array_values(self::AI_VARIABLE_FIELDS), array_values(self::AI_STATE_FIELDS)
        );

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
                if (isset($existingRoomsMap[$roomKey]['floor'])) {
                    // Etagenpflege kommt in V3.1; gerätespezifisches 'floor'
                    // darf die Raum-Etage in V3.0 nicht überschreiben.
                    $rooms[$roomKey]['floor'] = $existingRoomsMap[$roomKey]['floor'];
                } elseif ($roomFloor !== '') {
                    $rooms[$roomKey]['floor'] = $roomFloor;
                }
            }

            if (!isset($rooms[$roomKey]['domains'][$domain])) {
                $rooms[$roomKey]['domains'][$domain] = [];
            }

            // Mit dem ursprünglichen Leaf starten: ein List-Callback darf Felder
            // ausblenden, aber dadurch niemals aus der Konfiguration entfernen.
            $baseDomain = $existingRoomsMap[$roomKey]['domains'][$domain] ?? [];
            $baseCfg = ($domain === 'heizung' || $domain === 'jalousie')
                ? ($baseDomain[$key] ?? [])
                : ($baseDomain[$group][$key] ?? []);
            $cfg = is_array($baseCfg) ? $baseCfg : [];
            foreach ($row as $k => $v) {
                if (in_array($k, ['selected', 'roomKey', 'roomLabel', 'floor', 'domain', 'group', 'key', 'rowColor','kiStatus','diffKeys','editable','deletable','ipsObjectIds','ipsInstanceIds'], true)
                    || in_array($k, $virtualAIFields, true)) {
                    continue;
                }

                if (array_key_exists($k, $cfg) && $this->editorValueMatchesOriginal($v, $cfg[$k])) {
                    continue;
                }

                if (is_string($v)) {
                    $tv = trim($v);
                    // Strings, die zufällig mit '['/'{' beginnen, bleiben Strings.
                    if (!is_string($cfg[$k] ?? null) && $tv !== '' && ($tv[0] === '{' || $tv[0] === '[')) {
                        $decoded = json_decode($tv, true);
                        if (is_array($decoded)) {
                            $v = $decoded;
                        }
                    }
                }

                $cfg[$k] = $v;
            }
            $cfg = $this->mergeAIEditorFields($row, $cfg);

            if ($domain === 'heizung' || $domain === 'jalousie') {
                $rooms[$roomKey]['domains'][$domain][$key] = $cfg;
            } else {
                if (!isset($rooms[$roomKey]['domains'][$domain][$group])) {
                    $rooms[$roomKey]['domains'][$domain][$group] = [];
                }
                $rooms[$roomKey]['domains'][$domain][$group][$key] = $cfg;
            }
        }

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

        if ($hasRoomsWrapper) {
            $newCatalog = $existingCatalog;
            $newCatalog['rooms'] = $rooms;
            // Bestehende Metadaten im Wrapper einschließlich leerer Räume bewahren.
            foreach ($existingRoomsMap as $roomKey => $roomCfg) {
                if (!array_key_exists($roomKey, $newCatalog['rooms'])
                    && (!is_array($roomCfg) || !isset($roomCfg['domains']) || $roomCfg['domains'] === [])) {
                    $newCatalog['rooms'][$roomKey] = $roomCfg;
                }
            }
            return $newCatalog;
        }

        $roomKeys  = array_keys($rooms);
        $newCatalog = [];

        foreach ($existingCatalog as $k => $v) {
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

    private function editorValueMatchesOriginal(mixed $value, mixed $original): bool
    {
        if ($value === $original) return true;
        if (is_array($original) && is_string($value)) {
            return json_decode($value, true) === $original;
        }
        if (is_string($original) && is_numeric($original) && (is_int($value) || is_float($value))) {
            return (string)$value === $original;
        }
        return false;
    }

    /** Erlaubt additive Felder, verbietet aber jeden Verlust aus dem EDIT-Katalog. */
    private function findLostCatalogFields(mixed $source, mixed $roundTrip, string $path, array &$errors): void
    {
        if (count($errors) >= 40) return;
        if (is_array($source)) {
            if (!is_array($roundTrip)) {
                $errors[] = $path . ': Array beim Speichern verloren.';
                return;
            }
            foreach ($source as $key => $value) {
                $childPath = $path . '.' . (string)$key;
                if (!array_key_exists($key, $roundTrip)) {
                    $errors[] = $childPath . ': Feld beim Speichern verloren.';
                } else {
                    $this->findLostCatalogFields($value, $roundTrip[$key], $childPath, $errors);
                }
                if (count($errors) >= 40) return;
            }
        } elseif ($source !== $roundTrip) {
            $errors[] = $path . ': Wert oder Datentyp beim Speichern veraendert.';
        }
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
        $visibleDiff = array_map(fn(array $row): array => $this->decorateIpsIds($row), $visibleDiff);

        // Das Aktualisieren der Listen lässt ExpansionPanel-/Filterzustand intakt.
        $this->UpdateFormField('Entries', 'values', json_encode($visible, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('EntriesEdit', 'values', json_encode($visibleDiff, JSON_INVALID_UTF8_SUBSTITUTE));

        $editCatalog = $this->loadConfiguredCatalog('RoomsCatalogEditScriptID', 'EDIT');
        $prodCatalog = $this->loadConfiguredCatalog('RoomsCatalogScriptID', 'PROD');
        $roomRows = $this->buildRoomListRows($editCatalog);
        $roomDiffRows = $this->buildRoomDiffEntries($prodCatalog, $editCatalog);
        $this->UpdateFormField('Rooms', 'values', json_encode($roomRows, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('RoomsDiff', 'values', json_encode($roomDiffRows, JSON_INVALID_UTF8_SUBSTITUTE));
        [$roomOptions] = $this->buildFilterOptionsFromEntries(array_merge($prod, $edit));
        $knownKeys = array_column($roomOptions, 'value');
        foreach ($roomRows as $roomRow) {
            if (!in_array($roomRow['roomKey'], $knownKeys, true)) {
                $roomOptions[] = ['caption'=>$roomRow['display'] . ' (' . $roomRow['roomKey'] . ')', 'value'=>$roomRow['roomKey']];
            }
        }
        $this->UpdateFormField('FilterRoom', 'options', json_encode($roomOptions, JSON_INVALID_UTF8_SUBSTITUTE));

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
        return $this->decorateIpsIds($row);
    }

    /**
     * Tabellen-Hilfsfelder: alle tatsächlich konfigurierten IPS-Objekt-IDs
     * (z.B. state/toggle/soll) und, falls auf dem Server ermittelbar,
     * die zugehörigen IP-Symcon-Instanzen. Beide Spalten sind reine
     * Such-/Anzeigeinformationen, niemals Teil des RoomsCatalog-JSON.
     */
    private function decorateIpsIds(array $row): array
    {
        $objectIds = [];
        $instanceIds = [];
        $knownIdFields = [
            'id', 'var', 'controlId', 'statusId', 'tiltId',
            'state', 'toggle', 'set', 'value', 'wert', 'stellung',
            'ist', 'soll', 'eingestellt'
        ];

        foreach ($row as $name => $value) {
            if (!is_string($name) || is_bool($value) || (!is_int($value) && !is_string($value))) {
                continue;
            }
            // Zusätzliche individuelle *Id-Felder sind zulässig, aber
            // entityId (z.B. "light.kueche") ist kein IP-Symcon-Objekt.
            if (!in_array($name, $knownIdFields, true)
                && ($name === 'entityId' || preg_match('/Id$/D', $name) !== 1)) {
                continue;
            }
            $number = is_int($value) ? $value : (ctype_digit(trim($value)) ? (int)trim($value) : 0);
            if ($number <= 0 || $number > 99999) {
                continue;
            }
            $objectIds[$number] = $number;
            $instance = $this->lookupIpsInstanceId($number);
            if ($instance > 0) {
                $instanceIds[$instance] = $instance;
            }
        }

        $row['ipsObjectIds'] = implode(', ', array_values($objectIds));
        $row['ipsInstanceIds'] = implode(', ', array_values($instanceIds));
        return $row;
    }

    /**
     * Variablen liegen unterhalb einer Instanz oder einer Kategorie.
     * Darum nur existierende IPS-Objekte entlang der Parent-Kette lesen.
     * Wenn keine übergeordnete Instanz existiert, bewusst leer lassen.
     */
    private function lookupIpsInstanceId(int $objectId): int
    {
        if (isset($this->ipsInstanceLookupCache[$objectId])) {
            return $this->ipsInstanceLookupCache[$objectId];
        }
        if (!function_exists('IPS_ObjectExists') || !function_exists('IPS_GetObject')) {
            return 0;
        }

        $current = $objectId;
        $visited = [];
        $result = 0;
        for ($depth = 0; $depth < 30 && $current > 0; $depth++) {
            if (isset($visited[$current]) || !IPS_ObjectExists($current)) {
                break;
            }
            $visited[$current] = true;
            $object = IPS_GetObject($current);
            if (!is_array($object)) {
                break;
            }
            if ((int)($object['ObjectType'] ?? -1) === 1) {
                $result = $current;
                break;
            }
            $current = (int)($object['ObjectParentID'] ?? $object['ParentID'] ?? 0);
        }
        $this->ipsInstanceLookupCache[$objectId] = $result;
        return $result;
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
            ['caption'=>'IPS-Objekt-IDs','name'=>'ipsObjectIds','width'=>'155px','save'=>false,'quickFilter'=>true],
            ['caption'=>'IPS-Instanz-IDs','name'=>'ipsInstanceIds','width'=>'145px','save'=>false,'quickFilter'=>true],
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
        $type = (string)($row['aiType'] ?? '');
        $editorType = array_key_exists($type, self::AI_TYPE_FIELDS) ? $type : $this->inferredAIType($row);
        $typeCaptions = [
            '' => 'Bisheriges Datenmodell (unveraendert)',
            'light' => 'Licht', 'dimmer' => 'Dimmer', 'blind' => 'Jalousie',
            'heating' => 'Heizung', 'window' => 'Fensterkontakt',
            'switch' => 'Steckdose/Schalter', 'energy' => 'Energiezaehler',
            'motion' => 'Bewegungsmelder', 'fan' => 'Lueftung', 'generic' => 'Sonstiges'
        ];
        $variableCaptions = [
            'power' => 'Ein/Aus (Rueckmeldung)', 'brightness' => 'Helligkeit',
            'position' => 'Istposition', 'targetPosition' => 'Sollposition',
            'tilt' => 'Lamellenwinkel', 'moving' => 'Fahrstatus',
            'control' => 'Steuerungsvariable', 'temperature' => 'Isttemperatur',
            'targetTemperature' => 'Solltemperatur', 'open' => 'Fensterstatus',
            'powerWatts' => 'Leistung (W)', 'energy' => 'Verbrauch/Zaehlerstand',
            'motion' => 'Bewegung'
        ];
        $aiItems = [
            ['type'=>'Select','name'=>'aiType','caption'=>'Geraetetyp (V3.0)',
                'options'=>array_map(
                    static fn($value, $caption): array => ['value'=>$value,'caption'=>$caption],
                    array_keys($typeCaptions), array_values($typeCaptions)
                )],
            ['type'=>'Label','caption'=>'Legacy-Felder bleiben bestehen. Die Auswahl eines Typs aktiviert ein zusaetzliches ai-Modell; nach Typwechsel den Dialog erneut oeffnen, um die passenden Felder zu sehen.']
        ];
        $fields = self::AI_TYPE_FIELDS[$editorType] ?? [];
        foreach (array_chunk($fields, 2) as $pair) {
            $controls = [];
            foreach ($pair as $property) {
                $controls[] = ['type'=>'SelectVariable','name'=>self::AI_VARIABLE_FIELDS[$property],
                    'caption'=>$variableCaptions[$property]];
            }
            $aiItems[] = ['type'=>'RowLayout','items'=>$controls];
        }
        $stateFields = $editorType === 'blind' || $editorType === 'window'
            ? ['open'=>'Offen ist', 'closed'=>'Geschlossen ist']
            : (in_array($editorType, ['light','dimmer','switch','fan','motion'], true)
                ? ['on'=>'Aktiv/Ein ist', 'off'=>'Inaktiv/Aus ist'] : []);
        if ($stateFields !== []) {
            $controls = [];
            foreach ($stateFields as $property => $caption) {
                $controls[] = ['type'=>'ValidationTextBox','name'=>self::AI_STATE_FIELDS[$property],
                    'caption'=>$caption . ' (z.B. true/false, 0/100)'];
            }
            $aiItems[] = ['type'=>'RowLayout','items'=>$controls];
            $aiItems[] = ['type'=>'Label','caption'=>'Statuswerte nicht raten: bei unklarer Bedeutung erst in IP-Symcon pruefen.'];
        }
        $items[] = ['type'=>'ExpansionPanel','caption'=>'V3.0: Geraetetyp und KI-Variablen',
            'expanded'=>true,'items'=>$aiItems];

        $hidden = array_merge(['selected','roomKey','roomLabel','floor','domain','group','key','title',
            'synonyms','entityId','kiEnabled','kiReadOnly','rowColor','kiStatus','diffKeys','editable',
            'deletable','ipsObjectIds','ipsInstanceIds','ai','aiType'],
            array_values(self::AI_VARIABLE_FIELDS), array_values(self::AI_STATE_FIELDS));
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
