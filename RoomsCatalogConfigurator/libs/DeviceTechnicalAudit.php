<?php
declare(strict_types=1);

/**
 * V3.3.9 – deterministic, read-only evidence classifier for typed device roles.
 * Never reads live variable values, actuates devices, or grants KI permissions.
 * Technical plausibility is NOT confirmation of real on/off/open semantics.
 */
final class RCCDeviceTechnicalAudit
{
    private const VALID_TYPES = [
        'power'=>[0,1], 'brightness'=>[1,2], 'position'=>[1,2],
        'targetPosition'=>[1,2], 'tilt'=>[1,2], 'moving'=>[0,1],
        'control'=>[0,1,2], 'temperature'=>[1,2],
        'targetTemperature'=>[1,2], 'open'=>[0,1],
        'powerWatts'=>[1,2], 'energy'=>[1,2], 'motion'=>[0,1],
        'status'=>[0,1,2,3], 'action'=>[0,1,2],
    ];

    /**
     * @param array<string,mixed> $row flattened catalog entry
     * @param array<string,string> $fieldMap role => editor field
     * @param array<int,array<string,mixed>> $scoped metadata from bounded scanner
     * @param array<int,array<int,string>> $requiredRoles alternatives per type
     * @param callable(array,string):int $score role-aware existing suggestion scorer
     * @param callable(int):bool $exists IPS_VariableExists
     */
    public static function assess(
        array $row,
        array $fieldMap,
        array $scoped,
        array $requiredRoles,
        callable $score,
        callable $exists
    ): array {
        $type = (string)($row['aiType'] ?? '');
        $configured = [];
        foreach ($fieldMap as $role => $field) {
            $id = (int)($row[$field] ?? 0);
            if ($id > 0) $configured[$role] = $id;
        }
        $snapshot = [];
        foreach ($scoped as $var) {
            $id = (int)($var['id'] ?? 0);
            if ($id > 0) $snapshot[$id] = $var;
        }
        $reasons = [];
        $critical = false;
        $uncertain = false;
        $bound = [];
        $used = [];
        $evidence = [];
        foreach ($configured as $role => $id) {
            if (!$exists($id)) {
                $reasons[] = 'IPS-Variable #' . $id . ' fuer ' . $role . ' existiert nicht';
                $critical = true;
                continue;
            }
            if (!isset($snapshot[$id])) {
                $reasons[] = 'Variable #' . $id . ' (' . $role . ') ausserhalb der erkannten Geraeteinstanz';
                $uncertain = true;
                continue;
            }
            $v = $snapshot[$id];
            $typeCode = (int)($v['type'] ?? -1);
            if (!in_array($typeCode,self::VALID_TYPES[$role] ?? [],true)) {
                $reasons[] = 'Datentyp von #' . $id . ' passt nicht zu ' . $role;
                $critical = true;
                continue;
            }
            if (in_array($role,['control','action'],true) && empty($v['action'])) {
                $reasons[] = '#' . $id . ' besitzt keine Schreibaktion (' . $role . ')';
                $uncertain = true;
            }
            if (isset($used[$id]) && $used[$id] !== $role) {
                $reasons[] = 'Variable #' . $id . ' mehrfach benutzt: ' . $used[$id] . '/' . $role;
                $uncertain = true;
            }
            $used[$id] = $role;
            $rank = (int)$score($v,$role);
            if ($rank < 105) {
                $reasons[] = '#' . $id . ' (' . $role . '): Rolle anhand Name/Profil nicht eindeutig';
                $uncertain = true;
            }
            $peers = [];
            foreach ($snapshot as $other) {
                if ((int)($other['id'] ?? 0) === $id) continue;
                $peers[] = (int)$score($other,$role);
            }
            if ($peers !== [] && max($peers) >= $rank - 24) {
                $reasons[] = 'Mehrdeutige Kandidaten fuer ' . $role;
                $uncertain = true;
            }
            $bound[$role] = true;
            $evidence[$role] = [
                'id'=>$id, 'type'=>$typeCode, 'profile'=>(string)($v['profile'] ?? ''),
                'name'=>(string)($v['name'] ?? ''), 'ident'=>(string)($v['ident'] ?? ''),
                'action'=>!empty($v['action']), 'associations'=>(array)($v['associations'] ?? [])
            ];
        }
        if ($configured === []) {
            $reasons[]='Keine typisierte IPS-Variable zugeordnet';
            $uncertain = true;
        }
        if ($snapshot === []) {
            $reasons[]='Keine eindeutig ermittelte Geraeteinstanz; Metadaten nicht verfuegbar';
            $uncertain = true;
        }
        foreach ($requiredRoles as $alternatives) {
            if (array_intersect($alternatives,array_keys($bound)) === []) {
                $reasons[]='Pflicht-Rueckmeldung fehlt: '.implode(' oder ',$alternatives);
                $critical = true;
            }
        }

        // A profile-derived association may substantiate numeric enum labels,
        // but a position (%) alone does not prove 0=open or 100=closed.
        if (in_array($type,['blind','window'],true)) {
            $mapping = [
                'open'=>trim((string)($row['aiOpenValue'] ?? '')),
                'closed'=>trim((string)($row['aiClosedValue'] ?? ''))
            ];
            $status = $evidence['status'] ?? $evidence['open'] ?? null;
            $associations = is_array($status) ? (array)($status['associations'] ?? []) : [];
            if ($mapping['open'] === '' || $mapping['closed'] === ''
                || $mapping['open'] === $mapping['closed']) {
                $reasons[]='Offen/Geschlossen-Werte nicht explizit und unterschiedlich hinterlegt';
                $uncertain = true;
            } elseif ($associations !== []) {
                $known = [];
                foreach ($associations as $association) {
                    if (!is_array($association)) continue;
                    $value = (string)($association['value'] ?? '');
                    $label = self::normalize((string)($association['name'] ?? ''));
                    if ($value === $mapping['open'] && in_array($label,['offen','geoeffnet','open'],true)) $known['open']=true;
                    if ($value === $mapping['closed'] && in_array($label,['geschlossen','closed'],true)) $known['closed']=true;
                }
                if (count($known) !== 2) {
                    $reasons[]='Profil-Assoziationen bestaetigen Offen/Geschlossen-Werte nicht';
                    $uncertain = true;
                }
            } else {
                $reasons[]='Keine Profil-Assoziationen fuer Offen/Geschlossen; am realen Geraet pruefen';
                $uncertain = true;
            }
        }

        $fingerprint = hash('sha256',(string)json_encode(
            ['type'=>$type,'configured'=>$configured,'evidence'=>$evidence,
             'states'=>[$row['aiOnValue']??null,$row['aiOffValue']??null,
                        $row['aiOpenValue']??null,$row['aiClosedValue']??null]],
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
        $status = $critical ? 'Ungueltig' : ($uncertain ? 'Manuell pruefen' : 'Technisch plausibel');
        return [
            'status'=>$status,
            'reason'=> $reasons !== [] ? implode('; ',array_slice(array_unique($reasons),0,4))
                : 'ID, Instanz, Datentyp, Rollenname und benoetigte Rueckmeldungen plausibel.',
            'roleCount'=>count($configured),
            'manualEligible'=>!$critical && $configured !== [] && $snapshot !== []
                && (!in_array($type,['blind','window'],true)
                    || (trim((string)($row['aiOpenValue'] ?? '')) !== ''
                        && trim((string)($row['aiClosedValue'] ?? '')) !== ''
                        && (string)($row['aiOpenValue'] ?? '') !== (string)($row['aiClosedValue'] ?? ''))),
            'fingerprint'=>$fingerprint,
            'semanticReviewRequired'=>in_array($type,['blind','window'],true)
                || in_array('action',array_keys($configured),true)
                || in_array('control',array_keys($configured),true),
        ];
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text),'UTF-8');
        $text = str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],$text);
        return preg_replace('/[^a-z0-9]/','',$text) ?? '';
    }
}
