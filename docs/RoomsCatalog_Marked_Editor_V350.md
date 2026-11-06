# RoomsCatalogConfigurator V3.5.0 – markierte Geräte gemeinsam und nacheinander bearbeiten

## Einstieg

**RoomsCatalogConfigurator → Geräte bearbeiten (RoomsCatalog EDIT)** enthält
unter der Gerätetabelle weiterhin die Markierungsspalte und die Schaltflächen
**ALLE SICHTBAREN MARKIEREN**, **ALLE GERÄTE MARKIEREN (AUCH GEFILTERTE)**,
**MARKIERUNGEN AUFHEBEN** sowie **MARKIERTE CLONEN/LÖSCHEN**.

Neu gibt es direkt daneben:

**MARKIERTE NACHEINANDER BEARBEITEN**

Das lädt die derzeit markierten Einträge in **einen** Bearbeitungsdurchlauf.
Jeder Eintrag hat seine eigene Zusammenstellung aller relevanten
IP-Symcon-Variablen, einschließlich Livewertvorschau und Zustandszuordnung.
Die Tabellenspalten/Einträge werden dafür nicht dupliziert.

### Modus A: Eine Einstellung für alle markierten Geräte

Der dauerhaft sichtbare, zugeklappte Bereich
**Gemeinsame Felder aller markierten Geräte ändern** bietet die bereits
bestehende, geschützte Sammelbearbeitung mit:

- **Gerätetyp** gemeinsam ändern (oder unverändert lassen),
- **KI-Freigabe** unverändert / aus / nur lesen / schalten,
- bei einem ausgewählten Gerätetyp optional eindeutige fehlende
  Variablenbindungen **pro Gerät** vorschlagen,
- **Prüfstatus** bei allen auf offen setzen.

Zuerst **SAMMELBEARBEITUNG VORSCHAU**,
danach **VORSCHAU NACH ROOMSCATALOG EDIT ÜBERNEHMEN**.
Die Vorschau ist an die ausgewählten Geräte, die Einstellungen und den
EDIT-Quellhash gebunden; Änderungen machen sie ungültig.
**Individuelle IPS-Variablen-IDs werden dabei nicht auf andere Geräte kopiert.**

### Modus B: Jedes markierte Gerät innerhalb derselben Ansicht bearbeiten

1. Geräte in der Tabelle markieren.
2. **MARKIERTE NACHEINANDER BEARBEITEN** drücken.
3. In der sich öffnenden Sektion mit **◀ ZURÜCK** / **WEITER ▶**
   durch alle markierten Geräte blättern, ohne jedes Zahnrad öffnen zu müssen.
   Über die Geräteliste direkt zu einem bestimmten Eintrag springen.
4. Zum aktuell gewählten Gerät Name, Synonyme, Entity-ID,
   alle zu seinem Typ gehörenden Variablenbindungen,
   Roh-/Anzeigewert und Profil, die zugehörigen
   Offen/Zu- oder Ein/Aus-Mappings, KI-Rechte und Prüfstatus sehen.
5. **DIESES GERÄT IN EDIT SPEICHERN** oder **SPEICHERN UND WEITER ▶**.
   Im zweiten Fall wird der nachfolgende Eintrag angezeigt.
6. Mit **DURCHLAUF BEENDEN** den temporären Durchlauf schließen.

**Wichtig:** Mit **VOR / WEITER** werden ungespeicherte Änderungen
am bisherigen Eintrag **verworfen**. Nur **SPEICHERN** schreibt.
Jeder Eintrag wird ausschließlich in **RoomsCatalogEdit** bearbeitet,
niemals in PROD. Bei einem anderen EDIT-Schreibvorgang während des
Durchlaufs blockiert der Quellhash die veraltete Speicherung;
ein neuer Durchlauf muss begonnen werden.

### KNX-Bewegungsmelder als Beispiel

In der Tabelle stehen etwa:

| Raum | Bereich | Gruppe | Gerät |
| --- | --- | --- | --- |
| Büro | sensoren | bewegung | EG Büro Bewegung |
| Küche | sensoren | bewegung | EG Küche Bewegung |
| Wohnzimmer | sensoren | bewegung | EG Wohnzimmer Bewegung |

Alle drei markieren, Durchlauf beginnen und nach und nach die
jeweilige **Bewegungs-Istvariable** sowie das
Ein/Aus-Mapping auswählen. Das Profil und der Livewert
erscheinen unmittelbar neben der Variablenauswahl.
**Kein gemeinsames, festes Motion-ID-Feld für alle Geräte** –
jedes Gerät behält seine eigene IPS-ID.

Wenn ein bereits verwendeter Legacy-Statuswert aus exakt derselben
zugeordneten Istvariable kommt, wird er beim Austausch der
Variablenbindung mit korrigiert, damit AI- und Legacy-Rückmeldung
nicht auf verschiedene alte IDs zeigen. Explizit anders zugeordnete
Rückmeldungen bleiben unangetastet.

### Sicherheit, Datenbestand, Grenzen

- Alle unveränderten Metadaten, individuelle Räume, Bereiche,
  Gruppen, Geräteschlüssel und unbekannte Zusatzfelder bleiben erhalten.
- **Typ oder Raum wechseln:** weiterhin über das vorhandene Zahnrad
  oder den gemeinsamen Typ-Assistenten – nicht durch die Vor/Zurück-Navigation.
- Der Durchlauf speichert nur das aktuell angezeigte Gerät, prüft die
  Existenz neu ausgewählter IPS-Variablen und kontrolliert den
  persistierten Datensatz vor dem nächsten Schritt.
- Der Durchlauf ist eine eigene Auswahl-Snapshot; selbst wenn
  IP-Symcon nach dem Speichern die Tabellenmarkierungen zurücksetzt,
  bleiben die ursprünglich markierten Geräte über Vor/Zurück erreichbar.
- KI-Schaltrechte werden nicht automatisch vergeben. Die UI zeigt
  die vorhandenen Schalter an und Änderungen werden bewusst gespeichert.
- Für PROD gilt weiterhin die vorhandene separate Freigabe
  **EDIT NACH PROD ÜBERNEHMEN**.
