# RoomsCatalogConfigurator V3.3.25 – KNX-Prüfung ohne unnötige Sperren

## Korrektur 1: Dimmer mit gemeinsamer Prozentvariable
Eine *explizite*, bereits vorhandene Integer-/Float-ID mit Dimmerprofil (z. B. `~Intensity.100`) darf für `ai.variables.power` und `ai.variables.brightness` dieselbe Kennung haben. Die technische Prüfung erkennt diese Zuordnung als plausibel. Der abgeleitete logische Ein/Aus-Wert kann auf `0 = aus`, `>0 = ein` beruhen, **ohne** damit den physisch unabhängigen Istzustand zu beweisen.

Bei fehlender IPS-ID, nicht passendem Datentyp oder nicht zuordenbarem Profil bleibt die Prüfung streng. Kein Gerät wird dadurch geschaltet oder fachlich bestätigt.

## Korrektur 2: Jalousie mit getrenntem Status
- `position`: eigenständiger lesbarer Prozentwert; Profil `~Shutter`, `~ShutterPosition`, `~BehangPosition`, u. Ä.
- `tilt`: Lamellenstellung (`~Lamella`, `~Lamelle`, `~Tilt`).
- `action`: beschreibbare KNX-Fahrbefehle. Ein solcher Wert ist **keine** sichere Positionsrückmeldung.
- `status`: eigenes Offen/Geschlossen-Enum, beispielsweise mit Profil `~ShutterStatus.KNX`.

Eine lesbare, unabhängige Istposition reicht für eine **technische** Rückmeldungszuordnung: Eine zusätzliche manuelle Offen/Geschlossen-Codierung ist dann optional. Bei einem eigenständigen Status-Enum prüft die technische Auditlogik eindeutige Profil-Assoziationen (numerischer Wert ↔ Name). Ein Profil mit eindeutigem `Geöffnet` und `Geschlossen` kann als *Metadatenbeleg* für die technische Bedeutung dienen, **ohne die Werte zu speichern**.

**Strenger bleiben:** Eine ausdrücklich hinterlegte, widersprüchliche Offen/Geschlossen-Codierung bleibt manuell zu prüfen, ebenso eine gemeinsame ID für Soll-/Fahrposition und vermeintliche Istposition. Ein Positionsprozentwert `0/100` wird nicht ungeprüft als Statusenum interpretiert.

## Korrektur 3: Echte Fehler & Geräteübergreifende Mehrfachzuordnungen
Erhalten bleiben Warnungen/Fehler bei nicht vorhandener IPS-Variable, unpassendem Datentyp, falscher Schreibaktion und widersprüchlichen Rollen.

Zwei **unterschiedliche** KNX-Jalousien, die dieselbe explizite `status`-, `position`- oder `tilt`-Variable benutzen, werden in der Geräteliste **orange** hervorgehoben. Das muss man abklären: Es könnte eine versehentliche Kopie eines Gerätedatensatzes oder ein bewusst gemeinsam genutzter Gruppencontroller sein. Deshalb *niemals* automatische Zuordnungsänderung. Der JSON-Bericht enthält eine `sharedFeedbackAssignments`-Liste mit Gerätepfaden und betroffenen IDs.

## Korrektur 4: Profil-Assoziationen im JSON-Debug
`ROOMSCATALOG UEBERPRUEFEN` liefert die tatsächlich von IP-Symcon verfügbaren Profil-Assoziationen für konfigurierte KI-Rollen:
- Profil, ID, Datentyp und Schreibaktion
- max. 32 Assoziationen je Variable mit **Wert und Bezeichnung**
- `statusSemanticEvidence` (`position_only`, `profile_derived_not_saved`, `profile_confirmed`, `unconfirmed`, `conflict` oder `missing`)
- `positionFeedbackIndependent` (nur anhand der Metadaten und Rollentrennung bewertet)
- doppelt referenzierte Status-/Positions-/Lamellen-IDs zwischen Geräte-Einträgen

Keine Livevariablenwerte, kein PHP-Quelltext, keine Passwörter, keine Schaltaktionen. Alle Diagnosen sind **nur lesend**.

## Wichtig: unveränderte Bedienung und Freigaben
Der normale Geräteeditor, Raumverwaltung und die einzige **EDIT NACH PROD UEBERNEHMEN**-Aktion bleiben bestehen. Der JSON-Check ist **informativ und blockiert eine bewusste vollständige EDIT→PROD-Kopie nicht**. `RoomsCatalogEdit`, `RoomsCatalog PROD`, KI-Lese-/Schreibberechtigungen und fachliche Bestätigungen werden beim Prüfen weder korrigiert noch beschrieben.

## Regression
`tests/test_rooms_catalog_v339_technical.php` erweitert um:
- gleicher Intensity-Dimmerwert für power/brightness technisch plausibel
- fehlende Dimmer-ID weiterhin ungültig
- unabhängige Istposition und Lamellenwert ohne Statuszuordnung plausibel
- Statusprofil mit eindeutigen numerischen Assoziationen ohne Katalogänderung
- widersprüchliche gespeicherte Statuswerte bleiben Prüffälle
- Position gleich Fahrbefehl ist weiterhin nicht nachgewiesene echte Rückmeldung
- identische KNX-Status-/Positions-/Lamellen-IDs bei zwei Geräten erzeugen sichtbare orange Warnungen
- JSON enthält Profil-Wert/Namen und keine aktuellen IPS-Werte
- EDIT/PROD werden beim Prüfen nicht geschrieben

**Hinweis:** Der Zähler „technisch plausibel“ kann durch diese Regelkorrektur steigen. Das ist keine Bestätigung am physischen KNX-Gerät.
