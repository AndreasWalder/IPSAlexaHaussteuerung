<?php
declare(strict_types=1);

/**
 * V3.3.3 – inventarisiert vorhandene Navigations-Tabs verlustfrei.
 * Keine Schreibzugriffe auf RoomsCatalog oder SystemConfiguration (PROD).
 */
final class IAHSystemConfigurationManager
{
    /** @return array{entries:array<int,array<string,mixed>>,warnings:array<int,string>} */
    public static function inventoryNavigation(array $catalog): array
    {
        $entries = [];
        $warnings = [];
        $rooms = isset($catalog['rooms']) && is_array($catalog['rooms'])
            ? $catalog['rooms'] : $catalog;

        foreach ($rooms as $roomKey => $room) {
            if (!is_array($room)) {
                continue;
            }
            $domains = $room['domains'] ?? [];
            if (!is_array($domains)) {
                $warnings[] = (string) $roomKey . ': domains ist keine Liste';
                continue;
            }
            foreach ($domains as $domainKey => $domain) {
                if (!is_array($domain) || !array_key_exists('tabs', $domain)) {
                    continue;
                }
                if (!is_array($domain['tabs'])) {
                    $warnings[] = $roomKey . '/' . $domainKey . '/tabs: ungültiger Datentyp';
                    continue;
                }

                foreach ($domain['tabs'] as $tabKey => $definition) {
                    $path = (string) $roomKey . '/domains/' . (string) $domainKey . '/tabs/' . (string) $tabKey;
                    $id = 0;
                    $title = (string) $tabKey;
                    $aliases = [];
                    $order = null;
                    if (is_int($definition) || (is_string($definition) && ctype_digit($definition))) {
                        $id = (int) $definition;
                    } elseif (is_array($definition)) {
                        $title = trim((string) ($definition['title'] ?? $tabKey));
                        foreach (['id', 'nodeId', 'var', 'objectId', 'instanceId'] as $key) {
                            if (isset($definition[$key]) && is_numeric($definition[$key])) {
                                $id = (int) $definition[$key];
                                break;
                            }
                        }
                        $aliases = $definition['synonyms'] ?? $definition['aliases'] ?? [];
                        $order = $definition['order'] ?? null;
                    } else {
                        $warnings[] = $path . ': unbekanntes Tab-Format';
                    }
                    if (!is_array($aliases)) {
                        $aliases = array_filter(array_map('trim', explode(',', (string) $aliases)));
                    }
                    if ($id <= 0) {
                        $warnings[] = $path . ': keine eindeutige Objekt-ID';
                    }
                    $entries[] = [
                        'roomKey' => (string) $roomKey,
                        'domainKey' => (string) $domainKey,
                        'tabKey' => (string) $tabKey,
                        'title' => $title !== '' ? $title : (string) $tabKey,
                        'objectId' => $id,
                        'order' => $order,
                        'aliases' => array_values(array_map('strval', $aliases)),
                        'legacyPath' => $path,
                        // Rohdefinition für spätere, bestätigte Migration erhalten.
                        'legacyDefinition' => $definition,
                        'review' => $id > 0 ? 'Offen' : 'ID prüfen',
                    ];
                }
            }
        }

        return ['entries' => $entries, 'warnings' => $warnings];
    }

    /**
     * Nur Datenmodell-Vorschau für V3.3.4 – niemals automatisch in PROD übernehmen.
     * Jeder Quellpfad und jede Rohdefinition bleiben erhalten.
     *
     * @return array<string,mixed>
     */
    public static function navigationDraft(array $catalog): array
    {
        $scan = self::inventoryNavigation($catalog);
        return [
            'schemaVersion' => 1,
            'source' => 'RoomsCatalog',
            'importStatus' => 'review_required',
            'tabs' => $scan['entries'],
            'warnings' => $scan['warnings'],
        ];
    }

    /** Vertrauliche Werte bleiben im Skript – niemals in Formular oder Diagnose. */
    public static function configurationStructure(array $config): array
    {
        $sections = [];
        foreach ($config as $key => $value) {
            $sections[] = [
                'name' => (string) $key,
                'entries' => is_array($value) ? count($value) : 1,
            ];
        }
        return $sections;
    }
}
