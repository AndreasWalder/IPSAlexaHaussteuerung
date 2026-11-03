<?php
declare(strict_types=1);

/**
 * V3.3.4: Editor fuer SystemConfigurationEdit. Keine produktiven Schreibzugriffe,
 * keine Tab-Renderer-Umschaltung. Unbekannte Daten bleiben erhalten.
 */
final class IAHSystemNavigationEditor
{
    public const SCHEMA = 1;

    public static function importDraft(array $configuration, array $roomsCatalog, string $catalogHash): array
    {
        if (array_key_exists('navigation', $configuration)) {
            throw new RuntimeException('navigation ist bereits vorhanden. Kein automatisches Ueberschreiben.');
        }
        $inventory = IAHSystemConfigurationManager::navigationDraft($roomsCatalog);
        if ($inventory['warnings'] !== []) {
            throw new RuntimeException('Bestandsaufnahme enthaelt offene Hinweise. Zuerst pruefen.');
        }
        $items = [];
        foreach ($inventory['tabs'] as $i => $entry) {
            $path = (string) $entry['legacyPath'];
            $key = 'legacy_' . substr(hash('sha256', $path), 0, 20);
            if (isset($items[$key])) {
                throw new RuntimeException('Doppelte Navigationskennung entdeckt.');
            }
            $order = $entry['order'];
            $items[$key] = [
                'entryKey' => $key,
                'roomKey' => (string) $entry['roomKey'],
                'domainKey' => (string) $entry['domainKey'],
                'tabKey' => (string) $entry['tabKey'],
                'title' => (string) $entry['title'],
                'objectId' => (int) $entry['objectId'],
                'sortOrder' => is_numeric($order) ? (int) $order : ($i + 1) * 10,
                'aliases' => $entry['aliases'],
                'enabled' => true,
                'review' => (string) $entry['review'],
                'legacyPath' => $path,
                'legacyDefinition' => $entry['legacyDefinition'],
            ];
        }
        $configuration['navigation'] = [
            'schemaVersion' => self::SCHEMA,
            'mode' => 'draft_only',
            'source' => 'RoomsCatalog',
            'sourceHash' => $catalogHash,
            'importedAt' => gmdate('c'),
            'items' => $items,
        ];
        return $configuration;
    }

    public static function items(array $configuration): array
    {
        $navigation = $configuration['navigation'] ?? null;
        if (!is_array($navigation)
            || ($navigation['schemaVersion'] ?? null) !== self::SCHEMA
            || !is_array($navigation['items'] ?? null)) {
            return [];
        }
        return $navigation['items'];
    }

    /** List row values: strictly metadata, never configuration values or secrets. */
    public static function rows(array $configuration): array
    {
        $rows = [];
        foreach (self::items($configuration) as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $aliases = (array) ($entry['aliases'] ?? []);
            $rows[] = [
                'entryKey' => (string) $key,
                'roomKey' => (string) ($entry['roomKey'] ?? ''),
                'domainKey' => (string) ($entry['domainKey'] ?? ''),
                'tabKey' => (string) ($entry['tabKey'] ?? ''),
                'title' => (string) ($entry['title'] ?? ''),
                'objectId' => (int) ($entry['objectId'] ?? 0),
                'sortOrder' => (int) ($entry['sortOrder'] ?? 0),
                'aliases' => implode(', ', array_map('strval', $aliases)),
                'enabled' => !empty($entry['enabled']),
                'review' => (string) ($entry['review'] ?? 'Offen'),
                'legacyPath' => (string) ($entry['legacyPath'] ?? ''),
                'revision' => self::entryHash($entry),
            ];
        }
        usort($rows, static fn(array $a, array $b): int =>
            strcmp($a['roomKey'] . $a['domainKey'], $b['roomKey'] . $b['domainKey'])
            ?: ($a['sortOrder'] <=> $b['sortOrder']));
        return $rows;
    }

    public static function update(array $configuration, array $input): array
    {
        $items = self::items($configuration);
        $key = (string) ($input['entryKey'] ?? '');
        if ($key === '' || !isset($items[$key]) || !is_array($items[$key])) {
            throw new RuntimeException('Navigationseintrag nicht gefunden.');
        }
        $existing = $items[$key];
        if (!hash_equals(self::entryHash($existing), (string) ($input['revision'] ?? ''))) {
            throw new RuntimeException('Eintrag wurde zwischenzeitlich geaendert. Bitte neu laden.');
        }
        $title = trim((string) ($input['title'] ?? ''));
        $room = self::identifier((string) ($input['roomKey'] ?? ''), 'Raum');
        $domain = self::identifier((string) ($input['domainKey'] ?? ''), 'Bereich');
        $id = self::positiveOrZero($input['objectId'] ?? null);
        $order = self::sortOrder($input['sortOrder'] ?? null);
        $aliases = self::aliases($input['aliases'] ?? '');
        if ($title === '' || mb_strlen($title, 'UTF-8') > 120) {
            throw new RuntimeException('Navigations-Titel fehlt oder ist zu lang.');
        }
        $items[$key]['roomKey'] = $room;
        $items[$key]['domainKey'] = $domain;
        $items[$key]['title'] = $title;
        $items[$key]['objectId'] = $id;
        $items[$key]['sortOrder'] = $order;
        $items[$key]['aliases'] = $aliases;
        $items[$key]['enabled'] = self::boolean($input['enabled'] ?? false);
        // Review bleibt offen, bis spaeter Quelle, Route und Aktion geprueft sind.
        if ($items[$key] !== $existing) {
            $items[$key]['review'] = $id > 0 ? 'Erneut pruefen' : 'ID pruefen';
        }
        self::ensureUniqueRoutes($items);
        $configuration['navigation']['items'] = $items;
        return $configuration;
    }

    public static function create(array $configuration, array $input): array
    {
        if (!isset($configuration['navigation'])
            || !is_array($configuration['navigation'])
            || (int) ($configuration['navigation']['schemaVersion'] ?? 0) !== self::SCHEMA) {
            throw new RuntimeException('Zuerst Navigation nach EDIT importieren.');
        }
        $items = self::items($configuration);
        $room = self::identifier((string) ($input['roomKey'] ?? ''), 'Raum');
        $domain = self::identifier((string) ($input['domainKey'] ?? ''), 'Bereich');
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 120) {
            throw new RuntimeException('Navigations-Titel fehlt oder ist zu lang.');
        }
        $slug = strtolower(strtr($title, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', $slug), '_');
        if ($slug === '') {
            throw new RuntimeException('Aus Titel kann kein Key gebildet werden.');
        }
        $base = 'new_' . substr(hash('sha256', $room . '/' . $domain . '/' . $slug), 0, 16);
        $key = $base;
        for ($i = 2; isset($items[$key]); ++$i) {
            $key = $base . '_' . $i;
        }
        $entry = [
            'entryKey' => $key,
            'roomKey' => $room,
            'domainKey' => $domain,
            'tabKey' => $slug,
            'title' => $title,
            'objectId' => self::positiveOrZero($input['objectId'] ?? null),
            'sortOrder' => self::sortOrder($input['sortOrder'] ?? 100),
            'aliases' => self::aliases($input['aliases'] ?? ''),
            'enabled' => self::boolean($input['enabled'] ?? true),
            'review' => 'Neu – pruefen',
            'legacyPath' => '',
            'legacyDefinition' => null,
        ];
        $items[$key] = $entry;
        self::ensureUniqueRoutes($items);
        $configuration['navigation']['items'] = $items;
        return $configuration;
    }

    public static function entryHash(array $entry): string
    {
        return hash('sha256', (string) json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Kein eigener Parser: nur literal PHP-Arrays duerfen umgeschrieben werden. */
    public static function isLiteralArraySource(string $source): bool
    {
        $tokens = token_get_all($source);
        $foundReturn = false;
        foreach ($tokens as $token) {
            if (is_string($token)) {
                if (!str_contains("[](),;=+->", $token)) {
                    return false;
                }
                continue;
            }
            [$type, $value] = $token;
            if ($type === T_RETURN) {
                if ($foundReturn) return false;
                $foundReturn = true;
                continue;
            }
            if (in_array($type, [
                T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT,
                T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER,
                T_DOUBLE_ARROW, T_ARRAY
            ], true)) {
                continue;
            }
            if ($type === T_STRING && in_array(strtolower($value), ['true', 'false', 'null', 'array'], true)) {
                continue;
            }
            return false;
        }
        return $foundReturn;
    }

    public static function encode(array $configuration): string
    {
        return "<?php\nreturn " . var_export($configuration, true) . ";\n";
    }

    private static function identifier(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 100 || !preg_match('/^[\p{L}\p{N}_\-]+$/uD', $value)) {
            throw new RuntimeException($label . ': nur Buchstaben, Zahlen, _ und - zulassen.');
        }
        return $value;
    }

    private static function positiveOrZero(mixed $value): int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || $raw === '0') return 0;
        if (!ctype_digit($raw) || strlen($raw) > 10 || (int) $raw <= 0) {
            throw new RuntimeException('Ungueltige IP-Symcon-Objekt-ID.');
        }
        return (int) $raw;
    }

    private static function sortOrder(mixed $value): int
    {
        $raw = trim((string) ($value ?? ''));
        if (!preg_match('/^-?[0-9]{1,6}$/D', $raw)) {
            throw new RuntimeException('Sortierung muss eine ganze Zahl sein.');
        }
        return (int) $raw;
    }

    private static function aliases(mixed $value): array
    {
        if (is_array($value)) {
            $value = implode(',', array_map('strval', $value));
        }
        $value = (string) $value;
        if (strlen($value) > 1000) {
            throw new RuntimeException('Zu viele Synonyme.');
        }
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn(string $s): bool => $s !== '')));
    }

    private static function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    private static function ensureUniqueRoutes(array $items): void
    {
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $path = strtolower((string) ($item['roomKey'] ?? '') . '/'
                . (string) ($item['domainKey'] ?? '') . '/'
                . (string) ($item['tabKey'] ?? ''));
            if (isset($seen[$path])) {
                throw new RuntimeException('Doppelte Route: ' . $path);
            }
            $seen[$path] = true;
        }
    }
}
