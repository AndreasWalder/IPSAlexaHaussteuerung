# Alexa V3.4.3: direkte Hausfragen zuverlässiger erfassen

## Auswertung der Live-Logs vom 04.11.2026, 21:04–21:05

**21:04:58 und 21:05:11**: Alexa lieferte `IntentRequest / GetHaus`, alle Slots ohne `value`, `VoiceRouter.category=passthrough`, `transcript=""`; danach kam `ROUTE_ALL → main_launch`.

**21:05:40**: Alexa lieferte endlich einen echten Fragetext, allerdings stückweise und grammatikalisch ungenau: `Action=was`, `Device=ist temperatur`, `Room=von wohnzimmer`, `Alles=die`. Der Router setzte `category=query`, die V3.4.2-Sperre meldete `allowed=true`, `HomeAgentVoice.received` und `KIRoomsCatalog.catalog.loaded` waren vorhanden. Die vorliegende Logkopie endet bei `KIRoomsCatalog.openai.start`; daraus folgt **kein** nachweisbarer Backend-Fehler.

Die zwei tatsächlich unterschiedlichen Ursachen sind deshalb:
1. **Alexa-/ASR-/Interaction-Model-Erkennung**: Bei leerem GetHaus-Payload bekommt PHP keine Audiodaten und keinen Wortlaut. Das kann der KI-Router nicht rekonstruieren.
2. **KI-Routing**: Sobald Alexa einen Fragetext liefert, funktioniert die Weitergabe nach V3.4.2 nachweislich.

## Neues Modell für natürliche Fragen

**Wichtig: Die Alexa Developer Console muss manuell angepasst und neu gebaut werden.**
Ein IP-Symcon-Modulupdate ändert das Interaktionsmodell bei Amazon nicht.

Datei: [Alexa_Hausfragen_Intent_Paket.json](Alexa_Hausfragen_Intent_Paket.json)

Füge die fünf **neuen** Intents im bestehenden `de-DE`-Interaktionsmodell hinzu, ohne `GetHaus`, Navigation oder andere Intents zu löschen:

| Intent-Name | Slot | Sample Utterance | Beispiel |
|---|---|---|---|
| `HausWelcheIntent` | `Frage: AMAZON.SearchQuery` | `welche {Frage}` | „Welche Fenster sind offen?“ |
| `HausWieIntent` | `Frage: AMAZON.SearchQuery` | `wie {Frage}` | „Wie warm ist es im Büro?“ |
| `HausWasIntent` | `Frage: AMAZON.SearchQuery` | `was {Frage}` | „Was ist die Temperatur im Wohnzimmer?“ |
| `HausIstIntent` | `Frage: AMAZON.SearchQuery` | `ist {Frage}` | „Ist ein Fenster offen?“ |
| `HausSindIntent` | `Frage: AMAZON.SearchQuery` | `sind {Frage}` | „Sind alle Jalousien geschlossen?“ |

Die Namensschreibweise ist Teil des Protokolls und muss exakt übernommen werden.

### Warum getrennte Intents?

Ein `AMAZON.SearchQuery`-Slot erhält **nicht verlässlich das feste Anfangswort** aus der Sample Utterance. Mit einem einzigen Intent für `welche {Frage}` und `wie {Frage}` könnte PHP nicht erkennen, welches Fragewort vorne fehlte. Bei diesen fünf separaten Intents ist das Anfangswort durch den `intent.name` eindeutig.

Das PHP-Action-Skript fügt das passende Wort vor `slots.Frage.value` wieder ein und sendet an Router und KI einen zusammenhängenden Satz. Ist das Wort bereits im Slot enthalten, wird es nicht verdoppelt. Bei komplett leerem `Frage`-Slot wird kein Text erfunden und Alexa bittet um Wiederholung.

`HomeAgentFrageIntent` mit `frage {Frage}` bleibt als zusätzlicher, expliziter und vergleichsweise robuster Ausweichweg bestehen. Ein `AMAZON.SearchQuery`-Slot braucht eine gesprochene Trägerphrase, und pro Sample darf er nicht mit anderen Slots kombiniert werden.

### Schritt für Schritt in der Alexa Developer Console

1. Deinen bestehenden Custom Skill öffnen → **Build → Interaction Model → JSON Editor**.
2. Bestehendes `interactionModel.languageModel.intents`-Array öffnen. **Vorher eine Kopie des gesamten bisherigen JSON sichern.**
3. Nur die **fünf Objekte** aus `Alexa_Hausfragen_Intent_Paket.json` an das Ende des bereits vorhandenen `intents`-Arrays anhängen. Nicht das komplette Modell durch das Beispiel ersetzen.
4. **Save Model**, dann **Build Model**. Erst nach erfolgreichem Build gelten die neuen Spracherkennungsregeln; gegebenenfalls das Test-Stage bzw. die verwendete Live-Skill-Version beachten.
5. Auf dem betreffenden Alexa-Gerät die geöffnete Haussteuerung neu starten und die oben genannten Fragen testen.

Amazon-Dokumentation zu `AMAZON.SearchQuery`: https://developer.amazon.com/en-US/docs/alexa/custom-skills/slot-type-reference.html

## Verbesserungen auf der Symcon-Seite

- Neue Intents mit einem `Frage`-Slot werden direkt als Hausfrage eingestuft, das ursprüngliche Fragewort wird automatisch ergänzt.
- Für `GetHaus` **ohne irgendeinen erkannten Slot** wird nicht mehr kommentarlos die Hauptkachel gerendert. Stattdessen erklärt Alexa, dass kein Fragetext übergeben wurde, und nennt einen Beispielsatz.
- Das alleinstehende Wort `Frage` unter `GetHaus` führt nun zu einer verständlichen Frageaufforderung statt zum Heizungsrenderer.
- Echte Kachelnavigation, explizite Schaltbefehle und Rechte bleiben unverändert.
- Die bestehende Module-Control-Installation ersetzt den begrenzten `HOME_AGENT_VOICE_HOOK` des vorhandenen Action-Skripts beim **Übernehmen**. Das ist **keine** automatische Aktualisierung des Amazon-Interaktionsmodells.

## Diagnose nach dem Update

Bei einem Treffer des neuen Alexa-Intents:

```text
REQUEST_META {"intent":"HausWelcheIntent"}
HomeAgentVoice.slot_capture {"intent":"HausWelcheIntent","slotPresent":true,"prefix":"welche","chars":25}
VoiceRouter.decision {"category":"query", ...}
HomeAgentVoice.route_gate {"allowed":true,"reason":"dedicated_question_priority", ...}
HomeAgentVoice.received ...
KIRoomsCatalog.catalog.loaded ...
KIRoomsCatalog.tool ...
KIRoomsCatalog.answer ...
```

Bei leerer älterer GetHaus-Erkennung:

```text
REQUEST_META {"intent":"GetHaus"}
VoiceRouter.decision {"category":"passthrough", ...}
HomeAgentVoice.empty_gethaus {"reason":"alexa_sent_no_slot_values", ...}
```

**Wichtig:** Fehlt ein `Haus...Intent`, aber kommt `GetHaus` ohne Slotdaten, liegt der Fehler vor der KI, im von Alexa gebauten Request. Die Worterkennung kann IP-Symcon nicht anhand eines leeren Requests nachträglich verbessern. Die neuen Logeinträge enthalten keine API-Tokens oder Passwörter.

CI-Regressionen: `tests/test_alexa_voice_capture_v343.php` plus die bestehenden Tests für Navigation, Schaltrechte und V3.4.2-Fragepriorität.
