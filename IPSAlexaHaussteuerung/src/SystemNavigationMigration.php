<?php
declare(strict_types=1);

require_once __DIR__ . '/SystemConfigurationManager.php';
require_once __DIR__ . '/SystemNavigationEditor.php';

/**
 * V3.3.5 preflight and reversible migration. No write operations here.
 * Only the main module is allowed to publish/clean after confirmed preview.
 */
final class IAHSystemNavigationMigration
{
    public static function project(array $catalog, array $navigation): array
    {
        $runtime = require __DIR__ . '/../resources/helpers/NavigationRuntime.php';
        return $runtime['project']($catalog, $navigation);
    }

    /**
     * Validate imported source references against the current RoomsCatalog.
     * Do not silently migrate newly created real devices in a "tabs" group.
     *
     * @return array{errors:array,warnings:array,legacy:int,new:int,enabled:int,total:int}
     */
    public static function audit(array $catalog, array $navigation, ?callable $objectExists = null): array
    {
        $errors = [];
        $warnings = [];
        $items = $navigation['items'] ?? null;
        if (($navigation['schemaVersion'] ?? 0) !== 1 || !is_array($items) || $items === []) {
            return ['errors' => ['Navigation-Entwurf fehlt oder Datenmodell ungueltig.'],
                'warnings' => [], 'legacy' => 0, 'new' => 0, 'enabled' => 0, 'total' => 0];
        }
        $scan = IAHSystemConfigurationManager::inventoryNavigation($catalog);
        foreach ($scan['warnings'] as $warning) $errors[] = 'Quelle: ' . $warning;
        $live = [];
        foreach ($scan['entries'] as $entry) {
            $path = (string) ($entry['legacyPath'] ?? '');
            if (isset($live[$path])) {
                $errors[] = 'Doppelte Quelle: ' . $path;
            }
            $live[$path] = $entry['legacyDefinition'];
        }

        $seenLegacy = [];
        $seenDestination = [];
        $legacy = 0;
        $new = 0;
        $active = 0;
        $rooms = isset($catalog['rooms']) && is_array($catalog['rooms'])
            ? $catalog['rooms'] : $catalog;
        foreach ($items as $entryKey => $entry) {
            if (!is_array($entry)) {
                $errors[] = 'Navigation: ungueltiger Eintrag.';
                continue;
            }
            $room = (string) ($entry['roomKey'] ?? '');
            $domain = (string) ($entry['domainKey'] ?? '');
            $title = trim((string) ($entry['title'] ?? ''));
            $source = (string) ($entry['legacyPath'] ?? '');
            $id = (int) ($entry['objectId'] ?? 0);
            if ($source !== '') {
                $legacy++;
                if (isset($seenLegacy[$source])) {
                    $errors[] = 'Quellpfad doppelt: ' . $source;
                }
                $seenLegacy[$source] = true;
                if (!array_key_exists($source, $live)) {
                    $errors[] = 'Quelle fehlt oder wurde verschoben: ' . $source;
                } elseif ($live[$source] !== ($entry['legacyDefinition'] ?? null)) {
                    $errors[] = 'Quelle wurde seit dem Import geaendert: ' . $source;
                }
            } else {
                $new++;
            }
            if (!empty($entry['enabled'])) {
                $active++;
                if ($room === '' || !isset($rooms[$room]) || !is_array($rooms[$room])) {
                    $errors[] = 'Raum nicht vorhanden bei Navigation ' . $entryKey;
                }
                if ($domain === '' || $title === '') {
                    $errors[] = 'Bereich oder Titel fehlt bei Navigation ' . $entryKey;
                }
                if ($id <= 0) {
                    $errors[] = 'IPS-ID fehlt bei Navigation ' . $entryKey;
                } elseif ($objectExists !== null && !$objectExists($id)) {
                    $errors[] = 'IPS-Objekt nicht vorhanden: #' . $id;
                }
                $destination = mb_strtolower($room . '/' . $domain . '/' . $title, 'UTF-8');
                if (isset($seenDestination[$destination])) {
                    $errors[] = 'Doppelter Navigationstitel im gleichen Raum/Bereich: ' . $room . '/' . $domain . '/' . $title;
                }
                $seenDestination[$destination] = true;
            }
        }
        foreach (array_keys($live) as $path) {
            if (!isset($seenLegacy[$path])) {
                $errors[] = 'Nicht importierter neuer Tabs-Eintrag in Quelle: ' . $path;
            }
        }
        if ($legacy === 0) $errors[] = 'Keine bestaetigte Alt-Navigation im Entwurf.';
        return [
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'legacy' => $legacy, 'new' => $new, 'enabled' => $active, 'total' => count($items),
        ];
    }

    public static function prepareProductive(array $prod, array $edit, array $catalog): array
    {
        if (!is_array($edit['navigation'] ?? null)) {
            throw new RuntimeException('EDIT enthaelt keine Navigation.');
        }
        if (isset($prod['navigation']) && ($prod['navigation']['mode'] ?? '') === 'active') {
            throw new RuntimeException('Produktive Navigation ist bereits aktiv. Kein automatisches Ueberschreiben.');
        }
        if (array_key_exists('navigation', $prod)) {
            throw new RuntimeException('PROD hat bereits ein eigenes Navigation-Datenmodell.');
        }
        $nav = $edit['navigation'];
        $nav['mode'] = 'active';
        $nav['activatedAt'] = gmdate('c');
        $audit = self::audit($catalog, $nav);
        if ($audit['errors'] !== []) {
            throw new RuntimeException('Navigation ist nicht vollstaendig: ' . implode('; ',array_slice($audit['errors'],0,3)));
        }
        // Copy only the navigation area from EDIT: do not replace PROD var,
        // script, rendererDomains, launchCatalog or other live settings.
        $prod['navigation'] = $nav;
        return $prod;
    }

    public static function stripLegacyTabs(array $catalog, array $navigation): array
    {
        $audit = self::audit($catalog, $navigation);
        if ($audit['errors'] !== []) {
            throw new RuntimeException('Quellabgleich fehlgeschlagen: ' . implode('; ',array_slice($audit['errors'],0,3)));
        }
        $wrapped = isset($catalog['rooms']) && is_array($catalog['rooms']);
        $rooms = $wrapped ? $catalog['rooms'] : $catalog;
        foreach ($navigation['items'] as $item) {
            $source = (string) ($item['legacyPath'] ?? '');
            if ($source === '') continue;
            [$room, $rest] = explode('/domains/', $source, 2);
            [$domain, $tab] = explode('/tabs/', $rest, 2);
            unset($rooms[$room]['domains'][$domain]['tabs'][$tab]);
            if ($rooms[$room]['domains'][$domain]['tabs'] === []) {
                unset($rooms[$room]['domains'][$domain]['tabs']);
            }
        }
        if ($wrapped) {
            $catalog['rooms'] = $rooms;
            return $catalog;
        }
        return $rooms;
    }

    /** After cleanup, the projected runtime catalog must remain identical. */
    public static function assertProjectionParity(array $before, array $after, array $navigation): void
    {
        $left = self::project($before, $navigation);
        $right = self::project($after, $navigation);
        if ($left !== $right) {
            throw new RuntimeException('Laufzeitprojektion nach Bereinigung weicht von vorher ab.');
        }
    }

    public static function hasLegacyTabs(array $catalog, array $navigation): bool
    {
        $scan = IAHSystemConfigurationManager::inventoryNavigation($catalog);
        $known = [];
        foreach ((array) ($navigation['items'] ?? []) as $item) {
            if (is_array($item) && ($item['legacyPath'] ?? '') !== '') {
                $known[(string)$item['legacyPath']] = true;
            }
        }
        foreach ($scan['entries'] as $entry) {
            if (isset($known[(string)$entry['legacyPath']])) return true;
        }
        return false;
    }
}
