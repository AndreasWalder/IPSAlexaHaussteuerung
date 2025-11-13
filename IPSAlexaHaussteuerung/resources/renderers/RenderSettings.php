<?php
$in = json_decode($_IPS['payload'] ?? '{}', true) ?: [];
IPS_LogMessage('Alexa', 'AE(in)=' . json_encode($in['ACTIONS_ENABLED'] ?? null, JSON_UNESCAPED_UNICODE));
IPS_LogMessage('Alexa', 'SettingsRenderer args2=' . json_encode($in['args2'] ?? null, JSON_UNESCAPED_UNICODE));

$aplSupported = !empty($in['aplSupported']);
$action       = (string)($in['action'] ?? '');
$alexa        = (string)($in['alexa'] ?? '');
$args2        = $in['args2'] ?? null;

$CFG = is_array($in['CFG'] ?? null) ? $in['CFG'] : [];
// IDs: bevorzugt actions_vars, sonst var.ActionsEnabled
$VAR_IDS = is_array($CFG['actions_vars'] ?? null) ? $CFG['actions_vars'] : (is_array(($CFG['var']['ActionsEnabled'] ?? null)) ? $CFG['var']['ActionsEnabled'] : []);

// optionale Farb-Overrides
$COL = is_array($CFG['settings_colors'] ?? null) ? $CFG['settings_colors'] : [];
$COLOR_ON      = (string)($COL['on']      ?? '#10B981'); // grün
$COLOR_OFF     = (string)($COL['off']     ?? '#EF4444'); // rot
$COLOR_NEUTRAL = (string)($COL['neutral'] ?? '#2F3540'); // grau

$AE = is_array($in['ACTIONS_ENABLED'] ?? null) ? $in['ACTIONS_ENABLED'] : [];

$J = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

/* ------------ Helpers ------------- */
$readBool = static function($id, $fallback=false){
    if (!is_int($id) || $id <= 0) return (bool)$fallback;
    $v = @GetValue($id);
    return (bool)$v;
};
$writeBool = static function($id, bool $val){
    if (!is_int($id) || $id <= 0) return false;
    $var = @IPS_GetVariable($id);
    if (is_array($var) && ($var['VariableAction'] ?? 0) > 0) {
        @RequestAction($id, $val ? 1 : 0);
    } else {
        @SetValue($id, $val ? 1 : 0);
    }
    IPS_Sleep(120);
    return true;
};
// Fallback-Status aus ACTIONS_ENABLED mappen
$fallbackEnabled = static function(array $AE, string $key): bool {
    switch ($key) {
        case 'heizung_stellen':  return (bool)($AE['heizung']['stellen_aendern'] ?? false);
        case 'jalousie_steuern': return (bool)($AE['jalousie']['steuern'] ?? false);
        case 'licht_dimmers':    return (bool)($AE['licht']['dimmers'] ?? false);
        case 'licht_switches':   return (bool)($AE['licht']['switches'] ?? false);
        case 'lueft_stellen':  return (bool)($AE['lueft']['stellen_aendern'] ?? false);
        case 'geraete_toggle':  return (bool)($AE['geraete']['geraete_toggle'] ?? false);
        case 'bewaesserung_toggle':  return (bool)($AE['bewaesserung']['bewaesserung_toggle'] ?? false);
        default: return false;
    }
};

/* ------------ Definitionen ---------- */
$TITLES = [
    'heizung_stellen'  => 'Heizung: Stellen ändern',
    'jalousie_steuern' => 'Jalousien steuern erlauben',
    'licht_dimmers'    => 'Licht: Dimmer erlauben',
    'licht_switches'   => 'Licht: Schalter erlauben',
    'lueft_stellen'    => 'Lüftung: steuern erlauben',
    'geraete_toggle'   => 'Geräte: ändern erlauben',
    'bewaesserung_toggle'   => 'Bewässerung: steuern erlauben',
];

/* ------------ Flags laden ----------- */
$flags = [];
foreach ($TITLES as $k => $title) {
    $varId = (int)($VAR_IDS[$k] ?? 0);
    $on = $readBool($varId, $fallbackEnabled($AE, $k));
    $flags[$k] = [
        'varId' => $varId,
        'on'    => $on,
        'title' => $title,
        'key'   => $k,
    ];
}

/* ------------ Toggle aus args2 ------- */
$speech = '';
if (is_string($args2) && preg_match('~^toggle:actions:([a-z_]+)(?::(on|off|toggle))?$~i', trim($args2), $m)) {
    $key   = strtolower($m[1]);
    $mode  = strtolower($m[2] ?? 'toggle');
    if (isset($flags[$key])) {
        $cur  = (bool)$flags[$key]['on'];
        $next = $mode === 'on' ? true : ($mode === 'off' ? false : !$cur);
        $ok   = $writeBool((int)$flags[$key]['varId'], $next);
        // Neu einlesen (oder Fallback, falls keine ID)
        $flags[$key]['on'] = $ok
            ? $readBool((int)$flags[$key]['varId'], $next)
            : $fallbackEnabled($AE, $key); // nur Anzeige
        $speech = $flags[$key]['title'].' ist jetzt '.($flags[$key]['on'] ? 'aktiv' : 'deaktiviert').'.';
    } else {
        $speech = 'Diese Einstellung ist nicht vorhanden.';
    }
}

// === Datasource bauen ===
$sceneItems = [];

// Abschnitt: aktuelle Einstellungen
$sceneItems[] = [
    'section'     => 'Aktuelle Einstellungen Renderer',
    'sectionH'    => '4.6vw',
    'sectionFont' => '2.2vw',
    'sectionBold' => true,
    'sectionPadY' => '0.4vw'
];

foreach ($flags as $f) {
    $isOn = (bool)$f['on'];
    $sceneItems[] = [
        'title'        => $f['title'],
        'id'           => 'setting.'.$f['key'],
        // EIN Statusfeld (Farbe & Label)
        'statusLabel'  => $isOn ? 'An' : 'Aus',
        'statusColor'  => $isOn ? $COLOR_ON : $COLOR_OFF,
        // Zwei Buttons (aktive Farbe vs. neutral)
        'actions' => [
            [
                'label'         => 'An',
                'args'          => ['GetHaus','settings','toggle:actions:'.$f['key'].':on'],
                'mode'          => 'on',
                'active'        => $isOn,
                'colorOn'       => $COLOR_ON,
                'colorOff'      => $COLOR_OFF,
                'colorNeutral'  => $COLOR_NEUTRAL
            ],
            [
                'label'         => 'Aus',
                'args'          => ['GetHaus','settings','toggle:actions:'.$f['key'].':off'],
                'mode'          => 'off',
                'active'        => !$isOn,
                'colorOn'       => $COLOR_ON,
                'colorOff'      => $COLOR_OFF,
                'colorNeutral'  => $COLOR_NEUTRAL
            ]
        ]
    ];
}

// Unterer Abschnitts-Header vor „Gerätenamen“
$sceneItems[] = [
     'section'     => 'Aktuelle Einstellungen' . ($alexa !== '' ? (': '.$alexa) : ''),
    'sectionH'    => '4.6vw',
    'sectionFont' => '2.2vw',
    'sectionBold' => true,
    'sectionPadY' => '0.4vw'
];

// Row: Gerätenamen
$sceneItems[] = [
    'title'   => 'Gerätenamen',
    'id'      => 'setting.alexa',
    'actions' => [[
        'label'        => 'löschen',
        'args'         => ['GetHaus','delete','setting.alexa'],
        'mode'         => 'neutral',
        'active'       => true,
        'colorOn'      => $COLOR_ON,
        'colorOff'     => $COLOR_OFF,
        'colorNeutral' => $COLOR_NEUTRAL
    ]]
];

$doc = ['type' => 'Link', 'src' => 'doc://alexa/apl/documents/Einstellungen'];
$ds  = ['imageListData' => ['title'=>'Einstellungen','subtitle'=>'','sceneItems'=>$sceneItems]];

echo json_encode([
    'speech'   => $speech,
    'reprompt' => '',
    'apl'      => ['doc'=>$doc, 'ds'=>$ds, 'token'=>'hv-settings']
], $J);
