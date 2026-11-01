# Alexa versteht „Visu“ als „Wieso“ oder „Wizo“

## Diagnose vom 1. November 2026

Der Echo Show sendet an IP-Symcon beim gewünschten Befehl „Visu“
mehrfach **GetHaus / Action = `wieso`** und einmal **`wizo`**.
Die Alexa-Entity-Resolution meldet jeweils **`ER_SUCCESS_NO_MATCH`**.
Ein erfolgreicher Testbefehl wurde hingegen als
**GetHaus / Action = `test`** übermittelt und korrekt als
`Test.route reserved` behandelt.

**Die eigentliche Ursache liegt vor dem PHP-Code: Alexa übermittelt
nicht `visu`.** Ein PHP-Alias auf das Wort „visu“ kann daher
diese Eingaben nicht treffen.

### Sofortige, sichere IP-Symcon-Korrektur

Das Modul erkennt ab diesem Update zusätzlich den **exakten**
Alexa-Transkriptionsfehler **`wizo`** als Alias für die bestehende
technische Route `kacheltest` (nur einzelner Navigations-Slot oder
eindeutiger einzelner Navigationsbegriff). Die Modulinstanz beim
Aktualisieren einmal **Übernehmen**, damit ein angepasstes
Action-Skript ebenfalls den Alias erhält.

**`wieso` bleibt absichtlich ausgeschlossen:** Dies ist ein
gewöhnliches deutsches Fragewort. Andernfalls würden normale
KI-Fragen die Visualisierung versehentlich öffnen.

### Dauerhafte Abhilfe im Alexa Developer Portal

Das **Alexa-Interaktionsmodell** wird nicht durch das IP-Symcon-Modul
oder GitHub automatisch aktualisiert. Im tatsächlich verwendeten
**de-DE-Skill-Modell** sind die folgenden Schritte nötig:

1. Alexa Developer Console → **Build → Interaction Model → Intents
   → GetHaus** → beim Slot **Action** den zugewiesenen **Slot Type**
   nachsehen. Nicht einfach irgendeinen anderen Slot bearbeiten.
2. Falls es ein eigener Slot Type ist, unter **Slot Types**
   als neuen Wert **`Visu`** hinzufügen. Optional
   **`Visualisierung`** als Synonym oder eigenen Wert hinzufügen.
   `Wizo` kann optional als Synonym für `Visu` erfasst werden,
   aber **nicht `Wieso`**.
3. Bei den **Sample Utterances** von `GetHaus` sicherstellen,
   dass ein vorhandenes Muster mit **`{Action}`** als einzelner
   Slot existiert. Bestehende Phrasen für Licht, Jalousie,
   Heizung und Test **nicht ersetzen**. Ein eigenständiges
   unverknüpftes Sample `visu` ohne Slot wäre für den aktuellen
   Action-Router nicht ausreichend.
4. **Save Model → Build Model** ausführen und erst nach erfolgreichem
   Build erneut auf dem Echo testen.
5. Falls das Modell veröffentlichte und Entwicklungsversionen
   unterscheidet, darauf achten, die verwendete Skill-Version
   zu aktualisieren bzw. im Entwicklungs-Testmodus zu testen.

**Erwartete Logzeilen** nach der Korrektur:

```text
REQUEST_META ... "intent":"GetHaus"
SLOTSTRUCT_Action ... "value":"visu"
VoiceRouter.decision ... "category":"navigation","route":"kacheltest"
```

Falls weiter `wieso` als Wert übertragen wird, liegt das weiterhin
in der Alexa-Spracherkennung, nicht in der PHP-Visu-Route. In diesem
Fall ist der bereits hinterlegte längere Alternativbefehl
**„Visualisierung“** empfehlenswert. Der separate Befehl **„Test“**
bleibt unberührt.

## Weitere Diagnose

Die Logmeldung `helper_unavailable` ist bei den fehlgeschlagenen
`wieso`-Aufrufen aufgetreten. Sie ist gesondert zu untersuchen,
wenn sie auch bei **korrekt** erkanntem `visu` auftritt.
Für die eindeutige GetHaus-Einzelslot-Navigation existiert bereits
ein lokaler Fallback ohne KI-/VoiceRouter-Helper, wie der
erfolgreiche Test-Befehl demonstriert.

**Alexa-Dokumentation:**
- https://developer.amazon.com/en-US/docs/alexa/custom-skills/create-and-edit-custom-slot-types.html
- https://developer.amazon.com/en-US/docs/alexa/custom-skills/create-intents-utterances-and-slots.html

