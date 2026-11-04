# RoomsCatalogConfigurator V3.3.17 – Alle Prüfergebnisse / Zwischenspeicher zurücksetzen

## Neuer Button ganz oben

**ALLE PRUEFUNGEN UND ZWISCHENSPEICHER ZURUECKSETZEN**

Der Button steht als **erstes Bedienelement** im RoomsCatalogConfigurator (vor der bisherigen Katalog-Zusammenfassung) und verlangt eine ausdrückliche Bestätigung.

## Was entfernt wird

Nur flüchtige, im Modul als IP-Symcon-Attribute zwischengespeicherte Analyse-/UI-Daten:
- Migrationsbereitschaft und alte Vergleichsberichte
- Technische Geräteprüfung, Fehlerliste, Markierungen, differenzierte Funktionsumfang-Snapshots
- Vorschauen für technisches Protokoll, manuelle Bestätigung, Massenbearbeitung, Reparatur und Sicherheitsprüfung
- Gespeicherte Inventuren, Discovery-Kandidaten, Inventur-Abdeckungsbericht und Legacy-Vorschauen
- KI-Rollen-Vorschläge und ihre Bestätigungsvorschau
- Temporäre Filter / Mehrfachauswahl und nicht gespeicherte Massenbearbeitungs-Auswahl
- Laufzeit-Caches der Instanz-/Modulauflösung

Der aktuelle Gerätekatalog wird danach direkt aus dem bestehenden EDIT-Skript **nur gelesen** und für die Formularanzeige neu geladen. So kann man sofort erneut mit frischen Prüfungen beginnen.

## Was ausdrücklich NICHT zurückgesetzt wird

- **RoomsCatalogEdit** und **RoomsCatalog PROD** werden weder verändert noch überschrieben.
- Alle bereits im Katalog gespeicherten Geräte, Rollen/Variablenzuordnungen, Räume, Profile, KI-Lese-/Schreibfreigaben, Synonyme und Statuswerte bleiben bestehen.
- Bereits in EDIT gespeicherte technische Prüfmetadaten oder fachliche Bestätigungen bleiben bestehen. Der Reset entfernt **nur deren zwischengespeicherte Anzeigen und Vorschauen**.
- Die Modul-Einstellungen einschließlich Sucheinstellungen/Script-IDs und SystemConfiguration bleiben bestehen.
- Es werden keine Geräte geschaltet und keine Backups gelöscht.

**Wichtig zum aktuellen Datenstand:** Der hochgeladene Diagnoseexport vom 04.11.2026 enthält 167 Geräte mit 129 technisch plausiblen, 38 offen und 13 echten EDIT/PROD-Unterschieden. Darunter sind acht Dimmer mit Änderung `ai.variables.power`, vier Lüfter (einschließlich einer gespeicherten fachlichen Bestätigung) und ein weiterer Dimmer Sauna (im Achterblock enthalten). Diese Änderungen sind echte Skript-Differenzen, keine alten Prüf-Snapshots: Der neue Reset lässt sie **absichtlich unverändert**.

Die beim Update des Reset-Buttons angezeigten Ergebnisse werden erst nach einer bewusst neu gestarteten Geräte-/Migrationsprüfung aktualisiert.

## Technische Absicherung

- Reset verwendet ausschließlich `WriteAttributeString` für zwischengespeicherte Daten und `UpdateFormField` für den aktuellen Dialog.
- Prüfvorschauen und ihre Hash-gebundenen Freigaben werden ungültig; eine alte Reparatur darf nach dem Reset nicht ausgeführt werden.
- Der Wiederaufbau des Editors erfolgt aus den gespeicherten Skripten, aber niemals durch `IPS_SetScriptContent`.
- Der Regressionstest `tests/test_rooms_catalog_v339_technical.php` prüft Buttons, Datenfreiheit, die unveränderten Quellskripte, den geleerten Formularzustand und die Ungültigkeit einer alten Reparaturfreigabe.
