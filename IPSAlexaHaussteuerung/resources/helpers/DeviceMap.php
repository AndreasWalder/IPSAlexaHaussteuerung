<?php
/**
 * ============================================================
 * ALEXA ACTION SCRIPT — HELFER: DEVICE MAP (WIZARD)
 * ============================================================
 *
 * Dieses Skript wird vom Haupt-Action-Skript (Execute) per require geladen.
 * Es gibt ein Array von Funktionen zurück, die sich um die
 * Verwaltung der $V['DEVICE_MAP'] kümmern.
 *
 * Es muss in der $CFG['script']-Variable des Hauptskripts
 * als 'DEVICE_MAP_HELPERS' mit seiner ID registriert werden.
 */

// Helfer-Funktionen für die Geräte-Map (Device-Map)
$dm_load = static function (int $varId): array {
    $raw = GetValueString($varId);
    if ($raw === '' || $raw === null) return [];
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
};

$dm_save = static function (int $varId, array $arr): void {
    $new = json_encode($arr, JSON_UNESCAPED_UNICODE);
    $cur = GetValueString($varId);
    if ($new !== $cur) SetValueString($varId, $new);
};

$dm_short_name = static function (string $deviceId): string {
    return 'NEU:' . substr(hash('crc32b', $deviceId), 0, 6);
};

$dm_format_created = static function (int $ts): string {
    $tz = new DateTimeZone('Europe/Vienna');
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    $wd = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'][(int)$dt->format('w')];
    return $wd . ', ' . $dt->format('d.m.Y, H:i:s');
};

$dm_ensure = function (int $varId, string $deviceId) use ($dm_load, $dm_save, $dm_short_name, $dm_format_created): array {
    $map = $dm_load($varId);
    $changed = false;
    foreach ($map as &$entry) {
        if (isset($entry['created']) && is_numeric($entry['created'])) {
            $entry['created'] = $dm_format_created((int)$entry['created']);
            $changed = true;
        }
    }
    unset($entry);
    if ($changed) $dm_save($varId, $map);
    if (!isset($map[$deviceId])) {
        $map[$deviceId] = ['location' => $dm_short_name($deviceId), 'apl' => false, 'isNew' => true, 'created' => $dm_format_created(time())];
        $dm_save($varId, $map);
    }
    $entry = $map[$deviceId];
    $alexa = (string)($entry['location'] ?? $dm_short_name($deviceId));
    $apl = (bool)($entry['apl'] ?? false);
    $isNew = (bool)($entry['isNew'] ?? false);
    return [$alexa, $apl, $isNew];
};

$dm_update_location = static function (int $varId, string $deviceId, string $location) use ($dm_load, $dm_save, $dm_format_created) {
    $map = $dm_load($varId);
    if (!isset($map[$deviceId])) $map[$deviceId] = ['location' => '', 'apl' => false, 'isNew' => true, 'created' => $dm_format_created(time())];
    $map[$deviceId]['location'] = $location;
    $map[$deviceId]['isNew'] = false;
    $dm_save($varId, $map);
};

$dm_update_apl = static function (int $varId, string $deviceId, bool $apl) use ($dm_load, $dm_save, $dm_format_created) {
    $map = $dm_load($varId);
    if (!isset($map[$deviceId])) $map[$deviceId] = ['location' => '', 'apl' => false, 'isNew' => true, 'created' => $dm_format_created(time())];
    $map[$deviceId]['apl'] = $apl;
    $map[$deviceId]['isNew'] = false;
    $dm_save($varId, $map);
};

// Rückgabe-Array der Funktionen
return [
    'load'            => $dm_load,
    'save'            => $dm_save,
    'ensure'          => $dm_ensure,
    'update_location' => $dm_update_location,
    'update_apl'      => $dm_update_apl,
];
