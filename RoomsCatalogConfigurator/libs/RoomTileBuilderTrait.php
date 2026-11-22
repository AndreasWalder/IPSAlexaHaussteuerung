<?php
declare(strict_types=1);

/** Build read-only Room Tiles from the approved production RoomsCatalog. */
trait RCCRoomTileBuilderTrait
{
    private function roomTileIds(): array
    {
        return [
            'visualization' => 42 * 1000 + 569,
            'tileHome' => 52 * 1000 + 221,
            'multiRoomModule' => '{5D1D3B42-2B8E-4C5E-AE9A-4E8E2C00E9F4}',
            'roomModule' => '{0E33BB6A-B5A7-4D25-883A-EDF0551DB5C3}',
        ];
    }

    public function GenerateRoomTiles(): void
    {
        try {
            $result = $this->generateReadOnlyRoomTiles();
            $message = $result['rooms'] . ' Räume mit ' . $result['items']
                . ' Statuswerten aktualisiert. Übersicht und Detailseiten liegen unter '
                . 'Visualization (42569) → Tile Home (52221).';
            $this->UpdateFormField('RoomTileNotice', 'caption', $message);
            $this->logDebug('GenerateRoomTiles: ' . $message);
        } catch (Throwable $error) {
            $message = 'Raumkacheln nicht erstellt: ' . $error->getMessage();
            $this->UpdateFormField('RoomTileNotice', 'caption', $message);
            $this->logDebug('GenerateRoomTiles fehlgeschlagen: ' . $error->getMessage());
        }
    }

    private function generateReadOnlyRoomTiles(): array
    {
        $ids = $this->roomTileIds();
        if (!function_exists('IPS_ObjectExists') || !IPS_ObjectExists($ids['visualization'])
            || !IPS_ObjectExists($ids['tileHome'])) {
            throw new RuntimeException('Die freigegebene Tile-Visualisierung wurde nicht gefunden.');
        }
        $visualization = IPS_GetObject($ids['visualization']);
        $tileHome = IPS_GetObject($ids['tileHome']);
        if (($visualization['ObjectName'] ?? '') !== 'Visualization'
            || ($tileHome['ObjectName'] ?? '') !== 'Tile Home'
            || (int)($tileHome['ParentID'] ?? 0) !== $ids['visualization']) {
            throw new RuntimeException('Die Zielobjekte 42569 und 52221 passen nicht zur erwarteten Hierarchie.');
        }
        $moduleIds = IPS_GetModuleList();
        if (!in_array($ids['multiRoomModule'], $moduleIds, true)
            || !in_array($ids['roomModule'], $moduleIds, true)) {
            throw new RuntimeException('Das Community-Modul Room Tiles mit Multi Room Tile und Room Tile fehlt.');
        }

        $catalog = $this->loadConfiguredCatalog('RoomsCatalogScriptID', 'PROD');
        if ($catalog === []) {
            throw new RuntimeException('Bitte zuerst das produktive RoomsCatalog-Skript konfigurieren.');
        }
        $roomMap = $this->roomMap($catalog);
        $houseCategoryId = $this->roomTileChildCategory($ids['tileHome'], 'Haus');
        if ($houseCategoryId <= 0) {
            throw new RuntimeException('Unter Tile Home wurde keine Kategorie Haus gefunden.');
        }

        $prepared = [];
        $totalItems = 0;
        foreach ($roomMap as $roomKey => $room) {
            if ($roomKey === 'global' || !is_array($room) || !is_array($room['domains'] ?? null)) {
                continue;
            }
            $display = trim((string)($room['display'] ?? $roomKey));
            if ($display === '') {
                continue;
            }
            $items = $this->roomTileInfoItems($room['domains']);
            if ($items === []) {
                continue;
            }
            $prepared[] = ['key'=>(string)$roomKey, 'name'=>$display, 'items'=>$items];
            $totalItems += count($items);
        }
        if ($prepared === []) {
            throw new RuntimeException('Im produktiven RoomsCatalog gibt es keine eindeutig lesbaren Statusvariablen für Raumkacheln.');
        }

        // The only created or updated objects are the overview instance and
        // generated room pages below the user-approved Tile Home category.
        $detailRoot = $this->roomTileEnsureCategory($houseCategoryId,
            'IAHRoomDetailsGenerated', 'Raumdetails (automatisch)');
        $overviewRows = [];
        foreach ($prepared as $index => $room) {
            $roomIdent = 'IAHRoom_' . substr(sha1($room['key']), 0, 16);
            $roomCategory = $this->roomTileEnsureCategory($detailRoot, $roomIdent, $room['name']);
            $roomTile = $this->roomTileEnsureInstance($roomCategory, $ids['roomModule'],
                $roomIdent . '_Status', 'Status');
            $this->roomTileApplyProperties($roomTile, [
                'RoomName'=>$room['name'],
                'Target'=>$ids['tileHome'],
                'InfoItems'=>json_encode($room['items'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'MenuItems'=>'[]',
                'InfoTopCentered'=>true,
            ]);
            $overviewRows[] = [
                'RoomName'=>$room['name'],
                'Target'=>$roomCategory,
                'BackgroundImage'=>0,
                'InfoItems'=>json_encode($room['items'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'MenuItems'=>'[]',
                'InfoTopCentered'=>false,
                'ShowRoomName'=>true,
            ];
            IPS_SetPosition($roomCategory, $index);
        }

        $overview = $this->roomTileEnsureInstance($ids['tileHome'], $ids['multiRoomModule'],
            'IAHRoomStatusOverview', 'Raumstatus');
        $this->roomTileApplyProperties($overview, [
            'Rooms'=>json_encode($overviewRows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'Columns'=>0,
            'MinWidth'=>260,
            'MinHeight'=>210,
            'Gap'=>12,
            'BorderRadius'=>12,
            'UseFullTileHeight'=>true,
        ]);

        return ['rooms'=>count($prepared), 'items'=>$totalItems];
    }

    private function roomTileInfoItems(array $domains): array
    {
        $items = [];
        $roles = RCCDeviceTypeRegistry::roles();
        $fields = RCCDeviceTypeRegistry::fields();
        $types = RCCDeviceTypeRegistry::dataTypes();
        $captions = [
            'power'=>'Ein/Aus', 'brightness'=>'Helligkeit', 'position'=>'Position',
            'targetPosition'=>'Sollposition', 'tilt'=>'Lamellen', 'moving'=>'Fahrstatus',
            'temperature'=>'Isttemperatur', 'targetTemperature'=>'Solltemperatur',
            'open'=>'Fenster/Tür', 'powerWatts'=>'Leistung', 'energy'=>'Energie',
            'motion'=>'Bewegung', 'illuminance'=>'Lux', 'status'=>'Status',
            'humidity'=>'Feuchte', 'alarm'=>'Alarm', 'soilMoisture'=>'Bodenfeuchte',
            'batteryLevel'=>'Batterie', 'waterLeak'=>'Wasser', 'windSpeed'=>'Wind',
            'rain'=>'Regen', 'online'=>'Online', 'locked'=>'Schloss', 'armed'=>'Scharf',
            'feedIn'=>'Einspeisung', 'selfConsumption'=>'Eigenverbrauch',
            'chargePower'=>'Ladeleistung', 'dischargePower'=>'Entladeleistung',
            'volume'=>'Verbrauch', 'flowRate'=>'Durchfluss', 'charging'=>'Laden',
            'error'=>'Fehler',
        ];
        foreach ($domains as $domain => $groups) {
            if (!is_array($groups)) {
                continue;
            }
            if (in_array((string)$domain, ['heizung','jalousie'], true)) {
                $this->roomTileWalkEntries($groups, (string)$domain, (string)$domain,
                    $roles, $fields, $types, $captions, $items);
                continue;
            }
            foreach ($groups as $group => $entries) {
                if (is_array($entries)) {
                    $this->roomTileWalkEntries($entries, (string)$domain, (string)$group,
                        $roles, $fields, $types, $captions, $items);
                }
            }
        }
        return array_values($items);
    }

    private function roomTileWalkEntries(
        array $node,
        string $domain,
        string $group,
        array $roles,
        array $fields,
        array $types,
        array $captions,
        array &$items,
        int $depth = 0
    ): void {
        if ($depth > 8) {
            return;
        }
        $ai = $node['ai'] ?? null;
        $type = is_array($ai) ? (string)($ai['type'] ?? '') : '';
        $variableMap = is_array($ai) && is_array($ai['variables'] ?? null) ? $ai['variables'] : [];
        if ($type !== '' && isset($roles[$type])) {
            foreach ($roles[$type] as $role) {
                if (in_array($role, ['control', 'action'], true) || !isset($fields[$role])) {
                    continue;
                }
                $id = (int)($variableMap[$role] ?? 0);
                $this->roomTileAddInfoItem($items, $id, $role, $types, $captions);
            }
        }
        foreach ($node as $key => $value) {
            if (!is_array($value) || $key === 'ai') {
                continue;
            }
            $this->roomTileWalkEntries($value, $domain, $group, $roles, $fields, $types, $captions, $items, $depth + 1);
        }

        // Older RoomsCatalog entries use the original heating and blind fields.
        // They remain display-only in InfoItems; no menu or action is generated.
        $legacy = match ($domain) {
            'heizung'=>['temperature'=>['ist'], 'targetTemperature'=>['soll','eingestellt']],
            'jalousie'=>['position'=>['statusId','wert'], 'tilt'=>['tiltId']],
            'licht'=>$group === 'dimmers'
                ? ['brightness'=>['value','statusId'], 'power'=>['state']]
                : ['power'=>['state','statusId']],
            'geraete'=>['status'=>['statusId','stateId']],
            'sensoren'=>['status'=>['statusId','stateId']],
            default=>[],
        };
        foreach ($legacy as $role => $keys) {
            foreach ($keys as $key) {
                $id = (int)($node[$key] ?? 0);
                $this->roomTileAddInfoItem($items, $id, $role, $types, $captions);
            }
        }
    }

    private function roomTileAddInfoItem(array &$items, int $id, string $role, array $types, array $captions): void
    {
        if ($id <= 0 || !isset($types[$role]) || !IPS_VariableExists($id)) {
            return;
        }
        try {
            $variable = IPS_GetVariable($id);
            if (!in_array((int)($variable['VariableType'] ?? -1), $types[$role], true)) {
                return;
            }
            $object = IPS_GetObject($id);
            $name = trim((string)($object['ObjectName'] ?? IPS_GetName($id)));
        } catch (Throwable $error) {
            return;
        }
        $key = $role . ':' . $id;
        if (isset($items[$key])) {
            return;
        }
        $items[$key] = [
            'Id'=>'iah' . substr(sha1($key), 0, 12),
            'Area'=>(count($items) % 2 === 0 ? 'left' : 'right'),
            'VariableId'=>$id,
            'ShowName'=>true,
            'ShowIcon'=>true,
            'ShowValue'=>true,
            'UseVarColor'=>(int)($variable['VariableType'] ?? -1) === 0,
            'ColorTrue'=>-1,
            'ColorFalse'=>-1,
            'AltName'=>mb_substr($name !== '' ? $name : (string)($captions[$role] ?? $role), 0, 42),
        ];
    }

    private function roomTileChildCategory(int $parentId, string $name): int
    {
        foreach (IPS_GetChildrenIDs($parentId) as $childId) {
            $object = IPS_GetObject($childId);
            if ((int)($object['ObjectType'] ?? -1) === 0 && (string)($object['ObjectName'] ?? '') === $name) {
                return (int)$childId;
            }
        }
        return 0;
    }

    private function roomTileEnsureCategory(int $parentId, string $ident, string $name): int
    {
        foreach (IPS_GetChildrenIDs($parentId) as $childId) {
            $object = IPS_GetObject($childId);
            if ((string)($object['ObjectIdent'] ?? '') === $ident) {
                if ((int)($object['ObjectType'] ?? -1) !== 0) {
                    throw new RuntimeException('Ein generiertes Kategorie-Kennzeichen ist bereits anderweitig belegt.');
                }
                if ((string)($object['ObjectName'] ?? '') !== $name) {
                    IPS_SetName($childId, $name);
                }
                return (int)$childId;
            }
        }
        $uniqueName = $name;
        $suffix = 2;
        while ($this->roomTileChildNameExists($parentId, $uniqueName)) {
            $uniqueName = $name . ' (' . $suffix++ . ')';
        }
        $id = IPS_CreateCategory();
        IPS_SetParent($id, $parentId);
        IPS_SetName($id, $uniqueName);
        IPS_SetIdent($id, $ident);
        return $id;
    }

    private function roomTileChildNameExists(int $parentId, string $name): bool
    {
        foreach (IPS_GetChildrenIDs($parentId) as $childId) {
            if ((string)(IPS_GetObject($childId)['ObjectName'] ?? '') === $name) {
                return true;
            }
        }
        return false;
    }

    private function roomTileEnsureInstance(int $parentId, string $moduleId, string $ident, string $name): int
    {
        foreach (IPS_GetChildrenIDs($parentId) as $childId) {
            $object = IPS_GetObject($childId);
            if ((string)($object['ObjectIdent'] ?? '') !== $ident) {
                continue;
            }
            if ((int)($object['ObjectType'] ?? -1) !== 1
                || (string)(IPS_GetInstance($childId)['ModuleInfo']['ModuleID'] ?? '') !== $moduleId) {
                throw new RuntimeException('Ein generiertes Tile-Kennzeichen ist bereits anderweitig belegt.');
            }
            if ((string)($object['ObjectName'] ?? '') !== $name) {
                IPS_SetName($childId, $name);
            }
            return (int)$childId;
        }
        $id = IPS_CreateInstance($moduleId);
        IPS_SetParent($id, $parentId);
        IPS_SetName($id, $name);
        IPS_SetIdent($id, $ident);
        return $id;
    }

    private function roomTileApplyProperties(int $instanceId, array $properties): void
    {
        foreach ($properties as $key => $value) {
            if (!IPS_SetProperty($instanceId, (string)$key, $value)) {
                throw new RuntimeException('Tile-Eigenschaft ' . $key . ' konnte nicht gesetzt werden.');
            }
        }
        if (!IPS_ApplyChanges($instanceId)) {
            throw new RuntimeException('Die Tile-Konfiguration konnte nicht übernommen werden.');
        }
    }
}

