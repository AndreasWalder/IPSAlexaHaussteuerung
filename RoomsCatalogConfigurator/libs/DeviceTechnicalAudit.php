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
            $profile = strtolower((string)($v['profile'] ?? ''));
            // KNX-Dimmer often have ONE percentage variable (~Intensity.100)
            // for both brightness and the logical on/off reading.
            $sharedDimmerIntensity=$type==='dimmer'
                && in_array($typeCode,[1,2],true)
                && (int)($configured['brightness']??0)===$id
                && self::isIntensityProfile($profile);
            if (!in_array($typeCode,self::VALID_TYPES[$role] ?? [],true)
                && !($role==='power' && $sharedDimmerIntensity)) {
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
                $sharedDimmerRole=$sharedDimmerIntensity
                    && in_array($used[$id],['power','brightness','status'],true)
                    && in_array($role,['power','brightness','status'],true);
                if (!in_array($pair,$allowedShared,true)
                    && !$sharedDimmerRole
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
                'tilt'=>['lamella','lamelle','tilt'],
                'control'=>['switch','bool','intensity','steuerung'],
                'brightness'=>['intensity','brightness','dimm'],
                'temperature'=>['temperature','temp','heizung'],
                'targetTemperature'=>['temperature','temp','heizung'],
                'motion'=>['motion','bewegung'],
                'illuminance'=>['illumination','lux','illuminance'],
                'action'=>['steuerung','shutter','blind','rolladen'],
                'position'=>['shutterposition','behangposition','shutter','position','rolladen','jalousie'],
                'open'=>['contact','fenster'],
                'status'=>['switch','motion','temperature','shutter','blind','rolladen','jalousie'],
            ];
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
            $nameLower=mb_strtolower((string)($v['name']??''),'UTF-8');
            $nameLower=str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],$nameLower);
            $heatingSetpointControl = $type==='heating' && $role==='control'
                && !empty($v['action']) && $typeCode===2
                && str_contains($profile,'temperature')
                && (
                    (int)($configured['targetTemperature']??0)===$id
                    || str_contains($nameLower,'solltemperatur')
                    || str_contains($nameLower,'setpoint')
                );
            if ($heatingSetpointControl) $profileMatches=true;
            // Ein Luefter kann genau eine beschreibbare Boolean-Variable
            // fuer die Steuerung und den angezeigten Ein/Aus-Befehlswert
            // besitzen. Ein neutraler KNX-Profilname macht die eindeutige
            // vorhandene Aktion nicht ungueltig. Das ist KEIN Nachweis
            // einer physisch unabhaengigen Rueckmeldung.
            $fanControlSignal=$type==='fan' && $typeCode===0
                && !empty($v['action'])
                && (
                    $role==='control'
                    || ($role==='power'
                        && (int)($configured['control']??0)===$id)
                );
            if ($fanControlSignal || ($sharedDimmerIntensity
                && in_array($role,['power','brightness','status'],true))) {
                $profileMatches=true;
            }
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

        // KNX has independent roles:
        // Position = 0..100 percent, Tilt = lamella angle, Action = command,
        // Status = a (possibly different) enum with profile associations.
        // Never compare 0/100 *position* against the Status enum or
        // manufacture missing open/closed values from a percent reading.
        $positionId=(int)($configured['position']??0);
        $actionId=(int)($configured['action']??$configured['control']??0);
        $targetId=(int)($configured['targetPosition']??0);
        $positionCollides=$positionId>0
            && ($positionId===$actionId || $positionId===$targetId);
        if ($type==='blind' && $positionCollides) {
            $reasons[]='Istposition #'.$positionId.' mit Fahraktion oder Sollposition identisch; echte Rueckmeldung nicht nachgewiesen';
            $issueCodes['position_command_collision']=true;
            $uncertain=true;
        }
        $positionEvidence=isset($evidence['position'])
            && !$positionCollides && empty($evidence['position']['action']);
        $semanticEvidence='not_applicable';
        if (in_array($type,['blind','window'],true)) {
            $mapping=[
                'open'=>trim((string)($row['aiOpenValue']??'')),
                'closed'=>trim((string)($row['aiClosedValue']??''))
            ];
            $discrete=$evidence['status']??$evidence['open']??null;
            $associations=is_array($discrete)
                ? (array)($discrete['associations']??[]) : [];
            $profileStates=self::openClosedAssociationValues($associations);
            $hasMapping=$mapping['open']!=='' || $mapping['closed']!=='';
            if ($hasMapping && ($mapping['open']==='' || $mapping['closed']===''
                || $mapping['open']===$mapping['closed'])) {
                $reasons[]='Explizite Offen/Geschlossen-Zuordnung ist unvollstaendig oder gleich';
                $issueCodes['state_mapping_conflict']=true;
                $uncertain=true;
                $semanticEvidence='conflict';
            } elseif ($hasMapping && $profileStates!==[]
                && ($mapping['open']!==$profileStates['open']
                    || $mapping['closed']!==$profileStates['closed'])) {
                $reasons[]='Explizite Offen/Geschlossen-Zuordnung widerspricht KNX-Statusprofil';
                $issueCodes['state_mapping_conflict']=true;
                $uncertain=true;
                $semanticEvidence='conflict';
            } elseif ($hasMapping && $profileStates!==[]) {
                $semanticEvidence='profile_confirmed';
            } elseif ($hasMapping) {
                $semanticEvidence='unconfirmed';
                // If a distinct *position* is available this is optional
                // status semantics; without it, enum meaning must be reviewed.
                if (!$positionEvidence) {
                    $reasons[]='Keine Status-Profilassoziationen fuer Offen/Geschlossen; am Geraet pruefen';
                    $issueCodes['state_associations_missing']=true;
                    $uncertain=true;
                }
            } elseif ($profileStates!==[]) {
                // Profile proves the meaning for this audit only; do NOT
                // write assumed numeric values into the RoomsCatalog.
                $semanticEvidence='profile_derived_not_saved';
            } elseif ($discrete!==null) {
                $semanticEvidence='unconfirmed';
                if (!$positionEvidence) {
                    $reasons[]='Offen/Geschlossen ohne eindeutige Profil-Assoziationen';
                    $issueCodes['state_associations_missing']=true;
                    $uncertain=true;
                }
            } elseif (!$positionEvidence) {
                $semanticEvidence='missing';
                $reasons[]='Keine getrennt lesbare Position und keine nachgewiesene Offen/Geschlossen-Rueckmeldung';
                $issueCodes['state_mapping_missing']=true;
                $uncertain=true;
            } else {
                $semanticEvidence='position_only';
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
            'manualEligible'=>!$critical && !$uncertain && !$missingRequired
                && !$unusableAction && $configured !== [] && $snapshot !== [],
            'statusSemanticEvidence'=>$semanticEvidence,
            'positionFeedbackIndependent'=>$positionEvidence,
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

    private static function isIntensityProfile(string $profile): bool
    {
        return str_contains($profile,'intensity')
            || str_contains($profile,'brightness') || str_contains($profile,'dimm');
    }

    /**
     * Only exact, non-ambiguous enum labels give reliable status meanings.
     * A numeric percent value from a position variable is NEVER used here.
     */
    private static function openClosedAssociationValues(array $associations): array
    {
        $values=[];
        foreach ($associations as $association) {
            if (!is_array($association)) continue;
            $label=self::normalize((string)($association['name']??''));
            $kind=match ($label) {
                'offen','geoeffnet','open'=>'open',
                'geschlossen','closed'=>'closed',
                default=>null
            };
            if ($kind===null) continue;
            $value=$association['value']??null;
            if (!is_scalar($value) || is_bool($value)) continue;
            $string=(string)$value;
            if (isset($values[$kind]) && $values[$kind]!==$string) return [];
            $values[$kind]=$string;
        }
        if (!isset($values['open'],$values['closed'])
            || $values['open']===$values['closed']) return [];
        return $values;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text),'UTF-8');
        $text = str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],$text);
        return preg_replace('/[^a-z0-9]/','',$text) ?? '';
    }
}
