# RoomsCatalog V3.3.14 – KNX 2-Bit-Control und echte Licht-Rückmeldungen

## Ausgangslage
Nach 10 sicher korrigierten Heizungszuordnungen:
- 167 Geräte, 134 technisch plausibel, 33 weiterhin zu prüfen.
- EDIT und PROD sind identisch (gleiche Hashes), keine ungültigen/Legacy-Geräte.

Die frühere JSON-Gesamtdiagnose enthält drei `light`-Einträge,
bei denen `ai.variables.power`, `ai.variables.status` und das
Legacy-Feld `state` auf das **Steuerung/Value0**-Bit einer
KNX-DPT2-Instanz (`~PDPT2.KNX`, „Keine Priorität/Priorität“)
zeigen. `Value0` ist kein Ein/Aus-Rückmeldesignal.

Im bereits vorhandenen IP-Symcon-Variablen-Scope ist jeweils
genau eine **read-only** Boolean-Variable `Funktionswert/Value1`
mit Profil `~Switch`, Werten `Aus/An` vorhanden.

| Gerät | Bisherige state/power/status | Neuer Rückmeldewert |
| --- | ---: | ---: |
| Kinderzimmer groß Schreibtischlampe | 47850 | 44017 |
| Kinderzimmer klein Deckenlicht | 16254 | 10517 |
| OG WC Deckenlicht | 28641 | 41827 |

Dies sind Erkenntnisse anhand der älteren Diagnose vor dem
10-Heizungen-Patch; die V3.3.14-Aktion verifiziert jede Variable
vor Übernahme nochmals live **nur anhand der Metadaten**.

## Zwei bestehende Buttons
1. `GESAMTDIAGNOSE FUER CHATGPT HERUNTERLADEN`:
   aktueller Scan, Korrekturvorschläge und Hash-basierte Vorschau.
2. `SICHERE AUTO-KORREKTUREN NACH EDIT + PROD`:
   innerhalb 10 Minuten nach Vorschau, ausdrückliche Bestätigung,
   identische Quelldateien, zwei unabhängige verifizierte Backups,
   Rollback bei Schreib- oder Prüfungsfehlern.

Automatisch geändert werden **nur dann alle drei Felder**
`state`, `ai.variables.power` und `ai.variables.status`,
wenn exakt der beschriebene KNX-DPT2-/Value1-Fall nachgewiesen ist.
`ai.variables.control`, `toggle`, `kiReadOnly`, `kiEnabled`,
`ai.review.status` und alle weiteren Daten bleiben unangetastet.
Eine Steuerung oder ein körperlicher Gerätetest wird **nicht** ausgeführt.

Bei überlappenden/mehrfachen, beschreibbaren, unvollständig
beschrifteten oder bereits fachlich bestätigten Kandidaten
findet keine automatische Übernahme statt.

## Weiterhin offen
Falls die drei Ersetzungen korrekt sind, würde die technische
Prüfung danach ungefähr `137 plausibel / 30 manuell prüfen`
melden. Noch zu klären:
- 25 Jalousien mit nur Steuerbefehlen, aber ohne nachgewiesene
  Istposition/Status-Rückmeldung.
- 1 Wohnzimmer-Jalousie Ost links mit widersprüchlicher
  Zuordnung von Position, Position-Soll und Fahrbefehl.
- 3 KNX-Lüfter mit mehrdeutigen Funktionswerten.
- 1 Sauna-Dimmer mit unklarem Skalierungs-/Profilmapping.

Auch nach einer technischen Plausibilitätsprüfung bleibt
die Bestätigung am tatsächlichen Gerät offen.
