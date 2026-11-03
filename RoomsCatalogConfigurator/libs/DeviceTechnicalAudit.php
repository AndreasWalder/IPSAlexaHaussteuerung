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
        'status'=>[0,1,2,3], 'action'=>[0,1,2], 'illuminance'=>[2],
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
        $issueCodes = [];
        $critical = false;
        $uncertain = false;
        $missingRequired = false;
        $unusableAction = false;
        $bound = [];
        $used = [];
        $evidence = [];
        foreach ($configured as $role => $id) {
            if (!$exists($id)) {
                $reasons[] = 'IPS-Variable #' . $id . ' fuer ' . $role . ' existiert nicht';
                $issueCodes['missing_ips'] = true;
                $critical = true;
                continue;
            }
            if (!isset($snapshot[$id])) {
                $reasons[] = 'Variable #' . $id . ' (' . $role . ') ausserhalb der erkannten Geraeteinstanz';
                $issueCodes['outside_scope'] = true;
                $uncertain = true;
                continue;
            }
            $v = $snapshot[$id];
            $typeCode = (int)($v['type'] ?? -1);
            if (!in_array($typeCode,self::VALID_TYPES[$role] ?? [],true)) {
                $reasons[] = 'Datentyp von #' . $id . ' passt nicht zu ' . $role;
                $issueCodes['wrong_type'] = true;
                $critical = true;
                continue;
            }
            if (in_array($role,['control','action'],true) && empty($v['action'])) {
                $reasons[] = '#' . $id . ' besitzt keine Schreibaktion (' . $role . ')';
                $issueCodes['no_action'] = true;
                $uncertain = true;
                $unusableAction = true;
            }
            if (isset($used[$id]) && $used[$id] !== $role) {
                $pair = [$used[$id],$role];
                sort($pair,SORT_STRING);
                // Legacy-Projektion: Ein boolescher Status ist zugleich der
                // power-/open-/motion-Zustand. Das ist kein Mapping-Fehler.
                $allowedShared = [
                    ['power','status'], ['open','status'], ['motion','status']
                ];
                $sharedAction = [
                    ['control','power'], ['brightness','control'], ['control','status'],
                    ['control','targetTemperature']
                ];
                if (!in_array($pair,$allowedShared,true)
                    && !(in_array($pair,$sharedAction,true) && !empty($v['action']))) {
                    $reasons[] = 'Variable #' . $id . ' mehrfach benutzt: ' . $used[$id] . '/' . $role;
                    $issueCodes['duplicate_mapping'] = true;
                    $uncertain = true;
                }
            }
            $used[$id] = $role;
            $rank = (int)$score($v,$role);
            // Eine explizite ai.variables-ID mit passendem Datentyp
            // und anerkanntem Rollenprofil ist auch bei KNX "Value"
            // ein technischer Metadatentreffer. Ohne Profil bleibt
            // die fachliche Kontrolle weiterhin offen.
            $roleProfiles = [
                'power'=>['switch','bool'],
                'control'=>['switch','bool','intensity','steuerung'],
                'brightness'=>['intensity','brightness','dimm'],
                'temperature'=>['temperature','temp','heizung'],
                'targetTemperature'=>['temperature','temp','heizung'],
                'motion'=>['motion','bewegung'],
                'illuminance'=>['illumination','lux','illuminance'],
                'action'=>['steuerung','shutter','blind','rolladen'],
                'position'=>['shutterposition','behangposition'],
                'open'=>['contact','fenster'],
                'status'=>['switch','motion','temperature','shutter','blind'],
            ];
            $profile = strtolower((string)($v['profile'] ?? ''));
            $profileMatches = false;
            foreach ($roleProfiles[$role] ?? [] as $token) {
                if ($profile !== '' && str_contains($profile,$token)) {
                    $profileMatches = true;
                    break;
                }
            }
            // A writable KNX heating setpoint may legitimately serve as
            // targetTemperature AND control, even when named "Soll".
            // The same ID without an IPS action remains a manual issue.
            $heatingSetpointControl = $type==='heating' && $role==='control'
                && (int)($configured['targetTemperature']??0)===$id
                && !empty($v['action']);
            if ($heatingSetpointControl) $profileMatches=true;
            if ($profileMatches) $rank=max($rank,120);
            if ($rank < 105) {
                $reasons[] = '#' . $id . ' (' . $role . '): Rolle anhand Name/Profil nicht eindeutig';
                $issueCodes['role_name_ambiguous'] = true;
                $uncertain = true;
            }
            $peers = [];
            foreach ($snapshot as $other) {
                if ((int)($other['id'] ?? 0) === $id) continue;
                $peers[] = (int)$score($other,$role);
            }
            if (!$profileMatches && $peers !== [] && max($peers) >= $rank - 24) {
                $reasons[] = 'Mehrdeutige Kandidaten fuer ' . $role;
                $issueCodes['multiple_candidates'] = true;
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
            $issueCodes['no_typed_variables'] = true;
            $uncertain = true;
        }
        if ($snapshot === []) {
            $reasons[]='Keine eindeutig ermittelte Geraeteinstanz; Metadaten nicht verfuegbar';
            $issueCodes['no_scope'] = true;
            $uncertain = true;
        }
        foreach ($requiredRoles as $alternatives) {
            if (array_intersect($alternatives,array_keys($bound)) === []) {
                $reasons[]='Pflicht-Rueckmeldung fehlt: '.implode(' oder ',$alternatives);
                // Fehlendes Mapping ist unvollstaendig, nicht automatisch eine
                // defekte IPS-Variable. Manuelle Bestaetigung bleibt gesperrt.
                $issueCodes['missing_required_role'] = true;
                $uncertain = true;
                $missingRequired = true;
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
                $issueCodes['state_mapping_missing'] = true;
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
                    $issueCodes['state_mapping_conflict'] = true;
                    $uncertain = true;
                }
            } else {
                $reasons[]='Keine Profil-Assoziationen fuer Offen/Geschlossen; am realen Geraet pruefen';
                $issueCodes['state_associations_missing'] = true;
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
            'issueCodes'=>array_keys($issueCodes),
            'manualEligible'=>!$critical && !$missingRequired && !$unusableAction && $configured !== [] && $snapshot !== []
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


    /** Reject arbitrary executable PHP before reserializing EDIT from an array. */
    public static function isLiteralArraySource(string $php): bool
    {
        $tokens = token_get_all($php);
        $hasReturn = false;
        foreach ($tokens as $token) {
            if (is_string($token)) {
                if (!str_contains('[](),;=+-', $token)) return false;
                continue;
            }
            [$kind,$value] = $token;
            if ($kind === T_RETURN) {
                if ($hasReturn) return false;
                $hasReturn = true;
                continue;
            }
            if (in_array($kind, [
                T_OPEN_TAG,T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,
                T_CONSTANT_ENCAPSED_STRING,T_LNUMBER,T_DNUMBER,T_DOUBLE_ARROW,T_ARRAY
            ],true)) continue;
            if ($kind === T_STRING && in_array(strtolower($value),
                ['true','false','null','array'],true)) continue;
            return false;
        }
        return $hasReturn;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text),'UTF-8');
        $text = str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],$text);
        return preg_replace('/[^a-z0-9]/','',$text) ?? '';
    }
}
