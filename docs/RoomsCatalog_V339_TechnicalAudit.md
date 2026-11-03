# RoomsCatalogConfigurator V3.3.9 – technische Geräteprüfung und Migrationsabschluss

## Ziel

Die Inventur vom 3. November 2026 zeigte 175 Einträge, davon 167 typisiert,
8 Legacy, 0 fachlich bestätigt und 59 EDIT/PROD-Unterschiede. **Die Meldung
„0 fehlende Rückmeldungen“ war nur eine Mindestrollen-Prüfung**, keine
tatsächliche Bestätigung der Geräte. V3.3.9 ergänzt dafür eine
gerätespezifische technische Bewertung über die vorhandenen
IP-Symcon-Instanzen und Profile.

Die Prüfungen sind **kein Cloud-KI-Aufruf**. Der Server wird nicht
geschaltet, und keine aktuellen Variable-Werte oder Geheimnisse werden
in Berichten oder JSON-Snapshots gespeichert.

## Bedienung

Öffne `RoomsCatalogConfigurator` → **Geräteprüfung & Migrationsabschluss**.

1. **ALLE GERÄTE TECHNISCH PRÜFEN** erstellt eine momentane, rein lesende
   Prüfmatrix für alle EDIT-Geräte. Für jeden typisierten Gerätetyp
   werden zugehörige IP-Symcon-Variablen und ihr Instanzkontext,
   Datentyp, Profil, Name/Ident, erforderliche Rückmeldungen sowie
   vorhandene Schaltaktionen geprüft. Beim Rollladen/Fenster gelten
   außerdem Offen-/Geschlossen-Bedeutungen als ungeklärt, solange
   eindeutige Assoziationen fehlen.
2. In der Tabelle lassen sich Einträge filtern und markieren.
   **TECHNISCH PLAUSIBLE MARKIEREN** wählt nur eindeutige Fälle.
   Fehler, unbekannte Legacy-Typen und nicht nachgewiesene Pflichtrollen
   können nicht automatisch bestätigt werden.
3. **TECHNISCHE ÜBERNAHME VORSCHAU** und
   **TECHNISCHE ERGEBNISSE NACH EDIT SPEICHERN** schreiben für die
   ausgewählten Geräte ausschließlich
   `ai.review.technical` in `RoomsCatalogEdit`. Der bestehende
   `ai.review.status=pending` bleibt erhalten.
4. **Fachliche Bestätigung:** Für jedes markierte Gerät, dessen
   Ein/Aus-Zustand, Statuswerte, Soll-/Ist-Variablen und ggf.
   Steueraktion tatsächlich vor Ort geprüft wurden, darf die eigene
   Bestätigungs-Checkbox gesetzt werden. Erst danach ist die separate
   **MANUELLE BESTÄTIGUNG VORSCHAU** und
   **GEPRÜFTE GERÄTE NACH EDIT BESTÄTIGEN** nutzbar. Sie setzt
   `ai.review.status=confirmed` und dokumentiert die ausdrückliche
   manuelle Bestätigung. Gerätetypen ohne vollständige Pflichtrollen
   oder mit ungültigen IDs bleiben gesperrt.
5. Der ausgeklappte Bereich **Legacy-Geräte** zeigt alle alten Geräte
   mit Katalogpfad. Sie werden unter
   **Geräte erkennen, inventarisieren und reparieren** typisiert.
6. **EDIT/PROD-Unterschiede** zeigt alle neuen, entfernten und
   geänderten Einträge samt betroffenen Feldnamen – ohne die Feldwerte
   offenzulegen. Der Abschluss erfolgt nicht automatisch; die
   Veröffentlichung bleibt der separate, bereits vorhandene
   **EDIT NACH PROD ÜBERNEHMEN**-Schritt.

## Bewertungen

| Status | Bedeutung |
| --- | --- |
| Technisch plausibel | IPS-ID vorhanden, Instanzzugehörigkeit, Datentyp, eindeutige Rollen und ggf. Profilwerte plausibel |
| Manuell prüfen | Metadaten unvollständig, mehrdeutig, Statusbedeutung unklar oder Aktion nicht verifiziert |
| Ungültig | Nicht existierende IPS-ID, falscher Datentyp oder fehlende Pflicht-Rückmeldung |
| Legacy | `ai.type` noch nicht hinterlegt |

**Technisch plausibel ist niemals identisch mit einer fachlichen
Bestätigung am echten Gerät.** Ein erfolgreicher technischer Audit
sagt außerdem nichts über alle nicht konfigurierten Geräte außerhalb
der gewählten IP-Symcon-Suchwurzeln aus. Diese Prüfung bleibt wie bisher
bei der vollständigen Geräteinventur.

## Schutz

- Kein automatisches Aktivieren von `kiEnabled`, kein Ändern von
  `kiReadOnly` oder produktiver `RoomsCatalog`.
- Vor jedem EDIT-Schreibvorgang entsteht ein vollständiges
  `RoomsCatalogEdit_Backup_Technical_V339_...`-Skript.
- Die Übernahme wird blockiert, wenn die Vorschau älter als zehn
  Minuten ist, sich der EDIT-/PROD-Skriptinhalt oder die IPS-Variable
  verändert hat oder beim verlustfreien Schreiben ein Feld verloren
  gehen würde.
- Bestehende Legacy-Felder, benutzerdefinierte Felder, Räume,
  Aliasnamen, Backups und die historische Navigationskonfiguration
  bleiben erhalten.
- Sensible aktuelle Variablenwerte werden nicht gelesen; gespeichert
  werden nur Rolle, ID, Name/Ident/Profil, Assoziationen und
  Prüf-Zeitpunkt/-Signatur.
- Für neue Geräte und spätere Änderungen ist die Prüfung jederzeit
  erneut ausführbar; der Audit-Snapshot erlischt beim Quellwechsel.

## Vor V3.4

Erst wenn die acht Legacy-Fälle geklärt, alle erforderlichen Rollen
am realen Gerät bestätigt, die Unterschiede EDIT→PROD bewusst
veröffentlicht und die Geräteinventur über alle relevanten Suchwurzeln
geprüft ist, hat der KIHouseController eine verlässliche Datenbasis.
Das Modul verspricht zu keinem Zeitpunkt pauschal „100 %“.
