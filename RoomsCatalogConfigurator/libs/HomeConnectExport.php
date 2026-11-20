<?php
declare(strict_types=1);

/** Read-only inventory of the installed official Home Connect device instances. */
final class RCCHomeConnectExport
{
    public const MODULE_ID = '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}';

    public static function scan(int $rootId): array
    {
        if ($rootId <= 0 || !IPS_ObjectExists($rootId)
            || !in_array((int)IPS_GetObject($rootId)['ObjectType'], [0, 1], true)) {
            throw new RuntimeException('Bitte die Home-Connect-Hauptkategorie oder eine Geräteinstanz auswählen.');
        }
        $result = ['schemaVersion' => 1, 'provider' => 'HomeConnect', 'domain' => 'geraete',
            'exportedAt' => date('c'), 'root' => self::object($rootId),
            'complete' => true, 'notes' => [], 'devices' => []];
        $seen = [];
        $remaining = 4000;
        $queue = [[$rootId, 0]];
        while ($queue !== []) {
            [$id, $depth] = array_pop($queue);
            if (isset($seen[$id])) continue;
            if ($remaining <= 0 || count($seen) >= 2000 || $depth > 24) {
                self::incomplete($result, 'Scan-Limit erreicht; Teilinventur. Bitte einen kleineren Zweig wählen.');
                continue;
            }
            $seen[$id] = true;
            $remaining--;
            try {
                if (!IPS_ObjectExists($id)) continue;
                $object = IPS_GetObject($id);
                $type = (int)$object['ObjectType'];
                if ($type === 6) {
                    $target = (int)IPS_GetLink($id)['TargetID'];
                    if (self::isDevice($target)) $queue[] = [$target, $depth + 1];
                    continue;
                }
                if (self::isDevice($id)) {
                    $result['devices'][] = self::device($id, $result, $remaining);
                    continue;
                }
                if ($type === 0 || $type === 1) {
                    foreach (IPS_GetChildrenIDs($id) as $child) $queue[] = [(int)$child, $depth + 1];
                }
            } catch (Throwable $e) {
                self::incomplete($result, 'Objekt #' . $id . ' konnte nicht vollständig gelesen werden.');
            }
        }
        usort($result['devices'], static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        $result['deviceCount'] = count($result['devices']);
        $result['variableCount'] = array_sum(array_map(static fn(array $d): int => count($d['variables']), $result['devices']));
        if ($result['devices'] === []) $result['notes'][] = 'Keine offiziellen Home-Connect-Geräte unter der Auswahl gefunden.';
        $result['notes'][] = 'Momentaufnahme: programmabhängige Optionen und Laufzeitwerte können erst nach Programmauswahl oder Start erscheinen. Keine Programme gestartet oder Einstellungen verändert.';
        $result['notes'][] = 'groupHint ist ein Vorschlag anhand von Ident und Aktion. Eine vorhandene Aktion ist keine Alexa-Schaltfreigabe; Fernsteuerung, Fernstart und lokale Bedienung müssen vor Gerätebefehlen geprüft werden.';
        return $result;
    }

    private static function isDevice(int $id): bool
    {
        return IPS_InstanceExists($id)
            && strtoupper((string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '')) === self::MODULE_ID;
    }

    private static function object(int $id): array
    {
        $object = IPS_GetObject($id);
        return ['id' => $id, 'name' => self::text((string)$object['ObjectName']),
            'parentId' => (int)$object['ParentID'], 'ident' => self::text((string)($object['ObjectIdent'] ?? ''))];
    }

    private static function device(int $id, array &$result, int &$remaining): array
    {
        $instance = IPS_GetInstance($id);
        $device = self::object($id) + ['moduleId' => self::MODULE_ID,
            'instanceStatus' => (int)$instance['InstanceStatus'], 'variables' => []];
        // Only this non-secret property, never full configuration or HaID.
        $device['deviceType'] = self::text((string)IPS_GetProperty($id, 'DeviceType'));
        $queue = array_map(static fn($child): array => [(int)$child, 0], IPS_GetChildrenIDs($id));
        $seen = [];
        while ($queue !== []) {
            [$child, $depth] = array_pop($queue);
            if (isset($seen[$child])) continue;
            if ($remaining <= 0 || count($seen) >= 2000 || $depth > 16) {
                self::incomplete($result, 'Variablenscan bei Gerät #' . $id . ' begrenzt.');
                continue;
            }
            $seen[$child] = true;
            $remaining--;
            try {
                $object = IPS_GetObject($child);
                if ((int)$object['ObjectType'] === 2) {
                    $device['variables'][] = self::variable($child);
                } elseif ((int)$object['ObjectType'] === 0) {
                    foreach (IPS_GetChildrenIDs($child) as $nested) $queue[] = [(int)$nested, $depth + 1];
                }
            } catch (Throwable $e) {
                self::incomplete($result, 'Variable #' . $child . ' konnte nicht vollständig gelesen werden.');
            }
        }
        usort($device['variables'], static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $device;
    }

    private static function variable(int $id): array
    {
        $var = IPS_GetVariable($id);
        $row = self::object($id) + ['type' => (int)$var['VariableType'],
            'updatedAt' => (int)($var['VariableUpdated'] ?? 0),
            'changedAt' => (int)($var['VariableChanged'] ?? 0)];
        $action = (int)($var['VariableCustomAction'] ?? 0);
        if ($action === 0) $action = (int)($var['VariableAction'] ?? 0);
        $row['actionId'] = $action;
        $row['hasAction'] = $action > 1;
        $row['actionDisabled'] = $action === 1;
        $ident = $row['ident'];
        $runtime = in_array($ident, ['OptionProgramProgress', 'OptionRemainingProgramTime', 'OptionElapsedProgramTime'], true);
        $row['groupHint'] = $runtime ? 'status' : (str_starts_with($ident, 'Option') || $ident === 'UseDuration' ? 'option'
            : (in_array($ident, ['SelectedProgram', 'ActiveProgram', 'Control'], true) ? 'program'
                : ($row['hasAction'] ? 'setting' : 'status')));
        $sensitive = preg_match('/password|passwort|token|secret|credential|cookie|authorization/i', $ident . ' ' . $row['name']) === 1;
        $row['valueRedacted'] = $sensitive;
        if (!$sensitive) {
            $value = GetValue($id);
            $safe = self::value($value);
            $row['rawValue'] = $safe;
            $row['valueRedacted'] = $safe === null && $value !== null;
            if (!$row['valueRedacted'] && function_exists('GetValueFormatted')) {
                $row['formattedValue'] = self::value(GetValueFormatted($id));
            }
        }
        $profileName = (string)($var['VariableCustomProfile'] ?? '');
        if ($profileName === '') $profileName = (string)($var['VariableProfile'] ?? '');
        $row['profile'] = ['name' => self::text($profileName)];
        if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
            $profile = IPS_GetVariableProfile($profileName);
            foreach (['ProfileType', 'MinValue', 'MaxValue', 'StepSize', 'Digits', 'Prefix', 'Suffix', 'Icon'] as $key) {
                if (array_key_exists($key, $profile)) $row['profile'][$key] = self::value($profile[$key]);
            }
            $row['profile']['Associations'] = [];
            foreach ((array)($profile['Associations'] ?? []) as $association) {
                $row['profile']['Associations'][] = [
                    'Value' => self::value($association['Value'] ?? null),
                    'Name' => self::value($association['Name'] ?? ''),
                ];
            }
        }
        return $row;
    }

    private static function value($value)
    {
        if (is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) return $value;
        if (is_string($value) && strlen($value) <= 2048
            && !preg_match('~https?://|<[^>]+>|(?:bearer|password|token|secret)\s*[:=]~i', $value)) {
            return self::text($value);
        }
        return null;
    }

    private static function text(string $value): string
    {
        return mb_substr(trim((string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', strip_tags($value))), 0, 2048);
    }

    private static function incomplete(array &$result, string $note): void
    {
        $result['complete'] = false;
        if (count($result['notes']) < 30) $result['notes'][] = $note;
    }
}
