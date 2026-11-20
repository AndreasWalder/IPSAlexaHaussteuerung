<?php
declare(strict_types=1);

/** Generic, persistent variable-to-Echo rules. No script execution or model-selected actions. */
trait IAHAnnouncementTrait
{
    private function announcementRead(string $attribute): array
    {
        $value = json_decode($this->ReadAttributeString($attribute), true);
        return is_array($value) ? $value : [];
    }

    private function announcementWrite(string $attribute, array $value): void
    {
        $this->WriteAttributeString($attribute, json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function announcementBinding(int $id): array
    {
        if ($id <= 0 || !IPS_VariableExists($id)) throw new RuntimeException('Bitte eine vorhandene Auslösevariable auswählen.');
        $o = IPS_GetObject($id); $v = IPS_GetVariable($id);
        return ['parent'=>(int)$o['ParentID'], 'ident'=>(string)($o['ObjectIdent'] ?? ''), 'type'=>(int)$v['VariableType']];
    }

    private function announcementValid(array $r): bool
    {
        try {
            if ($this->announcementBinding((int)$r['variableId']) !== ($r['binding'] ?? null)) return false;
            if (($r['origin'] ?? '') === 'home_connect') {
                $owner = (int)($r['deviceId'] ?? 0);
                foreach ($this->homeConnectDevices() as $d) {
                    if ($d['id'] === $owner) return (int)IPS_GetObject($r['variableId'])['ParentID'] === $owner
                        && (int)(IPS_GetInstance($owner)['InstanceStatus'] ?? 0) === 102;
                }
                return false;
            }
            return true;
        } catch (Throwable $e) { return false; }
    }

    private static function announcementMatches(array $r, $value): bool
    {
        $expected = json_decode((string)$r['expected'], true);
        if (json_last_error() !== JSON_ERROR_NONE) $expected = (string)$r['expected'];
        $equal = (is_int($value) || is_float($value)) && (is_int($expected) || is_float($expected))
            ? (float)$value === (float)$expected : $value === $expected;
        return match ($r['operator']) {
            'event', 'equals' => is_array($expected) ? in_array($value, $expected, true) : $equal,
            'not_equals' => !$equal,
            'above' => is_numeric($value) && is_numeric($expected) && (float)$value > (float)$expected,
            'below' => is_numeric($value) && is_numeric($expected) && (float)$value < (float)$expected,
            'change', 'update' => true,
            default => false,
        };
    }

    private static function announcementQuiet(array $r): bool
    {
        $hour=(int)date('G'); $start=(int)$r['quietStart']; $end=(int)$r['quietEnd'];
        return $start !== $end && ($start < $end ? $hour >= $start && $hour < $end : $hour >= $start || $hour < $end);
    }

    private function announcementTemplates(array $rules): array
    {
        $seen = $this->announcementRead('AnnouncementTemplatesSeen');
        foreach ($this->homeConnectDevices() as $d) {
            $v = IAHHomeConnect::variables($d['id']);
            if (!isset($v['Event'])) continue;
            $templates = [];
            if (in_array($d['type'], ['Washer','WasherDryer','Dryer','Dishwasher','Oven'], true)) {
                $templates['finished'] = [['BSH.Common.Event.ProgramFinished'], $d['name'] . ': Das Programm ist fertig.'];
            }
            if ($d['type'] === 'Dishwasher') $templates['aborted'] = [['BSH.Common.Event.ProgramAborted'], $d['name'] . ': Das Programm wurde abgebrochen.'];
            if ($d['type'] === 'Oven') {
                $templates['preheat'] = [['BSH.Common.Event.PreheatFinished','Cooking.Oven.Event.PreheatFinished','Cooking.Oven.Event.RegularPreheatFinished'], $d['name'] . ': Das Vorheizen ist fertig.'];
                $templates['timer'] = [['BSH.Common.Event.AlarmClockElapsed'], $d['name'] . ': Der Timer ist abgelaufen.'];
            }
            if ($d['type'] === 'CoffeeMaker') {
                foreach (['BeanContainerEmpty'=>'Der Bohnenbehälter ist leer.', 'WaterTankEmpty'=>'Der Wassertank ist leer.', 'DripTrayFull'=>'Die Tropfschale ist voll.'] as $event=>$phrase) {
                    $templates[$event] = [['ConsumerProducts.CoffeeMaker.Event.' . $event], $d['name'] . ': ' . $phrase];
                }
            }
            foreach ($templates as $event=>[$values,$text]) {
                $key = 'hc_' . $d['id'] . '_' . $event;
                if (in_array($key, $seen, true)) continue;
                $seen[] = $key;
                if (isset($rules[$key])) continue;
                $rules[$key] = ['key'=>$key, 'title'=>$text, 'enabled'=>true, 'origin'=>'home_connect',
                    'deviceId'=>$d['id'], 'variableId'=>$v['Event']['id'], 'binding'=>$this->announcementBinding($v['Event']['id']),
                    'operator'=>'event', 'expected'=>json_encode($values, JSON_THROW_ON_ERROR), 'text'=>$text,
                    'cooldown'=>120, 'echoDeviceId'=>0, 'mode'=>'announcement', 'quietStart'=>0, 'quietEnd'=>0];
            }
        }
        $this->announcementWrite('AnnouncementTemplatesSeen', $seen);
        return $rules;
    }

    /** Also run periodically so newly discovered device variables become available without reinstalling. */
    public function RefreshAnnouncements(): void
    {
        if (!defined('VM_UPDATE')) return;
        $lock = 'IAH.Announcements.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) return;
        try {
            $rules = $this->announcementTemplates($this->announcementRead('AnnouncementRules'));
            $state = $this->announcementRead('AnnouncementState');
            $subscriptions = [];
            foreach ($rules as $key=>$r) {
                $active = $this->ReadPropertyBoolean('AnnouncementsEnabled') && !empty($r['enabled']) && $this->announcementValid($r);
                $hash = hash('sha256', serialize($r));
                if ($active && (($state[$key]['hash'] ?? '') !== $hash || empty($state[$key]['armed']))) {
                    $state[$key]['condition'] = self::announcementMatches($r, GetValue($r['variableId']));
                    $state[$key]['hash'] = $hash;
                }
                $state[$key]['armed'] = $active;
                if ($active) $subscriptions[(int)$r['variableId']] = true;
            }
            foreach ($this->announcementRead('AnnouncementSubscriptions') as $id) {
                if (!isset($subscriptions[(int)$id])) $this->UnregisterMessage((int)$id, VM_UPDATE);
            }
            foreach (array_keys($subscriptions) as $id) $this->RegisterMessage($id, VM_UPDATE);
            $state = array_intersect_key($state, $rules);
            $this->announcementWrite('AnnouncementSubscriptions', array_keys($subscriptions));
            $this->announcementWrite('AnnouncementRules', $rules);
            $this->announcementWrite('AnnouncementState', $state);
            if (!$this->ReadPropertyBoolean('AnnouncementsEnabled')) {
                $this->announcementWrite('AnnouncementQueue', []);
                $this->SetTimerInterval('AnnouncementDispatch', 0);
            }
        } catch (Throwable $e) {
            // Do not expose arbitrary variable values or provider error messages.
        } finally { IPS_SemaphoreLeave($lock); }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if (!defined('VM_UPDATE') || $Message !== VM_UPDATE || !$this->ReadPropertyBoolean('AnnouncementsEnabled')
            || !is_array($Data) || !array_key_exists(0, $Data)) return;
        $lock = 'IAH.Announcements.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1500)) return;
        try {
            $rules = $this->announcementRead('AnnouncementRules');
            $state = $this->announcementRead('AnnouncementState');
            $queue = $this->announcementRead('AnnouncementQueue');
            foreach ($rules as $key=>$r) {
                if ((int)$r['variableId'] !== (int)$SenderID || empty($r['enabled']) || !$this->announcementValid($r)) continue;
                $match = self::announcementMatches($r, $Data[0]);
                $old = (bool)($state[$key]['condition'] ?? false);
                $state[$key]['condition'] = $match;
                if (empty($state[$key]['armed'])) { $state[$key]['armed'] = true; continue; }
                $trigger = match ($r['operator']) {
                    'event', 'update' => $match,
                    'change' => !empty($Data[1]),
                    default => $match && !$old,
                };
                $now = time();
                if (!$trigger || $now - (int)($state[$key]['lastQueued'] ?? 0) < (int)$r['cooldown']) continue;
                if (self::announcementQuiet($r)) { $state[$key]['result'] = 'Ruhezeit'; continue; }
                if (count($queue) >= 100) { $state[$key]['result'] = 'Warteschlange voll'; continue; }
                $value = is_scalar($Data[0]) ? IAHHomeConnect::clean(is_bool($Data[0]) ? ($Data[0] ? 'Ja' : 'Nein') : (string)$Data[0]) : '';
                $text = strtr($r['text'], ['{name}'=>$r['title'], '{value}'=>$value]);
                $queue[] = ['key'=>$key, 'created'=>$now, 'hash'=>hash('sha256', serialize($r)), 'text'=>mb_substr($text, 0, 1000)];
                $state[$key]['lastQueued'] = $now;
                $state[$key]['result'] = 'Wartet auf Ausgabe';
            }
            $this->announcementWrite('AnnouncementState', $state);
            $this->announcementWrite('AnnouncementQueue', $queue);
            if ($queue !== []) $this->SetTimerInterval('AnnouncementDispatch', 250);
        } finally { IPS_SemaphoreLeave($lock); }
    }

    public function ProcessAnnouncements(): void
    {
        $sendLock = 'IAH.AnnouncementSend.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sendLock, 1)) return;
        try {
            $lock = 'IAH.Announcements.' . $this->InstanceID;
            if (!IPS_SemaphoreEnter($lock, 1000)) return;
            try {
                $queue = $this->announcementRead('AnnouncementQueue');
                $item = array_shift($queue);
                $this->announcementWrite('AnnouncementQueue', $queue);
                $this->SetTimerInterval('AnnouncementDispatch', $queue === [] ? 0 : 250);
            } finally { IPS_SemaphoreLeave($lock); }
            if (!is_array($item)) return;
            $rules = $this->announcementRead('AnnouncementRules');
            $r = $rules[$item['key']] ?? null;
            if (!$this->ReadPropertyBoolean('AnnouncementsEnabled') || !is_array($r) || empty($r['enabled'])
                || hash('sha256', serialize($r)) !== $item['hash'] || time() - $item['created'] > 120
                || self::announcementQuiet($r) || !$this->announcementValid($r)) {
                $this->announcementResult($item['key'], 'Verworfen', false);
                return;
            }
            $sent = $r['mode'] === 'speech' ? $this->SpeakText($item['text'], $r['echoDeviceId']) : $this->Announce($item['text'], $r['echoDeviceId']);
            $this->announcementResult($item['key'], $sent ? 'Angenommen' : 'Senden fehlgeschlagen', true);
        } finally { IPS_SemaphoreLeave($sendLock); }
    }

    private function announcementResult(string $key, string $result, bool $attempted): void
    {
        $lock = 'IAH.Announcements.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) return;
        try {
            if (!isset($this->announcementRead('AnnouncementRules')[$key])) return;
            $state = $this->announcementRead('AnnouncementState');
            if ($attempted) $state[$key]['lastSent'] = time();
            $state[$key]['result'] = $result;
            $this->announcementWrite('AnnouncementState', $state);
            $history = $this->announcementRead('AnnouncementHistory');
            $history[] = ['time'=>date('c'), 'key'=>$key, 'result'=>$result];
            $this->announcementWrite('AnnouncementHistory', array_slice($history, -100));
        } finally { IPS_SemaphoreLeave($lock); }
    }

    public function AddAnnouncementRule(): void
    {
        $key = 'custom_' . bin2hex(random_bytes(6));
        $lock = 'IAH.Announcements.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) return;
        try {
            $rules = $this->announcementRead('AnnouncementRules');
            if (count($rules) >= 200) throw new RuntimeException('Maximal 200 Durchsagenregeln.');
            $rules[$key] = ['key'=>$key,'title'=>'Eigene Durchsage','enabled'=>false,'origin'=>'custom',
                'variableId'=>0,'binding'=>null,'operator'=>'equals','expected'=>'true','text'=>'Meine Durchsage.',
                'cooldown'=>300,'echoDeviceId'=>0,'mode'=>'announcement','quietStart'=>0,'quietEnd'=>0];
            $this->announcementWrite('AnnouncementRules', $rules);
        } finally { IPS_SemaphoreLeave($lock); }
        $this->ReloadAnnouncementList();
        echo 'Neue Durchsage angelegt. Über das Zahnrad bearbeiten und aktivieren.';
    }

    public function SaveAnnouncementRule(string $rowJson): void
    {
        $lock = 'IAH.Announcements.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) return;
        try {
            $row = json_decode($rowJson, true, 32, JSON_THROW_ON_ERROR);
            $key = (string)($row['key'] ?? '');
            $rules = $this->announcementRead('AnnouncementRules');
            $old = $rules[$key] ?? null;
            if (!is_array($old) || ($row['revision'] ?? '') !== hash('sha256', serialize($old))) throw new RuntimeException('Regel wurde verändert. Liste bitte neu laden.');
            $r = $old;
            foreach (['title','operator','expected','text','mode'] as $field) $r[$field] = trim((string)($row[$field] ?? ''));
            foreach (['variableId','cooldown','echoDeviceId','quietStart','quietEnd'] as $field) $r[$field] = (int)($row[$field] ?? 0);
            $r['enabled'] = !empty($row['enabled']);
            if (!in_array($r['operator'], ['equals','not_equals','above','below','change','update','event'], true)
                || !in_array($r['mode'], ['announcement','speech'], true)
                || $r['cooldown'] < 1 || $r['cooldown'] > 24 * 60 * 60
                || min($r['quietStart'],$r['quietEnd']) < 0 || max($r['quietStart'],$r['quietEnd']) > 23
                || $r['title'] === '' || mb_strlen($r['title']) > 160 || $r['text'] === '' || mb_strlen($r['text']) > 900
                || strlen($r['expected']) > 2048) throw new RuntimeException('Bitte Name, Text, Vergleich und Zeitangaben prüfen.');
            if ($r['echoDeviceId'] !== 0 && !$this->echoRemoteTarget($r['echoDeviceId'])['ready']) throw new RuntimeException('Das ausgewählte Echo ist nicht bereit.');
            if ($r['variableId'] > 0) $r['binding'] = $this->announcementBinding($r['variableId']);
            elseif ($r['enabled']) throw new RuntimeException('Eine aktive Regel benötigt eine Auslösevariable.');
            if (in_array($r['operator'], ['above','below'], true)
                && (!is_numeric($r['expected']) || !is_finite((float)$r['expected']))) throw new RuntimeException('Für einen Grenzwert bitte eine endliche Zahl eingeben.');
            if ($r['variableId'] !== $old['variableId']) { $r['origin'] = 'custom'; unset($r['deviceId']); }
            $rules[$key] = $r;
            $this->announcementWrite('AnnouncementRules', $rules);
            echo 'Durchsage gespeichert.';
        } catch (Throwable $e) { echo $e->getMessage(); }
        finally { IPS_SemaphoreLeave($lock); }
        $this->RefreshAnnouncements();
        $this->ReloadAnnouncementList();
    }

    public function DeleteAnnouncementRule(string $key, string $revision): void
    {
        $lock = 'IAH.Announcements.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) return;
        try {
            $rules = $this->announcementRead('AnnouncementRules');
            if (!isset($rules[$key]) || hash('sha256', serialize($rules[$key])) !== $revision) return;
            unset($rules[$key]);
            $this->announcementWrite('AnnouncementRules', $rules);
        } finally { IPS_SemaphoreLeave($lock); }
        $this->RefreshAnnouncements();
        $this->ReloadAnnouncementList();
        echo 'Durchsage gelöscht.';
    }

    public function ExportAnnouncementRules(): void
    {
        $json = json_encode(['rules'=>array_values($this->announcementRead('AnnouncementRules')),
            'history'=>$this->announcementRead('AnnouncementHistory')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->UpdateFormField('AnnouncementJson', 'value', $json);
    }

    public function TestAnnouncementRule(string $key): void
    {
        $r = $this->announcementRead('AnnouncementRules')[$key] ?? null;
        if (!is_array($r)) { echo 'Bitte eine Durchsage auswählen.'; return; }
        $value = '';
        if ((int)$r['variableId'] > 0) {
            if (!$this->announcementValid($r)) { echo 'Die Auslösevariable fehlt oder ist nicht freigegeben.'; return; }
            $raw = GetValue($r['variableId']);
            $value = IAHHomeConnect::clean(is_bool($raw) ? ($raw ? 'Ja' : 'Nein') : (string)$raw);
        }
        $text = mb_substr(strtr($r['text'], ['{name}'=>$r['title'], '{value}'=>$value]), 0, 1000);
        $sent = $r['mode'] === 'speech' ? $this->SpeakText($text, $r['echoDeviceId']) : $this->Announce($text, $r['echoDeviceId']);
        echo $sent ? 'Testausgabe angenommen.' : 'Testausgabe fehlgeschlagen.';
    }

    private function announcementRows(): array
    {
        $state = $this->announcementRead('AnnouncementState');
        $rows = [];
        foreach ($this->announcementRead('AnnouncementRules') as $key=>$r) {
            $rows[] = $r + ['revision'=>hash('sha256', serialize($r)),
                'originLabel'=>($r['origin'] ?? '') === 'home_connect' ? 'Home Connect' : 'Eigene Regel',
                'status'=>empty($r['enabled']) ? 'Deaktiviert' : (!$this->ReadPropertyBoolean('AnnouncementsEnabled') ? 'Global pausiert'
                    : ($this->announcementValid($r) ? ($state[$key]['result'] ?? 'Bereit') : 'Quelle fehlt / gesperrt')),
                'lastSent'=>empty($state[$key]['lastSent']) ? '—' : date('d.m. H:i', $state[$key]['lastSent'])];
        }
        return $rows;
    }

    private function buildAnnouncementPanel(): array
    {
        $operators = [];
        $testOptions = $this->announcementTestOptions();
        foreach (['equals'=>'Wird gleich', 'not_equals'=>'Wird ungleich', 'above'=>'Überschreitet', 'below'=>'Unterschreitet',
            'change'=>'Bei Änderung', 'update'=>'Bei Aktualisierung', 'event'=>'Home-Connect-Ereignis'] as $value=>$caption) $operators[] = compact('value','caption');
        return ['type'=>'ExpansionPanel', 'caption'=>'AUTOMATISCHE DURCHSAGEN – Alle Regeln und eigene Verknüpfungen',
            'expanded'=>false, 'items'=>[
                ['type'=>'CheckBox', 'name'=>'AnnouncementsEnabled', 'caption'=>'Automatische Durchsagen aktivieren'],
                ['type'=>'Label', 'caption'=>'Alle Durchsagen an einer Stelle. Über das Zahnrad Variable, Vergleich, Text und Echo bearbeiten. Echo 0 verwendet die zentrale Echo-Remote-Ausgabe (auch alle Geräte). Texte unterstützen {name} und {value}. Vergleiche: true / false für Boolean, Zahlen oder exakter Text; für mehrere Ereignisse eine JSON-Liste. Mindestabstand verhindert Wiederholungen. Gleiche Ruhestunden bedeuten keine Ruhezeit. Änderungen an Regeln werden direkt gespeichert.'],
                ['type'=>'RowLayout', 'items'=>[
                    ['type'=>'Button','caption'=>'Eigene Durchsage hinzufügen','onClick'=>'IAH_AddAnnouncementRule($id);'],
                    ['type'=>'Button','caption'=>'Liste und Vorlagen aktualisieren','onClick'=>'IAH_RefreshHomeConnectDevices($id); IAH_ReloadAnnouncementList($id);'],
                    ['type'=>'Button','caption'=>'Regeln und Verlauf als JSON','onClick'=>'IAH_ExportAnnouncementRules($id);'],
                ]],
                ['type'=>'List','name'=>'AnnouncementRules','caption'=>'Gemeinsame Durchsagenliste','rowCount'=>12,
                    'add'=>false,'delete'=>true,'loadValuesFromConfiguration'=>false,
                    'columns'=>[
                        ['name'=>'key','caption'=>'Key','visible'=>false,'save'=>false],
                        ['name'=>'enabled','caption'=>'Aktiv','width'=>'65px','edit'=>['type'=>'CheckBox']],
                        ['name'=>'title','caption'=>'Name','width'=>'220px','quickFilter'=>true,'edit'=>['type'=>'ValidationTextBox']],
                        ['name'=>'originLabel','caption'=>'Quelle','width'=>'110px','save'=>false],
                        ['name'=>'variableId','caption'=>'Auslösevariable','width'=>'100px','edit'=>['type'=>'SelectVariable']],
                        ['name'=>'operator','caption'=>'Auslöser','width'=>'130px','edit'=>['type'=>'Select','options'=>$operators]],
                        ['name'=>'expected','caption'=>'Vergleichswert','width'=>'190px','edit'=>['type'=>'ValidationTextBox']],
                        ['name'=>'text','caption'=>'Durchsagetext','width'=>'250px','edit'=>['type'=>'ValidationTextBox','multiline'=>true]],
                        ['name'=>'cooldown','caption'=>'Abstand (s)','width'=>'90px','edit'=>['type'=>'NumberSpinner','minimum'=>1,'maximum'=>24*60*60]],
                        ['name'=>'echoDeviceId','caption'=>'Echo (0 = zentral)','width'=>'110px','edit'=>['type'=>'SelectInstance','moduleID'=>'{496AB8B5-396A-40E4-AF41-32F4C48AC90D}']],
                        ['name'=>'mode','caption'=>'Ausgabe','width'=>'100px','edit'=>['type'=>'Select','options'=>[
                            ['caption'=>'Durchsage','value'=>'announcement'],['caption'=>'Sprachausgabe','value'=>'speech']]]],
                        ['name'=>'quietStart','caption'=>'Ruhe ab Stunde','width'=>'100px','edit'=>['type'=>'NumberSpinner','minimum'=>0,'maximum'=>23]],
                        ['name'=>'quietEnd','caption'=>'Ruhe bis Stunde','width'=>'100px','edit'=>['type'=>'NumberSpinner','minimum'=>0,'maximum'=>23]],
                        ['name'=>'status','caption'=>'Ergebnis','width'=>'150px','save'=>false],
                        ['name'=>'lastSent','caption'=>'Letzter Auftrag','width'=>'120px','save'=>false],
                        ['name'=>'revision','caption'=>'Revision','visible'=>false,'save'=>false],
                    ],'values'=>$this->announcementRows(),
                    'onEdit'=>'IAH_SaveAnnouncementRule($id, json_encode($AnnouncementRules));',
                    'onDelete'=>'IAH_DeleteAnnouncementRule($id, (string)$AnnouncementRules["key"], (string)$AnnouncementRules["revision"]);'],
                ['type'=>'ValidationTextBox','name'=>'AnnouncementJson','caption'=>'Regeln und Verlauf als JSON','multiline'=>true,'width'=>'100%','value'=>''],
                ['type'=>'RowLayout', 'items'=>[
                    ['type'=>'Select','name'=>'AnnouncementTestKey','caption'=>'Durchsage testen','options'=>$testOptions,'value'=>''],
                    ['type'=>'Button','caption'=>'Ausgewählte Durchsage jetzt testen','onClick'=>'IAH_TestAnnouncementRule($id, (string)$AnnouncementTestKey);'],
                ]],
            ]];
    }

    public function ReloadAnnouncementList(): void
    {
        $this->UpdateFormField('AnnouncementRules', 'values', $this->announcementRows());
        $this->UpdateFormField('AnnouncementTestKey', 'options', $this->announcementTestOptions());
    }

    private function announcementTestOptions(): array
    {
        $options = [['caption'=>'Durchsage auswählen', 'value'=>'']];
        foreach ($this->announcementRead('AnnouncementRules') as $key=>$r) $options[] = ['caption'=>$r['title'], 'value'=>$key];
        return $options;
    }
}
