# V3.4.9 – IP-Symcon-Knotenpunkt nach RoomsCatalogEdit importieren

## Gefuehrter Ablauf

Im RoomsCatalogConfigurator: **Geraete erkennen, inventarisieren und reparieren → IP-Symcon-Geraeteinventur**.

1. Im IP-Symcon-Objektbaum den **Knotenpunkt** waehlen, beispielsweise **KNX → Sicherheit → Bewegung**. Nur die ausgewaehlte Kategorie, Instanz oder Variable und ihre untergeordneten Objekte werden inventarisiert. Weitere Wurzel-IDs sind optional.
2. **Bereich** und **Gruppe** auswaehlen, z.B. **sicherheit** und **bewegung**. Leer lassen bedeutet zentrale Typvorlage. Beide Werte werden gemeinsam angegeben. Fuer heizung/jalousie gelten die zentralen Spezialpfade.
3. **Geraetetyp** festlegen oder anhand des Modulnamens, der Variablennamen/-Idents und Profile erkennen lassen. Bei KNX-Bewegungsmeldern ist **motion (Bewegungsmelder)** sinnvoll. Raum optional fuer alle festlegen, sonst eindeutige Uebereinstimmungen mit Raumschluesseln, Namen und Aliases verwenden; Unklarheiten bleiben zur Auswahl.
4. **Optionaler KI-Aufruf:** „KI fuer unklare Vorschlaege“ aktivieren und die bestehende Alexa-Modulinstanz mit OpenAI-Key auswaehlen; es wird kein zweiter Key gespeichert. Gesendet werden nur Namen, Idents, Profile, Datentypen und Assoziationsbeschriftungen der gewaehlten Unterstruktur, keine IP-Symcon-IDs, Rohwerte oder Secrets. Bei API-Fehlern werden lokale Vorschlaege behalten. Es koennen API-Kosten anfallen.
5. Nach Speichern der Einstellungen (**Uebernehmen**) den Button **KNOTENPUNKT DURCHSUCHEN UND GERAETE VORSCHLAGEN** druecken. In der Tabelle Geraetenamen, Raum, Typ, Bereich und Gruppe korrigieren. Die IPS-ID oeffnet die gerateseitigen Zuordnungsdetails.
6. Nur gewuenschte Eintraege markieren (wahlweise „EINDEUTIGE MARKIEREN“) und **MARKIERTE GERAETE NACH EDIT UEBERNEHMEN** ausfuehren. Nur dieser Schritt schreibt neue Geraete nach RoomsCatalogEdit. PROD bleibt unveraendert.
7. Zugeordnete Istvariablen am echten Geraet pruefen, gezielt KI-Lesen erlauben und erst dann die geprueften EDIT-Aenderungen nach PROD uebernehmen.

## KNX-Bewegungsmelder

Auch eine Instanz mit nur einer Boolean-Value-Variable oder mehrere einzelne Bewegungsvariablen unter einer Kategorie werden als eigene Sensoren erkannt, nicht als ein grosses Geraet. Eindeutige nicht beschreibbare Statusvariablen werden als **motion** vorgeschlagen; keine KI-Aktionen. Beispiele wie „EG Buero Bewegung“ werden dem passenden Raum zugeordnet, sofern eindeutig.

Neue Eintraege haben das von KIHouseController lesbare **ai.version=1**, einen typisierten ai.variables.motion-Wert, Quelle/Variablen-Metadaten, **kiEnabled=false, kiReadOnly=true** und fachlichen Review-Status pending. Die KI liest sie erst nach ausdruecklicher Freigabe und EDIT-nach-PROD.

**Letzte Bewegung:** Das IP-Symcon-Feld VariableChanged ist die letzte Aenderung, eventuell auch die Rueckkehr von true auf false. Ein verlaessliches „letztes Mal Bewegung erkannt“ braucht eine Ereignishistorie (z.B. IPS Archiv) oder persistente Flankenspeicherung. Der Import erzeugt noch keinen solchen Ereignislogger. Aktueller Bewegungsstatus kann nach Freigabe gelesen werden.

## Sicherheit

Der Scanner verwendet ausschliesslich explizit gewaehlte Knotenpunkte und beschraenkte Baumtiefe/Anzahl. Er schreibt keine KNX-Werte, ueberschreibt keine bestehenden Geraete und weist KI-Vorschlaegen niemals automatisch Schreibrechte zu. Vor dem EDIT-Import werden die Vorschau, der Scan-Scope, die Auswahl und der EDIT-Skripthash auf Aenderungen geprueft. Bei Aenderung wird neu gescannt.

## Implementierung

- RoomsCatalogConfigurator/libs/GuidedDiscovery.php – lokaler Raum-/Profilsensor-Erkenner mit Typvalidierung.
- RoomsCatalogConfigurator/module.php – UI, Scanner, optionale OpenAI-Analyse, EDIT-Vorschau und Importsicherheit.
- tests/test_rooms_catalog_guided_tree_v349.php – KNX-Sensoren, direkte Variablen, Dubletten, Raumzuordnung und EDIT-only Regressionstest.
