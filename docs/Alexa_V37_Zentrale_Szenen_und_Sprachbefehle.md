# V3.7 – Eigene Alexa-Befehle, Geräteszenen und freie Antworten

## Fundstellen im bisherigen Code (vor V3.7)

- `IPSAlexaHaussteuerung/resources/action_entry.php`: 
  `gr_match_who_are_you`, `gr_match_who_are_your_creator` und `gr_match_who_are_andrea`.
  Die Fragen **„Wer bist du?“**, **„Wer ist Andreas?“**, **„Wer ist Andrea?“** sowie Alternativphrasen
  waren fest im PHP-Code hinterlegt, einschließlich fester Antworttexte.
- `action_entry.php`: eigene Fallbacks für Mähroboter/Roborock und Außentemperatur,
  mit fester Antwort und festem Thermometer-StandardCard-Bild.
- `IPSAlexaHaussteuerung/resources/renderers/RenderJalousie.php`:
  globale Sprachbefehle für **Jalousie öffnen / schließen** mit bestehenden
  Bildern `JalousieTag.png` bzw. `JalousieAbend.png`, sowie Einzel- und
  Gruppensteuerung unter den bisherigen Sicherheitsflags.
- `resources/helpers/VoiceIntentRouter.php`:
  Navigation, Kachel-Aliase und Trennung zwischen Sprachsteuerung und Kachelöffnen.

**Bestehende Befehle, APL-Kacheln, Router und Jalousiebilder bleiben als Fallback erhalten.**
Der V3.7-Dispatcher greift nur, wenn eine explizit konfigurierte **aktive** Regel exakt passt.

## Zuordnung und Oberfläche

### RoomsCatalogConfigurator

Im Zahnrad eines Gerätes erscheint die schreibgeschützte Sektion
**„V3.7 – erkannte Gerätefähigkeiten“**. Verfügbare Lese- und
Schaltmöglichkeiten werden aus bestehenden Typen, Variablen-IDs und
IP-Symcon-Aktionen abgeleitet. Es ist kein zusätzliches
Fähigkeiten-Mapping zu pflegen.

Wichtig: „technisch möglich“ ist nicht gleich „von Alexa erlaubt“.
Szenen nutzen ausschließlich bereits fachlich bestätigte und ausdrücklich
zum KI-Schalten freigegebene Fähigkeiten des **produktiven** RoomsCatalog.

### IPSAlexaHaussteuerung

Neuer Bereich **„V3.7 – Szenen und eigene Alexa-Befehle“**:

- **Antwort / Frage**: Name, beliebige alternative Sprachphrasen (Komma-getrennt),
  freier Antworttext (bis 800 Zeichen), optional eine öffentlich erreichbare
  HTTPS-Bild-URL für eine Alexa-Karte.
- **IP-Symcon-Skript**: vorhandenes Skript per `SelectScript` wählen.
  Eine aktivierte Skriptregel muss fachlich bestätigt werden. Das Skript wird
  ohne von der Sprache abgeleitete PHP-Ausdrücke oder Parameter gestartet.
  **IP-Symcon-Skripte dürfen trotzdem beliebige Aktionen enthalten**:
  vor dem Freigeben den Skriptinhalt prüfen.
- **Geräteszene**: mehrere Geräte aus RoomsCatalog auswählen und je Gerät
  die bestätigte Fähigkeit `power`, `brightness`, `targetPosition` oder
  `targetTemperature` sowie Sollwert hinterlegen. Maximal 12 Aktionen.
  Alle Aktionen und Grenzen werden geprüft, **bevor** die erste Aktion ausgeführt wird.
  Falls eine Aktion trotzdem zur Laufzeit fehlschlägt, meldet Alexa den
  Teilfehler; das System behauptet keine transaktionale Rollback-Garantie.

Die Regel kann beliebig viele alternative Gerätenamen oder Formulierungen
enthalten (je Regel höchstens 20 eindeutige Phrasen). Bei gleichen
Sprachphrasen in verschiedenen Regeln wird die Freigabe blockiert.
Bisherige reservierte Kachelnamen wie `Visu`, `Jalousie` oder
`Licht` dürfen nicht als alleinige neue Sprachregel verwendet werden.

Ein optionales Feld **Alexa-Intent** erlaubt einen eigenen
slotlosen Intentnamen wie `GutenAbendIntent`.

## Beispiel: „Guten Abend“

1. `NEUEN BEFEHL ANLEGEN`: Name `Guten Abend`, Satz `Guten Abend`,
   Typ **Geräteszene**.
2. Über das Zahnrad die Geräte aus dem RoomsCatalog auswählen:
   Wohnzimmer-Deckenlicht `power=false`,
   Stehlampe `brightness=20`,
   Jalousie `targetPosition=100` (nur wenn die Sollposition und
   KI-Schaltberechtigung im RoomsCatalog entsprechend existieren).
3. Alternativ Typ **IP-Symcon-Skript** wählen und dein bisheriges
   Abend-Skript im Skript-Auswahldialog hinterlegen.
4. Eigene Antwort festlegen, zum Beispiel „Die Abendbeleuchtung ist aktiviert“,
   optional Bild über HTTPS hinterlegen.
5. **Aktiv** und **fachlich bestätigt** setzen.
6. `SPRECHREGELN PRÜFEN (VORSCHAU)`, danach
   `GEPRÜFTE SPRECHREGELN NACH PROD`.
   Vor PROD wird das komplette ursprüngliche `SystemConfiguration`
   gesichert; `RoomsCatalog` und andere `SystemConfiguration`-Bereiche
   bleiben unangetastet.

**Kein automatisch erzeugter oder ausgeführter Alexa-Befehl während der Bearbeitung.**

## Bestehende hartcodierte Regeln übernehmen

Mit **„ALTANTWORTEN ALS ENTWURF EINFÜGEN“** erscheinen
die häufigsten vorhandenen Frage-Antwort-Regeln und die bisherigen
Jalousie-auf/zu-Kommandos als **deaktivierte Vorschläge**.
Sie ersetzen keine bestehenden Live-Funktionen und starten nichts.
Jede Regel kann einzeln angepasst, aktiviert und später nach PROD gebracht werden.

Bisherige individuell angepasste Antworten, z. B. zu Personen, werden
nicht automatisch aus PHP-Quelltext überschrieben oder als exakt
übernommene Texte ausgegeben. Ihre neue Formulierung ist frei wählbar.

## Alexa Interaction Model – entscheidende Voraussetzung

Die PHP-Laufzeit kann nur das ausführen, was Alexa mit dem Skill
als erkannten Intent/Slot an den Skill übergibt.

Für **„Guten Abend“** ohne bestehende Slot-Erkennung im Alexa Developer
Console Interaction Model einen eigenen Intent
`GutenAbendIntent` mit Sample Utterance `guten abend` eintragen,
das Skill-Modell aktualisieren und bauen. In V3.7 beim Sprachbefehl
unter **Alexa-Intent** genau `GutenAbendIntent` hinterlegen.
Dann kann die PHP-Action den validierten Intentnamen **auch ohne Textslot**
der freigegebenen Regel zuordnen.

Neue Phrasen werden nicht automatisch beim Alexa-Entwicklerportal veröffentlicht.
Die Konfigurationsoberfläche listet alle Phrasen und Intentnamen kopierbar auf.

## Versionssicherheit

- Datenmodell: `SystemConfigurationEdit['voiceRules']` / `SystemConfiguration['voiceRules']`.
- Reguläre Navigation in `SystemConfiguration['navigation']` und
  RoomsCatalog in PROD sind eigenständig.
- Jede Bearbeitung in EDIT erzeugt/erhält verifizierte Sicherungen.
- Jede neue PROD-Freigabe braucht eine frische, quellhashbasierte Vorschau,
  geschützte Backups und unabhängige Bestätigung.
- Kein frei interpretierter KI-Schaltbefehl; die Regeln werden vor dem
  bestehenden Router deterministic nach exakten Phrasen oder expliziten
  Alexa-Intentnamen ausgewertet.
- Vorhandene angepasste IP-Symcon-Action-Skripte werden **nur** am
  eindeutig geprüften Integrationsanker nach Originalbackup ergänzt.
  Sollte dieser Anker fehlen, wird die Datei nicht pauschal ersetzt;
  die Diagnose meldet den fehlenden Anker.

Tests: `php tests/test_voice_rules_v37.php`,
`php tests/test_voice_rules_editor_v37.php` und vollständige PHP-CI.
