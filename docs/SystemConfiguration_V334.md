# V3.3.4 – Navigation & Tabs im Hauptmodul bearbeiten (nur EDIT)

Die **27 in V3.3.3 erkannten Navigationseinträge** können jetzt in der
Instanzkonfiguration von `IPSAlexaHaussteuerung` verwaltet werden. Es
gibt bewusst weiterhin zwei Datenbestände: Die bestehenden `tabs`
bleiben bis zum produktiven Migrationsschritt V3.3.5 im RoomsCatalog;
die neuen Einträge werden als **Entwurf** in `SystemConfigurationEdit`
gepflegt. Der neue Editor steuert noch keine Alexa-Navigation.

## Bedienung

1. Das Modul `IPSAlexaHaussteuerung` in der IP-Symcon-Modulverwaltung aktualisieren
   und die Instanzkonfiguration öffnen.
2. Bereich **V3.3.4 – Navigation und Tabs verwalten (SystemConfigurationEdit)**.
3. **Bestandsvorschau anzeigen** anklicken. Die Vorschau zeigt Tab-Anzahl,
   EDIT-Skript-ID und Quellskript-ID. Sie ist zehn Minuten gültig und wird
   bei Änderungen an einem der Skripte automatisch ungültig.
4. **Geprüfte Tabs nach EDIT importieren** bestätigen. Dadurch erhält das
   bereits existierende `SystemConfigurationEdit` den zusätzlichen
   Abschnitt `navigation`. Alle bestehenden `var`, `script`,
   `missing`, `rendererDomains`, `launchCatalog` und unbekannte
   Felder bleiben als Werte erhalten. **Weder PROD noch RoomsCatalog
   werden geändert.**
5. Unter **Navigation & Tabs – nur SystemConfigurationEdit** lässt sich
   mit dem Zahnradsymbol je Eintrag der Anzeigename, Raum, Bereich,
   Objektverweis, die Sortierung, Synonyme und Aktiv-Status bearbeiten.
   Der ursprüngliche Tab-Key und der alte RoomsCatalog-Quellpfad bleiben
   als unveränderbare Referenz gespeichert.
6. Mit **Neuen Tab nur in EDIT anlegen** lassen sich neue Navigationsverweise
   für bekannte Räume erzeugen. Der technische Key wird automatisch aus
   dem Titel abgeleitet; bei gleichen Namen wird ein eindeutiger Key erzeugt.
7. **EDIT neu laden** synchronisiert die Tabellenansicht mit dem
   aktuellen Skriptinhalt.

Ein nicht mehr gewünschter Eintrag wird über **Aktiv = aus** deaktiviert,
nicht aus dem Entwurf gelöscht. Auch deaktivierte Einträge behalten
ihre ursprüngliche Definition für die spätere geprüfte Migration.

## Sicherung & Schutz

- `SystemConfiguration` (PROD) und `RoomsCatalog` werden **nie** von
  diesem Editor beschrieben. Auch die Renderer bleiben unverändert.
- Vor jedem echten EDIT-Schreibvorgang wird der bisherige PHP-Quelltext
  im lokalen `SystemConfiguration_Backup` protokolliert. Die erste
  PROD-Sicherung aus V3.3.3 bleibt dauerhaft erhalten; danach werden
  bis zu zwölf jüngere EDIT-Zwischenstände aufbewahrt.
- Eine alte Importvorschau oder eine veraltete Tabellenzeile kann
  keine neueren Bearbeitungen überschreiben.
- Die EDIT-Quelle muss ein **einfaches PHP-Literal-Array** mit `return`
  sein; benutzerdefinierter ausführbarer PHP-Code wird nicht automatisch
  umgeschrieben. Dann bleibt die Datei unverändert und die Oberfläche
  zeigt einen Abbruchhinweis.
- Unbekannte zusätzliche Eigenschaften (auch an Navigationseinträgen)
  bleiben erhalten; sie werden nicht als Felder gerendert oder gelöscht.
- Zugangsdaten und Werte der Systemkonfiguration werden nicht in der
  Navigationstabelle, im Vorschau-Attribut oder in GitHub ausgegeben.

## Zuständigkeit der Konfigurationen

| Bereich | Bearbeitungsort |
| --- | --- |
| Räume, echte Geräte und IPS-Variablen | `RoomsCatalogConfigurator` |
| Navigation, Tabs und deren Quellobjekte | `IPSAlexaHaussteuerung` → V3.3.4 Editor → EDIT |
| KI, Alexa, Visualisierung, PageMappings, Renderer und Hauptkacheln | Vorhandene Bereiche der Instanzkonfiguration |
| Automatisch ermittelte System-IDs, Runtime-Skripte | `SystemConfiguration` (Bestandsaufnahme / künftige zentrale Einstellungen) |
| Produktive Konfigurationsdaten | `SystemConfiguration` (PROD; **noch nicht migriert**) |
| Sicherung | `SystemConfiguration_Backup` |

## Grenzen

V3.3.4 bietet die bearbeitbare Navigationstabelle **nur für EDIT**.
Weder Geräte-Tabs im RoomsCatalog noch deren Alexa-/APL-Routen werden
bereits entfernt oder umgelenkt. Die Konsolidierung von doppelten
Modulparametern, vollständige Bearbeitung aller sonstigen Systemwerte
und die sichere Live-Umstellung erfolgen schrittweise in V3.3.5,
nicht als versteckte Nebenwirkung dieser Änderung.

Vor V3.4 folgt der Abgleich zwischen den gespeicherten EDIT-Entwürfen,
den 27 Live-Tabs und den bekannten Modulseiten mit expliziter
Vorschau, getesteten Fallbacks und PROD-Backup.
