<?php

declare(strict_types=1);

/**
 * ============================================================
 * KI NLU CONTEXT BUILDER — RoomsCatalog → compact NLU payload
 * ============================================================
 *
 * Änderungsverlauf
 * 2025-11-24: v1 — Erste Version (RoomsCatalog → rooms/devices/scenes)
 */

/**
 * Baue einen kompakten NLU-Context aus dem produktiven RoomsCatalog.
 *
 * Ziel: möglichst wenig Tokens für die KI verbrauchen, aber trotzdem
 *       dynamisch alle aktuell bekannten Räume / Geräte / Szenen abbilden.
 *
 * @param array $roomsCatalog Voller produktiver RoomsCatalog
 * @param array $opts         Optionale Einstellungen:
 *                            - 'limitRooms'   => string[]|null  (nur diese Room-Keys berücksichtigen)
 *                            - 'devices'      => string[]|null  (Device-Liste explizit setzen)
 *                            - 'includeScenes'=> bool           (default: true)
 *
 * @return array{
 *   rooms: string[],
 *   devices: string[],
 *   scenes: string[]
 * }
 */
function ki_build_nlu_context(array $roomsCatalog, array $opts = []): array
{
    $rooms = [];

    foreach ($roomsCatalog as $roomKey => $roomDef) {
        if (!is_array($roomDef)) {
            continue;
        }

        if ($roomKey === 'global') {
            continue;
        }

        if (isset($opts['limitRooms']) && is_array($opts['limitRooms']) && !in_array($roomKey, $opts['limitRooms'], true)) {
            continue;
        }

        $label = $roomDef['display'] ?? $roomKey;
        if (!is_string($label) || $label === '') {
            $label = (string) $roomKey;
        }

        $rooms[] = $label;
    }

    $rooms = array_values(array_unique($rooms, SORT_STRING));
    sort($rooms, SORT_NATURAL | SORT_FLAG_CASE);

    if (isset($opts['devices']) && is_array($opts['devices']) && $opts['devices'] !== []) {
        $devices = array_values(array_unique(array_map('strval', $opts['devices']), SORT_STRING));
    } else {
        $deviceSet = [];

        if (isset($roomsCatalog['global']) && is_array($roomsCatalog['global'])) {
            foreach ($roomsCatalog['global'] as $domainKey => $domainDef) {
                if (!is_string($domainKey) || $domainKey === '') {
                    continue;
                }
                $deviceSet[$domainKey] = true;
            }
        }

        foreach ($roomsCatalog as $roomKey => $roomDef) {
            if (!is_array($roomDef)) {
                continue;
            }
            if (!isset($roomDef['domains']) || !is_array($roomDef['domains'])) {
                continue;
            }

            foreach ($roomDef['domains'] as $domainKey => $_domainDef) {
                if (!is_string($domainKey) || $domainKey === '') {
                    continue;
                }
                $deviceSet[$domainKey] = true;
            }
        }

        $devices = array_keys($deviceSet);
    }

    sort($devices, SORT_NATURAL | SORT_FLAG_CASE);

    $includeScenes = array_key_exists('includeScenes', $opts) ? (bool) $opts['includeScenes'] : true;
    $sceneSet = [];

    if ($includeScenes) {
        $collectScenes = static function ($sceneDef) use (&$sceneSet, &$collectScenes): void {
            if (is_array($sceneDef)) {
                if (isset($sceneDef['title']) && is_string($sceneDef['title']) && $sceneDef['title'] !== '') {
                    $sceneSet[$sceneDef['title']] = true;
                }
                foreach ($sceneDef as $value) {
                    if (is_array($value) || is_string($value)) {
                        $collectScenes($value);
                    }
                }
                return;
            }

            if (is_string($sceneDef) && $sceneDef !== '') {
                $sceneSet[$sceneDef] = true;
            }
        };

        if (isset($roomsCatalog['global']) && is_array($roomsCatalog['global'])) {
            foreach ($roomsCatalog['global'] as $domainDef) {
                if (!is_array($domainDef) || !isset($domainDef['scenes'])) {
                    continue;
                }
                $collectScenes($domainDef['scenes']);
            }
        }

        foreach ($roomsCatalog as $roomDef) {
            if (!is_array($roomDef) || !isset($roomDef['domains']) || !is_array($roomDef['domains'])) {
                continue;
            }
            foreach ($roomDef['domains'] as $domainDef) {
                if (!is_array($domainDef) || !isset($domainDef['scenes'])) {
                    continue;
                }
                $collectScenes($domainDef['scenes']);
            }
        }
    }

    $scenes = array_keys($sceneSet);
    sort($scenes, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'rooms'   => $rooms,
        'devices' => $devices,
        'scenes'  => $scenes,
    ];
}
