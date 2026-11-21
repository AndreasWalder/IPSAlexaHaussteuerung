# Wetterstation: Froggit / Ecowitt

Die Domain `wetterstation` ergänzt Hausgeräte, Heizung und Bewässerung als eigener Bereich. Grundlage ist das installierte [Froggit-Modul](https://github.com/IPSAttain/Froggit/blob/master/Froggit/README.md), nicht eine feste Objekt-ID. Die Haupt-Wetterstation und das zusätzliche 433-MHz-Gateway werden als eigene Geräte erkannt. Weitere installierte Froggit-Instanzen werden ebenfalls erfasst.

## Nach dem Modulupdate

1. Den **RoomsCatalog Konfigurator** öffnen oder **RoomsCatalog neu laden** drücken. Der Abgleich ergänzt die gefundenen Wetterstationen in **EDIT**. Alternativ steht der Button **Wetterstationen in EDIT abgleichen** im Abschnitt **WETTERSTATION – Froggit / Ecowitt** zur Verfügung.
2. Unter **Geräte bearbeiten → Domain-Filter Wetterstation** beide Geräte prüfen. Über das Zahnrad sind alle zugeordneten Messwerte einschließlich aktueller Werte, Einheiten, Namen, Verknüpfungen und Lesefreigaben erreichbar. Alternative Stationsnamen können ebenfalls geändert werden.
3. **EDIT nach PROD übernehmen**, damit Alexa die geprüften Zuordnungen verwendet. Das Öffnen oder Abgleichen veröffentlicht keine Änderungen.

Ein erneuter Abgleich ergänzt neue Messwerte und behält vorhandene Namen, Verknüpfungen und Freigaben bei. Der bestehende EDIT-Sicherungsmechanismus wird verwendet. Geräte und einzelne Messwerte können für Alexa gesperrt werden. Das gilt auch für auf eine andere Station verlinkte Sensoren. Ein Löschen der gesamten Station aus EDIT wird beim nächsten automatischen Abgleich rückgängig gemacht; zum dauerhaften Ausschließen die Lesefreigabe deaktivieren.

## Messwerte und Statusseite

Unterstützt werden die tatsächlich vorhandenen Temperatur-, Luftfeuchte-, Luftdruck-, Wind-, Böen-, Windrichtungs-, Niederschlags-, UV-, Einstrahlungs-, Bodenfeuchte- und Batteriewerte. Weitere vom Modul angelegte Messwerte, etwa VPD, Blitz- oder Luftqualitätsdaten sowie Geräteinformationen und Rohwerte, stehen mit **alle Werte** zur Verfügung. Vorhandene Sensorbezeichnungen wie **Hecke Ost** bleiben erhalten. Linkziele werden überprüft und in einer gemeinsamen Abfrage nur einmal ausgegeben. Die doppelte textuelle Windrichtungsvariable entfällt; die gemessene Gradzahl wird zusammen mit der Himmelsrichtung angezeigt.

**Uhrzeit**, Gateway-Startzeit, Uploadintervall und Zugangsdaten sind keine Katalog-Messwerte. Die wetterbezogene Alexa-Statusseite nutzt das gemeinsame responsive Layout mit eigenem Wettersymbol, Messwertzeilen und einer scrollbaren Liste. Allgemeine Statusfragen wählen bevorzugt die Instanz mit den meisten Wetter-Messgrößen. Das 433-Gateway ist über seinen Namen einzeln abfragbar. **Alle Werte beider Wetterstationen** fasst beide Geräte zusammen.

Die Einheiten kommen aus den tatsächlichen Symcon-Profilen; bereits von Froggit umgerechnete Werte werden nicht erneut umgerechnet. Eine Einstrahlung in **lx** wird beispielsweise als **lx** ausgegeben. Regenrate ergänzt den Zeitbezug **/h** zum vom Modul gemeinsam für Regenmengen und Regenraten verwendeten Profil.

## Alexa-Fragen

- „Wie ist das Wetter?“ / „Wie geht es meiner Wetterstation?“
- „Zeige die Wetterstation.“
- „Wie geht es meiner 433Station?“ / „Zeige alle Werte der 433Station.“
- „Wie warm ist es draußen?“ / „Wie hoch ist der Taupunkt der Wetterstation?“
- „Wie stark ist der Wind?“ / „Aus welcher Richtung kommt der Wind?“
- „Wie stark war die stärkste Windböe heute?“
- „Regnet es gerade?“ / „Wie viel hat es heute geregnet?“
- „Wie viel Regen meldet die Wetterstation diese Woche?“
- „Wie hoch ist der UV-Index der Wetterstation?“
- „Wie hoch ist die solare Einstrahlung der Wetterstation?“
- „Wie feucht ist die Hecke Ost?“
- „Welche Batteriewerte meldet die Wetterstation?“
- „Zeige alle Werte beider Wetterstationen.“

Die Abfragen werden lokal aus den veröffentlichten Zuordnungen und den aktuellen Symcon-Werten beantwortet; ein zusätzlicher KI-Aufruf ist dafür nicht nötig. Der bestehende Alexa-Freitext-/Hausfragenweg wird weiterverwendet. Die Freigabe für Hausfragen muss aktiviert sein. Die Wetterstation bietet ausschließlich Lesezugriff und führt auch bei eingeschalteter allgemeiner Sprachsteuerung keine Geräteaktionen aus.

## Aktualität und Grenzen

Froggit speichert viele Werte standardmäßig nur bei Änderungen. Deshalb beweist ein alter `VariableUpdated`-Zeitpunkt allein keinen Ausfall. Die Integration verwendet die von Froggit bei jedem Upload aktualisierten Empfangsmetadaten der Variable `dateutc`; deren eigentlicher Uhrzeitwert wird weder angezeigt noch als Messwert übernommen.

- Bleiben Gateway-Uploads länger als 15 Minuten aus, werden Messwerte als **veraltet** gekennzeichnet.
- Ist Froggits **SaveAllValues** aktiviert, werden auch länger als 15 Minuten nicht aktualisierte Einzelwerte als **veraltet** gekennzeichnet.
- Ohne diese Einstellung sind länger als einen Tag unverändert gespeicherte Werte als **Aktualität unbestätigt** markiert. Ohne Empfangsmetadaten wird die Aktualität ebenfalls nicht behauptet. Das kann auch einen weiterhin unveränderten und gültigen Wert betreffen; ein Sensorausfall wird daraus nicht abgeleitet.
- Ungültige Werte wie **−100 % Batterie**, unmögliche Prozentwerte oder fehlende/anders zugeordnete Variablen erscheinen als **Nicht verfügbar**. Null bleibt ein gültiger Messwert, etwa bei Regenmenge oder Bodenfeuchte.

Der Empfang belegt die Erreichbarkeit des Gateways, aber nicht automatisch den Funkempfang jedes einzelnen Sensors. Die Integration ändert keine Einstellungen am Froggit-Modul. Einzelne veraltete Sensoren lassen sich im Editor vom Lesen ausschließen.

Tages-, Wochen-, Monats- und Jahresregen verwenden die jeweiligen Zähler des Froggit-Moduls; die stärkste Tagesböe verwendet dessen Tagesmaximum. Eine freie Archivauswertung, Wettervorhersagen, automatische Wetterwarnungen/Durchsagen und Wetterstation-Steuerbefehle sind damit noch nicht implementiert. Entsprechende Vorhersage- oder Archivfragen werden ausdrücklich als nicht verfügbar beantwortet.
