# V3.4.8 – vollständige, lokal kopierbare Hausdiagnose und Live-Werte im RoomsCatalogConfigurator

## 1. Warum „Laptop“ nicht mehr als Wohnraum erscheint

Ein Zimmer mit `roomKey=laptop` ist in dieser Installation ein **Testraum**.
Die KI lädt weiterhin das originale RoomsCatalog-PROD-Skript, ignoriert aber
`laptop` bei der Geräteindizierung. Testgeräte bleiben in EDIT/PROD und
im Konfigurator unverändert bestehen. Weitere reine Testbereiche können
optional in der zentralen Raumdefinition `aiTestRoom => true` oder
`excludeFromAI => true` erhalten. Es gibt **keine automatische Löschung**,
keine zusätzliche Kopie der Geräteliste und keine Änderung an den KI-Rechten.

Im IP-Symcon-Meldungsfenster erscheint
`KIRoomsCatalog.catalog.test_rooms_excluded` mit den ignorierten Raum-Keys.
Diese Entscheidung gilt für die generische Query, die Inventarliste,
Rohzustandsabfragen und das an ChatGPT angebotene Raumschema.

## 2. Detaillierte Diagnose für jede ChatGPT-Hausfrage

In `IPSAlexaHaussteuerung → KI-Haussteuerung` gibt es die Option
**„Detaillierte KI-Analyse in IP-Symcon-Logs“** (zunächst aktiviert).
Die detaillierten Daten werden **nur lokal protokolliert und nie als
zusätzliche Daten an die OpenAI-API übertragen**.

Bei jeder `query_house`- oder `get_states`-Abfrage erscheinen:

- `KIRoomsCatalog.catalog.loaded`: Anzahl erlaubter Geräte nach Typ,
  Schreibfreigabe, aktivierte Gerätediagnose.
- `KIRoomsCatalog.diagnostics.query`: Name des Tools, Operation,
  Eigenschaft, Bedingung, Gerätefilter, Zahl der Geräte vor/nach dem Filter,
  tatsächliche Treffer, mögliche Diagnosekürzung.
- `KIRoomsCatalog.diagnostics.devices`: durchnummerierte Teile mit jeweils
  bis zu sieben Geräten. Für jedes Gerät Raum, Name, RoomsCatalog-Key,
  Typ, Domäne, Gruppe, Typisierungsstatus, vorhandenes `stateMapping`,
  interpretierter Istwert, Klassifikation `on/off/unknown`,
  Filterergebnis und die zugeordneten IPS-Variablen:
  **Rolle, ID, Rohwert, formatierter Anzeigewert, Variablentyp,
  Variablenprofil, passende Profil-Assoziation, Objektname, Parent-ID,
  letztes Wertänderungs- und Aktualisierungsdatum (Unix-Zeit)**.

Alle Daten sind auf die **im RoomsCatalog freigegebenen Rollen** begrenzt,
lange Texte, URLs, JSON und nichtskalare Werte werden ausgeblendet.
API-Keys, Tokens, komplette Alexa-Requests und freie IPS-Objektbaum-Scans
sind nicht Teil des Berichts. Für private Fehleranalyse ist der gesamte
Bericht trotzdem vertraulich zu behandeln. Die Zusatzlogs bleiben Teil
des vorhandenen Alexa-Meldungsverlaufs (letzte 500 Meldungen).

**Bei der nächsten Fehleranalyse:** Hausfrage stellen und die aufeinander
folgenden `KIRoomsCatalog.diagnostics.query`- und
`KIRoomsCatalog.diagnostics.devices`-Meldungen inklusive aller Teile
kopieren. Zusammen mit `KIRoomsCatalog.tool` zeigen diese unmittelbar,
ob der Istwert falsch zugeordnet ist, ein Profil invertiert ist,
der Status aus einem Schaltbefehl statt aus einer Rückmeldung stammt,
oder ein Mapping fehlt. Kein manuelles Heraussuchen hunderter Variablen.

Bei der Frage „Wie viele Lichter sind eingeschaltet?“ zählen nur
Geräte mit relevanten Statusrollen (`power`, `status`, `brightness`,
`motion`) bzw. Legacy-Schalter. **Lux-Sensoren** werden nicht mehr als
„Lampen mit unbekanntem Status“ mitgezählt. Die Reihenfolge für die
Statusauswertung ist: explizites `ai.stateMapping` → IPS-Variablenprofil
→ Standard-Bool/0/1 → unbekannt. Unbekannte Werte werden niemals als
eingeschaltet geraten.

## 3. Aktueller Variablenwert direkt im Geräteeditor

Im **RoomsCatalogConfigurator → Geräte bearbeiten → Zahnrad/Editieren →
„3. Variablen und Zustandswerte“** steht rechts neben jeder
`SelectVariable`-Auswahl eine **„Aktuell“-Vorschau**:

`Aktuell: true (Ein) | #12345 | Bool | Profil: ~Switch`

Die Vorschau nutzt ausschließlich `GetValue`, `GetValueFormatted`,
`IPS_GetVariable` und die gerade gewählte Variable. Wird im
IP-Symcon-Objektbaum eine neue Variable ausgewählt und bestätigt,
aktualisiert `onChange` den Status direkt **im noch geöffneten
Geräteeditor**. Bei einem Typwechsel passen sich die sichtbaren
Statuslabels automatisch an. Das Speichern nach EDIT/PROD wird dadurch
**nicht** ausgelöst.

**Wichtig:** Die integrierte IP-Symcon-`SelectVariable`-Baumauswahl
ist ein systemeigener Dialog. Seine vorhandenen Tabellenspalten sind
über das SDK nicht um „Aktueller Wert“ erweiterbar. Die Vorschau
erscheint darum rechts neben der Auswahl, **nach Auswahl und Bestätigung
im Dialog**, nicht in der Baumansicht selbst.

## 4. Einspielen

Modulbibliotheken `IPSAlexaHaussteuerung` und `RoomsCatalogConfigurator`
aktualisieren, Instanzen öffnen und **Übernehmen** drücken. Es sind keine
neuen Alexa-Intents, kein zusätzlicher OpenAI-Aufruf und keine manuelle
Migration von Laptop/PROD notwendig.
