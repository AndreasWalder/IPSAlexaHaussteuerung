# Alexa-Meldungen direkt in IP-Symcon kopieren & KI-Kosten in EUR

## 500 Alexa-Meldungen ohne Dateizugriff

Nach dem Modulupdate und **Übernehmen** findet sich die Kategorie:

`IPSAlexaHaussteuerung → Diagnose → Alexa-Protokoll (kopieren)`

Darin:

- **Alexa-Meldungen (letzte 500)**: kopierbare mehrzeilige Stringvariable (`~TextBox`), **chronologisch** (älteste oben, neueste unten).
- **Anzahl gespeicherter Meldungen**: Zahl der tatsächlich gefundenen Zeilen.
- **Letzte Aktualisierung**: Zeit der letzten Änderung des Logtextes.
- **Quelle / Status**: zeigt an, ob die Symcon-Logdatei gelesen werden konnte.

Die aktualisierte Action registriert pro Alexa-Anfrage einen Abschluss-Callback. Der zusätzliche IP-Symcon-Timer **AlexaLogRefresh (15.000 ms)** ergänzt nachlaufende Meldungen auch dann, wenn der Haupt-Callback nicht ausgeführt wurde. Die Aktualisierung schreibt **keine zusätzliche Protokolldatei**.

Der Leser verwendet `IPS_GetLogDir() . 'logfile.log'`, also die **bereits existierende** IP-Symcon-Systemlogdatei. Vom Dateiende werden in 64-KiB-Blöcken maximal **16 MiB** nach den letzten 500 Zeilen mit Absender/Bezug `Alexa` durchsucht. VariableManager-Meldungen der Protokollvariable selbst werden ausgeschlossen, um eine Endlosschleife zu vermeiden. Sind innerhalb der letzten 16 MiB weniger als 500 Alexa-Meldungen enthalten, sind entsprechend weniger Zeilen verfügbar. Bei nicht lesbarem Log bleibt der zuletzt erfolgreich gelesene Text erhalten, und die Statusvariable erklärt das Problem.

**Wichtig:** Die Mehrzeilenvariable lässt sich in der IP-Symcon-Konsole direkt markieren und kopieren; es wird kein Download auf dem Server benötigt. API-Key-/Passwort-/Tokenmuster und einige interne IDs/IP-Adressen werden vor dem Einfügen maskiert. Die Maskierung kann jedoch nicht garantieren, dass jede private Information in älteren oder ungewohnten Logmeldungen erkannt wird: Den kopierten Text vor dem Teilen prüfen. Gesprochene Alexa-Fragen können gemäß der bestehenden Router-Diagnoseeinstellung enthalten sein.

## KI-Kosten in Euro

Unter `IPSAlexaHaussteuerung → KI-Kosten` und in den drei Backend-Kategorien stehen nun **EUR-Variablen** (`costTodayEUR`, `costMonthEUR`, `costTotalEUR` usw.). Die HTML-Übersicht zeigt EUR einschließlich Monatsprognose, Durchschnitt je Frage und Einzelkosten der letzten Fragen. Die Monatskostenwarnung ist ebenfalls in **EUR** konfigurierbar.

Die OpenAI-API rechnet nach wie vor in **USD** ab: die Rohdaten, Tokenpreise pro 1 Mio. Tokens und tatsächlich berechneten USD-Beträge werden zur Nachvollziehbarkeit beibehalten. Der angezeigte EUR-Betrag ist deshalb eine **Kursumrechnung einer USD-Kostenschätzung** und keine offizielle EUR-Rechnung inklusive Umsatzsteuer, Karten-/Bankgebühren oder Abweichungen des Zahlungsdienstleisters. Der jeweils aktuelle EUR-Anzeigewert eines historischen USD-Betrags kann sich bei Wechselkursänderungen verändern.

### EZB-Wechselkurs

- Standard: Kurs wird automatisch via HTTPS aus dem offiziellen EZB-Feed `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml` abgefragt.
- Die EZB quotiert **USD je 1 EUR**. Für die Kostendarstellung wird `1 / EZB-USD-Wert` gerechnet.
- Aktualisierung maximal etwa alle **12 Stunden**; bei einem Verbindungsproblem wird nach zwei Stunden erneut versucht.
- Als Fallback vor dem ersten erfolgreichen Abruf ist der verifizierte EZB-Kurs vom **30.10.2026: 1 EUR = 1,1172 USD** hinterlegt. Er wird als **Fallback mit Datum** angezeigt und niemals als aktueller Tageskurs ausgegeben.
- Da am Wochenende keine neuen Referenzkurse veröffentlicht werden, erscheint das Datum des letzten Banktages.
- Unter `KI-Kosten` zeigen **USD nach EUR – verwendeter Kurs**, **Wechselkurs – Datum** und **Wechselkurs – Quelle / Status** die Berechnungsbasis an.
- Optional: In der Instanzkonfiguration **KICostManualUsdToEur** auf einen gewünschten Faktor setzen (z. B. `0.9` bedeutet 1 USD = 0,90 EUR). **0** bedeutet automatische EZB-Abfrage.
- **Warnung ab Monatskosten (EUR)**, Standard 5 EUR, 0 = deaktiviert. Die ältere USD-Warnkonfiguration bleibt intern erhalten, aber die neue EUR-Einstellung steuert Warnungen.

Die bislang angelegten Variablen mit Endung `USD` werden nicht gelöscht, sondern ausgeblendet, damit alte Variablen-IDs und benutzerdefinierte Verknüpfungen nicht zerstört werden. In der neuen Oberfläche sind die EUR-Werte maßgeblich. Der in der Historie zusätzlich ergänzte Schlüssel `costEUR` dient nur als Anzeige; `costUsd` bleibt zur Rückverfolgbarkeit vorhanden.

## Installation und Test

1. In IP-Symcon das GitHub-Modul aktualisieren und die Modulinstanz **IPSAlexaHaussteuerung → Übernehmen**.
2. Eine Alexa-Frage ausführen; unter **Diagnose → Alexa-Protokoll (kopieren)** den Stringwert öffnen, komplett markieren und kopieren.
3. Unter **KI-Kosten** den Eurobetrag, EZB-Kurs samt Datum und das getrennte Backend prüfen.
4. Funktionstest im GitHub-Repository: `php tests/test_alexa_log_buffer_eur.php`.

Vorhandene RoomsCatalog-Geräte und Freigaben werden dabei nicht verändert.
