<?php
declare(strict_types=1);

/**
 * The unified navigator groups related records by their exact stable key.
 * This is only a view: the three existing module properties remain canonical.
 * Navigation EDIT/PROD remains a separate, revision-checked lifecycle.
 */
trait IAHVisualNavigationGroupTrait
{
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
            }
            unset($g);
        }
        foreach ($groups as &$g) {
            $parts=[];
            if ($g['hasLaunch']) $parts[]='Startkachel';
            if ($g['hasPage']) $parts[]='Seitenziel';
            if ($g['hasRenderer']) $parts[]='Renderer';
            if ($g['source']==='navigation') $parts[]='Navigation (EDIT)';
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
                ['type'=>'Label','caption'=>'2. Prüfung: Änderungen werden nur in '
                    .'SystemConfigurationEdit gespeichert. Anschließend separat nach PROD übernehmen.']
            ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        }

        $form=[
            ['type'=>'Label','caption'=>'Gemeinsamer Schlüssel: '.$row['key']
                .' · Vorhanden: '.$row['parts'].'. Jeder Schritt ist Teil derselben Konfiguration.'],
            ['type'=>'ExpansionPanel','caption'=>'1. Startkachel – Anzeige und Aktion',
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
                    ]]
                )],
            ['type'=>'ExpansionPanel','caption'=>'2. Seitenzuordnung – nur falls benötigt',
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
                            ['type'=>'ValidationTextBox','name'=>'pageTarget',
                                'caption'=>$row['pageAction']==='external_url'
                                    ?'HTTPS-Adresse':'IPS Item-ID (z. B. item2124)']
                        ]
                    ]]
                )],
            ['type'=>'ExpansionPanel','caption'=>'3. Renderer – Anzeige einer eigenen APL-Seite',
                'expanded'=>$row['hasRenderer'],'items'=>$row['hasRenderer']?[
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
                ]],
            ['type'=>'ExpansionPanel','caption'=>'4. Prüfen und speichern',
                'expanded'=>false,'items'=>[
                    ['type'=>'Label','caption'=>'Nur angehakte oder bereits vorhandene Komponenten '
                        .'werden gespeichert. Die bestehenden Instanz-Eigenschaften bleiben erhalten. '
                        .'Bei Änderungen an mehreren Eigenschaften wird zuvor ein gemeinsames Backup angelegt.'],
                    ['type'=>'Label','caption'=>'Navigationstabs haben einen eigenen EDIT-/PROD-Lebenszyklus '
                        .'und werden niemals automatisch nach PROD veröffentlicht.']
                ]]
        ];
        return json_encode($form,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    public function ToggleVisualNavigationGroupPart(string $panel,bool $enabled): void
    {
        if (!in_array($panel,['VisualNavLaunchFields','VisualNavPageFields'],true)) return;
        $this->UpdateFormField($panel,'visible',$enabled);
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
            if ($updated===[]) {
                $this->WriteAttributeString('VisualNavNotice','Keine Änderungen: '.$key);
                $this->ReloadForm();return;
            }
            // Restore-compatible backup contains every modified original.
            $backup=[];
            foreach ($updated as $property=>$_) $backup[$property]=$before[$property];
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
