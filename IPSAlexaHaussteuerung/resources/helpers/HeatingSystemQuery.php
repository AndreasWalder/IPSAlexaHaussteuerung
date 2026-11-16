<?php
declare(strict_types=1);

final class IAHHeatingSystemQuery
{
    public const PROVIDER = 'ObjectTreeHeating';
    public const KEY = 'heating_system';
    public const TITLES = [
        'anlage' => 'Heizungsanlage',
        'fernwaerme' => 'Fernwärme',
        'warmwasser' => 'Warmwasser',
        'solarheizung' => 'Solarheizung',
        'hausheizung' => 'Hausheizung',
        'dachrinnenheizung' => 'Dachrinnenheizung',
    ];
    private const OVERVIEW = [
        'fern_status', 'fern_heute', 'haus_vorlauf', 'haus_pumpe',
        'warm_temp', 'solar_leistung', 'dach_ost_status', 'dach_west_status', 'dach_rinne_status', 'dach_rohr_status',
    ];

    private static function normalize(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/u', ' ', strtr(mb_strtolower($text, 'UTF-8'), [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        ])) ?? '');
    }

    public static function topic(string $text): ?string
    {
        $q = self::normalize($text);
        if (!preg_match('/^(?:und )?(?:wie|wieviel|welche\w*|was|wo|wann|seit wann|warum|ist|sind|hat|'
            . 'laufen?|laeuft|status|details|uebersicht|zeige?|sage? mir|gibt es|zaehle|alle (?:werte|details))\b/', $q)
            && !preg_match('/^(?:heizung(?:sanlage)?|fernwaerme|warmwasser|solarheizung|hausheizung|'
                . 'dachrinnenheizung) (?:status|details|uebersicht)$/D', $q)) {
            return null;
        }
        // Dach-Rohrheizung muss ausdrücklich vom Bewässerungs-Heizband unterschieden werden.
        if (preg_match('/\b(?:bewaesser\w*|gartenpumpe|pumpenschacht|photovoltaik\w*|pv(?: anlage)?|pvanlage)\b/', $q)) {
            return null;
        }
        return match (true) {
            preg_match('/\b(?:dach ?rinnen? ?heizung|dach ?flaechen ?heizung|dach ?heizung|rohr ?begleit ?heizung (?:am|auf dem|vom) dach)\b/', $q) === 1 => 'dachrinnenheizung',
            preg_match('/\b(?:solar ?thermie|solar ?heizung|solar ?waerme|solar ?kreis\w*|solar ?pumpen?|solar ?ventil\w*|solar ?kollektor\w*|kollektor\w*)\b/', $q) === 1 => 'solarheizung',
            preg_match('/\b(?:warm ?wasser\w*|boiler\w*|speicher (?:oben|mitte|unten)|desinfektion)\b/', $q) === 1 => 'warmwasser',
            preg_match('/\b(?:fern ?waerme\w*|danfoss)\b/', $q) === 1 => 'fernwaerme',
            preg_match('/\b(?:haus ?heizung|fussboden ?heizung|fussboden ?ventil\w*|fussboden ?pumpe\w*|fussboden ?vorlauf|heizkreis\w*|heizkoerper ?stellgroesse)\b/', $q) === 1 => 'hausheizung',
            preg_match('/\b(?:heizungs ?anlage|heizung|heizungswerte)\b/', $q) === 1
                && !preg_match('/\b(?:im|in|auf der|in der) [a-z]/', $q) => 'anlage',
            default => null,
        };
    }

    public static function matchesVariable(array $metric): bool
    {
        $id = (int)($metric['id'] ?? 0);
        if ($id <= 0 || !IPS_VariableExists($id)) return false;
        $object = IPS_GetObject($id);
        $variable = IPS_GetVariable($id);
        return (int)($object['ParentID'] ?? -1) === ($metric['parent'] ?? null)
            && (string)($object['ObjectIdent'] ?? '') === ($metric['ident'] ?? null)
            && (int)($variable['VariableType'] ?? -1) === ($metric['type'] ?? null);
    }

    private static function number(float $value, int $digits = 1): string
    {
        $formatted = number_format($value, $digits, ',', '');
        return $digits > 0 ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }

    private static function reading(array $metric): array
    {
        $missing = ['value' => 'Wert fehlt', 'valid' => false];
        try {
            if (!self::matchesVariable($metric)) return $missing;
            $id = $metric['id'];
            $raw = GetValue($id);
            if (is_bool($raw)) {
                $value = ($metric['key'] ?? '') === 'haus_pumpe_sperre'
                    ? ($raw ? 'Gesperrt' : 'Entriegelt') : ($raw ? 'An' : 'Aus');
                return ['value' => $value, 'valid' => true, 'raw' => $raw];
            }
            if ((!is_int($raw) && !is_float($raw)) || !is_finite((float)$raw)) return $missing;
            $unit = $metric['unit'];
            if ($unit === '°C' && ($raw < -60 || $raw > 180)) {
                return ['value' => 'Messwert unplausibel', 'valid' => false];
            }
            if ($unit === '%' && ($raw < 0 || $raw > 100)) {
                return ['value' => 'Messwert unplausibel', 'valid' => false];
            }
            $formatted = '';
            if ($unit === '' && function_exists('GetValueFormatted')) {
                $formatted = trim(strip_tags(html_entity_decode(GetValueFormatted($id), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $formatted = mb_substr($formatted, 0, 90, 'UTF-8');
            }
            if ($unit === '') {
                $value = $formatted !== '' && !is_numeric($formatted) ? $formatted : 'Code ' . $raw;
            } elseif ($unit === 's') {
                $value = self::number($raw / 3600) . ' h';
            } else {
                $digits = in_array($unit, ['kWh', 'kW', '€', 'm³'], true) ? 2 : 1;
                $value = self::number((float)$raw, $digits) . ' ' . $unit;
            }
            return ['value' => $value, 'valid' => true, 'raw' => $raw];
        } catch (Throwable $e) {
            return $missing;
        }
    }

    /** A dimension-specific question selects its source before any values are read. */
    private static function selection(string $topic, string $q): array
    {
        $isStatus = preg_match('/\b(?:status|laeuft|laufen|aktiv|eingeschaltet|an|aus)\b/', $q) === 1;
        $target = match (true) {
            $topic === 'dachrinnenheizung' => match (true) {
                str_contains($q, 'rohr') => 'dach_rohr_',
                str_contains($q, 'dachflaechen') || str_contains($q, 'dach flaechen') => match (true) {
                    str_contains($q, 'ost') => 'dach_ost_',
                    str_contains($q, 'west') => 'dach_west_',
                    default => 'dach_',
                },
                str_contains($q, 'west') => 'dach_rinne_',
                default => 'dach_',
            },
            $topic === 'solarheizung' && str_contains($q, 'balkon') => 'balkon',
            $topic === 'solarheizung' && preg_match('/\b(?:ost|west|dach)\b/', $q, $part) === 1 => $part[0],
            default => '',
        };
        return match ($topic) {
            'fernwaerme' => match (true) {
                preg_match('/leistung|\bkw\b/', $q) === 1 => ['fern_leistung'],
                str_contains($q, 'gestern') => ['fern_gestern'],
                str_contains($q, 'heute') && preg_match('/verbrauch|bezug|\bkwh\b|wie viel|wieviel/', $q) => ['fern_heute'],
                preg_match('/verbrauch|energie|zaehler|bezug/', $q) === 1 => ['fern_heute', 'fern_gestern', 'fern_energie'],
                str_contains($q, 'volumen') => ['fern_volumen'],
                str_contains($q, 'vorlauf') => ['fern_vorlauf'],
                str_contains($q, 'ruecklauf') => ['fern_ruecklauf'],
                str_contains($q, 'aussen') => ['aussen', 'aussen_min', 'aussen_max'],
                preg_match('/betriebsart|modus/', $q) === 1 => ['fern_modus'],
                $isStatus => ['fern_status', 'fern_modus'],
                default => [],
            },
            'warmwasser' => match (true) {
                str_contains($q, 'desinfektion') => ['warm_des_*'],
                str_contains($q, 'oben') => ['warm_oben'],
                str_contains($q, 'mitte') => ['warm_mitte'],
                str_contains($q, 'unten') => ['warm_unten'],
                str_contains($q, 'komfort') => ['warm_komfort'],
                str_contains($q, 'absenk') => ['warm_absenkung'],
                preg_match('/betriebsart|modus/', $q) === 1 => ['warm_modus'],
                $isStatus => ['warm_status'],
                preg_match('/temperatur|wie warm|wie heiss/', $q) === 1 => ['warm_temp'],
                default => [],
            },
            'solarheizung' => match (true) {
                preg_match('/durchfluss|volumenstrom/', $q) === 1 => ['solar_durchfluss_stunde', 'solar_durchfluss'],
                str_contains($q, 'leistung') => ['solar_leistung'],
                preg_match('/euro|tagesertrag/', $q) === 1 => ['solar_euro'],
                preg_match('/energie|ertrag|\bkwh\b/', $q) === 1 => ['solar_energie'],
                preg_match('/vorlauf|ruecklauf/', $q, $direction) === 1 => [
                    'solar_' . ($target === '' ? '' : ($target === 'balkon' ? 'balkon_' : 'dach_')) . $direction[0],
                ],
                str_contains($q, 'pumpe') => match ($target) {
                    'balkon' => ['solar_balkon_pumpe'],
                    'dach', 'ost', 'west' => ['solar_dach_pumpe'],
                    default => ['solar_dach_pumpe', 'solar_balkon_pumpe'],
                },
                str_contains($q, 'ventil') => match ($target) {
                    'ost', 'west' => ['solar_' . $target . '_ventil'],
                    default => ['solar_ost_ventil', 'solar_west_ventil'],
                },
                str_contains($q, 'kollektor') => match ($target) {
                    'ost', 'west', 'balkon' => ['solar_' . $target],
                    default => ['solar_ost', 'solar_west', 'solar_balkon'],
                },
                $isStatus => ['solar_leistung', 'solar_dach_pumpe', 'solar_balkon_pumpe'],
                default => [],
            },
            'hausheizung' => match (true) {
                str_contains($q, 'ventil') => ['ventil_*'],
                str_contains($q, 'heizkoerper') => ['radiator_*'],
                str_contains($q, 'sperr') => ['haus_pumpe_sperre'],
                str_contains($q, 'pumpe') => ['haus_pumpe'],
                preg_match('/fussboden ?vorlauf/', $q) === 1 => ['haus_fussboden_vorlauf'],
                str_contains($q, 'vorlauf') => ['haus_vorlauf', 'haus_vorlauf_soll'],
                str_contains($q, 'ruecklauf') => ['haus_ruecklauf', 'haus_ruecklauf_soll'],
                str_contains($q, 'komfort') => ['haus_komfort'],
                str_contains($q, 'absenk') => ['haus_absenkung'],
                $isStatus => ['haus_pumpe', 'haus_vorlauf', 'ventil_*'],
                default => [],
            },
            'dachrinnenheizung' => match (true) {
                preg_match('/strom|\bma\b/', $q) === 1 => [$target . '*_strom'],
                preg_match('/betriebszaehler|betriebsstund|betriebszeit|laufzeit/', $q) === 1 => [$target . '*_zeit'],
                $isStatus => [$target . '*_status'],
                $target !== 'dach_' => [$target . '*'],
                default => [],
            },
            default => [],
        };
    }

    private static function matchesSelection(string $key, array $selection): bool
    {
        if ($selection === []) return true;
        foreach ($selection as $pattern) {
            if ($key === $pattern) return true;
            if (str_contains($pattern, '*')) {
                [$prefix, $suffix] = explode('*', $pattern, 2);
                if (str_starts_with($key, $prefix) && str_ends_with($key, $suffix)) return true;
            }
        }
        return false;
    }

    public static function query(array $catalog, string $text): ?array
    {
        $topic = self::topic($text);
        if ($topic === null) return null;
        $source = $catalog['dataSources'][self::KEY] ?? null;
        $result = [
            'handled' => true, 'localHouseQuery' => true, 'answerSource' => 'heating_' . $topic,
            'displayRows' => [],
        ];
        if (!is_array($source) || ($source['provider'] ?? '') !== self::PROVIDER
            || ($source['domain'] ?? '') !== 'heizung' || ($source['kiEnabled'] ?? false) !== true) {
            return $result + ['answer' => 'Im RoomsCatalog ist noch keine lesbare Heizungsanlage veröffentlicht.'];
        }

        $q = self::normalize($text);
        $historical = preg_match('/\b(?:wann|zuletzt|wie oft|seit wann|verlauf|historie|letzte\w*)\b/', $q) === 1;
        $allDetails = preg_match('/\b(?:alle (?:werte|details|messwerte)|detailansicht|details)\b/', $q) === 1;
        $openValves = $topic === 'hausheizung' && preg_match('/\b(?:fussboden ?)?ventil\w*\b/', $q) === 1
            && preg_match('/offen|geoeffnet/', $q) === 1;
        if ($historical && !str_contains($q, 'desinfektion')) {
            return $result + ['answer' => 'Für diese Heizungsfrage ist noch keine Archiv-Auswertung eingerichtet.'];
        }
        if (preg_match('/\b(?:stoerung\w*|fehlercode\w*|alarm\w*|kosten|heizzeit\w*|wochenplan|warum)\b/', $q)
            && !($topic === 'solarheizung' && str_contains($q, 'tagesertrag'))) {
            return $result + ['answer' => 'Diese Heizungsfrage ist noch keinem eindeutig zugeordneten Wert hinterlegt.'];
        }

        $selection = $allDetails ? [] : self::selection($topic, $q);
        if ($selection === ['fern_leistung']) {
            return $result + ['answer' => 'Für die momentane Fernwärmeleistung ist noch kein Messwert in Kilowatt zugeordnet.'];
        }
        if ($topic === 'solarheizung' && preg_match('/\b(?:heute|gestern|wochen|monat)\b/', $q)
            && preg_match('/waerme|energie|ertrag|\bkwh\b/', $q)
            && !in_array($selection, [['solar_euro'], ['solar_leistung']], true)
            && !$allDetails) {
            return $result + ['answer' => 'Für die Solarwärme ist der Bezugszeitraum des Kilowattstundenwerts noch ungeklärt.'];
        }
        if ($topic === 'anlage' && !$allDetails) $selection = self::OVERVIEW;
        $rows = [];
        $spoken = [];
        $ventils = [];
        $readableValveCount = 0;
        foreach ((array)($source['metrics'] ?? []) as $key => $metric) {
            if (!is_array($metric) || !isset(self::TITLES[$metric['group'] ?? ''])
                || ($metric['enabled'] ?? false) !== true) continue;
            if ($topic !== 'anlage' && $topic !== $metric['group']) continue;
            if (!self::matchesSelection($key, $selection)) continue;
            $reading = self::reading($metric + ['key' => $key]);
            if (str_starts_with($key, 'ventil_') && $reading['valid']) {
                $readableValveCount++;
                if ($reading['raw'] > 0) $ventils[] = $metric['label'];
            }
            if ($openValves && (!$reading['valid'] || $reading['raw'] <= 0)) continue;
            $label = $metric['label'];
            $rows[$key] = ['label' => $label, 'value' => $reading['value']];
            if ($reading['valid']) $spoken[$key] = $label . ' ' . $reading['value'];
        }
        if ($topic === 'anlage' && !$allDetails) {
            $rows = array_replace(array_intersect_key(array_fill_keys(self::OVERVIEW, null), $rows), $rows);
        }
        $result['displayRows'] = array_values($rows);
        if ($openValves) {
            $count = count($ventils);
            $result['answer'] = $readableValveCount === 0 ? 'Die Stellgrößen der Fußbodenventile sind nicht verfügbar.'
                : ($count === 0 ? 'Alle lesbaren Fußbodenventile haben eine Stellgröße von null Prozent.'
                    : $count . ' Fußbodenventile werden angesteuert. ' . implode(', ', array_slice(
                        array_map(static fn(string $label): string => str_replace('Fußbodenventil ', '', $label), $ventils), 0, 4)) . '.');
            return $result;
        }
        $validCount = count($spoken);
        if ($rows === []) {
            return $result + ['answer' => 'Für diese Frage ist noch kein passender Heizungswert zugeordnet.'];
        }
        if ($validCount === 0) {
            $message = count($rows) === 1 ? reset($rows)['value'] : 'Die angefragten Messwerte sind nicht verfügbar';
            return $result + ['answer' => $message . '.'];
        }
        $summary = [];
        if ($topic === 'anlage') {
            foreach (['fern_status', 'warm_temp', 'haus_vorlauf', 'solar_leistung'] as $key) {
                if (isset($spoken[$key])) $summary[] = $spoken[$key];
            }
        }
        if ($summary === []) $summary = array_slice(array_values($spoken), 0, 4);
        $answer = implode('. ', $summary) . '.';
        if ($validCount > count($summary) && ($allDetails || $topic !== 'anlage')) {
            $answer .= ' Die weiteren Werte stehen in der Übersicht.';
        }
        return $result + ['answer' => $answer];
    }
}
