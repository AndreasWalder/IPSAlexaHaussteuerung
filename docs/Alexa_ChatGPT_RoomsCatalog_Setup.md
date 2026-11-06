# Alexa + ChatGPT + RoomsCatalog (V3.4.6)

Die produktive Architektur benötigt nur noch **Alexa Custom Skill → IP-Symcon → OpenAI API**. Als einzige Gerätedatenbasis dient der `RoomsCatalog` mit den KI-Freigaben aus dem `RoomsCatalogConfigurator`. Der externe HomeAgent-MCP-Server, seine Bridge, der Endpunkt und das Token wurden aus dem Alexa-Modul entfernt.

## Was bleibt lokal, was macht ChatGPT?

| Anfrage | Weg |
| --- | --- |
| „Licht“, „Jalousie“, „Visu“, „Zurück“ | Bisherige direkte Alexa-Navigation und Kacheln |
| Einfacher, erkannter Schaltbefehl | Bisherige lokale IP-Symcon-Aktion ohne KI-Aufruf |
| „Welche Lichter sind eingeschaltet?“ | ChatGPT plant `query_house(property=activity,condition=active,domain=licht)` |
| „Welcher Raum ist der wärmste?“ | ChatGPT plant `query_house(property=temperature,operation=max,group_by=room,indoor_only=true)` |
| „Welche Jalousien sind geschlossen?“ | ChatGPT plant `query_house(property=closure,type=blind,condition=eq,value=geschlossen)`; nur bestätigte Status-/Endlagenzuordnungen gelten |
| Mehrdeutige oder zusammengesetzte Hausfrage | ChatGPT kann weitere lesende Abfragen auslösen; es benötigt entsprechende freigegebene IPS-Rückmeldungen |
| Ausdrücklich angeforderter KI-Schaltbefehl | Nur mit globaler KI-Schalterfreigabe **und** individueller geprüfter Gerätefreigabe |

Der Name `HomeAgentFrageIntent` in der **Alexa Developer Console** ist historisch. Er wird aus Kompatibilität weiterhin als sprachlicher Eingang akzeptiert, **ruft aber keinen HomeAgent auf**. Die neuen `HausWelcheIntent`, `HausWieIntent`, `HausWasIntent`, `HausIstIntent` und `HausSindIntent` bleiben ebenfalls erhalten. Die Alexa-Intent-Definitionen nicht löschen, sonst erreichen Sprachfragen den PHP-Endpunkt nicht mehr.

## Einmalige Einrichtung in IP-Symcon

1. Modulbibliotheken aktualisieren: `IPSAlexaHaussteuerung` und `RoomsCatalogConfigurator`.
2. In der Alexa-Modulinstanz den bereits vorhandenen OpenAI-API-Key und das Modell auswählen. `KI-Hausfragen beantworten` aktivieren.
3. `Übernehmen` drücken. Dadurch werden `KIHouseController` inklusive der zentralen DeviceTypeRegistry und `HouseQueryEngine` eingebettet sowie die bestehende Alexa-Action-Skript-Route aktualisiert.
4. Für benötigte Geräte im RoomsCatalogConfigurator **lesbare Istvariablen** zuordnen und `Mit KI` (nach Wunsch „Nur abfragen“) setzen. Nach fachlicher Prüfung EDIT nach PROD übernehmen.
5. Einfache klassische Alexa-Aktionen bleiben unabhängig vom KI-Hausfragen-Schalter möglich.

Keine HomeAgent-Installation, kein MCP-Endpunkt, kein MCP-Token und kein Wechsel zwischen KI-Backends mehr nötig. Die früheren internen Konfigurationsidentifikatoren `HomeAgentVoiceEnabled`, `HomeAgentVoiceRouteCommands`, `HomeAgentVoiceMaxWords` und `HomeAgentVoiceTimeoutSeconds` bleiben vorerst **nur aus Upgrade-Kompatibilitätsgründen** erhalten, damit bestehende Einstellungen nach Updates nicht verloren gehen. Sie betreffen jetzt ausschließlich die direkte ChatGPT-Anbindung; sie richten **keine MCP-Verbindung** ein.

Das bei älteren Installationen möglicherweise noch vorhandene, unbenutzte IP-Symcon-Skript `HomeAgentVoiceBridge` wird **nicht mehr erzeugt oder aufgerufen**. Falls es im Objektbaum erhalten bleibt, kann es nach Backup und Kontrolle seiner Referenzen manuell entfernt werden. Eine automatische Objektlöschung beim Modul-Update erfolgt aus Sicherheitsgründen nicht.

## Testfragen und Diagnosen

Im IP-Symcon-Meldungsfenster nach `KIVoice.` und `KIRoomsCatalog.` filtern.

- **„Welche Lampen sind eingeschaltet?“**: `KIRoomsCatalog.tool` sollte `name=query_house`, `property=activity`, `condition=active` enthalten. Boolean-, 0/1- und Text-Iststatus sowie Dimmerhelligkeit werden unterstützt. Kommando-/Steuerwerte gelten nicht als Zustandsbestätigung.
- **„Welche Jalousien sind offen?“**: `property=closure`; ohne ausdrücklich zugeordneten Offen-/Geschlossenstatus wird nichts geraten. Gegebenenfalls `ai.stateMapping` überprüfen.
- **„Welcher Raum ist der wärmste?“**: `property=temperature`, `operation=max`, `groupBy=room`. Nur tatsächliche Isttemperaturen, keine Sollwerte. Bei mehreren Sensorvariablen im Raum wird deren Mittelwert verglichen, identische IDs werden nicht doppelt gezählt.
- **Timeout/Fehler**: `KIRoomsCatalog.answer.fallback` bedeutet, dass bereits gültige Werte gelesen wurden, aber die zusätzliche ChatGPT-Sprachformulierung das Zeitbudget nicht mehr erreicht hätte. Eine kurze faktische Antwort wird direkt erzeugt. `KIVoice.bridge.decode` zeigt nur Länge, Antworttyp und JSON-Fehler eines unlesbaren Skriptergebnisses; keine sensiblen Inhalte.

Auf eine gültige KI-Antwort folgt weiter der bisherige Alexa-Dialog mit Rückfrage-/Offenhalteverhalten; Navigation und Kachelvisualisierung bleiben unverändert.

**Sicherheit:** Keine echten Adressen, Tokens, Rohtexte von Alexa oder personenbezogene Geräteinventare in öffentliche GitHub-Issues einfügen. Die Tests verifizieren die PHP-Abfragelogik, aber nicht die konkrete elektrische Rückmeldung im realen Haus.
