# RoomsCatalogConfigurator V3.3.2 – Geräte gemeinsam bearbeiten

Die bisherige Geräteliste bietet jetzt drei bewusste Auswahlmöglichkeiten:

- **ALLE SICHTBAREN MARKIEREN:** berücksichtigt Raum-, Bereichs-, Gruppen- und KI-Filter; bereits markierte, aber ausgefilterte Geräte werden abgewählt.
- **ALLE GERÄTE MARKIEREN (AUCH GEFILTERTE):** wählt alle Einträge des aktuellen EDIT-Bestands, einschließlich nicht sichtbarer Geräte.
- **MARKIERUNGEN AUFHEBEN:** hebt die gesamte Auswahl wieder auf.

Die neue Gruppe **V3.3.2: Markierte Geräte gemeinsam bearbeiten** zeigt die Anzahl markierter Geräte und die Eigenschaften, die bei allen gleich sind (Raum, Bereich, Gruppe, Gerätetyp, KI-Freigaben).

## Sammeländerungen

1. Geräte gezielt markieren. Der Filter hilft bei einer Auswahl, z. B. nur die Heizung.
2. Unter **Gerätetyp für alle Markierten** einen gemeinsamen Typ auswählen. **Nicht ändern** lässt die vorhandenen Typen bestehen.
3. Unter **KI-Freigabe** optional `Ohne KI`, `Mit KI – nur abfragen` oder `Mit KI – Schalten erlauben` auswählen. Der Standard **Nicht ändern** bewahrt alle vorhandenen KI-Rechte.
4. Unter **Fehlende Variablen** lässt sich einstellen, ob der Konfigurator je Gerät nur eindeutig zugeordnete, noch leere Variablenfelder automatisch ergänzen soll. Er überschreibt keine vorhandenen Variablen-IDs.
5. Optional den Prüfstatus aller markierten Geräte auf **offen** setzen. Bei einem geänderten Gerätetyp geschieht das automatisch.
6. **SAMMELBEARBEITUNG VORSCHAU** kontrollieren und anschließend die ausdrücklich bestätigte Aktion auf `RoomsCatalogEdit` anwenden.

## Schutzmaßnahmen

- Änderungen erfolgen **nur in `RoomsCatalogEdit`**. `RoomsCatalog` und `RoomsCatalog_Backup` werden nicht durch die Sammelbearbeitung geschrieben.
- Geräte-Keys, Raum, Bereich/Gruppe, Namen, Legacy-Felder und vorhandene IDs bleiben bei diesen gemeinsamen Bearbeitungen individuell erhalten. Ein Massen-Typwechsel verschiebt bestehende Legacy-Geräte nicht unbemerkt in einen anderen Katalogpfad.
- Die Vorschau wird ungültig, wenn sich inzwischen die Auswahl, die Bearbeitungsoptionen, das EDIT-Skript oder ihr Alter (maximal zehn Minuten) geändert haben.
- Vor dem Speichern wird der Katalog auf verlorene Felder geprüft. Bei Fehlern wird die Übernahme komplett abgebrochen.
- KI-Schreibrechte können nur durch die ausdrücklich gewählte Einstellung und eine zusätzliche Bestätigung vergeben werden.
- Der Status **geprüft** kann nicht pauschal für alle Geräte gesetzt werden: Jede tatsächliche Zuordnung muss später individuell bestätigt werden. V3.4 erst nach erfolgreicher Vollständigkeitsprüfung beginnen.

## Beispiel

Bereichsfilter `heizung` → **ALLE SICHTBAREN MARKIEREN** → Gerätetyp `Heizung` → KI-Freigabe `Nicht ändern` → eindeutige Variablenvorschläge einschalten → Vorschau → nach EDIT übernehmen.

Die Räume, alten `ist`-/`soll`-IDs, Namen und bisherigen KI-Freigaben bleiben dabei je Heizung unterschiedlich und korrekt erhalten.
