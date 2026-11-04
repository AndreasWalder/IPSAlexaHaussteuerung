<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/DeviceTechnicalAudit.php';
require_once __DIR__ . '/libs/SafeAutoRepair.php';
require_once __DIR__ . '/libs/DeviceTypeRegistry.php';

/**
 * ============================================================
 * ROOMS CATALOG CONFIGURATOR — IP-Symcon Modul (V3.3.2)
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
 *
 * 2026-11-02: V3.3 — Gerätesuche in ausgewählten IP-Symcon-Wurzeln
 * - Schlägt neue Instanzen mit Raum, Gerätetyp und Variablen vor
 * - Verhindert doppelte Geräte, lässt unsichere Funde zur Prüfung stehen
 * - Übernimmt nur bestätigte Geräte ohne KI-Schreibfreigaben nach EDIT
 *
 * 2026-11-02: V3.3.1 — Vollstaendige Geraeteinventur vor KI V3.4
 * - Modultyp, Variablenprofile, Assoziationen und Aktionen auswerten
 * - Status und Fahraktion als eigenstaendige Eigenschaften speichern
 * - Vollstaendigkeit/Restaufwand inkl. Scan-Limits transparent ausweisen
 * - Vorlagen aus bestaetigten EDIT-Geraeten ohne zweiten Datenbestand ableiten
 *
 * 2026-11-02: V3.3.2 — sichere Sammelbearbeitung und Gesamtauswahl
 * - Alle sichtbaren oder ausdruecklich alle Geraete markieren
 * - Geraetetyp, KI-Berechtigung und offene Pruefung auf markierte Geraete anwenden
 * - Eindeutige fehlende Variablen pro Geraet ergaenzen, Vorschau + EDIT-Hash-Schutz
 */

class RoomsCatalogConfigurator extends IPSModule
{
    // V3.4.4: Typen, editierbare Eigenschaften und Rollen nur noch zentral
    // in libs/DeviceTypeRegistry.php pflegen, nicht in diesem Formular.

    /** Auflösung der übergeordneten Instanz-ID je IP-Symcon-Objekt innerhalb des PHP-Laufs. */
    private array $ipsInstanceLookupCache = [];
    private array $learnedModuleTemplateCache = [];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('RoomsCatalogScriptID', 0);
        $this->RegisterPropertyInteger('RoomsCatalogEditScriptID', 0);
        $this->RegisterPropertyInteger('DiscoveryRootId', 0);
        $this->RegisterPropertyString('DiscoveryExtraRootIds', '');

        $this->RegisterAttributeString('RuntimeEntries', '[]');
        $this->RegisterAttributeString('RuntimeEditHash', '');
        $this->RegisterAttributeString('FilterRoom', '');
        $this->RegisterAttributeString('FilterDomain', '');
        $this->RegisterAttributeString('FilterGroup', '');
        $this->RegisterAttributeString('FilterAI', 'all');
        $this->RegisterAttributeString('SuggestionPreview', '{}');
        $this->RegisterAttributeString('DiscoveryCandidates', '{}');
        $this->RegisterAttributeString('DiscoveryInventory', '{}');
        $this->RegisterAttributeString('DiscoveryAuditReport', 'Noch keine Vollstaendigkeitspruefung.');
        $this->RegisterAttributeString('LegacyMigrationPreview', '{}');
        $this->RegisterAttributeString('BulkDeviceType', 'keep');
        $this->RegisterAttributeString('BulkAIMode', 'keep');
        $this->RegisterAttributeString('BulkSuggestions', 'yes');
        $this->RegisterAttributeString('BulkReview', 'keep');
        $this->RegisterAttributeString('BulkPreview', '{}');
        $this->RegisterAttributeString('MigrationReadinessReport', 'Noch nicht geprüft.');
        $this->RegisterAttributeString('MigrationReadinessDetails', '{}');
        $this->RegisterAttributeString('TechnicalDeviceSnapshot', '{}');
        $this->RegisterAttributeString('TechnicalDevicePreview', '{}');
        $this->RegisterAttributeString('TechnicalDeviceSummary', 'Noch keine technische Geraetepruefung ausgefuehrt.');
        $this->RegisterAttributeString('TechnicalDeviceActionMessage', '');
        $this->RegisterAttributeString('AutoRepairPreview', '{}');
        $this->RegisterAttributeString('AutoRepairNotice', 'Noch kein Diagnoseexport erstellt.');
        $this->RegisterAttributeString('PartialProdPublishPreview', '{}');
        $this->RegisterAttributeString('PartialProdPublishNotice', 'Noch keine bereichsweise PROD-Vorschau ausgefuehrt.');
        $this->RegisterAttributeString('PartialProdPublishRows', '[]');
        $this->RegisterAttributeString('PartialProdSelectedDomain', '');
        $this->RegisterAttributeString('SinglePositionSelectedKey', '');
        $this->RegisterAttributeString('SinglePositionProdPreview', '{}');
        $this->RegisterAttributeString('SinglePositionProdNotice', 'Noch keine einzelne Positionsaenderung geprueft.');
        $this->RegisterAttributeString('CatalogCheckDebugOutput', '');
        $this->RegisterAttributeString('CatalogCheckDebugSummary', 'Noch keine RoomsCatalog-Pruefung ausgefuehrt.');
        $this->RegisterAttributeString('KnxStatusRepairPreview', '{}');
        $this->RegisterAttributeString('KnxStatusRepairSummary', 'Noch keine KNX-Statuskorrektur geprueft.');
        $this->RegisterAttributeString('KnxStatusRepairPreviewText', '');
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

    /**
     * User-requested clean slate for transient checks, inventories and
     * one-time approval previews. This method NEVER writes a catalog script,
     * never changes module settings or KI permissions, and does not undo
     * already saved technical/manual review data in EDIT or PROD.
     */
    public function ResetAllCheckCaches(): void
    {
        $emptyCaches = [
            'SuggestionPreview'=>'{}',
            'DiscoveryCandidates'=>'{}',
            'DiscoveryInventory'=>'{}',
            'DiscoveryAuditReport'=>'Noch keine Vollstaendigkeitspruefung.',
            'LegacyMigrationPreview'=>'{}',
            'BulkPreview'=>'{}',
            'MigrationReadinessReport'=>'Noch nicht geprueft.',
            'MigrationReadinessDetails'=>'{}',
            'TechnicalDeviceSnapshot'=>'{}',
            'TechnicalDevicePreview'=>'{}',
            'TechnicalDeviceSummary'=>'Noch keine technische Geraetepruefung ausgefuehrt.',
            'TechnicalDeviceActionMessage'=>'',
            'AutoRepairPreview'=>'{}',
            'AutoRepairNotice'=>'Noch kein Diagnoseexport erstellt.',
            'CatalogCheckDebugOutput'=>'',
            'CatalogCheckDebugSummary'=>'Noch keine RoomsCatalog-Pruefung ausgefuehrt.',
            'KnxStatusRepairPreview'=>'{}',
            'KnxStatusRepairPreviewText'=>'',
            'KnxStatusRepairSummary'=>'Noch keine KNX-Statuskorrektur geprueft.',
            'PartialProdPublishPreview'=>'{}',
            'PartialProdPublishNotice'=>'Noch keine bereichsweise PROD-Vorschau ausgefuehrt.',
            'PartialProdPublishRows'=>'[]',
            'PartialProdSelectedDomain'=>'',
            'SinglePositionSelectedKey'=>'',
            'SinglePositionProdPreview'=>'{}',
            'SinglePositionProdNotice'=>'Noch keine einzelne Positionsaenderung geprueft.'
        ];
        foreach ($emptyCaches as $name=>$value) {
            $this->WriteAttributeString($name,$value);
        }

        // Only transient choices and selections are reset; the configured
        // RoomsCatalogEdit/PROD IDs and discovery roots stay unchanged.
        foreach ([
            'FilterRoom'=>'', 'FilterDomain'=>'', 'FilterGroup'=>'',
            'FilterAI'=>'all',
            'BulkDeviceType'=>'keep','BulkAIMode'=>'keep',
            'BulkSuggestions'=>'yes','BulkReview'=>'keep'
        ] as $name=>$value) {
            $this->WriteAttributeString($name,$value);
        }
        $this->ipsInstanceLookupCache=[];
        $this->learnedModuleTemplateCache=[];

        // Reconstruct the editor list from the persisted EDIT catalog. This
        // clears pending checkboxes without discarding catalog contents.
        $this->WriteAttributeString('RuntimeEntries','[]');
        $this->WriteAttributeString('RuntimeEditHash','');
        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();

        foreach ([
            'TechnicalDeviceSummary'=>'Noch keine technische Geraetepruefung ausgefuehrt.',
            'TechnicalDeviceSourceSummary'=>'Katalogquellen noch nicht geprueft – bitte technische Pruefung ausfuehren.',
            'TechnicalDeviceActionMessage'=>'',
            'AutoRepairNotice'=>'Noch kein Diagnoseexport erstellt.',
            'CatalogCheckDebugSummary'=>'Noch keine RoomsCatalog-Pruefung ausgefuehrt.',
            'KnxStatusRepairSummary'=>'Noch keine KNX-Statuskorrektur geprueft.',
            'PartialProdPublishNotice'=>'Noch keine bereichsweise PROD-Vorschau ausgefuehrt.',
            'SinglePositionProdNotice'=>'Noch keine einzelne Positionsaenderung geprueft.',
            'MigrationReadinessReport'=>'Noch keine aktuelle Migrationspruefung ausgefuehrt.',
            'DiscoverySummary'=>'Noch keine Geraeteinventur gestartet.',
            'DiscoveryAudit'=>'Noch keine Vollstaendigkeitspruefung.'
        ] as $name=>$label) {
            $this->UpdateFormField($name,'caption',$label);
        }
        foreach ([
            'TechnicalDeviceChecks','TechnicalLegacyDevices','TechnicalDiffRows',
            'Suggestions','DiscoveryInventoryList','DiscoveredDevices','LegacyDevices',
            'PartialProdPublishRows'
        ] as $name) {
            $this->UpdateFormField($name,'values','[]');
        }
        $this->UpdateFormField('TechnicalDevicesPhysicallyChecked','value',false);
        $this->UpdateFormField('FilterAI','value','all');
        $this->UpdateFormField('CatalogCheckDebugOutput','value','');
        $this->UpdateFormField('KnxStatusRepairPreviewText','value','');
        $this->UpdateFormField('PartialProdDomain','value','');
        $this->UpdateFormField('SinglePositionKey','value','');
        foreach ([
            'BulkDeviceType'=>'keep','BulkAIMode'=>'keep',
            'BulkSuggestions'=>'yes','BulkReview'=>'keep'
        ] as $name=>$value) {
            $this->UpdateFormField($name,'value',$value);
        }

        $message='Pruef-Zwischenspeicher zurueckgesetzt: technische Pruefungen, '
            .'Migrationsberichte, Inventur, Vorschauen und Markierungen geloescht. '
            .'RoomsCatalogEdit, RoomsCatalog PROD, Geraetedaten, KI-Freigaben '
            .'und bereits gespeicherte Bestaetigungen sind UNVERAENDERT. '
            .'Bestehende EDIT/PROD-Unterschiede bleiben bestehen.';
        $this->UpdateFormField('ResetCheckCachesNotice','caption',$message);
        $this->logDebug('ResetAllCheckCaches: nur temporaere Pruefattribute geloescht; keine Katalog-Skripte geschrieben.');
        echo $message;
    }

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
        $this->normalizeCurrentFilters($this->getRuntimeEntries(), $this->getProdEntries());
        $this->logDebug('SetRoomFilter: roomKey="' . $roomKey . '"');
        $this->RefreshVisibleLists();
    }

    public function SetDomainFilter(string $domainKey)
    {
        $this->WriteAttributeString('FilterDomain', $domainKey);
        $this->normalizeCurrentFilters($this->getRuntimeEntries(), $this->getProdEntries());
               $this->logDebug('SetDomainFilter: domainKey="' . $domainKey . '"');
        $this->RefreshVisibleLists();
    }

    public function SetGroupFilter(string $groupKey)
    {
        $this->WriteAttributeString('FilterGroup', $groupKey);
        $this->normalizeCurrentFilters($this->getRuntimeEntries(), $this->getProdEntries());
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
        $this->WriteAttributeString('BulkPreview','{}');
        $this->RefreshVisibleLists();
    }

    /** Bewusste Auswahl auch ausserhalb des aktuellen Raum-/Bereichsfilters. */
    public function SetAllSelection(bool $selected): void
    {
        $rows = $this->getRuntimeEntries();
        foreach ($rows as &$row) if (is_array($row)) $row['selected'] = $selected;
        unset($row);
        $this->WriteAttributeString('RuntimeEntries',json_encode($rows,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->WriteAttributeString('BulkPreview','{}');
        $this->RefreshVisibleLists();
    }

    private function bulkSelectionSummary(array $rows): string
    {
        $selected = array_values(array_filter($rows,static fn($row): bool=>is_array($row) && !empty($row['selected'])));
        if ($selected === []) return 'Keine Geraete markiert. Zuerst in der Tabelle markieren oder die Auswahlbuttons benutzen.';
        $common = [];
        foreach (['roomKey'=>'Raum','domain'=>'Bereich','group'=>'Gruppe','aiType'=>'Geraetetyp',
            'kiEnabled'=>'Mit KI','kiReadOnly'=>'Nur abfragen'] as $field=>$label) {
            $values = array_unique(array_map(static fn($row): string=>
                is_bool($row[$field] ?? null) ? (!empty($row[$field]) ? 'Ja' : 'Nein')
                    : (string)($row[$field] ?? ''),$selected));
            if (count($values)===1 && (string)$values[0]!=='') $common[] = $label . '=' . $values[0];
        }
        return count($selected) . ' Geraete markiert (auch ausserhalb des sichtbaren Filters moeglich). '
            . ($common!==[] ? 'Gemeinsam: ' . implode(', ',$common) . '.' : 'Gemischte Eigenschaften.');
    }

    public function SetBulkOption(string $field,string $value): void
    {
        $allowed = match ($field) {
            'BulkDeviceType'=>array_merge(['keep'],array_keys(RCCDeviceTypeRegistry::roles())),
            'BulkAIMode'=>['keep','disabled','read','write'],
            'BulkSuggestions'=>['yes','no'],
            'BulkReview'=>['keep','pending'],
            default=>[]
        };
        if (!in_array($value,$allowed,true)) return;
        $this->WriteAttributeString($field,$value);
        $this->WriteAttributeString('BulkPreview','{}');
        $this->UpdateFormField('BulkPreviewSummary','caption','Einstellungen geaendert: bitte Vorschau neu erstellen.');
    }

    private function bulkOptions(): array
    {
        $type = $this->ReadAttributeString('BulkDeviceType');
        $aiMode = $this->ReadAttributeString('BulkAIMode');
        $suggest = $this->ReadAttributeString('BulkSuggestions');
        $review = $this->ReadAttributeString('BulkReview');
        return [
            'type'=>array_key_exists($type,RCCDeviceTypeRegistry::roles()) ? $type : 'keep',
            'aiMode'=>in_array($aiMode,['disabled','read','write'],true) ? $aiMode : 'keep',
            'suggest'=>$suggest !== 'no',
            'review'=>$review === 'pending' ? 'pending' : 'keep'
        ];
    }

    private function bulkSelectionKeys(): array
    {
        $keys = [];
        foreach ($this->getRuntimeEntries() as $row) {
            if (!is_array($row) || empty($row['selected'])) continue;
            $key = $this->buildCompositeKey($row);
            if ($key !== null) $keys[$key] = true;
        }
        $keys = array_keys($keys);
        sort($keys,SORT_STRING);
        return $keys;
    }

    /** Nur ausdruecklich gewaehlte Aenderungen vom Verlustcheck ausnehmen. */
    private function isAuthorizedBulkChange(string $problem,array $keys,array $options): bool
    {
        foreach ($keys as $key) {
            $parts = explode('|',$key,4);
            if (count($parts)!==4) continue;
            [$room,$domain,$group,$device]=$parts;
            $base = 'RoomsCatalogEdit.' . $room . '.domains.' . $domain . '.'
                . (in_array($domain,['heizung','jalousie'],true) ? '' : $group . '.') . $device;
            if ($options['aiMode']!=='keep'
                && (str_starts_with($problem,$base . '.kiEnabled:')
                    || str_starts_with($problem,$base . '.kiReadOnly:'))) return true;
            if ($options['type']!=='keep' || $options['review']==='pending') {
                if (str_starts_with($problem,$base . '.ai.')) return true;
            }
        }
        return false;
    }

    /** Reine Vorschau: Auswahl, Einstellungen und EDIT-Inhalt werden festgeschrieben. */
    public function PreviewBulkEdit(): void
    {
        $keys = $this->bulkSelectionKeys();
        $options = $this->bulkOptions();
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($keys === [] || $editId<=0 || !IPS_ScriptExists($editId)) {
            echo 'Keine Geraete markiert oder RoomsCatalogEdit fehlt.';
            return;
        }
        if ($options['type']==='keep' && $options['aiMode']==='keep' && $options['review']==='keep') {
            echo 'Bitte mindestens Geraetetyp, KI-Freigabe oder Pruefstatus fuer die Sammelbearbeitung waehlen.';
            return;
        }
        $original = $this->loadRoomsCatalog($editId,'EDIT');
        $flat = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($original));
        $existing = [];
        $unsupported = [];
        foreach ($flat as $row) {
            $key = $this->buildCompositeKey($row);
            if ($key !== null) {
                $existing[$key] = true;
                if (in_array($options['aiMode'],['read','write'],true)
                    && in_array($key,$keys,true) && !$this->isSupportedAIEntry($row)) {
                    $unsupported[]=$key;
                }
            }
        }
        if (array_diff($keys,array_keys($existing))!==[]) {
            echo 'Ein markiertes Geraet ist nicht mehr in EDIT vorhanden. Bitte neu laden.';
            return;
        }
        if ($unsupported!==[]) {
            echo 'KI-Freigabe fuer diesen bisherigen Bereich noch nicht unterstuetzt: '
                . implode(', ',array_slice($unsupported,0,3)) . '. Bitte ohne KI-Freigabe typisieren.';
            return;
        }
        $preview = ['time'=>time(),'editHash'=>hash('sha256',IPS_GetScriptContent($editId)),
            'keys'=>$keys,'options'=>$options];
        $parts = [];
        if ($options['type']!=='keep') $parts[] = 'Geraetetyp=' . $options['type'];
        if ($options['aiMode']!=='keep') $parts[] = 'KI=' . $options['aiMode'];
        if ($options['review']==='pending') $parts[] = 'Pruefung zuruecksetzen';
        if ($options['type']!=='keep' && $options['suggest']) $parts[] = 'eindeutige fehlende Variablen ergaenzen';
        $message = sprintf('Vorschau: %d ausgewaehlte Geraete; %s. Nur EDIT, keine Aenderung an Namen, IDs, Raum oder Legacy-Pfaden. Auswahl: %s%s',
            count($keys),implode('; ',$parts),implode(', ',array_slice($keys,0,10)),
            count($keys)>10 ? ' ...' : '');
        $preview['message']=$message;
        $this->WriteAttributeString('BulkPreview',json_encode($preview,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('BulkPreviewSummary','caption',$message);
        echo $message;
    }

    public function ApplyBulkEdit(): void
    {
        $preview = json_decode($this->ReadAttributeString('BulkPreview'),true);
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if (!is_array($preview) || !is_array($preview['keys'] ?? null)
            || !is_array($preview['options'] ?? null) || ($preview['keys'] ?? []) === []
            || $editId<=0 || !IPS_ScriptExists($editId)
            || time()-(int)($preview['time'] ?? 0)>600
            || ($preview['editHash'] ?? '')!==hash('sha256',IPS_GetScriptContent($editId))
            || $preview['keys']!==$this->bulkSelectionKeys()
            || $preview['options']!==$this->bulkOptions()) {
            echo 'Sammel-Vorschau veraltet: Auswahl/Optionen/EDIT haben sich geaendert. Bitte neu pruefen.';
            return;
        }
        $options = $preview['options'];
        $original = $this->loadRoomsCatalog($editId,'EDIT');
        $rows = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($original));
        $targetKeys = array_fill_keys($preview['keys'],true);
        $processed = 0;
        $suggested = 0;
        foreach ($rows as &$row) {
            $key = $this->buildCompositeKey($row);
            if ($key===null || !isset($targetKeys[$key])) continue;
            if (in_array($options['aiMode'],['read','write'],true) && !$this->isSupportedAIEntry($row)) {
                echo 'Sammelbearbeitung wegen einer unzulaessigen KI-Freigabe abgebrochen.';
                return;
            }
            // Die flache IPSList serialisiert verschachtelte ai-Arrays als JSON-String.
            // Vor dem Ergaenzen von review/source wieder in die Originalform bringen.
            if (array_key_exists('ai',$row) && !is_array($row['ai'])) {
                $decoded = json_decode((string)($row['ai'] ?? ''),true);
                $row['ai'] = is_array($decoded) ? $decoded : [];
            }
            $processed++;
            $oldType = (string)($row['aiType'] ?? '');
            $type = $options['type']==='keep' ? $oldType : (string)$options['type'];
            if ($options['type']!=='keep') {
                $row['aiType'] = $type;
                if ($oldType!==$type) {
                    $row['aiReviewed'] = false;
                    $row['ai']['review'] = ['status'=>'pending',
                        'note'=>'Geraetetyp nach Sammelbearbeitung am Geraet pruefen'];
                }
            }
            if ($options['type']!=='keep' && $options['suggest'] && $type!=='generic') {
                $variables = $this->getScopedIpsVariables($row);
                $used = [];
                foreach (RCCDeviceTypeRegistry::fields() as $field) {
                    if ((int)($row[$field] ?? 0)>0) $used[(int)$row[$field]]=true;
                }
                foreach ($this->suggestAIFields($row,$type,$variables) as $suggestion) {
                    $id = (int)($suggestion['candidateId'] ?? 0);
                    if ($suggestion['confidence']!=='Hoch' || $id<=0 || isset($used[$id])) continue;
                    $row[RCCDeviceTypeRegistry::fields()[$suggestion['property']]]=$id;
                    $used[$id]=true;
                    $suggested++;
                    $row['aiReviewed']=false;
                }
                $statusId = (int)($row['aiStatusId'] ?? $row['aiOpenId'] ?? $row['aiPowerId'] ?? 0);
                foreach ($this->inferStateMapping($variables,$statusId,$type) as $meaning=>$value) {
                    $field = RCCDeviceTypeRegistry::states()[$meaning] ?? null;
                    if ($field===null || trim((string)($row[$field] ?? ''))!=='') continue;
                    $row[$field]=is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
                    $row['aiReviewed']=false;
                }
            }
            if ($options['aiMode']!=='keep') {
                $row['kiEnabled'] = $options['aiMode']!=='disabled';
                $row['kiReadOnly'] = $options['aiMode']!=='write';
            }
            if ($options['review']==='pending' || empty($row['aiReviewed'])) {
                $row['aiReviewed']=false;
                if ($type!=='' && array_key_exists($type,RCCDeviceTypeRegistry::roles())) {
                    $review = is_array($row['ai']['review'] ?? null) ? $row['ai']['review'] : [];
                    $review['status']='pending';
                    unset($review['confirmedAt']);
                    $row['ai']['review']=$review;
                }
            }
            $row['selected']=false;
            unset($targetKeys[$key]);
        }
        unset($row);
        if ($targetKeys!==[] || $processed!==count($preview['keys'])) {
            echo 'Sammelbearbeitung abgebrochen: Eintraege fehlen oder sind nicht eindeutig.';
            return;
        }
        $updated = $this->rebuildRoomsCatalogFromEntries($rows,$original);
        $loss = [];
        $this->findLostCatalogFields($original,$updated,'RoomsCatalogEdit',$loss);
        $loss = array_values(array_filter($loss,fn(string $problem): bool=>
            !$this->isAuthorizedBulkChange($problem,$preview['keys'],$options)));
        if ($loss!==[]) {
            echo 'Sammelbearbeitung abgebrochen: Datenverlust erkannt: ' . implode('; ',array_slice($loss,0,3));
            return;
        }
        if (IPS_SetScriptContent($editId,"<?php\nreturn " . var_export($updated,true) . ";\n")===false) {
            echo 'RoomsCatalogEdit konnte nicht gespeichert werden.';
            return;
        }
        $this->WriteAttributeString('BulkPreview','{}');
        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
        $this->UpdateFormField('BulkPreviewSummary','caption',
            $processed . ' Geraete in EDIT aktualisiert, ' . $suggested . ' eindeutige Variablen ergaenzt. PROD unveraendert.');
        echo $processed . ' Geraete in EDIT aktualisiert, ' . $suggested
            . ' eindeutige Variablen ergaenzt. Bitte in EDIT pruefen; PROD unveraendert.';
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
        if (!array_key_exists($type, RCCDeviceTypeRegistry::roles())) $type = $this->inferredAIType($row);
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
        if (!array_key_exists($type, RCCDeviceTypeRegistry::roles())) return;
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
            if (!in_array($property, RCCDeviceTypeRegistry::roles()[$type], true)) continue;
            $field = RCCDeviceTypeRegistry::fields()[$property];
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
            array_values(RCCDeviceTypeRegistry::fields())
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
        // Ein logisches KNX-Geraet kann Istwert, Sollwert und Aktion auf
        // verschiedenen KNX-Instanzen haben. V3.2 suchte nur in der
        // haeufigsten Instanz und erzeugte so bis zu 79 falsche Warnungen.
        // Ausschliesslich den Instanzen der vorhandenen Anker-IDs folgen.
        if (count($scopeCounts)>16) return [];
        $all=[];
        foreach (array_keys($scopeCounts) as $scope) {
            foreach ($this->getIpsVariablesUnderScope((int)$scope) as $variable) {
                $id=(int)($variable['id'] ?? 0);
                if ($id>0) $all[$id]=$variable;
            }
        }
        return array_values($all);
    }

    /** Gemeinsamer begrenzter Scanner für V3.2 und V3.3. */
    private function getIpsVariablesUnderScope(int $scope): array
    {
        if ($scope <= 0 || !function_exists('IPS_GetObject') || !function_exists('IPS_GetChildrenIDs')
            || !function_exists('IPS_GetVariable') || !function_exists('IPS_VariableExists')) return [];
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
                $associations = [];
                $profileInfo = [];
                if ($profile !== '' && function_exists('IPS_GetVariableProfile')) {
                    try {
                        $profileInfo = IPS_GetVariableProfile($profile);
                        if (!is_array($profileInfo)) $profileInfo = [];
                        foreach (array_slice((array)($profileInfo['Associations'] ?? []), 0, 32) as $association) {
                            if (!is_array($association)) continue;
                            $associations[] = [
                                'value' => $association['Value'] ?? null,
                                'name' => mb_substr((string)($association['Name'] ?? ''), 0, 80)
                            ];
                        }
                    } catch (Throwable $ignored) {
                        // Fehlende/alte Profile blockieren keine Geraeteinventur.
                    }
                }
                $found[$childId] = [
                    'id' => $childId,
                    'name' => (string)($object['ObjectName'] ?? ''),
                    'ident' => (string)($object['ObjectIdent'] ?? ''),
                    'profile' => $profile,
                    'type' => isset($variable['VariableType']) ? (int)$variable['VariableType'] : -1,
                    'action' => $action,
                    'updated' => (int)($variable['VariableUpdated'] ?? 0),
                    'changed' => (int)($variable['VariableChanged'] ?? 0),
                    'associations' => $associations,
                    'unit' => mb_substr((string)($profileInfo['Suffix'] ?? ''), 0, 20),
                    'minimum' => isset($profileInfo['MinValue']) ? (float)$profileInfo['MinValue'] : null,
                    'maximum' => isset($profileInfo['MaxValue']) ? (float)$profileInfo['MaxValue'] : null,
                    'hasPresentation' => !empty($variable['VariableCustomPresentation'])
                        || !empty($variable['VariablePresentation'])
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
        $names = RCCDeviceTypeRegistry::suggestions()[$property] ?? [];
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
        $profileHints = [
            'power'=>['switch','boolean'],'control'=>['switch','boolean'],
            'brightness'=>['intensity','brightness'],
            'position'=>['shutter','blind','intensity'],
            'targetPosition'=>['shutter','blind','intensity'],
            'tilt'=>['shutter','blind','intensity'],
            'temperature'=>['temperature'],'targetTemperature'=>['temperature'],
            'open'=>['window','contact'],'powerWatts'=>['watt','power'],
            'energy'=>['energy','electricity','kwh'],'motion'=>['motion','boolean'],
            'moving'=>['motion','boolean'],'illuminance'=>['illumination','lux'],
            'status'=>['status','shutter','blind'],'action'=>['action','direction','blind'],
            'humidity'=>['humidity','feuchtigkeit'],'alarm'=>['alarm'],
            'batteryLevel'=>['battery','batterie','soc'],
            'waterLeak'=>['leak','wasser'],'windSpeed'=>['wind','speed'],
            'rain'=>['regen','rain'],'online'=>['online','reachable'],
            'locked'=>['locked','verriegelt'],'armed'=>['armed','scharf'],
            'feedIn'=>['feedin','einspeisung'],'selfConsumption'=>['eigenverbrauch'],
            'chargePower'=>['ladeleistung','chargepower'],
            'dischargePower'=>['entladeleistung','dischargepower'],
            'volume'=>['volume','wasser'],'flowRate'=>['flow','durchfluss'],
            'charging'=>['charging','laden']
        ];
        $profileHint = $profileHints[$property] ?? [];
        foreach ($profileHint as $hint) {
            if ($profile !== '' && str_contains($profile, $hint)) {
                $score += 15;
                break;
            }
        }
        $associations = array_map(fn(array $assoc): string =>
            $this->normalizeSuggestionText((string)($assoc['name'] ?? '')),
            (array)($variable['associations'] ?? []));
        if ($property === 'status' && count(array_intersect($associations,
            ['offen','geschlossen','open','closed','opening','closing'])) >= 2) $score += 25;
        if ($property === 'action' && count(array_intersect($associations,
            ['auf','ab','stop','open','close','up','down'])) >= 2) $score += 25;
        $type = (int)($variable['type'] ?? -1);
        $compatible = in_array($type, RCCDeviceTypeRegistry::dataTypes()[$property] ?? [],true);
        if (!$compatible) return 0;
        $score += 10;
        if (in_array($property,['control','action'],true)) {
            if (empty($variable['action'])) return 0;
            $score += 20;
        }
        if (in_array($property,(array)($variable['templateRoles'] ?? []),true)) {
            $score = max($score,170);
        }
        return $score;
    }

    private function suggestAIFields(array $row, string $type, array $variables): array
    {
        $suggestions = [];
        foreach (RCCDeviceTypeRegistry::roles()[$type] as $property) {
            $field = RCCDeviceTypeRegistry::fields()[$property];
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

    /** V3.3: bewusst nur explizit konfigurierte Kategorien/Instanzen durchsuchen. */
    private function discoveryRoots(): array
    {
        $raw = [$this->ReadPropertyInteger('DiscoveryRootId')];
        foreach (preg_split('/[,;\s]+/', $this->ReadPropertyString('DiscoveryExtraRootIds')) ?: [] as $part) {
            if ($part !== '' && ctype_digit($part)) $raw[] = (int)$part;
        }
        $roots = [];
        if (!function_exists('IPS_GetObject') || !function_exists('IPS_ObjectExists')) return [];
        foreach (array_unique($raw) as $id) {
            $id = (int)$id;
            if ($id <= 0 || !IPS_ObjectExists($id)) continue;
            $object = IPS_GetObject($id);
            if (in_array((int)($object['ObjectType'] ?? -1), [0,1], true)) $roots[] = $id;
        }
        return $roots;
    }

    private function knownDiscoveryIds(array $catalog): array
    {
        $known = [];
        foreach ($this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($catalog)) as $row) {
            $source = (int)($row['discoverySourceId'] ?? 0);
            if ($source > 0) $known['source:' . $source] = true;
            foreach (array_merge(
                ['state','toggle','value','set','wert','stellung','ist','soll','eingestellt','statusId','controlId','tiltId','id','var'],
                array_values(RCCDeviceTypeRegistry::fields())
            ) as $field) {
                $id = (int)($row[$field] ?? 0);
                if ($id > 0) $known['var:' . $id] = true;
            }
        }
        return $known;
    }

    private function matchDiscoveryRoom(int $sourceId, array $catalog): string
    {
        $rooms = $this->roomMap($catalog);
        $names = [];
        foreach ($rooms as $roomKey => $room) {
            if ($roomKey === 'global' || !is_array($room) || !isset($room['domains'])) continue;
            $aliases = [$roomKey, (string)($room['display'] ?? $roomKey)];
            if (is_array($room['aliases'] ?? null)) $aliases = array_merge($aliases, $room['aliases']);
            elseif (is_string($room['aliases'] ?? null)) $aliases = array_merge($aliases, $this->parseRoomAliases($room['aliases']));
            foreach ($aliases as $alias) {
                $normalized = $this->normalizeSuggestionText((string)$alias);
                if ($normalized !== '') $names[$normalized][(string)$roomKey] = true;
            }
        }
        $visited = [];
        $current = $sourceId;
        for ($depth=0; $depth<12 && $current>0 && !isset($visited[$current]); $depth++) {
            $visited[$current] = true;
            $object = IPS_GetObject($current);
            if (!is_array($object)) break;
            $candidateNames = [(string)($object['ObjectName'] ?? ''), (string)($object['ObjectIdent'] ?? '')];
            $matched = [];
            foreach ($candidateNames as $candidateName) {
                $normalized = $this->normalizeSuggestionText($candidateName);
                foreach (array_keys($names[$normalized] ?? []) as $roomKey) $matched[$roomKey] = true;
            }
            if (count($matched) === 1) return (string)array_key_first($matched);
            if (count($matched) > 1) return ''; // Mehrdeutig: keine automatische Zuordnung.
            $current = (int)($object['ParentID'] ?? $object['ObjectParentID'] ?? 0);
        }
        return '';
    }

    private function discoverHighProperties(array $variables): array
    {
        $high = [];
        foreach (RCCDeviceTypeRegistry::fields() as $property => $field) {
            $ranked = [];
            foreach ($variables as $variable) {
                $score = $this->suggestionScore($variable, $property);
                if ($score > 0) $ranked[] = $score;
            }
            rsort($ranked, SORT_NUMERIC);
            if (($ranked[0] ?? 0) >= 105 && ($ranked[0] - ($ranked[1] ?? 0)) >= 25) $high[$property] = true;
        }
        return $high;
    }

    private function inferDiscoveredType(array $variables): string
    {
        $high = $this->discoverHighProperties($variables);
        if (isset($high['position']) || isset($high['tilt'])) return 'blind';
        if (isset($high['targetTemperature'])) return 'heating';
        // Ein einzelner numerischer Isttemperaturwert ist kein Thermostat.
        // Das Geraet bekommt erst durch Zielwert/Modulnamen den Typ Heizung.
        if (isset($high['temperature'])) return 'temperature';
        if (isset($high['brightness'])) return 'dimmer';
        if (isset($high['open'])) return 'window';
        if (isset($high['waterLeak'])) return 'water_leak';
        if (isset($high['humidity'])) return 'humidity';
        if (isset($high['windSpeed']) || isset($high['rain'])) return 'weather';
        if (isset($high['locked'])) return 'lock';
        if (isset($high['armed']) || isset($high['alarm'])) return 'alarm';
        if (isset($high['batteryLevel']) && (isset($high['chargePower']) || isset($high['dischargePower']))) return 'battery';
        if (isset($high['flowRate']) || isset($high['volume'])) return 'water_meter';
        if (isset($high['online'])) return 'network';
        if (isset($high['motion'])) return 'motion';
        if (isset($high['powerWatts']) || isset($high['energy'])) {
            return isset($high['power']) || isset($high['control']) ? 'switch' : 'energy';
        }
        if (isset($high['power']) || isset($high['control'])) return 'light';
        return 'generic';
    }

    /** Typvorschlaege fuer alle Standardklassen; Modulinformation vor Namensraten. */
    private function inferDeviceTypeFromMetadata(array $variables, string $moduleName, string $instanceName): array
    {
        $patterns = RCCDeviceTypeRegistry::discoveryPatterns();
        $module = $this->normalizeSuggestionText($moduleName);
        $instance = $this->normalizeSuggestionText($instanceName);
        foreach ([$module,$instance] as $step=>$name) {
            if ($name === '') continue;
            foreach ($patterns as $type=>$words) {
                foreach ($words as $word) {
                    if (str_contains($name,$word)) {
                        return ['type'=>$type,'confidence'=>$step===0 ? 'Hoch' : 'Pruefen',
                            'reason'=>($step===0 ? 'Modultyp ' : 'Geraetename ') . $word];
                    }
                }
            }
        }
        $fallback = $this->inferDiscoveredType($variables);
        return ['type'=>$fallback,'confidence'=>'Pruefen',
            'reason'=>$fallback==='generic' ? 'Typ noch unklar' : 'Variablenmuster'];
    }

    /** Nur explizit bestaetigte Rollen als lernbare Signatur desselben ModuleID. */
    private function learnedModuleTemplate(array $catalog, string $moduleId): array
    {
        if ($moduleId === '' || !function_exists('IPS_GetInstance') || !function_exists('IPS_GetObject')) return [];
        if (isset($this->learnedModuleTemplateCache[$moduleId])) return $this->learnedModuleTemplateCache[$moduleId];
        $types = [];
        $signatures = [];
        foreach ($this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($catalog)) as $row) {
            if (empty($row['aiReviewed']) || !array_key_exists((string)($row['aiType'] ?? ''),RCCDeviceTypeRegistry::roles())) continue;
            $sourceId = (int)($row['discoverySourceId'] ?? 0);
            if ($sourceId <= 0) {
                foreach (array_values(RCCDeviceTypeRegistry::fields()) as $field) {
                    $variableId = (int)($row[$field] ?? 0);
                    if ($variableId > 0) { $sourceId = $this->lookupIpsInstanceId($variableId); break; }
                }
            }
            if ($sourceId <= 0) continue;
            try {
                $instance = IPS_GetInstance($sourceId);
                $seenId = (string)($instance['ModuleInfo']['ModuleID'] ?? '');
                if ($seenId !== $moduleId) continue;
            } catch (Throwable $ignored) { continue; }
            $type = (string)$row['aiType'];
            $types[$type] = true;
            foreach (RCCDeviceTypeRegistry::fields() as $property=>$field) {
                $variableId = (int)($row[$field] ?? 0);
                if ($variableId <= 0) continue;
                $object = IPS_GetObject($variableId);
                if (!is_array($object)) continue;
                $ident = $this->normalizeSuggestionText((string)($object['ObjectIdent'] ?? ''));
                $name = $this->normalizeSuggestionText((string)($object['ObjectName'] ?? ''));
                $signature = $ident !== '' ? 'i:' . $ident : 'n:' . $name;
                if ($signature !== 'n:') $signatures[$property][$signature] = true;
            }
        }
        if (count($types) !== 1) return $this->learnedModuleTemplateCache[$moduleId] = [];
        $roles = [];
        foreach ($signatures as $property=>$values) {
            if (count($values) === 1) $roles[$property] = (string)array_key_first($values);
        }
        return $this->learnedModuleTemplateCache[$moduleId] = [
            'type'=>(string)array_key_first($types),'roles'=>$roles
        ];
    }

    private function withLearnedRoleHints(array $variables, array $template): array
    {
        if (($template['roles'] ?? []) === []) return $variables;
        foreach ($variables as &$variable) {
            $ident = $this->normalizeSuggestionText((string)($variable['ident'] ?? ''));
            $name = $this->normalizeSuggestionText((string)($variable['name'] ?? ''));
            $signature = $ident !== '' ? 'i:' . $ident : 'n:' . $name;
            foreach ($template['roles'] as $role=>$expected) {
                if ($signature === $expected) $variable['templateRoles'][] = $role;
            }
        }
        unset($variable);
        return $variables;
    }

    /** Assoziationen sind die einzige Quelle fuer sichere boolesche/Enum-Bedeutungen. */
    private function inferStateMapping(array $variables, int $statusId, string $type): array
    {
        $variable = null;
        foreach ($variables as $candidate) {
            if ((int)$candidate['id'] === $statusId) { $variable = $candidate; break; }
        }
        if ($variable === null) return [];
        $labels = in_array($type,['blind','window','door'],true)
            ? ['open'=>['offen','open','geoeffnet'],'closed'=>['geschlossen','closed']]
            : ['on'=>['ein','an','on','aktiv'],'off'=>['aus','off','inaktiv']];
        $mapped = [];
        foreach ($variable['associations'] ?? [] as $association) {
            $label = $this->normalizeSuggestionText((string)($association['name'] ?? ''));
            foreach ($labels as $role=>$names) {
                if (!in_array($label,$names,true) || array_key_exists($role,$mapped)) continue;
                $value = $association['value'] ?? null;
                if (is_int($value) || is_float($value) || is_bool($value)) $mapped[$role] = $value;
            }
        }
        return count($mapped) === 2 ? $mapped : [];
    }

    /** Fuer jede Geräteklasse: minimal notwendige Rueckmeldung, keine Schreibpflicht. */
    private function minimumReadingRoles(string $type): array
    {
        return RCCDeviceTypeRegistry::required($type);
    }

    private function roleCoverage(string $type, array $suggestions): array
    {
        $high = [];
        $pending = [];
        foreach ($suggestions as $suggestion) {
            if ($suggestion['confidence'] === 'Hoch' || $suggestion['confidence'] === 'Bereits belegt') {
                $high[$suggestion['property']] = true;
            } elseif ($suggestion['candidateId'] > 0) {
                $pending[] = $suggestion['property'];
            }
        }
        $missing = [];
        foreach ($this->minimumReadingRoles($type) as $alternatives) {
            if (!array_intersect($alternatives,array_keys($high))) {
                $missing[] = implode(' oder ',$alternatives);
            }
        }
        if ($type === 'generic') $missing[] = 'Geraetetyp manuell bestimmen';
        return ['assigned'=>count($high),'pending'=>$pending,'missing'=>$missing];
    }

    private function discoveryTypeLocation(string $type): array
    {
        return RCCDeviceTypeRegistry::location($type);
    }

    private function discoveryDeviceKey(string $title, int $sourceId): string
    {
        $base = substr($this->normalizeSuggestionText($title), 0, 36);
        return ($base !== '' ? $base : 'geraet') . '_' . $sourceId;
    }

    private function discoveryInventorySummary(array $report): string
    {
        $incomplete = 0;
        $unknownRooms = 0;
        $unknownTypes = 0;
        foreach ($report['items'] ?? [] as $item) {
            if (($item['roleMissing'] ?? []) !== []) $incomplete++;
            if (($item['roomKey'] ?? '') === '' && empty($item['known'])) $unknownRooms++;
            if (($item['deviceType'] ?? '') === 'generic') $unknownTypes++;
        }
        $status = !empty($report['truncated'])
            ? 'ACHTUNG: Suchgrenze erreicht, Bestand NICHT vollstaendig!'
            : 'Suchbereich vollstaendig durchlaufen (nicht gleichbedeutend mit vollstaendig konfiguriert).';
        return sprintf('V3.3.1 Inventur: %d Geraete im Suchbereich, %d bereits bekannt, %d neue Kandidaten, '
            . '%d Rollen unvollstaendig, %d neue ohne Raum, %d Typen ungeklart, %d unbehandelte Gruppen/Instanzen. %s',
            (int)($report['devices'] ?? 0),(int)($report['known'] ?? 0),(int)($report['new'] ?? 0),
            $incomplete,$unknownRooms,$unknownTypes,
            (int)($report['excludedGroups'] ?? 0)+(int)($report['withoutVariables'] ?? 0)+(int)($report['disabled'] ?? 0),$status);
    }

    private function discoveryInventoryRows(array $report): array
    {
        $rows = [];
        foreach ((array)($report['items'] ?? []) as $item) {
            $missing = (array)($item['roleMissing'] ?? []);
            $rows[] = [
                'sourceId'=>(int)($item['sourceId'] ?? 0),
                'name'=>(string)($item['name'] ?? ''),
                'moduleName'=>(string)($item['moduleName'] ?? ''),
                'roomKey'=>(string)($item['roomKey'] ?? ''),
                'deviceType'=>(string)($item['deviceType'] ?? 'generic'),
                'knownLabel'=>!empty($item['known']) ? 'Bestand' : 'Neu',
                'variableCount'=>count((array)($item['variableIds'] ?? [])),
                'roleMissingText'=>$missing === [] ? 'Rollen-Vorschlag vorhanden'
                    : 'Pruefen: ' . implode(', ',$missing)
            ];
        }
        return $rows;
    }

    /** READ ONLY: 100%-Status erst wenn jedes gefundene Geraet bestaetigt und zugeordnet ist. */
    public function AuditDiscoveryCoverage(): void
    {
        $inventory = json_decode($this->ReadAttributeString('DiscoveryInventory'),true);
        if (!is_array($inventory) || !is_array($inventory['items'] ?? null)
            || ($inventory['roots'] ?? []) !== $this->discoveryRoots()) {
            echo 'Zuerst eine vollstaendige Suche in V3.3.1 starten.';
            return;
        }
        $catalog = $this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT');
        $flat = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($catalog));
        $unresolved = [];
        $checked = 0;
        foreach ($inventory['items'] as $item) {
            $candidateSource = (int)($item['sourceId'] ?? 0);
            $device = null;
            $knownIds = array_map('intval',(array)($item['variableIds'] ?? []));
            foreach ($flat as $row) {
                if ((int)($row['discoverySourceId'] ?? 0) === $candidateSource) {
                    $device = $row;
                    break;
                }
                foreach (array_merge(['state','toggle','value','set','wert','stellung','ist','soll','statusId','controlId'],
                    array_values(RCCDeviceTypeRegistry::fields())) as $field) {
                    $id = (int)($row[$field] ?? 0);
                    if ($id > 0 && in_array($id,$knownIds,true)) { $device = $row; break 2; }
                }
                if ($device !== null) break;
            }
            if ($device === null) {
                $unresolved[] = 'IPS ' . $candidateSource . ': nicht im EDIT-Katalog';
                continue;
            }
            $checked++;
            $type = (string)($device['aiType'] ?? '');
            if (!array_key_exists($type,RCCDeviceTypeRegistry::roles()) || $type === 'generic') {
                $unresolved[] = 'IPS ' . $candidateSource . ': Geraetetyp noch nicht bestaetigt';
                continue;
            }
            if (empty($device['aiReviewed'])) {
                $unresolved[] = 'IPS ' . $candidateSource . ': Zuordnungen noch nicht am Geraet bestaetigt';
            }
            $bound = [];
            foreach (RCCDeviceTypeRegistry::fields() as $property=>$field) {
                $id = (int)($device[$field] ?? 0);
                if ($id > 0 && (!function_exists('IPS_VariableExists') || IPS_VariableExists($id))) {
                    $bound[$property] = true;
                    if (!in_array($id,$knownIds,true)) {
                        $unresolved[] = 'IPS ' . $candidateSource . ': ' . $property
                            . ' (' . $id . ') liegt ausserhalb der gescannten Quelle';
                    }
                }
            }
            foreach ($this->minimumReadingRoles($type) as $alternatives) {
                if (array_intersect($alternatives,array_keys($bound)) === []) {
                    $unresolved[] = 'IPS ' . $candidateSource . ': Rueckmeldung '
                        . implode('/',$alternatives) . ' fehlt';
                }
            }
            // Position alleine belegt nicht die Bedeutung 0/100; die KI darf nicht raten.
            if (in_array($type,['blind','window','door'],true)) {
                $mapping = $device['aiOpenValue'] ?? null;
                $closed = $device['aiClosedValue'] ?? null;
                $statusId = (int)($device['aiStatusId'] ?? 0);
                if ($statusId <= 0) $statusId = (int)($device['aiOpenId'] ?? 0);
                $profileMapping = $this->inferStateMapping(
                    $this->getIpsVariablesUnderScope($candidateSource),$statusId,$type);
                if (($mapping === null || $mapping === '' || $closed === null || $closed === '')
                    && $profileMapping === []) {
                    $unresolved[] = 'IPS ' . $candidateSource . ': Offen/Geschlossen-Bedeutung pruefen';
                }
            }
        }
        if (!empty($inventory['truncated'])) $unresolved[] = 'Geraetesuche unvollstaendig (Scan-Limit erreicht)';
        if ((int)($inventory['excludedGroups'] ?? 0) > 0) $unresolved[] = 'Grosse Variablengruppen noch nicht klassifiziert';
        if ((int)($inventory['withoutVariables'] ?? 0) > 0) $unresolved[] = 'Geraeteinstanzen ohne lesbare Variablen';
        if ((int)($inventory['disabled'] ?? 0) > 0) $unresolved[] = 'Deaktivierte Instanzen wurden uebersprungen';
        $unresolved = array_values(array_unique($unresolved));
        $total = (int)($inventory['devices'] ?? 0);
        $message = sprintf('Datenstands-Pruefung: %d/%d gefundene Geraete im EDIT-Katalog, %d offene Punkte. ',
            $checked,$total,count($unresolved));
        $message .= $unresolved === []
            ? 'Alle Pruefregeln bestanden; Live-Zustaende und Steuerungen vor V3.4 am System testen.'
            : 'Nicht 100% bereit: ' . implode(' | ',array_slice($unresolved,0,20));
        $this->WriteAttributeString('DiscoveryAuditReport',$message);
        $this->UpdateFormField('DiscoveryAudit','caption',$message);
        echo $message;
    }

    /** Ausfuehrliche Vorschau ohne Livewerte, damit die Feldbedeutungen kontrolliert werden koennen. */
    public function ShowDiscoveryDetails(int $sourceId): void
    {
        $snapshot = json_decode($this->ReadAttributeString('DiscoveryInventory'),true);
        foreach ((array)($snapshot['items'] ?? []) as $item) {
            if ((int)($item['sourceId'] ?? 0) !== $sourceId) continue;
            $lines = [sprintf('IPS-Instanz %d: %s; Modul %s (%s); Typ %s; Raum %s',
                $sourceId,$item['name'],$item['moduleName'],$item['moduleId'],
                $item['deviceType'],$item['roomKey'])];
            foreach ($item['variables'] as $variable) {
                $roles = [];
                foreach ($item['roles'] as $role=>$id) {
                    if ((int)$id === (int)$variable['id']) $roles[] = $role;
                }
                $associations = [];
                foreach ($variable['associations'] as $assoc) {
                    $associations[] = (string)$assoc['value'] . '=' . $assoc['name'];
                }
                $lines[] = sprintf('%d %s [%s], Typ %d, Profil %s, Aktion %s, Rolle %s, Assoziationen %s',
                    $variable['id'],$variable['name'],$variable['ident'],$variable['type'],
                    $variable['profile'],$variable['action'] ? 'ja' : 'nein',
                    implode(',',$roles),implode('/',$associations));
            }
            echo implode("\n",$lines);
            return;
        }
        echo 'Keine Inventurdaten zu IPS ' . $sourceId . ' vorhanden. Bitte neu scannen.';
    }

    /** Kopierbarer, datensparsamer Inventarbericht fuer die gemeinsame Analyse. */
    public function ExportDiscoveryInventory(): void
    {
        $snapshot = json_decode($this->ReadAttributeString('DiscoveryInventory'),true);
        if (!is_array($snapshot) || !isset($snapshot['items'])) {
            echo 'Bitte zuerst eine Geraeteinventur starten.';
            return;
        }
        echo json_encode($snapshot,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Alte Eintraege kontrolliert typisieren: Analyse schreibt keine Katalogdaten. */
    public function AnalyzeLegacyDevices(): void
    {
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($editId <= 0 || !IPS_ScriptExists($editId)) {
            echo 'RoomsCatalogEdit ist nicht konfiguriert.';
            return;
        }
        $catalog = $this->loadRoomsCatalog($editId,'EDIT');
        $rows = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($catalog));
        $candidates = [];
        $totalLegacy = 0;
        foreach ($rows as $row) {
            if ((string)($row['aiType'] ?? '') !== '') continue;
            $totalLegacy++;
            if (count($candidates) >= 500) continue;
            $variables = $this->getScopedIpsVariables($row);
            $sourceId = 0;
            foreach ($variables as $variable) {
                $sourceId = $this->lookupIpsInstanceId((int)$variable['id']);
                if ($sourceId > 0) break;
            }
            $moduleName = '';
            if ($sourceId > 0 && function_exists('IPS_GetInstance')) {
                try {
                    $instance = IPS_GetInstance($sourceId);
                    $moduleName = (string)($instance['ModuleInfo']['ModuleName'] ?? '');
                } catch (Throwable $ignored) {}
            }
            $typed = $this->inferDeviceTypeFromMetadata($variables,$moduleName,(string)($row['title'] ?? ''));
            $legacyType = $this->inferredAIType($row);
            // Vorhandene Raum-/Bereichssemantik hat Vorrang vor beliebigen Namensfunden.
            $type = $legacyType !== 'generic' ? $legacyType : $typed['type'];
            $scores = $this->suggestAIFields($row,$type,$variables);
            $coverage = $this->roleCoverage($type,$scores);
            $safe = $type !== 'generic' && $coverage['missing'] === [];
            $candidates[] = [
                'selected'=>false,'composite'=>$this->buildCompositeKey($row),
                'title'=>(string)($row['title'] ?? $row['key'] ?? ''),
                'roomKey'=>(string)($row['roomKey'] ?? ''),
                'deviceType'=>$type,'sourceId'=>$sourceId,
                'confidence'=>$safe ? 'Hoch' : 'Pruefen',
                'reason'=>$safe ? 'Mindestens notwendige Rueckmeldung vorhanden' :
                    ($coverage['missing'] !== [] ? 'Offen: ' . implode(', ',$coverage['missing']) : 'Typ/IDs pruefen')
            ];
        }
        $preview = [
            'time'=>time(),'editHash'=>hash('sha256',IPS_GetScriptContent($editId)),
            'truncated'=>$totalLegacy > count($candidates),'totalLegacy'=>$totalLegacy,'rows'=>$candidates
        ];
        $this->WriteAttributeString('LegacyMigrationPreview',json_encode($preview,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('LegacyDevices','values',json_encode($candidates,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        echo sprintf('Legacy-Analyse: %d Geraete, %d angezeigt, %d eindeutige. %s',
            $totalLegacy,count($candidates),count(array_filter($candidates,static fn($r): bool=>$r['confidence']==='Hoch')),
            $preview['truncated'] ? 'Suchgrenze: nicht vollstaendig!' : 'Keine Aenderung an EDIT/PROD.');
    }

    public function UpdateLegacyCandidate(string $rowJson): void
    {
        $row = json_decode($rowJson,true);
        $preview = json_decode($this->ReadAttributeString('LegacyMigrationPreview'),true);
        if (!is_array($row) || !is_array($preview['rows'] ?? null)) return;
        foreach ($preview['rows'] as &$candidate) {
            if ($candidate['composite'] !== (string)($row['composite'] ?? '')) continue;
            if (array_key_exists('selected',$row)) $candidate['selected'] = (bool)$row['selected'];
            $type = (string)($row['deviceType'] ?? '');
            if (array_key_exists($type,RCCDeviceTypeRegistry::roles())) $candidate['deviceType'] = $type;
            break;
        }
        unset($candidate);
        $this->WriteAttributeString('LegacyMigrationPreview',json_encode($preview,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('LegacyDevices','values',json_encode($preview['rows'],JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function SelectLegacyCandidates(bool $select): void
    {
        $preview = json_decode($this->ReadAttributeString('LegacyMigrationPreview'),true);
        if (!is_array($preview['rows'] ?? null)) return;
        foreach ($preview['rows'] as &$row) $row['selected'] = $select && $row['confidence'] === 'Hoch';
        unset($row);
        $this->WriteAttributeString('LegacyMigrationPreview',json_encode($preview,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('LegacyDevices','values',json_encode($preview['rows'],JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function ApplyLegacyCandidates(): void
    {
        $preview = json_decode($this->ReadAttributeString('LegacyMigrationPreview'),true);
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if (!is_array($preview['rows'] ?? null) || $editId <= 0 || !IPS_ScriptExists($editId)
            || time()-(int)($preview['time'] ?? 0) > 600
            || ($preview['editHash'] ?? '') !== hash('sha256',IPS_GetScriptContent($editId))) {
            echo 'Legacy-Vorschau veraltet: bitte erneut analysieren.';
            return;
        }
        $original = $this->loadRoomsCatalog($editId,'EDIT');
        $rows = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($original));
        $indexes = [];
        foreach ($rows as $index=>$row) $indexes[$this->buildCompositeKey($row) ?? ''] = $index;
        $count = 0;
        foreach ($preview['rows'] as $candidate) {
            if (empty($candidate['selected'])) continue;
            $identity = (string)($candidate['composite'] ?? '');
            $type = (string)($candidate['deviceType'] ?? '');
            if (!isset($indexes[$identity]) || !array_key_exists($type,RCCDeviceTypeRegistry::roles())
                || (string)($rows[$indexes[$identity]]['aiType'] ?? '') !== '') {
                echo 'Legacy-Uebernahme abgebrochen: Eintrag/Typ ungueltig. Bitte neu analysieren.';
                return;
            }
            $index = $indexes[$identity];
            $row = $rows[$index];
            $variables = $this->getScopedIpsVariables($row);
            $used = [];
            foreach (RCCDeviceTypeRegistry::fields() as $property=>$field) {
                if ((int)($row[$field] ?? 0) > 0) $used[(int)$row[$field]] = true;
            }
            foreach ($this->suggestAIFields($row,$type,$variables) as $suggestion) {
                $id = (int)$suggestion['candidateId'];
                if ($suggestion['confidence'] !== 'Hoch' || $id <= 0 || isset($used[$id])) continue;
                $row[RCCDeviceTypeRegistry::fields()[$suggestion['property']]] = $id;
                $used[$id] = true;
            }
            $stateId = (int)($row['aiStatusId'] ?? $row['aiOpenId'] ?? $row['aiPowerId'] ?? 0);
            foreach ($this->inferStateMapping($variables,$stateId,$type) as $meaning=>$value) {
                $field = RCCDeviceTypeRegistry::states()[$meaning] ?? null;
                if ($field === null || trim((string)($row[$field] ?? '')) !== '') continue;
                $row[$field] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
            }
            $sourceId = (int)($candidate['sourceId'] ?? 0);
            if ($sourceId > 0 && function_exists('IPS_GetInstance')) {
                try {
                    $instance = IPS_GetInstance($sourceId);
                    $moduleInfo = is_array($instance) ? (array)($instance['ModuleInfo'] ?? []) : [];
                    $row['ai']['source'] = [
                        'instanceId'=>$sourceId,
                        'moduleId'=>(string)($moduleInfo['ModuleID'] ?? ''),
                        'moduleName'=>(string)($moduleInfo['ModuleName'] ?? ''),
                        'variableIds'=>array_column($variables,'id'),
                        'unmappedVariableIds'=>array_values(array_diff(array_column($variables,'id'),array_keys($used)))
                    ];
                } catch (Throwable $ignored) {}
            }
            $row['aiType'] = $type;
            $row['aiReviewed'] = false;
            $row['ai']['review'] = [
                'status'=>'pending',
                'note'=>'Legacy-Zuordnung und Statusbedeutung am Geraet bestaetigen'
            ];
            $rows[$index] = $row;
            $count++;
        }
        if ($count === 0) { echo 'Keine alten Geraete markiert.'; return; }
        $updated = $this->rebuildRoomsCatalogFromEntries($rows,$original);
        $loss = [];
        $this->findLostCatalogFields($original,$updated,'RoomsCatalogEdit',$loss);
        if ($loss !== []) {
            echo 'Legacy-Uebernahme wegen Datenverlust abgebrochen: ' . implode('; ',array_slice($loss,0,3));
            return;
        }
        if (IPS_SetScriptContent($editId,"<?php\nreturn " . var_export($updated,true) . ";\n") === false) {
            echo 'RoomsCatalogEdit konnte nicht gespeichert werden.';
            return;
        }
        $this->WriteAttributeString('LegacyMigrationPreview','{}');
        $this->UpdateFormField('LegacyDevices','values','[]');
        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
        echo $count . ' bestehende Geraete im EDIT-Katalog typisiert; Legacy-Felder und PROD unveraendert. Noch am Geraet pruefen.';
    }

    public function ScanNewDevices(): void
    {
        $roots = $this->discoveryRoots();
        $edit = $this->loadConfiguredCatalog('RoomsCatalogEditScriptID', 'EDIT');
        if ($roots === [] || $edit === []) {
            echo 'Bitte mindestens eine gueltige Suchwurzel und RoomsCatalogEdit konfigurieren.';
            return;
        }
        if (!function_exists('IPS_GetChildrenIDs') || !function_exists('IPS_GetVariable')) {
            echo 'IP-Symcon-Objektfunktionen nicht verfuegbar.';
            return;
        }
        $known = $this->knownDiscoveryIds($edit);
        $queue = array_map(static fn($id): array => [$id,0], $roots);
        $visited = [];
        $candidates = [];
        $skipped = 0;
        $inventory = [];
        $noVariables = 0;
        $excludedGroups = 0;
        $disabled = 0;
        $depthLimited = false;
        while ($queue !== [] && count($visited) < 2500 && count($candidates) < 200) {
            [$id,$depth] = array_shift($queue);
            if ($id <= 0 || isset($visited[$id]) || !IPS_ObjectExists($id)) continue;
            $visited[$id] = true;
            $object = IPS_GetObject($id);
            if (!is_array($object) || !in_array((int)($object['ObjectType'] ?? -1), [0,1], true)) continue;
            if (!empty($object['ObjectIsDisabled'])) {
                $disabled++;
                continue;
            }
            $kind = (int)$object['ObjectType'];
            $children = IPS_GetChildrenIDs($id);
            $childContainers = 0;
            $directVariables = 0;
            foreach (is_array($children) ? $children : [] as $childId) {
                $child = IPS_GetObject((int)$childId);
                $childType = (int)($child['ObjectType'] ?? -1);
                if ($childType === 2) $directVariables++;
                if ($childType === 0 || $childType === 1) {
                    $childContainers++;
                    if ($kind === 0 && $depth < 12) $queue[] = [(int)$childId,$depth+1];
                    elseif ($kind === 0 && $depth >= 12) $depthLimited = true;
                }
            }
            // Instanzen sind Geräte; Kategorien nur als kleine Blatt-Gruppe zulassen.
            if ($kind === 0 && $childContainers > 0) {
                if ($directVariables > 0) $excludedGroups++;
                continue;
            }
            if ($kind === 0 && $directVariables === 0) continue;
            if ($kind === 0 && $directVariables > 12) { $excludedGroups++; continue; }
            if ($kind === 1 && $directVariables === 0 && $childContainers === 0) {
                $noVariables++;
                continue;
            }
            $variables = $this->getIpsVariablesUnderScope($id);
            if ($variables === []) { $noVariables++; continue; }
            $moduleInfo = [];
            if ($kind === 1 && function_exists('IPS_GetInstance')) {
                try {
                    $instance = IPS_GetInstance($id);
                    $moduleInfo = is_array($instance) ? (array)($instance['ModuleInfo'] ?? []) : [];
                } catch (Throwable $ignored) {}
            }
            $moduleId = (string)($moduleInfo['ModuleID'] ?? '');
            $moduleName = mb_substr((string)($moduleInfo['ModuleName'] ?? ''),0,100);
            $template = $this->learnedModuleTemplate($edit,$moduleId);
            $variables = $this->withLearnedRoleHints($variables,$template);
            $ids = array_column($variables, 'id');
            $isKnown = isset($known['source:' . $id])
                || array_filter($ids, static fn($v): bool => isset($known['var:' . $v])) !== [];
            $typing = isset($template['type']) && ($template['roles'] ?? []) !== []
                ? ['type'=>$template['type'],'confidence'=>'Hoch','reason'=>'Bestaetigte Vorlage (ModuleID)']
                : $this->inferDeviceTypeFromMetadata($variables,$moduleName,(string)($object['ObjectName'] ?? ''));
            $type = $typing['type'];
            $roomKey = $this->matchDiscoveryRoom($id, $edit);
            $suggestions = $this->suggestAIFields([], $type, $variables);
            $coverage = $this->roleCoverage($type,$suggestions);
            $matched = [];
            foreach ($suggestions as $suggestion) {
                if ($suggestion['confidence'] === 'Hoch' && (int)$suggestion['candidateId'] > 0) {
                    $matched[$suggestion['property']] = (int)$suggestion['candidateId'];
                }
            }
            $inventory[] = [
                'sourceId'=>$id,'sourceType'=>$kind===1 ? 'instance' : 'category',
                'moduleId'=>$moduleId,'moduleName'=>$moduleName,
                'name'=>mb_substr((string)($object['ObjectName'] ?? ''),0,100),
                'roomKey'=>$roomKey,'deviceType'=>$type,
                'variableIds'=>$ids,'roles'=>$matched,'roleMissing'=>$coverage['missing'],
                'known'=>$isKnown,
                'variables'=>array_map(static fn(array $var): array => [
                    'id'=>$var['id'],'name'=>$var['name'],'ident'=>$var['ident'],
                    'profile'=>$var['profile'],'type'=>$var['type'],
                    'action'=>$var['action'],'unit'=>$var['unit'] ?? '',
                    'associations'=>$var['associations'] ?? [],
                    'updated'=>$var['updated'] ?? 0,'changed'=>$var['changed'] ?? 0
                ],$variables)
            ];
            if ($isKnown) {
                $skipped++;
                continue;
            }
            $high = count($this->discoverHighProperties($variables));
            $confidence = $roomKey !== '' && $type !== 'generic' && $high > 0 ? 'Hoch' : 'Pruefen';
            $title = trim((string)($object['ObjectName'] ?? ''));
            $candidates[] = [
                'selected'=>false, 'sourceId'=>$id, 'sourceType'=>$kind === 1 ? 'instance' : 'category',
                'title'=>$title !== '' ? $title : 'Geraet ' . $id,
                'roomKey'=>$roomKey, 'deviceType'=>$type,
                'moduleName'=>$moduleName,'moduleId'=>$moduleId,
                'typeHint'=>$typing['reason'],'typeConfidence'=>$typing['confidence'],
                'variableCount'=>count($variables), 'confidence'=>$confidence,
                'reason'=>($roomKey === '' ? 'Raum zuordnen; ' : '') .
                    ($type === 'generic' ? 'Geraetetyp pruefen; ' : '') . $high . ' eindeutige Variablen' .
                    ($coverage['missing'] !== [] ? '; offen: ' . implode(', ',$coverage['missing']) : ''),
                'readiness'=>$coverage['missing'] === [] ? 'Pruefen' : 'Unvollstaendig',
                'mappedRoles'=>$matched,
                'variableIds'=>$ids
            ];
        }
        $truncated = $queue !== [] || $depthLimited || count($visited) >= 2500 || count($candidates) >= 200;
        $inventoryReport = [
            'time'=>time(),'roots'=>$roots,'scannedObjects'=>count($visited),
            'devices'=>count($inventory),'known'=>$skipped,'new'=>count($candidates),
            'withoutVariables'=>$noVariables,'excludedGroups'=>$excludedGroups,
            'disabled'=>$disabled,'truncated'=>$truncated,
            'items'=>$inventory
        ];
        $this->WriteAttributeString('DiscoveryInventory',json_encode($inventoryReport,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->WriteAttributeString('DiscoveryAuditReport','Neue Inventur: Vollstaendigkeitspruefung erneut starten.');
        $preview = [
            'time'=>time(), 'roots'=>$roots,
            'editHash'=>hash('sha256', IPS_GetScriptContent($this->ReadPropertyInteger('RoomsCatalogEditScriptID'))),
            'rows'=>$candidates
        ];
        $this->WriteAttributeString('DiscoveryCandidates', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('DiscoveredDevices','values',json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('DiscoveryInventoryList','values',json_encode(
            $this->discoveryInventoryRows($inventoryReport),JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $summary = $this->discoveryInventorySummary($inventoryReport);
        $this->UpdateFormField('DiscoverySummary','caption',$summary);
        echo $summary . ' Nur explizite Uebernahme aendert EDIT.';
    }

    public function UpdateDiscoveryCandidate(string $rowJson): void
    {
        $row = json_decode($rowJson, true);
        $preview = json_decode($this->ReadAttributeString('DiscoveryCandidates'), true);
        if (!is_array($row) || !is_array($preview) || !is_array($preview['rows'] ?? null)) return;
        $sourceId = (int)($row['sourceId'] ?? 0);
        foreach ($preview['rows'] as &$candidate) {
            if ((int)($candidate['sourceId'] ?? 0) !== $sourceId) continue;
            if (isset($row['selected'])) $candidate['selected'] = (bool)$row['selected'];
            if (isset($row['roomKey'])) $candidate['roomKey'] = trim((string)$row['roomKey']);
            if (isset($row['title'])) $candidate['title'] = trim((string)$row['title']);
            $type = (string)($row['deviceType'] ?? '');
            if (array_key_exists($type,RCCDeviceTypeRegistry::roles())) $candidate['deviceType'] = $type;
            break;
        }
        unset($candidate);
        $this->WriteAttributeString('DiscoveryCandidates', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('DiscoveredDevices','values',json_encode($preview['rows'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function SelectDiscoveredDevices(bool $select): void
    {
        $preview = json_decode($this->ReadAttributeString('DiscoveryCandidates'), true);
        if (!is_array($preview) || !is_array($preview['rows'] ?? null)) return;
        foreach ($preview['rows'] as &$row) {
            $row['selected'] = $select && ($row['confidence'] ?? '') === 'Hoch';
        }
        unset($row);
        $this->WriteAttributeString('DiscoveryCandidates', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('DiscoveredDevices','values',json_encode($preview['rows'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function ApplyDiscoveredDevices(): void
    {
        $preview = json_decode($this->ReadAttributeString('DiscoveryCandidates'), true);
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if (!is_array($preview) || !is_array($preview['rows'] ?? null) || $editId <= 0 || !IPS_ScriptExists($editId)
            || ($preview['roots'] ?? []) !== $this->discoveryRoots()
            || time() - (int)($preview['time'] ?? 0) > 600
            || ($preview['editHash'] ?? '') !== hash('sha256', IPS_GetScriptContent($editId))) {
            echo 'Geraetesuche veraltet oder EDIT geaendert. Bitte erneut scannen.';
            return;
        }
        $original = $this->loadRoomsCatalog($editId,'EDIT');
        $rooms = $this->roomMap($original);
        $rows = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($original));
        $known = $this->knownDiscoveryIds($original);
        $added = 0;
        $errors = [];
        foreach ($preview['rows'] as $candidate) {
            if (empty($candidate['selected'])) continue;
            $sourceId = (int)($candidate['sourceId'] ?? 0);
            $roomKey = (string)($candidate['roomKey'] ?? '');
            $type = (string)($candidate['deviceType'] ?? '');
            $title = trim((string)($candidate['title'] ?? ''));
            if ($sourceId <= 0 || !IPS_ObjectExists($sourceId) || !isset($rooms[$roomKey]['domains'])
                || !array_key_exists($type,RCCDeviceTypeRegistry::roles()) || $title === '') {
                $errors[] = 'Geraet ' . $sourceId . ': Raum, Typ oder Name ungueltig.';
                continue;
            }
            $variables = $this->getIpsVariablesUnderScope($sourceId);
            $variables = $this->withLearnedRoleHints($variables,
                $this->learnedModuleTemplate($original,(string)($candidate['moduleId'] ?? '')));
            $ids = array_column($variables, 'id');
            if (isset($known['source:' . $sourceId]) || $ids === []
                || array_filter($ids, static fn($v): bool => isset($known['var:' . $v])) !== []) {
                $errors[] = 'Geraet ' . $sourceId . ': bereits bekannt oder keine gueltigen Variablen.';
                continue;
            }
            [$domain,$group] = $this->guidedTypeLocation($type);
            $key = $this->discoveryDeviceKey($title,$sourceId);
            $entry = [
                'selected'=>false,'roomKey'=>$roomKey,'roomLabel'=>(string)($rooms[$roomKey]['display'] ?? $roomKey),
                'floor'=>(string)($rooms[$roomKey]['floor'] ?? ''),'domain'=>$domain,'group'=>$group,'key'=>$key,
                'title'=>$title,'kiEnabled'=>false,'kiReadOnly'=>true,'aiType'=>$type,
                'discoverySourceId'=>$sourceId,'discoverySourceType'=>(string)($candidate['sourceType'] ?? 'instance')
            ];
            $used = [];
            foreach ($this->suggestAIFields($entry,$type,$variables) as $suggestion) {
                $id = (int)$suggestion['candidateId'];
                if ($suggestion['confidence'] !== 'Hoch' || $id <= 0 || isset($used[$id])) continue;
                $entry[RCCDeviceTypeRegistry::fields()[$suggestion['property']]] = $id;
                $used[$id] = true;
            }
            $statusId = (int)($entry['aiStatusId'] ?? $entry['aiOpenId'] ?? $entry['aiPowerId'] ?? 0);
            $stateMapping = $this->inferStateMapping($variables,$statusId,$type);
            foreach ($stateMapping as $meaning=>$value) {
                $field = RCCDeviceTypeRegistry::states()[$meaning] ?? null;
                if ($field !== null) $entry[$field] = is_bool($value)
                    ? ($value ? 'true' : 'false') : (string)$value;
            }
            $entry['ai'] = [
                'version'=>2,
                'source'=>[
                    'instanceId'=>$sourceId,
                    'moduleId'=>(string)($candidate['moduleId'] ?? ''),
                    'moduleName'=>(string)($candidate['moduleName'] ?? ''),
                    'variableIds'=>$ids,
                    'unmappedVariableIds'=>array_values(array_diff($ids,array_keys($used)))
                ],
                'review'=>['status'=>'pending','note'=>'Gerätetyp, Variablen und Statussemantik am Geraet verifizieren']
            ];
            // Legacy-Felder ergänzen nur bekannte, eindeutige Mappings.
            $legacy = match ($type) {
                'light','dimmer','fan','switch' => ['power'=>'state','control'=>'toggle','brightness'=>'value'],
                'blind' => ['position'=>'wert'], 'heating' => ['temperature'=>'ist','targetTemperature'=>'soll'],
                'window' => ['open'=>'state'], 'motion' => ['motion'=>'state'], default => []
            };
            foreach ($legacy as $property => $field) {
                $id = (int)($entry[RCCDeviceTypeRegistry::fields()[$property]] ?? 0);
                if ($id > 0) $entry[$field] = $id;
            }
            $exists = false;
            foreach ($rows as $row) if ($this->buildCompositeKey($row) === $this->buildCompositeKey($entry)) $exists = true;
            if ($exists) continue;
            $rows[] = $entry;
            foreach ($ids as $id) $known['var:' . $id] = true;
            $known['source:' . $sourceId] = true;
            $added++;
        }
        if ($errors !== []) {
            echo 'Uebernahme abgebrochen: ' . implode('; ', array_slice($errors,0,4));
            return;
        }
        if ($added === 0) { echo 'Keine markierten neuen Geraete zum Uebernehmen.'; return; }
        $updated = $this->rebuildRoomsCatalogFromEntries($rows,$original);
        $loss = [];
        $this->findLostCatalogFields($original,$updated,'RoomsCatalogEdit',$loss);
        if ($loss !== []) { echo 'Uebernahme abgebrochen: ' . implode('; ',array_slice($loss,0,3)); return; }
        if (IPS_SetScriptContent($editId,"<?php\nreturn " . var_export($updated,true) . ";\n") === false) {
            echo 'RoomsCatalogEdit konnte nicht geschrieben werden.';
            return;
        }
        $this->WriteAttributeString('DiscoveryCandidates','{}');
        $this->UpdateFormField('DiscoveredDevices','values','[]');
        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
        echo $added . ' neue Geraete ausschliesslich in RoomsCatalogEdit gespeichert (KI-Schalten aus, PROD unveraendert).';
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
            'state', 'toggle', 'editorOriginalKey'
        ];
        $keys = array_merge($keys, ['aiType','aiReviewed'], array_values(RCCDeviceTypeRegistry::fields()), array_values(RCCDeviceTypeRegistry::states()));
        foreach ($entries as $row) {
            if (!is_array($row)) continue;
            foreach (array_keys($row) as $name) {
                if (!is_string($name) || in_array($name, ['rowColor','kiStatus','diffKeys','checkStatus','checkDetails','changeKind','changeDetails','editable','deletable','ipsObjectIds','ipsInstanceIds'], true)) continue;
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
                if (!array_key_exists($aiType, RCCDeviceTypeRegistry::roles())) {
                    $errors[] = $key . ': unbekannter KI-Geraetetyp ' . $aiType;
                } else {
                    foreach (RCCDeviceTypeRegistry::roles()[$aiType] as $property) {
                        $field = RCCDeviceTypeRegistry::fields()[$property];
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
        $type = (string)($row['aiType'] ?? '');
        if ($type !== '' && array_key_exists($type, RCCDeviceTypeRegistry::roles())) {
            foreach (RCCDeviceTypeRegistry::roles()[$type] as $property) {
                if (in_array($property, ['control','action'], true)) continue;
                $field = RCCDeviceTypeRegistry::fields()[$property] ?? '';
                $id = (int)($row[$field] ?? 0);
                if ($id > 0) return $id;
            }
        }
        $group = (string)($row['group'] ?? '');
        $fields = match ($group) {
            'heizung' => ['ist','aiTemperatureId','soll','eingestellt'],
            'dimmers' => ['value','state'],
            'jalousie' => ['wert'],
            // Ein Luefter hat nicht zwingend ein getrenntes Statusobjekt.
            // Die Steuerungs-ID darf als abfragbarer Soll-/Befehlswert
            // dienen; echter physischer Status ist damit NICHT bewiesen.
            'fans' => ['aiPowerId','aiControlId','state','toggle','value'],
            default => ['state','value']
        };
        foreach ($fields as $field) if ((int)($row[$field] ?? 0)>0) return (int)$row[$field];
        return 0;
    }

    private function aiWriteId(array $row): int
    {
        return match ((string)($row['group'] ?? '')) {
            'switches' => (int)(($row['toggle']??0) ?: ($row['aiControlId']??0)),
            'fans' => (int)(($row['aiControlId']??0) ?: ($row['toggle']??0)),
            'dimmers' => (int)($row['set'] ?? 0),
            'heizung' => (int)($row['soll'] ?? $row['eingestellt'] ?? 0),
            default => 0
        };
    }

    private function isSupportedAIEntry(array $row): bool
    {
        // Alle bekannten typisierten Sensoren und Messgeraete sind lesbar;
        // die separate Schreibfreigabe bleibt auf die Altgruppen beschraenkt.
        $type = (string)($row['aiType'] ?? '');
        if ($type !== '' && $type !== 'generic'
            && array_key_exists($type, RCCDeviceTypeRegistry::roles())) return true;
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
            'position' => ['stellung', 'value'], // Jalousie-wert ist Fahrbefehl, KEIN Istpositionswert
            // Ein altes "set" ist gerade bei KNX-Jalousien oft eine
            // Steuerangabe und KEINE eigenstaendige IPS-Sollpositionsvariable.
            // Zielposition nur aus ai.variables.targetPosition uebernehmen,
            // niemals einen Legacy-Wert (z.B. 1) als IPS-ID erfinden.
            'targetPosition' => [],
            'tilt' => ['tiltId'],
            'moving' => ['moving'],
            'control' => ['toggle', 'set', 'controlId', 'soll', 'eingestellt'],
            'temperature' => ['ist'],
            'targetTemperature' => ['soll', 'eingestellt'],
            'open' => ['state', 'value'],
            'powerWatts' => ['leistung'],
            'energy' => ['verbrauch'],
            'motion' => ['state'],
            'illuminance' => ['value','wert'],
            'status' => ['statusId','state'],
            'action' => ['controlId','toggle'],
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
        $row['aiReviewed'] = ($ai['review']['status'] ?? '') === 'confirmed';
        $variables = isset($ai['variables']) && is_array($ai['variables']) ? $ai['variables'] : [];
        foreach (RCCDeviceTypeRegistry::fields() as $property => $field) {
            $configured = $variables[$property] ?? null;
            $row[$field] = is_numeric($configured) ? (int)$configured
                : $this->legacyAIVariable($row, $property);
        }
        $mapping = isset($ai['stateMapping']) && is_array($ai['stateMapping']) ? $ai['stateMapping'] : [];
        foreach (RCCDeviceTypeRegistry::states() as $property => $field) {
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
        if ($type === '' || !array_key_exists($type, RCCDeviceTypeRegistry::roles())) return $cfg;

        $ai = isset($cfg['ai']) && is_array($cfg['ai']) ? $cfg['ai'] : [];
        $ai['version'] = max(1, (int)($ai['version'] ?? 1));
        $ai['type'] = $type;
        $variables = isset($ai['variables']) && is_array($ai['variables']) ? $ai['variables'] : [];
        foreach (RCCDeviceTypeRegistry::fields() as $property => $field) {
            if (!in_array($property, RCCDeviceTypeRegistry::roles()[$type], true)) continue;
            if (!array_key_exists($field, $row)) continue;
            $value = (int)$row[$field];
            if ($value > 0) {
                $variables[$property] = $value;
            } else {
                // A SelectVariable selection of "Keine" (0) must DELETE
                // the old nested binding rather than leave an explicit
                // 0 or accidentally retain the previous invalid ID.
                // Only currently edited, type-compatible roles are touched.
                unset($variables[$property]);
            }
        }
        if ($variables !== [] || array_key_exists('variables', $ai)) $ai['variables'] = $variables;

        $mapping = isset($ai['stateMapping']) && is_array($ai['stateMapping']) ? $ai['stateMapping'] : [];
        foreach (RCCDeviceTypeRegistry::states() as $property => $field) {
            if (!array_key_exists($field, $row)) continue;
            $value = trim((string)$row[$field]);
            if ($value !== '') $mapping[$property] = $this->parseAIStateValue($value);
        }
        if ($mapping !== [] || array_key_exists('stateMapping', $ai)) $ai['stateMapping'] = $mapping;
        if (!empty($row['aiReviewed'])) {
            $review = is_array($ai['review'] ?? null) ? $ai['review'] : [];
            $review['status'] = 'confirmed';
            $review['confirmedAt'] = (int)($review['confirmedAt'] ?? time());
            $ai['review'] = $review;
        } elseif (isset($ai['review'])) {
            $ai['review']['status'] = 'pending';
            unset($ai['review']['confirmedAt']);
        }
        $cfg['ai'] = $ai;
        return $cfg;
    }

    /** Ein Geräte-Key wird beim Anlegen aus dem Anzeigenamen vorgeschlagen. */
    private function entryKeyFromTitle(string $title): string
    {
        $text = mb_strtolower(trim($title), 'UTF-8');
        $text = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $text);
        $text = trim((string)preg_replace('/[^a-z0-9]+/u', '_', $text), '_');
        return substr($text !== '' ? $text : 'geraet', 0, 48);
    }

    /** Standards für NEUE typisierte Geräte; Legacy-Einträge behalten ihren bisherigen Pfad. */
    private function guidedTypeLocation(string $type): array
    {
        return match ($type) {
            'switch','generic' => ['devices','tabs'],
            default => $this->discoveryTypeLocation($type)
        };
    }

    /** Bei einem echten Raum-/Typwechsel den alten Leaf entfernen, auch im letzten Raum. */
    private function removeMovedCatalogLeaf(array &$catalog, string $composite): void
    {
        $parts = explode('|', $composite, 4);
        if (count($parts) !== 4) return;
        [$roomKey,$domain,$group,$key] = $parts;
        if (isset($catalog['rooms']) && is_array($catalog['rooms'])) {
            $rooms = &$catalog['rooms'];
        } else {
            $rooms = &$catalog;
        }
        if (!isset($rooms[$roomKey]['domains'][$domain])) return;
        if ($domain === 'heizung' || $domain === 'jalousie') {
            unset($rooms[$roomKey]['domains'][$domain][$key]);
        } else {
            unset($rooms[$roomKey]['domains'][$domain][$group][$key]);
            if (($rooms[$roomKey]['domains'][$domain][$group] ?? null) === []) {
                unset($rooms[$roomKey]['domains'][$domain][$group]);
            }
        }
        if ($rooms[$roomKey]['domains'][$domain] === []) unset($rooms[$roomKey]['domains'][$domain]);
    }

    /**
     * Die abgeleiteten Angaben niemals dem Dialog-Client überlassen.
     * sourceKey ist nur eine kurzlebige Editor-Hilfe und wird nicht gespeichert.
     *
     * @return array{0:array,1:string}
     */
    private function normalizeGuidedEntry(array $row, array $existingRows, array $catalog): array
    {
        $originalKey = trim((string)($row['editorOriginalKey'] ?? ''));
        unset($row['editorOriginalKey']);
        $source = null;
        if ($originalKey !== '') {
            foreach ($existingRows as $existing) {
                if ($this->buildCompositeKey($existing) === $originalKey) {
                    $source = $existing;
                    break;
                }
            }
            if ($source === null) throw new InvalidArgumentException('Urspruengliches Geraet wurde zwischenzeitlich geaendert. Bitte neu laden.');
            // Vor einem Raum-/Typwechsel alle unbekannten Legacy- und ai-Felder
            // mitnehmen, die das Dialogformular nicht ausdrücklich enthält.
            $row = array_replace($source, $row);
        }
        $new = $source === null;
        $roomKey = trim((string)($row['roomKey'] ?? ''));
        $rooms = $this->roomMap($catalog);
        if ($roomKey === '' || $roomKey === 'global' || !isset($rooms[$roomKey]['domains'])
            || !is_array($rooms[$roomKey]['domains'])) {
            throw new InvalidArgumentException('Bitte einen gueltigen Raum aus der Raumliste auswaehlen.');
        }
        $row['roomKey'] = $roomKey;
        $row['roomLabel'] = (string)($rooms[$roomKey]['display'] ?? $roomKey);
        $row['floor'] = (string)($rooms[$roomKey]['floor'] ?? '');
        $row['title'] = trim((string)($row['title'] ?? ''));
        if ($row['title'] === '') throw new InvalidArgumentException('Bitte einen Geraetenamen eingeben.');

        $type = trim((string)($row['aiType'] ?? ($source['aiType'] ?? '')));
        if ($new && !array_key_exists($type, RCCDeviceTypeRegistry::roles())) {
            throw new InvalidArgumentException('Neue Geraete brauchen einen konkreten Geraetetyp.');
        }
        if ($type !== '' && !array_key_exists($type, RCCDeviceTypeRegistry::roles())) {
            throw new InvalidArgumentException('Unbekannter Geraetetyp.');
        }
        if ($new || ($type !== '' && $type !== (string)($source['aiType'] ?? '')
            && $type !== $this->inferredAIType($source))) {
            [$row['domain'],$row['group']] = $this->guidedTypeLocation($type);
        } else {
            $row['domain'] = (string)($source['domain'] ?? $row['domain'] ?? '');
            $row['group'] = (string)($source['group'] ?? $row['group'] ?? '');
        }
        if ($new) {
            // Keine unbemerkte Schaltfreigabe direkt beim Anlegen.
            $row['kiEnabled'] = false;
            $row['kiReadOnly'] = true;
            $row['aiReviewed'] = false;
            $legacyFields = match ($type) {
                'light','dimmer','fan','switch' => ['power'=>'state','control'=>'toggle','brightness'=>'value'],
                'blind' => ['position'=>'wert'],
                'heating' => ['temperature'=>'ist','targetTemperature'=>'soll'],
                'window' => ['open'=>'state'], 'motion' => ['motion'=>'state'],
                default => []
            };
            foreach ($legacyFields as $property => $legacyField) {
                $id = (int)($row[RCCDeviceTypeRegistry::fields()[$property]] ?? 0);
                if ($id > 0 && (int)($row[$legacyField] ?? 0) <= 0) $row[$legacyField] = $id;
            }
        }
        if (!$new && $type !== (string)($source['aiType'] ?? '')) $row['aiReviewed'] = false;
        if (!$new) {
            foreach (array_merge(array_values(RCCDeviceTypeRegistry::fields()),array_values(RCCDeviceTypeRegistry::states())) as $field) {
                if (array_key_exists($field,$row) && (string)$row[$field] !== (string)($source[$field] ?? '')) {
                    $row['aiReviewed'] = false;
                    break;
                }
            }
        }

        $key = trim((string)($row['key'] ?? ''));
        if ($key === '') {
            $key = $this->entryKeyFromTitle($row['title']);
            $base = $key;
            $suffix = 2;
            $taken = [];
            foreach ($existingRows as $existing) {
                $composite = $this->buildCompositeKey($existing);
                if ($composite !== null && $composite !== $originalKey) $taken[$composite] = true;
            }
            while (isset($taken[$roomKey . '|' . $row['domain'] . '|' . $row['group'] . '|' . $key])) {
                $key = $base . '_' . $suffix++;
            }
        }
        if (($new || $key !== (string)($source['key'] ?? ''))
            && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Geraete-Key darf nur Buchstaben, Zahlen, _ oder - enthalten.');
        }
        $row['key'] = $key;
        $targetKey = $this->buildCompositeKey($row);
        foreach ($existingRows as $existing) {
            $composite = $this->buildCompositeKey($existing);
            if ($composite === $targetKey && $composite !== $originalKey) {
                throw new InvalidArgumentException('Dieser Geraete-Key ist in Raum/Bereich/Gruppe bereits vergeben.');
            }
        }
        return [$row, $originalKey];
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
            if (is_array($entry)) unset($entry['rowColor'], $entry['kiStatus'], $entry['diffKeys'], $entry['checkStatus'], $entry['checkDetails'], $entry['changeKind'], $entry['changeDetails'], $entry['editable'], $entry['ipsObjectIds'], $entry['ipsInstanceIds']);
        }
        unset($entry);
        $this->logDebug('SaveToEdit: START, rows=' . count($entries));

        $editScriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($editScriptId <= 0 || !IPS_ScriptExists($editScriptId)) {
            $this->logDebug('SaveToEdit: RoomsCatalogEditScriptID ungültig: ' . $editScriptId);
            return;
        }

        $all = $this->getRuntimeEntries();
        $originalKeysToMove = [];
        if (count($entries) === 1 && is_array($entries[0])
            && array_key_exists('editorOriginalKey', $entries[0])) {
            try {
                [$entries[0],$originalKey] = $this->normalizeGuidedEntry(
                    $entries[0], $all, $this->loadRoomsCatalog($editScriptId, 'EDIT')
                );
            } catch (InvalidArgumentException $error) {
                echo 'Geraet nicht gespeichert: ' . $error->getMessage();
                return;
            }
            $newKey = $this->buildCompositeKey($entries[0]);
            if ($originalKey !== '' && $originalKey !== $newKey) $originalKeysToMove[$originalKey] = true;
        }

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

        if ($originalKeysToMove !== []) {
            // Standort/Typwechsel verschiebt das Geraet, statt es zu duplizieren.
            $all = array_values(array_filter($all, fn(array $entry): bool =>
                !isset($originalKeysToMove[$this->buildCompositeKey($entry) ?? ''])));
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
        foreach (array_keys($originalKeysToMove) as $oldKey) {
            $this->removeMovedCatalogLeaf($newCatalog, $oldKey);
        }

        $php = "<?php\nreturn " . var_export($newCatalog, true) . ";\n";
        IPS_SetScriptContent($editScriptId, $php);

        $roomsArray = $this->extractRoomsFromCatalog($newCatalog);
        $roomCount  = count($roomsArray);
        $this->logDebug('SaveToEdit: ENDE, rooms=' . $roomCount);

        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
    }


    /**
     * EDIT -> PROD by explicit domain only. Never validates or copies
     * unrelated domains, removes devices, or modifies room metadata.
     * Pending/unverified KNX blind commands never pass as state feedback.
     */
    private function scopedProdSources(): array
    {
        $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $prodId=$this->ReadPropertyInteger('RoomsCatalogScriptID');
        if ($editId<=0 || $prodId<=0 || $editId===$prodId
            || !IPS_ScriptExists($editId) || !IPS_ScriptExists($prodId)) {
            throw new \RuntimeException('Es muessen zwei unterschiedliche gueltige EDIT- und PROD-Skripte konfiguriert sein.');
        }
        $editSource=(string)IPS_GetScriptContent($editId);
        $prodSource=(string)IPS_GetScriptContent($prodId);
        if (!RCCDeviceTechnicalAudit::isLiteralArraySource($editSource)
            || !RCCDeviceTechnicalAudit::isLiteralArraySource($prodSource)) {
            throw new \RuntimeException('Nur statische PHP-Array-Kataloge werden bereichsweise sicher uebernommen.');
        }
        $edit=$this->loadRoomsCatalog($editId,'EDIT');
        $prod=$this->loadRoomsCatalog($prodId,'PROD');
        if ($edit===[] || $prod===[]) {
            throw new \RuntimeException('EDIT oder PROD ist leer beziehungsweise konnte nicht gelesen werden.');
        }
        return compact('editId','prodId','editSource','prodSource','edit','prod');
    }

    private function scopedProdDomainOptions(array $entries): array
    {
        $options=[
            ['caption'=>'Bitte Bereich auswaehlen','value'=>''],
            ['caption'=>'ALLE GUELTIGEN BEREICHE OHNE JALOUSIE','value'=>'__all_without_jalousie__']
        ];
        $labels=['licht'=>'Licht','heizung'=>'Heizung','jalousie'=>'Jalousie (nur gueltige Rueckmeldungen)',
            'lueftung'=>'Lueftung','energie'=>'Energie','sensoren'=>'Sensoren',
            'sicherheit'=>'Sicherheit','information'=>'Information','bewaesserung'=>'Bewaesserung'];
        $domains=[];
        foreach ($entries as $row) {
            $key=(string)($row['domain']??'');
            if ($key!=='' && $key!=='global') $domains[$key]=true;
        }
        ksort($domains);
        foreach (array_keys($domains) as $key) {
            $options[]=['caption'=>$labels[$key]??ucfirst($key),'value'=>$key];
        }
        return $options;
    }

    /** Return an original device leaf, not the flattened/form's virtual fields. */
    private function scopedProdLeaf(array $catalog,array $row): ?array
    {
        $room=(string)($row['roomKey']??'');
        $domain=(string)($row['domain']??'');
        $group=(string)($row['group']??'');
        $key=(string)($row['key']??'');
        $rooms=$this->roomMap($catalog);
        $domainData=$rooms[$room]['domains'][$domain]??null;
        if (!is_array($domainData)) return null;
        $leaf=in_array($domain,['heizung','jalousie'],true)
            ? ($domainData[$key]??null)
            : ($domainData[$group][$key]??null);
        return is_array($leaf)?$leaf:null;
    }

    /**
     * Build a complete, explainable decision from current source scripts.
     * Only NEW/CHANGED individual devices can be accepted; deletions and
     * room changes are never part of a partial publish.
     */
    private function scopedProdPlan(array $edit,array $prod,string $area): array
    {
        $editRows=$this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($edit));
        $prodRows=$this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($prod));
        $domains=array_fill_keys(array_filter(array_column($editRows,'domain')),true);
        if ($area==='' || ($area!=='__all_without_jalousie__' && !isset($domains[$area]))) {
            throw new \InvalidArgumentException('Bitte einen vorhandenen Bereich auswaehlen.');
        }
        $inScope=static fn(string $domain):bool => $area==='__all_without_jalousie__'
            ? ($domain!=='jalousie' && $domain!=='global')
            : ($domain===$area);

        $oldRows=[];
        foreach ($prodRows as $row) {
            $key=$this->buildCompositeKey($row);
            if ($key!==null) $oldRows[$key]=$row;
        }
        $newKeys=[];
        $approved=[];
        $skipped=[];
        $unchanged=0;
        $allowedRooms=$this->roomMap($prod);
        foreach ($editRows as $row) {
            $key=$this->buildCompositeKey($row);
            if ($key===null || !$inScope((string)($row['domain']??''))) continue;
            if (isset($newKeys[$key])) {
                $skipped[]=['key'=>$key,'domain'=>(string)$row['domain'],
                    'title'=>(string)($row['title']??$key),
                    'reason'=>'Doppelter Geraeteschluessel in EDIT.','status'=>'Gesperrt'];
                continue;
            }
            $newKeys[$key]=true;
            $old=$this->scopedProdLeaf($prod,$row);
            $new=$this->scopedProdLeaf($edit,$row);
            if ($new===null) {
                $skipped[]=['key'=>$key,'domain'=>(string)$row['domain'],
                    'title'=>(string)($row['title']??$key),
                    'reason'=>'Geraet in EDIT nicht eindeutig gefunden.','status'=>'Gesperrt'];
                continue;
            }
            if ($old!==null && $old===$new) { $unchanged++; continue; }

            $reason='';
            $room=(string)($row['roomKey']??'');
            if (!isset($allowedRooms[$room]['domains'])
                || !is_array($allowedRooms[$room]['domains'])) {
                $reason='Raum in PROD nicht vorhanden; neue Raeume bitte separat pruefen.';
            } elseif ($room==='global') {
                $reason='Globale System-/UI-Eintraege gehoeren nicht in den Geraetekatalog.';
            } elseif ($old!==null && !empty($old['ai']['review']['status'])
                && $old['ai']['review']['status']==='confirmed'
                && ($new['ai']['review']['status']??'')!=='confirmed') {
                $reason='Bereits bestaetigte Geraetezuordnung wuerde zurueckgesetzt.';
            } elseif (!empty($new['kiEnabled']) && empty($new['kiReadOnly'])
                && ($old===null || empty($old['kiEnabled']) || !empty($old['kiReadOnly']))) {
                $reason='Neue KI-Schaltfreigabe: nur im gesonderten Freigabeprozess uebernehmen.';
            } else {
                $errors=$this->validateEntries([$row],true);
                if ($errors!==[]) {
                    $reason=implode('; ',array_slice($errors,0,2));
                } else {
                    $assessment=$this->assessTechnicalDevice($row);
                    if (($assessment['status']??'')!=='Technisch plausibel') {
                        $reason=(string)($assessment['reason']??'Variable/Status nicht eindeutig geprueft.');
                    }
                }
            }
            // Blind: commands (e.g. 0=Open, 100=Close) are NOT a real
            // position or closed/open reading. Even if generic validation
            // passes, proof of a distinct readback is mandatory.
            if ($reason==='' && (string)$row['domain']==='jalousie') {
                $roles=(array)($new['ai']['variables']??[]);
                $feedback=(int)($roles['position']??$roles['status']??0);
                $action=(int)($roles['action']??0);
                if ($feedback<=0 || ($action>0 && $feedback===$action)) {
                    $reason='Jalousie ohne eigenstaendige, gepruefte Rueckmeldevariable.';
                }
            }
            if ($reason!=='') {
                $skipped[]=[
                    'key'=>$key,'domain'=>(string)$row['domain'],
                    'title'=>(string)($row['title']??$key),'reason'=>$reason,'status'=>'Gesperrt'
                ];
                continue;
            }
            $approved[]=[
                'key'=>$key,'domain'=>(string)$row['domain'],
                'title'=>(string)($row['title']??$key),
                'change'=>$old===null?'Neu':'Geaendert',
                'status'=>'Gueltig','reason'=>'Variablenzuordnung technisch plausibel.'
            ];
        }
        // Deletions in EDIT must never silently delete PROD devices.
        foreach ($oldRows as $key=>$row) {
            if (!$inScope((string)($row['domain']??'')) || isset($newKeys[$key])) continue;
            $skipped[]=['key'=>$key,'domain'=>(string)$row['domain'],
                'title'=>(string)($row['title']??$key),
                'status'=>'Gesperrt','reason'=>'In EDIT entfernt; Teiluebernahme loescht keine PROD-Geraete.'];
        }
        return [
            'domain'=>$area,'approved'=>$approved,'skipped'=>$skipped,
            'unchanged'=>$unchanged
        ];
    }

    /** Copy only explicitly approved device leaves into the existing PROD tree. */
    private function scopedProdApplyPlan(array $prod,array $edit,array $approved): array
    {
        $candidate=$prod;
        $sourceRooms=$this->roomMap($edit);
        if (isset($candidate['rooms']) && is_array($candidate['rooms'])) {
            $destRooms=&$candidate['rooms'];
        } else {
            $destRooms=&$candidate;
        }
        foreach ($approved as $item) {
            $parts=explode('|',(string)$item['key'],4);
            if (count($parts)!==4) throw new \RuntimeException('Ungueltiger Geraeteschluessel.');
            [$room,$domain,$group,$key]=$parts;
            if (!isset($destRooms[$room]['domains']) || !is_array($destRooms[$room]['domains'])) {
                throw new \RuntimeException('PROD-Raum seit Vorschau geaendert: '.$room);
            }
            $sourceDomain=$sourceRooms[$room]['domains'][$domain]??null;
            $leaf=in_array($domain,['heizung','jalousie'],true)
                ? ($sourceDomain[$key]??null)
                : ($sourceDomain[$group][$key]??null);
            if (!is_array($leaf)) throw new \RuntimeException('EDIT-Geraet nicht gefunden: '.$item['key']);
            if (!isset($destRooms[$room]['domains'][$domain])) $destRooms[$room]['domains'][$domain]=[];
            if (in_array($domain,['heizung','jalousie'],true)) {
                $destRooms[$room]['domains'][$domain][$key]=$leaf;
            } else {
                if (!isset($destRooms[$room]['domains'][$domain][$group])) {
                    $destRooms[$room]['domains'][$domain][$group]=[];
                }
                $destRooms[$room]['domains'][$domain][$group][$key]=$leaf;
            }
        }
        unset($destRooms);
        return $candidate;
    }

    private function scopedProdShowPreview(array $plan,string $message): void
    {
        $this->WriteAttributeString('PartialProdPublishNotice',$message);
        $this->UpdateFormField('PartialProdPublishNotice','caption',$message);
        $rows=[];
        foreach ($plan['approved'] as $r) $rows[]=$r;
        foreach ($plan['skipped'] as $r) $rows[]=$r;
        $json=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        $this->WriteAttributeString('PartialProdPublishRows',$json);
        $this->UpdateFormField('PartialProdPublishRows','values',$json);
    }

    public function SetScopedProdDomain(string $area): void
    {
        $this->WriteAttributeString('PartialProdSelectedDomain',$area);
        $this->WriteAttributeString('PartialProdPublishPreview','{}');
        $this->WriteAttributeString('PartialProdPublishRows','[]');
        $this->WriteAttributeString('PartialProdPublishNotice','Bitte fuer diesen Bereich eine neue Vorschau erstellen.');
        $this->UpdateFormField('PartialProdPublishRows','values','[]');
        $this->UpdateFormField('PartialProdPublishNotice','caption',
            'Bitte fuer diesen Bereich eine neue Vorschau erstellen.');
    }

    public function PreviewScopedProdPublish(string $area): void
    {
        $this->WriteAttributeString('PartialProdPublishPreview','{}');
        $this->WriteAttributeString('PartialProdSelectedDomain',$area);
        try {
            $sources=$this->scopedProdSources();
            $plan=$this->scopedProdPlan($sources['edit'],$sources['prod'],$area);
            $fingerprint=hash('sha256',json_encode($plan,
                JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
            $preview=[
                'time'=>time(),'domain'=>$area,
                'editId'=>$sources['editId'],'prodId'=>$sources['prodId'],
                'editHash'=>hash('sha256',$sources['editSource']),
                'prodHash'=>hash('sha256',$sources['prodSource']),
                'planHash'=>$fingerprint
            ];
            $this->WriteAttributeString('PartialProdPublishPreview',
                json_encode($preview,JSON_THROW_ON_ERROR));
            $message=sprintf('VORSCHAU Bereich %s: %d gueltige Aenderungen; %d gesperrt; '
                .'%d unveraendert. Nur diese gueltigen Geraete werden nach PROD geschrieben. '
                .'Andere Bereiche, alle ungueltigen Jalousien und Raum-Metadaten bleiben unveraendert. '
                .'Vorschau 10 Minuten gueltig.',
                $area,count($plan['approved']),count($plan['skipped']),$plan['unchanged']);
            $this->scopedProdShowPreview($plan,$message);
            echo $message;
        } catch (\Throwable $e) {
            $message='TEIL-PROD-VORSCHAU BLOCKIERT: '.$e->getMessage();
            $this->scopedProdShowPreview(['approved'=>[],'skipped'=>[]],$message);
            echo $message;
        }
    }

    public function ApplyScopedProdPublish(string $area): void
    {
        try {
            $preview=json_decode($this->ReadAttributeString('PartialProdPublishPreview'),true);
            if (!is_array($preview) || !isset($preview['time'])
                || time()-(int)$preview['time']>600 || (int)$preview['time']>time()
                || ($preview['domain']??'')!==$area) {
                throw new \RuntimeException('Bitte zuerst eine aktuelle Bereichs-Vorschau fuer denselben Bereich erstellen.');
            }
            $sources=$this->scopedProdSources();
            if ($sources['editId']!==(int)($preview['editId']??0)
                || $sources['prodId']!==(int)($preview['prodId']??0)
                || hash('sha256',$sources['editSource'])!==($preview['editHash']??'')
                || hash('sha256',$sources['prodSource'])!==($preview['prodHash']??'')) {
                throw new \RuntimeException('EDIT/PROD hat sich seit der Vorschau veraendert. Neu pruefen.');
            }
            $plan=$this->scopedProdPlan($sources['edit'],$sources['prod'],$area);
            $fingerprint=hash('sha256',json_encode($plan,
                JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
            if (!hash_equals((string)($preview['planHash']??''),$fingerprint)) {
                throw new \RuntimeException('Geräte- oder IPS-Metadaten haben sich seit der Vorschau geaendert.');
            }
            if ($plan['approved']===[]) {
                $message='KEINE UEBERNAHME: Im Bereich gibt es keine technisch gueltigen Aenderungen.';
                $this->scopedProdShowPreview($plan,$message);
                echo $message;
                return;
            }
            $candidate=$this->scopedProdApplyPlan($sources['prod'],$sources['edit'],$plan['approved']);
            if ($candidate===$sources['prod']) {
                throw new \RuntimeException('Keine wirksamen Aenderungen erkannt.');
            }
            $php="<?php\nreturn ".var_export($candidate,true).";\n";
            // No backup and no write if a concurrent operator changed sources.
            if (IPS_GetScriptContent($sources['prodId'])!==$sources['prodSource']
                || IPS_GetScriptContent($sources['editId'])!==$sources['editSource']) {
                throw new \RuntimeException('Kataloge vor der Sicherung veraendert. Abbruch.');
            }
            $backup=IPS_CreateScript(0);
            if (!is_int($backup) || $backup<=0) {
                throw new \RuntimeException('PROD-Backup konnte nicht angelegt werden.');
            }
            IPS_SetParent($backup,IPS_GetParent($sources['prodId']));
            IPS_SetName($backup,'RoomsCatalog_PartialProd_Backup_'.date('Ymd_His').'_'.$backup);
            IPS_SetScriptContent($backup,$sources['prodSource']);
            if (IPS_GetScriptContent($backup)!==$sources['prodSource']) {
                throw new \RuntimeException('PROD-Backup konnte nicht verifiziert werden; keine Uebernahme.');
            }
            if (IPS_GetScriptContent($sources['prodId'])!==$sources['prodSource']
                || IPS_GetScriptContent($sources['editId'])!==$sources['editSource']) {
                throw new \RuntimeException('Kataloge nach Sicherung veraendert. PROD nicht beschrieben.');
            }
            try {
                IPS_SetScriptContent($sources['prodId'],$php);
                if (IPS_GetScriptContent($sources['prodId'])!==$php) {
                    throw new \RuntimeException('PROD-Schreibverifikation fehlgeschlagen.');
                }
            } catch (\Throwable $writeError) {
                try {
                    IPS_SetScriptContent($sources['prodId'],$sources['prodSource']);
                    $restored=IPS_GetScriptContent($sources['prodId'])===$sources['prodSource'];
                } catch (\Throwable $restoreError) { $restored=false; }
                throw new \RuntimeException('Schreibfehler: '.$writeError->getMessage()
                    .($restored?' PROD wiederhergestellt.':' ACHTUNG: Wiederherstellung nicht bestaetigt!')
                    .' Sicherung #'.$backup);
            }
            $this->WriteAttributeString('PartialProdPublishPreview','{}');
            $message=sprintf('ERFOLG: %d gueltige Geraete im Bereich %s nach PROD uebernommen; '
                .'%d gesperrt/unveraendert in PROD belassen. Backup #%d. '
                .'EDIT bleibt unveraendert; offene Unterschiede koennen bestehen.',
                count($plan['approved']),$area,count($plan['skipped']),$backup);
            $this->scopedProdShowPreview($plan,$message);
            $this->reloadAllFromCatalog();
            $this->RefreshVisibleLists();
            echo $message;
        } catch (\Throwable $e) {
            $message='BEREICHSWEISE PROD-UEBERNAHME BLOCKIERT: '.$e->getMessage();
            $this->WriteAttributeString('PartialProdPublishNotice',$message);
            $this->UpdateFormField('PartialProdPublishNotice','caption',$message);
            echo $message;
        }
    }


    /** Candidate choices derived from current EDIT/PROD, never from a stale form row. */
    private function singlePositionProdOptions(array $edit,array $prod): array
    {
        $options=[['caption'=>'Bitte geaenderte Jalousie auswaehlen','value'=>'']];
        foreach ($this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($edit)) as $row) {
            if (($row['domain']??'')!=='jalousie') continue;
            $key=$this->buildCompositeKey($row);
            if ($key===null) continue;
            $before=$this->scopedProdLeaf($prod,$row);
            $after=$this->scopedProdLeaf($edit,$row);
            if (!is_array($before) || !is_array($after)) continue;
            $oldNested=(int)($before['ai']['variables']['position']??0);
            $newNested=(int)($after['ai']['variables']['position']??0);
            $oldFlat=(int)($before['aiPositionId']??0);
            $newFlat=(int)($after['aiPositionId']??0);
            if ($oldNested===$newNested && $oldFlat===$newFlat) continue;
            $options[]=[
                'value'=>$key,
                'caption'=>(string)($row['roomLabel']??$row['roomKey'])
                    .' / '.(string)($row['title']??$row['key'])
                    .' (Positions-ID geaendert)'
            ];
        }
        return $options;
    }

    /**
     * Check precisely ONE changed position binding – not the whole shutter.
     * A valid IPS variable ID is still not a confirmed physical position.
     * Missing open/closed status mappings deliberately do NOT block a
     * field-only import. No command, KI rights or review status is copied.
     */
    private function singlePositionProdPlan(array $edit,array $prod,string $key): array
    {
        if ($key==='' || substr_count($key,'|')!==3 || strlen($key)>320) {
            throw new \RuntimeException('Bitte eine geaenderte Jalousie auswaehlen.');
        }
        $parts=explode('|',$key,4);
        if ($parts[1]!=='jalousie' || $parts[2]!=='jalousie') {
            throw new \RuntimeException('Diese Einzelfeld-Uebernahme gilt nur fuer Jalousie-Positionen.');
        }
        $row=null;
        foreach ($this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($edit)) as $entry) {
            if ($this->buildCompositeKey($entry)===$key) {
                if ($row!==null) throw new \RuntimeException('Geraeteschluessel mehrfach vorhanden.');
                $row=$entry;
            }
        }
        if ($row===null) throw new \RuntimeException('Jalousie in EDIT nicht vorhanden.');
        $before=$this->scopedProdLeaf($prod,$row);
        $after=$this->scopedProdLeaf($edit,$row);
        if (!is_array($before) || !is_array($after)) {
            throw new \RuntimeException('Keine Felduebernahme fuer neue/entfernte Geraete.');
        }
        if (($before['ai']['review']['status']??'')==='confirmed') {
            throw new \RuntimeException('PROD-Geraet bereits fachlich bestaetigt. Positionsaenderung gesondert pruefen.');
        }
        if (($after['ai']['type']??'')!=='blind' || ($before['ai']['type']??'')!=='blind') {
            throw new \RuntimeException('EDIT/PROD haben keinen eindeutigen Jalousie-Geraetetyp.');
        }
        $oldNested=(int)($before['ai']['variables']['position']??0);
        $newNested=(int)($after['ai']['variables']['position']??0);
        $oldFlat=(int)($before['aiPositionId']??0);
        $newFlat=(int)($after['aiPositionId']??0);
        // An unchanged nested field and a changed legacy field still
        // must agree. Compare FINAL target values, not just the delta.
        if ($newNested>0 && $newFlat>0 && $newNested!==$newFlat) {
            throw new \RuntimeException('aiPositionId und ai.variables.position enthalten unterschiedliche neue IDs.');
        }
        $changed=[];
        if ($newNested!==$oldNested) $changed['ai.variables.position']=$newNested;
        if ($newFlat!==$oldFlat && array_key_exists('aiPositionId',$after)) {
            $changed['aiPositionId']=$newFlat;
        }
        if ($changed===[]) throw new \RuntimeException('Keine geaenderte Positions-ID vorhanden.');
        $distinct=array_unique(array_values($changed));
        if (count($distinct)!==1) {
            throw new \RuntimeException('aiPositionId und ai.variables.position enthalten unterschiedliche neue IDs.');
        }
        $id=(int)reset($distinct);
        if ($id<=0 || !function_exists('IPS_VariableExists') || !IPS_VariableExists($id)) {
            throw new \RuntimeException('Neue Positions-ID existiert nicht als IP-Symcon-Variable.');
        }
        foreach (['wert','controlId','statusId','tiltId'] as $field) {
            if ((int)($after[$field]??0)===$id && $field!=='statusId') {
                throw new \RuntimeException('Position darf nicht dieselbe ID wie der Fahrbefehl/Lamellenwert sein.');
            }
        }
        foreach (['action','control','tilt','targetPosition'] as $role) {
            if ((int)($after['ai']['variables'][$role]??0)===$id) {
                throw new \RuntimeException('Positions-ID ist bereits als Fahraktion, Sollposition oder Lamellenwert belegt.');
            }
        }
        foreach (['wert','controlId'] as $field) {
            if ((int)($before[$field]??0)===$id) {
                throw new \RuntimeException('Der neue Positionswert ist die bestehende Fahraktion.');
            }
        }

        if (!function_exists('IPS_GetVariable') || !function_exists('IPS_GetObject')) {
            throw new \RuntimeException('IP-Symcon-Variablenmetadaten nicht abrufbar.');
        }
        $variable=IPS_GetVariable($id);
        $object=IPS_GetObject($id);
        if (!is_array($variable) || !is_array($object)
            || (int)($object['ObjectType']??-1)!==2
            || !in_array((int)($variable['VariableType']??-1),[1,2],true)) {
            throw new \RuntimeException('Positionsvariable muss ein vorhandener Integer-/Float-Wert sein.');
        }
        $action=(int)($variable['VariableCustomAction']??0);
        $hasAction=$action!==1 && ($action>1 || (int)($variable['VariableAction']??0)>0);
        if ($hasAction || (function_exists('HasAction') && HasAction($id))) {
            throw new \RuntimeException('Neue ID ist beschreibbar; ein Fahrbefehl ist keine sichere Positionsrueckmeldung.');
        }
        $name=mb_strtolower((string)($object['ObjectName']??''),'UTF-8');
        $ident=mb_strtolower((string)($object['ObjectIdent']??''),'UTF-8');
        $profile=strtolower((string)($variable['VariableCustomProfile']??'')
            ?: (string)($variable['VariableProfile']??''));
        $hints=$name.' '.$ident.' '.$profile;
        if (str_contains($hints,'lamelle') || str_contains($hints,'tilt')
            || str_contains($hints,'steuerung') || str_contains($hints,'action')
            || str_contains($hints,'befehl') || str_contains($hints,'beschatten')) {
            throw new \RuntimeException('Neue ID sieht wie Lamellen- oder Steuerwert aus, nicht wie Istposition.');
        }
        if (!str_contains($hints,'position') && !str_contains($hints,'stellung')
            && !str_contains($hints,'shutter') && !str_contains($hints,'behangen')
            && !str_contains($hints,'prozent') && !str_contains($hints,'intensity')) {
            throw new \RuntimeException('Variablenname/Profil lassen keine Istposition erkennen. Bitte Zuordnung pruefen.');
        }
        $fields=[];
        if (isset($before['ai']['variables']) || isset($after['ai']['variables'])) {
            $fields[]='ai.variables.position';
        }
        if (array_key_exists('aiPositionId',$before) || array_key_exists('aiPositionId',$after)) {
            $fields[]='aiPositionId';
        }
        return [
            'key'=>$key,
            'oldId'=>$oldNested?:$oldFlat,
            'newId'=>$id,
            'name'=>(string)($object['ObjectName']??''),
            'profile'=>$profile,
            'hasAction'=>$hasAction,
            'type'=>(int)$variable['VariableType'],
            'fields'=>$fields,
            'device'=>(string)($row['title']??$row['key']),
            'room'=>(string)($row['roomLabel']??$row['roomKey'])
        ];
    }

    /** Preserve the original PROD leaf byte-for-byte except named fields. */
    private function singlePositionProdCopy(array $prod,array $plan): array
    {
        $next=$prod;
        if (isset($next['rooms']) && is_array($next['rooms'])) $rooms=&$next['rooms'];
        else $rooms=&$next;
        [$room,$domain,$group,$key]=explode('|',(string)$plan['key'],4);
        $leaf=&$rooms[$room]['domains'][$domain][$key];
        if (!is_array($leaf) || ($leaf['ai']['type']??'')!=='blind') {
            throw new \RuntimeException('PROD-Jalousie seit Vorschau nicht mehr vorhanden.');
        }
        foreach ($plan['fields'] as $field) {
            if ($field==='aiPositionId') $leaf['aiPositionId']=(int)$plan['newId'];
            elseif ($field==='ai.variables.position') $leaf['ai']['variables']['position']=(int)$plan['newId'];
            else throw new \RuntimeException('Nicht freigegebene Felduebernahme: '.$field);
        }
        unset($leaf,$rooms);
        return $next;
    }

    public function SetSinglePositionProdKey(string $key): void
    {
        $this->WriteAttributeString('SinglePositionSelectedKey',$key);
        $this->WriteAttributeString('SinglePositionProdPreview','{}');
        $notice='Bitte fuer dieses einzelne Positionsfeld eine neue Vorschau starten.';
        $this->WriteAttributeString('SinglePositionProdNotice',$notice);
        $this->UpdateFormField('SinglePositionProdNotice','caption',$notice);
    }

    public function PreviewSinglePositionProd(string $key): void
    {
        $this->WriteAttributeString('SinglePositionProdPreview','{}');
        try {
            $sources=$this->scopedProdSources();
            $plan=$this->singlePositionProdPlan($sources['edit'],$sources['prod'],$key);
            $preview=[
                'time'=>time(),'key'=>$key,
                'editId'=>$sources['editId'],'prodId'=>$sources['prodId'],
                'editHash'=>hash('sha256',$sources['editSource']),
                'prodHash'=>hash('sha256',$sources['prodSource']),
                'planHash'=>hash('sha256',json_encode($plan,
                    JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))
            ];
            $this->WriteAttributeString('SinglePositionProdPreview',
                json_encode($preview,JSON_THROW_ON_ERROR));
            $notice=sprintf('EINZELFELD GÜLTIG: %s / %s: Position #%d → #%d. '
                .'Nur %s wird in PROD geaendert; andere Geraete- und Statusfelder, '
                .'KI-Rechte und Bestätigungen bleiben unberuehrt. '
                .'Eine physische Istposition wurde dadurch NICHT bestaetigt. '
                .'Vorschau 10 Minuten gueltig.',
                $plan['room'],$plan['device'],$plan['oldId'],$plan['newId'],
                implode(' + ',$plan['fields']));
            $this->WriteAttributeString('SinglePositionProdNotice',$notice);
            $this->UpdateFormField('SinglePositionProdNotice','caption',$notice);
            echo $notice;
        } catch (\Throwable $error) {
            $notice='EINZELFELD GESPERRT: '.$error->getMessage();
            $this->WriteAttributeString('SinglePositionProdNotice',$notice);
            $this->UpdateFormField('SinglePositionProdNotice','caption',$notice);
            echo $notice;
        }
    }

    public function ApplySinglePositionProd(string $key): void
    {
        try {
            $preview=json_decode($this->ReadAttributeString('SinglePositionProdPreview'),true);
            if (!is_array($preview) || !isset($preview['time'])
                || (int)$preview['time']>time()
                || time()-(int)$preview['time']>600
                || ($preview['key']??'')!==$key) {
                throw new \RuntimeException('Bitte zuerst die aktuelle Vorschau fuer genau dieses Geraet ausfuehren.');
            }
            $sources=$this->scopedProdSources();
            foreach (['edit','prod'] as $source) {
                if ((int)($preview[$source.'Id']??0)!==$sources[$source.'Id']
                    || !hash_equals((string)($preview[$source.'Hash']??''),
                        hash('sha256',$sources[$source.'Source']))) {
                    throw new \RuntimeException('EDIT/PROD seit Vorschau veraendert – bitte neu pruefen.');
                }
            }
            $plan=$this->singlePositionProdPlan($sources['edit'],$sources['prod'],$key);
            $planHash=hash('sha256',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            if (!hash_equals((string)($preview['planHash']??''),$planHash)) {
                throw new \RuntimeException('IPS-Metadaten oder Feldzuordnung seit Vorschau geaendert.');
            }
            $next=$this->singlePositionProdCopy($sources['prod'],$plan);
            if ($next===$sources['prod']) throw new \RuntimeException('Keine tatsaechliche Positionsaenderung.');
            if (IPS_GetScriptContent($sources['editId'])!==$sources['editSource']
                || IPS_GetScriptContent($sources['prodId'])!==$sources['prodSource']) {
                throw new \RuntimeException('Kataloge kurz vor Backup veraendert.');
            }
            $backup=IPS_CreateScript(0);
            if (!is_int($backup) || $backup<=0) {
                throw new \RuntimeException('PROD-Backup konnte nicht erzeugt werden.');
            }
            IPS_SetParent($backup,IPS_GetParent($sources['prodId']));
            IPS_SetName($backup,'RoomsCatalog_Einzelposition_Backup_'.date('Ymd_His').'_'.$backup);
            IPS_SetScriptContent($backup,$sources['prodSource']);
            if (IPS_GetScriptContent($backup)!==$sources['prodSource']) {
                throw new \RuntimeException('Backup-Verifikation fehlgeschlagen, PROD unveraendert.');
            }
            if (IPS_GetScriptContent($sources['editId'])!==$sources['editSource']
                || IPS_GetScriptContent($sources['prodId'])!==$sources['prodSource']) {
                throw new \RuntimeException('Kataloge nach Backup veraendert. Keine Uebernahme.');
            }
            $php="<?php\nreturn ".var_export($next,true).";\n";
            try {
                IPS_SetScriptContent($sources['prodId'],$php);
                if (IPS_GetScriptContent($sources['prodId'])!==$php) {
                    throw new \RuntimeException('PROD-Schreibverifikation fehlgeschlagen.');
                }
            } catch (\Throwable $writeError) {
                try {
                    IPS_SetScriptContent($sources['prodId'],$sources['prodSource']);
                    $restored=IPS_GetScriptContent($sources['prodId'])===$sources['prodSource'];
                } catch (\Throwable $restoreError) { $restored=false; }
                throw new \RuntimeException('Schreibfehler: '.$writeError->getMessage()
                    .($restored?' PROD wiederhergestellt.':' ACHTUNG: PROD-Wiederherstellung nicht bestaetigt!')
                    .' Backup #'.$backup);
            }
            $this->WriteAttributeString('SinglePositionProdPreview','{}');
            $notice=sprintf('ERFOLG: Nur %s / %s: %s nach PROD uebernommen '
                .'(ID #%d). PROD-Backup #%d. Sonstige Jalousie- und KI-Daten '
                .'unveraendert; EDIT weiterhin unveraendert.',
                $plan['room'],$plan['device'],implode(' + ',$plan['fields']),
                $plan['newId'],$backup);
            $this->WriteAttributeString('SinglePositionProdNotice',$notice);
            $this->UpdateFormField('SinglePositionProdNotice','caption',$notice);
            $this->reloadAllFromCatalog();
            $this->RefreshVisibleLists();
            echo $notice;
        } catch (\Throwable $error) {
            $notice='EINZELFELD-PROD GESPERRT: '.$error->getMessage();
            $this->WriteAttributeString('SinglePositionProdNotice',$notice);
            $this->UpdateFormField('SinglePositionProdNotice','caption',$notice);
            echo $notice;
        }
    }

    /**
     * Advisory only: a missing optional feedback is not an automatic
     * publication blocker. Reuse existing validation reasons so that red
     * rows explain WHY they are highlighted.
     */
    /**
     * Cross-device KNX feedback audit (catalog bindings only).
     * The same IPS ID may intentionally represent a group controller, so
     * this is a *warning*, never an automatic write or publish blocker.
     */
    private function sharedFeedbackAssignments(array $rows): array
    {
        $index=[];
        foreach ($rows as $row) {
            if ((string)($row['aiType']??'')!=='blind') continue;
            $key=$this->buildCompositeKey($row);
            if ($key===null) continue;
            $ai=$row['ai']??[];
            if (is_string($ai)) $ai=json_decode($ai,true)??[];
            $bindings=is_array($ai) && is_array($ai['variables']??null)
                ? $ai['variables'] : [];
            foreach (['status','position','tilt'] as $role) {
                $id=(int)($bindings[$role]??0);
                if ($id<=0) continue;
                $index[$id.'|'.$role][$key]=[
                    'path'=>$key,'room'=>(string)($row['roomLabel']??$row['roomKey']??''),
                    'device'=>(string)($row['title']??$row['key']??''),
                    'role'=>$role,'id'=>$id
                ];
            }
        }
        $warnings=[];
        $byDevice=[];
        foreach ($index as $members) {
            if (count($members)<2) continue;
            $sample=reset($members);
            $warning=[
                'id'=>$sample['id'],'role'=>$sample['role'],
                'devices'=>array_values($members),
                'reason'=>'Mehrere unterschiedliche Jalousien verwenden dieselbe KNX-'
                    .$sample['role'].'-Rueckmeldung #'.$sample['id']
                    .'. Nur bei nachweislich gemeinsamem Geraet korrekt.'
            ];
            $warnings[]=$warning;
            foreach ($members as $member) {
                $byDevice[$member['path']][]=$warning['reason'];
            }
        }
        return ['groups'=>$warnings,'byDevice'=>$byDevice];
    }

    private function catalogCheckHint(array $row,array $sharedWarnings=[]): array
    {
        $errors=$this->validateEntries([$row]);
        $notes=array_map(static function(string $error): string {
            $start=strpos($error,': ');
            $text=$start!==false ? substr($error,$start+2) : $error;
            if (preg_match('/ungueltige KI-Variable targetPosition \\(([-0-9]+)\\)/',$text,$match)===1) {
                return 'Optionale Sollposition: IPS-Variable #'.$match[1]
                    .' existiert nicht. Im Geraeteeditor unter Sollposition '
                    .'"Keine" waehlen oder eine gueltige Sollpositionsvariable auswaehlen.';
            }
            return $text;
        },$errors);
        if ($notes===[]) {
            if ($sharedWarnings!==[]) {
                return [
                    'status'=>'Pruefen (Mehrfach-ID)',
                    'reason'=>implode('; ',array_unique($sharedWarnings)),
                    'severity'=>'warning','codes'=>$sharedWarnings
                ];
            }
            return [
                'status'=>'OK','reason'=>'Keine offensichtlichen Konfigurationsprobleme erkannt.',
                'severity'=>'ok','codes'=>[]
            ];
        }
        $notes=array_merge($notes,$sharedWarnings);
        // Optional/demonstrably missing status semantics are an advisory;
        // non-existing variables, unsafe writable rights are critical.
        $onlySemantics=count(array_filter($notes,static fn(string $v): bool=>
            str_contains($v,'Offen/Geschlossen-Werte der Jalousie fehlen oder sind gleich.')))===count($notes);
        return [
            'status'=>$onlySemantics?'Pruefen (Hinweis)':'Pruefen',
            'reason'=>implode('; ',array_slice($notes,0,4)),
            'severity'=>$onlySemantics?'warning':'error',
            'codes'=>$notes
        ];
    }

    /**
     * Display metadata-only diagnostic text directly in an editable/copyable
     * IPS TextArea. Never reads live variable values or sends switch actions.
     */
    public function CheckRoomsCatalog(): void
    {
        try {
            $this->ensureRuntimeCatalogCurrent();
            $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
            $prodId=$this->ReadPropertyInteger('RoomsCatalogScriptID');
            if ($editId<=0 || $prodId<=0 || !IPS_ScriptExists($editId)
                || !IPS_ScriptExists($prodId)) {
                throw new \RuntimeException('EDIT- und PROD-Skripte muessen konfiguriert sein.');
            }
            $editSource=IPS_GetScriptContent($editId);
            $prodSource=IPS_GetScriptContent($prodId);
            $editCatalog=$this->loadRoomsCatalog($editId,'EDIT');
            $prodCatalog=$this->loadRoomsCatalog($prodId,'PROD');
            $rows=$this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($editCatalog));
            $shared=$this->sharedFeedbackAssignments($rows);
            $stats=['total'=>count($rows),'ok'=>0,'warning'=>0,'error'=>0,
                'technicallyPlausible'=>0,'technicallyNeedsReview'=>0,'technicallyInvalid'=>0,
                'notManuallyConfirmed'=>0,
                'sharedFeedbackGroups'=>count($shared['groups']),
                'devicesWithSharedFeedback'=>count($shared['byDevice'])];
            $problems=[];
            foreach ($rows as $row) {
                $path=$this->buildCompositeKey($row);
                $sharedWarnings=$shared['byDevice'][(string)$path]??[];
                $hint=$this->catalogCheckHint($row,$sharedWarnings);
                $technical=$this->assessTechnicalDevice($row);
                $status=(string)($technical['status']??'Manuell pruefen');
                if ($status==='Technisch plausibel') $stats['technicallyPlausible']++;
                elseif ($status==='Ungueltig') $stats['technicallyInvalid']++;
                else $stats['technicallyNeedsReview']++;
                if (empty($row['aiReviewed'])) $stats['notManuallyConfirmed']++;
                $stats[$hint['severity']]++;
                if ($hint['severity']==='ok' && $status==='Technisch plausibel') continue;

                $ai=$row['ai']??[];
                if (is_string($ai)) $ai=json_decode($ai,true)??[];
                $roles=is_array($ai) && is_array($ai['variables']??null)
                    ? $ai['variables'] : [];
                // Only ID/type/name/profile/action metadata for *configured*
                // roles; no credentials, current value, or raw source.
                $bindings=[];
                foreach ($roles as $role=>$id) {
                    $n=(int)$id;
                    if ($n<=0) continue;
                    $binding=['role'=>(string)$role,'id'=>$n,
                        'exists'=>function_exists('IPS_VariableExists') && IPS_VariableExists($n)];
                    if ($binding['exists'] && function_exists('IPS_GetVariable')
                        && function_exists('IPS_GetObject')) {
                        $v=IPS_GetVariable($n);
                        $o=IPS_GetObject($n);
                        $binding['name']=(string)($o['ObjectName']??'');
                        $binding['ident']=(string)($o['ObjectIdent']??'');
                        $binding['variableType']=(int)($v['VariableType']??-1);
                        $binding['profile']=(string)(($v['VariableCustomProfile']??'')
                            ?:($v['VariableProfile']??''));
                        $binding['hasAction']=function_exists('HasAction')
                            ? HasAction($n)
                            : ((int)($v['VariableCustomAction']??0)>0
                                || (int)($v['VariableAction']??0)>0);
                        $binding['associations']=[];
                        if ($binding['profile']!=='' && function_exists('IPS_GetVariableProfile')) {
                            try {
                                $profileData=IPS_GetVariableProfile($binding['profile']);
                                foreach (array_slice((array)($profileData['Associations']??[]),0,32)
                                    as $association) {
                                    if (!is_array($association)) continue;
                                    $value=$association['Value']??null;
                                    if (!is_int($value) && !is_float($value) && !is_string($value)) continue;
                                    $binding['associations'][]=[
                                        'value'=>$value,
                                        'name'=>mb_substr((string)($association['Name']??''),0,80)
                                    ];
                                }
                            } catch (\Throwable $ignored) {
                                // A missing profile is diagnostic, not a catalog failure.
                            }
                        }
                    }
                    $bindings[]=$binding;
                }
                $problems[]=[
                    'path'=>$this->buildCompositeKey($row),
                    'room'=>(string)($row['roomLabel']??$row['roomKey']??''),
                    'device'=>(string)($row['title']??$row['key']??''),
                    'type'=>(string)($row['aiType']??''),
                    'uiSeverity'=>$hint['severity'],
                    'uiReasons'=>$hint['codes'],
                    'technicalStatus'=>$status,
                    'technicalReasons'=>(string)($technical['reason']??''),
                    'technicalIssueCodes'=>array_values((array)($technical['issueCodes']??[])),
                    'statusSemanticEvidence'=>(string)($technical['statusSemanticEvidence']??''),
                    'positionFeedbackIndependent'=>!empty($technical['positionFeedbackIndependent']),
                    'sharedFeedbackWarnings'=>$sharedWarnings,
                    'capability'=>(string)($technical['capability']??''),
                    'confirmed'=>!empty($row['aiReviewed']),
                    'kiEnabled'=>!empty($row['kiEnabled']),
                    'kiReadOnly'=>!empty($row['kiReadOnly']),
                    'bindings'=>$bindings
                ];
            }
            $diff=$this->diagnosticCatalogDiff($editCatalog,$prodCatalog);
            $roomDiff=$this->buildRoomDiffEntries($prodCatalog,$editCatalog);
            $report=[
                'schema'=>'RoomsCatalogCheck/1',
                'createdAt'=>date(DATE_ATOM),
                'notice'=>'Lesende Metadatenanalyse. Keine aktuellen Variablenwerte, Passwoerter oder Schaltaktionen. Warnungen sperren EDIT→PROD NICHT.',
                'sources'=>['editId'=>$editId,'prodId'=>$prodId,
                    'editHash'=>hash('sha256',$editSource),
                    'prodHash'=>hash('sha256',$prodSource)],
                'statistics'=>$stats,
                'editProdDifferences'=>$diff,
                'roomMetadataDifferences'=>$roomDiff,
                'sharedFeedbackAssignments'=>$shared['groups'],
                'problems'=>$problems
            ];
            $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE
                |JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
            $summary=sprintf('%d Geraete: %d OK, %d Hinweise, %d Fehler; '
                .'%d technisch offen/ungueltig; %d Geraete- und %d Raumdifferenzen EDIT/PROD. '
                .'%d doppelt zugeordnete Rueckmeldegruppen bei %d Geraeten. '
                .'Die Diagnose kann unten kopiert und hier eingefuegt werden. '
                .'Keine automatische Korrektur, keine PROD-Sperre.',
                $stats['total'],$stats['ok'],$stats['warning'],$stats['error'],
                $stats['technicallyNeedsReview']+$stats['technicallyInvalid'],
                (int)$diff['total'],count($roomDiff),
                $stats['sharedFeedbackGroups'],$stats['devicesWithSharedFeedback']);
            $this->WriteAttributeString('CatalogCheckDebugOutput',$json);
            $this->WriteAttributeString('CatalogCheckDebugSummary',$summary);
            $this->UpdateFormField('CatalogCheckDebugOutput','value',$json);
            $this->UpdateFormField('CatalogCheckDebugSummary','caption',$summary);
            $this->RefreshVisibleLists();
            echo $summary;
        } catch (\Throwable $error) {
            $summary='ROOMSCATALOG-PRUEFUNG FEHLGESCHLAGEN: '.$error->getMessage();
            $this->WriteAttributeString('CatalogCheckDebugSummary',$summary);
            $this->UpdateFormField('CatalogCheckDebugSummary','caption',$summary);
            echo $summary;
        }
    }

    /**
     * Safe semantic source: use ONLY a configured status variable with
     * ~ShutterStatus.KNX and unambiguous numeric profile associations.
     * Commands (0/100) and position feedback are not used as status enums.
     */
    private function knxShutterStatusValues(int $statusId): ?array
    {
        if ($statusId<=0 || !function_exists('IPS_VariableExists')
            || !function_exists('IPS_GetVariable') || !function_exists('IPS_GetVariableProfile')
            || !IPS_VariableExists($statusId)) return null;
        $v=IPS_GetVariable($statusId);
        if (!is_array($v) || (int)($v['VariableType']??-1)!==1) return null;
        $profile=(string)(($v['VariableCustomProfile']??'') ?: ($v['VariableProfile']??''));
        if (mb_strtolower($profile,'UTF-8')!=='~shutterstatus.knx') return null;
        try {
            $meta=IPS_GetVariableProfile($profile);
        } catch (\Throwable $ignored) {
            return null;
        }
        $values=[];
        foreach ((array)($meta['Associations']??[]) as $assoc) {
            if (!is_array($assoc) || !isset($assoc['Value'])) continue;
            $label=mb_strtolower(trim((string)($assoc['Name']??'')),'UTF-8');
            $label=str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],$label);
            $kind=match ($label) {
                'offen','geoeffnet','open'=>'open',
                'geschlossen','closed'=>'closed',
                default=>null
            };
            if ($kind===null) continue;
            $numeric=$assoc['Value'];
            $isExactInt=is_int($numeric)
                || (is_float($numeric) && is_finite($numeric) && floor($numeric)===$numeric)
                || (is_string($numeric) && preg_match('/^-?\d+$/D',$numeric)===1);
            if (!$isExactInt) return null;
            $value=(int)$numeric;
            if (isset($values[$kind]) && $values[$kind]!==$value) return null;
            $values[$kind]=$value;
        }
        if (!isset($values['open'],$values['closed'])
            || $values['open']===$values['closed']) return null;
        return $values;
    }

    /**
     * Conservative candidates: ONLY legacy 0/100 meanings on explicitly
     * assigned KNX status enums. Different custom mappings are never overwritten.
     */
    private function knxShutterStatusRepairPlan(array $catalog): array
    {
        $rooms=$this->extractRoomsFromCatalog($catalog);
        $plan=[];
        foreach ($rooms as $roomKey=>$room) {
            foreach ((array)($room['domains']['jalousie']??[]) as $key=>$leaf) {
                if (!is_array($leaf)) continue;
                $ai=$leaf['ai']??[];
                if (!is_array($ai) || ($ai['type']??'')!=='blind'
                    || ($ai['review']['status']??'')==='confirmed') continue;
                $statusId=(int)($ai['variables']['status']??0);
                $actionId=(int)($ai['variables']['action']??0);
                $positionId=(int)($ai['variables']['position']??0);
                if ($statusId<=0 || $statusId===$actionId || $statusId===$positionId) continue;
                $known=$this->knxShutterStatusValues($statusId);
                if ($known===null) continue;
                $old=(array)($ai['stateMapping']??[]);
                // 0/100 are the old command/position endpoint convention.
                // Do not silently replace an explicitly custom state enum.
                if (!in_array($old['open']??null,[0,'0'],true)
                    || !in_array($old['closed']??null,[100,'100'],true)
                    || ($known['open']===0 && $known['closed']===100)) continue;
                $plan[]=[
                    'roomKey'=>(string)$roomKey,'key'=>(string)$key,
                    'device'=>(string)($leaf['title']??$key),
                    'statusId'=>$statusId,
                    'old'=>['open'=>0,'closed'=>100],
                    'new'=>['open'=>$known['open'],'closed'=>$known['closed']]
                ];
            }
        }
        return $plan;
    }

    private function knxStatusRepairSources(): array
    {
        $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $prodId=$this->ReadPropertyInteger('RoomsCatalogScriptID');
        if ($editId<=0 || $prodId<=0 || $editId===$prodId
            || !IPS_ScriptExists($editId) || !IPS_ScriptExists($prodId)) {
            throw new \RuntimeException('EDIT/PROD muessen zwei verschiedene gueltige Skripte sein.');
        }
        $editSource=(string)IPS_GetScriptContent($editId);
        $prodSource=(string)IPS_GetScriptContent($prodId);
        if (!RCCDeviceTechnicalAudit::isLiteralArraySource($editSource)) {
            throw new \RuntimeException('EDIT ist kein statisches PHP-Array.');
        }
        $catalog=$this->loadRoomsCatalog($editId,'EDIT');
        if ($this->extractRoomsFromCatalog($catalog)===[]) {
            throw new \RuntimeException('EDIT-Katalog leer oder unlesbar.');
        }
        return compact('editId','prodId','editSource','prodSource','catalog');
    }

    /** No write – identify exact candidate devices and show before/after. */
    public function PreviewKnxStatusRepair(): void
    {
        try {
            $src=$this->knxStatusRepairSources();
            $plan=$this->knxShutterStatusRepairPlan($src['catalog']);
            $data=[
                'time'=>time(),
                'editHash'=>hash('sha256',$src['editSource']),
                'prodHash'=>hash('sha256',$src['prodSource']),
                'plan'=>$plan
            ];
            $text=json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE
                |JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
            $msg=count($plan).' eindeutige KNX-Statuszuordnungen zur EDIT-Korrektur gefunden. '
                .'Nur open=0 / closed=100 wird auf die Profilwerte umgestellt. '
                .'Keine Fahraktionen, KI-Freigaben, Bestaetigungen oder PROD-Aenderung. '
                .'Bitte Vorschau kontrollieren und dann ausdruecklich uebernehmen.';
            $this->WriteAttributeString('KnxStatusRepairPreview',json_encode($data,JSON_THROW_ON_ERROR));
            $this->WriteAttributeString('KnxStatusRepairPreviewText',$text);
            $this->WriteAttributeString('KnxStatusRepairSummary',$msg);
            $this->UpdateFormField('KnxStatusRepairSummary','caption',$msg);
            $this->UpdateFormField('KnxStatusRepairPreviewText','value',$text);
            echo $msg;
        } catch (\Throwable $error) {
            $this->WriteAttributeString('KnxStatusRepairPreview','{}');
            echo 'KNX-STATUS-VORSCHAU FEHLGESCHLAGEN: '.$error->getMessage();
        }
    }

    /**
     * Explicit operator action. EDIT only, verified backup, source-hash
     * and freshly rebuilt IPS metadata guard, readback and rollback.
     */
    public function ApplyKnxStatusRepair(): void
    {
        try {
            $preview=json_decode($this->ReadAttributeString('KnxStatusRepairPreview'),true);
            if (!is_array($preview) || !isset($preview['time'])
                || time()-(int)$preview['time']>900) {
                throw new \RuntimeException('Zuerst aktuelle Vorschau ausfuehren (15 Minuten gueltig).');
            }
            $src=$this->knxStatusRepairSources();
            if (hash('sha256',$src['editSource'])!==($preview['editHash']??'')
                || hash('sha256',$src['prodSource'])!==($preview['prodHash']??'')) {
                throw new \RuntimeException('EDIT/PROD nach Vorschau veraendert. Bitte Vorschau wiederholen.');
            }
            $plan=$this->knxShutterStatusRepairPlan($src['catalog']);
            if ($plan===[] || $plan!==($preview['plan']??null)) {
                throw new \RuntimeException('Keine unveraenderten eindeutigen Vorschlaege; Vorschau wiederholen.');
            }
            $next=$src['catalog'];
            if (isset($next['rooms']) && is_array($next['rooms'])) {
                $rooms=&$next['rooms'];
            } else {
                $rooms=&$next;
            }
            foreach ($plan as $item) {
                $leaf=&$rooms[$item['roomKey']]['domains']['jalousie'][$item['key']];
                if (!is_array($leaf) || !is_array($leaf['ai']??null)
                    || (int)($leaf['ai']['variables']['status']??0)!==$item['statusId']
                    || !in_array($leaf['ai']['stateMapping']['open']??null,[0,'0'],true)
                    || !in_array($leaf['ai']['stateMapping']['closed']??null,[100,'100'],true)) {
                    throw new \RuntimeException('Unerwartete Geraeteaenderung, EDIT bleibt unveraendert.');
                }
                $leaf['ai']['stateMapping']['open']=$item['new']['open'];
                $leaf['ai']['stateMapping']['closed']=$item['new']['closed'];
                unset($leaf);
            }
            unset($rooms);
            $php="<?php\nreturn ".var_export($next,true).";\n";
            if (IPS_GetScriptContent($src['editId'])!==$src['editSource']
                || IPS_GetScriptContent($src['prodId'])!==$src['prodSource']) {
                throw new \RuntimeException('Katalog vor dem Backup geaendert.');
            }
            $backup=IPS_CreateScript(0);
            if (!is_int($backup) || $backup<=0) {
                throw new \RuntimeException('EDIT-Backup konnte nicht erzeugt werden.');
            }
            IPS_SetParent($backup,IPS_GetParent($src['editId']));
            IPS_SetName($backup,'RoomsCatalogEdit_Backup_KNX_Status_'.date('Ymd_His').'_'.$backup);
            IPS_SetScriptContent($backup,$src['editSource']);
            if (IPS_GetScriptContent($backup)!==$src['editSource']) {
                throw new \RuntimeException('EDIT-Backup-Verifikation fehlgeschlagen. Keine Aenderung.');
            }
            if (IPS_GetScriptContent($src['editId'])!==$src['editSource']
                || IPS_GetScriptContent($src['prodId'])!==$src['prodSource']) {
                throw new \RuntimeException('Katalog nach Backup geaendert. Keine Aenderung.');
            }
            try {
                if (IPS_SetScriptContent($src['editId'],$php)===false
                    || IPS_GetScriptContent($src['editId'])!==$php) {
                    throw new \RuntimeException('EDIT-Schreibverifikation fehlgeschlagen.');
                }
            } catch (\Throwable $writeError) {
                $restored=false;
                try {
                    IPS_SetScriptContent($src['editId'],$src['editSource']);
                    $restored=IPS_GetScriptContent($src['editId'])===$src['editSource'];
                } catch (\Throwable $ignored) { }
                throw new \RuntimeException('EDIT-Schreibfehler: '.$writeError->getMessage()
                    .($restored ? ' Ursprung wiederhergestellt.' : ' Backup #'.$backup.' manuell pruefen.'));
            }
            $this->WriteAttributeString('KnxStatusRepairPreview','{}');
            $msg='ERFOLG: '.count($plan).' KNX-Statuszuordnungen ausschliesslich in EDIT '
                .'an Profilwerte angepasst. EDIT-Backup #'.$backup.'. PROD UNVERAENDERT. '
                .'Jetzt ROOMSCATALOG UEBERPRUEFEN und danach bewusst EDIT NACH PROD.';
            $this->WriteAttributeString('KnxStatusRepairSummary',$msg);
            $this->UpdateFormField('KnxStatusRepairSummary','caption',$msg);
            $this->reloadAllFromCatalog();
            $this->RefreshVisibleLists();
            echo $msg;
        } catch (\Throwable $error) {
            echo 'KNX-STATUS-UEBERNAHME ABGEBROCHEN: '.$error->getMessage();
        }
    }

    /**
     * Speichert den Edit-Katalog 1:1 nach PROD.
     */
    /**
     * Publish the ENTIRE EDIT catalog to PROD, unchanged.
     *
     * This is intentionally a user-confirmed copy, NOT a complete-device
     * validation. Each device's optional status, position or other roles
     * may remain incomplete; the device editor owns those checks.
     *
     * Safeguards: separate scripts, non-empty literal-array EDIT source,
     * independent verified PROD backup, race checks, read-back and rollback.
     */
    public function SaveEditToProd(): void
    {
        try {
            $prodId=$this->ReadPropertyInteger('RoomsCatalogScriptID');
            $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
            if ($prodId<=0 || $editId<=0 || $prodId===$editId
                || !IPS_ScriptExists($prodId) || !IPS_ScriptExists($editId)) {
                throw new \RuntimeException('Bitte zwei verschiedene gueltige EDIT-/PROD-Skripte einstellen.');
            }
            $editSource=(string)IPS_GetScriptContent($editId);
            $prodSource=(string)IPS_GetScriptContent($prodId);
            if (!RCCDeviceTechnicalAudit::isLiteralArraySource($editSource)) {
                throw new \RuntimeException('EDIT muss ein statisches PHP-Array zurueckgeben; keine dynamischen Skriptbefehle.');
            }
            $editCatalog=$this->loadRoomsCatalog($editId,'EDIT');
            if ($editCatalog===[] || $this->extractRoomsFromCatalog($editCatalog)===[]) {
                throw new \RuntimeException('EDIT ist leer oder unlesbar. PROD bleibt unveraendert.');
            }
            if (IPS_GetScriptContent($editId)!==$editSource
                || IPS_GetScriptContent($prodId)!==$prodSource) {
                throw new \RuntimeException('EDIT/PROD hat sich beim Lesen veraendert. Bitte erneut versuchen.');
            }
            if ($editSource===$prodSource) {
                echo 'EDIT und PROD sind bereits identisch. Keine Uebernahme notwendig.';
                return;
            }

            // Back up the exact original PROD script content BEFORE editing.
            $backup=IPS_CreateScript(0);
            if (!is_int($backup) || $backup<=0) {
                throw new \RuntimeException('PROD-Backup konnte nicht erstellt werden.');
            }
            IPS_SetParent($backup,IPS_GetParent($prodId));
            IPS_SetName($backup,'RoomsCatalog_Backup_before_full_EDIT_to_PROD_'.date('Ymd_His').'_'.$backup);
            IPS_SetScriptContent($backup,$prodSource);
            if (IPS_GetScriptContent($backup)!==$prodSource) {
                throw new \RuntimeException('PROD-Backup nicht verifiziert; keine Uebernahme.');
            }
            if (IPS_GetScriptContent($editId)!==$editSource
                || IPS_GetScriptContent($prodId)!==$prodSource) {
                throw new \RuntimeException('EDIT/PROD nach der Sicherung veraendert; PROD nicht beschrieben.');
            }
            try {
                // 1:1: includes EVERY room, device, optional field, and KI
                // setting exactly as stored in EDIT, without reserializing.
                IPS_SetScriptContent($prodId,$editSource);
                if (IPS_GetScriptContent($prodId)!==$editSource) {
                    throw new \RuntimeException('PROD-Schreibverifikation fehlgeschlagen.');
                }
            } catch (\Throwable $writeError) {
                $restored=false;
                try {
                    IPS_SetScriptContent($prodId,$prodSource);
                    $restored=IPS_GetScriptContent($prodId)===$prodSource;
                } catch (\Throwable $restoreError) { /* backup remains available */ }
                throw new \RuntimeException('PROD-Schreibfehler: '.$writeError->getMessage()
                    .($restored?' Original wurde wiederhergestellt.'
                        :' ACHTUNG: Wiederherstellung nicht bestaetigt!')
                    .' Backup #'.$backup);
            }
            $this->WriteAttributeString('PartialProdPublishPreview','{}');
            $this->WriteAttributeString('SinglePositionProdPreview','{}');
            $this->WriteAttributeString('AutoRepairPreview','{}');
            $this->WriteAttributeString('KnxStatusRepairPreview','{}');
            $this->logDebug('SaveEditToProd: vollstaendiger EDIT-Skriptinhalt 1:1 nach PROD, Backup #'.$backup);
            echo 'ERFOLG: Gesamter RoomsCatalog EDIT 1:1 nach PROD kopiert. '
                .'Alle Raeume, Geraete, Variablen, KI-Freigaben und auch unvollstaendige '
                .'Statusfelder wurden unveraendert uebernommen. '
                .'Keine vollstaendige Geraetepruefung erzwungen. PROD-Backup #'.$backup.'.';
            $this->ReloadCatalog();
        } catch (\Throwable $error) {
            $this->logDebug('SaveEditToProd: '.$error->getMessage());
            echo 'EDIT NACH PROD ABGEBROCHEN: '.$error->getMessage();
        }
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
        foreach (RCCDeviceTypeRegistry::fields() as $virtualField) $clone[$virtualField] = 0;
        foreach (RCCDeviceTypeRegistry::states() as $virtualField) $clone[$virtualField] = '';
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


    /** Kein Schreibzugriff: KI-Freigabe und Modellbereitschaft sind getrennt. */

    /**
     * Technical audit is read-only. No live values, passwords, switching,
     * or automatic semantic confirmation. The bounded IPS scanner is reused.
     */
    private function assessTechnicalDevice(array $row): array
    {
        $key = $this->buildCompositeKey($row);
        $type = (string)($row['aiType'] ?? '');
        $base = [
            'key'=>(string)$key,
            'room'=>(string)($row['roomLabel'] ?? $row['roomKey'] ?? ''),
            'title'=>(string)($row['title'] ?? $row['key'] ?? ''),
            'type'=>$type,
            'selected'=>false,
            'manuallyConfirmed'=>!empty($row['aiReviewed']),
            'fingerprint'=>'',
            'manualEligible'=>false,
            'roleCount'=>0
        ];
        if ($key === null || !array_key_exists($type,RCCDeviceTypeRegistry::roles()) || $type === 'generic') {
            return $base + [
                'capability'=>'Technische Zuordnung pruefen',
                'status'=>$type === '' ? 'Legacy' : 'Manuell pruefen',
                'reason'=>$type === '' ? 'Kein ai.type hinterlegt – Legacy-Import pruefen'
                    : 'Unbekannter/unspezifischer Geraetetyp, manuell zuordnen'
            ];
        }
        $variables = $this->getScopedIpsVariables($row);
        // Virtuelle Editorwerte koennen aus Legacy-Feldern stammen.
        // Fuer V3.4 nur wirklich im neuen ai.variables gespeicherte
        // Rollen als typisierte Bindungen bestaetigen.
        $sourceAi = $row['ai'] ?? [];
        if (is_string($sourceAi)) $sourceAi = json_decode($sourceAi,true);
        $explicit = is_array($sourceAi) && is_array($sourceAi['variables'] ?? null)
            ? $sourceAi['variables'] : [];
        $typedRow = $row;
        foreach (RCCDeviceTypeRegistry::fields() as $role=>$field) {
            if (!isset($explicit[$role]) || (int)$explicit[$role] <= 0) {
                $typedRow[$field] = 0;
            }
        }
        $assessment = RCCDeviceTechnicalAudit::assess(
            $typedRow,
            array_intersect_key(RCCDeviceTypeRegistry::fields(),array_fill_keys(RCCDeviceTypeRegistry::roles()[$type],true)),
            $variables,
            $this->minimumReadingRoles($type),
            fn(array $variable,string $property): int => $this->suggestionScore($variable,$property),
            fn(int $id): bool => function_exists('IPS_VariableExists') && IPS_VariableExists($id)
        );
        // Der alte V3.3.8-Check beruecksichtigte auch virtuelle
        // Legacy-Felder. Ein fehlendes ai.variables-Feld ist hingegen
        // eine offene Migration, nicht die Aussage "IPS-Geraet defekt".
        $missingTypedRoles = [];
        // Nur wirklich benoetigte Rueckmeldungen nachfordern:
        // ein Licht mit ai.variables.power braucht nicht zusaetzlich
        // ein legacy-"status" Alias auf dieselbe Statusvariable.
        foreach ($this->minimumReadingRoles($type) as $alternatives) {
            $hasTypedRole = false;
            foreach ($alternatives as $role) {
                $field = RCCDeviceTypeRegistry::fields()[$role];
                if ((int)($typedRow[$field] ?? 0) > 0) $hasTypedRole = true;
            }
            if ($hasTypedRole) continue;
            foreach ($alternatives as $role) {
                $field = RCCDeviceTypeRegistry::fields()[$role];
                if ((int)($row[$field] ?? 0) > 0) $missingTypedRoles[] = $role;
            }
        }
        $assessment['mappedExplicitRoles'] = count(array_filter(
            array_intersect_key($explicit,array_fill_keys(RCCDeviceTypeRegistry::roles()[$type],true)),
            static fn($id): bool => (int)$id > 0
        ));
        $assessment['legacyRoleCandidates'] = count($missingTypedRoles);
        if ($missingTypedRoles !== []) {
            $assessment['issueCodes'][] = 'legacy_fields_not_mapped';
            if ($assessment['status'] === 'Technisch plausibel') {
                $assessment['status'] = 'Manuell pruefen';
            }
            $assessment['manualEligible'] = false;
            $assessment['reason'] .= '; Legacy-Felder nicht in ai.variables: '
                .implode(', ',$missingTypedRoles);
        }
        // KI-Schreibrechte sind besonders kritisch. Ein plausibler
        // Istwert allein beweist KEINE funktionsfaehige Schaltaktion.
        if (!empty($row['kiEnabled']) && empty($row['kiReadOnly'])) {
            $writeId = $this->aiWriteId($row);
            if ($writeId <= 0 || !function_exists('IPS_VariableExists') || !IPS_VariableExists($writeId)) {
                $assessment['status'] = 'Ungueltig';
                $assessment['manualEligible'] = false;
                $assessment['reason'] .= '; KI-Schreibfreigabe ohne gueltige Schaltvariable';
                $assessment['issueCodes'][] = 'write_variable_invalid';
            } else {
                $writeMetadata = null;
                foreach ($variables as $var) {
                    if ((int)($var['id'] ?? 0) === $writeId) { $writeMetadata = $var; break; }
                }
                if (!is_array($writeMetadata) || empty($writeMetadata['action'])
                    || (function_exists('HasAction') && !HasAction($writeId))) {
                    $assessment['status'] = 'Manuell pruefen';
                    $assessment['manualEligible'] = false;
                    $assessment['reason'] .= '; KI-Schaltvariable #'.$writeId.' ohne nachgewiesene IPS-Aktion';
                    $assessment['issueCodes'][] = 'write_action_unproven';
                }
            }
        }
        $assessment['capability']=$this->classifyTechnicalCapability(
            $row,$assessment,$variables,$explicit
        );
        return array_merge($base,$assessment);
    }


    /**
     * Distinguish a legitimate KNX blind command-only configuration from
     * an incorrect mapping, without treating commands as position feedback.
     * This is ONLY a reporting label: review status and permissions stay
     * exactly as before; no device is marked technically validated.
     */
    private function classifyTechnicalCapability(
        array $row,array $assessment,array $scoped,array $explicit
    ): string {
        if (($row['aiType']??'')==='fan'
            && ($assessment['status']??'')==='Technisch plausibel') {
            $control=(int)($explicit['control']??0);
            $power=(int)($explicit['power']??0);
            $status=(int)($explicit['status']??0);
            // Steuerwert ist kein bestaetigter Istzustand: auch bei
            // derselben gemeinsamen ID eindeutig als solcher ausweisen.
            if ($control>0 && ($power<=0 || $power===$control)
                && ($status<=0 || $status===$control)) {
                return 'Nur Steuerungswert (kein separater Iststatus)';
            }
        }
        if (($assessment['status']??'')==='Technisch plausibel') {
            return 'Technisch plausibel (nicht fachlich bestaetigt)';
        }
        if (($row['aiType']??'')!=='blind'
            || ($assessment['status']??'')!=='Manuell pruefen') {
            return 'Technische Zuordnung pruefen';
        }
        $codes=array_values(array_unique((array)($assessment['issueCodes']??[])));
        sort($codes,SORT_STRING);
        $expected=['missing_required_role','state_mapping_missing'];
        sort($expected,SORT_STRING);
        if ($codes!==$expected) return 'Technische Zuordnung pruefen';
        $actionId=(int)($explicit['action']??0);
        if ($actionId<=0 || $actionId!==(int)($row['wert']??0)
            || !empty($explicit['position']) || !empty($explicit['status'])
            || !empty($explicit['targetPosition'])
            || !empty($explicit['open'])) return 'Technische Zuordnung pruefen';
        $matched=null;
        foreach ($scoped as $variable) {
            if ((int)($variable['id']??0)===$actionId) {
                $matched=$variable;
                break;
            }
        }
        if (!is_array($matched) || empty($matched['action'])
            || (int)($matched['type']??-1)!==1) {
            return 'Technische Zuordnung pruefen';
        }
        $profile=strtolower((string)($matched['profile']??''));
        if (!str_contains($profile,'steuerung')
            || !(str_contains($profile,'rolladen')
                || str_contains($profile,'jalousie'))) {
            return 'Technische Zuordnung pruefen';
        }
        $commands=[];
        foreach ((array)($matched['associations']??[]) as $association) {
            if (!is_array($association)) continue;
            $label=mb_strtolower(trim((string)($association['name']??'')),'UTF-8');
            $label=str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],$label);
            $commands[$label]=true;
        }
        if (!isset($commands['oeffnen'],$commands['schliessen'],$commands['stop'])) {
            return 'Technische Zuordnung pruefen';
        }
        return 'Nur Steuerung (Ist-Rueckmeldung nicht nachgewiesen)';
    }

    /** Field names only – never include device values or secrets in diff reports. */
    private function deviceDiffReport(array $prod, array $edit): array
    {
        $rows = [];
        $counts = ['new'=>0,'removed'=>0,'changed'=>0];
        foreach ($this->buildDiffEntries($prod,$edit) as $row) {
            if (!is_array($row)) continue;
            $changed = (string)($row['diffKeys'] ?? '');
            $kind = $changed === 'new' ? 'new' : ($changed === 'removed' ? 'removed' : 'changed');
            $counts[$kind]++;
            $rows[] = [
                'room'=>(string)($row['roomLabel'] ?? $row['roomKey'] ?? ''),
                'title'=>(string)($row['title'] ?? $row['key'] ?? ''),
                'type'=>$kind,
                'path'=>(string)$this->buildCompositeKey($row),
                'fields'=>$changed
            ];
        }
        return ['rows'=>$rows,'counts'=>$counts];
    }

    private function deviceTechnicalSnapshot(): array
    {
        $stored = json_decode($this->ReadAttributeString('TechnicalDeviceSnapshot'),true);
        if (!is_array($stored) || !is_array($stored['rows'] ?? null)
            || !is_string($stored['editHash'] ?? null)
            || !is_string($stored['prodHash'] ?? null)
            || time() - (int)($stored['time'] ?? 0) > 3600) return [];
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $prodId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        if ($editId <= 0 || $prodId <= 0
            || !IPS_ScriptExists($editId) || !IPS_ScriptExists($prodId)
            || !hash_equals($stored['editHash'],hash('sha256',(string)IPS_GetScriptContent($editId)))
            || !hash_equals($stored['prodHash'],hash('sha256',(string)IPS_GetScriptContent($prodId)))) {
            return [];
        }
        return $stored;
    }



    /**
     * Read-only export. Deliberately excludes script contents, tokens,
     * passwords, current variable values and network access information.
     */
    private function safeRepairSources(): array
    {
        $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $prodId=$this->ReadPropertyInteger('RoomsCatalogScriptID');
        if ($editId<=0 || $prodId<=0 || $editId===$prodId
            || !IPS_ScriptExists($editId) || !IPS_ScriptExists($prodId)) {
            throw new \RuntimeException('EDIT/PROD-Skripte fehlen oder verwenden dieselbe ID.');
        }
        $editSource=(string)IPS_GetScriptContent($editId);
        $prodSource=(string)IPS_GetScriptContent($prodId);
        if (!RCCDeviceTechnicalAudit::isLiteralArraySource($editSource)
            || !RCCDeviceTechnicalAudit::isLiteralArraySource($prodSource)) {
            throw new \RuntimeException('Nicht-statische PHP-Kataloge werden aus Sicherheitsgruenden nicht automatisch veraendert.');
        }
        return [
            'editId'=>$editId,'prodId'=>$prodId,
            'editSource'=>$editSource,'prodSource'=>$prodSource,
            'catalog'=>$this->loadRoomsCatalog($editId,'EDIT')
        ];
    }

    private function safeRepairPlan(array $catalog): array
    {
        $rows=$this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($catalog));
        // buildEntryRow serializes nested ai to a JSON string for IPS form lists.
        // Normalize it BEFORE generating metadata, ownership or repair plans.
        // Otherwise every export misleadingly shows roles=[] and every repair
        // proposal is silently suppressed, despite typed devices in the source.
        foreach ($rows as &$entry) {
            if (is_string($entry['ai']??null)) {
                $decoded=json_decode($entry['ai'],true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('Geraet hat unlesbare ai-Metadaten: '
                        .(string)($this->buildCompositeKey($entry)??'unbekannt'));
                }
                $entry['ai']=$decoded;
            }
        }
        unset($entry);
        $devices=[];
        $changes=[];
        $counts=[];
        $owners=[];
        // Never attach a candidate that already belongs to another device,
        // even if it appears only in that device's legacy fields.
        foreach ($rows as $sourceRow) {
            $sourceKey=$this->buildCompositeKey($sourceRow);
            if ($sourceKey===null) continue;
            $ids=(array)($sourceRow['ai']['variables']??[]);
            foreach (['state','toggle','value','set','wert','stellung','ist','soll','statusId','controlId'] as $legacyName) {
                if ((int)($sourceRow[$legacyName]??0)>0) $ids[]=$sourceRow[$legacyName];
            }
            foreach ($ids as $rawId) {
                $id=is_numeric($rawId) ? (int)$rawId : 0;
                if ($id>0) $owners[$id][$sourceKey]=true;
            }
        }
        foreach ($rows as $row) {
            $key=$this->buildCompositeKey($row);
            if ($key===null) continue;
            $audit=$this->assessTechnicalDevice($row);
            $scope=$this->getScopedIpsVariables($row);
            $reserved=[];
            foreach ($owners as $id=>$deviceOwners) {
                if (count($deviceOwners)>1 || !isset($deviceOwners[$key])) $reserved[$id]=true;
            }
            $bindings=RCCSafeAutoRepair::propose(
                $row,$scope,
                fn(array $variable,string $role):int=>$this->suggestionScore($variable,$role),
                $reserved
            );
            if ($bindings!==[]) $changes[$key]=$bindings;
            $status=(string)($audit['status']??'Unbekannt');
            $counts[$status]=($counts[$status]??0)+1;
            $legacy=[];
            foreach (['state','toggle','value','set','wert','stellung','ist','soll','statusId','controlId','tiltId'] as $field) {
                $id=(int)($row[$field]??0);
                if ($id>0) $legacy[$field]=$id;
            }
            $ips=[];
            foreach (array_slice($scope,0,80) as $variable) {
                $ips[]=array_intersect_key($variable,array_flip([
                    'id','name','ident','type','profile','action','associations'
                ]));
            }
            $devices[]=[
                'key'=>$key,'room'=>(string)($row['roomLabel']??''),
                'device'=>(string)($row['title']??$row['key']??''),
                'type'=>(string)($row['aiType']??''),
                'status'=>$status,
                'capability'=>(string)($audit['capability']??'Technische Zuordnung pruefen'),
                'reasons'=>(string)($audit['reason']??''),
                'issueCodes'=>(array)($audit['issueCodes']??[]),
                'roles'=>(array)($row['ai']['variables']??[]),
                'stateMapping'=>(array)($row['ai']['stateMapping']??[]),
                'reviewStatus'=>(string)($row['ai']['review']['status']??''),
                'kiEnabled'=>!empty($row['kiEnabled']),
                'kiReadOnly'=>!empty($row['kiReadOnly']),
                'legacyObjectIds'=>$legacy,
                'ipsScopeCount'=>count($scope),
                'ipsScopeTruncated'=>count($scope)>80,
                'ipsVariables'=>$ips,
                'safeSuggestedBindings'=>$bindings
            ];
        }
        return ['devices'=>$devices,'changes'=>$changes,'counts'=>$counts];
    }

    /**
     * Read-only field-path diff for ChatGPT diagnostics, never sends raw
     * script bodies, current IPS values, passwords, or field contents.
     */
    private static function diagnosticFieldPathDiff(
        mixed $before,mixed $after,string $prefix,array &$paths,int $limit=60
    ): void {
        if (count($paths)>=$limit) return;
        if (is_string($before) && is_string($after)
            && ($before[0]??'')==='{' && ($after[0]??'')==='{') {
            $decodedBefore=json_decode($before,true);
            $decodedAfter=json_decode($after,true);
            if (is_array($decodedBefore) && is_array($decodedAfter)) {
                $before=$decodedBefore;
                $after=$decodedAfter;
            }
        }
        if (is_array($before) && is_array($after)) {
            $keys=array_unique(array_merge(array_keys($before),array_keys($after)));
            foreach ($keys as $key) {
                if (count($paths)>=$limit) return;
                $field=$prefix==='' ? (string)$key : $prefix.'.'.(string)$key;
                if (!array_key_exists($key,$before) || !array_key_exists($key,$after)) {
                    $paths[]=$field;
                    continue;
                }
                self::diagnosticFieldPathDiff($before[$key],$after[$key],$field,$paths,$limit);
            }
            return;
        }
        if ($before!==$after && $prefix!=='') $paths[]=$prefix;
    }

    private function diagnosticCatalogDiff(array $editCatalog,array $prodCatalog): array
    {
        $edit=$this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($editCatalog));
        $prod=$this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($prodCatalog));
        $rows=$this->deviceDiffReport($prod,$edit);
        $editByKey=[];
        $prodByKey=[];
        foreach ($edit as $row) {
            $key=$this->buildCompositeKey($row);
            if ($key!==null) $editByKey[$key]=$row;
        }
        foreach ($prod as $row) {
            $key=$this->buildCompositeKey($row);
            if ($key!==null) $prodByKey[$key]=$row;
        }
        $details=[];
        foreach ($rows['rows'] as $diff) {
            $key=(string)($diff['path']??'');
            $changed=[];
            if (($diff['type']??'')==='changed'
                && isset($prodByKey[$key],$editByKey[$key])) {
                $from=$prodByKey[$key];
                $to=$editByKey[$key];
                // Do not include UI-only, selected, or display metadata.
                foreach (['selected','rowColor','diffKeys'] as $field) {
                    unset($from[$field],$to[$field]);
                }
                self::diagnosticFieldPathDiff($from,$to,'',$changed);
            }
            $details[]=[
                'path'=>$key,
                'room'=>(string)($diff['room']??''),
                'device'=>(string)($diff['title']??''),
                'type'=>(string)($diff['type']??''),
                'changedFields'=>$changed,
                'fieldsTruncated'=>count($changed)>=60,
            ];
        }
        return [
            'counts'=>$rows['counts'],
            'total'=>count($details),
            'items'=>$details
        ];
    }

    public function ExportAIRepairDiagnostics(): void
    {
        try {
            $sources=$this->safeRepairSources();
            $plan=$this->safeRepairPlan($sources['catalog']);
            $prodCatalog=$this->loadRoomsCatalog($sources['prodId'],'PROD');
            $diff=$this->diagnosticCatalogDiff($sources['catalog'],$prodCatalog);
            $report=[
                'schema'=>'RoomsCatalogSafeRepair/1','createdAt'=>date(DATE_ATOM),
                'note'=>'Nur Metadaten. Keine Livewerte, Passwoerter oder Aktionstests. Fachliche Pruefung offen.',
                'sources'=>[
                    'editId'=>$sources['editId'],'prodId'=>$sources['prodId'],
                    'editSha256'=>hash('sha256',$sources['editSource']),
                    'prodSha256'=>hash('sha256',$sources['prodSource']),
                    'identical'=>$sources['editSource']===$sources['prodSource']
                ],
                'editProdComparison'=>$diff,
                'summary'=>['total'=>count($plan['devices']),
                    'statusCounts'=>$plan['counts'],'safeBindingChanges'=>count($plan['changes']),
                    'capabilityCounts'=>array_count_values(array_column($plan['devices'],'capability')),
                    'typedRolesExported'=>count(array_filter($plan['devices'],static fn(array $d):bool=>($d['roles']??[])!==[]))],
                'devices'=>$plan['devices']
            ];
            $signature=hash('sha256',json_encode($plan,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
            $this->WriteAttributeString('AutoRepairPreview',json_encode([
                'time'=>time(),'editId'=>$sources['editId'],'prodId'=>$sources['prodId'],
                'editHash'=>hash('sha256',$sources['editSource']),
                'prodHash'=>hash('sha256',$sources['prodSource']),
                'signature'=>$signature
            ],JSON_INVALID_UTF8_SUBSTITUTE));
            $notice=sprintf('Diagnose fuer %d Geraete erstellt; %d eindeutige technische Korrekturen vorgeschlagen; '
                .'%d EDIT/PROD-Unterschiede mit Feldpfaden im Download. '
                .'Schritt 2 ist bei EDIT/PROD-Unterschieden gesperrt. '
                .'Alle unsicheren Zuordnungen bleiben unveraendert.',
                count($plan['devices']),count($plan['changes']),(int)$diff['total']);
            $this->WriteAttributeString('AutoRepairNotice',$notice);
            $this->UpdateFormField('AutoRepairNotice','caption',$notice);
            $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE
                |JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
            echo 'data:application/json;charset=utf-8;base64,'.base64_encode($json);
        } catch (\Throwable $e) {
            $message='DIAGNOSE FEHLGESCHLAGEN: '.$e->getMessage();
            $this->WriteAttributeString('AutoRepairNotice',$message);
            $this->UpdateFormField('AutoRepairNotice','caption',$message);
            echo 'data:application/json;base64,'.base64_encode(
                json_encode(['error'=>$message],JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    public function ApplySafeAutoRepair(): void
    {
        try {
            $preview=json_decode($this->ReadAttributeString('AutoRepairPreview'),true);
            if (!is_array($preview) || time()-(int)($preview['time']??0)>600) {
                throw new \RuntimeException('Bitte zuerst eine aktuelle Diagnose herunterladen (max. zehn Minuten).');
            }
            $sources=$this->safeRepairSources();
            if ($sources['editSource']!==$sources['prodSource']) {
                throw new \RuntimeException('EDIT und PROD unterscheiden sich – bitte zuerst manuell abgleichen.');
            }
            foreach (['edit','prod'] as $name) {
                if ((int)($preview[$name.'Id']??0)!==$sources[$name.'Id']
                    || !hash_equals((string)($preview[$name.'Hash']??''),
                        hash('sha256',$sources[$name.'Source']))) {
                    throw new \RuntimeException('Katalog seit der Diagnose veraendert: '.$name);
                }
            }
            $plan=$this->safeRepairPlan($sources['catalog']);
            $signature=hash('sha256',json_encode($plan,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
            if (!hash_equals((string)($preview['signature']??''),$signature)) {
                throw new \RuntimeException('IP-Symcon-Metadaten seit Diagnose veraendert. Abbruch.');
            }
            $changes=$plan['changes'];
            if ($changes===[]) {
                $message='Keine eindeutigen weiteren Korrekturen. Unklare Rueckmeldungen und Schaltaktionen bleiben zur Pruefung.';
                $this->WriteAttributeString('AutoRepairNotice',$message);
                $this->UpdateFormField('AutoRepairNotice','caption',$message);
                echo $message;
                return;
            }
            $next=RCCSafeAutoRepair::applyBindings($sources['catalog'],$changes);
            $loss=[];
            $this->findLostCatalogFields($sources['catalog'],$next,'RoomsCatalogEdit',$loss);
            // Authorized replacements are the known KNX heating setpoint or
            // a fully checked KNX DPT2 light-feedback repair. All other
            // existing fields and permissions remain unchanged.
            // All replacements are limited to a control field that formerly
            // held the same read-only ID as targetTemperature. The exact
            // original value and full destination are checked against the plan.
            $approved=[];
            $flat=$this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($sources['catalog']));
            foreach ($flat as $flatRow) {
                $key=$this->buildCompositeKey($flatRow);
                if ($key===null || !isset($changes[$key]['control'])) continue;
                $oldAi=$flatRow['ai']??null;
                if (is_string($oldAi)) $oldAi=json_decode($oldAi,true);
                if (!is_array($oldAi) || ($oldAi['type']??'')!=='heating') continue;
                $oldControl=(int)($oldAi['variables']['control']??0);
                $oldTarget=(int)($oldAi['variables']['targetTemperature']??0);
                if ($oldControl<=0 || $oldControl!==$oldTarget) continue;
                [$room,$domain,$group,$device]=explode('|',$key,4);
                $approved[]='RoomsCatalogEdit.'.$room.'.domains.'.$domain.'.'.$device
                    .'.ai.variables.control:';
            }
            // KNX DPT2 light-only repair: the existing "state" was
            // incorrectly a KNX priority bit (Value0). The *same*
            // repair must replace both typed power/status and legacy state
            // with the read-only Boolean Funktionswert (Value1).
            foreach ($flat as $flatRow) {
                $key=$this->buildCompositeKey($flatRow);
                if ($key===null || !isset($changes[$key]['power'],$changes[$key]['status'])) continue;
                $oldAi=$flatRow['ai']??null;
                if (is_string($oldAi)) $oldAi=json_decode($oldAi,true);
                if (!is_array($oldAi) || ($oldAi['type']??'')!=='light'
                    || empty($flatRow['kiReadOnly'])
                    || (int)$changes[$key]['power']!==(int)$changes[$key]['status']) continue;
                $oldState=(int)($flatRow['state']??0);
                $oldPower=(int)($oldAi['variables']['power']??0);
                $oldStatus=(int)($oldAi['variables']['status']??0);
                if ($oldState<=0 || $oldState!==$oldPower || $oldState!==$oldStatus) continue;
                [$room,$domain,$group,$device]=explode('|',$key,4);
                $base='RoomsCatalogEdit.'.$room.'.domains.'.$domain.'.'.$group.'.'.$device.'.';
                foreach (['state:','ai.variables.power:','ai.variables.status:'] as $field) {
                    $approved[]=$base.$field;
                }
            }
            $loss=array_values(array_filter($loss,static function(string $error)use($approved):bool {
                foreach($approved as $prefix) if(str_starts_with($error,$prefix)) return false;
                return true;
            }));
            if ($loss!==[]) throw new \RuntimeException('Datenverlustpruefung: '.implode('; ',array_slice($loss,0,3)));
            $nextSource="<?php\nreturn ".var_export($next,true).";\n";
            if (IPS_GetScriptContent($sources['editId'])!==$sources['editSource']
                || IPS_GetScriptContent($sources['prodId'])!==$sources['prodSource']) {
                throw new \RuntimeException('Katalog kurz vor der Sicherung veraendert. Abbruch.');
            }
            // Both original scripts must have verifiable independent backups
            // BEFORE either script is modified.
            $backups=[];
            foreach (['edit','prod'] as $name) {
                $scriptId=$sources[$name.'Id'];
                $backup=IPS_CreateScript(0);
                if (!is_int($backup) || $backup<=0) {
                    throw new \RuntimeException('Sicherung konnte nicht angelegt werden. PROD/EDIT unveraendert.');
                }
                IPS_SetParent($backup,IPS_GetParent($scriptId));
                IPS_SetName($backup,'RoomsCatalog_V3312_before_repair_'.strtoupper($name).'_'.date('Ymd_His').'_'.$backup);
                IPS_SetScriptContent($backup,$sources[$name.'Source']);
                if (IPS_GetScriptContent($backup)!==$sources[$name.'Source']) {
                    throw new \RuntimeException('Backup-Verifikation fehlgeschlagen; keine Uebernahme.');
                }
                $backups[$name]=$backup;
            }
            if (IPS_GetScriptContent($sources['editId'])!==$sources['editSource']
                || IPS_GetScriptContent($sources['prodId'])!==$sources['prodSource']) {
                throw new \RuntimeException('Katalog nach Sicherung veraendert; keine Uebernahme.');
            }
            try {
                foreach (['edit','prod'] as $name) {
                    IPS_SetScriptContent($sources[$name.'Id'],$nextSource);
                    if (IPS_GetScriptContent($sources[$name.'Id'])!==$nextSource)
                        throw new \RuntimeException('Schreibverifikation fehlgeschlagen: '.$name);
                }
            } catch (\Throwable $writeError) {
                $failed=[];
                foreach (['edit','prod'] as $name) {
                    try {
                        IPS_SetScriptContent($sources[$name.'Id'],$sources[$name.'Source']);
                        if (IPS_GetScriptContent($sources[$name.'Id'])!==$sources[$name.'Source']) $failed[]=$name;
                    } catch (\Throwable $restoreError) { $failed[]=$name; }
                }
                throw new \RuntimeException('Schreibfehler: '.$writeError->getMessage().
                    ($failed!==[]?' ROLLBACK FEHLER: '.implode(',',$failed):' Alte Inhalte wiederhergestellt.').
                    ' Backups: '.implode(',',$backups));
            }
            $this->WriteAttributeString('AutoRepairPreview','{}');
            $this->reloadAllFromCatalog();
            $this->RefreshVisibleLists();
            $message=sprintf('ERFOLG: %d sichere technische Rollen-Zuordnungen in EDIT und PROD korrigiert. '
                .'Backups: EDIT #%d, PROD #%d. KI-Rechte, Werte und Bestaetigungen unveraendert. '
                .'Bitte ALLE GERAETE TECHNISCH PRUEFEN erneut ausfuehren.',
                count($changes),$backups['edit'],$backups['prod']);
            $this->WriteAttributeString('AutoRepairNotice',$message);
            $this->UpdateFormField('AutoRepairNotice','caption',$message);
            echo $message;
        } catch (\Throwable $e) {
            $message='AUTO-KORREKTUR BLOCKIERT: '.$e->getMessage();
            $this->WriteAttributeString('AutoRepairNotice',$message);
            $this->UpdateFormField('AutoRepairNotice','caption',$message);
            echo $message;
        }
    }

    private function technicalDeviceSourceSummary(array $snapshot): string
    {
        if ($snapshot === []) {
            return 'Katalogquellen noch nicht geprueft – bitte technische Pruefung ausfuehren.';
        }
        $src = (array)($snapshot['source'] ?? []);
        $editId = (int)($src['editId'] ?? 0);
        $prodId = (int)($src['prodId'] ?? 0);
        $hashE = substr((string)($src['editHash'] ?? ''),0,12);
        $hashP = substr((string)($src['prodHash'] ?? ''),0,12);
        $typed = (int)($src['typed'] ?? 0);
        $legacy = (int)($src['legacy'] ?? 0);
        $message = sprintf(
            'DATENQUELLEN: EDIT #%d (Hash %s), PROD #%d (Hash %s). '
            .'Im aktuellen EDIT-Katalog: %d typisiert, %d Legacy. ',
            $editId,$hashE,$prodId,$hashP,$typed,$legacy
        );
        if ($editId === $prodId) {
            $message .= 'FEHLKONFIGURATION: EDIT und PROD zeigen auf DIESELBE Skript-ID! '
                .'Keine Aenderungen bestaetigen oder produktiv uebernehmen. ';
        } elseif ($hashE === $hashP) {
            $message .= 'Die Skriptinhalte sind identisch; 0 Unterschiede ist daher nachvollziehbar. ';
        } elseif (empty($snapshot['diffRows'])) {
            $message .= 'Quelltexte unterscheiden sich, die analysierten Geraeteeintraege aber nicht. ';
        }
        $previous = (array)($src['previousAudit'] ?? []);
        if (($previous['available'] ?? false) && !empty($previous['changed'])) {
            $message .= sprintf(
                'ACHTUNG: Seit der letzten Migrationspruefung hat sich EDIT geaendert. '
                .'Vorher: %d typisiert, %d Legacy. Jetzt: %d typisiert, %d Legacy. ',
                (int)($previous['typed'] ?? 0),(int)($previous['legacy'] ?? 0),
                $typed,$legacy
            );
        } elseif (($previous['available'] ?? false) && empty($previous['changed'])
            && ((int)($previous['typed'] ?? -1) !== $typed
                || (int)($previous['legacy'] ?? -1) !== $legacy)) {
            $message .= 'WIDERSPRUCH: alter und neuer Zaehler unterscheiden sich trotz identischem EDIT-Hash. ';
        } elseif (!($previous['available'] ?? false)) {
            $message .= 'Ein direkt vergleichbarer alter Migrations-Pruefbericht ist nicht mehr vorhanden. ';
        }
        $issues = (array)($snapshot['issueCounts'] ?? []);
        arsort($issues,SORT_NUMERIC);
        $names = [
            'role_name_ambiguous'=>'Rollenname/Profil uneindeutig',
            'multiple_candidates'=>'mehrere gleichwertige Variablen',
            'missing_required_role'=>'Pflichtrolle im neuen Modell fehlt',
            'no_typed_variables'=>'keine expliziten ai.variables',
            'legacy_fields_not_mapped'=>'Legacy-IDs noch nicht im neuen Modell',
            'no_scope'=>'kein IPS-Geraeteumfeld',
            'outside_scope'=>'Variable ausserhalb der Instanz',
            'missing_ips'=>'IPS-Variable existiert nicht',
            'wrong_type'=>'IPS-Datentyp falsch',
            'no_action'=>'fehlende Steueraktion',
            'duplicate_mapping'=>'unplausible Doppelzuordnung',
            'state_mapping_missing'=>'Statusbedeutung fehlt',
            'state_mapping_conflict'=>'Statuswerte und Profil widersprechen sich',
            'state_associations_missing'=>'keine eindeutigen Profil-Werte',
            'write_variable_invalid'=>'KI-Schaltvariable fehlt',
            'write_action_unproven'=>'KI-Aktion nicht verifiziert',
        ];
        if ($issues !== []) {
            $parts = [];
            foreach (array_slice($issues,0,12,true) as $code=>$count) {
                $parts[] = ($names[$code] ?? $code) . ': ' . (int)$count;
            }
            $message .= "\nHAEUFIGSTE PRUEFURSACHEN (Geraete koennen mehrfach vorkommen): "
                .implode(' | ',$parts);
        }
        return $message;
    }

    private function technicalDeviceSummaryFromSnapshot(array $snapshot): string
    {
        if ($snapshot === []) {
            return 'Pruefung fehlt oder ist veraltet. Bitte "ALLE GERAETE TECHNISCH PRUEFEN" ausfuehren.';
        }
        $stats = (array)($snapshot['counts'] ?? []);
        $diff = (array)($snapshot['diffCounts'] ?? []);
        $modes=(array)($snapshot['capabilityCounts']??[]);
        $commandOnly=(int)($modes['Nur Steuerung (Ist-Rueckmeldung nicht nachgewiesen)']??0);
        $toReview=(int)($modes['Technische Zuordnung pruefen']??0);
        return sprintf(
            '%d Geraete geprueft: %d technisch plausibel, %d manuell pruefen, '
            .'%d ungueltig, %d Legacy; %d bereits fachlich bestaetigt. '
            .'EDIT/PROD: %d neu, %d entfernt, %d geaendert. '
            .'Davon %d Geraete nur mit Steuerbefehl (keine echte Rueckmeldung nachgewiesen), '
            .'%d technische Zuordnungen gesondert pruefen. '
            .'Technisch plausibel bedeutet NICHT am echten Geraet bestaetigt.',
            count($snapshot['rows']),
            (int)($stats['Technisch plausibel'] ?? 0),
            (int)($stats['Manuell pruefen'] ?? 0),
            (int)($stats['Ungueltig'] ?? 0),
            (int)($stats['Legacy'] ?? 0),
            (int)($stats['Manuell bestaetigt'] ?? 0),
            (int)($diff['new'] ?? 0),(int)($diff['removed'] ?? 0),
            (int)($diff['changed'] ?? 0),
            $commandOnly,$toReview
        );
    }

    public function RunTechnicalDeviceAudit(): void
    {
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $prodId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        if ($editId <= 0 || $prodId <= 0 || !IPS_ScriptExists($editId) || !IPS_ScriptExists($prodId)) {
            echo 'RoomsCatalogEdit und RoomsCatalog PROD muessen beide vorhanden sein.';
            return;
        }
        $editSource = IPS_GetScriptContent($editId);
        $prodSource = IPS_GetScriptContent($prodId);
        $catalog = $this->loadRoomsCatalog($editId,'EDIT');
        $prodCatalog = $this->loadRoomsCatalog($prodId,'PROD');
        $rows = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($catalog));
        $prodRows = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($prodCatalog));
        $results = [];
        $stats = ['Technisch plausibel'=>0,'Manuell pruefen'=>0,'Ungueltig'=>0,'Legacy'=>0,'Manuell bestaetigt'=>0];
        $legacyRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $result = $this->assessTechnicalDevice($row);
            $stats[$result['status']] = ($stats[$result['status']] ?? 0) + 1;
            if ($result['manuallyConfirmed']) $stats['Manuell bestaetigt']++;
            $results[] = $result;
            if ($result['status'] === 'Legacy') {
                $legacyRows[] = [
                    'path'=>(string)($result['key'] ?? ''),
                    'title'=>(string)($result['title'] ?? ''),
                    'room'=>(string)($result['room'] ?? ''),
                    'message'=>'Typisierung im Bereich "Altgeraete ins neue Modell uebernehmen"'
                ];
            }
        }
        $diff = $this->deviceDiffReport($prodRows,$rows);
        $typeStats = $this->catalogMigrationStatistics($rows,$prodRows);
        $oldAudit = json_decode($this->ReadAttributeString('MigrationReadinessDetails'),true);
        $currentHash = hash('sha256',json_encode($catalog,JSON_INVALID_UTF8_SUBSTITUTE));
        $oldStats = (array)($oldAudit['statistics'] ?? []);
        $priorAvailable = is_array($oldAudit) && isset($oldAudit['catalogHash'])
            && isset($oldStats['typed']) && isset($oldStats['legacy']);
        $capabilityCounts=array_count_values(array_map(
            static fn(array $row):string=>(string)($row['capability']??'Technische Zuordnung pruefen'),
            $results
        ));
        $issueCounts = [];
        foreach ($results as $result) {
            foreach (array_unique((array)($result['issueCodes'] ?? [])) as $code) {
                $issueCounts[(string)$code] = ($issueCounts[(string)$code] ?? 0) + 1;
            }
        }
        $source = [
            'editId'=>$editId,'prodId'=>$prodId,
            'editHash'=>hash('sha256',$editSource),'prodHash'=>hash('sha256',$prodSource),
            'typed'=>$typeStats['typed'],'legacy'=>$typeStats['legacy'],
            'previousAudit'=>[
                'available'=>$priorAvailable,
                'changed'=>$priorAvailable && !hash_equals(
                    (string)$oldAudit['catalogHash'],$currentHash),
                'typed'=>(int)($oldStats['typed'] ?? -1),
                'legacy'=>(int)($oldStats['legacy'] ?? -1)
            ]
        ];
        // A scan is a read-only snapshot. Avoid a false current snapshot if
        // the EDIT script was changed during a long inventory read.
        if ($editSource !== IPS_GetScriptContent($editId) || $prodSource !== IPS_GetScriptContent($prodId)) {
            echo 'Katalog waehrend der Geraetepruefung veraendert. Bitte erneut starten.';
            return;
        }
        $snapshot = [
            'version'=>1,'time'=>time(),
            'editHash'=>hash('sha256',$editSource),
            'prodHash'=>hash('sha256',$prodSource),
            'rows'=>$results,'counts'=>$stats,'capabilityCounts'=>$capabilityCounts,
            'legacyRows'=>$legacyRows,
            'diffRows'=>$diff['rows'],'diffCounts'=>$diff['counts'],
            'issueCounts'=>$issueCounts,'source'=>$source
        ];
        $this->WriteAttributeString('TechnicalDeviceSnapshot',json_encode($snapshot,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
        $this->WriteAttributeString('TechnicalDevicePreview','{}');
        $summary = $this->technicalDeviceSummaryFromSnapshot($snapshot);
        $this->WriteAttributeString('TechnicalDeviceSummary',$summary);
        $this->UpdateFormField('TechnicalDeviceSummary','caption',$summary);
        $this->UpdateFormField('TechnicalDeviceSourceSummary','caption',
            $this->technicalDeviceSourceSummary($snapshot));
        $this->UpdateFormField('TechnicalDeviceChecks','values',json_encode($results,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('TechnicalLegacyDevices','values',json_encode($legacyRows,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('TechnicalDiffRows','values',json_encode($diff['rows'],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('TechnicalDeviceActionMessage','caption','Ergebnisse nur gelesen – keine EDIT/PROD-Aenderungen.');
        echo $summary;
    }

    /** Auswahl ist nur UI-Metadatum und muss im urspruenglichen Snapshot liegen. */
    public function MarkTechnicalDevice(string $key,bool $selected): void
    {
        $snapshot = $this->deviceTechnicalSnapshot();
        if ($snapshot === []) {
            echo 'Geraetepruefung fehlt oder ist veraltet. Bitte neu starten.';
            return;
        }
        $found = false;
        foreach ($snapshot['rows'] as &$row) {
            if ($row['key'] !== $key) continue;
            $row['selected'] = $selected && !in_array($row['status'],['Legacy','Ungueltig'],true)
                && !empty($row['manualEligible']);
            $found = true;
            break;
        }
        unset($row);
        if (!$found) return;
        $this->WriteAttributeString('TechnicalDeviceSnapshot',json_encode($snapshot,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
        $this->WriteAttributeString('TechnicalDevicePreview','{}');
        $this->UpdateFormField('TechnicalDeviceChecks','values',json_encode($snapshot['rows'],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
    }

    public function SelectTechnicalDevices(bool $select): void
    {
        $snapshot = $this->deviceTechnicalSnapshot();
        if ($snapshot === []) {
            echo 'Bitte zuerst technische Geraetepruefung ausfuehren.';
            return;
        }
        foreach ($snapshot['rows'] as &$row) {
            $row['selected'] = $select && $row['status'] === 'Technisch plausibel'
                && !empty($row['manualEligible']);
        }
        unset($row);
        $this->WriteAttributeString('TechnicalDeviceSnapshot',json_encode($snapshot,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
        $this->WriteAttributeString('TechnicalDevicePreview','{}');
        $this->UpdateFormField('TechnicalDeviceChecks','values',json_encode($snapshot['rows'],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE));
        echo $select ? 'Technisch plausible Geraete markiert – fachliche Bestaetigung bleibt separat.'
            : 'Alle technischen Markierungen aufgehoben.';
    }

    private function previewTechnicalDeviceAction(string $mode,bool $manualAttested): void
    {
        $this->WriteAttributeString('TechnicalDevicePreview','{}');
        if ($mode === 'manual' && !$manualAttested) {
            echo 'Manuelle Bestaetigung erfordert die ausdrueckliche Erklaerung, alle markierten Geraete '
                .'und ihre Werte am echten System selbst getestet zu haben.';
            return;
        }
        $snapshot = $this->deviceTechnicalSnapshot();
        if ($snapshot === []) { echo 'Geraetepruefung veraltet – erneut ausfuehren.'; return; }
        $targets = [];
        foreach ($snapshot['rows'] as $item) {
            if (empty($item['selected'])) continue;
            if (empty($item['manualEligible']) || ($mode === 'technical'
                && $item['status'] !== 'Technisch plausibel')) {
                echo 'Markierte Geraete enthalten ungueltige oder noch ungepruefte IPS-Zuordnungen.';
                return;
            }
            $targets[$item['key']] = $item['fingerprint'];
        }
        if ($targets === []) { echo 'Bitte zuerst passende Geraete markieren.'; return; }
        $preview = [
            'mode'=>$mode, 'time'=>time(), 'editHash'=>$snapshot['editHash'],
            'prodHash'=>$snapshot['prodHash'], 'keys'=>$targets
        ];
        $this->WriteAttributeString('TechnicalDevicePreview',json_encode($preview,JSON_INVALID_UTF8_SUBSTITUTE));
        $message = sprintf('Vorschau: %d Geraete, %s. Nur RoomsCatalogEdit; '
            .'bestehende KI-Freigaben und PROD bleiben unveraendert.',
            count($targets),
            $mode === 'manual' ? 'nach ausdruecklich bestaetigtem echtem Geraetetest fachlich bestaetigen'
                : 'technische Pruefmetadaten speichern (keine fachliche Bestaetigung)');
        $this->UpdateFormField('TechnicalDeviceActionMessage','caption',$message);
        echo $message;
    }

    public function PreviewTechnicalDeviceReview(): void
    {
        $this->previewTechnicalDeviceAction('technical',false);
    }

    public function PreviewManualDeviceConfirmation(bool $physicalTestDone=false): void
    {
        $this->previewTechnicalDeviceAction('manual',$physicalTestDone);
    }

    public function ApplyTechnicalDeviceReview(): void
    {
        $this->applyTechnicalDeviceAction('technical',false);
    }

    public function ApplyManualDeviceConfirmation(bool $physicalTestDone=false): void
    {
        $this->applyTechnicalDeviceAction('manual',$physicalTestDone);
    }

    /**
     * Protected EDIT-only transactional write with full source backup.
     * Do not touch the existing review.status unless a user explicitly states
     * every selected device was physically checked.
     */
    private function applyTechnicalDeviceAction(string $mode,bool $physicalTestDone): void
    {
        if ($mode === 'manual' && !$physicalTestDone) {
            echo 'Manuelle Bestaetigung ohne tatsaechlichen Geraetetest gesperrt.';
            return;
        }
        $preview = json_decode($this->ReadAttributeString('TechnicalDevicePreview'),true);
        $snapshot = $this->deviceTechnicalSnapshot();
        if (!is_array($preview) || $snapshot === []
            || ($preview['mode'] ?? '') !== $mode
            || time() - (int)($preview['time'] ?? 0) > 600
            || ($preview['editHash'] ?? '') !== ($snapshot['editHash'] ?? '')
            || ($preview['prodHash'] ?? '') !== ($snapshot['prodHash'] ?? '')
            || !is_array($preview['keys'] ?? null) || $preview['keys'] === []) {
            echo 'Pruefvorschau veraltet oder nicht mehr aktuell; bitte technische Analyse wiederholen.';
            return;
        }
        $editId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($editId <= 0 || !IPS_ScriptExists($editId)) return;
        if ($editId === $this->ReadPropertyInteger('RoomsCatalogScriptID')) {
            echo 'SICHERHEITSSTOPP: EDIT und PROD haben dieselbe Skript-ID. Keine Uebernahme.';
            return;
        }
        $originalSource = IPS_GetScriptContent($editId);
        if (!RCCDeviceTechnicalAudit::isLiteralArraySource((string)$originalSource)) {
            echo 'RoomsCatalogEdit enthaelt dynamisches PHP; automatische Uebernahme gesperrt, '
                .'um Skriptlogik nicht zu verlieren.';
            return;
        }
        $original = $this->loadRoomsCatalog($editId,'EDIT');
        $flat = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($original));
        $byKey = [];
        foreach ($flat as $row) {
            $key = $this->buildCompositeKey($row);
            if ($key !== null) $byKey[$key] = $row;
        }
        $checked = [];
        foreach ($preview['keys'] as $key=>$fingerprint) {
            if (!isset($byKey[$key])) { echo 'Geraetezeile fehlt: Pruefung wiederholen.'; return; }
            $live = $this->assessTechnicalDevice($byKey[$key]);
            if (($live['fingerprint'] ?? '') !== $fingerprint
                || empty($live['manualEligible'])
                || ($mode === 'technical' && $live['status'] !== 'Technisch plausibel')
                || in_array($live['status'],['Ungueltig','Legacy'],true)) {
                echo 'Geraet ' . $key . ' wurde veraendert oder ist nicht pruefbar. Keine Daten geschrieben.';
                return;
            }
            $checked[$key] = $live;
        }
        $catalog = $original;
        if (isset($catalog['rooms']) && is_array($catalog['rooms'])) {
            $rooms = &$catalog['rooms'];
        } else {
            $rooms = &$catalog;
        }
        foreach ($checked as $key=>$result) {
            $parts = explode('|',$key,4);
            if (count($parts) !== 4) { echo 'Ungueltiger Geraete-Key.'; return; }
            [$room,$domain,$group,$device] = $parts;
            if (!isset($rooms[$room]['domains'][$domain])) { echo 'Quellpfad nicht vorhanden.'; return; }
            if (in_array($domain,['heizung','jalousie'],true)) {
                if (!isset($rooms[$room]['domains'][$domain][$device])) { echo 'Quellgeraet fehlt.'; return; }
                $cfg = &$rooms[$room]['domains'][$domain][$device];
            } else {
                if (!isset($rooms[$room]['domains'][$domain][$group][$device])) { echo 'Quellgeraet fehlt.'; return; }
                $cfg = &$rooms[$room]['domains'][$domain][$group][$device];
            }
            if (!is_array($cfg['ai'] ?? null)) { echo 'ai-Struktur fehlt.'; return; }
            $review = is_array($cfg['ai']['review'] ?? null) ? $cfg['ai']['review'] : [];
            $review['technical'] = [
                // Unklare Profil-Rollen werden auch bei ausdruecklicher
                // manueller Bestaetigung nicht als maschinell eindeutig ausgegeben.
                'status'=>$result['status'] === 'Technisch plausibel'
                    ? 'validated' : 'manually_verified_with_uncertain_metadata',
                'at'=>time(),
                'fingerprint'=>$result['fingerprint'],
                'source'=>'IP-Symcon Namen, Profile, Datentypen und Aktionen'
            ];
            if ($mode === 'manual') {
                $review['status'] = 'confirmed';
                $review['confirmedAt'] = time();
                $review['confirmationMethod'] = 'user_verified_on_real_device';
            }
            $cfg['ai']['review'] = $review;
            unset($cfg);
        }
        unset($rooms);
        $loss = [];
        $this->findLostCatalogFields($original,$catalog,'RoomsCatalogEdit',$loss);
        // Nur die explizit manuell bestaetigten review-Felder duerfen
        // sich absichtlich aendern. Jede andere Aenderung bleibt gesperrt.
        if ($mode === 'manual') {
            $approvedPaths = [];
            foreach (array_keys($checked) as $approvedKey) {
                [$room,$domain,$group,$device] = explode('|',$approvedKey,4);
                $approvedPaths[] = 'RoomsCatalogEdit.' . $room . '.domains.' . $domain . '.'
                    . (in_array($domain,['heizung','jalousie'],true) ? '' : $group . '.') . $device
                    . '.ai.review.';
            }
            $loss = array_values(array_filter($loss, static function (string $problem) use ($approvedPaths): bool {
                foreach ($approvedPaths as $path) {
                    foreach (['status:','confirmedAt:','confirmationMethod:'] as $field) {
                        if (str_starts_with($problem,$path.$field)) return false;
                    }
                }
                return true;
            }));
        }
        if ($loss !== []) {
            echo 'Datenverlustpruefung abgebrochen: '.implode('; ',array_slice($loss,0,3));
            return;
        }
        $nextSource = "<?php\nreturn ".var_export($catalog,true).";\n";
        if ($originalSource !== IPS_GetScriptContent($editId)) {
            echo 'RoomsCatalogEdit wurde zwischenzeitlich geaendert. Keine Uebernahme.';
            return;
        }
        if (!function_exists('IPS_CreateScript') || !function_exists('IPS_SetParent')
            || !function_exists('IPS_SetName') || !function_exists('IPS_GetParent')) {
            echo 'Automatische Backup-Funktionen fehlen; aus Sicherheitsgruenden nichts gespeichert.';
            return;
        }
        $backupId = IPS_CreateScript(0);
        if (!is_int($backupId) || $backupId <= 0) { echo 'Backup konnte nicht erstellt werden.'; return; }
        IPS_SetParent($backupId, IPS_GetParent($editId));
        IPS_SetName($backupId,'RoomsCatalogEdit_Backup_Technical_V339_'.date('Ymd_His').'_'.$backupId);
        IPS_SetScriptContent($backupId,$originalSource);
        if (IPS_GetScriptContent($backupId) !== $originalSource) {
            echo 'Backup-Verifikation fehlgeschlagen, EDIT unveraendert.';
            return;
        }
        IPS_SetScriptContent($editId,$nextSource);
        if (IPS_GetScriptContent($editId) !== $nextSource) {
            IPS_SetScriptContent($editId,$originalSource);
            echo 'EDIT konnte nicht verifiziert werden; urspruenglicher Inhalt wiederhergestellt.';
            return;
        }
        $this->WriteAttributeString('TechnicalDevicePreview','{}');
        $this->WriteAttributeString('TechnicalDeviceSnapshot','{}');
        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
        $message = count($checked).' Geraete '.($mode === 'manual'
            ? 'nach manueller Pruef-Erklaerung bestaetigt' : 'technisch protokolliert')
            .'. Nur EDIT geaendert, KI-Freigaben/PROD unveraendert. Backup #'.$backupId.'.';
        $this->UpdateFormField('TechnicalDeviceActionMessage','caption',$message);
        echo $message;
    }

    private function catalogMigrationStatistics(array $edit, array $prod, bool $validateVariables = false): array
    {
        $summary = [
            'total'=>count($edit), 'readOnly'=>0, 'writable'=>0, 'disabled'=>0,
            'typed'=>0, 'confirmed'=>0, 'legacy'=>0, 'generic'=>0, 'unreviewed'=>0,
            'missingFeedback'=>0, 'invalidVariables'=>0, 'tabsEdit'=>0, 'tabsProd'=>0,
            'prodDiff'=>count($this->buildDiffEntries($prod,$edit)), 'examples'=>[]
        ];
        foreach ($edit as $row) {
            if (!is_array($row)) continue;
            $path = implode(' / ',array_map('strval',[
                $row['roomKey']??'', $row['domain']??'', $row['group']??'', $row['key']??''
            ]));
            if (($row['group'] ?? '') === 'tabs') $summary['tabsEdit']++;
            if (empty($row['kiEnabled'])) $summary['disabled']++;
            elseif (empty($row['kiReadOnly'])) $summary['writable']++;
            else $summary['readOnly']++;
            $type = trim((string)($row['aiType']??''));
            if ($type === '' || !array_key_exists($type,RCCDeviceTypeRegistry::roles())) {
                $summary['legacy']++;
                if (count($summary['examples']) < 20) $summary['examples'][]='Ohne typisiertes Modell: '.$path;
                continue;
            }
            $summary['typed']++;
            if ($type === 'generic') {
                $summary['generic']++;
                if (count($summary['examples']) < 20) $summary['examples'][]='Geraetetyp unklar: '.$path;
            }
            if (empty($row['aiReviewed'])) {
                $summary['unreviewed']++;
                if (count($summary['examples']) < 20) $summary['examples'][]='Zuordnung unbestaetigt: '.$path;
            } else $summary['confirmed']++;
            $bound = [];
            foreach (RCCDeviceTypeRegistry::fields() as $property=>$field) {
                $id = (int)($row[$field]??0);
                if ($id <= 0) continue;
                if ($validateVariables && function_exists('IPS_VariableExists') && !IPS_VariableExists($id)) {
                    $summary['invalidVariables']++;
                    if (count($summary['examples']) < 20) $summary['examples'][]='Fehlende IPS-Variable #'.$id.': '.$path;
                    continue;
                }
                $bound[] = $property;
            }
            if ($type !== 'generic') {
                foreach ($this->minimumReadingRoles($type) as $options) {
                    if (array_intersect($options,$bound) === []) {
                        $summary['missingFeedback']++;
                        if (count($summary['examples']) < 20) $summary['examples'][]='Fehlende Rueckmeldung ['
                            .implode('/',$options).']: '.$path;
                    }
                }
            }
        }
        foreach ($prod as $row) if (is_array($row) && ($row['group']??'') === 'tabs') $summary['tabsProd']++;
        $summary['modelReady'] = $summary['legacy'] === 0 && $summary['generic'] === 0
            && $summary['unreviewed'] === 0 && $summary['missingFeedback'] === 0
            && $summary['invalidVariables'] === 0;
        $summary['migrationReady'] = $summary['modelReady'] && $summary['tabsEdit'] === 0
            && $summary['tabsProd'] === 0 && $summary['prodDiff'] === 0;
        return $summary;
    }

    private function currentMigrationReport(array $editCatalog): string
    {
        $report = $this->ReadAttributeString('MigrationReadinessReport');
        $meta = json_decode($this->ReadAttributeString('MigrationReadinessDetails'),true);
        if ($report === '' || !is_array($meta) || !is_string($meta['catalogHash'] ?? null)) {
            return 'Noch keine aktuelle Migrationspruefung ausgefuehrt.';
        }
        $currentHash = hash('sha256',json_encode($editCatalog,JSON_INVALID_UTF8_SUBSTITUTE));
        if (!hash_equals($meta['catalogHash'],$currentHash)) {
            return 'RoomsCatalogEdit wurde seit dem letzten Pruefbericht geaendert. '
                .'Datenstand erneut pruefen; alte Ergebnisse sind nicht mehr gueltig.';
        }
        return $report;
    }

    private function catalogMigrationShortSummary(array $s): string
    {
        return sprintf(
            'KI-Zugriff: %d/%d freigegeben (%d nur lesen, %d schalten), %d ohne KI. '
            .'Neues Modell: %d/%d typisiert, %d bestaetigt. '
            .'Offen: %d Legacy, %d unbekannte Typen, %d unbestaetigte, %d fehlende Rueckmeldungen, '
            .'%d EDIT/PROD-Unterschiede, %d uebrige Tabs.',
            $s['readOnly']+$s['writable'], $s['total'], $s['readOnly'], $s['writable'],
            $s['disabled'], $s['typed'], $s['total'], $s['confirmed'],
            $s['legacy'], $s['generic'], $s['unreviewed'], $s['missingFeedback'],
            $s['prodDiff'], $s['tabsEdit']+$s['tabsProd']
        );
    }

    /** Geraetedaten-Check ohne KI- oder Schaltfreigabe. */
    public function AuditMigrationReadiness(): void
    {
        $editCatalog = $this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT');
        $prodCatalog = $this->loadConfiguredCatalog('RoomsCatalogScriptID','PROD');
        $edit = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($editCatalog));
        $prod = $this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($prodCatalog));
        $s = $this->catalogMigrationStatistics($edit,$prod,true);
        $scan = json_decode($this->ReadAttributeString('DiscoveryInventory'),true);
        $roots = $this->discoveryRoots();
        $scanned = is_array($scan) && is_array($scan['items']??null)
            && ($scan['roots']??null) === $roots && $roots !== [] && empty($scan['truncated']);
        $report = $this->catalogMigrationShortSummary($s);
        $report .= $s['migrationReady'] ? ' Katalog-Modell technisch konsistent.'
            : ' Katalog-Modell noch NICHT vollstaendig migriert.';
        $report .= $scanned ? ' Inventur vorhanden; IP-Symcon-Zuordnungen fachlich pruefen.'
            : ' Keine nachweislich vollstaendige Inventur aller Suchwurzeln.';
        if ($s['examples'] !== []) $report .= "\nOffene Punkte (Auswahl):\n".implode("\n",$s['examples']);
        $this->WriteAttributeString('MigrationReadinessDetails',json_encode([
            'checkedAt'=>time(), 'catalogHash'=>hash('sha256',json_encode($editCatalog,JSON_INVALID_UTF8_SUBSTITUTE)),
            'statistics'=>$s, 'scanAvailable'=>$scanned
        ],JSON_INVALID_UTF8_SUBSTITUTE));
        $this->WriteAttributeString('MigrationReadinessReport',$report);
        $this->UpdateFormField('MigrationReadinessReport','caption',$report);
        echo $report;
    }

    // =====================================================================
    // Konfigurationsformular
    // =====================================================================

    public function GetConfigurationForm()
    {
        $this->ensureRuntimeCatalogCurrent();
        $entriesProd  = $this->getProdEntries();
        $entriesEdit  = $this->getRuntimeEntries();
        $filterAI = $this->ReadAttributeString('FilterAI');
        $filters = $this->normalizeCurrentFilters($entriesEdit,$entriesProd);
        $filterRoom = $filters['room'];
        $filterDomain = $filters['domain'];
        $filterGroup = $filters['group'];

        $editScriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $editCatalog = $this->loadConfiguredCatalog('RoomsCatalogEditScriptID', 'EDIT');
        $prodCatalog = $this->loadConfiguredCatalog('RoomsCatalogScriptID', 'PROD');
        $roomRows = $this->buildRoomListRows($editCatalog);
        $roomDiffRows = $this->buildRoomDiffEntries($prodCatalog, $editCatalog);
        $suggestionPreview = json_decode($this->ReadAttributeString('SuggestionPreview'), true);
        $suggestionRows = is_array($suggestionPreview) && is_array($suggestionPreview['suggestions'] ?? null)
            ? $suggestionPreview['suggestions'] : [];
        $discoveryPreview = json_decode($this->ReadAttributeString('DiscoveryCandidates'), true);
        $discoveryRows = is_array($discoveryPreview) && is_array($discoveryPreview['rows'] ?? null)
            ? $discoveryPreview['rows'] : [];
        $legacyPreview = json_decode($this->ReadAttributeString('LegacyMigrationPreview'),true);
        $legacyRows = is_array($legacyPreview) && is_array($legacyPreview['rows'] ?? null)
            ? $legacyPreview['rows'] : [];
        $inventoryPreview = json_decode($this->ReadAttributeString('DiscoveryInventory'),true);
        $discoverySummary = is_array($inventoryPreview) && isset($inventoryPreview['items'])
            ? $this->discoveryInventorySummary($inventoryPreview) : 'Noch keine Geraeteinventur gestartet.';
        $inventoryRows = is_array($inventoryPreview) ? $this->discoveryInventoryRows($inventoryPreview) : [];
        $bulkOptions = $this->bulkOptions();
        $bulkPreview = json_decode($this->ReadAttributeString('BulkPreview'),true);
        $migrationStats = $this->catalogMigrationStatistics($entriesEdit,$entriesProd);
        $migrationSummary = $this->catalogMigrationShortSummary($migrationStats);

        $roomOptions = $filters['roomOptions'];
        $domainOptions = $filters['domainOptions'];
        $groupOptions = $filters['groupOptions'];
        $knownRooms = array_column($roomOptions, 'value');
        foreach ($roomRows as $roomRow) {
            if (!in_array($roomRow['roomKey'], $knownRooms, true)) {
                $roomOptions[] = ['caption' => $roomRow['display'] . ' (' . $roomRow['roomKey'] . ')', 'value' => $roomRow['roomKey']];
            }
        }

        $visibleProd    = $this->filterByAI($this->applyFilters($entriesEdit, $filterRoom, $filterDomain, $filterGroup), $filterAI);
        $sharedDisplay=$this->sharedFeedbackAssignments($entriesEdit)['byDevice'];
        $visibleProd    = array_map(fn(array $r): array => $this->decorateRow($r,
            $sharedDisplay[(string)$this->buildCompositeKey($r)]??[]), $visibleProd);
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
                    'type'=>'RowLayout',
                    'items'=>[
                        ['type'=>'Button',
                            'caption'=>'ALLE PRUEFUNGEN UND ZWISCHENSPEICHER ZURUECKSETZEN',
                            'confirm'=>'Alle bisherigen Analyse-/Inventurberichte, Diagnose- und Reparaturvorschauen sowie Markierungen im RoomsCatalogConfigurator verwerfen? Gespeicherte EDIT/PROD-Geraetedaten, Freigaben und manuelle Bestaetigungen bleiben unveraendert. Bestehende EDIT/PROD-Unterschiede werden NICHT rueckgaengig gemacht.',
                            'onClick'=>'RCC_ResetAllCheckCaches($id);']
                    ]
                ],
                ['type'=>'Label','name'=>'ResetCheckCachesNotice','caption'=>
                    'Setzt nur temporäre Prüfungen, Suchergebnisse und Markierungen zurück; '
                    .'kein Zurücksetzen gespeicherter Geräte-, Freigabe- oder PROD-Daten.'],
                [
                    'type'=>'Label',
                    'name'=>'CatalogSummary',
                    'caption'=>'RoomsCatalog EDIT: '.count($entriesEdit).' Eintraege. '
                        .($aiReadable-$aiWritable).' KI nur lesen, '.$aiWritable.' KI schaltbar, '
                        .(count($entriesEdit)-$aiReadable).' ohne KI-Freigabe. EDIT wird erst nach Freigabe produktiv.'
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
                    'type'=>'ExpansionPanel',
                    'caption'=>'Datenstand und KI-Berechtigungen',
                    'expanded'=>true,
                    'items'=>[
                        ['type'=>'Label','name'=>'MigrationSummary','caption'=>$migrationSummary],
                        ['type'=>'Label','caption'=>
                            'KI-Lese-/Schreibfreigabe und fachlich bestaetigte Geraetezuordnung sind getrennt. '
                            .'Keine KI-Schreibfreigaben ohne ausdrueckliche Freigabe.'],
                        ['type'=>'Button','caption'=>'MODELL, IPS-VARIABLEN UND MIGRATION PRUEFEN',
                            'onClick'=>'RCC_AuditMigrationReadiness($id);'],
                        ['type'=>'Label','name'=>'MigrationReadinessReport',
                            'caption'=>$this->currentMigrationReport($editCatalog)],
                    ],
                ],
                [
                    'type'=>'ExpansionPanel',
                    'caption'=>'Geraetepruefung & Migrationsabschluss',
                    'expanded'=>true,
                    'items'=>[
                        ['type'=>'Label','caption'=>
                            'V3.3.9: Alle Geraetetypen aus echten IPS-Instanz-/Variablenmetadaten pruefen. '
                            .'Keine Livewerte, keine Schaltbefehle, keine KI-Freigaben. '
                            .'Technisch plausibel ist noch KEINE fachliche Bestaetigung.'],
                        ['type'=>'Label','caption'=>
                            'WICHTIG BEI GEANDERTEN KATALOGZAHLEN: Zuerst DIESE technische Pruefung '
                            .'ausfuehren. Sie vergleicht mit dem gespeicherten alten Migrationsbericht. '
                            .'Erst danach die Migrationspruefung erneut starten, sonst geht der alte '
                            .'Vergleichsstand verloren. Keine EDIT-/PROD-Uebernahme vor der Ursachenpruefung.'],
                        ['type'=>'Button','caption'=>'ALLE GERAETE TECHNISCH PRUEFEN',
                            'onClick'=>'RCC_RunTechnicalDeviceAudit($id);'],
                        ['type'=>'Label','name'=>'TechnicalDeviceSummary',
                            'caption'=>$this->technicalDeviceSummaryFromSnapshot($this->deviceTechnicalSnapshot())],
                        ['type'=>'Label','name'=>'TechnicalDeviceSourceSummary',
                            'caption'=>$this->technicalDeviceSourceSummary($this->deviceTechnicalSnapshot())],
                        ['type'=>'Label','caption'=>'V3.3.12: Diagnose mit allen technischen Rollen, Geraeten und IPS-Metadaten als JSON-Datei fuer ChatGPT exportieren. Keine aktuellen Variablenwerte, Zugangsdaten oder Schaltbefehle.'],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'Button','caption'=>'1 – GESAMTDIAGNOSE FUER CHATGPT HERUNTERLADEN',
                                'download'=>'RoomsCatalog_ChatGPT_Diagnose.json',
                                'onClick'=>'RCC_ExportAIRepairDiagnostics($id);'],
                            ['type'=>'Button','caption'=>'2 – SICHERE AUTO-KORREKTUREN NACH EDIT + PROD',
                                'confirm'=>'Nur nach dem Diagnoseexport: Eindeutig erkannte fehlende Lesevariablen nach EDIT UND PROD schreiben? Es werden zuerst zwei vollstaendige Backups erstellt. Keine KI-Rechte, Schaltbefehle oder fachlichen Bestaetigungen. Unklare Geraete bleiben offen.',
                                'onClick'=>'RCC_ApplySafeAutoRepair($id);']
                        ]],
                        ['type'=>'Label','name'=>'AutoRepairNotice',
                            'caption'=>$this->ReadAttributeString('AutoRepairNotice')],
                        [
                            'type'=>'List','name'=>'TechnicalDeviceChecks',
                            'caption'=>'Technische Geraetepruefung (alle Typen)',
                            'rowCount'=>15,'loadValuesFromConfiguration'=>false,
                            'add'=>false,'delete'=>false,
                            'columns'=>[
                                ['caption'=>'Auswahl','name'=>'selected','width'=>'80px','edit'=>['type'=>'CheckBox'],'add'=>false],
                                ['caption'=>'Raum','name'=>'room','width'=>'120px','save'=>false,'quickFilter'=>true,'add'=>''],
                                ['caption'=>'Geraet','name'=>'title','width'=>'170px','save'=>false,'quickFilter'=>true,'add'=>''],
                                ['caption'=>'Typ','name'=>'type','width'=>'95px','save'=>false,'quickFilter'=>true,'add'=>''],
                                ['caption'=>'Technischer Stand','name'=>'status','width'=>'145px','save'=>false,'quickFilter'=>true,'add'=>''],
                                ['caption'=>'Funktionsumfang','name'=>'capability','width'=>'230px','save'=>false,'quickFilter'=>true,'add'=>''],
                                ['caption'=>'Manuell bestaetigt','name'=>'manuallyConfirmed','width'=>'110px','save'=>false,'add'=>false],
                                ['caption'=>'Rollen','name'=>'roleCount','width'=>'65px','save'=>false,'add'=>0],
                                ['caption'=>'Grund / offener Punkt','name'=>'reason','width'=>'auto','save'=>false,'add'=>''],
                                ['caption'=>'Interner Schluessel','name'=>'key','visible'=>false,'save'=>false,'add'=>''],
                                ['caption'=>'Signatur','name'=>'fingerprint','visible'=>false,'save'=>false,'add'=>'']
                            ],
                            'values'=>(array)($this->deviceTechnicalSnapshot()['rows'] ?? []),
                            'onEdit'=>'RCC_MarkTechnicalDevice($id, (string)($TechnicalDeviceChecks["key"] ?? ""), '
                                .'(bool)($TechnicalDeviceChecks["selected"] ?? false));'
                        ],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'Button','caption'=>'TECHNISCH PLAUSIBLE MARKIEREN',
                                'onClick'=>'RCC_SelectTechnicalDevices($id, true);'],
                            ['type'=>'Button','caption'=>'MARKIERUNGEN AUFHEBEN',
                                'onClick'=>'RCC_SelectTechnicalDevices($id, false);']
                        ]],
                        ['type'=>'Label','caption'=>
                            'Schritt A – Bei technisch plausiblen Geraeten nur die Pruefmetadaten nach EDIT speichern. '
                            .'Die fachliche Bestaetigung bleibt offen.'],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'Button','caption'=>'TECHNISCHE UEBERNAHME VORSCHAU',
                                'onClick'=>'RCC_PreviewTechnicalDeviceReview($id);'],
                            ['type'=>'Button','caption'=>'TECHNISCHE ERGEBNISSE NACH EDIT SPEICHERN',
                                'confirm'=>'Markierte technisch plausible Geraete nur als TECHNISCH geprueft nach EDIT schreiben? Alle bisherigen Freigaben und PROD bleiben unveraendert.',
                                'onClick'=>'RCC_ApplyTechnicalDeviceReview($id);']
                        ]],
                        ['type'=>'Label','caption'=>
                            'Schritt B – NUR wenn du jede markierte Zuordnung, Statusbedeutung '
                            .'und Steuerfunktion am ECHTEN Geraet geprueft hast:'],
                        ['type'=>'CheckBox','name'=>'TechnicalDevicesPhysicallyChecked',
                            'caption'=>'Ich habe ALLE markierten Geraete und die Zuordnungen tatsaechlich am System geprueft.',
                            'value'=>false],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'Button','caption'=>'MANUELLE BESTAETIGUNG VORSCHAU',
                                'onClick'=>'RCC_PreviewManualDeviceConfirmation($id, (bool)$TechnicalDevicesPhysicallyChecked);'],
                            ['type'=>'Button','caption'=>'GEPRUEFTE GERAETE NACH EDIT BESTAETIGEN',
                                'confirm'=>'Wirklich alle markierten Geraete am echten System inkl. Soll/Ist, Ein/Aus, Offen/Geschlossen und Aktionen geprueft? Erst dann wird ai.review.status=confirmed gesetzt.',
                                'onClick'=>'RCC_ApplyManualDeviceConfirmation($id, (bool)$TechnicalDevicesPhysicallyChecked);']
                        ]],
                        ['type'=>'Label','name'=>'TechnicalDeviceActionMessage',
                            'caption'=>$this->ReadAttributeString('TechnicalDeviceActionMessage')],
                        ['type'=>'ExpansionPanel','caption'=>'Legacy-Geraete – noch nicht typisiert',
                            'expanded'=>false,'items'=>[
                                ['type'=>'Label','caption'=>'Nur lesende Liste. Die sichere Typisierung erfolgt unter Geraete erkennen, inventarisieren und reparieren.'],
                                ['type'=>'List','name'=>'TechnicalLegacyDevices','caption'=>'Legacy-Katalogeintraege',
                                    'rowCount'=>12,'loadValuesFromConfiguration'=>false,
                                    'add'=>false,'delete'=>false,
                                    'columns'=>[
                                        ['caption'=>'Raum','name'=>'room','width'=>'135px','save'=>false,'add'=>''],
                                        ['caption'=>'Geraet','name'=>'title','width'=>'180px','save'=>false,'add'=>''],
                                        ['caption'=>'Katalogpfad','name'=>'path','width'=>'290px','save'=>false,'add'=>''],
                                        ['caption'=>'Hinweis','name'=>'message','width'=>'auto','save'=>false,'add'=>'']
                                    ],
                                    'values'=>(array)($this->deviceTechnicalSnapshot()['legacyRows'] ?? [])
                                ]
                            ]],
                        ['type'=>'ExpansionPanel','caption'=>'EDIT/PROD-Unterschiede – alle Eintraege',
                            'expanded'=>false,'items'=>[
                                ['type'=>'Label','caption'=>'Vollstaendige Liste: nur Namen geaenderter Felder, keine konkreten Werte oder Geheimnisse. Vor Produktivuebernahme jede Aenderung bewerten.'],
                                ['type'=>'List','name'=>'TechnicalDiffRows','caption'=>'Neue, entfernte und geaenderte Eintraege',
                                    'rowCount'=>15,'loadValuesFromConfiguration'=>false,
                                    'add'=>false,'delete'=>false,
                                    'columns'=>[
                                        ['caption'=>'Raum','name'=>'room','width'=>'120px','save'=>false,'add'=>''],
                                        ['caption'=>'Geraet','name'=>'title','width'=>'150px','save'=>false,'add'=>''],
                                        ['caption'=>'Art','name'=>'type','width'=>'90px','save'=>false,'add'=>''],
                                        ['caption'=>'Betroffene Felder','name'=>'fields','width'=>'220px','save'=>false,'add'=>''],
                                        ['caption'=>'Katalogpfad','name'=>'path','width'=>'auto','save'=>false,'add'=>'']
                                    ],
                                    'values'=>(array)($this->deviceTechnicalSnapshot()['diffRows'] ?? [])
                                ]
                            ]]
                    ]
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Raeume & Etagen',
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
                                    'caption'=>'ALLE GERAETE MARKIEREN (AUCH GEFILTERTE)',
                                    'onClick'=>'RCC_SetAllSelection($id, true);'
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
                    'type'=>'ExpansionPanel',
                    'caption'=>'Mehrere Geraete bearbeiten',
                    'expanded'=>true,
                    'items'=>[
                        ['type'=>'Label','name'=>'BulkSelectionSummary',
                            'caption'=>$this->bulkSelectionSummary($entriesEdit)],
                        ['type'=>'Label','caption'=>'Nur ausgewaehlte Eigenschaften werden geaendert. Unterschiedliche Geraetenamen, IDs, Raeume, bisherige Bereiche/Gruppen und sonstige Legacy-Felder bleiben unveraendert. Mehrfachaenderungen immer zuerst in EDIT pruefen.'],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'Select','name'=>'BulkDeviceType','caption'=>'Geraetetyp fuer alle Markierten',
                                'options'=>[
                                    ['caption'=>'Nicht aendern','value'=>'keep'],
                                    ...array_map(static fn(string $type,string $caption):array=>
                                        ['caption'=>$caption,'value'=>$type],
                                        array_keys(RCCDeviceTypeRegistry::captions()),
                                        array_values(RCCDeviceTypeRegistry::captions()))
                                ],
                                'value'=>$bulkOptions['type'],
                                'onChange'=>'RCC_SetBulkOption($id, "BulkDeviceType", $BulkDeviceType);'],
                            ['type'=>'Select','name'=>'BulkAIMode','caption'=>'KI-Freigabe',
                                'options'=>[
                                    ['caption'=>'Nicht aendern','value'=>'keep'],
                                    ['caption'=>'Ohne KI','value'=>'disabled'],
                                    ['caption'=>'Mit KI – nur abfragen','value'=>'read'],
                                    ['caption'=>'Mit KI – Schalten erlauben','value'=>'write']
                                ],
                                'value'=>$bulkOptions['aiMode'],
                                'onChange'=>'RCC_SetBulkOption($id, "BulkAIMode", $BulkAIMode);']
                        ]],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'Select','name'=>'BulkSuggestions','caption'=>'Fehlende Variablen pro Geraet',
                                'options'=>[
                                    ['caption'=>'Nur eindeutige Vorschlaege uebernehmen','value'=>'yes'],
                                    ['caption'=>'Bestehende Zuordnungen unveraendert lassen','value'=>'no']
                                ],
                                'value'=>$bulkOptions['suggest'] ? 'yes' : 'no',
                                'onChange'=>'RCC_SetBulkOption($id, "BulkSuggestions", $BulkSuggestions);'],
                            ['type'=>'Select','name'=>'BulkReview','caption'=>'Pruefstatus',
                                'options'=>[
                                    ['caption'=>'Unveraendert','value'=>'keep'],
                                    ['caption'=>'Bei allen auf offen setzen','value'=>'pending']
                                ],
                                'value'=>$bulkOptions['review'],
                                'onChange'=>'RCC_SetBulkOption($id, "BulkReview", $BulkReview);']
                        ]],
                        ['type'=>'Button','caption'=>'SAMMELBEARBEITUNG VORSCHAU',
                            'onClick'=>'RCC_PreviewBulkEdit($id);'],
                        ['type'=>'Label','name'=>'BulkPreviewSummary',
                            'caption'=>(string)($bulkPreview['message'] ?? 'Noch keine gueltige Vorschau. Auswahl und Einstellungen festlegen.')],
                        ['type'=>'Button','caption'=>'VORSCHAU NACH ROOMSCATALOG EDIT UEBERNEHMEN',
                            'confirm'=>'Die angezeigten Eigenschaften bei ALLEN markierten Geraeten im EDIT-Katalog aendern? Eine KI-Schreibfreigabe ist nur bei expliziter Auswahl erlaubt. PROD bleibt unveraendert.',
                            'onClick'=>'RCC_ApplyBulkEdit($id);']
                    ]
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Variablenzuordnungen pruefen',
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
                    'type' => 'ExpansionPanel',
                    'caption' => 'IP-Symcon-Geraeteinventur',
                    'expanded' => true,
                    'items' => [
                        ['type'=>'Label','caption'=>'Nur die hier ausgewaehlten Kategorien/Instanzen werden durchsucht. Gefundene Geraete sind erst Vorschlaege; keine automatische KI- oder Schreibfreigabe. Suchwurzeln nach dem Aendern mit Uebernehmen speichern.'],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'SelectObject','name'=>'DiscoveryRootId','caption'=>'Suchwurzel (Kategorie oder Instanz)'],
                            ['type'=>'ValidationTextBox','name'=>'DiscoveryExtraRootIds','caption'=>'Weitere Suchwurzeln (IPS-IDs, Komma getrennt)']
                        ]],
                        ['type'=>'Button','caption'=>'IP-SYMCON-GERAETE INVENTARISIEREN','onClick'=>'RCC_ScanNewDevices($id);'],
                        ['type'=>'Label','name'=>'DiscoverySummary','caption'=>$discoverySummary],
                        ['type'=>'Button','caption'=>'DATENSTAND UND OFFENE PUNKTE PRUEFEN',
                            'onClick'=>'RCC_AuditDiscoveryCoverage($id);'],
                        ['type'=>'Button','caption'=>'INVENTAR ALS JSON ZUM KOPIEREN',
                            'onClick'=>'RCC_ExportDiscoveryInventory($id);'],
                        ['type'=>'Label','name'=>'DiscoveryAudit',
                            'caption'=>$this->ReadAttributeString('DiscoveryAuditReport')],
                        [
                            'type'=>'List','name'=>'DiscoveryInventoryList',
                            'caption'=>'Inventar aller gefundenen Geraete, inklusive Bestand (IPS-ID anklicken fuer Details)',
                            'rowCount'=>12,'loadValuesFromConfiguration'=>false,
                            'add'=>false,'delete'=>false,
                            'sort'=>['column'=>'name','direction'=>'ascending'],
                            'columns'=>[
                                ['caption'=>'IPS-ID / Details','name'=>'sourceId','width'=>'110px','save'=>false,
                                    'quickFilter'=>true,
                                    'onClick'=>'RCC_ShowDiscoveryDetails($id, (int)($DiscoveryInventoryList["sourceId"] ?? 0));'],
                                ['caption'=>'Geraet','name'=>'name','width'=>'175px','save'=>false,'quickFilter'=>true],
                                ['caption'=>'Modul','name'=>'moduleName','width'=>'125px','save'=>false,'quickFilter'=>true],
                                ['caption'=>'Raum','name'=>'roomKey','width'=>'100px','save'=>false],
                                ['caption'=>'Typ','name'=>'deviceType','width'=>'105px','save'=>false],
                                ['caption'=>'Bestand / Neu','name'=>'knownLabel','width'=>'95px','save'=>false],
                                ['caption'=>'Variablen','name'=>'variableCount','width'=>'80px','save'=>false],
                                ['caption'=>'Hinweis (Scan-Vorschlag)','name'=>'roleMissingText','width'=>'auto','save'=>false]
                            ],
                            'values'=>$inventoryRows
                        ],
                        [
                            'type'=>'List','name'=>'DiscoveredDevices','caption'=>'Neue Geraete: pruefen und zur Uebernahme markieren',
                            'rowCount'=>15,'loadValuesFromConfiguration'=>false,
                            'add'=>false,'delete'=>false,
                            'sort'=>['column'=>'title','direction'=>'ascending'],
                            'columns'=>[
                                ['caption'=>'Uebernehmen','name'=>'selected','width'=>'95px','edit'=>['type'=>'CheckBox']],
                                ['caption'=>'IPS-ID (Details)','name'=>'sourceId','width'=>'105px','save'=>false,
                                    'quickFilter'=>true,
                                    'onClick'=>'RCC_ShowDiscoveryDetails($id, (int)($DiscoveredDevices["sourceId"] ?? 0));'],
                                ['caption'=>'Modultyp','name'=>'moduleName','width'=>'135px','save'=>false,'quickFilter'=>true],
                                ['caption'=>'Quelle','name'=>'sourceType','width'=>'85px','save'=>false],
                                ['caption'=>'Geraetename','name'=>'title','width'=>'165px','edit'=>['type'=>'ValidationTextBox'],'quickFilter'=>true],
                                ['caption'=>'Raum','name'=>'roomKey','width'=>'125px','edit'=>['type'=>'Select','options'=>$roomOptions]],
                                ['caption'=>'Geraetetyp','name'=>'deviceType','width'=>'115px','edit'=>['type'=>'Select','options'=>[
                                    ...array_map(static fn(string $type,string $caption):array=>
                                        ['caption'=>$caption,'value'=>$type],
                                        array_keys(RCCDeviceTypeRegistry::captions()),
                                        array_values(RCCDeviceTypeRegistry::captions()))
                                ]]],
                                ['caption'=>'Variablen','name'=>'variableCount','width'=>'80px','save'=>false],
                                ['caption'=>'Bewertung','name'=>'confidence','width'=>'90px','save'=>false],
                                ['caption'=>'Datenstand','name'=>'readiness','width'=>'95px','save'=>false],
                                ['caption'=>'Hinweis','name'=>'reason','width'=>'auto','save'=>false]
                            ],
                            'values'=>$discoveryRows,
                            'onEdit'=>'RCC_UpdateDiscoveryCandidate($id, json_encode(array_filter(['
                                . '\'sourceId\' => ($DiscoveredDevices["sourceId"] ?? null), '
                                . '\'selected\' => ($DiscoveredDevices["selected"] ?? null), '
                                . '\'roomKey\' => ($DiscoveredDevices["roomKey"] ?? null), '
                                . '\'title\' => ($DiscoveredDevices["title"] ?? null), '
                                . '\'deviceType\' => ($DiscoveredDevices["deviceType"] ?? null)'
                                . '], static fn($v) => $v !== null), JSON_INVALID_UTF8_SUBSTITUTE));'
                        ],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'Button','caption'=>'EINDEUTIGE MARKIEREN','onClick'=>'RCC_SelectDiscoveredDevices($id, true);'],
                            ['type'=>'Button','caption'=>'ALLE MARKIERUNGEN AUFHEBEN','onClick'=>'RCC_SelectDiscoveredDevices($id, false);'],
                            ['type'=>'Button','caption'=>'MARKIERTE GERAETE NACH EDIT UEBERNEHMEN',
                                'confirm'=>'Markierte neue Geraete ausschliesslich nach RoomsCatalogEdit importieren? KI bleibt deaktiviert und PROD unveraendert.',
                                'onClick'=>'RCC_ApplyDiscoveredDevices($id);']
                        ]],
                        ['type'=>'Label','caption'=>'Alle 10 Geraeteklassen werden analysiert. Der Bericht unterscheidet die abgeschlossene Suche vom fachlich geprueften Datenstand. Neue Geraete starten mit KI aus und Nur abfragen an. Profile und Namen liefern Vorschlaege, keine ungeprueften Schaltrechte.']
                    ]
                ],
                [
                    'type'=>'ExpansionPanel',
                    'caption'=>'Altgeraete ins neue Modell uebernehmen',
                    'expanded'=>false,
                    'items'=>[
                        ['type'=>'Label','caption'=>'Bestehende Legacy-Geraete analysieren. Die Uebernahme fuegt nur ai.type/Variablen im EDIT-Katalog hinzu. Alte Felder, KI-Freigaben und PROD bleiben erhalten.'],
                        ['type'=>'Button','caption'=>'BESTEHENDE GERAETE ANALYSIEREN',
                            'onClick'=>'RCC_AnalyzeLegacyDevices($id);'],
                        [
                            'type'=>'List','name'=>'LegacyDevices','caption'=>'Bestehende Geraete zur Typisierung',
                            'rowCount'=>12,'loadValuesFromConfiguration'=>false,
                            'add'=>false,'delete'=>false,
                            'columns'=>[
                                ['caption'=>'Uebernehmen','name'=>'selected','width'=>'90px','edit'=>['type'=>'CheckBox']],
                                ['caption'=>'Katalogpfad','name'=>'composite','width'=>'200px','save'=>false,'quickFilter'=>true],
                                ['caption'=>'Geraet','name'=>'title','width'=>'150px','save'=>false,'quickFilter'=>true],
                                ['caption'=>'Raum','name'=>'roomKey','width'=>'105px','save'=>false],
                                ['caption'=>'IPS-ID','name'=>'sourceId','width'=>'85px','save'=>false],
                                ['caption'=>'Typ','name'=>'deviceType','width'=>'135px','edit'=>['type'=>'Select','options'=>[
                                    ...array_map(static fn(string $type,string $caption):array=>
                                        ['caption'=>$caption,'value'=>$type],
                                        array_keys(RCCDeviceTypeRegistry::captions()),
                                        array_values(RCCDeviceTypeRegistry::captions()))
                                ]]],
                                ['caption'=>'Bewertung','name'=>'confidence','width'=>'85px','save'=>false],
                                ['caption'=>'Pruefhinweis','name'=>'reason','width'=>'auto','save'=>false]
                            ],
                            'values'=>$legacyRows,
                            'onEdit'=>'RCC_UpdateLegacyCandidate($id, json_encode(array_filter(['
                                . '\'composite\' => ($LegacyDevices["composite"] ?? null), '
                                . '\'selected\' => ($LegacyDevices["selected"] ?? null), '
                                . '\'deviceType\' => ($LegacyDevices["deviceType"] ?? null)'
                                . '], static fn($v) => $v !== null), JSON_INVALID_UTF8_SUBSTITUTE));'
                        ],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'Button','caption'=>'EINDEUTIGE MARKIEREN','onClick'=>'RCC_SelectLegacyCandidates($id, true);'],
                            ['type'=>'Button','caption'=>'MARKIERUNGEN AUFHEBEN','onClick'=>'RCC_SelectLegacyCandidates($id, false);'],
                            ['type'=>'Button','caption'=>'MARKIERTE NACH EDIT UEBERNEHMEN',
                                'confirm'=>'Bestehende Geraete ohne Aenderung der Legacy-Felder nach ai.type migrieren? Nur EDIT wird beschrieben.',
                                'onClick'=>'RCC_ApplyLegacyCandidates($id);']
                        ]]
                    ]
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => 'Vorschau: Unterschiede EDIT zu PROD',
                    'expanded' => $diffExpanded || $roomDiffRows !== [],
                    'items'    => [
                        [
                            'type' => 'List', 'name' => 'RoomsDiff',
                            'caption' => 'Raum-Aenderungen EDIT zu PROD',
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
                    'type'=>'ExpansionPanel',
                    'caption'=>'BEREICHSWEISE PROD-UEBERNAHME – NUR GUELTIGE GERAETE',
                    'expanded'=>true,
                    'items'=>[
                        ['type'=>'Label','caption'=>
                            'Unabhaengig von anderen Bereichen: Nur neue/geaenderte und technisch plausible '
                            .'Geraete des ausgewaehlten Bereichs nach PROD. Ungueltige Geraete werden '
                            .'einzeln gesperrt, Jalousien ohne ECHTE Rueckmeldung niemals uebernommen. '
                            .'Keine Geraete-Loeschungen, keine KI-Schreibrechte-Erweiterungen und '
                            .'keine Aenderung an anderen Bereichen oder Raum-Metadaten.'],
                        ['type'=>'Select','name'=>'PartialProdDomain',
                            'caption'=>'Bereich fuer die PROD-Teiluebernahme',
                            'options'=>$this->scopedProdDomainOptions($entriesEdit),
                            'value'=>$this->ReadAttributeString('PartialProdSelectedDomain'),
                            'onChange'=>'RCC_SetScopedProdDomain($id, (string)$PartialProdDomain);'],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'Button','caption'=>'1 – GUELTIGE AENDERUNGEN IM BEREICH PRUEFEN',
                                'onClick'=>'RCC_PreviewScopedProdPublish($id, (string)$PartialProdDomain);'],
                            ['type'=>'Button','caption'=>'2 – NUR GUELTIGE AENDERUNGEN NACH PROD',
                                'confirm'=>'Nur die in der aktuellen Bereichsvorschau als GUELTIG markierten Geraete nach PROD uebernehmen? Ungueltige Jalousien und andere Bereiche bleiben unangetastet. Vorher PROD-Backup; EDIT bleibt unveraendert.',
                                'onClick'=>'RCC_ApplyScopedProdPublish($id, (string)$PartialProdDomain);']
                        ]],
                        ['type'=>'Label','name'=>'PartialProdPublishNotice',
                            'caption'=>$this->ReadAttributeString('PartialProdPublishNotice')],
                        ['type'=>'List','name'=>'PartialProdPublishRows',
                            'caption'=>'Bereichs-Vorschau: gueltig / gesperrt',
                            'rowCount'=>12,'loadValuesFromConfiguration'=>false,
                            'add'=>false,'delete'=>false,
                            'columns'=>[
                                ['caption'=>'Raum/Bereich/Gruppe/Key','name'=>'key','width'=>'270px','save'=>false,'add'=>''],
                                ['caption'=>'Geraet','name'=>'title','width'=>'180px','save'=>false,'add'=>''],
                                ['caption'=>'Ergebnis','name'=>'status','width'=>'90px','save'=>false,'add'=>''],
                                ['caption'=>'Grund','name'=>'reason','width'=>'auto','save'=>false,'add'=>'']
                            ],
                            'values'=>json_decode($this->ReadAttributeString('PartialProdPublishRows'),true)??[]
                        ],
                        ['type'=>'ExpansionPanel',
                            'caption'=>'NUR EINE JALOUSIE-POSITIONS-ID NACH PROD (OHNE GESAMTPRUEFUNG)',
                            'expanded'=>true,
                            'items'=>[
                                ['type'=>'Label','caption'=>
                                    'Hier wird nur die geaenderte Positions-ID des ausgewaehlten Geraets '
                                    .'in PROD uebernommen. Keine Gesamtpruefung und keine Uebernahme von '
                                    .'Offen/Geschlossen-Werten, Steuerbefehlen, KI-Rechten oder weiteren '
                                    .'geänderten Feldern. Die einzelne ID muss existieren, Integer/Float '
                                    .'und als Positions-Rueckmeldung plausibel sein (kein Fahrbefehl).'],
                                ['type'=>'Select','name'=>'SinglePositionKey',
                                    'caption'=>'Geraet mit geaenderter Positions-ID',
                                    'options'=>$this->singlePositionProdOptions($editCatalog,$prodCatalog),
                                    'value'=>$this->ReadAttributeString('SinglePositionSelectedKey'),
                                    'onChange'=>'RCC_SetSinglePositionProdKey($id,(string)$SinglePositionKey);'],
                                ['type'=>'RowLayout','items'=>[
                                    ['type'=>'Button','caption'=>'1 – NUR DIESE POSITIONS-ID PRUEFEN',
                                        'onClick'=>'RCC_PreviewSinglePositionProd($id,(string)$SinglePositionKey);'],
                                    ['type'=>'Button','caption'=>'2 – NUR DIESE POSITIONS-ID NACH PROD',
                                        'confirm'=>'Nur die angezeigte Positions-ID (und den zugehoerigen ai.variables.position-Wert) dieses EINEN Geraets in PROD aendern? Andere Feldunterschiede, ungeklaerte Statuswerte und KI-Freigaben bleiben unveraendert. Ein PROD-Backup wird erzeugt.',
                                        'onClick'=>'RCC_ApplySinglePositionProd($id,(string)$SinglePositionKey);']
                                ]],
                                ['type'=>'Label','name'=>'SinglePositionProdNotice',
                                    'caption'=>$this->ReadAttributeString('SinglePositionProdNotice')]
                            ]
                        ]
                    ]
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'GESAMTEN EDIT-KATALOG NACH PROD (NUR WENN ALLES GUELTIG)',
                    'confirm' => 'ALLE Bereiche gemeinsam nach PROD uebernehmen? Bei einer einzigen ungueltigen Jalousie wird dies weiterhin blockiert. Fuer Teiluebernahme bitte den Bereichs-Assistenten verwenden.',
                    'onClick' => 'RCC_SaveEditToProd($id);'
                ]
            ]
        ];

        // Einmalige Versionsassistenten nicht mehr im normalen Einstellungsfluss
        // anzeigen. Inventur und Variablenanalyse bleiben dauerhaft unter einer
        // zugeklappten Rubrik fuer neue Geraete erreichbar.
        $assistants = [];
        $remaining = [];
        $insertAt = null;
        foreach ($form['elements'] as $element) {
            $caption = (string)($element['caption'] ?? '');
            if (in_array($caption, [
                'Variablenzuordnungen pruefen',
                'IP-Symcon-Geraeteinventur',
                'Altgeraete ins neue Modell uebernehmen'
            ],true)) {
                if ($insertAt === null) $insertAt = count($remaining);
                if ($caption === 'Altgeraete ins neue Modell uebernehmen'
                    && $migrationStats['legacy'] === 0) {
                    continue; // Keine Altdaten mehr in EDIT: Assistent entfaellt.
                }
                $element['expanded'] = false;
                $assistants[] = $element;
                continue;
            }
            $remaining[] = $element;
        }
        if ($insertAt !== null) {
            array_splice($remaining,$insertAt,0,[[
                'type'=>'ExpansionPanel',
                'caption'=>'Geraete erkennen, inventarisieren und reparieren',
                'expanded'=>false,
                'items'=>array_merge([
                    ['type'=>'Label','caption'=>
                        'Geraete koennen hier auch nach abgeschlossener Migration erneut erkannt werden. '
                        .'Vorhandene Konfigurationen und KI-Berechtigungen bleiben bei Analysen unveraendert.']
                ],$assistants)
            ]]);
        }
        $form['elements'] = $remaining;

        // V3.3.21 – Keine separaten Freigabe-, Bereichs-, Einzelfeld-
        // oder Migrations-Publishing-Aktionen in der Hauptansicht.
        // Der regulaere Geraeteeditor mit seinen Prueffeldern bleibt.
        $everyday=['Raeume & Etagen','RoomsCatalog Scripts',
            'Geraete bearbeiten (RoomsCatalog EDIT)'];
        $main=[];
        foreach ($form['elements'] as $index=>$element) {
            $caption=(string)($element['caption']??'');
            if ($index<=2 || in_array($caption,$everyday,true)) {
                if (in_array($caption,$everyday,true)) $element['expanded']=true;
                $main[]=$element;
            }
        }
        // Pending changes are read directly from both current script sources,
        // not the last technical audit or resettable snapshot.
        $pending=$this->catalogPendingChanges($entriesProd,$entriesEdit,$editCatalog,$prodCatalog);
        $pendingSaveCallback=str_replace('$Entries[','$PendingChanges[',
            $saveSelectedRowCallback);
        $main[]=[
            'type'=>'ExpansionPanel',
            'caption'=>'Aenderungen in EDIT vor PROD ('.count($pending).' Geraete)',
            'expanded'=>true,
            'items'=>[
                ['type'=>'Label','name'=>'PendingChangesSummary',
                    'caption'=>count($pending).' Geraeteaenderungen vor PROD. '
                        .'Neue und geaenderte Geraete direkt ueber das Zahnrad bearbeiten. '
                        .'Entfernte Geraete sind nur zur Information aufgefuehrt. '
                        .'Die gesamte Uebernahme erfolgt weiterhin ueber den einen Button darunter.'],
                ['type'=>'List','name'=>'PendingChanges',
                    'caption'=>'Noch nicht nach PROD uebernommene Geraete',
                    'rowCount'=>12,'loadValuesFromConfiguration'=>false,
                    'add'=>false,'delete'=>false,'rowColor'=>'rowColor',
                    'sort'=>['column'=>'roomLabel','direction'=>'ascending'],
                    'form'=>'return json_decode(RCC_GetEntryEditForm($id, '
                        .'(string)($PendingChanges["roomKey"] ?? ""), '
                        .'(string)($PendingChanges["domain"] ?? ""), '
                        .'(string)($PendingChanges["group"] ?? ""), '
                        .'(string)($PendingChanges["key"] ?? "")), true);',
                    'columns'=>[
                        ['caption'=>'Raum','name'=>'roomLabel','width'=>'145px','save'=>false,'quickFilter'=>true,'add'=>''],
                        ['caption'=>'Bereich','name'=>'domain','width'=>'100px','save'=>false,'quickFilter'=>true,'add'=>''],
                        ['caption'=>'Geraet','name'=>'title','width'=>'180px','save'=>true,'quickFilter'=>true,'add'=>''],
                        ['caption'=>'Aenderung','name'=>'changeKind','width'=>'105px','save'=>false,'quickFilter'=>true,'add'=>''],
                        ['caption'=>'Geaenderte Felder','name'=>'changeDetails','width'=>'auto','save'=>false,'quickFilter'=>true,'add'=>''],
                        ['caption'=>'Key','name'=>'key','width'=>'110px','save'=>true,'add'=>'']
                    ],
                    'values'=>$pending,
                    'onEdit'=>$pendingSaveCallback
                ],
                ['type'=>'Label','caption'=>count($roomDiffRows)
                    .' geaenderte Raum-/Etagen-Metadaten (Raumverwaltung oben bearbeiten).'],
                ['type'=>'List','name'=>'PendingRoomChanges','caption'=>'Raumaenderungen',
                    'rowCount'=>5,'loadValuesFromConfiguration'=>false,
                    'add'=>false,'delete'=>false,
                    'columns'=>[
                        ['caption'=>'Raum-Key','name'=>'roomKey','width'=>'150px','save'=>false,'add'=>''],
                        ['caption'=>'Raum','name'=>'display','width'=>'180px','save'=>false,'add'=>''],
                        ['caption'=>'Etage','name'=>'floor','width'=>'95px','save'=>false,'add'=>''],
                        ['caption'=>'Aenderung','name'=>'change','width'=>'100px','save'=>false,'add'=>'']
                    ],
                    'values'=>$roomDiffRows]
            ]
        ];
        $main[]=[
            'type'=>'ExpansionPanel',
            'caption'=>'RoomsCatalog ueberpruefen – Diagnose fuer ChatGPT',
            'expanded'=>false,
            'items'=>[
                ['type'=>'Label','caption'=>'Liest aktuelle EDIT-/PROD-Daten und IPS-Variablenmetadaten. '
                    .'Zeigt alle offenen Punkte als kopierbaren JSON-Text an. '
                    .'Keine Livewerte, keine Schaltbefehle und keine Aenderungen am Katalog. '
                    .'Die Diagnose blockiert EDIT NACH PROD nicht.'],
                ['type'=>'Button','caption'=>'ROOMSCATALOG UEBERPRUEFEN',
                    'onClick'=>'RCC_CheckRoomsCatalog($id);'],
                ['type'=>'Label','name'=>'CatalogCheckDebugSummary',
                    'caption'=>$this->ReadAttributeString('CatalogCheckDebugSummary')],
                ['type'=>'ValidationTextBox','name'=>'CatalogCheckDebugOutput',
                    'caption'=>'Debug-Ausgabe (alles markieren und hier einfuegen)',
                    'multiline'=>true,'width'=>'100%',
                    'value'=>$this->ReadAttributeString('CatalogCheckDebugOutput')],
                ['type'=>'ExpansionPanel','caption'=>'KNX-Jalousie-Statuswerte sicher in EDIT korrigieren',
                    'expanded'=>false,'items'=>[
                        ['type'=>'Label','caption'=>'Nur eindeutig abweichende Status-Zuordnungen mit '
                            .'Profil ~ShutterStatus.KNX und bisherigen Werten 0/100. '
                            .'Erst Vorschau; anschliessend manuelle Uebernahme mit EDIT-Backup. '
                            .'Fahrbefehle, KI-Freigaben, andere Geraete und PROD bleiben unveraendert.'],
                        ['type'=>'Button','caption'=>'1 – STATUS-KORREKTUR VORSCHAU',
                            'onClick'=>'RCC_PreviewKnxStatusRepair($id);'],
                        ['type'=>'Label','name'=>'KnxStatusRepairSummary',
                            'caption'=>$this->ReadAttributeString('KnxStatusRepairSummary')],
                        ['type'=>'ValidationTextBox','name'=>'KnxStatusRepairPreviewText',
                            'caption'=>'Korrekturvorschau (alt / neu)',
                            'multiline'=>true,'width'=>'100%',
                            'value'=>$this->ReadAttributeString('KnxStatusRepairPreviewText')],
                        ['type'=>'Button','caption'=>'2 – GEPRUEFTE STATUSWERTE NACH EDIT',
                            'confirm'=>'Die in der letzten Vorschau ausgewiesenen KNX-Statuswerte '
                                .'nach EDIT schreiben? Es wird zuerst ein verifiziertes EDIT-Backup '
                                .'erstellt. PROD bleibt unveraendert.',
                            'onClick'=>'RCC_ApplyKnxStatusRepair($id);']
                    ]]
            ]
        ];
        $main[]=[
            'type'=>'RowLayout',
            'items'=>[
                ['type'=>'Button',
                    'caption'=>'EDIT NACH PROD UEBERNEHMEN',
                    'confirm'=>'Den GESAMTEN RoomsCatalog EDIT 1:1 nach PROD kopieren? '
                        .'ALLE Raeume, Geraete, Variablen, Statusfelder und KI-Berechtigungen '
                        .'in PROD werden durch EDIT ersetzt – auch unvollstaendige Zuordnungen. '
                        .'Es gibt KEINE globale Geraetepruefung. '
                        .'Vorher wird ein vollstaendiges PROD-Backup erstellt.',
                    'onClick'=>'RCC_SaveEditToProd($id);']
            ]
        ];
        $form['elements']=$main;
        $form['actions']=[];

        return json_encode($form);
    }

    // =====================================================================
    // Interne Logik
    // =====================================================================

    /**
     * External writes from navigation migration can invalidate RuntimeEntries
     * and leave a stale "tabs" filter. Compare EDIT's actual source hash first.
     */
    private function ensureRuntimeCatalogCurrent(): void
    {
        $id = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($id <= 0 || !function_exists('IPS_GetScriptContent') || !IPS_ScriptExists($id)) return;
        $source = IPS_GetScriptContent($id);
        if (!is_string($source)) return;
        if ($this->ReadAttributeString('RuntimeEditHash') !== hash('sha256',$source)) {
            $this->reloadAllFromCatalog();
        }
    }

    private function reloadAllFromCatalog(): void
    {
        $this->learnedModuleTemplateCache = [];
        $this->logDebug('reloadAllFromCatalog: START');

        $scriptId = $this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $scriptId = $this->ReadPropertyInteger('RoomsCatalogScriptID');
        }
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            $this->logDebug('reloadAllFromCatalog: kein gültiges ScriptID gesetzt');
            $this->WriteAttributeString('RuntimeEntries', '[]');
            $this->WriteAttributeString('RuntimeEditHash', '');
            return;
        }

        $catalog = $this->loadRoomsCatalog($scriptId, 'PROD');

        if ($catalog === []) {
            $this->logDebug('reloadAllFromCatalog: Katalog leer oder ungültig');
            $this->WriteAttributeString('RuntimeEntries', '[]');
            $this->WriteAttributeString('RuntimeEditHash', '');
            return;
        }

        $rooms = $this->extractRoomsFromCatalog($catalog);
        $this->logDebug('reloadAllFromCatalog: Räume im geladenen Katalog=' . count($rooms));

        $entries = $this->buildFlatEntriesFromRooms($rooms);
        $this->logDebug('reloadAllFromCatalog: erzeugte Einträge=' . count($entries));

        $this->WriteAttributeString('RuntimeEntries', json_encode($entries));
        if (function_exists('IPS_GetScriptContent')) {
            $source = IPS_GetScriptContent($scriptId);
            if (is_string($source)) $this->WriteAttributeString('RuntimeEditHash',hash('sha256',$source));
        }
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

    /** Presentation-only EDIT/PROD difference rows. Removal stays visible but
     * cannot open an EDIT item that no longer exists. */
    private function catalogPendingChanges(
        array $prodEntries,array $editEntries,array $editCatalog,array $prodCatalog
    ): array {
        $diagnostic=$this->diagnosticCatalogDiff($editCatalog,$prodCatalog);
        $byKey=[];
        foreach ($diagnostic['items'] as $item) {
            $byKey[(string)$item['path']]=$item;
        }
        $rows=[];
        foreach ($this->buildDiffEntries($prodEntries,$editEntries) as $row) {
            $key=$this->buildCompositeKey($row);
            $diff=(string)($row['diffKeys']??'');
            $removed=$diff==='removed';
            $new=$diff==='new';
            $detail=$byKey[(string)$key]??[];
            $fields=$removed?'Geraet in EDIT entfernt'
                :($new?'Neues Geraet':implode(', ',array_slice($detail['changedFields']??[],0,24)));
            if ($fields==='' && !$new && !$removed) $fields=$diff;
            $row['changeKind']=$removed?'Entfernt':($new?'Neu':'Geaendert');
            $row['changeDetails']=$fields;
            $row['editable']=!$removed;
            $row['rowColor']=$removed?'#FFCCCC':($new?'#DDFFDD':'#FFF1C4');
            $rows[]=$this->decorateIpsIds($row);
        }
        return $rows;
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


    /**
     * IP-Symcon merkt sich die Filter ueber Modulupdates hinweg. Nach der
     * Tab-Migration koennen alte Filterwerte (z. B. "tabs") verschwinden.
     * Nur ungueltige Filter zuruecksetzen, keine Geraete/KI-Flags aendern.
     *
     * Domain- und Gruppenoptionen folgen dem gewaehlten Raum/Bereich,
     * damit weder unpassende noch fehlende Filterwerte angezeigt werden.
     */
    private function normalizeCurrentFilters(array $edit, array $prod = []): array
    {
        // Geräte-Filter ausschließlich aus EDIT: alte, in PROD bereits
        // entfernte Navigations-Tabs dürfen keine auswählbaren Gruppen erzeugen.
        $entries = $edit;
        $selectedRoom = $this->ReadAttributeString('FilterRoom');
        $selectedDomain = $this->ReadAttributeString('FilterDomain');
        $selectedGroup = $this->ReadAttributeString('FilterGroup');

        [$roomOptions] = $this->buildFilterOptionsFromEntries($entries);
        if ($selectedRoom !== '' && !in_array($selectedRoom, array_column($roomOptions,'value'),true)) {
            $selectedRoom = '';
        }
        $scope = $selectedRoom === '' ? $entries
            : $this->applyFilters($entries,$selectedRoom,'','');
        [, $domainOptions] = $this->buildFilterOptionsFromEntries($scope);
        if ($selectedDomain !== '' && !in_array($selectedDomain,array_column($domainOptions,'value'),true)) {
            $selectedDomain = '';
        }
        $scope = $selectedDomain === '' ? $scope
            : $this->applyFilters($scope,'',$selectedDomain,'');
        [, , $groupOptions] = $this->buildFilterOptionsFromEntries($scope);
        if ($selectedGroup !== '' && !in_array($selectedGroup,array_column($groupOptions,'value'),true)) {
            $selectedGroup = '';
        }
        foreach (['FilterRoom'=>$selectedRoom,'FilterDomain'=>$selectedDomain,'FilterGroup'=>$selectedGroup] as $name=>$value) {
            if ($this->ReadAttributeString($name) !== $value) {
                $this->WriteAttributeString($name,$value);
            }
        }
        return [
            'room'=>$selectedRoom, 'domain'=>$selectedDomain,'group'=>$selectedGroup,
            'roomOptions'=>$roomOptions,'domainOptions'=>$domainOptions,'groupOptions'=>$groupOptions,
        ];
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
            ['aiType','aiReviewed'], array_values(RCCDeviceTypeRegistry::fields()), array_values(RCCDeviceTypeRegistry::states())
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
        $this->ensureRuntimeCatalogCurrent();
        $edit = $this->getRuntimeEntries();
        $prod = $this->getProdEntries();
        $filters = $this->normalizeCurrentFilters($edit,$prod);
        $room = $filters['room'];
        $domain = $filters['domain'];
        $group = $filters['group'];
        $ai = $this->ReadAttributeString('FilterAI');
        // zuerst Options aktualisieren, dann die aktuellen gueltigen Werte.
        foreach (['FilterRoom'=>$filters['roomOptions'], 'FilterDomain'=>$filters['domainOptions'],
                  'FilterGroup'=>$filters['groupOptions']] as $name=>$options) {
            $this->UpdateFormField($name,'options',json_encode($options,JSON_INVALID_UTF8_SUBSTITUTE));
        }
        foreach (['FilterRoom'=>$room,'FilterDomain'=>$domain,'FilterGroup'=>$group] as $name=>$value) {
            $this->UpdateFormField($name,'value',$value);
        }

        $visible = $this->filterByAI($this->applyFilters($edit, $room, $domain, $group), $ai);
        $sharedDisplay=$this->sharedFeedbackAssignments($edit)['byDevice'];
        $visible = array_map(fn(array $row): array => $this->decorateRow($row,
            $sharedDisplay[(string)$this->buildCompositeKey($row)]??[]), $visible);
        $diff = $this->buildDiffEntries($prod, $edit);
        $visibleDiff = $this->filterByAI($this->applyFilters($diff, $room, $domain, $group), $ai);
        $visibleDiff = array_map(fn(array $row): array => $this->decorateIpsIds($row), $visibleDiff);

        // Das Aktualisieren der Listen lässt ExpansionPanel-/Filterzustand intakt.
        $this->UpdateFormField('Entries', 'values', json_encode($visible, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('EntriesEdit', 'values', json_encode($visibleDiff, JSON_INVALID_UTF8_SUBSTITUTE));
        $editCatalogForDiff=$this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT');
        $prodCatalogForDiff=$this->loadConfiguredCatalog('RoomsCatalogScriptID','PROD');
        $pending=$this->catalogPendingChanges($prod,$edit,$editCatalogForDiff,$prodCatalogForDiff);
        $this->UpdateFormField('PendingChanges','values',json_encode($pending,JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('PendingChangesSummary','caption',
            count($pending).' Geraeteaenderungen warten auf die naechste vollstaendige PROD-Uebernahme. '
            .'Mit dem Zahnrad neue/geaenderte EDIT-Geraete nachbearbeiten. Entfernte Eintraege sind nicht editierbar.');
        $this->UpdateFormField('BulkSelectionSummary','caption',$this->bulkSelectionSummary($edit));

        $editCatalog = $this->loadConfiguredCatalog('RoomsCatalogEditScriptID', 'EDIT');
        $prodCatalog = $this->loadConfiguredCatalog('RoomsCatalogScriptID', 'PROD');
        $roomRows = $this->buildRoomListRows($editCatalog);
        $roomDiffRows = $this->buildRoomDiffEntries($prodCatalog, $editCatalog);
        $this->UpdateFormField('Rooms', 'values', json_encode($roomRows, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('RoomsDiff', 'values', json_encode($roomDiffRows, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('PendingRoomChanges','values',json_encode($roomDiffRows,JSON_INVALID_UTF8_SUBSTITUTE));
        $discoveryPreview = json_decode($this->ReadAttributeString('DiscoveryCandidates'), true);
        $discoveryRows = is_array($discoveryPreview) && is_array($discoveryPreview['rows'] ?? null)
            ? $discoveryPreview['rows'] : [];
        $this->UpdateFormField('DiscoveredDevices', 'values', json_encode($discoveryRows, JSON_INVALID_UTF8_SUBSTITUTE));
        $inventory = json_decode($this->ReadAttributeString('DiscoveryInventory'),true);
        $this->UpdateFormField('DiscoveryInventoryList','values',json_encode(
            is_array($inventory) ? $this->discoveryInventoryRows($inventory) : [],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $legacyPreview = json_decode($this->ReadAttributeString('LegacyMigrationPreview'),true);
        $legacyRows = is_array($legacyPreview) && is_array($legacyPreview['rows'] ?? null)
            ? $legacyPreview['rows'] : [];
        $this->UpdateFormField('LegacyDevices','values',json_encode($legacyRows,JSON_INVALID_UTF8_SUBSTITUTE));
        $roomOptions = $filters['roomOptions'];
        $knownKeys = array_column($roomOptions, 'value');
        foreach ($roomRows as $roomRow) {
            if (!in_array($roomRow['roomKey'], $knownKeys, true)) {
                $roomOptions[] = ['caption'=>$roomRow['display'] . ' (' . $roomRow['roomKey'] . ')', 'value'=>$roomRow['roomKey']];
            }
        }
        $this->UpdateFormField('FilterRoom', 'options', json_encode($roomOptions, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('FilterRoom', 'value', $room);

        $readable = count(array_filter($edit, static fn($r): bool => !empty($r['kiEnabled'])));
        $writable = count(array_filter($edit, static fn($r): bool => !empty($r['kiEnabled']) && empty($r['kiReadOnly'])));
        $migrationStats = $this->catalogMigrationStatistics($edit,$prod);
        $summary = 'RoomsCatalog EDIT: '.count($edit).' Eintraege. '
            .($readable-$writable).' KI nur lesen, '.$writable.' KI schaltbar, '
            .(count($edit)-$readable).' ohne KI-Freigabe. EDIT wird erst nach Freigabe produktiv.';
        $this->UpdateFormField('MigrationSummary','caption',$this->catalogMigrationShortSummary($migrationStats));
        $this->UpdateFormField('MigrationReadinessReport','caption',$this->currentMigrationReport(
            $this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT')
        ));
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

    private function decorateRow(array $row,array $sharedWarnings=[]): array
    {
        $enabled = !empty($row['kiEnabled']);
        $readOnly = !empty($row['kiReadOnly']);
        $row['kiStatus'] = !$enabled ? 'Ohne KI' : ($readOnly ? 'Nur lesen' : 'Schaltbar');
        $row['rowColor'] = !$enabled ? '#EEEEEE' : ($readOnly ? '#E2F2FF' : '#DDF4DE');
        $check=$this->catalogCheckHint($row,$sharedWarnings);
        $row['checkStatus']=$check['status'];
        $row['checkDetails']=$check['reason'];
        if ($check['severity']==='error') {
            $row['kiStatus']='Pruefen';
            $row['rowColor']='#FFCCCC';
        } elseif ($check['severity']==='warning') {
            $row['kiStatus']='Pruefen';
            $row['rowColor']='#FFE5B4';
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
            // Auch reine Anzeigespalten brauchen einen add-Default, sonst
            // verweigert die IP-Symcon-Liste das Oeffnen von "Hinzufuegen".
            ['caption'=>'IPS-Objekt-IDs','name'=>'ipsObjectIds','width'=>'155px','add'=>'','save'=>false,'quickFilter'=>true],
            ['caption'=>'IPS-Instanz-IDs','name'=>'ipsInstanceIds','width'=>'145px','add'=>'','save'=>false,'quickFilter'=>true],
            ['caption'=>'Key','name'=>'key','width'=>'130px','add'=>'','quickFilter'=>true],
            ['caption'=>'Mit KI','name'=>'kiEnabled','width'=>'80px','add'=>false,'edit'=>['type'=>'CheckBox']],
            ['caption'=>'Nur abfragen','name'=>'kiReadOnly','width'=>'110px','add'=>true,'edit'=>['type'=>'CheckBox']],
            ['caption'=>'Freigabe','name'=>'kiStatus','width'=>'105px','add'=>'','save'=>false],
            ['caption'=>'Pruefstatus','name'=>'checkStatus','width'=>'140px','add'=>'','save'=>false,'quickFilter'=>true],
            ['caption'=>'Was ist zu pruefen?','name'=>'checkDetails','width'=>'350px','add'=>'','save'=>false,'quickFilter'=>true],
            ['caption'=>'Raum-Key','name'=>'roomKey','visible'=>false,'add'=>'','quickFilter'=>true],
            ['caption'=>'Synonyme','name'=>'synonyms','visible'=>false,'add'=>'','quickFilter'=>true]
        ];
    }

    /** Die Variablen-Auswahl im offenen Listen-Dialog beim Typwechsel umschalten. */
    public function UpdateEntryTypeForm(string $type): void
    {
        if (!array_key_exists($type,RCCDeviceTypeRegistry::roles())) {
            $this->UpdateFormField('EntryTargetPreview','caption',
                'Bisheriges Datenmodell: Bereich und Gruppe bleiben unveraendert.');
            $type = '';
        } else {
            [$domain,$group] = $this->guidedTypeLocation($type);
            $this->UpdateFormField('EntryTargetPreview','caption',
                'Bereich / Gruppe werden automatisch zugeordnet: ' . $domain . ' / ' . $group);
        }
        foreach (RCCDeviceTypeRegistry::fields() as $property=>$field) {
            $this->UpdateFormField($field,'visible',in_array($property,RCCDeviceTypeRegistry::roles()[$type] ?? [],true));
        }
        foreach (RCCDeviceTypeRegistry::states() as $property=>$field) {
            $show = in_array($type,['blind','window'],true)
                ? in_array($property,['open','closed'],true)
                : (in_array($type,['light','dimmer','switch','motion'],true)
                    && in_array($property,['on','off'],true));
            $this->UpdateFormField($field,'visible',$show);
        }
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
        $isNew = $row === [];
        if ($isNew) {
            $row = [
                'roomKey'=>'', 'roomLabel'=>'', 'floor'=>'',
                'domain'=>'licht', 'group'=>'switches', 'key'=>'',
                'title'=>'', 'synonyms'=>'', 'entityId'=>'',
                'aiType'=>'light', 'kiEnabled'=>false, 'kiReadOnly'=>true
            ];
        }
        $roomOptions = [['caption'=>'-- Bitte Raum auswaehlen --','value'=>'']];
        $catalogRooms = $this->roomMap($this->loadConfiguredCatalog('RoomsCatalogEditScriptID', 'EDIT'));
        foreach ($catalogRooms as $knownKey => $roomCfg) {
            if ($knownKey === 'global' || !is_array($roomCfg) || !is_array($roomCfg['domains'] ?? null)) continue;
            $caption = (string)($roomCfg['display'] ?? $knownKey);
            $floor = trim((string)($roomCfg['floor'] ?? ''));
            $roomOptions[] = ['caption'=>$caption . ($floor !== '' ? ' (' . $floor . ')' : ''),'value'=>(string)$knownKey];
        }
        usort($roomOptions, static fn(array $a,array $b): int => strcasecmp($a['caption'],$b['caption']));
        if (!$isNew && !in_array((string)$row['roomKey'], array_column($roomOptions,'value'), true)) {
            $roomOptions[] = ['caption'=>(string)($row['roomLabel'] ?? $row['roomKey']),'value'=>(string)$row['roomKey']];
        }
        $type = (string)($row['aiType'] ?? '');
        $editorType = array_key_exists($type, RCCDeviceTypeRegistry::roles()) ? $type : $this->inferredAIType($row);
        $typeCaptions = RCCDeviceTypeRegistry::captions();
        if (!$isNew) $typeCaptions = [''=>'Bisheriges Datenmodell (unveraendert)'] + $typeCaptions;
        $path = $this->guidedTypeLocation($editorType);
        $targetHint = !$isNew && $type === ''
            ? 'Bisheriges Datenmodell: Bereich und Gruppe bleiben unveraendert.'
            : 'Bereich / Gruppe werden automatisch zugeordnet: ' . $path[0] . ' / ' . $path[1];
        $items = [
            ['type'=>'ValidationTextBox','name'=>'editorOriginalKey',
                'value'=>$isNew ? '' : ($this->buildCompositeKey($row) ?? ''),'visible'=>false],
            [
                'type'=>'ExpansionPanel','caption'=>'1. Geraetetyp auswaehlen','expanded'=>true,'items'=>[
                    ['type'=>'Select','name'=>'aiType','caption'=>'Was fuer ein Geraet ist es?',
                        'value'=>$type,'options'=>array_map(
                            static fn($value,$caption): array=>['value'=>$value,'caption'=>$caption],
                            array_keys($typeCaptions),array_values($typeCaptions)),
                        'onChange'=>'RCC_UpdateEntryTypeForm($id, $aiType);'],
                    ['type'=>'Label','name'=>'EntryTargetPreview',
                        'caption'=>$targetHint]
                ]
            ],
            [
                'type'=>'ExpansionPanel','caption'=>'2. Raum und Geraet','expanded'=>true,'items'=>[
                    ['type'=>'Select','name'=>'roomKey','caption'=>'Raum aus der Raumverwaltung',
                        'value'=>(string)$row['roomKey'],'options'=>$roomOptions],
                    ['type'=>'Label','caption'=>'Raum-Key, Raumname und Etage kommen automatisch aus V3.1. Bereich und Gruppe aus dem Geraetetyp.'],
                    ['type'=>'ValidationTextBox','name'=>'title','caption'=>'Geraetename'],
                    ['type'=>'ValidationTextBox','name'=>'key','caption'=>'Geraete-Key (optional, aus Geraetename automatisch)'],
                    ['type'=>'ValidationTextBox','name'=>'synonyms','caption'=>'Alternative Geraetenamen (Komma getrennt)'],
                    ['type'=>'ValidationTextBox','name'=>'entityId','caption'=>'Entity-ID (optional)']
                ]
            ]
        ];
        $variableCaptions = RCCDeviceTypeRegistry::propertyCaptions();
        if ($editorType === 'fan') {
            $variableCaptions['power'] = 'Ein/Aus-Wert (optional; darf dieselbe ID wie Steuerung sein)';
            $variableCaptions['control'] = 'Steuerung (Ein/Aus)';
        }
        $typeHint = match ($editorType) {
            'fan' => 'Luefter: Steuerungsvariable reicht aus. Ein/Aus-Wert ist optional und darf dieselbe ID haben. Ohne separate Rueckmeldung ist der tatsaechliche Laufzustand nicht bestaetigt.',
            'heating' => 'Heizung: nur Isttemperatur, Solltemperatur und Steuerung. Steuerung darf dieselbe beschreibbare Solltemperatur-Variable sein. Kein zusaetzlicher Geraetestatus.',
            default => 'Eigenschaften kommen zentral aus der Typvorlage. Nur vorhandene IPS-Variablen zuordnen; fehlende optionale Rueckmeldungen sind kein Fehler. Neue Typen sind zunaechst nur lesbar.'
        };
        $aiItems = [['type'=>'Label','caption'=>$typeHint]];
        $invalidBindings=[];
        foreach (RCCDeviceTypeRegistry::fields() as $property=>$field) {
            if (!in_array($property,RCCDeviceTypeRegistry::roles()[$editorType] ?? [],true)) continue;
            $id=(int)($row[$field]??0);
            if ($id>0 && !IPS_VariableExists($id)) {
                $invalidBindings[$property]=$id;
            }
        }
        if ($invalidBindings!==[]) {
            $invalidText=[];
            foreach ($invalidBindings as $property=>$id) {
                $invalidText[]=$property.' (#'.$id.')';
            }
            $aiItems[]=['type'=>'Label','caption'=>
                'ACHTUNG: Im EDIT-Katalog sind ungueltige Variablen-IDs gespeichert: '
                .implode(', ',$invalidText).'. Der IP-Symcon-Auswahldialog kann dafuer '
                .'"Keine" anzeigen, obwohl noch eine alte ID im Katalog steht. '
                .'Beim Speichern werden diese nicht mehr existierenden Zuordnungen '
                .'entfernt, sofern du keine neue gueltige Variable auswaehlst.'];
        }
        foreach (array_chunk(array_keys(RCCDeviceTypeRegistry::fields()), 2) as $pair) {
            $controls = [];
            foreach ($pair as $property) {
                $control=['type'=>'SelectVariable','name'=>RCCDeviceTypeRegistry::fields()[$property],
                    'caption'=>$variableCaptions[$property] ?? $property,
                    'visible'=>in_array($property,RCCDeviceTypeRegistry::roles()[$editorType] ?? [],true)];
                if (isset($invalidBindings[$property])) {
                    // What the selector actually shows ("Keine") is the
                    // initial value we submit on confirmation, never the
                    // stale, non-existing IPS-ID.
                    $control['value']=0;
                }
                $controls[]=$control;
            }
            $aiItems[] = ['type'=>'RowLayout','items'=>$controls];
        }
        $stateFields = ['on'=>'Aktiv/Ein ist','off'=>'Inaktiv/Aus ist',
            'open'=>'Offen ist','closed'=>'Geschlossen ist'];
        $controls = [];
        foreach ($stateFields as $property=>$caption) {
            $visible = in_array($editorType,['blind','window'],true)
                ? in_array($property,['open','closed'],true)
                : (in_array($editorType,['light','dimmer','switch','motion'],true)
                    && in_array($property,['on','off'],true));
            $controls[] = ['type'=>'ValidationTextBox','name'=>RCCDeviceTypeRegistry::states()[$property],
                'caption'=>$caption . ' (z.B. true/false, 0/100)','visible'=>$visible];
        }
        $aiItems[] = ['type'=>'RowLayout','items'=>$controls];
        $aiItems[] = ['type'=>'CheckBox','name'=>'aiReviewed',
            'caption'=>'Alle Variablen und die Wertbedeutung am echten Geraet geprueft',
            'enabled'=>!$isNew];
        $items[] = ['type'=>'ExpansionPanel','caption'=>'3. Variablen und Zustandswerte',
            'expanded'=>true,'items'=>$aiItems];
        $items[] = ['type'=>'ExpansionPanel','caption'=>'4. KI-Freigabe','expanded'=>true,'items'=>[
            ['type'=>'CheckBox','name'=>'kiEnabled','caption'=>'Mit KI: Abfragen freigeben',
                'enabled'=>!$isNew],
            ['type'=>'CheckBox','name'=>'kiReadOnly','caption'=>'Nur abfragen: Schalten gesperrt',
                'enabled'=>!$isNew],
            ['type'=>'Label','caption'=>$isNew
                ? 'Neue Geraete starten sicher mit KI aus und Nur abfragen an. Freigabe spaeter im EDIT-Katalog setzen.'
                : 'Schalten nur bei expliziter Freigabe und gueltiger IP-Symcon-Aktion.']
        ]];

        $hidden = array_merge(['selected','roomKey','roomLabel','floor','domain','group','key','title',
            'synonyms','entityId','kiEnabled','kiReadOnly','rowColor','kiStatus','diffKeys','checkStatus','checkDetails','changeKind','changeDetails','editable',
            'deletable','ipsObjectIds','ipsInstanceIds','ai','aiType','aiReviewed'],
            array_values(RCCDeviceTypeRegistry::fields()), array_values(RCCDeviceTypeRegistry::states()));
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
        $items[]=['type'=>'ExpansionPanel','caption'=>'Weitere Felder / Objekt-IDs (Experten)','expanded'=>false,
            'items'=>$extra!==[]?$extra:[['type'=>'Label','caption'=>'Keine weiteren Eigenschaften vorhanden']]];
        return json_encode($items,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function logDebug(string $message): void
    {
        // IPS_LogMessage('Alexa', 'RCC-DEBUG: ' . $message);
    }
}
