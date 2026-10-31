# Alexa: Neue IP-Symcon-Kachelvisualisierung separat testen

Der Testmodus öffnet die **neue Kachelvisualisierung** unter `/tile/` direkt über die bewährte, instrumentierte Alexa-Web-API-for-Games-Shell. **Kamera, Energie und das alte WebFront bleiben unverändert.**

## Aufruf mit Sprache

1. „**Alexa, öffne Haussteuerung.**“
2. „**Kacheltest.**“

Alternative: „**Kachelvisualisierung.**“ Der bereits bestehende `GetHaus`-Intent verwendet dafür einen einzelnen `Action`- oder `Device`-Slot; der VoiceRouter erkennt die Wörter auch ohne eigene neue Alexa-Intents. Ob Alexa auf einem konkreten Gerät einen Begriff als freien Slotwert versteht, hängt vom jeweiligen Alexa-Sprachmodell ab. Der Test wird **niemals** als Schaltbefehl interpretiert.

## Einstellungen

IP-Symcon → Modulinstanz **IPSAlexaHaussteuerung** → **Alexa – neue Kachelvisualisierung testen (separater Testmodus)**:

- **Alexa Kacheltest erlauben**: an (Standard). Nur der separate Sprachbefehl verwendet diesen Modus.
- **Kachelvisualisierungs-Instanz-ID**: `0` (Standard) für die Visualisierungsauswahl von `/tile/`. Optional hier die konkrete Instanz-ID deiner Kachelvisualisierung eintragen, damit Alexa `/tile/#<ID>` direkt lädt.

Nach dem GitHub-Modulupdate einmal auf **Übernehmen** klicken, damit das vorhandene Action-Skript die neuen Sprach-Aliase übernimmt. Die bereits installierten Diagnose-WebHooks `/hook/iah-alexa-shell` und `/hook/iah-alexa-html-debug` werden weiterverwendet.

Offizielle Symcon-Dokumentation: Die Kachelvisualisierung ist ab Symcon 7.0 im Browser unter `/tile/` verfügbar, wenn das WebFront als Standardvisualisierung läuft: https://www.symcon.de/de/service/dokumentation/komponenten/kachel-visualisierung/browser/

## Korrektur: Alexa meldet „Kachelvisualisierung-Testmodus ist deaktiviert“

Bei der ersten Version fehlten `TileTestEnabled` und `TileTestVisualizationId` im
`V`-Payload des bereits vorhandenen Action-Skripts. Der Renderer wertete ein
fehlendes Feld fälschlich wie `false` aus – selbst wenn die Modul-Checkbox
aktiviert war. **Nach diesem Fix liest der Renderer die Werte unmittelbar aus
der tatsächlichen IP-Symcon-Modulinstanz**; deshalb funktionieren auch alte
Action- und eigene SystemConfiguration-Skripte weiter.

Das Ereignis `TileTest.settings` im **Alexa-Protokoll (letzte 500)** zeigt
anonymisiert `source=instance`, `enabled=true/false` und
`visualizationSelected=true/false`. Nur wenn die Checkbox am Modul
tatsächlich ausgeschaltet ist, wird der Test unterbunden.

Einmal Modul aktualisieren und **Übernehmen**. „Kacheltest“ erneut sagen.

## Symcon-Titel sichtbar, aber keine Passwortabfrage (31.10.2026)

Live-Ergebnis: `shell_boot`, `iframe_load` und `frame_dom=not_empty` funktionieren. `tile_render=loading` bleibt über mehrere Sekunden bestehen, `local_storage=unsupported` wurde im **äußeren** Alexa-Browser gemessen. Dies beweist noch NICHT, dass genau diese Einschränkung den Start der Kachelvisualisierung verhindert. Auch bei eingetragener Kachelvisualisierungs-Instanz-ID kommt nur „Symcon“ und eine leere Ansicht.

**Für das reine Laden muss kein Passwort als URL-Parameter hinterlegt werden.** IP-Symcon zeigt bei einer passwortgeschützten Kachelvisualisierung nach Auswahl der Visualisierung ein Passwortfeld an, sofern die Browser-App ordnungsgemäß startet. Das bestehende Modul-Feld `Passwort (WFC)` gilt für das **alte WebFront**, nicht als automatische Anmeldung für die neue Kachelvisualisierung. Das Modul fügt der Kacheltest-Adresse absichtlich **kein** `?password=...` hinzu. Dies ist für die neue Visualisierung nicht als verlässliche Anmeldung vorgesehen; außerdem sollten Zugangsdaten nicht in Browser-URLs, Protokollen oder GitHub erscheinen.

### Zusätzliche Diagnose ab diesem Update

Beim Sprachbefehl **„Kacheltest“** werden im bereits vorhandenen Verlauf `Letzte 100 Browser-Ereignisse` nun außerdem erfasst:

- `tile_document`: `loading`, `interactive` oder `complete` (der innere iframe-Dokumentzustand).
- `tile_inner_storage`: echter `localStorage`-Lesen/Schreiben-Test **innerhalb des Visualisierungs-iframe**; unabhängig von der äußeren Alexa-Shell.
- `tile_snapshot` nach `onload`, `after_3s`, `after_8s` und `after_15s`: Anzahl DOM-Elemente, direkte Body-Kinder, gefundene Scripts/Stylesheets, iframe-Elemente, Passwortfelder, ggf. App-Root-Kinder und über die Performance-API beobachtete Script-/CSS-/XHR-/Fetch-/sonstige Ressourcen. Zahlen auf maximal 9999 begrenzt. Es werden keine Inhalte, URLs, Login-Daten oder JavaScript-Fehlertexte erfasst.

**Interpretation:** `tile_snapshot.status=loading` zusammen mit einem unveränderten, sehr kleinen `metrics.nodes`-Wert bei `after_8s` oder `after_15s` spricht dafür, dass die Kachel-App nicht initialisiert. `tile_inner_storage=unsupported` wäre eine mögliche Erklärung; es ist kein Ursachenbeweis. `tile_snapshot.status=login` bedeutet, dass ein echtes Passwortfeld im iframe sichtbar ist. `not_empty` ist allein kein Beweis für vollständig dargestellte Kacheln.

Die Browserdiagnose kann Fehler nicht nachträglich auslesen, die innerhalb des iframe **vor** dessen `load`-Event aufgetreten sind. Für deren genaue Fehlermeldungen wären gesonderte Instrumentierung **innerhalb** der Kachel-App oder Debugging auf einem vergleichbaren Browser nötig. Dieser Test verändert weder die proprietäre Symcon-Webanwendung noch deren Anmeldung.

**Test nach Update:** Modulinstanz **Übernehmen** → Alexa-Skill starten → **„Kacheltest“** → nach mindestens 15 Sekunden die JSON-Zeilen mit `route=kacheltest` aus **Letzte 100 Browser-Ereignisse** kopieren. Zum Debuggen das bestehende Kamera- und Energie-WebFront bitte unverändert lassen.

## Diagnose

**Objektbaum → Diagnose → Web API for Games – Kamera/Energie**. Die bekannten Variablen erfassen nun zusätzlich `route=kacheltest`.

- `TileTest.start` im Alexa-Protokoll (letzte 500): der Testmodus wurde aktiviert und der Zielpfad `/tile/` gebaut.
- `ExternalHTML.prepare`, `ExternalHTML.probe`: HTTP-, TLS-, CSP-, iframe- und Body-Status; vollständige URLs/Passwörter werden nicht gespeichert.
- `shell_boot`, `iframe_start`, `iframe_load`: Browser-Shell und iframe starten.
- `tile_capability` mit `local_storage`, `session_storage`, `websocket`, `indexed_db`: Verfügbarkeit typischer Browser-APIs.
- `tile_render` mit `login`: Passwortfeld wird erkannt. Ein Passwort ist für die neue Kachelvisualisierung **nicht** automatisch im URL-Link hinterlegt, weil das unzuverlässig und sicherheitskritisch wäre.
- `tile_render` mit `loading` oder `not_empty`: grobe DOM-Struktur in der geladenen Visualisierung. **not_empty garantiert nicht**, dass die Kacheln sichtbar und korrekt funktionieren.
- `tile_frame_js_error`, `tile_frame_rejection`, `tile_frame_resource_error`: Fehler innerhalb der Kachelvisualisierung, soweit sie nach der iframe-Initialisierung beobachtbar sind. Keine Fehlertexte werden übertragen.
- `iframe_timeout`: iframe wird nicht fertig geladen.

Falls der Echo-Bildschirm weiter weiß bleibt, die **letzten 100 Browser-Ereignisse** und das **Alexa-Protokoll (letzte 500)** kopieren; die Diagnose erfasst **keine** Bildschirminhalte, Zugangsdaten oder automatisch nutzbaren Screenshots.

**Sicherheit:** Falls die Kachelvisualisierung eine Anmeldung verlangt, muss sie regulär erfolgen. Den Passwortschutz nicht für diesen Test deaktivieren oder für die gesamte Internetöffentlichkeit freigeben. Die bisherigen WebFront-Passwörter und Kamerarouten bleiben unberührt.
