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
Im Winter nennt der Überblick auch den Live-Status der Rohrbegleitheizung,
die Aktivität von heute, das letzte erkannte Ereignis und die Stationstemperaturen.
Auf Geräten mit Alexa-Bildschirm passen sich Schrift und Höhe der Statuszeilen
an die Bildschirmgröße und die Anzahl der Zeilen an.
Im Sommer stehen Pumpe, Kreise und Bodenfeuchte im Vordergrund.

Im Alexa-Modul muss **Archiv für Hausabfragen** eingerichtet sein. Im
Anlageneditor kontrollieren, welche Datenpunkte archiviert sind:

- **Pumpe:** bestätigten booleschen Pumpenstatus als Archivsignal wählen, falls
  der Scan mehrere mögliche Zustände gefunden hat. Es zählt der jüngste
  nachweisbare Aus→Ein-Wechsel. Alexa gibt nur das lokale Datum aus.
- **Rohrbegleitheizung:** Die Messreihe **Schaltschrank Verteiler und Heizung Pumpe**
  hat Vorrang. Als feste Leistungsquelle wird die archivierte Wirkleistung
  #54809 bevorzugt; #47232 bleibt als Rückfallquelle. Im Anlageneditor lässt sich die zugehörige Watt-Variable als
  **Rohrbegleitheizung: Leistung** explizit zuordnen.
  **2.775,2 W** dient als Referenz. Der Export von #54809 zeigte Spitzen von
  **2.332,5 bis 2.859,1 W** bei etwa **15 W** Grundlast. Mit 17 % Toleranz
  ergibt sich ein Erkennungsband von **2.303,42 bis 3.246,98 W**. Nach dem Grundverbrauch
  (bis 30 W) zählt eine in diesem Band liegende Lastspitze beim Abfallen
  auf Grundverbrauch einmal. Eine Spitze über dem Band wird verworfen.
  Als Uhrzeit zählt der Eintritt ins Band. Tageszahlen folgen der Ortszeit.
  Der direkte Status zeigt den aktuellen Messwert, die Starts und Uhrzeiten
  von heute und den letzten erkannten Start. Zusätzlich sieht man die
  Variablen-ID, den Bereich der tatsächlich gelesenen Archivwerte und
  das verwendete Erkennungsband.
  Ein archivierter Link „Schaltschrank Verteiler“ innerhalb der Anlage wird
  auch bei älterem RoomsCatalog erkannt. Ohne Rohwerte im angefragten
  Zeitraum bleibt die Anzahl offen.
- Ohne Zeitraum wird im Winter ab dem eingestellten Saisonbeginn gezählt,
  sonst über die letzten 30 Tage. „Heute“, „gestern“, „diesen Winter“ und
  „in den letzten sieben Tagen“ werden ebenfalls unterstützt. Ist die
  Archivabdeckung unvollständig, nennt Alexa nur nachgewiesene Ereignisse.

Beispiele für Alexa oder den Sprachtest:

- „Wie geht es meiner Bewässerung?“
- „Wie kalt ist es in den jeweiligen Stationen?“
- „Wie oft hat die Rohrbegleitheizung diesen Winter eingeschaltet?“
- „Wann hat die Rohrbegleitheizung zuletzt eingeschaltet?“
- „Status Rohrbegleitheizung“
- „Wie oft hat die Rohrbegleitheizung pro Tag eingeschaltet?“
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

## Statusfragen im Alexa-Modul

Auch Bewässerungsfragen laufen bei aktivierter Modulabfrage über
`QueryAlexaHouseReadOnly`. Die erzeugte KIHouseController-Skriptbrücke bleibt
für den bisherigen Befehlspfad und als Kompatibilitätsweg bei fehlender
Modulfunktion erhalten. PHP-Diagnosen der Skriptbrücke dürfen eine normale
Statusfrage nicht zu „Bitte anders formulieren“ machen.

„Wie geht es meiner Bewässerung?“, „Wie geht es mit der Bewässerung?“ und
entsprechende Formulierungen mit „unserer“ liefern die gemeinsame Statusansicht.
Deren Messwerte richten sich nach dem tatsächlichen Winter-/Sommermodus.
Lesende Fragen und Archivabfragen senden keine Geräteaktionen.
