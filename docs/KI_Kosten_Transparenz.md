# KI-Kostentransparenz in IPSAlexaHaussteuerung

## Darstellung in IP-Symcon

Nach dem Modul-Update und Übernehmen legt das Modul unter IPSAlexaHaussteuerung → KI-Kosten die Übersicht und die Kategorien **Alle KI-Anbindungen**, **KI + RoomsCatalog**, **KI + Home Agent** und **Sonstige KI – Intent-Parser** an. Die Rohdaten liegen persistent in der ausgeblendeten String-Variable kiCostData. In jeder Kategorie werden die letzten 100 Fragen ohne persönlichen Fragetext als JSON gespeichert; die HTML-Übersicht zeigt die letzten 25.

## Variablen je Backend und insgesamt

- Fragen heute / Monat / gesamt, fehlgeschlagene Fragen, API-Aufrufe heute / Monat / gesamt.
- Eingabe-Tokens insgesamt und im Monat, gecachte Tokens, Ausgabe-Tokens insgesamt und im Monat, Token-Gesamtzahl.
- Kosten heute, im Monat und insgesamt (USD), Kosten der letzten Frage, Modell, letzte Ausführung und Status.
- Prognose der Monatskosten auf Basis des bisherigen Tagesdurchschnitts und durchschnittliche Kosten pro Frage, jeweils getrennt je Backend und insgesamt. Am Monatsanfang kann die Prognose stark schwanken.
- API-Aufrufe ohne Token-Daten oder ohne bekannten Preis werden gesondert gezählt und in der Übersicht gewarnt.

## Preisberechnung

Offiziell belegte Modellpreise (USD je 1 Mio. Tokens):

| Modell | Eingabe | Gecachte Eingabe | Ausgabe |
| --- | ---: | ---: | ---: |
| **gpt-6-luna** (neuer Standard) | **0,10** | **0,01** | **0,50** |
| gpt-4.1-mini | 0,40 | 0,10 | 1,60 |
| gpt-4.1-nano | 0,10 | 0,025 | 0,40 |
| gpt-4.1 | 2,00 | 0,50 | 8,00 |

Quellen: https://developers.openai.com/api/docs/pricing und https://openai.com/index/gpt-4-1/

Berechnung: (Eingabe − Cache) × Eingabepreis / 1.000.000 + Cache × Cachepreis / 1.000.000 + Ausgabe × Ausgabepreis / 1.000.000.
Die Modellpreise können in der Modulkonfiguration unter KI-Kosten & Tokenverbrauch als JSON angepasst werden. Preiswerte zum Zeitpunkt des Aufrufs werden gespeichert; historische Werte werden nicht rückwirkend umgerechnet. Unbekannte Modelle werden nicht fälschlich als kostenlos interpretiert.

## GPT-6 Luna und GPT-4.1 mini umschalten

In der IP-Symcon-Instanz **IPSAlexaHaussteuerung → KI Intent Parser → KI-Modell** steht ein Auswahlfeld mit genau zwei Modellen:

- **GPT-6 Luna** (neuer Standard auch bei bestehenden Instanzen, da neue zentrale Einstellungsvariable): günstig und auf kurze Sprachbefehle ausgerichtet.
- **GPT-4.1 mini**: bisher bewährte Alternative.

Nach der Auswahl in der Instanzkonfiguration **Übernehmen** klicken. Die Auswahl gilt einheitlich für **KI + RoomsCatalog**, **KI + Home Agent** und den älteren KI-Intent-Parser. Die bisherige Property `KIIntentModel` bleibt aus Kompatibilitätsgründen intern erhalten, wird von der neuen Modellauswahl aber nicht mehr verwendet.

Für Luna nutzt die Anbindung weiterhin Chat Completions, stellt bei Funktionsaufrufen jedoch `reasoning_effort: "none"` ein. Für GPT-4.1 mini bleibt das alte Chat-Completions-Format ohne diesen Parameter erhalten. Dokumentation: https://developers.openai.com/api/docs/guides/latest-model

Die KI-Kostenüberwachung trennt weiterhin nach Anbindung und berücksichtigt im Rohdaten-Detail außerdem das jeweils **wirklich verwendete Modell und dessen Preis** zum Zeitpunkt des Aufrufs. Eine Modellumstellung verändert die gespeicherten historischen Kosten nicht.

**Wichtig:** Für beide Modelle genügt derselbe bestehende API-Schlüssel, sofern das jeweilige Modell im API-Projekt nutzbar ist. Die Kombination konnte auf GitHub automatisiert getestet werden, aber echte OpenAI-/Alexa-Sprachaufrufe sind erst nach dem Update vor Ort testbar.

## Zuverlässigkeit

Die Token-Daten werden unmittelbar nach jedem erfolgreichen OpenAI-Chat-Completions-Aufruf erfasst. Bei zwei Modellrunden einer Alexa-Frage werden beide Runden derselben Frage zugerechnet. Kosten der ersten Runde werden auch dann gezählt, wenn die zweite Runde scheitert.

Der Modul-Timer aktualisiert Tages-/Monatsanzeigen im Zeitzonenbezug Europe/Vienna auch ohne neue Fragen. Die Monatswarnung ist ab 5 USD eingeschaltet, konfigurierbar auf 0 USD zum Deaktivieren. Eine Warnung ist kein hartes Ausgabenlimit.

## Grenzen

- Erfassung beginnt erst nach Installation dieser Version: frühere Kosten nicht exakt nachträglich erfassbar.
- Keine offiziellen Rechnungsdaten, sondern Schätzungen anhand echter API-usage-Felder, hinterlegter Preise und eventuell variierender Tarife.
- Keine Kosten externer Apps desselben API-Schlüssels, EUR-Umrechnung oder österreichischer Umsatzsteuer.
- Sollte ein Skript zwischen einer kostenpflichtigen API-Antwort und der Speicherung vollständig abstürzen, kann dieser Einzelfall fehlen.
- Tageswerte und Monatswerte folgen Europe/Vienna. Die Historie zeigt die letzten 100 abgeschlossenen Fragen pro Backend; die Summen bleiben vollständig bestehen.

## Nach dem Merge

IP-Symcon-Modul aktualisieren und Übernehmen klicken; anschließend eine Alexa-Frage stellen. Statistik unter KI-Kosten prüfen. Automatisierter Test: php tests/test_ki_cost_accounting.php. Offizielle OpenAI-Kosten: https://platform.openai.com/usage.