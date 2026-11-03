# V3.3.15 – Technische Klassifikation von KNX-Jalousien

## Ausgangslage
Nach V3.3.14: 167 Geräte; 137 technisch plausibel, 30 zur manuellen technischen Prüfung. EDIT und PROD stimmen überein.

Unter den 30 Fällen:
- 25 Jalousien: eindeutig belegte Fahraktion, keine separate Istposition oder Statusrückmeldung nachgewiesen.
- Wohnzimmer Ost links: besondere Positions-/Befehlszuordnung.
- EG WC Lüfter stark, Bad Lüfter und OG WC Lüfter: KNX-Statusprofil nicht eindeutig.
- Sauna Deckenlicht (Dimmer): Helligkeitsprofil unklar.

## Neue Anzeige ohne Änderung der Kataloge
Die Tabelle »Technische Geräteprüfung« enthält jetzt eine zusätzliche Spalte »Funktionsumfang«. Als Ergänzung zur unveränderten Hauptsumme werden drei Kategorien unterschieden:

| Kategorie | Bei unveränderten Daten erwartet |
|---|---:|
| Technisch plausibel (nicht fachlich bestätigt) | 137 |
| Nur Steuerung (Ist-Rückmeldung nicht nachgewiesen) | 25 |
| Technische Zuordnung prüfen | 5 |

Die 25 Jalousien bleiben im ursprünglichen Status »Manuell prüfen« – sie werden nicht als technisch vollständig oder fachlich bestätigt deklariert.

Als »Nur Steuerung« wird ausschließlich eine eindeutig dokumentierte Jalousie-Fahraktion in der selben bekannten Instanz eingestuft. Vorausgesetzt werden eine gültige IPS-Aktion, ein Jalousie-/Rollladen-Steuerungsprofil mit den Befehlen Öffnen/Schließen/Stop und genau die beiden bereits bekannten offenen Punkte fehlende Pflicht-Rückmeldung sowie fehlende Statusbedeutung. Bei anderen Fehlern erfolgt »Technische Zuordnung prüfen«.

Eine Befehlsassoziation 0=Öffnen, 100=Schließen oder 999=Stop beweist **nicht**, dass die echte Jalousie geöffnet, geschlossen oder überhaupt bewegt wurde. Aktuelle Position und Fahrtrichtung bleiben ohne reale Rückmeldung unbekannt.

## Bedienung
Nach dem Update im RoomsCatalogConfigurator »ALLE GERAETE TECHNISCH PRUEFEN« drücken. Die drei Kategorien und die Funktionsumfang-Spalte werden angezeigt. Der Diagnoseexport enthält die Kategorien ebenfalls als `capability` und `capabilityCounts`.

Keine Konfigurationsdaten werden mit dieser reinen Prüfung verändert. Der Reparaturbutton erhält keine zusätzlichen Schreibaktionen. Fachliche Freigaben und KI-Rechte bleiben unverändert.
