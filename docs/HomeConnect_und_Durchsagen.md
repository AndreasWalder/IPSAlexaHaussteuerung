# Home Connect und zentrale Durchsagen

## Nach dem Modul-Update

1. IPSAlexaHaussteuerung öffnen und die Einstellungen übernehmen. Die bestehende
   Echo-Remote-Verknüpfung bleibt erhalten.
2. RoomsCatalogConfigurator öffnen oder **RoomsCatalog neu laden** drücken.
   Die erkannten Home-Connect-Geräte werden mit Backup in EDIT ergänzt.
3. Im normalen Geräteeditor den **Domain-Filter Hausgeräte** wählen. Das Zahnrad
   eines Geräts zeigt sämtliche verknüpften Variablen mit aktuellen Werten,
   bearbeitbaren IDs, Namen, Bedeutungen und Lese-/Schaltfreigaben. Name, Raum,
   Gruppe und alternative Gerätenamen werden im selben Dialog bearbeitet.
   Anschließend **EDIT nach PROD übernehmen**, damit Alexa die Zuordnungen nutzt.
4. Unter **AUTOMATISCHE DURCHSAGEN** stehen die erkannten Vorlagen und eigene Regeln.
   Die globalen Schalter werden mit der Modulkonfiguration übernommen.

Die Erkennung basiert auf der Home-Connect-Modulkennung und aktuellen Variablen,
nicht auf festen Objekt-IDs. Variablen und Profile werden bei jeder Frage neu
gelesen. Der vorhandene JSON-Export im RoomsCatalogConfigurator bleibt verfügbar.
Die zentrale Quelle steht in `RoomsCatalogEdit.dataSources.household_devices`.
Beim Abgleichen bleiben eigene Namen, Verknüpfungen und Freigaben erhalten. Neue
Variablen werden nach EDIT ergänzt und mit der normalen PROD-Übernahme freigegeben.
Die alte Home-Connect-Konfiguration liefert vor dieser ersten Veröffentlichung
weiterhin den bisherigen Betrieb; danach ist der veröffentlichte Katalog maßgeblich.
Die Übersicht in IPSAlexaHaussteuerung ist eine Verbindungsanzeige. Die Bearbeitung
der Hausgeräte erfolgt zentral im RoomsCatalog, automatische Durchsagen weiterhin
in ihrem eigenen Bereich.

## Weitere Hausgeräte hinzufügen

Im RoomsCatalogConfigurator **HAUSGERÄTE – Vorhandene und weitere Geräte verknüpfen**
öffnen. Eine Geräteinstanz, Kategorie oder einzelne Variable auswählen und **Als
Hausgerät verknüpfen** drücken. Die darunterliegenden Variablen werden gemeinsam
aufgenommen. Diese Verknüpfung ist herstellerunabhängig; Home Connect ist einer der
unterstützten Anbieter und keine eigene Domain.

Danach im normalen Hausgeräte-Zahnrad die Namen und Bedeutungen zuordnen. Weitere
Variablen lassen sich dort einzeln verknüpfen. Für allgemeine Geräte gibt es
Statusfragen, die Alexa-Show-Statusansicht und Ein/Aus über eine ausdrücklich
freigegebene Boolean-Variable mit Bedeutung **Ein/Aus** und vorhandener IPS-Aktion.
Andere Programmabläufe benötigen eine passende Geräteanbindung. Neue allgemeine
Geräte erhalten zunächst Leserechte; ihre Schaltfreigabe muss gezielt gesetzt
werden. Home-Connect-Befehle verwenden weiterhin die gerätespezifischen Prüfungen.

## Fragen und Anzeige auf der Alexa Show

Beispiele innerhalb des Custom Skills Haussteuerung:

- „Wie geht es meinen Haushaltsgeräten?“
- „Wie geht es meiner Waschmaschine?“
- „Wie lange läuft die Wäsche noch?“
- „Ist der Geschirrspüler fertig?“
- „Wie warm ist der Dampfbackofen?“
- „Wie geht es meiner Mikrowelle?“
- „Welche Programme hat mein Kaffeevollautomat?“
- „Wie viele Getränke hat die Kaffeemaschine gezählt?“
- „Ist die Tür der Waschmaschine offen?“
- „Ist der Fernstart beim Waschtrockner freigegeben?“
- „Zeige alle Werte meines Geschirrspülers.“

Die vorhandenen Hausfragen-Intents und das Freitextfeld werden verwendet. Bei Bedarf
mit „Frage …“ beginnen, wenn Alexa einen Satz keinem Frage-Intent zuordnet. Die
Textvorschau **Sprachbefehl testen** nutzt dieselben Fakten und schaltet keine Geräte.

Es gibt eigene Vektorsymbole für Waschen/Trocknen, Geschirrspüler, Kaffee, Backofen, Mikrowelle,
Kochfeld und die Übersicht. Die Statuszeilen passen sich an die Bildschirmgröße an
und sind bei langen Detailansichten scrollbar. Die Symbole brauchen keine
heruntergeladenen Bilder. Die optionale KI formuliert die geprüften Fakten; bei
API-Fehlern bleibt die lokale Antwort verfügbar. Die Anzeige verwendet weiter die
lokal gelesenen Werte.

Ein ausgewähltes Programm ist nicht zwingend das laufende Programm. Fortschritt
und Restzeit werden nur bei passendem Betriebsstatus gezeigt. Alte Statusmeldungen
werden mit ihrem Zeitstempel kenntlich gemacht. Leere oder noch nie aktualisierte
Variablen werden als fehlende Angaben behandelt. Ein unbenutztes Mikrowellenprogramm
wird dadurch nicht als defekt oder unmöglich eingestuft. „Bereit“ oder „100 %“ allein
beweisen keinen erfolgreichen Abschluss. Dafür wird das Fertig-Ereignis verwendet.

## Eindeutige Gerätebefehle

Zusätzlich zu **Freigegebene Gerätefunktionen steuern** im RoomsCatalog-Zahnrad und
der Schaltfreigabe der betreffenden Variablen müssen die bestehenden **KI-Kommandos**
(`HomeAgentVoiceRouteCommands`) aktiviert sein. Beispiele:

- „Schalte die Kaffeemaschine ein.“
- „Stelle die Kaffeemaschine auf Cappuccino.“
- „Starte die Waschmaschine.“
- „Stoppe den Geschirrspüler.“
- „Pausiere den Dampfbackofen.“ – falls im aktuellen Control-Profil verfügbar.
- „Stelle die Menge der Kaffeemaschine auf 160 ml.“ – falls ein entsprechender
  Wertebereich im aktuellen Profil vorliegt.

Bei der Programmauswahl wird genau ein vorhandener Profilwert gesucht. Bei
mehrdeutigen Programmnamen wird nicht geschaltet. Eine Auswahl startet das Programm
nicht automatisch. „Starte Gerät mit anderem Programm“ startet nicht versehentlich
das zuvor ausgewählte Programm; das gewünschte Programm zunächst separat auswählen.

Schaltbefehle prüfen die Gerätezuordnung, vorhandene Aktion, Remote-Freigaben und
lokale Bedienung. Start/Fortsetzen erfordern Fernstart; eine offene Tür verhindert
den Start. Zahlenoptionen brauchen einen erkennbaren aktuellen Wertebereich und
eine passende Schrittweite. Laufzeitprognosen und Beladungsempfehlungen werden trotz
eventuell vorhandener Aktion nicht als Sollwerte geschaltet. Das Kochfeld wird nur
abgefragt. Eine unscharfe Namenserkennung erlaubt keine Geräteaktion. Rückmeldung
„übergeben“ bestätigt den Auftrag, nicht den tatsächlichen Beginn eines Programms.

## Automatische Durchsagen

Für die sechs vorhandenen Ausgangsgeräte werden folgende Vorlagen angelegt:

| Gerät | Meldungen |
|---|---|
| Micro-Backofen | Programm fertig, Vorheizen fertig, Timer abgelaufen |
| Dampf-Backofen | Programm fertig, Vorheizen fertig, Timer abgelaufen |
| Geschirrspüler | Programm fertig, Programm abgebrochen |
| Waschtrockner | Programm fertig |
| Kaffeevollautomat | Bohnenbehälter leer, Wassertank leer, Tropfschale voll |

Vorlagen werden aktiviert angelegt. Sie reagieren auf neu empfangene Home-Connect-
Ereignisse; Installation und Listenaktualisierung sprechen keine bereits
gespeicherten Zustände. Ein Home-Connect-Ereignis kann erneut denselben Wert liefern;
deshalb nutzen diese Vorlagen Aktualisierungen und einen Mindestabstand von zwei
Minuten. Die alten und die offiziellen Kennungen für Vorheizereignisse werden
berücksichtigt. Der tatsächliche Empfang hängt vom Gerät und dem installierten
Home-Connect-Modul ab.

Jede Regel kann deaktiviert, umbenannt, bearbeitet oder gelöscht werden. Wiederholte
Erkennung überschreibt die eigenen Texte und Einstellungen nicht. Eine gelöschte
Vorlage wird nicht automatisch wieder angelegt. Pro Regel steht eine explizite
Testausgabe zur Verfügung, die den Auslöser nicht verändert.

### Eigene Regel anlegen

**Eigene Durchsage hinzufügen** wählen und die neue Zeile über das Zahnrad bearbeiten:

| Feld | Bedeutung |
|---|---|
| Aktiv | Regel überwachen |
| Auslösevariable | Beliebige vorhandene Symcon-Variable verknüpfen |
| Auslöser | Wird gleich/ungleich, über-/unterschreitet, Änderung oder Aktualisierung |
| Vergleichswert | `true`/`false`, Zahl oder exakter Text; Ereignisse optional als JSON-Liste |
| Durchsagetext | Fester Text, optional `{name}` und `{value}` |
| Abstand | Mindestzeit zwischen zwei Ausgaben derselben Regel in Sekunden |
| Echo | Null verwendet die zentrale Echo-Ausgabe, sonst gezielt ein Echo-Gerät |
| Ausgabe | Durchsage mit Ankündigungston oder normale Sprachausgabe |
| Ruhe ab/bis Stunde | Gleiche Stunden deaktivieren die Ruhezeit; Zeiträume über Mitternacht sind möglich |

Beispiel: eine Boolean-Variable „Waschprogramm beendet“ mit **Wird gleich**,
Vergleich `true` und Text „Die Wäsche ist fertig.“ verknüpfen. Ein Temperaturwert
kann mit **Überschreitet**, Vergleich `30` und dem Text „Die Temperatur beträgt
{value}.“ verbunden werden. Die Quelle muss dafür nicht zu Home Connect gehören.

Wird gleich/ungleich und Grenzwerte lösen beim Eintritt in die Bedingung aus.
„Bei Aktualisierung“ kann nach Ablauf des Mindestabstands erneut auslösen, auch
bei unverändertem Wert. `{value}` verwendet den Rohwert des auslösenden Updates;
Boolean-Werte werden zu Ja/Nein. Bei neu aktivierten Regeln wird der vorhandene
Wert als Ausgangszustand übernommen, ohne sofortige Durchsage.

Ausgaben werden außerhalb des Variablen-Callbacks nacheinander verschickt. Regeln
und Quelle werden vor der Ausgabe erneut geprüft. Abgeschaltete, veränderte oder
mehr als zwei Minuten alte Aufträge werden verworfen. Ausgaben in Ruhezeiten werden
nicht später nachgeholt. Fehlgeschlagene Aufträge werden nicht automatisch erneut
gesendet. Die Liste zeigt das letzte Sendeergebnis; der JSON-Export enthält die
Regeln samt ihren Textvorlagen und die letzten hundert Sendeversuche. Der Verlauf
speichert keine Nachrichtentexte oder Anmeldedaten.

Die bestehende Echo-Remote-Konfiguration bestimmt, ob alle Geräte des verbundenen
Amazon-Kontos oder das Standardgerät angesprochen werden. Dafür muss der Custom
Skill nicht geöffnet sein. Ein positives Sendeergebnis ist die Annahme des Auftrags
durch Echo Remote, keine Bestätigung der tatsächlichen Wiedergabe.

## Was weiterhin von den Gerätedaten abhängt

Weitere Programmoptionen können erst nach Auswahl oder Betrieb eines Programms
erscheinen. Fehlende Alarmarten, Kochzonen-Leistungswerte und zusätzliche
Mikrowellenparameter werden nicht erfunden. Neue vorhandene Variablen werden bei
späteren Fragen gelesen. Nicht eindeutig zugeordnete Befehle bleiben ohne Aktion.
Die echte Programmende-Meldung und die Bedienung der Geräte müssen in der eigenen
Installation praktisch geprüft werden; lokale Tests verwenden die exportierten
Geräte und simulierte Zustandswechsel.
