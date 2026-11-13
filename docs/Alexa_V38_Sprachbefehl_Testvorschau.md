# V3.8 – Sprachbefehl testen

1. Im **RoomsCatalogConfigurator** das Panel **Sprachbefehl testen** öffnen.
   Dasselbe Panel liegt in **IPSAlexaHaussteuerung → Szenen und eigene Alexa-Befehle**.
   Bei genau einer Alexa-Instanz wird sie im Konfigurator vorausgewählt.
2. Einen Satz wie „Wer ist Andrea?“ oder „Jalousien schließen“ eingeben und
   **EDIT – Entwurf** oder **PROD – veröffentlicht** auswählen. Bei einem
   eigenen Alexa-Intent ohne Satz kann stattdessen der Intentname eingegeben werden.
3. **SPRACHBEFEHL TESTEN** zeigt die getroffene Regel, den Alias oder Intent, den
   vorgesehenen Antworttext, die Bilddatei oder Bild-URL und die geplante Aktion
   als kopierbares JSON. Bei einer Szene werden die Geräteschritte gegen die
   Freigaben im produktiven RoomsCatalog geprüft, auch beim Test von EDIT.
4. **Bei Bedarf auch KI testen** nutzt nach einem fehlenden exakten Treffer die
   konfigurierte KI-API, sofern die KI-Spracherkennung der Alexa-Instanz aktiv
   ist. Nur Antwort- und Messwertregeln kommen dafür infrage. Beim EDIT-Test
   können dafür auch die aktivierten EDIT-Fragealiasse verwendet werden.

Der Test prüft die eigene Regelzuordnung hinter Alexa, nicht die
Echo-Spracherkennung, das Skill-Interaktionsmodell oder weitere Alexa-Routen.
Es werden keine Skripte gestartet, Variablen geschaltet, Messwerte gelesen
oder Änderungen veröffentlicht. Antwortvorlagen für Messwerte zeigen daher
`{value}`, Skriptparameter nur ihre Namen. Dynamische Konfigurationsskripte
werden in der Vorschau nicht ausgeführt.

Beim Öffnen der Instanzkonfiguration sind die Bereiche beider Module
eingeklappt. Der einmalige KNX-Assistent für alte Jalousie-Statuswerte wurde
entfernt. Gespeicherte Zuordnungen und Backups bleiben erhalten.
**RoomsCatalog überprüfen** meldet weiterhin widersprüchliche Statuswerte.
