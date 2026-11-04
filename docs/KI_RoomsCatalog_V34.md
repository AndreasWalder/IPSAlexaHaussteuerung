# V3.4.0 – Typisierte Geräte und mehrere Eigenschaften in der Alexa-KI

V3.4 nutzt die in V3.0–V3.3.26 gepflegten `ai.version=1`, `ai.type`,
`ai.variables` und `ai.stateMapping` erstmals direkt im produktiven
`KIHouseController`. Die bestehende Alexa-/APL-Navigation, das Alexa Gateway
`/alexa/haus`, `SystemConfiguration` und der einfache RoomsCatalog-Editor
bleiben unverändert.

## Welche Geräte abgefragt werden können

Die KI liest ausschließlich **RoomsCatalog PROD** und nur vorhandene, dort
explizit verknüpfte IP-Symcon-Variablen. Der Bereich kann dabei auch
`sensoren`, `energie` oder `geraete` sein; die KI beschränkt sich nicht mehr
auf Licht, Heizung, Jalousie und Lüftung.

| Typ | Gelieferte Eigenschaften, sofern konfiguriert |
| --- | --- |
| Licht | `power`, `status` |
| Dimmer | `power`, `brightness`, `status` |
| Jalousie | `position`, `targetPosition`, `tilt`, `status`, `moving` |
| Heizung | `temperature`, `targetTemperature` |
| Fensterkontakt | `open`, `status` |
| Steckdose/Schalter | `power`, `powerWatts`, `energy`, `status` |
| Energiezähler | `powerWatts`, `energy`, `status` |
| Bewegung | `motion`, `status` |
| Helligkeitssensor | `illuminance` |
| Lüfter | `power` oder ausdrücklich gekennzeichneter `commandValue` |
| Sonstiges | `power`, `status` ohne automatische Schreibberechtigung |

Die Antwort enthält weiter `state` für bisherige Nutzer und zusätzlich
`properties` mit den **einzeln benannten Werten**. Kein `action`-/`control`-
Kommando wird als physische Rückmeldung gemeldet. Bei einem Lüfter, der nur
`control` besitzt, ist der Istzustand ausdrücklich **nicht bestätigt**.
Bei Fenster-/Jalousie-Statuswerten wird `offen`/`geschlossen` nur dann
ausgegeben, wenn ein dazu passendes, explizites `ai.stateMapping` vorliegt;
KNX-Fahrbefehle wie `0/100/999` werden nicht als Rückmeldung gedeutet.

Die Raum-Aliase aus der V3.1-Raumverwaltung funktionieren jetzt auch bei
Alexa-KI-Fragen. `get_devices` listet Geräte-Keys, Typen, verfügbare
Eigenschaften und die derzeit erlaubten Schreib-Eigenschaften.

## Schreiben: zusätzliche Sicherheitsbarrieren

Die bisherigen Legacy-Geräte bleiben unverändert. Für **neu typisierte**
Geräte müssen dagegen *alle* Bedingungen erfüllt sein:

1. `KI darf explizite Schaltbefehle ausführen` in der Hauptinstanz aktiviert.
2. `kiEnabled=true` und `kiReadOnly=false` am betreffenden PROD-Gerät.
3. `ai.review.status=confirmed` – fachliche Bestätigung im Editor; eine
   rein technische Plausibilitätsprüfung reicht nicht.
4. Die passende **explizite** Aktionsvariable ist vorhanden, besitzt
   `HasAction` und den richtigen IP-Symcon-Variablentyp.
5. Der Befehl adressiert genau ein Gerät, einen erlaubten Wert und eine
   eindeutig zugeordnete Eigenschaft.

Erlaubt sind nur diese klaren Zielrollen:

- Licht, Steckdose, Lüfter: `power` über explizites boolesches `control`.
- Dimmer: `power` über boolesches `control` oder `brightness` über eine
  beschreibbare Helligkeitsvariable, `0–100`.
- Heizung: `targetTemperature` über diese beschreibbare Sollwertvariable,
  begrenzt auf `6–30 °C`.
- Jalousie: `targetPosition` nur als **eigenständige** beschreibbare
  Zielvariable, `0–100`. Sie darf nicht dieselbe ID wie `position` oder
  `action` verwenden.

`action`, uninterpretierte KNX-Enums, freie Variablen-IDs, implizite
`wert`-/`set`-Fahrbefehle und Schreibbefehle für Sensoren bleiben verboten.
`set_multiple` bleibt auf ausdrücklich verlangte Gruppen von maximal
15 bereits freigegebenen Licht-/Lüftungsschaltern begrenzt. Das Ergebnis
eines `RequestAction` ist keine bestätigte physische Rückmeldung.

## Aktualisierung in IP-Symcon

1. In **Module Control** das Repository aktualisieren und in der
   IPSAlexaHaussteuerung-Instanz **Übernehmen** wählen, damit die aktualisierte
   `KIHouseController`-Helferdatei im IP-Symcon-Skript ankommt.
2. `KI-Verarbeitung = KI + RoomsCatalog` und den richtigen **PROD**-
   Katalog prüfen. `RoomsCatalogEdit` wird von Alexa weiterhin nicht benutzt.
3. Zunächst ohne globale KI-Schreibfreigabe abfragen, zum Beispiel:
   „Welche Fenster sind offen?“, „Wie warm ist es im Büro?“,
   „Welche Position hat die Jalousie Süd?“ oder „Wie viel Leistung verbraucht
   die Steckdose?“
4. Die einzelnen Typ-/Variablenzuordnungen und Status-Mappings vor Ort
   kontrollieren. Erst danach Geräte gezielt fachlich bestätigen und bei
   Bedarf die bisherige KI-Schreibfreigabe verwenden.

Bei einem Modulupdate werden `RoomsCatalog`, `RoomsCatalogEdit`,
`RoomsCatalog_Backup_*`, `SystemConfiguration` und Gerätefreigaben **nicht**
automatisch umgeschrieben. Für einen noch nicht zugeordneten `ai`-Typ
erfolgt keine automatische Interpretation als Legacy-Gerät.

Regressionsprüfung: `tests/test_ki_rooms_catalog_v34.php`, zusätzlich
`tests/test_ki_rooms_catalog.php` und die bestehende PHP-CI.
