# RoomsCatalogConfigurator V3.3.20 – Nur eine geänderte Jalousie-Positions-ID nach PROD

## Hintergrund
Die bisherige bereichsweise Übernahme V3.3.18 validiert jedes geänderte Gerät vollständig.
Damit wird eine **einzelne** Änderung der `aiPositionId` / `ai.variables.position`
zurückgewiesen, sobald eine **andere** Eigenschaft der Jalousie noch nicht
fertig konfiguriert ist (z. B. fehlende Werte für Offen/Geschlossen).

## Neuer gezielter Workflow
Unter **Weitere Funktionen (Experten): Prüfen, Migration und PROD** →
**BEREICHSWEISE PROD-UEBERNAHME** gibt es:

**NUR EINE JALOUSIE-POSITIONS-ID NACH PROD (OHNE GESAMTPRUEFUNG)**

1. Aus der automatisch ermittelten Liste der Jalousien mit geänderter Positions-ID
   genau **ein** Gerät auswählen, beispielsweise `Büro / Jalousie Fenster`.
2. **1 – NUR DIESE POSITIONS-ID PRUEFEN** – zeigt bisherige und neue IPS-ID.
3. **2 – NUR DIESE POSITIONS-ID NACH PROD** – übernimmt nach Sicherheitsdialog
   exakt die `aiPositionId` sowie, soweit vorhanden, das zugehörige
   `ai.variables.position`, damit beide Felder konsistent sind.

Es wird **nicht** der vollständige `ai`-Block kopiert.

## Was nicht verlangt wird
- Keine vollständigen Offen-/Geschlossen-Zustandsabbildungen.
- Keine vollständige fachliche oder technische Bestätigung des übrigen Geräts.
- Keine Validierung fremder Jalousien, anderer Räume oder Geräte.

## Was weiterhin geprüft werden MUSS
- EDIT und PROD enthalten denselben vorhandenen Jalousie-Datensatz als typisiertes `blind`-Gerät.
- Es gibt tatsächlich eine geänderte, eindeutige positive Positions-ID.
- IPS-Variable existiert, ist Integer oder Float, hat keine Schreibaktion und
  ihre Bezeichnung/Profil passt plausibel zu einer Positionsrückmeldung.
- Die ID ist nicht gleichzeitig Fahrbefehl, Sollposition oder Lamellenstellung.
- Bereits in PROD fachlich bestätigte Geräte können hier nicht unbemerkt neu zugeordnet werden.
- Wenn `aiPositionId` und `ai.variables.position` **beide** geändert wurden,
  müssen sie denselben neuen Wert haben.

**Wichtig:** Ein formal passender IPS-Wert belegt noch nicht den
tatsächlichen realen Jalousiestand. Es werden keine Geräte gefahren,
keine Position gemessen und keine fachlichen Bestätigungen gesetzt.

## Schutz vor Datenverlust
- Vorschau ist höchstens 10 Minuten gültig; ausgewählter Geräteschlüssel,
  EDIT-/PROD-Skript-IDs und SHA-256-Quelltext-Hashes werden gebunden.
- Der exakte Feld-Änderungsplan inklusive Variablenmetadaten wird
  vor der Übernahme erneut berechnet und mit der Vorschau verglichen.
- Vor jeder Änderung entsteht ein **separates vollständiges und lesend
  verifiziertes PROD-Skriptbackup**.
- PROD wird nach dem Schreiben wieder eingelesen und verifiziert.
  Bei einem Fehler wird versucht, den alten vollständigen Skriptinhalt
  wiederherzustellen.
- Räume, Gerätenamen, Befehle, Statusabbildungen, `ai.review`,
  KI-Lese-/Schreibberechtigungen, **andere** Änderungen im `ai`-Block
  und alle übrigen Geräte bleiben in PROD unverändert.
- EDIT bleibt unverändert.
- Der Zurücksetzen-Button invalidiert auch diese Einzelfeld-Vorschau.

## Beispiel Büro
Wenn bei **Büro / Jalousie / Fenster** ausschließlich eine gültige
Positions-ID geändert wurde, kann sie übernommen werden, obwohl die
vollständige Jalousie-Übernahme weiterhin wegen fehlender
Offen/Geschlossen-Werte gesperrt bleibt.

Wenn die neue ID dagegen eine steuerbare Position (Fahrbefehl)
oder eine Lamellenstellung ist, zeigt der Assistent einen **konkreten
Sperrgrund**. Er bietet keinen unsicheren generellen Bypass an.
