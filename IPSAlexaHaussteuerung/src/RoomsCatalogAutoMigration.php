<?php
declare(strict_types=1);

/**
 * V3.3.11: deterministische, verlustarme Offline-Projektion.
 * Keine Schaltbefehle, keine aktuellen Variablenwerte und keine
 * implizite Bestaetigung von Zustands-Semantik oder KI-Schreibrechten.
 */
final class IAHRoomsCatalogAutoMigration
{
    private const UI_DOMAINS = [
        'jalousie'=>['scenes','icon','open_close_var'],
        'licht'=>['scenes'],
        'lueftung'=>['default_buttons','central','icon','central_title','status_colors'],
    ];

    public static function project(array $catalog, array $system, callable $meta): array
    {
        $new = $catalog;
        $rooms = isset($new['rooms']) && is_array($new['rooms'])
            ? $new['rooms'] : $new;
        $changed = [];
        $warnings = [];
        $types = [];
        $skipped = 0;
        $scanned = 0;

        foreach ($rooms as $roomKey => &$room) {
            if ($roomKey === 'global' || !is_array($room)
                || !is_array($room['domains'] ?? null)) continue;
            $domains = &$room['domains'];
            foreach ($domains as $domainKey => &$domain) {
                if (!is_array($domain)) continue;
                if (in_array($domainKey,['heizung','jalousie'],true)) {
                    foreach ($domain as $deviceKey => &$device) {
                        if (!is_array($device)) continue;
                        self::correctDevice($device,$domainKey,$domainKey,
                            (string)$roomKey.'/'.$domainKey.'/'.$deviceKey,
                            $meta,$changed,$warnings,$types,$skipped);
                        $scanned++;
                    }
                    unset($device);
                    continue;
                }
                foreach ($domain as $groupKey => &$group) {
                    if (!is_array($group)) continue;
                    foreach ($group as $deviceKey => &$device) {
                        if (!is_array($device)) continue;
                        self::correctDevice($device,$domainKey,(string)$groupKey,
                            (string)$roomKey.'/'.$domainKey.'/'.$groupKey.'/'.$deviceKey,
                            $meta,$changed,$warnings,$types,$skipped);
                        $scanned++;
                    }
                    unset($device);
                }
                unset($group);
            }
            unset($domain);
            unset($domains);
        }
        unset($room);

        $uiCount=0;
        $uiMoved=[];
        $globalDomains = is_array($rooms['global']['domains'] ?? null)
            ? $rooms['global']['domains'] : [];
        $newSystem = $system;
        if (isset($newSystem['roomUi']) && !is_array($newSystem['roomUi'])) {
            throw new RuntimeException('SystemConfiguration.roomUi hat einen unerwarteten Typ.');
        }
        $existing = $newSystem['roomUi']['globalDomains'] ?? [];
        if (!is_array($existing)) {
            throw new RuntimeException('SystemConfiguration.roomUi.globalDomains hat einen unerwarteten Typ.');
        }
        foreach (self::UI_DOMAINS as $domainKey=>$allowed) {
            if (!array_key_exists($domainKey,$globalDomains)) continue;
            $data=$globalDomains[$domainKey];
            if (!is_array($data)) throw new RuntimeException('Globale UI-Domain ist kein Array: '.$domainKey);
            foreach (array_keys($data) as $key) {
                if (!in_array((string)$key,$allowed,true)) {
                    throw new RuntimeException('Unerwartetes globales Feld: '.$domainKey.'.'.$key
                        .' – bitte zuerst manuell zuordnen (Abbruch ohne Schreibzugriff).');
                }
            }
            if (isset($existing[$domainKey]) && $existing[$domainKey] !== $data) {
                throw new RuntimeException('Konflikt in SystemConfiguration.roomUi: '.$domainKey);
            }
            $existing[$domainKey]=$data;
            unset($rooms['global']['domains'][$domainKey]);
            $uiMoved[]=$domainKey;
            foreach (['scenes','default_buttons','central'] as $key) {
                if (isset($data[$key]) && is_array($data[$key])) $uiCount+=count($data[$key]);
            }
        }
        if ($uiMoved!==[]) {
            $newSystem['roomUi']['schemaVersion']=1;
            $newSystem['roomUi']['globalDomains']=$existing;
        }
        if (isset($new['rooms']) && is_array($new['rooms'])) $new['rooms']=$rooms;
        else $new=$rooms;

        return [
            'catalog'=>$new, 'system'=>$newSystem,
            'report'=>[
                'devicesSeen'=>$scanned,'devicesChanged'=>count($changed),
                'changes'=>$changed,'typeChanges'=>$types,'warnings'=>$warnings,
                'skipped'=>$skipped,'uiItemsMoved'=>$uiCount,
                'uiDomainsMoved'=>$uiMoved
            ]
        ];
    }

    private static function correctDevice(
        array &$d,string $domain,string $group,string $path,callable $meta,
        array &$changed,array &$warnings,array &$types,int &$skipped
    ): void {
        $prior=$d;
        $ai=is_array($d['ai'] ?? null) ? $d['ai'] : [];
        $vars=is_array($ai['variables'] ?? null) ? $ai['variables'] : [];
        $oldType=(string)($ai['type'] ?? '');
        $type=$oldType;
        $candidate=0;
        $role='';
        $allowed=[];
        if ($domain==='licht' && $group==='status') {
            $candidate=(int)($d['value'] ?? 0);
            $info=$candidate>0 ? $meta($candidate) : null;
            if (is_array($info) && (int)($info['type'] ?? -1)===2
                && str_contains(strtolower((string)($info['profile'] ?? '')),'illumination')) {
                $type='illuminance';$role='illuminance';$allowed=[2];
                unset($vars['power'],$vars['control'],$vars['status']);
            }
        } elseif ($domain==='licht' && $group==='dimmers') {
            $candidate=(int)($d['value'] ?? 0);
            $info=$candidate>0 ? $meta($candidate) : null;
            if (is_array($info) && in_array((int)($info['type'] ?? -1),[1,2],true)) {
                $type='dimmer';$role='brightness';$allowed=[1,2];
                // Legacy-Autoimport hatte den Dimmwert faelschlich als Boolean power behandelt.
                if ((int)($vars['power'] ?? 0)===$candidate) unset($vars['power']);
                if ((int)($vars['control'] ?? 0)===$candidate
                    && empty($info['hasAction'])) unset($vars['control']);
            }
        } elseif ($domain==='jalousie') {
            $candidate=(int)($d['wert'] ?? 0);
            $info=$candidate>0 ? $meta($candidate) : null;
            if (is_array($info) && in_array((int)($info['type'] ?? -1),[1,2],true)
                && !empty($info['hasAction'])) {
                $type='blind';$role='action';$allowed=[1,2];
                // Nur ein Kommandowert, KEINE Positionsrueckmeldung.
            }
        } elseif ($domain==='heizung') {
            $candidate=(int)($d['ist'] ?? 0);
            $info=$candidate>0 ? $meta($candidate) : null;
            if (is_array($info) && in_array((int)($info['type'] ?? -1),[1,2],true)) {
                $type='heating';$role='temperature';$allowed=[1,2];
                $target=(int)($d['soll'] ?? 0);
                $tInfo=$target>0 ? $meta($target) : null;
                if (is_array($tInfo) && in_array((int)($tInfo['type'] ?? -1),[1,2],true)) {
                    $vars['targetTemperature']=$target;
                }
                // Mehrere KNX-Instanzen sind gueltig.
            }
        } elseif ($domain==='licht' && $group==='switches') {
            $candidate=(int)($d['state'] ?? 0);
            $info=$candidate>0 ? $meta($candidate) : null;
            if (is_array($info) && (int)($info['type'] ?? -1)===0) {
                $type='light';$role='power';$allowed=[0];
                $toggle=(int)($d['toggle'] ?? 0);
                $cmd=$toggle>0 ? $meta($toggle) : null;
                if (is_array($cmd) && !empty($cmd['hasAction'])) $vars['control']=$toggle;
            }
        } elseif ($domain==='sensoren' && $group==='bewegung') {
            $candidate=(int)($vars['motion'] ?? 0);
            $info=$candidate>0 ? $meta($candidate) : null;
            if (is_array($info) && (int)($info['type'] ?? -1)===0) {
                $type='motion';$role='motion';$allowed=[0];
            }
        } elseif ($domain==='lueftung' && $group==='fans') {
            $candidate=(int)($d['state'] ?? 0);
            $info=$candidate>0 ? $meta($candidate) : null;
            if (is_array($info) && (int)($info['type'] ?? -1)===0) {
                $type='fan';$role='power';$allowed=[0];
                $toggle=(int)($d['toggle'] ?? 0);
                $cmd=$toggle>0 ? $meta($toggle) : null;
                if (is_array($cmd) && !empty($cmd['hasAction'])) $vars['control']=$toggle;
            }
        }
        if ($role!=='' && $candidate>0 && in_array((int)($info['type'] ?? -1),$allowed,true)) {
            $vars[$role]=$candidate;
        } elseif ($oldType==='' || ($domain==='licht' && in_array($group,['status','dimmers'],true))) {
            $warnings[]=$path.': keine eindeutige Daten-/Profilzuordnung – ungeaendert.';
            $skipped++;
        }
        if ($type!=='' && ($type!==$oldType || $vars!==($ai['variables'] ?? []))) {
            $ai['version']=max(1,(int)($ai['version'] ?? 1));
            $ai['type']=$type;
            $ai['variables']=$vars;
            $review=is_array($ai['review'] ?? null) ? $ai['review'] : [];
            // Niemals einen alten Confirmed-Eintrag nach einer automatischen
            // Umtypisierung ungeprueft als bestaetigt beibehalten.
            if ($type!==$oldType) $review['status']='pending';
            elseif (!isset($review['status'])) $review['status']='pending';
            $review['note']='Automatisch aus echten IPS-Typen/Profilen zugeordnet; Werte und Aktionen vor Ort bestaetigen.';
            $ai['review']=$review;
            $d['ai']=$ai;
        }
        if ($d!==$prior) {
            $changed[]=$path;
            if ($type!==$oldType) {
                $types[($oldType!=='' ? $oldType : 'Legacy').' -> '.$type]
                    = ($types[($oldType!=='' ? $oldType : 'Legacy').' -> '.$type] ?? 0)+1;
            }
        }
    }
}
