# V3.4.1 – Alexa-Livefragen: Filter, Temperaturwerte und Diagnose

Anlass: Die Alexa-Diagnose vom 04.11.2026 zeigte drei korrekt transkribierte Fragen, die in der V3.4-Auswertung dennoch schlecht beantwortet wurden.

- **„Wie warm ist es im Büro?“**: Der Controller prüft nun auch die bereits ausdrücklich konfigurierte, numerische Legacy-`ist`-Variable eines typisierten Heizkreises, wenn dessen neuer `ai.variables.temperature`-Eintrag noch fehlt. Diese Ergänzung ist nur lesend; sie vergibt keine Schreibrechte.
- **„Welche Fenster sind offen?“**: Der Controller findet Fenster auch dann, wenn das KI-Werkzeug `domain=fenster` liefert, die Geräte aber tatsächlich unter `sensoren/fenster` abgelegt sind. Die explizit konfigurierte boolesche Rolle `ai.variables.open` bedeutet `true=offen`, `false=geschlossen`; ein allgemeines `status`-Feld wird ohne bestätigte Zuordnung nicht umgedeutet.
- **„Welche Jalousien sind zu?“**: Statt erst eine Inventarliste (`get_devices`) abzurufen und im zweiten Aufruf einen weiteren Werkzeugaufruf zu verlangen, wird bei klaren Leseanfragen gleich `get_states` gewählt. Die zweite Anfrage fordert ausdrücklich eine Sprachantwort ohne weitere Werkzeuge. Jalousien mit unklarem Status werden als unbekannt ausgewiesen; keine pauschale Annahme aus der Position 0 oder 100.

## Neue Diagnose im vorhandenen Alexa-Log-Puffer

`KIRoomsCatalog.catalog.loaded` enthält weiterhin die Zahl der KI-Geräte, zusätzlich die erkannten Typen und Heizgeräte mit lesbarer Temperatur.

`KIRoomsCatalog.tool` zeigt nun `matched`, `shown`, `truncated`, `filtersUsed`, `temperatureAvailable` und `statusSummary` mit `offen`, `geschlossen`, `unbekannt`. So lassen sich insbesondere **„nicht gefunden“**, **„keine Rolle zugeordnet“** und **„kein Fenster offen“** voneinander unterscheiden. Es werden keine zusätzlichen Passwörter oder IP-Symcon-Steuerwerte geloggt.

Die automatische Filterergänzung ist auf **Leseanfragen** begrenzt. Räume werden nur aus vollständig genannten, eindeutig zuordenbaren Raum-/Aliasnamen übernommen. Die bestehende Alexa-/APL-Navigation sowie `SystemConfiguration`, `RoomsCatalog`, `RoomsCatalogEdit` und Backups werden nicht verändert.

## Update und Live-Test

In IP-Symcon das Modul über Module Control aktualisieren und in der Instanz **Übernehmen** drücken. Danach zuerst die drei Fragen von oben erneut stellen und den Alexa-Meldungspuffer kopieren. Besonders wichtig ist nun `filtersUsed` und die Zahl `matched`. Sind keine passenden Werte vorhanden, muss die Gerätekonfiguration im EDIT-Katalog geprüft und nach PROD übernommen werden – der Code erfindet keine Daten.

Test: `php tests/test_ki_rooms_catalog_v34.php` zusammen mit allen bestehenden CI-Regressionen.
