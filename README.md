# IPSAlexaHaussteuerung  
### Alexa Haussteuerung für IP-Symcon

Ein modernes, flexibles **IP-Symcon-Modul** als zentrale Auswertelogik für deinen Alexa Custom Skill „Haussteuerung“.  
Es übernimmt das Routing, Mapping und die Verarbeitung deiner Alexa-Anfragen – **ohne eigenen WebHook**, vollständig integriert mit dem **Alexa Gateway (AlexaCustomSkillIntent)**.

---

## ✨ Features

- **Letzte Alexa-Aktion (V3.8.4)**
  Unter **Diagnose** stehen der Name des anfragenden Echo in `alexa`,
  die erkannten Slots und die letzte Verarbeitung mit Antwort, Intent,
  Regel, Ergebnis und Uhrzeit. Die JSON-Variable fasst dieselbe Aktion zusammen.
  Start, Sitzungsende und die Textvorschau überschreiben sie nicht.
  In einer Sprachregel vom Typ **Antwort** wird `{alexa}` bei einer
  echten Anfrage durch den in IP-Symcon vergebenen Gerätenamen ersetzt.
  Beispiel für „Wer bist du?“ ist `Ich bin die Alexa {alexa}.`
  Die bisherige Standardantwort der importierten Regel wird automatisch
  dynamisch. Eigene Antworttexte bleiben unverändert, außer wenn sie
  den Platzhalter verwenden. Ein unbekanntes Echo bekommt keinen
  Namen eines anderen Geräts. Die Textvorschau zeigt stattdessen einen Platzhalter.
  Seit V3.8.5 werden Hausfragen nur aus der aktuellen Anfrage ausgewertet.
  Die Echo-Identitätsregel wird nur über einen passenden Alias oder
  ihren ausdrücklich konfigurierten eigenen Intent ausgelöst.
  Seit V3.8.6 beantwortet der Skill einfache Fragen zur aktuellen Temperatur
  in einem eindeutigen Raum aus den freigegebenen Istwerten, ohne KI-Aufruf.
  Wenn mehrere Messwerte vorliegen, wird ihr Mittelwert genannt.
  Skriptausgaben können die JSON-Antwort nicht mehr verfälschen.
  Technische Fehler stehen im Log. Alexa verwendet kurze, neutrale Antworten.
  Bei lesenden Hausfragen gibt es seit V3.8.7 bei einer leeren oder
  vorübergehend fehlgeschlagenen Modellantwort einen erneuten Versuch
  innerhalb des Alexa-Zeitbudgets. Ohne verwertbare Antwort sagt der
  Skill „Bitte anders formulieren.“ Schaltbefehle werden nicht wiederholt.
  Seit V3.8.8 laufen lesende Alexa-Hausfragen direkt im Modul. Bei
  **Hausfragen bevorzugt mit KI beantworten** formuliert das Modell
  einfache Antworten aus bereits gelesenen Istwerten und plant komplexe
  Abfragen. Liefert das Modell keinen verwertbaren Text, bleiben
  verfügbare lokale Antworten erhalten.
  V3.8.9 erkennt auch „In welchem Raum …?“ als Hausfrage.
  Für „Wo ist es am hellsten?“ vergleicht der Skill die gemessenen Lux-Werte
  pro Innenraum, nicht die Dimmerstellung. Die KI kann das Ergebnis formulieren.
  „Wo wurde zuletzt das Licht eingeschaltet?“ vergleicht aufgezeichnete
  Ein-Rückmeldungen über die freigegebenen Lichter im gesamten Haus.
  Voraussetzung sind ein eingerichtetes Archive Control und archivierte Schaltzustände.
  V3.8.10 erkennt bei dieser Frage auch die Alexa-Transkription „wie wird
  wurde zu Schluss das Licht eingeschaltet“. Fragt der Skill bei einer
  Archivabfrage nach dem Raum, reicht in derselben Sitzung der Raumname.
  V3.8.11 erkennt bei der Einschaltfrage auch „im Haus“ und nutzt dieselbe
  Archivsuche für eine eingegrenzte Raumfrage. „Wo wurde zuletzt Bewegung
  erkannt?“ vergleicht archivierte Aktivmeldungen ohne die alte Grenze von
  30 Geräten. „Innen“, „außer außen“ und „draußen“ grenzen den Bereich ein.
  Die KI darf das geprüfte Ergebnis formulieren, aber nicht die Auswahl
  der Sensoren oder den Zeitpunkt ändern.

- **Bewässerung als Anlage aus dem Objektbaum (V3.8.16)**
  Im RoomsCatalog einen Hauptordner auswählen. Der Scan löst Dummy-Module,
  Links und statische Skriptbezüge auf. Nach dem EDIT-Import stehen Pumpe,
  Kreise, Bodenfeuchte, Winter und Zeitpläne in einer gruppierten Anlagenansicht.
  Alexa kann den saisonalen Status abfragen, archivierte Einschaltungen der
  Rohrbegleitheizung zählen und das letzte Pumpen-Einschaltdatum nennen.
  Seit V3.8.17 lädt der Zahnrad-Dialog im Symcon-Listenformat und der
  Anlagenbereich zeigt die Gruppen auch ohne Dialog. Der Button **JSON für
  Analyse exportieren** lädt eine Diagnose für den ausgewählten Hauptordner
  herunter. Verlinkte Sensoren werden über die tatsächlichen Symcon-Linktypen
  und Eltern-IDs erkannt.
  [Einrichtung und Beispiele](docs/Bewaesserung_Objektbaum_V3816.md)

- **Kompaktes Alexa-Protokoll und Ortsrückfrage (V3.8.15)**
  Die bestehende Variable **Alexa-Meldungen (letzte 500)** enthält jetzt
  kompakte `IAH.Request`-Zeilen mit Intent, erkanntem Wortlaut (bei aktiver
  Transkriptoption), Regel und PROD-Stand, Antwort, Ergebnis und Dauer.
  Mehrfach ausgegebene Slot-Strukturen und große Renderer-Daten werden
  im kopierbaren Export ausgelassen. Die Variable behält ihre Objekt-ID.
  Bei einem unklaren Ortswechsel wie „Und inge?“ fragt der Skill lokal
  „Welchen Raum oder welche Etage meinst du?“. Die ursprüngliche Frage
  bleibt für die Antwort in derselben Alexa-Sitzung erhalten.

- **Alexa-Intents synchronisieren (V3.8.14)**
  In der IPSAlexaHaussteuerung-Instanz den Abschnitt **Alexa-Intents synchronisieren**
  öffnen, die vorhandene GetHaus-Instanz wählen und das vollständige
  Alexa-Interaktionsmodell aus dem JSON Editor einfügen. **Vorschau prüfen**
  zeigt bestehende Zuordnungen, fehlende Intents und Konflikte am selben
  Patami-WebHook. **Alexa-Intents synchronisieren** legt die fehlenden
  Instanzen mit dem lokalen Action-Skript an. Bestehende Skript-Intents
  werden nur mit der ausdrücklichen Option umgestellt. GetHaus, fremde
  WebHooks und vorhandene Nicht-Skript-Aktionen bleiben unverändert.
  Es werden keine bestehenden Instanzen gelöscht und das Alexa-Modell
  wird nicht veröffentlicht oder geändert.

- **Alexa-Frageanfänge und eigene Befehle (V3.8.13)**
  Separate Frage-Intents erhalten den gesprochenen Anfang, auch bei „Wo …?“,
  „Seit wann …?“ oder „In welchem Raum …?“. `GetHaus` bleibt unverändert.
  „Befehl …“ führt zum vorhandenen Sprachregel-Editor. Eine Trefferregel
  vom Typ **Antwort** liest nur ihren Text vor. Ein unbekannter Befehl wird
  nicht als Schaltaktion geraten. Alle Intents verwenden das gleiche
  Action-Skript. [Intent-Paket für den Alexa JSON-Editor](docs/Alexa_Hausfragen_Intent_Paket.json).

- **Beständige Laufzeitdiagnose (V3.8.12)**
  Modul-Updates und „Alles neu aktualisieren“ erhalten vorhandene Werte und
  Objekt-IDs, auch deaktivierte Schaltfreigaben. `log_recent` enthält die
  letzten 200 Modulmeldungen. `dumpFile` enthält die letzte Renderer-Vorschau
  über die Diagnosefunktionen. `domain_flag` merkt sich die aktuelle
  Navigation, `activeTabState` den zuletzt gewählten Tab je Renderer-Route.
  `skillActive` ist ein globaler Navigationsmerker, kein Online-Status eines Echo.
  `Diagnose → alexa` zeigt das anfragende, registrierte Echo auch bei normalen
  Skill-Fragen und Bildschirmaktionen. Es zeigt nicht das im Satz genannte Zielgerät.
  Die Aktionsdiagnose enthält jetzt Verarbeitungsdauer und bei beantworteten
  Hausfragen die Antwortquelle. **Letzte Variablenaktion (JSON)** bleibt beim
  Lesen oder Testen stehen und zeigt bei einer angenommenen Schreibaktion
  Zeitpunkt, Quelle, Ziel-ID, Variablenname, übergebenen Wert und Ergebnis.
  Die vier bestehenden `lastVariable*` bleiben erhalten und zeigen dieselbe
  letzte Variablenaktion. Eine angenommene Schaltaktion bestätigt noch keinen
  physischen Gerätezustand. Interne Schreibvorgänge selbst gestarteter
  benutzerdefinierter Skripte werden nicht mitverfolgt.

- **Sprachbefehl testen (V3.8.3)**
  Im RoomsCatalogConfigurator oder unter „Szenen und eigene Alexa-Befehle“
  der Alexa-Instanz einen Satz eingeben. Der Test erkennt eigene Regeln,
  Hausfragen und Navigation automatisch. Hausfragen nutzen produktive
  Istwerte, Schaltaktionen werden nur angezeigt. Ortsbezogene Folgefragen
  wie „Und im Obergeschoss?“ funktionieren im Test und in der Alexa-Sitzung.
  Etagen wie EG und Erdgeschoss werden bei Erst- und Folgefragen gleich
  aufgelöst. Fensterfragen unterscheiden offen, geschlossen und unbekannt.
  [Anleitung und Grenzen der Vorschau](docs/Alexa_V38_Sprachbefehl_Testvorschau.md)

- **Einstieg über „Action“**  
  Automatisch erzeugtes Skript **„Action (Haus\Übersicht/Einstellungen Entry)“** mit  
  `Execute($request = null)` – kann direkt im AlexaCustomSkillIntent-Modul als *„Dieses Skript ausführen“* gewählt werden.
- **Kein interner WebHook**  
  Das Alexa-Gateway (z. B. `/alexa/haus`) bleibt der Entry-Point – das Modul übernimmt nur die interne Auswertung.
- **Router + Renderer-Wrapper**
  Leitet Payloads an deine vorhandenen Skripte (Heizung, Licht, Jalousie, Lüftung, Geräte, Bewässerung, Einstellungen, Route_all) weiter.
- **Automatische Variablenanlage & Standardwerte**
  Erstellt Kategorien *Einstellungen* und *Alexa new devices helper* sowie Runtime-Variablen (`action`, `device`, `room`, `skillActive`, …)
  – inkl. sinnvoller Startwerte (Toggles = true, `skillActive = false`).
- **Auto-Deployment aller Skripte**
  Action-, Route- und Renderer-Skripte inklusive `SystemConfiguration` werden beim `ApplyChanges()` erzeugt oder aktualisiert.
  Die erzeugte `SystemConfiguration` enthält alle relevanten IDs und wird automatisch mit dem Action-Skript verknüpft.
  - **RoomsCatalog-Template inklusive**
    Beim ersten `ApplyChanges()` erzeugt das Modul automatisch ein Skript **„RoomsCatalog“** in der Kategorie *Einstellungen* und befüllt es mit dem Beispiel aus `resources/helpers/RoomsCatalog.php`. Dieses Skript kannst du direkt bearbeiten und dessen ID z. B. im `SystemConfiguration`-Skript verwenden.
  - **RoomsCatalog Konfigurator**
    Eine eigene Configurator-Instanz („RoomsCatalogConfigurator“) erzeugt bei Bedarf ein bearbeitbares `RoomsCatalogEdit`-Skript, markiert Unterschiede farblich und kann die geprüften Änderungen auf Knopfdruck in den produktiven RoomsCatalog übernehmen.
- **Konfig-Skript frei wählbar**
  Hinterlege dein bestehendes `SystemConfiguration`-Skript direkt in der Instanz (*Config ScriptID*). Das Action-Entry-Skript lädt diese ID automatisch – keine hart codierte Script-ID `48789` mehr notwendig.
- **V/S-Mapping**  
  Alle Variablen-IDs (V) und Script-IDs (S) werden automatisch ins Payload injiziert – keine festen IDs mehr nötig.
- **Diagnose-Dashboard in der Instanz-Form**
  - Live-Status: Scripts / Variablen
  - Antwort-Vorschau (`dumpFile`)
  - Letzte Fehler / Logs (`log_recent`) mit automatischem Ringpuffer (≈ 200 Zeilen)
  - Direktanzeige des **Codex-Protokolls** (Inhalt der Variable `log_recent`) als mehrzeiliges Textfeld
  - Buttons: *IDs neu ermitteln*, *Test-Launch*, *Custom-Payload senden*, *Vorschau leeren*, *Logs leeren*
- **Konfig-Formular mit sinnvollen Settings**
  - Sicherheits- & Verbindungs-Parameter  
  - Page-IDs (Energie / Kamera)  
  - Script-IDs (Action, Route_allRenderer, HeizungRenderer, …)  
  - Diagnose-Bereiche mit Payload-Editor

---

## Alexa + ChatGPT (direkte RoomsCatalog-Haussteuerung)

**V3.4.6 – nur noch eine KI-Anbindung:** Spracheingaben aus Alexa werden
an den eigenen **KIHouseController** weitergegeben, der mit dem konfigurierten
OpenAI-Modell (ChatGPT über API) das freigegebene `RoomsCatalog` abfragt.
Für einfache Schaltbefehle und Navigation bleibt die bestehende lokale
IP-Symcon-Steuerung erhalten. Es wird **kein externer HomeAgent/MCP-Server**
mehr benötigt, auch kein MCP-Endpunkt oder zusätzliches Zugangstoken.

Die KI verwendet zentrale Gerätetypen und Eigenschaften, analysiert Fragen
über `query_house` (Listen, Status, Mittelwerte, Min/Max, Summen,
Raumvergleiche) und kann nur für explizit berechtigte Geräte schalten.
Fragen erhalten keine Schreibwerkzeuge. Der Alexa-Intent
`HomeAgentFrageIntent` mit `AMAZON.SearchQuery` heißt aus
**Abwärtskompatibilitätsgründen** in der Alexa Developer Console weiterhin so,
obwohl **kein HomeAgent mehr aufgerufen** wird.

**Einrichtung:** KI-API-Key, Modell und „KI-Hausfragen beantworten“ im
IP-Symcon-Alexa-Modul konfigurieren und **Übernehmen**.
Geräte-/Schaltfreigaben erfolgen ausschließlich im RoomsCatalogConfigurator.
Die alten MCP-Einstellungen und die Bridge wurden entfernt; bereits
eingerichtete Alexa-Intents müssen deshalb nicht umgebaut werden.

**Fehlerbehebungen in V3.4.6:** Licht-Iststatus wird auch bei typisierten
Boolean-/0/1-/Text-Rückmeldungen korrekt ausgewertet, bei Jalousien werden
Positionen ohne Zu/Offen-Statusmapping nicht als Zustandsbestätigung
ausgegeben. Wenn die KI eine gültige Abfrage abgeschlossen hat und die
nachfolgende Sprachformulierung wegen Zeitüberschreitung scheitert, liefert
Alexa eine kurze, sichere Antwort aus den tatsächlich gelesenen Werten.
Weitere Diagnose für ungültige/fehlende Skriptrückgaben.

[ChatGPT-Setup und Diagnose](docs/Alexa_ChatGPT_RoomsCatalog_Setup.md) ·
[KI + RoomsCatalog](docs/KI_RoomsCatalog.md) ·
[Universelle KI-Datenabfragen](docs/Alexa_AI_HouseQuery_V345.md) ·
[Alexa-Intent-Vorlage (historischer Name)](docs/HomeAgentFrageIntent.json)

## Aktueller Stand und KI-Kosten

Nach V3.6.1 ist die HuaweiPV-Anlage einschließlich Archivabfragen und
sicherer Messwert-Freigaben über den RoomsCatalog editierbar.
Die aktive KI-Abrechnung unterscheidet nur **ChatGPT + RoomsCatalog** und
**Intent-Parser**, jeweils mit EUR-Kosten, Tages-/Monatsübersicht,
Token-Statistik, Monatsprognose und Modellhistorie.
Historische Altkosten bleiben in der Gesamtsumme erhalten;
nicht mehr benutzte Kostengruppen werden nur ausgeblendet.

[Roadmap: ursprünglich geplant vs. heute offen](docs/RoomsCatalog_Roadmap_nach_V36.md) ·
[Aufgeräumte Bedienoberfläche ab V3.6.3](docs/RoomsCatalog_PostMigration_Cleanup_V363.md) ·
[KI-Kosten in EUR](docs/KI_Kosten_Transparenz.md)

## Alexa: Produktive Kachelvisualisierung „Visu“

Sprachbefehl **„Visu“**: „Alexa, öffne Haussteuerung“ → „Visu“. Die erfolgreiche IP-Symcon-Kachelvisualisierung unter `/tile/` verwendet weiterhin den erprobten HTTPS-URL-Bootstrap, AutoLogin mit Passwort und Enter, die leise Alexa-HTML-Sitzung sowie die gerätebezogene Sprachsteuerung ohne neuen Bildschirm. Ihre interne technische Route bleibt für bestehende WebHooks `kacheltest` – nur der Sprachbefehl heißt jetzt „Visu“.

**Im Modul werden nur drei produktive Einstellungen angezeigt:** Visualisierungs-Instanz-ID, Passwort und Debug ein/aus. Debug ist standardmäßig ausgeschaltet, damit Browserereignisse und zusätzliche DOM-Snapshots den normalen Betrieb nicht belasten. Bei Problemen kann man es einschalten und die Diagnosevariable kopieren. Die bestehenden Kamera-/Energie-Routen und das alte WebFront bleiben unverändert.

**„Test“ öffnet keine Visualisierung mehr**, sondern bleibt als eigenständiger Kanal für spätere Tests und Erweiterungen frei.

**[Details zu Einrichtung, Sprachsteuerung und Diagnose](docs/Alexa_Kachelvisualisierung_Testmodus.md)**

## 🛠️ Installation

Repository in deinen IP-Symcon-Module-Ordner klonen (z. B. `/var/lib/symcon/modules/`):

```bash
git clone https://github.com/AndreasWalder/IPSAlexaHaussteuerung
```

Symcon-Dienst neu starten.

### Instanz anlegen
Objektbaum → *Instanz hinzufügen* → **„IPSAlexaHaussteuerung“**  
Einstellungen prüfen, insbesondere Script-IDs und LOG_LEVEL.

---

## ⚙️ Integration mit Alexa Gateway

1. In der **AlexaCustomSkillIntent-Instanz** bei  
   **„Dieses Skript ausführen“** → **`Action (Haus\Übersicht/Einstellungen Entry)`** auswählen.  
2. Das Gateway ruft dann automatisch `Execute($request)` auf.  
3. Das Entry-Script übergibt die Anfrage an das Modul → `RunRouteAll()` → Router → Renderer → deine bestehenden Skripte.  
4. Das Ergebnis wird als JSON an Alexa zurückgegeben.

---

## 💡 Beispiel-Ablauf

1. **Alexa** sagt: „Heizung im Wohnzimmer auf 22 Grad.“  
2. Alexa Gateway → `Action (Entry)` → `Execute($request)`  
3. Modul → `RunRouteAll()`  
4. Router → erkennt Domain *Heizung*, ruft `HeizungRenderer` mit Payload + V/S auf  
5. Renderer → verarbeitet Anfrage, liefert APL / Card-Response  
6. Gateway → gibt JSON-Antwort an Alexa zurück

---

## 🧩 Diagnose-Funktionen

| Funktion | Beschreibung |
|-----------|--------------|
| **Diagnose: IDs neu ermitteln** | Rebind / Neuaufbau aller Variablen & Entry-Script |
| **Diagnose: Test-Launch** | Schickt Beispiel-Payload (`main_launch`) durch Router |
| **Diagnose: Custom-Payload senden** | Sendet den JSON aus *Diagnose → Custom Payload (JSON)* |
| **Diagnose: Vorschau leeren** | Setzt Variable `dumpFile` zurück |
| **Diagnose: Logs leeren** | Löscht den Inhalt der Variable `log_recent` |

---

## ⚙️ Interne Struktur

```text
IPSAlexaHaussteuerung/
├─ IPSAlexaHaussteuerung/
│  ├─ module.php
│  ├─ module.json
│  ├─ form.json
│  ├─ resources/
│  │  ├─ action_entry.php
│  │  ├─ helpers/
│  │  │  ├─ CoreHelpers.php
│  │  │  ├─ DeviceMap.php
│  │  │  ├─ DeviceMapWizard.php
│  │  │  ├─ Lexikon.php
│  │  │  ├─ Normalizer.php
│  │  │  ├─ RoomBuilderHelpers.php
│  │  │  ├─ RoomsCatalog.php
│  │  │  ├─ WfcDelayedPageSwitch.php
│  │  │  └─ WebHookIcons.php
│  │  └─ renderers/
│  │     ├─ LaunchRequest.php
│  │     ├─ RenderBewaesserung.php
│  │     ├─ RenderGeraete.php
│  │     ├─ RenderHeizung.php
│  │     ├─ RenderJalousie.php
│  │     ├─ RenderLicht.php
│  │     ├─ RenderLueftung.php
│  │     ├─ RenderSettings.php
│  │     └─ Route_allRenderer.php
│  └─ src/
│     ├─ Helpers.php
│     ├─ LogTrait.php
│     ├─ Router.php
│     ├─ Routes/
│     │  └─ RouteAll.php
│     └─ Renderers/
│        ├─ RenderMain.php
│        ├─ RenderHeizung.php
│        ├─ RenderJalousie.php
│        ├─ RenderLicht.php
│        ├─ RenderLueftung.php
│        ├─ RenderGeraete.php
│        ├─ RenderBewaesserung.php
│        └─ RenderSettings.php
└─ RoomsCatalogConfigurator/
   ├─ module.php
   └─ module.json
```

## 🧱 RoomsCatalog Konfigurator

Der zusätzliche Modul-Ordner `RoomsCatalogConfigurator/` stellt eine eigenständige **Configurator-Instanz** bereit. Typischer Ablauf:

1. Instanz hinzufügen → „RoomsCatalog Konfigurator“ auswählen.
2. Im Formular das produktive RoomsCatalog-Skript auswählen.
3. Optional: per Button „RoomsCatalogEdit erstellen/aktualisieren“ eine bearbeitbare Kopie erzeugen.
4. Änderungen nimmst du direkt im `RoomsCatalogEdit`-Skript vor.
5. Die Liste „Räume, Domains & Status“ markiert neue (grün), fehlende (gelb) und geänderte (rot) Einträge und nutzt jetzt die komplette Formularbreite.
6. Die neue strukturierte Ansicht (Expansion Panel) erlaubt dir, Räume auf- und zuzuklappen und Domains kompakt je Raum zu prüfen – ähnlich wie ein klassischer Baum.
7. Über den Popup-Button „Eintrag aus RoomsCatalog hinzufügen“ kannst du Räume, Domains oder einzelne Bereiche direkt aus dem produktiven RoomsCatalog auswählen, optional duplizieren und per Klick in dein `RoomsCatalogEdit` übernehmen.
8. Sobald alles passt → Button „RoomsCatalog mit Edit überschreiben“ drückt die geprüften Änderungen zurück ins aktive RoomsCatalog.

So hast du jederzeit einen visuellen Überblick über Unterschiede und kannst neue Räume, Domains oder Geräte gefahrlos vorbereiten.

### 📂 Helper-Skripte

Im Ordner `resources/helpers/` findest du Vorlagen für alle externen Skripte,
die das Action-Script erwartet. Kopiere die Inhalte in eigene IP-Symcon
Skripte und hinterlege deren IDs in deiner Konfiguration (`var.CoreHelpers`,
`var.DeviceMap`, `var.DeviceMapWizard`, `var.Lexikon`, `script.NORMALIZER`,
`var.RoomBuilderHelpers`, `var.RoomsCatalog`, usw.). Die enthaltenen Dateien
decken folgende Aufgaben ab:

> 🆕 **RoomsCatalog-Automatismus:** Das Modul legt beim ersten `ApplyChanges()` bereits ein Skript **„RoomsCatalog“** unterhalb der Kategorie *Einstellungen* an und befüllt es mit dem Standard-Template. Du kannst den Inhalt dort direkt anpassen – die Script-ID lässt sich anschließend im `SystemConfiguration`-Skript verwenden.

- > 💡 **Hinweis:** Standardmäßig erzeugt das Modul selbst ein `SystemConfiguration`-Skript, pflegt dort alle IDs und hinterlegt dieses automatisch im Action-Entry. Wenn du eine eigene Variante nutzen willst, kannst du sie im Feld **Config ScriptID** auswählen.

- `CoreHelpers.php` – generische Utilities wie Slot-Handling, APL-Parsing,
  Tabs-Matching oder Nummern-Extraktion.
- `DeviceMap.php` – Persistenzhelfer für die Geräte-Map (Wizard Speicher).
- `DeviceMapWizard.php` – kompletter Dialog-Flow für den Geräte-Wizard.
- `Lexikon.php` – Wörterbuch & Regex-Patterns für Begriffe/Zahlen.
- `Normalizer.php` – Normalisierungsfunktionen für Tokens, Räume & Actions.
- `RoomBuilderHelpers.php` – baut aus dem RoomsCatalog einen aggregierten
  Status je Raum (z. B. Heizkreise) für Renderer/Widgets.
- `RoomsCatalog.php` – kompletter Raum-/Domain-Katalog mit allen IDs,
  Synonymen und Tabs. Diesen Inhalt kannst du direkt in ein IP-Symcon-Skript
  kopieren und dort bearbeiten, um Räume komfortabel zu pflegen.
- `WfcDelayedPageSwitch.php` – nimmt per `IPS_RunScriptEx` eine Zielseite und
  WebFront-ID entgegen, speichert sie gepuffert und schaltet nach 10 Sekunden
  automatisch über `WFC_SwitchPage` um (praktisch für "nach Erfolg X anzeigen").
- `WebHookIcons.php` – WebHook-Endpunkt, der Dateien aus `user/icons/`
  sicher ausliefert (Token aus der Modul-Instanz übernehmen und als
  `$SECRET` setzen, Hook z. B. `/hook/alexa-icons`).

### 🖥️ Renderer-Skripte

Unter `resources/renderers/` findest du komplette APL-Renderer, die in
deinem IP-Symcon System laufen und von den PHP-Modul-Routen via
`IPS_RunScriptEx`/`IPS_RunScriptWaitEx` aufgerufen werden können. Kopiere
die Dateien nach Symcon, verknüpfe sie mit deinen Render-Skripten und trage
die jeweiligen Script-IDs in der Modulkonfiguration ein.

- `RenderBewaesserung.php` – vollständiger Bewässerungs-Renderer mit Tabs,
  Aktionen (Toggle/Set), DS-Logging, Voice-Matching und Enum-Aufbereitung.
- `RenderGeraete.php` – universeller Geräte-Renderer für beliebige Räume,
  inklusive Dummy-Rubriken, Sortierung, Profil/Enum-Auflösung und APL-DS Dump.
- `RenderHeizung.php` – temperaturfokussierter Renderer, der ohne Fallbacks nur
  explizit adressierte Heizkreise erlaubt und bei fehlenden Zielen klare
  Sprachantworten liefert.
- `RenderJalousie.php` – Renderer für Jalousien und Szenen inklusive
  Prozent-/Aktionslogik, Icon-Auflösung über den WebHook und Payload-Limiter
  für große APL-Datasources.
- `RenderLicht.php` – Schalt- und Dimmer-Renderer mit ActionsEnabled-Guards,
  zielgerichteten Visual-Updates, Szenenunterstützung und synchronisiertem
  Switch/Dimmer-State pro Raum.
- `RenderLueftung.php` – Lüftungsrenderer ohne Fallbacks, inklusive zentraler
  und raumbezogener Geräte, Buttons aus dem RoomsCatalog sowie klaren
  Fehlermeldungen bei nicht erreichbaren Variablen.
- `RenderSettings.php` – Einstellungen/Actions-Renderer zum Umschalten der
  `ActionsEnabled`-Flags samt Farbschema, Logik für APL-Buttons und Alexa-Infos.
- `LaunchRequest.php` – Start-/Tiles-Renderer für den LaunchIntent mit Icon-
  Proxy, Payload-Limiter und Diagnose-Logging, damit der Einstieg in deine
  Visualisierung stabil bleibt.
- `Route_allRenderer.php` – zentrales Routing-Skript, das die Payloads an die
  jeweiligen Render-Skripte dispatcht, Flags setzt, External-Links öffnet und
  alle Responses konsolidiert an Alexa zurückgibt.

### ⏱️ Verzögertes WebFront-Umschalten

1. Erstelle in IP-Symcon ein Skript und kopiere den Inhalt von
   `resources/helpers/WfcDelayedPageSwitch.php` hinein.
2. Starte das Skript bei Bedarf mit `IPS_RunScriptEx($id, ['wfc' => <WFC-ID>, 'page' => 'page.XYZ']);`
   zum Beispiel nach einem erfolgreichen Alexa-Kommando.
3. Das Skript puffert die Parameter zehn Sekunden lang und ruft danach
   automatisch `WFC_SwitchPage`. So kann der Client z. B. nach einer Szene
   automatisch zur Visualisierung springen.

### 🌐 WebHook für Icon-Auslieferung

1. Erstelle in IP-Symcon ein Skript und kopiere den Inhalt von
   `resources/helpers/WebHookIcons.php` hinein.
2. Trage im Skript bei `$SECRET` genau den Token ein, der im Modul unter
   *Token* angezeigt wird (siehe Instanzkonfiguration).
3. Registriere das Skript als WebHook (z. B. `/hook/alexa-icons`).
4. Lege deine PNG/SVG/ICO-Dateien in `user/icons/` ab und rufe sie über
   `https://<symcon-host>/hook/alexa-icons/<datei>?token=<TOKEN>` auf.

Die Auslieferung erfolgt mit passenden MIME-Typen, ETag/Last-Modified-Headern
und optionalem Caching (1 Jahr für Bilder/CSS/JS, no-store für HTML). Damit
lassen sich die Alexa-APLs oder externe Displays mit den gleichen Icons
versorgen, die auch innerhalb von IP-Symcon verwendet werden.

---

## 🧑‍💻 Autor & Lizenz
Erstellt von **Andreas Walder**  
Lizenz: **MIT**

---

## 🛠️ Weiterentwicklung
Pull Requests / Issues / Feature-Vorschläge sind willkommen!  
Bitte Ideen und Bugs direkt als GitHub Issue oder PR einreichen.

---

**Letztes Update:** 2025-11-12 –  
Initiale Version ohne internen WebHook, mit Action-Entry, automatischer Verknüpfung, V/S-Mapping und Diagnose-Dashboard.
