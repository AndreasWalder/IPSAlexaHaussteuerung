<?php
declare(strict_types=1);

/** Home Connect uses installed instance identities and live metadata, never inferred object IDs. */
final class IAHHomeConnect
{
    public const MODULE_ID = '{F29DF312-A62E-9989-1F1A-0D1E1D171AD3}';

    public static function norm(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/u', ' ', strtr(mb_strtolower($s),
            ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'])) ?? '');
    }

    public static function recognizes(string $text): bool
    {
        return preg_match('/\b(?:home connect|hausgeraet\w*|haushaltsgeraet\w*|waschmaschine\w*|waschtrockner\w*|waesche|geschirrspuel\w*|spuelmaschine|'
            . 'kaffee\w*|mikrowelle|mi[ck]ro ?back ?ofen|back ?ofen|dampf ?back ?ofen|dampfgarer|kochfeld)\b/', self::norm($text)) === 1;
    }

    public static function validDevice(int $id): bool
    {
        return $id > 0 && IPS_InstanceExists($id)
            && strtoupper((string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '')) === self::MODULE_ID;
    }

    public static function source(int $root, array $settings): array
    {
        $devices = [];
        $ids = IPS_GetInstanceListByModuleID(self::MODULE_ID);
        sort($ids, SORT_NUMERIC);
        foreach (array_slice($ids, 0, 100) as $id) {
            $id = (int)$id;
            if (!self::validDevice($id) || ($root > 0 && !self::below($id, $root))) continue;
            $object = IPS_GetObject($id);
            $type = (string)IPS_GetProperty($id, 'DeviceType');
            $name = self::clean((string)$object['ObjectName']);
            $aliases = match ($type) {
                'WasherDryer' => ['Waschtrockner', 'Waschmaschine', 'Wäsche', 'Waschen und Trocknen'],
                'Washer' => ['Waschmaschine', 'Wäsche'],
                'Dryer' => ['Trockner', 'Wäschetrockner'],
                'Dishwasher' => ['Geschirrspüler', 'Spülmaschine'],
                'CoffeeMaker' => ['Kaffeevollautomat', 'Kaffeemaschine', 'Kaffeeautomat'],
                'Hob' => ['Kochfeld', 'Herdplatte', 'Induktionsfeld'],
                'Oven' => preg_match('/micro|mikro|mikrowelle/i', $name)
                    ? ['Mikrowelle', 'Mikro Backofen', 'Micro Backofen', 'Backofen']
                    : (preg_match('/dampf|steam/i',$name) ? ['Dampf Backofen', 'Dampfbackofen', 'Dampfgarer', 'Backofen'] : ['Backofen','Ofen']),
                default => [],
            };
            $custom = (array)($settings[(string)$id] ?? []);
            $extra = preg_split('/[,;\n]+/', (string)($custom['aliases'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $devices[(string)$id] = ['id'=>$id, 'name'=>$name, 'type'=>$type,
                'parent'=>(int)$object['ParentID'],
                'aliases'=>array_values(array_unique(array_merge([$name], $aliases, array_map('trim', $extra)))),
                'enabled'=>($custom['enabled'] ?? true) === true,
                'allowControl'=>($custom['allowControl'] ?? true) === true];
        }
        return ['provider'=>'HomeConnect', 'kiEnabled'=>true, 'domain'=>'geraete', 'devices'=>$devices];
    }

    private static function below(int $id, int $root): bool
    {
        if (!IPS_ObjectExists($root)) return false;
        for ($n = 0; $n < 32 && $id > 0; $n++) {
            if ($id === $root) return true;
            $id = (int)IPS_GetObject($id)['ParentID'];
        }
        return false;
    }

    public static function variables(int $device): array
    {
        if (!self::validDevice($device)) return [];
        $rows = [];
        $queue = array_map(static fn($id): array => [(int)$id, 0], IPS_GetChildrenIDs($device));
        $seen = [];
        while ($queue !== [] && count($seen) < 500) {
            [$id, $depth] = array_pop($queue);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            try {
                $o = IPS_GetObject($id);
                if ((int)$o['ObjectType'] === 0 && $depth < 8) {
                    foreach (IPS_GetChildrenIDs($id) as $child) $queue[] = [(int)$child, $depth + 1];
                    continue;
                }
                if ((int)$o['ObjectType'] !== 2) continue;
                $ident = (string)($o['ObjectIdent'] ?? '');
                if ($ident === '' || preg_match('/password|token|secret|cookie|credential/i', $ident)) continue;
                $v = IPS_GetVariable($id);
                $profile = (string)($v['VariableCustomProfile'] ?? '') ?: (string)($v['VariableProfile'] ?? '');
                $p = $profile !== '' && IPS_VariableProfileExists($profile) ? IPS_GetVariableProfile($profile) : [];
                $action = (int)($v['VariableCustomAction'] ?? 0);
                if ($action === 0) $action = (int)($v['VariableAction'] ?? 0);
                $row = ['id'=>$id, 'ident'=>$ident, 'name'=>self::clean((string)$o['ObjectName']),
                    'parent'=>(int)$o['ParentID'], 'type'=>(int)$v['VariableType'], 'action'=>$action > 1,
                    'updated'=>(int)($v['VariableUpdated'] ?? 0), 'profile'=>$p];
                try {
                    $raw = GetValue($id);
                    if (!(is_scalar($raw) && (!is_float($raw) || is_finite($raw)))
                        || (is_string($raw) && (strlen($raw) > 2048 || preg_match('~https?://|<[^>]+>~i', $raw)))) continue;
                    $row['raw'] = $row['updated'] > 0 ? $raw : null;
                } catch (Throwable $e) { $row['raw'] = null; }
                if (isset($rows[$ident])) {
                    // Duplicate idents in a device subtree are ambiguous and cannot be controlled.
                    $rows[$ident]['ambiguous'] = true;
                } else $rows[$ident] = $row;
            } catch (Throwable $e) {}
        }
        return $rows;
    }

    public static function clean(string $text): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/', ' ', strip_tags($text)) ?? ''), 0, 240);
    }

    public static function forDevice(array $device): array
    {
        $live=self::variables((int)$device['id']);
        if (!array_key_exists('variables',$device)) return $live;
        $rows=[];
        foreach ((array)$device['variables'] as $semantic=>$m) {
            if (empty($m['enabled'])) continue;
            $actual=$live[(string)($m['ident']??'')]??null;
            if ($actual===null||$actual['id']!==($m['id']??null)||$actual['parent']!==($m['parent']??null)||$actual['type']!==($m['type']??null)) continue;
            $actual['name']=(string)$m['title']; $actual['action']=$actual['action']&&!empty($m['writable']);
            $rows[(string)$semantic]=$actual;
        }
        return $rows;
    }

    public static function display(array $v): string
    {
        $raw = $v['raw'] ?? null;
        if ($raw === null) return 'Noch kein Wert';
        if (is_bool($raw)) return $raw ? 'Ja' : 'Nein';
        $ident = $v['ident'];
        if (str_contains($ident, 'ProgramTime') || in_array($ident, ['OptionDuration','AlarmClock','OptionStartInRelative','OptionFinishInRelative'], true)) {
            if (is_numeric($raw)) {
                $minutes = (int)ceil((float)$raw / 60);
                return $minutes >= 60 ? intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min' : $minutes . ' min';
            }
        }
        foreach ((array)($v['profile']['Associations'] ?? []) as $a) {
            if (($a['Value'] ?? null) === $raw && (string)($a['Name'] ?? '') !== '') return self::clean((string)$a['Name']);
        }
        if (is_numeric($raw)) {
            $unit = trim((string)($v['profile']['Suffix'] ?? ''));
            return rtrim(rtrim(number_format((float)$raw, 2, ',', ''), '0'), ',') . ($unit !== '' ? ' ' . $unit : '');
        }
        if ($raw === '') return 'Keine Angabe';
        $last = substr((string)$raw, (int)strrpos('.' . $raw, '.'));
        return match ($last) {
            'Run'=>'Läuft', 'Ready'=>'Bereit', 'Inactive'=>'Inaktiv', 'Finished'=>'Beendet',
            'Pause'=>'Pausiert', 'DelayedStart'=>'Zeitversetzter Start', 'Error'=>'Störung', 'Aborting'=>'Wird abgebrochen',
            'On'=>'Ein', 'Off'=>'Aus', 'Standby'=>'Standby', 'Closed'=>'Geschlossen', 'Open'=>'Offen', 'Locked'=>'Verriegelt',
            'GC30'=>'30 °C', 'GC40'=>'40 °C', 'GC60'=>'60 °C', 'GC90'=>'90 °C',
            default => self::clean(str_replace(['Cooking.Oven.Program.Microwave.', 'BSH.Common.EnumType.',
                'ConsumerProducts.CoffeeMaker.EnumType.', 'LaundryCare.Washer.EnumType.', '.', '_'],
                ['Mikrowelle ', '', '', '', ' ', ' '], (string)$raw)),
        };
    }

    public static function devices(array $catalog): array
    {
        $devices = [];
        foreach ((array)($catalog['dataSources'] ?? []) as $source) {
            if (($source['provider'] ?? '') !== 'HomeConnect' || empty($source['kiEnabled'])) continue;
            foreach ((array)($source['devices'] ?? []) as $d) {
                if (!empty($d['enabled']) && self::validDevice((int)($d['id'] ?? 0))) $devices[(string)$d['id']] = $d;
            }
        }
        return array_values($devices);
    }

    public static function select(array $devices, string $q): array
    {
        $q = self::nameForm($q);
        $matches = []; $best = 0;
        foreach ($devices as $d) {
            $score = 0;
            foreach (array_merge([$d['name']], (array)$d['aliases']) as $alias) {
                $alias = self::nameForm((string)$alias);
                $compact = str_replace(' ', '', $alias);
                if ($compact === '') continue;
                // ASR may join or split compound names, including Micro/Mikro.
                $pattern = implode(' *', str_split($compact));
                if (preg_match('/\b' . $pattern . '(?:s|es)?\b/', $q)) $score = max($score, strlen($compact));
            }
            if ($score > $best) { $best = $score; $matches = [$d]; }
            elseif ($score > 0 && $score === $best) $matches[] = $d;
        }
        if ($matches !== []) return $matches;
        // Conservative spelling tolerance for questions only. Control needs an exact name.
        $words = explode(' ', $q);
        foreach ($devices as $d) {
            foreach (array_merge([$d['name']], (array)$d['aliases']) as $alias) {
                $alias = self::nameForm((string)$alias);
                if (strlen($alias) < 7 || strlen($alias) > 60) continue;
                $n = count(explode(' ', $alias));
                for ($i=0; $i <= count($words)-$n; $i++) {
                    if (levenshtein($alias, implode(' ', array_slice($words,$i,$n))) <= 1) {
                        $d['approximate'] = true; $matches[(string)$d['id']] = $d;
                    }
                }
            }
        }
        return array_values($matches);
    }

    private static function nameForm(string $text): string
    {
        return preg_replace('/\bmicro/', 'mikro', self::norm($text)) ?? '';
    }

    /** Stable numbered choices are carried as data, never parsed from spoken answers. */
    public static function clarification(array $devices): array
    {
        $choices = []; $rows = []; $parts = [];
        foreach (array_slice(array_values($devices), 0, 10) as $i=>$d) {
            $choices[] = ['id'=>(int)$d['id'], 'name'=>(string)$d['name'], 'aliases'=>(array)$d['aliases']];
            $rows[] = ['label'=>(string)($i+1), 'value'=>$d['name']];
            $parts[] = ($i+1) . ': ' . $d['name'];
        }
        return self::response('Hausgeräteauswahl', 'all', $rows,
            'Welches Gerät meinst du? ' . implode('. ', $parts) . '. Sage zum Beispiel das erste oder den Gerätenamen.')
            + ['awaitingDevice'=>true, 'deviceChoices'=>$choices];
    }

    /** null means another topic; zero means a selection reply needing clarification. */
    public static function choiceReply(string $spoken, array $choices): ?int
    {
        $q = self::norm($spoken);
        if (preg_match('/^(?:(?:bitte|nummer|das|der|die|den|dem|geraet|backofen) )*(\d+|eins|ein[se]?|zwei|drei|vier|fuenf|sechs|sieben|acht|neun|zehn|erste[rsnm]?|zweite[rsnm]?|dritte[rsnm]?|vierte[rsnm]?|fuenfte[rsnm]?|sechste[rsnm]?|siebte[rsnm]?|achte[rsnm]?|neunte[rsnm]?|zehnte[rsnm]?)(?: (?:bitte|das|der|die|den|dem|geraet|backofen))*$/D', $q, $m)) {
            $numbers = ['eins','zwei','drei','vier','fuenf','sechs','sieben','acht','neun','zehn'];
            $stems = ['erst','zweit','dritt','viert','fuenft','sechst','siebt','acht','neunt','zehnt'];
            $index = ctype_digit($m[1]) ? (int)$m[1] : (in_array($m[1],['ein','eine'],true)?1:0);
            foreach ($numbers as $i=>$word) {
                if ($m[1] === $word || str_starts_with($m[1], $stems[$i])) { $index=$i+1; break; }
            }
            return (int)($choices[$index-1]['id']??0);
        }
        if ($q === '' || strlen($q)>120 || preg_match('/\b(?:wie|was|wann|wo|welche\w*|ist|sind|schalte|starte|stoppe|stelle|oeffne|schliesse|zurueck|ende|abbrechen|einschalten|ausschalten|starten|stoppen|ein|aus)\b/', $q)) return null;
        $selected = self::select($choices, $q);
        $bare = preg_replace('/^(?:(?:bitte|und|das|der|die|den|dem|mein|meine|meinen|meinem|meiner) )+/', '', $q) ?? $q;
        $bare = preg_replace('/ bitte$/D', '', $bare) ?? $bare;
        $bare = str_replace(' ', '', self::nameForm($bare));
        foreach ($selected as $d) {
            foreach (array_merge([$d['name']], (array)$d['aliases']) as $alias) {
                $alias = str_replace(' ', '', self::nameForm((string)$alias));
                if ($alias === $bare || (strlen($alias)>=7 && levenshtein($alias,$bare)<=1))
                    return count($selected)===1 ? (int)$selected[0]['id'] : 0;
            }
        }
        return null;
    }

    private static function response(string $title, string $kind, array $rows, ?string $answer = null): array
    {
        return ['handled'=>true, 'localHouseQuery'=>true, 'answerSource'=>'home_connect_' . $kind,
            'displayTitle'=>$title, 'displayRows'=>$rows,
            'answer'=>$answer ?? $title . ': ' . implode('. ', array_map(static fn(array $r): string => $r['label'] . ' ' . $r['value'], array_slice($rows, 0, 4))) . '.'];
    }

    public static function query(array $catalog, string $text, bool $allowWrites = false, int $selectedId = 0): ?array
    {
        $q = self::norm($text);
        $devices = self::devices($catalog);
        $selected = self::select($devices, $q);
        if ($selectedId > 0) {
            $selected = array_values(array_filter($devices, static fn($d):bool=>(int)$d['id']===$selectedId));
            $allowWrites = false;
            if ($selected === []) return self::response('Hausgeräte', 'all', [], 'Das ausgewählte Hausgerät ist nicht mehr für Alexa verfügbar.');
        }
        if ($selected === [] && !self::recognizes($q)) return null;
        if ($devices === []) return self::response('Haushaltsgeräte', 'all', [], 'Es sind keine Home-Connect-Geräte für Alexa freigegeben.');
        if ($selected === []) {
            if (preg_match('/\b(?:haushaltsgeraet\w*|home connect)\b/', $q)) {
                $rows = [];
                foreach ($devices as $d) {
                    $vars = self::forDevice($d);
                    $rows[] = ['label'=>$d['name'], 'value'=>isset($vars['OperationState']) ? self::display($vars['OperationState']) : 'Status fehlt'];
                }
                return self::response('Haushaltsgeräte', 'all', $rows);
            }
            return self::response('Haushaltsgeräte', 'all', [], 'Welches Gerät meinst du? ' . implode(', ', array_column($devices, 'name')) . '.');
        }
        if (count($selected) > 1) return self::clarification($selected);
        $d = $selected[0]; $v = self::forDevice($d);
        $kind = match ($d['type']) { 'Washer','WasherDryer','Dryer'=>'laundry', 'Dishwasher'=>'dishwasher', 'CoffeeMaker'=>'coffee', 'Hob'=>'hob', 'Oven','Microwave'=>'oven', default=>'all' };
        if ($d['type'] === 'Microwave' || ($d['type'] === 'Oven' && preg_match('/micro|mikro|mikrowelle/i',$d['name']))) $kind='microwave';
        $command = preg_match('/^(?:bitte )?(?:schalte|schalt|mach|mache|stelle|stell|setze|waehle|starte|start|stoppe|stopp|beende|pausiere|fortsetzen)\b/', $q) === 1;
        if ($command) {
            $answer = !empty($d['approximate']) ? 'Bitte den Gerätenamen für einen Schaltbefehl eindeutig nennen.' : self::command($d, $v, $q, $allowWrites);
            return self::response($d['name'], $kind, [['label'=>'Auftrag', 'value'=>$answer]], $answer)
                + ['isCommand'=>true];
        }
        $active = in_array($v['OperationState']['raw'] ?? '', [
            'BSH.Common.EnumType.OperationState.Run','BSH.Common.EnumType.OperationState.Pause','BSH.Common.EnumType.OperationState.DelayedStart'], true);
        $wanted = match (true) {
            preg_match('/alle werte|details|alle details|alles ueber/', $q) === 1 => array_keys($v),
            preg_match('/restzeit|wie lange|wie lang|wann.*fertig|wie viel.*zeit|wieviel.*zeit|braucht.*noch|dauert/', $q) === 1 => ['OperationState','RemainingProgramTime','ProgramProgress'],
            preg_match('/welche.*programm|programme.*verfuegbar|was.*programme/', $q) === 1 => ['SelectedProgram'],
            preg_match('/temperatur|wie warm|wie heiss/', $q) === 1 => ['CurrentCavityTemperature','OptionSetpointTemperature','OptionTemperature','OptionCoffeeTemperature','OptionHotWaterTemperature'],
            preg_match('/tuer|offen|verriegelt/', $q) === 1 => ['DoorState'],
            preg_match('/fernstart|fernsteuer|fernbedien/', $q) === 1 => ['RemoteControlActive','RemoteControlStartAllowed','LocalControlActive'],
            preg_match('/zaehler|wie viele|wieviele|bezug|getraenke/', $q) === 1 => array_values(array_filter(array_keys($v), static fn(string $i): bool => str_starts_with($i, 'BeverageCounter'))),
            preg_match('/stoerung|meldung|alarm|wasser.*leer|bohnen|tropfschale/', $q) === 1 => ['OperationState','Event','EventDescription'],
            preg_match('/schleuder|drehzahl|umdreh/', $q) === 1 => ['OptionSpinSpeed'],
            preg_match('/staerke|stark|menge|fuellmenge/', $q) === 1 => ['OptionBeanAmount','OptionFillQuantity'],
            default => ['OperationState','PowerState','ActiveProgram','SelectedProgram','RemainingProgramTime','ProgramProgress','CurrentCavityTemperature','DoorState','Event'],
        };
        $rows = [];
        foreach ($wanted as $ident) {
            if (!isset($v[$ident])) continue;
            if (in_array($ident, ['Control','UseDuration'], true)) continue;
            if ($ident === 'SelectedProgram' && preg_match('/welche.*programm|programme.*verfuegbar|was.*programme/', $q)) {
                $names = array_column((array)($v[$ident]['profile']['Associations'] ?? []), 'Name');
                $rows[] = ['label'=>'Verfügbare Programme', 'value'=>$names !== [] ? implode(', ', $names) : 'Noch keine Programmliste vorhanden'];
                continue;
            }
            if (in_array($ident, ['RemainingProgramTime','ElapsedProgramTime','ProgramProgress'], true) && !$active) continue;
            if (in_array($ident, ['Event','EventDescription','ActiveProgram'], true) && ($v[$ident]['raw'] ?? '') === '') continue;
            $label = $ident === 'SelectedProgram' ? 'Ausgewähltes Programm' : $v[$ident]['name'];
            $rows[] = ['label'=>$label, 'value'=>self::display($v[$ident])];
        }
        if ($rows === []) $rows[] = ['label'=>'Information', 'value'=>'Dieser Wert wird vom Gerät noch nicht bereitgestellt'];
        if (!$active && preg_match('/restzeit|wie lang|wann.*fertig|braucht.*noch|dauert/', $q)) {
            $rows[] = ['label'=>'Restzeit', 'value'=>'Aktuell kein laufendes Programm gemeldet'];
        }
        $updated = (int)($v['OperationState']['updated'] ?? 0);
        if ($updated > 0 && time() - $updated > 24 * 60 * 60) $rows[] = ['label'=>'Letzte Statusmeldung', 'value'=>date('d.m.Y H:i', $updated)];
        if (($v['Connected']['raw'] ?? true) === false) array_unshift($rows, ['label'=>'Verbindung', 'value'=>'Gerät offline – gespeicherte Werte']);
        $answer = null;
        if (preg_match('/\bfertig\b/', $q) && !$active) {
            $finished = ($v['Event']['raw'] ?? '') === 'BSH.Common.Event.ProgramFinished';
            $answer = $d['name'] . ' meldet ' . (isset($v['OperationState']) ? self::display($v['OperationState']) : 'keinen Betriebsstatus') . '. '
                . ($finished ? 'Die letzte Fertigmeldung ist vom ' . date('d.m. H:i', (int)$v['Event']['updated']) . '.'
                    : 'Eine erfolgreiche Programmbeendigung ist aktuell nicht durch eine Fertigmeldung bestätigt.');
        }
        return self::response($d['name'], $kind, $rows, $answer);
    }

    private static function command(array $d, array $v, string $q, bool $allow): string
    {
        if (!$allow || empty($d['allowControl'])) return 'Die Sprachsteuerung für dieses Home-Connect-Gerät ist deaktiviert.';
        if ($d['type'] === 'Hob') return 'Das Kochfeld wird über Alexa nur abgefragt. Kochzonen werden nicht ferngestartet.';
        if ((int)(IPS_GetInstance($d['id'])['InstanceStatus'] ?? 0) !== 102 || ($v['Connected']['raw'] ?? true) === false) return 'Das Gerät ist aktuell nicht erreichbar.';
        // Some appliance types expose RemoteStartAllowed but no RemoteControlActive variable.
        // Enforce every provided restriction; require an explicit positive allowance either way.
        $remote = isset($v['RemoteControlActive']) ? ($v['RemoteControlActive']['raw'] ?? null)
            : ($v['RemoteControlStartAllowed']['raw'] ?? null);
        if ($remote !== true) return 'Bitte die Fernbedienung direkt am Gerät aktivieren.';
        if (($v['LocalControlActive']['raw'] ?? false) === true) return 'Das Gerät wird gerade lokal bedient.';
        $ident = ''; $value = null;
        $control = match (true) {
            preg_match('/^(?:bitte )?(?:stoppe|stopp|beende)\b/', $q) === 1 => 'Stop',
            preg_match('/^(?:bitte )?pausiere\b/', $q) === 1 => 'Pause',
            preg_match('/\b(?:fort|fortsetzen)\b/', $q) === 1 => 'Resume',
            preg_match('/^(?:bitte )?(?:starte|start)\b/', $q) === 1 => 'Start',
            default => '',
        };
        if ($control !== '') {
            $remainder = $q;
            foreach (array_merge((array)$d['aliases'], array_column((array)($v['SelectedProgram']['profile']['Associations'] ?? []),'Name')) as $label) {
                $label = self::norm((string)$label);
                if ($label !== '') $remainder = preg_replace('/\b' . preg_quote($label,'/') . '\b/', ' ', $remainder) ?? $remainder;
            }
            $remainder = preg_replace('/\b(?:bitte|starte|start|stoppe|stopp|beende|pausiere|setze|fort|fortsetzen|programm|das|den|die|der|meine|meinen|meiner|meinem|mit|von|fuer|jetzt)\b/', ' ', $remainder) ?? $remainder;
            if (trim($remainder) !== '') return 'Bitte Programm und Optionen zuerst auswählen und den Gerätebefehl danach einzeln nennen.';
            if ($control === 'Start') {
                foreach ((array)($v['SelectedProgram']['profile']['Associations'] ?? []) as $a) {
                    $name = self::norm((string)($a['Name'] ?? ''));
                    if (strlen($name) >= 3 && preg_match('/\b' . preg_quote($name, '/') . '\b/', $q)
                        && ($v['SelectedProgram']['raw'] ?? '') !== $a['Value']) return 'Bitte zuerst das gewünschte Programm auswählen und danach starten.';
                }
            }
            $ident = 'Control'; $value = $control;
        }
        elseif (preg_match('/\b(?:ein|an|aus)\b$/', $q, $m)) {
            $boolean = [];
            foreach ($v as $i=>$row) {
                if ($row['type'] !== 0 || !$row['action']) continue;
                $name = self::norm($row['name']);
                if (strlen($name) >= 4 && preg_match('/\b' . preg_quote($name, '/') . '\b/', $q)) $boolean[] = $i;
            }
            if (count($boolean) === 1) { $ident = $boolean[0]; $value = $m[0] !== 'aus'; }
            elseif ($boolean === [] && !preg_match('/kindersicherung|sabbat|vorwaesche|vorspuelen|option|glanztrocknen|variospeed/', $q)) {
                $remainder = $q;
                foreach ((array)$d['aliases'] as $alias) {
                    $alias = self::norm((string)$alias);
                    if ($alias !== '') $remainder = preg_replace('/\b' . preg_quote($alias,'/') . '\b/', ' ', $remainder) ?? $remainder;
                }
                $remainder = trim(preg_replace('/\s+/', ' ', $remainder) ?? '');
                if (preg_match('/^(?:schalte|schalt|mach|mache|stelle|stell) (?:(?:die|den|das|meine|meinen|meiner|meinem) )?(?:auf )?(?:ein|an|aus)$/D', $remainder)) {
                    $ident = 'PowerState'; $value = 'BSH.Common.EnumType.PowerState.' . ($m[0] === 'aus' ? 'Off' : 'On');
                }
            }
        } else {
            // Exactly one known program or option association must match the requested label.
            $candidates = []; $best = 0;
            foreach ($v as $i=>$row) {
                if ($i !== 'SelectedProgram' && !str_starts_with($i, 'Option')) continue;
                if (in_array($i, ['OptionEstimatedTotalProgramTime','OptionRemainingProgramTimeIsEstimated','OptionLoadRecommendation'], true)) continue;
                foreach ((array)($row['profile']['Associations'] ?? []) as $a) {
                    $label = self::norm((string)($a['Name'] ?? ''));
                    if (strlen($label) >= 3 && preg_match('/\b' . preg_quote($label, '/') . '\b/', $q)) {
                        if (strlen($label) > $best) { $candidates = []; $best = strlen($label); }
                        if (strlen($label) === $best) $candidates[$i . '|' . (string)$a['Value']] = [$i,$a['Value']];
                    }
                }
            }
            if (count($candidates) === 1) [$ident,$value] = reset($candidates);
            if ($ident === '' && preg_match('/\b(\d+(?:[ ,.]\d+)?)\s*(?:ml|milliliter|grad|sekunden|minuten|u min|umdrehungen)\b/', $q, $m)) {
                $numbers = [];
                foreach ($v as $i=>$row) {
                    if (!$row['action'] || !in_array($i, ['OptionFillQuantity','OptionDuration','OptionSetpointTemperature'], true)) continue;
                    if (($i === 'OptionFillQuantity' && !preg_match('/menge|ml|milliliter/', $q))
                        || ($i === 'OptionDuration' && !preg_match('/dauer|minuten|sekunden/', $q))
                        || ($i === 'OptionSetpointTemperature' && !preg_match('/temperatur|grad/', $q))) continue;
                    $number = (float)str_replace([',',' '], ['.',''], $m[1]);
                    if ($i === 'OptionDuration' && str_contains($q, 'minuten')) $number *= 60;
                    $min = (float)($row['profile']['MinValue'] ?? 0); $max = (float)($row['profile']['MaxValue'] ?? 0);
                    $step = (float)($row['profile']['StepSize'] ?? 0);
                    if ($max > $min && $number >= $min && $number <= $max
                        && ($step <= 0 || abs(($number - $min) / $step - round(($number - $min) / $step)) < 0.0001)) {
                        $numbers[] = [$i, $row['type'] === 1 ? (int)$number : $number];
                    }
                }
                if (count($numbers) === 1) [$ident,$value] = reset($numbers);
            }
        }
        if ($ident === '' || !isset($v[$ident]) || empty($v[$ident]['action']) || !empty($v[$ident]['ambiguous'])) return 'Bitte eine eindeutige, verfügbare Gerätefunktion oder ein Programm nennen.';
        if (($ident === 'Control' && in_array($value, ['Start','Resume'], true))) {
            if (($v['RemoteControlStartAllowed']['raw'] ?? false) !== true) return 'Der Fernstart muss direkt am Gerät freigegeben werden.';
            if (isset($v['DoorState']) && str_ends_with((string)$v['DoorState']['raw'], '.Open')) return 'Die Gerätetür ist noch offen.';
        }
        $allowed = array_column((array)($v[$ident]['profile']['Associations'] ?? []), 'Value');
        if (!in_array($value, $allowed, true) && !(is_numeric($value)
            && in_array($ident, ['OptionFillQuantity','OptionDuration','OptionSetpointTemperature'], true))) return 'Dieser Wert ist im aktuellen Geräteprofil nicht als Auswahl verfügbar.';
        if ($ident === 'Control' && $value === 'Start' && !in_array($v['OperationState']['raw'] ?? '', ['BSH.Common.EnumType.OperationState.Ready','BSH.Common.EnumType.OperationState.Inactive'], true)) return 'Das Gerät meldet keinen startbereiten Zustand.';
        if ($ident === 'Control' && $value === 'Start' && (($v['SelectedProgram']['raw'] ?? '') === ''
            || !in_array($v['SelectedProgram']['raw'] ?? null, array_column((array)($v['SelectedProgram']['profile']['Associations'] ?? []),'Value'),true))) return 'Bitte zuerst ein aktuell verfügbares Programm auswählen.';
        try {
            $row = $v[$ident];
            $object = IPS_GetObject($row['id']);
            if (!self::validDevice($d['id']) || (int)$object['ParentID'] !== $row['parent'] || $object['ObjectIdent'] !== $ident) return 'Die Gerätezuordnung hat sich verändert.';
            if (RequestAction($row['id'], $value) === false) return 'Das Gerät hat den Auftrag abgelehnt.';
            return 'Der Auftrag für ' . $d['name'] . ' wurde übergeben.';
        } catch (Throwable $e) { return 'Der Auftrag konnte nicht an das Gerät übergeben werden.'; }
    }
}
