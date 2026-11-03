# V3.3.16 – EDIT/PROD-Unterschiede im Gesamtdiagnose-Export

## Anlass
Nach der Reparatur von 13 technisch eindeutigen KNX-Zuordnungen und Einführung der reinen Jalousie-Diagnose zeigt die neue Migrationsprüfung:

- 167 von 167 Geräten typisiert
- 154/167 KI-freigegeben: 147 nur lesen, 7 schaltbar
- 1 fachlich bestätigt, 166 noch unbestätigt
- 25 fehlende Ist-Rückmeldungen für Jalousien (Steuerkommandos sind kein Positionsnachweis)
- 13 EDIT/PROD-Unterschiede
- 0 Legacy, 0 unbekannte Typen, 0 übrige Tabs

**13 Unterschiede sind ein eigenständiger Befund**, nicht mit den 25 fehlenden Rückmeldungen zu verwechseln. Möglicherweise stammen sie aus Änderungen, die bewusst nur in EDIT gespeichert wurden, etwa technisches Prüfprotokoll oder eine fachliche Bestätigung. Ein älterer technischer Snapshot mit 0 Unterschieden kann inzwischen veraltet sein.

## Ergänzung am bestehenden Diagnose-Button
Der Button `1 – GESAMTDIAGNOSE FUER CHATGPT HERUNTERLADEN` erzeugt weiterhin die komplette Geräteanalyse. Zusätzlich beinhaltet die JSON-Datei:

`editProdComparison`:
- `total`: Anzahl unterschiedlicher Geräte
- `counts`: neue, entfernte und veränderte Geräte
- `items`: pro betroffenem Gerät `path`, `room`, `device`, `type` sowie `changedFields`

`changedFields` enthält **nur die Namen der geänderten Felder** bis auf Ebene `ai.review.status`, `ai.variables.control` oder `ai.review.technical`, niemals alte/neue Werte oder den PHP-Quelltext. Bei mehr als 60 Feldnamen pro Gerät ist `fieldsTruncated` gesetzt.

Die Funktion liest nur Metadaten und Quelltext-Hashes. Keine KI-Berechtigungen, Live-Variablen, Zugangsdaten oder Katalogskripte werden verändert.

## Sicherheitsregel
**Button 2 nicht bei bestehenden EDIT/PROD-Unterschieden ausführen.** Die vorhandene Implementierung lehnt eine Reparatur ab, wenn die Skriptinhalte nicht identisch sind.

Vor einer produktiven Übernahme die **13 einzelnen Differenzen anhand des Exports** prüfen und entscheiden, ob sie nachvollziehbare Prüfmetadaten oder unerwünschte Feldänderungen sind. Eine technisch plausible Zuordnung ersetzt niemals die fachliche Prüfung am echten Gerät. Die eine vorhandene fachliche Bestätigung darf nicht ohne gesonderte Prüfung automatisch zurückgesetzt oder auf andere Geräte übertragen werden.
