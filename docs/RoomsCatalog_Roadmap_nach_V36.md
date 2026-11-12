# RoomsCatalog: Roadmap nach V3.6.1
Stand 12.11.2026

## Von Anfang an vereinbart
1. Ein PROD-Katalog (RoomsCatalog), ein EDIT-Katalog (RoomsCatalogEdit) und verifizierte Backups.
2. Möglichst wenig Datenpflege: IP-Symcon-Instanzen, Profile, Variablen-Idents und Moduldefinitionen automatisch auswerten.
3. Alexa frei Fragen zu Räumen, Geräten, aktuellem Zustand, Historie, PV und Verbräuchen stellen.
4. Individuelle KI-Lese- und Schreibrechte. Niemals Aktoren allein aufgrund einer KI-Vermutung steuern.
5. Bestehendes Alexa-Gateway und Visu weiterverwenden, ohne einen externen KI-Agenten.

## Abgleich der ursprünglich geplanten Versionen

| Version | Ursprüngliches Ziel | Stand |
| --- | --- | --- |
| V3.0 | Rückwärtskompatibles typisiertes Gerätemodell | Erledigt |
| V3.1 | Räume / Etagen / Aliase zentral | Erledigt |
| V3.2 | Instanzbezogene Variablenvorschläge | Erledigt |
| V3.3 | Automatische Geräteerkennung | Stark erweitert |
| V3.4 | KI liest typisierte Geräte und Eigenschaften | Erledigt |
| V3.5 | Selbsttest, Livewerte, Fehlererkennung | Erledigt und erweitert |
| V3.6 | Energie/Zähler/Archiv | Erledigt (Archive Control und HuaweiPV) |
| V3.6.1 | HuaweiPV im Geräteeditor | Erledigt |
| V3.6.2 | Übersichtliche KI-Kosten ohne veraltetes Backend | In eigenem PR implementiert |
| V3.7 | Szenen und Capabilities | Noch offen |

## Prioritäten

### 1. V3.7 Szenen und Gerätefähigkeiten
- Szenen als explizite, freigegebene RoomsCatalog-Entitäten statt willkürlicher KI-Mehrfachaktionen.
- Fähigkeiten/Capabilities beschreiben lesbare Werte, schaltbare Werte und Archivverfügbarkeit; sichere Rechteprüfung auch bei Szenen.

### 2. Alexa im Gespräch
- Folgefragen sollen Raum, Gerät und Zeitfenster korrekt übernehmen.
- Mehrdeutigkeiten gezielt erfragen, unnötige API-Aufrufe und lange Wartezeiten reduzieren.
- Sprachbefehl „Visu“ sowie bestehende Tile-/Kamera-/Energie-Navigation auf den Alexa-Geräten praktisch prüfen.
- Plattformseitige Grenzen für die Dauer einer Alexa-Sitzung beachten.

### 3. Automatische Erkennung weiter ausbauen
- HuaweiPV ist der erste Provider für komplexe Modul-Datenquellen; andere Integrationen nach gleichem Muster.
- Eigene Typen/Prüfungen für Präsenz und CO₂.
- Profile, Einheiten, Idents, Archivabdeckung und Bedeutung kombinieren. Unklare Zuordnungen offenlassen.

### 4. Auswertungen, Diagnose und Kosten
- Zusammenhängende historische Energie-/Raumanalysen, aber ausschließlich mit tatsächlichen Messwerten.
- Präzisere, fragebezogene Diagnosen statt Logausgaben aller 175+ Geräte.
- Token, Laufzeiten, Modellpreise und Eurokosten weiter optimieren.

## Bereits umgesetzt – nicht noch einmal planen
- Einmaliger Geräteimport, KI-Rechte je Gerät und bei Huawei je Messwert.
- EDIT/PROD-Vorschau, Backup, Restore, Prüflogik.
- Archiv #17597 als Quelle für bereits aufgezeichnete Historie.
- Huawei Sun 2000 #59140 als DataSource; ein Eintrag in der EDIT-Gerätetabelle.
- Kopierbare letzte 500 Alexa-Meldungen und Euro-Kostenübersicht.
- Eigener KIHouseController. Ein externer HomeAgent ist kein Bestandteil der aktiven Architektur.

## Abwärtskompatibilität
Der Alexa-Intent mit historischer Kennung HomeAgentFrageIntent bleibt als Name erhalten,
weil ältere Alexa-Skill-Interaktionsmodelle ihn weiterhin aufrufen. Er verwendet
nur den eigenen KIHouseController. Frühere Kostendaten bleiben in den
Gesamtsummen und dem versteckten Altbereich erhalten; die Benutzeroberfläche
hat nur noch RoomsCatalog und Intent-Parser als aktive Kostenquellen.
