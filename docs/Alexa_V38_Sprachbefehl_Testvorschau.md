# V3.8.2 – Sprachbefehl testen und Orts-Folgefragen

1. Im **RoomsCatalogConfigurator** das Panel **Sprachbefehl testen** öffnen.
   Dasselbe Panel liegt in **IPSAlexaHaussteuerung → Szenen und eigene Alexa-Befehle**.
   Bei genau einer Alexa-Instanz ist keine Instanzauswahl nötig.
2. Einen Satz wie am Echo eingeben und **SPRACHBEFEHL TESTEN** drücken. Ein
   Alexa-Intent, der KI-Haken und eine Auswahl der Testart sind nicht nötig.
3. Für unveröffentlichte eigene Sprachregeln optional **EDIT – Entwurf** wählen.
   Standard ist **PROD – veröffentlicht**. Hausfragen und Navigation verwenden
   weiterhin den produktiven RoomsCatalog und die produktive Navigation.

| Eingabe | Vorschau |
| --- | --- |
| „Wer ist Andrea?“ | Eigene Regel, Antwort und gegebenenfalls Bild. |
| „Welche Lichter sind eingeschaltet?“ | Aktuelle Rückmeldungen der freigegebenen Lichter, Antwort und verwendete Daten. Diese Abfrage braucht keinen OpenAI-Aufruf. |
| „Welcher Raum ist am wärmsten?“ | Antwort und lesende Abfragen der konfigurierten Haus-KI. Je nach Konfiguration sind auch Archiv- und Energiedaten verfügbar. |
| „Visu“ | Vorgesehenes Navigationsziel ohne Öffnen der Visualisierung. |
| „Jalousien schließen“ | Bei aktiver eigener Regel das vorgesehene Skript oder Gerät, ohne Start oder Schaltaktion. |
| „Schalte das Licht im Wohnzimmer ein“ | Klassischer Schaltbefehl als Vorschau. Das exakte Ziel lässt sich ohne die Alexa-Slots nicht in jedem Fall bestätigen. |

Die Antwort steht direkt im Panel. Unter **Vorschau (kopierbares JSON)**
stehen Trefferart, Antwort und je nach Route geplante Schritte oder verwendete
Daten. Ist die KI-Hausabfrage aktiviert, nutzen freie Hausfragen die dort
konfigurierte OpenAI-API. Die KI erhält in der Vorschau keine Schreibwerkzeuge.

Der Test arbeitet mit dem eingegebenen Text. Er testet nicht das Mikrofon,
das Alexa-Interaktionsmodell, APL-Klicks oder den aktuellen Gerätekontext eines
Echo. Eigene Regeln mit einem dedizierten Alexa-Intent lassen sich über ihren
hinterlegten gesprochenen Alias prüfen. Konfigurationsskripte werden als
statische Arrays gelesen, nicht ausgeführt. Es werden keine Geräte geschaltet,
Skripte gestartet oder Änderungen nach PROD übernommen.

Beim Öffnen der Instanzkonfiguration sind die Bereiche beider Module
weiterhin eingeklappt.

## Folgefragen am Echo und im Testfeld

„Welche Lichter sind eingeschaltet?“ beantwortet die Frage fürs Haus.
„Und im Obergeschoss?“ oder „Und im Büro?“ behält danach das Thema bei
und liest aktuelle Werte nur für die angegebene Etage bzw. den Raum.
In **Räume & Etagen** hinterlegte Werte wie `OG` und `1. OG` sowie
Raumaliasse werden berücksichtigt. Ein unbekannter oder mehrdeutiger Ort
führt zu einer Rückfrage, nicht zu einer Abfrage fürs ganze Haus.

Eine vollständige neue Frage, auch „Und wie warm ist es?“, ersetzt das
vorherige Thema. Eine neue Alexa-Sitzung bzw. ein neu geöffnetes Testformular
beginnt ohne Kontext. Ohne vorherige Hausfrage wird um eine vollständige
Frage gebeten. Folgefragen schalten keine Geräte.

### Einmalig im Alexa-Interaktionsmodell ergänzen

1. In der Alexa Developer Console den vorhandenen Skill und das deutsche
   Interaktionsmodell öffnen.
2. Im JSON Editor **nur** diesen Intent in `interactionModel.languageModel.intents`
   ergänzen. Bestehende Intents nicht ersetzen.

```json
{
  "name": "HausUndIntent",
  "slots": [{"name": "Frage", "type": "AMAZON.SearchQuery"}],
  "samples": ["und {Frage}"]
}
```

3. **Save Model** und **Build Model** ausführen. In IP-Symcon das Modul
   aktualisieren und die Alexa-Instanz bei Bedarf einmal **Übernehmen**,
   damit das bestehende Action-Skript den aktualisierten Sprachblock erhält.
4. Skill öffnen, „Welche Lichter sind eingeschaltet?“ sagen und direkt
   nach der Antwort „Und im Obergeschoss?“ fragen.

Der neue Intent ist auch im [Intent-Paket](Alexa_Hausfragen_Intent_Paket.json)
enthalten. Wenn Alexa den kurzen Satz nicht erfasst, lässt sich über den
bestehenden Frage-Intent auch „Frage, und im Obergeschoss“ probieren.
