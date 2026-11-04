# RoomsCatalogConfigurator V3.3.26 – KNX-Statuswerte gezielt korrigieren

## Basis: echte IP-Symcon-Diagnose, 04.11.2026

Der vollständige vom Nutzer kopierte Bericht enthält 167 Geräte, davon 140 technisch plausibel, 27 offen und 0 ungültig. 0 EDIT/PROD-Unterschiede. Die offenen Befunde gruppieren sich auf:

1. **25×** `state_mapping_conflict`: eigene `ai.stateMapping.open/closed`-Werte widersprechen dem explizit zugeordneten Profil `~ShutterStatus.KNX`. Die KNX-Profil-Assoziationen sind eindeutig `0=N/A`, `1=Geöffnet`, `2=Geschlossen`. Die Fahraktion hat ein **anderes Profil** und benutzt zulässig z. B. `0=Öffnen, 100=Schliessen, 999=Stop`. Position in Prozent ist ebenfalls ein anderes Signal.
2. **1× Büro-Jalousie:** eigene Positionsvariable `#38429` mit Profil `~ShutterPosition.100`, schreibbar, mit ausdrücklich belegten Endpunkt-Assoziationen `0=Geöffnet, 100=Geschlossen`, separate Fahraktion `#28160`. Dass die Positionsvariable auch eine IPS-Aktion anbietet, verbietet nicht die Metadaten-Rückmeldung.
3. **1× Sauna-Dimmer:** `power` und `brightness` sind beide die skalierte Integer-Variable `#48506` mit `~Scaling.KNX`, separate Steuerung `#50744` mit `~Intensity.100`. Das ist eine zulässige gemeinsame Skalierung, nicht automatisch ein physisch unabhängiger Iststatus.

## Überarbeitete technische Prüfung

- Separat belegte, beschreibbare Position wird nicht bloß wegen `HasAction` als ungültig gewertet. Ein **identisches Objekt** für Fahraktion/Sollposition/Istposition bleibt prüfbedürftig.
- Wenn keine eigene Statusvariable konfiguriert ist, dürfen **explizit benannte Profil-Assoziationen** an einer getrennten Positionsvariable die Endpunkt-Bedeutung belegen, nicht aber vermutete Prozentkonventionen.
- Dimmer mit `~Scaling.KNX` und gemeinsamem Integer-/Float-Wert für Power/Helligkeit werden als plausibel erkannt. Nicht existente IDs, falsche Datentypen und problematische Aktionen bleiben sichtbar.
- Ein **explizit gespeicherter** Widerspruch von `ai.stateMapping` gegen das echte Statusprofil bleibt auch in V3.3.26 eine technische Warnung – bis die Werte im Katalog korrekt sind.

## Optionale, explizit freizugebende EDIT-Korrektur

Im einfachen RoomsCatalogConfigurator unter **RoomsCatalog überprüfen – Diagnose für ChatGPT** ist die neue, eingeklappte Funktion **KNX-Jalousie-Statuswerte sicher in EDIT korrigieren**.

1. **1 – STATUS-KORREKTUR VORSCHAU**: liest nur EDIT, PROD und Profilmetadaten; listet die betroffenen Geräte mit Alt-/Neu-Paaren. Keine Datei wird geschrieben.
2. **2 – GEPRUEFTE STATUSWERTE NACH EDIT**: erfordert zusätzlich eine ausdrückliche Bestätigung. Es werden nur eindeutig betroffene Statuszuordnungen angepasst, niemals Fahraktionen. Vorher: verifiziertes Backup des exakten vorherigen RoomsCatalogEdit-PHP-Quelltextes.

**Automatisch angepasst werden ausschließlich** Geräte, die alle Bedingungen erfüllen:
- Gerätetyp `blind`, fachlich noch **nicht bestätigt**
- in `ai.variables.status` hinterlegte, existierende Integer-Variable, verschieden von Fahraktion und Positionsvariable
- Profil exakt `~ShutterStatus.KNX`; zwei eindeutige **numerische** Profil-Assoziationen `Geöffnet` und `Geschlossen`
- das bisherige `ai.stateMapping` ist genau `open=0, closed=100`, passend zur bekannten alten Prozentkonvention, aber unpassend zum Statusenum
- Neuwerte weichen davon ab und sind verschieden.

**Ausgeschlossen**: Alle anderen State-Mappings, fehlende oder mehrdeutige Profil-Assoziationen, fachlich bestätigte Geräte, alle nicht betroffenen Felder. Eine 0/1- oder selbst definierte Belegung wird **nicht** ohne explizite Entscheidung geändert.

Vor dem EDIT-Schreiben werden EDIT- und PROD-Hashes mit der Vorschau (max. 15 min) verglichen und alle Profil-Metadaten erneut geprüft. Zusätzlich gibt es Backup-Verifikation, Schreib-Verifikation und gegebenenfalls Rollback. PROD bleibt unverändert; die bisherige einzige **EDIT NACH PROD UEBERNEHMEN**-Funktion bleibt separat.

## Ablauf nach Modulupdate

1. „ROOMSCATALOG ÜBERPRÜFEN“ ausführen und den neuen Zähler prüfen.
2. Status-Korrekturvorschau öffnen. **Die tatsächliche Vorschauzahl** ist entscheidend; sie kann kleiner als 25 sein, wenn die gespeicherten Altwerte nicht exakt 0/100 entsprechen.
3. Geräte und Alt-/Neu-Werte kontrollieren. Nur dann die ausdrücklich bestätigte EDIT-Korrektur ausführen.
4. „ROOMSCATALOG ÜBERPRÜFEN“ erneut ausführen.
5. Erst bei zufriedenstellender Diagnose den bestehenden „EDIT NACH PROD ÜBERNEHMEN“-Button verwenden.

Weder Statusprüfung noch Vorschau oder EDIT-Korrektur schaltet reale KNX-Geräte. „Technisch plausibel“ ist keine fachliche Bestätigung an der echten Anlage.
