# RoomsCatalogConfigurator – geführter Geräteeditor (V3.3)

Der Dialog **Geräte bearbeiten → Hinzufügen** führt in vier Schritten durch die Anlage:

1. **Gerätetyp** wählen. Für neue Geräte stehen Licht, Dimmer, Jalousie, Heizung, Fensterkontakt, Schalter/Steckdose, Energiezähler, Bewegung, Lüftung und Sonstiges zur Verfügung. Das unveränderte Legacy-Datenmodell ist nur noch beim Bearbeiten älterer Geräte wählbar.
2. **Raum aus den in V3.1 gepflegten Räumen** auswählen. Der Raum-Key, Anzeigename und die Etage werden serverseitig aus `RoomsCatalogEdit` übernommen und nicht doppelt gepflegt. Den Gerätenamen eingeben; der Geräte-Key kann leer bleiben und wird dann automatisch erzeugt, bei Namensgleichheit mit einer laufenden Nummer ergänzt.
3. **IP-Symcon-Variablen** zuordnen. Bei einem Typwechsel blendet `onChange` die zum Typ gehörenden Variablen und Statuswerte ein und die anderen aus. Bereich und Gruppe werden aus dem Gerätetyp abgeleitet. Der endgültige Katalogpfad wird beim Speichern serverseitig bestimmt.
4. **KI-Freigaben** prüfen. Neu angelegte Geräte erhalten grundsätzlich `kiEnabled=false`, `kiReadOnly=true`. Erst nach Prüfung im EDIT-Katalog freigeben und anschließend über den bestehenden geprüften EDIT→PROD-Weg produktiv schalten.

Bestehende Legacy-Geräte behalten ihre bisherige Domain/Gruppe, solange der Gerätetyp nicht ausdrücklich geändert wird. Ein bewusster Raum-/Typwechsel verschiebt den Datensatz innerhalb des EDIT-Katalogs; unbekannte Zusatzfelder werden übernommen und der alte Eintrag entfernt. `RoomsCatalog` und Backups werden dabei nicht direkt verändert.

Die automatische Zuordnung verwendet unter anderem `licht/switches`, `licht/dimmers`, `jalousie`, `heizung`, `lueftung/fans`, `devices/tabs`, `sensoren` und `energie`. Für seltene oder benutzerdefinierte Gruppierungen können bestehende Legacy-Geräte weiterhin unverändert bleiben; eine spätere Erweiterung des Typkatalogs kann zusätzliche Pfade aufnehmen.
