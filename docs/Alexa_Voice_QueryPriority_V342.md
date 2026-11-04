# Alexa V3.4.2 – erkannte Hausfragen vor der Kachelnavigation ausführen

## Fehlerbild vom 04.11.2026

Die Alexa-Logs zeigten um **20:46:52** eindeutig `HomeAgentFrageIntent`, `category=query`, `transcript="welche fenster sind offen"`. Danach folgte trotzdem `ROUTE_ALL → main_launch`, ohne `HomeAgentVoice.received`. Um 20:29 war dieselbe Frage zuvor bis zum KI-Controller durchgelaufen.

Der Alexa-Freitext und die Entscheidung des VoiceRouters waren daher bereits vorhanden. Das Problem lag in der **nachgelagerten Routing-Sperre des Action-Skripts**; das Log verriet bislang nicht, welche Bedingung einen Aufruf abgefangen hat.

## Korrektur

Das Action-Skript prüft jetzt zuerst das Ergebnis des VoiceRouters:

- **Eine bestätigte Frage** über `HomeAgentFrageIntent` wird der KI übergeben. Inkonsistente Launch-, Back- oder APL-Flags aus der SDK-Darstellung dürfen diesen eindeutigen Fragetyp nicht wieder auf die Startkachel leiten. Der Name des Amazon-Intents wird unverändert weiterverwendet.
- **Eine bestätigte Frage** über `GetHaus` geht ebenfalls in die KI-Verarbeitung, sofern es sich nicht um ein echtes APL-Ereignis oder eine ausdrücklich erkannte Kachelnavigation handelt.
- **Navigation** wie `Licht`, `Jalousie` oder `Zurück` bleibt in der Kachelsteuerung; APL-Klicks bleiben APL.
- **Befehle** wie `Schalte das Licht ein` unter `GetHaus` behalten ihren bisherigen klassischen Pfad. Die KI bekommt dadurch keine weiteren Schaltrechte.
- Ist der KI-Backend deaktiviert, fehlt der Text oder schlägt die KI fehl, wird bei einer erkannten Frage eine verständliche Sprachausgabe zurückgegeben statt unbemerkt `main_launch` zu öffnen.

Es werden keine Geräte, Rechte, Konfigurationen oder `RoomsCatalog*`-Skripte geändert.

## Neue Diagnose

Bei jeder dedizierten Frage oder vom VoiceRouter erkannten Hausfrage erscheint:

```text
HomeAgentVoice.route_gate {"category":"query","intent":"HomeAgentFrageIntent",
  "allowed":true,"reason":"dedicated_question_priority", ...}
HomeAgentVoice.received ...
KIRoomsCatalog.catalog.loaded ...
KIRoomsCatalog.tool ...
KIRoomsCatalog.answer ...
```

Weitere mögliche Gründe sind `recognized_question_priority`, `apl_event`, `legacy_navigation` und `launch_back_or_exit`. Die Flags `aplEvent`, `aplArgsCount`, `launch` und `back` helfen bei der Ursachenanalyse. Es werden weder API-Schlüssel noch gesprochene Passwörter in diesen neuen Einträgen protokolliert.

Falls eine bestätigte Frage ausnahmsweise nicht weitergeleitet wird, folgt `HomeAgentVoice.query_blocked` mit Begründung und einer klaren Alexa-Antwort.

## Aktualisierung und Abnahme

1. GitHub-Modul in der IP-Symcon **Module Control aktualisieren**.
2. In der Alexa-Haussteuerungsinstanz **Übernehmen** drücken. Der vorhandene `HOME_AGENT_VOICE_HOOK` im Action-Skript wird aus dem Modul-Template aktualisiert. Bereits angelegte Skript-IDs bleiben erhalten.
3. `Welche Fenster sind offen?`, `Wie warm ist es im Büro?` und `Welche Jalousien sind zu?` ausprobieren. Im kopierbaren 500er-Log steht nun die Entscheidung vor dem Backend-Aufruf.
4. `Licht`, `Jalousie`, `Zurück` sowie einen normalen Schaltbefehl separat ausprobieren, damit Navigation und klassische Befehle nachweislich unverändert funktionieren.

Regression: `tests/test_alexa_voice_question_priority_v342.php`. Sie prüft die originale, aus `action_entry.php` extrahierte Routing-Closure zusammen mit dem tatsächlichen `VoiceIntentRouter` und schützt die automatische Installation des Hooks.
