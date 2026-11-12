<?php
declare(strict_types=1);

/** V3.7 – UI/EDIT/PROD for user-defined voice scenes and answers. */
trait IAHVoiceRuleEditorTrait
{
    private function voiceRuleContext(): array
    {
        $ctx=$this->navigationEditorContext();
        $prodId=(int)($ctx['ids']['prod']??0);
        $ctx['prodSource']=$this->getScriptContent($prodId);
        $ctx['prod']=$this->readConfigurationArrayFromScript($prodId);
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
                'scriptId'=>(int)($rule['scriptId']??0),
                'steps'=>count((array)($rule['steps']??[])),
                'enabled'=>!empty($rule['enabled']),
                'confirmed'=>!empty($rule['confirmed']),
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
            ['caption'=>'Geräteszene','value'=>'scene']
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
            .'"scriptId"=>(int)($'.$name.'["scriptId"] ?? 0),'
            .'"enabled"=>(bool)($'.$name.'["enabled"] ?? false),'
            .'"confirmed"=>(bool)($'.$name.'["confirmed"] ?? false)'
            .']));';
        $form='return json_decode(IAH_GetVoiceRuleEditForm($id, '
            .'(string)($VoiceRuleRows["key"] ?? "")),true);';
        $notice=$this->ReadAttributeString('VoiceRuleEditorNotice');
        return [
            'type'=>'ExpansionPanel','caption'=>'V3.7 – Szenen und eigene Alexa-Befehle',
            'expanded'=>true,'items'=>[
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
                    .'Geräteszenen verwenden nur im produktiven RoomsCatalog '
                    .'fachlich bestätigte Schreibfähigkeiten. '
                    .'Ein ausgewähltes Skript kann eigenständig Geräte steuern: '
                    .'bitte nur zuvor geprüftes IP-Symcon-Skript auswählen.'],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'Button','caption'=>'ALTANTWORTEN ALS ENTWURF EINFÜGEN',
                        'confirm'=>'Vorhandene hartcodierte Antwortbeispiele '
                            .'nur als DEAKTIVIERTE Entwürfe nach '
                            .'SystemConfigurationEdit übernehmen? '
                            .'Bisherige Funktionen bleiben unverändert.',
                        'onClick'=>'IAH_ImportLegacyVoiceRuleDrafts($id);'],
                    ['type'=>'Button','caption'=>'EDITOR NEU LADEN',
                        'onClick'=>'IAH_ReloadVoiceRules($id);'],
                ]],
                ['type'=>'Label','name'=>'VoiceRuleEditorNotice',
                    'caption'=>$notice,'visible'=>$notice!==''],
                ['type'=>'List','name'=>$name,
                    'caption'=>'Eigene Befehle, Szenen und Antworten',
                    'rowCount'=>12,'add'=>false,'delete'=>false,
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
                        ['caption'=>'IPS-Skript','name'=>'scriptId','width'=>'120px',
                            'edit'=>['type'=>'SelectScript']],
                        ['caption'=>'Geräteaktionen','name'=>'steps','width'=>'88px',
                            'save'=>false],
                        ['caption'=>'Aktiv','name'=>'enabled','width'=>'65px',
                            'edit'=>['type'=>'CheckBox']],
                        ['caption'=>'Bestätigt','name'=>'confirmed','width'=>'80px',
                            'edit'=>['type'=>'CheckBox']],
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
                        ['caption'=>'Geräteszene','value'=>'scene']
                    ]],
                ['type'=>'ValidationTextBox','name'=>'intentName',
                    'caption'=>'Optional: Alexa-Intent (z. B. GutenAbendIntent)',
                    'value'=>(string)($rule['intentName']??'')],
                ['type'=>'ValidationTextBox','name'=>'answer',
                    'caption'=>'Antworttext oder Rückmeldung',
                    'multiline'=>true,'value'=>(string)($rule['answer']??'')],
                ['type'=>'ValidationTextBox','name'=>'imageUrl',
                    'caption'=>'Öffentliche HTTPS-URL für optionales Alexa-Bild',
                    'value'=>(string)($rule['imageUrl']??'')],
                ['type'=>'SelectScript','name'=>'scriptId',
                    'caption'=>'IP-Symcon-Skript auswählen',
                    'value'=>(int)($rule['scriptId']??0)],
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
                'imageUrl'=>'','scriptId'=>0,'steps'=>[],
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
            foreach(['title','aliases','kind','intentName','answer','imageUrl','scriptId','enabled','confirmed'] as $field)
                if(array_key_exists($field,$data))$input[$field]=$data[$field];
            $next=IAHVoiceRuleEngine::change($ctx['edit'],$key,$input);
            $this->writeVoiceRuleEdit($ctx,$next);
            $this->voiceRuleNotice('Sprachregel in EDIT gespeichert.');
            $this->ReloadVoiceRules();
        }catch(Throwable $e){$this->voiceRuleNotice('Nicht gespeichert: '.$e->getMessage());}
    }

    public function DeleteVoiceRule(string $key): void
    {
        try {
            $ctx=$this->voiceRuleContext();
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

    public function ImportLegacyVoiceRuleDrafts(): void
    {
        try{
            $ctx=$this->voiceRuleContext();
            $next=(array)($ctx['edit']??[]);
            $count=0;
            foreach(IAHVoiceRuleEngine::legacyDrafts() as $key=>$r) {
                if(isset(IAHVoiceRuleEngine::rules($next)[$key]))continue;
                $next=IAHVoiceRuleEngine::change($next,$key,$r);
                $count++;
            }
            if($count)$this->writeVoiceRuleEdit($ctx,$next);
            $this->voiceRuleNotice($count.' alte Befehle als DEAKTIVIERTE '
                .'EDIT-Vorlagen angelegt. Nichts ausgeführt. '
                .'Die Jalousien laufen weiter über ihren bisherigen Renderer.');
            $this->ReloadVoiceRules();
        }catch(Throwable $e){$this->voiceRuleNotice('Vorlagen nicht importiert: '.$e->getMessage());}
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
            $forbidden=array_map('intval',array_values((array)($ctx['ids']??[])));
            $forbidden[]=(int)($ctx['catalogId']??0);
            $helper=@IPS_GetObjectIDByIdent('iahHelper',$this->InstanceID);
            $renderer=@IPS_GetObjectIDByIdent('iahRenderers',$this->InstanceID);
            foreach([$helper,$renderer] as $parentId){
                if((int)$parentId<=0)continue;
                foreach((array)IPS_GetChildrenIDs((int)$parentId) as $childId)
                    if(IPS_ScriptExists((int)$childId))$forbidden[]=(int)$childId;
            }
            $result=IAHVoiceRuleEngine::run($rules,$catalog,$event['text'],
                ['intent'=>(string)($event['intent']??''),
                 'forbiddenScriptIds'=>array_values(array_unique($forbidden))]);
            if(!empty($result['matched']))
                IPS_LogMessage('Alexa','VoiceRules.V37 '.json_encode([
                    'key'=>$result['ruleKey']??'ambiguous',
                    'kind'=>$result['kind']??'unknown',
                    'ok'=>$result['ok']??false,
                    'executed'=>$result['executed']??0
                ],JSON_UNESCAPED_UNICODE));
            return json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        }catch(Throwable $e){
            IPS_LogMessage('Alexa','VoiceRules.V37 failed errorType='.get_class($e));
            return '{"matched":false}';
        }
    }
}
