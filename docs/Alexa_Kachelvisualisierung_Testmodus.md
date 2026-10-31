# Alexa: Neue IP-Symcon-Kachelvisualisierung separat testen

## Auswertung 31.10.2026, 22:59 – warum der Ring weiterhin weiß wird

Die neue Aufzeichnung zeigt erstmals einen **konkreten, reproduzierten
URL-Kontextfehler**:

- `tile_boot=hash_match`: das Fragment mit der Visualisierungs-ID ist gesetzt.
- `tile_location=srcdoc_context`: der tatsächliche Dokumentpfad ist
  `about:srcdoc`, nicht `/tile/`.
- `tile_history=history_blocked`: der Alexa-Browser verweigert das
  Umschreiben des srcdoc-Dokuments auf `/tile/#ID`.
- `shim_session` und `tile_inner_storage=supported`: unser temporärer
  localStorage-Ersatz funktioniert.
- **19 HTTP-Antworten 2xx**, davon **1 JSON und 18 sonstige
  Content-Types**; 0 Antworten 4xx/5xx.
- Trotzdem nach 15 Sekunden nur **12 HTML-Elemente**, kein Passwortfeld
  und weiterhin keine Kacheloberfläche.

**Interpretation:** Der gesperrte srcdoc-Dokumentkontext ist ein
nachgewiesener Unterschied zur echten Tile-URL und eine plausible Ursache.
Das beweist nicht, dass kein weiterer Fehler vorliegt. HTTP 200 beweist
außerdem nicht, dass die Antworten die erwarteten Inhalte haben.

### Neuer, separat wählbarer URL-Kompatibilitätstest

Unter **Alexa – neue Kachelvisualisierung testen** gibt es die neue
Auswahl **„Kompatibilitätsverfahren“**, die nur ausgewertet wird, wenn
`TileTestStorageCompat` aktiviert ist.

- **Neue Methode: echte HTTPS-Dokumentadresse /tile/ (Standard für
  aktivierten Test):** Das Modul installiert auf dem Server zusätzlich
  `alexa-tile-url-bootstrap.html`. Das Alexa-iframe lädt diese
  reguläre HTTPS-Seite über den **bestehenden, token-geschützten**
  Modul-Hook. Die Seite ruft nur die eigene `/tile/`-URL ab,
  testet ein gleichursprüngliches `history.replaceState('/tile/#ID')`
  und setzt die Original-HTML-Seite mit früh installiertem localStorage-
  Ersatz über `document.open/write/close` ein. Dieser Ansatz verwendet
  kein `iframe.srcdoc`; das ist der entscheidende Vergleich.
- **Vorherige Methode: iframe.srcdoc (Vergleich):** Verhält sich wie der
  bisherige Test. Bei fehlgeschlagener neuer Bootstrap-Datei erfolgt
  automatisch ein Rückfall auf diese Variante.

Die neue Methode meldet `tile_compat=url_bootstrap`,
`tile_origin=origin_start/origin_injected`,
`tile_location=tile_context/other_context` und
`tile_history=history_ok/history_blocked`. Alle Meldungen sind
fest definierte Zustandswerte. Keine HTTP-Antwortinhalte, URLs,
Passwortfelder oder Token werden aufgezeichnet.

**Test:** Module Store aktualisieren → Modulinstanz „Übernehmen“ →
localStorage-Test weiterhin **aktiviert** lassen → Verfahren
**„Neue Methode: echte HTTPS-Dokumentadresse“** auswählen →
„Alexa, öffne Haussteuerung“ → **„Test“** → 15 Sekunden offen lassen →
`route=kacheltest` in **Letzte 100 Browser-Ereignisse** kopieren.

**Grenze:** Auch diese Methode verändert testweise die Darstellung des
HTML-Dokuments und kann an der Browser-Sandbox, Symcon-Authentifizierung
oder anderen Anforderungen der SPA scheitern. Ein Ergebnis wird erst
durch das tatsächliche Erscheinen von Passwortfeld/Kacheln bestätigt.
Kamera, Energie, WebFront und die originale /tile/-Seite werden
nicht verändert.

## Diagnose 31.10.2026, 23:31:41 – genaue Ursache Content-Type

**Beobachtung am Echo:** Nach dem letzten Modulupdate wird der
Passwortdialog erkannt (`tile_autologin=field_detected`), doch die
Serverantwort meldet unmittelbar `tile_autologin=content_type_invalid`.
Das ist der genaue Ablehnungsgrund dieses Tests, nicht der zuvor
vermutete Host-/Origin-Vergleich.

**Ursache im WebHook:** Der PHP-Helfer verlangte
`$_SERVER['CONTENT_TYPE'] = application/json`. Dieses PHP-Metadatenfeld
ist bei der eingesetzten IP-Symcon-WebHook-/Proxy-Kombination
offenbar nicht zuverlässig verfügbar. Der Browser selbst sendet
bereits JSON per `fetch` mit `Content-Type: application/json`.

**Korrektur:** Der Endpoint ignoriert die optionale PHP-Content-Type-
Metadatenangabe und validiert stattdessen den tatsächlichen
POST-Body streng als JSON (maximal 2.048 Bytes) mit allen bisherigen
Pflichtdaten. Unverändert Pflicht bleiben ein gültiges Modul-Token,
konkrete Visualisierungs-ID, aktivierter Opt-in-Modus, gleicher
HTTPS-Ursprung und ein zeitlich begrenztes signiertes HMAC-Ticket.
Ungültige, zu große oder nicht parsebare POST-Bodies werden weiterhin
mit `payload_invalid` abgewiesen. **Das Passwort steht weiterhin nie
in URLs oder Logs.**

Nach Modulupdate und **Übernehmen** erneut **„Test“** auf Alexa
sprechen. Der ideale Verlauf ist jetzt
`field_detected → filled → submitted` (bzw. `enter_sent` für
die alternativ unterstützte Eingabe). Bei erneuter Fehlermeldung
nur `tile_autologin`-Einträge aus **Letzte 100 Browser-Ereignisse**
kopieren; eine manuelle Anmeldung bleibt möglich.

## Automatisches Passwort: „field_detected“ und danach „credential_unavailable“ (31.10.2026)

Bei dem Test auf dem Echo wurde das Passwortfeld korrekt erkannt; der
Browser konnte aber keine Zugangsdaten über den neuen POST-WebHook lesen.
Die Modulkonfiguration zeigte `autoLoginReady=true`. Der alte
Fehlercode `credential_unavailable` unterschied weder einen blockierten
Serverzugriff noch einen Netzwerkfehler oder eine ungültige Antwort.

Ein plausibler Grund: Der WebHook verglich `HTTP_ORIGIN` bisher
direkt mit `HTTP_HOST`. Hinter einem Reverse Proxy / Connect-Zugang
kann `HTTP_HOST` jedoch ein interner Servername sein, während der
Alexa-Browser die öffentliche HTTPS-Adresse sieht.

**Änderung:** Die Herkunft wird jetzt gegen `BaseUrl` der betroffenen
IP-Symcon-Modulinstanz (inklusive HTTPS und Port) geprüft, nicht mehr
gegen `HTTP_HOST`. Fehlen die optionalen Browserheader, ist ein
gültiges, zufälliges und maximal 120 Sekunden gültiges signiertes
Zugriffsticket mit Modul-Token unverändert erforderlich. Sind explizite
Cross-Site-Werte vorhanden, wird der Abruf abgewiesen. Es werden
weiterhin keine Passwörter per URL oder Protokoll übertragen.

### Fehlermeldungen ohne Geheimnisse

Bei einem erneuten „Test“ schreibt die bestehende Variable
**Letzte 100 Browser-Ereignisse** nun z. B.:

- `tile_autologin / origin_mismatch`: Origin stimmt nicht mit der
  konfigurierten externen BaseUrl überein.
- `tile_autologin / origin_invalid`: Keine gültige externe HTTPS-BaseUrl
  oder ein ungültiger Origin-Header.
- `tile_autologin / configuration_disabled`: Opt-in nicht aktiv,
  Visualisierungs-ID oder Passwort fehlt.
- `tile_autologin / ticket_expired` bzw. `ticket_invalid`:
  Kurzzeit-Ticket abgelaufen oder ungültig.
- `tile_autologin / credential_http_404`: Credential-WebHook nicht
  erreichbar bzw. nicht korrekt registriert.
- `tile_autologin / credential_http_403`: WebHook verweigert den Abruf,
  ohne genaueres Fehler-Header-Signal.
- `tile_autologin / credential_http_5xx`: Serverseitiger Fehler.
- `tile_autologin / credential_network`: Browsernetzwerk/CORS/Transport
  verhindert die Antwort.
- `tile_autologin / credential_invalid_response`: Antwort unerwartet.

Alle Codes sind feste Kategorien. **Es werden weder Passwort, Token,
Ticket, vollständige URL noch die Fehlermeldung des Servers geloggt.**

**Nach Installation:** Modul aktualisieren und Instanz auf
**Übernehmen** klicken (damit der WebHook-Helper aktualisiert wird).
Weiterhin `Test` sagen, mindestens 25 Sekunden öffnen lassen.
Anschließend nur die Zeilen mit `tile_autologin` kopieren. Auch bei
fehlgeschlagenem Autologin bleibt die manuelle Anmeldung möglich.

## Optional: Passwort der Kachelvisualisierung automatisch eingeben

Die Kachelvisualisierung startet mit der neuen `url`-Bootstrap-Methode
auch auf Alexa und zeigt die reguläre Passwortabfrage. **Dies ist die
unterstützte Anmeldung, kein Umgehen der Zugriffskontrolle.**

Für den Komfort ist jetzt eine **standardmäßig deaktivierte** Funktion
verfügbar, die das bereits gültige Passwort im tatsächlichen Login-Dialog
einmalig eingibt und nach Möglichkeit auf „Anmelden“ klickt.

**Einrichtung:** IP-Symcon-Modulinstanz →
**Alexa – neue Kachelvisualisierung testen**:
1. `Alexa Kacheltest erlauben`: ein.
2. `Kachelvisualisierungs-Instanz-ID`: konkrete ID, nicht 0.
3. `TileTestStorageCompat`: ein; **Kompatibilitätsverfahren**:
   **Neue Methode: echte HTTPS-Dokumentadresse /tile/**.
4. `Automatische Passwort-Anmeldung auf Alexa`: ein.
5. In das maskierte Feld **Passwort der ausgewählten Kachelvisualisierung**
   das Passwort *dieser* Tile-Instanz eintragen, nicht das alte
   WebFront-Passwort. **Übernehmen** klicken.
6. „Alexa, öffne Haussteuerung“ → „Test“ sagen.

### Wie funktioniert das?

- **Kein Passwort in der URL** oder in den Amazon-HTML.Start-Direktiven.
- Der Renderer erstellt je Testaufruf ein zufälliges, mit Modul-Token
  und Passwort signiertes **maximal 120 Sekunden** gültiges Ticket.
  Das Ticket enthält das Passwort nicht.
- Wenn der Browser das echte Passwortfeld sieht, ruft er **einmal**
  den getrennten WebHook `/hook/iah-alexa-tile-credential` per
  Same-Origin-HTTPS-**POST** auf. Das Backend prüft ID, Token,
  Konfiguration, Ticket und Ablaufdatum.
- Die Antwort ist `no-store`; das Passwort wird ausschließlich im
  Browser für das Login-Feld verwendet. Die echte Tile-Anmeldung
  prüft das Passwort weiterhin wie bei einer manuellen Eingabe.
- Falls das Formular nicht automatisch abgeschickt werden kann,
  wird nur das Passwortfeld gefüllt; der Benutzer kann dann
  „Anmelden“ antippen. Es werden **keine** wiederholten Anmeldeversuche
  unternommen, um Sperren durch Fehlversuche zu vermeiden.
- Diagnose `tile_autologin` meldet ausschließlich feste Codes:
  `field_detected`, `filled`, `submitted`, `enter_sent`,
  `filled_only`, `credential_unavailable`, `dom_changed`.
  **Niemals** Passwort, Passwortlänge, Token, Eingabewert oder Cookie.

### Sicherheit und Grenzen

**WICHTIG:** Eine `PasswordTextBox` maskiert nur die Anzeige in IP-Symcon.
Modul-Properties werden in der Symcon-Konfiguration gespeichert
und sind damit für Administratoren/Backups auslesbar; eine native
verschlüsselte Speicherung wird durch diese Ergänzung **nicht**
behauptet. Nutze deshalb möglichst eine eigenständige, auf die
erforderlichen Geräte beschränkte Kachelvisualisierung mit eigenem
Passwort, nicht das Administrationspasswort. Wer das Echo mit
aktiviertem AutoLogin starten kann, kann die freigegebenen
Haussteuerungsfunktionen bedienen.

Die von Symcon unterstützte Alternative ohne Speicherung eines Passworts
ist **Autostart für IP-Adresse** in den Sicherheitseinstellungen der
Kachelvisualisierung, aber nur für ein *vertrauenswürdiges, direkt
angeschlossenes* Gerät im lokalen Netzwerk. Bei Reverse Proxy oder
Connect ist die IP-Bindung in der Regel nicht zuverlässig; keinesfalls
global `0.0.0.0/0` freigeben.
Dokumentation:
https://www.symcon.de/de/service/dokumentation/komponenten/kachel-visualisierung/instanzkonfiguration/sicherheit/

Ein Passwort als `?password=...`-URL-Parameter wird bewusst **nicht**
verwendet: Symcon betrachtet dies bei der neuen Kachelvisualisierung als
Sicherheitslücke und unterstützt es nicht verlässlich.
Dokumentierte Diskussion:
https://community.symcon.de/t/kachel-visualierung-passwort-im-get-parameter/139629

Bei `TileAutoLoginEnabled=false`, fehlendem Passwort, deaktiviertem
Kompatibilitätsmodus, `srcdoc`-Verfahren oder fehlender konkreter
Visualisierungs-ID verhält sich der Test exakt wie bisher: **manuelle
Anmeldung**. Kamera/Energie und das klassische WebFront sind
vollständig unverändert.

## Neuer dauerhafter Alexa-Befehl „Test“

**Ab jetzt nur „Test“ sagen** (nach „Alexa, öffne Haussteuerung“).
Der bisherige Begriff **„Kacheltest“** wurde in deinen Logs oft als
**„Kaffeetest“** erkannt. Das kurze Wort „Test“ ist ab sofort ein eigener
Sprachalias mit **direkter lokaler Navigation ohne KI-Auswertung**.

- Die interne Route heißt `test` und führt zunächst über
  `AlexaTestTarget=kacheltest` zur Kachelvisualisierung.
- In der Modulinstanz steht die neue Auswahl **„Ziel für Alexa-Sprachbefehl
  Test“**. Momentan ist ausschließlich die vorhandene Kachelvisualisierungs-
  Diagnose freigegeben; weitere Testziele lassen sich zukünftig ergänzen.
- Alte Sprachbefehle `Kacheltest` und `Kachelvisualisierung` bleiben
  unverändert. Bestehende benutzerangepasste Action-Skripte werden beim
  **Übernehmen** an der bestehenden Aliasliste behutsam nachgerüstet.
- Im Alexa-Protokoll erscheint `Test.route`, im Browserdiagnoseverlauf
  weiter `route=kacheltest`, damit die bisherigen Variablen und
  Log-Auswertungen kompatibel bleiben.

## Neue Auswertung der weißen Ansicht (31.10.2026, 22:49)

Die letzten Logs zeigen bei aktivierter Kompatibilität **19 erfolgreiche
HTTP-Antworten der Klasse 2xx**, **0 Antworten der Klassen 4xx und 5xx**,
`shim_session`, `tile_inner_storage=supported` und
`tile_boot=hash_match`. Dennoch gibt es **kein Passwortfeld** und nur
**12 DOM-Elemente** nach 15 Sekunden. Ein fehlendes Passwort oder ein
HTTP-401/403-Problem wird damit weniger wahrscheinlich; ob alle
Antwortkörper die erwarteten Daten enthalten, ist noch nicht geklärt.

Die neue Diagnose ergänzt:

- `tile_location`: tatsächlicher Dokument-URL-Kontext, klassifiziert als
  `srcdoc_context`, `tile_context` oder `other_context`.
- `tile_history`: testet früh und einmalig, ob der Browser das
  `about:srcdoc`-Dokument per `history.replaceState` nach `/tile/#ID`
  umschreiben lässt: `history_ok`, `history_blocked` oder
  `history_unchanged`. Wenn das verboten ist, bleibt die Seite
  unverändert. **Dies ist keine bestätigte Reparatur.**
- `tile_snapshot.metrics.responseJson`, `responseHtml` und
  `responseOther`: Klassifikation der von der App angeforderten
  HTTP-Antworten **nur über den Content-Type**, ohne URLs, Nutzdaten oder
  Passwörter zu lesen.

**Test:** GitHub-Modul aktualisieren, in der Modulinstanz auf
**Übernehmen** klicken, die bestehende localStorage-Kompatibilitätsoption
aktiviert lassen und auf Alexa **„Test“** sagen. Die Ansicht 15 Sekunden
geöffnet lassen; anschließend die neuen Browserereignisse kopieren.



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

## Opt-in: Test mit localStorage-Ersatz (experimentell)

Die Browser-Diagnose vom **31.10.2026 22:26** zeigte `tile_inner_storage=unsupported`, und die
Kachel-Seite blieb nach 15 Sekunden bei **6 DOM-Elementen**, ohne
Passwortfeld oder fertig initialisierte Oberfläche stehen. Wir wissen damit,
dass der Alexa-Browser localStorage blockiert; **ob genau dies den
Kachelvisualisierungs-Start verhindert, ist noch nicht bewiesen.**

Ein isolierter Test versucht deshalb, *vor* den Skripten der Tile-App einen
`localStorage`-Ersatz zu installieren, der **sessionStorage** als temporären
Backend-Speicher verwendet (wenn das nicht möglich ist, In-Memory-Speicher).
Ohne diesen explizit aktivierten Modus wird **gar nichts verändert**.

### Einschalten und vergleichen

1. Modul über GitHub/Module Store aktualisieren und Modulinstanz öffnen.
2. Unter **Alexa – neue Kachelvisualisierung testen** die neue Checkbox
   **„Experiment: localStorage in Alexa-Kacheltest durch sessionStorage ersetzen (nur Test)“**
   aktivieren und **Übernehmen** klicken.
3. Alexa-Skill starten, **„Kacheltest“** sagen, mindestens 15 Sekunden öffnen.
4. Im Diagnose-Verlauf **Letzte 100 Browser-Ereignisse** nach
   `route=kacheltest` filtern: `tile_compat`,
   `tile_inner_storage`, `tile_snapshot` sowie `tile_render`.
5. Checkbox wieder **deaktivieren** und erneut testen, um den Vergleich
   mit der unveränderten Originalseite zu haben.

### Technischer Ablauf und Einschränkungen

Nur für `route=kacheltest` und `compat=1` wird die **eigene**
`/tile/`-HTML-Seite per `fetch` unter derselben HTTPS-Origin geladen.
Der Browser fügt einen kleinen Bootstrap-Code unmittelbar nach `<head>`
ein, noch vor den originalen Skripten. Die veränderte Seite läuft als
`iframe.srcdoc`; ein `<base href=".../tile/">` erhält die relativen
Ressourcenpfade. Das ist **kein Ersatz für den Originalserver**.

- `tile_compat: fetch_start` = HTML-Abfrage beginnt.
- `tile_compat: html_injected` = HTML gepatcht und als `srcdoc` gesetzt.
- `tile_compat: shim_session` = die Browser-Schnittstelle wurde durch
  temporäres sessionStorage ersetzt.
- `tile_compat: shim_memory` = nur flüchtiger Speicher verfügbar.
- `tile_compat: shim_blocked` / `shim_missing` = Browser erlaubt den
  Ersatz nicht oder hat das Bootstrap-Skript nicht ausgeführt.
- `tile_inner_storage: supported` im inneren iframe = dort ist das
  localStorage-kompatible API für den Test zugänglich.
- `tile_snapshot` nach 3, 8 und 15 Sekunden zeigt, ob die Oberfläche
  tatsächlich weiter aufgebaut wird.

**Wichtig:** `iframe.srcdoc` verändert den `window.location`-Kontext
(z. B. `about:srcdoc` statt `/tile/#INSTANZ`). Auch wenn die
localStorage-Schnittstelle danach funktioniert, kann die Kachel-App wegen
dieses Unterschieds weiterhin nicht starten oder die ausgewählte
Kachelvisualisierungs-ID verlieren. JavaScript-Modulimport, CSP und
Ressourcenpfade können ebenfalls inkompatibel sein.
`shim_session` allein ist daher **kein Erfolgsnachweis**.

Falls `fetch`, HTML-Prüfung oder das Einfügen fehlschlagen, wird im
Test automatisch auf die **normale iframe-Ansicht** zurückgefallen.

Es werden niemals Passwörter oder Login-Token aus der originalen
WebFront-Konfiguration übernommen, keine URL-Passwörter angehängt und
keine Kachelinhalte, HTML-Quellen oder Speicherwerte protokolliert.
**Kamera, Energie, Licht, Jalousie, Heizung und das alte WebFront
sind nicht Teil des Experiments.** Der Testmodus ist standardmäßig AUS.

## Neuer Befund: Mit Speicherersatz erscheint der animierte Symcon-Ring, dann weiße Seite

**Gemessener Vergleich (31.10.2026):** Ohne Ersatz blieb die Anwendung bis
15 Sekunden bei 6 HTML-Knoten und 0 API-Anfragen. Mit aktivierter Option
`TileTestStorageCompat` wurde `shim_session` und
`tile_inner_storage=supported` bestätigt. Nach 15 Sekunden gab es
**11 DOM-Knoten**, **19 Fetch-/XHR-Anfragen** und den animierten Symcon-Ring.
Das ist ein echter Fortschritt bei der Initialisierung, aber noch kein
Nachweis einer funktionierenden Kachelvisualisierung.

**Weitere Ursache möglich:** Die experimentelle `iframe.srcdoc`-Einbindung
verliert `/tile/#<Visualisierungs-ID>` als echte Dokumentadresse
(`window.location` wird zu `about:srcdoc`). Auch bei funktionierender
Speicher-API könnte die App dadurch an Routing oder Anmeldung scheitern.

### Verbesserte Testdiagnose nach diesem Update

Im bereits aktivierten Kompatibilitätsversuch wird jetzt **vor den
Original-Symcon-Skripten** zusätzlich eine datensparsame Startüberwachung
installiert. Die Checkbox bleibt standardmäßig ausgeschaltet.

- `tile_boot` / `hash_match`, `hash_missing` oder `no_hash`: ob das
  numerische `#ID`-Fragment in die srcdoc-Testseite übertragen wurde.
  **Achtung:** `hash_match` repariert nicht die `about:srcdoc`-Basisadresse.
- `tile_boot` / `hooks_installed`: frühe Überwachung installiert.
- `tile_early_error` und `tile_early_rejection`: JavaScript-Ausnahmen,
  Promise-Rejections oder Script-/CSS-Ladefehler bei der Initialisierung.
  Die Meldungen werden ausschließlich als feste Fehlerkategorien erfasst,
  beispielsweise `security_error`, `type_error`, `reference_error`.
  **Keine** Fehlermeldung, Stacktrace, URL oder Benutzerdaten werden gespeichert.
- `tile_snapshot.metrics.response2xx`, `response3xx`, `response4xx`,
  `response5xx`, `responseFailed`: Anzahlen beobachteter HTTP-Ergebnisse
  ohne URLs oder Antwortkörper. Diese Werte erklären, ob die beobachteten
  API-Anfragen erfolgreich sind oder z. B. durch fehlende Anmeldung scheitern.

**Test:** Modul aktualisieren, Modulinstanz **Übernehmen**, bestehende
Kompatibilitäts-Checkbox aktiviert lassen, „Alexa, öffne Haussteuerung“
→ „Kacheltest“ sagen und nach 15 Sekunden die
`kacheltest`-Einträge aus der Browser-Diagnosevariable kopieren.
Kamera/Energie und das alte WebFront bleiben unverändert.

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
