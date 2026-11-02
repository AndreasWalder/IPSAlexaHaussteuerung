# RoomsCatalogConfigurator V3.3.1 – Geräteinventur für alle Typen

Ziel vor V3.4: **alle Geräte innerhalb der ausdrücklich ausgewählten IP-Symcon-Wurzeln erfassen, ihre Datenqualität messen und offene Zuordnungen sichtbar machen.** 100 % bedeutet hier nicht, dass eine KI jeden unbekannten Zustand zweifelsfrei erraten kann. Es bedeutet, dass die Inventur vollständig durchgelaufen ist, jede gefundene Geräteinstanz zugeordnet wurde, die mindestens nötigen Rückmeldungen vorhanden sind und der Nutzer die Bedeutung am Gerät bestätigt hat.

## Ablauf

1. Im `RoomsCatalogConfigurator` die Kategorie(n) für Geräte festlegen und die Konfiguration übernehmen.
2. **IP-SYMCON-GERAETE INVENTARISIEREN** anklicken. Die Suche bleibt auf die konfigurierten Wurzeln beschränkt und ändert keine Katalogdaten.
3. Die Zusammenfassung unterscheidet bekannte Geräte, neue Kandidaten, ungeklärte Typen/Räume sowie fehlende Rückmeldungen. Wird die Begrenzung von 2.500 Objekten, 200 neuen Kandidaten oder der Suchtiefe erreicht, wird ausdrücklich **keine vollständige Inventur** gemeldet. Bei großen Installationen die Suchbereiche entsprechend aufteilen.
4. Neue Kandidaten prüfen und nur bewusst nach `RoomsCatalogEdit` übernehmen. Neue Einträge starten mit `kiEnabled=false` und `kiReadOnly=true`.
5. Unter **Bisherige Geräte ins neue Datenmodell übernehmen** eine Legacy-Analyse starten. Die Vorschläge aus Bereich, Instanztyp und Variablenprofilen überprüfen, markieren und nur nach EDIT übernehmen. Bestehende Legacy-Felder sowie Lese-/Schreibfreigaben bleiben dabei erhalten. Das Modul legt `ai.type`, sichere Variablen-/Profilzuordnungen, Quellmetadaten und den Prüfstatus `pending` ergänzend an.
6. Im Geräteeditor die zugeordneten Variablen und Zustandsbedeutungen prüfen. Mit **Alle Variablen und die Wertbedeutung am echten Gerät geprüft** die Zuordnung ausdrücklich bestätigen.
7. **DATENSTAND UND OFFENE PUNKTE PRUEFEN** zeigt fehlende Geräte, Typen, Rückmeldungen, Statusbedeutungen und Bestätigungen. Erst wenn die Liste leer ist und der Scan nicht abgeschnitten wurde, kann der gewählte Bereich als bereit für V3.4 gelten. Die Livefunktion ist zusätzlich am echten Gerät zu testen.

## Unterstützte Geräteklassen

`light`, `dimmer`, `blind`, `heating`, `window`, `switch`, `energy`, `motion`, `fan`, `generic`.

Eine `generic`-Zuordnung gilt zunächst als **ungeklärt**, bis ein fachlicher Typ festgelegt wurde. Für jeden Typ existieren Mindestanforderungen an Rückmeldungen. Zusätzliche, noch nicht zugeordnete IP-Symcon-Variablen werden im erkannten Inventar aufgelistet und beim Import als Variablen-IDs unter `ai.source.unmappedVariableIds` vermerkt.

## Erkennung

- `IPS_GetInstance` liefert die Modul-ID und den Modulnamen, z. B. KNX Rollladen.
- `IPS_GetVariable` liefert Typ, Profil/Darstellung, Aktion und Aktualisierungszeit.
- `IPS_GetVariableProfile` liefert Wertebereiche, Einheiten und Assoziationen. Aus eindeutigen Paaren wie `0=Offen`, `1=Geschlossen` kann eine `ai.stateMapping` entstehen.
- Für Jalousien unterscheidet das Schema `status`, `action`, `position`, `targetPosition`, `tilt`, `moving` und `control`. `Aktion` ist keine Sollposition, und ein Fahrstatus darf nicht aus einem beliebigen Statusfeld geraten werden.
- Ein nachweislich bestätigtes Geräte-Mapping kann als Vorlage für andere Instanzen mit **derselben ModuleID** und passender Variablen-Identifikation benutzt werden. Diese Vorlagen werden jedes Mal aus dem EDIT-Katalog abgeleitet – ohne eine weitere separat gepflegte Datei. Bei widersprüchlichen Vorlagen oder nicht eindeutigen Zuordnungen bleibt das Ergebnis offen.
- Keine aktuellen Variablenwerte, Kennwörter oder Zugangs-URLs werden ins Inventar oder die `ai.source`-Metadaten kopiert. Livewerte bleiben in IP-Symcon.

## Kompatibilität und Sicherheitsgrenzen

`RoomsCatalog`, `RoomsCatalogEdit` und `RoomsCatalog_Backup` bleiben bestehen. Der Import schreibt nur in EDIT. Bisherige Felder können weiterhin bestehen bleiben; das erweiterte `ai.variables`-Modell ist Grundlage für V3.4. Vor einer vollständigen Ablösung der Legacy-Felder müssen die Alexa-Renderer und der `KIHouseController` gezielt umgestellt und gegen den echten IPS-Bestand getestet werden.

Ein positiver Vollständigkeitsbericht bezieht sich ausschließlich auf die konfigurierten Suchwurzeln. Er bescheinigt keine 100-prozentige physikalische Richtigkeit der Messwerte; dafür sind die Liveprüfung und eine fachliche Bestätigung erforderlich. Die Inventar-Funktion **INVENTAR ALS JSON ZUM KOPIEREN** ermöglicht die gemeinsame Fehlersuche ohne Livewerte.

**Wichtig:** Diese Version enthält eine lokale, deterministische Analyse. Sie schickt nicht ungefragt den vollständigen IP-Symcon-Baum an einen Cloud-KI-Dienst. Eine optionale KI-Bewertung unklarer Metadaten lässt sich später mit ausdrücklicher Freigabe und Datenschutzkontrolle ergänzen; eine KI-Antwort darf niemals allein eine Schreibfreigabe erzeugen.
