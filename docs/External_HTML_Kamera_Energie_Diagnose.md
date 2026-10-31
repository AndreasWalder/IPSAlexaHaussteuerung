# Diagnose: Alexa Web API for Games – Kamera und Energie (weißer Bildschirm)

## Ausgangssituation

Licht, Jalousie und Lüftung verwenden APL und funktionieren. Die Kacheln **Kamera** und **Energie** verwenden dagegen die separate Schnittstelle `Alexa.Presentation.HTML.Start`, die die URL `/hook/iah-alexa-shell?f=alexa-diagnostic-shell.html` öffnet (vorher: `alexa-shell.html`). Die erfolgreiche Antwort von `ROUTE_ALL` beweist nur, dass die HTML-Start-Anweisung erzeugt wurde – **nicht**, dass der Alexa-Browser die Webseite geladen oder das iframe angezeigt hat.

Alexa unterscheidet HTML- von APL-Geräteunterstützung. Die Seite muss über **öffentlich erreichbares HTTPS und gültiges TLS-Zertifikat** geladen werden. Einbettung durch iframe kann unter anderem durch `X-Frame-Options` oder die CSP-Direktive `frame-ancestors` blockiert werden.

## Automatische Browserdiagnose (neu)

Nach **GitHub-Modul aktualisieren** und **Modulinstanz → Übernehmen** werden folgende Ressourcen automatisch eingerichtet:

- `/hook/iah-alexa-html-debug` → geschütztes Browser-Empfängerskript (Tokenprüfung).
- `/hook/iah-alexa-shell` → Datei-Auslieferung, **nur falls dieser WebHook noch nicht existiert**. Bestehende WebHooks werden nicht überschrieben.
- `user/icons/alexa-diagnostic-shell.html` → neue, eigene Diagnose-Shell. Die bisherige `alexa-shell.html` wird **nicht** geändert.
- Zusätzliche kopierbare Variablen **Letztes Browser-Ereignis (JSON)** und **Letzte 100 Browser-Ereignisse (JSON-Zeilen)**.

**Korrektur nach Logs vom 31.10.2026, 21:38 Uhr:** In der bisherigen Version meldete IP-Symcon
`ExternalHTML.setup hook_exists_external /hook/icons` und
`ExternalHTML.setup hook_exists_external /hook/alexa-html-debug`. Die Browser-Variable blieb leer, während die HTTP-Probe einen 200-Status mit 0 Bytes Inhalt meldete. Die neuen **eigenen** Modul-WebHooks umgehen diese vorhandenen Belegungen ohne fremde WebHooks oder alte Testskripte zu überschreiben.

Neu erkennt die Serverprobe zusätzlich `shell_http_200_empty_body`. Wenn die Shell über den modulspezifischen
WebHook tatsächlich angefordert wird, protokolliert der WebHook die Datei-Auslieferung als
`ExternalHTML.shellDelivery` (mit `server_probe` oder `other_client`, ohne personenbezogene Inhalte).

**Kein separates PHP-Installationsskript und keine manuelle WebHook-Konfiguration nötig.** Kamera und Energie nutzen vorübergehend die neue Diagnose-Shell; Licht, Jalousie, Heizung sowie andere Routen bleiben unverändert.

Beim Alexa-Aufruf **„Kamera“** sollten die Ereignisse `shell_boot`, `iframe_start`, `iframe_load` / `iframe_error`, `frame_dom` und eventuell `iframe_timeout` in den neuen Variablen und in **Alexa-Meldungen (letzte 500)** erscheinen. Die HTML-Shell meldet **nur vordefinierte Statuscodes**, niemals Adressen, Tokens, Passwörter, DOM-Texte oder JavaScript-Fehlertexte.

**Auswertung:**

- `ExternalHTML.directive`, aber kein `ExternalHTML.browser shell_boot`: Das Echo hat die Shell möglicherweise gar nicht geladen, der WebHook ist nicht erreichbar, oder JavaScript/HTML wird auf dem Gerät nicht unterstützt. Die alleinige Abwesenheit eines Browser-Events beweist keine einzelne Ursache.
- `shell_boot`, aber kein `iframe_load`: Browser startet, iframe-Seite wird nicht fertig geladen.
- `iframe_load` und `frame_dom: empty`: Die Seite lädt, enthält aber keinen erkennbaren DOM-Inhalt.
- `iframe_load` und `frame_dom: cross_origin`: Browser konnte den iframe-Inhalt nicht auslesen (kein Fehlerbeweis).
- `js_error`/`resource_error`: JavaScript-/Ressourcenproblem, ohne vertrauliche Details zu sammeln.

Der asynchrone Server-Check `ExternalHTML.probe` bleibt parallel aktiv und berichtet HTTPS/Status/Content-Type/iframe-Sicherheits-Header. `shellHttp.bytesRead: 0` ist zunächst **nur ein Messwert des Servers**, kein Beweis für eine leere Anzeige auf dem Echo.

**Falls keine HTML-Ereignisse kommen:** Prüfe unter dem Modul das Protokoll auf `ExternalHTML.setup`. Bei bereits anderweitig belegten WebHooks wird nur eine Warnung ausgegeben; fremde Hooks werden aus Sicherheitsgründen nicht übernommen.

## Neue Diagnose in IP-Symcon

Nach Modulupdate und **Übernehmen** unter:

`IPSAlexaHaussteuerung → Diagnose → Web API for Games – Kamera/Energie`

- **Letzter HTML-Diagnosebericht (JSON)**: jüngster Status, nach erfolgreicher oder fehlgeschlagener Netzwerkprobe.
- **Letzte 20 HTML-Diagnosen (JSON-Zeilen)**: kopierbarer Verlauf.

Zusätzlich erscheinen im bestehenden **Alexa-Protokoll (letzte 500)** folgende Ereignisse:

- `ExternalHTML.device`: erkannte Alexa-Unterstützung für **HTML** getrennt von **APL** (sofern im Request verfügbar).
- `ExternalHTML.prepare`: welche externe Seite angefordert wurde und ob Shell, Ziel und Probe konfiguriert sind.
- `ExternalHTML.probeQueued`: asynchroner Prüfauftrag gestartet.
- `ExternalHTML.directive`: HTML.Start-Typ, URI-Schema, Zeitlimit.
- `ExternalHTML.probe`: Prüfungsergebnisse (HTTPS, HTTP-Status, cURL-Code, Antwortzeit, Content-Type, Redirect, `X-Frame-Options`, `Content-Security-Policy frame-ancestors`, lokale Existenz von `alexa-shell.html`).
- `ExternalHTML.clientEvent`: falls die vorhandene HTML-Anwendung ein `Alexa.Presentation.HTML.Message` sendet, werden nur die Felder `type`, `stage`, `status`, `code` übernommen.

**Keine vollständigen URL-Adressen, Zugangstokens, WebFront-Passwörter, HTTP-Inhalte, Cookie-Werte oder private Geräteschlüssel werden von der neuen Diagnose ausgegeben.** Frühere alte Meldungen können dennoch sensible Informationen enthalten; vor dem Teilen kontrollieren.

## Häufige Ursachen in `warnings`

| Warnung | Interpretation |
| --- | --- |
| `device_reports_no_html_support` | Alexa-Gerät meldet keine HTML-Web-API-Unterstützung (APL kann trotzdem funktionieren). |
| `local_shell_file_not_found_at_user_icons` | Auf dem IP-Symcon-Server liegt `user/icons/alexa-shell.html` nicht an der erwarteten Stelle. Falls ein anderer Hook die Datei dynamisch ausliefert, ist diese Warnung allein kein Fehlerbeweis. |
| `shell_not_found` / `target_not_found` | HTTP 404, Pfad oder Hook prüfen. |
| `shell_unauthorized` / `target_unauthorized` | HTTP 401/403, Authentifizierung/Token/Zugriffsrechte prüfen. |
| `shell_redirect_or_login` / `target_redirect_or_login` | HTTP 3xx mit Weiterleitung; kann auf Login-Redirect hindeuten. |
| `target_iframe_restricted_by_xframe` | Header `X-Frame-Options: DENY` blockiert immer; `SAMEORIGIN` blockiert nur bei unterschiedlicher Origin von Shell und Ziel. |
| `target_iframe_possible_csp_frame_ancestors_block` | CSP `frame-ancestors 'none'` oder `'self'` bei anderer Origin kann das Einbetten verhindern. |
| `shell_csp_frame_src_may_block_iframe` | CSP `frame-src 'none'` oder `'self'` bei anderer Ziel-Origin kann das iframe sperren. |
| `target_requires_https_iframe` | Das iframe-Ziel ist nicht HTTPS und wird in der sicheren Alexa-Web-Umgebung womöglich als Mixed Content gesperrt. |
| `shell_requires_https` | Alexa Web API verlangt HTTPS. |
| `shell_curl_error_...` | TLS/DNS/Timeout oder Verbindungsproblem. Nummer ist der cURL-Fehlercode. |
| `target_not_text_html` | WebFront-Ziel liefert kein HTML, möglicherweise JSON-Fehlerseite. |

### Grenzen der Prüfung

**Die HTTP-Probe läuft auf dem IP-Symcon-Server, nicht auf dem Echo-Gerät.** Sie verwendet HTTPS mit Zertifikatsprüfung, folgt keinen Redirects und begrenzt die Zeit pro Seite auf 5 Sekunden. Sie läuft **parallel** über `IPS_RunScriptEx`; der Alexa-Aufruf muss nicht auf den Prüfabschluss warten.

Die Prüfung kann daher nicht beweisen, ob das Echo-Gerät die Seite öffnen kann, und nicht automatisch Browser-JavaScript-Fehler, Cookie-/Iframe-Verhalten oder Netzwerkfehler innerhalb des Echos feststellen. Die neue Diagnose-Shell erfasst dafür begrenzte Browser-Ereignisse und schreibt sie über den automatisch registrierten Diagnose-WebHook ins Modul. Sind Shell und Zielserver erreichbar, aber das Bild bleibt weiß, ist die Browser-/Iframe-Seite der nächste Ansatzpunkt.

## So testen

1. GitHub-Modul aktualisieren, Modulinstanz öffnen, **Übernehmen** klicken.
2. Alexa-Skill starten und **„Kamera“** sagen. Einige Sekunden später **„Energie“** testen.
3. In **Diagnose → Web API for Games – Kamera/Energie** die beiden JSON-Variablen öffnen und kopieren.
4. Ergänzend **Diagnose → Alexa-Protokoll (kopieren)** senden, insbesondere `ExternalHTML.*`-Zeilen.
5. Optional Status des lokalen `alexa-shell.html`-Hooks und der WebFront-Seite vergleichen. **Keine vollständigen URLs mit Token/Passwort** weitergeben.

Bestehende APL-Routen, RoomsCatalog-Zuordnungen und die bisherige `alexa-shell.html` werden nicht verändert.
