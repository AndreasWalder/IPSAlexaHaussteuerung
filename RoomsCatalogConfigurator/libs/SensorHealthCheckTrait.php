<?php
declare(strict_types=1);

/**
 * Read-only, bounded health heuristics for RoomsCatalog variable inspection.
 * Statuses are observations, not assertions that a physical sensor is broken.
 * No archive writes, IPS_SetValue, scripts or device actions.
 */
trait RCCSensorHealthCheckTrait
{
    private function rccHealthProfile(array $point,array $var,string $field): array
    {
        $role=strtolower((string)($point['role']??''));
        $profile=strtolower((string)(($var['VariableCustomProfile']??'')
            ?:($var['VariableProfile']??'')));
        $caption=strtolower($field.' '.$role.' '.$profile.' '.(string)($point['label']??''));
        $seasonal=!empty($point['seasonal'])
            || preg_match('/(?:winter|frost|rohrbegleit|irrigation|bewaesser|bewässer|sprinkler|zone|ventil|pump)/iu',$caption)===1;
        $isBattery=preg_match('/(?:battery|batterie|akku|ladung)/iu',$caption)===1;
        $isHumidity=preg_match('/(?:humidity|feucht|moisture)/iu',$caption)===1;
        $isTemperature=preg_match('/(?:temperature|temperatur)/iu',$caption)===1;
        $isSwitch=(int)($var['VariableType']??-1)===0
            || preg_match('/(?:status|switch|schalt|manual|manuell|automatic|automatik|relai|aktiv)/iu',$caption)===1;
        $numeric=in_array((int)($var['VariableType']??-1),[1,2],true);
        $continuous=$numeric && !$isSwitch && ($isTemperature || $isHumidity
            || $isBattery || preg_match('/(?:pressure|druck|leistung|power|voltage|spannung)/iu',$caption)===1);
        return ['seasonal'=>(bool)$seasonal,'battery'=>(bool)$isBattery,
            'humidity'=>(bool)$isHumidity,'temperature'=>(bool)$isTemperature,
            'continuous'=>(bool)$continuous,'switch'=>(bool)$isSwitch];
    }

    /**
     * $archiveBudget is shared by the entire inspection run. Use at most
     * 30 daily aggregation calls across all selected devices.
     */
    private function rccInspectSensorHealth(
        int $id,array $var,array $point,string $field,
        int $archiveId,array $archiveMap,int $now,int &$archiveBudget
    ): array {
        $findings=[];$meta=$this->rccHealthProfile($point,$var,$field);
        $updated=(int)($var['VariableUpdated']??0);
        $changed=(int)($var['VariableChanged']??0);
        $expected=(int)($point['expectedIntervalSeconds']??$point['maxAgeSeconds']??0);
        $expected=max(0,min($expected,365*86400));
        // VariableUpdated is a receiving timestamp, unlike VariableChanged.
        if ($expected>0 && $updated>0 && $now-$updated>$expected) {
            $findings[]=['severity'=>$meta['seasonal']?'Hinweis':'Prüfen',
                'message'=>'Letzte Variablenaktualisierung '
                    .date('d.m.Y H:i',$updated).' ('.(int)round(($now-$updated)/3600)
                    .' Stunden). Konfiguriertes Intervall '.$expected
                    .' s überschritten. '.($meta['seasonal']
                        ?'Saison und Anlagenbetrieb berücksichtigen.'
                        :'Sensorverbindung und Abfragezyklus kontrollieren.')];
        }
        // No timestamp in older/custom modules: insufficient evidence,
        // not a defective sensor. Do not flood the result with warnings.

        // Value checks only for recognized, physically meaningful quantities.
        // Never operate on an actuator based on these observations.
        if ($meta['continuous'] && function_exists('GetValue')) {
            try {
                $raw=GetValue($id);
                if (is_int($raw)||is_float($raw)) {
                    $value=(float)$raw;
                    if (!is_finite($value)) {
                        $findings[]=['severity'=>'Prüfen',
                            'message'=>'Messwert ist nicht endlich (NaN/Inf). Format/Quelle prüfen.'];
                    } else {
                        $min=isset($point['healthMin'])&&is_numeric($point['healthMin'])
                            ?(float)$point['healthMin']:null;
                        $max=isset($point['healthMax'])&&is_numeric($point['healthMax'])
                            ?(float)$point['healthMax']:null;
                        if (($min!==null&&$value<$min)||($max!==null&&$value>$max)) {
                            $findings[]=['severity'=>'Prüfen',
                                'message'=>'Aktueller Wert '.round($value,3)
                                    .' liegt außerhalb des konfigurierten Prüfbereichs'
                                    .' ('.($min??'offen').' bis '.($max??'offen').').'];
                        }
                        $unit=strtolower((string)($point['unit']??''));
                        $profile=strtolower((string)(($var['VariableCustomProfile']??'')
                            ?:($var['VariableProfile']??'')));
                        if (($meta['battery']||$meta['humidity'])
                            && ($unit==='%'||str_contains($profile,'intensity')
                                ||str_contains($profile,'battery'))) {
                            if ($value<0||$value>100) {
                                $findings[]=['severity'=>'Prüfen',
                                    'message'=>'Prozentwert außerhalb 0–100. Profil/Skalierung prüfen.'];
                            } elseif ($meta['battery']&&$value<15) {
                                $findings[]=['severity'=>'Prüfen',
                                    'message'=>'Batteriestand '.round($value,1)
                                        .' %. Batteriewechsel bzw. Ladezustand prüfen.'];
                            } elseif ($meta['battery']&&$value<25) {
                                $findings[]=['severity'=>'Hinweis',
                                    'message'=>'Batteriestand '.round($value,1)
                                        .' %. Entwicklung beobachten.'];
                            }
                        }
                    }
                }
            }catch(\Throwable $e) {
                $findings[]=['severity'=>'Hinweis',
                    'message'=>'Aktueller Wert nicht lesbar: '.$e->getMessage()];
            }
        }

        // A constant archived value is NOT proof of a stuck sensor.
        // Only inspect enabled archives of active non-seasonal analog sensors.
        if ($archiveId>0 && $archiveBudget>0 && $meta['continuous']
            && !$meta['seasonal'] && isset($archiveMap[$id])
            && function_exists('AC_GetAggregatedValues')) {
            $archiveBudget--;
            try {
                $days=AC_GetAggregatedValues($archiveId,$id,1,$now-7*86400,$now,8);
                $valid=[];
                if(is_array($days))foreach($days as $row) {
                    if(!is_array($row)
                        || !isset($row['Min'],$row['Max'])
                        || !is_numeric($row['Min'])||!is_numeric($row['Max']))continue;
                    // Skip days with no actual samples when the aggregation provides Count.
                    if (isset($row['Count']) && (int)$row['Count']===0)continue;
                    $valid[]=$row;
                }
                if(count($valid)>=4) {
                    $min=min(array_map(static fn($r)=>(float)$r['Min'],$valid));
                    $max=max(array_map(static fn($r)=>(float)$r['Max'],$valid));
                    $epsilon=(isset($point['healthMinVariation'])
                        && is_numeric($point['healthMinVariation']))
                        ?max(0.0,(float)$point['healthMinVariation']):0.0;
                    // Without an explicit variation threshold a flat
                    // seven-day trend is only a low-confidence observation.
                    if ($max-$min<=$epsilon
                        && $meta['temperature'] && $updated>=$now-86400) {
                        $findings[]=['severity'=>'Hinweis',
                            'message'=>'Archiv über mindestens vier Tage fast konstant ('
                                .round($min,3).'–'.round($max,3).'). '
                                .'Kann ein stabiler Messort sein; Sensor mit Referenzwert vergleichen. '
                                .'Archiv protokolliert normalerweise nur Wertänderungen.'];
                    }
                }
            }catch(\Throwable $e) {
                $findings[]=['severity'=>'Hinweis',
                    'message'=>'Trendvergleich nicht möglich: '.$e->getMessage()];
            }
        }
        return $findings;
    }
}
