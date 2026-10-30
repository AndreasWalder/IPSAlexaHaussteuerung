# Home Agent über Alexa: HomeAgentFrageIntent

Dieser Sprachkanal ergänzt die bestehenden GetHaus-/Alexa-APL-Funktionen um freie Hausfragen.

## 1. Alexa-Skill (Developer Console)

**Build → Interaction Model → Intents → Add Intent → Create custom intent**:

- Name: **HomeAgentFrageIntent** (exakt)
- Ein Slot: **Frage** (exakt), Typ **AMAZON.SearchQuery**
- Sample Utterances:

```text
frage {Frage}
sag mir {Frage}
sage mir {Frage}
prüfe {Frage}
ich möchte wissen {Frage}
kontrolliere {Frage}
```

Danach **Save Model → Build Model**. Unter **Test** den Skill aufrufen. Nicht das gesamte bestehende Interaction Model durch diese Datei ersetzen! Das JSON-Fragment `HomeAgentFrageIntent.json` ist nur eine Vorlage für einen zusätzlichen Intent.

Wichtig: Ein `AMAZON.SearchQuery`-Slot benötigt eine Trägerphrase (z. B. „frage“), sollte pro Utterance **allein** stehen und möglichst am **Ende** der Utterance stehen. Mit bestehenden Sample Utterances darf kein identischer Eintrag kollidieren.

## 2. Symcon

- Home Agent (separate Instanz) muss installiert und mindestens ein Gerät freigegeben sein.
- Home-Agent-Zugang „Alexa“ mit **Nur abfragen** für den ersten Test.
- Alexa-Modul: „KI-Hausfragen über Home Agent beantworten“ einschalten, MCP-Endpunkt und Zugangs-Token eintragen.
- OpenAI-Modell/Key im vorhandenen KI-Intent-Parser-Bereich pflegen. Zugangsdaten **niemals** in GitHub eintragen.
- „Komplexe Sprachbefehle über Home Agent“ zunächst deaktivieren (Schreibaktionen brauchen zusätzliche Freigabe).
- Modul-Update übernehmen. **Vorher die in Symcon erzeugten Skripte sichern.** Das Modul aktualisiert den Home-Agent-Hook im bestehenden Action-Skript sowie das Helper-Skript `HomeAgentVoiceBridge`.

Fiktives Beispiel für einen Endpunkt. `192.0.2.10` gehört zum reservierten Dokumentationsnetz (RFC 5737) und ist keine echte Serveradresse. IP-Adresse und Instanz-ID durch die Werte der eigenen lokalen IP-Symcon-Installation ersetzen:

```text
http://192.0.2.10:3777/hook/homeagent/12345
```

## 3. Sprachtest

1. „Alexa, öffne Haussteuerung.“
2. „Frage welche Lichter eingeschaltet sind.“

Bei Erfolg enthält `REQUEST_RAW` nun einen `IntentRequest` mit `intent.name = "HomeAgentFrageIntent"` und `slots.Frage.value`. Die neue Verarbeitung liest genau diesen Slot, übergibt ihn an den MCP-Client und gibt die kurze Antwort direkt an Alexa zurück.

## 4. Diagnose in den Symcon-Meldungen

Setze den Log-Level der Alexa-Instanz temporär auf **debug**. Im Kanal **Alexa** erscheinen dann etwa:

```text
HomeAgentVoice.received       {"intent":"HomeAgentFrageIntent","source":"Frage","chars":30}
HomeAgentVoice.recognized_text {"text":"welche Lichter eingeschaltet sind"}
HomeAgentVoice.bridge.start  {"scriptId":12345}
HomeAgentVoice.mcp.tools_list.start
HomeAgentVoice.mcp.tools_list.done
HomeAgentVoice.mcp.tools.available
HomeAgentVoice.openai.start
HomeAgentVoice.openai.done
HomeAgentVoice.mcp.tool.start {"name":"get_state"}
HomeAgentVoice.mcp.tool.done
HomeAgentVoice.openai.start
HomeAgentVoice.openai.done
HomeAgentVoice.answer.done
HomeAgentVoice.answer        {"durationSeconds":3.2,"text":"..."}
```

Die Meldung `HomeAgentVoice.missing_text` bedeutet: Alexa hat zwar den Intent erkannt, aber keinen nutzbaren Frage-Slot geliefert. `HomeAgentVoice.bridge.failed` bedeutet einen Verbindungs-, Authentifizierungs-, OpenAI- oder Zeitbudgetfehler. Für den dedizierten Freitext-Intent wird dann eine verständliche Fehlermeldung ausgegeben, anstatt versehentlich eine andere Alexa-Aktion auszuführen.

**Datenschutz:** Rohlogs können weiterhin andere Informationen über Alexa-Interaktionen enthalten. Der bisherige REQUEST_RAW-Log maskiert jetzt bekannte Zugangstoken und IDs; die neue Home-Agent-Diagnose gibt keinen OpenAI-Key oder MCP-Token aus. Debug-Level nach dem Test wieder reduzieren.

## Test: IPS_RunScriptWaitEx-Parameter (ohne Zugangsdaten)

Der Symcon-Skriptaufruf akzeptiert als Parameterwerte keine PHP-Arrays. Insbesondere `['config' => []]` führt zu
`Cannot auto-convert value for parameter Parameters (Type is not supported)`.
Die Produktiv-Anbindung überträgt deshalb die Einstellung als **JSON-String** unter `homeAgentConfigJson`.

Ein gefahrloser Test der Bridge-Skriptausgabe ohne externe API-Aufrufe:

```php
<?php
declare(strict_types=1);
$result = IPS_RunScriptWaitEx(23549, [
    'homeAgentText' => 'Test',
    'homeAgentConfigJson' => '{}'
]);
var_dump($result);
```

Erwartet: `{"handled":false,"error":"Konfiguration unvollständig"}`.
Das prüft nur den Transport und JSON-Rückgabewert, noch nicht die Verbindung zu Home Agent oder OpenAI.

## Hinweise

- Nur im Alexa-Developer-Portal kann das Interaction Model des Skills geändert und neu gebaut werden. Das Symcon-Modul kann den Alexa-Intent nicht automatisch erstellen.
- Der vorherige `GetHaus`-Intent ohne ausgefüllte Slots übermittelt **keinen ursprünglichen Sprachtext**; daraus kann die KI die Frage nicht rekonstruieren.
- Alexa Custom Skills haben ein begrenztes Zeitbudget. Eine MCP-/OpenAI-Verarbeitung kann trotzdem zu lange dauern. Aktuell wird die Anfrage maximal zwei OpenAI-Runden lang verarbeitet.
- Der klassische GetHaus-/APL-Pfad bleibt unverändert. Der neue Intent wird ausschließlich über Home Agent beantwortet.

**Sicherheit:** Beispieladresse niemals als produktiven Endpunkt eintragen. Die echte Server-IP und das MCP-Token werden nur in der lokalen IP-Symcon-Konfiguration gepflegt; keine Zugangsdaten in GitHub, README, Screenshots oder Diagnoseprotokollen veröffentlichen. Für externe Erreichbarkeit TLS und eine geeignete Zugangssicherung verwenden.
