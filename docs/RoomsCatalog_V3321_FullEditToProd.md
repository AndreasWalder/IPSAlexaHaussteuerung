# RoomsCatalogConfigurator V3.3.21 – einfacher EDIT → PROD Button

## Gewünschter Ablauf

Im Hauptformular stehen nur die grundlegenden Bereiche:
- Prüf-Zwischenspeicher zurücksetzen
- RoomsCatalog Scripts (PROD/EDIT auswählen)
- Räume & Etagen
- Geräte bearbeiten (RoomsCatalog EDIT)
- **EDIT NACH PROD UEBERNEHMEN** – ein einziger produktiver Übernahme-Button

Die bisherigen, historisch hinzugefügten Bereiche für gesonderte Freigaben, EDIT/PROD-Differenzen, Migrationsprüfungen, selektive Bereichsübernahmen, Einzelpositions-Übernahmen und globale technische Prüfungen werden **nicht mehr angezeigt**. Die regulären individuellen Eingabefelder und Prüfungen im Geräte-Bearbeiten-Dialog bleiben erhalten.

## Bedeutung von EDIT → PROD

Nach einer ausdrücklichen Bestätigung kopiert `SaveEditToProd()` den **gesamten PHP-Skriptinhalt** von RoomsCatalogEdit direkt nach RoomsCatalog PROD.

- Alle Räume, Geräte und Bereiche – insbesondere Jalousien, Licht/Dimmer, Lüfter, Heizung, Sensoren – werden übernommen.
- Alle Einstellungen, IDs, Statusmappings, individuellen KI-Lese- und Schreibfreigaben, Bestätigungsmetadaten und auch noch *unvollständige* Gerätebeschreibungen werden übertragen.
- **Keine technische oder fachliche globale Geräteprüfung** kann die Übernahme wegen einer fehlenden Jalousierückmeldung, doppelten Profilrolle oder einer unklaren optionalen Variablen verhindern.
- Es gibt **keine** partielle Zusammenführung. PROD wird inhaltlich vollständig durch EDIT ersetzt.

## Schutz beim Schreiben

Die Übernahme wird ausdrücklich bestätigt. Das Modul prüft nur:
1. Es gibt getrennte gültige EDIT-/PROD-Skripte.
2. Der EDIT-Skriptinhalt ist ein nicht-leeres, statisch definiertes PHP-Array (keine freien ausführbaren PHP-Anweisungen).
3. EDIT und PROD haben sich seit dem Einlesen nicht verändert.
4. Vor jeder Änderung von PROD wird der **bisherige PROD-Skriptinhalt vollständig** in ein separates Backup-Skript kopiert und dessen Inhalt wieder eingelesen und verifiziert.
5. Der neue PROD-Inhalt wird ebenfalls nochmals verifiziert. Bei einem Schreibfehler wird die ursprüngliche PROD-Quelle zurückgeschrieben, soweit möglich.

Bei exakt identischem EDIT und PROD erfolgt keine unnötige Schreiboperation oder Backup-Erstellung.

## Einschränkung

Dies ist eine bewusste Kopie, keine Freigabe oder fachliche Geräteprüfung: Ein irrtümlich falscher oder unvollständiger Eintrag in EDIT wird genauso nach PROD übernommen und kann dadurch Antworten oder Steuerungen der produktiven Alexa-/IP-Symcon-Anbindung beeinflussen.

Wer noch prüfen möchte, nutzt die Prüfung beim Bearbeiten des einzelnen Geräts. PROD lässt sich bei Bedarf anhand des automatisch erzeugten Backups wiederherstellen.

## Tests

Regressionsprüfungen stellen sicher, dass der einfache Button **einmal** sichtbar ist, alle alten Übernahmeaktionen ausgeblendet sind, eine unvollständige Jalousie den Komplett-Kopiervorgang **nicht** blockiert, auch andere Geräte/Räume vollständig übernommen werden, Backups den Originalzustand erhalten, EDIT unverändert bleibt und ungültige/ausführbare PHP-Quellen oder leere Daten nicht übernommen werden.
