<?php
declare(strict_types=1);

/** V3.7 – UI/EDIT/PROD for user-defined voice scenes and answers. */
trait IAHVoiceRuleEditorTrait
{
    /**
     * Read the currently published SystemConfiguration rather than a stale
     * OPcache copy. IPS_SetScriptContent can update the script while another
     * PHP worker still has the old compiled include cached.
     *
     * Only the PROD config is used for execution; EDIT is never a fallback.
     */
    private function readFreshVoiceRuleProd(int $prodId): ?array
    {
        if($prodId<=0 || !IPS_ScriptExists($prodId))return null;
        $path=@IPS_GetScriptFile($prodId);
        if(is_string($path) && $path!=='' && is_file($path)
            && function_exists('opcache_invalidate')){
            // The update is local to the compiled file; no script executed.
            @opcache_invalidate($path,true);
        }
        return $this->readConfigurationArrayFromScript($prodId);
    }

    private function voiceRuleContext(): array
    {
        $ctx=$this->navigationEditorContext();
        $prodId=(int)($ctx['ids']['prod']??0);
        $ctx['prodSource']=$this->getScriptContent($prodId);
        // An IPS_GetScriptContent snapshot can contain the current rules
        // while a cached PHP include returns an older array or nothing at all.
        // Prefer strict literal parsing of the very same source bytes used
        // to generate the diagnostic SHA-256. It NEVER executes PHP.
        $literal=IAHVoiceRuleLiteralReader::read($ctx['prodSource']);
        if(is_array($literal)){
            $ctx['prod']=$literal;
            $ctx['prodLoadMethod']='literal_source';
        }else{
            // Preserve compatibility with explicitly customized, dynamic
            // SystemConfiguration scripts that cannot be parsed as literals.
            $ctx['prod']=$this->readFreshVoiceRuleProd($prodId);
            $ctx['prodLoadMethod']='legacy_include';
        }
        $ctx['prodId']=$prodId;
        $ctx['prodVoiceRuleCount']=is_array($ctx['prod'])
            ?count(IAHVoiceRuleEngine::rules($ctx['prod'])):0;
        return $ctx;
    }

    private function voiceRuleRows(array $edit, array $prod): array
    {
        $rows=[];$production=IAHVoiceRuleEngine::rules($prod);
        foreach(IAHVoiceRuleEngine::rules($edit) as $key=>$rule) {
            if(!is_array($rule))continue;
            $same=isset($production[$key]) && $production[$key]===$rule;
            $rows[]=[
                'key'=>$key,
                'title'=>(string)($rule['title']??$key),
                'aliases'=>implode(', ',IAHVoiceRuleEngine::phrases($rule['aliases']??[])),
                'kind'=>(string)($rule['kind']??'answer'),
                'intentName'=>(string)($rule['intentName']??''),
                'answer'=>(string)($rule['answer']??''),
                'imageUrl'=>(string)($rule['imageUrl']??''),
                'imageFile'=>(string)($rule['imageFile']??''),
                'cardTitle'=>(string)($rule['cardTitle']??''),
                'cardText'=>(string)($rule['cardText']??''),
                'scriptParams'=>json_encode((array)($rule['scriptParams']??[]),
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'scriptId'=>(int)($rule['scriptId']??0),
                'variableId'=>(int)($rule['variableId']??0),
                'variableValue'=>(string)($rule['variableValue']??''),
                'readVariableId'=>(int)($rule['readVariableId']??0),
                'origin'=>(string)($rule['origin']??'custom'),
                'migrationNote'=>(string)($rule['migrationNote']??''),
                'steps'=>count((array)($rule['steps']??[])),
                'enabled'=>!empty($rule['enabled']),
                'confirmed'=>!empty($rule['confirmed']),
                'enabledDisplay'=>!empty($rule['enabled'])?'Ja':'Nein',
                'confirmedDisplay'=>!empty($rule['confirmed'])?'Ja':'Nein',
                'status'=>$same?'PROD synchron':(isset($production[$key])?'Geaendert':'Nur in EDIT'),
                'revision'=>hash('sha256',serialize($rule))
            ];
        }
        uasort($rows,static fn(array $a,array $b):int=>
            strcasecmp($a['title'],$b['title']));
        return array_values($rows);
    }

    private function voiceRuleDeviceOptions(array $catalog): array
    {
        $options=[['caption'=>'-- Gerät wählen --','value'=>'']];
        foreach(IAHVoiceRuleEngine::capabilities($catalog) as $key=>$dev){
            if($dev['roles']===[])continue;
            $options[]=['caption'=>($dev['room']?:'Haus').' / '.$dev['name']
                .' ('.implode(', ',array_keys($dev['roles'])).')','value'=>$key];
        }
        usort($options,static fn(array $a,array $b):int=>
            strcasecmp($a['caption'],$b['caption']));
        return $options;
    }

    private function voiceRuleUtterances(array $edit): string
    {
        $lines=['Sprachbefehle / Alternativnamen für das Alexa-Interaktionsmodell:'];
        foreach(IAHVoiceRuleEngine::rules($edit) as $item){
            if(!is_array($item))continue;
            $lines[]=($item['enabled']??false?'[aktiv] ':'[Entwurf] ')
                .(string)($item['title']??'').': '
                .implode(' / ',IAHVoiceRuleEngine::phrases($item['aliases']??[]))
                .(!empty($item['intentName'])?' | Alexa-Intent: '.$item['intentName']:'');
        }
        $lines[]='Hinweis: Neue frei gesprochene Sätze werden erst erkannt, '
            .'wenn das Alexa-Skill-Interaktionsmodell sie auch als Intent/Slot erfassen kann.';
        return implode("\n",$lines);
    }

    private function buildVoiceRuleEditorForm(): array
    {
        $ctx=$this->voiceRuleContext();
        $edit=is_array($ctx['edit']??null)?$ctx['edit']:[];
        $prod=is_array($ctx['prod']??null)?$ctx['prod']:[];
        $kindOptions=[
            ['caption'=>'Antwort / Frage','value'=>'answer'],
            ['caption'=>'IP-Symcon-Skript','value'=>'script'],
            ['caption'=>'Geräteszene','value'=>'scene'],
            ['caption'=>'Variable schalten','value'=>'variable'],
            ['caption'=>'Variable abfragen','value'=>'read']
        ];
        $rows=$this->voiceRuleRows($edit,$prod);
        $name='VoiceRuleRows';
        $onEdit='IAH_SaveVoiceRule($id, json_encode(['
            .'"key"=>($'.$name.'["key"] ?? ""),'
            .'"revision"=>($'.$name.'["revision"] ?? ""),'
            .'"title"=>($'.$name.'["title"] ?? ""),'
            .'"aliases"=>($'.$name.'["aliases"] ?? ""),'
            .'"kind"=>($'.$name.'["kind"] ?? "answer"),'
            .'"intentName"=>($'.$name.'["intentName"] ?? ""),'
            .'"answer"=>($'.$name.'["answer"] ?? ""),'
            .'"imageUrl"=>($'.$name.'["imageUrl"] ?? ""),'
            .'"imageFile"=>($'.$name.'["imageFile"] ?? ""),'
            .'"cardTitle"=>($'.$name.'["cardTitle"] ?? ""),'
            .'"cardText"=>($'.$name.'["cardText"] ?? ""),'
            .'"scriptParams"=>($'.$name.'["scriptParams"] ?? "{}"),'
            .'"scriptId"=>(int)($'.$name.'["scriptId"] ?? 0),'
            .'"variableId"=>(int)($'.$name.'["variableId"] ?? 0),'
            .'"variableValue"=>($'.$name.'["variableValue"] ?? ""),'
            .'"readVariableId"=>(int)($'.$name.'["readVariableId"] ?? 0),'
            .'"enabled"=>(bool)($'.$name.'["enabled"] ?? false),'
            .'"confirmed"=>(bool)($'.$name.'["confirmed"] ?? false)'
            .']));';
        $form='return json_decode(IAH_GetVoiceRuleEditForm($id, '
            .'(string)($VoiceRuleRows["key"] ?? "")),true);';
        $notice=$this->ReadAttributeString('VoiceRuleEditorNotice');
        return [
            'type'=>'ExpansionPanel','caption'=>'V3.7.1 – Szenen und eigene Alexa-Befehle',
            'expanded'=>false,'items'=>[
                ['type'=>'Label','caption'=>'Alle Sprachbefehle, Alternativnamen, '
                    .'Fragen/Antworten, Bildkarten und Szenen werden zentral '
                    .'in SystemConfigurationEdit gespeichert. Erst nach '
                    .'expliziter Vorschau und PROD-Übernahme werden sie live.'],
                ['type'=>'Label','caption'=>'„Guten Abend“ als Szene '
                    .'oder IPS-Skript, „Wer ist Andreas“ als eigene Antwort: '
                    .'Wortlaut und Bild frei festlegen. Für slotlose '
                    .'Alexa-Befehle kann ein eigener Skill-Intent wie '
                    .'GutenAbendIntent zugeordnet werden. Die Beispiel-Sätze '
                    .'müssen im Alexa Developer-Modell hinterlegt sein. '
                    .'Bestehende Jalousie-Bilder bleiben erhalten.'],
                ['type'=>'Label','caption'=>'Sicherheit: Skripte und Geräteszenen '
                    .'müssen eigens bestätigt und aktiviert werden. '
                    .'Aktiv und Bestätigt bitte gemeinsam über das Zahnrad setzen. '
                    .'Geräteszenen verwenden nur im produktiven RoomsCatalog '
                    .'fachlich bestätigte Schreibfähigkeiten. '
                    .'Ein ausgewähltes Skript kann eigenständig Geräte steuern: '
                    .'bitte nur zuvor geprüftes IP-Symcon-Skript auswählen.'],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'Button','caption'=>'ALTBEFEHLE PRÜFEN (VORSCHAU)',
                        'onClick'=>'IAH_PreviewLegacyVoiceRuleDrafts($id);'],
                    ['type'=>'Button','caption'=>'ALTANTWORTEN ALS ENTWURF EINFÜGEN',
                        'confirm'=>'Bestehende Befehle, Bilder, Texte und Aktionsziele '
                            .'als DEAKTIVIERTE Entwuerfe nach EDIT uebernehmen? '
                            .'PROD und Geraete bleiben unveraendert.',
                        'onClick'=>'IAH_ImportLegacyVoiceRuleDrafts($id);'],
                    ['type'=>'Button','caption'=>'ALLE DEAKTIVIERTEN ALT-ENTWÜRFE AUS EDIT LÖSCHEN',
                        'confirm'=>'Wirklich ALLE deaktivierten importierten ALT-Entwuerfe aus EDIT '
                            .'loeschen? Eigene Regeln und PROD bleiben unveraendert.',
                        'onClick'=>'IAH_DeleteLegacyVoiceRuleDrafts($id);'],
                    ['type'=>'Button','caption'=>'KONFIG ALS JSON AUSGEBEN',
                        'onClick'=>'IAH_ExportVoiceRulesDiagnostic($id);'],
                    ['type'=>'Button','caption'=>'EDITOR NEU LADEN',
                        'onClick'=>'IAH_ReloadVoiceRules($id);'],
                ]],
                ['type'=>'CheckBox','name'=>'VoiceRulesAiReadOnlyEnabled',
                    'caption'=>'KI-Erkennung fuer sinngemaesse Fragen erlauben (nur '
                        .'Antworten/Messwerte, keine Skripte oder Schaltaktionen; '
                        .'nutzt die vorhandene KI-API-Konfiguration)',
                    'value'=>method_exists($this,'ReadPropertyBoolean')
                        ?$this->ReadPropertyBoolean('VoiceRulesAiReadOnlyEnabled'):false],
                IAHVoiceRulePreviewForm::panel('IAH_ShowVoiceRuleTestPreview($id, '
                    .'(string)$VoiceTestText, (string)$VoiceTestSource, '
                    .'(string)$VoiceTestIntent, (bool)$VoiceTestAi);'),
                ['type'=>'Label','name'=>'VoiceRuleEditorNotice',
                    'caption'=>$notice,'visible'=>$notice!==''],
                ['type'=>'List','name'=>$name,
                    'caption'=>'Eigene Befehle, Szenen und Antworten',
                    'rowCount'=>12,'add'=>false,'delete'=>true,
                    'onDelete'=>'IAH_DeleteVoiceRule($id, '
                        .'(string)($VoiceRuleRows["key"] ?? ""), '
                        .'(string)($VoiceRuleRows["revision"] ?? ""));',
                    'loadValuesFromConfiguration'=>false,
                    'columns'=>[
                        ['caption'=>'Key','name'=>'key','visible'=>false,'save'=>false],
                        ['caption'=>'Name','name'=>'title','width'=>'160px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Sprachbefehle / alternative Namen','name'=>'aliases',
                            'width'=>'250px','edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Typ','name'=>'kind','width'=>'110px',
                            'edit'=>['type'=>'Select','options'=>$kindOptions]],
                        ['caption'=>'Alexa-Intent','name'=>'intentName','width'=>'145px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Antworttext','name'=>'answer','width'=>'210px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Bild-URL','name'=>'imageUrl','width'=>'120px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Bilddatei','name'=>'imageFile','width'=>'135px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Kartentitel','name'=>'cardTitle','width'=>'120px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Kartentext','name'=>'cardText','width'=>'140px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Skriptparameter JSON','name'=>'scriptParams','width'=>'170px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Zielvariable','name'=>'variableId','width'=>'110px',
                            'edit'=>['type'=>'SelectVariable']],
                        ['caption'=>'Schaltwert','name'=>'variableValue','width'=>'85px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['caption'=>'Lesevariable','name'=>'readVariableId','width'=>'115px',
                            'edit'=>['type'=>'SelectVariable']],
                        ['caption'=>'Herkunft','name'=>'origin','width'=>'85px','save'=>false],
                        ['caption'=>'Übernahmehinweis','name'=>'migrationNote','width'=>'200px','save'=>false],
                        ['caption'=>'IPS-Skript','name'=>'scriptId','width'=>'120px',
                            'edit'=>['type'=>'SelectScript']],
                        ['caption'=>'Geräteaktionen','name'=>'steps','width'=>'88px',
                            'save'=>false],
                        // Status only in the table: checkbox inline editing would
                        // save each flag separately, reject incomplete settings,
                        // and reset the other selected fields. Change both in gear.
                        ['caption'=>'Aktiv','name'=>'enabledDisplay','width'=>'65px',
                            'save'=>false],
                        ['caption'=>'Bestätigt','name'=>'confirmedDisplay','width'=>'80px',
                            'save'=>false],
                        ['name'=>'enabled','visible'=>false,'save'=>true],
                        ['name'=>'confirmed','visible'=>false,'save'=>true],
                        ['caption'=>'Status','name'=>'status','width'=>'115px',
                            'save'=>false],
                        ['caption'=>'Revision','name'=>'revision','visible'=>false,
                            'save'=>false]
                    ],
                    'values'=>$rows,
                    'form'=>$form,'onEdit'=>$onEdit
                ],
                ['type'=>'Label','caption'=>'Neuen Sprachbefehl in EDIT anlegen'],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'ValidationTextBox','name'=>'VoiceNewTitle','caption'=>'Name'],
                    ['type'=>'ValidationTextBox','name'=>'VoiceNewAlias',
                        'caption'=>'Satz (z. B. Guten Abend)'],
                    ['type'=>'Select','name'=>'VoiceNewKind',
                        'caption'=>'Typ','value'=>'answer','options'=>$kindOptions],
                    ['type'=>'Button','caption'=>'NEUEN BEFEHL ANLEGEN',
                        'onClick'=>'IAH_CreateVoiceRule($id, (string)$VoiceNewTitle, '
                            .'(string)$VoiceNewAlias, (string)$VoiceNewKind);']
                ]],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'Button','caption'=>'SPRECHREGELN PRÜFEN (VORSCHAU)',
                        'onClick'=>'IAH_PreviewVoiceRules($id);'],
                    ['type'=>'Button','caption'=>'GEPRÜFTE SPRECHREGELN NACH PROD',
                        'confirm'=>'Sprechregeln und Szenen aus SystemConfigurationEdit '
                            .'mit gesichertem Original-PROD-Backup aktivieren? '
                            .'Es werden keine Szenen ausgeführt.',
                        'onClick'=>'IAH_PublishVoiceRules($id);']
                ]],
                ['type'=>'ValidationTextBox','name'=>'VoiceRulesDiagnosticJson',
                    'caption'=>'Szenen & eigene Alexa-Befehle – kopierbare Diagnose '
                        .'(EDIT, PROD und letzte Alexa-Abfragen; ohne Systemkonfiguration)',
                    'multiline'=>true,'width'=>'100%',
                    'value'=>$this->ReadAttributeString('VoiceRulesDiagnosticJson')],
                ['type'=>'ValidationTextBox','name'=>'VoiceRuleUtterances',
                    'caption'=>'Alexa-Sätze (für Skill-Interaktionsmodell)',
                    'multiline'=>true,'width'=>'100%',
                    'value'=>$this->voiceRuleUtterances($edit)],
            ]
        ];
    }

    public function GetVoiceRuleEditForm(string $key): string
    {
        try{
            $ctx=$this->voiceRuleContext();
            $rule=IAHVoiceRuleEngine::rules((array)($ctx['edit']??[]))[$key]??null;
            if(!is_array($rule))throw new RuntimeException('Sprachregel nicht gefunden.');
            $steps=[];
            foreach((array)($rule['steps']??[]) as $idx=>$step){
                if(!is_array($step))continue;
                $steps[]=['index'=>(int)$idx,'deviceKey'=>$step['deviceKey']??'',
                    'property'=>$step['property']??'',
                    'value'=>(string)($step['value']??'')];
            }
            $q=var_export($key,true);
            // Old V3.7.1 imports without open_close_var were accidentally
            // created as 'script'; present the proper control type when edited.
            $isOldBlind=in_array($key,['jalousien_oeffnen','jalousien_schliessen'],true)
                && ($rule['origin']??'')==='legacy'
                && ($rule['kind']??'')==='script'
                && (int)($rule['scriptId']??0)===0;
            if($isOldBlind)$rule['kind']='variable';
            $deviceOptions=$this->voiceRuleDeviceOptions((array)($ctx['catalog']??[]));
            $propertyOptions=[
                ['caption'=>'Ein / Aus','value'=>'power'],
                ['caption'=>'Dimmer in Prozent','value'=>'brightness'],
                ['caption'=>'Jalousie-Sollposition 0–100','value'=>'targetPosition'],
                ['caption'=>'Heizungs-Sollwert °C','value'=>'targetTemperature']
            ];
            $items=[
                ['type'=>'Label','caption'=>'Sprechregel „'.(string)$rule['title'].'“: '
                    .'Hier eigene Antworten, Alternativnamen, Bild und Aktionen bearbeiten.'],
                ['type'=>'ValidationTextBox','name'=>'title','caption'=>'Name',
                    'value'=>(string)$rule['title']],
                ['type'=>'ValidationTextBox','name'=>'aliases',
                    'caption'=>'Alternative Sprachbefehle (Komma getrennt)',
                    'value'=>implode(', ',IAHVoiceRuleEngine::phrases($rule['aliases']??[]))],
                ['type'=>'Select','name'=>'kind','caption'=>'Typ',
                    'value'=>(string)$rule['kind'],
                    'options'=>[
                        ['caption'=>'Freie Antwort','value'=>'answer'],
                        ['caption'=>'IP-Symcon-Skript','value'=>'script'],
                        ['caption'=>'Geräteszene','value'=>'scene'],
                        ['caption'=>'Variable schalten','value'=>'variable'],
                        ['caption'=>'Variable abfragen','value'=>'read']
                    ]],
                ['type'=>'ValidationTextBox','name'=>'intentName',
                    'caption'=>'Optional: Alexa-Intent (z. B. GutenAbendIntent)',
                    'value'=>(string)($rule['intentName']??'')],
                ['type'=>'ValidationTextBox','name'=>'answer',
                    'caption'=>'Antworttext oder Rückmeldung',
                    'multiline'=>true,'value'=>(string)($rule['answer']??'')],
                ['type'=>'ValidationTextBox','name'=>'imageUrl',
                    'caption'=>'Bild: HTTPS-URL (alternativ zu lokaler Datei)',
                    'value'=>(string)($rule['imageUrl']??'')],
                ['type'=>'ValidationTextBox','name'=>'imageFile',
                    'caption'=>'Bilddatei aus user/icons (z. B. Abend.png)',
                    'value'=>(string)($rule['imageFile']??'')],
                ['type'=>'ValidationTextBox','name'=>'cardTitle',
                    'caption'=>'Alexa-Kartentitel (optional)',
                    'value'=>(string)($rule['cardTitle']??'')],
                ['type'=>'ValidationTextBox','name'=>'cardText',
                    'caption'=>'Alexa-Kartentext (optional)',
                    'multiline'=>true,'value'=>(string)($rule['cardText']??'')],
                ['type'=>'SelectVariable','name'=>'variableId',
                    'caption'=>'Schaltvariable aus IP-Symcon auswählen',
                    'value'=>(int)($rule['variableId']??0)],
                ['type'=>'ValidationTextBox','name'=>'variableValue',
                    'caption'=>'Fester Schaltwert (z. B. 1 oder 2)',
                    'value'=>(string)($rule['variableValue']??'')],
                ['type'=>'SelectVariable','name'=>'readVariableId',
                    'caption'=>'Messwertvariable für {value} auswählen',
                    'value'=>(int)($rule['readVariableId']??0)],
                ['type'=>'Label','caption'=>'Wichtig: Bei den alten globalen Jalousie-Befehlen '
                    .'fuer eine Integer-Schaltvariable 1=oeffnen und 2=schliessen '
                    .'verwenden, NICHT true. true/false gelten nur fuer Boolean-Variablen. '
                    .'Zuerst Variable und Wert waehlen, dann Aktiv UND Bestaetigt.'],
                ['type'=>'Label','caption'=>'Herkunft: '
                    .(string)($rule['origin']??'custom')
                    .' — '.(string)($rule['migrationNote']??'')],
                ['type'=>'SelectScript','name'=>'scriptId',
                    'caption'=>'IP-Symcon-Skript auswählen',
                    'value'=>(int)($rule['scriptId']??0)],
                ['type'=>'ValidationTextBox','name'=>'scriptParams',
                    'caption'=>'Skriptparameter als JSON-Objekt',
                    'multiline'=>true,
                    'value'=>json_encode((array)($rule['scriptParams']??[]),
                        JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],
                ['type'=>'Label','caption'=>'Beispiel: {"Richtung":"Auf","Position":0}. '
                    .'Die Werte werden als $_IPS-Parameter mit IPS_RunScriptEx '
                    .'übergeben, nicht aus Alexa-Freitext generiert. '
                    .'Bilddatei und Bild-URL sind Alternativen; eine lokale '
                    .'Bilddatei wird über den bestehenden HTTPS-Bilder-WebHook ausgeliefert.'],
                ['type'=>'CheckBox','name'=>'enabled','caption'=>'Regel aktiv',
                    'value'=>!empty($rule['enabled'])],
                ['type'=>'CheckBox','name'=>'confirmed',
                    'caption'=>'Skript-/Geräteaktion fachlich bestätigt',
                    'value'=>!empty($rule['confirmed'])],
                ['type'=>'Label','caption'=>'Für eine Geräteszene hier Aktionen '
                    .'aus dem RoomsCatalog auswählen. Zulässig sind nur '
                    .'fachlich bestätigte KI-Schreibrollen. '
                    .'Geräteaktionen nie per freier IPS-ID oder KI-Anweisung.'],
                ['type'=>'List','name'=>'VoiceRuleSteps',
                    'caption'=>'Geräte in dieser Szene',
                    'rowCount'=>max(3,min(12,count($steps))),'add'=>false,'delete'=>true,
                    'values'=>$steps,'loadValuesFromConfiguration'=>false,
                    'columns'=>[
                        ['caption'=>'Pos.','name'=>'index','width'=>'55px','save'=>false],
                        ['caption'=>'Gerät','name'=>'deviceKey','width'=>'260px','save'=>false],
                        ['caption'=>'Fähigkeit','name'=>'property','width'=>'145px','save'=>false],
                        ['caption'=>'Zielwert','name'=>'value','width'=>'100px','save'=>false]
                    ],
                    'onDelete'=>'IAH_DeleteVoiceRuleStep($id, '.$q.', '
                        .'(int)($VoiceRuleSteps["index"] ?? -1));'
                ],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'Select','name'=>'VoiceRuleStepDevice',
                        'caption'=>'Gerät aus RoomsCatalog',
                        'options'=>$deviceOptions,'value'=>''],
                    ['type'=>'Select','name'=>'VoiceRuleStepProperty',
                        'caption'=>'Fähigkeit',
                        'options'=>$propertyOptions,'value'=>'power'],
                    ['type'=>'ValidationTextBox','name'=>'VoiceRuleStepValue',
                        'caption'=>'Wert: true/false oder Zahl','value'=>''],
                    ['type'=>'Button','caption'=>'GERÄTEAKTION ZUR SZENE',
                        'onClick'=>'IAH_AddVoiceRuleStep($id, '.$q.', '
                            .'(string)$VoiceRuleStepDevice, '
                            .'(string)$VoiceRuleStepProperty, '
                            .'(string)$VoiceRuleStepValue);']
                ]],
                ['type'=>'Button','caption'=>'DIESE SPRACHREGEL AUS EDIT LÖSCHEN',
                    'confirm'=>'Nur diese eine Sprachregel aus '
                        .'SystemConfigurationEdit löschen? '
                        .'PROD bleibt bis zur Freigabe unverändert.',
                    'onClick'=>'IAH_DeleteVoiceRule($id, '.$q.');']
            ];
            return json_encode($items,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        }catch(Throwable $e){
            return json_encode([['type'=>'Label','caption'=>'Editor nicht verfügbar: '.$e->getMessage()]],
                JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }

    private function voiceRuleNotice(string $text): void
    {
        $this->WriteAttributeString('VoiceRuleEditorNotice',$text);
        $this->UpdateFormField('VoiceRuleEditorNotice','caption',$text);
        $this->UpdateFormField('VoiceRuleEditorNotice','visible',true);
        echo $text;
    }

    private function writeVoiceRuleEdit(array $context,array $next): void
    {
        IAHVoiceRuleEngine::validateCollection($next);
        $this->saveSystemNavigationEdit($context,$next,'before_voice_rule_edit');
        $this->WriteAttributeString('VoiceRulesPublishPreview','');
    }

    public function CreateVoiceRule(string $title,string $alias,string $kind): void
    {
        try {
            $ctx=$this->voiceRuleContext();
            if(!is_array($ctx['edit']??null))throw new RuntimeException('EDIT fehlt.');
            $key='voice_'.substr(hash('sha256',$title.'|'.$alias.'|'.microtime(true)),0,16);
            $rule=[
                'title'=>$title,'aliases'=>[$alias],'kind'=>$kind,
                'answer'=>$kind==='answer'?'Hier bitte deine Antwort eintragen.':'',
                'imageUrl'=>'','imageFile'=>'','cardTitle'=>'','cardText'=>'',
                'scriptId'=>0,'scriptParams'=>[],'steps'=>[],
                'variableId'=>0,'variableValue'=>'','readVariableId'=>0,
                'enabled'=>false,'confirmed'=>false
            ];
            $next=IAHVoiceRuleEngine::change($ctx['edit'],$key,$rule);
            $this->writeVoiceRuleEdit($ctx,$next);
            $this->voiceRuleNotice('Neue deaktivierte Sprachregel in EDIT angelegt.');
            $this->ReloadVoiceRules();
        }catch(Throwable $e){$this->voiceRuleNotice('Nicht angelegt: '.$e->getMessage());}
    }

    public function SaveVoiceRule(string $payload): void
    {
        try{
            $data=json_decode($payload,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($data))throw new RuntimeException('Ungueltige Felder.');
            $ctx=$this->voiceRuleContext();
            $key=(string)($data['key']??'');
            $old=IAHVoiceRuleEngine::rules((array)$ctx['edit'])[$key]??null;
            if(!is_array($old))throw new RuntimeException('Regel nicht gefunden.');
            if(!hash_equals(hash('sha256',serialize($old)),(string)($data['revision']??'')))
                throw new RuntimeException('Eintrag geaendert. Bitte neu laden.');
            $input=$old;
            foreach(['title','aliases','kind','intentName','answer','imageUrl',
                'imageFile','cardTitle','cardText','scriptId','scriptParams',
                'variableId','variableValue','readVariableId',
                'enabled','confirmed'] as $field)
                if(array_key_exists($field,$data))$input[$field]=$data[$field];
            // Repair imports produced by the first V3.7.1 release.
            // A manually selected Schaltvariable must not disappear just
            // because that old imported rule was wrongly typed 'script'.
            if(in_array($key,['jalousien_oeffnen','jalousien_schliessen'],true)
                && ($old['origin']??'')==='legacy'
                && ($input['kind']??'')==='script'
                && (int)($input['scriptId']??0)===0
                && (int)($input['variableId']??0)>0){
                $input['kind']='variable';
                if(trim((string)($input['variableValue']??''))===''){
                    $input['variableValue']=$key==='jalousien_oeffnen'?'1':'2';
                }
            }
            $warning='';
            try{
                $next=IAHVoiceRuleEngine::change($ctx['edit'],$key,$input);
            }catch(RuntimeException $e){
                // Do not discard a correctly chosen variable/image/text just
                // because activation was premature. Save an inactive draft
                // and tell the user exactly why activation was rejected.
                if(empty($input['enabled']))throw $e;
                $input['enabled']=false;
                $next=IAHVoiceRuleEngine::change($ctx['edit'],$key,$input);
                $warning=' Aktiv bleibt AUS: '.$e->getMessage()
                    .' Bitte Zielvariable, Wert und Bestätigt prüfen.';
            }
            $this->writeVoiceRuleEdit($ctx,$next);
            $this->voiceRuleNotice('Sprachregel in EDIT gespeichert.'.$warning);
            $this->ReloadVoiceRules();
        }catch(Throwable $e){$this->voiceRuleNotice('Nicht gespeichert: '.$e->getMessage());}
    }

    public function DeleteVoiceRule(string $key,string $revision=''): void
    {
        try {
            $ctx=$this->voiceRuleContext();
            $current=IAHVoiceRuleEngine::rules((array)($ctx['edit']??[]))[$key]??null;
            if(!is_array($current))throw new RuntimeException('Regel nicht gefunden.');
            if($revision!=='' && !hash_equals(
                hash('sha256',serialize($current)),$revision))
                throw new RuntimeException('Regel zwischenzeitlich geaendert. Bitte neu laden.');
            $next=IAHVoiceRuleEngine::change($ctx['edit'],$key,[],true);
            $this->writeVoiceRuleEdit($ctx,$next);
            $this->voiceRuleNotice('Sprachregel aus EDIT gelöscht. PROD unverändert.');
            $this->ReloadVoiceRules();
        }catch(Throwable $e){$this->voiceRuleNotice('Nicht gelöscht: '.$e->getMessage());}
    }

    public function AddVoiceRuleStep(string $key,string $deviceKey,string $property,string $value): void
    {
        try{
            $ctx=$this->voiceRuleContext();
            $old=IAHVoiceRuleEngine::rules((array)$ctx['edit'])[$key]??null;
            if(!is_array($old))throw new RuntimeException('Sprachregel nicht gefunden.');
            if((string)$old['kind']!=='scene')
                throw new RuntimeException('Geräteaktionen gibt es nur bei Szenen.');
            $caps=IAHVoiceRuleEngine::capabilities((array)($ctx['catalog']??[]));
            if(!isset($caps[$deviceKey]['roles'][$property]))
                throw new RuntimeException('Gerät oder Fähigkeit nicht freigegeben.');
            $old['steps'][]=['deviceKey'=>$deviceKey,'property'=>$property,'value'=>$value];
            // Full scene validation, including value type/range and all existing steps.
            IAHVoiceRuleEngine::planScene($old,(array)($ctx['catalog']??[]));
            $next=IAHVoiceRuleEngine::change($ctx['edit'],$key,$old);
            $this->writeVoiceRuleEdit($ctx,$next);
            $this->voiceRuleNotice('Geräteaktion in Szene (EDIT) hinzugefügt.');
            $this->ReloadVoiceRules();
        }catch(Throwable $e){$this->voiceRuleNotice('Szene nicht geändert: '.$e->getMessage());}
    }

    public function DeleteVoiceRuleStep(string $key,int $index): void
    {
        try{
            $ctx=$this->voiceRuleContext();
            $old=IAHVoiceRuleEngine::rules((array)$ctx['edit'])[$key]??null;
            if(!is_array($old)||!array_key_exists($index,(array)($old['steps']??[])))
                throw new RuntimeException('Geräteaktion nicht gefunden.');
            array_splice($old['steps'],$index,1);
            $next=IAHVoiceRuleEngine::change($ctx['edit'],$key,$old);
            $this->writeVoiceRuleEdit($ctx,$next);
            $this->voiceRuleNotice('Geräteaktion aus EDIT entfernt.');
            $this->ReloadVoiceRules();
        }catch(Throwable $e){$this->voiceRuleNotice('Nicht entfernt: '.$e->getMessage());}
    }

    private function legacyVoiceRuleDrafts(array $ctx): array
    {
        return IAHVoiceLegacyMigration::drafts(
            (array)($ctx['catalog']??[]),
            $this->ReadPropertyInteger('VarAussenTemp')
        );
    }

    public function PreviewLegacyVoiceRuleDrafts(): void
    {
        try{
            $ctx=$this->voiceRuleContext();
            $drafts=$this->legacyVoiceRuleDrafts($ctx);
            $existing=IAHVoiceRuleEngine::rules((array)($ctx['edit']??[]));
            $lines=[];
            foreach($drafts as $key=>$rule){
                $parts=[$rule['title'],'Typ: '.$rule['kind']];
                if(!empty($rule['imageFile']))$parts[]='Bild: '.$rule['imageFile'];
                if(!empty($rule['imageUrl']))$parts[]='Bild-URL vorhanden';
                if(!empty($rule['variableId']))$parts[]='Schaltvariable: '.$rule['variableId'];
                if(!empty($rule['readVariableId']))$parts[]='Lesevariable: '.$rule['readVariableId'];
                $parts[]=isset($existing[$key])?'bereits in EDIT':'neuer Entwurf';
                $lines[]=implode(' | ',$parts);
            }
            $this->voiceRuleNotice('Nur Vorschau: '.count($drafts)
                .' ALT-Befehle gefunden, noch nichts uebernommen. '
                .implode(' ; ',$lines));
        }catch(Throwable $e){
            $this->voiceRuleNotice('ALT-Vorschau fehlgeschlagen: '.$e->getMessage());
        }
    }

    public function ImportLegacyVoiceRuleDrafts(): void
    {
        try{
            $ctx=$this->voiceRuleContext();
            $next=(array)($ctx['edit']??[]);
            $count=0;$skipped=[];
            foreach($this->legacyVoiceRuleDrafts($ctx) as $key=>$rule){
                if(isset(IAHVoiceRuleEngine::rules($next)[$key])){
                    $skipped[]=$key.' (vorhanden)';
                    continue;
                }
                try{
                    $next=IAHVoiceRuleEngine::change($next,$key,$rule);
                    $count++;
                }catch(Throwable $e){
                    $skipped[]=$key.' ('.$e->getMessage().')';
                }
            }
            if($count)$this->writeVoiceRuleEdit($ctx,$next);
            $this->voiceRuleNotice($count.' alte Befehle als DEAKTIVIERTE '
                .'EDIT-Entwuerfe mit Bildern und technischen Zielen uebernommen. '
                .'Keine alte Steuerung gestartet, PROD unveraendert.'
                .($skipped?' Uebersprungen: '.implode('; ',$skipped):''));
            $this->ReloadVoiceRules();
        }catch(Throwable $e){
            $this->voiceRuleNotice('ALT-Uebernahme fehlgeschlagen: '.$e->getMessage());
        }
    }

    public function DeleteLegacyVoiceRuleDrafts(): void
    {
        try{
            $ctx=$this->voiceRuleContext();
            $next=(array)($ctx['edit']??[]);
            $count=0;
            foreach(IAHVoiceRuleEngine::rules($next) as $key=>$rule){
                if(($rule['origin']??'')!=='legacy'||!empty($rule['enabled']))continue;
                $next=IAHVoiceRuleEngine::change($next,$key,[],true);
                $count++;
            }
            if($count)$this->writeVoiceRuleEdit($ctx,$next);
            $this->voiceRuleNotice($count.' deaktivierte importierte ALT-Entwuerfe aus EDIT '
                .'geloescht. Eigene Regeln, PROD, Originalskripte und Bilder erhalten.');
            $this->ReloadVoiceRules();
        }catch(Throwable $e){
            $this->voiceRuleNotice('ALT-Entwuerfe nicht geloescht: '.$e->getMessage());
        }
    }

    public function ReloadVoiceRules(): void
    {
        $ctx=$this->voiceRuleContext();
        $edit=(array)($ctx['edit']??[]);
        $rows=$this->voiceRuleRows($edit,(array)($ctx['prod']??[]));
        $this->UpdateFormField('VoiceRuleRows','values',
            json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('VoiceRuleUtterances','value',
            $this->voiceRuleUtterances($edit));
    }

    /**
     * Export ONLY the two voiceRules collections. Never export the surrounding
     * SystemConfiguration (tokens, API keys and app credentials).
     * Read-only inspection; does not call IPS_RunScript or RequestAction.
     */
    private function diagnosticVoiceRules(array $configuration): array
    {
        $out=[];
        foreach(IAHVoiceRuleEngine::rules($configuration) as $key=>$rule){
            if(!is_array($rule))continue;
            $entry=[];
            foreach(['title','aliases','kind','intentName','answer','imageUrl',
                'imageFile','cardTitle','cardText','scriptId','variableId',
                'variableValue','readVariableId','steps','enabled','confirmed',
                'origin','migrationNote'] as $field)
                $entry[$field]=$rule[$field]??null;
            $params=(array)($rule['scriptParams']??[]);
            $safeParams=[];
            foreach($params as $param=>$value){
                $secretName=preg_match(
                    '/(?:password|passwort|token|secret|api.?key|authorization|auth|credential|private.?key)/i',
                    (string)$param)===1;
                $secretValue=is_string($value) && preg_match(
                    '/(?:sk-proj-|sk-|ghp_|github_pat_|AIza)[A-Za-z0-9_-]{12,}/',
                    $value)===1;
                $safeParams[$param]=($secretName||$secretValue)?'[REDACTED]':$value;
            }
            $entry['scriptParams']=$safeParams;
            // A signed or tokenized image URL must not leak a query secret.
            if(is_string($entry['imageUrl']) && str_contains($entry['imageUrl'],'?'))
                $entry['imageUrl']=explode('?',$entry['imageUrl'],2)[0].'?[QUERY_REDACTED]';
            try{
                IAHVoiceRuleEngine::validateRule($rule);
                $entry['validation']='ok';
            }catch(Throwable $e){
                $entry['validation']='invalid: '.$e->getMessage();
            }
            $entry['scriptAvailable']=(int)($entry['scriptId']??0)>0
                ?(function_exists('IPS_ScriptExists')
                    && IPS_ScriptExists((int)$entry['scriptId'])):null;
            foreach(['variableId','readVariableId'] as $idField){
                $id=(int)($entry[$idField]??0);
                $entry[$idField.'Exists']=$id>0
                    ?(function_exists('IPS_VariableExists')&&IPS_VariableExists($id)):null;
            }
            if($entry['imageFile']!==''){
                $filename=(string)$entry['imageFile'];
                $safeFile=preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\\.(?:png|jpg|jpeg|gif)$/iD',$filename)
                    && !str_contains($filename,'..');
                $entry['imageFileExists']=$safeFile && function_exists('IPS_GetKernelDir')
                    ?is_file(IPS_GetKernelDir().'user'.DIRECTORY_SEPARATOR
                        .'icons'.DIRECTORY_SEPARATOR.$filename):null;
            }
            $out[$key]=$entry;
        }
        return $out;
    }

    private function voiceRuleDiagnosticsData(): array
    {
        $ctx=$this->voiceRuleContext();
        $edit=$this->diagnosticVoiceRules((array)($ctx['edit']??[]));
        $prod=$this->diagnosticVoiceRules((array)($ctx['prod']??[]));
        $summary=[
            'editTotal'=>count($edit),
            'prodTotal'=>count($prod),
            'editActive'=>count(array_filter($edit,static fn(array $r):bool=>!empty($r['enabled']))),
            'prodActive'=>count(array_filter($prod,static fn(array $r):bool=>!empty($r['enabled']))),
            'onlyEdit'=>array_values(array_diff(array_keys($edit),array_keys($prod))),
            'onlyProd'=>array_values(array_diff(array_keys($prod),array_keys($edit))),
            'different'=>[]
        ];
        foreach(array_intersect(array_keys($edit),array_keys($prod)) as $key)
            if($edit[$key]!==$prod[$key])$summary['different'][]=$key;
        // Resolve the generated action without mutating object idents.
        $rendererParent=(int)@IPS_GetObjectIDByIdent('iahRenderers',$this->InstanceID);
        $actionId=$rendererParent>0
            ?(int)@IPS_GetObjectIDByIdent('iahActionEntry',$rendererParent):0;
        if($actionId<=0 && $rendererParent>0)
            $actionId=(int)@IPS_GetObjectIDByName(
                'Action (Haus\\\\Übersicht/Einstellungen Entry)',$rendererParent);
        if($actionId<=0)
            $actionId=(int)@IPS_GetObjectIDByIdent('iahActionEntry',$this->InstanceID);
        $actionSource=$actionId>0?$this->getScriptContent($actionId):null;
        $trace=json_decode($this->ReadAttributeString('VoiceRulesDiagnosticTrace'),true);
        return [
            'schema'=>'IAHVoiceRulesDiagnostics/1',
            'generatedAt'=>date('c'),
            'scope'=>'voiceRules EDIT + PROD only; no other SystemConfiguration fields',
            'summary'=>$summary,
            'bridge'=>[
                'actionScriptId'=>$actionId,
                'actionScriptExists'=>$actionId>0 && function_exists('IPS_ScriptExists')
                    && IPS_ScriptExists($actionId),
                'voiceRulesPatchPresent'=>is_string($actionSource)
                    && str_contains($actionSource,'IAH_VOICE_RULES_V37_BEGIN')
                    && str_contains($actionSource,'IAH_ResolveVoiceRule('),
                'hint'=>'A spoken rule is usable only if Alexa supplies the phrase '
                    .'as a slot/transcript or a registered matching custom Intent.'
            ],
            'selfTests'=>$this->voiceRuleSelfTests((array)($ctx['prod']??[])),
            'runtimeSource'=>[
                'prodScriptId'=>(int)($ctx['prodId']??0),
                'prodSourceSha256'=>hash('sha256',(string)($ctx['prodSource']??'')),
                'prodLoadMethod'=>(string)($ctx['prodLoadMethod']??'unknown'),
                'prodRulesRead'=>count($prod),
                'prodSchemaVersion'=>(int)($ctx['prod']['voiceRules']['schemaVersion']??0),
                'prodLoadMethod'=>(string)($ctx['prodLoadMethod']??'unknown')
            ],
            'edit'=>$edit,
            'prod'=>$prod,
            'recentRecognition'=>is_array($trace)?array_slice($trace,-30):[]
        ];
    }

    /** Check each active PROD phrase locally without running any action. */
    private function voiceRuleSelfTests(array $prod): array
    {
        $tests=[];
        foreach(IAHVoiceRuleEngine::rules($prod) as $key=>$rule){
            if(!is_array($rule)||empty($rule['enabled']))continue;
            $phrase=IAHVoiceRuleEngine::phrases($rule['aliases']??[])[0]??'';
            $match=IAHVoiceRuleEngine::matching($prod,$phrase);
            $matchedKey=(string)($match['key']??'');
            $tests[$key]=[
                'phrase'=>$phrase,
                'matchedKey'=>$matchedKey,
                'ok'=>$matchedKey===$key,
                'status'=>isset($match['status'])?(string)$match['status']:''
            ];
        }
        return $tests;
    }

    public function ExportVoiceRulesDiagnostic(): void
    {
        try{
            $json=json_encode($this->voiceRuleDiagnosticsData(),
                JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE
                |JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
            $this->WriteAttributeString('VoiceRulesDiagnosticJson',$json);
            $this->UpdateFormField('VoiceRulesDiagnosticJson','value',$json);
            $this->voiceRuleNotice('Szenen/Sprachbefehle: kopierbares JSON '
                .'erstellt (EDIT, PROD, letzte Erkennungen). '
                .'Enthaelt keine Systemkonfiguration oder API-Tokens.');
        }catch(Throwable $e){
            $this->voiceRuleNotice('JSON-Diagnose fehlgeschlagen: '.$e->getMessage());
        }
    }

    private function recordVoiceRuleRecognition(
        array $event,array $result,string $reason='',array $meta=[]
    ): void
    {
        try{
            $trace=json_decode($this->ReadAttributeString('VoiceRulesDiagnosticTrace'),true);
            if(!is_array($trace))$trace=[];
            $trace[]=[
                'time'=>date('c'),
                'intent'=>mb_substr((string)($event['intent']??''),0,90,'UTF-8'),
                'spoken'=>mb_substr(trim((string)($event['text']??'')),0,160,'UTF-8'),
                'normalized'=>IAHVoiceRuleEngine::normalize((string)($event['text']??'')),
                'matched'=>!empty($result['matched']),
                'ruleKey'=>(string)($result['ruleKey']??''),
                'kind'=>(string)($result['kind']??''),
                'ok'=>!empty($result['ok']),
                'matchMethod'=>(string)($result['matchMethod']??'exact'),
                'prodScriptId'=>(int)($meta['prodScriptId']??0),
                'prodRuleCount'=>(int)($meta['prodRuleCount']??0),
                'prodSourceSha256'=>(string)($meta['prodSourceSha256']??''),
                'prodSchemaVersion'=>(int)($meta['prodSchemaVersion']??0),
                'prodLoadMethod'=>(string)($meta['prodLoadMethod']??'unknown'),
                'candidateIndex'=>(int)($event['candidateIndex']??0),
                'aiFallbackRequested'=>!empty($event['aiFallback']),
                'reason'=>$reason!==''?$reason:(
                    empty($result['matched'])?'no_exact_prod_phrase_or_custom_intent':
                    (empty($result['ok'])?'matched_but_not_executed':'executed_or_answered'))
            ];
            $this->WriteAttributeString('VoiceRulesDiagnosticTrace',
                json_encode(array_slice($trace,-30),JSON_UNESCAPED_UNICODE
                    |JSON_INVALID_UTF8_SUBSTITUTE));
        }catch(Throwable $e){
            IPS_LogMessage('Alexa','VoiceRules trace write failed: '.get_class($e));
        }
    }

    private function voiceRulePreviewPlan(array $ctx): array
    {
        $ids=(array)($ctx['ids']??[]);
        if(count(array_filter([$ids['prod']??0,$ids['edit']??0,$ids['backup']??0,
            $ctx['catalogId']??0],static fn($id):bool=>(int)$id>0))!==4
            || count(array_unique([$ids['prod'],$ids['edit'],$ids['backup'],$ctx['catalogId']]))!==4)
            throw new RuntimeException('SystemConfiguration/RoomsCatalog-Skript-ID fehlt.');
        foreach(['prodSource','editSource','catalogSource'] as $f)
            if(!is_string($ctx[$f]??null)
                || !IAHSystemNavigationEditor::isLiteralArraySource($ctx[$f]))
                throw new RuntimeException('Nur sichere statische PHP-Konfigurationen.');
        $prod=(array)($ctx['prod']??[]);
        $edit=(array)($ctx['edit']??[]);
        IAHVoiceRuleEngine::validateCollection($edit);
        $catalog=(array)($ctx['catalog']??[]);
        foreach(IAHVoiceRuleEngine::rules($edit) as $rule) {
            if(!is_array($rule)||empty($rule['enabled']))continue;
            if(($rule['kind']??'')==='scene')IAHVoiceRuleEngine::planScene($rule,$catalog);
            if(($rule['kind']??'')==='script') {
                $id=(int)($rule['scriptId']??0);
                if($id===0||!IPS_ScriptExists($id)
                    || in_array($id,[(int)$ids['prod'],(int)$ids['edit'],(int)$ids['backup'],
                        (int)$ctx['catalogId']],true))
                    throw new RuntimeException('Systemskript als Szene nicht erlaubt.');
            }
        }
        // Preserves the other PROD sections, including navigation and secrets.
        if(array_key_exists('voiceRules',$edit))$prod['voiceRules']=$edit['voiceRules'];
        else unset($prod['voiceRules']);
        return $prod;
    }

    public function PreviewVoiceRules(): void
    {
        $this->WriteAttributeString('VoiceRulesPublishPreview','');
        try{
            $ctx=$this->voiceRuleContext();
            $plan=$this->voiceRulePreviewPlan($ctx);
            $next=IAHSystemNavigationEditor::encode($plan);
            $p=['at'=>time(),'ids'=>$ctx['ids'],'catalogId'=>$ctx['catalogId'],
                'editHash'=>hash('sha256',$ctx['editSource']),
                'prodHash'=>hash('sha256',$ctx['prodSource']),
                'catalogHash'=>hash('sha256',$ctx['catalogSource']),
                'targetHash'=>hash('sha256',$next)];
            $this->WriteAttributeString('VoiceRulesPublishPreview',
                json_encode($p,JSON_THROW_ON_ERROR));
            $this->voiceRuleNotice(count(IAHVoiceRuleEngine::rules($plan))
                .' Sprechregeln geprüft. Keine Skripte gestartet und '
                .'keine Geräte geschaltet. PROD noch unverändert.');
        }catch(Throwable $e){$this->voiceRuleNotice('Vorschau blockiert: '.$e->getMessage());}
    }

    public function PublishVoiceRules(): void
    {
        try{
            $ctx=$this->voiceRuleContext();
            $p=json_decode($this->ReadAttributeString('VoiceRulesPublishPreview'),true);
            if(!is_array($p)||$p['ids']!==$ctx['ids']
                || (int)$p['catalogId']!==(int)$ctx['catalogId']
                || (int)($p['at']??0)<time()-600)
                throw new RuntimeException('Bitte aktuelle Vorschau ausführen.');
            foreach(['editHash'=>'editSource','prodHash'=>'prodSource',
                'catalogHash'=>'catalogSource'] as $h=>$field)
                if(!hash_equals((string)($p[$h]??''),hash('sha256',$ctx[$field])))
                    throw new RuntimeException('Quellen seit Vorschau verändert.');
            $plan=$this->voiceRulePreviewPlan($ctx);
            $next=IAHSystemNavigationEditor::encode($plan);
            if(!hash_equals((string)($p['targetHash']??''),hash('sha256',$next)))
                throw new RuntimeException('Ergebnis seit Vorschau verändert.');
            if($next===$ctx['prodSource']){
                $this->WriteAttributeString('VoiceRulesPublishPreview','');
                $this->voiceRuleNotice('PROD-Sprechregeln bereits identisch.');
                return;
            }
            $ids=$ctx['ids'];
            $this->appendNavigationBackup((int)$ids['backup'],(int)$ids['prod'],
                $ctx['prodSource'],'before_voice_rules_prod_v37');
            if($this->getScriptContent((int)$ids['prod'])!==$ctx['prodSource']
                || $this->getScriptContent((int)$ids['edit'])!==$ctx['editSource'])
                throw new RuntimeException('Skripte während Sicherung verändert.');
            try{
                IPS_SetScriptContent((int)$ids['prod'],$next);
                if($this->getScriptContent((int)$ids['prod'])!==$next)
                    throw new RuntimeException('PROD-Leseprüfung fehlgeschlagen.');
            }catch(Throwable $e){
                IPS_SetScriptContent((int)$ids['prod'],$ctx['prodSource']);
                if($this->getScriptContent((int)$ids['prod'])!==$ctx['prodSource'])
                    throw new RuntimeException('PROD-Rollback nicht verifiziert. Backup prüfen.');
                throw $e;
            }
            $this->WriteAttributeString('VoiceRulesPublishPreview','');
            $this->voiceRuleNotice('Sprechregeln nach PROD veröffentlicht. '
                .'Navigation/RoomsCatalog unverändert, Backup erfolgreich.');
        }catch(Throwable $e){$this->voiceRuleNotice('Nicht veröffentlicht: '.$e->getMessage());}
    }

    private function voiceRuleForbiddenScripts(array $ids,int $catalogId): array
    {
        $forbidden=array_map('intval',array_values($ids));
        $forbidden[]=$catalogId;
        foreach(['iahHelper','iahRenderers'] as $ident){
            $parent=(int)@IPS_GetObjectIDByIdent($ident,$this->InstanceID);
            if($parent<=0)continue;
            foreach(IPS_GetChildrenIDs($parent) as $childId)
                if(IPS_ScriptExists((int)$childId))$forbidden[]=(int)$childId;
        }
        return array_values(array_unique($forbidden));
    }

    private function matchVoiceRuleWithAi(array $configuration,string $spoken): ?array
    {
        if(!method_exists($this,'ReadPropertyBoolean')
            || !$this->ReadPropertyBoolean('VoiceRulesAiReadOnlyEnabled')
            || !$this->ReadPropertyBoolean('KIIntentEnabled'))return null;
        $key=$this->ReadPropertyString('KIIntentApiKey');
        $model=$this->ReadPropertyString('KIModelSelection');
        if($key==='')return null;
        $onAiResponse=function(array $apiResponse) use($model):void {
            if(!method_exists($this,'RecordKICostEvent'))return;
            try{
                $qid='voicerule-'.bin2hex(random_bytes(8));
                $events=[
                    ['type'=>'start','model'=>$model],
                    ['type'=>'api','callId'=>'voice-ai-0','response'=>[
                        'model'=>(string)($apiResponse['model']??$model),
                        'usage'=>$apiResponse['usage']??null
                    ]],
                    ['type'=>'finish','success'=>true]
                ];
                foreach($events as $item)
                    $this->RecordKICostEvent(json_encode(array_merge(
                        ['backend'=>'IntentParser','questionId'=>$qid],$item
                    ),JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
            }catch(Throwable $ignored){}
        };
        return IAHVoiceRuleAiMatcher::match($configuration,$spoken,
            static fn(array $allowed,string $text):?array =>
                IAHVoiceRuleAiMatcher::openAiCandidate($allowed,$text,$key,$model,$onAiResponse));
    }

    /** Text/intent dry run. Never include configuration PHP or call the live resolver. */
    public function GetVoiceRuleTestPreview(
        string $spoken,string $source='PROD',string $intent='',bool $useAi=false
    ): string {
        $result=['dryRun'=>true,'matched'=>false,'ok'=>false];
        try {
            if(!in_array($source,['EDIT','PROD'],true))
                throw new RuntimeException('Bitte EDIT oder PROD auswählen.');
            $result['source']=$source;
            $spoken=trim($spoken);
            $intent=trim($intent);
            if(strlen($spoken)>640||mb_strlen($spoken,'UTF-8')>160||strlen($intent)>62)
                throw new RuntimeException('Satz oder Alexa-Intent ist zu lang.');
            if($spoken===''&&$intent==='')
                throw new RuntimeException('Bitte einen Satz oder Alexa-Intent eingeben.');
            if($intent!==''&&!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,61}$/D',$intent))
                throw new RuntimeException('Alexa-Intent enthält ungültige Zeichen.');
            $result['spoken']=$spoken;
            $result['intent']=$intent;
            $result['normalized']=IAHVoiceRuleEngine::normalize($spoken);
            $settings=$this->getObjectIDByIdentOrName($this->InstanceID,'iahSettings','Einstellungen');
            $ids=$this->systemConfigurationScriptIds($settings);
            $scriptId=$ids[strtolower($source)];
            $result['scriptId']=$scriptId;
            $configuration=IAHVoiceRuleLiteralReader::read($this->getScriptContent($scriptId));
            if($configuration===null)
                throw new RuntimeException($source.' ist kein lesbares statisches PHP-Array. '
                    .'Dynamische Konfigurationsskripte werden im Test nicht ausgeführt.');
            $rules=IAHVoiceRuleEngine::rules($configuration);
            $result['ruleCount']=count($rules);
            $result['activeRuleCount']=count(array_filter($rules,
                static fn($rule):bool=>is_array($rule)&&!empty($rule['enabled'])));
            if(isset($configuration['voiceRules'])
                && (int)($configuration['voiceRules']['schemaVersion']??0)!==IAHVoiceRuleEngine::VERSION)
                throw new RuntimeException('Unbekanntes Datenmodell der Sprachregeln.');

            $match=IAHVoiceRuleEngine::matching($configuration,$spoken,$intent);
            if($match===null && $useAi)$match=$this->matchVoiceRuleWithAi($configuration,$spoken);
            if($match!==null){
                $catalogId=$settings>0
                    ?$this->getObjectIDByIdentOrName($settings,'roomsCatalog','RoomsCatalog'):0;
                $catalog=($match['rule']['kind']??'')==='scene'
                    ?IAHVoiceRuleLiteralReader::read($this->getScriptContent($catalogId)):null;
                $result=array_replace($result,IAHVoiceRuleEngine::preview($match,$catalog,
                    $this->voiceRuleForbiddenScripts($ids,$catalogId)));
            }else{
                $inactive=[];
                foreach($rules as $key=>$rule){
                    if(!is_array($rule)||!empty($rule['enabled']))continue;
                    $enabled=$rule;
                    $enabled['enabled']=true;
                    if(IAHVoiceRuleEngine::matching(['voiceRules'=>[
                        'schemaVersion'=>IAHVoiceRuleEngine::VERSION,'items'=>[$key=>$enabled]
                    ]],$spoken,$intent)!==null)$inactive[]=$key;
                }
                if($inactive!==[]){
                    $result['inactiveMatches']=$inactive;
                    $result['error']='Passende Regel ist in '.$source.' deaktiviert.';
                }
            }
        }catch(Throwable $error){
            $result['error']=$error->getMessage();
        }
        return json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE
            |JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    }

    public function ShowVoiceRuleTestPreview(string $spoken,string $source,string $intent,bool $useAi): void
    {
        $json=$this->GetVoiceRuleTestPreview($spoken,$source,$intent,$useAi);
        $this->UpdateFormField('VoiceTestResult','value',$json);
        $this->UpdateFormField('VoiceTestSummary','caption',
            IAHVoiceRulePreviewForm::summary(json_decode($json,true,512,JSON_THROW_ON_ERROR)));
    }

    /**
     * Called only from the real Alexa action script after exact text capture.
     * The SDK voice handler provides spoken text but NEVER a scriptId or
     * variable ID. Rule matching is against the published, allowlisted config.
     */
    public function ResolveVoiceRule(string $payloadJson): string
    {
        try{
            $event=json_decode($payloadJson,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($event) || !is_string($event['text']??null))
                throw new RuntimeException('Unbekanntes Alexa-Ereignis.');
            if(!empty($event['aplEvent'])||!empty($event['launch'])
                || !empty($event['exit']) || !empty($event['back']))
                return '{"matched":false}';
            $ctx=$this->voiceRuleContext();
            $rules=(array)($ctx['prod']??[]);
            $catalog=(array)($ctx['catalog']??[]);
            $forbidden=$this->voiceRuleForbiddenScripts($ctx['ids'],(int)$ctx['catalogId']);
            $prodRules=IAHVoiceRuleEngine::rules($rules);
            $meta=[
                'prodScriptId'=>(int)($ctx['prodId']??0),
                'prodRuleCount'=>count($prodRules),
                'prodSchemaVersion'=>(int)($rules['voiceRules']['schemaVersion']??0),
                'prodSourceSha256'=>hash('sha256',(string)($ctx['prodSource']??'')),
                'prodLoadMethod'=>(string)($ctx['prodLoadMethod']??'unknown')
            ];
            $result=IAHVoiceRuleEngine::run($rules,$catalog,$event['text'],
                ['intent'=>(string)($event['intent']??''),
                 'forbiddenScriptIds'=>array_values(array_unique($forbidden))]);
            // Only if the caller has exhausted all exact candidates may
            // optional KI classify a *question* into an enabled read-only rule.
            // No scripts/scenes/variables are included in the model allowlist.
            if(empty($result['matched']) && !empty($event['aiFallback']) && $prodRules!==[]){
                $aiMatch=$this->matchVoiceRuleWithAi($rules,$event['text']);
                if($aiMatch!==null){
                    $checked=IAHVoiceRuleEngine::run($rules,$catalog,$aiMatch['alias'],[
                        'intent'=>'','forbiddenScriptIds'=>$forbidden
                    ]);
                    if(!empty($checked['matched']) && !empty($checked['ok'])
                        && ($checked['ruleKey']??'')===$aiMatch['key']
                        && in_array($checked['kind']??'',['answer','read'],true)){
                        $checked['matchMethod']='ai_read_only';
                        $result=$checked;
                    }
                }
            }
            if(empty($result['matched']) && $prodRules===[]
                && is_string($ctx['prodSource']??null)
                && str_contains($ctx['prodSource'],"'voiceRules'")){
                $result=['matched'=>true,'ok'=>false,'kind'=>'unavailable',
                    'ruleKey'=>'prod_rules_unavailable',
                    'answer'=>'Die produktiven Sprachregeln konnten derzeit nicht '
                        .'eingelesen werden. Bitte die JSON-Diagnose pruefen.'];
            }
            // Never execute old hard-coded Alexa shortcuts. Defer this guard
            // while the Action is checking other exact phrase candidates.
            if(empty($result['matched']) && empty($event['skipLegacyFallback'])
                && IAHVoiceLegacyMigration::isRetiredPhrase(
                    $event['text'],$catalog,$this->ReadPropertyInteger('VarAussenTemp'))){
                $result=['matched'=>true,'ok'=>false,'kind'=>'retired',
                    'ruleKey'=>'legacy_disabled',
                    'answer'=>'Dieser alte Befehl ist deaktiviert. '
                        .'Bitte eine neue Sprachregel im Konfigurator anlegen.'];
            }
            $this->recordVoiceRuleRecognition($event,$result,'',$meta);
            if(!empty($result['matched']))
                IPS_LogMessage('Alexa','VoiceRules.V37 '.json_encode([
                    'key'=>$result['ruleKey']??'ambiguous',
                    'kind'=>$result['kind']??'unknown',
                    'ok'=>$result['ok']??false,
                    'executed'=>$result['executed']??0
                ],JSON_UNESCAPED_UNICODE));
            return json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        }catch(Throwable $e){
            if(isset($event) && is_array($event))
                $this->recordVoiceRuleRecognition($event,['matched'=>false],
                    'resolver_exception_'.get_class($e),
                    isset($meta)&&is_array($meta)?$meta:[]);
            IPS_LogMessage('Alexa','VoiceRules.V37 failed errorType='.get_class($e));
            return '{"matched":false}';
        }
    }
}
