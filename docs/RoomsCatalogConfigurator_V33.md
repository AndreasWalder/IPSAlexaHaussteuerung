# RoomsCatalogConfigurator V3.3 – Geräte erkennen

V3.3 ergänzt die bestehende V3.0-Gerätebeschreibung und die V3.1-Raumverwaltung um eine **kontrollierte Gerätesuche**. Es gibt keinen zweiten Gerätebestand und keine automatische Änderung am produktiven Katalog.

## Bedienung in IP-Symcon

1. IP-Symcon-Modul `IPSAlexaHaussteuerung` aus GitHub aktualisieren und die Konfiguration des `RoomsCatalogConfigurator` öffnen.
2. Im Abschnitt **V3.3: Neue IP-Symcon-Geraete erkennen** mindestens eine Suchwurzel wählen (Kategorie oder Instanz). Optional weitere Kategorie-/Instanz-IDs durch Kommas getrennt eintragen. Die geänderte Modulkonfiguration zunächst **übernehmen**.
3. **NEUE GERAETE SUCHEN** starten. Es werden ausschließlich Objekte innerhalb dieser Suchwurzeln untersucht.
4. Die Vorschläge in der Tabelle prüfen. Bei Bedarf **Raum**, **Gerätetyp** oder **Gerätename** korrigieren. Eine grüne beziehungsweise „Hoch“-Bewertung ist nur eine Hilfestellung, keine Freigabe.
5. Einzelne Geräte markieren oder **EINDEUTIGE MARKIEREN** verwenden. Anschließend **MARKIERTE GERAETE NACH EDIT UEBERNEHMEN** bestätigen.
6. Neue Geräte im Geräteeditor auf korrekte Status-/Steuervariablen und insbesondere die tatsächliche Bedeutung von `0/1` und `0/100` kontrollieren. Erst danach je nach Wunsch KI-Lesefreigaben setzen und über den bisherigen validierten **EDIT NACH PROD UEBERNEHMEN**-Ablauf produktiv schalten.

## Sicherheits- und Kompatibilitätsregeln

- Der Scanner läuft **nicht über den kompletten Objektbaum**: Nur konfigurierte Wurzeln werden genutzt, begrenzt auf 2.500 geprüfte Objekte und 200 Kandidaten pro Scan.
- Bereits erfasste Geräte werden anhand der bisherigen Variablen-IDs und `discoverySourceId` übersprungen. Ein einzelner IPS-Wert darf nicht unbemerkt mehreren importierten Geräten zugeordnet werden.
- Eine erkannte Raumzuordnung nutzt Raum-Keys, Anzeigenamen und V3.1-Aliasse entlang der IPS-Objekthierarchie. Ein nicht eindeutig bestimmbarer Raum bleibt offen.
- Die typabhängige Variablenbewertung nutzt die bestehenden V3.2-Regeln. Nur eindeutige Zuordnungen werden übernommen; mögliche Fehlinterpretationen erfordern manuelle Prüfung.
- Eine Vorschau verfällt nach zehn Minuten und sobald sich Suchwurzeln oder `RoomsCatalogEdit` seit dem Scan geändert haben.
- Neue Geräte landen **ausschließlich** in `RoomsCatalogEdit` mit `kiEnabled=false`, `kiReadOnly=true`. Bestehende Gerätedaten, Räume und zusätzliche Metadaten bleiben erhalten. `RoomsCatalog` und die produktive KI-Steuerung werden beim Geräteimport nicht verändert.
- Die bekannten Dateien/Skripte `RoomsCatalog`, `RoomsCatalogEdit` und die verifizierte `RoomsCatalog_Backup`-Sicherung bleiben Bestandteil der EDIT→PROD-Freigabe.

## Grenzen von V3.3

Die Erkennung gruppiert IP-Symcon-**Instanzen** und kleine Blatt-Kategorien mit Variablen. Auch eine gute Trefferbewertung ersetzt keine fachliche Prüfung; zum Beispiel kann die Variable „Status“ zu einem Gerät gehören, das keine Leuchte ist. Kontakte, Jalousiepositionen, Stromzähler und Geräte ohne eindeutige Profil-/Namenshinweise müssen gegebenenfalls nachbearbeitet werden.

V3.4 erweitert anschließend die KI-Auswertung der typisierten Geräte und ihrer mehreren Eigenschaften. Bis dahin bietet V3.3 vor allem die schnellere, sichere Pflege des Katalogs.
