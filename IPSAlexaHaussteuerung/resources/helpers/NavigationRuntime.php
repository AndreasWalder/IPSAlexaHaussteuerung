<?php
declare(strict_types=1);

/**
 * V3.3.5 runtime helper, installed as an IP-Symcon helper script.
 * Only overlays navigation onto an IN-MEMORY copy of the RoomsCatalog.
 * No IPS write operations; device entries and settings are untouched.
 *
 * @return array{project:callable(array,array):array}
 */
return [
    'project' => static function (array $catalog, array $navigation): array {
        if (($navigation['schemaVersion'] ?? null) !== 1
            || ($navigation['mode'] ?? '') !== 'active'
            || !is_array($navigation['items'] ?? null)) {
            return $catalog;
        }

        $wrapped = isset($catalog['rooms']) && is_array($catalog['rooms']);
        $rooms = $wrapped ? $catalog['rooms'] : $catalog;

        // Remove only the exact legacy keys recorded at import time, not all
        // "tabs" groups: device types can legitimately have a group named tabs.
        foreach ($navigation['items'] as $item) {
            if (!is_array($item)) continue;
            $path = (string) ($item['legacyPath'] ?? '');
            if ($path === '') continue;
            $source = explode('/domains/', $path, 2);
            if (count($source) !== 2) continue;
            $parts = explode('/tabs/', $source[1], 2);
            if (count($parts) !== 2) continue;
            [$domain, $tabKey] = $parts;
            $roomKey = $source[0];
            if (isset($rooms[$roomKey]['domains'][$domain]['tabs'][$tabKey])) {
                unset($rooms[$roomKey]['domains'][$domain]['tabs'][$tabKey]);
                if ($rooms[$roomKey]['domains'][$domain]['tabs'] === []) {
                    unset($rooms[$roomKey]['domains'][$domain]['tabs']);
                }
            }
        }

        foreach ($navigation['items'] as $item) {
            if (!is_array($item) || empty($item['enabled'])) continue;
            $roomKey = (string) ($item['roomKey'] ?? '');
            $domain = (string) ($item['domainKey'] ?? '');
            $key = (string) ($item['title'] ?? '');
            $id = (int) ($item['objectId'] ?? 0);
            if ($roomKey === '' || $domain === '' || $key === '' || $id <= 0
                || !isset($rooms[$roomKey]) || !is_array($rooms[$roomKey])) {
                continue;
            }
            $definition = is_array($item['legacyDefinition'] ?? null)
                ? $item['legacyDefinition'] : [];
            $definition['id'] = $id;
            $definition['title'] = $key;
            $definition['order'] = (int) ($item['sortOrder'] ?? 9999);
            $definition['synonyms'] = array_values(array_map(
                'strval', (array) ($item['aliases'] ?? [])
            ));
            if (!isset($rooms[$roomKey]['domains']) || !is_array($rooms[$roomKey]['domains'])) {
                $rooms[$roomKey]['domains'] = [];
            }
            if (!isset($rooms[$roomKey]['domains'][$domain]) || !is_array($rooms[$roomKey]['domains'][$domain])) {
                $rooms[$roomKey]['domains'][$domain] = [];
            }
            if (!isset($rooms[$roomKey]['domains'][$domain]['tabs'])
                || !is_array($rooms[$roomKey]['domains'][$domain]['tabs'])) {
                $rooms[$roomKey]['domains'][$domain]['tabs'] = [];
            }
            // Renderer gr_normalize_tab_def uses the ARRAY KEY as title,
            // therefore use the edited title as the in-memory array key.
            $rooms[$roomKey]['domains'][$domain]['tabs'][$key] = $definition;
        }
        if ($wrapped) {
            $catalog['rooms'] = $rooms;
            return $catalog;
        }
        return $rooms;
    },
];
