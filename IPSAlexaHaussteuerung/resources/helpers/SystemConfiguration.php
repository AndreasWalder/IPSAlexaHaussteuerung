<?php
/**
 * Template for the IPSAlexaHaussteuerung SystemConfiguration script.
 *
 * The module overrides credentials like BaseUrl/Token/Passwort/StartPage and the
 * WfcId/Energie- und Kamera-Page-IDs with the values from the
 * instance configuration. All other IDs have to be configured manually below.
 */
return [
    'var' => [
        // Toggle variables (bool vars inside "Einstellungen")
        'ActionsEnabled' => [
            'heizung_stellen'    => 0,
            'jalousie_steuern'   => 0,
            'licht_switches'     => 0,
            'licht_dimmers'      => 0,
            'lueft_stellen'      => 0, // alias for legacy lueftung_toggle
            'lueftung_toggle'    => 0,
            'geraete_toggle'     => 0,
            'bewaesserung_toggle'=> 0,
        ],
        // Runtime/status variables (string/bool vars you want to track)
        'AUSSEN_TEMP'     => 0,
        'INFORMATION'     => 0,
        'MELDUNGEN'       => 0,
        'DEVICE_MAP'      => 0,
        'DEVICE_SAVE'     => 0,
        'ROOM_SAVE'       => 0,
        'DOMAIN_FLAG'     => 0,
        'SKILL_ACTIVE'    => 0,
        'PENDING_DEVICE'  => 0,
        'PENDING_STAGE'   => 0,
        'lastVariableDevice' => 0,
        'lastVariableId'     => 0,
        'lastVariableAction' => 0,
        'lastVariableValue'  => 0,
        'ACTION_VAR'         => 0,
        'DEVICE_VAR'         => 0,
        'ROOM_VAR'           => 0,
        'SZENE_VAR'          => 0,
        'OBJECT_VAR'         => 0,
        'NUMBER_VAR'         => 0,
        'PROZENT_VAR'        => 0,
        'ALLES_VAR'          => 0,
        'ALEXA_VAR'          => 0,
        'externalKey'        => '',
        // Helper scripts (these point to scripts located below the instance)
        'CoreHelpers'        => 0,
        'DeviceMap'          => 0,
        'RoomBuilderHelpers' => 0,
        'DeviceMapWizard'    => 0,
        'Lexikon'            => 0,
    ],
    'script' => [
        'ROUTE_ALL'           => 0,
        'RENDER_MAIN'         => 0,
        'RENDER_HEIZUNG'      => 0,
        'RENDER_JALOUSIE'     => 0,
        'RENDER_LICHT'        => 0,
        'RENDER_LUEFTUNG'     => 0,
        'RENDER_GERAETE'      => 0,
        'RENDER_BEWAESSERUNG' => 0,
        'RENDER_SETTINGS'     => 0,
        'DEVICE_MAP_HELPERS'  => 0,
        'ROOMS_CATALOG'       => 0,
        'NORMALIZER'          => 0,
    ],
];
