# RoomsCatalogConfigurator V3.3.18 – Bereichsweise PROD-Übernahme

## Problem

Die bisherige Aktion **EDIT NACH PROD UEBERNEHMEN** prüft ALLE Geräte im gesamten EDIT-Katalog. Schon eine einzige KI-freigegebene Jalousie ohne echte eigene Offen/Geschlossen-Rückmeldung blockiert jede Änderung in anderen Bereichen. Auch technisch unklare Dimmer-/Lüfterrollen sollen andere Geräte nicht blockieren.

## Neue Funktion im unteren Aktionsbereich

**BEREICHSWEISE PROD-UEBERNAHME – NUR GUELTIGE GERAETE**

1. Bei **Bereich** beispielsweise `licht`, `lueftung`, `heizung` oder **ALLE GUELTIGEN BEREICHE OHNE JALOUSIE** wählen.
2. **1 – GUELTIGE AENDERUNGEN IM BEREICH PRUEFEN** ausführen.
3. In der Tabelle je geändertem Gerät sehen, welche Zuordnung *gültig* beziehungsweise *gesperrt* ist – mit dem genauen Grund.
4. **2 – NUR GUELTIGE AENDERUNGEN NACH PROD** bestätigen.

Die Vorschau ist 10 Minuten gültig und an den EDIT/PROD-Quelltext-Hash, die IDs, den gewählten Bereich und die erneut nachgewiesenen IP-Symcon-Metadaten gebunden. Bei einer Veränderung wird neu geprüft, nicht automatisch weitergeschrieben.

### Was wird übernommen?

**Nur neue/geänderte Geräteblätter** aus EDIT, wenn **beide** Voraussetzungen erfüllt sind:
- bestehende Katalogvalidierung `validateEntries` erfolgreich (eindeutige IP-Symcon-IDs, unterstützte KI-Geräteklasse, echte Schreibaktion bei vorhandener Schaltfreigabe und sonstige gerätespezifische Prüfungen)
- technische Geräteprüfung `assessTechnicalDevice` meldet **Technisch plausibel** auf Basis der typisierten `ai.variables` und echten IP-Symcon-Variablenmetadaten

Innerhalb des ausgewählten Bereichs werden weiterhin problematische Einzelgeräte ausgeschlossen. Ein unzureichender Dimmer sperrt **nicht** ein anderes gültiges Lichtgerät derselben Domain.

### Jalousien

Mit der Voreinstellung **alle gültigen außer Jalousien** werden *keine* Jalousien übernommen. Wird explizit `jalousie` gewählt, gilt dieselbe strenge technische Gerätevalidierung sowie zusätzlich die Bedingung, dass eine eigenständige echte `position`- oder `status`-Variable vorhanden ist und **nicht** identisch mit der Fahraktion sein darf.

**Nicht erlaubt:** 0=Öffnen, 100=Schließen, 999=Stop als tatsächlichen Istzustand umzudeuten. Fehlt eine Rückmeldung oder eine eindeutige Offen/Geschlossen-Zustandsabbildung, wird das Gerät als **gesperrt** aufgeführt und nicht nach PROD geschrieben.

### Was bleibt unberührt?

- Alle anderen Bereiche und nicht gewählte Geräte bleiben bytegenau in ihrem ursprünglichen Konfigurationsbaum erhalten.
- Gesperrte Geräte und vorhandene PROD-Geräte, die in EDIT entfernt wurden, bleiben in PROD erhalten (Teilübernahme löscht nie).
- Raum-/Etagen-/Alias-Metadaten werden nicht automatisch übernommen.
- **EDIT wird nicht geschrieben**, nur ausgewählte, valide Gerätedatensätze werden in PROD aktualisiert.
- Neue KI-Schaltfreigaben (Übergang von KI-aus/lesen zu Schreiben) werden durch diese Teilübernahme **nicht** aktiviert. Solche Berechtigungsänderungen benötigen den ausdrücklich freigegebenen Komplett-Workflow.
- Bereits bestätigte Gerätezuordnungen in PROD können nicht durch eine unbeabsichtigte `pending`-Version aus EDIT überschrieben werden.
- Das bisherige Komplett-Publishing bleibt mit seiner vollständigen Validierung als ausdrücklich gekennzeichneter zusätzlicher Button erhalten.

### Sicherheitsprüfungen

- Vor jedem Schreiben wird ein vollständiges Backup des **aktuellen PROD-Skripttexts** erstellt und lesend verifiziert.
- Vorschau/Live-Quellen/Plan werden unmittelbar vor dem Commit nochmals verglichen.
- Der geschriebene PROD-Quelltext wird verifiziert; bei Fehler wird die Originalversion wiederherzustellen versucht und der Fehler gemeldet.
- Beim Zurücksetzen der Prüf-Zwischenspeicher wird auch die Bereichsvorschau ungültig.
- Nach erfolgreicher Übernahme zeigt die bestehende EDIT/PROD-Differenzliste nur die übrig gebliebenen tatsächlichen Unterschiede an.

## Beispiel auf Basis der aktuellen Diagnose

Zuletzt: 167 Geräte, 129 technisch plausibel, 38 offen, 13 EDIT/PROD-Geräteänderungen. Davon neun Dimmer mit `ai.variables.power`-Unstimmigkeiten und vier Lüfter (Statusabbildungen und Bestätigungsmetadaten). Die bereichsweise Vorschau entscheidet **live**, welche der geänderten Geräte tatsächlich valide sind. Sie rät keine Profilzuordnungen und kann folglich bei `licht` auch `0 gültig` melden – das wäre richtig, wenn alle betroffenen Geräte unplausibel sind.

### Regressionstest

`tests/test_rooms_catalog_v339_technical.php` deckt ab:
- Gesamt-Publishing weiterhin durch ungültige Jalousie blockiert
- gültiges Lichtgerät im gewählten Bereich publiziert
- ungültiges Licht und Löschung im gleichen Bereich übersprungen
- Jalousien bei ungültigem Status vollständig von PROD ausgeschlossen
- **alle ohne Jalousien** übernimmt einen gültigen weiteren Bereich
- EDIT unverändert, vollständiges PROD-Backup, ursprüngliche Raum-/Jalousiedaten erhalten
- Hash-Schutz bei konkurrierender Änderung, Bereichswechsel und Reset
