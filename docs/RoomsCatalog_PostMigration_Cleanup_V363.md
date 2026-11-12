# V3.6.3 – Aufräumen nach abgeschlossener RoomsCatalog-Migration

Die V3.3-Migration ist abgeschlossen. Dieser Stand entfernt **einmalige**
Konvertierungs- und Migrationsschaltflächen, aber **keine produktiven
RoomsCatalog-, SystemConfiguration- oder Backup-Skripte**.

## IPSAlexaHaussteuerung

**Entfernt:**
- „RoomsCatalog automatisch bereinigen & nach PROD übernehmen“, einschließlich
  der vierfachen EDIT-/PROD-Migration, des einmaligen Autokorrektur-Rollbacks
  und der dazugehörigen Migrationshilfsklasse.
- Einmalige Bestandsaufnahme von alten Navigation-Tabs, Import-Assistent,
  Cutover, Cleanup und Rollback der V3.3.3/V3.3.5-Migration.
- Alte Migration-Receipt-/Preview-Konfigurationsfelder.

**Bleibt aktiv:**
- Navigation & Tabs mit zentraler Bearbeitung in SystemConfigurationEdit,
  Anzeigen aktueller Stände, Bearbeiten/Anlegen neuer Tabs und einem expliziten,
  hashgesicherten „Änderungen prüfen“ → „Änderungen nach PROD übernehmen“.
- Eigenständige Backups des produktiven SystemConfiguration-Skripts mit
  Hash-Kontrolle und Leseverifikation. Kein unkontrolliertes PROD-Schreiben.
- Alle KI-, Alexa-/APL-, Kachelvisualisierungs-, Geräte- und Archivfunktionen.

Für die normale Navigation-Publikation ist kein historischer
Migrationsbeleg mehr erforderlich. Ist die Navigation in SystemConfiguration
PROD nicht aktiviert, wird ein neuer Publish-Versuch aus Sicherheitsgründen
blockiert. Der entfernte Migrationsassistent wird **nicht** stillschweigend neu
ausgeführt.

## RoomsCatalogConfigurator

**Entfernt:**
- „Altgeräte ins neue Modell übernehmen“ und seine einmaligen
  Legacy-Umwandlungs-Buttons.
- „Modell, IPS-Variablen und Migration prüfen“ mit den alten
  Migration-Readiness-Attributen und Vergleichswerten.
- „Sichere Auto-Korrekturen nach EDIT + PROD“: die zusätzliche Funktion,
  die normale EDIT-Freigaben durch parallele PROD-Mutationen umgehen konnte.

**Bleibt aktiv:**
- RoomsCatalog-Gerätebearbeitung einschließlich Typen, KI-Rechten, KNX-Profilen,
  Livewerten, Batch-/Mehrfachbearbeitung und intelligenten Variablenvorschlägen.
- Erkennung neuer Geräte über den IP-Symcon-Baum und geführte Importe nach EDIT.
- Technische Geräteprüfung, Fehlerdiagnose/JSON-Export, bewusst manuelle
  fachliche Bestätigung, KNX-Statusvorschau und Reparatur nur in EDIT.
- HuaweiPV-Import, virtuelle Huawei-Gerätezeile, gruppierte Messwerte und
  KI-Lesefreigaben.
- RoomsCatalogEdit, RoomsCatalog PROD, vollständige EDIT→PROD-Übernahme,
  Differenzanzeige, individuelle Backups und Wiederherstellung.
- Bestehende Legacy-Felder in den Katalogdaten bleiben als
  Rückwärtskompatibilität gespeichert und werden nicht automatisiert gelöscht.

## Sicherung und Migration

Es werden **keine vorhandenen Backup-Skripte, Skriptinhalte oder Variablen in
der Benutzerinstallation gelöscht**. Alte Migrationsergebnisse sind keine
Berechtigung, heutige PROD-Daten zurückzusetzen. Für einen tatsächlichen
Rollback stehen die erhaltenen Backups und die normalen
RoomsCatalog-Restore-Funktionen zur Verfügung.

Nach dem Merge: IP-Symcon-Modul aktualisieren, in beiden Modulinstanzen
„Übernehmen“ ausführen und prüfen, dass „Navigation & Tabs“,
„Geräte bearbeiten (RoomsCatalog EDIT)“, HuaweiPV, technische Diagnose,
„EDIT NACH PROD ÜBERNEHMEN“ und Backup-Wiederherstellung weiterhin sichtbar
sind. Die alte Autokorrektur aus V3.3.11 sollte nicht mehr erscheinen.

Tests: vollständige PHP-CI, besonders
test_system_navigation_current_v363.php,
test_rooms_catalog_v339_technical.php,
test_rooms_catalog_filter_migration.php,
test_huaweipv_editor_v361.php.
