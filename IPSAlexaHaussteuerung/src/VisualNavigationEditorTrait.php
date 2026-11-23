<?php
declare(strict_types=1);

/**
 * Gemeinsame Sicht auf die vorhandenen Alexa-Kachel-, Seiten-, Renderer- und
 * Navigationsdefinitionen. Keine zweite Datenquelle: Änderungen werden in
 * die bisherigen Instanzeigenschaften geschrieben; Navigation bleibt EDIT/PROD.
 */
trait IAHVisualNavigationEditorTrait
{
    private function visualTileIconOptions(): array
    {
        $options=[['caption'=>'(Standard-Icon)','value'=>'']];
        $directory=rtrim(IPS_GetKernelDir(),'\\/').DIRECTORY_SEPARATOR.'user'.DIRECTORY_SEPARATOR.'icons';
        if (!is_dir($directory)) return $options;
        foreach ((array)@scandir($directory) as $file) {
            if (!is_string($file) ||
                !preg_match('/^[A-Za-z0-9_-]+\\.(?:png|jpe?g|webp)$/iD',$file)) continue;
            $options[]=['caption'=>$file,'value'=>$file];
        }
        return $options;
    }

    private function visualTileColorValue(string $color): int
    {
        $color=ltrim($color,'#');
        return preg_match('/^[0-9a-fA-F]{6}$/D',$color) ? hexdec($color) : 0x365C89;
    }

    private function visualTileColorHex(mixed $value): string
    {
        if(is_int($value) || (is_string($value)&&ctype_digit($value))) {
            $n=(int)$value;
            if($n<0 || $n>0xFFFFFF)throw new RuntimeException('Ungültiger RGB-Farbwert.');
            return sprintf('#%06X',$n);
        }
        $text=strtoupper(trim((string)$value));
        if(preg_match('/^0X([0-9A-F]{6})$/D',$text,$match))return '#'.$match[1];
        if(preg_match('/^([0-9A-F]{6})$/D',$text,$match))return '#'.$match[1];
        if(preg_match('/^#[0-9A-F]{6}$/D',$text))return $text;
        throw new RuntimeException('Bitte eine Farbe mit der Farbauswahl wählen.');
    }

    private function visualTileSaveUploadedIcon(string $tileId,string $base64): string
    {
        $base64=trim($base64);
        if ($base64==='') return '';
        if(strlen($base64)>700000)throw new RuntimeException('Icondatei zu groß (maximal 500 KB).');
        if(str_contains($base64,','))$base64=substr($base64,strpos($base64,',')+1);
        $bytes=base64_decode($base64,true);
        if(!is_string($bytes)||strlen($bytes)<32||strlen($bytes)>500000)
            throw new RuntimeException('Ungültige oder zu große Icondatei.');
        $size=@getimagesizefromstring($bytes);
        if(!is_array($size)||($size[0]??0)<16||($size[1]??0)<16
            ||($size[0]??0)>1024||($size[1]??0)>1024)
            throw new RuntimeException('Das Bild muss zwischen 16 und 1024 Pixel groß sein.');
        $extension=match((int)($size[2]??0)) {
            IMAGETYPE_PNG=>'png',IMAGETYPE_JPEG=>'jpg',IMAGETYPE_WEBP=>'webp',
            default=>throw new RuntimeException('Nur PNG, JPG oder WebP verwenden.'),
        };
        $directory=rtrim(IPS_GetKernelDir(),'\\/').DIRECTORY_SEPARATOR.'user'.DIRECTORY_SEPARATOR.'icons';
        if(!is_dir($directory) && !@mkdir($directory,0755,true) && !is_dir($directory))
            throw new RuntimeException('Iconverzeichnis kann nicht angelegt werden.');
        $filename='AlexaTile_'.$tileId.'_'.substr(hash('sha256',$bytes),0,12).'.'.$extension;
        $path=$directory.DIRECTORY_SEPARATOR.$filename;
        if(!is_file($path)&&@file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))
            throw new RuntimeException('Icondatei konnte nicht gespeichert werden.');
        return $filename;
    }

    private function visualNavigationRows(): array
    {
        $rows = [];
        $launch = json_decode($this->ReadPropertyString('LaunchCatalog'), true);
        foreach (is_array($launch) ? $launch : [] as $tile) {
            if (!is_array($tile) || empty($tile['id'])) continue;
            $type = (string)($tile['actionType'] ?? '');
            $target = trim((string)($tile['target'] ?? ''));
            if ($type === '' || $target === '') {
                foreach ((array)($this->launchCatalogDefaults()['tiles'] ?? []) as $preset) {
                    if (($preset['id'] ?? '') !== $tile['id']) continue;
                    if ($type === '') $type = (string)($preset['actionType'] ?? 'route');
                    if ($target === '') $target = (string)($preset['target'] ?? $tile['id']);
                    break;
                }
            }
            if ($type === '') $type = 'route';
            if ($target === '') $target = (string)$tile['id'];
            $rows[] = [
                'source'=>'launch', 'key'=>(string)$tile['id'],
                'title'=>(string)($tile['title'] ?? $tile['id']),
                'actionType'=>$type,
                'target'=>$target,
                'icon'=>(string)($tile['icon'] ?? ''),
                'iconUpload'=>'',
                'color'=>$this->visualTileColorValue((string)($tile['color']??'')),
                'resultTitle'=>(string)($tile['resultTitle']??''),
                'resultIconMode'=>(string)($tile['resultIconMode']??'ai'),
                'resultStatusMode'=>(string)($tile['resultStatusMode']??'auto'),
                'resultLayout'=>(string)($tile['resultLayout']??'auto'),
                'speakAnswer'=>(bool)($tile['speakAnswer']??true),
                'useArchive'=>(bool)($tile['useArchive']??true),
                'detail'=>(string)($tile['subtitle'] ?? ''),
                'status'=>$type === 'ai_query' && $target === '' ? 'Ziel fehlt' : 'Konfiguriert',
            ];
        }
        foreach ($this->readPageMappingsProperty() as $key=>$mapping) {
            $rows[] = [
                'source'=>'page','key'=>(string)$key,
                'title'=>(string)($mapping['label'] ?? $key),
                'actionType'=>(string)($mapping['type'] ?? 'wfc_item'),
                'target'=>(string)($mapping['value'] ?? ''),
                'detail'=>'Page-Zuordnung', 'status'=>'Konfiguriert',
            ];
        }
        $renderers = json_decode($this->ReadPropertyString('RendererDomains'), true);
        foreach (is_array($renderers) ? $renderers : [] as $renderer) {
            if (!is_array($renderer) || empty($renderer['route'])) continue;
            $rows[] = [
                'source'=>'renderer','key'=>(string)$renderer['route'],
                'title'=>(string)($renderer['title'] ?? $renderer['route']),
                'actionType'=>'render',
                'target'=>(string)($renderer['roomDomain'] ?? ''),
                'detail'=>(string)($renderer['aplDoc'] ?? ''),
                'status'=>'Konfiguriert',
            ];
        }
        $context = $this->navigationEditorContext();
        if (is_array($context['edit'])) {
            foreach (IAHSystemNavigationEditor::items($context['edit']) as $entryKey=>$entry) {
                if (!is_array($entry)) continue;
                $rows[] = [
                    'source'=>'navigation',
                    'key'=>(string)$entryKey,
                    'title'=>(string)($entry['title'] ?? ''),
                    'actionType'=>'navigation',
                    'target'=>(int)($entry['objectId'] ?? 0),
                    'detail'=>(string)($entry['roomKey'] ?? '').' / '.(string)($entry['domainKey'] ?? ''),
                    'status'=>empty($entry['enabled']) ? 'Inaktiv' : 'EDIT/PROD separat',
                ];
            }
        }
        return $rows;
    }

    /** Reports the router version actually installed in the generated Action script. */
    private function visualNavigationRuntimeStatus(): string
    {
        $actionId = $this->getActionScriptId();
        $source = $actionId > 0 ? $this->getScriptContent($actionId) : '';
        $version = 0;
        if (is_string($source)) {
            for ($candidate=7; $candidate>=1; --$candidate) {
                $begin='IAH_APL_TILE_ROUTE_V'.$candidate.'_BEGIN';
                $end='IAH_APL_TILE_ROUTE_V'.$candidate.'_END';
                if (str_contains($source,$begin) && str_contains($source,$end)) {
                    $version=$candidate;
                    break;
                }
            }
        }

        // Resolve inherited/default bindings the same way as the editor list.
        $football = null;
        foreach ($this->visualNavigationRows() as $row) {
            if (($row['source']??'')==='launch' && ($row['key']??'')==='bundesliga') {
                $football=$row;
                break;
            }
        }
        $binding=$football===null ? 'nicht vorhanden'
            : (string)$football['actionType'].' → '.(string)$football['target'];
        $routerStatus=$version===7 ? 'V7 INSTALLIERT'
            : ($version>0 ? 'V'.$version.' VERALTET' : 'NICHT GEFUNDEN');
        $instruction=$version===7 ? 'Router ist aktuell.'
            : 'Bitte APL-Klickrouter prüfen / reparieren und danach die Diagnose erneut lesen.';
        return 'Action #'.$actionId.' · APL-Klickrouter: '.$routerStatus
            .' · Fußball: '.$binding.' · '.$instruction;
    }

    public function RepairVisualTileRouting(): void
    {
        $actionId = $this->getActionScriptId();
        if ($actionId <= 0 || !IPS_ScriptExists($actionId)) {
            $status='APL-Klickrouter: Action-Skript nicht gefunden. Keine Änderung durchgeführt.';
        } else {
            $this->patchActionScriptAplTileRoute($actionId);
            $this->patchActionScriptAiStatusPage($actionId);
            $status=$this->visualNavigationRuntimeStatus();
        }
        $this->WriteAttributeString('VisualNavNotice',$status);
        $this->UpdateFormField('VisualNavRuntimeStatus','caption',$status);
        $this->UpdateFormField('VisualNavNotice','caption',$status);
    }


    /**
     * Symcon List.form (>= 7.0): a row-specific dialog, without exposing
     * irrelevant settings from another source. This method is invoked by
     * a short form script, not by an action or the ActionScript router.
     */
    public function GetVisualNavigationEditForm(string $source, string $action): string
    {
        $title=['type'=>'ValidationTextBox','name'=>'title',
            'caption'=>'Anzeigetext','width'=>'100%'];
        $target=['type'=>'ValidationTextBox','name'=>'target',
            'caption'=>'Ziel','width'=>'100%'];
        $fields=[$title];

        if ($source==='launch') {
            $isAi=$action==='ai_query';
            $fields[]=['type'=>'Label','caption'=>'Startkachel: Welche Aktion soll beim Antippen ausgeführt werden?'];
            $fields[]=[
                'type'=>'Select','name'=>'actionType','caption'=>'Beim Antippen',
                'onChange'=>'IAH_UpdateVisualNavigationActionForm($id, $actionType);',
                'options'=>[
                    ['caption'=>'Vorhandene APL-Seite öffnen','value'=>'route'],
                    ['caption'=>'Freie KI-Frage ausführen','value'=>'ai_query']
                ]
            ];
            $target['caption']=$isAi?'Frage an die KI (frei formulierbar)':'APL-Seite / Route (z. B. bundesliga)';
            $fields[]=$target;
            $fields[]=['type'=>'ExpansionPanel','caption'=>'Aussehen der Startkachel',
                'expanded'=>true,'items'=>[
                    ['type'=>'Select','name'=>'icon','caption'=>'Kachelbild aus vorhandenen Dateien',
                        'options'=>$this->visualTileIconOptions()],
                    ['type'=>'SelectFile','name'=>'iconUpload','caption'=>'Oder neues Bild hochladen',
                        'extensions'=>'.png,.jpg,.jpeg,.webp'],
                    ['type'=>'SelectColor','name'=>'color','caption'=>'Kachelfarbe','allowTransparent'=>false]
                ]];
            $fields[]=['type'=>'ExpansionPanel','name'=>'VisualNavAiSettings',
                'caption'=>'KI-Ergebnis auf Alexa Show','visible'=>$isAi,'expanded'=>true,
                'items'=>[
                    ['type'=>'Label','caption'=>'Diese Einstellungen betreffen ausschließlich KI-Kacheln. '
                        .'Die Frage darf jedes Thema enthalten. Keine Geräte werden durch diese KI-Frage geschaltet.'],
                    ['type'=>'ValidationTextBox','name'=>'resultTitle',
                        'caption'=>'Ergebnis-Überschrift (leer = KI entscheidet)','width'=>'100%'],
                    ['type'=>'Select','name'=>'resultIconMode',
                        'caption'=>'Icon der Ergebnisseite','options'=>[
                            ['caption'=>'KI wählt passendes Icon','value'=>'ai'],
                            ['caption'=>'Festes Startkachel-Bild verwenden','value'=>'tile']
                        ]],
                    ['type'=>'Select','name'=>'resultStatusMode',
                        'caption'=>'Status bewerten','options'=>[
                            ['caption'=>'Nur wenn Daten eine Bewertung erlauben','value'=>'auto'],
                            ['caption'=>'Keine Statusbewertung','value'=>'off']
                        ]],
                    ['type'=>'Select','name'=>'resultLayout','caption'=>'Darstellung','options'=>[
                        ['caption'=>'Automatisch anhand der Antwort','value'=>'auto'],
                        ['caption'=>'Nur Antworttext','value'=>'text'],
                        ['caption'=>'Statusseite mit Details','value'=>'status']
                    ]],
                    ['type'=>'CheckBox','name'=>'speakAnswer','caption'=>'Antwort auch vorlesen'],
                    ['type'=>'CheckBox','name'=>'useArchive',
                        'caption'=>'Archivwerte bei Hausfragen berücksichtigen']
                ]];
        } elseif ($source==='navigation') {
            // Navigation is an EDIT-only configuration; publication remains
            // in the existing EDIT/PROD workflow.
            $fields[]=['type'=>'Label','caption'=>'Navigations-Tab (EDIT): '
                .'Titel und verknüpftes IP-Symcon-Objekt ändern. '
                .'Zum Veröffentlichen anschließend EDIT nach PROD übernehmen.'];
            $fields[]=['type'=>'SelectObject','name'=>'target',
                'caption'=>'Verknüpftes IP-Symcon-Objekt','width'=>'100%'];
        } elseif ($source==='page') {
            $fields[]=['type'=>'Label','caption'=>'Page-Zuordnung: Der Schlüssel bleibt unverändert. '
                .'Ein IPS-Element benötigt eine Item-ID, ein externer Link eine HTTPS-Adresse.'];
            $fields[]=['type'=>'Select','name'=>'actionType','caption'=>'Typ der Zielseite',
                'onChange'=>'IAH_UpdateVisualNavigationActionForm($id, $actionType);',
                'options'=>[
                    ['caption'=>'IPS-Visualisierungselement (z. B. item2124)','value'=>'wfc_item'],
                    ['caption'=>'Externe HTTPS-Seite','value'=>'external_url']
                ]];
            $target['caption']=$action==='external_url'
                ? 'Vollständige HTTPS-Adresse':'IPS Item-ID (z. B. item2124)';
            $fields[]=$target;
        } elseif ($source==='renderer') {
            $fields[]=['type'=>'Label','caption'=>'Renderer: Die vorhandene APL-Vorlage bleibt bestehen. '
                .'Hier wird nur die RoomsCatalog-Domäne zugeordnet.'];
            $fields[]=['type'=>'ValidationTextBox','name'=>'target',
                'caption'=>'RoomsCatalog-Domäne (z. B. devices)','width'=>'100%'];
        } else {
            $fields[]=['type'=>'Label','caption'=>'Unbekannte Herkunft: keine Bearbeitung möglich.'];
        }
        return json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    /** Adjust dependent fields within the currently opened List dialog. */
    public function UpdateVisualNavigationActionForm(string $action): void
    {
        $this->UpdateFormField('target','caption',match($action) {
            'ai_query'=>'Frage an die KI (frei formulierbar)',
            'route'=>'APL-Seite / Route (z. B. bundesliga)',
            'external_url'=>'Vollständige HTTPS-Adresse',
            'wfc_item'=>'IPS Item-ID (z. B. item2124)',
            default=>'Ziel'
        });
        // Only launch rows contain this named panel. In a page mapping
        // there is no such panel, so leave it alone in that case.
        if (in_array($action,['route','ai_query'],true)) {
            $this->UpdateFormField('VisualNavAiSettings','visible',$action==='ai_query');
        }
    }

    private function buildVisualNavigationForm(): array
    {
        $onEdit = 'IAH_SaveVisualNavigationEntry($id, json_encode(['
            .'"source"=>$VisualNavRows["source"], '
            .'"key"=>$VisualNavRows["key"], '
            .'"title"=>$VisualNavRows["title"], '
            .'"actionType"=>$VisualNavRows["actionType"], '
            .'"target"=>$VisualNavRows["target"], '
            .'"icon"=>($VisualNavRows["icon"] ?? ""), '
            .'"iconUpload"=>($VisualNavRows["iconUpload"] ?? ""), '
            .'"color"=>($VisualNavRows["color"] ?? 3562633), '
            .'"resultTitle"=>($VisualNavRows["resultTitle"] ?? ""), '
            .'"resultIconMode"=>($VisualNavRows["resultIconMode"] ?? "ai"), '
            .'"resultStatusMode"=>($VisualNavRows["resultStatusMode"] ?? "auto"), '
            .'"resultLayout"=>($VisualNavRows["resultLayout"] ?? "auto"), '
            .'"speakAnswer"=>($VisualNavRows["speakAnswer"] ?? true), '
            .'"useArchive"=>($VisualNavRows["useArchive"] ?? true)'
            .'], JSON_INVALID_UTF8_SUBSTITUTE));';
        return [
            'type'=>'ExpansionPanel',
            'caption'=>'Visualisierung & Navigation – zentral bearbeiten',
            'expanded'=>true,
            'items'=>[
                ['type'=>'Label','caption'=>'Alle Verknüpfungen an einem Ort. '
                    .'Aktion und Ziel einer Launch-Kachel bearbeiten: "Seite öffnen" verwendet eine vorhandene Route; '
                    .'"KI-Frage" fragt die KI und zeigt Antwort, automatisch gewähltes Icon und Status. '
                    .'Page-Zuordnungen und Renderer werden direkt übernommen. '
                    .'Navigationstabs bleiben zum Schutz der bestehenden EDIT/PROD-Veröffentlichung separat versioniert.'],
                ['type'=>'Label','name'=>'VisualNavRuntimeStatus',
                    'caption'=>$this->visualNavigationRuntimeStatus()],
                ['type'=>'Button','caption'=>'APL-KLICKROUTER PRÜFEN / REPARIEREN',
                    'onClick'=>'IAH_RepairVisualTileRouting($id);'],
                ['type'=>'Label','name'=>'VisualNavNotice',
                    'caption'=>$this->ReadAttributeString('VisualNavNotice')],
                ['type'=>'List','name'=>'VisualNavRows',
                    'caption'=>'Verknüpfungen nach Schlüssel zusammengefasst',
                    'rowCount'=>15,'add'=>false,'delete'=>false,
                    'loadValuesFromConfiguration'=>false,
                    'values'=>$this->visualNavigationGroups(),
                    'columns'=>[
                         ['name'=>'key','caption'=>'Schlüssel','width'=>'130px','save'=>false],
                         ['name'=>'title','caption'=>'Anzeigename','width'=>'200px','save'=>true],
                         ['name'=>'parts','caption'=>'Zusammengehörige Elemente','width'=>'250px','save'=>false],
                         ['name'=>'actionType','caption'=>'Startkachel / Typ','width'=>'170px',
                             'save'=>true,'edit'=>['type'=>'Select','options'=>[
                                 ['caption'=>'APL-Seite öffnen','value'=>'route'],
                                 ['caption'=>'KI-Frage','value'=>'ai_query'],
                                 ['caption'=>'Seitenzuordnung','value'=>'wfc_item'],
                                 ['caption'=>'Renderer','value'=>'render'],
                                 ['caption'=>'Navigation EDIT','value'=>'navigation']
                             ]]],
                         ['name'=>'target','caption'=>'Ziel / Frage','width'=>'235px','save'=>true],
                         ['name'=>'status','caption'=>'Status','width'=>'155px','save'=>false],
                         ['name'=>'groupId','visible'=>false,'save'=>true],
                     ],
                     'form'=>'return json_decode(IAH_GetVisualNavigationGroupForm('
                         .'$id, (string)($VisualNavRows["groupId"] ?? "")), true);',
                                          
                     'onEdit'=>'IAH_SaveVisualNavigationGroup($id, json_encode($VisualNavRows, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));',
                ],
                ['type'=>'Label','caption'=>'KI-Kachel testen: ID einer bestehenden Kachel eingeben. '
                    .'Der Test verwendet die echte KI-/Datenquelle (gegebenenfalls API-Kosten), '
                    .'schaltet aber keine Geräte.'],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'ValidationTextBox','name'=>'VisualNavTestKey','caption'=>'Kachel-ID für Test','value'=>''],
                    ['type'=>'Button','caption'=>'KI-FRAGE TESTEN',
                        'onClick'=>'IAH_TestVisualNavigationTile($id, $VisualNavTestKey);']
                ]],
                ['type'=>'Label','name'=>'VisualNavTestStatus','caption'=>'Noch kein Test ausgeführt.'],
                ['type'=>'Button','caption'=>'LETZTE VISUALISIERUNGSÄNDERUNG ZURÜCKNEHMEN',
                    'confirm'=>'Die vorherige Konfiguration der zuletzt geänderten Kachel-, Page- oder Renderer-Liste wiederherstellen?',
                    'onClick'=>'IAH_RestoreVisualNavigationBackup($id);'],
                ['type'=>'Label','caption'=>'Neue Startkachel mit verständlicher Aktion anlegen:'],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'ValidationTextBox','name'=>'VisualNavNewKey','caption'=>'Kachel-ID','value'=>''],
                    ['type'=>'ValidationTextBox','name'=>'VisualNavNewTitle','caption'=>'Titel','value'=>''],
                    ['type'=>'Select','name'=>'VisualNavNewAction','caption'=>'Aktion','value'=>'route',
                        'options'=>[['caption'=>'Seite öffnen','value'=>'route'],['caption'=>'KI-Frage','value'=>'ai_query']]],
                    ['type'=>'ValidationTextBox','name'=>'VisualNavNewTarget','caption'=>'Route oder genaue Frage','value'=>''],
                    ['type'=>'Select','name'=>'VisualNavNewIcon','caption'=>'Vorhandenes Kachelbild',
                        'options'=>$this->visualTileIconOptions(),'value'=>''],
                    ['type'=>'SelectColor','name'=>'VisualNavNewColor','caption'=>'Kachelfarbe',
                        'allowTransparent'=>false,'value'=>0x365C89],
                    ['type'=>'SelectFile','name'=>'VisualNavNewIconUpload','caption'=>'Oder Bild hochladen',
                        'extensions'=>'.png,.jpg,.jpeg,.webp'],
                ]],
                ['type'=>'Button','caption'=>'STARTKACHEL ANLEGEN',
                    'onClick'=>'IAH_CreateVisualNavigationTile($id, $VisualNavNewKey, $VisualNavNewTitle, $VisualNavNewAction, $VisualNavNewTarget, $VisualNavNewIcon, $VisualNavNewColor, (string)$VisualNavNewIconUpload);'],
                ['type'=>'ExpansionPanel','caption'=>'Navigationstabs (EDIT/PROD) bearbeiten',
                    'expanded'=>false,'items'=>[$this->buildSystemNavigationEditorForm()]],
            ],
        ];
    }

    /**
     * Reports only the shape of the script output. Never copies the raw PHP
     * error page, model response, token or local directory into the UI/log.
     */
    private function visualTileOutputDiagnostic(mixed $raw): string
    {
        $payload=is_string($raw)?trim($raw):'';
        $kind='unexpected_text';
        $type='none';
        $file='';
        $line=0;
        if ($payload==='') {
            $kind='empty';
        } elseif (preg_match('/\\b(?:Parse error|syntax error|Fatal error|Uncaught|Compile Error)\\b/i',$payload)) {
            $kind='php_error';
            $type=preg_match('/Parse error|syntax error/i',$payload)?'parse'
                :(preg_match('/Uncaught/i',$payload)?'uncaught':'fatal');
        } elseif (preg_match('/\\b(?:Warning|Notice|Deprecated|Recoverable fatal error)\\s*:/i',$payload)) {
            $kind='php_warning';
            $type=preg_match('/Deprecated/i',$payload)?'deprecated'
                :(preg_match('/Notice/i',$payload)?'notice':'warning');
        } elseif (preg_match('/^\\s*</',$payload)) {
            $kind='html';
        } elseif (preg_match('/^[\\[{]/',$payload)) {
            $kind='malformed_json';
        }
        if ($kind==='php_error'||$kind==='php_warning') {
            if (preg_match('~(?:in|thrown in)\\s+([^\\r\\n]+?\\.php)\\s+(?:on\\s+)?line\\s+(\\d{1,7})~i',
                $payload,$location)) {
                $file=preg_replace('/[^A-Za-z0-9_.-]/','',
                    basename(str_replace('\\\\','/',$location[1])))??'';
                $line=(int)$location[2];
            }
        }
        $summary='Ausgabetyp='.get_debug_type($raw)
            .' · Länge='.strlen($payload)
            .' · Kategorie='.$kind
            .' · PHP-Fehler='.$type;
        if ($file!=='') $summary.=' · Datei='.$file.' · Zeile='.$line;
        return $summary;
    }

    /** Read-only test using the same KI controller as an APL tile click. */
    public function TestVisualNavigationTile(string $key): void
    {
        $key=strtolower(trim($key));
        $message='';
        try {
            $tiles=json_decode($this->ReadPropertyString('LaunchCatalog'),true);
            $tile=null;
            foreach (is_array($tiles)?$tiles:[] as $candidate) {
                if (is_array($candidate) && strtolower((string)($candidate['id']??''))===$key) {
                    $tile=$candidate; break;
                }
            }
            if($tile===null)throw new RuntimeException('Kachel-ID nicht gefunden.');
            $action=(string)($tile['actionType']??'route');
            if($action!=='ai_query') {
                $message='Kachel '.(string)($tile['title']??$key)
                    .': APL-Seite öffnen → '.(string)($tile['target']??$key)
                    .' (keine KI-Abfrage).';
            } else {
                $question=trim((string)($tile['target']??''));
                if($question==='')throw new RuntimeException('Kein Fragetext hinterlegt.');
                $helper=$this->getObjectIDByIdentOrName($this->InstanceID,'iahHelper','Alexa new devices helper');
                $script=$helper>0?$this->getObjectIDByIdentOrName($helper,'kiHouseControllerScript','KIHouseController'):0;
                if($script<=0 || !IPS_ScriptExists($script))
                    throw new RuntimeException('KIHouseController nicht gefunden.');
                $settings=$this->getObjectIDByIdentOrName($this->InstanceID,'iahSettings','Einstellungen');
                $catalog=$settings>0?$this->getObjectIDByIdentOrName($settings,'roomsCatalog','RoomsCatalog'):0;
                $config=[
                    'catalog_script_id'=>$catalog,
                    'archive_control_id'=>(bool)($tile['useArchive']??true)
                        ?(int)$this->ReadPropertyInteger('KIArchiveControlId'):0,
                    'api_key'=>$this->ReadPropertyString('KIIntentApiKey'),
                    'model'=>$this->ReadPropertyString('KIModelSelection'),
                    'max_words'=>(int)$this->ReadPropertyInteger('HomeAgentVoiceMaxWords'),
                    'timeout_seconds'=>(int)$this->ReadPropertyInteger('HomeAgentVoiceTimeoutSeconds'),
                    'device_diagnostics'=>false,
                    'allow_writes'=>false,
                    'ki_cost_instance_id'=>$this->InstanceID,
                    'tile_display'=>true
                ];
                $raw=IPS_RunScriptWaitEx($script,[
                    'kiCatalogText'=>$question,
                    'kiCatalogConfigJson'=>json_encode($config,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
                ]);
                $data=is_string($raw)?json_decode(trim($raw),true):null;
                if(!is_array($data)||empty($data['handled'])) {
                    $errorCode=is_array($data)?(string)($data['error']??'unhandled'):'invalid_json';
                    $diagnostic=$this->visualTileOutputDiagnostic($raw);
                    // Only fixed fields, never the raw helper output.
                    IPS_LogMessage('Alexa.KITile',
                        'Test '.preg_replace('/[^a-z0-9_-]/','',$key)
                        .' · '.$errorCode.' · '.$diagnostic);
                    throw new RuntimeException('Keine verwertbare Antwort ('
                        .$errorCode.'). '.$diagnostic);
                }
                $title=trim((string)($tile['resultTitle']??''));
                if($title==='')$title=(string)($data['displayTitle']??$tile['title']??'KI-Ergebnis');
                $icon=($tile['resultIconMode']??'ai')==='tile'
                    ?(string)($tile['icon']??''): (string)($data['displayIcon']??'device');
                $status=($tile['resultStatusMode']??'auto')==='off'
                    ? 'ausgeblendet' : (string)($data['displayStatus']??'unknown');
                $answer=mb_substr((string)($data['answer']??''),0,500,'UTF-8');
                $message='KI-Test erfolgreich · Titel: '.$title.' · Icon: '.$icon
                    .' · Status: '.$status.' · Layout: '.(string)($tile['resultLayout']??'auto')
                    .' · Detailzeilen: '.count((array)($data['displayRows']??[]))
                    .' · Antwort: '.$answer;
            }
        } catch(\Throwable $e) {
            $message='KI-Test fehlgeschlagen: '.$e->getMessage();
        }
        $this->UpdateFormField('VisualNavTestStatus','caption',$message);
    }

    public function SaveVisualNavigationEntry(string $payload): void
    {
        try {
            $entry = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($entry)) throw new RuntimeException('Ungültige Eingabe.');
            $source = (string)($entry['source'] ?? '');
            $key = (string)($entry['key'] ?? '');
            $title = trim((string)($entry['title'] ?? ''));
            $action = (string)($entry['actionType'] ?? '');
            $target = trim((string)($entry['target'] ?? ''));
            $icon=trim((string)($entry['icon']??''));
            $iconUpload=trim((string)($entry['iconUpload']??''));
            $color=$entry['color']??0x365C89;
            if ($key === '' || $title === '' || $target === '') {
                throw new RuntimeException('Schlüssel, Titel und Ziel dürfen nicht leer sein.');
            }
            if ($source === 'navigation') {
                if ($action !== 'navigation' || !ctype_digit($target)) {
                    throw new RuntimeException('Für Navigation eine numerische IPS-Objekt-ID angeben.');
                }
                $context = $this->navigationEditorContext();
                if (!is_array($context['edit'])) {
                    throw new RuntimeException('SystemConfigurationEdit ist nicht lesbar.');
                }
                // Use the normal editor's row, including its revision hash.
                // Saving the raw item loses "revision" and cannot pass the
                // concurrency protection in IAHSystemNavigationEditor::update.
                foreach (IAHSystemNavigationEditor::rows($context['edit']) as $navigation) {
                    if ((string)($navigation['entryKey'] ?? '') !== $key) continue;
                    $navigation['title'] = $title;
                    $navigation['objectId'] = (int)$target;
                    $this->SaveSystemNavigationTab(json_encode($navigation,
                        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                    return;
                }
                throw new RuntimeException('Navigations-Tab nicht gefunden.');
            }
            $property = match ($source) {
                'launch'=>'LaunchCatalog','page'=>'PageMappings','renderer'=>'RendererDomains',
                default=>throw new RuntimeException('Unbekannte Herkunft.'),
            };
            $rows = json_decode($this->ReadPropertyString($property), true);
            if (!is_array($rows)) throw new RuntimeException('Gespeicherte Konfiguration nicht lesbar.');
            $matchKey = $source === 'page' ? 'key' : ($source === 'renderer' ? 'route' : 'id');
            $found = false;
            foreach ($rows as &$row) {
                if (!is_array($row) || (string)($row[$matchKey] ?? '') !== $key) continue;
                $found = true;
                if ($source === 'launch') {
                    if (!in_array($action, ['route','ai_query'], true)) {
                        throw new RuntimeException('Launch-Kacheln unterstützen Seite öffnen oder KI-Frage.');
                    }
                    if ($action === 'route' && preg_match('/^[a-z0-9_-]+$/', $target) !== 1) {
                        throw new RuntimeException('Die Route darf nur Buchstaben, Zahlen, _ und - enthalten.');
                    }
                    if ($action === 'ai_query' && mb_strlen($target, 'UTF-8') > 240) {
                        throw new RuntimeException('Frage ist zu lang (max. 240 Zeichen).');
                    }
                    $row['title'] = $title;
                    $row['actionType'] = $action;
                    $row['target'] = $target;
                    if($iconUpload!=='')$icon=$this->visualTileSaveUploadedIcon($key,$iconUpload);
                    if($icon!==''&&!preg_match('/^[A-Za-z0-9_-]+\\.(?:png|jpe?g|webp)$/iD',$icon))
                        throw new RuntimeException('Ungültiger Icon-Dateiname.');
                    $row['icon']=$icon;
                    $row['color']=$this->visualTileColorHex($color);
                    foreach ([
                        'resultIconMode'=>['ai','tile'],
                        'resultStatusMode'=>['auto','off'],
                        'resultLayout'=>['auto','text','status'],
                    ] as $name=>$allowed) {
                        $value=(string)($entry[$name]??$row[$name]??$allowed[0]);
                        if (!in_array($value,$allowed,true))
                            throw new RuntimeException('Ungültige KI-Anzeigeoption: '.$name);
                        $row[$name]=$value;
                    }
                    $row['resultTitle']=mb_substr(trim((string)($entry['resultTitle']??'')),0,90,'UTF-8');
                    $row['speakAnswer']=(bool)($entry['speakAnswer']??true);
                    $row['useArchive']=(bool)($entry['useArchive']??true);
                } elseif ($source === 'page') {
                    if (!in_array($action, ['wfc_item','external_url'], true)) {
                        throw new RuntimeException('Ungültiger Seitentyp.');
                    }
                    if ($action === 'external_url' && !str_starts_with($target, 'https://')) {
                        throw new RuntimeException('Externe Seiten benötigen HTTPS.');
                    }
                    $row['label']=$title; $row['type']=$action; $row['value']=$target;
                } elseif ($source === 'renderer') {
                    if ($action !== 'render') throw new RuntimeException('Renderer-Aktion nicht veränderbar.');
                    $row['title']=$title; $row['roomDomain']=$target;
                }
                break;
            }
            unset($row);
            if (!$found) throw new RuntimeException('Eintrag wurde nicht gefunden; bitte neu laden.');
            if (function_exists('IPS_HasChanges') && IPS_HasChanges($this->InstanceID)) {
                throw new RuntimeException('Bitte zuerst ungespeicherte Instanzeinstellungen übernehmen.');
            }
            $this->WriteAttributeString('VisualNavBackup', json_encode([
                'property'=>$property,'previous'=>$this->ReadPropertyString($property)
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            IPS_SetProperty($this->InstanceID, $property,
                json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            IPS_ApplyChanges($this->InstanceID);
            $this->WriteAttributeString('VisualNavNotice', 'Gespeichert: '.$source.' / '.$key);
        } catch (Throwable $e) {
            $this->WriteAttributeString('VisualNavNotice', 'Nicht gespeichert: '.$e->getMessage());
        }
        $this->ReloadForm();
    }

    public function CreateVisualNavigationTile(string $key,string $title,string $action,string $target,
        string $icon='',mixed $color=0x365C89,string $iconUpload=''): void
    {
        $key=strtolower(trim($key)); $title=trim($title); $target=trim($target);
        try {
            if (!preg_match('/^[a-z0-9_-]{2,48}$/', $key) || $title==='' || $target==='') {
                throw new RuntimeException('ID (a-z, 0-9, _, -), Titel und Ziel angeben.');
            }
            if (!in_array($action,['route','ai_query'],true)) throw new RuntimeException('Ungültige Aktion.');
            if ($action === 'route' && !preg_match('/^[a-z0-9_-]+$/', $target))
                throw new RuntimeException('Bitte eine gültige Route angeben.');
            if ($action === 'ai_query' && mb_strlen($target,'UTF-8') > 240)
                throw new RuntimeException('Frage ist zu lang.');
            $rows=json_decode($this->ReadPropertyString('LaunchCatalog'),true);
            if (!is_array($rows)) throw new RuntimeException('Kachelkonfiguration nicht lesbar.');
            foreach ($rows as $row) if (($row['id']??'')===$key) throw new RuntimeException('ID existiert bereits.');
            if($iconUpload!=='')$icon=$this->visualTileSaveUploadedIcon($key,$iconUpload);
            if($icon!==''&&!preg_match('/^[A-Za-z0-9_-]+\\.(?:png|jpe?g|webp)$/iD',$icon))
                throw new RuntimeException('Ungültiger Icon-Dateiname.');
            $rows[]=['id'=>$key,'title'=>$title,'subtitle'=>'','actionType'=>$action,'target'=>$target,
                'icon'=>$icon,'color'=>$this->visualTileColorHex($color),
                'resultTitle'=>'','resultIconMode'=>'ai','resultStatusMode'=>'auto',
                'resultLayout'=>'auto','speakAnswer'=>true,'useArchive'=>true];
            if (function_exists('IPS_HasChanges') && IPS_HasChanges($this->InstanceID)) {
                throw new RuntimeException('Bitte zuerst ungespeicherte Instanzeinstellungen übernehmen.');
            }
            $this->WriteAttributeString('VisualNavBackup', json_encode([
                'property'=>'LaunchCatalog','previous'=>$this->ReadPropertyString('LaunchCatalog')
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            IPS_SetProperty($this->InstanceID,'LaunchCatalog',
                json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            IPS_ApplyChanges($this->InstanceID);
            $this->WriteAttributeString('VisualNavNotice','Kachel angelegt: '.$title);
        } catch(Throwable $e) {
            $this->WriteAttributeString('VisualNavNotice','Nicht angelegt: '.$e->getMessage());
        }
        $this->ReloadForm();
    }
    public function RestoreVisualNavigationBackup(): void
    {
        try {
            $backup=json_decode($this->ReadAttributeString('VisualNavBackup'),true,64,JSON_THROW_ON_ERROR);
            if (!is_array($backup)) throw new RuntimeException('Kein gültiges Backup vorhanden.');
            $allowed=['LaunchCatalog','PageMappings','RendererDomains'];
            $originals=[];
            if (isset($backup['properties']) && is_array($backup['properties'])) {
                $originals=$backup['properties'];
            } elseif (in_array(($backup['property']??''),$allowed,true)
                && is_string($backup['previous']??null)) {
                // Backward compatible with existing single-entry backups.
                $originals[$backup['property']]=$backup['previous'];
            }
            if ($originals===[])throw new RuntimeException('Kein gültiges Backup vorhanden.');
            foreach ($originals as $property=>$raw) {
                if(!in_array($property,$allowed,true)||!is_string($raw)
                    || !is_array(json_decode($raw,true)))
                    throw new RuntimeException('Backup enthält ungültige Eigenschaften.');
            }
            if (function_exists('IPS_HasChanges') && IPS_HasChanges($this->InstanceID))
                throw new RuntimeException('Bitte zuerst andere ungespeicherte Änderungen übernehmen.');
            // Protect the current state against a partial restore failure.
            $current=[];
            foreach ($originals as $property=>$_) $current[$property]=$this->ReadPropertyString($property);
            try {
                foreach ($originals as $property=>$raw)
                    IPS_SetProperty($this->InstanceID,$property,$raw);
                IPS_ApplyChanges($this->InstanceID);
            } catch (\Throwable $restoreError) {
                foreach ($current as $property=>$raw)
                    IPS_SetProperty($this->InstanceID,$property,$raw);
                IPS_ApplyChanges($this->InstanceID);
                throw $restoreError;
            }
            $this->WriteAttributeString('VisualNavBackup','');
            $this->WriteAttributeString('VisualNavNotice',
                'Letzte Änderung zurückgenommen: '.implode(', ',array_keys($originals)));
        } catch(\Throwable $e) {
            $this->WriteAttributeString('VisualNavNotice','Wiederherstellung fehlgeschlagen: '.$e->getMessage());
        }
        $this->ReloadForm();
    }

}
