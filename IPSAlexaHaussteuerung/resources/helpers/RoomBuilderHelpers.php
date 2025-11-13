<?php
/**
 * ============================================================
 * ALEXA ACTION SCRIPT — HELFER: ROOM BUILDER
 * ============================================================
 *
 * Dieses Skript wird vom Haupt-Action-Skript (Execute) per require geladen.
 * Es gibt Funktionen zurück, die den Status von Geräten in Räumen
 * (z.B. Heizungsdaten) auslesen und aufbereiten.
 *
 * Es muss in der $CFG['var']-Variable des Hauptskripts
 * als 'RoomBuilderHelpers' mit seiner ID registriert werden.
 */

/**
 * Interne Helferfunktion: Liest die Daten *eines* Heizkreises aus.
 */
$read_heating_circuit = static function (array $ids): array {
    $ist            = GetValueFormatted($ids['ist']);
    $stellungRawVal = GetValue($ids['stellung']);
    $stellungText   = GetValueFormatted($ids['stellung']);
    $statusBool     = ($stellungRawVal >= 1);
    return [
        'ist'        => $ist,
        'eingestellt'=> GetValueFormatted($ids['eingestellt']),
        'soll'       => GetValueFormatted($ids['soll']),
        'stellung'   => $stellungRawVal,
        '_ventil'    => ['stellung'=>$stellungRawVal,'stellung_text'=>$stellungText,'status'=>$statusBool?1:0,'status_bool'=>$statusBool],
    ];
};

/**
 * Baut das $rooms-Array auf, indem es den Status (Heizung)
 * für alle Räume im $ROOMS-Katalog liest.
 */
$build_rooms_status = static function (array $ROOMS) use ($read_heating_circuit): array {
    $rooms = [];
    foreach ($ROOMS as $key => $def) {
        $hasDomain = isset($def['domains']['heizung']) && is_array($def['domains']['heizung']) && $def['domains']['heizung'] !== [];
        $addedAny  = false;
        if ($hasDomain) {
            foreach ($def['domains']['heizung'] as $circuitId => $ids) {
                if (!is_array($ids)) continue;
                if (!isset($ids['ist'],$ids['stellung'],$ids['eingestellt'],$ids['soll'])) continue;
                $data = $read_heating_circuit($ids);
                if (!isset($rooms[$key]['ventil']) && isset($data['_ventil'])) $rooms[$key]['ventil'] = $data['_ventil'];
                unset($data['_ventil']);
                $rooms[$key]['heizung'][$circuitId] = $data;
                $addedAny = true;
            }
        }
        if (!$addedAny && isset($def['ids']) && is_array($def['ids'])) {
            $ids = $def['ids'];
            if (isset($ids['ist'],$ids['stellung'],$ids['eingestellt'],$ids['soll'])) {
                $data = $read_heating_circuit($ids);
                if (!isset($rooms[$key]['ventil']) && isset($data['_ventil'])) $rooms[$key]['ventil'] = $data['_ventil'];
                unset($data['_ventil']);
                $rooms[$key]['heizung']['default'] = $data;
            }
        }
    }
    return $rooms;
};

// Rückgabe-Array der Funktionen
return [
    'build_rooms_status' => $build_rooms_status,
    // $read_heating_circuit ist nur intern und muss nicht zurückgegeben werden
];
