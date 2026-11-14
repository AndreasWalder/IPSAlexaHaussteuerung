# Bewässerung aus dem Objektbaum (V3.8.16)

Im **RoomsCatalog Konfigurator → Anlage aus Objektbaum – Bewässerung** die Wurzel
wählen (z. B. `Haus → Übersicht → Bewässerung`, ID `41578`), die Anlage scannen
und optional **Mit KI analysieren**. Danach nach **EDIT** übernehmen. Unter
**Geräte bearbeiten → Bewässerung** öffnet das Zahnrad die Anlage. Die
bestehende Veröffentlichung **EDIT nach PROD** aktiviert die Konfiguration für
Alexa. Der Import verändert keine Aktoren, Programme, Archiveinstellungen
oder Alexa-Intents.

## Was der Scan erkennt

- Kategorien, Dummy-Module, Links und Linkketten. Gleiche Zielvariablen erscheinen
  einmal, ihre Aliasnamen und Herkunft bleiben erhalten.
- Bekannte `IPSBewaesserung`-Idents ordnen Kreise, Pumpe und Anlagenautomatik zu.
  Registrierte Modulreferenzen und bekannte Aktor-Properties liefern ergänzende
  Beziehungen. Steuerungsvariablen und Zustandsrückmeldungen bleiben getrennt.
- Skripte im Teilbaum, verlinkte Skripte, variable Aktionsskripte, statisch
  referenzierte Skripte und Zeitereignisse werden lokal gelesen. Ausgewertet
  werden z. B. literale IDs und einfache, unveränderte ID-Zuweisungen in `GetValue`, `SetValue`, `RequestAction` und
  `IPS_RunScript*`. Skripte werden nicht ausgeführt. Dynamisch berechnete IDs,
  Daten aus Includes und versteckte Laufzeitabhängigkeiten können fehlen.
- Der optionale KI-Aufruf verwendet den vorhandenen Alexa-KI-Zugang und sendet
  nur ausgewählte, bereinigte Metadaten mit lokalen Zeilenindizes. Keine
  Skriptinhalte, Livewerte, IPS-IDs oder Zugangsdaten im Analysepayload.
  Die KI schlägt Rollen und Feuchtesensor-Zuordnungen vor. Neu typisierte
  Sensoren bleiben bis zur Prüfung im Anlageneditor für Sprachabfragen deaktiviert.
- Zeitereignisse zeigen letzten und nächsten **Ereignislauf**. Das ist nicht
  automatisch ein Pumpenstart. Ein angekündigter nächster Start benötigt
  einen eindeutigen, zukünftigen Zeitstempel für die Anlage oder den Kreis.

## Winter und Archiv

Eine eindeutig lesbare boolesche Wintermodus-Variable hat Vorrang. Ohne diese
Variable gelten die eingestellten Monate (Standard November bis März).
Im Winter nennt der Überblick auch Rohrbegleitheizung und Stationstemperaturen.
Im Sommer stehen Pumpe, Kreise und Bodenfeuchte im Vordergrund.

Im Alexa-Modul muss **Archiv für Hausabfragen** eingerichtet sein. Im
Anlageneditor kontrollieren, welche Datenpunkte archiviert sind:

- **Pumpe:** bestätigten booleschen Pumpenstatus als Archivsignal wählen, falls
  der Scan mehrere mögliche Zustände gefunden hat. Es zählt der jüngste
  nachweisbare Aus→Ein-Wechsel. Alexa gibt nur das lokale Datum aus.
- **Rohrbegleitheizung:** archivierte Ein/Aus-Rückmeldung oder einen
  geeigneten Leistungs-/Strommesswert verwenden. Für Messwerte sowohl
  **EIN ab** als auch **AUS bis** in der Einheit der Variable eingeben.
  Zwischen diesen Schwellen bleibt der zuletzt belegte Zustand erhalten.
  Ein gleichbleibender hoher Messwert, ein Standby-Verbrauch und der erste
  vorhandene Ein-Wert ohne nachgewiesenen vorherigen Aus-Wert ergeben
  keine zusätzliche Einschaltung. Keine Rückschlüsse aus Temperaturanstiegen.
- Ohne Zeitraum wird im Winter ab dem eingestellten Saisonbeginn gezählt,
  sonst über die letzten 30 Tage. „Heute“, „gestern“, „diesen Winter“ und
  „in den letzten sieben Tagen“ werden ebenfalls unterstützt. Ist die
  Archivabdeckung unvollständig, nennt Alexa nur nachgewiesene Ereignisse.

Beispiele für Alexa oder den Sprachtest:

- „Wie geht es meiner Bewässerung?“
- „Wie kalt ist es in den jeweiligen Stationen?“
- „Wie oft hat die Rohrbegleitheizung diesen Winter eingeschaltet?“
- „Ist bei der Bewässerung noch alles aus?“
- „Wann war die Pumpe zuletzt eingeschaltet?“
- „Wann startet Kreis 4 wieder?“

## Steuerung und Diagnose

Schalten ist zunächst aus. Nur bekannte boolesche Aktionen der Original-
Bewässerungssteuerung können im Anlageneditor freigegeben werden. Alexa benötigt
zusätzlich die globale Einstellung für Sprachbefehle. Beispiel nach Freigabe:
„Stoppe Kreis 4“. Der Einzelkreis wird nur im bekannten Handbetrieb angesteuert.
Alexa überschreibt weder die Automatik noch ruft sie entdeckte Skripte oder
direkte KNX-Aktoren auf. Zeitbegrenztes manuelles Schalten ist für diese
Steuerung nicht verfügbar.

Die bestehende Variable **Alexa-Meldungen (letzte 500)** enthält Scan-Gruppen,
ausgewählte Kandidaten, das KI-Ergebnis und die Diagnosen der Sprachabfragen
inklusive Archiveingrenzung und Auswertungsgrund. Auf der Installation kann
der Anlageneditor zur Kontrolle der tatsächlichen Signalzuordnung geöffnet
werden.
