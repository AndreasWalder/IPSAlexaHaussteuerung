# Sicherheit und Datenschutz

Das Repository ist öffentlich. Bitte **keine echten Konfigurationsdateien**, Keys, Tokens, Passwörter, lokale Server-IPs, privaten Räume-/Gerätepläne oder Symcon-Skript-Exports einchecken.

## RoomsCatalog: Update-Verhalten und Privatsphäre

- `IPSAlexaHaussteuerung/resources/helpers/RoomsCatalog.php` ist bewusst eine **leere, anonymisierte Vorlage** (`return [];`).
- In `module.php` erzeugt `ensureRoomsCatalogTemplate()` nur beim **ersten** Anlegen eines neuen Symcon-Skripts einen Katalog aus der Vorlage. Der produktive, existierende Symcon-RoomsCatalog wird beim Update **nicht ersetzt**. Seine Geräte, Objekt-IDs und `kiEnabled`/`kiReadOnly`-Berechtigungen bleiben erhalten.
- Deine produktive Konfiguration bleibt ausschließlich **lokal in IP-Symcon** und wird dort per RoomsCatalogConfigurator (EDIT → PROD) geändert.
- Bei Neuinstallationen ist der Katalog zunächst leer und muss vor der ersten Benutzung konfiguriert werden.
- **Wichtig:** `.gitignore` kann bereits getrackte Dateien nicht schützen. Niemals die hier eingecheckte Vorlage mit der lokalen realen Konfiguration ersetzen und committen; lokale Exporte außerhalb des Repositorys aufbewahren.

## Zugangsdaten und Diagnose

- API-Schlüssel und Home-Agent-Token nur in den **IP-Symcon-Modulinstanz-Einstellungen** verwalten. Diese Werte sind nicht Teil der GitHub-Vorlagen.
- Die Alexa-Routen protokollieren ab dieser Version **nicht mehr** den WebFront-Token, die Server-URL, die vollständige externe Seiten-URL, den Antwort-Rohtext oder komplette Alexa-Requests.
- Beim Modulupdate wird ein vorhandenes Action-Skript über `patchActionScriptSafeRequestLogging()` **gezielt** auf sichere Request-Metadaten umgestellt, ohne die übrige benutzerdefinierte Action-Logik zu überschreiben.
- Frühere Symcon-Logdateien enthalten möglicherweise noch Tokens oder URLs aus alten Versionen. **Alte Logs separat prüfen, sicher löschen/archivieren und betroffene Tokens bei Verdacht austauschen.** Ein Update bereinigt keine historischen Logeinträge.
- Keine vollständigen Screenshots mit Zugangskennungen/URLs oder kompletten Request-JSON in Issues, Pull Requests und Chats veröffentlichen.
- Die alte externe WebFront-/APL-Navigation kann **aus Kompatibilitätsgründen weiterhin Passwort/Hook-Token in generierten URLs enthalten**; diese Werte werden nicht mehr geloggt. Solche URL-Parameter können trotzdem in Browserhistorie, Proxies oder Webserverlogs landen. Nur über HTTPS mit angemessener Zugriffskontrolle einsetzen; langfristig eine sitzungsbasierte/kurzlebige Tokenlösung vorsehen.
- IP-Symcon-WebHooks niemals ohne Authentifizierung öffentlich freigeben; bei Zugriff von außerhalb zusätzlich VPN/HTTPS und Zugriffsregeln einsetzen.

## Öffentliche Git-Historie

Das Ersetzen oder Löschen einer Datei durch einen Pull Request entfernt sie **nicht aus der alten Git-Historie**. Auch vergangene Pull Requests, Forks, Klone und Caches können den früheren Katalog oder ältere private Adressen weiterhin enthalten. Die vorliegende Änderung **bereinigt den aktuellen Branch-Stand**, nicht rückwirkend die gesamte Historie.

Um alte Inhalte auch aus der Git-Historie zu entfernen, wäre eine gesonderte, koordinierte **History-Rewrite-Aktion** inklusive Force-Push, Behandlung alter Pull-Request-Refs/Forks und Cache-Bereinigung notwendig. Das ist kein gewöhnliches Modulupdate und wird nicht automatisch vorgenommen. Bei vermuteten kompromittierten Zugangsdaten **Tokens sofort rotieren**, unabhängig von einem History-Rewrite.

## Fiktive Beispieladresse

Beispiele in der Dokumentation verwenden `192.0.2.10` aus dem für Dokumentation reservierten TEST-NET-1-Adressbereich und eine fiktive Instanz-ID `12345`. Diese Werte sind nicht produktiv nutzbar; die echten lokalen Werte müssen im eigenen Symcon-System eingetragen werden.
