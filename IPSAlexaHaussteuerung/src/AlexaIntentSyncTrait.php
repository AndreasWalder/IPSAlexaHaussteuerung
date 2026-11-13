<?php
declare(strict_types=1);

/** Manage the installed Patami Intent instances, never the Alexa model itself. */
trait IAHAlexaIntentSyncTrait
{
    private const PATAMI_INTENT = '{F14921A2-405D-4576-A389-95FB9A5CD730}';
    private const PATAMI_WEBHOOK = '{F416D335-7D66-4B91-97AA-02C7F4E3D343}';
    // Patami derives the property name from the concrete Custom\ScriptIntent class.
    private const PATAMI_SCRIPT = 'Patami_IPS_Services_Alexa_Skills_Custom_Intents_Custom_ScriptIntent_ScriptID';
    private const ACTION_BUILTINS = ['AMAZON.HelpIntent', 'AMAZON.FallbackIntent', 'AMAZON.CancelIntent', 'AMAZON.StopIntent'];

    private function buildAlexaIntentSyncForm(): array
    {
        $getHaus = 0;
        try {
            $action = $this->intentSyncActionId();
            $matches = [];
            foreach (IPS_GetInstanceListByModuleID(self::PATAMI_INTENT) as $id) {
                $state = $this->intentSyncState((int)$id);
                if ($state['name'] === 'GetHaus' && $state['mode'] === 1 && $state['script'] === $action && $state['hook'] > 0) {
                    $matches[] = $id;
                }
            }
            if (count($matches) === 1) $getHaus = (int)$matches[0];
        } catch (Throwable $ignored) {
            // Optional Patami integration may not be installed yet.
        }

        $args = '(int)$AlexaIntentSyncReference, (string)$AlexaIntentSyncJson, (bool)$AlexaIntentSyncRetarget';
        return ['type'=>'ExpansionPanel', 'caption'=>'Alexa-Intents synchronisieren', 'expanded'=>false, 'items'=>[
            ['type'=>'Label', 'caption'=>'Alexa-Interaktionsmodell aus dem JSON Editor einfügen. GetHaus bestimmt den WebHook und bleibt unverändert. Die Vorschau ändert nichts.'],
            ['type'=>'SelectModule', 'moduleID'=>self::PATAMI_INTENT, 'name'=>'AlexaIntentSyncReference',
                'caption'=>'Vorhandene GetHaus-Instanz', 'value'=>$getHaus],
            ['type'=>'ValidationTextBox', 'name'=>'AlexaIntentSyncJson', 'caption'=>'Alexa-Interaktionsmodell (JSON)',
                'multiline'=>true, 'width'=>'100%', 'value'=>''],
            ['type'=>'CheckBox', 'name'=>'AlexaIntentSyncRetarget',
                'caption'=>'Auch vorhandene Skript-Intents auf das gemeinsame Action-Skript umstellen', 'value'=>false],
            ['type'=>'RowLayout', 'items'=>[
                ['type'=>'Button', 'caption'=>'Vorschau prüfen', 'onClick'=>'IAH_PreviewAlexaIntentSync($id, '.$args.');'],
                ['type'=>'Button', 'caption'=>'Alexa-Intents synchronisieren',
                    'confirm'=>'Die Änderungen aus der aktuellen Vorschau in IP-Symcon übernehmen?',
                    'onClick'=>'IAH_ApplyAlexaIntentSync($id, '.$args.');'],
            ]],
            ['type'=>'Label', 'name'=>'AlexaIntentSyncStatus', 'caption'=>'Noch keine Vorschau.'],
            ['type'=>'List', 'name'=>'AlexaIntentSyncRows', 'caption'=>'Zuordnungen', 'rowCount'=>12,
                'add'=>false, 'delete'=>false, 'loadValuesFromConfiguration'=>false,
                'columns'=>[
                    ['name'=>'intent', 'caption'=>'Intent', 'width'=>'225px', 'save'=>false],
                    ['name'=>'id', 'caption'=>'Instanz-ID', 'width'=>'85px', 'save'=>false],
                    ['name'=>'result', 'caption'=>'Status', 'width'=>'175px', 'save'=>false],
                    ['name'=>'detail', 'caption'=>'Zuordnung / Hinweis', 'width'=>'430px', 'save'=>false],
                ], 'values'=>[]],
        ]];
    }

    private function intentSyncActionId(): int
    {
        // Unlike the general legacy resolver, never guess a global Action script.
        $root = $this->InstanceID;
        $cat = $this->getObjectIDByIdentOrName($root, 'iahRenderers', 'Renderers');
        $name = 'Action (Haus\\Übersicht/Einstellungen Entry)';
        foreach (array_filter([$cat, $root]) as $parent) {
            $id = $this->getObjectIDByIdentOrName($parent, 'iahActionEntry', $name);
            if ($id > 0 && IPS_ScriptExists($id)) return $id;
        }
        throw new RuntimeException('Das lokale Action-Skript fehlt. Bitte zuerst die Modulinstanz aktualisieren.');
    }

    private function intentSyncState(int $id): array
    {
        $instance = IPS_GetInstance($id);
        if (strcasecmp((string)($instance['ModuleInfo']['ModuleID'] ?? ''), self::PATAMI_INTENT) !== 0)
            throw new RuntimeException('Bitte eine GetHaus-Instanz vom Typ AlexaCustomSkillIntent auswählen.');
        $standard = (string)IPS_GetProperty($id, 'StandardIntentName');
        return [
            'id'=>$id,
            'name'=>$standard !== '' ? $standard : (string)IPS_GetProperty($id, 'IntentName'),
            'hook'=>(int)($instance['ConnectionID'] ?? 0),
            'status'=>(int)($instance['InstanceStatus'] ?? 0),
            'mode'=>(int)IPS_GetProperty($id, 'IntentType'),
            'script'=>(int)IPS_GetProperty($id, self::PATAMI_SCRIPT),
            'pending'=>IPS_HasChanges($id),
        ];
    }

    private static function intentSyncNames(string $raw): array
    {
        if (strlen($raw) > 1024 * 1024) throw new RuntimeException('Das Alexa-JSON ist zu groß.');
        $raw = trim($raw);
        if ($raw === '') throw new RuntimeException('Bitte das Alexa-JSON einfügen.');
        if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
        try {
            $model = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Ungültiges JSON. Bitte das vollständige Modell aus dem Alexa JSON Editor kopieren.');
        }
        $intents = $model['interactionModel']['languageModel']['intents'] ?? null;
        if (!is_array($intents) || !array_is_list($intents) || !$intents || count($intents) > 200)
            throw new RuntimeException('Die Intent-Liste im Alexa-Interaktionsmodell fehlt oder enthält zu viele Einträge.');
        $names = [];
        foreach ($intents as $entry) {
            $name = $entry['name'] ?? null;
            if (!is_string($name) || !preg_match('/^(?:AMAZON\.)?[A-Za-z][A-Za-z0-9_]{0,99}$/D', $name))
                throw new RuntimeException('Das Modell enthält einen ungültigen Intent-Namen.');
            $key = strtolower($name);
            if (isset($names[$key])) throw new RuntimeException('Doppelter Intent-Name im Alexa-Modell: '.$name);
            $names[$key] = $name;
        }
        if (($names['gethaus'] ?? '') !== 'GetHaus')
            throw new RuntimeException('Das importierte Modell enthält keinen exakt benannten GetHaus-Intent.');
        return array_values($names);
    }

    private function intentSyncReference(int $reference, int $action): array
    {
        if ($reference <= 0 || !IPS_InstanceExists($reference))
            throw new RuntimeException('Bitte die vorhandene GetHaus-Instanz auswählen.');
        $ref = $this->intentSyncState($reference);
        if ($ref['name'] !== 'GetHaus' || $ref['mode'] !== 1 || $ref['script'] !== $action || $ref['pending'] || $ref['status'] !== 102)
            throw new RuntimeException('GetHaus muss aktiv sein und ohne offene Änderungen auf das lokale Action-Skript '.$action.' zeigen.');
        if ($ref['hook'] <= 0 || !IPS_InstanceExists($ref['hook'])
            || strcasecmp((string)(IPS_GetInstance($ref['hook'])['ModuleInfo']['ModuleID'] ?? ''), self::PATAMI_WEBHOOK) !== 0)
            throw new RuntimeException('GetHaus ist nicht mit einem Patami Alexa Custom Skill WebHook verbunden.');
        return $ref;
    }

    private function intentSyncPlan(int $reference, string $raw, bool $retarget): array
    {
        $names = self::intentSyncNames($raw);
        $action = $this->intentSyncActionId();
        $ref = $this->intentSyncReference($reference, $action);
        $byName = [];
        $instances = IPS_GetInstanceListByModuleID(self::PATAMI_INTENT);
        sort($instances, SORT_NUMERIC);
        foreach ($instances as $id) {
            $id = (int)$id;
            if ((int)(IPS_GetInstance($id)['ConnectionID'] ?? 0) !== $ref['hook']) continue;
            $state = $this->intentSyncState($id);
            $byName[strtolower($state['name'])][] = $state;
        }
        if (count($byName['gethaus'] ?? []) !== 1)
            throw new RuntimeException('Am ausgewählten WebHook gibt es mehrere GetHaus-Instanzen. Bitte die Zuordnung zuerst klären.');

        $rows = [];
        $ops = [];
        $snapshot = [];
        foreach ($names as $name) {
            $matches = $byName[strtolower($name)] ?? [];
            $snapshot[$name] = $matches;
            $id = $matches[0]['id'] ?? 0;
            if ($name === 'GetHaus') {
                [$result, $detail] = ['Referenz', 'Bleibt unverändert → '.$action];
            } elseif (str_starts_with($name, 'AMAZON.') && !in_array($name, self::ACTION_BUILTINS, true)) {
                [$result, $detail] = ['Übersprungen', 'Kein eigener Handler im Action-Skript.'];
            } elseif (!str_starts_with($name, 'AMAZON.') && !preg_match('/^[A-Za-z]+$/D', $name)) {
                [$result, $detail] = ['Übersprungen', 'Diese Patami-Version akzeptiert für eigene Namen nur Buchstaben.'];
            } elseif (count($matches) > 1) {
                [$result, $detail] = ['Konflikt', 'Mehrere gleichnamige Instanzen am gleichen WebHook.'];
            } elseif (!$matches) {
                [$result, $detail] = ['Fehlt', 'Neue Instanz → '.$action];
                $ops[] = ['kind'=>'create', 'name'=>$name, 'id'=>0];
            } else {
                $current = $matches[0];
                if ($current['name'] !== $name) {
                    [$result, $detail] = ['Konflikt', 'Schreibweise im Modell und in IP-Symcon unterscheiden sich.'];
                } elseif ($current['pending']) {
                    [$result, $detail] = ['Konflikt', 'Instanz hat noch nicht übernommene Änderungen.'];
                } elseif ($current['status'] !== 102) {
                    [$result, $detail] = ['Prüfen', 'Instanz ist nicht aktiv. Status '.$current['status'].'.'];
                } elseif ($current['mode'] !== 1) {
                    [$result, $detail] = ['Unverändert', 'Bestehende Aktion ist kein Skript und bleibt erhalten.'];
                } elseif ($current['script'] === $action) {
                    [$result, $detail] = ['Passt', 'Action '.$action];
                } elseif (!$retarget) {
                    [$result, $detail] = ['Anderes Skript', 'Derzeit '.$current['script'].'. Nur mit aktivierter Option umstellen.'];
                } else {
                    [$result, $detail] = ['Umstellen', $current['script'].' → '.$action];
                    $ops[] = ['kind'=>'update', 'name'=>$name, 'id'=>$id, 'oldScript'=>$current['script']];
                }
            }
            $rows[] = ['intent'=>$name, 'id'=>$id > 0 ? (string)$id : '–', 'result'=>$result, 'detail'=>$detail];
        }
        $parent = IPS_GetParent($reference);
        return ['hook'=>$ref['hook'], 'action'=>$action, 'parent'=>$parent,
            'ops'=>$ops, 'rows'=>$rows, 'hash'=>hash('sha256', json_encode(
                [$reference, hash('sha256',$raw), $retarget, $ref, $action, $parent, $snapshot, $ops],
                JSON_THROW_ON_ERROR))];
    }

    private function intentSyncOutput(string $caption, array $rows): void
    {
        $this->UpdateFormField('AlexaIntentSyncStatus', 'caption', $caption);
        $this->UpdateFormField('AlexaIntentSyncRows', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function PreviewAlexaIntentSync(int $reference, string $modelJson, bool $retarget = false): void
    {
        $this->WriteAttributeString('AlexaIntentSyncPreview', '');
        try {
            $plan = $this->intentSyncPlan($reference, $modelJson, $retarget);
            $this->WriteAttributeString('AlexaIntentSyncPreview', json_encode(
                ['hash'=>$plan['hash'], 'at'=>time()], JSON_THROW_ON_ERROR));
            $this->intentSyncOutput(sprintf('WebHook %d, Action %d. %d Änderung(en) geplant. Noch nichts geändert.',
                $plan['hook'], $plan['action'], count($plan['ops'])), $plan['rows']);
        } catch (Throwable $error) {
            $this->intentSyncOutput('Vorschau nicht möglich. '.$error->getMessage(), []);
        }
    }

    public function ApplyAlexaIntentSync(int $reference, string $modelJson, bool $retarget = false): void
    {
        $lock = '';
        $locked = false;
        try {
            $plan = $this->intentSyncPlan($reference, $modelJson, $retarget);
            $lock = 'IAH.AlexaIntentSync.'.$plan['hook'];
            $locked = IPS_SemaphoreEnter($lock, 1500);
            if (!$locked) throw new RuntimeException('Für diesen WebHook läuft bereits eine Synchronisierung.');
            // Re-read all affected objects after acquiring the webhook lock.
            $plan = $this->intentSyncPlan($reference, $modelJson, $retarget);
            if ($lock !== 'IAH.AlexaIntentSync.'.$plan['hook'])
                throw new RuntimeException('GetHaus wurde auf einen anderen WebHook umgestellt. Bitte die Vorschau neu prüfen.');
            $preview = json_decode($this->ReadAttributeString('AlexaIntentSyncPreview'), true);
            if (!is_array($preview) || (int)($preview['at'] ?? 0) < time() - 900
                || !hash_equals((string)($preview['hash'] ?? ''), $plan['hash']))
                throw new RuntimeException('Bitte zuerst eine aktuelle Vorschau prüfen. Modell oder Instanzzuordnung haben sich geändert.');

            $this->WriteAttributeString('AlexaIntentSyncPreview', '');
            $done = [];
            $errors = [];
            foreach ($plan['ops'] as $op) {
                $id = $op['id'];
                try {
                    if ($op['kind'] === 'create') {
                        $id = IPS_CreateInstance(self::PATAMI_INTENT);
                        if ($id <= 0) throw new RuntimeException('Instanz konnte nicht angelegt werden.');
                        IPS_SetName($id, $op['name'].' Custom Skill Visualisierung');
                        IPS_SetParent($id, $plan['parent']);
                        if (IPS_GetParent($id) !== $plan['parent'])
                            throw new RuntimeException('Neue Instanz konnte nicht neben GetHaus einsortiert werden.');
                        $currentHook = (int)(IPS_GetInstance($id)['ConnectionID'] ?? 0);
                        if ($currentHook !== $plan['hook']) {
                            if ($currentHook > 0 && !IPS_DisconnectInstance($id))
                                throw new RuntimeException('Automatische Verbindung der neuen Instanz konnte nicht gelöst werden.');
                            if (!IPS_ConnectInstance($id, $plan['hook']))
                                throw new RuntimeException('Verbindung zum ausgewählten WebHook nicht möglich.');
                        }
                        $isStandard = str_starts_with($op['name'], 'AMAZON.');
                        if (!IPS_SetProperty($id, 'StandardIntentName', $isStandard ? $op['name'] : '')
                            || (!$isStandard && !IPS_SetProperty($id, 'IntentName', $op['name']))
                            || !IPS_SetProperty($id, 'IntentType', 1)) {
                            throw new RuntimeException('Intent-Name oder Aktion konnte nicht gesetzt werden.');
                        }
                    }
                    if (!IPS_SetProperty($id, self::PATAMI_SCRIPT, $plan['action']) || !IPS_ApplyChanges($id))
                        throw new RuntimeException('Das Action-Skript konnte nicht übernommen werden.');
                    $new = $this->intentSyncState($id);
                    if ($new['name'] !== $op['name'] || $new['hook'] !== $plan['hook'] || $new['mode'] !== 1
                        || $new['script'] !== $plan['action'] || $new['status'] !== 102 || $new['pending'])
                        throw new RuntimeException('Die neue Zuordnung wurde nicht als aktiv bestätigt.');
                    $done[$op['name']] = $id;
                } catch (Throwable $error) {
                    $detail = $error->getMessage();
                    if ($op['kind'] === 'update') {
                        try {
                            if (!IPS_SetProperty($id, self::PATAMI_SCRIPT, $op['oldScript']) || !IPS_ApplyChanges($id)
                                || $this->intentSyncState($id)['script'] !== $op['oldScript'])
                                throw new RuntimeException('Nicht verifiziert.');
                            $detail .= ' Die bisherige Skriptzuordnung wurde wiederhergestellt.';
                        } catch (Throwable $ignored) {
                            $detail .= ' Die Wiederherstellung konnte nicht bestätigt werden.';
                        }
                    }
                    $errors[$op['name']] = ['id'=>$id, 'detail'=>$detail];
                }
            }
            foreach ($plan['rows'] as &$row) {
                $name = $row['intent'];
                if (isset($done[$name])) {
                    $row['result'] = $row['result'] === 'Fehlt' ? 'Angelegt' : 'Umgestellt';
                    $row['id'] = (string)$done[$name];
                    $row['detail'] = 'Aktiv → '.$plan['action'];
                } elseif (isset($errors[$name])) {
                    $row['result'] = 'Fehler';
                    $row['id'] = $errors[$name]['id'] > 0 ? (string)$errors[$name]['id'] : '–';
                    $row['detail'] = $errors[$name]['detail'];
                }
            }
            unset($row);
            $this->intentSyncOutput(sprintf('%d Instanz(en) synchronisiert, %d Fehler. GetHaus und Alexa-Modell unverändert.',
                count($done), count($errors)), $plan['rows']);
        } catch (Throwable $error) {
            $this->intentSyncOutput('Synchronisierung nicht möglich. '.$error->getMessage(), []);
        } finally {
            if ($locked) IPS_SemaphoreLeave($lock);
        }
    }
}
