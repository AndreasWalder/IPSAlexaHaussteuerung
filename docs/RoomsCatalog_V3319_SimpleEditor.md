# V3.3.19 – Einfacher RoomsCatalogConfigurator und minimale Geraetevariablen

## Kernansicht
Standardmaessig werden nur die Elemente zur laufenden Pflege angezeigt:
1. Alle Pruefungen/Zwischenspeicher zuruecksetzen
2. Raeume & Etagen
3. RoomsCatalog Scripts (PROD und EDIT)
4. Geraete bearbeiten (RoomsCatalog EDIT)

Der kurze Katalogstatus bleibt sichtbar. Alle bisherigen Diagnose-/Migrationstools, Inventur,
Massenbearbeitung, EDIT/PROD-Vergleich und beide PROD-Uebernahmen sind nicht entfernt,
sondern in **Weitere Funktionen (Experten): Pruefen, Migration und PROD** verschoben.
Dieser Bereich ist standardmaessig **geschlossen** und alle enthaltenen
ExpansionPanels ebenfalls. Die Callback-Skripte und gespeicherten Einstellungen
bleiben identisch. Ein Oeffnen des Formulars schreibt weder EDIT noch PROD.

## Beim Bearbeiten: nur sinnvolle Felder

### Luefter
- `ai.variables.control`: **Steuerung (Ein/Aus)** (eine gueltige IPS-Aktion notwendig, wenn gesteuert werden soll).
- `ai.variables.power`: **Ein/Aus-Wert (optional)**.
- Beide IDs duerfen identisch sein. Ist nur `control` vorhanden, ist auch das ein gueltiges Fan-Modell und darf als abfragbarer **Steuerwert** fungieren.
- Kein zusaetzliches Pflichtfeld `ai.variables.status`, keine Pflicht zur separaten Rueckmeldung, keine Eingabe manueller true/false-Zustandswerte im normalen Editor.
- **Nicht behaupten:** Der gemeinsame Steuerwert ist keine nachgewiesene physische Ist-Rueckmeldung. Die Geraetepruefung kennzeichnet solche Luefter als `Nur Steuerungswert (kein separater Iststatus)`.

### Heizung
- `temperature` (Isttemperatur)
- `targetTemperature` (Solltemperatur)
- `control` (beschreibbare Sollwertsteuerung)
- `targetTemperature` und `control` duerfen dieselbe ID haben, wenn sie wirklich beschreibbar ist. Kein zusaetzliches Geraetestatusfeld.

### Uebrige Typen
Die bisherigen Rollen und Sonderfaelle bleiben vorerst im unveraenderten Geratemodell;
die Kataloge und Berechtigungen werden nicht automatisch umgeschrieben.
Andere Felder / Objekt-IDs sind im Einzelgeraeteeditor im geschlossenen
`Weitere Felder / Objekt-IDs (Experten)` erreichbar.

## Weniger strenge technische Pruefung, aber keine blinde Freigabe
- Ein gueltiger Fan mit `control` und/oder `power` erfuellt die minimale Rollenpruefung.
- `power===control` ist erlaubt; die technische Pruefung bewertet dies **nicht** als doppelte oder fehlende Zuordnung.
- Eine Boolean-Steuerungsvariable mit echter IPS-Aktion muss nicht allein wegen einem neutralen KNX-Profil `Value` als uneindeutig gelten.
- Fehlt die Schreibaktion, darf trotzdem **keine** KI-Schreibfreigabe als sicher bestaetigt werden.
- Bei Jalousien gelten weiterhin die strengeren Regeln, weil ein Fahrbefehl nicht mit einer echten Positionsrueckmeldung gleichzusetzen ist.
- Der Auto-Reparatur-Assistent versucht nicht mehr, einem Luefter mit bereits vorhandener `control`-Rolle eine erfundene weitere Statusvariable zuzuweisen.

## Backup/Tests
Die Anpassung ist eine Modul-Code-Aenderung, keine Katalogmigration.
Regressions pruefen UI-Sichtbarkeit, unveraenderte Expertenfunktionen,
gemeinsame Fan-IDs, Fan ohne getrennte Rueckmeldung, Heizungs-Doppelrolle
und Unveraendertheit der beiden gesicherten Katalog-Skripte.
