# V3.3.3 – SystemConfiguration: sichere Vorbereitung der Navigationstrennung

Die bisherigen `tabs` im `RoomsCatalog` sind Navigationsverweise und keine
steuerbaren Geräte. Sie bleiben für bestehende Alexa-Renderer und
Navigationsrouten **vorerst unverändert**. V3.3.3 führt noch **keine
Migration oder Produktivübernahme** aus.

## Neue Skripte unter IPSAlexaHaussteuerung / Einstellungen

| Skript | Verwendung | Verhalten beim Modulupdate |
| --- | --- | --- |
| `SystemConfiguration` | Bestehende produktive Einstellungen | Wird wie bisher nur bei fehlendem/leerem Skript erstellt; bestehende Konfiguration bleibt erhalten |
| `SystemConfigurationEdit` | Künftige Bearbeitungsbasis | Beim ersten Mal exakte Kopie des aktuell verwendeten PROD-PHP-Quelltexts; danach nie automatisch überschrieben |
| `SystemConfiguration_Backup` | Sicherung vor der künftigen Migration | Beim ersten Mal eine vollständige Quelltext-Sicherung mit Zeitstempel, Quell-Skript-ID und SHA-256; danach nie automatisch überschrieben |

Eine bereits im Modul hinterlegte alternative `SystemConfigScriptId` wird
beim initialen Kopieren nach EDIT bevorzugt, sofern das Skript existiert.
Alle Skripte bleiben auf dem eigenen IP-Symcon-Server. Der Inhalt wird nicht
nach GitHub hochgeladen oder in Diagnosevariablen veröffentlicht.

## Bestandsaufnahme im Modul

1. Das Modul über IP-Symcon aktualisieren.
2. Unter `IPSAlexaHaussteuerung / Einstellungen` die drei Skripte prüfen.
3. Modulkonfiguration öffnen und den Abschnitt
   **V3.3.3 – SystemConfiguration und Navigation (Bestandsaufnahme)** öffnen.
4. **Systemeinstellungen und Tabs einlesen** klicken.

Die Liste zeigt Raum, Bereich, Navigationstitel, Objekt-ID und alten
RoomsCatalog-Pfad. Auch Tabs unter Sonderbereichen (beispielsweise
`global/robot/tabs` oder `Heizraum/devices/tabs`) werden erfasst.
Ein unbekannter Wert bleibt als prüfbedürftig markiert. Der Scanner
schreibt weder in `RoomsCatalog`/`RoomsCatalogEdit` noch in
`SystemConfiguration`/`SystemConfigurationEdit`.

Die Bestandsaufnahme zeigt nur Strukturinformationen, keine API-Keys,
Passwörter oder Inhalte des `SystemConfiguration`-Skripts. Ein rein
internes, versioniertes Navigations-Entwurfsmodell erhält die alte
Rohdefinition samt Quellpfad für einen späteren verlustfreien Import.

## Bewusste Grenzen von V3.3.3

- Keine doppelten Tab-Stammdaten oder neue produktive Datenquelle.
- Keine Navigation aus `RoomsCatalog` entfernen oder umleiten.
- Noch kein EDIT-zu-PROD-Knopf für `SystemConfiguration`.
- Die Verwaltung weiterer Modul-Einstellungen sowie die Bearbeitung der
  Navigation folgen erst in V3.3.4, nach Bestätigung der Bestandsaufnahme.
- V3.4 (KIHouseController-Auswertung) bleibt bis dahin ausgesetzt.

Die CI prüft das genaue Kopieren von benutzerdefiniertem PHP, die
Initialsicherung, idempotente Modulupdates, Tab-Erkennung und den
Ausschluss geheimer Werte aus der Inventarausgabe.
