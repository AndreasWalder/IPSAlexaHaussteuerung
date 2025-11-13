<?php
// Normalizer.php
return [
  // 1) Klein-/Trimm-Helfer
  'lc' => static function($v){
    return is_string($v) ? mb_strtolower($v,'UTF-8') : '';
  },

  // 2) Dezimalwörter wie "komma"/"punkt" zwischen Ziffern → Komma
  //    Übergib hier einfach den Regex aus dem Lexikon (PATTERN['decimal_words'])
  'decimal_words' => static function(string $s, string $pattern): string {
    return preg_replace($pattern, ',', $s);
  },

  // 3) Slug/Key für Räume (ä→ae, ß→ss, Sonderzeichen→_, trim)
  'room_slug' => static function (?string $s): string {
    if (!is_string($s) || $s === '') return '';
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','Ä'=>'ae','Ö'=>'oe','Ü'=>'ue']);
    $s = preg_replace('/[^a-z0-9]+/u', '_', $s);
    return trim($s, '_');
  },

  // 4) Token-Normalisierung (ä→ae, ß→ss, Sonderzeichen→Leerzeichen, Mehrfachspaces glätten)
  'token_norm' => static function(string $s): string {
    $s = mb_strtolower(trim($s),'UTF-8');
    $s = strtr($s,['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    $s = preg_replace('/[^a-z0-9]+/u',' ',$s);
    return trim(preg_replace('/\s{2,}/u',' ',$s));
  },

  // 5) Action-Normalisierung …
  'action' => static function($action, $device, $alles, $object, $room){
    $a   = mb_strtolower(trim((string)$action), 'UTF-8');
    $a   = str_replace(['schliessen','lueften','oeffnen'], ['schließen','lüften','öffnen'], $a);
    $hay = mb_strtolower(trim(($action ?? '').' '.($device ?? '').' '.($alles ?? '').' '.($object ?? '').' '.($room ?? '')), 'UTF-8');

    // Exit / Zurück
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(exit|zurück)(?!\pL)/u', $hay))    return 'zurück';
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(ende|fertig|hauptmenü)(?!\pL)/u',$hay)) return 'ende';

    // "1"/"eins" → EIN (nur wenn nicht "prozent" erwähnt wird)
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(1|eins)(?!\pL)/u', $hay)
        && !preg_match('/(*UTF8)(*UCP)(?<!\pL)prozent(?!\pL)/u', $hay)) {
      return 'ein';
    }
    // Optional: "0"/"null" → AUS (ebenfalls ohne "prozent")
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(0|null)(?!\pL)/u', $hay)
        && !preg_match('/(*UTF8)(*UCP)(?<!\pL)prozent(?!\pL)/u', $hay)) {
      return 'aus';
    }

    // EIN/AUS früh priorisieren (erkennt auch "... büro an")
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(ein|einschalten|ein\s*schalten|an|anmachen|anschalten|mach(e)?\s+an)(?!\pL)/u', $hay)) return 'ein';
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(aus|ausschalten|aus\s*schalten|ausmachen|abschalten|mach(e)?\s+aus)(?!\pL)/u', $hay))   return 'aus';

    // Temperatur/„stellen“
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(stell(e|en)?|änder(e|n)|aender(e|n)|setze|setzen)(?!\pL)/u', $hay) ||
        (preg_match('/(*UTF8)(*UCP)(?<!\pL)auf\s+\d{1,3}(?:[.,]\d{1,2})?(?:\s*grad)?(?!\pL)/u', $hay) &&
         (strpos($hay,'grad')!==false || strpos($hay,'temperatur')!==false))) return 'stellen';

    // Jalousie & Co …
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(fahre|fahren)(?!\pL)/u', $hay))    return 'fahren';
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(öffnen|aufmachen|auf|auffahren|hochfahren|open|fahre\s+auf)(?!\pL)/u', $hay)) return 'öffnen';
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(schließen|zumachen|zu|zufahren|zu\s+fahren|niederfahren|close|fahre\s+zu)(?!\pL)/u', $hay)) return 'schließen';
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(stop|stopp|halt|anhalten)(?!\pL)/u', $hay)) return 'stop';
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(beschatten|beschattung|schatten)(?!\pL)/u', $hay)) return 'beschatten';
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(lüften)(?!\pL)/u', $hay))          return 'lüften';
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(mitte|mittig|halb|mittelstellung)(?!\pL)/u', $hay) ||
        preg_match('/(*UTF8)(*UCP)50\s*%(?!\pL)/u', $hay)) return 'mitte';
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(dim(m|me|men)?|regle(n)?)(?!\pL)/u', $hay)) return 'dimmen';
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(lesen|auslesen|abfragen|frage|status)(?!\pL)/u', $hay)) return 'lesen';

    return $a ?: '';
  },

  // 6) "an/aus" am Ende eines Room-Textes heraustrennen
  'extract_power_from_room' => static function (?string $room): array {
    $room = is_string($room) ? trim($room) : '';
    if ($room === '') return [$room, null];

    if (preg_match('/\s+(an|ein|einschalten|anschalten)\s*[.!?,;:]*$/iu', $room)) {
      return [trim(preg_replace('/\s+(an|ein|einschalten|anschalten)\s*[.!?,;:]*$/iu', '', $room)), 'on'];
    }
    if (preg_match('/\s+(aus|ausschalten|abschalten)\s*[.!?,;:]*$/iu', $room)) {
      return [trim(preg_replace('/\s+(aus|ausschalten|abschalten)\s*[.!?,;:]*$/iu', '', $room)), 'off'];
    }
    return [$room, null];
  },

  // 7) Power aus allen Tokens bestimmen (falls nicht schon aus dem Room)
  'power_from_tokens' => static function($action, $device, $alles, $object, $room, $pref = null): ?string {
    if ($pref !== null) return $pref; // Vorrang für Ergebnis aus extract_power_from_room

    $hay = mb_strtolower(trim(
      ($action ?? '').' '.($device ?? '').' '.($alles ?? '').' '.($object ?? '').' '.($room ?? '')
    ), 'UTF-8');

    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(ein|an|einschalten|anmachen|anschalten)(?!\pL)/u', $hay)) return 'on';
    if (preg_match('/(*UTF8)(*UCP)(?<!\pL)(aus|ausschalten|abschalten|ausmachen)(?!\pL)/u', $hay)) return 'off';
    return null;
  },
];
