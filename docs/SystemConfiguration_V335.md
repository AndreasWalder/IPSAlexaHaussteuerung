# V3.3.5 – geprüfte Navigation-Umstellung aus RoomsCatalog nach SystemConfiguration

**Wichtig:** Der GitHub-Commit ändert die produktiven Einstellungen beim Modulupdate
NICHT. Der Benutzer bestätigt den schreibenden Cutover und die spätere
Bereinigung getrennt in der Konfiguration von `IPSAlexaHaussteuerung`.

## Voraussetzungen

- Die V3.3.4-Tabs sind geprüft in `SystemConfigurationEdit` enthalten.
- Ursprüngliche Quellpfade und Rohdefinitionen wurden durch die
  EDIT-Bearbeitung nicht gelöscht. Es dürfen keine zusätzlichen nicht
  zugeordneten Tabs inzwischen im RoomsCatalog entstanden sein.
- In der Instanz `IPSAlexaHaussteuerung` **Übernehmen** klicken, damit
  die vorhandene Action-Vorlage gezielt um den Navigations-Leseweg
  ergänzt und `NavigationRuntime` unter den Helper-Skripten installiert wird.
- `RoomsCatalogConfigurator` mit derselben RoomsCatalog-PROD-ID muss
  für das Aufräumen verfügbar sein; `RoomsCatalogEdit` wird bevorzugt
  über den zugehörigen RoomsCatalogConfigurator automatisch ermittelt.
  Nur wenn das nicht gelingt, ist im Hauptmodul die optionale
  `NavigationRoomsCatalogEditScriptId` zu setzen.

## Schritt 1 – Navigation aktivieren

1. In **V3.3.5 – Navigation produktiv umstellen und absichern**
   **1 – Aktivierung Vorschau** wählen.
2. Prüfen: Anzahl, geprüfte und neu angelegte Tabs sowie Null-Fehler.
   Hier werden aktive IPS-Objekt-IDs auf Existenz überprüft.
3. **2 – Navigation in PROD aktivieren** bestätigen (Vorschau ist
   höchstens zehn Minuten gültig).
4. Der aktuelle PHP-Quelltext von `SystemConfiguration` wird im
   `SystemConfiguration_Backup` mit SHA-256 gesichert, bevor PROD
   geändert wird. Nur das Feld `navigation` wird aus EDIT übernommen;
   `var`, `script`, `rendererDomains`, `launchCatalog` und alle
   weiteren aktuellen PROD-Einstellungen werden unverändert bewahrt.
5. Das Haupt-Alexa-Action-Skript liest jetzt den aktivierten Abschnitt
   und ergänzt die `tabs` **nur im Speicher**. CoreHelpers,
   dynamische Domains, RenderGeraete und die anderen Renderer bekommen
   dadurch dieselbe projizierte Datenstruktur. Bis zur Bereinigung
   bleiben die alten Tabs im RoomsCatalog stehen.

**Manueller Live-Test:** Alexa-Navigation auf verschiedene Räume, Licht,
Jalousie, Geräte, Bewässerung, Sicherheit und eine dynamische Domain
(Bienen/Roboter) öffnen; Tabs wählen, zurücknavigieren und Sprache testen.
Erst danach fortfahren. Ein erfolgreicher CI-Test ersetzt den
tatsächlichen Alexa-Funktionstest nicht.

## Schritt 2 – Alte Tabs nach Live-Test bereinigen

1. **Ich habe die Navigation ... getestet** aktivieren.
2. **3 – Bereinigung Vorschau** wählen. Diese prüft die ursprünglich
   übernommenen Quellpfade in *beiden* RoomsCatalog-Skripten.
   Ältere, nachträglich veränderte oder nicht importierte Tabellen-Einträge
   verhindern die Bereinigung; ebenso ungültiges PHP, fehlende
   Skript-IDs und eine veränderte PROD-Quelle.
3. **4 – Alte Tabs ... entfernen** bestätigen.

Vor jeder Änderung werden **zwei separate vollständig verifizierte
Backup-Skripte** erstellt (RoomsCatalog + RoomsCatalogEdit). Die
Bereinigung entfernt ausschließlich die in der Migrationsmap
aufgezeichneten `domains/<Bereich>/tabs/<TabKey>`-Einträge – keine
anderen Gerätekategorien, Zustände oder Metadaten.

Die in-memory-Projektion **vor und nach dem Entfernen** muss identisch
sein, sonst bricht der Vorgang ab.

## Navigation später ändern (ohne Skripteditor)

Nach der ersten Aktivierung – auch nach der Bereinigung – kannst du einen
Tab im V3.3.4-Editor ändern oder einen neuen Tab hinzufügen. Verwende
anschließend im Abschnitt V3.3.5 die beiden Knöpfe
**Änderungen prüfen** und **Änderungen aktivieren**.
Nur das Feld `navigation` wird in die aktuelle
`SystemConfiguration` (PROD) geschrieben, nie sämtliche alten Werte
aus einer eventuell veralteten EDIT-Kopie. Die bestehende PROD-Fassung
wird vorher im `SystemConfiguration_Backup` gesichert.

Dabei werden Objekt-IDs, Dubletten, Raumbezüge, der aktuelle PROD-Hash
und die unveränderlichen Herkunftsdaten vorhandener Tabs geprüft.
Ein deaktivierter Tab bleibt als Datensatz im SystemConfiguration
erhalten und wird beim Rendern ausgeblendet. Vorhandene neue Änderungen
am produktiven System, die nicht von diesem Modul stammen, blockieren
die sichere Übernahme.

## Rückfall

**Notfall – vorherigen Stand wiederherstellen** stellt zuerst beide
RoomsCatalog-Skripte aus den persönlichen Backup-Skripten wieder her
(sofern bereits bereinigt) und erst danach die ursprüngliche
`SystemConfiguration` aus `SystemConfiguration_Backup`. Alle
Quelltexte werden bytegenau geprüft. Wenn seit dem Cutover manuelle
Änderungen passiert sind, ist die automatische Wiederherstellung
gesperrt, um neuere Einstellungen nicht zu überschreiben.

## Begrenzungen / bekannte Risiken

- Bis der Benutzer die Aktivierung bestätigt, läuft die Navigation
  unverändert aus RoomsCatalog. Nach Bestätigung läuft sie aus
  `SystemConfiguration` (PROD), nicht aus EDIT.
- Im RoomsCatalogConfigurator kann ein alter EDIT-/PROD-Stand später
  wieder Tab-Einträge erzeugen, wenn unabhängig alte Sicherungen
  zurückgespielt werden. Nach Cutover immer dessen aktuellen EDIT
  verwenden.
- Echte Geräte, die aus historischen Gründen in einer Gruppe
  `tabs` liegen, **müssen** vor der Aktivierung von Navigationseinträgen
  unterschieden sein. Der Quellabgleich ist bewusst strikt und blockiert
  unbekannte zusätzliche Tabs, statt sie stillschweigend zu löschen.
- Keine KI-Schreibfreigaben werden geändert.
- Ein PHP-Datenmodell mit dynamischen Ausdrücken wird nicht automatisch
  nach `var_export` umgeschrieben.
- Der Produktiv-Cutover kann nicht allein durch einen Screenshot oder
  CI-Tests als 100 % bestätigt werden.

## Dateien

- `IPSAlexaHaussteuerung/resources/helpers/NavigationRuntime.php`:
  aktive Navigation als in-memory-RoomsCatalog-Projektion.
- `IPSAlexaHaussteuerung/src/SystemNavigationMigration.php`:
  Validierung, Quellvergleiche, prüfbare Bereinigung.
- `IPSAlexaHaussteuerung/resources/action_entry.php`:
  Config-Übergabe und Runtime-Projektion für Alexa.
- `IPSAlexaHaussteuerung/module.php`:
  Formular, Migration, Sicherungen, dynamische Domain-Erkennung
  und gezielter Patch für bestehende Action-Skripte.
- `tests/test_system_configuration_v335.php`:
  Aktivierung, veraltete Vorschau, Katalog-Parität, Bereinigung und
  Wiederherstellung in einer simulierten IP-Symcon-Installation.

## Nach erfolgreicher Migration: aufgeräumte Einstellungen

Sobald **alle** folgenden Bedingungen erfüllt sind, vereinfacht sich
die Instanzkonfiguration automatisch:

- Migration wurde über den V3.3.5-Assistenten abgeschlossen
  (`stage=cleaned`, nicht bloß die Navigation in PROD aktiviert).
- `SystemConfiguration` ist produktiv auf `navigation.mode=active`.
- Die ursprünglich importierten Navigationseinträge sind aus
  `RoomsCatalog` **und** `RoomsCatalogEdit` entfernt.
- Beide ursprünglichen RoomsCatalog-Sicherungen existieren noch und
  stimmen mit den gespeicherten Quellprüfsummen überein.
- Die betroffenen Quellskripte stimmen mit dem Migrationsprotokoll
  überein.

Danach werden die einmaligen **V3.3.3-Bestandsaufnahme**, die
**V3.3.4-Import-Schaltflächen** und der **V3.3.5-Migrationsassistent**
im normalen Formular automatisch ausgeblendet.

Stattdessen zeigt die Hauptinstanz nur noch **Navigation & Tabs** –
mit `SystemConfigurationEdit` als Bearbeitungsstand und den Schaltflächen
**Änderungen prüfen** und **Änderungen aktivieren**.
Eine zusammengefasste, zugeklappte Rubrik
**Erweiterte Wartung & Wiederherstellung** enthält den
statusgesicherten Wiederherstellungsknopf.

**Es werden dabei keine Skripte, Backups oder Konfigurationswerte
gelöscht.** Die Änderung betrifft nur die Oberfläche.
Wenn die Abschlussbedingungen nach einem Rollback oder fehlenden
Backups nicht mehr gelten, sind die ursprünglichen Migrationswerkzeuge
wieder sichtbar. Änderungen an echten Geräten und anderen
Systemeinstellungen bleiben dadurch unabhängig von der Navigation.

## Abschlussarbeiten in der Oberfläche (V3.3.7)

Nach dem verifizierten Cleanup gibt es im Hauptmodul keine eigenen
V3.3.3- bis V3.3.5-Konfigurationsbereiche mehr. Die einmaligen
Import-, Bestandsaufnahme- und Cleanup-Schaltflächen sind ausgeblendet.
Die laufende Pflege erfolgt über **Navigation & Tabs** mit den
Aktionen **Änderungen prüfen** und **Änderungen aktivieren**.

Die Tabelle zeigt jetzt nur die tatsächlich benötigten Felder;
Tab-Key, ursprünglicher Quellpfad, interne ID und Prüfsumme bleiben
für die sichere Bearbeitung in den Listendaten erhalten, sind aber im
Alltag verborgen. Die IP-Symcon-Objekt-ID wird als **Integer**
übergeben, damit die Objektauswahl die ID korrekt interpretieren kann.
Eine alte Importfehlermeldung wird bei abgeschlossener Migration nicht
mehr als aktueller Fehler angezeigt; neue Fehlermeldungen bleiben
weiterhin sichtbar.

Die Prüfung/Review-Information und die Datensicherungen werden nicht
entfernt. Die abgeschlossene Migration bestätigt eine technische
Bereinigung, **keine vollständige Alexa-Funktionsprüfung** und noch
keinen 100-%-Gerätedatenstand für den künftigen KIHouseController.

Die Wiederherstellung bleibt unter **Erweiterte Wartung &
Wiederherstellung** erreichbar. Es werden **keine Skripte, Backups
oder Attribute automatisch gelöscht**, da der lokale Backup- und
Migrationsnachweis zum Schutz produktiver Installationen gebraucht wird.
