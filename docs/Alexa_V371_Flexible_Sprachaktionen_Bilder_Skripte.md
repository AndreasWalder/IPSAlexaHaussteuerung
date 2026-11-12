# V3.7.1 – Allgemeine Alexa-Sprachaktionen mit Bildern und Skriptparametern

## Ziel

**Keine speziellen Jalousie-, Licht-, Roboter- oder Szenenfälle im Code nachbauen.**
Jede neue Sprachregel in **IPSAlexaHaussteuerung → V3.7 – Szenen und eigene Alexa-Befehle**
besitzt dieselben, zentral im `SystemConfigurationEdit.voiceRules` gespeicherten Bausteine:

- **Name und alternative Sprachsätze**, optional eigener Alexa-Intent.
- **Aktionstyp**: nur antworten, eigenes IP-Symcon-Skript starten oder eine
  Geräteszene mit vorhandenen RoomsCatalog-Schaltfähigkeiten ausführen.
- **Sprachantwort** für Alexa sowie davon unabhängiger **Kartentitel** und **Kartentext**.
- **Bild**: entweder eine öffentliche HTTPS-Adresse oder ein Bilddateiname unter
  `IP-Symcon/user/icons/` (z. B. `Abend.png`).
- **IP-Symcon-Skript auswählen** und optional ein festes JSON-Objekt
  mit Parametern hinterlegen.

## Beispiel: Ein neues Abendprogramm

Im Tabellen-Zahnrad bei **Eigene Befehle, Szenen und Antworten**:

| Feld | Beispiel |
| --- | --- |
| Name | Abendprogramm |
| Sprachbefehle | Guten Abend, Schönen Abend, Starte Abendprogramm |
| Typ | IP-Symcon-Skript |
| Skript | über den eingebauten IP-Symcon-Auswahldialog |
| Skriptparameter (JSON) | `{"Richtung":"Zu","Helligkeit":20,"LichtAn":true}` |
| Antwort | Ich starte das Abendprogramm. |
| Bilddatei | `Abend.png` |
| Bild-URL | leer |
| Kartentitel | Abendprogramm |
| Kartentext | Beleuchtung und Beschattung werden eingestellt. |
| Aktiv / Bestätigt | erst nach Durchsicht aktivieren |

Im gewählten PHP-Skript sind die Parameter über
`$_IPS['Richtung']`, `$_IPS['Helligkeit']` und
`$_IPS['LichtAn']` zugänglich. IP-Symcon startet das Skript über
`IPS_RunScriptEx($scriptId, $parameters)`.
Die Werte stammen ausschließlich aus dem geprüften PROD-Katalog:
**Alexa/Freitext/KI können weder Skriptcode noch Parameter frei verändern.**

Das Skript besitzt die volle IP-Symcon-Ausführungsberechtigung und muss selbst
alle Gerätesicherheits- und Berechtigungsprüfungen durchführen. Die Sprachregel
ist deshalb erst nach ausdrücklicher Bestätigung aktiv. Skriptstart bedeutet
nicht, dass alle Geräte schon fertig gesteuert wurden, da RunScriptEx parallel startet.

## Bilddarstellung

**Alternative A – Bild auf dem IP-Symcon-Server**

1. Das Bild als `Abend.png` unter `IP-Symcon/user/icons/` bereitstellen.
2. In der zentralen Sprachregel **Bilddatei = Abend.png** eingeben.
3. Das Modul verwendet den bereits bestehenden WebHook
   `/hook/iah-alexa-icons` samt konfiguriertem Token und HTTPS-BaseURL.
   Keine neue Bild-Sonderlogik und keine hartcodierte Datei im PHP-Quelltext.
4. Alexa benötigt eine von Amazon erreichbare HTTPS-Adresse;
   auf der lokalen IP-Symcon-Konsole sichtbare Bilder genügen allein nicht.

**Alternative B – öffentliches Bild**

In **Bild-URL** eine HTTPS-Adresse angeben und **Bilddatei** leer lassen.
Beide Felder dürfen nicht gleichzeitig ausgefüllt werden.

Optional **Kartentitel** und **Kartentext** setzen. Ohne Angaben
werden automatisch Regelname und Sprachantwort verwendet.
Die Karte wird nur angezeigt, wenn die zugehörige Aktion erfolgreich
gestartet wurde. Bilder werden beim Veröffentlichen nicht heruntergeladen.

## Beispiel Jalousien (ohne Sonderbehandlung)

Eine neue Regel *Jalousien öffnen* kann auf ein von dir ausgewähltes,
wiederverwendbares Skript zeigen, das den Parameter
`{"Richtung":"Auf"}` erwartet. Für *Jalousien schließen* kannst du
**dasselbe Skript** wählen, aber `{"Richtung":"Zu"}` hinterlegen,
mit zwei verschiedenen Bildern und Antworten.
Alternativ kann jede Szene aus regulären RoomsCatalog-Geräteaktionen bestehen.

Damit gibt es keine Jalousie-spezifischen Codepfade in der neuen
Sprachregel-Engine. Die **alten** Jalousie-Renderer bleiben aus
Abwärtskompatibilität erhalten; du kannst sie nach einem erfolgreichen
Vergleich schrittweise durch eigene, explizit geprüfte Regeln ersetzen.
Ein Renderer, der komplexe interne Payloads erwartet, kann nicht einfach
ohne Anpassung als neues Skript verwendet werden – dafür ein eigenes
Standard-Skript mit klaren Parametern wählen.

## Sicherheit, Freigabe und Änderungen

- Maximal 12 feste Parameter; nur String, Zahl, Bool oder null.
- Keine PHP-Ausdrücke, verschachtelte JSON-Objekte, freien IPS-Systemfelder
  oder Pfadangaben zur Bilddatei.
- Einmalige Prüfung, verifiziertes Backup und ausdrückliche
  **EDIT → PROD**-Freigabe wie bei V3.7.
- Bereits vorhandene Regeln ohne Bilddatei/Skriptparameter bleiben gültig.
- Die Alexa Developer Console muss die neuen Sprachsätze weiterhin über
  dein Skill-Interaktionsmodell erfassen.

Automatische Tests:
`tests/test_voice_rules_generic_v371.php` und
`tests/test_voice_rules_generic_editor_v371.php`.
