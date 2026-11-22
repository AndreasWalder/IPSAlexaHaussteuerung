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
            foreach (IAHSystemNavigationEditor::items($context['edit']) as $entry) {
                if (!is_array($entry)) continue;
                $rows[] = [
                    'source'=>'navigation',
                    'key'=>(string)($entry['entryKey'] ?? $entry['tabKey'] ?? ''),
                    'title'=>(string)($entry['title'] ?? ''),
                    'actionType'=>'navigation',
                    'target'=>(string)($entry['objectId'] ?? 0),
                    'detail'=>(string)($entry['roomKey'] ?? '').' / '.(string)($entry['domainKey'] ?? ''),
                    'status'=>empty($entry['enabled']) ? 'Inaktiv' : 'EDIT/PROD separat',
                ];
            }
        }
        return $rows;
    }

    private function visualNavigationRuntimeStatus(): string
    {
        $actionId = $this->getActionScriptId();
        $source = $actionId > 0 ? $this->getScriptContent($actionId) : '';
        $installed = is_string($source)
            && str_contains($source, 'IAH_APL_TILE_ROUTE_V5_BEGIN')
            && str_contains($source, 'IAH_APL_TILE_ROUTE_V5_END');
        $entries = json_decode($this->ReadPropertyString('LaunchCatalog'), true);
        $football = null;
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (($entry['id'] ?? '') === 'bundesliga') { $football=$entry; break; }
        }
        $binding = $football === null ? 'nicht vorhanden' :
            (string)($football['actionType'] ?? '(Altbestand)').' → '
            .(string)($football['target'] ?? '(kein Ziel)');
        return 'Action #'.$actionId.' · APL-Klickrouter V5: '
            .($installed?'INSTALLIERT':'FEHLT').' · Fußball: '.$binding
            .' · Falls der Router fehlt: unten Reparatur starten.';
    }

    public function RepairVisualTileRouting(): void
    {
        $actionId = $this->getActionScriptId();
        if ($actionId > 0 && IPS_ScriptExists($actionId)) {
            $this->patchActionScriptAplTileRoute($actionId);
            $this->patchActionScriptAiStatusPage($actionId);
        }
        $status = $this->visualNavigationRuntimeStatus();
        $this->WriteAttributeString('VisualNavNotice', $status);
        $this->UpdateFormField('VisualNavRuntimeStatus', 'caption', $status);
        $this->UpdateFormField('VisualNavNotice', 'caption', $status);
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
            .'"color"=>($VisualNavRows["color"] ?? 3562633)'
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
                    'caption'=>'Kacheln, Seiten und Verknüpfungen',
                    'rowCount'=>15,'add'=>false,'delete'=>false,
                    'loadValuesFromConfiguration'=>false,
                    'values'=>$this->visualNavigationRows(),
                    'columns'=>[
                        ['name'=>'source','caption'=>'Herkunft','width'=>'110px','save'=>false],
                        ['name'=>'key','caption'=>'Interner Schlüssel','width'=>'130px','save'=>false],
                        ['name'=>'title','caption'=>'Anzeigetext','width'=>'165px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['name'=>'actionType','caption'=>'Beim Antippen','width'=>'145px',
                            'edit'=>['type'=>'Select','options'=>[
                                ['caption'=>'Seite öffnen','value'=>'route'],
                                ['caption'=>'KI-Frage ausführen','value'=>'ai_query'],
                                ['caption'=>'IPS-Element','value'=>'wfc_item'],
                                ['caption'=>'Externe HTTPS-URL','value'=>'external_url'],
                                ['caption'=>'Renderer','value'=>'render'],
                                ['caption'=>'Navigations-Tab (EDIT)','value'=>'navigation'],
                            ]]],
                        ['name'=>'target','caption'=>'Ziel / Frage / Objekt','width'=>'235px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['name'=>'icon','caption'=>'Kachelbild (vorhandene Datei)','width'=>'180px',
                            'edit'=>['type'=>'Select','options'=>$this->visualTileIconOptions()]],
                        ['name'=>'iconUpload','caption'=>'Bild hochladen','width'=>'110px',
                            'edit'=>['type'=>'SelectFile','extensions'=>'.png,.jpg,.jpeg,.webp']],
                        ['name'=>'color','caption'=>'Kachelfarbe','width'=>'105px',
                            'edit'=>['type'=>'SelectColor','allowTransparent'=>false]],
                        ['name'=>'detail','caption'=>'Zusatzinformation','width'=>'190px','save'=>false],
                        ['name'=>'status','caption'=>'Status','width'=>'140px','save'=>false],
                    ],
                    'onEdit'=>$onEdit,
                ],
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
                foreach (IAHSystemNavigationEditor::items($context['edit']) as $navigation) {
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
                'icon'=>$icon,'color'=>$this->visualTileColorHex($color)];
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
            if (!is_array($backup) || !in_array(($backup['property']??''),[
                'LaunchCatalog','PageMappings','RendererDomains'
            ],true) || !is_string($backup['previous']??null)) {
                throw new RuntimeException('Kein gültiges Backup vorhanden.');
            }
            if (function_exists('IPS_HasChanges') && IPS_HasChanges($this->InstanceID)) {
                throw new RuntimeException('Bitte zuerst ungespeicherte Instanzeinstellungen übernehmen.');
            }
            IPS_SetProperty($this->InstanceID,$backup['property'],$backup['previous']);
            IPS_ApplyChanges($this->InstanceID);
            $this->WriteAttributeString('VisualNavBackup','');
            $this->WriteAttributeString('VisualNavNotice','Letzte Änderung zurückgenommen.');
        } catch(Throwable $e) {
            $this->WriteAttributeString('VisualNavNotice','Wiederherstellung fehlgeschlagen: '.$e->getMessage());
        }
        $this->ReloadForm();
    }
}
