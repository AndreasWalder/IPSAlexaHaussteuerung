# X-Sense und Sicherheitsbericht

X-Sense-Geräte aus Home Assistant werden als **devices → xsense → Gerätename** in den RoomsCatalog aufgenommen. Es werden keine festen Objekt-IDs aus den Screenshots vorausgesetzt. Geräte und einzelne Variablen bleiben ausschließlich lesbar.

## Einrichtung

1. Module aktualisieren und Änderungen übernehmen.
2. Im RoomsCatalogConfigurator den Abschnitt **X-SENSE – Rauchmelder, Wassermelder und Basisstation** öffnen. Der Abgleich findet Home-Assistant-Instanzen unter einem X-Sense benannten Zweig. Falls dieser Zweig anders heißt, die passende Kategorie im optionalen Kategorie-Feld auswählen und die Konfiguration übernehmen.
3. **X-SENSE IN EDIT ABGLEICHEN** ausführen. Unter **Geräte bearbeiten** den Bereich **devices** und die Gruppe **xsense** wählen. Der automatische EDIT-Abgleich findet auch beim Neuladen statt.
4. Über das Zahnrad Gerätenamen, alternative Namen, Raum, Gruppe und Geräteart prüfen. Alle nicht privaten Variablen sind mit aktuellem Wert und Kennung aufgelistet. Name, Verknüpfung, Bedeutung, Aktivzustand und Lesefreigabe sind editierbar. Die Variable muss zum ausgewählten Gerät gehören; doppelte Zuordnungen werden abgelehnt. Das Bearbeiten einer Variable aktualisiert nur deren Liste, damit der geöffnete Dialog nicht seine ausgewählte Zeile verliert.
5. **EDIT nach PROD übernehmen**. Der Abgleich selbst veröffentlicht nichts und erhält vorhandene Namen, Verknüpfungen und Freigaben.

Der in den Screenshots gezeigte Bestand umfasst eine Basisstation, zehn Rauchmelder und drei Wassermelder. Maßgeblich ist der tatsächliche lokale Objektbaum; zusätzliche Geräte werden beim erneuten Abgleich ergänzt. Unbekannte Gerätearten und unbekannte Variablen bleiben zur Prüfung sichtbar.

## Fragen und Statusseiten

| Frage | Auswertung |
| --- | --- |
| Wie geht es meiner Brandmeldestation? | Rauchmelder und Basisstation, Rauchalarm zuerst, danach Störungen und Wartungshinweise |
| Wie geht es meinen Wassermeldern? | Wassermelder und Basisverbindung, aktive Wassermeldung zuerst |
| Wie geht es meinem Rauchmelder Büro? | Passender Gerätename oder alternativer Name |
| Wie ist die Sicherheit im Haus? | X-Sense, veröffentlichte Fenster-/Türkontakte sowie typisierte Sicherheitsgeräte, Schlösser, Alarmanlage, Kameras und Bewegungsmelder |
| Welcher Akku bei den X-Sense-Geräten ist am schlechtesten? | Niedrigster vergleichbarer Ladestand in Prozent, Gleichstände und fehlende Werte ausdrücklich genannt |
| Welcher X-Sense-Akku ist am schnellsten gefallen? | Größter beobachteter mittlerer Rückgang unter den auswertbaren Batterieaufzeichnungen; unterschiedliche Messzeiträume werden genannt |
| Wann gab es zuletzt Rauchalarm / Wasseralarm? | Tatsächliche aktive Alarmrückmeldung im Archiv |
| Wie oft gab es heute Rauchalarm bei X-Sense? | Nachgewiesene Aus→Ein-Wechsel pro aufgezeichnetem Melder |
| Zeige alle Werte der X-Sense-Geräte | Zusätzliche freigegebene Variablen auf der Statusseite |

Die Seiten verwenden das gemeinsame responsive Alexa-Layout mit Rauchmelder-, Wasser-, Batterie- oder Schildsymbol und einer scrollbaren Liste. Die Sprachausgabe nennt Probleme und deren Bedeutung, statt jede Variable vorzulesen. Längere Gerätelisten bleiben auf dem Display vollständig sichtbar. Die Auswertung funktioniert lokal ohne zusätzlichen KI-Aufruf; die freigegebenen Standard-Lesebindungen stehen außerdem den allgemeinen KI-/Archivwerkzeugen zur Verfügung.

## Fenster zuletzt offen

Sind **alle erfassten Fenster eindeutig geschlossen**, ergänzt der Sicherheitsbericht den letzten im Archiv gefundenen offenen Fensterzustand mit Gerätename und Datum/Uhrzeit. Der Bericht sagt **offen protokolliert**, weil ein Archivmesspunkt keinen exakten Öffnungsbeginn beweist. Gesucht wird bis zu 365 Tage zurück. Ein fehlender oder unklarer Fensterkontakt verhindert die Behauptung, alle Fenster seien geschlossen. Aktuell offene Fenster haben Vorrang.

Invertierte Zuordnungen und Profiltexte werden berücksichtigt. Nicht archivierte Kontakte, widersprüchliche Werte oder ein erreichtes Zeit-/Mengenlimit schränken die Aussage auf die geprüften Werte ein. Ein fehlender Nachweis wird nicht mit einem nie geöffneten Fenster gleichgesetzt.

## Alarm- und Batterieverlauf

Verwendet wird die konfigurierte Archive-Control-Instanz beziehungsweise eine eindeutig vorhandene Instanz. Die Integration aktiviert oder verändert keine Aufzeichnung. Für Verlaufsfragen müssen die echten Rauch-/Wasser-/Basisalarmrückmeldungen, Batterie-Prozentwerte und Fensterkontakte bereits in Symcon archiviert sein.

- Der allgemeine Statusbericht prüft Alarmaufzeichnungen der letzten 30 Tage. Ausdrückliche Fragen nach dem letzten Alarm suchen ohne anderen Zeitraum bis zu 365 Tage zurück. Heute und gestern verwenden Kalendergrenzen für **Europe/Vienna**.
- Mehrere aktive Messpunkte zählen nicht mehrfach. Ein Alarmstart setzt einen belegten inaktiven Ausgangszustand voraus. Fehlt dieser oder ist die Aufzeichnung unvollständig, werden nur nachgewiesene Starts bzw. Untergrenzen genannt. Mehrere Melder können denselben Vorfall melden; ihre Starts sind keine Anzahl unabhängiger Brände.
- Bei Batteriefragen ergänzen vorhandene Messpunkte der letzten 30 Tage die Statusseite um Veränderungen in **Prozentpunkten** und die tatsächlichen Messzeitpunkte. Ein Ladestand oder Rückgang ist keine Messung von Akkukapazität, Defekt oder verbleibender Lebensdauer. Gleiche Ladestände ergeben keinen erfundenen schlechtesten Akku.
- Der gemeinsame Archivleser lädt Rohdaten seitenweise innerhalb eines Zeitbudgets. Unvollständige Abfragen und fehlende Aufzeichnungen sind sichtbar. Ausgeblendete Geräte/Variablen dürfen auch über den Archivweg nicht gelesen werden.

## Eindeutige Zustände und Grenzen

Die bei einem Wassermelder teilweise als **Rauch** benannte Boolean-Variable wird anhand der Geräteart als **Wasseralarm** verstanden. Die Geräteart ist editierbar; bei einer Änderung sind auch die Variablenrollen zu prüfen. Befehlstasten, Alarm-/Sprachlautstärke, Safe Mode und als veraltet gekennzeichnete Alarm- oder Selbsttestwerte gelten nicht als aktuelle Alarmrückmeldung. Die Anzeige eines veralteten Selbsttestwertes bedeutet keinen bestandenen Selbsttest.

Aktive Alarme haben Vorrang, auch wenn eine zweite Rückmeldung widerspricht oder das Gerät nicht verbunden ist. Nicht verbundene Geräte, Lebensdauerfehler, Stummschaltung, aktive Tests, Batteriewarnungen und Prozentwerte unter 20 % werden erklärt. Eine ausdrücklich gemeldete Gerätezeit älter als 48 Stunden oder eine unplausible Gerätezeit wird als Hinweis ausgegeben. Ein alter Symcon-Speicherzeitpunkt allein wird nicht als Sensorausfall gewertet, da unveränderte Werte nicht zwingend erneut gespeichert werden.

Ein erreichbar gemeldeter Home-Assistant-Kanal beweist weder eine aktuelle Sensormeldung noch einen erfolgreichen Selbsttest. Der Bericht beschreibt verfügbare Rückmeldungen und behauptet keine umfassende Sicherheitsprüfung. IP-Adressen, WLAN-Namen und Zugangsdaten werden nicht in den Katalog aufgenommen. Tests, Stummschalten, Scharfschalten und sonstige Gerätebefehle werden über diese Integration nicht ausgeführt. Automatische X-Sense-Durchsagen sind mit dieser Änderung nicht eingerichtet.
