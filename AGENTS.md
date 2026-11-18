# IP-Symcon-Projektregeln

- Die Symcon-Dokumentation für KI-Agenten steht unter https://www.symcon.de/de/llms.txt.
- Für PHP-Funktionen zuerst https://www.symcon.de/de/llms/function-index.md bzw. für Modulfunktionen https://www.symcon.de/de/llms/function-index-modules.md lesen.
- In produktivem PHP und erzeugten IP-Symcon-Skripten keine Dezimalzahl-Literale mit fünf oder mehr aufeinanderfolgenden Ziffern verwenden. Die Symcon-Referenzprüfung kann sie als Objekt-IDs behandeln und fehlende Objekte melden.
- Zeiten, Größen und Grenzwerte als gleichwertige Berechnungen schreiben, etwa `10 * 1000`, `60 * 1000` oder `24 * 60 * 60`.
- Auch feste Objektzuordnungen im Programmcode als gleichwertige Berechnungen schreiben. Ihre numerischen Werte und die vorhandene Prüfung von ID, Typ und Herkunft erhalten.
- Skriptvorlagen und beim Installieren eingebettete Helfer gemeinsam prüfen. Für neue Skriptquellen `IAHSymconScriptSource::normalize()` verwenden. Katalogdaten weiterhin mit dem vorhandenen statischen Array-Format lesen und speichern.
