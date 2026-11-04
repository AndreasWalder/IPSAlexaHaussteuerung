# RoomsCatalog V3.4.4 – zentrale Gerätetypen und Eigenschaften

## Ziel

Die **einzige Definition** der Typen, lesbaren Eigenschaften, Anzeigenamen, Datentypen, minimalen Rückmeldungen und Zielbereiche liegt in:

`RoomsCatalogConfigurator/libs/DeviceTypeRegistry.php`

Der Konfigurator (Anlegen, Bearbeiten, Mehrfachauswahl, Gerätinventur, technische Prüfung) und der produktive Alexa-`KIHouseController` nutzen diese Definition. Der Controller wird beim Modul-Übernehmen als IP-Symcon-Skript angelegt; die Registry wird dabei aus der Quelldatei **in das erzeugte Skript eingebettet**, damit das Skript nicht von einem relativen Modulpfad abhängt.

Keine Änderung der bestehenden `RoomsCatalog`, `RoomsCatalogEdit` oder `RoomsCatalog_Backup`-Daten und **keine automatische Freigabe von Schreibaktionen**.

## Vorhandene Typen – unverändert

`light`, `dimmer`, `blind`, `heating`, `window`, `switch`, `energy`, `motion`, `illuminance`, `fan`, `generic`.

## Neue Typen – zunächst nur lesbar

| Typ | Bezeichnung | Auswählbare Eigenschaften |
| --- | --- | --- |
| `door` | Türkontakt | Offen/Geschlossen, Status |
| `temperature` | Temperatursensor | Isttemperatur |
| `humidity` | Luftfeuchtigkeitssensor | Luftfeuchtigkeit |
| `smoke` | Rauchmelder | Alarm, Batteriestand, Status |
| `water_leak` | Wassermelder | Wasser erkannt, Alarm, Status |
| `weather` | Wetterstation | Temperatur, Luftfeuchtigkeit, Wind, Regen, Lux |
| `camera` | Kamera | Online, Bewegung, Status (kein Kamerastream oder Passwort) |
| `irrigation` | Bewässerung | Ein/Aus-Rückmeldung, Status, vorhandene Steuerungsvariable – ohne neue Schaltfreigabe |
| `lock` | Türschloss | Verriegelt, Batterie, Status – kein Entriegeln |
| `alarm` | Alarmanlage | Scharf, Alarm, Status – keine Aktivierungsfunktion |
| `solar` | Photovoltaik | Leistung, Energie, Einspeisung, Eigenverbrauch |
| `battery` | Batteriespeicher | Ladezustand, Lade-/Entladeleistung |
| `water_meter` | Wasserzähler | Wassermenge, Durchfluss |
| `ev_charger` | Wallbox | Laden aktiv, Leistung, Energie, Batteriestand |
| `media` | Mediengerät | Ein/Aus-Rückmeldung, Lautstärke, Status – ohne neue Schaltfreigabe |
| `network` | Netzwerkgerät | Online/Offline, Status |

**Nicht enthalten:** Präsenzmelder (`presence`) und CO₂-Sensor (`co2`).

## Wenig Konfigurationsaufwand

Im `RoomsCatalogConfigurator` das gewünschte Gerät anlegen und **Gerätetyp** auswählen. Der Dialog zeigt ausschließlich die passenden Eigenschaften als `SelectVariable`. Die Domain/Gruppe und Anzeigenamen der Eigenschaften kommen automatisch aus der zentralen Registry.

- Bestehende Geräte werden **nicht neu angelegt** und behalten alle bisherigen Verknüpfungen, unbekannten Zusatzfelder und KI-Rechte.
- Fehlende optionale Eigenschaften dürfen leer bleiben. Voraussetzung für KI-Leseabfragen ist mindestens eine gültige lesbare Variable.
- Die Gerätinventur erkennt eindeutige Typnamen/Instanznamen als **Vorschlag**; mehrere hochsichere Variablen können über die bereits vorhandene **Sammelbearbeitung** oder **„MARKIERTES GERÄT ANALYSIEREN“ → „EINDEUTIGE VORSCHLÄGE NACH EDIT ÜBERNEHMEN“** ergänzt werden.
- Eindeutig bedeutet: Namen/Ident/Typ passen; **die Bedeutung des Wertes** (z. B. `true=offen`) muss fachlich geprüft werden. Das ist kein automatisches Schalten.
- Neue Geräte starten mit `kiEnabled=false` und `kiReadOnly=true`. Nach dem Zuordnen und Prüfen kann „Mit KI“ aktiviert werden. PROD erst mit `EDIT NACH PROD UEBERNEHMEN` nach Prüfung und Backup.
- Das System speichert weiterhin nur die IPS-IDs unter `ai.variables`; die zentralen Eigenschaftsvorlagen erzeugen **keine** zusätzlichen IPS-Variablen.

Beispiel Wetterstation:

```php
'ai' => [
    'version' => 1,
    'type' => 'weather',
    'variables' => [
        'temperature' => 12345,  // bestehende IPS-ID
        'humidity' => 23456,
        'rain' => 34567
    ]
]
```

Die IDs sind **rein fiktive Beispiele**, keine automatisch erzeugten Geräte.

## „Letzte Änderung“ ohne extra Pflege

Die letzte Änderung kommt aus **`IPS_GetVariable(ID)['VariableChanged']`**, einem Unix-Zeitstempel der *tatsächlichen Wertänderung*. Es wird **kein** Timer, eigenes Skript oder zusätzlicher Eintrag im RoomsCatalog benötigt.

**Nur bei ausdrücklichen Fragen** wie „Wann wurde die Jalousie zuletzt geändert?“, „Seit wann ist das Fenster offen?“, „Wann gab es die letzte Änderung am Gerät?“ werden `lastChangedAt` (ISO-8601 mit Zeitzone) und `lastChangedProperty` zum Ergebnis ergänzt. Normale Fragen wie „Welche Jalousien sind zu?“ oder „Wie warm ist es?“ liefern **keine Zeitfelder**. `get_devices` zeigt ebenfalls nur auf Nachfrage die Zeitfelder.

Wichtig: Ein Zeitstempel ist **keine vollständige Historie**. Bei Geräten mit mehreren Eigenschaften zeigt er den letzten Wertwechsel einer lesbaren Eigenschaft und nennt deren Namen. Er darf nicht automatisch als Zeit der letzten Fensteröffnung ausgegeben werden, wenn eine andere Eigenschaft geändert wurde. Wenn `VariableChanged` nicht verfügbar oder null ist, wird **kein Zeitpunkt erfunden**. `VariableUpdated` wird bewusst nicht als letzte Änderung verwendet, weil ein erneutes Lesen oder Schreiben desselben Werts kein Zustandswechsel ist.

## Tests und Freigaben

- `tests/test_device_property_registry_v344.php`: 27 Typen, Rollen und IPS-Datentypen, fehlende Präsenz-/CO₂-Typen, neue Typen ohne Schreibrecht, `VariableChanged` nur auf Nachfrage.
- Bestehende Alexa-KI-, RoomsCatalog-, Migrations-, Prüfungs- und UI-Tests bleiben aktiv.
- Ein erfolgreiches GitHub-CI beweist keine realen KNX-/IP-Symcon-Werte; sie müssen pro Geräteklasse einmal auf echte Bedeutung geprüft werden.
