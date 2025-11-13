<?php
return [
  'objects' => [
    'tür'     => ['tür','tuer','türe','tuere'],
    'fenster' => ['fenster','scheibe','fensterfläche'],
    'dusche'  => ['dusche','duschen','whirlpool'],

    'links'   => ['links','linke','linker','linksseitig','linksseite','linke seite','left'],
    'mitte'   => ['mitte','mittig','zentrum','zentral','middle','center','mittelstellung'],
    'rechts'  => ['rechts','rechte','rechter','rechtsseitig','rechtsseite','rechte seite','right'],

    'ost'     => ['ost','osten','ostseite','ost-seite','east'],
    'süd'     => ['süd','sued','südseite','suedseite','south'],
    'west'    => ['west','westen','westseite','west-seite','westside'],
  ],

  'patterns' => [
    // Begriffe
    'outside_temp' => '/(*UTF8)(*UCP)(?<!\pL)(au(?:ß|ss)entemperatur|außen\s*temperatur|aussen\s*temperatur)(?!\pL)/u',
    'ventilation'  => '/(*UTF8)(*UCP)(?<!\pL)(?:au(?:ß|ss)lüftung|lüftung|lueftung)(?!\pL)/u',

    // Zahl & Dezimaltrenner
    'decimal_words'=> '/(*UTF8)(*UCP)(?<=\d)\s*(komma|punkt)\s*(?=\d)/ui',
    'temp_number1' => '/(*UTF8)(*UCP)(?<!\pL)auf\s+(\d{1,3}(?:[.,]\d{1,2})?)(?:\s*grad)?(?!\pL)/ui',
    'temp_number2' => '/(*UTF8)(*UCP)(?<!\pL)(\d{1,3}(?:[.,]\d{1,2})?)\s*grad(?!\pL)/ui',
    'plain_number' => '/(*UTF8)(*UCP)(?<!\pL)(\d{1,3}(?:[.,]\d{1,2})?)(?!\s*%)(?!\pL)/u',
  ],
];
