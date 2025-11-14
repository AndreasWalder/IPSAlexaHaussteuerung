<?php
return [
    'title' => 'HAUS VISUALISIERUNG',
    'subtitleTemplate' => 'Raumname: {{alexa}}',
    'footerText' => 'Tipp eine Kachel an – oder sage z. B. „Jalousie“.',
    'logo' => 'Logo.png',
    'homeIcon' => 'HomeIcon.png',
    'headerIcon' => 'Icon.png',
    'tiles' => [
        ['id' => 'licht',        'title' => 'Licht',        'subtitle' => 'schalten & dimmen',        'icon' => 'Licht.png',        'color' => '#FFE066'],
        ['id' => 'jalousie',     'title' => 'Jalousie',     'subtitle' => 'auf/zu/beschatten',     'icon' => 'Jalousie.png',     'color' => '#74C0FC'],
        ['id' => 'heizung',      'title' => 'Heizung',      'subtitle' => 'soll/ist',              'icon' => 'Heizung.png',      'color' => '#FF8A80'],
        ['id' => 'lueftung',     'title' => 'Lüftung',      'subtitle' => 'Stufen & Timer',        'icon' => 'Lueftung.png',     'color' => '#63E6BE'],
        ['id' => 'bewaesserung', 'title' => 'Bewässerung',  'subtitle' => 'Zonen & Automatik',      'icon' => 'Bewaesserung.png', 'color' => '#0FC0FC'],
        ['id' => 'geraete',      'title' => 'Geräte',       'subtitle' => 'Steckdosen & mehr',      'icon' => 'Geraete.png',      'color' => '#BAC8FF'],
        ['id' => 'sicherheit',   'title' => 'Sicherheit',   'subtitle' => 'Fenster & Türen',        'icon' => 'Sicherheit.png',   'color' => '#FFC9C9'],
        ['id' => 'listen',       'title' => 'Listen',       'subtitle' => 'Aufgaben & Notizen',     'icon' => 'Listen.png',       'color' => '#D8F212'],
        ['id' => 'energie',      'title' => 'Energie',      'subtitle' => 'Verbrauch & PV',         'icon' => 'Energie.png',      'color' => '#63EE1E'],
        ['id' => 'kamera',       'title' => 'Kamera',       'subtitle' => 'Live',                   'icon' => 'Kamera.png',       'color' => '#A59CCF'],
        ['id' => 'info',         'title' => 'Information',  'subtitle' => 'System & Wetter',        'icon' => 'Information.png',  'color' => '#99E9F2'],
        ['id' => 'szene',        'title' => 'Szene',        'subtitle' => 'auswählen & mehr',       'icon' => 'Szene.png',        'color' => '#E599F7'],
        ['id' => 'einstellungen','title' => 'Einstellungen','subtitle' => '',                      'icon' => 'Einstellungen.png','color' => '#63E6BE'],
    ],
];
