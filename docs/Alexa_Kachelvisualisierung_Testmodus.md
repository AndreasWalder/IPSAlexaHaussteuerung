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
