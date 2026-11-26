Warning: truncated output (original token count: 121767)
Total output lines: 8955

<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/DeviceTechnicalAudit.php';
require_once __DIR__ . '/libs/SafeAutoRepair.php';
require_once __DIR__ . '/libs/DeviceTypeRegistry.php';
require_once __DIR__ . '/libs/DeviceCapabilities.php';
require_once __DIR__ . '/libs/GuidedDiscovery.php';
require_once __DIR__ . '/libs/KnxGroupAddressDiscovery.php';
require_once __DIR__ . '/../IPSAlexaHaussteuerung/src/SymconScriptSource.php';
require_once __DIR__ . '/libs/ModuleDataSources.php';
require_once __DIR__ . '/libs/DataSourceEditor.php';
require_once __DIR__ . '/libs/DataSourceEditorTrait.php';
require_once __DIR__ . '/libs/SystemTreeDiscovery.php';
require_once __DIR__ . '/libs/SystemTreeEditorTrait.php';
require_once __DIR__ . '/libs/CatalogVariableBindingsTrait.php';
require_once __DIR__ . '/libs/CatalogScalarValuesTrait.php';
require_once __DIR__ . '/libs/MarkedDeviceInspectionTrait.php';
require_once __DIR__ . '/libs/CatalogBackupRotation.php';
require_once __DIR__ . '/libs/SensorHealthCheckTrait.php';
require_once __DIR__ . '/libs/HeatingSystemEditorTrait.php';
require_once __DIR__ . '/libs/HomeConnectEditorTrait.php';
require_once __DIR__ . '/libs/HouseholdEditorTrait.php';
require_once __DIR__ . '/libs/WeatherStationEditorTrait.php';
require_once __DIR__ . '/libs/XSenseEditorTrait.php';
require_once dirname(__DIR__) . '/IPSAlexaHaussteuerung/src/VoiceRuleLiteralReader.php';
require_once __DIR__ . '/../IPSAlexaHaussteuerung/src/VoiceRulePreviewForm.php';

/**
 * ============================================================
 * ROOMS CATALOG CONFIGURATOR — IP-Symcon Modul (V3.3.3)
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
 *
 * 2026-11-26: V3.3.3 — vollständiger Objektbaum- und Profil-JSON-Export
 * - Elternpfade, Kind-IDs, Instanzmodule und Variablenmetadaten ergänzen
 * - Verwendete Profile mit Wertebereich, Anzeige und allen Assoziationen exportieren
 * - Aktuelle Sensorwerte fuer die Analyse; erkennbare Zugangsdaten und Skriptinhalte bleiben außen vor
 *
 * 2026-11-26: Shelly-Messgeraete anhand ihrer Messkanäle typisieren
 * - PM1-Leistungs-/Energie-Identifikatoren direkt zuordnen
 * - Shelly-Messgeraete trotz Erreichbarkeitsvariable als Energiezaehler erkennen
 * - Shelly-Temperaturkanaele vor namensbasierten Netzwerk-/Heizungs-Hinweisen erkennen
 */

class RoomsCatalogConfigurator extends IPSModule
{
    private const ALEXA_MODULE_ID = '{F528D4D0-5729-4315-AE88-9BBABDBD0392}';
    use RCCDataSourceEditorTrait;
    use RCCSystemTreeEditorTrait;
    use RCCCatalogVariableBindingsTrait;
    use RCCCatalogScalarValuesTrait;
    use RCCMarkedDeviceInspectionTrait;
    use RCCSensorHealthCheckTrait;
    use RCCHeatingSystemEditorTrait;
    use RCCHomeConnectEditorTrait;
    use RCCHouseholdEditorTrait;
    use RCCWeatherStationEditorTrait;
    use RCCXSenseEditorTrait;
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
        $this->RegisterPropertyString('KnxGroupAddressExportPath', '');
        $this->RegisterPropertyString('KnxGroupAddressExportFile', '');
        // V3.4.9: Ein IP-Symcon-Knoten als gefuehrter Importscope.
        // Leer = den vom zentralen Typregister vorgeschlagenen Pfad nutzen.
        $this->RegisterPropertyString('DiscoveryDomainOverride', '');
        $this->RegisterPropertyString('DiscoveryGroupOverride', '');
        $this->RegisterPropertyString('DiscoveryTypeOverride', '');
        $this->RegisterPropertyString('DiscoveryRoomOverride', '');
        // Optional: bereits konfiguriertes Alexa-Modul liefert die KI-API-
        // Anmeldedaten; KEIN zweiter Token im RoomsCatalogConfigurator.
        $this->RegisterPropertyBoolean('DiscoveryUseAI', false);
        $this->RegisterPropertyInteger('DiscoveryAlexaInstanceId', 0);
        $this->RegisterPropertyInteger('HuaweiDataSourceInstanceId', 0);
        $this->RegisterPropertyInteger('XSenseRootId', 0);
        $this->RegisterPropertyInteger('SystemTreeRootId', 0);
        $this->RegisterPropertyInteger('HomeConnectRootId', 0);

        $this->RegisterAttributeString('RuntimeEntries', '[]');
        $this->RegisterAttributeString('OverviewSourceSelections', '{}');
        $this->RegisterAttributeString('RuntimeEditHash', '');
        $this->RegisterAttributeString('FilterRoom', '');
        $this->RegisterAttributeString('FilterDomain', '');
        $this->RegisterAttributeString('FilterGroup', '');
        $this->RegisterAttributeString('FilterAI', 'all');
        $this->RegisterAttributeString('SuggestionPreview', '{}');
        $this->RegisterAttributeString('DiscoveryCandidates', '{}');
        $this->RegisterAttributeString('KnxAddressPreview', '{}');
        $this->RegisterAttributeString('KnxAddressStatus', 'Noch kein KNX-Export abgeglichen.');
        $this->RegisterAttributeString('DiscoveryInventory', '{}');
        $this->RegisterAttributeString('DiscoveryAuditReport', 'Noch keine Vollstaendigkeitspruefung.');
        $this->RegisterAttributeString('DiscoveryAIReport', 'Keine KI-Unterstuetzung bei der letzten Geraetesuche.');
        $this->RegisterAttributeString('BulkDeviceType', 'keep');
        $this->RegisterAttributeString('BulkAIMode', 'keep');
        $this->RegisterAttributeString('BulkSuggestions', 'yes');
        $this->RegisterAttributeString('BulkReview', 'keep');
        $this->RegisterAttributeString('BulkPreview', '{}');
        // Wizard speichert die ausgewaehlten Geraete-Keys unabhaengig von
        // RuntimeEntries, die nach jedem EDIT-Speichern neu geladen werden.
        $this->RegisterAttributeString('MarkedEditorKeys','[]');
        $this->RegisterAttributeString('MarkedEditorIndex','0');
        $this->RegisterAttributeString('MarkedEditorEditHash','');
        $this->RegisterAttributeString('MarkedEditorNotice','');
        $this->RegisterAttributeString('CatalogRestorePreview','{}');
        $this->RegisterAttributeString('HuaweiDataSourcePreview','{}');
        $this->RegisterAttributeString('SystemTreePreview','{}');
        $this->RegisterAttributeString('HeatingSystemPreview','{}');
        $this->RegisterAttributeString('SystemTreeNotice','Noch kein Anlagen-Scan gestartet.');
        $this->RegisterAttributeString('CatalogBindingInventory','[]');
        $this->RegisterAttributeString('CatalogBindingPreview','');
        $this->RegisterAttributeString('CatalogBindingSelected','0');
        $this->RegisterAttributeString('CatalogBindingNotice','Noch keine Variablenanalyse durchgeführt.');
        $this->RegisterAttributeString('CatalogBindingLog','[]');
        $this->RegisterAttributeString('MarkedInspectionResult','');
        $this->RegisterAttributeString('CatalogScalarHash','');
        $this->RegisterAttributeString('CatalogScalarRows','[]');
        $this->RegisterAttributeString('CatalogScalarNotice','Noch keine Katalogfelder geladen.');
        $this->RegisterAttributeString('CatalogFormPerformance','');
        $this->RegisterAttributeString('CatalogFormPerformanceLastLog','0');
        $this->RegisterAttributeString('HuaweiDataSourceReport','');
        $this->RegisterAttributeString('HuaweiDataSourceNotice','Noch kein HuaweiPV-Scan gestartet.');
        $this->RegisterAttributeString('CatalogRestoreNotice','Noch kein Backup zur Wiederherstellung ausgewaehlt.');
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
            'DiscoveryAIReport'=>'Keine KI-Unterstuetzung bei der letzten Geraetesuche.',
            'BulkPreview'=>'{}',
            'MarkedEditorKeys'=>'[]',
            'MarkedEditorIndex'=>'0',
            'MarkedEditorEditHash'=>'',
            'MarkedEditorNotice'=>'',
            'CatalogRestorePreview'=>'{}',
            'HuaweiDataSourcePreview'=>'{}',
            'SystemTreePreview'=>'{}',
            'SystemTreeNotice'=>'Noch kein Anlagen-Scan gestartet.',
            'HuaweiDataSourceReport'=>'',
            'TechnicalDeviceSnapshot'=>'{}',
            'TechnicalDevicePreview'=>'{}',
            'TechnicalDeviceSummary'=>'Noch keine technische Geraetepruefung ausgefuehrt.',
            'TechnicalDeviceActionMessage'=>'',
            'AutoRepairPreview'=>'{}',
            'AutoRepairNotice'=>'Noch kein Diagnoseexport erstellt.',
            'CatalogCheckDebugOutput'=>'',
            'CatalogCheckDebugSummary'=>'Noch keine RoomsCatalog-Pruefung ausgefuehrt.',
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
        // Nach dem Reset darf der alte mehrstufige Editor nicht stehen bleiben.
        // Attribute allein zu loeschen schliesst das bereits sichtbare IPS-Panel nicht.
        $this->refreshMarkedEditorForm();

        foreach ([
            'TechnicalDeviceSummary'=>'Noch keine technische Geraetepruefung ausgefuehrt.',
            'TechnicalDeviceSourceSummary'=>'Katalogquellen noch nicht geprueft – bitte technische Pruefung ausfuehren.',
            'TechnicalDeviceActionMessage'=>'',
            'AutoRepairNotice'=>'Noch kein Diagnoseexport erstellt.',
            'CatalogCheckDebugSummary'=>'Noch keine RoomsCatalog-Pruefung ausgefuehrt.',
            'PartialProdPublishNotice'=>'Noch keine bereichsweise PROD-Vorschau ausgefuehrt.',
            'SinglePositionProdNotice'=>'Noch keine einzelne Positionsaenderung geprueft.',
            'DiscoverySummary'=>'Noch keine Geraeteinventur gestartet.',
            'DiscoveryAudit'=>'Noch keine Vollstaendigkeitspruefung.'
        ] as $name=>$label) {
            $this->UpdateFormField($name,'caption',$label);
        }
        foreach ([
            'TechnicalDeviceChecks','TechnicalLegacyDevices','TechnicalDiffRows',
            'Suggestions','DiscoveryInventoryList','DiscoveredDevices',
            'PartialProdPublishRows'
        ] as $name) {
            $this->UpdateFormField($name,'values','[]');
        }
        $this->UpdateFormField('TechnicalDevicesPhysicallyChecked','value',false);
        $this->UpdateFormField('FilterAI','value','all');
        $this->UpdateFormField('CatalogCheckDebugOutput','value','');
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

    /**
     * Die bereits von EDIT->PROD und Reparatur-Assistenten angelegten
     * RoomsCatalog-Backups werden aus den beiden Skript-Ordnern gesammelt.
     * Andere Skripte duerfen nicht als Restore-Quelle ausgewaehlt werden.
     */
    private function catalogRestoreOptions(): array
    {
        $options=[['caption'=>'Backup auswaehlen','value'=>0]];
        $anchors=[$this->ReadPropertyInteger('RoomsCatalogScriptID'),
            $this->ReadPropertyInteger('RoomsCatalogEditScriptID')];
        foreach(RCCBackupRotation::candidates($anchors) as $id=>$name)
            $options[]=['caption'=>$name.' (#'.$id.')','value'=>$id];
        return $options;
    }

    private function catalogRestoreSource(int $backupId): string
    {
        $allowed=array_map('intval',array_column($this->catalogRestoreOptions(),'value'));
        if ($backupId<=0 || !in_array($backupId,$allowed,true))
            throw new \RuntimeException('Backup nicht im konfigurierten RoomsCatalog-Ordner gefunden.');
        $source=(string)IPS_GetScriptContent($backupId);
        if (!RCCDeviceTechnicalAudit::isLiteralArraySource($source))
            throw new \RuntimeException('Backup ist kein gueltiger statischer PHP-Array-Katalog.');
        $catalog=$this->loadRoomsCatalog($backupId,'BACKUP');
        if ($catalog===[] || $this->extractRoomsFromCatalog($catalog)===[])
            throw new \RuntimeException('Backup enthaelt keinen gueltigen Geraetekatalog.');
        return $source;
    }

    /** Eine unabhaengige, verifizierte Kopie vor JEDEM Restore anlegen. */
    private function catalogBackupCurrentEdit(int $editId,string $content,string $reason): int
    {
        return RCCBackupRotation::create($editId,$content,'EDIT',$reason,[
            $this->ReadPropertyInteger('RoomsCatalogScriptID'),$editId
        ]);
    }

    /** Preview is read-only, derives all IDs from the selected HuaweiPV node. */
    public function PreviewHuaweiDataSource(int $sourceId=0): void
    {
        $this->WriteAttributeString('HuaweiDataSourcePreview','{}');
        try {
            $id=$sourceId>0?$sourceId:$this->ReadPropertyInteger('HuaweiDataSourceInstanceId');
            $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
            $prodId=$this->ReadPropertyInteger('RoomsCatalogScriptID');
            if ($editId<=0 || $editId===$prodId || !IPS_ScriptExists($editId))
                throw new RuntimeException('EDIT und PROD muessen zwei verschiedene Skripte sein.');
            $original=(string)IPS_GetScriptContent($editId);
            if (!RCCDeviceTechnicalAudit::isLiteralArraySource($original))
                throw new RuntimeException('Nur statische PHP-Array-Kataloge werden unterstuetzt.');
            $source=RCCModuleDataSources::scanHuawei($id);
            $catalog=$this->loadRoomsCatalog($editId,'EDIT');
            $key='huawei_'.$id;
            $previous=$catalog['dataSources'][$key]??null;
            if ($previous!==null && (
                ($previous['provider']??'')!=='HuaweiPV'
                || (int)($previous['sourceInstanceId']??0)!==$id))
                throw new RuntimeException('Konflikt mit bereits verwendetem Datenquellen-Key.');
            $oldIds=is_array($previous) ? array_keys((array)($previous['metrics']??[])):[];
            $newIds=array_keys($source['metrics']);
            $snapshot=[
                'sourceInstanceId'=>$id,'sourceHash'=>hash('sha256',serialize($source)),
                'editHash'=>hash('sha256',$original),'expires'=>time()+600
            ];
            $this->WriteAttributeString('HuaweiDataSourcePreview',json_encode($snapshot,JSON_THROW_ON_ERROR));
            $report=[
                'provider'=>'HuaweiPV','sourceInstanceId'=>$id,
                'key'=>$key,'destination'=>'RoomsCatalogEdit.dataSources.'.$key,
                'mode'=>$previous===null?'NEU':'AKTUALISIEREN',
                'found'=>count($newIds),
                'newMetrics'=>array_values(array_diff($newIds,$oldIds)),
                'removedMetrics'=>array_values(array_diff($oldIds,$newIds)),
                'topics'=>$source['scanSummary']['topics'],
                'excluded'=>$source['scanSummary']['excluded'],
                'metrics'=>array_map(static fn(array $m):array=>[
                    'ident'=>$m['ident'],'name'=>$m['label'],
                    'id'=>$m['id'],'unit'=>$m['unit'],
                    'topic'=>$m['topic'],'kind'=>$m['kind'],
                    'writableInIPSymcon'=>$m['writableInIPSymcon']
                ],array_values($source['metrics'])),
                'aiPermissions'=>['enabled'=>true,'readOnly'=>true,'writes'=>false],
                'prodUnchanged'=>true
            ];
            $notice='VORSCHAU: '.$report['found'].' HuaweiPV-Messwerte aus Instanz #'.$id.
                ' erkannt. '.count($report['newMetrics']).' neu, '.
                count($report['removedMetrics']).' entfallen. Noch nichts gespeichert. '
                .'Ein Import sichert EDIT und aktualisiert nur dataSources, niemals PROD.';
            $this->WriteAttributeString('HuaweiDataSourceReport',
                json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
        } catch(Throwable $e) {
            $notice='HUAWEIPV-VORSCHAU FEHLGESCHLAGEN: '.$e->getMessage();
            $this->WriteAttributeString('HuaweiDataSourceReport','');
        }
        $this->WriteAttributeString('HuaweiDataSourceNotice',$notice);
        $this->UpdateFormField('HuaweiDataSourceNotice','caption',$notice);
        $this->UpdateFormField('HuaweiDataSourceReport','value',
            $this->ReadAttributeString('HuaweiDataSourceReport'));
        echo $notice;
    }

    /**
     * Explicit commit to EDIT only. Preview, source, live object tree and EDIT
     * script must still match; backup + readback + rollback on failure.
     */
    public function ImportHuaweiDataSourceToEdit(int $sourceId=0): void
    {
        try {
            $snapshot=json_decode($this->ReadAttributeString('HuaweiDataSourcePreview'),true);
            $id=$sourceId>0?$sourceId:$this->ReadPropertyInteger('HuaweiDataSourceInstanceId');
            if (!is_array($snapshot) || (int)($snapshot['sourceInstanceId']??0)!==$id
                || (int)($snapshot['expires']??0)<time())
                throw new RuntimeException('Bitte die aktuelle Instanz zuerst frisch scannen.');
            $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
            $prodId=$this->ReadPropertyInteger('RoomsCatalogScriptID');
            if($editId<=0 || $prodId===$editId || !IPS_ScriptExists($editId))
                throw new RuntimeException('EDIT/PROD-Konfiguration ungueltig.');
            $original=(string)IPS_GetScriptContent($editId);
            if(hash('sha256',$original)!==($snapshot['editHash']??''))
                throw new RuntimeException('EDIT wurde nach der Vorschau geaendert; bitte neu scannen.');
            if(!RCCDeviceTechnicalAudit::isLiteralArraySource($original))
                throw new RuntimeException('EDIT-Quelle nicht als statisches Array erkannt.');
            $source=RCCModuleDataSources::scanHuawei($id);
            if(hash('sha256',serialize($source))!==($snapshot['sourceHash']??''))
                throw new RuntimeException('HuaweiPV-Objektbaum wurde geaendert; bitte neu scannen.');
            $catalog=$this->loadRoomsCatalog($editId,'EDIT');
            $key='huawei_'.$id;
            $old=$catalog['dataSources'][$key]??null;
            if($old!==null && (!is_array($old)
                || ($old['provider']??'')!=='HuaweiPV'
                || (int)($old['sourceInstanceId']??0)!==$id))
                throw new RuntimeException('Bestehender Katalogeintrag kollidiert mit HuaweiPV.');
            if(is_array($old)) {
                foreach($source['metrics'] as $ident=>&$metric) {
                    // Explicitly disabled metrics stay disabled across reimport.
                    if(($old['metrics'][$ident]['enabled']??true)===false)
                        $metric['enabled']=false;
                }
                unset($metric);
                if(($old['kiEnabled']??true)===false)$source['kiEnabled']=false;
                if(is_string($old['title']??null) && trim($old['title'])!=='')
                    $source['title']=$old['title'];
            }
            // Never carry over legacy write privileges.
            $source['kiReadOnly']=true;$source['allowWrites']=false;
            $catalog['dataSources'][$key]=$source;
            $next="<?php\nreturn ".var_export($catalog,true).";\n";
            if(IPS_GetScriptContent($editId)!==$original)
                throw new RuntimeException('EDIT wurde waehrend der Uebernahme geaendert.');
            $backup=$this->catalogBackupCurrentEdit($editId,$original,'before_huaweipv_v36');
            try {
                IPS_SetScriptContent($editId,$next);
                if(IPS_GetScriptContent($editId)!==$next)
                    throw new RuntimeException('EDIT-Schreibverifikation fehlgeschlagen.');
            } catch(Throwable $e) {
                IPS_SetScriptContent($editId,$original);
                if(IPS_GetScriptContent($editId)!==$original)
                    throw new RuntimeException('Ruecknahme unklar; Sicherheitskopie #'.$backup.' pruefen.');
                throw $e;
            }
            $this->WriteAttributeString('HuaweiDataSourcePreview','{}');
            $this->reloadAllFromCatalog();
            $this->RefreshVisibleLists();
            $notice='HUAWEIPV IMPORTIERT: '.count($source['metrics']).
                ' Messwerte in RoomsCatalogEdit.dataSources.'.$key.
                ' gesichert. EDIT-Sicherheitskopie #'.$backup.
                '. PROD bleibt unveraendert. Schreibzugriff ist gesperrt.';
        } catch(Throwable $e) {
            $notice='HUAWEIPV-IMPORT BLOCKIERT: '.$e->getMessage();
        }
        $this->WriteAttributeString('HuaweiDataSourceNotice',$notice);
        $this->UpdateFormField('HuaweiDataSourceNotice','caption',$notice);
        echo $notice;
    }

    /** Optional cleanup of old backup scripts; never touches PROD or EDIT. */
    public function PruneCatalogBackups(): void
    {
        try {
            $sources=[$this->ReadPropertyInteger('RoomsCatalogScriptID'),
                $this->ReadPropertyInteger('RoomsCatalogEditScriptID')];
            $before=count(RCCBackupRotation::candidates($sources));
            RCCBackupRotation::prune($sources);
            $after=count(RCCBackupRotation::candidates($sources));
            $message='Backup-Aufraeumen: vorher '.$before.', jetzt '.$after
                .' Katalog-Sicherungen. RoomsCatalog und RoomsCatalogEdit unveraendert.';
            $this->UpdateFormField('CatalogRestoreBackupId','options',
                json_encode($this->catalogRestoreOptions(),JSON_INVALID_UTF8_SUBSTITUTE));
            $this->UpdateFormField('CatalogRestoreBackupId','value',0);
            $this->WriteAttributeString('CatalogRestorePreview','{}');
        }catch(\Throwable $error){
            $message='Backup-Aufraeumen fehlgeschlagen: '.$error->getMessage();
        }
        $this->WriteAttributeString('CatalogRestoreNotice',$message);
        $this->UpdateFormField('CatalogRestoreNotice','caption',$message);
        echo $message;
    }

    public function CreateCatalogEditBackup(): void
    {
        try {
            $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
            if ($editId<=0 || !IPS_ScriptExists($editId))
                throw new \RuntimeException('EDIT-Skript fehlt.');
            $source=(string)IPS_GetScriptContent($editId);
            if (!RCCDeviceTechnicalAudit::isLiteralArraySource($source))
                throw new \RuntimeException('EDIT ist kein statischer PHP-Array-Katalog.');
            $id=$this->catalogBackupCurrentEdit($editId,$source,'manual');
            $message='EDIT-Sicherung erfolgreich angelegt: #'.$id.'. PROD unveraendert.';
            $this->UpdateFormField('CatalogRestoreBackupId','options',
                json_encode($this->catalogRestoreOptions(),JSON_INVALID_UTF8_SUBSTITUTE));
            $this->UpdateFormField('CatalogRestoreBackupId','value',$id);
            $this->WriteAttributeString('CatalogRestorePreview','{}');
        } catch (\Throwable $error) {
            $message='EDIT-SICHERUNG FEHLGESCHLAGEN: '.$error->getMessage();
        }
        $this->WriteAttributeString('CatalogRestoreNotice',$message);
        $this->UpdateFormField('CatalogRestoreNotice','caption',$message);
        echo $message;
    }

    public function PreviewCatalogRestore(int $backupId): void
    {
        $this->WriteAttributeString('CatalogRestorePreview','{}');
        try {
            $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
            $prodId=$this->ReadPropertyInteger('RoomsCatalogScriptID');
            if ($editId<=0 || $editId===$prodId || !IPS_ScriptExists($editId))
                throw new \RuntimeException('Zwei verschiedene EDIT-/PROD-Skripte konfigurieren.');
            $backup=$this->catalogRestoreSource($backupId);
            $current=(string)IPS_GetScriptContent($editId);
            $backupHash=hash('sha256',$backup);
            if ($backupHash===hash('sha256',$current))
                throw new \RuntimeException('Backup und EDIT sind bereits identisch.');
            $catalog=$this->loadRoomsCatalog($backupId,'BACKUP');
            $entries=$this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog($catalog));
            $preview=['id'=>$backupId,'backupHash'=>$backupHash,
                'editHash'=>hash('sha256',$current),'expires'=>time()+600];
            $this->WriteAttributeString('CatalogRestorePreview',
                json_encode($preview,JSON_THROW_ON_ERROR));
            $message='VORSCHAU: Backup #'.$backupId.' mit '.count($entries).
                ' Geraeten. Ziel: ausschliesslich RoomsCatalogEdit #'.$editId.
                '. Der aktuelle EDIT-Inhalt wird zuerst separat gesichert. '
                .'PROD bleibt unberuehrt. Vorschau 10 Minuten gueltig.';
        } catch (\Throwable $error) {
            $message='RESTORE-VORSCHAU BLOCKIERT: '.$error->getMessage();
        }
        $this->WriteAttributeString('CatalogRestoreNotice',$message);
        $this->UpdateFormField('CatalogRestoreNotice','caption',$message);
        echo $message;
    }

    public function RestoreCatalogBackupToEdit(int $backupId): void
    {
        try {
            $preview=json_decode($this->ReadAttributeString('CatalogRestorePreview'),true);
            if (!is_array($preview) || ($preview['id']??0)!==$backupId
                || (int)($preview['expires']??0)<time())
                throw new \RuntimeException('Bitte dieses Backup zuerst frisch als Vorschau pruefen.');
            $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
            $prodId=$this->ReadPropertyInteger('RoomsCatalogScriptID');
            if ($editId<=0 || $editId===$prodId || !IPS_ScriptExists($editId))
                throw new \RuntimeException('EDIT/PROD-Konfiguration ungueltig.');
            $current=(string)IPS_GetScriptContent($editId);
            $backup=$this->catalogRestoreSource($backupId);
            if (hash('sha256',$current)!==($preview['editHash']??'')
                || hash('sha256',$backup)!==($preview['backupHash']??''))
                throw new \RuntimeException('EDIT oder Backup hat sich geaendert. Vorschau erneut ausfuehren.');
            $safetyId=$this->catalogBackupCurrentEdit($editId,$current,'before_restore');
            if (IPS_GetScriptContent($editId)!==$current
                || IPS_GetScriptContent($backupId)!==$backup)
                throw new \RuntimeException('Quelldaten nach der Sicherung geaendert. Kein Restore.');
            try {
                IPS_SetScriptContent($editId,$backup);
                if (IPS_GetScriptContent($editId)!==$backup)
                    throw new \RuntimeException('EDIT-Schreibverifikation fehlgeschlagen.');
            } catch (\Throwable $writeError) {
                $rolledBack=false;
                try {
                    IPS_SetScriptContent($editId,$current);
                    $rolledBack=IPS_GetScriptContent($editId)===$current;
                } catch (\Throwable $ignored) {}
                throw new \RuntimeException($writeError->getMessage().
                    ($rolledBack?' Urspruenglicher EDIT wiederhergestellt.':
                        ' EDIT unklar; Sicherheitskopie #'.$safetyId.' pruefen.'));
            }
            $this->ResetAllCheckCaches();
            $message='RESTORE ERFOLGREICH: Backup #'.$backupId.' nach EDIT #'.$editId.
                ' uebernommen. Vorheriger EDIT als Sicherheitskopie #'.$safetyId.
                ' vorhanden. PROD NICHT veraendert.';
            $this->UpdateFormField('CatalogRestoreBackupId','options',
                json_encode($this->catalogRestoreOptions(),JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (\Throwable $error) {
            $message='BACKUP-WIEDERHERSTELLUNG BLOCKIERT: '.$error->getMessage();
        }
        $this->WriteAttributeString('CatalogRestorePreview','{}');
        $this->WriteAttributeString('CatalogRestoreNotice',$message);
        $this->UpdateFormField('CatalogRestoreNotice','caption',$message);
        echo $message;
    }

    public function ReloadCatalog()
    {
        $this->logDebug('ReloadCatalog: Button gedrückt');
        $this->syncHouseholdToEdit();
        $this->syncWeatherStationsToEdit();
        $this->syncXSenseToEdit();
        $this->reloadAllFromCatalog();
        $this->clearSuggestionPreview();
        $this->RefreshVisibleLists();
    }

    public function SetRoomFilter(string $roomKey)
    {
        $this->WriteAttributeString('FilterRoom', $roomKey);
        $this->normalizeCurrentFilters(
            array_merge($this->getRuntimeEntries(),$this->overviewSourceRows(
                $this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT'))),
            $this->getProdEntries());
        $this->logDebug('SetRoomFilter: roomKey="' . $roomKey . '"');
        $this->RefreshVisibleLists();
    }

    public function SetDomainFilter(string $domainKey)
    {
        $this->WriteAttributeString('FilterDomain', $domainKey);
        $this->normalizeCurrentFilters(
            array_merge($this->getRuntimeEntries(),$this->overviewSourceRows(
                $this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT'))),
            $this->getProdEntries());
               $this->logDebug('SetDomainFilter: domainKey="' . $domainKey . '"');
        $this->RefreshVisibleLists();
    }

    public function SetGroupFilter(string $groupKey)
    {
        $this->WriteAttributeString('FilterGroup', $groupKey);
        $this->normalizeCurrentFilters(
            array_merge($this->getRuntimeEntries(),$this->overviewSourceRows(
                $this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT'))),
            $this->getProdEntries());
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
        $sourceSelection=[];
        if ($selected) {
            $sourceRows=$this->overviewSourceRows($this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT'));
            $sourceRows=$this->filterByAI($this->applyFilters($sourceRows,$this->ReadAttributeString('FilterRoom'),
                $this->ReadAttributeString('FilterDomain'),$this->ReadAttributeString('FilterGroup')),$this->ReadAttributeString('FilterAI'));
            foreach ($sourceRows as $sourceRow) $sourceSelection[$this->buildCompositeKey($sourceRow)]=true;
        }
        $this->WriteAttributeString('OverviewSourceSelections',json_encode($sourceSelection));
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
        $sourceSelection=[];
        if ($selected) foreach ($this->overviewSourceRows($this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT')) as $sourceRow)
            $sourceSelection[$this->buildCompositeKey($sourceRow)]=true;
        $this->WriteAttributeString('OverviewSourceSelections',json_encode($sourceSelection));
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
     …91767 tokens truncated…eFormField('DiscoveredDevices', 'values', json_encode($discoveryRows, JSON_INVALID_UTF8_SUBSTITUTE));
        $inventory = json_decode($this->ReadAttributeString('DiscoveryInventory'),true);
        $this->UpdateFormField('DiscoveryInventoryList','values',json_encode(
            is_array($inventory) ? $this->discoveryInventoryRows($inventory) : [],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
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
        $this->UpdateFormField('CatalogDeviceStats','caption',$this->catalogMigrationShortSummary($migrationStats));
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
            if ($number <= 0 || $number > (99 * 1000 + 999)) {
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
            ['caption'=>'Datenquelle','name'=>'isDataSource','visible'=>false,'add'=>false],
            ['caption'=>'Raum-Key','name'=>'roomKey','visible'=>false,'add'=>'','quickFilter'=>true],
            ['caption'=>'Synonyme','name'=>'synonyms','visible'=>false,'add'=>'','quickFilter'=>true],
            ['name'=>'XSenseVariables','visible'=>false,'add'=>[],'save'=>false,'edit'=>['type'=>'List']],
                        ['name'=>'WeatherVariables','visible'=>false,'add'=>[],'save'=>false,'edit'=>['type'=>'List']],
            ['name'=>'HouseholdVariables','visible'=>false,'add'=>[],'save'=>false,'edit'=>['type'=>'List']]
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
            $visible=in_array($property,RCCDeviceTypeRegistry::roles()[$type] ?? [],true);
            $this->UpdateFormField($field,'visible',$visible);
            $this->UpdateFormField('ValuePreview_'.$field,'visible',$visible);
        }
        foreach (RCCDeviceTypeRegistry::states() as $property=>$field) {
            $show = in_array($type,['blind','window'],true)
                ? in_array($property,['open','closed'],true)
                : (in_array($type,['light','dimmer','switch','motion'],true)
                    && in_array($property,['on','off'],true));
            $this->UpdateFormField($field,'visible',$show);
        }
    }

    /**
     * Das native SelectVariable-Objektbaum-Popup kann keine zusaetzliche
     * Livewert-Spalte anzeigen. Direkt NEBEN der ausgewaehlten Variable im
     * Geraeteeditor gibt es deshalb eine aktualisierbare Wert-Vorschau.
     * Reines Lesen, nie RequestAction oder EDIT/PROD-Schreibzugriff.
     */
    private function liveVariablePreview(int $variableId): string
    {
        if ($variableId<=0) return 'Aktueller Wert: keine Variable ausgewaehlt';
        if (!function_exists('IPS_VariableExists') || !IPS_VariableExists($variableId))
            return 'Aktueller Wert: Variable #'.$variableId.' existiert nicht!';
        if (!function_exists('IPS_GetVariable') || !function_exists('GetValue'))
            return 'Variable #'.$variableId.' vorhanden; Livewert im Test nicht verfuegbar';
        try {
            $var=IPS_GetVariable($variableId);
            $value=GetValue($variableId);
            $type=(int)($var['VariableType']??-1);
            $typeName=match($type) { 0=>'Bool',1=>'Integer',2=>'Float',3=>'String',default=>'unbekannt' };
            $raw=is_bool($value) ? ($value?'true':'false') :
                (is_scalar($value)?(string)$value:'[unbekannt]');
            // Es sind auch stringbasierte Rueckmeldungen moeglich; potentiell
            // sensitive URLs, JSON oder lange Texte NICHT im Editor drucken.
            if (is_string($value) && (mb_strlen($value,'UTF-8')>70
                || preg_match('~[/:\\\\@{}]|https?~iu',$value))) $raw='[Text ausgeblendet]';
            $preview='Aktuell: '.$raw;
            if (function_exists('GetValueFormatted')) {
                try {
                    $formatted=GetValueFormatted($variableId);
                    if (is_scalar($formatted) && mb_strlen((string)$formatted,'UTF-8')<=70
                        && !preg_match('~[/:\\\\@{}]|https?~iu',(string)$formatted)
                        && (string)$formatted!==$raw)
                        $preview.=' ('.(string)$formatted.')';
                } catch (Throwable $ignored) {}
            }
            $profile=(string)(($var['VariableCustomProfile']??'')?:($var['VariableProfile']??''));
            $preview.=' | #'.$variableId.' | '.$typeName;
            if ($profile!=='') $preview.=' | Profil: '.$profile;
            return $preview;
        } catch (Throwable $e) {
            return 'Variable #'.$variableId.' konnte nicht gelesen werden.';
        }
    }

    /** Statusvorschau direkt nach Schliessen des Variablenauswahldialogs. */
    public function RefreshSelectedVariablePreview(string $field, int $variableId): void
    {
        if (!in_array($field,array_values(RCCDeviceTypeRegistry::fields()),true)) return;
        $this->UpdateFormField('ValuePreview_'.$field,'caption',
            $this->liveVariablePreview($variableId));
    }


    /**
     * V3.5.0: Festgehaltene Markierung fuer den mehrstufigen EDIT-Editor.
     * Der normale IPS-List-Haken ist nur eine voruebergehende Auswahl und
     * wird nach SaveToEdit bewusst neu geladen. Deshalb eigene Wizard-Keys.
     */
    private function markedEditorContext(): ?array
    {
        $keys=json_decode($this->ReadAttributeString('MarkedEditorKeys'),true);
        if(!is_array($keys) || $keys===[]) return null;
        $keys=array_values(array_filter($keys,static fn($key):bool=>
            is_string($key) && substr_count($key,'|')===3));
        if($keys===[]) return null;
        $index=max(0,min(count($keys)-1,(int)$this->ReadAttributeString('MarkedEditorIndex')));
        $key=$keys[$index];
        foreach($this->getRuntimeEntries() as $row) {
            if(is_array($row) && $this->buildCompositeKey($row)===$key)
                return compact('keys','index','key','row');
        }
        return ['keys'=>$keys,'index'=>$index,'key'=>$key,'row'=>null];
    }

    private function markedEditorName(array $row): string
    {
        return trim((string)($row['title']??$row['key']??'Geraet'));
    }

    /** Erst nach Auswahl per Button einen bestandsfesten Bearbeitungsdurchlauf starten. */
    public function StartMarkedEditor(): void
    {
        $this->ensureRuntimeCatalogCurrent();
        $keys=$this->bulkSelectionKeys();
        $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        if($keys===[] || $editId<=0 || !IPS_ScriptExists($editId)) {
            $message='Bitte zuerst Geraete in der Tabelle markieren und ein gueltiges EDIT-Skript konfigurieren.';
            $this->WriteAttributeString('MarkedEditorNotice',$message);
            $this->UpdateFormField('MarkedEditorNotice','caption',$message);
            echo $message;
            return;
        }
        $this->WriteAttributeString('MarkedEditorKeys',
            json_encode($keys,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $this->WriteAttributeString('MarkedEditorIndex','0');
        $this->WriteAttributeString('MarkedEditorEditHash',
            hash('sha256',(string)IPS_GetScriptContent($editId)));
        $this->WriteAttributeString('MarkedEditorNotice',
            count($keys).' Geraete im Durchlauf. Aenderungen werden pro Geraet nur durch SPEICHERN uebernommen.');
        $this->refreshMarkedEditorForm();
        echo count($keys).' markierte Geraete zur Bearbeitung geladen. Erster Eintrag aktiv.';
    }

    public function FinishMarkedEditor(): void
    {
        $this->WriteAttributeString('MarkedEditorKeys','[]');
        $this->WriteAttributeString('MarkedEditorIndex','0');
        $this->WriteAttributeString('MarkedEditorEditHash','');
        $this->WriteAttributeString('MarkedEditorNotice','Bearbeitungsdurchlauf abgeschlossen. EDIT bleibt gespeichert.');
        $this->refreshMarkedEditorForm();
    }

    /** Nur navigieren. Nicht gespeicherte Eingaben werden bewusst verworfen. */
    public function StepMarkedEditor(int $step): void
    {
        $context=$this->markedEditorContext();
        if($context===null) {
            echo 'Noch kein Bearbeitungsdurchlauf gestartet.'; return;
        }
        $newIndex=max(0,min(count($context['keys'])-1,
            $context['index']+($step<0?-1:1)));
        $this->WriteAttributeString('MarkedEditorIndex',(string)$newIndex);
        $this->WriteAttributeString('MarkedEditorNotice',
            'Geraet gewechselt. Nicht gespeicherte Aenderungen am vorherigen Eintrag wurden verworfen.');
        $this->refreshMarkedEditorForm();
    }

    /** Optional gezielt einen markierten Eintrag in der Dropdown-Liste anwaehlen. */
    public function ChooseMarkedEditor(string $key): void
    {
        $context=$this->markedEditorContext();
        if($context===null) return;
        $index=array_search($key,$context['keys'],true);
        if($index===false) return;
        $this->WriteAttributeString('MarkedEditorIndex',(string)$index);
        $this->WriteAttributeString('MarkedEditorNotice',
            'Eintrag gewechselt. Aenderungen bitte vor dem Wechsel speichern.');
        $this->refreshMarkedEditorForm();
    }

    /**
     * Ausschliesslich fuer Bewegungsmelder: eindeutig erkennbare IST-Variable
     * unter der bekannten Quell-Instanz als EDIT-Vorschlag anzeigen.
     * 11995 ist eine Instanz, keine boolesche Lesevariable. Kein Autowrite,
     * keine KI-Freigabe, keine fachliche Bestaetigung.
     */
    private function markedEditorMotionCandidate(array $row): int
    {
        if ((int)($row['aiMotionId']??0)>0) return 0;
        $source=(int)($row['discoverySourceId']??0);
        if ($source<=0 || !function_exists('IPS_ObjectExists')
            || !IPS_ObjectExists($source)) return 0;
        $candidates=[];
        foreach ($this->discoveryVariablesForSource($source) as $variable) {
            if ((int)($variable['type']??-1)!==0 || !empty($variable['action'])) continue;
            $id=(int)($variable['id']??0);
            if ($id<=0 || !IPS_VariableExists($id)) continue;
            $candidates[]=['id'=>$id,'score'=>$this->suggestionScore($variable,'motion')];
        }
        if ($candidates===[]) return 0;
        usort($candidates,static fn($a,$b):int=>$b['score']<=>$a['score']
            ?: $a['id']<=>$b['id']);
        $best=$candidates[0];
        $second=(int)($candidates[1]['score']??0);
        // Eindeutiger Profil-/Namens-Treffer oder einzige lesbare
        // Boolean-Variable dieser als motion typisierten Quelle.
        if (($best['score']>=105 && $best['score']-$second>=25)
            || count($candidates)===1)
            return (int)$best['id'];
        return 0;
    }

    private function markedEditorSourceInfo(array $row,string $type,int $motionId): string
    {
        if ($row===[]) return '';
        $source=(int)($row['discoverySourceId']??0);
        if ($source<=0) return 'Keine Discovery-Quellinstanz hinterlegt.';
        if ($type==='motion' && (int)($row['aiMotionId']??0)<=0) {
            if ($motionId>0) return 'Quelle: IPS-Instanz #'.$source.
                '. Bewegung: Variable #'.$motionId.
                ' unter der Instanz eindeutig vorgeschlagen (noch NICHT gespeichert). '
                .'Livewert und Zuordnung bitte pruefen, dann DIESES GERAET SPEICHERN.';
            return 'Quelle: IPS-Instanz #'.$source.
                '. Keine eindeutige Bewegungs-Istvariable gefunden. '
                .'Bitte im IP-Symcon-Baum die passende untergeordnete Variable waehlen.';
        }
        return 'Quell-Objekt/Instanz #'.$source.' (reine Herkunftsangabe).';
    }

    /**
     * Native SelectVariable kennt keinen SDK-Parameter fuer einen Startknoten.
     * Deshalb bekommt jeder Geraete-Rollenwert eine zweite, nach der bereits
     * bekannten Quellinstanz eingegrenzte Direktauswahl. Der originale
     * SelectVariable-Baum bleibt fuer Ausnahmen erhalten.
     */
    private function markedEditorVariableScope(array $row): array
    {
        $source=(int)($row['discoverySourceId']??0);
        if ($source<=0) {
            // Bei Altgeraeten darf eine bereits bekannte Istvariable ihren
            // Elternknoten als Filter liefern. Keine Suche im ganzen Haus.
            foreach (array_values(RCCDeviceTypeRegistry::fields()) as $field) {
                $id=(int)($row[$field]??0);
                if ($id<=0 || !function_exists('IPS_VariableExists')
                    || !IPS_VariableExists($id)) continue;
                $source=$this->lookupIpsInstanceId($id);
                if ($source>0)break;
            }
        }
        if ($source<=0 || !function_exists('IPS_ObjectExists')
            || !IPS_ObjectExists($source))return ['sourceId'=>0,'variables'=>[]];
        $variables=[];
        foreach ($this->discoveryVariablesForSource($source) as $variable) {
            $id=(int)($variable['id']??0);
            if ($id>0 && function_exists('IPS_VariableExists') && IPS_VariableExists($id))
                $variables[$id]=$variable;
        }
        return ['sourceId'=>$source,'variables'=>array_values($variables)];
    }

    private function markedEditorScopedOptions(array $variables,string $property): array
    {
        $options=[['caption'=>'Keine Variable (Zuordnung entfernen)','value'=>0]];
        $dataTypes=RCCDeviceTypeRegistry::dataTypes()[$property]??[];
        foreach ($variables as $variable) {
            $id=(int)($variable['id']??0);
            if ($id<=0 || !in_array((int)($variable['type']??-1),$dataTypes,true))
                continue;
            $name=mb_substr((string)($variable['name']??'Variable'),0,70,'UTF-8');
            $profile=mb_substr((string)($variable['profile']??''),0,60,'UTF-8');
            $type=match((int)($variable['type']??-1)) {
                0=>'Bool',1=>'Integer',2=>'Float',3=>'String',default=>'Unbekannt'
            };
            $caption='#'.$id.' | '.$name.' | '.$type;
            if ($profile!=='')$caption.=' | '.$profile;
            if (!empty($variable['action']))$caption.=' | Aktion';
            $options[]=['caption'=>$caption,'value'=>$id];
        }
        return $options;
    }

    private function markedEditorScopedValue(array $options,int $id): int
    {
        if ($id<=0)return 0;
        foreach ($options as $option)if ((int)$option['value']===$id)return $id;
        return 0;
    }

    /**
     * Auswahlliste fuer genau den aktuellen Geraete-Knoten; nur UI-aendernd.
     * Auch manipulierte Optionen duerfen keine fremden IDs einschleusen.
     */
    public function SelectMarkedScopedVariable(string $field,int $variableId): void
    {
        $context=$this->markedEditorContext();
        $row=is_array($context['row']??null)?$context['row']:[];
        if ($row===[])return;
        $type=(string)($row['aiType']??'');
        if (!isset(RCCDeviceTypeRegistry::roles()[$type]))
            $type=$this->inferredAIType($row);
        $role=array_search($field,RCCDeviceTypeRegistry::fields(),true);
        if ($role===false || !in_array($role,RCCDeviceTypeRegistry::roles()[$type]??[],true))return;
        $scope=$this->markedEditorVariableScope($row);
        $options=$this->markedEditorScopedOptions($scope['variables'],$role);
        if ($variableId!==0 && $this->markedEditorScopedValue($options,$variableId)!==$variableId)
            return;
        $this->UpdateFormField('MarkedEdit_'.$field,'value',$variableId);
        $this->UpdateFormField('MarkedValuePreview_'.$field,'caption',
            $this->liveVariablePreview($variableId));
    }

    /** Statusvorschau auch im mehrstufigen Editor nach SelectVariable-Auswahl. */
    public function RefreshMarkedVariablePreview(string $field,int $variableId): void
    {
        $role=array_search($field,RCCDeviceTypeRegistry::fields(),true);
        if($role===false)return;
        $this->UpdateFormField('MarkedValuePreview_'.$field,'caption',
            $this->liveVariablePreview($variableId));
        $context=$this->markedEditorContext();
        $row=is_array($context['row']??null)?$context['row']:[];
        if($row===[])return;
        $scope=$this->markedEditorVariableScope($row);
        $options=$this->markedEditorScopedOptions($scope['variables'],$role);
        $this->UpdateFormField('MarkedScope_'.$field,'value',
            $this->markedEditorScopedValue($options,$variableId));
    }

    /**
     * UI-Update OHNE ReloadForm: laesst das Panel offen, zeigt kompletten
     * Rollenblock und die aktuellen IPS-Werte des naechsten Geraetes.
     */
    private function refreshMarkedEditorForm(): void
    {
        $context=$this->markedEditorContext();
        $row=is_array($context['row']??null)?$context['row']:[];
        $active=$row!==[];
        $type=(string)($row['aiType']??'');
        if(!isset(RCCDeviceTypeRegistry::roles()[$type]) && $active)
            $type=$this->inferredAIType($row);
        if(!isset(RCCDeviceTypeRegistry::roles()[$type]))$type='';
        $total=count($context['keys']??[]);
        $index=(int)($context['index']??0);
        $caption=$active
            ? sprintf('Eintrag %d/%d: %s – %s / %s / %s',
                $index+1,$total,$this->markedEditorName($row),
                (string)($row['roomLabel']??$row['roomKey']),
                (string)($row['domain']??''),(string)($row['group']??''))
            : ($total>0?'Eintrag in EDIT nicht mehr vorhanden – Durchlauf neu starten.'
                :'Noch kein Durchlauf: zuerst die markierten Geraete laden.');
        $this->UpdateFormField('MarkedEditorPanel','visible',$active);
        $this->UpdateFormField('MarkedEditorPanel','expanded',true);
        $this->UpdateFormField('MarkedEditorProgress','caption',$caption);
        $this->UpdateFormField('MarkedEditorNotice','caption',
            $this->ReadAttributeString('MarkedEditorNotice'));
        $this->UpdateFormField('MarkedEditorDevice','options',
            json_encode($this->markedEditorOptions($context),JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('MarkedEditorDevice','value',(string)($context['key']??''));
        $this->UpdateFormField('MarkedEditorPrevious','enabled',$active&&$index>0);
        $this->UpdateFormField('MarkedEditorNext','enabled',$active&&$index<$total-1);
        $this->UpdateFormField('MarkedEditorSave','enabled',$active);
        $this->UpdateFormField('MarkedEditorSaveNext','enabled',$active);
        $autoMotionId=$active && $type==='motion'
            ? $this->markedEditorMotionCandidate($row) : 0;
        $scope=$active ? $this->markedEditorVariableScope($row)
            : ['sourceId'=>0,'variables'=>[]];
        $this->UpdateFormField('MarkedEditorSourceInfo','caption',
            $this->markedEditorSourceInfo($row,$type,$autoMotionId).
            ($scope['sourceId']>0 ? ' | Variablen-Filter: Instanz/Objekt #'.
                $scope['sourceId'].'.' : ''));
        $this->UpdateFormField('MarkedEditSourceKey','value',(string)($context['key']??''));
        $this->UpdateFormField('MarkedEditType','caption',
            'Geraetetyp: '.(RCCDeviceTypeRegistry::captions()[$type]??'Legacy').
            ' | Raum und Typ koennen gemeinsam oder ueber das Zahnrad geaendert werden.');
        foreach(['title'=>'MarkedEditTitle','synonyms'=>'MarkedEditSynonyms',
            'entityId'=>'MarkedEditEntityId'] as $field=>$name)
            $this->UpdateFormField($name,'value',(string)($row[$field]??''));
        foreach(['kiEnabled'=>'MarkedEditKI','kiReadOnly'=>'MarkedEditReadOnly',
            'aiReviewed'=>'MarkedEditReviewed'] as $field=>$name)
            $this->UpdateFormField($name,'value',(bool)($row[$field]??($field==='kiReadOnly')));
        foreach(RCCDeviceTypeRegistry::fields() as $property=>$field) {
            $visible=$active && in_array($property,
                RCCDeviceTypeRegistry::roles()[$type]??[],true);
            $id=(int)($row[$field]??0);
            if($type==='motion' && $property==='motion' && $id<=0)
                $id=$autoMotionId;
            if($id>0 && (!function_exists('IPS_VariableExists')||!IPS_VariableExists($id)))
                $id=0;
            $options=$this->markedEditorScopedOptions($scope['variables'],$property);
            $this->UpdateFormField('MarkedScope_'.$field,'visible',
                $visible && count($options)>1);
            $this->UpdateFormField('MarkedScope_'.$field,'options',
                json_encode($options,JSON_INVALID_UTF8_SUBSTITUTE));
            $this->UpdateFormField('MarkedScope_'.$field,'value',
                $this->markedEditorScopedValue($options,$id));
            $this->UpdateFormField('MarkedEdit_'.$field,'visible',$visible);
            $this->UpdateFormField('MarkedEdit_'.$field,'value',$id);
            $this->UpdateFormField('MarkedValuePreview_'.$field,'visible',$visible);
            $this->UpdateFormField('MarkedValuePreview_'.$field,'caption',
                $this->liveVariablePreview($id));
        }
        foreach(RCCDeviceTypeRegistry::states() as $property=>$field) {
            $visible=$active && (in_array($type,['blind','window'],true)
                ? in_array($property,['open','closed'],true)
                : (in_array($type,['light','dimmer','switch','motion'],true)
                    && in_array($property,['on','off'],true)));
            $this->UpdateFormField('MarkedEdit_'.$field,'visible',$visible);
            $this->UpdateFormField('MarkedEdit_'.$field,'value',(string)($row[$field]??''));
        }
    }

    private function markedEditorOptions(?array $context): array
    {
        if($context===null)return [['caption'=>'Kein Durchlauf gestartet','value'=>'']];
        $rows=[];
        foreach($this->getRuntimeEntries() as $row) {
            if(is_array($row) && ($key=$this->buildCompositeKey($row))!==null)
                $rows[$key]=$row;
        }
        $result=[];
        foreach($context['keys'] as $i=>$key) {
            $row=$rows[$key]??null;
            $caption=$row===null?'[Nicht mehr vorhanden] '.$key:
                sprintf('%d. %s / %s', $i+1,
                    (string)($row['roomLabel']??$row['roomKey']),$this->markedEditorName($row));
            $result[]=['caption'=>$caption,'value'=>$key];
        }
        return $result;
    }

    /** Standard-IPS-Formelemente; keine zweite Kopie des RoomsCatalog. */
    private function markedEditorForm(): array
    {
        $context=$this->markedEditorContext();
        $row=is_array($context['row']??null)?$context['row']:[];
        $active=$row!==[];
        $type=(string)($row['aiType']??'');
        if($type==='' && $active)$type=$this->inferredAIType($row);
        if(!isset(RCCDeviceTypeRegistry::roles()[$type]))$type='';
        $index=(int)($context['index']??0);
        $total=count($context['keys']??[]);
        $roles=[];
        $captions=RCCDeviceTypeRegistry::propertyCaptions();
        $autoMotionId=$active && $type==='motion'
            ? $this->markedEditorMotionCandidate($row) : 0;
        $scope=$active ? $this->markedEditorVariableScope($row)
            : ['sourceId'=>0,'variables'=>[]];
        foreach(RCCDeviceTypeRegistry::fields() as $property=>$field) {
            $visible=$active && in_array($property,RCCDeviceTypeRegistry::roles()[$type]??[],true);
            $id=(int)($row[$field]??0);
            if($type==='motion' && $property==='motion' && $id<=0)
                $id=$autoMotionId;
            $options=$this->markedEditorScopedOptions($scope['variables'],$property);
            $useScope=$visible && count($options)>1;
            $roles[]=['type'=>'RowLayout','items'=>[
                ['type'=>'Select','name'=>'MarkedScope_'.$field,
                    'caption'=>($captions[$property]??$property).
                        ' (Filter #'.$scope['sourceId'].')',
                    'options'=>$options,
                    'value'=>$this->markedEditorScopedValue($options,$id),
                    'visible'=>$useScope,'width'=>'39%',
                    'onChange'=>sprintf('RCC_SelectMarkedScopedVariable($id, "%s", (int)$MarkedScope_%s);',
                        $field,$field)],
                ['type'=>'SelectVariable','name'=>'MarkedEdit_'.$field,
                    'caption'=>$useScope?'Anderes Objekt (ganzer IPS-Baum)':($captions[$property]??$property),
                    'visible'=>$visible,'value'=>$id,'width'=>$useScope?'26%':'43%',
                    'onChange'=>sprintf('RCC_RefreshMarkedVariablePreview($id, "%s", $MarkedEdit_%s);',
                        $field,$field)],
                ['type'=>'Label','name'=>'MarkedValuePreview_'.$field,
                    'caption'=>$this->liveVariablePreview($id),'visible'=>$visible]
            ]];
        }
        $states=[];
        foreach(RCCDeviceTypeRegistry::states() as $property=>$field) {
            $visible=$active && (in_array($type,['blind','window'],true)
                ? in_array($property,['open','closed'],true)
                : (in_array($type,['light','dimmer','switch','motion'],true)
                    && in_array($property,['on','off'],true)));
            $states[]=['type'=>'ValidationTextBox','name'=>'MarkedEdit_'.$field,
                'caption'=>match($property){'on'=>'Ein/Aktiv ist','off'=>'Aus/Inaktiv ist',
                    'open'=>'Offen ist','closed'=>'Geschlossen ist',default=>$property},
                'value'=>(string)($row[$field]??''),'visible'=>$visible];
        }
        $parts=[
            "'sourceKey'=>\$MarkedEditSourceKey",
            "'title'=>\$MarkedEditTitle",
            "'synonyms'=>\$MarkedEditSynonyms",
            "'entityId'=>\$MarkedEditEntityId",
            "'kiEnabled'=>\$MarkedEditKI",
            "'kiReadOnly'=>\$MarkedEditReadOnly",
            "'aiReviewed'=>\$MarkedEditReviewed"
        ];
        foreach(RCCDeviceTypeRegistry::fields() as $field)
            $parts[]=var_export($field,true).' => ($MarkedEdit_'.$field.' ?? null)';
        foreach(RCCDeviceTypeRegistry::states() as $field)
            $parts[]=var_export($field,true).' => ($MarkedEdit_'.$field.' ?? null)';
        $payload='json_encode(array_filter(['.implode(', ',$parts)
            .'], static fn($v) => $v !== null), JSON_INVALID_UTF8_SUBSTITUTE)';
        $onSave='RCC_SaveMarkedEditorDevice($id, '.$payload.', false);';
        $onSaveNext='RCC_SaveMarkedEditorDevice($id, '.$payload.', true);';
        return [
            'type'=>'ExpansionPanel','name'=>'MarkedEditorPanel',
            'caption'=>'Markierte Geraete nacheinander bearbeiten',
            'expanded'=>false,'visible'=>$active,'items'=>[
                ['type'=>'Label','name'=>'MarkedEditorProgress','caption'=>$active
                    ? sprintf('Eintrag %d/%d – %s', $index+1,$total,$this->markedEditorName($row))
                    :'Noch kein Durchlauf gestartet.'],
                ['type'=>'Label','name'=>'MarkedEditorNotice',
                    'caption'=>$this->ReadAttributeString('MarkedEditorNotice')],
                ['type'=>'Select','name'=>'MarkedEditorDevice',
                    'caption'=>'Direkt zu einem markierten Geraet wechseln',
                    'options'=>$this->markedEditorOptions($context),
                    'value'=>(string)($context['key']??''),
                    'onChange'=>'RCC_ChooseMarkedEditor($id, (string)$MarkedEditorDevice);'],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'Button','name'=>'MarkedEditorPrevious',
                        'caption'=>'◀ ZURUECK','enabled'=>$index>0,
                        'onClick'=>'RCC_StepMarkedEditor($id,-1);'],
                    ['type'=>'Button','name'=>'MarkedEditorNext',
                        'caption'=>'WEITER ▶','enabled'=>$index<$total-1,
                        'onClick'=>'RCC_StepMarkedEditor($id,1);'],
                    ['type'=>'Button','name'=>'MarkedEditorSaveNext',
                        'caption'=>'SPEICHERN UND WEITER ▶',
                        'enabled'=>$active,'onClick'=>$onSaveNext],
                    ['type'=>'Button','caption'=>'DURCHLAUF BEENDEN',
                        'onClick'=>'RCC_FinishMarkedEditor($id);']
                ]],
                ['type'=>'ValidationTextBox','name'=>'MarkedEditSourceKey',
                    'value'=>(string)($context['key']??''),'visible'=>false],
                ['type'=>'Label','name'=>'MarkedEditType',
                    'caption'=>'Geraetetyp: '.(RCCDeviceTypeRegistry::captions()[$type]??'Legacy').
                        ' | Typ-/Raumwechsel weiterhin ueber Zahnrad oder gemeinsame Bearbeitung.'],
                ['type'=>'Label','name'=>'MarkedEditorSourceInfo',
                    'caption'=>$this->markedEditorSourceInfo($row,$type,$autoMotionId)],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'ValidationTextBox','name'=>'MarkedEditTitle',
                        'caption'=>'Geraetename','value'=>(string)($row['title']??'')],
                    ['type'=>'ValidationTextBox','name'=>'MarkedEditSynonyms',
                        'caption'=>'Alternative Namen','value'=>(string)($row['synonyms']??'')],
                    ['type'=>'ValidationTextBox','name'=>'MarkedEditEntityId',
                        'caption'=>'Entity-ID (optional)','value'=>(string)($row['entityId']??'')]
                ]],
                ['type'=>'ExpansionPanel','caption'=>'Zugehoerige Variablen / aktuelle IPS-Werte',
                    'expanded'=>false,'items'=>$roles],
                ['type'=>'RowLayout','items'=>$states],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'CheckBox','name'=>'MarkedEditKI','caption'=>'Mit KI',
                        'value'=>(bool)($row['kiEnabled']??false)],
                    ['type'=>'CheckBox','name'=>'MarkedEditReadOnly','caption'=>'Nur abfragen',
                        'value'=>(bool)($row['kiReadOnly']??true)],
                    ['type'=>'CheckBox','name'=>'MarkedEditReviewed',
                        'caption'=>'Fachlich geprueft','value'=>(bool)($row['aiReviewed']??false)]
                ]],
                ['type'=>'Label','caption'=>'VOR/WEITER speichert nicht automatisch. '
                    .'Bitte jedes Geraet bewusst speichern; alle Werte sind unabhaengig, '
                    .'Variablen-IDs werden nie auf andere Geraete kopiert. Nur EDIT.'],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'Button','name'=>'MarkedEditorSave',
                        'caption'=>'DIESES GERAET IN EDIT SPEICHERN',
                        'onClick'=>$onSave]
                ]]
            ]
        ];
    }

    /** Nur aktuelles Geraet aktualisieren, nie alle gleichzeitig mit denselben Variablen-IDs. */
    public function SaveMarkedEditorDevice(string $dataJson,bool $next=false): void
    {
        $context=$this->markedEditorContext();
        $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $input=json_decode($dataJson,true);
        $fail=function(string $message):void{
            $this->WriteAttributeString('MarkedEditorNotice',$message);
            $this->UpdateFormField('MarkedEditorNotice','caption',$message);
            echo $message;
        };
        if($context===null || !is_array($context['row']??null)
            || !is_array($input) || $editId<=0 || !IPS_ScriptExists($editId)) {
            $fail('Speichern abgebrochen: Bitte die Markierung erneut laden.');return;
        }
        $key=$context['key'];
        if(($input['sourceKey']??'')!==$key) {
            $fail('Geraet gewechselt: Eingaben stammen aus einem anderen Eintrag. Bitte erneut pruefen.');return;
        }
        $source=(string)IPS_GetScriptContent($editId);
        if(hash('sha256',$source)!==$this->ReadAttributeString('MarkedEditorEditHash')) {
            $fail('EDIT wurde seit dem Laden geaendert: zuerst Bearbeitungsdurchlauf neu starten.');return;
        }
        $row=$context['row'];
        $type=(string)($row['aiType']??'');
        if(!isset(RCCDeviceTypeRegistry::roles()[$type])) {
            // Legacy-Geraete koennen Namen und Berechtigungen erhalten.
            // Typ-Rollen sind erst nach expliziter Typisierung editierbar.
            $type='';
        }
        $title=trim((string)($input['title']??''));
        if($title==='' || mb_strlen($title,'UTF-8')>200) {
            $fail('Bitte einen Geraetenamen mit maximal 200 Zeichen angeben.');return;
        }
        // SaveToEdit erkennt ein einzelnes Geraet nur anhand der
        // roomKey/domain/key-Spalten. Ohne diese wuerde das assoziative
        // Payload als Zeilen-Liste missverstanden und NICHT gespeichert.
        $update=['editorOriginalKey'=>$key,
            'roomKey'=>(string)$row['roomKey'],
            'domain'=>(string)$row['domain'],
            'group'=>(string)$row['group'],
            'key'=>(string)$row['key'],
            'title'=>$title,
            'synonyms'=>(string)($input['synonyms']??''),
            'entityId'=>(string)($input['entityId']??''),
            'kiEnabled'=>(bool)($input['kiEnabled']??false),
            'kiReadOnly'=>(bool)($input['kiReadOnly']??true),
            'aiReviewed'=>(bool)($input['aiReviewed']??false)];
        if($type!=='') {
            foreach(RCCDeviceTypeRegistry::fields() as $role=>$field) {
                if(!in_array($role,RCCDeviceTypeRegistry::roles()[$type],true))continue;
                if(!array_key_exists($field,$input))continue;
                $value=(int)$input[$field];
                if($value>0 && (!function_exists('IPS_VariableExists')
                    || !IPS_VariableExists($value))) {
                    $fail('Variable #'.$value.' fuer '.$role.' existiert nicht. Nicht gespeichert.');return;
                }
                if($value<0) {
                    $fail('Negative Variablen-ID ist ungueltig.');return;
                }
                $update[$field]=$value;
            }
            // Zusammenspiel von typisiertem ai-Wert und bestehendem
            // Legacy-Feld: wurde das alte Feld bisher aus derselben
            // Rueckmeldung gespeist, darf es nicht auf der alten IPS-ID
            // haengen bleiben, wenn nur diese Rolle geaendert wird.
            $linkedLegacy=match($type) {
                'motion'=>['motion'=>'state'],
                'window'=>['open'=>'state'],
                'light','dimmer','switch','fan'=>['power'=>'state',
                    'control'=>'toggle','brightness'=>'value'],
                'heating'=>['temperature'=>'ist','targetTemperature'=>'soll'],
                'blind'=>['position'=>'wert'],
                default=>[]
            };
            foreach($linkedLegacy as $role=>$legacyField) {
                $field=RCCDeviceTypeRegistry::fields()[$role]??'';
                $oldId=(int)($row[$field]??0);
                if($field==='' || !array_key_exists($field,$update)
                    || $oldId<=0 || $oldId!==(int)($row[$legacyField]??0)
                    || $oldId===(int)$update[$field])continue;
                $update[$legacyField]=(int)$update[$field];
            }
            // Bei Legacy-Motion-Imports wurde 'status' haeufig nur als
            // virtuelle Zweitrolle aus state abgeleitet. Wenn motion
            // bewusst verschoben wurde, soll diese unbestaetigte Kopie
            // keinen veralteten Statuswert zurueckhalten.
            if($type==='motion' && array_key_exists('aiMotionId',$update)) {
                $oldMotion=(int)($row['aiMotionId']??0);
                $oldStatus=(int)($row['aiStatusId']??0);
                $rawAi=$row['ai']??[];
                if(is_string($rawAi))$rawAi=json_decode($rawAi,true);
                $explicitStatus=is_array($rawAi)
                    && array_key_exists('status',(array)($rawAi['variables']??[]));
                if(!$explicitStatus && $oldMotion>0 && $oldMotion===$oldStatus
                    && $oldMotion!==(int)$update['aiMotionId']
                    && ($update['aiStatusId']??$oldStatus)===$oldStatus)
                    $update['aiStatusId']=(int)$update['aiMotionId'];
            }
            foreach(RCCDeviceTypeRegistry::states() as $state=>$field) {
                $visible=in_array($type,['blind','window'],true)
                    ? in_array($state,['open','closed'],true)
                    : (in_array($type,['light','dimmer','switch','motion'],true)
                        && in_array($state,['on','off'],true));
                if($visible && array_key_exists($field,$input))
                    $update[$field]=trim((string)$input[$field]);
            }
        }
        // Bestehende, ansonsten nicht editierte Felder bleiben im SaveToEdit
        // erhalten, auch die individuelle Quelle, Gruppe, bisherige Keys usw.
        $this->SaveToEdit(json_encode($update,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $after=(string)IPS_GetScriptContent($editId);
        $persisted=$this->buildFlatEntriesFromRooms($this->extractRoomsFromCatalog(
            $this->loadRoomsCatalog($editId,'EDIT')));
        $saved=null;
        foreach($persisted as $candidate) {
            if($this->buildCompositeKey($candidate)===$key) {$saved=$candidate;break;}
        }
        $okay=is_array($saved)
            && (string)($saved['title']??'')===$title
            && (bool)($saved['kiEnabled']??false)===$update['kiEnabled']
            && (bool)($saved['kiReadOnly']??true)===$update['kiReadOnly'];
        if($okay && $type!=='') {
            foreach(RCCDeviceTypeRegistry::fields() as $role=>$field) {
                if(!array_key_exists($field,$update))continue;
                if((int)($saved[$field]??0)!==(int)$update[$field])
                    {$okay=false;break;}
            }
        }
        if(!$okay) {
            $this->WriteAttributeString('MarkedEditorEditHash',hash('sha256',$after));
            $fail('Speichern nicht bestaetigt: der EDIT-Katalog enthaelt die erwarteten Werte '
                .'nicht. Eintrag nicht gewechselt; bitte Ursache pruefen.');
            return;
        }
        $this->WriteAttributeString('MarkedEditorEditHash',hash('sha256',$after));
        if($next && $context['index']<count($context['keys'])-1)
            $this->WriteAttributeString('MarkedEditorIndex',(string)($context['index']+1));
        $this->WriteAttributeString('MarkedEditorNotice',
            'Geraet '.($context['index']+1).'/'.count($context['keys']).
            ' nach EDIT gespeichert. PROD unveraendert.'.
            ($next?' Naechster Eintrag geladen.':''));
        $this->refreshMarkedEditorForm();
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
        // V3.7: Fähigkeiten werden aus vorhandenen IPS-Variablen und
        // fachlichen KI-Rechten berechnet, nicht als zweites Schema gepflegt.
        if (!$isNew) {
            $capabilities = RCCDeviceCapabilities::describe($row);
            $items[] = [
                'type'=>'ExpansionPanel',
                'caption'=>'V3.7 – erkannte Gerätefähigkeiten',
                'expanded'=>false,
                'items'=>[
                    ['type'=>'Label','caption'=>$capabilities['message']],
                    ['type'=>'Label','caption'=>'Eigenschaften und Berechtigungen '
                        .'werden weiterhin oben in der Gerätebearbeitung '
                        .'eingestellt. Szenen und frei gesprochene Befehle '
                        .'werden in IPSAlexaHaussteuerung verwaltet.']
                ]
            ];
        }
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
        foreach (RCCDeviceTypeRegistry::fields() as $property=>$field) {
            $visible=in_array($property,RCCDeviceTypeRegistry::roles()[$editorType] ?? [],true);
            $currentId=isset($invalidBindings[$property])?0:(int)($row[$field]??0);
            $control=['type'=>'SelectVariable','name'=>$field,
                'caption'=>$variableCaptions[$property]??$property,
                'width'=>'43%','visible'=>$visible,
                // Nach Bestaetigen des IPS-Baumdialogs die Auswahl direkt lesen.
                'onChange'=>sprintf('RCC_RefreshSelectedVariablePreview($id, "%s", $%s);',$field,$field)];
            if (isset($invalidBindings[$property])) {
                // Keine muss veraltete IDs beim Speichern entfernen.
                $control['value']=0;
            }
            $aiItems[]=['type'=>'RowLayout','items'=>[
                $control,
                ['type'=>'Label','name'=>'ValuePreview_'.$field,
                    'caption'=>$this->liveVariablePreview($currentId),
                    'visible'=>$visible]
            ]];
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

