<?php
return [
    'buero' => [
        'display' => 'Büro',
        'synonyms' => ['büro','buero','office','eg büro','eg buero'],
        'floor' => 'EG',
        'domains' => [
            'heizung' => [
                'buero' => [
                    'title' => 'Büro',
                    'ist' => 28944,
                    'stellung' => 10810,
                    'eingestellt' => 33161,
                    'soll' => 33161,
                    'icon' => 'Buero.png',
                    'entityId' => 'heizung.Büro',
                    'order' => 110
                ]
            ],
            'jalousie' => [
                'fenster' => [
                    'title' => 'Büro',
                    'wert' => 28160,
                    'order' => 80
                ]
            ],
            'licht' => [
                'switches' => [
                    'decke_all' => [
                        'title' => 'Büro Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','hauptlicht','haupt','alle','gesamt'],
                        'state' => 38988,
                        'toggle' => 39641,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_buero_all',
                        'order' => 10
                    ]
                ],
                'dimmers' => [
                    'all' => [
                        'title' => 'Büro Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','hauptlicht','haupt','alle','gesamt','dimmen'],
                        'value' => 47227,
                        'set' => 47227,
                        'min' => 0,
                        'max' => 100,
                        'step' => 1,
                        'icon' => 'dim.png',
                        'entityId' => 'dim.eg_buero_all',
                        'order' => 20
                    ],
                    'links' => [
                        'title' => 'Büro Deckenlicht links',
                        'synonyms' => ['decke links','links','linke seite','linker kreis'],
                        'value' => 13073,
                        'set' => 13073,
                        'min' => 0,
                        'max' => 100,
                        'step' => 1,
                        'icon' => 'dim.png',
                        'entityId' => 'dim.eg_buero_links',
                        'order' => 21
                    ],
                    'mitte' => [
                        'title' => 'Büro Deckenlicht mitte',
                        'synonyms' => ['decke mitte','mitte','mittig','zentral'],
                        'value' => 29962,
                        'set' => 29962,
                        'min' => 0,
                        'max' => 100,
                        'step' => 1,
                        'icon' => 'dim.png',
                        'entityId' => 'dim.eg_buero_mitte',
                        'order' => 22
                    ],
                    'rechts' => [
                        'title' => 'Büro Deckenlicht rechts',
                        'synonyms' => ['decke rechts','rechts','rechte seite','rechter kreis'],
                        'value' => 33326,
                        'set' => 33326,
                        'min' => 0,
                        'max' => 100,
                        'step' => 1,
                        'icon' => 'dim.png',
                        'entityId' => 'dim.eg_buero_rechts',
                        'order' => 23
                    ]
                ],
                'status' => [
                    'lux' => [
                        'title' => 'Büro',
                        'value' => 53445,
                        'unit' => 'lx',
                        'icon' => 'lux.png',
                        'order' => 10
                    ]
                ]
            ]
        ]
    ],
    'kueche' => [
        'display' => 'Küche',
        'synonyms' => ['küche','kueche','küchen','kuechen','eg küche','eg kueche','kueche'],
        'floor' => 'EG',
        'domains' => [
            'heizung' => [
                'kueche' => [
                    'title' => 'Küche',
                    'ist' => 21992,
                    'stellung' => 16682,
                    'eingestellt' => 58652,
                    'soll' => 58652,
                    'icon' => 'Kueche.png',
                    'entityId' => 'heizung.Küche',
                    'order' => 80
                ]
            ],
            'jalousie' => [
                'fenster' => ['title' => 'Küche Fenster','wert' => 46924,'order' => 90],
                'westlinks' => ['title' => 'Küche West links','wert' => 19129,'order' => 100],
                'westrechts' => ['title' => 'Küche West rechts','wert' => 25136,'order' => 110]
            ],
            'licht' => [
                'switches' => [
                    'deckenlicht_alle' => [
                        'title' => 'Küche Deckenlicht alle',
                        'synonyms' => ['decke','decken','alle','hauptlicht','gesamt','küche','kueche','küchen','kuechen','eg küche','eg kueche','kueche'],
                        'state' => 51838,
                        'toggle' => 41292,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_kueche_deckenlicht_alle',
                        'order' => 40
                    ],
                    'theke_und_schlange' => [
                        'title' => 'Küche Theke und Schlange',
                        'synonyms' => ['theke und schlange','theke','schlange','arbeitsplatte','unterbau'],
                        'state' => 52923,
                        'toggle' => 14841,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_kueche_theke_und_schlange',
                        'order' => 110
                    ],
                    'licht_anrichte' => [
                        'title' => 'Küche Licht Anrichte',
                        'synonyms' => ['anrichte','vitrine','board','kommode'],
                        'state' => 33911,
                        'toggle' => 42379,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_kueche_licht_anrichte',
                        'order' => 30
                    ],
                    'schlange' => [
                        'title' => 'Küche Schlange',
                        'synonyms' => ['schlange','led streifen','streifen'],
                        'state' => 53419,
                        'toggle' => 53419,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_kueche_schlange',
                        'order' => 50
                    ],
                    'licht_speiss' => [
                        'title' => 'Küche Licht Speiß',
                        'synonyms' => ['speis','speiß','speisekammer'],
                        'state' => 52340,
                        'toggle' => 50803,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_kueche_licht_speiss',
                        'order' => 60
                    ],
                    'licht_theke' => [
                        'title' => 'Küche Licht Theke',
                        'synonyms' => ['theke','bar','tresen'],
                        'state' => 16252,
                        'toggle' => 48901,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_kueche_licht_theke',
                        'order' => 70
                    ],
                    'licht_anrichte_davor' => [
                        'title' => 'Küche Licht Anrichte davor',
                        'synonyms' => ['anrichte davor','vitrine vorne','board vorne'],
                        'state' => 10945,
                        'toggle' => 31887,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_kueche_licht_anrichte_davor',
                        'order' => 80
                    ],
                    'licht_gang' => [
                        'title' => 'Küche Licht Gang',
                        'synonyms' => ['gang','flur','durchgang'],
                        'state' => 56135,
                        'toggle' => 17037,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_kueche_licht_gang',
                        'order' => 90
                    ],
                    'licht_geraete' => [
                        'title' => 'Küche Licht Geräte',
                        'synonyms' => ['geräte','geraete','küchengeräte','arbeitsgeräte'],
                        'state' => 31090,
                        'toggle' => 58576,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_kueche_licht_geraete',
                        'order' => 100
                    ]
                ],
                'dimmers' => [
                    'anrichte' => [
                        'title' => 'Küche Anrichte',
                        'synonyms' => ['anrichte','vitrine','board','kommode','dimmen'],
                        'value' => 28194,
                        'set' => 28194,
                        'state' => 33911,
                        'min' => 0,
                        'max' => 100,
                        'step' => 1,
                        'icon' => 'dim.png',
                        'entityId' => 'dim.eg_kueche_anrichte_v',
                        'order' => 40
                    ]
                ],
                'status' => [
                    'lux' => ['title' => 'Küche vorne','value' => 40860,'unit' => 'lx','icon' => 'lux.png','order' => 11]
                ]
            ],
             'devices' => [
                'tabs' => [
                    'Dampf-Backofen'    => ['id' => 32701, 'order'      => 10,
                        'synonyms'      => ['dampf-backofen','dampfbackofen','dampfofen','backofendampf']],
                    'Geschirrspüler'    => ['id' => 16021, 'order'      => 20,
                        'synonyms'      => ['geschirrspueler','geschirr-spüler','spuelmaschine','spülmaschine','spüler','geschirrspüler']],
                    'Kaffeevollautomat' => ['id' => 33267, 'order'   => 30,
                        'synonyms'      => ['kaffee','kaffeeautomat','vollautomat']],
                    'Kochfeld'          => ['id' => 21810, 'order'            => 40,
                        'synonyms'      => ['kochfeld','herd','kochplatte']],
                    'Micro-Backofen'    => ['id' => 15080, 'order'      => 50,
                        'synonyms'      => ['mikrowelle','micro','mikro','microbackofen']]
                ]
            ]
        ]
    ],
    'eg_wc' => [
        'display' => 'EG WC',
        'synonyms' => ['eg wc','erdgeschoss wc','wc eg','wc erdgeschoss','eg_wc','eg pc', 'egwc'],
        'floor' => 'EG',
        'domains' => [
            'jalousie' => [
                'fenster' => ['title' => 'EG WC','wert' => 18915,'order' => 80]
            ],
            'licht' => [
                'switches' => [
                    'wc_spiegel' => [
                        'title' => 'WC Spiegel',
                        'synonyms' => ['spiegel','spiegellampe','licht spiegel'],
                        'state' => 42954,
                        'toggle' => 15786,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_eg_wc_wc_spiegel',
                        'order' => 10
                    ],
                    'wc_deckenlicht_schlaten' => [
                        'title' => 'WC Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','hauptlicht','haupt', 'eg wc','erdgeschoss wc','wc eg','wc erdgeschoss','eg_wc','eg pc', 'egwc'],
                        'state' => 57478,
                        'toggle' => 56065,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_eg_wc_wc_deckenlicht_schlaten',
                        'order' => 20
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'WC Helligkeit','value' => 15524,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ],
            'lueftung' => [
                'fans' => [
                    'wc' => ['title' => 'EG WC Lüfter','state' => 49570,'toggle' => 30979,'icon' => 'vent.png','entityId' => 'luefter.eg_wc','order' => 10],
                    'wc_boost' => ['title' => 'EG WC Lüfter stark','state' => 50093,'toggle' => 17791,'icon' => 'vent.png','entityId' => 'luefter.eg_wc','order' => 11]
                ]
            ]
        ]
    ],
    'eg_gang' => [
    'display' => 'EG Gang',
    'synonyms' => ['eg gang','erdgeschoss','egg','erdgeschoss gang','eg_gang'],
    'floor' => 'EG',
    'domains' => [
         'heizung' => [
                'gang' => [
                    'title' => 'Gang',
                    'ist' => 16128,
                    'stellung' => 19429,
                    'eingestellt' => 13130,
                    'soll' => 13130,
                    'icon' => 'Keller_Gang.png',
                    'entityId' => 'gang.Elternzimmer',
                    'order' => 155
                ]
            ],
        'licht' => [
            'switches' => [
                'gang_deckenlicht' => [
                    'title' => 'EG Gang Deckenlicht',
                    'synonyms' => ['gang','flur','decke','deckenlicht','hauptlicht','eg gang','erdgeschoss','egg','erdgeschoss gang','eg_gang'],
                    'state' => 57156,
                    'toggle' => 57156,
                    'iconOn' => 'bulb_on.png',
                    'iconOff' => 'bulb_off.png',
                    'entityId' => 'light.eg_gang_deckenlicht',
                    'order' => 11
                ]
            ],
            'dimmers' => [],
            'status' => []
        ]
       ]
    ],
    'og_gang' => [
    'display'  => 'OG Gang',
    'synonyms' => ['og gang','obergeschoss gang','oberer gang','flur oben','og_gang'],
    'floor'    => 'OG',
    'domains'  => [
        'licht' => [
            'switches' => [
                'deckenlicht' => [
                    'title'    => 'OG Gang Deckenlicht',
                    'synonyms' => ['decke','decken','deckenlicht','hauptlicht','gang','flur','og gang','obergeschoss gang'],
                    'state'    => 55908,
                    'toggle'   => 57559,
                    'iconOn'   => 'bulb_on.png',
                    'iconOff'  => 'bulb_off.png',
                    'entityId' => 'light.og_gang_deckenlicht',
                    'order'    => 10
                ],
                'deckenlicht_hinten' => [
                    'title'    => 'OG Gang Deckenlicht hinten',
                    'synonyms' => ['decke hinten','hinten','hinterer gang','flur hinten'],
                    'state'    => 23215,
                    'toggle'   => 49634,
                    'iconOn'   => 'bulb_on.png',
                    'iconOff'  => 'bulb_off.png',
                    'entityId' => 'light.og_gang_deckenlicht_hinten',
                    'order'    => 20
                ],
                'gang_und_stiege_led_oben' => [
                    'title'    => 'OG Gang und Stiege Deckenlicht und LED oben',
                    'synonyms' => ['stiege','treppe','stiege oben','treppe oben','led oben'],
                    'state'    => 51727,
                    'toggle'   => 11930,
                    'iconOn'   => 'bulb_on.png',
                    'iconOff'  => 'bulb_off.png',
                    'entityId' => 'light.og_gang_und_stiege_led_oben',
                    'order'    => 30
                ],
                'vitrine_steckdose' => [
                    'title'    => 'OG Gang Vitrine Steckdose',
                    'synonyms' => ['vitrine','steckdose','dose','board'],
                    'state'    => 37942,
                    'toggle'   => 15804,
                    'iconOn'   => 'bulb_on.png',
                    'iconOff'  => 'bulb_off.png',
                    'entityId' => 'light.og_gang_vitrine_steckdose',
                    'order'    => 40
                ]
            ],
            'dimmers' => [],
            'status'  => [
                'lux' => [
                    'title' => 'OG Gang',
                    'value' => 56130,
                    'unit'  => 'lx',
                    'icon'  => 'lux.png',
                    'order' => 10
                ],
                'lux_hinten' => [
                    'title' => 'OG Gang hinten',
                    'value' => 33072,
                    'unit'  => 'lx',
                    'icon'  => 'lux.png',
                    'order' => 11
                ]
                ]
            ]
        ]
    ],
    'wintergarten' => [
        'display' => 'Wintergarten',
        'synonyms' => ['wintergarten','wg','eg wintergarten'],
        'floor' => 'EG',
        'domains' => [
            'heizung' => [
                'wintergarten' => [
                    'title' => 'Wintergarten',
                    'ist' => 54131,
                    'stellung' => 20405,
                    'eingestellt' => 37885,
                    'soll' => 31344,
                    'icon' => 'Wintergarten.png',
                    'entityId' => 'heizung.Wintergarten',
                    'order' => 90
                ]
            ],
            'jalousie' => [
                'tuer' => ['title' => 'Wintergarten Tür','wert' => 20090,'order' => 120],
                'mitte_links' => ['title' => 'Wintergarten Mitte links','wert' => 58722,'order' => 130],
                'mitte_rechts' => ['title' => 'Wintergarten Mitte rechts','wert' => 51640,'order' => 140],
                'westlinks' => ['title' => 'Wintergarten West links','wert' => 45231,'order' => 150],
                'westrechts' => ['title' => 'Wintergarten West rechts','wert' => 35759,'order' => 160]
            ],
            'licht' => [
                'switches' => [
                    'deckenlicht_kreis_vorne' => [
                        'title' => 'Wintergarten Deckenlicht Kreis vorne',
                        'synonyms' => ['decke vorne','kreis vorne','vorderer kreis','vorn'],
                        'state' => 56807,
                        'toggle' => 56807,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wintergarten_deckenlicht_kreis_vorne',
                        'order' => 10
                    ],
                    'spot_glasschrank_oben' => [
                        'title' => 'Wintergarten Spot Glasschrank oben',
                        'synonyms' => ['glasschrank oben','spot oben','vitrine oben'],
                        'state' => 21428,
                        'toggle' => 34583,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wintergarten_spot_glasschrank_oben',
                        'order' => 20
                    ],
                    'spot_glasschrank_unten' => [
                        'title' => 'Wintergarten Spot Glasschrank unten',
                        'synonyms' => ['glasschrank unten','spot unten','vitrine unten'],
                        'state' => 24925,
                        'toggle' => 17933,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wintergarten_spot_glasschrank_unten',
                        'order' => 30
                    ],
                    'spot_glasschrank_oben_und_unten' => [
                        'title' => 'Wintergarten Spot Glasschrank oben und unten',
                        'synonyms' => ['glasschrank beide','vitrine beide','spots beide'],
                        'state' => 21428,
                        'toggle' => 33000,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wintergarten_spot_glasschrank_oben_und_unten',
                        'order' => 40
                    ],
                    'deckenlicht_kreis_hinten' => [
                        'title' => 'Wintergarten Deckenlicht Kreis hinten',
                        'synonyms' => ['decke hinten','kreis hinten','hinterer kreis','hinten'],
                        'state' => 34411,
                        'toggle' => 34411,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wintergarten_deckenlicht_kreis_hinten',
                        'order' => 50
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'Wintergarten','value' => 44157,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ],
            'lueftung' => [
                'fans' => [
                    'wg' => ['title' => 'Wintergarten Lüfter Ofen','state' => 47321,'toggle' => 47321,'icon' => 'vent.png','entityId' => 'luefter.wintergarten','order' => 10]
                ]
            ]
        ]
    ],
    'wohnzimmer' => [
        'display' => 'Wohnzimmer',
        'synonyms' => ['wohnzimmer','fernsehraum','wohnen','wz','eg wohnzimmer'],
        'floor' => 'EG',
        'domains' => [
            'heizung' => [
                'wohnzimmer' => [
                    'title' => 'Wohnzimmer',
                    'ist' => 57012,
                    'stellung' => 14944,
                    'eingestellt' => 23640,
                    'soll' => 23640,
                    'icon' => 'Wohnzimmer.png',
                    'entityId' => 'heizung.Wohnzimmer',
                    'order' => 100
                ]
            ],
            'jalousie' => [
                'ostlinks' => ['title' => 'Wohnzimmer Ost links','wert' => 25770,'order' => 170],
                'ostrechts' => ['title' => 'Wohnzimmer Ost rechts','wert' => 44357,'order' => 180],
                'tuer' => ['title' => 'Wohnzimmer Tür','wert' => 12353,'order' => 190],
                'tuer_links' => ['title' => 'Wohnzimmer Tür links','wert' => 59620,'order' => 200],
                'tuer_rechts' => ['title' => 'Wohnzimmer Tür rechts','wert' => 13162,'order' => 210]
            ],
            'licht' => [
                'switches' => [
                    'steckdose_west_ablage' => [
                        'title' => 'Wohnzimmer Steckdose West Ablage',
                        'synonyms' => ['steckdose ablage','west ablage','ablage'],
                        'state' => 32787,
                        'toggle' => 26858,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wohnzimmer_steckdose_west_ablage',
                        'order' => 10
                    ],
                    'rechts_deckenlicht' => [
                        'title' => 'Wohnzimmer Rechts Deckenlicht',
                        'synonyms' => ['decke rechts','rechts','rechte seite'],
                        'state' => 56851,
                        'toggle' => 28625,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wohnzimmer_rechts_deckenlicht',
                        'order' => 20
                    ],
                    'mitte_links_deckenlicht' => [
                        'title' => 'Wohnzimmer Mitte Links Deckenlicht',
                        'synonyms' => ['decke mitte links','mitte links','mittlere links'],
                        'state' => 23380,
                        'toggle' => 12727,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wohnzimmer_mitte_links_deckenlicht',
                        'order' => 30
                    ],
                    'links_deckenlicht' => [
                        'title' => 'Wohnzimmer Links Deckenlicht',
                        'synonyms' => ['decke links','links','linke seite'],
                        'state' => 36213,
                        'toggle' => 18011,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wohnzimmer_links_deckenlicht',
                        'order' => 40
                    ],
                    'weihnachtsbeleuchtung_steckdose' => [
                        'title' => 'Wohnzimmer Weihnachts Steckdose',
                        'synonyms' => ['weihnachten','weihnachtsbeleuchtung','weihnachts steckdose','xmas'],
                        'state' => 57862,
                        'toggle' => 26100,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wohnzimmer_weihnachtsbeleuchtung_steckdose',
                        'order' => 50
                    ],
                    'l_eingang' => [
                        'title' => 'Wohnzimmer L Eingang',
                        'synonyms' => ['eingang','eingangslicht','l eingang'],
                        'state' => 42565,
                        'toggle' => 42565,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wohnzimmer_l_eingang',
                        'order' => 60
                    ],
                    'vorne_deckenlicht' => [
                        'title' => 'Wohnzimmer vorne Deckenlicht',
                        'synonyms' => ['decke vorne','vorne'],
                        'state' => 16161,
                        'toggle' => 54123,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wohnzimmer_vorne_deckenlicht',
                        'order' => 70
                    ],
                    'mitte_rechts_deckenlicht' => [
                        'title' => 'Wohnzimmer Mitte Rechts Deckenlicht',
                        'synonyms' => ['decke mitte rechts','mitte rechts','mittlere rechts'],
                        'state' => 38930,
                        'toggle' => 38894,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wohnzimmer_mitte_rechts_deckenlicht',
                        'order' => 80
                    ],
                    'keyboard_deckenlicht' => [
                        'title' => 'Wohnzimmer Keyboard Deckenlicht',
                        'synonyms' => ['keyboard','klavier','piano','instrument'],
                        'state' => 35334,
                        'toggle' => 24753,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wohnzimmer_keyboard_deckenlicht',
                        'order' => 90
                    ],
                    'alle_hue' => [
                        'title' => 'Wohnzimmer alle Hue',
                        'synonyms' => ['alle hue','hue alle','philips hue','hue','wohnzimmer','wohnen','wz','eg wohnzimmer'],
                        'state' => 20946,
                        'toggle' => 20946,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wohnzimmer_alle_hue',
                        'order' => 100
                    ],
                    'fernsehen_licht' => [
                        'title' => 'Wohnzimmer Fernsehen Licht',
                        'synonyms' => ['fernsehen','tv','fernseher','media'],
                        'state' => 55752,
                        'toggle' => 55752,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_wohnzimmer_fernsehen_licht',
                        'order' => 110
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'Wohnzimmer hinten','value' => 22692,'unit' => 'lx','icon' => 'lux.png','order' => 12]
                ]
            ]
        ]
    ],
    'bad' => [
        'display' => 'Bad',
        'synonyms' => ['bad','badezimmer','og bad'],
        'floor' => 'OG',
        'domains' => [
            'heizung' => [
                'bad' => [
                    'title' => 'Bad',
                    'ist' => 47701,
                    'stellung' => 39137,
                    'eingestellt' => 49965,
                    'soll' => 37111,
                    'icon' => 'Bad.png',
                    'entityId' => 'heizung.Bad',
                    'order' => 120
                ]
            ],
            'jalousie' => [
                'fenster' => ['title' => 'OG Bad','wert' => 22239,'order' => 220]
            ],
            'licht' => [
                'switches' => [
                    'waschbecken_dusche_led_und_whirlpool_led' => [
                        'title' => 'Bad Licht alle',
                        'synonyms' => ['alle','gesamt','bad alle','alles an'],
                        'state' => 50009,
                        'toggle' => 50009,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_bad_waschbecken_dusche_led_und_whirlpool_led',
                        'order' => 10
                    ],
                    'waschbecken_deckenlicht' => [
                        'title' => 'Bad Waschbecken Deckenlicht',
                        'synonyms' => ['waschbecken','spiegel','becken','decke waschbecken','bad','badezimmer','og bad'],
                        'state' => 55110,
                        'toggle' => 59572,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_bad_waschbecken_deckenlicht',
                        'order' => 20
                    ],
                    'duche_deckenlicht' => [
                        'title' => 'Bad Duche Deckenlicht',
                        'synonyms' => ['dusche','decke dusche','duschlicht'],
                        'state' => 48012,
                        'toggle' => 22033,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_bad_duche_deckenlicht',
                        'order' => 30
                    ],
                    'whirlpool_deckenlicht' => [
                        'title' => 'Bad Whirlpool Deckenlicht',
                        'synonyms' => ['whirlpool','wanne','badewanne'],
                        'state' => 49863,
                        'toggle' => 33392,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_bad_whirlpool_deckenlicht',
                        'order' => 40
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'Bad','value' => 56521,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ],
            'lueftung' => [
                'fans' => [
                    'bad' => ['title' => 'Bad Lüfter','state' => 15839,'toggle' => 12445,'icon' => 'vent.png','entityId' => 'luefter.bad','order' => 10]
                ]
            ]
        ]
    ],
    'eltern' => [
        'display' => 'Elternzimmer',
        'synonyms' => ['eltern','elternzimmer','og eltern'],
        'floor' => 'OG',
        'domains' => [
            'heizung' => [
                'eltern' => [
                    'title' => 'Elternzimmer',
                    'ist' => 45022,
                    'stellung' => 12979,
                    'eingestellt' => 43582,
                    'soll' => 43582,
                    'icon' => 'Elternzimmer.png',
                    'entityId' => 'heizung.Elternzimmer',
                    'order' => 150
                ]
            ],
            'jalousie' => [
                'tuer' => ['title' => 'Elternzimmer Tür','wert' => 48883,'order' => 230],
                'fenster' => ['title' => 'Elternzimmer Fenster','wert' => 44078,'order' => 240]
            ],
            'licht' => [
                'switches' => [
                    'leselicht_links' => [
                        'title' => 'Elternzimmer Leselicht links',
                        'synonyms' => ['lese links','nachtlicht links','bett links'],
                        'state' => 10927,
                        'toggle' => 21594,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_eltern_leselicht_links',
                        'order' => 10
                    ],
                    'vitrinelicht' => [
                        'title' => 'Elternzimmer Vitrinelicht',
                        'synonyms' => ['vitrine','schrank vitrine','schranklicht'],
                        'state' => 34323,
                        'toggle' => 47228,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_eltern_vitrinelicht',
                        'order' => 20
                    ],
                    'kastenlicht' => [
                        'title' => 'Elternzimmer Kastenlicht',
                        'synonyms' => ['kasten','kleiderschrank','schrank','eltern','elternzimmer'],
                        'state' => 13133,
                        'toggle' => 14679,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_eltern_kastenlicht',
                        'order' => 30
                    ],
                    'leselicht_rechts' => [
                        'title' => 'Elternzimmer Leselicht rechts',
                        'synonyms' => ['lese rechts','nachtlicht rechts','bett rechts'],
                        'state' => 31099,
                        'toggle' => 54341,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_eltern_leselicht_rechts',
                        'order' => 40
                    ],
                    'led_bett' => [
                        'title' => 'Elternzimmer LED Bett',
                        'synonyms' => ['bett led','bettlicht','unterbett'],
                        'state' => 59968,
                        'toggle' => 56533,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_eltern_led_bett',
                        'order' => 50
                    ]
                ],
                'dimmers' => [
                    'vitrinelicht_dimmen_wert' => [
                        'title' => 'Elternzimmer Vitrinelicht',
                        'synonyms' => ['vitrine','vitrine dimmen','schrank vitrine dimmen'],
                        'value' => 16849,
                        'set' => 16849,
                        'min' => 0,
                        'max' => 100,
                        'step' => 1,
                        'icon' => 'dim.png',
                        'entityId' => 'dim.og_eltern_vitrinelicht_dimmen_wert',
                        'order' => 10
                    ],
                    'kastenlicht_dimmen_wert' => [
                        'title' => 'Elternzimmer Kastenlicht',
                        'synonyms' => ['kasten','kleiderschrank','schrank','kasten dimmen'],
                        'value' => 29625,
                        'set' => 29625,
                        'min' => 0,
                        'max' => 100,
                        'step' => 1,
                        'icon' => 'dim.png',
                        'entityId' => 'dim.og_eltern_kastenlicht_dimmen_wert',
                        'order' => 20
                    ]
                ],
                'status' => [
                    'lux' => ['title' => 'Elternzimmer','value' => 15714,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ]
        ]
    ],
    'kinder_gross' => [
        'display' => 'Kinderzimmer groß',
        'synonyms' => ['kinderzimmer groß','kinderzimmer gross','kinderzimmergroß','kinderzimmergross','kizi groß','kizi gross','og kinderzimmer groß'],
        'floor' => 'OG',
        'domains' => [
            'heizung' => [
                'kinder_gross' => [
                    'title' => 'Kinderzimmer groß',
                    'ist' => 33371,
                    'stellung' => 54339,
                    'eingestellt' => 21691,
                    'soll' => 15389,
                    'icon' => 'Kinderzimmer_groß.png',
                    'entityId' => 'heizung.Kinderzimmer groß',
                    'order' => 140
                ]
            ],
            'jalousie' => [
                'sued' => ['title' => 'Kinderzimmer groß Fenster','wert' => 30210,'order' => 250],
                'sued_tuer' => ['title' => 'Kinderzimmer groß Tür','wert' => 50929,'order' => 260]
            ],
            'licht' => [
                'switches' => [
                    'deckenlicht' => [
                        'title' => 'Kinderzimmer groß Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','hauptlicht','haupt','kinderzimmer groß','kinderzimmer gross','kinderzimmergroß','kinderzimmergross','kizi groß','kizi gross','og kinderzimmer groß'],
                        'state' => 58708,
                        'toggle' => 28815,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_kinder_gross_deckenlicht',
                        'order' => 10
                    ],
                    'weihnachtsbeleuchtung' => [
                        'title' => 'Kinderzimmer groß Weihnachts',
                        'synonyms' => ['weihnachten','xmas','weihnachtsbeleuchtung'],
                        'state' => 33659,
                        'toggle' => 27732,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_kinder_gross_weihnachtsbeleuchtung',
                        'order' => 20
                    ],
                    'balkonlicht' => [
                        'title' => 'Kinderzimmer_groß Balkonlicht',
                        'synonyms' => ['balkon','außen','aussen'],
                        'state' => 34229,
                        'toggle' => 34229,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_kinder_gross_balkonlicht',
                        'order' => 30
                    ],
                    'schreibtischlampe' => [
                        'title' => 'Kinderzimmer groß Schreibtischlampe',
                        'synonyms' => ['schreibtisch','tischlampe','arbeitsplatz'],
                        'state' => 47850,
                        'toggle' => 11337,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_kinder_gross_schreibtischlampe',
                        'order' => 40
                    ]
                ],
                'dimmers' => [
                    'deckenlicht_dimmen_wert' => [
                        'title' => 'Kinderzimmer groß Deckenlicht',
                        'synonyms' => ['decke','deckenlicht','hauptlicht','dimmen'],
                        'value' => 15120,
                        'set' => 15120,
                        'min' => 0,
                        'max' => 100,
                        'step' => 1,
                        'icon' => 'dim.png',
                        'entityId' => 'dim.og_kinder_gross_deckenlicht_dimmen_wert',
                        'order' => 10
                    ]
                ],
                'status' => [
                    'lux' => ['title' => 'Kinderzimmer groß','value' => 30470,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ]
        ]
    ],
    'kinder_klein' => [
        'display' => 'Kinderzimmer klein',
        'synonyms' => ['kinderzimmer klein','kizi klein','og kinderzimmer klein','samuel'],
        'floor' => 'OG',
        'domains' => [
            'heizung' => [
                'kinder_klein' => [
                    'title' => 'Kinderzimmer klein',
                    'ist' => 12248,
                    'stellung' => 16130,
                    'eingestellt' => 25119,
                    'soll' => 58748,
                    'icon' => 'Kinderzimmer_klein.png',
                    'entityId' => 'heizung.Kinderzimmer klein',
                    'order' => 130
                ]
            ],
            'jalousie' => [
                'west' => ['title' => 'Kinderzimmer klein West','wert' => 28427,'order' => 270],
                'nord' => ['title' => 'Kinderzimmer klein Nord','wert' => 10412,'order' => 280]
            ],
            'licht' => [
                'switches' => [
                    'leselicht' => [
                        'title' => 'Kinderzimmer klein Leselicht',
                        'synonyms' => ['lese','nachtlicht','bett'],
                        'state' => 11539,
                        'toggle' => 37361,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_kinder_klein_leselicht',
                        'order' => 10
                    ],
                    'deckenlicht' => [
                        'title' => 'Kinderzimmer klein Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','hauptlicht','haupt','kinderzimmer klein','kizi klein','og kinderzimmer klein','samuel'],
                        'state' => 16254,
                        'toggle' => 23667,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_kinder_klein_deckenlicht',
                        'order' => 20
                    ]
                ],
                'dimmers' => [
                    'deckenlicht_dimmen_wert' => [
                        'title' => 'Kinderzimmer_klein Deckenlicht',
                        'synonyms' => ['decke','deckenlicht','dimmen','hauptlicht'],
                        'value' => 29328,
                        'set' => 29328,
                        'min' => 0,
                        'max' => 100,
                        'step' => 1,
                        'icon' => 'dim.png',
                        'entityId' => 'dim.og_kinder_klein_deckenlicht_dimmen_wert',
                        'order' => 10
                    ]
                ],
                'status' => [
                    'lux' => ['title' => 'Kinderzimmer klein','value' => 28922,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ]
        ]
    ],
    'stiegenhaus' => [
        'display' => 'Stiegenhaus',
        'synonyms' => ['stiegenhaus','treppenhaus'],
        'floor' => 'OG',
        'domains' => [
            'jalousie' => [
                'links' => ['title' => 'Stiegenhaus Links','wert' => 32291,'order' => 290],
                'mitte' => ['title' => 'Stiegenhaus Mitte','wert' => 45259,'order' => 300],
                'rechts' => ['title' => 'Stiegenhaus Rechts','wert' => 43222,'order' => 310]
            ],
            'licht' => [
                'switches' => [
                    'deckenlicht' => [
                        'title' => 'Stiegenhaus Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','treppe','hauptlicht', 'stiegenhaus'],
                        'state' => 48275,
                        'toggle' => 51542,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_stiegenhaus_deckenlicht',
                        'order' => 20
                    ],
                    'seitenlicht' => [
                        'title' => 'Stiegenhaus Seitenlicht',
                        'synonyms' => ['seite','wandlicht','stiege seite'],
                        'state' => 46538,
                        'toggle' => 15412,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_stiegenhaus_seitenlicht',
                        'order' => 30
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'Stiegenhaus unten','value' => 15611,'unit' => 'lx','icon' => 'lux.png','order' => 11]
                ]
            ]
        ]
    ],
    'og_wc' => [
        'display' => 'OG WC',
        'synonyms' => ['og wc','obergeschoss wc','wc og','wc obergeschoss','og_wc','og pc', 'ogwc'],
        'floor' => 'OG',
        'domains' => [
            'jalousie' => [
                'fenster' => ['title' => 'OG WC','wert' => 47623,'order' => 320]
            ],
            'licht' => [
                'switches' => [
                    'wc_spiegel' => [
                        'title' => 'WC Spiegel',
                        'synonyms' => ['spiegel','spiegellampe','licht spiegel'],
                        'state' => 44872,
                        'toggle' => 48163,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_og_wc_wc_spiegel',
                        'order' => 10
                    ],
                    'wc_deckenlicht' => [
                        'title' => 'WC Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','hauptlicht','haupt','og wc','obergeschoss wc','wc og','wc obergeschoss','og_wc','og pc', 'ogwc'],
                        'state' => 28641,
                        'toggle' => 28493,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.og_og_wc_wc_deckenlicht',
                        'order' => 20
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'WC Helligkeit','value' => 35131,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ],
            'lueftung' => [
                'fans' => [
                    'wc' => ['title' => 'WC Lüfter','state' => 14580,'toggle' => 30915,'icon' => 'vent.png','entityId' => 'luefter.og_wc','order' => 10]
                ]
            ]
        ]
    ],
    'keller_stiegenhaus' => [
        'display' => 'Keller Stiegenhaus',
        'synonyms' => ['keller stiegenhaus','kellerstiegenhaus','keller_stiegenhaus', 'treppen'],
        'floor' => 'Keller',
        'domains' => [
            'heizung' => [
                'keller_stiegenhaus' => [
                    'title' => 'Keller Stiegenhaus',
                    'ist' => 52615,
                    'stellung' => 26604,
                    'eingestellt' => 47020,
                    'soll' => 50556,
                    'icon' => 'Stiegenhaus.png',
                    'entityId' => 'heizung.Keller_Stiegenhaus',
                    'order' => 50
                ]
            ],
            'licht' => [
                'switches' => [
                    'decke' => [
                        'title' => 'Stiegenhaus Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','treppe','hauptlicht', 'stiegenhaus', 'Stiegenhaus', 'treppe','stiege','stufen','treppen','treppe licht', 'treppen licht'],
                        'state' => 24473,
                        'toggle' => 33145,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.kg_stiegenhaus_decke',
                        'order' => 10
                    ],
                    'treppe' => [
                        'title' => 'Treppenlicht',
                        'synonyms' => [],
                        'state' => 18648,
                        'toggle' => 17110,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.kg_aussenTreppenlicht',
                        'order' => 30
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux_oben' => ['title' => 'Stiegenhaus oben','value' => 44977,'unit' => 'lx','icon' => 'lux.png','order' => 10],
                    'lux_unten' => ['title' => 'Stiegenhaus unten','value' => 15611,'unit' => 'lx','icon' => 'lux.png','order' => 11]
                ]
            ]
        ]
    ],
    'keller_gang' => [
        'display' => 'Keller Gang',
        'synonyms' => ['keller gang','kellergang','kgg','untergeschoss gang','ug gang','ug_gang'],
        'floor' => 'Keller',
        'domains' => [
            'heizung' => [
                'keller_gang' => [
                    'title' => 'Keller Gang',
                    'ist' => 57150,
                    'stellung' => 25353,
                    'eingestellt' => 33400,
                    'soll' => 26034,
                    'icon' => 'Keller_Gang.png',
                    'entityId' => 'heizung.Keller Gang',
                    'order' => 20
                ]
            ],
            'licht' => [
                'switches' => [
                    'gang_deckenlicht' => [
                        'title' => 'Keller Gang Deckenlicht',
                        'synonyms' => ['gang','flur','decke','deckenlicht','hauptlicht'],
                        'state' => 58477,
                        'toggle' => 45977,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.kg_keller_gang_gang_deckenlicht',
                        'order' => 10
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'Gang','value' => 47438,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ]
        ]
    ],
    'garderobe' => [
        'display' => 'EG Garderobe',
        'synonyms' => ['garderobe','schuhraum','eg garderobe','garderobe eg','garderobe decken'],
        'floor' => 'EG',
        'domains' => [
            'heizung' => [
                'garderobe' => [
                    'title' => 'EG Garderobe',
                    'ist' => 47637,
                    'stellung' => 19768,
                    'eingestellt' => 53986,
                    'soll' => 29436,
                    'icon' => 'Garderobe.png',
                    'entityId' => 'heizung.EG Garderobe',
                    'order' => 60
                ]
            ],
            'licht' => [
                'switches' => [
                    'zeitautomatik_dekolicht' => [
                        'title' => 'Garderobe Zeitautomatik Dekolicht',
                        'synonyms' => ['deko','dekolicht','zeitautomatik','automatik','zeit'],
                        'state' => 50694,
                        'toggle' => 50694,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_garderobe_zeitautomatik_dekolicht',
                        'order' => 10
                    ],
                    'deckenlicht' => [
                        'title' => 'Garderobe Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','hauptlicht','haupt', 'decken licht'],
                        'state' => 39399,
                        'toggle' => 12684,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_garderobe_deckenlicht',
                        'order' => 20
                    ],
                    'steckdose' => [
                        'title' => 'Garderobe Steckdose',
                        'synonyms' => ['steckdose','dose','stecker'],
                        'state' => 36268,
                        'toggle' => 36268,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.eg_garderobe_steckdose',
                        'order' => 30
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'Garderobe','value' => 32908,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ]
        ]
    ],
    'sauna' => [
        'display' => 'Sauna',
        'synonyms' => ['sauna'],
        'floor' => 'Keller',
        'domains' => [
            'heizung' => [
                'sauna' => [
                    'title' => 'Sauna',
                    'ist' => 28134,
                    'stellung' => 44016,
                    'eingestellt' => 28802,
                    'soll' => 26602,
                    'icon' => 'Sauna.png',
                    'entityId' => 'heizung.Sauna',
                    'order' => 10
                ]
            ],
            'licht' => [
                'switches' => [
                    'deckenlicht' => [
                        'title' => 'Sauna Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','hauptlicht','haupt'],
                        'state' => 39357,
                        'toggle' => 28209,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.kg_sauna_deckenlicht',
                        'order' => 10
                    ]
                ],
                'dimmers' => [
                    'deckenlicht_dimmen_wert' => [
                        'title' => 'Sauna Deckenlicht',
                        'synonyms' => ['decke','deckenlicht','dimmen','hauptlicht'],
                        'value' => 48506,
                        'set' => 50744,
                        'min' => 0,
                        'max' => 100,
                        'step' => 1,
                        'icon' => 'dim.png',
                        'entityId' => 'dim.kg_sauna_deckenlicht_dimmen_wert',
                        'order' => 10
                    ]
                ],
                'status' => [
                    'lux' => ['title' => 'Sauna','value' => 59114,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ],
            'lueftung' => [
                'fans' => [
                    'sauna' => ['title' => 'Sauna Lüfter','state' => 42350,'toggle' => 46196,'icon' => 'vent.png','entityId' => 'luefter.sauna','order' => 10]
                ]
            ]
        ]
    ],
    'lagerraum' => [
        'display' => 'Lagerraum',
        'synonyms' => ['lagerraum','lager','abstellraum','abstellkammer'],
        'floor' => 'Keller',
        'domains' => [
            'heizung' => [
                'lagerraum' => [
                    'title' => 'Lagerraum',
                    'ist' => 47138,
                    'stellung' => 30445,
                    'eingestellt' => 34620,
                    'soll' => 56708,
                    'icon' => 'Lagerraum.png',
                    'entityId' => 'heizung.Lagerraum',
                    'order' => 40
                ]
            ],
            'licht' => [
                'switches' => [
                    'decke' => [
                        'title' => 'Abstellraum Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','hauptlicht','lager'],
                        'state' => 19601,
                        'toggle' => 33332,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.kg_abstellraum',
                        'order' => 10
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'Abstellraum','value' => 55623,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ]
        ]
    ],
    'hobbyraum' => [
        'display' => 'Hobbyraum',
        'synonyms' => ['hobbyraum','bastelraum'],
        'floor' => 'Keller',
        'domains' => [
            'heizung' => [
                'hobbyraum' => [
                    'title' => 'Hobbyraum','ist' => 58438,'stellung' => 32550,'eingestellt' => 10699,'soll' => 46664,'icon' => 'Hobbyraum.png','entityId' => 'heizung.Hobbyraum','order' => 30
                ]
            ],
            'licht' => [
                'switches' => [
                    'decke' => [
                        'title' => 'Hobbyraum Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','hauptlicht', 'hobbyraum'],
                        'state' => 38174,
                        'toggle' => 11567,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.kg_hobby_decke',
                        'order' => 10
                    ],
                    'tuer_decke' => [
                        'title' => 'Hobbyraum Tür Deckenlicht',
                        'synonyms' => ['tür','tuer','eingang','decke tür','hobbyraum tür'],
                        'state' => 51419,
                        'toggle' => 51419,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.kg_hobby_tuer',
                        'order' => 20
                    ],
                    'werkbank' => [
                        'title' => 'Hobbyraum Werkbank',
                        'synonyms' => ['werkbank','arbeitsplatz','bank'],
                        'state' => 28366,
                        'toggle' => 42907,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.kg_hobby_werkbank',
                        'order' => 30
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'Hobbyraum','value' => 42660,'unit' => 'lx','icon' => 'lux.png','order' => 10],
                    'lux2' => ['title' => 'Hobbyraum Tür','value' => 10573,'unit' => 'lx','icon' => 'lux.png','order' => 11]
                ]
            ],
            'lueftung' => [
                'fans' => [
                    'hobby' => ['title' => 'Hobbyraum Lüfter','state' => 31936,'toggle' => 31936,'icon' => 'vent.png','entityId' => 'luefter.hobbyraum','order' => 10]
                ]
            ]
        ]
    ],
   'technikraum' => [
        'display' => 'Technikraum',
        'synonyms' => ['technikraum','technik'],
        'floor' => 'Keller',
        'domains' => [
            'licht' => [
                'switches' => [
                    'decke' => [
                        'title' => 'Technikraum Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','technik','hauptlicht'],
                        'state' => 24746,
                        'toggle' => 49798,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.kg_technik',
                        'order' => 10
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'Technikraum','value' => 51954,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ]
        ]
    ],
    'heizraum' => [
        'display' => 'Heizraum',
        'synonyms' => ['heizraum','heizungskeller','waschraum','waschmaschine','wäscheraum'],
        'floor' => 'Keller',
        'domains' => [
            'licht' => [
                'switches' => [
                    'decke' => [
                        'title' => 'Heizraum Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','hauptlicht'],
                        'state' => 45092,
                        'toggle' => 55256,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.kg_heizraum',
                        'order' => 10
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'Heizraum','value' => 26951,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ],
            'devices' => [
                    'tabs' => [
                      'Waschtrockner' => ['id' => 58379, 'order' => 80,
                        'synonyms'    => ['waschmaschine','trockner','wäsche','wäschetrockner']],
                  ]
                ]
        ]
    ],
    'Außen' => [
        'display' => 'Außen',
        'synonyms' => ['außen','aussen','aussenbereich','garten','draußen'],
        'floor' => 'AUSSEN',
        'domains' => [
            'licht' => [
                'switches' => [
                    'deckenlicht_alle' => [
                        'title' => 'Außen Deckenlicht alle',
                        'synonyms' => ['decke','decken','alle','gesamte außen','außenlicht','aussenlicht'],
                        'state' => 30271,
                        'toggle' => 13929,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.aussen_deckenlicht_alle',
                        'order' => 10
                    ],
                    'blumentrog_alle' => [
                        'title' => 'Außen Blumentrog alle',
                        'synonyms' => ['blumentrog','tröge','troege','alle troege','alle blumentröge'],
                        'state' => 33467,
                        'toggle' => 33467,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.aussen_blumentrog_alle',
                        'order' => 20
                    ],
                    'blumentrog_links' => [
                        'title' => 'Außen Blumentrog links',
                        'synonyms' => ['blumentrog links','links trog','linker trog'],
                        'state' => 38378,
                        'toggle' => 38378,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.aussen_blumentrog_links',
                        'order' => 30
                    ],
                    'blumentrog_mitte' => [
                        'title' => 'Außen Blumentrog mitte',
                        'synonyms' => ['blumentrog mitte','mittlerer trog','mitte trog'],
                        'state' => 40854,
                        'toggle' => 40854,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.aussen_blumentrog_mitte',
                        'order' => 40
                    ],
                    'blumentrog_rechts' => [
                        'title' => 'Außen Blumentrog rechts',
                        'synonyms' => ['blumentrog rechts','rechter trog','rechts trog'],
                        'state' => 54185,
                        'toggle' => 54185,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.aussen_blumentrog_rechts',
                        'order' => 50
                    ],
                    'einfahrt' => [
                        'title' => 'Außen Einfahrtlicht',
                        'synonyms' => ['einfahrt','zufahrt','auffahrt','parkplatz'],
                        'state' => 31020,
                        'toggle' => 25512,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.aussen_einfahrt',
                        'order' => 60
                    ],
                    'tuer_decke' => [
                        'title' => 'Außen Tür Deckenlicht',
                        'synonyms' => ['tür','tuer','haustür','eingang','decke tür'],
                        'state' => 37634,
                        'toggle' => 37634,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.aussen_tuer_decke',
                        'order' => 70
                    ]
                ],
                'dimmers' => [],
                'status' => [
                    'lux' => ['title' => 'Aussen Nord','value' => 43600,'unit' => 'lx','icon' => 'lux.png','order' => 10]
                ]
            ],
            'sprinkler' => [
                    'tabs' => [
                      'Bewässerung' => ['id' => 41578, 'order' => 10,
                        'synonyms'    => ['garten','bewässerung','bewaesserung']],
                  ]
                ]
        ]
    ],
    'dachgeschoss' => [
        'display' => 'Dachgeschoß',
        'synonyms' => ['dachgeschoss','dachboden'],
        'floor' => 'DG',
        'domains' => [
            'licht' => [
                'switches' => [
                    'decke' => [
                        'title' => 'Dachboden Deckenlicht',
                        'synonyms' => ['decke','decken','deckenlicht','dachboden','bodenlicht','hauptlicht'],
                        'state' => 40622,
                        'toggle' => 49813,
                        'iconOn' => 'bulb_on.png',
                        'iconOff' => 'bulb_off.png',
                        'entityId' => 'light.kg_heizraum',
                        'order' => 10
                    ]
                ],
                'dimmers' => [],
                'status' => []
            ]
        ]
    ],
    'laptop' => [
        'display' => 'Laptop',
        'synonyms' => ['laptop'],
        'domains' => [
            'heizung' => [
                'laptop' => [
                    'title' => 'Test Laptop',
                    'ist' => 28944,
                    'stellung' => 10810,
                    'eingestellt' => 33161,
                    'soll' => 33161,
                    'icon' => 'laptop.png',
                    'entityId' => 'laptop.Büro',
                    'order' => 110
                ]
            ],
            'licht' => ['switches' => [],'dimmers' => [],'status' => []]
        ]
    ],


    'global' => [
        'jalousie' => [
            'icon' => 'JalousieIcon.png',
            'open_close_var' => 53051,
            'scenes' => [
                'og' => ['var' => 56751,'title' => 'Obergeschoss'],
                'eg_nowg' => ['var' => 10276,'title' => 'EG ohne Wintergarten'],
                'wg_nt' => ['var' => 57033,'title' => 'Wintergarten ohne Tür'],
                'wz' => ['var' => 24514,'title' => 'Wohnzimmer'],
                'all' => ['var' => 40172,'title' => 'Alle zusammen']
            ]
        ],
        'licht' => [
            'scenes' => [
                ['section' => 'EG Szenen','sectionH' => '2.6vw','sectionFont' => '1.2vw','sectionBold' => true,'sectionPadY' => '0.4vw'],
                ['title' => 'Led Küche','id' => 'scene.eg_led_kueche','actions' => [
                    ['label' => 'Öffnen','color' => '#2ECC71','args' => ['GetHaus','öffnen','scene.eg_led_kueche']],
                    ['label' => 'Schliessen','color' => '#3C414A','args' => ['GetHaus','schliessen','scene.eg_led_kueche']],
                    ['label' => 'Party','color' => '#8A2BE2','args' => ['GetHaus','party','scene.eg_led_kueche']]
                ]],
                ['section' => 'OG Szenen','sectionH' => '2.6vw','sectionFont' => '1.2vw','sectionBold' => true,'sectionPadY' => '0.4vw'],
                ['title' => 'Led Whirlpool','id' => 'scene.og_led_whirlpool','actions' => [
                    ['label' => 'Blau','color' => '#3B82F6','args' => ['GetHaus','color','scene.og_led_whirlpool','blue']],
                    ['label' => 'Grün','color' => '#10B981','args' => ['GetHaus','color','scene.og_led_whirlpool','green']],
                    ['label' => 'Rot','color' => '#EF4444','args' => ['GetHaus','color','scene.og_led_whirlpool','red']],
                    ['label' => 'Weiß','color' => '#9CA3AF','args' => ['GetHaus','color','scene.og_led_whirlpool','white']]
                ]]
            ]
        ],
        'lueftung' => [
            'icon' => 'VentIcon.png',
            'central_title' => 'Zentrale Lüftung',
            'status_colors' => ['on' => '@pillOpen','off' => '@pillClose','boost' => '#ff7a00'],
            'default_buttons' => [
                ['label' => 'An','color' => '@pillOpen','argsTpl' => ['Ventilation','toggle','${entityId}','on']],
                ['label' => 'Aus','color' => '@pillClose','argsTpl' => ['Ventilation','toggle','${entityId}','off']]
            ],
            'central' => [
                ['title' => 'Stiegenhaus Fenster','entityId' => 'vent.central','statusText' => 'Aus','statusDetail' => 'Manuell aktiv','statusLevel' => 'off','icon' => 'stiege.png','order' => 10]
            ]
        ],
        'floors' => [
            'order' => ['Keller','EG','OG','DG','AUSSEN'],
            'labels' => ['Keller' => 'Keller','EG' => 'EG','OG' => 'OG','DG' => 'DG','AUSSEN' => 'Außen'],
            'section' => ['height' => '3.4vw','fontSize' => '1.4vw','bold' => false,'padY' => '0.8vw']
        ],
        'domains' => [
            'licht' => ['switches' => [],'dimmers' => [],'status' => []]
        ],
        'external_pages' => [
            'energie' => [
                'title'     => 'Energie',
                'logo'      => 'Energie.png',
                'pageIdVar' => 'EnergiePageId',
                'actions'   => ['energie'],
                'navs'      => ['external.energy-main', 'energie'],
            ],
            'kamera' => [
                'title'     => 'Kamera',
                'logo'      => 'Kamera.png',
                'pageIdVar' => 'KameraPageId',
                'actions'   => ['kamera'],
                'navs'      => ['kamera.energy-main', 'kamera'],
            ],
        ],
    ]
];
