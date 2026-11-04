<?php
declare(strict_types=1);

/**
 * V3.4.5: Universelles, NUR LESENDES Werkzeug fuer KI-Abfragen.
 *
 * Die KI plant: Geraetefilter, gesuchte Eigenschaft, Vergleich, Gruppierung.
 * PHP liest ausschliesslich bereits freigegebene RoomsCatalog-Variablen und
 * berechnet darauf deterministische Ergebnisse. Kein PHP-Code der KI,
 * keine frei uebergebenen IPS-IDs, kein RequestAction, keine Domain-Sonderlogik.
 *
 * Wird bei der Modulerstellung in das IPS-Skript eingebettet.
 */

/** Kleine, dynamische Faehigkeitsliste als Faktenbasis fuer den KI-Planer. */
function iah_ai_schema(array $devices): array
{
    $types = [];
    $properties = [];
    $rooms = [];
    foreach ($devices as $dev) {
        $type = (string)($dev['type'] ?? '');
        if ($type !== '') $types[$type] = ($types[$type] ?? 0) + 1;
        $roomKey = (string)($dev['roomKey'] ?? '');
        if ($roomKey !== '') $rooms[$roomKey] = (string)($dev['room'] ?? $roomKey);
        foreach (array_keys((array)($dev['reads'] ?? [])) as $property) {
            // 'commandValue' bezeichnet ausdruecklich keine Rueckmeldung.
            if ($property === 'commandValue') continue;
            $properties[$property] = ($properties[$property] ?? 0) + 1;
        }
        if (empty($dev['typed']) && ($dev['group'] ?? '') === 'heizung' && ($dev['read'] ?? 0) > 0)
            $properties['temperature'] = ($properties['temperature'] ?? 0) + 1;
    }
    ksort($types);
    ksort($properties);
    return ['types'=>$types,'properties'=>$properties,'roomCount'=>count($rooms),
        'rooms'=>array_values($rooms)];
}

/** Rueckmelde-Rollen aus dem zentralen Katalog; abgeleitete Aktivitaet ist optional. */
function iah_ai_property_allowed(string $property): bool
{
    return $property === 'activity' || $property === 'state'
        || array_key_exists($property, RCCDeviceTypeRegistry::fields());
}

/** Istwert lesen, niemals einen Befehlswert als bestaetigten Zustand ausgeben. */
function iah_ai_property_value(array $state, string $property): mixed
{
    $props = (array)($state['properties'] ?? []);
    if ($property === 'state') return $state['state'] ?? null;
    if ($property === 'activity') {
        // Sichere semantische Alternative: expliziter Ein/Aus-Istzustand,
        // sonst Helligkeit > 0 oder Bewegung; keine control/action-Rollen.
        foreach (['power','status','motion'] as $role) {
            $value = $props[$role] ?? null;
            if (in_array($value,['an','aus'],true)) return $value;
            if (is_bool($value)) return $value ? 'an' : 'aus';
        }
        $brightness = $props['brightness'] ?? null;
        if (is_numeric($brightness) && !is_bool($brightness))
            return (float)$brightness > 0 ? 'an' : 'aus';
        if (empty($state['properties']) && in_array(($state['state'] ?? null),['an','aus'],true))
            return $state['state'];
        return null;
    }
    if (!array_key_exists($property,$props)) return null;
    return $props[$property];
}

/** Typisierte Werte aus IP-Symcon-Vorlagen oder expliziten Mapping-Labels. */
function iah_ai_condition_matches(mixed $actual, string $operator, mixed $expected): bool
{
    if ($operator === '') return true;
    $normalized = static function(mixed $value):mixed {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_string($value)) {
            $text = iah_rc_norm($value);
            return match ($text) {
                'ein','eingeschaltet','aktiv','true','on','an' => 'an',
                'aus','ausgeschaltet','inaktiv','false','off' => 'aus',
                'offen','geoeffnet','open' => 'offen',
                'geschlossen','zu','closed' => 'geschlossen',
                default => $text
            };
        }
        return $value;
    };
    if ($operator === 'active') return in_array($normalized($actual),['an','offen'],true)
        || $actual === true || (is_numeric($actual) && !is_bool($actual) && (float)$actual > 0);
    if ($operator === 'inactive') return in_array($normalized($actual),['aus','geschlossen'],true)
        || $actual === false || (is_numeric($actual) && !is_bool($actual) && (float)$actual <= 0);
    if (in_array($operator,['eq','neq'],true)) {
        $a=$normalized($actual);$b=$normalized($expected);
        $equal = $a === $b || (is_numeric($a) && is_numeric($b) && (float)$a === (float)$b);
        return $operator === 'eq' ? $equal : !$equal;
    }
    if (!in_array($operator,['gt','gte','lt','lte'],true)
        || !is_numeric($actual) || !is_numeric($expected)
        || is_bool($actual) || is_bool($expected)) return false;
    $a=(float)$actual;$b=(float)$expected;
    return match($operator) {
        'gt'=>$a>$b, 'gte'=>$a>=$b, 'lt'=>$a<$b, 'lte'=>$a<=$b,
        default=>false
    };
}

/** Vergleiche die gewuenschten Raeume anhand der Room-IDs/-Aliase. */
function iah_ai_selected_room(array $dev, array $requested): bool
{
    if ($requested === []) return true;
    foreach($requested as $needle) {
        $candidate=iah_rc_norm((string)$needle);
        if ($candidate === '') continue;
        foreach(array_merge([(string)($dev['roomKey']??''),(string)($dev['room']??'')],
            (array)($dev['roomAliases']??[])) as $name) {
            if (iah_rc_norm((string)$name)===$candidate) return true;
        }
    }
    return false;
}

/**
 * Property-basiert lesen, filtern, gruppieren, zaehlen, mitteln, summieren,
 * Maximum/Minimum und Ranglisten bilden – fuer ALLE Geraetetypen.
 *
 * @param array $args ausschliesslich deklarative Filter/Operationen
 */
function iah_ai_query_house(array $devices, array $args, string $spoken = ''): array
{
    $property=(string)($args['property'] ?? '');
    $operation=(string)($args['operation'] ?? 'list');
    $groupBy=(string)($args['group_by'] ?? 'device');
    $condition=(string)($args['condition'] ?? '');
    $expected=$args['value'] ?? null;
    $validOps=['list','count','min','max','avg','sum'];
    if (!iah_ai_property_allowed($property))
        return ['ok'=>false,'error'=>'unknown_property','requested'=>$property,
            'availableProperties'=>array_keys(iah_ai_schema($devices)['properties'])];
    if (!in_array($operation,$validOps,true) || !in_array($groupBy,['room','device','none'],true))
        return ['ok'=>false,'error'=>'unsupported_operation','operations'=>$validOps,
            'groupBy'=>['room','device','none']];
    if (!in_array($condition,['','eq','neq','gt','gte','lt','lte','active','inactive'],true))
        return ['ok'=>false,'error'=>'unsupported_condition'];
    $type=(string)($args['type']??'');
    $knownTypes=iah_rc_type_roles();
    if ($type!=='' && !isset($knownTypes[$type]) && !in_array($type,['switches','fans','dimmers'],true))
        return ['ok'=>false,'error'=>'unknown_device_type','requested'=>$type,
            'hint'=>'Bei einer Eigenschaft property statt type verwenden.',
            'availableTypes'=>array_keys(iah_ai_schema($devices)['types'])];
    $filters=[];
    foreach (['room','floor','domain','group','type','name','deviceKey'] as $field)
        if (!empty($args[$field]) && is_scalar($args[$field]))
            $filters[$field]=(string)$args[$field];
    $rooms=array_values(array_filter((array)($args['rooms']??[]), 'is_scalar'));
    $limit=max(1,min(50,(int)($args['limit']??15)));
    $indoorOnly=($args['indoor_only']??false)===true;
    $found=iah_rc_find($devices,$filters);
    $validDevices=0;$measurements=0;$perGroup=[];$dedup=[];
    $includeLastChange=iah_rc_wants_last_change($spoken);
    foreach ($found as $dev) {
        if (!iah_ai_selected_room($dev,$rooms)) continue;
        // Nicht von Innenraeumen auf explizite Wetterstationen schliessen.
        if ($indoorOnly && (($dev['type']??'')==='weather'
            || preg_match('/\\b(aussen|aussenbereich|garten|terrasse|balkon|outdoor|draussen)\\b/u',
                iah_rc_norm((string)($dev['room']??'').' '.($dev['roomKey']??''))))) continue;
        $state=iah_rc_state($dev,$includeLastChange);
        $value=iah_ai_property_value($state,$property);
        if ($value===null) continue; // fehlend ≠ aus / 0
        $validDevices++;
        if (!iah_ai_condition_matches($value,$condition,$expected)) continue;
        $groupKey=$groupBy==='room' ? (string)$dev['roomKey']
            : ($groupBy==='device' ? (string)$dev['id'] : '_all');
        $label=$groupBy==='room' ? (string)$dev['room'] :
            ($groupBy==='device' ? (string)$dev['name'] : 'Gesamt');
        $sourceId=(int)($dev['reads'][$property]??($dev['read']??0));
        // Dieselbe IPS-Variable innerhalb eines Raumes nicht doppelt mitteln.
        if ($groupBy==='room' && $sourceId>0 && isset($dedup[$groupKey][$sourceId]))
            continue;
        if ($groupBy==='room' && $sourceId>0) $dedup[$groupKey][$sourceId]=true;
        if (!isset($perGroup[$groupKey])) $perGroup[$groupKey]=[
            'key'=>$groupKey,'name'=>$label,'count'=>0,'values'=>[],
            'devices'=>[]];
        $perGroup[$groupKey]['count']++;
        $perGroup[$groupKey]['values'][]=$value;
        $entry=['deviceKey'=>$dev['id'],'name'=>$dev['name'],'room'=>$dev['room'],
            'value'=>$value];
        if ($includeLastChange && isset($state['lastChangedAt'])) {
            $entry['lastChangedAt']=$state['lastChangedAt'];
            $entry['lastChangedProperty']=$state['lastChangedProperty']??'';
        }
        $perGroup[$groupKey]['devices'][]=$entry;
        $measurements++;
    }
    $rows=[];
    foreach($perGroup as $group) {
        $row=['key'=>$group['key'],'name'=>$group['name'],'count'=>$group['count']];
        if ($operation==='list' || $operation==='count') {
            if ($groupBy==='device') $row['value']=$group['values'][0]??null;
            if ($operation==='list') {
                $row['devices']=array_slice($group['devices'],0,4);
                if (count($group['devices'])>4) $row['moreDevices']=count($group['devices'])-4;
            }
        } else {
            $numeric=array_values(array_filter($group['values'],
                static fn($v):bool=>is_numeric($v)&&!is_bool($v)&&is_finite((float)$v)));
            if (count($numeric)!==count($group['values'])) continue;
            $nums=array_map('floatval',$numeric);
            // Fuer einen Raumvergleich den Mittelwert aller unabhaengigen
            // Sensoren des Raumes bewerten, nicht die Extremwerte einzelner
            // Sensoren. Fuer Geräte/gesamt dagegen normale min/max-Semantik.
            $row['value']=($groupBy==='room' && in_array($operation,['min','max'],true))
                ? array_sum($nums)/count($nums)
                : match($operation) {
                    'min'=>min($nums), 'max'=>max($nums),
                    'sum'=>array_sum($nums), 'avg'=>array_sum($nums)/count($nums),
                    default=>null
                };
            $row['value']=round((float)$row['value'],3);
        }
        $rows[]=$row;
    }
    if (in_array($operation,['min','max','avg','sum'],true)) {
        usort($rows,static function(array $a,array $b) use($operation):int {
            $cmp=(float)($a['value']??0) <=>(float)($b['value']??0);
            if ($operation==='max') $cmp=-$cmp;
            return $cmp!==0 ? $cmp : strcmp((string)$a['key'],(string)$b['key']);
        });
    } elseif ($operation==='count') {
        usort($rows,static fn(array $a,array $b):int=>$b['count']<=>$a['count']);
    }
    $totalCount=array_sum(array_column($rows,'count'));
    if ($groupBy==='none') {
        // Alle Messungen gruppiert und als Gesamtmetrik; bei max/min/... ist
        // operation das Aggregat ueber ALLE Sensoren statt einzelner Raeume.
        $primary=$rows[0]??null;
    } else $primary=$rows[0]??null;
    $visible=iah_rc_bounded_rows(array_slice($rows,0,$limit));
    $truncated=count($rows)>count($visible);
    $result=['ok'=>true,'operation'=>$operation,'property'=>$property,
        'groupBy'=>$groupBy,'matchedDevices'=>count($found),'withProperty'=>$validDevices,
        'matchedReadings'=>$measurements,'groups'=>count($rows),
        'totalCount'=>$totalCount,'truncated'=>$truncated,
        'roomSensorAggregation'=>$groupBy==='room' ? 'mean_of_distinct_measurements' : 'none',
        'rows'=>$visible];
    if ($primary!==null && in_array($operation,['max','min','avg','sum'],true)) {
        $result['best']=$primary;
        // Bei gleicher Anzeige-Rundung alle gleichrangigen Gewinner nennen.
        $same=array_values(array_filter($rows,static fn(array $row):bool=>
            round((float)$row['value'],1)===round((float)$primary['value'],1)));
        $result['ties']=array_slice($same,0,10);
    }
    if ($validDevices===0) $result['warning']='Keine gueltigen Istwerte fuer diese Eigenschaft gefunden. Nicht als Null interpretieren.';
    elseif ($groupBy!=='none' && $truncated) $result['warning']='Ergebnisliste gekuerzt; Aggregat wurde aus allen Treffern berechnet.';
    return $result;
}
