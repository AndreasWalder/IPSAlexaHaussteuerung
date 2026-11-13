# V3.8.1 – Sprachbefehl testen

1. Im **RoomsCatalogConfigurator** das Panel **Sprachbefehl testen** öffnen.
   Dasselbe Panel liegt in **IPSAlexaHaussteuerung → Szenen und eigene Alexa-Befehle**.
   Bei genau einer Alexa-Instanz ist keine Instanzauswahl nötig.
2. Einen Satz wie am Echo eingeben und **SPRACHBEFEHL TESTEN** drücken. Ein
   Alexa-Intent, der KI-Haken und eine Auswahl der Testart sind nicht nötig.
3. Für unveröffentlichte eigene Sprachregeln optional **EDIT – Entwurf** wählen.
   Standard ist **PROD – veröffentlicht**. Hausfragen und Navigation verwenden
   weiterhin den produktiven RoomsCatalog und die produktive Navigation.

| Eingabe | Vorschau |
| --- | --- |
| „Wer ist Andrea?“ | Eigene Regel, Antwort und gegebenenfalls Bild. |
| „Welche Lichter sind eingeschaltet?“ | Aktuelle Rückmeldungen der freigegebenen Lichter, Antwort und verwendete Daten. Diese Abfrage braucht keinen OpenAI-Aufruf. |
| „Welcher Raum ist am wärmsten?“ | Antwort und lesende Abfragen der konfigurierten Haus-KI. Je nach Konfiguration sind auch Archiv- und Energiedaten verfügbar. |
| „Visu“ | Vorgesehenes Navigationsziel ohne Öffnen der Visualisierung. |
| „Jalousien schließen“ | Bei aktiver eigener Regel das vorgesehene Skript oder Gerät, ohne Start oder Schaltaktion. |
| „Schalte das Licht im Wohnzimmer ein“ | Klassischer Schaltbefehl als Vorschau. Das exakte Ziel lässt sich ohne die Alexa-Slots nicht in jedem Fall bestätigen. |

Die Antwort steht direkt im Panel. Unter **Vorschau (kopierbares JSON)**
stehen Trefferart, Antwort und je nach Route geplante Schritte oder verwendete
Daten. Ist die KI-Hausabfrage aktiviert, nutzen freie Hausfragen die dort
konfigurierte OpenAI-API. Die KI erhält in der Vorschau keine Schreibwerkzeuge.

Der Test arbeitet mit dem eingegebenen Text. Er testet nicht das Mikrofon,
das Alexa-Interaktionsmodell, APL-Klicks oder den aktuellen Gerätekontext eines
Echo. Eigene Regeln mit einem dedizierten Alexa-Intent lassen sich über ihren
hinterlegten gesprochenen Alias prüfen. Konfigurationsskripte werden als
statische Arrays gelesen, nicht ausgeführt. Es werden keine Geräte geschaltet,
Skripte gestartet oder Änderungen nach PROD übernommen.

Beim Öffnen der Instanzkonfiguration sind die Bereiche beider Module
weiterhin eingeklappt.
