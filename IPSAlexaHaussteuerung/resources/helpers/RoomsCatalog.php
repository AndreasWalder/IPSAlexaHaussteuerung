<?php
return array (
  'buero' => 
  array (
    'display' => 'Büro',
    'domains' => 
    array (
      'heizung' => 
      array (
        'buero' => 
        array (
          'title' => 'Büro',
          'ist' => 28944,
          'stellung' => 10810,
          'eingestellt' => 33161,
          'soll' => 33161,
          'icon' => 'Buero.png',
          'entityId' => 'heizung.Büro',
          'order' => 110,
        ),
      ),
      'jalousie' => 
      array (
        'fenster' => 
        array (
          'title' => 'Büro',
          'wert' => 28160,
          'order' => 80,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'decke_all' => 
          array (
            'title' => 'Büro Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht, haupt, alle, gesamt',
            'state' => 38988,
            'toggle' => 39641,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_buero_all',
            'order' => 10,
          ),
        ),
        'dimmers' => 
        array (
          'all' => 
          array (
            'title' => 'Büro Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht, haupt, alle, gesamt, dimmen',
            'value' => 47227,
            'set' => 47227,
            'min' => 0,
            'max' => 100,
            'step' => 1,
            'icon' => 'dim.png',
            'entityId' => 'dim.eg_buero_all',
            'order' => 20,
          ),
          'links' => 
          array (
            'title' => 'Büro Deckenlicht links',
            'synonyms' => 'decke links, links, linke seite, linker kreis',
            'value' => 13073,
            'set' => 13073,
            'min' => 0,
            'max' => 100,
            'step' => 1,
            'icon' => 'dim.png',
            'entityId' => 'dim.eg_buero_links',
            'order' => 21,
          ),
          'mitte' => 
          array (
            'title' => 'Büro Deckenlicht mitte',
            'synonyms' => 'decke mitte, mitte, mittig, zentral',
            'value' => 29962,
            'set' => 29962,
            'min' => 0,
            'max' => 100,
            'step' => 1,
            'icon' => 'dim.png',
            'entityId' => 'dim.eg_buero_mitte',
            'order' => 22,
          ),
          'rechts' => 
          array (
            'title' => 'Büro Deckenlicht rechts',
            'synonyms' => 'decke rechts, rechts, rechte seite, rechter kreis',
            'value' => 33326,
            'set' => 33326,
            'min' => 0,
            'max' => 100,
            'step' => 1,
            'icon' => 'dim.png',
            'entityId' => 'dim.eg_buero_rechts',
            'order' => 23,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Büro',
            'value' => 53445,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'EG',
  ),
  'kueche' => 
  array (
    'display' => 'Küche',
    'domains' => 
    array (
      'heizung' => 
      array (
        'kueche' => 
        array (
          'title' => 'Küche',
          'ist' => 21992,
          'stellung' => 16682,
          'eingestellt' => 58652,
          'soll' => 58652,
          'icon' => 'Kueche.png',
          'entityId' => 'heizung.Küche',
          'order' => 80,
        ),
      ),
      'jalousie' => 
      array (
        'fenster' => 
        array (
          'title' => 'Küche Fenster',
          'wert' => 46924,
          'order' => 90,
        ),
        'westlinks' => 
        array (
          'title' => 'Küche West links',
          'wert' => 19129,
          'order' => 100,
        ),
        'westrechts' => 
        array (
          'title' => 'Küche West rechts',
          'wert' => 25136,
          'order' => 110,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'deckenlicht_alle' => 
          array (
            'title' => 'Küche Deckenlicht alle',
            'synonyms' => 'decke, decken, alle, hauptlicht, gesamt, küche, kueche, küchen, kuechen, eg küche, eg kueche, kueche',
            'state' => 51838,
            'toggle' => 41292,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_kueche_deckenlicht_alle',
            'order' => 40,
          ),
          'theke_und_schlange' => 
          array (
            'title' => 'Küche Theke und Schlange',
            'synonyms' => 'theke und schlange, theke, schlange, arbeitsplatte, unterbau',
            'state' => 52923,
            'toggle' => 14841,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_kueche_theke_und_schlange',
            'order' => 110,
          ),
          'licht_anrichte' => 
          array (
            'title' => 'Küche Licht Anrichte',
            'synonyms' => 'anrichte, vitrine, board, kommode',
            'state' => 33911,
            'toggle' => 42379,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_kueche_licht_anrichte',
            'order' => 30,
          ),
          'schlange' => 
          array (
            'title' => 'Küche Schlange',
            'synonyms' => 'schlange, led streifen, streifen',
            'state' => 53419,
            'toggle' => 53419,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_kueche_schlange',
            'order' => 50,
          ),
          'licht_speiss' => 
          array (
            'title' => 'Küche Licht Speiß',
            'synonyms' => 'speis, speiß, speisekammer',
            'state' => 52340,
            'toggle' => 50803,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_kueche_licht_speiss',
            'order' => 60,
          ),
          'licht_theke' => 
          array (
            'title' => 'Küche Licht Theke',
            'synonyms' => 'theke, bar, tresen',
            'state' => 16252,
            'toggle' => 48901,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_kueche_licht_theke',
            'order' => 70,
          ),
          'licht_anrichte_davor' => 
          array (
            'title' => 'Küche Licht Anrichte davor',
            'synonyms' => 'anrichte davor, vitrine vorne, board vorne',
            'state' => 10945,
            'toggle' => 31887,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_kueche_licht_anrichte_davor',
            'order' => 80,
          ),
          'licht_gang' => 
          array (
            'title' => 'Küche Licht Gang',
            'synonyms' => 'gang, flur, durchgang',
            'state' => 56135,
            'toggle' => 17037,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_kueche_licht_gang',
            'order' => 90,
          ),
          'licht_geraete' => 
          array (
            'title' => 'Küche Licht Geräte',
            'synonyms' => 'geräte, geraete, küchengeräte, arbeitsgeräte',
            'state' => 31090,
            'toggle' => 58576,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_kueche_licht_geraete',
            'order' => 100,
          ),
        ),
        'dimmers' => 
        array (
          'anrichte' => 
          array (
            'title' => 'Küche Anrichte',
            'synonyms' => 'anrichte, vitrine, board, kommode, dimmen',
            'value' => 28194,
            'set' => 28194,
            'state' => 33911,
            'min' => 0,
            'max' => 100,
            'step' => 1,
            'icon' => 'dim.png',
            'entityId' => 'dim.eg_kueche_anrichte_v',
            'order' => 40,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Küche vorne',
            'value' => 40860,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 11,
          ),
        ),
      ),
      'devices' => 
      array (
        'tabs' => 
        array (
          'Dampf-Backofen' => 
          array (
            'id' => 32701,
            'order' => 10,
            'synonyms' => 'dampf-backofen, dampfbackofen, dampfofen, backofendampf',
          ),
          'Geschirrspüler' => 
          array (
            'id' => 16021,
            'order' => 20,
            'synonyms' => 'geschirrspueler, geschirr-spüler, spuelmaschine, spülmaschine, spüler, geschirrspüler',
          ),
          'Kaffeevollautomat' => 
          array (
            'id' => 33267,
            'order' => 30,
            'synonyms' => 'kaffee, kaffeeautomat, vollautomat',
          ),
          'Kochfeld' => 
          array (
            'id' => 21810,
            'order' => 40,
            'synonyms' => 'kochfeld, herd, kochplatte',
          ),
          'Micro-Backofen' => 
          array (
            'id' => 15080,
            'order' => 50,
            'synonyms' => 'mikrowelle, micro, mikro, microbackofen',
          ),
          'Gefrierschrank' => 
          array (
            'id' => 35524,
            'order' => 60,
            'synonyms' => 'gefrierschrank, gefrier',
          ),
          'Kühlschrank' => 
          array (
            'id' => 58614,
            'order' => 70,
            'synonyms' => 'kühlschrank, kuehlschrank',
          ),
        ),
      ),
    ),
    'floor' => 'EG',
  ),
  'eg_wc' => 
  array (
    'display' => 'EG WC',
    'domains' => 
    array (
      'jalousie' => 
      array (
        'fenster' => 
        array (
          'title' => 'EG WC',
          'wert' => 18915,
          'order' => 80,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'wc_spiegel' => 
          array (
            'title' => 'WC Spiegel',
            'synonyms' => 'spiegel, spiegellampe, licht spiegel',
            'state' => 42954,
            'toggle' => 15786,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_eg_wc_wc_spiegel',
            'order' => 10,
          ),
          'wc_deckenlicht_schlaten' => 
          array (
            'title' => 'WC Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht, haupt, eg wc, erdgeschoss wc, wc eg, wc erdgeschoss, eg_wc, eg pc, egwc',
            'state' => 57478,
            'toggle' => 56065,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_eg_wc_wc_deckenlicht_schlaten',
            'order' => 20,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'WC Helligkeit',
            'value' => 15524,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
      'lueftung' => 
      array (
        'fans' => 
        array (
          'wc' => 
          array (
            'title' => 'EG WC Lüfter',
            'state' => 49570,
            'toggle' => 30979,
            'icon' => 'vent.png',
            'entityId' => 'luefter.eg_wc',
            'order' => 10,
          ),
          'wc_boost' => 
          array (
            'title' => 'EG WC Lüfter stark',
            'state' => 50093,
            'toggle' => 17791,
            'icon' => 'vent.png',
            'entityId' => 'luefter.eg_wc',
            'order' => 11,
          ),
        ),
      ),
    ),
    'floor' => 'EG',
  ),
  'eg_gang' => 
  array (
    'display' => 'EG Gang',
    'domains' => 
    array (
      'heizung' => 
      array (
        'gang' => 
        array (
          'title' => 'Gang',
          'ist' => 16128,
          'stellung' => 19429,
          'eingestellt' => 13130,
          'soll' => 13130,
          'icon' => 'Keller_Gang.png',
          'entityId' => 'gang.Elternzimmer',
          'order' => 155,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'gang_deckenlicht' => 
          array (
            'title' => 'EG Gang Deckenlicht',
            'synonyms' => 'gang, flur, decke, deckenlicht, hauptlicht, eg gang, erdgeschoss, egg, erdgeschoss gang, eg_gang',
            'state' => 57156,
            'toggle' => 57156,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_gang_deckenlicht',
            'order' => 11,
          ),
        ),
      ),
    ),
    'floor' => 'EG',
  ),
  'og_gang' => 
  array (
    'display' => 'OG Gang',
    'domains' => 
    array (
      'licht' => 
      array (
        'switches' => 
        array (
          'deckenlicht' => 
          array (
            'title' => 'OG Gang Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht, gang, flur, og gang, obergeschoss gang',
            'state' => 55908,
            'toggle' => 57559,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_gang_deckenlicht',
            'order' => 10,
          ),
          'deckenlicht_hinten' => 
          array (
            'title' => 'OG Gang Deckenlicht hinten',
            'synonyms' => 'decke hinten, hinten, hinterer gang, flur hinten',
            'state' => 23215,
            'toggle' => 49634,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_gang_deckenlicht_hinten',
            'order' => 20,
          ),
          'gang_und_stiege_led_oben' => 
          array (
            'title' => 'OG Gang und Stiege Deckenlicht und LED oben',
            'synonyms' => 'stiege, treppe, stiege oben, treppe oben, led oben',
            'state' => 51727,
            'toggle' => 11930,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_gang_und_stiege_led_oben',
            'order' => 30,
          ),
          'vitrine_steckdose' => 
          array (
            'title' => 'OG Gang Vitrine Steckdose',
            'synonyms' => 'vitrine, steckdose, dose, board',
            'state' => 37942,
            'toggle' => 15804,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_gang_vitrine_steckdose',
            'order' => 40,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'OG Gang',
            'value' => 56130,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
          'lux_hinten' => 
          array (
            'title' => 'OG Gang hinten',
            'value' => 33072,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 11,
          ),
        ),
      ),
    ),
    'floor' => 'OG',
  ),
  'wintergarten' => 
  array (
    'display' => 'Wintergarten',
    'domains' => 
    array (
      'heizung' => 
      array (
        'wintergarten' => 
        array (
          'title' => 'Wintergarten',
          'ist' => 54131,
          'stellung' => 20405,
          'eingestellt' => 37885,
          'soll' => 31344,
          'icon' => 'Wintergarten.png',
          'entityId' => 'heizung.Wintergarten',
          'order' => 90,
        ),
      ),
      'jalousie' => 
      array (
        'tuer' => 
        array (
          'title' => 'Wintergarten Tür',
          'wert' => 20090,
          'order' => 120,
        ),
        'mitte_links' => 
        array (
          'title' => 'Wintergarten Mitte links',
          'wert' => 58722,
          'order' => 130,
        ),
        'mitte_rechts' => 
        array (
          'title' => 'Wintergarten Mitte rechts',
          'wert' => 51640,
          'order' => 140,
        ),
        'westlinks' => 
        array (
          'title' => 'Wintergarten West links',
          'wert' => 45231,
          'order' => 150,
        ),
        'westrechts' => 
        array (
          'title' => 'Wintergarten West rechts',
          'wert' => 35759,
          'order' => 160,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'deckenlicht_kreis_vorne' => 
          array (
            'title' => 'Wintergarten Deckenlicht Kreis vorne',
            'synonyms' => 'decke vorne, kreis vorne, vorderer kreis, vorn',
            'state' => 56807,
            'toggle' => 56807,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wintergarten_deckenlicht_kreis_vorne',
            'order' => 10,
          ),
          'spot_glasschrank_oben' => 
          array (
            'title' => 'Wintergarten Spot Glasschrank oben',
            'synonyms' => 'glasschrank oben, spot oben, vitrine oben',
            'state' => 21428,
            'toggle' => 34583,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wintergarten_spot_glasschrank_oben',
            'order' => 20,
          ),
          'spot_glasschrank_unten' => 
          array (
            'title' => 'Wintergarten Spot Glasschrank unten',
            'synonyms' => 'glasschrank unten, spot unten, vitrine unten',
            'state' => 24925,
            'toggle' => 17933,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wintergarten_spot_glasschrank_unten',
            'order' => 30,
          ),
          'spot_glasschrank_oben_und_unten' => 
          array (
            'title' => 'Wintergarten Spot Glasschrank oben und unten',
            'synonyms' => 'glasschrank beide, vitrine beide, spots beide',
            'state' => 21428,
            'toggle' => 33000,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wintergarten_spot_glasschrank_oben_und_unten',
            'order' => 40,
          ),
          'deckenlicht_kreis_hinten' => 
          array (
            'title' => 'Wintergarten Deckenlicht Kreis hinten',
            'synonyms' => 'decke hinten, kreis hinten, hinterer kreis, hinten',
            'state' => 34411,
            'toggle' => 34411,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wintergarten_deckenlicht_kreis_hinten',
            'order' => 50,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Wintergarten',
            'value' => 44157,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
      'lueftung' => 
      array (
        'fans' => 
        array (
          'wg' => 
          array (
            'title' => 'Wintergarten Lüfter Ofen',
            'state' => 47321,
            'toggle' => 47321,
            'icon' => 'vent.png',
            'entityId' => 'luefter.wintergarten',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'EG',
  ),
  'wohnzimmer' => 
  array (
    'display' => 'Wohnzimmer',
    'domains' => 
    array (
      'heizung' => 
      array (
        'wohnzimmer' => 
        array (
          'title' => 'Wohnzimmer',
          'ist' => 57012,
          'stellung' => 14944,
          'eingestellt' => 23640,
          'soll' => 23640,
          'icon' => 'Wohnzimmer.png',
          'entityId' => 'heizung.Wohnzimmer',
          'order' => 100,
        ),
      ),
      'jalousie' => 
      array (
        'ostlinks' => 
        array (
          'title' => 'Wohnzimmer Ost links',
          'wert' => 25770,
          'order' => 170,
        ),
        'ostrechts' => 
        array (
          'title' => 'Wohnzimmer Ost rechts',
          'wert' => 44357,
          'order' => 180,
        ),
        'tuer' => 
        array (
          'title' => 'Wohnzimmer Tür',
          'wert' => 12353,
          'order' => 190,
        ),
        'tuer_links' => 
        array (
          'title' => 'Wohnzimmer Tür links',
          'wert' => 59620,
          'order' => 200,
        ),
        'tuer_rechts' => 
        array (
          'title' => 'Wohnzimmer Tür rechts',
          'wert' => 13162,
          'order' => 210,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'steckdose_west_ablage' => 
          array (
            'title' => 'Wohnzimmer Steckdose West Ablage',
            'synonyms' => 'steckdose ablage, west ablage, ablage',
            'state' => 32787,
            'toggle' => 26858,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wohnzimmer_steckdose_west_ablage',
            'order' => 10,
          ),
          'rechts_deckenlicht' => 
          array (
            'title' => 'Wohnzimmer Rechts Deckenlicht',
            'synonyms' => 'decke rechts, rechts, rechte seite',
            'state' => 56851,
            'toggle' => 28625,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wohnzimmer_rechts_deckenlicht',
            'order' => 20,
          ),
          'mitte_links_deckenlicht' => 
          array (
            'title' => 'Wohnzimmer Mitte Links Deckenlicht',
            'synonyms' => 'decke mitte links, mitte links, mittlere links',
            'state' => 23380,
            'toggle' => 12727,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wohnzimmer_mitte_links_deckenlicht',
            'order' => 30,
          ),
          'links_deckenlicht' => 
          array (
            'title' => 'Wohnzimmer Links Deckenlicht',
            'synonyms' => 'decke links, links, linke seite',
            'state' => 36213,
            'toggle' => 18011,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wohnzimmer_links_deckenlicht',
            'order' => 40,
          ),
          'weihnachtsbeleuchtung_steckdose' => 
          array (
            'title' => 'Wohnzimmer Weihnachts Steckdose',
            'synonyms' => 'weihnachten, weihnachtsbeleuchtung, weihnachts steckdose, xmas',
            'state' => 57862,
            'toggle' => 26100,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wohnzimmer_weihnachtsbeleuchtung_steckdose',
            'order' => 50,
          ),
          'l_eingang' => 
          array (
            'title' => 'Wohnzimmer L Eingang',
            'synonyms' => 'eingang, eingangslicht, l eingang',
            'state' => 42565,
            'toggle' => 42565,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wohnzimmer_l_eingang',
            'order' => 60,
          ),
          'vorne_deckenlicht' => 
          array (
            'title' => 'Wohnzimmer vorne Deckenlicht',
            'synonyms' => 'decke vorne, vorne',
            'state' => 16161,
            'toggle' => 54123,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wohnzimmer_vorne_deckenlicht',
            'order' => 70,
          ),
          'mitte_rechts_deckenlicht' => 
          array (
            'title' => 'Wohnzimmer Mitte Rechts Deckenlicht',
            'synonyms' => 'decke mitte rechts, mitte rechts, mittlere rechts',
            'state' => 38930,
            'toggle' => 38894,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wohnzimmer_mitte_rechts_deckenlicht',
            'order' => 80,
          ),
          'keyboard_deckenlicht' => 
          array (
            'title' => 'Wohnzimmer Keyboard Deckenlicht',
            'synonyms' => 'keyboard, klavier, piano, instrument',
            'state' => 35334,
            'toggle' => 24753,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wohnzimmer_keyboard_deckenlicht',
            'order' => 90,
          ),
          'alle_hue' => 
          array (
            'title' => 'Wohnzimmer alle Hue',
            'synonyms' => 'alle hue, hue alle, philips hue, hue, wohnzimmer, wohnen, wz, eg wohnzimmer',
            'state' => 20946,
            'toggle' => 20946,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wohnzimmer_alle_hue',
            'order' => 100,
          ),
          'fernsehen_licht' => 
          array (
            'title' => 'Wohnzimmer Fernsehen Licht',
            'synonyms' => 'fernsehen, tv, fernseher, media',
            'state' => 55752,
            'toggle' => 55752,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_wohnzimmer_fernsehen_licht',
            'order' => 110,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Wohnzimmer hinten',
            'value' => 22692,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 12,
          ),
        ),
      ),
    ),
    'floor' => 'EG',
  ),
  'bad' => 
  array (
    'display' => 'Bad',
    'domains' => 
    array (
      'heizung' => 
      array (
        'bad' => 
        array (
          'title' => 'Bad',
          'ist' => 47701,
          'stellung' => 39137,
          'eingestellt' => 49965,
          'soll' => 37111,
          'icon' => 'Bad.png',
          'entityId' => 'heizung.Bad',
          'order' => 120,
        ),
      ),
      'jalousie' => 
      array (
        'fenster' => 
        array (
          'title' => 'OG Bad',
          'wert' => 22239,
          'order' => 220,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'waschbecken_dusche_led_und_whirlpool_led' => 
          array (
            'title' => 'Bad Licht alle',
            'synonyms' => 'alle, gesamt, bad alle, alles an',
            'state' => 50009,
            'toggle' => 50009,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_bad_waschbecken_dusche_led_und_whirlpool_led',
            'order' => 10,
          ),
          'waschbecken_deckenlicht' => 
          array (
            'title' => 'Bad Waschbecken Deckenlicht',
            'synonyms' => 'waschbecken, spiegel, becken, decke waschbecken, bad, badezimmer, og bad',
            'state' => 55110,
            'toggle' => 59572,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_bad_waschbecken_deckenlicht',
            'order' => 20,
          ),
          'duche_deckenlicht' => 
          array (
            'title' => 'Bad Duche Deckenlicht',
            'synonyms' => 'dusche, decke dusche, duschlicht',
            'state' => 48012,
            'toggle' => 22033,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_bad_duche_deckenlicht',
            'order' => 30,
          ),
          'whirlpool_deckenlicht' => 
          array (
            'title' => 'Bad Whirlpool Deckenlicht',
            'synonyms' => 'whirlpool, wanne, badewanne',
            'state' => 49863,
            'toggle' => 33392,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_bad_whirlpool_deckenlicht',
            'order' => 40,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Bad',
            'value' => 56521,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
      'lueftung' => 
      array (
        'fans' => 
        array (
          'bad' => 
          array (
            'title' => 'Bad Lüfter',
            'state' => 15839,
            'toggle' => 12445,
            'icon' => 'vent.png',
            'entityId' => 'luefter.bad',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'OG',
  ),
  'eltern' => 
  array (
    'display' => 'Elternzimmer',
    'domains' => 
    array (
      'heizung' => 
      array (
        'eltern' => 
        array (
          'title' => 'Elternzimmer',
          'ist' => 45022,
          'stellung' => 12979,
          'eingestellt' => 43582,
          'soll' => 43582,
          'icon' => 'Elternzimmer.png',
          'entityId' => 'heizung.Elternzimmer',
          'order' => 150,
        ),
      ),
      'jalousie' => 
      array (
        'tuer' => 
        array (
          'title' => 'Elternzimmer Tür',
          'wert' => 48883,
          'order' => 230,
        ),
        'fenster' => 
        array (
          'title' => 'Elternzimmer Fenster',
          'wert' => 44078,
          'order' => 240,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'leselicht_links' => 
          array (
            'title' => 'Elternzimmer Leselicht links',
            'synonyms' => 'lese links, nachtlicht links, bett links',
            'state' => 10927,
            'toggle' => 21594,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_eltern_leselicht_links',
            'order' => 10,
          ),
          'vitrinelicht' => 
          array (
            'title' => 'Elternzimmer Vitrinelicht',
            'synonyms' => 'vitrine, schrank vitrine, schranklicht',
            'state' => 34323,
            'toggle' => 47228,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_eltern_vitrinelicht',
            'order' => 20,
          ),
          'kastenlicht' => 
          array (
            'title' => 'Elternzimmer Kastenlicht',
            'synonyms' => 'kasten, kleiderschrank, schrank, eltern, elternzimmer',
            'state' => 13133,
            'toggle' => 14679,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_eltern_kastenlicht',
            'order' => 30,
          ),
          'leselicht_rechts' => 
          array (
            'title' => 'Elternzimmer Leselicht rechts',
            'synonyms' => 'lese rechts, nachtlicht rechts, bett rechts',
            'state' => 31099,
            'toggle' => 54341,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_eltern_leselicht_rechts',
            'order' => 40,
          ),
          'led_bett' => 
          array (
            'title' => 'Elternzimmer LED Bett',
            'synonyms' => 'bett led, bettlicht, unterbett',
            'state' => 59968,
            'toggle' => 56533,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_eltern_led_bett',
            'order' => 50,
          ),
        ),
        'dimmers' => 
        array (
          'vitrinelicht_dimmen_wert' => 
          array (
            'title' => 'Elternzimmer Vitrinelicht',
            'synonyms' => 'vitrine, vitrine dimmen, schrank vitrine dimmen',
            'value' => 16849,
            'set' => 16849,
            'min' => 0,
            'max' => 100,
            'step' => 1,
            'icon' => 'dim.png',
            'entityId' => 'dim.og_eltern_vitrinelicht_dimmen_wert',
            'order' => 10,
          ),
          'kastenlicht_dimmen_wert' => 
          array (
            'title' => 'Elternzimmer Kastenlicht',
            'synonyms' => 'kasten, kleiderschrank, schrank, kasten dimmen',
            'value' => 29625,
            'set' => 29625,
            'min' => 0,
            'max' => 100,
            'step' => 1,
            'icon' => 'dim.png',
            'entityId' => 'dim.og_eltern_kastenlicht_dimmen_wert',
            'order' => 20,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Elternzimmer',
            'value' => 15714,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'OG',
  ),
  'kinder_gross' => 
  array (
    'display' => 'Kinderzimmer groß',
    'domains' => 
    array (
      'heizung' => 
      array (
        'kinder_gross' => 
        array (
          'title' => 'Kinderzimmer groß',
          'ist' => 33371,
          'stellung' => 54339,
          'eingestellt' => 21691,
          'soll' => 15389,
          'icon' => 'Kinderzimmer_groß.png',
          'entityId' => 'heizung.Kinderzimmer groß',
          'order' => 140,
        ),
      ),
      'jalousie' => 
      array (
        'sued' => 
        array (
          'title' => 'Kinderzimmer groß Fenster',
          'wert' => 30210,
          'order' => 250,
        ),
        'sued_tuer' => 
        array (
          'title' => 'Kinderzimmer groß Tür',
          'wert' => 50929,
          'order' => 260,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'deckenlicht' => 
          array (
            'title' => 'Kinderzimmer groß Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht, haupt, kinderzimmer groß, kinderzimmer gross, kinderzimmergroß, kinderzimmergross, kizi groß, kizi gross, og kinderzimmer groß',
            'state' => 58708,
            'toggle' => 28815,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_kinder_gross_deckenlicht',
            'order' => 10,
          ),
          'weihnachtsbeleuchtung' => 
          array (
            'title' => 'Kinderzimmer groß Weihnachts',
            'synonyms' => 'weihnachten, xmas, weihnachtsbeleuchtung',
            'state' => 33659,
            'toggle' => 27732,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_kinder_gross_weihnachtsbeleuchtung',
            'order' => 20,
          ),
          'balkonlicht' => 
          array (
            'title' => 'Kinderzimmer_groß Balkonlicht',
            'synonyms' => 'balkon, außen, aussen',
            'state' => 34229,
            'toggle' => 34229,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_kinder_gross_balkonlicht',
            'order' => 30,
          ),
          'schreibtischlampe' => 
          array (
            'title' => 'Kinderzimmer groß Schreibtischlampe',
            'synonyms' => 'schreibtisch, tischlampe, arbeitsplatz',
            'state' => 47850,
            'toggle' => 11337,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_kinder_gross_schreibtischlampe',
            'order' => 40,
          ),
        ),
        'dimmers' => 
        array (
          'deckenlicht_dimmen_wert' => 
          array (
            'title' => 'Kinderzimmer groß Deckenlicht',
            'synonyms' => 'decke, deckenlicht, hauptlicht, dimmen',
            'value' => 15120,
            'set' => 15120,
            'min' => 0,
            'max' => 100,
            'step' => 1,
            'icon' => 'dim.png',
            'entityId' => 'dim.og_kinder_gross_deckenlicht_dimmen_wert',
            'order' => 10,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Kinderzimmer groß',
            'value' => 30470,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'OG',
  ),
  'kinder_klein' => 
  array (
    'display' => 'Kinderzimmer klein',
    'domains' => 
    array (
      'heizung' => 
      array (
        'kinder_klein' => 
        array (
          'title' => 'Kinderzimmer klein',
          'ist' => 12248,
          'stellung' => 16130,
          'eingestellt' => 25119,
          'soll' => 58748,
          'icon' => 'Kinderzimmer_klein.png',
          'entityId' => 'heizung.Kinderzimmer klein',
          'order' => 130,
        ),
      ),
      'jalousie' => 
      array (
        'west' => 
        array (
          'title' => 'Kinderzimmer klein West',
          'wert' => 28427,
          'order' => 270,
        ),
        'nord' => 
        array (
          'title' => 'Kinderzimmer klein Nord',
          'wert' => 10412,
          'order' => 280,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'leselicht' => 
          array (
            'title' => 'Kinderzimmer klein Leselicht',
            'synonyms' => 'lese, nachtlicht, bett',
            'state' => 11539,
            'toggle' => 37361,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_kinder_klein_leselicht',
            'order' => 10,
          ),
          'deckenlicht' => 
          array (
            'title' => 'Kinderzimmer klein Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht, haupt, kinderzimmer klein, kizi klein, og kinderzimmer klein, samuel',
            'state' => 16254,
            'toggle' => 23667,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_kinder_klein_deckenlicht',
            'order' => 20,
          ),
        ),
        'dimmers' => 
        array (
          'deckenlicht_dimmen_wert' => 
          array (
            'title' => 'Kinderzimmer_klein Deckenlicht',
            'synonyms' => 'decke, deckenlicht, dimmen, hauptlicht',
            'value' => 29328,
            'set' => 29328,
            'min' => 0,
            'max' => 100,
            'step' => 1,
            'icon' => 'dim.png',
            'entityId' => 'dim.og_kinder_klein_deckenlicht_dimmen_wert',
            'order' => 10,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Kinderzimmer klein',
            'value' => 28922,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'OG',
  ),
  'stiegenhaus' => 
  array (
    'display' => 'Stiegenhaus',
    'domains' => 
    array (
      'jalousie' => 
      array (
        'links' => 
        array (
          'title' => 'Stiegenhaus Links',
          'wert' => 32291,
          'order' => 290,
        ),
        'mitte' => 
        array (
          'title' => 'Stiegenhaus Mitte',
          'wert' => 45259,
          'order' => 300,
        ),
        'rechts' => 
        array (
          'title' => 'Stiegenhaus Rechts',
          'wert' => 43222,
          'order' => 310,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'deckenlicht' => 
          array (
            'title' => 'Stiegenhaus Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, treppe, hauptlicht, stiegenhaus',
            'state' => 48275,
            'toggle' => 51542,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_stiegenhaus_deckenlicht',
            'order' => 20,
          ),
          'seitenlicht' => 
          array (
            'title' => 'Stiegenhaus Seitenlicht',
            'synonyms' => 'seite, wandlicht, stiege seite',
            'state' => 46538,
            'toggle' => 15412,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_stiegenhaus_seitenlicht',
            'order' => 30,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Stiegenhaus unten',
            'value' => 15611,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 11,
          ),
        ),
      ),
    ),
    'floor' => 'OG',
  ),
  'og_wc' => 
  array (
    'display' => 'OG WC',
    'domains' => 
    array (
      'jalousie' => 
      array (
        'fenster' => 
        array (
          'title' => 'OG WC',
          'wert' => 47623,
          'order' => 320,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'wc_spiegel' => 
          array (
            'title' => 'WC Spiegel',
            'synonyms' => 'spiegel, spiegellampe, licht spiegel',
            'state' => 44872,
            'toggle' => 48163,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_og_wc_wc_spiegel',
            'order' => 10,
          ),
          'wc_deckenlicht' => 
          array (
            'title' => 'WC Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht, haupt, og wc, obergeschoss wc, wc og, wc obergeschoss, og_wc, og pc, ogwc',
            'state' => 28641,
            'toggle' => 28493,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.og_og_wc_wc_deckenlicht',
            'order' => 20,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'WC Helligkeit',
            'value' => 35131,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
      'lueftung' => 
      array (
        'fans' => 
        array (
          'wc' => 
          array (
            'title' => 'WC Lüfter',
            'state' => 14580,
            'toggle' => 30915,
            'icon' => 'vent.png',
            'entityId' => 'luefter.og_wc',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'OG',
  ),
  'keller_stiegenhaus' => 
  array (
    'display' => 'Keller Stiegenhaus',
    'domains' => 
    array (
      'heizung' => 
      array (
        'keller_stiegenhaus' => 
        array (
          'title' => 'Keller Stiegenhaus',
          'ist' => 52615,
          'stellung' => 26604,
          'eingestellt' => 47020,
          'soll' => 50556,
          'icon' => 'Stiegenhaus.png',
          'entityId' => 'heizung.Keller_Stiegenhaus',
          'order' => 50,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'decke' => 
          array (
            'title' => 'Stiegenhaus Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, treppe, hauptlicht, stiegenhaus, Stiegenhaus, treppe, stiege, stufen, treppen, treppe licht, treppen licht',
            'state' => 24473,
            'toggle' => 33145,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.kg_stiegenhaus_decke',
            'order' => 10,
          ),
          'treppe' => 
          array (
            'title' => 'Treppenlicht',
            'state' => 18648,
            'toggle' => 17110,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.kg_aussenTreppenlicht',
            'order' => 30,
          ),
        ),
        'status' => 
        array (
          'lux_oben' => 
          array (
            'title' => 'Stiegenhaus oben',
            'value' => 44977,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
          'lux_unten' => 
          array (
            'title' => 'Stiegenhaus unten',
            'value' => 15611,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 11,
          ),
        ),
      ),
    ),
    'floor' => 'Keller',
  ),
  'keller_gang' => 
  array (
    'display' => 'Keller Gang',
    'domains' => 
    array (
      'heizung' => 
      array (
        'keller_gang' => 
        array (
          'title' => 'Keller Gang',
          'ist' => 57150,
          'stellung' => 25353,
          'eingestellt' => 33400,
          'soll' => 26034,
          'icon' => 'Keller_Gang.png',
          'entityId' => 'heizung.Keller Gang',
          'order' => 20,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'gang_deckenlicht' => 
          array (
            'title' => 'Keller Gang Deckenlicht',
            'synonyms' => 'gang, flur, decke, deckenlicht, hauptlicht',
            'state' => 58477,
            'toggle' => 45977,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.kg_keller_gang_gang_deckenlicht',
            'order' => 10,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Gang',
            'value' => 47438,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'Keller',
  ),
  'garderobe' => 
  array (
    'display' => 'EG Garderobe',
    'domains' => 
    array (
      'heizung' => 
      array (
        'garderobe' => 
        array (
          'title' => 'EG Garderobe',
          'ist' => 47637,
          'stellung' => 19768,
          'eingestellt' => 53986,
          'soll' => 29436,
          'icon' => 'Garderobe.png',
          'entityId' => 'heizung.EG Garderobe',
          'order' => 60,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'zeitautomatik_dekolicht' => 
          array (
            'title' => 'Garderobe Zeitautomatik Dekolicht',
            'synonyms' => 'deko, dekolicht, zeitautomatik, automatik, zeit',
            'state' => 50694,
            'toggle' => 50694,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_garderobe_zeitautomatik_dekolicht',
            'order' => 10,
          ),
          'deckenlicht' => 
          array (
            'title' => 'Garderobe Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht, haupt, decken licht',
            'state' => 39399,
            'toggle' => 12684,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_garderobe_deckenlicht',
            'order' => 20,
          ),
          'steckdose' => 
          array (
            'title' => 'Garderobe Steckdose',
            'synonyms' => 'steckdose, dose, stecker',
            'state' => 36268,
            'toggle' => 36268,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.eg_garderobe_steckdose',
            'order' => 30,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Garderobe',
            'value' => 32908,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'EG',
  ),
  'sauna' => 
  array (
    'display' => 'Sauna',
    'domains' => 
    array (
      'heizung' => 
      array (
        'sauna' => 
        array (
          'title' => 'Sauna',
          'ist' => 28134,
          'stellung' => 44016,
          'eingestellt' => 28802,
          'soll' => 26602,
          'icon' => 'Sauna.png',
          'entityId' => 'heizung.Sauna',
          'order' => 10,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'deckenlicht' => 
          array (
            'title' => 'Sauna Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht, haupt',
            'state' => 39357,
            'toggle' => 28209,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.kg_sauna_deckenlicht',
            'order' => 10,
          ),
        ),
        'dimmers' => 
        array (
          'deckenlicht_dimmen_wert' => 
          array (
            'title' => 'Sauna Deckenlicht',
            'synonyms' => 'decke, deckenlicht, dimmen, hauptlicht',
            'value' => 48506,
            'set' => 50744,
            'min' => 0,
            'max' => 100,
            'step' => 1,
            'icon' => 'dim.png',
            'entityId' => 'dim.kg_sauna_deckenlicht_dimmen_wert',
            'order' => 10,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Sauna',
            'value' => 59114,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
      'lueftung' => 
      array (
        'fans' => 
        array (
          'sauna' => 
          array (
            'title' => 'Sauna Lüfter',
            'state' => 42350,
            'toggle' => 46196,
            'icon' => 'vent.png',
            'entityId' => 'luefter.sauna',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'Keller',
  ),
  'lagerraum' => 
  array (
    'display' => 'Lagerraum',
    'domains' => 
    array (
      'heizung' => 
      array (
        'lagerraum' => 
        array (
          'title' => 'Lagerraum',
          'ist' => 47138,
          'stellung' => 30445,
          'eingestellt' => 34620,
          'soll' => 56708,
          'icon' => 'Lagerraum.png',
          'entityId' => 'heizung.Lagerraum',
          'order' => 40,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'decke' => 
          array (
            'title' => 'Abstellraum Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht, lager',
            'state' => 19601,
            'toggle' => 33332,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.kg_abstellraum',
            'order' => 10,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Abstellraum',
            'value' => 55623,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'Keller',
  ),
  'hobbyraum' => 
  array (
    'display' => 'Hobbyraum',
    'domains' => 
    array (
      'heizung' => 
      array (
        'hobbyraum' => 
        array (
          'title' => 'Hobbyraum',
          'ist' => 58438,
          'stellung' => 32550,
          'eingestellt' => 10699,
          'soll' => 46664,
          'icon' => 'Hobbyraum.png',
          'entityId' => 'heizung.Hobbyraum',
          'order' => 30,
        ),
      ),
      'licht' => 
      array (
        'switches' => 
        array (
          'decke' => 
          array (
            'title' => 'Hobbyraum Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht, hobbyraum',
            'state' => 38174,
            'toggle' => 11567,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.kg_hobby_decke',
            'order' => 10,
          ),
          'tuer_decke' => 
          array (
            'title' => 'Hobbyraum Tür Deckenlicht',
            'synonyms' => 'tür, tuer, eingang, decke tür, hobbyraum tür',
            'state' => 51419,
            'toggle' => 51419,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.kg_hobby_tuer',
            'order' => 20,
          ),
          'werkbank' => 
          array (
            'title' => 'Hobbyraum Werkbank',
            'synonyms' => 'werkbank, arbeitsplatz, bank',
            'state' => 28366,
            'toggle' => 42907,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.kg_hobby_werkbank',
            'order' => 30,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Hobbyraum',
            'value' => 42660,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
          'lux2' => 
          array (
            'title' => 'Hobbyraum Tür',
            'value' => 10573,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 11,
          ),
        ),
      ),
      'lueftung' => 
      array (
        'fans' => 
        array (
          'hobby' => 
          array (
            'title' => 'Hobbyraum Lüfter',
            'state' => 31936,
            'toggle' => 31936,
            'icon' => 'vent.png',
            'entityId' => 'luefter.hobbyraum',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'Keller',
  ),
  'technikraum' => 
  array (
    'display' => 'Technikraum',
    'domains' => 
    array (
      'licht' => 
      array (
        'switches' => 
        array (
          'decke' => 
          array (
            'title' => 'Technikraum Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, technik, hauptlicht',
            'state' => 24746,
            'toggle' => 49798,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.kg_technik',
            'order' => 10,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Technikraum',
            'value' => 51954,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'Keller',
  ),
  'heizraum' => 
  array (
    'display' => 'Heizraum',
    'domains' => 
    array (
      'licht' => 
      array (
        'switches' => 
        array (
          'decke' => 
          array (
            'title' => 'Heizraum Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, hauptlicht',
            'state' => 45092,
            'toggle' => 55256,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.kg_heizraum',
            'order' => 10,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Heizraum',
            'value' => 26951,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
      'devices' => 
      array (
        'tabs' => 
        array (
          'Waschtrockner' => 
          array (
            'id' => 58379,
            'order' => 80,
            'synonyms' => 'waschmaschine, trockner, wäsche, wäschetrockner',
          ),
        ),
      ),
    ),
    'floor' => 'Keller',
  ),
  'Außen' => 
  array (
    'display' => 'Außen',
    'domains' => 
    array (
      'licht' => 
      array (
        'switches' => 
        array (
          'deckenlicht_alle' => 
          array (
            'title' => 'Außen Deckenlicht alle',
            'synonyms' => 'decke, decken, alle, gesamte außen, außenlicht, aussenlicht',
            'state' => 30271,
            'toggle' => 13929,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.aussen_deckenlicht_alle',
            'order' => 10,
          ),
          'blumentrog_alle' => 
          array (
            'title' => 'Außen Blumentrog alle',
            'synonyms' => 'blumentrog, tröge, troege, alle troege, alle blumentröge',
            'state' => 33467,
            'toggle' => 33467,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.aussen_blumentrog_alle',
            'order' => 20,
          ),
          'blumentrog_links' => 
          array (
            'title' => 'Außen Blumentrog links',
            'synonyms' => 'blumentrog links, links trog, linker trog',
            'state' => 38378,
            'toggle' => 38378,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.aussen_blumentrog_links',
            'order' => 30,
          ),
          'blumentrog_mitte' => 
          array (
            'title' => 'Außen Blumentrog mitte',
            'synonyms' => 'blumentrog mitte, mittlerer trog, mitte trog',
            'state' => 40854,
            'toggle' => 40854,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.aussen_blumentrog_mitte',
            'order' => 40,
          ),
          'blumentrog_rechts' => 
          array (
            'title' => 'Außen Blumentrog rechts',
            'synonyms' => 'blumentrog rechts, rechter trog, rechts trog',
            'state' => 54185,
            'toggle' => 54185,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.aussen_blumentrog_rechts',
            'order' => 50,
          ),
          'einfahrt' => 
          array (
            'title' => 'Außen Einfahrtlicht',
            'synonyms' => 'einfahrt, zufahrt, auffahrt, parkplatz',
            'state' => 31020,
            'toggle' => 25512,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.aussen_einfahrt',
            'order' => 60,
          ),
          'tuer_decke' => 
          array (
            'title' => 'Außen Tür Deckenlicht',
            'synonyms' => 'tür, tuer, haustür, eingang, decke tür',
            'state' => 37634,
            'toggle' => 37634,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.aussen_tuer_decke',
            'order' => 70,
          ),
        ),
        'status' => 
        array (
          'lux' => 
          array (
            'title' => 'Aussen Nord',
            'value' => 43600,
            'unit' => 'lx',
            'icon' => 'lux.png',
            'order' => 10,
          ),
        ),
      ),
      'sprinkler' => 
      array (
        'tabs' => 
        array (
          'Bewässerung' => 
          array (
            'id' => 41578,
            'order' => 10,
            'synonyms' => 'garten, bewässerung, bewaesserung',
          ),
        ),
      ),
    ),
    'floor' => 'AUSSEN',
  ),
  'dachgeschoss' => 
  array (
    'display' => 'Dachgeschoß',
    'domains' => 
    array (
      'licht' => 
      array (
        'switches' => 
        array (
          'decke' => 
          array (
            'title' => 'Dachboden Deckenlicht',
            'synonyms' => 'decke, decken, deckenlicht, dachboden, bodenlicht, hauptlicht',
            'state' => 40622,
            'toggle' => 49813,
            'iconOn' => 'bulb_on.png',
            'iconOff' => 'bulb_off.png',
            'entityId' => 'light.kg_heizraum',
            'order' => 10,
          ),
        ),
      ),
    ),
    'floor' => 'DG',
  ),
  'laptop' => 
  array (
    'display' => 'Laptop',
    'domains' => 
    array (
      'heizung' => 
      array (
        'laptop' => 
        array (
          'title' => 'Test Laptop',
          'ist' => 28944,
          'stellung' => 10810,
          'eingestellt' => 33161,
          'soll' => 33161,
          'icon' => 'laptop.png',
          'entityId' => 'laptop.Büro',
          'order' => 110,
        ),
      ),
    ),
  ),
  'global' => 
  array (
    'display' => 'global',
    'domains' => 
    array (
      'been' => 
      array (
        'tabs' => 
        array (
          'Bienen' => 
          array (
            'id' => 49803,
            'order' => 30,
            'synonyms' => 'bienen, biene',
          ),
        ),
      ),
      'hifi' => 
      array (
        'tabs' => 
        array (
          'Hifi' => 
          array (
            'id' => 49803,
            'order' => 30,
            'synonyms' => 'hifi, anlage',
          ),
        ),
      ),
      'roboter' => 
      array (
        'tabs' => 
        array (
          'Roborock' => 
          array (
            'id' => 38386,
            'order' => 10,
            'synonyms' => 'roborock, bodenwischer',
          ),
           'Dock' => 
          array (
            'id' => 44390,
            'order' => 20,
            'synonyms' => 'dock, doc, doch, druck',
          ),
          'Wrox' => 
          array (
            'id' => 15315,
            'order' => 30,
            'synonyms' => 'wrox, mähroboter, rasenmäher, brooks, , vox',
          ),
        ),
      ),
      'save' => 
      array (
        'tabs' => 
        array (
          'Sicherheit' => 
          array (
            'id' => 59054,
            'order' => 30,
            'synonyms' => 'sicherheit, fenster',
          ),
          'Bewegungsmelder' => 
          array (
            'id' => 24267,
            'order' => 40,
            'synonyms' => 'bewegungsmelder',
          ),
          'Sicherungen' => 
          array (
            'id' => 20753,
            'order' => 50,
            'synonyms' => 'sicherungen',
          ),
          'Online Status' => 
          array (
            'id' => 58316,
            'order' => 60,
            'synonyms' => 'online',
          ),
          'Fritzbox' => 
          array (
            'id' => 26254,
            'order' => 70,
            'synonyms' => 'fritzbox',
          ),
        ),
      ),
      'list' => 
      array (
        'tabs' => 
        array (
          'Tanken' => 
          array (
            'id' => 57976,
            'order' => 10,
            'synonyms' => 'tanken, danke',
          ),
          'Einkaufsliste' => 
          array (
            'id' => 38806,
            'order' => 20,
            'synonyms' => 'einkaufffsliste',
          ),
          'ToDo' => 
          array (
            'id' => 43768,
            'order' => 30,
            'synonyms' => 'todo',
          ),
        ),
      ),
      'info_domain' => 
      array (
        'tabs' => 
        array (
          'Information' => 
          array (
            'id' => 24242,
            'order' => 10,
            'synonyms' => 'information',
          ),
        ),
      ),
      'szene_domain' => 
      array (
        'tabs' => 
        array (
          'Küche' => 
          array (
            'id' => 45998,
            'order' => 10,
            'synonyms' => 'küche, szeneküche',
          ),
          'Wohnzimmer' => 
          array (
            'id' => 30562,
            'order' => 20,
            'synonyms' => 'wohnzimmer, szenewohnzimmer',
          ),
        ),
      ),
      'jalousie' => 
      array (
        'scenes' => 
        array (
          'og' => 
          array (
            'var' => 56751,
            'title' => 'Obergeschoss',
          ),
          'eg_nowg' => 
          array (
            'var' => 10276,
            'title' => 'EG ohne Wintergarten',
          ),
          'wg_nt' => 
          array (
            'var' => 57033,
            'title' => 'Wintergarten ohne Tür',
          ),
          'wz' => 
          array (
            'var' => 24514,
            'title' => 'Wohnzimmer',
          ),
          'all' => 
          array (
            'var' => 40172,
            'title' => 'Alle zusammen',
          ),
        ),
        'icon' => 'JalousieIcon.png',
        'open_close_var' => 53051,
      ),
      'licht' => 
      array (
        'scenes' => 
        array (
          0 => 
          array (
            'section' => 'EG Szenen',
            'sectionH' => '2.6vw',
            'sectionFont' => '1.2vw',
            'sectionBold' => true,
            'sectionPadY' => '0.4vw',
          ),
          1 => 
          array (
            'title' => 'Led Küche',
            'id' => 'scene.eg_led_kueche',
            'actions' => 
            array (
              0 => 
              array (
                'label' => 'Öffnen',
                'color' => '#2ECC71',
                'args' => 
                array (
                  0 => 'GetHaus',
                  1 => 'öffnen',
                  2 => 'scene.eg_led_kueche',
                ),
              ),
              1 => 
              array (
                'label' => 'Schliessen',
                'color' => '#3C414A',
                'args' => 
                array (
                  0 => 'GetHaus',
                  1 => 'schliessen',
                  2 => 'scene.eg_led_kueche',
                ),
              ),
              2 => 
              array (
                'label' => 'Party',
                'color' => '#8A2BE2',
                'args' => 
                array (
                  0 => 'GetHaus',
                  1 => 'party',
                  2 => 'scene.eg_led_kueche',
                ),
              ),
            ),
          ),
          2 => 
          array (
            'section' => 'OG Szenen',
            'sectionH' => '2.6vw',
            'sectionFont' => '1.2vw',
            'sectionBold' => true,
            'sectionPadY' => '0.4vw',
          ),
          3 => 
          array (
            'title' => 'Led Whirlpool',
            'id' => 'scene.og_led_whirlpool',
            'actions' => 
            array (
              0 => 
              array (
                'label' => 'Blau',
                'color' => '#3B82F6',
                'args' => 
                array (
                  0 => 'GetHaus',
                  1 => 'color',
                  2 => 'scene.og_led_whirlpool',
                  3 => 'blue',
                ),
              ),
              1 => 
              array (
                'label' => 'Grün',
                'color' => '#10B981',
                'args' => 
                array (
                  0 => 'GetHaus',
                  1 => 'color',
                  2 => 'scene.og_led_whirlpool',
                  3 => 'green',
                ),
              ),
              2 => 
              array (
                'label' => 'Rot',
                'color' => '#EF4444',
                'args' => 
                array (
                  0 => 'GetHaus',
                  1 => 'color',
                  2 => 'scene.og_led_whirlpool',
                  3 => 'red',
                ),
              ),
              3 => 
              array (
                'label' => 'Weiß',
                'color' => '#9CA3AF',
                'args' => 
                array (
                  0 => 'GetHaus',
                  1 => 'color',
                  2 => 'scene.og_led_whirlpool',
                  3 => 'white',
                ),
              ),
            ),
          ),
        ),
      ),
      'lueftung' => 
      array (
        'default_buttons' => 
        array (
          0 => 
          array (
            'label' => 'An',
            'color' => '@pillOpen',
            'argsTpl' => 
            array (
              0 => 'Ventilation',
              1 => 'toggle',
              2 => '${entityId}',
              3 => 'on',
            ),
          ),
          1 => 
          array (
            'label' => 'Aus',
            'color' => '@pillClose',
            'argsTpl' => 
            array (
              0 => 'Ventilation',
              1 => 'toggle',
              2 => '${entityId}',
              3 => 'off',
            ),
          ),
        ),
        'central' => 
        array (
          0 => 
          array (
            'title' => 'Stiegenhaus Fenster',
            'entityId' => 'vent.central',
            'statusText' => 'Aus',
            'statusDetail' => 'Manuell aktiv',
            'statusLevel' => 'off',
            'icon' => 'stiege.png',
            'order' => 10,
          ),
        ),
        'icon' => 'VentIcon.png',
        'central_title' => 'Zentrale Lüftung',
        'status_colors' => 
        array (
          'on' => '@pillOpen',
          'off' => '@pillClose',
          'boost' => '#ff7a00',
        ),
      ),
      'floors' => 
      array (
        'order' => 
        array (
          0 => 'Keller',
          1 => 'EG',
          2 => 'OG',
          3 => 'DG',
          4 => 'AUSSEN',
        ),
        'labels' => 
        array (
          'Keller' => 'Keller',
          'EG' => 'EG',
          'OG' => 'OG',
          'DG' => 'DG',
          'AUSSEN' => 'Außen',
        ),
        'section' => 
        array (
          'height' => '3.4vw',
          'fontSize' => '1.4vw',
          'bold' => false,
          'padY' => '0.8vw',
        ),
      ),
    ),
  ),
);
