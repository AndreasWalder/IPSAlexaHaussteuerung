# RoomsCatalogConfigurator V3.3.12 – Diagnoseexport und sichere Auto-Korrektur

## Warum diese Version?
Nach der V3.3.11-Migration: 167 Geräte, 118 technisch plausibel,
49 zur manuellen Prüfung, keine ungültigen oder Legacy-Einträge.
Eine Heizung kann dieselbe beschreibbare KNX-Sollwertvariable für
`targetTemperature` und `control` benutzen – das darf nicht allein
wegen einer Doppelbelegung als Fehler gelten. Eine Jalousie-`wert`-
Variable ist dagegen ein Fahrbefehl und kein Positionssensor.

Die Listenbearbeitung warnte außerdem `Undefined array key "illuminance"`
und erzeugte dadurch ungültiges Formular-JSON. Die Lux-Beschriftung ist ergänzt.

## Zwei Schaltflächen unter »Geräteprüfung & Migrationsabschluss«
**1 – GESAMTDIAGNOSE FÜR CHATGPT HERUNTERLADEN**
Erstellt eine JSON-Datei mit ALLEN gefundenen Geräten, technischen
Prüfgründen, bereits hinterlegten Rollen, Objekt-IDs sowie Namens-,
Profil- und Aktionsmetadaten der zugehörigen IP-Symcon-Variablen
(max. 80 Einzelvariablen pro Gerät; bei Überschreitung markiert).
Die Datei kann direkt in ChatGPT hochgeladen werden.

Keine API-Keys, Passwörter, vollständigen PHP-Skripte, Livewerte oder
automatischen Schaltbefehle werden exportiert. Raumnamen und interne
Objekt-IDs sind enthalten; Datei deshalb bewusst teilen.

Der Export erzeugt gleichzeitig eine signierte Vorschau der
**eindeutig** erkannten zusätzlichen Leserollen und zeigt deren Anzahl.
Ein bloßer Export verändert weder EDIT noch PROD.

**2 – SICHERE AUTO-KORREKTUREN NACH EDIT + PROD**
Nur innerhalb von zehn Minuten nach dem Diagnoseexport:
Bestätigung im Dialog, gleiche und getrennte EDIT-/PROD-Skripte,
literal sichere PHP-Arrays, erneute Metadatenanalyse sowie SHA-256-
Vergleich aller Quellen und des Plans erforderlich.
Zunächst werden zwei unabhängige Original-Skript-Backups erstellt
und verifiziert, dann werden ausschließlich fehlende `ai.variables`
für eindeutige und stark bewertete Leserückmeldungen ergänzt.
Bei Schreib-/Verifikationsfehlern erfolgt Rückschreiben der
Originalquellen. Alle anderen Felder bleiben erhalten.

**Nicht automatisch:** 0/100-Jalousierichtung, fehlende echte Status-
rückmeldungen, Konflikte, fehlende IPS-Schaltaktionen, KI-Schaltrechte,
oder die Kennzeichnung als fachlich bestätigt. Diese Fälle bleiben
bewusst als »Manuell prüfen« erhalten.

## Anschließende Kontrolle
`ALLE GERAETE TECHNISCH PRUEFEN` erneut ausführen und die
neuen Werte vergleichen. `0 EDIT/PROD-Unterschiede` und
`0 ungueltig / 0 Legacy` sind wichtige, aber nicht ausreichende
Kriterien für die echte Gerätefunktion. Licht, Dimmer, Jalousien,
Heizung, Lüftung und Szenen live überprüfen.

Die Download-Schaltfläche verwendet die in IP-Symcon 7.0+
unterstützte `Button.download`-Funktion mit einer Data-URL.

## Nachprüfung anhand des Exports vom 4. November 2026 (V3.3.13)
Der erste V3.3.12-Export meldete 167 Geräte, 124 technisch plausibel,
43 manuell prüfen, 0 Vorschläge. Dabei waren jedoch **für alle Geräte**
`roles: []`, `stateMapping: []`, `reviewStatus: ""` ausgegeben.
Ursache: `buildEntryRow` wandelt verschachtelte `ai`-Metadaten
für IPS-Formulare in JSON-Strings um; der Diagnoseexport hatte diese
Strings anders als der technische Prüfer nicht wieder decodiert.
Damit waren die Rollendaten im Export falsch leer und der konservative
Auto-Reparaturplan zu streng auf null eingeschränkt.

V3.3.13 decodiert die vorhandenen Daten für die **Analyse**, ohne
Katalogdaten zu verändern. Der Export zeigt nun ausdrücklich die
ursprünglichen `ai.variables`, `ai.stateMapping`, `ai.review.status`
und die Zahl der Geräte mit exportierten Rollen.

In den 43 offenen Fällen waren 26 Jalousien, 10 Heizungen, drei
Lichter, drei Lüfter und ein Dimmer. Bei zehn Heizungen war
`ai.variables.control` identisch zur nur lesbaren Solltemperatur,
während im gleichen KNX-Kontext jeweils eine eindeutig als
`Solltemperatur` bezeichnete Float-Variable mit echter Schreibaktion
vorhanden war. Bei *noch nicht fachlich bestätigten* und *KI nur lesen*
konfigurierten Geräten kann V3.3.13 diese technische `control`-
Zuordnung mit neuer Vorschau, Sicherungen und Hash-Kontrolle
korrekt umhängen. Die alte `targetTemperature`-Rückmeldung, Legacy-
IDs, KI-Rechte und Bestätigungen werden **nicht** verändert.

Die übrigen blinden Jalousie-Rückmeldungen werden **nicht** aus
Steuerungsbefehlen oder 0/100-Profiltexten erfunden. Auch für
zweideutige KNX-Funktionswerte und unklare Dimmer-Skalierung bleibt
eine weitere fachliche/technische Zuordnung nötig.

**Wichtig:** Vor erneuter Durchführung einer automatischen Reparatur
zuerst V3.3.13 einspielen und erneut die Gesamtdiagnose exportieren.
Das alte V3.3.12-JSON liefert nicht sämtliche Rollendaten.
