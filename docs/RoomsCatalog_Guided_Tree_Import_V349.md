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

## Kopierbarer Objektbaum-Export

Nach einer Inventur drueckt **OBJEKTTREE + PROFILE ALS JSON ZUM KOPIEREN**. Der Export ergaenzt das bisherige Geraeteinventar um eine flache Objektliste mit `parentId`, `childIds` und vollstaendigem `path`. Instanzen enthalten Modul-ID/-Name, Status und Verbindungs-ID. Variablen enthalten Typ, aktuellen Wert, verwendete Standard-/Custom-Profile, Schreibaktionsmetadaten sowie Zeitstempel. Die Profiltafel fuehrt Praefix, Suffix, Wertebereich, Schrittweite, Nachkommastellen, Icon und Assoziationen mit Wert, Name, Icon und Farbe auf.

Der Export ist auf 5.000 Objekte und 24 Ebenen begrenzt und meldet `truncated`, wenn ein Limit erreicht wurde. Textwerte werden auf 240 Zeichen begrenzt. Variablen, deren Name, Ident oder Profil auf Passwort, Token oder Schlüssel hinweist, erhalten keinen Wert; Skriptinhalte werden nie gelesen. Das JSON kannst du hier einfuegen, damit neue Geraetetypen und Variablenzuordnungen aus konkreten Modul-, Profil- und Live-Merkmalen abgeleitet werden koennen.

### Shelly PM1 und Mehrkanal-Temperatur

Shelly-Instanzen mit `pm1_0_apower` und/oder `pm1_0_aenergy_total` werden als Energiezaehler vorgeschlagen, auch wenn ihr Name „Heizung“ enthaelt oder sie zusaetzlich `Reachable` melden. Leistung, Energie und Erreichbarkeit koennen als getrennte Alexa-Leserollen zugeordnet werden. Typische Fragen sind „Wie viel Leistung zieht die Heizungspumpe?“, „Wie hoch ist ihr Gesamtverbrauch?“ und „Ist die Heizungspumpe erreichbar?“.

Bei Shelly-Instanzen mit mehreren Temperaturkanaelen kann ein einzelnes Kataloggeraet nur einen Temperaturwert abbilden. Fuer getrennte Fragen wie „Wie warm ist es oben im Pumpenschacht?“ und „Wie warm ist der Verteilerkasten?“ die einzelnen Temperaturvariablen als zusaetzliche Suchwurzeln inventarisieren und als eigene Temperatursensoren mit passenden Namen/Raeumen nach EDIT uebernehmen. Der Heizungsname allein ist kein Thermostat: Ohne Solltemperatur darf kein Heizungstyp mit Steuerungsrolle angelegt werden.

Fuer den Shelly-Knotenpunkt gibt es dafuer **ALLE SHELLY-GERAETE + TEMPERATUREN NACH EDIT ANLEGEN**. Ein Klick scannt den eingestellten Knotenpunkt, legt eindeutige PM1-Geraete mit Wirkleistung, Gesamtenergie und Erreichbarkeit sowie jeden numerischen Temperaturkanal als einzelnen, nur lesbaren Sensor in EDIT an. Wenn an der konfigurierten Alexa-Instanz ein Archive Control eingestellt ist, aktiviert der Button Logging fuer diese Rollen. Danach genuegt **EDIT NACH PROD UEBERNEHMEN**. Es wird nichts geschaltet; bestehende PROD-Daten bleiben bis zu diesem zweiten Schritt unberuehrt. Archivfragen sind erst fuer Werte ab aktivierter Aufzeichnung moeglich.

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

