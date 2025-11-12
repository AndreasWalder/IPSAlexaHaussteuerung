IPSymcon-Bewaesserung
Bewässerung Multi-Zone
Ein modernes, flexibles IP-Symcon-Modul zur Steuerung von bis zu 10 Haupt-Bewässerungssträngen und einer Nebenstelle (Zone 11) – mit direkter KNX-Anbindung, Einzel- und Automatikbetrieb, Prioritäten, editierbaren Startlaufzeiten, Pumpensteuerung und übersichtlicher Statusanzeige im WebFront.

✨ Features
Bis zu 10 separat steuerbare Hauptzonen + 1 Nebenstelle
Jede Zone frei benennbar (Konfig-Formular)
Manueller Modus: Sofortiges EIN/AUS je Zone
Automatik-Modus:
Ablauf nach einstellbarer Priorität
DauerStart: Startwert für Laufzeit jeder Zone, direkt im WebFront einstellbar
Beim Automatikstart wird Dauer automatisch aus DauerStart übernommen
Nacheinander-Schaltung (immer nur eine Prio-Gruppe läuft gleichzeitig)
Manueller „Schritt weiter“-Befehl: beendet aktuelle Prio und startet direkt die nächste
Pumpensteuerung integriert:
Wird im Automatikbetrieb automatisch mit eingeschaltet
Kann manuell zugeschaltet werden
Status- und Infoanzeige wie die Zonen
Statusanzeigen je Zone und Pumpe:
Zeigt an, wann die nächste Bewässerung startet oder wie lange sie noch läuft
Meldungen wie „Automatik erledigt“ oder „Start in x min“
Kompatibel mit KNX (direktes Schalten per verlinkter Bool-Variable)
Timer-gesteuert (keine zyklischen Ereignisse notwendig)
WebFront-tauglich – alle Variablen optimal beschriftet
Dynamische Instanzkonfiguration – alles komfortabel über das Formular konfigurierbar

🛠️ Installation
Repository klonen in deinen IP-Symcon-Module-Ordner (z. B. /var/lib/symcon/modules/):

git clone https://github.com/AndreasWalder/IPSBewaesserung
Symcon-Dienst neu starten.

Instanz anlegen:
Objektbaum → Instanz hinzufügen → „Bewässerung Multi-Zone“

Zonenanzahl, Namen, Aktoren und Pumpe in der Instanzkonfiguration festlegen.

PHP Skript Timer anlegen und auf 2 Sekunden stellen. Es braucht zum Schluss noch einen Auto Timer, der händisch angelegt werden muss.

<?php
IPS_RequestAction(Instanz Objekt Id, "Evaluate", 0);

image

⚙️ Konfiguration
Jede Zone (Strang) bietet folgende Variablen im WebFront:

Name (String): Frei wählbar (z. B. „Rasen Ost“)
Manuell (Bool): Sofortiges EIN/AUS (übersteuert Automatik)
Automatik (Bool): Automatik-Ablauf aktivieren/deaktivieren
DauerStart (Int): Standardlaufzeit (Minuten) – wird beim Automatikstart in Dauer übernommen
Dauer (Int): Aktuelle Laufzeit (Minuten) – wird von der Automatik heruntergezählt
Priorität (Int): Reihenfolge der Bewässerung
Status (Bool): Ein/Aus-Status (nur Anzeige)
Info (String): Zeigt Restlaufzeit, Fehler oder Startzeit an

Pumpe:
Pumpe Manuell (Bool): Manuelle Zuschaltung der Pumpe
Pumpe Status (Bool): EIN/AUS-Status der Pumpe (automatisch und manuell)
Pumpe Info (String): Statusmeldung oder Fehler

Die zu schaltenden KNX-Bool-Variablen werden in der Instanzkonfiguration zugeordnet.

💡 Beispiel-Anwendungsfall
Zone 1: „Hecke Nordseite“ – DauerStart 20 min – Priorität 1 – Automatik ✅
Zone 2: „Blumenbeet“ – DauerStart 15 min – Priorität 2 – Automatik ✅
Zone 3: „Rasen Ost“ – DauerStart 30 min – Priorität 3 – Automatik ❌

Automatik-Ablauf:
Start Zone 1 (20 min)
Danach Zone 2 (15 min)
Zone 3 wird übersprungen (Automatik deaktiviert)
Mit „Manueller Schritt“ kann Zone 1 sofort beendet und Zone 2 gestartet werden.
Beim nächsten Automatikstart werden alle Dauer-Werte wieder aus den DauerStart-Werten gesetzt.

🧑‍💻 Autor & Lizenz
Erstellt von Andreas Walder
MIT-Lizenz (LICENSE liegt bei)

🛠️ Weiterentwicklung
Mit Forks, Issues & Pull Requests sehr gerne gesehen!
Fehler, Ideen und Verbesserungen bitte als GitHub Issue oder PR einreichen.

Letztes Update: 2025-08-11 – DauerStart-Variablen, Automatik-Übernahme der Startwerte, manueller Schritt, optimierte Ablaufsteuerung.
