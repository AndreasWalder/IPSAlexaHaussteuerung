# RoomsCatalogConfigurator V3.2 — intelligente Variablenvorschläge

V3.2 erweitert die bekannten V3.0-/V3.1-Editoren um eine geführte Zuordnung von IP-Symcon-Variablen. **Die vorhandenen Geräte und Schaltrechte werden nicht automatisch geändert.** Neue Bindungen entstehen nur nach ausdrücklichem Bestätigen in `RoomsCatalogEdit`.

## So benutzt du die Vorschläge

1. Modul in IP-Symcon aktualisieren und **Übernehmen** klicken.
2. Im Bereich **Geräte bearbeiten (RoomsCatalog EDIT)** genau ein Gerät in der Spalte **Markiert** auswählen.
3. Im neuen Bereich **V3.2: Intelligente Variablenvorschläge** auf **MARKIERTES GERÄT ANALYSIEREN** klicken.
4. Die Vorschau prüfen: Eigenschaft, vorhandene ID, vorgeschlagene IP-Symcon-ID, Variablenname, Profil, Bewertung und Begründung.
5. **EINDEUTIGE VORSCHLÄGE NACH EDIT ÜBERNEHMEN** übernimmt ausschließlich eindeutige, noch nicht konfigurierte Eigenschaften. Unklare Zuordnungen weiterhin im bestehenden V3.0-Geräteeditor manuell festlegen.
6. Bei Bedarf die V3.0-KI-Variablen und insbesondere die Jalousie-Offen/Geschlossen-Bedeutung kontrollieren. Erst dann die üblichen Schritte **KATALOG PRUEFEN** und **EDIT NACH PROD UEBERNEHMEN** ausführen.

## Analyseprinzip

- Anker sind bereits bekannte Objekt-/Variablen-IDs des gewählten Geräts (`state`, `toggle`, `wert`, `ist`, `soll` oder vorhandene `ai.variables`).
- Die Suche bleibt auf die zugehörige **Instanz** begrenzt. Gibt es keine Instanz, wird nur die **direkt übergeordnete Kategorie** untersucht. Darunterliegende Kategorien werden begrenzt mitgeprüft; fremde Geräteinstanzen werden nicht durchsucht.
- Vorschläge verwenden Variablennamen, Objekt-Ident, Profil, Datentyp und das Vorhandensein einer aktiven Schaltaktion. Ein Profil alleine reicht nie für eine automatische Übernahme.
- Eindeutige Treffer erhalten die Bewertung **Hoch**. Bei ähnlich guten Alternativen erscheint **Prüfen**, eine automatische Übernahme ist ausgeschlossen.
- Schon belegte Eigenschaften bleiben unverändert. Beim späteren Übernehmen werden alle vorgeschlagenen IDs noch einmal gegen die aktuellen IP-Symcon-Objekte geprüft.
- Die Vorschau gilt höchstens zehn Minuten. Nach Änderung des Geräts muss neu analysiert werden.

## Was V3.2 bewusst nicht macht

- Kein systemweiter Geräte-Scan; dieser ist für **V3.3 – automatische Geräteerkennung** vorgesehen.
- Keine automatische Bestätigung von Boolean- oder Jalousie-Positionssemantik (`0 = offen` oder `100 = offen` ist installationsabhängig).
- Keine Freigabe von KI-Schreibrechten, keine Schaltaktion und kein direkter PROD-Schreibzugriff.
- Noch keine produktive Nutzung der zusätzlichen `ai`-Variablen durch den KIHouseController; diese folgt in **V3.4**.

`RoomsCatalog`, `RoomsCatalogEdit`, `RoomsCatalog_Backup_*`, `global`, Raum-Aliase, ältere Gerätefelder und zusätzliche Daten bleiben erhalten.

Regressionstest: `php tests/test_rooms_catalog_v32_suggestions.php` (neben sämtlichen bisherigen CI-Tests).
