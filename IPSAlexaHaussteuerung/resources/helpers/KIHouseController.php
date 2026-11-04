<?php

declare(strict_types=1);

// IAH_DEVICETYPE_REGISTRY_INSERT
// Im Symcon-Skript durch Modul-Update aus der zentralen Registry eingebettet.
// Beim direkten PHP-Unit-Test dieselbe zentrale PHP-Datei laden.
if (!class_exists('RCCDeviceTypeRegistry', false)) {
    require_once dirname(__DIR__, 3) . '/RoomsCatalogConfigurator/libs/DeviceTypeRegistry.php';
}

// IAH_HOUSE_QUERY_ENGINE_INSERT
// Auf IP-Symcon wird der zentrale, lesende Query-Engine-Code direkt ins Skript eingebettet.
if (!function_exists('iah_ai_query_house')) {
    require_once __DIR__ . '/HouseQueryEngine.php';
}

/**
 * Alexa KI -> produktiver RoomsCatalog -> sichere IP-Symcon-Aktionen.
 * Keine Home-Agent-Installation, keine freien PHP-/Variablen-ID-Aufrufe.
 * Bestandsgeräte ohne kiReadOnly-Eintrag bleiben ausschließlich lesbar.
 */
function iah_rc_log(string $event, array $details = []): void
{
    IPS_LogMessage('Alexa', 'KIRoomsCatalog.' . $event .
        ($details ? ' ' . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : ''));
}
function iah_rc_norm(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    return trim(preg_replace('/[^a-z0-9]+/u', ' ', str_replace(
        ['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $text)) ?? '');
}
/** V3.4: Explizite Rollen; Steuerbefehle sind keine bestaetigten Istwerte. */
function iah_rc_type_roles(): array
{
    return RCCDeviceTypeRegistry::roles();
}
function iah_rc_aliases(mixed $aliases): array
{
    if (is_string($aliases)) $aliases = explode(',', $aliases);
    if (!is_array($aliases)) return [];
    return array_values(array_filter(array_map(
        static fn($value): string => is_string($value) ? trim($value) : '', $aliases
    ), static fn(string $value): bool => $value !== ''));
}
/** V3.4.1: Aus dem gesprochenen Text eindeutig erkennbare Lesefilter bevorzugen.
 * So wird etwa "Fenster" nicht irrtuemlich nur in domain=fenster gesucht,
 * obwohl die Daten unter sensoren/fenster liegen. Ohne Schreibwirkung.
 */
function iah_rc_scope_read_query(array $devices, array $args, string $spoken): array
{
    $n = ' ' . iah_rc_norm($spoken) . ' ';
    if (preg_match('/\bfenster(kontakt|kontakte|n)?\b/u', $n)) {
        unset($args['domain'], $args['group'], $args['only_active']);
        if (in_array(iah_rc_norm((string)($args['name'] ?? '')), ['fenster','fensterkontakt','fensterkontakte'], true)) unset($args['name']);
        $args['type'] = 'window';
    } elseif (preg_match('/\b(jalousie|jalousien|rollladen|rolllaeden|rollo|rollos)\b/u', $n)) {
        unset($args['type'], $args['group'], $args['only_active']);
        if (in_array(iah_rc_norm((string)($args['name'] ?? '')), ['jalousie','jalousien','rollladen'], true)) unset($args['name']);
        $args['domain'] = 'jalousie';
    } elseif (preg_match('/\b(warm|temperatur|temperaturen|heizung|heizungen|grad)\b/u', $n)
        && !preg_match('/\b(stell|stelle|setze|erhoehe|senke|schalte)\b/u', $n)) {
        unset($args['type'], $args['group'], $args['only_active']);
        if (in_array(iah_rc_norm((string)($args['name'] ?? '')), ['temperatur','heizung'], true)) unset($args['name']);
        $args['domain'] = 'heizung';
    }
    // Raum nur bei eindeutiger, vollstaendiger Namens-/Alias-Nennung erschliessen.
    $foundRooms = [];
    foreach ($devices as $dev) {
        foreach (array_merge([$dev['room'],$dev['roomKey']], $dev['roomAliases'] ?? []) as $label) {
            $needle = iah_rc_norm((string)$label);
            if (strlen($needle) >= 4 && str_contains($n, ' ' . $needle . ' ')) {
                $foundRooms[$dev['roomKey']] = $dev['roomKey'];
                break;
            }
        }
    }
    if (count($foundRooms) === 1) {
        $args['room'] = (string)reset($foundRooms);
        // Das Modell liefert einen Raum gelegentlich faelschlich als name.
        // Nur den exakt erkannten Raumbegriff aus dem Namensfilter entfernen.
        $roomName = iah_rc_norm((string)($args['name'] ?? ''));
        foreach ($devices as $dev) {
            if ($dev['roomKey'] !== $args['room']) continue;
            foreach (array_merge([$dev['room'],$dev['roomKey']], $dev['roomAliases'] ?? []) as $label) {
                if ($roomName!=='' && $roomName===iah_rc_norm((string)$label)) {
                    unset($args['name']);
                    break 2;
                }
            }
        }
    }
    return $args;
}
/** Keine freien KNX-Befehle/Enums: nur sichere, explizite Zielrollen. */
function iah_rc_write_roles(string $type, array $ids): array
{
    $candidates = match ($type) {
        'light','switch','fan' => ['power'=>'control'],
        'dimmer' => ['power'=>'control','brightness'=>'brightness'],
        'heating' => ['targetTemperature'=>'targetTemperature'],
        'blind' => ['targetPosition'=>'targetPosition'],
        default => []
    };
    $writable = [];
    foreach ($candidates as $role => $source) {
        $id = (int)($ids[$source] ?? 0);
        if ($id <= 0 || !IPS_VariableExists($id) || !function_exists('HasAction') || !HasAction($id)) continue;
        $variableType = (int)(IPS_GetVariable($id)['VariableType'] ?? -1);
        if ($role === 'power' ? $variableType !== 0 : !in_array($variableType,[1,2],true)) continue;
        // Insbesondere Jalousie-Fahraktion und Istposition niemals als Sollposition verwenden.
        if ($type === 'blind' && ($id === ($ids['position'] ?? 0) || $id === ($ids['action'] ?? 0))) continue;
        $writable[$role] = $id;
    }
    return $writable;
}
function iah_rc_index(int $scriptId): array
{
    if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) throw new RuntimeException('RoomsCatalog-Skript fehlt');
    $file = IPS_GetScriptFile($scriptId);
    if (!is_file($file)) $file = rtrim(IPS_GetKernelDir(), '\\/') . '/scripts/' . $file;
    if (!is_file($file)) throw new RuntimeException('RoomsCatalog-Datei fehlt');
    $rooms = require $file;
    if (!is_array($rooms)) throw new RuntimeException('RoomsCatalog ist ungültig');
    if (is_array($rooms['rooms'] ?? null)) $rooms = $rooms['rooms'];
    $devices = [];
    $knownTypes = iah_rc_type_roles();
    foreach ($rooms as $roomKey => $room) {
        if (!is_array($room) || $roomKey === 'global') continue;
        $roomName = (string)($room['display'] ?? $roomKey);
        $floor = (string)($room['floor'] ?? '');
        $roomAliases = iah_rc_aliases($room['aliases'] ?? []);
        $domains = (array)($room['domains'] ?? []);
        foreach ($domains as $domain => $groups) {
            if (!is_array($groups)) continue;
            // Heizung/Jalousie sind flach; alle anderen Geraetebereiche gruppiert.
            $sets = in_array($domain, ['heizung','jalousie'], true) ? [$domain=>$groups] : [];
            if ($sets === []) foreach ($groups as $group => $items)
                if (is_array($items)) $sets[(string)$group] = $items;
            foreach ($sets as $group => $items) {
                foreach ($items as $key => $dev) {
                    if (!is_array($dev) || (array_key_exists('kiEnabled',$dev) && !$dev['kiEnabled'])) continue;
                    $ai = is_array($dev['ai'] ?? null) ? $dev['ai'] : [];
                    $type = (string)($ai['type'] ?? '');
                    $typed = isset($knownTypes[$type]) && (int)($ai['version'] ?? 0) === 1
                        && is_array($ai['variables'] ?? null);
                    // Ein explizites, aber unbekanntes Schema niemals als Legacy interpretieren.
                    if (array_key_exists('type',$ai) && !$typed) continue;
                    $legacy = ($domain === 'licht' && in_array($group,['switches','dimmers','status'],true))
                        || ($domain === 'heizung' && $group === 'heizung')
                        || ($domain === 'jalousie' && $group === 'jalousie')
                        || ($domain === 'lueftung' && $group === 'fans');
                    if (!$typed && !$legacy) continue;
                    $readFields = match ($group) {
                        'heizung' => ['ist','soll','eingestellt'],
                        'jalousie' => ['wert'],
                        'dimmers' => ['value','state'],
                        'status' => ['value'],
                        default => ['state','value']
                    };
                    $reads = [];
                    $writeRoles = [];
                    if ($typed) {
                        $mapped = [];
                        foreach ($knownTypes[$type] as $role) {
                            $variableId = $ai['variables'][$role] ?? null;
                            if ((!is_int($variableId) && !(is_string($variableId) && ctype_digit($variableId)))
                                || (int)$variableId <= 0 || !IPS_VariableExists((int)$variableId)) continue;
                            $mapped[$role] = (int)$variableId;
                        }
                        foreach ($mapped as $role => $variableId)
                            if (!in_array($role,['action','control'],true)) $reads[$role] = $variableId;
                        // Vorhandene, ausdrueckliche Legacy-Heizungswerte nicht verlieren,
                        // wenn der additive ai-Block noch keine Temperaturrolle enthaelt.
                        // Ausschliesslich lesend; keine neue Schreibberechtigung.
                        if ($type === 'heating') {
                            foreach (['temperature'=>['ist'], 'targetTemperature'=>['soll','eingestellt']] as $role=>$fields) {
                                if (isset($reads[$role])) continue;
                                foreach ($fields as $field) {
                                    $legacyId=(int)($dev[$field] ?? 0);
                                    if ($legacyId<=0 || !IPS_VariableExists($legacyId)) continue;
                                    $legacyType=(int)(IPS_GetVariable($legacyId)['VariableType'] ?? -1);
                                    if (in_array($legacyType,[1,2],true)) { $reads[$role]=$legacyId; break; }
                                }
                            }
                        }
                        // Ein Fan mit nur control meldet einen Befehlswert, keinen Istzustand.
                        if ($type === 'fan' && !isset($reads['power']) && isset($mapped['control']))
                            $reads['commandValue'] = $mapped['control'];
                        if ($reads === []) continue;
                        if (($ai['review']['status'] ?? '') === 'confirmed'
                            && ($dev['kiEnabled'] ?? false) === true
                            && array_key_exists('kiReadOnly',$dev) && $dev['kiReadOnly'] === false)
                            $writeRoles = iah_rc_write_roles($type,$mapped);
                        $readId = (int)reset($reads);
                    } else {
                        $readId = 0;
                        foreach ($readFields as $field) if ((int)($dev[$field] ?? 0) > 0) {
                            $readId = (int)$dev[$field]; break;
                        }
                    }
                    if ($readId <= 0 || !IPS_VariableExists($readId)) continue;
                    $writeId = $typed ? (int)(reset($writeRoles) ?: 0) : match ($group) {
                        'switches', 'fans' => (int)($dev['toggle'] ?? 0),
                        'dimmers' => (int)($dev['set'] ?? 0),
                        'heizung' => (int)($dev['soll'] ?? $dev['eingestellt'] ?? 0),
                        default => 0
                    };
                    $writable = $typed ? $writeRoles !== [] : (array_key_exists('kiReadOnly',$dev) && !$dev['kiReadOnly']
                        && in_array($group,['switches','fans','dimmers','heizung'],true)
                        && $writeId > 0 && IPS_VariableExists($writeId)
                        && function_exists('HasAction') && HasAction($writeId));
                    $id = (string)$roomKey . '/' . $domain . '/' . $group . '/' . $key;
                    $devices[$id] = [
                        'id' => $id, 'name' => (string)($dev['title'] ?? $key),
                        'room' => $roomName, 'roomKey' => (string)$roomKey,
                        'floor' => $floor, 'domain' => (string)$domain, 'group' => (string)$group,
                        'roomAliases' => $roomAliases,
                        'synonyms' => iah_rc_aliases($dev['synonyms'] ?? ''),
                        'read' => $readId, 'write' => $writeId, 'writable' => $writable,
                        'typed' => $typed, 'type' => $typed ? $type : (string)$group,
                        'reads' => $reads, 'writeRoles' => $writeRoles,
                        'stateMapping' => $typed && is_array($ai['stateMapping'] ?? null) ? $ai['stateMapping'] : [],
                        'setpoint' => (int)($dev['eingestellt'] ?? $dev['soll'] ?? 0),
                        'min' => is_numeric($dev['min'] ?? null) ? (float)$dev['min'] : null,
                        'max' => is_numeric($dev['max'] ?? null) ? (float)$dev['max'] : null
                    ];
                }
            }
        }
    }
    return $devices;
}
function iah_rc_find(array $devices, array $filters): array
{
    $found = [];
    $deviceKey = (string)($filters['deviceKey'] ?? '');
    if ($deviceKey !== '') return isset($devices[$deviceKey]) ? [$devices[$deviceKey]] : [];
    foreach ($devices as $dev) {
        $ok = true;
        foreach (['name','room','floor','domain','group','type'] as $field) {
            $wanted = iah_rc_norm((string)($filters[$field] ?? ''));
            if ($wanted === '' || $wanted === 'all' || $wanted === 'alle') continue;
            $choices = $field === 'name'
                ? array_merge([$dev['name'],$dev['id']],$dev['synonyms'])
                : ($field === 'room' ? array_merge([$dev['room'],$dev['roomKey']],$dev['roomAliases'] ?? []) : [$dev[$field]]);
            $match = false;
            foreach ($choices as $choice) {
                $value = iah_rc_norm((string)$choice);
                if ($value !== '' && ($value === $wanted || str_contains($value,$wanted))) $match = true;
            }
            if (!$match) { $ok = false; break; }
        }
        if ($ok) $found[] = $dev;
    }
    return $found;
}
/** Zeitpunkt der LETZTEN WERTAENDERUNG laut IPS, nicht letzter Poll/Update.
 * Der Zeitwert ist eine Eigenschaft der verknuepften Variable, keine Historie.
 * Ohne explizite Nachfrage werden keinerlei Zeitdaten gelesen/ausgegeben.
 */
function iah_rc_wants_last_change(string $spoken): bool
{
    return preg_match('/\\b(?:wann|seit\\s+wann|wie\\s+lange|zuletzt|letzte[rsn]?|geändert|geaendert|gewechselt|aktualisiert|letzter\\s+wechsel|letzte\\s+statusaenderung)\\b/ui',
        $spoken) === 1;
}
function iah_rc_last_change(array $dev): ?array
{
    // Der zuletzt geaenderte lesbare Istwert; keine Kommando-IDs.
    $last=0; $lastRole='';
    foreach ((array)($dev['reads'] ?? []) as $role=>$id) {
        if ($role==='commandValue' || !IPS_VariableExists((int)$id)) continue;
        try {
            $var=IPS_GetVariable((int)$id);
            $ts=(int)($var['VariableChanged'] ?? 0);
        } catch (Throwable $ignored) { continue; }
        if ($ts>0 && $ts<time()+60 && $ts>$last) {$last=$ts;$lastRole=(string)$role;}
    }
    // Legacy-Datensaetze haben nur eine Read-ID, keine Rollenliste.
    if (!$last && empty($dev['typed']) && (int)($dev['read']??0)>0) {
        try {
            $var=IPS_GetVariable((int)$dev['read']);
            $last=(int)($var['VariableChanged']??0);
            $lastRole='state';
        } catch (Throwable $ignored) {$last=0;}
    }
    if ($last<=0 || $last>time()+60) return null;
    return ['lastChangedAt'=>date('c',$last),'lastChangedProperty'=>$lastRole];
}
function iah_rc_state(array $dev, bool $includeLastChange = false): array
{
    $v = !empty($dev['typed']) ? null : GetValue($dev['read']);
    $state = is_bool($v) ? ($v ? 'an' : 'aus') : $v;
    $out = ['deviceKey'=>$dev['id'],'name'=>$dev['name'],'room'=>$dev['room'],
        'floor'=>$dev['floor'],'type'=>$dev['type'] ?? $dev['group'],'state'=>$state,'writable'=>$dev['writable']];
    if (!empty($dev['typed'])) {
        $properties=[];
        foreach ($dev['reads'] as $role=>$id) {
            if (!IPS_VariableExists($id)) continue;
            try { $raw=GetValue($id); } catch (Throwable $e) { continue; }
            $mapped=$raw;
            $meanings = match ($dev['type']) {
                'blind','window','door' => in_array($role,['status','open'],true)
                    ? ['open'=>'offen','closed'=>'geschlossen'] : [],
                'light','dimmer','switch','motion' => in_array($role,['power','status','motion'],true)
                    ? ['on'=>'an','off'=>'aus'] : [],
                default => []
            };
            foreach ($meanings as $key=>$label) {
                $expected=$dev['stateMapping'][$key] ?? null;
                if ($expected !== null && get_debug_type($expected) === get_debug_type($raw) && $expected === $raw) {
                    $mapped=$label;
                    break;
                }
            }
            // Die explizit als 'open' konfigurierte Boolean-Rolle bedeutet offen=true.
            // Fuer allgemeines 'status' bleibt weiterhin ein stateMapping notwendig.
            if (in_array($dev['type'],['window','door'],true) && $role==='open' && is_bool($mapped)
                && !array_key_exists('open',$dev['stateMapping'])
                && !array_key_exists('closed',$dev['stateMapping']))
                $mapped=$mapped ? 'offen' : 'geschlossen';
            if (is_bool($mapped) && in_array($role,['power','motion','commandValue'],true))
                $mapped=$mapped ? 'an' : 'aus';
            $properties[$role]=$mapped;
        }
        // Position bleibt numerisch. Ein Endlagenstatus darf nur aus dem
        // expliziten Mapping abgeleitet werden, niemals pauschal aus 0/100.
        if ($dev['type']==='blind' && !isset($properties['status'])
            && isset($properties['position']) && is_numeric($properties['position'])) {
            $position=$properties['position'];
            foreach (['open'=>'offen','closed'=>'geschlossen'] as $meaning=>$label) {
                $expected=$dev['stateMapping'][$meaning] ?? null;
                if ($expected!==null && is_numeric($expected) && (float)$expected===(float)$position) {
                    $properties['endPositionStatus']=$label;
                    break;
                }
            }
        }
        $out['properties']=$properties;
        $out['availableProperties']=array_keys($properties);
        $firstRole=array_key_first($dev['reads']);
        $out['state']=$properties[$firstRole] ?? 'nicht verfügbar';
        if (isset($properties['commandValue']) && !isset($properties['power'])) {
            $out['state']='Istzustand nicht bestätigt';
            $out['stateSource']='nur Steuerungswert';
        }
        if ($includeLastChange) {
            $change=iah_rc_last_change($dev);
            if ($change!==null) $out=array_merge($out,$change);
        }
        return $out;
    }
    if ($dev['group'] === 'heizung') {
        if (is_numeric($v)) $out['properties']=['temperature'=>$v];
        if ($dev['setpoint'] > 0 && IPS_VariableExists($dev['setpoint'])) {
            $out['targetTemperature'] = GetValue($dev['setpoint']);
            $out['properties']['targetTemperature']=$out['targetTemperature'];
        }
        if (isset($out['properties'])) $out['availableProperties']=array_keys($out['properties']);
    }
    if ($includeLastChange) {
        $change=iah_rc_last_change($dev);
        if ($change!==null) $out=array_merge($out,$change);
    }
    return $out;
}
function iah_rc_write(array $dev, mixed $value, string $property = ''): array
{
    if (!$dev['writable']) return ['ok'=>false,'reason'=>'Dieses Gerät ist nur lesbar'];
    if (!empty($dev['typed'])) {
        $roles=$dev['writeRoles'];
        if ($property === '') {
            if (count($roles) === 1) $property=(string)array_key_first($roles);
            elseif (is_bool($value) && isset($roles['power'])) $property='power';
            elseif (is_numeric($value) && !is_bool($value) && isset($roles['brightness'])) $property='brightness';
        }
        if (!array_key_exists($property,$roles))
            return ['ok'=>false,'reason'=>'Eigenschaft ist nicht eindeutig oder nicht zum Schalten freigegeben'];
        $id=(int)$roles[$property];
    } else {
        if ($property !== '') return ['ok'=>false,'reason'=>'Eigenschaften nur bei typisierten Geräten unterstützt'];
        $id=(int)$dev['write'];
    }
    if (!IPS_VariableExists($id) || !HasAction($id)) return ['ok'=>false,'reason'=>'Keine Schaltaktion'];
    $type = (int)(IPS_GetVariable($id)['VariableType'] ?? -1);
    $role = !empty($dev['typed']) ? $property : $dev['group'];
    if (in_array($role,['switches','fans','power'],true)) {
        if ($type !== 0 || !is_bool($value)) return ['ok'=>false,'reason'=>'Booleanwert erwartet'];
    } elseif (in_array($role,['dimmers','heizung','brightness','targetTemperature','targetPosition'],true)) {
        if (!in_array($type,[1,2],true) || !is_numeric($value) || is_bool($value))
            return ['ok'=>false,'reason'=>'Numerischer Sollwert erwartet'];
        $num=(float)$value;
        if (!empty($dev['typed'])) {
            $min=$role==='targetTemperature'?6:0;
            $max=$role==='targetTemperature'?30:100;
        } else {
            $min=$dev['min'] ?? ($dev['group']==='dimmers'?0:6);
            $max=$dev['max'] ?? ($dev['group']==='dimmers'?100:30);
        }
        if (!is_finite($num) || $num<$min || $num>$max)
            return ['ok'=>false,'reason'=>'Wert liegt außerhalb des erlaubten Bereichs'];
        $value=$type===1?(int)round($num):$num;
    } else return ['ok'=>false,'reason'=>'Schalten für diesen Gerätetyp nicht unterstützt'];
    try {
        $ok=RequestAction($id,$value);
        return ['ok'=>(bool)$ok,'name'=>$dev['name'],'room'=>$dev['room'],
            'result'=>$ok?'Aktion gesendet; Rückmeldung noch nicht verifiziert':'IP-Symcon hat die Aktion abgelehnt'];
    } catch (Throwable $e) {
        return ['ok'=>false,'reason'=>'Aktion fehlgeschlagen','name'=>$dev['name']];
    }
}
/** Antworten bleiben auch bei vielen typisierten Eigenschaften valides JSON. */
function iah_rc_bounded_rows(array $rows): array
{
    $result=[];
    $bytes=0;
    foreach ($rows as $row) {
        $encoded=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        $length=is_string($encoded) ? strlen($encoded) : 0;
        if (count($result)>=50 || $bytes+$length>9500) break;
        $result[]=$row;
        $bytes+=$length+1;
    }
    return $result;
}
function iah_rc_tool(array $devices, string $name, array $args, bool $allowWrites, string $spoken): array
{
    if ($name === 'get_devices') {
        $found=iah_rc_find($devices,$args);
        $rows=array_map(static function(array $d)use($spoken):array {
            $row=['deviceKey'=>$d['id'],'name'=>$d['name'],
                'room'=>$d['room'],'type'=>$d['type'],'writable'=>$d['writable'],
                'properties'=>array_keys($d['reads']),
                'writableProperties'=>array_keys($d['writeRoles'])];
            if (iah_rc_wants_last_change($spoken)) {
                $change=iah_rc_last_change($d);
                if ($change!==null) $row=array_merge($row,$change);
            }
            return $row;
        },$found);
        $rows=iah_rc_bounded_rows($rows);
        return ['count'=>count($found),'truncated'=>count($found)>count($rows),'devices'=>$rows];
    }
    if ($name === 'get_states') {
        $scopedArgs=iah_rc_scope_read_query($devices,$args,$spoken);
        $found=iah_rc_find($devices,$scopedArgs);$active=0;$entries=[];$eligible=0;
        $onlyActive=(bool)($scopedArgs['only_active']??false);
        $includeLastChange=iah_rc_wants_last_change($spoken);
        $statuses=['offen'=>0,'geschlossen'=>0,'unbekannt'=>0];
        $temperatureAvailable=0;
        foreach ($found as $dev) {
            $row=iah_rc_state($dev,$includeLastChange);
            $on=$row['state']==='an'
                || in_array('an',array_intersect_key((array)($row['properties']??[]),
                    array_flip(['power','status','motion'])),true);
            if($on)$active++;
            if (array_key_exists('temperature',(array)($row['properties'] ?? []))) $temperatureAvailable++;
            if (in_array($dev['type'] ?? '', ['window','door','blind'],true) || $dev['group']==='jalousie') {
                $props=(array)($row['properties']??[]);
                $status=$props['status']??$props['open']??$props['endPositionStatus']??null;
                if ($status==='offen') $statuses['offen']++;
                elseif ($status==='geschlossen') $statuses['geschlossen']++;
                else $statuses['unbekannt']++;
            }
            if (!$onlyActive || $on) { $eligible++; $entries[]=$row; }
        }
        $entries=iah_rc_bounded_rows($entries);
        return ['matched'=>count($found),'activeBooleanDevices'=>$active,
            'temperatureAvailable'=>$temperatureAvailable,
            'statusSummary'=>$statuses,
            'filtersUsed'=>array_intersect_key($scopedArgs,array_flip(['room','domain','group','type','name','only_active'])),
            'shown'=>count($entries),'truncated'=>$eligible>count($entries),
            'devices'=>$entries];
    }
    if (!$allowWrites) return ['ok'=>false,'reason'=>'KI-Schaltbefehle global nicht freigegeben'];
    if ($name === 'set_device') {
        $found=iah_rc_find($devices,$args);
        if (count($found)!==1) return ['ok'=>false,'reason'=>count($found)?'Gerätename ist mehrdeutig':'Gerät nicht gefunden','matches'=>count($found)];
        return iah_rc_write($found[0],$args['value']??null,(string)($args['property']??''));
    }
    if ($name === 'set_multiple') {
        if (!preg_match('/\b(alle|alles|sämtliche|saemtliche|gesamten)\b/ui',$spoken))
            return ['ok'=>false,'reason'=>'Gruppenschaltung muss ausdrücklich verlangt sein'];
        if (!is_bool($args['value']??null) || !in_array((string)($args['domain']??''),['licht','lueftung'],true))
            return ['ok'=>false,'reason'=>'Nur Licht-/Lüftungsschalter als Gruppe'];
        $list=[];
        foreach (iah_rc_find($devices,$args) as $dev)
            if(in_array($dev['group'],['switches','fans'],true) && $dev['writable'])$list[$dev['write']]=$dev;
        if (!$list || count($list)>15) return ['ok'=>false,'reason'=>'Keine oder mehr als 15 freigegebene Geräte','count'=>count($list)];
        $results=[];foreach($list as $dev)$results[]=iah_rc_write($dev,$args['value']);
        return ['requested'=>count($results),'accepted'=>count(array_filter($results,static fn($r)=>$r['ok']??false)),
            'results'=>$results];
    }
    return ['ok'=>false,'reason'=>'Unbekanntes Werkzeug'];
}
function iah_rc_http(array $payload,string $apiKey,float $deadline): array
{
    $timeout=(int)(($deadline-microtime(true))*1000);
    if($timeout<250) throw new RuntimeException('Zeitbudget ausgeschöpft');
    $ch=curl_init('https://api.openai.com/v1/chat/completions');
    if($ch===false)throw new RuntimeException('cURL konnte nicht gestartet werden');
    try {
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],
            CURLOPT_CONNECTTIMEOUT_MS=>min(1000,$timeout),CURLOPT_TIMEOUT_MS=>$timeout,
            CURLOPT_FOLLOWLOCATION=>false]);
        $data=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        if(!is_string($data)||$code!==200)throw new RuntimeException('OpenAI-HTTP-Fehler, Status '.$code);
        return json_decode($data,true,512,JSON_THROW_ON_ERROR);
    } finally {curl_close($ch);}
}
/** Für beide KI-Modelle mit einem Unit-Test überprüfbare API-Parameter. */
function iah_rc_chat_payload(string $model,array $messages,array $tools,int $round,
    bool $preferRead=false, bool $finalAnswer=false, string $preferredReadTool='get_states'): array
{
    $toolChoice=$round===0
        ? ($preferRead ? ['type'=>'function','function'=>['name'=>$preferredReadTool]] : 'required')
        : ($finalAnswer ? 'none' : 'auto');
    $payload=['model'=>$model,'messages'=>$messages,'tools'=>$tools,
        'tool_choice'=>$toolChoice,'max_completion_tokens'=>200];
    if($model==='gpt-6-luna')$payload['reasoning_effort']='none';
    return $payload;
}
function iah_rc_voice(string $text,array $cfg): array
{
    $key=trim((string)($cfg['api_key']??''));$catalogId=(int)($cfg['catalog_script_id']??0);
    if($key===''||$catalogId<=0||trim($text)==='')return ['handled'=>false,'error'=>'KI-Konfiguration unvollständig'];
    // Ereignisse an das persistente IPS-Modul melden, ohne den normalen Antwortweg zu beeinflussen.
    $costInstanceId=(int)($cfg['ki_cost_instance_id']??0);
    $costQuestionId=bin2hex(random_bytes(12));
    $costReport=static function (string $type,array $more=[]) use($costInstanceId,$costQuestionId):void {
        if ($costInstanceId<=0 || !function_exists('IAH_RecordKICostEvent')) return;
        try {
            $event=array_merge(['type'=>$type,'backend'=>'RoomsCatalog','questionId'=>$costQuestionId],$more);
            if (!IAH_RecordKICostEvent($costInstanceId,json_encode($event,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE))) {
                iah_rc_log('cost.warning',['stage'=>$type,'reason'=>'Event nicht gespeichert']);
            }
        } catch (Throwable $err) {
            iah_rc_log('cost.warning',['stage'=>$type,'reason'=>'Statistikfehler']);
        }
    };
    $costReport('start',['model'=>(string)($cfg['model']??'gpt-4.1-mini')]);
    $costFinish=static function (array $result) use($costReport):array {
        $costReport('finish',['success'=>(bool)($result['handled']??false)]);
        return $result;
    };
    $started=microtime(true);$deadline=$started+max(2,min(7,(int)($cfg['timeout_seconds']??6)));
    $maxWords=max(8,min(45,(int)($cfg['max_words']??20)));$allowWrites=(bool)($cfg['allow_writes']??false);
    try{
        $devices=iah_rc_index($catalogId);
        $typeCounts=[];$temperatureMapped=0;
        foreach ($devices as $device) {
            $kind=(string)($device['type']??'');
            $typeCounts[$kind]=($typeCounts[$kind]??0)+1;
            if (isset($device['reads']['temperature']) ||
                ($device['group']==='heizung' && $device['read']>0)) $temperatureMapped++;
        }
        iah_rc_log('catalog.loaded',['count'=>count($devices),'writeEnabled'=>$allowWrites,
            'types'=>$typeCounts,'temperatureReadable'=>$temperatureMapped]);
        if(!$devices)return $costFinish(['handled'=>true,'answer'=>'Im RoomsCatalog sind keine KI-Geräte freigegeben.']);
        $props=[
            'deviceKey'=>['type'=>'string'],'name'=>['type'=>'string'],'room'=>['type'=>'string'],
            'floor'=>['type'=>'string'],'domain'=>['type'=>'string'],'group'=>['type'=>'string'],
            'type'=>['type'=>'string'],
        ];
        $make=static fn(string $n,string $d,array $p):array=>['type'=>'function','function'=>[
            'name'=>$n,'description'=>$d,'parameters'=>['type'=>'object','properties'=>$p]]];
        $tools=[
            $make('get_states','Liest aktuelle Geraete-Eigenschaften aus RoomsCatalog PROD: z.B. Temperatur, Sollwert, Position, Status, Lux und Verbrauch. Nur explizite Rueckmeldungen sind Istwerte. Fuer eingeschaltete Lichter domain=licht und only_active=true.',
                $props+['only_active'=>['type'=>'boolean']]),
            $make('get_devices','Listet Geraete mit eindeutigen deviceKeys, verfuegbaren Eigenschaften und freigegebenen Schreib-Eigenschaften.',$props)
        ];
        if($allowWrites){
            $tools[]=$make('set_device','Aendert EIN eindeutig bestimmtes, explizit freigegebenes und fachlich bestaetigtes Geraet. property optional: power als Boolean, brightness/targetPosition 0-100, targetTemperature 6-30. Zuerst get_devices fuer deviceKey und writableProperties. Keine KNX-Fahrbefehle oder frei erfundenen IDs.',
                $props+['property'=>['type'=>'string'],'value'=>['type'=>['boolean','number']]]);
            $tools[]=$make('set_multiple','Schaltet bis zu 15 freigegebene Lampen/Lüfter, nur wenn der Nutzer ausdrücklich ALLE verlangt.',
                $props+['value'=>['type'=>'boolean']]);
        }
        $uiDomain=(string)($cfg['ui_context_domain']??'');
        $uiDomain=preg_match('/^[a-z0-9_-]{2,50}$/D',$uiDomain) ? $uiDomain : '';
        $uiHint=$uiDomain!=='' ? ' Aktueller Alexa-UI-Bereich: '.$uiDomain.'. Dieser Bereich ist ausschließlich ein Kontext für unvollständige Fragen und gewährt keine Schreibrechte.' : '';
        $preferRead=(bool)preg_match('/^\s*(wie|wann|seit wann|welche|welcher|welches|was|wieviel|wie viel|ist|sind|zeige|nenne|liste|haben|hat)\b/ui',$text);
        $messages=[
            ['role'=>'system','content'=>'Du bist eine deutsche Alexa-Haussteuerung. Alle Hausdaten nur ueber die Werkzeuge aus RoomsCatalog PROD. Geraete koennen mehrere Eigenschaften besitzen; Status, Sollwert und Steuerbefehl unterscheiden. Fuer Live-Fragen immer get_states (nicht get_devices: das ist nur eine Inventarliste). Bei matched=0 oder statusSummary.unbekannt>0 nicht behaupten, alle Fenster/Jalousien seien geschlossen. Nur bestaetigte Offen-/Geschlossen-Werte verwenden. Ein reiner Steuerwert ist KEIN bestaetigter Istzustand. Keine erfundenen Geraete oder Einheiten, keine Schaltbestaetigung ohne Werkzeugerfolg. Maximal '.$maxWords.' Woerter, ein kurzer deutscher Satz. Zeitangaben wie lastChangedAt/lastChangedProperty werden NUR bei expliziter Frage nach letzter Aenderung, wann oder seit wann geliefert und beziehen sich auf den letzten IPS-WERTWECHSEL der bezeichneten Eigenschaft, nicht auf historische Ereignisse. Keine Zeiten erfinden; ohne Zeitfeld die Verfuegbarkeit verneinen. Bei Abfragen niemals schalten. Bei unklaren Geraeten nachfragen. Eine an IP-Symcon gesendete Aktion ist noch keine bestaetigte Rueckmeldung.'.$uiHint],
            ['role'=>'user','content'=>mb_substr($text,0,500,'UTF-8')]
        ];
        for($round=0;$round<2;$round++){
            iah_rc_log('openai.start',['round'=>$round+1]);
            $model=(string)($cfg['model']??'gpt-6-luna');
            $request=iah_rc_chat_payload($model,$messages,$tools,$round,$preferRead,true);
            $response=iah_rc_http($request,$key,$deadline);
            $costReport('api',['callId'=>'round-'.$round,'response'=>[
                'model'=>(string)($response['model']??($cfg['model']??'')),
                'usage'=>$response['usage']??null
            ]]);
            $msg=$response['choices'][0]['message']??null;
            if(!is_array($msg))throw new RuntimeException('Keine KI-Antwort');
            $calls=$msg['tool_calls']??[];
            if(!$calls){
                $words=preg_split('/\s+/u',trim(strip_tags((string)($msg['content']??''))),-1,PREG_SPLIT_NO_EMPTY)?:[];
                if(!$words)throw new RuntimeException('Keine Sprachantwort');
                $answer=implode(' ',array_slice($words,0,$maxWords));
                iah_rc_log('answer',['durationMs'=>round((microtime(true)-$started)*1000),'text'=>$answer]);
                return $costFinish(['handled'=>true,'answer'=>$answer]);
            }
            if($round>0)throw new RuntimeException('Zu viele Tool-Runden');
            $messages[]=$msg;
            foreach(array_slice($calls,0,3) as $call){
                $name=(string)($call['function']['name']??'');
                $args=json_decode((string)($call['function']['arguments']??'{}'),true);
                if(!is_array($args)||!in_array($name,array_column(array_column($tools,'function'),'name'),true))
                    throw new RuntimeException('Ungültiger Werkzeugaufruf');
                $result=iah_rc_tool($devices,$name,$args,$allowWrites,$text);
                $diagnostic=['name'=>$name,'ok'=>$result['ok']??true];
                foreach (['matched','shown','truncated','temperatureAvailable','statusSummary','filtersUsed','count'] as $field)
                    if (array_key_exists($field,$result)) $diagnostic[$field]=$result[$field];
                iah_rc_log('tool',$diagnostic);
                $messages[]=['role'=>'tool','tool_call_id'=>(string)$call['id'],
                    'content'=>mb_substr(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),0,12000,'UTF-8')];
            }
        }
    }catch(Throwable $e){
        iah_rc_log('error',['reason'=>$e->getMessage(),'durationMs'=>round((microtime(true)-$started)*1000)]);
        return $costFinish(['handled'=>false,'error'=>$e->getMessage()]);
    }
    return $costFinish(['handled'=>false,'error'=>'Keine Antwort']);
}
if(isset($_IPS)&&is_array($_IPS)&&array_key_exists('kiCatalogText',$_IPS)){
    $cfg=json_decode((string)($_IPS['kiCatalogConfigJson']??'{}'),true);
    $result=iah_rc_voice((string)$_IPS['kiCatalogText'],is_array($cfg)?$cfg:[]);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
