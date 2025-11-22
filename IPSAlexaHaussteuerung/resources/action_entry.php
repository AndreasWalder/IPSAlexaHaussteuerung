<?php 
/**
 * ============================================================
 * ALEXA ACTION SCRIPT — Routing Heizung / Jalousie / Licht / Lüftung / Geräte
 * ============================================================
 *
 * Änderungsverlauf
 * 2025-11-11: Schlank-Refactor (CoreHelpers)
 * - Slot-Zugriffe über CoreHelpers:getSlot()
 * - APL-Args über CoreHelpers:parseAplArgs()
 * - Raumauflösung über CoreHelpers:room_key_by_spoken()
 * - Domain-Erkennung aus APL-Args über CoreHelpers:domainFromAplArgs()
 * - Tabs-Fallback über CoreHelpers:fallbackTabDomain() + findTabId/buildTabsCache
 * - Nummernextraktion über CoreHelpers:extractNumberOnly()/maybeMergeDecimalFromPercent()
 * - Lokale Helper entfernt, Logging/Routes unverändert
 *
 * 2025-11-10: Router-Refactor
 * - Alles durch ROUTE_ALL geleitet (kein Direkt-Render mehr)
 * - EXTERNAL PAGES Block ersetzt durch Route-Markierung 'external'
 * - HTML.Back-Handler setzt nur $__route='main_launch'
 * - Tabellen-Router statt if/elseif-Kaskade
 * - Doppelte/undefinierte Variablen aufgeräumt (mkCid nur 1x; maskToken/ hitApl/ settingsCall/ nav definiert)
 * - Lazy rooms-Bau nur wenn benötigt
 * - Device-Tabs Matching mit Cache
 * - Logging-Wrapper mit LOG_LEVEL
 *
 * 2025-11-11: Code-Hygiene
 * - Konstanten für 'pendingStage'-Werte (z.B. $STAGE_AWAIT_NAME)
 * - str_starts_with() statt strpos()===0 für Prefix-Prüfungen
 * - @-Fehlerunterdrückung bei RequestAction entfernt
 *
 * 2025-11-11: Refactor
 * - Device-Map-Helfer (dm_...) in 'AlexaHelpers_DeviceMap.php' ausgelagert.
 *
 * 2025-11-11: Anpassung
 * - Lade-Pfad für Helfer-Skript an Config angepasst (liest $V['DeviceMap'] statt $S['DEVICE_MAP_HELPERS'])
 */

const IAH_MODULE_GUID = '{F528D4D0-5729-4315-AE88-9BBABDBD0392}';

function iah_get_instance_id(): int
{
    $self = (int) ($_IPS['SELF'] ?? 0);
    $instanceId = iah_resolve_instance_from_object($self);
    if ($instanceId > 0) {
        return $instanceId;
    }

    $fallback = iah_find_instance_via_config_script();
    if ($fallback > 0) {
        return $fallback;
    }

    return 0;
}

function iah_resolve_instance_from_object(int $objectId): int
{
    while ($objectId > 0) {
        $parent = (int) @IPS_GetParent($objectId);
        if ($parent <= 0) {
            return 0;
        }
        if (IPS_InstanceExists($parent)) {
            return $parent;
        }
        $objectId = $parent;
    }

    return 0;
}

function iah_find_instance_via_config_script(): int
{
    if (!function_exists('IPS_GetInstanceListByModuleID')) {
        return 0;
    }

    $instances = @IPS_GetInstanceListByModuleID(IAH_MODULE_GUID);
    if (!is_array($instances) || $instances === []) {
        return 0;
    }

    foreach ($instances as $instanceId) {
        $props = iah_get_instance_properties((int) $instanceId);
        $configScript = (int) ($props['SystemConfigScriptId'] ?? 0);
        if ($configScript > 0 && IPS_ScriptExists($configScript)) {
            return (int) $instanceId;
        }
    }

    return 0;
}

function iah_get_instance_properties(int $instanceId): array
{
    if ($instanceId <= 0) {
        return [];
    }
    $rawConfig = IPS_GetConfiguration($instanceId);
    $props = json_decode((string) $rawConfig, true);
    return is_array($props) ? $props : [];
}

/**
 * Ergänzt alle Räume automatisch mit Domains aus dem global-Block des RoomsCatalog.
 * So lassen sich global gepflegte Tabs (z. B. Sicherheit/Bienen) ohne Duplikate
 * in jedem Raum nutzen.
 */
function iah_merge_global_room_domains(array $rooms): array
{
    $globalDomains = isset($rooms['global']['domains']) && is_array($rooms['global']['domains'])
        ? $rooms['global']['domains']
        : [];

    if ($globalDomains === []) {
        return $rooms;
    }

    foreach ($rooms as $roomKey => $roomCfg) {
        if ($roomKey === 'global' || !is_array($roomCfg)) {
            continue;
        }

        $domains = isset($roomCfg['domains']) && is_array($roomCfg['domains'])
            ? $roomCfg['domains']
            : [];

        foreach ($globalDomains as $domainKey => $domainCfg) {
            if (!isset($domains[$domainKey])) {
                $domains[$domainKey] = $domainCfg;
            }
        }

        $rooms[$roomKey]['domains'] = $domains;
    }

    return $rooms;
}

function iah_renderer_domain_defaults_map(): array
{
    return [
        'geraete' => [
            'logName'     => 'Geraete',
            'roomDomain'  => 'devices',
            'title'       => 'Geräte',
            'speechEmpty' => 'Keine Geräte im RoomsCatalog konfiguriert.',
            'aplDoc'      => 'doc://alexa/apl/documents/Geraete',
            'aplToken'    => 'hv-geraete',
        ],
        'bewaesserung' => [
            'logName'     => 'Bewaesserung',
            'roomDomain'  => 'sprinkler',
            'title'       => 'Bewässerung',
            'speechEmpty' => 'Keine Bewässerung im RoomsCatalog konfiguriert.',
            'aplDoc'      => 'doc://alexa/apl/documents/Bewaesserung',
            'aplToken'    => 'hv-bewaesserung',
        ],
    ];
}

function iah_renderer_domain_base(string $route): array
{
    $normalized = strtolower($route);
    $title = $normalized !== '' ? ucfirst($normalized) : 'Renderer';
    $tokenSlug = preg_replace('/[^a-z0-9]+/i', '', $normalized);
    if ($tokenSlug === '') {
        $tokenSlug = 'renderer';
    }

    return [
        'route'        => $normalized,
        'logName'      => $title,
        'roomDomain'   => 'devices',
        'title'        => $title,
        'subtitle'     => 'Steckdosen & mehr',
        'speechEmpty'  => 'Keine Einträge im RoomsCatalog konfiguriert.',
        'aplDoc'       => 'doc://alexa/apl/documents/' . $title,
        'aplToken'     => 'hv-' . $tokenSlug,
        'toggleVarKey' => $normalized . '_toggle',
    ];
}

function iah_launch_catalog_defaults(): array
{
    static $defaults = null;
    if ($defaults === null) {
        $file = __DIR__ . '/helpers/LaunchCatalogDefaults.php';
        $data = @include $file;
        if (is_array($data)) {
            $defaults = $data;
        } else {
            $defaults = [
                'title' => 'HAUS VISUALISIERUNG',
                'subtitleTemplate' => 'Raumname: {{alexa}}',
                'footerText' => 'Tipp eine Kachel an – oder sage z. B. „Jalousie“.',
                'logo' => 'Logo.png',
                'homeIcon' => 'HomeIcon.png',
                'headerIcon' => 'Icon.png',
                'tiles' => [],
            ];
        }
    }

    return $defaults;
}

function iah_build_launch_catalog(array $props): array
{
    $defaults = iah_launch_catalog_defaults();
    $raw = json_decode((string) ($props['LaunchCatalog'] ?? '[]'), true);
    $tiles = [];
    if (is_array($raw)) {
        foreach ($raw as $entry) {
            $tile = iah_sanitize_launch_tile($entry);
            if ($tile !== null) {
                $tiles[] = $tile;
            }
        }
    }
    if ($tiles === []) {
        $tiles = (array)($defaults['tiles'] ?? []);
    }

    // Fehlende Kacheln für konfigurierte PageMappings automatisch ergänzen
    $tileIds = [];
    foreach ($tiles as $tileEntry) {
        if (!is_array($tileEntry)) {
            continue;
        }
        $id = strtolower((string) ($tileEntry['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $tileIds[$id] = true;
    }

    foreach (iah_build_page_mappings($props) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = strtolower((string) ($entry['key'] ?? ''));
        if ($key === '' || isset($tileIds[$key])) {
            continue;
        }

        $label = trim((string) ($entry['label'] ?? ''));
        $tiles[] = [
            'id'    => $key,
            'title' => $label !== '' ? $label : ucfirst($key),
        ];
        $tileIds[$key] = true;
    }

    $read = static function (string $key, string $fallback) use ($props): string {
        $val = trim((string) ($props[$key] ?? ''));
        return $val !== '' ? $val : $fallback;
    };

    return [
        'title' => $read('LaunchTitle', (string) ($defaults['title'] ?? '')),
        'subtitleTemplate' => $read('LaunchSubtitleTemplate', (string) ($defaults['subtitleTemplate'] ?? '')),
        'footerText' => $read('LaunchFooterText', (string) ($defaults['footerText'] ?? '')),
        'logo' => $read('LaunchLogo', (string) ($defaults['logo'] ?? 'Logo.png')),
        'homeIcon' => $read('LaunchHomeIcon', (string) ($defaults['homeIcon'] ?? 'HomeIcon.png')),
        'headerIcon' => $read('LaunchHeaderIcon', (string) ($defaults['headerIcon'] ?? 'Icon.png')),
        'tiles' => $tiles,
    ];
}

if (!function_exists('iah_external_page_list')) {
    function iah_external_page_list(array $values, string $fallback): array
    {
        $set = [];
        foreach ($values as $value) {
            $val = strtolower(trim((string) $value));
            if ($val === '') {
                continue;
            }
            $set[$val] = true;
        }
        if ($fallback !== '') {
            $key = strtolower($fallback);
            if (!isset($set[$key])) {
                $set[$key] = true;
            }
        }

        return array_keys($set);
    }
}

if (!function_exists('iah_build_external_page_catalog')) {
    function iah_build_external_page_catalog(array $rooms, array $pageMappings, array $launchCatalog): array
    {
        $catalog = [];
        $base = [];
        if (isset($rooms['global']['external_pages']) && is_array($rooms['global']['external_pages'])) {
            $base = $rooms['global']['external_pages'];
        }
        foreach ($base as $key => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $normKey = strtolower((string) $key);
            if ($normKey === '') {
                continue;
            }
            $catalog[$normKey] = [
                'title'     => (string) ($cfg['title'] ?? ''),
                'logo'      => (string) ($cfg['logo'] ?? ''),
                'pageKey'   => strtolower((string) ($cfg['pageKey'] ?? $normKey)),
                'pageIdVar' => trim((string) ($cfg['pageIdVar'] ?? '')),
                'actions'   => iah_external_page_list((array) ($cfg['actions'] ?? []), $normKey),
                'navs'      => iah_external_page_list((array) ($cfg['navs'] ?? []), $normKey),
            ];
        }

        $tiles = is_array($launchCatalog['tiles'] ?? null) ? $launchCatalog['tiles'] : [];
        $tileMap = [];
        foreach ($tiles as $tile) {
            if (!is_array($tile)) {
                continue;
            }
            $tileId = strtolower((string) ($tile['id'] ?? ''));
            if ($tileId === '') {
                continue;
            }
            $tileMap[$tileId] = $tile;
        }

        foreach ($pageMappings as $key => $entry) {
            $normKey = strtolower((string) $key);
            if ($normKey === '') {
                continue;
            }
            $tile = $tileMap[$normKey] ?? null;
            if (!isset($catalog[$normKey])) {
                $title = (string) ($tile['title'] ?? ($entry['label'] ?? ''));
                if ($title === '') {
                    $title = ucfirst($normKey);
                }
                $logo = (string) ($tile['icon'] ?? '');
                $catalog[$normKey] = [
                    'title'     => $title,
                    'logo'      => $logo,
                    'pageKey'   => $normKey,
                    'pageIdVar' => '',
                    'actions'   => [$normKey],
                    'navs'      => [$normKey],
                ];
            } else {
                if (($catalog[$normKey]['title'] ?? '') === '') {
                    $catalog[$normKey]['title'] = (string) ($tile['title'] ?? ($entry['label'] ?? ucfirst($normKey)));
                }
                if (($catalog[$normKey]['logo'] ?? '') === '' && ($tile['icon'] ?? '') !== '') {
                    $catalog[$normKey]['logo'] = (string) $tile['icon'];
                }
                if (($catalog[$normKey]['pageKey'] ?? '') === '') {
                    $catalog[$normKey]['pageKey'] = $normKey;
                }
                if (!in_array($normKey, $catalog[$normKey]['actions'], true)) {
                    $catalog[$normKey]['actions'][] = $normKey;
                }
                if (!in_array($normKey, $catalog[$normKey]['navs'], true)) {
                    $catalog[$normKey]['navs'][] = $normKey;
                }
            }
        }

        return $catalog;
    }
}

function iah_page_mapping_defaults(): array
{
    return [
        ['key' => 'energie', 'type' => 'wfc_item', 'value' => 'item2124', 'label' => 'Energie'],
        ['key' => 'kamera', 'type' => 'wfc_item', 'value' => 'item9907', 'label' => 'Kamera'],
    ];
}

function iah_sanitize_page_mapping_entry(array $entry): ?array
{
    $key = strtolower(trim((string) ($entry['key'] ?? '')));
    if ($key === '') {
        return null;
    }

    $type = strtolower(trim((string) ($entry['type'] ?? 'wfc_item')));
    if (!in_array($type, ['wfc_item', 'external_url'], true)) {
        $type = 'wfc_item';
    }

    $value = trim((string) ($entry['value'] ?? ''));
    if ($value === '') {
        return null;
    }
    if ($type === 'external_url' && stripos($value, 'https://') !== 0) {
        return null;
    }

    $normalized = [
        'key'   => $key,
        'type'  => $type,
        'value' => $value,
    ];

    $label = trim((string) ($entry['label'] ?? ''));
    if ($label !== '') {
        $normalized['label'] = $label;
    }

    return $normalized;
}

function iah_build_page_mappings(array $props): array
{
    $raw = json_decode((string) ($props['PageMappings'] ?? '[]'), true);
    $map = [];
    if (is_array($raw)) {
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $parsed = iah_sanitize_page_mapping_entry($entry);
            if ($parsed === null) {
                continue;
            }
            $key = $parsed['key'];
            unset($parsed['key']);
            $map[$key] = $parsed;
        }
    }

    if ($map === []) {
        $fallbacks = [
            'energie' => trim((string) ($props['EnergiePageId'] ?? '')),
            'kamera'  => trim((string) ($props['KameraPageId'] ?? '')),
        ];
        foreach ($fallbacks as $key => $value) {
            if ($value === '') {
                continue;
            }
            $map[$key] = [
                'type'  => 'wfc_item',
                'value' => $value,
                'label' => ucfirst($key),
            ];
        }
    }

    if ($map === []) {
        foreach (iah_page_mapping_defaults() as $entry) {
            $parsed = iah_sanitize_page_mapping_entry($entry);
            if ($parsed === null) {
                continue;
            }
            $key = $parsed['key'];
            unset($parsed['key']);
            $map[$key] = $parsed;
        }
    }

    return $map;
}

function iah_legacy_page_id_from_mappings(array $pageMappings, string $key, string $fallback): string
{
    $normKey = strtolower($key);
    if (isset($pageMappings[$normKey]) && ($pageMappings[$normKey]['type'] ?? '') === 'wfc_item') {
        $value = trim((string) ($pageMappings[$normKey]['value'] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return trim($fallback);
}

function iah_derive_start_page(array $props): string
{
    $raw = trim((string) ($props['StartPage'] ?? ''));
    $wfcId = (int) ($props['WfcId'] ?? 0);

    if ($raw !== '' && !preg_match('/^#?\d+$/', $raw)) {
        return $raw;
    }

    if ($wfcId > 0) {
        return '#' . $wfcId;
    }

    return $raw;
}

function iah_sanitize_launch_tile($entry): ?array
{
    if (!is_array($entry)) {
        return null;
    }
    $id = strtolower(trim((string) ($entry['id'] ?? '')));
    $title = trim((string) ($entry['title'] ?? ''));
    if ($id === '' || $title === '') {
        return null;
    }

    $tile = ['id' => $id, 'title' => $title];
    $subtitle = trim((string) ($entry['subtitle'] ?? ''));
    if ($subtitle !== '') {
        $tile['subtitle'] = $subtitle;
    }

    $icon = trim((string) ($entry['icon'] ?? ''));
    if ($icon !== '') {
        $tile['icon'] = $icon;
    }

    $color = trim((string) ($entry['color'] ?? ''));
    if ($color !== '') {
        if ($color[0] !== '#') {
            $color = '#' . $color;
        }
        if (preg_match('/^#[0-9A-F]{3,6}$/i', $color)) {
            $tile['color'] = strtoupper($color);
        }
    }

    return $tile;
}

function iah_sanitize_renderer_domain_entry(array $entry): array
{
    $out = [];
    $fields = ['logName', 'roomDomain', 'title', 'subtitle', 'speechEmpty', 'aplDoc', 'aplToken', 'toggleVarKey'];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $entry)) {
            continue;
        }
        $val = trim((string) $entry[$field]);
        if ($val === '') {
            continue;
        }
        $out[$field] = $val;
    }

    return $out;
}

function iah_infer_tab_domain_title(array $tabs, string $route): string
{
    foreach ($tabs as $key => $tab) {
        $title = '';
        if (is_array($tab)) {
            $title = trim((string)($tab['title'] ?? ''));
            if ($title === '') {
                $title = trim((string)$key);
            }
        } else {
            $title = trim((string)$tab);
            if ($title === '') {
                $title = trim((string)$key);
            }
        }

        if ($title !== '') {
            return $title;
        }
    }

    return ucfirst($route);
}

function iah_detect_rooms_catalog_tab_domains(array $ROOMS, ?callable $normalizer = null): array
{
    $norm = $normalizer ?? static function ($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\s+/u', ' ', $value);
        return mb_strtolower($value, 'UTF-8');
    };

    $routes = [];
    foreach ($ROOMS as $room) {
        if (!is_array($room)) {
            continue;
        }
        $domains = (array)($room['domains'] ?? []);
        foreach ($domains as $domainKey => $domainDef) {
            $route = strtolower((string)$domainKey);
            if ($route === '' || isset($routes[$route])) {
                continue;
            }
            if (in_array($route, ['devices', 'sprinkler'], true)) {
                continue;
            }
            $tabs = (array)($domainDef['tabs'] ?? []);
            if ($tabs === []) {
                continue;
            }
            $title = iah_infer_tab_domain_title($tabs, $route);
            $synonyms = [];
            foreach ($tabs as $tab) {
                if (!is_array($tab)) {
                    continue;
                }
                $tabSynonyms = $tab['synonyms'] ?? [];
                if ($tabSynonyms === [] || $tabSynonyms === null) {
                    continue;
                }
                if (!is_array($tabSynonyms)) {
                    $tabSynonyms = [$tabSynonyms];
                }
                foreach ($tabSynonyms as $synonym) {
                    $normalized = $norm($synonym);
                    if ($normalized === '') {
                        continue;
                    }
                    $synonyms[$normalized] = true;
                }
            }
            $synonyms[$norm($route)] = true;
            $routes[$route] = [
                'route'      => $route,
                'roomDomain' => $route,
                'title'      => $title,
                'logName'    => $title,
            ];
            if ($synonyms !== []) {
                $routes[$route]['synonyms'] = array_keys($synonyms);
            }
        }
    }

    return $routes;
}

function iah_build_renderer_domain_list(array $props): array
{
    $raw = $props['RendererDomains'] ?? '[]';
    if (is_string($raw)) {
        $data = json_decode($raw, true);
    } elseif (is_array($raw)) {
        $data = $raw;
    } else {
        $data = [];
    }
    if (!is_array($data)) {
        $data = [];
    }

    $map = [];
    foreach ($data as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $route = strtolower(trim((string)($entry['route'] ?? '')));
        if ($route === '') {
            continue;
        }
        $base = iah_renderer_domain_base($route);
        $override = iah_renderer_domain_defaults_map()[$route] ?? [];
        $custom = iah_sanitize_renderer_domain_entry($entry);
        $custom['route'] = $route;
        $map[$route] = array_merge($base, $override, $custom);
    }

    foreach (iah_renderer_domain_defaults_map() as $route => $override) {
        if (!isset($map[$route])) {
            $map[$route] = array_merge(iah_renderer_domain_base($route), $override);
        }
    }

    return array_values($map);
}

function iah_build_logger(array $props): callable
{
    $rank = ['error' => 0, 'warn' => 1, 'info' => 2, 'debug' => 3];
    $level = strtolower((string) ($props['LOG_LEVEL'] ?? 'info'));

    return static function (string $lvl, string $msg, array $ctx = []) use ($rank, $level): void {
        $target = $rank[$level] ?? 2;
        $current = $rank[$lvl] ?? 2;
        if ($current > $target) {
            return;
        }

        if (!empty($ctx)) {
            $msg .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        IPS_LogMessage('Alexa', $msg);
    };
}

function iah_get_child_object(int $parent, string $ident, string $name): int
{
    if ($parent <= 0) {
        return 0;
    }
    if ($ident !== '') {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id) {
            return (int) $id;
        }
    }
    if ($name !== '') {
        $id = @IPS_GetObjectIDByName($name, $parent);
        if ($id) {
            return (int) $id;
        }
    }

    return 0;
}

function iah_resolve_configured_var(array $props, string $propKey, int $fallback = 0): int
{
    $configured = (int) ($props[$propKey] ?? 0);
    if ($configured > 0 && IPS_VariableExists($configured)) {
        return $configured;
    }

    return $fallback;
}

function iah_find_script_by_name(int $instanceId, string $name): int
{
    $local = @IPS_GetObjectIDByName($name, $instanceId);
    if ($local) {
        return (int) $local;
    }

    $global = @IPS_GetObjectIDByName($name, 0);
    return (int) $global;
}

function iah_get_config_script_id(array $props, int $instanceId): int
{
    $configured = (int) ($props['SystemConfigScriptId'] ?? 0);
    if ($configured > 0 && IPS_ScriptExists($configured)) {
        return $configured;
    }

    $auto = iah_get_child_object($instanceId, 'iahSystemConfiguration', 'SystemConfiguration');
    if ($auto > 0 && IPS_ScriptExists($auto)) {
        return $auto;
    }

    return 0;
}

function iah_detect_missing_entries(array $var, array $scripts): array
{
    $requiredVars = ['CoreHelpers', 'DeviceMap', 'RoomBuilderHelpers', 'DeviceMapWizard', 'Lexikon', 'DEVICE_MAP', 'PENDING_DEVICE', 'PENDING_STAGE', 'DOMAIN_FLAG', 'SKILL_ACTIVE', 'ACTION_VAR', 'DEVICE_VAR', 'ROOM_VAR', 'ALEXA_VAR'];
    $requiredScripts = ['ROOMS_CATALOG', 'NORMALIZER'];
    $missing = [];

    foreach ($requiredVars as $key) {
        $val = $var[$key] ?? 0;
        if ((int) $val === 0) {
            $missing[] = $key;
        }
    }

    foreach ($requiredScripts as $key) {
        $val = $scripts[$key] ?? 0;
        if ((int) $val === 0) {
            $missing[] = $key;
        }
    }

    return $missing;
}

function iah_build_system_configuration_internal(int $instanceId, array $props, callable $logCfg): array
{
    $settings = iah_get_child_object($instanceId, 'iahSettings', 'Einstellungen');
    $helper = iah_get_child_object($instanceId, 'iahHelper', 'Alexa new devices helper');
    $diag    = iah_get_child_object($instanceId, 'iahDiag', 'Diagnose');
    if ($diag <= 0) {
        $diag = $instanceId;
    }
    $logCfg('debug', 'CFG.build.internal', [
        'instanceId' => $instanceId,
        'settings' => $settings,
        'helper' => $helper,
    ]);

    $lueftungToggle = iah_get_child_object($settings, 'lueftungToggle', 'lueftung_toggle');
    $pageMappings = iah_build_page_mappings($props);
    $startPage = iah_derive_start_page($props);
    $energieLegacy = iah_legacy_page_id_from_mappings($pageMappings, 'energie', (string) ($props['EnergiePageId'] ?? ''));
    $kameraLegacy = iah_legacy_page_id_from_mappings($pageMappings, 'kamera', (string) ($props['KameraPageId'] ?? ''));

    $var = [
        'BaseUrl'       => (string) ($props['BaseUrl'] ?? ''),
        'Source'        => (string) ($props['Source'] ?? ''),
        'Token'         => (string) ($props['Token'] ?? ''),
        'Passwort'      => (string) ($props['Passwort'] ?? ''),
        'StartPage'     => $startPage,
        'LOG_LEVEL'     => (string) ($props['LOG_LEVEL'] ?? 'info'),
        'WfcId'         => (int) ($props['WfcId'] ?? 0),
        'EnergiePageId' => $energieLegacy,
        'KameraPageId'  => $kameraLegacy,
        'pageMappings'  => $pageMappings,
        'ActionsEnabled' => [
            'heizung_stellen'   => iah_get_child_object($settings, 'heizungStellen', 'heizung_stellen'),
            'jalousie_steuern'  => iah_get_child_object($settings, 'jalousieSteuern', 'jalousie_steuern'),
            'licht_switches'    => iah_get_child_object($settings, 'lichtSwitches', 'licht_switches'),
            'licht_dimmers'     => iah_get_child_object($settings, 'lichtDimmers', 'licht_dimmers'),
            'lueft_stellen'     => $lueftungToggle,
            'lueftung_toggle'   => $lueftungToggle,
            'geraete_toggle'    => iah_get_child_object($settings, 'geraeteToggle', 'geraete_toggle'),
            'bewaesserung_toggle' => iah_get_child_object($settings, 'bewaesserungToggle', 'bewaesserung_toggle'),
        ],
        'DEVICE_MAP'     => iah_get_child_object($helper, 'deviceMapJson', 'DeviceMapJson'),
        'PENDING_DEVICE' => iah_get_child_object($helper, 'pendingDeviceId', 'PendingDeviceId'),
        'PENDING_STAGE'  => iah_get_child_object($helper, 'pendingStage', 'PendingStage'),
        'DOMAIN_FLAG'    => iah_get_child_object($instanceId, 'domainFlag', 'domain_flag'),
        'SKILL_ACTIVE'   => iah_get_child_object($instanceId, 'skillActive', 'skillActive'),
        'AUSSEN_TEMP'    => iah_resolve_configured_var($props, 'VarAussenTemp'),
        'INFORMATION'    => iah_resolve_configured_var($props, 'VarInformation'),
        'MELDUNGEN'      => iah_resolve_configured_var($props, 'VarMeldungen'),
        'HEIZRAUM_IST'   => iah_resolve_configured_var($props, 'VarHeizraumIst'),
        'OG_GANG_IST'    => iah_resolve_configured_var($props, 'VarOgGangIst'),
        'TECHNIK_IST'    => iah_resolve_configured_var($props, 'VarTechnikIst'),
        'CoreHelpers'        => iah_get_child_object($helper, 'coreHelpersScript', 'CoreHelpers'),
        'DeviceMap'          => iah_get_child_object($helper, 'deviceMapScript', 'DeviceMap'),
        'RoomBuilderHelpers' => iah_get_child_object($helper, 'roomBuilderHelpersScript', 'RoomBuilderHelpers'),
        'DeviceMapWizard'    => iah_get_child_object($helper, 'deviceMapWizardScript', 'DeviceMapWizard'),
        'Lexikon'            => iah_get_child_object($helper, 'lexikonScript', 'Lexikon'),
        'ACTION_VAR'         => iah_get_child_object($diag, 'action', 'action'),
        'DEVICE_VAR'         => iah_get_child_object($diag, 'device', 'device'),
        'ROOM_VAR'           => iah_get_child_object($diag, 'room', 'room'),
        'SZENE_VAR'          => iah_get_child_object($diag, 'szene', 'szene'),
        'OBJECT_VAR'         => iah_get_child_object($diag, 'object', 'object'),
        'NUMBER_VAR'         => iah_get_child_object($diag, 'number', 'number'),
        'PROZENT_VAR'        => iah_get_child_object($diag, 'prozent', 'prozent'),
        'ALLES_VAR'          => iah_get_child_object($diag, 'alles', 'alles'),
        'ALEXA_VAR'          => iah_get_child_object($diag, 'alexa', 'alexa'),
    ];

    $scripts = [
        'ROUTE_ALL'           => iah_find_script_by_name($instanceId, 'Route_allRenderer'),
        'RENDER_MAIN'         => iah_find_script_by_name($instanceId, 'LaunchRequest'),
        'RENDER_HEIZUNG'      => iah_find_script_by_name($instanceId, 'HeizungRenderer'),
        'RENDER_JALOUSIE'     => iah_find_script_by_name($instanceId, 'JalousieRenderer'),
        'RENDER_LICHT'        => iah_find_script_by_name($instanceId, 'LichtRenderer'),
        'RENDER_LUEFTUNG'     => iah_find_script_by_name($instanceId, 'LüftungRenderer'),
        'RENDER_GERAETE'      => iah_find_script_by_name($instanceId, 'GeraeteRenderer'),
        'RENDER_BEWAESSERUNG' => iah_find_script_by_name($instanceId, 'BewaesserungRenderer'),
        'RENDER_SETTINGS'     => iah_find_script_by_name($instanceId, 'EinstellungsRender'),
        'ROOMS_CATALOG'       => iah_get_child_object($settings, 'roomsCatalog', 'RoomsCatalog'),
        'NORMALIZER'          => iah_get_child_object($helper, 'normalizerScript', 'Normalizer'),
    ];

    $missing = iah_detect_missing_entries($var, $scripts);

    $rendererDomains = iah_build_renderer_domain_list($props);

    return [
        'var' => $var,
        'script' => $scripts,
        'missing' => $missing,
        'rendererDomains' => $rendererDomains,
        'launchCatalog' => iah_build_launch_catalog($props),
    ];
}

function iah_build_system_configuration(int $instanceId): array
{
    $props = iah_get_instance_properties($instanceId);
    $logCfg = iah_build_logger($props);
    $scriptId = iah_get_config_script_id($props, $instanceId);

    if ($scriptId > 0) {
        $path = IPS_GetScriptFile($scriptId);
        $data = @require $path;
        if (is_array($data)) {
            $var = is_array($data['var'] ?? null) ? $data['var'] : [];
            $scripts = is_array($data['script'] ?? null) ? $data['script'] : [];
            $missing = is_array($data['missing'] ?? null) ? $data['missing'] : iah_detect_missing_entries($var, $scripts);
            $logCfg('debug', 'CFG.load.script', ['script' => $scriptId]);
            $rendererDomains = is_array($data['rendererDomains'] ?? null)
                ? $data['rendererDomains']
                : iah_build_renderer_domain_list($props);
            $launchCatalog = is_array($data['launchCatalog'] ?? null)
                ? $data['launchCatalog']
                : iah_build_launch_catalog($props);
            if (!isset($var['pageMappings']) || !is_array($var['pageMappings'])) {
                $var['pageMappings'] = iah_build_page_mappings($props);
            }
            return [
                'var' => $var,
                'script' => $scripts,
                'missing' => $missing,
                'rendererDomains' => $rendererDomains,
                'launchCatalog' => $launchCatalog,
            ];
        }

        $logCfg('warn', 'CFG.load.script.invalid', ['script' => $scriptId]);
    } else {
        $logCfg('warn', 'CFG.load.script.notfound', []);
    }

    return iah_build_system_configuration_internal($instanceId, $props, $logCfg);
}

function Execute($request = null)
{
    // --- Konstanten für Wizard-Status ---
    $STAGE_AWAIT_NAME = 'await_name';
    $STAGE_AWAIT_APL  = 'await_apl';

    try {
        $JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ($request === null) {
            IPS_LogMessage('Alexa', 'Script manuell ausgeführt – kein Request vorhanden. Beende ohne Fehler.');
            return;
        }

        // --------- Config laden ---------
        $instanceId = iah_get_instance_id();
        $CFG = iah_build_system_configuration($instanceId);
        if (!empty($CFG['missing'])) {
            return TellResponse::CreatePlainText('Fehler: Folgende IDs fehlen oder konnten nicht erzeugt werden: ' . implode(', ', $CFG['missing']));
        }

        $V = $CFG['var'];
        $S = $CFG['script'];
        $pageMappings = is_array($V['pageMappings'] ?? null) ? $V['pageMappings'] : [];
        $launchCatalog = is_array($CFG['launchCatalog'] ?? null) ? $CFG['launchCatalog'] : [];
        $rendererDomains = is_array($CFG['rendererDomains'] ?? null) ? $CFG['rendererDomains'] : [];
        $rendererDomainMap = [];
        foreach ($rendererDomains as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $routeKey = strtolower((string)($entry['route'] ?? ''));
            if ($routeKey === '') {
                continue;
            }
            $rendererDomainMap[$routeKey] = $entry;
        }
        $rendererRoomDomainKeys = [];
        foreach ($rendererDomainMap as $routeKey => $entry) {
            $roomDomain = strtolower((string)($entry['roomDomain'] ?? ''));
            if ($roomDomain === '') {
                $roomDomain = $routeKey;
            }
            $rendererRoomDomainKeys[$routeKey] = $roomDomain;
        }
        $rendererDomainSynonyms = [];
        foreach ($rendererDomainMap as $routeKey => $entry) {
            foreach ([
                $routeKey,
                strtolower((string)($entry['roomDomain'] ?? '')),
                strtolower((string)($entry['logName'] ?? '')),
                strtolower((string)($entry['title'] ?? '')),
            ] as $syn) {
                $syn = trim((string) $syn);
                if ($syn === '' || in_array($syn, ['devices', 'sprinkler'], true)) {
                    continue;
                }
                $rendererDomainSynonyms[$syn] = $routeKey;
            }
        }
        $claimedRoomDomains = [];
        foreach ($rendererRoomDomainKeys as $roomDomain) {
            if ($roomDomain === '') {
                continue;
            }
            $claimedRoomDomains[$roomDomain] = true;
        }

        $writeRuntimeString = static function ($varId, string $value): void {
            $id = (int) $varId;
            if ($id > 0) {
                @SetValueString($id, $value);
            }
        };

        // Legacy alias: expose lueftung_toggle also as lueft_stellen
        if (isset($V['ActionsEnabled']) && is_array($V['ActionsEnabled'])) {
            if (!isset($V['ActionsEnabled']['lueft_stellen']) && isset($V['ActionsEnabled']['lueftung_toggle'])) {
                $V['ActionsEnabled']['lueft_stellen'] = $V['ActionsEnabled']['lueftung_toggle'];
            }
        }
        if (isset($CFG['var']['ActionsEnabled']) && is_array($CFG['var']['ActionsEnabled'])) {
            if (!isset($CFG['var']['ActionsEnabled']['lueft_stellen']) && isset($CFG['var']['ActionsEnabled']['lueftung_toggle'])) {
                $CFG['var']['ActionsEnabled']['lueft_stellen'] = $CFG['var']['ActionsEnabled']['lueftung_toggle'];
            }
        }
        $baseUrl = (string)($V['BaseUrl'] ?? '');
        $token   = (string)($V['Token']   ?? '');
        $source  = (string)($V['Source']  ?? '');

        // --- Core-Helper laden (applyApl/makeCard/matchDomain/…)
        if (!isset($V['CoreHelpers']) || (int)$V['CoreHelpers'] === 0) {
            return TellResponse::CreatePlainText('Fehler: Die ID für "CoreHelpers" (Helfer-Skript) ist nicht in der Konfiguration gesetzt. (var.CoreHelpers)');
        }

        $CORE = require IPS_GetScriptFile((int)$V['CoreHelpers']);
        $applyApl       = $CORE['applyApl'];
        $makeCard       = $CORE['makeCard'];
        $maskToken      = $CORE['maskToken'];
        $matchDomain    = $CORE['matchDomain'];
        $resetState     = $CORE['resetState'];
        $getDomainPref  = $CORE['getDomainPref'];
        $mkCid          = $CORE['mkCid'];
        $logExt         = $CORE['logExt'];
        $getSlotCH      = $CORE['getSlot'];
        $parseAplArgs   = $CORE['parseAplArgs'];
        $roomSpokenCH   = $CORE['room_key_by_spoken'];
        $domainFromAPL  = $CORE['domainFromAplArgs'];
        $buildTabsCache = $CORE['buildTabsCache'];
        $findTabIdCH    = $CORE['findTabId'];
        $fallbackTabCH  = $CORE['fallbackTabDomain'];
        $tabDomainById  = $CORE['tabDomainById'];
        $extractNumber  = $CORE['extractNumberOnly'];
        $mergeDecimal   = $CORE['maybeMergeDecimalFromPercent'];

        // Helfer-Skripte
        $DM_HELPERS = require IPS_GetScriptFile((int)$V['DeviceMap']);
        $RBUILDER = require IPS_GetScriptFile((int)$V['RoomBuilderHelpers']);

        // --------- Lexikon & Normalizer ---------
        $LEX  = require IPS_GetScriptFile($V['Lexikon']);
        $PAT  = $LEX['patterns'];

        $NORM = require IPS_GetScriptFile($S['NORMALIZER']);
        $lc                  = $NORM['lc'];
        $normalizeRoom       = $NORM['room_slug'];
        $token_norm          = $NORM['token_norm'];
        $normalize_action    = $NORM['action'];
        $normalize_decimal_words = static function (string $s) use ($NORM, $PAT) {return $NORM['decimal_words']($s, $PAT['decimal_words']);};

        // --------- Config laden Ende ---------

        // --------- Logging Wrapper ---------
        $LOG_LEVEL = (string)($V['LOG_LEVEL'] ?? 'info');
        $rank = ['off'=>0,'warn'=>1,'info'=>2,'debug'=>3];
        $log = static function(string $lvl, string $msg, $ctx=null) use ($LOG_LEVEL,$rank,$JSON) {
            if (($rank[$lvl]??2) <= ($rank[$LOG_LEVEL]??2)) {
                IPS_LogMessage('Alexa', $msg . ($ctx!==null?(' '.json_encode($ctx,$JSON)):''));
            }
        };

        $log('debug', 'SupportedInterfaces', array_keys((array)($request->context->System->device->supportedInterfaces ?? [])));
        $intentName = method_exists($request,'GetIntentName') ? (string)$request->GetIntentName() : '';
        $log('debug','Intent', $intentName);

        // --------- ActionsEnabled ---------
        $IDS  = (array)($V['ActionsEnabled'] ?? []);
        $read = static function ($id) { return $id ? (bool)GetValue($id) : false; };
        $ACTIONS_ENABLED = [
            'heizung'       => ['stellen_aendern' => $read($IDS['heizung_stellen']      ?? 0)],
            'jalousie'      => ['steuern'         => $read($IDS['jalousie_steuern']     ?? 0)],
            'licht'         => ['switches'        => $read($IDS['licht_switches']       ?? 0), 'dimmers'         => $read($IDS['licht_dimmers']        ?? 0)],
            'lueftung'      => ['toggle'          => $read($IDS['lueft_stellen']        ?? ($IDS['lueftung_toggle'] ?? 0))],
            'geraete'       => ['toggle'          => $read($IDS['geraete_toggle']       ?? 0)],
            'bewaesserung'  => ['toggle'          => $read($IDS['bewaesserung_toggle']  ?? 0)],
        ];
        $lueftEnabled = (bool)($ACTIONS_ENABLED['lueftung']['toggle'] ?? false);
        $ACTIONS_ENABLED['lueft_stellen'] = $lueftEnabled;
        $ACTIONS_ENABLED['lueft'] = ['stellen_aendern' => $lueftEnabled, 'stellen' => $lueftEnabled];
        $log('debug','AE(main)', $ACTIONS_ENABLED);

        // --------- RoomsCatalog ---------
        $ROOMS = iah_merge_global_room_domains((array) require IPS_GetScriptFile($S['ROOMS_CATALOG']));
        $externalPages = iah_build_external_page_catalog($ROOMS, $pageMappings, $launchCatalog);
        if (!is_array($ROOMS) || $ROOMS === []) {
            return TellResponse::CreatePlainText('Fehler: RoomsCatalog leer oder ungültig.');
        }

        $tabDomains = iah_detect_rooms_catalog_tab_domains($ROOMS, $lc);
        $tabDomainSynonyms = [];
        foreach ($tabDomains as $routeKey => $entry) {
            foreach ((array)($entry['synonyms'] ?? []) as $synonym) {
                if ($synonym === '') {
                    continue;
                }
                $tabDomainSynonyms[$synonym] = $routeKey;
            }
        }
        $addedDynamicRoute = false;
        foreach ($tabDomains as $routeKey => $entry) {
            $roomDomain = strtolower((string)($entry['roomDomain'] ?? $routeKey));
            if ($roomDomain === '') {
                $roomDomain = $routeKey;
            }
            if (isset($claimedRoomDomains[$roomDomain])) {
                continue;
            }
            if (!isset($rendererDomainMap[$routeKey])) {
                $rendererDomainMap[$routeKey] = array_merge(iah_renderer_domain_base($routeKey), $entry);
            }
            $rendererRoomDomainKeys[$routeKey] = $roomDomain;
            $claimedRoomDomains[$roomDomain] = true;
            $addedDynamicRoute = true;
        }
        if ($addedDynamicRoute) {
            $rendererDomains = array_values($rendererDomainMap);
            $CFG['rendererDomains'] = $rendererDomains;
        }

        // ---------- Frühe Slots (für Exit) ----------
        $action1  = $lc($getSlotCH($request,'Action')  ?? '');
        $szene1   = $lc($getSlotCH($request,'Szene')   ?? '');
        $device1  = $lc($getSlotCH($request,'Device')  ?? '');
        $room1    = $lc($getSlotCH($request,'Room')    ?? '');
        $object1  = $lc($getSlotCH($request,'Object')  ?? '');
        $number1  = $getSlotCH($request,'Number')      ?? null;
        $prozent1 = $getSlotCH($request,'Prozent')     ?? null;
        $alles1   = $lc($getSlotCH($request,'Alles')   ?? '');

        $log('debug','RawSlots', ["a"=>$action1,"s"=>$szene1,"d"=>$device1,"r"=>$room1,"o"=>$object1,"n"=>$number1,"p"=>$prozent1,"al"=>$alles1]);

        // ---------- Exit ----------
        $wantsExit = in_array($action1, ['ende','fertig','exit'], true) ||
            ($action1 === 'ende' && $device1 === '' && $room1 === '' && $object1 === '' && $szene1 === '' && $alles1 === '' &&
            ($number1 === null || $number1 === '' || $number1 === '?') && ($prozent1 === null || $prozent1 === '' || $prozent1 === '?'));
        if ($wantsExit) {
            $resetState($V);
            SetValueBoolean($V['SKILL_ACTIVE'], false);
            return TellResponse::CreatePlainText('ok, bis bald!');
        }

        // ---------- Launch/Zurück ----------
        $IS_LAUNCH = $request->IsLaunchRequest();
        $IS_BACK   = ($lc($getSlotCH($request,'Action') ?? '') === 'zurück');
        if ($IS_LAUNCH || $IS_BACK) { $resetState($V); }

        // ---------- APL_ARGS zentral ----------
        $APL = $parseAplArgs($request);
        $log('debug','APL_ARGS', $APL['args']);

        // ============================================================
        // FRÜHER, HARTER UI-OVERRIDE (Buttons aus der Main-Ansicht)
        // ============================================================
        $navForce = false;
        $forcedDomain = null;
        $forcedDevice = null;

        if (is_array($APL['args']) && count($APL['args']) >= 2 && (string)$APL['args'][0] === 'GetHaus') {
            $navId  = $lc((string)($APL['a1'] ?? ''));
            $navId2 = $lc((string)($APL['a2'] ?? ''));
            if ($navId === 'home' && $navId2 !== '') { $navId = $navId2; }

            $NAV_MAP = [
                'licht'          => ['domain' => 'licht',         'device' => 'licht'],
                'jalousie'       => ['domain' => 'jalousie',      'device' => 'jalousie'],
                'heizung'        => ['domain' => 'heizung',       'device' => 'temperatur'],
                'lueftung'       => ['domain' => 'lueftung',      'device' => 'lueftung'],
                'einstellungen'  => ['domain' => 'einstellungen', 'device' => 'einstellungen'],
                'bewaesserung'   => ['domain' => 'bewaesserung',  'device' => 'bewaesserung'],
                'geraete'        => ['domain' => 'geraete',       'device' => 'geraete'],
                'sicherheit'     => ['domain' => 'sicherheit',    'device' => 'sicherheit'],
                'listen'         => ['domain' => 'listen',        'device' => 'listen'],
                'energie'        => ['domain' => 'energie',       'device' => 'energie'],
                'kamera'         => ['domain' => 'kamera',        'device' => 'kamera'],
                'info'           => ['domain' => 'info',          'device' => 'info'],
                'szene'          => ['domain' => 'szene',         'device' => 'szene'],
            ];

            foreach ($rendererDomainMap as $routeKey => $entry) {
                if (isset($NAV_MAP[$routeKey])) {
                    continue;
                }
                if ($routeKey === '' || in_array($routeKey, ['main_launch', 'external', 'settings'], true)) {
                    continue;
                }
                $roomDomainRaw = strtolower((string)($rendererRoomDomainKeys[$routeKey] ?? ($entry['roomDomain'] ?? '')));
                if ($roomDomainRaw === '') {
                    $roomDomainRaw = $routeKey;
                }
                $domainAlias = $roomDomainRaw;
                if ($domainAlias === '' || in_array($domainAlias, ['devices', 'sprinkler'], true)) {
                    $domainAlias = $routeKey;
                }
                $NAV_MAP[$routeKey] = [
                    'domain' => $domainAlias,
                    'device' => $domainAlias,
                ];
                if ($domainAlias !== $routeKey && !isset($NAV_MAP[$domainAlias])) {
                    $NAV_MAP[$domainAlias] = $NAV_MAP[$routeKey];
                }
            }

            if (isset($NAV_MAP[$navId])) {
                $forcedDomain = $NAV_MAP[$navId]['domain'];
                $forcedDevice = $NAV_MAP[$navId]['device'];
                $navForce     = true;
                SetValueString($V['DOMAIN_FLAG'], $forcedDomain);
                IPS_LogMessage('Alexa', 'UI_FORCE → navId=' . $navId . ' / domain=' . $forcedDomain . ' / device=' . $forcedDevice);
            }
        }

        // --------- HTML.Message → nur Route setzen ---------
        $__route = null;
        if (($request->type ?? '') === 'Alexa.Presentation.HTML.Message') {
            $msg = (array)($request->message ?? []);
            if (($msg['type'] ?? null) === 'back') {
                $__route = 'main_launch';
            }
        }

        // --------- External Pages → nur Route markieren ---------
        $nav = strtolower((string) ($APL['a1'] ?? ''));
        $selected = null;
        foreach ($externalPages as $key => $cfg) {
            $actions = is_array($cfg['actions'] ?? null) ? $cfg['actions'] : [];
            $navs    = is_array($cfg['navs'] ?? null) ? $cfg['navs'] : [];
            $hit = in_array($action1, $actions, true)
                || in_array($nav, $navs, true)
                || ($navForce && strtolower((string) $forcedDomain) === $key);
            if ($hit) { $selected = $key; break; }
        }
        if ($selected !== null) { $__route = 'external'; }

        // --------- Hauptslots (zweite Runde) ---------
        $deviceId = $request->GetDeviceId();
        $action   = $lc($getSlotCH($request,'Action') ?? '');
        $szene    = $lc($getSlotCH($request,'Szene')  ?? '');
        $device   = $lc($getSlotCH($request,'Device') ?? '');
        $room     = $lc($getSlotCH($request,'Room')   ?? '');

        // Etagen normalisieren
        $room = preg_replace('/(*UTF8)(*UCP)(?<!\pL)(untergeschoss|ug)(?!\pL)/u', 'keller', $room);
        $room = preg_replace('/(*UTF8)(*UCP)(?<!\pL)obergeschoss(?!\pL)/u', 'og', $room);
        $room = preg_replace('/(*UTF8)(*UCP)(?<!\pL)erdgeschoss(?!\pL)/u', 'eg', $room);
        $room = trim(preg_replace('/\s{2,}/u', ' ', $room));

        $object  = $lc($getSlotCH($request,'Object') ?? '');
        $number  = $getSlotCH($request,'Number') ?? null;
        $prozent = $getSlotCH($request,'Prozent') ?? null;
        $alles   = $lc($getSlotCH($request,'Alles')  ?? '');

        // PENDING_STAGE lesen (Wizard nur bei aktivem Stage)
        $stage = '';
        if (!empty($V['PENDING_STAGE'])) {
            $stage = @GetValueString($V['PENDING_STAGE']);
        }

        // Falls UI-Override aktiv: Device & Domain hart setzen
        $domain = null;
        if ($navForce) {
            $domain = $forcedDomain;
            $device = $forcedDevice;
        }

        if (in_array($action, ['ende','fertig','exit','zurück'], true)) {
            SetValueString($V['DOMAIN_FLAG'], "");
        }

        // --------- Device-Map Wizard Flow (ausgelagert, nur bei aktivem Stage) ---------
        if ($stage !== '') {
            $wizard = require IPS_GetScriptFile((int)$V['DeviceMapWizard']);

            $wzResult = $wizard['handle_wizard'](
                $V,
                $intentName,
                (string)$action,
                (string)$alles,
                (string)$room,
                (string)$device,
                $DM_HELPERS,
                $STAGE_AWAIT_NAME,
                $STAGE_AWAIT_APL
            );

            if ($wzResult !== null) {
                $type     = (string)($wzResult['type'] ?? '');
                $text     = (string)($wzResult['text'] ?? '');
                $reprompt = isset($wzResult['reprompt']) ? (string)$wzResult['reprompt'] : '';

                if ($type === 'ask') {
                    $ask = AskResponse::CreatePlainText($text);
                    if ($reprompt !== '') {
                        $ask->SetRepromptPlainText($reprompt);
                    }
                    return $ask;
                }

                if ($type === 'tell') {
                    return TellResponse::CreatePlainText($text);
                }

                return TellResponse::CreatePlainText(
                    $text !== '' ? $text : 'Assistent beendet.'
                );
            }
        }

        // --------- Room/Number/Action Normalisierung ---------
        $room_raw = $room;
        [$room, $powerHint] = $NORM['extract_power_from_room']($room);
        $room_candidates_text = trim(($room ? $room . ' ' : '') . $object . ' ' . $action . ' ' . $alles . ' ' . $device);
        $room = $roomSpokenCH($ROOMS, $room, $token_norm) ?? $roomSpokenCH($ROOMS, $room_candidates_text, $token_norm) ?? '';

        if (!$navForce) {
            $device = preg_replace('/(*UTF8)(*UCP)\s+\d+(?:[.,]\d+)?$/u', '', $device);
            $device = trim(preg_replace('/\s{2,}/u', ' ', $device));
            $device = preg_replace('/(*UTF8)(*UCP)(?<!\pL)heizung(?!\pL)/u', 'temperatur', $device);
            if (preg_match('/(*UTF8)(*UCP)(?<!\pL)temperatur(?!\pL)/u', $device)) $device = 'temperatur';
        }

        // Nummer/Prozent extrahieren
        [$number, $target]  = $extractNumber($action, $device, $alles, $room, $object, $szene, $number, $normalize_decimal_words, $PAT);
        [$number, $prozent] = $mergeDecimal($number, $prozent, $action, $device, $alles);
        if ($number !== null && $number !== '' && $number !== '?') $target = (float)str_replace(',', '.', (string)$number);

        $action = $normalize_action($action, $device, $alles, $object, $room);
        $power  = $NORM['power_from_tokens']($action, $device, $alles, $object, $room, $powerHint);
        if (!$power) { if (in_array($action, ['ein','an'], true)) $power = 'on'; elseif ($action === 'aus') $power = 'off'; }

        // APL args1/args2/args3 (nur Info / leichte Korrekturen)
        if ($APL['a1'] !== null) {
            IPS_LogMessage('Alexa', "APL entity args1 parsed → " . $APL['a1']);
            if (is_numeric($APL['a1']))       { $number = $APL['a1']; }
            else if ($lc((string)$APL['a1']) !== 'zurück') { $action = $lc((string)$APL['a1']) ?: $action; }
        }

        // Prüfe Tab-Domain anhand der APL-Args (z. B. geraete.tab + tabId), um missklassifizierte Sicherheit/Save-Tabs zu erkennen
        $aplTabRoute = null;
        $aplTabId    = null;
        foreach ((array)($APL['args'] ?? []) as $aplArg) {
            if (is_string($aplArg) && preg_match('/([a-z0-9_-]+)\.tab$/i', $aplArg, $m)) {
                $aplTabRoute = strtolower($m[1]);
            }
            if (is_string($aplArg) && ctype_digit($aplArg)) {
                $aplTabId = $aplArg;
            }
        }
        if ($aplTabId !== null) {
            $tabDomain = $tabDomainById($aplTabId, $ROOMS);
            if ($tabDomain !== null) {
                $mappedDomain = $tabDomain;

                // Versuche, anhand der bekannten Renderer-Domains einen passenden Routen-Key zu finden (z. B. save → sicherheit)
                foreach ($rendererRoomDomainKeys as $routeKey => $roomDomainKey) {
                    if ($roomDomainKey === $tabDomain) {
                        $mappedDomain = $routeKey;
                        break;
                    }
                }
                if ($mappedDomain === $tabDomain && isset($rendererDomainMap[$tabDomain])) {
                    $mappedDomain = $tabDomain;
                }
                if ($mappedDomain === $tabDomain && isset($rendererDomainSynonyms[$tabDomain])) {
                    $mappedDomain = $rendererDomainSynonyms[$tabDomain];
                }

                // Sicherheits-/Save-Tabs auf die explizite Sicherheit-Route legen, falls keine Mapping-Regel gegriffen hat
                if ($mappedDomain === $tabDomain && in_array($tabDomain, ['sprinkler', 'save', 'sicherheit'], true)) {
                    if (isset($rendererDomainMap['sicherheit'])) {
                        $mappedDomain = 'sicherheit';
                    } elseif (isset($rendererDomainSynonyms['sicherheit'])) {
                        $mappedDomain = $rendererDomainSynonyms['sicherheit'];
                    }
                }

                $priorDomain = $domain;
                $domain = $mappedDomain;
                if (!$navForce && $device === '') {
                    $device = $mappedDomain;
                }
                $log('debug', 'APL_TAB_DOMAIN', [
                    'tabId'        => $aplTabId,
                    'tabDomain'    => $tabDomain,
                    'mappedDomain' => $mappedDomain,
                    'aplRoute'     => $aplTabRoute,
                    'prevDomain'   => $priorDomain,
                ]);
            } else {
                $log('debug', 'APL_TAB_DOMAIN_MISS', ['tabId' => $aplTabId, 'aplRoute' => $aplTabRoute]);
            }
        }

        // --- Domain aus APL-Args ableiten ---
        if ($domain === null) {
            $d = $domainFromAPL($APL, $lc);
            if ($d !== null) {
                $domain = $d;
                if (!$navForce && $device === '') {
                    $device = ($domain === 'heizung') ? 'temperatur' : $domain;
                }
            }
        }

        // --------- Tabs-Matching + Fallback (über CoreHelpers) ---------
        $findTabIdWithCache = static function(string $spoken) use ($buildTabsCache, $findTabIdCH, $ROOMS, $token_norm) : ?string {
            $cache = $buildTabsCache($ROOMS);
            return $findTabIdCH($spoken, $cache, $token_norm);
        };
        $loggerForFallback = static function(string $lvl, string $msg, $ctx=null) use ($log) { $log($lvl,$msg,$ctx); };

        // Globaler Bewässerung-Tab-Fallback zuerst, damit Sicherheit/Save-Tabs nicht zu Geräte gemappt werden
        if ($domain === null && !$navForce) {
            $fallbackTabCH($domain, $device, 'bewaesserung', $action, $device, $object, $alles, $findTabIdWithCache, $loggerForFallback);
        }
        // Globaler Geräte-Tab-Fallback
        if ($domain === null && !$navForce) {
            $fallbackTabCH($domain, $device, 'geraete', $action, $device, $object, $alles, $findTabIdWithCache, $loggerForFallback);
        }

        // --- Fallback: DOMAIN_FLAG als Präferenz
        if ($domain === null) {
            $pref = $getDomainPref($V);
            if ($pref !== '') {
                $domain = $pref;
                if (!$navForce && $device === '') {
                    $device = ($pref === 'heizung') ? 'temperatur' : $pref;
                }
            }
        }

        // Dimmer-Ereignis
        $domainPrefNow = $getDomainPref($V);
        $aplDimmerEvent = (is_numeric($APL['a1'])) && (
            (is_string($APL['a2']) && stripos($APL['a2'], 'licht.') === 0) ||
            (is_string($APL['a2']) && preg_match('/(*UTF8)(*UCP)(?<!\pL)licht(?!\pL)/u', $APL['a2']) === 1) ||
            ($domainPrefNow === 'licht')
        );
        if ($aplDimmerEvent && !$navForce) { $device = 'licht'; $action = ''; }

        // Geräte/Bewässerung: Domain-Erzwingung durch APL-Events
        if ($domain === null && is_string($APL['a1']) && str_starts_with((string)$APL['a1'], 'geraete.')) {
            $domain = 'geraete';
        }
        if (($APL['a1'] ?? null) === 'geraete.setEnum' && is_numeric($APL['a2'] ?? null) && is_numeric($APL['a3'] ?? null)) {
            RequestAction((int)$APL['a2'], (int)$APL['a3']);
        }
        if ($domain === null && is_string($APL['a1']) && str_starts_with((string)$APL['a1'], 'bewaesserung.')) {
            $domain = 'bewaesserung';
        }
        if (($APL['a1'] ?? null) === 'bewaesserung.setEnum' && is_numeric($APL['a2'] ?? null) && is_numeric($APL['a3'] ?? null)) {
            RequestAction((int)$APL['a2'], (int)$APL['a3']);
        }

        if ($domain === null && !$navForce && !empty($tabDomainSynonyms)) {
            $slotValues = [$action, $device, $room, $object, $alles, $szene];
            foreach ($slotValues as $slotValue) {
                $slotKey = trim((string) $slotValue);
                if ($slotKey === '') {
                    continue;
                }
                if (isset($tabDomainSynonyms[$slotKey])) {
                    $domain = $tabDomainSynonyms[$slotKey];
                    break;
                }
            }
        }

        if ($domain === null && !$navForce && !empty($rendererDomainSynonyms)) {
            $slotValues = [$action, $device, $room, $object, $alles, $szene];
            foreach ($slotValues as $slotValue) {
                $slotKey = $lc(trim((string) $slotValue));
                if ($slotKey === '') {
                    continue;
                }
                if (isset($rendererDomainSynonyms[$slotKey])) {
                    $domain = $rendererDomainSynonyms[$slotKey];
                    break;
                }
            }
        }

        // --------- Domain-Autodetect (nur wenn nicht navForce) ---------
        if ($domain === null && !$navForce) {
            $domain = $matchDomain($action, $device, $alles, $room);
        }
        $log('debug','domain', ($domain ?? '(auto)'));

        $writeRuntimeString($V['ACTION_VAR'] ?? 0, (string) $action);
        $writeRuntimeString($V['DEVICE_VAR'] ?? 0, (string) $device);
        $writeRuntimeString($V['ROOM_VAR'] ?? 0, (string) $room);
        $writeRuntimeString($V['SZENE_VAR'] ?? 0, (string) $szene);
        $writeRuntimeString($V['OBJECT_VAR'] ?? 0, (string) $object);
        $writeRuntimeString($V['NUMBER_VAR'] ?? 0, $number === null ? '' : (string) $number);
        $writeRuntimeString($V['PROZENT_VAR'] ?? 0, $prozent === null ? '' : (string) $prozent);
        $writeRuntimeString($V['ALLES_VAR'] ?? 0, (string) $alles);

        // --------- Außentemperatur-Shortcut ---------
        $AUSSEN_ALIASES = ['außentemperatur','aussentemperatur'];
        if (in_array($action,$AUSSEN_ALIASES,true) || in_array($device,$AUSSEN_ALIASES,true) ||
            in_array($object,$AUSSEN_ALIASES,true) || in_array($alles,$AUSSEN_ALIASES,true)) {
            $text = 'Die Außentemperatur beträgt ' . GetValueFormatted($V['AUSSEN_TEMP']);
            $img  = 'https://media.istockphoto.com/id/1323823418/de/foto/niedrigwinkelansicht-thermometer-am-blauen-himmel-mit-sonnenschein.jpg?s=612x612&w=0&k=20&c=iFJaAAxJ_chcBz5Bnjy20HSlULU7AWIW16d_bwlB0Ss=';
            $resetState($V);
            return AskResponse::CreatePlainText($text)
                ->SetStandardCard($text, $text, $img, $img)
                ->SetRepromptPlainText('möchtest du zu Heizung oder zurück? - oder soll ich noch eine Temperatur ansagen?');
        }

        // --------- Device-Map Pflege ---------
        [$alexa, $aplSupported, $isNewDevice] = $DM_HELPERS['ensure']($V['DEVICE_MAP'], (string)$deviceId);
        IPS_LogMessage("Alexa", 'Alexa: ' . $alexa);
        IPS_LogMessage("Alexa", 'APL Supported: ' . ($aplSupported ? 'true' : 'false'));

        $writeRuntimeString($V['ALEXA_VAR'] ?? 0, (string) $alexa);

        if (($APL['a1'] ?? null) === 'delete') {
            $map = $DM_HELPERS['load']($V['DEVICE_MAP']); $deleted = false;
            if ($alexa !== '') {
                $alexaLc = mb_strtolower($alexa, 'UTF-8');
                foreach ($map as $k => $entry) {
                    $locLc = mb_strtolower((string)($entry['location'] ?? ''), 'UTF-8');
                    if ($locLc !== '' && $locLc === $alexaLc) { unset($map[$k]); $deleted = true; }
                }
            }
            if (!$deleted && isset($map[$deviceId])) { unset($map[$deviceId]); $deleted = true; }
            $DM_HELPERS['save']($V['DEVICE_MAP'], $map);
            $msg = $deleted ? ('Eintrag „' . $alexa . '“ wurde gelöscht.') : 'Kein passender Eintrag gefunden.';
            return AskResponse::CreatePlainText($msg)->SetRepromptPlainText('Was möchtest du als Nächstes?');
        }

        if ($isNewDevice && ($request->IsLaunchRequest() || $action === '')) {
            SetValueString($V['PENDING_DEVICE'], (string)$deviceId);
            SetValueString($V['PENDING_STAGE'], $STAGE_AWAIT_NAME);
            $msg = 'Neues Gerät erkannt. Wie soll ich das Gerät nennen?';
            return AskResponse::CreatePlainText($msg)->SetRepromptPlainText('Sag mir einen Namen, zum Beispiel Küche oder Wohnzimmer.');
        }

        $alexaKey = $roomSpokenCH($ROOMS, $alexa, $token_norm) ?? $normalizeRoom($alexa);
        if (!isset($ROOMS[$alexaKey])) {
            $fallback = isset($ROOMS['wohnzimmer']) ? 'wohnzimmer' : array_key_first($ROOMS);
            IPS_LogMessage('Alexa', "alexaKey '".($alexaKey ?: '(leer)')."' nicht gefunden – Fallback: '$fallback'");
            $alexaKey = $fallback;
        }

        if ($action === 'wer bist du') {
            return AskResponse::CreatePlainText('Ich bin die Alexa für ' . $alexa)->SetRepromptPlainText('wie kann ich helfen?');
        }

         $isPlainGetHaus = (
            $intentName === 'GetHaus'
            && $action === ''
            && $device === ''
            && $room === ''
            && $object === ''
            && $szene === ''
            && $alles === ''
            && (($APL['a1'] ?? null) === null)
        );

        // ===== Tabellen-Router =====
        $ROUTES = [
            'main'         => fn()=> $request->IsLaunchRequest()
                || $isPlainGetHaus
                || ($action==='zurück' && !is_numeric($APL['a1']))
                || ($APL['a1']==='zurück'),
            'main_home'    => fn()=> is_array($APL['args']) && (($APL['args'][0]??'')==='GetHaus') && (($APL['args'][1]??'')==='home'),
            'heizung'      => fn()=> $domain==='heizung' || $device==='heizung' || $device==='temperatur',
            'jalousie'     => fn()=> $domain==='jalousie' || $device==='jalousie',
            'licht'        => fn()=> $domain==='licht'    || $device==='licht',
            'lueftung'     => fn()=> $domain==='lueftung' || $device==='lueftung',
            'geraete'      => fn()=> $domain==='geraete'  || $device==='geraete'  || (is_string($APL['a1']) && str_starts_with((string)$APL['a1'],'geraete.')),
            'bewaesserung' => fn()=> $domain==='bewaesserung' || $device==='bewaesserung' || (is_string($APL['a1']) && str_starts_with((string)$APL['a1'],'bewaesserung.')),
            'external'     => fn()=> isset($selected),
            'settings'     => fn()=> $domain==='einstellungen'
                || $device==='einstellungen'
                || in_array($action, ['einstellung','einstellungen','settings'], true),
        ];

        foreach ($rendererDomainMap as $routeKey => $entry) {
            if (isset($ROUTES[$routeKey])) {
                continue;
            }
            $routePrefix = $routeKey . '.';
            $roomDomainRaw = strtolower((string)($rendererRoomDomainKeys[$routeKey] ?? ($entry['roomDomain'] ?? '')));
            if ($roomDomainRaw === '') {
                $roomDomainRaw = $routeKey;
            }
            $roomDomainPrefix = $roomDomainRaw . '.';
            $domainAlias = $roomDomainRaw;
            if ($domainAlias === '' || in_array($domainAlias, ['devices', 'sprinkler'], true)) {
                $domainAlias = $routeKey;
            }
            $baseRendererPrefix = (strtolower((string)($entry['roomDomain'] ?? 'devices')) === 'sprinkler')
                ? 'bewaesserung.'
                : 'geraete.';
            $ROUTES[$routeKey] = fn() => $domain === $routeKey
                || ($roomDomainRaw !== '' && $domain === $roomDomainRaw)
                || $domain === $domainAlias
                || $device === $routeKey
                || ($roomDomainRaw !== '' && $device === $roomDomainRaw)
                || $device === $domainAlias
                || (is_string($APL['a1']) && str_starts_with((string)$APL['a1'], $routePrefix))
                || ($roomDomainRaw !== '' && is_string($APL['a1']) && str_starts_with((string)$APL['a1'], $roomDomainPrefix))
                || (is_string($APL['a1']) && str_starts_with((string)$APL['a1'], $baseRendererPrefix));
        }

        // Route bestimmen
        $__route = $__route ?? null;
        foreach ($ROUTES as $r=>$cond) {
            if ($cond()) { $__route = ($r==='main' || $r==='main_home') ? 'main_launch' : $r; break; }
        }

        // --------- Lazy rooms nur wenn benötigt ---------
        $needsRooms = in_array($__route ?? '', ['main_launch','heizung','jalousie','licht','lueftung','geraete','bewaesserung'], true);
        $rooms = [];
        if ($needsRooms) {
            if (isset($RBUILDER['build_rooms_status']) && is_callable($RBUILDER['build_rooms_status'])) {
                $rooms = $RBUILDER['build_rooms_status']($ROOMS);
            }
        }

        // --------- ROUTE_ALL Call ---------
        if ($__route !== null) {
            // APL override aus Tab-Fallback übernehmen (falls gesetzt)
            $aplArgs = is_array($APL['args']) ? array_values($APL['args']) : [];
            if (isset($GLOBALS['_APL_OVERRIDE'])) {
                $aplArgs[1] = $GLOBALS['_APL_OVERRIDE'][0] ?? ($aplArgs[1] ?? null);
                $aplArgs[2] = $GLOBALS['_APL_OVERRIDE'][1] ?? ($aplArgs[2] ?? null);
            }

            $payload = [
                'route'           => $__route,
                'S'               => [
                    'RENDER_MAIN'         => $S['RENDER_MAIN'],
                    'RENDER_HEIZUNG'      => $S['RENDER_HEIZUNG'],
                    'RENDER_JALOUSIE'     => $S['RENDER_JALOUSIE'],
                    'RENDER_LICHT'        => $S['RENDER_LICHT'],
                    'RENDER_LUEFTUNG'     => $S['RENDER_LUEFTUNG'],
                    'RENDER_GERAETE'      => $S['RENDER_GERAETE'],
                    'RENDER_BEWAESSERUNG' => $S['RENDER_BEWAESSERUNG'],
                    'RENDER_SETTINGS'     => $S['RENDER_SETTINGS'],
                ],
                'V'               => [
                    'AUSSEN_TEMP'   => $V['AUSSEN_TEMP'],
                    'INFORMATION'   => $V['INFORMATION'],
                    'MELDUNGEN'     => $V['MELDUNGEN'],
                    'DOMAIN_FLAG'   => $V['DOMAIN_FLAG'],
                    'SKILL_ACTIVE'  => $V['SKILL_ACTIVE'],
                    'Passwort'      => $V['Passwort'],
                    'StartPage'     => $V['StartPage'],
                    'WfcId'         => $V['WfcId'],
                    'EnergiePageId' => $V['EnergiePageId'],
                    'KameraPageId'  => $V['KameraPageId'],
                    'pageMappings'  => $pageMappings,
                    'InstanceID'    => $instanceId,
                    'externalKey'   => $selected ?? null,
                ],
                'rooms'           => $rooms,
                'ROOMS'           => $ROOMS,
                'ACTIONS_ENABLED' => $ACTIONS_ENABLED,
                'args1v'          => $APL['a1'],
                'args2v'          => $APL['a2'],
                'args3v'          => $APL['a3'],
                'aplSupported'    => (bool)($aplSupported ?? false),
                'action'          => (string)($action ?? ''),
                'device'          => (string)($device ?? ''),
                'room'            => (string)($room ?? ''),
                'object'          => (string)($object ?? ''),
                'alles'           => (string)($alles ?? ''),
                'number'          => $number,
                'prozent'         => $prozent,
                'power'           => $power,
                'aplArgs'         => $aplArgs,
                'skillActive'     => (bool)GetValueBoolean($V['SKILL_ACTIVE']),
                'alexaKey'        => (string)$alexaKey,
                'alexa'           => (string)$alexa,
                'baseUrl'         => (string)$baseUrl,
                'source'          => (string)$source,
                'token'           => (string)$token,
                'room_raw'        => (string)$room_raw,
                'szene'           => (string)$szene,
                'CFG'             => $CFG,
                'rendererDomains' => array_values($rendererDomainMap),
                'externalPages'   => $externalPages,
            ];

            $res = json_decode(IPS_RunScriptWaitEx($S['ROUTE_ALL'], ['payload'=>json_encode($payload, $JSON)]), true);

            if (!is_array($res) || empty($res['ok'])) {
                return TellResponse::CreatePlainText('Fehler beim Rendern.');
            }

            if (array_key_exists('setDomainFlag', (array)($res['flags'] ?? []))) {
                $val = $res['flags']['setDomainFlag'];
                if ($val === '') { SetValueString($V['DOMAIN_FLAG'], ""); }
                elseif (is_string($val)) { SetValueString($V['DOMAIN_FLAG'], $val); }
            }
            if (array_key_exists('setSkillActive', (array)($res['flags'] ?? []))) {
                $s = $res['flags']['setSkillActive'];
                if ($s === true)  { SetValueBoolean($V['SKILL_ACTIVE'], true); }
                if ($s === false) { SetValueBoolean($V['SKILL_ACTIVE'], false); }
            }

            $data       = (array)$res['data'];
            $tokenApl   = (string)($res['aplToken'] ?? 'hv-main');
            $endSession = !empty($data['endSession']);
            $speech     = (string)($data['speech'] ?? '');
            $reprompt   = (string)($data['reprompt'] ?? '');
            $r = $endSession
                ? TellResponse::CreatePlainText($speech)
                : AskResponse::CreatePlainText($speech)->SetRepromptPlainText($reprompt);
            $makeCard($r, $data['card'] ?? []);
            $applyApl($r, $data['apl'] ?? [], $tokenApl);

            if (!empty($data['directives']) && is_array($data['directives'])) {
                foreach ($data['directives'] as $d) {
                    $r->AddDirective($d);
                }
            }
            return $r;
        }

        // --------- Fallback ---------
        if (($APL['a2'] ?? null) != null) {
            return AskResponse::CreatePlainText($APL['a2'] . " ausgewählt")->SetRepromptPlainText('');
        }
        $resetState($V);
        return AskResponse::CreatePlainText('Konnte kein Gerät verstehen. Wie bitte?')->SetRepromptPlainText('Wie bitte?');

    } catch (Exception $e) {
        IPS_LogMessage('Alexa', "Fehler: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return TellResponse::CreatePlainText('Ein Fehler ist aufgetreten! Andreas sagen!');
    } finally {
        IPS_LogMessage('Alexa', "Ende");
    }
}
