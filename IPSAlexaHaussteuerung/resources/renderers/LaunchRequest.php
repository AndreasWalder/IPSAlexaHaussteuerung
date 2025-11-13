<?php
$in = json_decode($_IPS['payload'] ?? '{}', true) ?: [];

$apl     = !empty($in['aplSupported']);
$action  = (string)($in['action'] ?? '');
$args1   = (string)($in['args1'] ?? '');
$alexa   = (string)($in['alexa'] ?? '');
$baseUrl = (string)($in['baseUrl'] ?? '');
$source  = (string)($in['source'] ?? '');
$token   = (string)($in['token'] ?? '');

$ucwords = static function(string $s): string { return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8'); };
$nf = static function(int $n): string { return number_format($n, 0, ',', '.'); };
$ellipsis = static function(?string $s, int $max) {
    $s = (string)($s ?? '');
    if (mb_strlen($s,'UTF-8') <= $max) return $s;
    return mb_substr($s, 0, max(0,$max-1), 'UTF-8').'…';
};

$iconUrl = static function(string $file, string $baseUrl, string $token): string {
    $path = IPS_GetKernelDir().'user'.DIRECTORY_SEPARATOR.'icons'.DIRECTORY_SEPARATOR.$file;
    $v = @filemtime($path) ?: 1;
    return rtrim($baseUrl,'/').'/hook/icons?f='.rawurlencode($file).'&token='.rawurlencode($token).'&v='.$v;
};

if ($baseUrl === '' || $token === '') { throw new Exception('baseUrl oder token fehlt'); }
$icon = static function(string $f) use ($iconUrl,$baseUrl,$token){ return $iconUrl($f,$baseUrl,$token); };

IPS_LogMessage('Alexa', 'iconUrl test: ' . $icon('Logo.png'));

$ds = [
 'tilesData'=>[
  'title'=>'HAUS VISUALISIERUNG',
  'subtitle'=>'Raumname: ' . $ucwords($alexa),
  'logoUrl'=> $icon('Logo.png'),
  'homeIcon'=> $icon('HomeIcon.png'),
  'footerText'=>'Tipp eine Kachel an – oder sage z. B. „Jalousie“.',
  'Text1'=>'Innentemperatur:','Value1'=>(string)($in['kuecheIstTemperatur'] ?? ''),
  'Text2'=>'Status:','Value2'=>(string)($in['meldungen'] ?? ''),
  'Text3'=>'Außentemperatur:','Value3'=>(string)($in['aussenTemperatur'] ?? ''),
  'Text4'=>'Information:','Value4'=>(string)($in['information'] ?? ''),
  'Icon'=> $icon('Icon.png'),
  'Source'=> $source,
  'tiles'=>[
    ['id'=>'licht','title'=>'Licht','subtitle'=>'schalten & dimmen','icon'=>$icon('Licht.png'),'color'=>'#FFE066'],
    ['id'=>'jalousie','title'=>'Jalousie','subtitle'=>'auf/zu/beschatten','icon'=>$icon('Jalousie.png'),'color'=>'#74C0FC'],
    ['id'=>'heizung','title'=>'Heizung','subtitle'=>'soll/ist','icon'=>$icon('Heizung.png'),'color'=>'#FF8A80'],
    ['id'=>'lueftung','title'=>'Lüftung','subtitle'=>'Stufen & Timer','icon'=>$icon('Lueftung.png'),'color'=>'#63E6BE'],
    ['id'=>'bewaesserung','title'=>'Bewässerung','subtitle'=>'Zonen & Automatik','icon'=>$icon('Bewaesserung.png'),'color'=>'#0FC0FC'],
    ['id'=>'geraete','title'=>'Geräte','subtitle'=>'Steckdosen & mehr','icon'=>$icon('Geraete.png'),'color'=>'#BAC8FF'],
    ['id'=>'sicherheit','title'=>'Sicherheit','subtitle'=>'Fenster & Türen','icon'=>$icon('Sicherheit.png'),'color'=>'#FFC9C9'],
    ['id'=>'listen','title'=>'Listen','subtitle'=>'Aufgaben & Notizen','icon'=>$icon('Listen.png'),'color'=>'#D8F212'],
    ['id'=>'energie','title'=>'Energie','subtitle'=>'Verbrauch & PV','icon'=>$icon('Energie.png'),'color'=>'#63EE1E'],
    ['id'=>'kamera','title'=>'Kamera','subtitle'=>'Live','icon'=>$icon('Kamera.png'),'color'=>'#A59CCF'],
    ['id'=>'info','title'=>'Information','subtitle'=>'System & Wetter','icon'=>$icon('Information.png'),'color'=>'#99E9F2'],
    ['id'=>'szene','title'=>'Szene','subtitle'=>'auswählen & mehr','icon'=>$icon('Szene.png'),'color'=>'#E599F7'],
    ['id'=>'einstellungen','title'=>'Einstellungen','subtitle'=>'','icon'=>$icon('Einstellungen.png'),'color'=>'#63E6BE'],
  ],
 ]
];

$speech = ($action === 'zurück' || $args1 === 'zurück') ? '' : ($apl ? 'Willkommen' : ('Willkommen '.$alexa));

$calcSize = static function(array $ds, bool $apl, string $speech): int {
    $aplObj = $apl ? ['doc'=>['type'=>'Link','src'=>'doc://alexa/apl/documents/Hausvisualisierung_Main'], 'ds'=>$ds] : null;
    return strlen(json_encode(['speech'=>$speech,'apl'=>$aplObj], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
};
$bytes = static function($v): int { return strlen(json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); };

/* — Deutsche Diagnose — */
$payloadReport = static function(array $ds, bool $apl, string $speech) use ($calcSize,$bytes,$nf) {
    $total = $calcSize($ds,$apl,$speech);
    $dsBytes = $bytes($ds);
    @IPS_LogMessage('Alexa', 'GESAMT: '. $nf($total) .' Bytes  |  Datasource: '. $nf($dsBytes) .' Bytes');

    $td = $ds['tilesData'] ?? [];
    $headerKeys = ['title','subtitle','footerText','Text1','Value1','Text2','Value2','Text3','Value3','Text4','Value4','Source'];
    $perKey = [];
    $sumTxt = 0;
    foreach ($headerKeys as $k) {
        $len = isset($td[$k]) ? mb_strlen((string)$td[$k], 'UTF-8') : 0;
        $perKey[$k] = $len; $sumTxt += $len;
    }
    arsort($perKey);
    $topTxt = array_slice($perKey, 0, 3, true);
    $hdr = 'HEADER-TEXT: Summe Zeichen='. $nf($sumTxt) .' | Top: ';
    $parts = [];
    foreach ($topTxt as $k=>$l) $parts[] = $k.'='. $nf($l);
    @IPS_LogMessage('Alexa', $hdr.implode(', ', $parts));

    $logoL = isset($td['logoUrl']) ? strlen((string)$td['logoUrl']) : 0;
    $homeL = isset($td['homeIcon']) ? strlen((string)$td['homeIcon']) : 0;
    $iconL = isset($td['Icon']) ? strlen((string)$td['Icon']) : 0;
    @IPS_LogMessage('Alexa', 'HEADER-BILDER: logoUrl='. $nf($logoL) .'c, homeIcon='. $nf($homeL) .'c, Icon='. $nf($iconL) .'c');

    $tiles = $td['tiles'] ?? [];
    $tileCount = is_array($tiles) ? count($tiles) : 0;
    $tileStats = [];
    $iconSum = 0;
    foreach ($tiles as $t) {
        $tb = $bytes($t);
        $id = (string)($t['id'] ?? '');
        $tileStats[] = ['id'=>$id,'bytes'=>$tb,'title'=>(string)($t['title'] ?? '')];
        if (!empty($t['icon']) && is_string($t['icon'])) $iconSum += strlen($t['icon']);
    }
    usort($tileStats, fn($a,$b)=>$b['bytes']<=>$a['bytes']);
    $topTiles = array_slice($tileStats, 0, 5);
    $msgTiles = 'TILES: Anzahl='. $tileCount .' | Gesamt-Icon-Zeichen='. $nf($iconSum) .' | Größte Tiles: ';
    $chunks = [];
    foreach ($topTiles as $x) $chunks[] = $x['id'].':'.$nf($x['bytes']).'b';
    @IPS_LogMessage('Alexa', $msgTiles.implode(', ', $chunks));

    if (!empty($perKey['Value2']) && $perKey['Value2'] > 2000) {
        @IPS_LogMessage('Alexa', 'HINWEIS: "Value2" (Status/Meldungen) ist sehr lang und verursacht den Hauptanteil der Payload.');
    }
};

/* — Sanfter Limiter (Ziel ~200 kB, große Texte zuerst) — */
$shrinkSoft = static function(array $ds, bool $apl, string $speech, int $targetBytes = 200000) use ($calcSize,$ellipsis) {
    $trace = [];
    $size = $calcSize($ds,$apl,$speech);
    if ($size <= $targetBytes) return [$ds,$trace];

    $td =& $ds['tilesData'];

    // 1) Header-Texte kürzen (schont Optik)
    $limits = [
        'footerText'=>120, 'subtitle'=>80, 'Text1'=>40, 'Text2'=>40, 'Text3'=>40, 'Text4'=>40,
        'Value1'=>80, 'Value2'=>400, 'Value3'=>80, 'Value4'=>200, 'Source'=>80
    ];
    foreach ($limits as $k=>$max) if (isset($td[$k])) $td[$k] = $ellipsis($td[$k], $max);
    $trace[] = 'Header-Texte gekürzt';
    if (($size = $calcSize($ds,$apl,$speech)) <= $targetBytes) return [$ds,$trace];

    // 2) Tile-Subtitles sanft kürzen
    if (isset($td['tiles']) && is_array($td['tiles'])) {
        foreach ($td['tiles'] as &$t) if (isset($t['subtitle'])) $t['subtitle'] = $ellipsis($t['subtitle'], 24);
        unset($t);
        $trace[] = 'Tile-Subtitles gekürzt';
    }
    if (($size = $calcSize($ds,$apl,$speech)) <= $targetBytes) return [$ds,$trace];

    // 3) Größte Tile-Icons selektiv entfernen (nur die längsten 4, >150c)
    if (isset($td['tiles']) && is_array($td['tiles'])) {
        $idx = [];
        foreach ($td['tiles'] as $i=>$t) {
            $len = (!empty($t['icon']) && is_string($t['icon'])) ? strlen($t['icon']) : 0;
            if ($len > 0) $idx[] = ['i'=>$i,'len'=>$len];
        }
        usort($idx, fn($a,$b)=>$b['len']<=>$a['len']);
        $removed = 0;
        foreach ($idx as $x) {
            if ($x['len'] < 150) break;
            unset($td['tiles'][$x['i']]['icon']);
            if (++$removed >= 4) break;
        }
        if ($removed > 0) $trace[] = 'Größte Tile-Icons entfernt: '.$removed;
    }
    if (($size = $calcSize($ds,$apl,$speech)) <= $targetBytes) return [$ds,$trace];

    // 4) Kachelfarben entfernen (optisch gering)
    if (isset($td['tiles']) && is_array($td['tiles'])) {
        foreach ($td['tiles'] as &$t) if (isset($t['color'])) unset($t['color']);
        unset($t);
        $trace[] = 'Tile-Farben entfernt';
    }
    if (($size = $calcSize($ds,$apl,$speech)) <= $targetBytes) return [$ds,$trace];

    // 5) Tile-Anzahl moderat reduzieren: 13→12→10→8
    if (isset($td['tiles']) && is_array($td['tiles'])) {
        $n = count($td['tiles']);
        $cuts = [12,10,8];
        foreach ($cuts as $c) {
            if ($n > $c) { $td['tiles'] = array_slice($td['tiles'], 0, $c); $n = $c; $trace[] = 'Tiles auf '.$c.' reduziert'; }
            if (($size = $calcSize($ds,$apl,$speech)) <= $targetBytes) return [$ds,$trace];
        }
    }

    // 6) Header-Icon (zusätzlich zum kleinen Content-Icon) entfernen
    if (isset($td['Icon'])) { unset($td['Icon']); $trace[] = 'Header-Icon entfernt'; }
    if (($size = $calcSize($ds,$apl,$speech)) <= $targetBytes) return [$ds,$trace];

    // 7) Alle Tile-Icons entfernen (letzte Stufe, Optik leidet etwas)
    if (isset($td['tiles']) && is_array($td['tiles'])) {
        foreach ($td['tiles'] as &$t) if (isset($t['icon'])) unset($t['icon']);
        unset($t);
        $trace[] = 'Alle Tile-Icons entfernt';
    }
    if (($size = $calcSize($ds,$apl,$speech)) <= $targetBytes) return [$ds,$trace];

    // 8) Minimal-Fallback (sollte selten greifen)
    if (isset($td['tiles']) && is_array($td['tiles'])) {
        $td['tiles'] = array_slice($td['tiles'], 0, 6);
        $trace[] = 'Minimal-Fallback: 6 Tiles';
    }
    return [$ds,$trace];
};

$payloadReport($ds,$apl,$speech);

if ($apl) {
    $before = $calcSize($ds,$apl,$speech);
    list($ds, $trace) = $shrinkSoft($ds,$apl,$speech, 200000); // Ziel bewusst < 256kB
    $after  = $calcSize($ds,$apl,$speech);

    @IPS_LogMessage('Alexa', 'Limiter: vorher='. $nf($before) .' Bytes, nachher='. $nf($after) .' Bytes');
    if (!empty($trace)) @IPS_LogMessage('Alexa', 'Limiter-Schritte: '.implode(' → ', $trace));
    if ($after > 256000) @IPS_LogMessage('Alexa', 'WARNUNG: Payload weiterhin groß. Prüfe besonders Value2 (Status/Meldungen).');
}

$aplObj = $apl ? ['doc'=>['type'=>'Link','src'=>'doc://alexa/apl/documents/Hausvisualisierung_Main'], 'ds'=>$ds] : null;

echo json_encode(['speech'=>$speech,'apl'=>$aplObj], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
