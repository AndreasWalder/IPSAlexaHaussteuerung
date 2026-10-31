# Diagnose: Alexa Web API for Games – Kamera und Energie (weißer Bildschirm)

## Ausgangssituation

Licht, Jalousie und Lüftung verwenden APL und funktionieren. Die Kacheln **Kamera** und **Energie** verwenden dagegen die separate Schnittstelle `Alexa.Presentation.HTML.Start`, die die URL `/hook/icons?f=alexa-shell.html` öffnet. Die erfolgreiche Antwort von `ROUTE_ALL` beweist nur, dass die HTML-Start-Anweisung erzeugt wurde – **nicht**, dass der Alexa-Browser die Webseite geladen oder das iframe angezeigt hat.

Alexa unterscheidet HTML- von APL-Geräteunterstützung. Die Seite muss über **öffentlich erreichbares HTTPS und gültiges TLS-Zertifikat** geladen werden. Einbettung durch iframe kann unter anderem durch `X-Frame-Options` oder die CSP-Direktive `frame-ancestors` blockiert werden.

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

Die Prüfung kann daher nicht beweisen, ob das Echo-Gerät die Seite öffnen kann, und nicht automatisch Browser-JavaScript-Fehler, Cookie-/Iframe-Verhalten oder Netzwerkfehler innerhalb des Echos feststellen. Dafür müsste die vorhandene `alexa-shell.html` zusätzlich eine opt-in JavaScript-Telemetrie enthalten (diese Datei wird durch das Modul **nicht** überschrieben). Sind Shell und Zielserver erreichbar, aber das Bild bleibt weiß, ist die Browser-/Iframe-Seite der nächste Ansatzpunkt.

## So testen

1. GitHub-Modul aktualisieren, Modulinstanz öffnen, **Übernehmen** klicken.
2. Alexa-Skill starten und **„Kamera“** sagen. Einige Sekunden später **„Energie“** testen.
3. In **Diagnose → Web API for Games – Kamera/Energie** die beiden JSON-Variablen öffnen und kopieren.
4. Ergänzend **Diagnose → Alexa-Protokoll (kopieren)** senden, insbesondere `ExternalHTML.*`-Zeilen.
5. Optional Status des lokalen `alexa-shell.html`-Hooks und der WebFront-Seite vergleichen. **Keine vollständigen URLs mit Token/Passwort** weitergeben.

Bestehende APL-Routen, RoomsCatalog-Zuordnungen und die bisherige `alexa-shell.html` werden nicht verändert.
