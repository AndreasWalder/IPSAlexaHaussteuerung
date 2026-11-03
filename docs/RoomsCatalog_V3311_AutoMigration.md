# V3.3.11 – RoomsCatalog automatisch korrigieren, globale UI nach SystemConfiguration und nach PROD

## Ausgangslage
Die vom Benutzer am 3. November 2026 bereitgestellten Daten zeigten:
175 Einträge, davon **167 echte Geräte** und **8 globale UI-Einträge**
(1 Jalousie-Szenendefinition, 4 Licht-Szenen/Überschriften,
2 Lüftungs-Standardtasten, 1 zentrale Lüftung). Bei der letzten
serverseitigen Prüfung waren RoomsCatalog PROD und EDIT identisch.

Diese Migration arbeitet **auf den echten aktuellen IP-Symcon-Skripten**,
nicht mit einem älteren hochgeladenen JSON-Abbild. Ohne expliziten
Benutzerklick wird nichts produktiv geschrieben.

## Bedienung – alle Einstellungen im Hauptmodul
1. Das Modul **IPSAlexaHaussteuerung** aktualisieren und **Übernehmen**.
2. Den neuen Bereich **RoomsCatalog automatisch bereinigen & nach PROD
   übernehmen** öffnen. Die alte Navigation-Migration muss abgeschlossen
   sein, und RoomsCatalog sowie RoomsCatalogEdit müssen dieselben
   Geräte enthalten.
3. **1 – MIGRATION PRÜFEN (NUR VORSCHAU)** ausführen. Anzahl der
   Erkennungen, Typwechsel und der offen gebliebenen Fälle kontrollieren.
4. **2 – GEPRÜFTE KORREKTUREN NACH PROD ÜBERNEHMEN** drücken und
   Sicherheitsdialog bestätigen. Die zehn Minuten gültige Vorschau
   wird noch einmal mit echten IP-Symcon-Daten/Hashes verglichen.
5. Der Assistent erstellt zuerst **vier vollständige, verifizierte
   Sicherungsskripte**. Danach werden RoomsCatalog PROD + EDIT sowie
   SystemConfiguration PROD + EDIT geschrieben und wieder eingelesen.
   Bei Schreibfehlern stellt er die ursprünglichen Inhalte wieder her.
6. Anschließend **Alexa Licht, Dimmen, Heizung, Jalousie, Szenen und
   Lüftung testen**. Die technischen und fachlichen Prüfungen in
   RoomsCatalogConfigurator erneut starten. Das Migrationsverfahren
   erteilt keine neuen KI-Freigaben und keine pauschalen Bestätigungen.

## Geraetetypen und Variablen
- Lux-Messwerte mit Profil `~Illumination.F` werden als
  `ai.type=illuminance` und `ai.variables.illuminance=<floatId>`
  statt fälschlich Boolean-Licht gespeichert.
- Dimmer-Variablen werden bei passendem Datentyp zu
  `ai.type=dimmer`, `ai.variables.brightness`.
- Alte Jalousie-`wert`-Variablen mit tatsächlicher Schreibaktion
  werden **ausschließlich als `action`** übernommen, niemals als
  vermeintlich sichere Positionsrückmeldung.
- Heizung bewahrt die getrennten `ist`-/`soll`-Variablen und darf
  mehrere KNX-Instanzen pro logischem Gerät verwenden.
- Lichtschalter, Bewegungs- und Lüftungsvariablen werden nur mit
  kompatiblen Datentypen übernommen.
- Bei fehlenden oder uneindeutigen Profilen bleibt der betreffende
  Eintrag unverändert und wird in der Vorschau als offen aufgeführt.

**Wichtig:** `ai.review.status` bleibt unbestätigt. Die tatsächlichen
Werte (z. B. Auf/Zu, 0/100 und Schaltwirkung) sind keine durch einen
Metadaten-Scan nachgewiesenen Wahrheiten.

## Globale UI-Einstellungen
Die folgenden vollständigen UI-Domain-Bereiche werden aus
`RoomsCatalog['global']['domains']` entfernt und unter
`SystemConfiguration['roomUi']['globalDomains']` gespeichert:

- `jalousie` (inklusive Szenen, Icon und Steuerungsmetadaten)
- `licht` (Szenen)
- `lueftung` (Standardtasten, Zentrale, Icons, Statusfarben)

Die bestehenden `global.floors` und andere nicht migrierte
systemweite Domains bleiben aus Kompatibilitätsgründen zunächst
erhalten. Die Alexa Action-Route projiziert `roomUi.globalDomains`
**nur zur Laufzeit** zurück in das für bestehende Renderer erwartete
Schema; es werden keine UI-Daten zurück in RoomsCatalog geschrieben.

## Sicherheitsbedingungen
- Keine automatische Aktivierung bei Modulupdate.
- Kein Schreibzugriff bei unterschiedlichen RoomsCatalog-EDIT/PROD-
  Beständen, unsicheren/dynamischen PHP-Quellen, fehlenden Skripten,
  doppelten Skript-IDs, unbekannten globalen UI-Feldern oder
  kollidierenden Einstellungen.
- Zehn-Minuten-Preview, SHA-256-Vergleich, erneute Berechnung und
  Verifikation der geplanten Zieltexte.
- Vor Änderungen vier unabhängige Original-Skript-Backups;
  produktive Änderungen erfolgen erst **nach** verifizierter Sicherung.
- Keine Änderung von KI-Lese-/Schaltberechtigungen, Passwörtern,
  Navigation, Zusatzfeldern oder Benutzerdaten außerhalb der ausdrücklich
  beschriebenen Gerätefelder und UI-Domains.
- Keine Löschung von Backups. Ein kompletter Rollback kann anhand
  der protokollierten vier Backup-IDs durchgeführt werden.
