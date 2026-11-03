# RoomsCatalog: Filter-Reparatur und Migrationsprüfung vor V3.4

## Hintergrund
Durch V3.3.5 wurden die Navigationseinträge (`tabs`) aus
`RoomsCatalog` und `RoomsCatalogEdit` entfernt. Ein bereits geöffneter
RoomsCatalogConfigurator kann noch den alten Gruppenfilter `tabs`
gespeichert haben und ein veraltetes `RuntimeEntries`-Abbild anzeigen.
Das führte zu einer leeren Liste und der Meldung
„Aktueller Wert 'tabs' ist nicht verfügbar“.

## Änderung
- Der Konfigurator prüft vor jeder Anzeige den SHA-256-Hash des
  tatsächlichen EDIT-Skripts. Nach externen Änderungen lädt er die
  Einträge neu und verwirft ausschließlich alte Auswahlmarkierungen.
- Raum-, Bereichs- und Gruppenfilter werden aus **aktuellen EDIT-Geräten**
  aufgebaut, nicht aus historischen PROD-Tabs. Ungültige gespeicherte
  Filter werden auf `Alle` zurückgesetzt.
- Bereiche und Gruppen im Filter richten sich nach dem gewählten Raum
  und Bereich. Die Dropdowns zeigen nur dort vorkommende Gruppen;
  sie sind kein Katalog aller möglichen Gerätearten.
- Kein einziges Gerät wird dabei verändert oder gelöscht.

## KI-Status richtig lesen
Die bisherigen Zahlen „KI sichtbar: 154, KI schaltbar: 7“ sagen
ausschließlich etwas über die konfigurierten **Berechtigungen** aus.
Die neue Darstellung unterscheidet **nur lesen**, **schaltbar** und
**ohne KI-Freigabe**. Die Gerätetypisierung (`ai.type`), das
bestätigte Variablenmapping (`ai.review.status=confirmed`) und
die Vollständigkeit der IPS-Variablen sind davon unabhängig.
Ein Gerät mit `kiEnabled=false` wird **nicht automatisch freigegeben**.

## Migrationsstatus
Der Button **MODELL, IPS-VARIABLEN UND MIGRATION PRÜFEN** erstellt
einen ausschließlich lesenden Bericht:
- Geräte mit und ohne typisiertes `ai`-Modell,
- `generic`-Typen, die einer manuellen Einordnung bedürfen,
- unbestätigte Variablenzuordnungen,
- fehlende Rückmeldungsrollen und nicht existierende IPS-Variablen,
- EDIT/PROD-Abweichungen und übrig gebliebene `tabs`.

Eine fehlende oder nicht nachweislich vollständige Geräteinventur
wird ausdrücklich genannt. **„Katalog-Modell technisch konsistent“ ist
keine Garantie für 100 % reale Gerätedaten und kein Alexa-Livetest.**

## Aufgeräumte Oberfläche
V3.1-Raumverwaltung bleibt als **Räume & Etagen** bestehen, weil man
auch künftig Räume umbenennen muss. Die laufende Mehrfachbearbeitung
bleibt ebenfalls sichtbar. Variablensuche, IP-Symcon-Inventur und – falls
noch Legacy-Geräte vorhanden – die Legacy-Migration befinden sich unter
**Geräte erkennen, inventarisieren und reparieren**. Dieser Bereich ist
standardmäßig zugeklappt. Wenn kein Legacy-Gerät im aktuellen EDIT
mehr vorhanden ist, wird der Legacy-Importassistent nicht mehr angezeigt.
Erkennung und Analyse bleiben für **neu hinzukommende Geräte** erreichbar.

Weder Daten, Moduleinstellungen, Skripte noch Sicherungen werden
automatisch gelöscht. V3.4 darf erst beginnen, wenn die offenen
Prüfpunkte am tatsächlichen IP-Symcon-Bestand geklärt sind.
