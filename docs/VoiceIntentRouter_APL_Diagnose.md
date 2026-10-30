# Alexa-Sprachrouter: UI-Kacheln, KI-Anfragen und APL-Kontext

## Zweck

Der lokale `VoiceIntentRouter` erkennt die Absicht eines Sprachbefehls **vor dem KI-Aufruf**. Eindeutige Kachel-Namen navigieren unmittelbar über die bisherigen `ROUTE_ALL`-Renderer. Fragen und eindeutige Schaltbefehle werden weiterhin mit den vorhandenen Freigaben über RoomsCatalog oder den optionalen Home-Agent-Backend verarbeitet. Der Router selbst schaltet **niemals** ein Gerät.

## Beispiele

| Sprache / UI | Klassifizierung | Verhalten |
| --- | --- | --- |
| „Licht“ / „Jalousie“ / „Lüftung“ | navigation | Vorhandene UI-Kachel aufrufen, keine OpenAI-Kosten |
| „Öffne Licht“ / „Öffne die Kachel Jalousie“ / „Gehe zum Menü Licht“ | navigation | Kachel öffnen |
| „Zurück“ | navigation | Vorheriger Bereich für dieses Alexa-Gerät; sonst Hauptmenü |
| APL-Button mit `GetHaus` und Kachelkennung | apl_navigation | Vorhandene APL-/Legacy-Navigation, KI wird übersprungen |
| Andere APL-UserEvents (z. B. Dimmer) | apl_action | Bestehende UI-Handler, KI wird übersprungen |
| „Welche Lichter sind an?“ | query | KI-Abfrage der Geräte |
| „Welche sind an?“ nach Öffnen der Licht-Kachel | query | KI erhält den letzten UI-Bereich als **Lese-Kontext** |
| „Schalte das Licht im Wohnzimmer ein“ | action | Vorhandene KI-Freigabe und Gerätesuche; keine neue Berechtigung |
| „Öffne die Jalousie“ | action | Physische Aktion, nicht Kachelnavigation |
| „Licht aus“ | clarify | Rückfrage statt vermuteter Schaltaktion |

Der Kontext gilt **höchstens 30 Minuten** und ist pro Alexa-Gerät getrennt. APL-Viewport-Metadaten werden nur als Diagnose gespeichert: Bildschirmunterstützung, Viewport-Modus/Form, Anzahl der APL-Argumente und erkannte Event-Art. **Es werden keine Roh-APL-Arguments, APL-Präsentationstokens, Alexa-DeviceIDs oder Session-IDs gespeichert.** Geräte werden über einen verkürzten Hash pseudonymisiert.

## Diagnose in IP-Symcon

Nach dem Modul-Update und **Übernehmen** erscheint

`IPSAlexaHaussteuerung → Diagnose → KI-Sprachrouter und APL-Navigation`

mit:

- **Letzte Routerentscheidung (JSON)**: Zeitpunkt, anonymer Alexa-Gerätehash, erkannte Kategorie, Begründung, UI-Ziel, Intent, Viewport-Daten und optional der erkannte Sprachtext.
- **Letzte 80 Routerentscheidungen (JSON)**: Verlauf inklusive erfolgreicher Renderer-Navigation (`ui_rendered`).
- **Routingzähler (JSON)**: wie oft Navigation, KI-Abfrage, KI-Aktion, Rückfrage oder APL-Event erkannt wurde.
- **Alexa-UI-Kontext je Gerät (JSON)**: letzte und vorherige Kachel mit Zeitstempel; alte Geräte-Einträge werden bereinigt.
- **Router-Datenspeicher (nicht bearbeiten)**: ausgeblendete Rohdatenvariable; wird beim Update nicht überschrieben.

Im Alexa-Protokoll erscheinen zusätzlich `VoiceRouter`-Einträge. Die protokollierten Kategorien sind `navigation`, `query`, `action`, `clarify`, `apl_navigation`, `apl_action`, `passthrough` und `ui_rendered`.

## Gesprochene Texte speichern und loggen

In der Modulkonfiguration unter **Alexa-UI-Navigation, Sprachrouter & Diagnose** steht die Option **Gesprochene Texte in Diagnosevariablen und Alexa-Log speichern** (standardmäßig **ein**, wie gewünscht). Pro Äußerung werden maximal **400 Zeichen** gespeichert; typische Passwort-, PIN-, API-Key- und Tokenmuster werden ausgeblendet.

Wenn du die Option ausschaltest, werden neue Äußerungen ohne Text gespeichert. **Bereits gespeicherte Texte im Diagnoseverlauf und in früheren IP-Symcon-Logdateien werden nicht rückwirkend gelöscht.** Sie sind lokal zu schützen; bei Zugriff auf Symcon-Logs können befugte Benutzer den Sprachtext lesen. Keine vollständigen Logs ungeschützt auf GitHub veröffentlichen.

## Architektur und Update

- Neuer `resources/helpers/VoiceIntentRouter.php`, vom Modul als Helper-Skript installiert.
- Der bestehende `action_entry.php`-KI-Hook prüft den lokalen Router, bevor er einen API-Aufruf ausführt.
- Die vorhandenen `$NAV_MAP`, `ROUTE_ALL` und UI-Renderer bleiben maßgeblich; keine zweite Geräte-Steuerungslogik und keine neuen Geräte-Schreibrechte.
- Der letzte UI-Bereich wird nach einem **erfolgreichen** Renderer-Aufruf aktualisiert.
- Für Folgefragen wird der UI-Bereich als Hinweis an beide KI-Backends übergeben, niemals als Berechtigung zur Schaltung.
- Beim Update ersetzt `module.php` nur den vorhandenen `HOME_AGENT_VOICE_HOOK`-Abschnitt des Action-Skripts; übrige Änderungen bleiben bestehen. Der vorhandene lokale RoomsCatalog wird **nicht** durch eine Vorlage überschrieben.

## Tests

`php tests/test_voice_intent_router.php`

Die Tests prüfen klassische Kacheln, Formulierungen, Navigation zurück, APL-Events, KI-Abfragen/Schaltbefehle, mehrdeutige Befehle, Hash-Trennung pro Gerät, Transkript/Redaktion, 30-Minuten-Kontext und die Integration in Modul und Action.

Die tatsächliche Echo-Show-/Alexa-APL-Übertragung muss nach Installation mit Testbefehlen vor Ort geprüft werden. Die Sprach-Erkennung durch Alexa und die APL-Daten sind geräte- und Intent-abhängig.
