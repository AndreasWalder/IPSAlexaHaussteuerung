# IPSAlexaHaussteuerung  
### Alexa Haussteuerung für IP-Symcon

Ein modernes, flexibles **IP-Symcon-Modul** als zentrale Auswertelogik für deinen Alexa Custom Skill „Haussteuerung“.  
Es übernimmt das Routing, Mapping und die Verarbeitung deiner Alexa-Anfragen – **ohne eigenen WebHook**, vollständig integriert mit dem **Alexa Gateway (AlexaCustomSkillIntent)**.

---

## ✨ Features

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
- **V/S-Mapping**  
  Alle Variablen-IDs (V) und Script-IDs (S) werden automatisch ins Payload injiziert – keine festen IDs mehr nötig.
- **Diagnose-Dashboard in der Instanz-Form**
  - Live-Status: Scripts / Variablen  
  - Antwort-Vorschau (`dumpFile`)  
  - Letzte Fehler / Logs (`log_recent`) mit automatischem Ringpuffer (≈ 200 Zeilen)  
  - Buttons: *IDs neu ermitteln*, *Test-Launch*, *Custom-Payload senden*, *Vorschau leeren*, *Logs leeren*
- **Konfig-Formular mit sinnvollen Settings**
  - Sicherheits- & Verbindungs-Parameter  
  - Page-IDs (Energie / Kamera)  
  - Script-IDs (Action, Route_allRenderer, HeizungRenderer, …)  
  - Diagnose-Bereiche mit Payload-Editor

---

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
├─ module.php
├─ module.json
├─ form.json
├─ resources/
│  ├─ action_entry.php
│  └─ helpers/
│     ├─ CoreHelpers.php
│     ├─ DeviceMap.php
│     ├─ DeviceMapWizard.php
│     ├─ Lexikon.php
│     ├─ RoomBuilderHelpers.php
│     ├─ RoomsCatalog.php
│     ├─ Normalizer.php
│     └─ WebHookIcons.php
├─ src/
│  ├─ Helpers.php
│  ├─ LogTrait.php
│  ├─ Router.php
│  ├─ Routes/
│  │  └─ RouteAll.php
│  └─ Renderers/
│     ├─ RenderMain.php
│     ├─ RenderHeizung.php
│     ├─ RenderJalousie.php
│     ├─ RenderLicht.php
│     ├─ RenderLueftung.php
│     ├─ RenderGeraete.php
│     ├─ RenderBewaesserung.php
│     └─ RenderSettings.php
```

### 📂 Helper-Skripte

Im Ordner `resources/helpers/` findest du Vorlagen für alle externen Skripte,
die das Action-Script erwartet. Kopiere die Inhalte in eigene IP-Symcon
Skripte und hinterlege deren IDs in deiner Konfiguration (`var.CoreHelpers`,
`var.DeviceMap`, `var.DeviceMapWizard`, `var.Lexikon`, `script.NORMALIZER`,
`var.RoomBuilderHelpers`, `var.RoomsCatalog`, usw.). Die enthaltenen Dateien
decken folgende Aufgaben ab:

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
- `WebHookIcons.php` – WebHook-Endpunkt, der Dateien aus `user/icons/`
  sicher ausliefert (Token aus der Modul-Instanz übernehmen und als
  `$SECRET` setzen, Hook z. B. `/hook/alexa-icons`).

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
