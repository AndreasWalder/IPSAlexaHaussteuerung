# KI-Kostentransparenz in IPSAlexaHaussteuerung

## Darstellung in IP-Symcon

Nach dem Modul-Update und **Übernehmen** zeigt die IPSAlexaHaussteuerung unter **KI-Kosten** die Kategorien **KI-Kosten gesamt**, **ChatGPT + RoomsCatalog** und **KI – Intent-Parser**. Die HTML-Übersicht umfasst Tages-/Monatskosten, Monatsprognose, aktuelle Token- und API-Aufrufzahlen sowie die letzten 25 Fragen. Die persistenten Rohdaten bleiben in der ausgeblendeten String-Variable `kiCostData` erhalten; jede aktive Kategorie hat bis zu 100 gespeicherte Fragen ohne persönlichen Fragetext. **Die nicht mehr genutzte HomeAgent-Kostenkategorie wird ausgeblendet, aber nicht gelöscht.** Bereits angefallene Kosten bleiben unverändert in der Gesamtsumme, in der Historie als *Altbestand* erkennbar.

## Variablen je Backend und insgesamt

- Fragen heute / Monat / gesamt, fehlgeschlagene Fragen, API-Aufrufe heute / Monat / gesamt.
- Eingabe-Tokens insgesamt und im Monat, gecachte Tokens, Ausgabe-Tokens insgesamt und im Monat, Token-Gesamtzahl.
- Kosten heute, im Monat und insgesamt (EUR), Kosten der letzten Frage, Modell, letzte Ausführung und Status.
- Prognose der Monatskosten auf Basis des bisherigen Tagesdurchschnitts und durchschnittliche Kosten pro Frage, jeweils getrennt je Backend und insgesamt. Am Monatsanfang kann die Prognose stark schwanken.
- API-Aufrufe ohne Token-Daten oder ohne bekannten Preis werden gesondert gezählt und in der Übersicht gewarnt.

## Preisberechnung

Die **Modellpreise** bleiben in USD je 1 Mio. Tokens, weil OpenAI in USD abrechnet. **Angezeigte Gesamtkosten** werden automatisch mit dem EZB-Wechselkurs in EUR umgerechnet:

Offiziell belegte Modellpreise (USD je 1 Mio. Tokens):

| Modell | Eingabe | Gecachte Eingabe | Ausgabe |
| --- | ---: | ---: | ---: |
| **gpt-6-luna** (neuer Standard) | **0,10** | **0,01** | **0,50** |
| gpt-4.1-mini | 0,40 | 0,10 | 1,60 |
| gpt-4.1-nano | 0,10 | 0,025 | 0,40 |
| gpt-4.1 | 2,00 | 0,50 | 8,00 |

Quellen: https://developers.openai.com/api/docs/pricing und https://openai.com/index/gpt-4-1/

Berechnung: (Eingabe − Cache) × Eingabepreis / 1.000.000 + Cache × Cachepreis / 1.000.000 + Ausgabe × Ausgabepreis / 1.000.000.
Die USD-Modellpreise können in der Modulkonfiguration unter KI-Kosten & Tokenverbrauch als JSON angepasst werden. USD-Preiswerte zum Zeitpunkt des Aufrufs werden gespeichert; historische USD-Beträge bleiben unverändert. Der sichtbare EUR-Betrag wird mit dem jeweils ausgewiesenen EZB-Referenzkurs oder einem manuellen USD→EUR-Kurs umgerechnet und kann sich dadurch bei einer Kursänderung verändern. Unbekannte Modelle werden nicht fälschlich als kostenlos interpretiert.

## GPT-6 Luna und GPT-4.1 mini umschalten

In der IP-Symcon-Instanz **IPSAlexaHaussteuerung → KI Intent Parser → KI-Modell** steht ein Auswahlfeld mit genau zwei Modellen:

- **GPT-6 Luna** (neuer Standard auch bei bestehenden Instanzen, da neue zentrale Einstellungsvariable): günstig und auf kurze Sprachbefehle ausgerichtet.
- **GPT-4.1 mini**: bisher bewährte Alternative.

Nach der Auswahl in der Instanzkonfiguration **Übernehmen** klicken. Die Auswahl gilt für **ChatGPT + RoomsCatalog** und den **KI-Intent-Parser**. Es gibt keinen separaten HomeAgent-API-Backendpfad mehr. Die bisherige Property `KIIntentModel` bleibt aus Kompatibilitätsgründen intern erhalten, wird von der neuen Modellauswahl aber nicht mehr verwendet.

Für Luna nutzt die Anbindung weiterhin Chat Completions, stellt bei Funktionsaufrufen jedoch `reasoning_effort: "none"` ein. Für GPT-4.1 mini bleibt das alte Chat-Completions-Format ohne diesen Parameter erhalten. Dokumentation: https://developers.openai.com/api/docs/guides/latest-model

Die KI-Kostenüberwachung trennt die beiden **aktiven** Anbindungen und den Gesamtwert. Sie zeigt Kosten in **Euro**, einschließlich des jeweils tatsächlich verwendeten Modells sowie des USD-Preissnapshots im Rohdaten-Detail. Historische Einträge früherer Routen bleiben in der Gesamtstatistik, statt unbemerkt aus den Zahlen zu verschwinden. Eine Modellumstellung verändert die gespeicherten historischen USD-Kosten nicht.

**Wichtig:** Für beide Modelle genügt derselbe bestehende API-Schlüssel, sofern das jeweilige Modell im API-Projekt nutzbar ist. Die Kombination konnte auf GitHub automatisiert getestet werden, aber echte OpenAI-/Alexa-Sprachaufrufe sind erst nach dem Update vor Ort testbar.

## Zuverlässigkeit

Die Token-Daten werden unmittelbar nach jedem erfolgreichen OpenAI-Chat-Completions-Aufruf erfasst. Für die aktive Kostenerfassung sind nur noch die Backends `RoomsCatalog` und `IntentParser` zugelassen; der bisherige `HomeAgent`-Backendname wird nicht mehr für neue Kostenereignisse akzeptiert. Bei zwei Modellrunden einer Alexa-Frage werden beide Runden derselben Frage zugerechnet. Kosten der ersten Runde werden auch dann gezählt, wenn die zweite Runde scheitert.

Der Modul-Timer aktualisiert Tages-/Monatsanzeigen im Zeitzonenbezug Europe/Vienna auch ohne neue Fragen. Die Monatswarnung ist ab 5 EUR eingeschaltet, konfigurierbar auf 0 EUR zum Deaktivieren. Eine Warnung ist kein hartes Ausgabenlimit.

## Grenzen

- Erfassung beginnt erst nach Installation dieser Version: frühere Kosten nicht exakt nachträglich erfassbar.
- Keine offiziellen Rechnungsdaten, sondern Schätzungen anhand echter API-usage-Felder, hinterlegter Preise und eventuell variierender Tarife.
- Keine Kosten externer Apps desselben API-Schlüssels, keine österreichische Umsatzsteuer, Gebühren oder garantierte Übereinstimmung mit dem Wechselkurs der Karten-/Bankabrechnung. EUR wird als referenzkursbasierte Schätzung angezeigt.
- Sollte ein Skript zwischen einer kostenpflichtigen API-Antwort und der Speicherung vollständig abstürzen, kann dieser Einzelfall fehlen.
- Tageswerte und Monatswerte folgen Europe/Vienna. Die Historie zeigt die letzten 100 abgeschlossenen Fragen pro Backend; die Summen bleiben vollständig bestehen.

## Nach dem Merge

IP-Symcon-Modul aktualisieren und Übernehmen klicken; anschließend eine Alexa-Frage stellen. Statistik unter KI-Kosten prüfen. Automatisierte Tests: `php tests/test_ki_cost_accounting.php` und `php tests/test_ki_cost_dashboard_v362.php`. Die neue Ansicht blendet historische Kosten nicht aus der Gesamtsumme aus und erstellt keine zusätzlichen OpenAI-Aufrufe. Offizielle OpenAI-Kosten: https://platform.openai.com/usage.
## Alexa-Meldungen direkt kopieren

Zusätzlich stellt die Modulinstanz die **letzten 500 Alexa-Meldungen aus dem Systemlog** unter **Diagnose → Alexa-Protokoll (kopieren)** als TextBox-Stringvariable bereit. Sie wird nach Alexa-Aufrufen und im 15-Sekunden-Intervall aktualisiert. Weitere Details stehen in `docs/Alexa_Protokoll_500_Euro_Kosten.md`.
