# V3.4.5 – Alexa-KI mit universeller RoomsCatalog-Abfrage

## Grundidee: KI plant, IP-Symcon liefert Fakten

Es werden **keine einzelnen Sonderregeln für natürliche Sätze** wie „Welcher Raum ist am wärmsten?“ programmiert. Stattdessen bekommt die KI:

1. Ein aktuelles, kompaktes Schema der freigegebenen RoomsCatalog-Gerätetypen, messbaren Eigenschaften und Räume.
2. Das universelle, ausschließlich lesende Werkzeug `query_house`.
3. Die bereits vorhandenen Werkzeuge `get_states`, `get_devices`, `set_device` und `set_multiple` (Schreibrechte bleiben unverändert und sind separat geschützt).

Die KI übersetzt freie Sprachfragen in deklarative Parameter. PHP beschränkt sich darauf, die **von der KI ausgewählte Datenoperation korrekt und reproduzierbar** auf freigegebenen Istwerten auszuführen. Es gibt keinen frei ausführbaren PHP-Code, keine SQL-Eingabe und keine freien IPS-IDs.

## Universelles Query-Werkzeug

`query_house` unterstützt:

- `property`: eine vorhandene Eigenschaft aus der zentralen `RCCDeviceTypeRegistry` (z. B. `temperature`, `powerWatts`, `open`, `humidity`, `brightness`, `energy`) oder das **abgeleitete** Lesefeld `activity` (aus Ist-Schalter, Helligkeit oder Bewegung, nie aus Steuerbefehlen).
- `operation`: `list`, `count`, `min`, `max`, `avg`, `sum`.
- `group_by`: `device`, `room`, `none`.
- `condition`: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `active`, `inactive` sowie optional `value`.
- Filter `room`, `rooms` (mehrere Räume), `floor`, `type`, `domain`, `group`, `name`, `deviceKey`.
- `indoor_only`: Wetterstationen und deutlich als Außenbereich bezeichnete Räume ausschließen, wenn der Nutzer nach **Innenräumen** fragt.
- `limit`: wie viele Ergebniszeilen die KI sehen soll. Anzahl/Extremwert werden **vor** der Begrenzung aus allen Treffern gebildet; fehlende Daten sind weder 0 noch „aus“.

Für `group_by=room` bedeutet `min`/`max`: zuerst je Raum den Mittelwert **unterschiedlicher IP-Symcon-Sensorvariablen** bilden, danach die Raum-Mittelwerte vergleichen. Ein einzelner stark erwärmter Sensor dominiert damit nicht mehrere Sensoren eines Raums. Die doppelte Verknüpfung derselben IPS-Variablen erhöht nicht ihr Gewicht.

**Keine Schätzung**: Wenn die zur Frage notwendige `ai.variables`-Zuordnung fehlt oder nicht lesbar ist, meldet das Werkzeug eine fehlende Eigenschaft statt eines erfundenen Wertes.

## Beispiele: alle mit derselben Abfragearchitektur

| Alexa-Frage | KI-Werkzeugparameter |
| --- | --- |
| Welcher Raum ist am wärmsten? | `property=temperature, operation=max, group_by=room, indoor_only=true` |
| Welcher Raum ist am kältesten? | `property=temperature, operation=min, group_by=room, indoor_only=true` |
| Wie warm ist es im Wohnzimmer? | `property=temperature, operation=list, room=Wohnzimmer` |
| Wie ist die Temperatur in Küche und Büro? | `property=temperature, operation=avg, group_by=room, rooms=[Küche,Büro]` |
| Welche Lichter sind eingeschaltet? | `property=activity, operation=list, domain=licht, condition=active` |
| Wie viele Lichter sind aus? | `property=activity, operation=count, domain=licht, condition=inactive` |
| Wie viele Fenster sind offen? | `property=open, operation=count, type=window, condition=eq, value=offen` |
| Wo ist die höchste Luftfeuchtigkeit? | `property=humidity, operation=max, group_by=room` |
| Wie hoch ist die gesamte gemessene Leistung? | `property=powerWatts, operation=sum, group_by=none` |

Beispiele dienen nur zur **Anleitung des Sprachmodells**. Es gibt keinen Code-Zweig, der die jeweiligen Sätze abfängt. Andere Kombinationen aus Eigenschaft, Operation, Bedingungen und Räumen werden mit demselben Werkzeug ausgeführt.

## KI-Planung und Korrektur

Die Eingabe enthält die echten verfügbare Geräte-/Eigenschaftsanzahlen. Die KI bekommt `query_house` als bevorzugtes Lesewerkzeug statt der bisherigen erzwungenen `get_states`-Rohdatenabfrage.

Zum Beispiel fand das Sprachmodell am **04.11.2026** bei „Welcher Raum ist der wärmste?“ null Geräte, weil es `domain=temperature` oder `type=Temperatur` erfand, obwohl 16 freigegebene Heizungsgeräte mit Isttemperatur existierten. Ein nicht unterstützter `type` erzeugt jetzt eine strukturierte Fehlermeldung samt tatsächlich vorhandenen Typen; die KI kann die Parameter korrigieren.

Zwei Modellrunden reichen normalerweise (Werkzeugaufruf + natürlich formulierte Antwort). Eine dritte Runde ist möglich, damit die KI fehlerhafte Abfrageparameter einmal korrigieren kann. Das bestehende Alexa-Zeitbudget wird weiterhin eingehalten; zusätzliche Modellaufrufe erhöhen Laufzeit und API-Kosten.

Die Angabe `VariableChanged` zur letzten echten Wertänderung wird weiterhin **nur auf ausdrückliche Nachfrage** bereitgestellt.

## Sicherheit und Kompatibilität

- `query_house` ist **nur lesend**. Schreibaktionen bleiben in den bestehenden, geprüften Funktionen. Es gibt keine zusätzliche KI-Schaltberechtigung.
- Der RoomsCatalog, RoomsCatalogEdit, RoomsCatalog_Backup, bekannte Gerätetypen und bestehende Zuordnungen bleiben unverändert.
- `get_states` und `get_devices` bleiben für Abwärtskompatibilität bestehen.
- Die Query-Engine ist in `resources/helpers/HouseQueryEngine.php`. Beim Installieren bindet das Alexa-Modul sie in die auf dem Server erzeugte `KIHouseController`-Skriptkopie ein; das erfordert ein **Modul-Update und Übernehmen**.
- Keine zusätzlichen Alexa-Intents. Die Intents erfassen weiterhin freie Fragen; die KI plant deren Bedeutung.
- Der Komponententest `tests/test_ai_house_query_v345.php` prüft echte/alte Heizungsdaten, Sensoren je Raum, mehrfache IPS-ID, Fenster, Licht/Dimmer, Summe, fehlerhafte Modellparameter, fehlende Werte und Schaltfreiheit.

## Installation / Überprüfung

1. Die Modulbibliothek **IPSAlexaHaussteuerung** auf den neuen `main`-Stand aktualisieren.
2. In der Alexa-Modulinstanz **Übernehmen** und falls vorhanden den Helferskript-Refresh ausführen, damit der gespeicherte `KIHouseController` neu eingebettet wird.
3. Die Frage `Welcher Raum ist der wärmste?` stellen.
4. Die Alexa-Logs prüfen: Der Werkzeugname sollte `query_house`, die Eigenschaft `temperature`, die Operation `max` und die Gruppierung `room` zeigen. Die Antwort nennt nur gemessene und freigegebene Isttemperaturen.
5. Anschließend andere Kombinationen ausprobieren (kältester Raum, höchste Luftfeuchtigkeit, eingeschaltete Lichter, offene Fenster, gesamte Leistung). Fehlende Verknüpfungen im RoomsCatalog müssen zuvor ergänzt werden.

**Grenze:** Ein KI-Sprachmodell kann trotz präziser Werkzeuge einen unpassenden Filter wählen. Der Controller beantwortet solche Fälle nicht mit frei erfundenen Daten. Ein grüner CI-Test überprüft die Funktion, ersetzt aber nicht einen realen IP-Symcon-Test mit aktuellen Messwerten.
