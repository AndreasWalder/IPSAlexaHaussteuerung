# Archivfragen und Rückfragen

Vergangenheitsfragen werden vor den aktuellen Wetter- und Bewässerungsantworten ausgewertet. Die Datenbasis sind die in PROD freigegebenen Wetterstationswerte, die tatsächlichen Pumpen-/Kreisrückmeldungen der Bewässerung und die freigegebenen Lesebindungen des RoomsCatalog. Links auf denselben Messwert werden zusammengeführt. Gesperrte Werte und manuelle Schaltbefehle werden nicht als Bewässerungsnachweis verwendet.

## Beispiele

- „Wie oft war heute schon Frost?“ zählt zusammenhängende Frostphasen unter null Grad einschließlich einer bereits um Mitternacht laufenden Phase und nennt den Tiefstwert.
- „Wie lange war heute Frost?“ nennt die aus dem Verlauf belegte Dauer.
- „Wann wurde zuletzt gegossen?“ sucht nach tatsächlich protokollierter aktiver Bewässerung. Die Antwort nennt den letzten aktiven Archivmesspunkt, keinen geplanten Start und keinen behaupteten exakten Beginn. Ohne Zeitraum wird bis zu ein Jahr zurückgesucht.
- „Wie oft wurde heute bewässert?“ zählt belegte Aus→Ein-Wechsel je Kreis; mehrere Ein-Messpunkte ergeben keinen zusätzlichen Start.
- „Wie feucht war gestern die Hecke Ost?“ liefert den archivierten Wertebereich.
- „Und gestern?“ ersetzt den Zeitraum der vorherigen Frage. Kalendergrenzen werden für Europe/Vienna gebildet.

Die vorhandene allgemeine KI-Archivfunktion bekommt zusätzlich die freigegebenen Messwertbindungen aus Wetterstation und Bewässerung. Sie filtert Rolle und tatsächliche Archivierung vor der Bereichsprüfung. Eine ausdrückliche Anfrage nach einer bestimmten Eigenschaft wird nicht mehr allein wegen vieler Geräte verworfen. Der Zugriff bleibt lesend.

## Archivierung und Grenzen

Verwendet wird die eingestellte Archive-Control-Instanz; ohne gültige Auswahl kann eine eindeutig vorhandene Instanz automatisch erkannt werden. Die Integration aktiviert keine Archivierung. Fehlende Aufzeichnung wird genannt und ergibt keine erfundene Null. Pro Variable werden Rohwerte seitenweise mit überlappender Zeitgrenze geladen; gleiche Werte werden zusammengeführt, widersprüchliche Werte derselben Sekunde als unklar behandelt.

Fehlender Ausgangswert, gesperrte/deaktivierte Aufzeichnung, widersprüchliche Werte, nicht archivierte passende Rückmeldungen und Zeit-/Mengenlimits machen eine Auswertung unvollständig. Ereigniszahlen sind dann Untergrenzen; eine globale Aussage über den letzten Vorgang wird eingeschränkt. Auch vollständiges Auslesen kann nicht erkennen, ob vor der Archivierung Sensorereignisse verloren gegangen sind. Zusammenhängende Phasen und Laufzeiten setzen die von Symcon protokollierten Zustandswechsel voraus.

## Dialog

Eine Rückfrage bleibt 90 Sekunden an die Alexa-Sitzung und das Echo gebunden. Antworten wie „Bewässerung“, „bei der Bewässerung“, „die Hecke“ und nummerierte Auswahlantworten wie „die zweite Option“ werden vor der Menünavigation mit der ursprünglichen Frage verknüpft. Nicht direkt zuordenbare kurze Präzisierungen werden zusammen mit der ursprünglichen Frage an die Hausfragenlogik weitergereicht. Eine neue vollständige Frage oder ein ausdrücklicher Navigationsbefehl beendet die Rückfrage.

Die Auswahl verwendet ausschließlich Frage und Optionen mit Ziffern. Geräte-/Bereichsauswahlen werden gegen den aktuellen veröffentlichten Katalog geprüft. Die Antwort eines anderen Echos oder einer anderen Sitzung wird nicht übernommen. Mehrdeutige Geräte werden weiterhin erfragt; eine Auswahl erteilt keine zusätzliche Schreibfreigabe.
