# V3.3.10 – Katalogstand und technische Prüfursachen

Auslöser ist der Widerspruch zwischen zwei aufeinanderfolgenden Berichten:
zuerst 167/175 typisiert, 8 Legacy und 59 EDIT/PROD-Unterschiede;
danach 67 Legacy, 0 technisch plausibel und 0 EDIT/PROD-Unterschiede.

## Erkenntnis

Beide Prüfungen verwenden dieselbe Katalogparserlogik, die technische
Analyse verlangt allerdings zusätzlich ausdrückliche Zuordnungen in
ai.variables, gültige IP-Symcon-Variablen, Instanzkontext, Typen,
Rollenbezeichnungen und (bei Jalousien) die Statuswerte.

Beide Berichte sind Momentaufnahmen. Änderungen, Wiederherstellungen
oder Übernahmen von RoomsCatalogEdit bzw. RoomsCatalog PROD können die
Zahlen verändern. Die Prüfung darf daraus niemals automatisch ableiten,
dass Geräte entfernt oder bestätigt wurden.

## Neue Quellenanzeige

Unter **Geräteprüfung & Migrationsabschluss** nach **ALLE GERÄTE
TECHNISCH PRUEFEN**:

- EDIT-/PROD-Skript-IDs und gekürzte SHA-256-Prüfsummen.
- Aktuell aus EDIT gelesene Zahlen für typisiert und Legacy. Wenn ein
  früherer Migrationsbericht vorhanden ist, steht der alte Wert
  zusammen mit dem neuen Wert und dem Hinweis auf eine Quelländerung.
- Erklärung, ob Skript-IDs identisch sind, Skriptinhalte identisch sind
  oder nur die analysierten Gerätelisten keinen Unterschied aufweisen.
- Häufigste technische Gründe, warum kein Gerät „plausibel“ ist:
  fehlende ai.variables, unklare KNX-Namen, mehrdeutige Kandidaten,
  fehlende Profile, Statuswertzuordnung, nicht vorhandene Variable
  oder fehlende Schreibaktion.

## Bewertungspräzisierung

Eine fehlende **typisierte Pflichtrolle** ist „Manuell prüfen“ und
keine automatisch defekte IPS-Hardware. Die Sammelbestätigung bleibt
dabei gesperrt. Fehlende IPS-Variablen und falsche Datentypen bleiben
„Ungültig“.

Legacy-Felder (state, wert, ist, soll) werden als mögliche
Zuordnungskandidaten identifiziert, nicht als bestätigte ai.variables.

Sind EDIT und PROD dieselbe Skript-ID, verweigert die Prüfung jedes
Schreiben im Rahmen der technischen/manuellen Sammelübernahme.

## Nächster Schritt auf dem echten Server

1. Das Modul aktualisieren und die Instanz anwenden.
2. **MODELL, IPS-VARIABLEN UND MIGRATION PRUEFEN** ausführen.
3. **ALLE GERAETE TECHNISCH PRUEFEN** ausführen.
4. Die neuen Zeilen **DATENQUELLEN** und
   **HAEUFIGSTE PRUEFURSACHEN** vergleichen.

Bitte bei abweichenden Skriptständen keine Sammelbestätigung und keine
Freigabe EDIT→PROD vornehmen, bevor die Ursache geklärt ist.

Die Auswahlwerte „Alle“, „Bewegung“, „Nicht ändern“, „Nur eindeutige
Vorschläge übernehmen“ und „Unverändert“ sind Filter-/Sammelbearbeitung,
nicht Bestandteile der technischen Prüfung.
