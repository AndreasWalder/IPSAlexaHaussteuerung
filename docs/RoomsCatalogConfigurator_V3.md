# RoomsCatalogConfigurator V3.0 — rückwärtskompatibles Gerätemodell

Die V3.0 erweitert den bestehenden Konfigurator um eine **typabhängige Gerätebearbeitung**. Bestehende Alexa-Renderer und der KIHouseController verwenden weiterhin ihre bisherigen Felder. Die zusätzlichen `ai`-Eigenschaften werden in V3.0 **noch nicht** für Live-Abfragen oder Schaltaktionen ausgewertet; das folgt in V3.4.

## Vorhandene Skripte bleiben erhalten

| Skript | Funktion |
| --- | --- |
| `RoomsCatalog` | Produktive Konfiguration; wird nur durch „EDIT NACH PROD UEBERNEHMEN“ geändert |
| `RoomsCatalogEdit` | Alle Bearbeitungen; zunächst ohne produktive Wirkung |
| `RoomsCatalog_Backup_*` | Vor jeder produktiven Übernahme eine verifizierte Kopie des vorherigen PROD-Skriptinhalts |

Das Format `<?php return [...];` bleibt. Bereits vorhandene Root-Keys wie `wohnzimmer`, `kueche`, `global` sowie ein gegebenenfalls vorhandener `rooms`-Wrapper bleiben in ihrer ursprünglichen Form. Eine automatische Umwandlung aller Kataloge findet nicht statt.

## Additives Gerätemodell

Beispiel für eine Jalousie mit getrennten Eigenschaften:

```php
'sued' => [
    'title' => 'Jalousie Süd',
    'wert' => 34567,              // Bisherige Felder bleiben erhalten
    'kiEnabled' => true,
    'kiReadOnly' => true,
    'ai' => [
        'version' => 1,
        'type' => 'blind',
        'variables' => [
            'position' => 34567,
            'targetPosition' => 23456,
            'tilt' => 45678,
            'moving' => 56789,
            'control' => 12345,
        ],
        'stateMapping' => [
            'open' => 0,
            'closed' => 100,
        ],
    ],
],
```

`ai.version = 1` ist die Versionskennung für die additive Gerätebeschreibung. Für einen bestehenden Eintrag wird **kein** `ai`-Block eingefügt, solange im Editor kein neuer Gerätetyp explizit ausgewählt wurde. Bestehende unbekannte Felder unter `ai`, etwa für spätere Funktionen, bleiben beim Bearbeiten erhalten.

Unterstützte Editor-Typen: Licht, Dimmer, Jalousie, Heizung, Fensterkontakt, Steckdose/Schalter, Energiezähler, Bewegungsmelder, Lüftung und Sonstiges.

## Bedienung in IP-Symcon

1. In der Module Control das GitHub-Modul aktualisieren und im **RoomsCatalogConfigurator** auf **Übernehmen** klicken.
2. Die vorhandenen Skript-IDs für PROD und EDIT belassen. **ROOMSCATALOG NEU LADEN** lädt den Edit-Stand.
3. Gerät in „Geräte und KI-Freigaben“ öffnen. Die Gruppe entscheidet zunächst, welche Variablen als Vorschläge angezeigt werden (beispielsweise Position/Lamellen bei Jalousien).
4. In **V3.0: Gerätetyp und KI-Variablen** den passenden Typ ausdrücklich auswählen, die IP-Symcon-Variablen zuordnen und ggf. Statuswerte eingeben. Bei einem Typwechsel zuerst speichern und den Dialog erneut öffnen.
5. Nach dem Speichern werden die Ergänzungen **nur in `RoomsCatalogEdit`** abgelegt. Mit **DIFF-VORSCHAU** und **KATALOG PRUEFEN** kontrollieren.
6. Erst bei freigegebenem Stand **EDIT NACH PROD UEBERNEHMEN** wählen. Der Vorgang prüft IDs, doppelte Einträge, Statuszuordnungen und ob beim erneuten Aufbauen des Katalogs vorhandene Felder verloren gehen würden. **PROD bleibt unverändert, wenn ein Problem gefunden oder die Backup-Kopie nicht bestätigt wird.**

Die Bedeutungen `0 = offen` und `100 = geschlossen` sind **Beispielwerte** und müssen für jede Jalousie anhand der tatsächlichen Variable bestätigt werden. Ohne klare Zuordnung bleibt eine bereits für KI freigegebene Jalousie mit explizitem `ai.type = blind` von der Produktivübernahme ausgeschlossen.

## Was V3.0 noch nicht macht

- Keine automatische IP-Symcon-Gerätesuche oder Profilanalyse (V3.3).
- Keine zentrale Bearbeitung von Raum-Aliases/Etagen; vorhandene `aliases`, `floor` und zusätzliche Raum-Metadaten bleiben erhalten (V3.1).
- Keine Live-Auswertung der neuen AI-Variablen im KIHouseController und keine erweiterten Schaltrechte (V3.4).
- Keine Migration oder Änderung der produktiven Geräte bei einem bloßen Modul-Update.

Regressionstest: `php tests/test_rooms_catalog_v3_model.php`. Die bisherige Mehrfachauswahl, die gewohnten Felder und die Edit-/Prod-Trennung werden zusätzlich mit den bestehenden Tests abgesichert.
