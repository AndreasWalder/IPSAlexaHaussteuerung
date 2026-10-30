# KI + RoomsCatalog (direkte Alexa-Haussteuerung)

Die bestehende Alexa-/APL-Steuerung bleibt erhalten. Der zusätzliche Intent `HomeAgentFrageIntent` verwendet jetzt standardmäßig **KI + RoomsCatalog** als Datenquelle. **Home Agent (MCP)** lässt sich alternativ weiterhin auswählen.

## Einstellungen

In der Alexa-Haussteuerungsinstanz:
- **KI-Hausfragen beantworten** einschalten.
- **KI-Verarbeitung**: `KI + RoomsCatalog` (Standard), `KI + Home Agent` oder `Klassisch`.
- Den bereits vorhandenen OpenAI-API-Key und das Modell hinterlegen.
- **KI darf explizite Schaltbefehle ausführen**: zunächst deaktiviert lassen; später nur bewusst aktivieren.
- Gewünschte kurze Sprachantwort auf 20 Wörter einstellen.

Bei `Klassisch` wird der neue KI-Sprachkanal deaktiviert; vorhandene normale Alexa-Kommandos bleiben erhalten.

## RoomsCatalogConfigurator

Der vorhandene Konfigurator zeigt primär den **EDIT-Katalog**. Dort stehen eine kompakte Gerätetabelle und ein Bearbeitungsdialog zur Verfügung.

**Pro unterstütztem Gerät**:
- `Mit KI` (`kiEnabled`): für Statusabfragen und mögliche Schaltbefehle sichtbar.
- `Nur abfragen` (`kiReadOnly`): alle Schreibaktionen ausdrücklich sperren.

**Sicherheit für bestehende Geräte:** Wenn die Flags noch nicht im Catalog hinterlegt sind, gelten unterstützte Geräte als für KI-Abfragen sichtbar, aber **nicht schaltbar**. Ein Schaltbefehl wird erst ausgeführt, wenn sowohl die globale Schreibfreigabe als auch `kiEnabled=true` und `kiReadOnly=false` für dieses Gerät gesetzt sind. Zusätzlich muss die Variable tatsächlich eine IP-Symcon-Aktion besitzen. Es werden keine direkten `SetValue`-Schreibzugriffe verwendet.

Zum Bearbeiten:
1. Oben EDIT-Skript und produktiven RoomsCatalog zuordnen.
2. Raum-/Bereich-/Gruppenfilter oder KI-Filter verwenden.
3. Ein Gerät im Dialog bearbeiten, oder mehrere markieren und per Schaltfläche KI-Freigaben setzen.
4. Änderungen zunächst nur **in RoomsCatalogEdit** speichern.
5. **KATALOG PRUEFEN** und **DIFF-VORSCHAU** verwenden.
6. Erst danach bewusst **EDIT NACH PROD UEBERNEHMEN**; vorher wird automatisch eine Sicherungskopie des produktiven RoomsCatalog-Skripts erzeugt.

Beim Klonen werden alte Aktions-/Status-IDs zurückgesetzt; neue Klone sind für KI zunächst nicht sichtbar.

## Unterstützte KI-Geräte

| Bereich | Lesen | Schreiben |
| --- | --- | --- |
| Lichtschalter | Ja | Bei Freigabe |
| Dimmer | Ja | Bei Freigabe innerhalb des Wertebereichs |
| Heizung | Ja | Sollwert bei Freigabe, Sicherheitsbereich |
| Jalousien | Ja | **Nein** (Positions-/Richtungslogik noch nicht geprüft) |
| Lichtstatus/Lux | Ja | Nein |
| Lüfter | Ja | Bei Freigabe |
| Navigation, Tabs, Skripte, unstrukturierte Szenen | Nein | Nein |

**Wichtige Grenzen:** Der neue Controller kennt nur den oben genannten strukturierten Teil des RoomsCatalog. Er verwendet keine freie PHP-Ausführung und keine vom Modell erfundenen Variablen-IDs. Mehrdeutige Gerätezuordnungen führen nicht zu einer Einzelaktion. Gruppenaktionen sind nur für explizite **alle**-Befehle und maximal 15 Schalter/Lüfter vorgesehen.

## Diagnose

Die Protokolle erscheinen in den IP-Symcon-Meldungen unter **Alexa** mit Präfix `KIRoomsCatalog.`. Der `HomeAgentFrageIntent` bleibt der sprachliche Eingang. Der Backendwechsel wird durch `KIVoice.backend.selected` protokolliert.

## Prüfstatus

PHP-/JSON-Syntaxtests und ein Mock-Test der Schutzmechanismen laufen in GitHub Actions. **Noch ausstehend** sind der IP-Symcon-Formular-Praxistest und das reale Schalten mit den konkreten Aktionsvariablen deiner Anlage. Vor der Produktivübernahme angepasste IP-Symcon-Skripte sichern. Dieses PR **nicht automatisch mergen**.
