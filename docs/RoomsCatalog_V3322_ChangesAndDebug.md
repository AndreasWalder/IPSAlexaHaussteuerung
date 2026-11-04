# RoomsCatalogConfigurator V3.3.22 – einfache Pruefung und Aenderungsuebersicht

## Hauptansicht

- Bisherige Raum-/Etagenverwaltung, Skriptauswahl, Geraeteeditor und ein einziger **EDIT NACH PROD UEBERNEHMEN** bleiben erhalten.
- **Geraete bearbeiten** bekommt rechts neben dem bisherigen Freigabestatus die Spalten **Pruefstatus** und **Was ist zu pruefen?**. Ungueltige Variablen und Schreibaktionen sind rot markiert, fehlende Offen/Geschlossen-Semantik einer Jalousie erscheint orange als Hinweis.
- **Aenderungen in EDIT vor PROD** zeigt alle neuen, geaenderten und aus EDIT entfernten Geraete. Die Liste ermittelt die Unterschiede aus **aktuellen PROD-/EDIT-Quellscripten**, nicht aus alten technischen Zwischenspeichern. Geaenderte Feldpfade werden moeglichst genau angegeben, z. B. `ai.variables.position` statt nur `ai`. Neue und geaenderte Geraete koennen direkt mit dem Zahnrad im gewohnten geraetetypischen Editor nachkonfiguriert werden. Bei aus EDIT entfernten Geraeten ist das Zahnrad deaktiviert (es gibt dort keinen EDIT-Eintrag mehr). Die Raum-/Etagen-Aenderungen sind separat sichtbar und im oberen Raumeditor bearbeitbar.
- **RoomsCatalog ueberpruefen – Diagnose fuer ChatGPT** hat genau einen Button **ROOMSCATALOG UEBERPRUEFEN**. Er liest EDIT/PROD und technische IPS-Variablenmetadaten nur lesend und gibt einen **formatierten JSON-Text in einer TextArea** aus. Benutzer kann den Inhalt markieren, kopieren und in ChatGPT einfuegen.

## Inhalt der Diagnose

Das JSON `RoomsCatalogCheck/1` enthaelt:
- Zeit, aktuelle EDIT/PROD-Script-IDs und SHA-256-Hashes (nicht den PHP-Quelltext)
- Zaehler fuer UI-Fehler/Hinweise, technische Plausibilitaet, noch nicht bestaetigte Geraete
- Vollstaendige Liste geaenderter/neuer/entfernter Geraete und geaenderter Feldpfade plus Raumaenderungen
- Liste aller offenen/auffaelligen Geraete mit Schluessel, Typ, Raum, konkreten Gruenden und Issue-Codes
- Nur fuer bereits im Geraet hinterlegte AI-Rollen die zugrunde liegenden IPS-Variablenmetadaten: ID, Existenz, Name, Ident, Typ, Profil und Vorhandensein einer Aktion
- Keine aktuellen Variablenwerte, Passwoerter, Sicherheitsinformationen oder Schaltbefehle. Kein automatisches Schreiben in EDIT/PROD.

## Absichtliche Freigabelogik

Der Diagnosebutton ist **informativ**. Er kann die Uebernahme nicht sperren. Fehlende Statuswerte werden als Punkte zum manuellen Pruefen ausgewiesen. Der einzige Publish-Button kopiert weiterhin **gesamten EDIT-Quelltext 1:1 nach PROD** und erstellt vorher sein verifiziertes PROD-Backup. Auch unvollstaendige Konfigurationen werden damit bewusst uebernommen.

## Aktualisierung und Reset

- Bearbeiten eines Geraetes, Aendern der Filter oder ein erfolgreicher PROD-Abgleich aktualisiert die Aenderungstabelle direkt.
- Der obere Reset-Button entfernt zusaetzlich die gespeicherte Debug-Ausgabe, laesst RoomsCatalog EDIT/PROD weiterhin unangetastet.
- Pruefstatus, Pruefhinweise und Aenderungsdarstellung sind reine UI-Metadaten und werden nicht als Geraeteeigenschaften zurueckgeschrieben.

## Testabdeckung

In `tests/test_rooms_catalog_v339_technical.php`:
- Eine einzige PROD-Gesamtkopie trotz unvollstaendiger Jalousien
- Gear-Editor mit typabhaengigem Dialog fuer geaenderte/neue Geraete
- Echte Feldpfade aus aktuellem EDIT/PROD; rote Warnungszeile mit Grund
- Kopierbare, schreibfreie JSON-Diagnose ohne Live-Variableninhalte
- Keine Geraetedaten-Mutation durch neue UI-Felder
- Nach erfolgreicher 1:1-Produebernahme aktualisiert sich die ausstehende Aenderungsliste auf null
