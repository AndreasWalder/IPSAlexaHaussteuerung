# RoomsCatalogConfigurator V3.1 — zentrale Räume und Etagen

V3.1 ergänzt den bereits funktionierenden V3.0-Geräteeditor um eine **eigene Raumverwaltung**. Ziel ist: Räume, Etagen und alternative Namen nur **einmal** pflegen, auch wenn ein Raum viele Geräte besitzt.

## Bedienung

1. In IP-Symcon unter **Module Control** das GitHub-Modul aktualisieren und bei der vorhandenen RoomsCatalogConfigurator-Instanz **Übernehmen** wählen.
2. Im neuen Bereich **V3.1: Räume, Etagen und alternative Namen** einen Raum bearbeiten:
   - **Raum-Key:** bleibt unverändert, damit keine Gerätebezüge brechen.
   - **Raumname:** z. B. `Wohnzimmer`.
   - **Etage:** frei benannter Wert, z. B. `KG`, `EG`, `OG`, `DG`.
   - **Alternative Namen:** kommagetrennt, z. B. `Stube, Wohnbereich`.
3. Für einen neuen Raum die vier Felder unter der Tabelle ausfüllen und **NEUEN RAUM IM EDIT ANLEGEN** wählen. Der neue Raum hat zunächst keine Geräte.
4. Änderungen mit **DIFF-VORSCHAU** und **KATALOG PRUEFEN** prüfen. Im Diff-Bereich erscheinen jetzt auch reine Raumänderungen, selbst wenn keine Geräteeinträge verändert wurden.
5. Mit **EDIT NACH PROD UEBERNEHMEN** freigeben. Zuvor entsteht wie in V3.0 ein verifiziertes `RoomsCatalog_Backup_*`.

## Datenmodell

Die vorhandenen `RoomsCatalog`, `RoomsCatalogEdit` und `RoomsCatalog_Backup_*` bleiben PHP-Skripte mit `return [...]`. V3.1 ergänzt oder aktualisiert ausschließlich die Raum-Metadaten (`display`, `floor`, `aliases`) im bestehenden Katalog:

```php
'wohnzimmer' => [
    'display' => 'Wohnzimmer',
    'floor' => 'EG',
    'aliases' => ['Stube', 'Wohnbereich'],
    'domains' => [ /* vorhandene Geräte unverändert */ ],
],
```

Die produktive Datenstruktur wird nicht automatisch migriert. Zusätzliche Raumfelder, Geräte, verschachtelte `ai`-Variablen, der bisherige Root-Aufbau und auch der `global`-Bereich bleiben erhalten.

## Schutzmaßnahmen

- EDIT ist weiterhin die einzige Bearbeitungsbasis; PROD wird erst durch die ausdrückliche Übernahme geändert.
- Leere Räume werden beim späteren Speichern von Geräten nicht versehentlich entfernt.
- Gleiche alternative Namen für unterschiedliche Räume werden beim Bearbeiten/Anlegen abgefangen, damit spätere KI-Abfragen nicht mehrdeutig sind.
- Bei der PROD-Übernahme werden Raumfelder zusätzlich zur vorhandenen V3.0-Geräte- und Backup-Prüfung validiert.
- Die Oberfläche aktualisiert Raum- und Gerätelisten nach Änderungen, ohne den Konfigurator neu anzulegen.

Die Geräteerkennung (V3.3) und die aktive Nutzung von Raum-Aliasen im KIHouseController (V3.4) folgen später. In V3.1 werden diese Informationen bereits zentral und rückwärtskompatibel gespeichert.

Tests: `php tests/test_rooms_catalog_v31_rooms.php` sowie sämtliche bisherigen RoomsCatalog-/Alexa-Tests.
