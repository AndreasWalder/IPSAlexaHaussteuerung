<?php
declare(strict_types=1);

/**
 * The unified navigator groups exact route keys and unambiguous navigation titles.
 * This is only a view: the three existing module properties remain canonical.
 * Navigation EDIT/PROD remains a separate, revision-checked lifecycle.
 */
trait IAHVisualNavigationGroupTrait
{
    /** Detect WebFront Classic instances without assuming a single configured ID. */
    /** The three APL lists have one staged EDIT copy, without live writes. */
    private function visualNavigationDraftData(): array
    {
        $draft=json_decode($this->ReadAttributeString('VisualNavDraft'),true);
        return is_array($draft) ? $draft : ['properties'=>[],'baseline'=>[]];
    }

    private function visualNavigationReadProperty(string $name): string
    {
        $draft=$this->visualNavigationDraftData();
        return (string)($draft['properties'][$name]??$this->ReadPropertyString($name));
    }

    private function visualNavigationStageProperties(array $updates): void
    {
        $allowed=['LaunchCatalog','PageMappings','RendererDomains'];
        $draft=$this->visualNavigationDraftData();
        foreach ($updates as $name=>$json) {
            if (!in_array($name,$allowed,true) || !is_string($json)
                || !is_array(json_decode($json,true)))
                throw new \RuntimeException('Ungültige EDIT-Eigenschaft: '.$name);
            $current=$this->ReadPropertyString($name);
            $baseline=$draft['baseline'][$name]??hash('sha256',$current);
            if (!hash_equals($baseline,hash('sha256',$current)))
                throw new \RuntimeException('PROD wurde zwischenzeitlich verändert: '.$name);
            $draft['baseline'][$name]=$baseline;
            $draft['properties'][$name]=$json;
        }
        $this->WriteAttributeString('VisualNavDraft',json_encode($draft,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $this->WriteAttributeString('VisualNavDraftPreview','');
    }

    private function visualNavigationDraftSummary(): string
    {
        $draft=$this->visualNavigationDraftData();
        $names=array_keys((array)($draft['properties']??[]));
        if ($names===[]) return 'EDIT: Keine offenen Kachel-/Seitenänderungen.';
        return 'EDIT: '.count($names).' geänderte Konfigurationsbereiche ('
            .implode(', ',$names).'). Noch nicht in PROD.';
    }

    public function ValidateVisualNavigationTileDraft(string $key,string $title,
        string $action,string $target): string
    {
        $key=strtolower(trim($key));$title=trim($title);$target=trim($target);
        if (!preg_match('/^[a-z0-9_-]{2,48}$/D',$key))
            return 'Kachel-ID muss 2–48 Zeichen (a-z, 0-9, _ oder -) haben.';
        if ($title==='' || mb_strlen($title,'UTF-8')>120)
            return 'Bitte einen Titel mit höchstens 120 Zeichen eingeben.';
        if (!in_array($action,['route','ai_query'],true))return 'Ungültige Aktion.';
        if ($target==='')return 'Route oder KI-Frage fehlt.';
        if ($action==='route' && !preg_match('/^[a-z0-9_-]{1,64}$/D',$target))
            return 'Ungültige APL-Route.';
        if ($action==='ai_query' && mb_strlen($target,'UTF-8')>240)
            return 'KI-Frage ist zu lang.';
        $rows=json_decode($this->visualNavigationReadProperty('LaunchCatalog'),true);
        if (!is_array($rows))return 'EDIT-Kachelkonfiguration nicht lesbar.';
        foreach ($rows as $row) {
            if (is_array($row) && (string)($row['id']??'')===$key)
                return 'Diese Kachel-ID existiert bereits.';
        }
        return '';
    }

    public function CreateVisualNavigationTileDraft(string $payload): void
    {
        try {
            $input=json_decode($payload,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($input))throw new \RuntimeException('Ungültige Kacheldaten.');
            $key=strtolower(trim((string)($input['key']??'')));
            $title=trim((string)($input['title']??''));
            $action=(string)($input['action']??'');
            $target=trim((string)($input['target']??''));
            $error=$this->ValidateVisualNavigationTileDraft($key,$title,$action,$target);
            if ($error!=='')throw new \RuntimeException($error);
            $icon=trim((string)($input['icon']??''));
            $upload=trim((string)($input['iconUpload']??''));
            if ($upload!=='') $icon=$this->visualTileSaveUploadedIcon($key,$upload);
            if ($icon!==''&&!preg_match('/^[A-Za-z0-9_-]+\\.(?:png|jpe?g|webp)$/iD',$icon))
                throw new \RuntimeException('Ungültiger Bilddateiname.');
            $layout=(string)($input['resultLayout']??'auto');
            if (!in_array($layout,['auto','text','status'],true))
                throw new \RuntimeException('Ungültiges Ergebnislayout.');
            $rows=json_decode($this->visualNavigationReadProperty('LaunchCatalog'),true,64,JSON_THROW_ON_ERROR);
            if (!is_array($rows))throw new \RuntimeException('LaunchCatalog ist kein Array.');
            $rows[]=[
                'id'=>$key,'title'=>$title,'subtitle'=>'',
                'actionType'=>$action,'target'=>$target,'icon'=>$icon,
                'color'=>$this->visualTileColorHex($input['color']??0x365C89),
                'resultTitle'=>mb_substr(trim((string)($input['resultTitle']??'')),0,90,'UTF-8'),
                'resultIconMode'=>'ai','resultStatusMode'=>'auto',
                'resultLayout'=>$layout,
                'speakAnswer'=>(bool)($input['speakAnswer']??true),
                'useArchive'=>(bool)($input['useArchive']??true)
            ];
            $this->visualNavigationStageProperties(['LaunchCatalog'=>json_encode($rows,
                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            $this->WriteAttributeString('VisualNavNotice',
                'Kachel '.$key.' in EDIT angelegt. Zum Veröffentlichen EDIT prüfen und nach PROD übernehmen.');
        } catch (\Throwable $error) {
            $this->WriteAttributeString('VisualNavNotice','Kachel nicht angelegt: '.$error->getMessage());
        }
        $this->ReloadForm();
    }

    public function PreviewVisualNavigationDraft(): void
    {
        $this->WriteAttributeString('VisualNavDraftPreview','');
        try {
            $draft=$this->visualNavigationDraftData();
            $changes=(array)($draft['properties']??[]);
            if ($changes===[])throw new \RuntimeException('Keine EDIT-Änderungen.');
            foreach ($changes as $name=>$json) {
                if (!is_array(json_decode((string)$json,true)))
                    throw new \RuntimeException('EDIT-JSON ungültig: '.$name);
                if (!hash_equals((string)($draft['baseline'][$name]??''),
                    hash('sha256',$this->ReadPropertyString($name))))
                    throw new \RuntimeException('PROD wurde seit EDIT geändert: '.$name);
            }
            $this->WriteAttributeString('VisualNavDraftPreview',json_encode([
                'hash'=>hash('sha256',json_encode($draft,JSON_THROW_ON_ERROR)),
                'time'=>time()
            ],JSON_THROW_ON_ERROR));
            $this->WriteAttributeString('VisualNavNotice',
                'Vorschau OK: '.implode(', ',array_keys($changes))
                .' · EDIT kann jetzt nach PROD übernommen werden (10 Minuten gültig).');
        } catch (\Throwable $error) {
            $this->WriteAttributeString('VisualNavNotice','EDIT-Prüfung: '.$error->getMessage());
        }
        $this->ReloadForm();
    }

    public function PublishVisualNavigationDraft(): void
    {
        try {
            $draft=$this->visualNavigationDraftData();
            $changes=(array)($draft['properties']??[]);
            $preview=json_decode($this->ReadAttributeString('VisualNavDraftPreview'),true);
            if ($changes===[] || !is_array($preview)
                || (int)($preview['time']??0)<time()-600
                || !hash_equals((string)($preview['hash']??''),
                    hash('sha256',json_encode($draft,JSON_THROW_ON_ERROR))))
                throw new \RuntimeException('Bitte zuerst EDIT-Änderungen erneut prüfen.');
            if (function_exists('IPS_HasChanges') && IPS_HasChanges($this->InstanceID))
                throw new \RuntimeException('Instanzeinstellungen zuerst übernehmen.');
            $before=[];
            foreach ($changes as $name=>$json) {
                $raw=$this->ReadPropertyString($name);
                if (!hash_equals((string)($draft['baseline'][$name]??''),hash('sha256',$raw)))
                    throw new \RuntimeException('PROD zwischenzeitlich geändert: '.$name);
                if (!is_array(json_decode((string)$json,true)))
                    throw new \RuntimeException('Ungültiges EDIT-JSON: '.$name);
                $before[$name]=$raw;
            }
            $this->WriteAttributeString('VisualNavBackup',json_encode([
                'properties'=>$before,'reason'=>'before_visual_edit_to_prod',
                'timestamp'=>time()
            ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            try {
                foreach ($changes as $name=>$json)
                    IPS_SetProperty($this->InstanceID,$name,$json);
                IPS_ApplyChanges($this->InstanceID);
                foreach ($changes as $name=>$json) {
                    if (json_decode($this->ReadPropertyString($name),true)!==json_decode($json,true))
                        throw new \RuntimeException('PROD-Schreibprüfung fehlgeschlagen: '.$name);
                }
            } catch (\Throwable $error) {
                foreach ($before as $name=>$json)
                    IPS_SetProperty($this->InstanceID,$name,$json);
                IPS_ApplyChanges($this->InstanceID);
                throw $error;
            }
            $this->WriteAttributeString('VisualNavDraft','');
            $this->WriteAttributeString('VisualNavDraftPreview','');
            $this->WriteAttributeString('VisualNavNotice',
                'APL-Konfiguration aus EDIT nach PROD übernommen: '.implode(', ',array_keys($changes)));
        } catch (\Throwable $error) {
            $this->WriteAttributeString('VisualNavNotice','Nicht veröffentlicht: '.$error->getMessage());
        }
        $this->ReloadForm();
    }

    private function visualNavigationWebFrontInstances(): array
    {
        if (!function_exists('IPS_GetInstanceListByModuleID')) return [];
        $ids=(array)IPS_GetInstanceListByModuleID('{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}');
        // Allow newer kernels to provide an alternative WebFront module ID.
        if ($ids===[] && function_exists('IPS_GetInstanceList') && function_exists('IPS_GetInstance')) {
            foreach ((array)IPS_GetInstanceList() as $id) {
                try {
                    $info=IPS_GetInstance((int)$id);
                    $name=strtolower((string)($info['ModuleInfo']['ModuleName']??''));
                    if (str_contains($name,'webfront')) $ids[]=(int)$id;
                } catch (\Throwable $e) { continue; }
            }
        }
        $ids=array_values(array_unique(array_map('intval',$ids)));
        sort($ids,SORT_NUMERIC);
        return array_filter($ids,static fn(int $id):bool=>$id>0);
    }

    /** A picker for each installed Classic WebFront instance. */
    private function visualNavigationWebFrontInstanceOptions(): array
    {
        $options=[['caption'=>'Alle WebFront-Instanzen','value'=>0]];
        foreach ($this->visualNavigationWebFrontInstances() as $id) {
            $name=function_exists('IPS_GetName')?(string)IPS_GetName($id):'WebFront';
            $options[]=['caption'=>$name.' (#'.$id.')','value'=>$id];
        }
        return $options;
    }

    /** Read labels from the selected Classic WebFront configuration, if present. */
    private function visualNavigationWebFrontItemOptions(int $selectedInstance=0): array
    {
        $options=[['caption'=>'Manuelle Item-ID verwenden','value'=>'']];
        if (!function_exists('IPS_GetConfiguration')) return $options;
        $seen=[];
        foreach ($this->visualNavigationWebFrontInstances() as $instanceId) {
            if ($selectedInstance>0 && $instanceId!==$selectedInstance) continue;
            try {
                $config=json_decode(IPS_GetConfiguration($instanceId),true);
                if (!is_array($config)) continue;
                $items=$config['Items']??[];
                if (is_string($items)) $items=json_decode($items,true);
                if (!is_array($items)) continue;
                $name=function_exists('IPS_GetName')?(string)IPS_GetName($instanceId):'WebFront';
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    $id=trim((string)($item['ID']??''));
                    if ($id==='' || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$id)) continue;
                    $detail=$item['Configuration']??[];
                    if (is_string($detail)) $detail=json_decode($detail,true);
                    if (!is_array($detail)) $detail=[];
                    $title=trim((string)($detail['title']??$detail['name']??$detail['Title']??''));
                    if ($title==='') $title=(string)($item['ClassName']??'Element');
                    // In the all-instances view identical IDs can be ambiguous.
                    // Include source name for disambiguation but keep original item ID.
                    $key=$instanceId.':'.$id;
                    if (isset($seen[$key])) continue;
                    $seen[$key]=true;
                    $options[]=['caption'=>$title.' — '.$id.' ('.$name.' #'.$instanceId.')',
                        'value'=>$id];
                }
            } catch (\Throwable $error) { continue; }
        }
        usort($options,static fn(array $a,array $b):int=>
            $a['value']===''?-1:($b['value']===''?1:
                strnatcasecmp($a['caption'],$b['caption'])));
        return $options;
    }

    private function visualNavigationGroups(): array
    {
        $groups=[];
        foreach ($this->visualNavigationRows() as $record) {
            $source=(string)($record['source']??'');
            $key=(string)($record['key']??'');
            if ($key==='') continue;
            // Navigation entryKey is not a launch route. Keep it independent
            // from a coincidentally identical renderer/page key.
            $groupId=($source==='navigation'?'navigation:':'route:').$key;
            if (!isset($groups[$groupId])) {
                $groups[$groupId]=[
                    'groupId'=>$groupId,'key'=>$key,'source'=>$source,
                    'title'=>'','actionType'=>'route','target'=>$key,
                    'hasLaunch'=>false,'hasPage'=>false,'hasRenderer'=>false,
                    'addLaunch'=>false,'addPage'=>false,
                    'icon'=>'','iconUpload'=>'','color'=>0x365C89,
                    'resultTitle'=>'','resultIconMode'=>'ai','resultStatusMode'=>'auto',
                    'resultLayout'=>'auto','speakAnswer'=>true,'useArchive'=>true,
                    'pageTitle'=>'','pageAction'=>'wfc_item','pageTarget'=>'',
                    'rendererTitle'=>'','rendererTarget'=>'',
                    'parts'=>'','status'=>'','detail'=>''
                ];
            }
            $g=&$groups[$groupId];
            if ($source==='launch') {
                $g['hasLaunch']=true;
                foreach (['title','actionType','target','icon','iconUpload','color',
                    'resultTitle','resultIconMode','resultStatusMode','resultLayout',
                    'speakAnswer','useArchive'] as $field) {
                    if (array_key_exists($field,$record)) $g[$field]=$record[$field];
                }
                $g['source']='launch';
            } elseif ($source==='page') {
                $g['hasPage']=true;
                $g['pageTitle']=(string)$record['title'];
                $g['pageAction']=(string)$record['actionType'];
                $g['pageTarget']=(string)$record['target'];
                if (!$g['hasLaunch'] && $g['title']==='') {
                    $g['title']=(string)$record['title'];
                    $g['source']='page';
                }
            } elseif ($source==='renderer') {
                $g['hasRenderer']=true;
                $g['rendererTitle']=(string)$record['title'];
                $g['rendererTarget']=(string)$record['target'];
                if (!$g['hasLaunch'] && !$g['hasPage'] && $g['title']==='') {
                    $g['title']=(string)$record['title'];
                    $g['source']='renderer';
                }
            } elseif ($source==='navigation') {
                $g['source']='navigation';
                $g['title']=(string)$record['title'];
                $g['target']=(int)$record['target'];
                $g['actionType']='navigation';
                foreach (['navigationRoom','navigationDomain','navigationOrder',
                    'navigationAliases','navigationEnabled'] as $field) {
                    $g[$field]=$record[$field]??($field==='navigationEnabled'?true:'');
                }
                $g['navigationTitle']=$g['title'];
                $g['navigationTarget']=$g['target'];
            }
            unset($g);
        }
        // Merge legacy EDIT navigation into a canonical tile only on a
        // unique, normalized display-name match. No fuzzy matching.
        $normalize=static function (string $title): string {
            $title=mb_strtolower(trim($title),'UTF-8');
            return preg_replace('/[^\\p{L}\\p{N}]+/u','', $title) ?? '';
        };
        $titleIndex=[];
        foreach ($groups as $id=>$group) {
            if (($group['source']??'')==='navigation') continue;
            $name=$normalize((string)($group['title']?:$group['rendererTitle']?:$group['pageTitle']?:$group['key']));
            if ($name!=='') $titleIndex[$name][]=$id;
        }
        $navTitleCounts=[];
        foreach ($groups as $candidate) {
            if (($candidate['source']??'')!=='navigation') continue;
            $normalized=$normalize((string)$candidate['title']);
            if ($normalized!=='') $navTitleCounts[$normalized]=($navTitleCounts[$normalized]??0)+1;
        }
        foreach (array_keys($groups) as $navId) {
            if (!isset($groups[$navId]) || $groups[$navId]['source']!=='navigation') continue;
            $name=$normalize((string)$groups[$navId]['title']);
            $matches=$titleIndex[$name]??[];
            if ($name==='' || count($matches)!==1 || ($navTitleCounts[$name]??0)!==1) continue;
            $targetId=$matches[0];
            if (!empty($groups[$targetId]['navigationKey'])) continue;
            $groups[$targetId]['navigationKey']=$groups[$navId]['key'];
            $groups[$targetId]['navigationTitle']=$groups[$navId]['title'];
            $groups[$targetId]['navigationTarget']=$groups[$navId]['target'];
            foreach (['navigationRoom','navigationDomain','navigationOrder',
                'navigationAliases','navigationEnabled'] as $field) {
                $groups[$targetId][$field]=$groups[$navId][$field]??null;
            }
            unset($groups[$navId]);
        }
        foreach ($groups as &$g) {
            $parts=[];
            if ($g['hasLaunch']) $parts[]='Startkachel';
            if ($g['hasPage']) $parts[]='Seitenziel';
            if ($g['hasRenderer']) $parts[]='Renderer';
            if ($g['source']==='navigation' || !empty($g['navigationKey'])) $parts[]='Navigation (EDIT)';
            $g['parts']=implode(' + ',$parts);
            $g['detail']=$g['parts'];
            $g['status']=$g['source']==='navigation'?'Nur EDIT – PROD separat':
                (count($parts)>1?'Zusammengefasst':'Einzelne Verknüpfung');
            if (!$g['hasLaunch'] && $g['source']!=='navigation') {
                // Suggested defaults only: creating a launch tile requires an
                // explicit opt-in in the editing dialog.
                $g['actionType']='route';
                $g['target']=$g['key'];
            }
            if ($g['title']==='') $g['title']=$g['key'];
        }
        unset($g);
        $result=array_values($groups);
        usort($result,static fn(array $a,array $b):int=>
            strnatcasecmp((string)$a['title'],(string)$b['title'])
            ?:strcmp((string)$a['groupId'],(string)$b['groupId']));
        return $result;
    }

    /** A numbered, conditional form for one group. All sections save together. */
    public function GetVisualNavigationGroupForm(string $groupId): string
    {
        $row=null;
        foreach ($this->visualNavigationGroups() as $candidate) {
            if ($candidate['groupId']===$groupId) {$row=$candidate;break;}
        }
        if ($row===null) return json_encode([
            ['type'=>'Label','caption'=>'Eintrag nicht mehr vorhanden. Bitte Konfigurator neu öffnen.']
        ],JSON_THROW_ON_ERROR);
        $isNav=$row['source']==='navigation';
        $isAi=$row['actionType']==='ai_query';
        if ($isNav) {
            return json_encode([
                ['type'=>'Label','caption'=>'1. Navigation (EDIT) – Eintrag '.$row['key']],
                ['type'=>'ValidationTextBox','name'=>'title','caption'=>'Anzeigename'],
                ['type'=>'SelectObject','name'=>'target','caption'=>'Verknüpftes IP-Symcon-Objekt'],
                ['type'=>'ValidationTextBox','name'=>'navigationRoom','caption'=>'Raum-Schlüssel'],
                ['type'=>'ValidationTextBox','name'=>'navigationDomain','caption'=>'Bereich-Schlüssel'],
                ['type'=>'NumberSpinner','name'=>'navigationOrder','caption'=>'Reihenfolge'],
                ['type'=>'ValidationTextBox','name'=>'navigationAliases',
                    'caption'=>'Synonyme (Komma-getrennt)'],
                ['type'=>'CheckBox','name'=>'navigationEnabled','caption'=>'Navigation aktiv'],
                ['type'=>'Label','caption'=>'2. Prüfung: Änderungen werden nur in '
                    .'SystemConfigurationEdit gespeichert. Anschließend separat nach PROD übernehmen.']
            ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        }

        $form=[
            ['type'=>'Label','caption'=>'Gemeinsamer Schlüssel: '.$row['key']
                .' · Vorhanden: '.$row['parts'].'. Jeder Schritt ist Teil derselben Konfiguration.'],
            ['type'=>'ExpansionPanel','name'=>'VisualNavStage1','caption'=>'1. Startkachel – Anzeige und Aktion',
                'expanded'=>true,'items'=>array_merge(
                    $row['hasLaunch']?[]:[[
                        'type'=>'CheckBox','name'=>'addLaunch',
                        'caption'=>'Für diesen Schlüssel eine Startkachel anlegen',
                        'onChange'=>'IAH_ToggleVisualNavigationGroupPart($id, "VisualNavLaunchFields", $addLaunch);'
                    ]],
                    [[
                        'type'=>'ExpansionPanel','name'=>'VisualNavLaunchFields',
                        'caption'=>$row['hasLaunch']?'Startkachel bearbeiten':'Neue Startkachel (optional)',
                        'visible'=>$row['hasLaunch'],'expanded'=>true,
                        'items'=>[
                            ['type'=>'ValidationTextBox','name'=>'title','caption'=>'Anzeigename'],
                            ['type'=>'Select','name'=>'actionType','caption'=>'Beim Antippen',
                                'onChange'=>'IAH_UpdateVisualNavigationActionForm($id, $actionType);',
                                'options'=>[
                                    ['caption'=>'APL-Seite öffnen','value'=>'route'],
                                    ['caption'=>'Beliebige KI-Frage stellen','value'=>'ai_query']
                                ]],
                            ['type'=>'ValidationTextBox','name'=>'target',
                                'caption'=>$isAi?'Frage an die KI':'APL-Route (z. B. bienen)'],
                            ['type'=>'Select','name'=>'icon','caption'=>'Kachelbild',
                                'options'=>$this->visualTileIconOptions()],
                            ['type'=>'SelectFile','name'=>'iconUpload','caption'=>'Oder Bild hochladen',
                                'extensions'=>'.png,.jpg,.jpeg,.webp'],
                            ['type'=>'SelectColor','name'=>'color','caption'=>'Kachelfarbe',
                                'allowTransparent'=>false],
                            ['type'=>'ExpansionPanel','name'=>'VisualNavAiSettings',
                                'caption'=>'KI-Ergebnis gestalten','expanded'=>false,'visible'=>$isAi,
                                'items'=>[
                                    ['type'=>'ValidationTextBox','name'=>'resultTitle',
                                        'caption'=>'Ergebnis-Überschrift (leer = automatisch)'],
                                    ['type'=>'Select','name'=>'resultIconMode','caption'=>'Icon der Ergebnisseite',
                                        'options'=>[
                                            ['caption'=>'KI wählt Icon','value'=>'ai'],
                                            ['caption'=>'Kachelbild verwenden','value'=>'tile']]],
                                    ['type'=>'Select','name'=>'resultStatusMode','caption'=>'Statusbewertung',
                                        'options'=>[
                                            ['caption'=>'Nur anhand von Fakten','value'=>'auto'],
                                            ['caption'=>'Keine Statusanzeige','value'=>'off']]],
                                    ['type'=>'Select','name'=>'resultLayout','caption'=>'Ergebnisseite',
                                        'options'=>[
                                            ['caption'=>'Automatisch','value'=>'auto'],
                                            ['caption'=>'Nur Text','value'=>'text'],
                                            ['caption'=>'Statusseite','value'=>'status']]],
                                    ['type'=>'CheckBox','name'=>'speakAnswer','caption'=>'Antwort vorlesen'],
                                    ['type'=>'CheckBox','name'=>'useArchive',
                                        'caption'=>'Archiv bei Hausfragen berücksichtigen']
                                ]]
                        ]
                    ]],
                    [[
                        'type'=>'Button','caption'=>'Weiter zu Schritt 2',
                        'onClick'=>'IAH_OpenVisualNavigationGroupStep($id, 2);'
                    ]]
                )],
            ['type'=>'ExpansionPanel','name'=>'VisualNavStage2','caption'=>'2. Seitenzuordnung – nur falls benötigt',
                'expanded'=>$row['hasPage'],'items'=>array_merge(
                    $row['hasPage']?[]:[[
                        'type'=>'CheckBox','name'=>'addPage',
                        'caption'=>'Eine zusätzliche Seitenzuordnung anlegen (optional)',
                        'onChange'=>'IAH_ToggleVisualNavigationGroupPart($id, "VisualNavPageFields", $addPage);'
                    ]],
                    [[
                        'type'=>'ExpansionPanel','name'=>'VisualNavPageFields','expanded'=>true,
                        'caption'=>$row['hasPage']?'Vorhandenes Seitenziel':'Neues Seitenziel',
                        'visible'=>$row['hasPage'],
                        'items'=>[
                            ['type'=>'ValidationTextBox','name'=>'pageTitle',
                                'caption'=>'Name der Seitenzuordnung'],
                            ['type'=>'Select','name'=>'pageAction','caption'=>'Seitentyp',
                                'onChange'=>'IAH_UpdateVisualNavigationGroupPageType($id, $pageAction);',
                                'options'=>[
                                    ['caption'=>'IP-Symcon Visualisierung (item...)','value'=>'wfc_item'],
                                    ['caption'=>'Externe HTTPS-Seite','value'=>'external_url']]],
                            ['type'=>'Select','name'=>'pageInstance',
                                'caption'=>'WebFront-Instanz (Classic)',
                                'visible'=>$row['pageAction']!=='external_url',
                                'options'=>$this->visualNavigationWebFrontInstanceOptions(),
                                'onChange'=>'IAH_UpdateVisualNavigationWebFrontInstance($id, (int)$pageInstance);'],
                            ['type'=>'Select','name'=>'pageTargetChoice',
                                'caption'=>'Vorhandenes WebFront-Element (ID + Bezeichnung)',
                                'visible'=>$row['pageAction']!=='external_url',
                                'options'=>$this->visualNavigationWebFrontItemOptions()],
                            ['type'=>'ValidationTextBox','name'=>'pageTarget',
                                'caption'=>$row['pageAction']==='external_url'
                                    ?'HTTPS-Adresse':'Manuelle IPS Item-ID (Fallback)'],
                            ['type'=>'Label','caption'=>'Bei einer Auswahl im Dropdown wird deren '
                                .'Item-ID verwendet. Ohne Auswahl gilt die manuelle Eingabe.']
                        ]
                    ]],
                    [[
                        'type'=>'RowLayout','items'=>[
                            ['type'=>'Button','caption'=>'Zurück zu 1',
                                'onClick'=>'IAH_OpenVisualNavigationGroupStep($id, 1);'],
                            ['type'=>'Button','caption'=>'Weiter zu 3',
                                'onClick'=>'IAH_OpenVisualNavigationGroupStep($id, 3);']
                        ]
                    ]]
                )],
            ['type'=>'ExpansionPanel','name'=>'VisualNavStage3','caption'=>'3. Renderer – Anzeige einer eigenen APL-Seite',
                'expanded'=>false,'items'=>array_merge($row['hasRenderer']?[
                    ['type'=>'ValidationTextBox','name'=>'rendererTitle',
                        'caption'=>'Titel der Ergebnisseite'],
                    ['type'=>'ValidationTextBox','name'=>'rendererTarget',
                        'caption'=>'RoomsCatalog-Domäne (z. B. been)'],
                    ['type'=>'Label','caption'=>'Die vorhandene APL-Vorlage bleibt unverändert.']
                ]:[
                    ['type'=>'Label','caption'=>'Kein Renderer vorhanden. Für KI-Fragen und '
                        .'viele vorhandene APL-Routen ist keiner erforderlich. '
                        .'Neue Renderer mit eigener APL-Vorlage werden weiterhin '
                        .'über die Experteneinstellungen angelegt.']
                ],[[
                    'type'=>'RowLayout','items'=>[
                        ['type'=>'Button','caption'=>'Zurück zu 2',
                            'onClick'=>'IAH_OpenVisualNavigationGroupStep($id, 2);'],
                        ['type'=>'Button','caption'=>'Weiter zu 4: Prüfen',
                            'onClick'=>'IAH_OpenVisualNavigationGroupStep($id, 4);']
                    ]
                ]])],
            ['type'=>'ExpansionPanel','name'=>'VisualNavNavigationEdit',
                'caption'=>'Zugehöriger Navigations-Tab (EDIT)',
                'expanded'=>!empty($row['navigationKey']),
                'visible'=>!empty($row['navigationKey']),
                'items'=>[
                    ['type'=>'Label','caption'=>'Legacy-Schlüssel: '.(string)($row['navigationKey']??'')
                        .' · EDIT bleibt getrennt von PROD.'],
                    ['type'=>'ValidationTextBox','name'=>'navigationTitle','caption'=>'Navigationsname'],
                    ['type'=>'SelectObject','name'=>'navigationTarget',
                        'caption'=>'Navigationsziel (IP-Symcon-Objekt)'],
                    ['type'=>'ValidationTextBox','name'=>'navigationRoom',
                        'caption'=>'Raum-Schlüssel'],
                    ['type'=>'ValidationTextBox','name'=>'navigationDomain',
                        'caption'=>'Bereich-Schlüssel'],
                    ['type'=>'NumberSpinner','name'=>'navigationOrder',
                        'caption'=>'Reihenfolge'],
                    ['type'=>'ValidationTextBox','name'=>'navigationAliases',
                        'caption'=>'Synonyme (Komma-getrennt)'],
                    ['type'=>'CheckBox','name'=>'navigationEnabled',
                        'caption'=>'Navigation aktiv'],
                    ['type'=>'Label','caption'=>'Änderungen am Navigationstab werden über den '
                        .'bestehenden revisionierten EDIT-Speicherweg übernommen. '
                        .'PROD wird dadurch nicht verändert.']
                ]],
            ['type'=>'ExpansionPanel','name'=>'VisualNavStage4','caption'=>'4. Prüfen und speichern',
                'expanded'=>false,'items'=>[
                    ['type'=>'Label','caption'=>'Nur angehakte oder bereits vorhandene Komponenten '
                        .'werden gespeichert. Die bestehenden Instanz-Eigenschaften bleiben erhalten. '
                        .'Bei Änderungen an mehreren Eigenschaften wird zuvor ein gemeinsames Backup angelegt.'],
                    ['type'=>'Label','caption'=>'Navigationstabs haben einen eigenen EDIT-/PROD-Lebenszyklus '
                        .'und werden niemals automatisch nach PROD veröffentlicht.'],
                    ['type'=>'Button','caption'=>'Zurück zu Schritt 3',
                        'onClick'=>'IAH_OpenVisualNavigationGroupStep($id, 3);'],
                    ['type'=>'Label','caption'=>'Zum Abschließen unten OK drücken. '
                        .'Bei Fehlern bleibt die vorhandene Konfiguration erhalten.']
                ]]
        ];
        return json_encode($form,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    /** Guide the user from one setup step to the next without saving early. */
    public function OpenVisualNavigationGroupStep(int $step): void
    {
        if ($step<1 || $step>4) return;
        for ($i=1;$i<=4;$i++) {
            $this->UpdateFormField('VisualNavStage'.$i,'expanded',$i===$step);
        }
    }

    public function ToggleVisualNavigationGroupPart(string $panel,bool $enabled): void
    {
        if (!in_array($panel,['VisualNavLaunchFields','VisualNavPageFields'],true)) return;
        $this->UpdateFormField($panel,'visible',$enabled);
    }

    public function UpdateVisualNavigationWebFrontInstance(int $instanceId): void
    {
        $this->UpdateFormField('pageTargetChoice','options',
            json_encode($this->visualNavigationWebFrontItemOptions($instanceId),
                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $this->UpdateFormField('pageTargetChoice','value','');
    }

    public function UpdateVisualNavigationGroupPageType(string $type): void
    {
        $this->UpdateFormField('pageTarget','caption',
            $type==='external_url'?'Vollständige HTTPS-Adresse':'IPS Item-ID (z. B. item2124)');
    }

    /** Store multiple sources safely with one ApplyChanges and one backup. */
    public function SaveVisualNavigationGroup(string $payload): void
    {
        try {
            $input=json_decode($payload,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($input)) throw new RuntimeException('Ungültige Eingabe.');
            $groupId=(string)($input['groupId']??'');
            $existing=null;
            foreach ($this->visualNavigationGroups() as $candidate) {
                if ($candidate['groupId']===$groupId) {$existing=$candidate;break;}
            }
            if ($existing===null) throw new RuntimeException('Eintrag nicht mehr vorhanden. Bitte neu laden.');
            $key=$existing['key'];
            if ($existing['source']==='navigation') {
                // Native navigation editor retains its concurrency protection.
                $context=$this->navigationEditorContext();
                if (!is_array($context['edit'])) throw new RuntimeException('Navigation-EDIT nicht lesbar.');
                foreach (IAHSystemNavigationEditor::rows($context['edit']) as $nav) {
                    if ((string)($nav['entryKey']??'')!==$key)continue;
                    $nav['title']=trim((string)($input['title']??''));
                    $nav['objectId']=(int)($input['target']??0);
                    foreach (['navigationRoom'=>'roomKey','navigationDomain'=>'domainKey',
                        'navigationOrder'=>'sortOrder','navigationAliases'=>'aliases',
                        'navigationEnabled'=>'enabled'] as $inputField=>$navField) {
                        if (array_key_exists($inputField,$input)) $nav[$navField]=$input[$inputField];
                    }
                    $this->SaveSystemNavigationTab(json_encode($nav,JSON_THROW_ON_ERROR));
                    return;
                }
                throw new RuntimeException('Navigations-Tab nicht gefunden.');
            }
            if (function_exists('IPS_HasChanges') && IPS_HasChanges($this->InstanceID))
                throw new RuntimeException('Bitte zuerst andere ungespeicherte Instanzänderungen übernehmen.');
            $addLaunch=!$existing['hasLaunch'] && !empty($input['addLaunch']);
            $addPage=!$existing['hasPage'] && !empty($input['addPage']);
            $launch=$existing['hasLaunch']||$addLaunch;
            $page=$existing['hasPage']||$addPage;
            $renderer=$existing['hasRenderer'];
            // Inherited legacy page fallbacks must not be materialized merely
            // because the user confirmed another part of the group.
            if ($existing['hasPage'] && !$addPage
                && (string)($input['pageAction']??'')===(string)$existing['pageAction']
                && trim((string)($input['pageTitle']??''))===(string)$existing['pageTitle']
                && trim((string)($input['pageTarget']??''))===(string)$existing['pageTarget']) {
                $rawPage=json_decode($this->ReadPropertyString('PageMappings'),true);
                $storedPage=false;
                foreach (is_array($rawPage)?$rawPage:[] as $entry) {
                    if(is_array($entry) && (string)($entry['key']??'')===$key) {
                        $storedPage=true;break;
                    }
                }
                if (!$storedPage) $page=false;
            }
            $before=[];$after=[];
            foreach (['LaunchCatalog','PageMappings','RendererDomains'] as $prop) {
                $raw=$this->ReadPropertyString($prop);
                $parsed=json_decode($raw,true);
                if (!is_array($parsed))throw new RuntimeException($prop.' ist kein gültiges Array.');
                $before[$prop]=$raw;
                $after[$prop]=$parsed;
            }
            if ($launch) {
                if ($addLaunch && !preg_match('/^[a-z0-9_-]{2,48}$/D',$key))
                    throw new RuntimeException('Für eine neue Startkachel ist ein gültiger Schlüssel erforderlich.');
                $title=trim((string)($input['title']??''));
                $action=(string)($input['actionType']??'route');
                $target=trim((string)($input['target']??''));
                if ($title===''||$target==='')throw new RuntimeException('Startkachel: Titel und Ziel fehlen.');
                if(!in_array($action,['route','ai_query'],true))
                    throw new RuntimeException('Startkachel: unbekannte Aktion.');
                if($action==='route' && !preg_match('/^[a-z0-9_-]{1,64}$/D',$target))
                    throw new RuntimeException('Startkachel: ungültige APL-Route.');
                if($action==='ai_query' && mb_strlen($target,'UTF-8')>240)
                    throw new RuntimeException('KI-Frage ist zu lang (max. 240 Zeichen).');
                $icon=trim((string)($input['icon']??''));
                $upload=trim((string)($input['iconUpload']??''));
                if ($upload!=='') $icon=$this->visualTileSaveUploadedIcon($key,$upload);
                if ($icon!=='' && !preg_match('/^[A-Za-z0-9_-]+\.(?:png|jpe?g|webp)$/iD',$icon))
                    throw new RuntimeException('Ungültige Kachelbild-Datei.');
                $tile=[
                    'title'=>$title,'actionType'=>$action,'target'=>$target,
                    'icon'=>$icon,'color'=>$this->visualTileColorHex($input['color']??0x365C89),
                    'resultTitle'=>mb_substr(trim((string)($input['resultTitle']??'')),0,90,'UTF-8'),
                    'speakAnswer'=>(bool)($input['speakAnswer']??true),
                    'useArchive'=>(bool)($input['useArchive']??true)
                ];
                foreach ([
                    'resultIconMode'=>['ai','tile'],
                    'resultStatusMode'=>['auto','off'],
                    'resultLayout'=>['auto','text','status']
                ] as $field=>$allowed) {
                    $value=(string)($input[$field]??$allowed[0]);
                    if(!in_array($value,$allowed,true))throw new RuntimeException('Ungültiger KI-Modus: '.$field);
                    $tile[$field]=$value;
                }
                $found=false;
                foreach ($after['LaunchCatalog'] as &$row) {
                    if(!is_array($row)||(string)($row['id']??'')!==$key)continue;
                    $row=array_replace($row,$tile);
                    $found=true;break;
                }
                unset($row);
                if($addLaunch && !$found) $after['LaunchCatalog'][]=['id'=>$key]+$tile;
                if($existing['hasLaunch'] && !$found)
                    throw new RuntimeException('Startkachel wurde zwischenzeitlich entfernt.');
            }
            if ($page) {
                $type=(string)($input['pageAction']??'wfc_item');
                $value=trim((string)($input['pageTarget']??''));
                if ($type==='wfc_item') {
                    $selected=trim((string)($input['pageTargetChoice']??''));
                    if ($selected!=='') {
                        $instance=(int)($input['pageInstance']??0);
                        $known=array_column($this->visualNavigationWebFrontItemOptions($instance),'value');
                        if (!in_array($selected,$known,true))
                            throw new RuntimeException('Ausgewähltes WebFront-Element existiert nicht mehr.');
                        $value=$selected;
                    }
                }
                $label=trim((string)($input['pageTitle']??''));
                if(!in_array($type,['wfc_item','external_url'],true)||$value==='')
                    throw new RuntimeException('Seitenzuordnung: Typ und Ziel erforderlich.');
                if($type==='external_url' && !str_starts_with($value,'https://'))
                    throw new RuntimeException('Externe Seiten müssen HTTPS verwenden.');
                $found=false;
                foreach ($after['PageMappings'] as &$row) {
                    if(!is_array($row)||(string)($row['key']??'')!==$key)continue;
                    $row=array_replace($row,['type'=>$type,'value'=>$value,'label'=>$label]);
                    $found=true;break;
                }
                unset($row);
                if (!$found) $after['PageMappings'][]=['key'=>$key,'type'=>$type,'value'=>$value,'label'=>$label];
            }
            if ($renderer) {
                $title=trim((string)($input['rendererTitle']??''));
                $domain=trim((string)($input['rendererTarget']??''));
                if ($title==='' || $domain==='')
                    throw new RuntimeException('Renderer: Titel und RoomsCatalog-Domäne erforderlich.');
                $found=false;
                foreach ($after['RendererDomains'] as &$row) {
                    if(!is_array($row)||(string)($row['route']??'')!==$key)continue;
                    $row['title']=$title;$row['roomDomain']=$domain;
                    $found=true;break;
                }
                unset($row);
                if(!$found)throw new RuntimeException('Renderer wurde zwischenzeitlich entfernt.');
            }
            $updated=[];
            foreach ($after as $property=>$value) {
                $encoded=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                if (json_decode($before[$property],true)!==$value) $updated[$property]=$encoded;
            }
            // Only write the legacy EDIT navigation when explicitly changed.
            $navChange=null;
            if (!empty($existing['navigationKey'])) {
                $newNavTitle=trim((string)($input['navigationTitle']??$existing['navigationTitle']));
                $newNavTarget=(int)($input['navigationTarget']??$existing['navigationTarget']);
                if ($newNavTitle==='' || $newNavTarget<=0)
                    throw new RuntimeException('Navigation: Name und IPS-Objekt müssen gültig sein.');
                $navFieldMap=[
                    'navigationRoom'=>'roomKey','navigationDomain'=>'domainKey',
                    'navigationOrder'=>'sortOrder','navigationAliases'=>'aliases',
                    'navigationEnabled'=>'enabled'
                ];
                $metadataChanged=false;
                foreach ($navFieldMap as $inputField=>$_) {
                    if (!array_key_exists($inputField,$input)) continue;
                    $original=$existing[$inputField]??null;
                    $proposed=$input[$inputField];
                    if ($inputField==='navigationEnabled') {
                        $original=(bool)$original;$proposed=(bool)$proposed;
                    } elseif ($inputField==='navigationOrder') {
                        $original=(int)$original;$proposed=(int)$proposed;
                    } else {
                        $original=trim((string)$original);$proposed=trim((string)$proposed);
                    }
                    if ($original!==$proposed) {$metadataChanged=true;break;}
                }
                if ($newNavTitle!==$existing['navigationTitle']
                    || $newNavTarget!==(int)$existing['navigationTarget']
                    || $metadataChanged) {
                    $context=$this->navigationEditorContext();
                    if (!is_array($context['edit']))
                        throw new RuntimeException('Navigation-EDIT ist nicht lesbar.');
                    foreach (IAHSystemNavigationEditor::rows($context['edit']) as $nav) {
                        if ((string)($nav['entryKey']??'')!==(string)$existing['navigationKey'])continue;
                        $nav['title']=$newNavTitle;
                        $nav['objectId']=$newNavTarget;
                        foreach ($navFieldMap as $inputField=>$navField) {
                            if (array_key_exists($inputField,$input)) $nav[$navField]=$input[$inputField];
                        }
                        $navChange=$nav;
                        break;
                    }
                    if ($navChange===null)throw new RuntimeException('Navigations-Tab nicht mehr vorhanden.');
                }
            }
            if ($updated===[] && $navChange===null) {
                $this->WriteAttributeString('VisualNavNotice','Keine Änderungen: '.$key);
                $this->ReloadForm();return;
            }
            // Restore-compatible backup contains every modified original.
            $backup=[];
            foreach ($updated as $property=>$_) $backup[$property]=$before[$property];
            if ($navChange!==null && $updated!==[]) {
                throw new RuntimeException('Navigation und Kacheleinstellungen wurden gleichzeitig geändert. '
                    .'Bitte zunächst die Kachel speichern und danach die Navigation.');
            }
            if ($navChange!==null) {
                $this->SaveSystemNavigationTab(json_encode($navChange,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                return;
            }
            $this->WriteAttributeString('VisualNavBackup',json_encode([
                'properties'=>$backup,'key'=>$key
            ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            try {
                foreach ($updated as $property=>$value)
                    IPS_SetProperty($this->InstanceID,$property,$value);
                IPS_ApplyChanges($this->InstanceID);
                foreach ($updated as $property=>$value) {
                    $actual=json_decode($this->ReadPropertyString($property),true);
                    if ($actual!==json_decode($value,true))
                        throw new RuntimeException('Schreibprüfung fehlgeschlagen: '.$property);
                }
            } catch (\Throwable $writeError) {
                foreach ($backup as $property=>$raw)
                    IPS_SetProperty($this->InstanceID,$property,$raw);
                IPS_ApplyChanges($this->InstanceID);
                throw $writeError;
            }
            $this->WriteAttributeString('VisualNavNotice',
                'Gespeichert: '.$key.' · '.implode(', ',array_keys($updated)));
        } catch (\Throwable $error) {
            $this->WriteAttributeString('VisualNavNotice',
                'Nicht gespeichert: '.$error->getMessage());
        }
        $this->ReloadForm();
    }
}
