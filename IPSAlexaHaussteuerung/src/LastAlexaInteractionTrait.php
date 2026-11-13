<?php
declare(strict_types=1);

trait IAHLastAlexaInteractionTrait
{
    private const LAST_ALEXA_FIELDS = [
        'spoken'=>['iahLastAlexaSpoken','Letzter Sprachbefehl'],
        'intent'=>['iahLastAlexaIntent','Letzter Intent'],
        'route'=>['iahLastAlexaRoute','Letzter Verarbeitungsweg'],
        'ruleKey'=>['iahLastAlexaRule','Letzte Sprachregel'],
        'answer'=>['iahLastAlexaAnswer','Letzte Alexa-Antwort'],
        'status'=>['iahLastAlexaStatus','Letztes Ergebnis'],
        'at'=>['iahLastAlexaAt','Letzte Aktion am'],
    ];

    private function ensureLastAlexaInteraction(int $diag): void
    {
        foreach(self::LAST_ALEXA_FIELDS as [$ident,$name])
            $this->ensureVar($diag,$name,$ident,VARIABLETYPE_STRING,'',null);
        $this->ensureVar($diag,'Letzte Alexa-Aktion (JSON)','iahLastAlexaInteraction',VARIABLETYPE_STRING,'~TextBox',null);
    }

    private function alexaNameForDevice(string $deviceId): string
    {
        if ($deviceId === '' || strlen($deviceId) > 1024) return '';
        $helper = $this->getObjectIDByIdentOrName($this->InstanceID, 'iahHelper', 'Alexa new devices helper');
        $mapId = $helper > 0 ? $this->getObjectIDByIdentOrName($helper, 'deviceMapJson', 'DeviceMapJson') : 0;
        if ($mapId <= 0 || !IPS_VariableExists($mapId)
            || (int)(IPS_GetVariable($mapId)['VariableType'] ?? -1) !== 3) return '';
        $map = json_decode(GetValueString($mapId), true);
        $name = $map[$deviceId]['location'] ?? '';
        if (!is_string($name) || str_starts_with(trim($name), 'NEU:')) return '';
        return trim(mb_substr(mb_scrub($name, 'UTF-8'), 0, 100, 'UTF-8'));
    }

    private function alexaDiagnosisVariableId(): int
    {
        $diag = $this->getObjectIDByIdentOrName($this->InstanceID, 'iahDiag', 'Diagnose');
        return $diag > 0 ? (int)@IPS_GetObjectIDByIdent('alexa', $diag) : 0;
    }

    private function currentAlexaRuleContext(array $event): array
    {
        // Reading Diagnose/alexa in a live answer must use the Echo from
        // THIS request, not a shared variable written by another Echo.
        return [
            'alexaName' => $this->alexaNameForDevice(is_string($event['deviceId'] ?? null) ? $event['deviceId'] : ''),
            'alexaReadVariableId' => $this->alexaDiagnosisVariableId(),
        ];
    }

    private static function lastAlexaText(mixed $text,int $limit): string
    {
        if(!is_scalar($text))return '';
        if(is_bool($text))return $text?'true':'false';
        $text=mb_scrub((string)$text,'UTF-8');
        $text=trim(preg_replace('/[\x00-\x1F\x7F]+/u',' ',$text)??'');
        $text=preg_replace('/\b(passwort|password|pin|token|api[\s-]?key)\s+(?:ist\s+|lautet\s+)?[^\s,;.]+/iu',
            '$1 [GESCHUETZT]',$text)??$text;
        return mb_substr(iah_alexalog_redact($text),0,$limit,'UTF-8');
    }

    /** Keep a coherent snapshot of one real request, never a mix of old slots. */
    public function RecordAlexaInteraction(string $payload): void
    {
        if(strlen($payload)>32000)return;
        $entry=json_decode($payload,true);
        if(!is_array($entry)||!in_array($entry['requestType']??'',['IntentRequest','Alexa.Presentation.APL.UserEvent'],true))
            return;
        $diag=$this->getObjectIDByIdentOrName($this->InstanceID,'iahDiag','Diagnose');
        if($diag<=0)return;
        $lock='IAH.LastAlexaInteraction.'.$this->InstanceID;
        $useLock=function_exists('IPS_SemaphoreEnter');
        if($useLock&&!IPS_SemaphoreEnter($lock,300))return;
        try{
            $snapshot=['at'=>date(DATE_ATOM),'requestType'=>$entry['requestType']];
            $name=$this->alexaNameForDevice(is_string($entry['deviceId']??null)?$entry['deviceId']:'');
            $snapshot['alexa']=self::lastAlexaText($name,100);
            $save=static function(string $ident,string $value)use($diag):void{
                $id=(int)@IPS_GetObjectIDByIdent($ident,$diag);
                if($id>0&&IPS_VariableExists($id)&&(int)(IPS_GetVariable($id)['VariableType']??-1)===3
                    &&GetValueString($id)!==$value)SetValueString($id,$value);
            };
            // Do not associate an unregistered Echo with the last known one.
            $save('alexa',$snapshot['alexa']);
            $transcript=$this->ReadPropertyBoolean('VoiceRouterStoreTranscript');
            foreach(['action','device','room','szene','object','number','prozent','alles'] as $slot){
                $snapshot['slots'][$slot]=$transcript?self::lastAlexaText($entry['slots'][$slot]??'',300):'';
                $save($slot,$snapshot['slots'][$slot]);
            }
            foreach(self::LAST_ALEXA_FIELDS as $key=>[$ident,$label]){
                if($key!=='at')$snapshot[$key]=(!$transcript&&in_array($key,['spoken','answer'],true))
                    ?'':self::lastAlexaText($entry[$key]??'',800);
                $save($ident,$snapshot[$key]);
            }
            $save('iahLastAlexaInteraction',json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE
                |JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
        }finally{
            if($useLock)IPS_SemaphoreLeave($lock);
        }
    }

    private function applyAlexaNameToVoiceRule(array $result, string $name): array
    {
        if (empty($result['ok']) || !in_array($result['kind'] ?? '', ['answer', 'read'], true)) return $result;
        $answer = (string)($result['answer'] ?? '');
        $identity = ($result['ruleKey'] ?? '') === 'wer_bist_du' && $result['kind'] === 'answer';
        $oldDefault = $identity && $answer === 'Ich bin deine Alexa-Haussteuerung.';
        $template = 'Ich bin die Alexa {alexa}.';
        foreach (['answer', 'cardText'] as $field) {
            $text = (string)($result[$field] ?? '');
            if ($oldDefault && $text === $answer) $text = $template;
            if ($identity) $text = str_replace('{value}', '{alexa}', $text);
            $result[$field] = $name === '' && $text === $template
                ? 'Ich bin deine Alexa-Haussteuerung.'
                : str_replace('{alexa}', $name !== '' ? $name : 'unbekanntes Alexa-Gerät', $text);
        }
        return $result;
    }

    private function patchActionScriptLastAlexaInteraction(int $scriptId): void
    {
        $current = $this->getScriptContent($scriptId);
        $template = @file_get_contents(__DIR__ . '/../resources/action_entry.php');
        if (!is_string($current) || $current === '' || !is_string($template)) return;
        $hooks = [
            'LEGACY' => ['        $writeRuntimeString = static function ($varId, string $value): void {', true],
            'CURRENT_SLOTS' => ['        // Helfer-Skripte', false],
            'RENDER' => ["            \$speech     = (string)(\$data['speech'] ?? '');", true],
            'RENDER_KI' => ["                            \$speechKi     = (string)(\$dataKi['speech'] ?? '');", true],
            'RENDER_ERROR' => ["                return TellResponse::CreatePlainText('Fehler beim Rendern.');", false],
            'APL_FALLBACK' => [<<<'PHP'
            return AskResponse::CreatePlainText($APL['a2'] . " ausgewählt")->SetRepromptPlainText('');
PHP, false],
            'NO_MATCH' => ["        return AskResponse::CreatePlainText('Konnte kein Gerät verstehen. Wie bitte?')->SetRepromptPlainText('Wie bitte?');", false],
            'EXCEPTION' => ["        return TellResponse::CreatePlainText('Ein Fehler ist aufgetreten! Andreas sagen!');", false],
            'SAVE' => ['        // "Ende" nur bei LOG_LEVEL=debug', false],
        ];
        $next = $current;
        try {
            foreach ($hooks as $key => [$anchor, $after]) {
                $begin = '// IAH_LAST_ALEXA_' . $key . '_BEGIN';
                $end = '// IAH_LAST_ALEXA_' . $key . '_END';
                $pattern = '/^[ \t]*' . preg_quote($begin, '/') . '\R.*?^[ \t]*' . preg_quote($end, '/') . '(?=\r?$)/ms';
                if (preg_match_all($pattern, $template, $blocks) !== 1)
                    throw new RuntimeException('Vorlagenabschnitt fehlt: ' . $key);
                if (str_contains($next, $begin) || str_contains($next, $end)) {
                    if (substr_count($next, $begin) !== 1 || substr_count($next, $end) !== 1
                        || preg_match_all($pattern, $next) !== 1)
                        throw new RuntimeException('Action-Abschnitt uneindeutig: ' . $key);
                    $next = preg_replace_callback($pattern, static fn(): string => $blocks[0][0], $next, 1);
                } elseif (substr_count($next, $anchor) === 1) {
                    $next = str_replace($anchor, $after ? $anchor . "\n" . $blocks[0][0] : $blocks[0][0] . "\n" . $anchor, $next);
                } elseif (in_array($key, ['LEGACY', 'CURRENT_SLOTS', 'SAVE'], true)) {
                    throw new RuntimeException('Action-Anker fehlt: ' . $key);
                }
            }
            if ($next === $current) return;
            token_get_all($next, TOKEN_PARSE);
            $backupId = $this->createNavigationCatalogBackup($scriptId, 'LastAlexaInteraction', $current);
            IPS_SetScriptContent($scriptId, $next);
            if ($this->getScriptContent($scriptId) !== $next) {
                IPS_SetScriptContent($scriptId, $current);
                throw new RuntimeException('Action nach Update nicht lesbar, Backup #' . $backupId);
            }
            IPS_LogMessage('Alexa', 'LastAlexaInteraction: Action aktualisiert, Backup #' . $backupId);
        } catch (Throwable $error) {
            IPS_LogMessage('Alexa', 'LastAlexaInteraction: Action-Update fehlgeschlagen. ' . $error->getMessage());
        }
    }
}
