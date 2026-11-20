<?php
declare(strict_types=1);

/** Read-only match between an ETS group-address export and configured KNX variables. */
final class RCCKnxGroupAddressDiscovery
{
    private const MAX_EXPORT_BYTES = 10 * 1024 * 1024;
    private const MAX_ROWS = 5000;

    public static function scan(string $path): array
    {
        $path = trim($path);
        if ($path === '' || !is_file($path) || !is_readable($path))
            throw new RuntimeException('Bitte einen lesbaren ETS-Gruppenadress-Export auswaehlen.');
        $size = @filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_EXPORT_BYTES)
            throw new RuntimeException('Der XML-Export ist leer oder groesser als 10 MB.');
        if (!class_exists(XMLReader::class))
            throw new RuntimeException('Die PHP-XMLReader-Erweiterung ist nicht verfuegbar.');

        $reader = new XMLReader();
        if (!$reader->open($path, null, LIBXML_NONET | LIBXML_NOBLANKS))
            throw new RuntimeException('Der ETS-Export konnte nicht gelesen werden.');
        $addresses = [];
        $rootSeen = false;
        $validRoot = false;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) continue;
                if (!$rootSeen) {
                    $rootSeen = true;
                    $validRoot = $reader->localName === 'GroupAddress-Export'
                        && str_contains($reader->namespaceURI, 'knx.org/xml/ga-export/');
                    if (!$validRoot) throw new RuntimeException('Die Datei ist kein KNX-Gruppenadress-Export.');
                    continue;
                }
                if ($reader->localName !== 'GroupAddress') continue;
                $address = trim((string)$reader->getAttribute('Address'));
                $name = trim((string)$reader->getAttribute('Name'));
                $dpt = trim((string)$reader->getAttribute('DPTs'));
                if ($name === '' || preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{1,3})$/D', $address, $m) !== 1)
                    continue;
                if ((int)$m[1] > 31 || (int)$m[2] > 7 || (int)$m[3] > 255) continue;
                $addresses[$address] = ['name'=>mb_substr($name,0,120), 'dpt'=>mb_substr($dpt,0,40)];
            }
        } finally {
            $reader->close();
        }
        if (!$validRoot || $addresses === [])
            throw new RuntimeException('Im Export wurden keine gueltigen Gruppenadressen gefunden.');

        $rows = [];
        $instances = IPS_GetInstanceList();
        foreach ($instances as $instanceId) {
            $instanceId = (int)$instanceId;
            if ($instanceId <= 0) continue;
            try {
                $instance = IPS_GetInstance($instanceId);
                $moduleName = (string)($instance['ModuleInfo']['ModuleName'] ?? '');
                if (!preg_match('/^KNX (?:DPT(?: \d+)?|EIS Group|Shutter)$/i', $moduleName)) continue;
                $configuration = json_decode((string)IPS_GetConfiguration($instanceId), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($configuration)) continue;
                $children = [];
                foreach ((array)IPS_GetChildrenIDs($instanceId) as $childId) {
                    $childId = (int)$childId;
                    if ($childId <= 0 || !IPS_VariableExists($childId)) continue;
                    $object = IPS_GetObject($childId);
                    if ((int)($object['ObjectType'] ?? -1) !== 2) continue;
                    $children[$childId] = (string)($object['ObjectName'] ?? '');
                }
                if ($children === []) continue;
                foreach (array_keys($configuration) as $property) {
                    if (!is_string($property) || preg_match('/^(.*Address)1$/', $property, $m) !== 1) continue;
                    $prefix = $m[1];
                    $parts = [];
                    foreach ([1,2,3] as $part) {
                        $value = $configuration[$prefix.$part] ?? null;
                        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                            $parts = [];
                            break;
                        }
                        $parts[] = (int)$value;
                    }
                    if (count($parts) !== 3 || $parts === [0,0,0]) continue;
                    $address = implode('/', $parts);
                    if (!isset($addresses[$address])) continue;
                    $targets = self::variablesForFunction($children, $prefix);
                    foreach ($targets as $variableId=>$variableName) {
                        if (count($rows) >= self::MAX_ROWS)
                            throw new RuntimeException('Der Abgleich ueberschreitet 5.000 Treffer; bitte den ETS-Export aufteilen.');
                        $rows[] = [
                            'key'=>$variableId.'|'.$address.'|'.$prefix,
                            'selected'=>false,
                            'variableId'=>$variableId,
                            'variableName'=>mb_substr($variableName,0,100),
                            'instanceId'=>$instanceId,
                            'instanceName'=>mb_substr((string)IPS_GetName($instanceId),0,100),
                            'moduleName'=>mb_substr($moduleName,0,60),
                            'function'=>mb_substr(rtrim(substr($prefix,0,-7),'_'),0,40),
                            'address'=>$address,
                            'groupName'=>$addresses[$address]['name'],
                            'dpt'=>$addresses[$address]['dpt'],
                        ];
                    }
                }
            } catch (Throwable $error) {
                if ($error instanceof RuntimeException && str_contains($error->getMessage(),'5.000 Treffer')) throw $error;
                continue;
            }
        }
        return ['addressCount'=>count($addresses),'rows'=>$rows];
    }

    /** Avoid suggesting the same shutter GA for every variable of its instance. */
    private static function variablesForFunction(array $children, string $prefix): array
    {
        if (count($children) <= 1) return $children;
        $function = strtolower(rtrim(substr($prefix, 0, -7), '_'));
        $needles = match (true) {
            str_contains($function, 'blade') || str_contains($function, 'tilt') => ['lamelle','til','blade'],
            str_contains($function, 'position') => ['position','pos','stellung'],
            str_contains($function, 'move') => ['move','fahrt','fahren','steuer'],
            str_contains($function, 'stop') => ['stop','halt'],
            str_contains($function, 'up') => ['auf','hoch','up'],
            str_contains($function, 'down') => ['ab','runter','down'],
            default => [],
        };
        if ($needles === []) return [];
        foreach ($children as $id=>$name) {
            $normalized = mb_strtolower($name);
            foreach ($needles as $needle) {
                if (str_contains($normalized, $needle)) return [$id=>$name];
            }
        }
        return [];
    }
}
