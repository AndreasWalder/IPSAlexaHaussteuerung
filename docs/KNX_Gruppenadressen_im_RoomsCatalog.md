# KNX-Gruppenadressen im RoomsCatalog

Der RoomsCatalog-Konfigurator kann einen ETS-Gruppenadress-Export gegen die
KNX-Instanzen in IP-Symcon abgleichen. Der Export selbst wird nur gelesen und
nicht im Repository oder im RoomsCatalog gespeichert.

## Einrichtung

1. ETS als Gruppenadress-XML exportieren. Der Export muss Namen, Gruppenadressen
   und DPT enthalten. Eine `.knxproj`-Datei ist ein ETS-Projektarchiv und wird
   nicht direkt importiert; IP-Symcon benötigt dafür den Gruppenadress-XML-Export.
2. Im Konfigurator **ETS-Gruppenadress-XML auswählen** anklicken und die XML-Datei
   auswählen. Der XML-Export darf höchstens 10 MB groß sein. Symcon speichert die
   ausgewählte Datei Base64-kodiert in der Eigenschaft der Konfigurator-Instanz.
3. Die geänderten Formulareinstellungen übernehmen und danach **ETS-Export mit
   KNX-Variablen abgleichen** ausführen.
4. Treffer prüfen und nur gewünschte Namen markieren. Hohe Namensähnlichkeit zu
   bereits verknüpften Geräten wird vorausgewählt; die Liste zeigt auch Treffer,
   die noch keinem Kataloggerät zugeordnet sind.
5. **Ausgewählte Namen als Alexa-Synonyme nach EDIT übernehmen** ausführen.
6. EDIT prüfen und anschließend wie üblich kontrolliert nach PROD veröffentlichen.

Die Übernahme ändert ausschließlich die alternativen Namen bestehender EDIT-Geräte.
Sie erteilt keine Schreibfreigabe, ändert keine KNX-Konfiguration und löscht keine
Variablen. Noch nicht zugeordnete Geräte bleiben sichtbar und können zuvor über die
IP-Symcon-Geräteinventur mit Typ, Raum und Variablen nach EDIT übernommen werden.

## Erkannte Gerätetypen

Die automatische Geräteinventur enthält zusätzlich Vorschläge für Bodenfeuchtesensoren
und Saug-/Mähroboter. Beide werden zunächst nur lesend und mit ausgeschalteter
KI-Schaltfreigabe in EDIT angelegt. Bodenfeuchte und Roboterstatus können danach
über die normalen RoomsCatalog-Hausfragen abgefragt werden.

## Alexa Show

Erfolgreiche lesende `query_house`-Abfragen können auf Geräten mit Bildschirm eine
kompakte Statuskarte mit typabhängigem Vektor-Icon zeigen. Werte kommen aus derselben
RoomsCatalog-Abfrage wie die Sprachantwort. Rückfragen und Schreibaktionen erzeugen
keine Statuskarte.
