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

### IP-Symcon-IDs direkt in beiden Tabellen suchen

In **„Geräte und KI-Freigaben“** und **„Vorschau: Unterschiede EDIT zu PROD“**
gibt es jetzt zwei zusätzliche, **schreibgeschützte** und per
**Schnellfilter durchsuchbare** Spalten:

- **IPS-Objekt-IDs**: Alle konfigurierten Referenzen einer Zeile
  (beispielsweise `state=91001` und `toggle=91002` als
  `91001, 91002`). Die Schnellfilter-Suche nach `91002` zeigt
  damit direkt den passenden Raum, das Gerät und die Zeile.
- **IPS-Instanz-IDs**: Wenn die referenzierten Objekte unterhalb
  einer existierenden IP-Symcon-Instanz liegen, wird deren
  **tatsächliche Instanz-ID** angezeigt; mehrere Instanzen werden
  mit Komma getrennt. Eine Suche nach dieser Instanz-ID findet
  ebenfalls die betreffenden Einträge.

**Hinweis:** IP-Symcon-Variablen-IDs sind nicht dasselbe wie
Instanz-IDs. Ein Gerät kann mehrere Variablen und mehrere Instanzen
referenzieren. Existiert keine zuordenbare Instanz oder ist ein Objekt
gelöscht, bleibt die Instanz-Spalte leer; eine konfigurierte Objekt-ID
kann trotzdem gesucht werden. Beide Spalten werden nur beim Anzeigen
aus bestehenden Daten und dem IP-Symcon-Objektbaum berechnet.
Sie werden **nicht** in RoomsCatalogEdit/PROD gespeichert und verändern
die Mehrfachauswahl, die KI-Freigaben oder die DIFF-Berechnung nicht.

Die Schnellfilter sind **Spalten-Suchfelder** von IP-Symcon: Sie
filtern die angezeigten Tabellenzeilen nach dem eingegebenen Wert.
Die gewohnten Raum-, Domain-, Gruppen- und KI-Filter funktionieren
weiterhin und die Listen werden aktualisiert, ohne das Formular
vollständig zu schließen.

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

### Alexa-Fragen und Routerdiagnose

Der VoiceRouter liest seine Zustandsvariablen jetzt aus dem tatsaechlichen Pfad Diagnose/iahVoiceDiag, nicht mehr direkt unter der Modulinstanz. Die falsche Suche verursachte bisher helper_unavailable. Die KI selbst konnte trotzdem funktionieren.

Testfrage im Alexa-Skill: **Frage, welche Lampen sind eingeschaltet?** Der bereits vorhandene HomeAgentFrageIntent verwendet den Slot Frage. Wenn der Aufruf IP-Symcon erreicht, sieht man HomeAgentVoice.received und KIRoomsCatalog.tool mit get_states. Ein blosses GetHaus/Device=licht ist dagegen eine Navigationsanfrage und enthaelt keinen Fragetext.


Die Protokolle erscheinen in den IP-Symcon-Meldungen unter **Alexa** mit Präfix `KIRoomsCatalog.`. Der `HomeAgentFrageIntent` bleibt der sprachliche Eingang. Der Backendwechsel wird durch `KIVoice.backend.selected` protokolliert.

## Prüfstatus

PHP-/JSON-Syntaxtests und ein Mock-Test der Schutzmechanismen laufen in GitHub Actions. **Noch ausstehend** sind der IP-Symcon-Formular-Praxistest und das reale Schalten mit den konkreten Aktionsvariablen deiner Anlage. Vor der Produktivübernahme angepasste IP-Symcon-Skripte sichern. Dieses PR **nicht automatisch mergen**.
