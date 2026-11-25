<?php
declare(strict_types=1);

/**
 * Central variable replacement for the RoomsCatalog EDIT tree.
 * No direct PROD changes. Explicit scan/preview instead of a costly
 * tree/archive inventory on every GetConfigurationForm invocation.
 */
trait RCCCatalogVariableBindingsTrait
{
    private function rccBindingTrace(string $event, array $data=[]): void
    {
        try {
            $log=json_decode($this->ReadAttributeString('CatalogBindingLog'),true);
            if (!is_array($log))$log=[];
            $log[]=['at'=>date('Y-m-d H:i:s'),'event'=>$event,'data'=>$data];
            $this->WriteAttributeString('CatalogBindingLog',json_encode(
                array_slice($log,-250),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (Throwable $ignored) {}
    }

    private static function rccIsId(mixed $v): int
    {
        if (is_int($v) && $v>0)return $v;
        if (is_string($v) && preg_match('/^[1-9][0-9]{1,9}$/D',$v))return (int)$v;
        return 0;
    }

    private static function rccVariableKey(string $field, array $path): bool
    {
        $name=strtolower($field);
        if (in_array($name,['parent','parentid','sourcerootid','archiveid','archiveinstanceid',
            'scriptid','instanceid','roomid','zone','order','sortorder',
            'sourceid','eventid','controllerid','deviceid','moduleid','timestamp'],true))return false;
        if (preg_match('/(?:variable|sensor|value|status|control|feedback|position|target|measurement|power|temperature|humidity|motion|battery|tilt)id$/iD',$field))return true;
        if (str_starts_with($name,'ai') && str_ends_with($name,'id'))return true;
        if (in_array($name,['set','state','ist','soll','wert','toggle','id','pumpid'],true)) {
            // Generic IDs are only considered inside a catalog leaf, point
            // definition or dedicated variables map, not metadata/root objects.
            return count($path)>=4;
        }
        if (in_array('variables',$path,true))return true;
        if ($name==='value' && in_array('variables',$path,true))return true;
        return false;
    }

    private function rccBindingRefs(array $tree): array
    {
        $refs=[];
        $walk=static function (array $branch,array $path=[]) use (&$walk,&$refs): void {
            foreach ($branch as $key=>$value) {
                $at=[...$path,$key];
                $name=(string)$key;
                $id=self::rccIsId($value);
                $container=(string)($path[count($path)-1]??'');
                $nonVariableField=in_array(strtolower($name),[
                    'parent','parentid','sourcerootid','archiveid','archiveinstanceid',
                    'scriptid','instanceid','roomid','zone','order','sortorder',
                    'sourceid','eventid','controllerid','deviceid','moduleid',
                    'timestamp','duration','timeout','count','port','threshold',
                    'minutes','seconds','day','month','year'],true);
                if ($id>0 && ($id>=10000 || IPS_VariableExists($id))
                    && !$nonVariableField
                    && (self::rccVariableKey($name,$path)
                        || $container==='voiceActions'
                        || (IPS_VariableExists($id) && count($path)>=3)))
                    $refs[]=['id'=>$id,'path'=>$at,'kind'=>'value'];
                if (ctype_digit($name) && self::rccIsId($name)>0
                    && in_array($container,['points','heater'],true))
                    $refs[]=['id'=>(int)$name,'path'=>$at,'kind'=>'key'];
                if (is_array($value))$walk($value,$at);
            }
        };
        $walk($tree);
        return $refs;
    }

    private static function rccPathText(array $path): string
    {
        return implode(' / ',array_map('strval',$path));
    }

    private function rccBindingInfo(int $id): array
    {
        $out=['id'=>$id,'exists'=>false,'parent'=>0,'type'=>-1,'profile'=>'',
            'archive'=>'Unbekannt','name'=>'Variable nicht vorhanden'];
        if ($id<=0 || !IPS_VariableExists($id))return $out;
        $o=IPS_GetObject($id);$v=IPS_GetVariable($id);
        $out['exists']=true;
        $out['parent']=(int)($o['ParentID']??0);
        $out['name']=(string)($o['ObjectName']??'');
        $out['type']=(int)($v['VariableType']??-1);
        $out['profile']=(string)(($v['VariableCustomProfile']??'')?:($v['VariableProfile']??''));
        return $out;
    }

    /** Lazy inventory: only executed by the explicit scan button. */
    public function ScanCatalogVariableBindings(string $filter=''): void
    {
        try {
            [$script,$old,$catalog]=$this->systemTreeEditContext();
            $counts=[];
            foreach ($this->rccBindingRefs($catalog) as $ref) {
                $id=$ref['id'];
                if (!isset($counts[$id]))$counts[$id]=['id'=>$id,'count'=>0,'paths'=>[]];
                $counts[$id]['count']++;
                if (count($counts[$id]['paths'])<3)
                    $counts[$id]['paths'][]=self::rccPathText($ref['path']);
            }
            ksort($counts,SORT_NUMERIC);
            $rows=[];
            $filter=mb_strtolower(trim($filter),'UTF-8');
            foreach ($counts as $id=>$ref) {
                if ($filter!=='' && !str_contains(mb_strtolower(
                    (string)$id.' '.implode(' ',$ref['paths']),'UTF-8'),$filter)
                    && (!IPS_VariableExists((int)$id)
                        || !str_contains(mb_strtolower(IPS_GetName((int)$id),'UTF-8'),$filter)))
                    continue;
                $info=$this->rccBindingInfo((int)$id);
                $rows[]=['variableId'=>(int)$id,'name'=>$info['name'],
                    'parent'=>$info['parent'],'type'=>$info['type'],
                    'profile'=>$info['profile'],'references'=>$ref['count'],
                    'paths'=>implode(' | ',$ref['paths']),
                    'status'=>$info['exists']?'Gültig':'FEHLT'];
            }
            $visible=array_slice($rows,0,200);
            $this->WriteAttributeString('CatalogBindingInventory',json_encode($visible,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $this->WriteAttributeString('CatalogBindingNotice',count($rows).' IDs zur Suche passend, '
                .count($visible).' angezeigt. Suche für weitere IDs eingrenzen. '
                .'Bei ungültigen IDs kann die bisherige Nummer direkt eingegeben werden.');
            $this->rccBindingTrace('SCAN_OK',['count'=>count($rows)]);
            $this->UpdateFormField('CatalogBindingRows','values',
                json_encode($visible,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $this->UpdateFormField('CatalogBindingNotice','caption',
                $this->ReadAttributeString('CatalogBindingNotice'));
        } catch (Throwable $e) {
            $this->WriteAttributeString('CatalogBindingNotice',$e->getMessage());
            $this->rccBindingTrace('SCAN_FAILED',['error'=>$e->getMessage()]);
        }
    }

    public function SelectCatalogVariableBinding(int $id): void
    {
        $this->WriteAttributeString('CatalogBindingSelected',(string)$id);
        $this->UpdateFormField('CatalogBindingOld','value',$id);
        $this->rccBindingTrace('SELECT',['oldId'=>$id]);
    }

    private static function rccBindingAdvice(array $old,array $new): array
    {
        $issues=[];
        if (!$new['exists'])$issues[]='Neue Variable existiert nicht.';
        if ($old['exists'] && $new['exists'] && $old['type']!==$new['type']) {
            $issues[]='Datentyp unterschiedlich: '.$old['type'].' → '.$new['type'].'. '
                .'Werteformat prüfen (Boolean 0/1, Integer/Float, Text-Konverter). '
                .'Bei unklaren Werten normalisierte Hilfsvariable verwenden.';
        }
        if ($old['exists'] && $new['exists'] && $old['profile']!==$new['profile']) {
            $issues[]='Profil unterschiedlich: Einheiten, Skalierung und Assoziationen prüfen '
                .'(z. B. °F/°C, kW/W, kPa/bar).';
        }
        return $issues;
    }

    public function PreviewCatalogVariableBinding(int $oldId,int $newId): void
    {
        $this->WriteAttributeString('CatalogBindingPreview','');
        try {
            if ($oldId<=0 || $newId<=0)throw new RuntimeException('Beide Variablen-IDs wählen.');
            [$script,$original,$catalog]=$this->systemTreeEditContext();
            $refs=array_values(array_filter($this->rccBindingRefs($catalog),
                static fn(array $ref):bool=>$ref['id']===$oldId));
            if ($refs===[])throw new RuntimeException('Die alte ID hat keine erkannten Referenzen.');
            $old=$this->rccBindingInfo($oldId);$new=$this->rccBindingInfo($newId);
            if (!$new['exists'])throw new RuntimeException('Neue Variable existiert nicht.');
            try {
                [$archiveId,$archiveInventory]=$this->systemTreeArchive();
                foreach ([$oldId,$newId] as $archiveVariableId) {
                    $entry=is_array($archiveInventory)
                        ?($archiveInventory[$archiveVariableId]??null):null;
                    $archiveText=$entry===null?'Keine Archivdaten / nicht gefunden'
                        :('Archiv #'.$archiveId.'; Logging '
                          .(!empty($entry['AggregationActive'])?'aktiv':'inaktiv')
                          .'; '.(int)($entry['RecordCount']??0).' Werte');
                    if ($archiveVariableId===$oldId)$old['archive']=$archiveText;
                    if ($archiveVariableId===$newId)$new['archive']=$archiveText;
                }
            } catch (Throwable $archiveError) {
                $old['archive']=$new['archive']='Archiv konnte nicht gelesen werden';
            }
            $issues=self::rccBindingAdvice($old,$new);
            $rows=array_map(static fn(array $ref):array=>[
                'path'=>self::rccPathText($ref['path']),'kind'=>$ref['kind'],
                'oldId'=>$oldId,'newId'=>$newId],$refs);
            $preview=['script'=>$script,'hash'=>hash('sha256',$original),
                'oldId'=>$oldId,'newId'=>$newId,'rows'=>$rows,'issues'=>$issues,'time'=>time()];
            $this->WriteAttributeString('CatalogBindingPreview',json_encode($preview,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $note=count($rows).' Fundstellen. Alt: Parent #'.$old['parent']
                .' / Typ '.$old['type'].' / '.$old['archive']
                .'; neu: Parent #'.$new['parent']
                .' / Typ '.$new['type'].' / '.$new['archive'].'. '
                .($issues===[]?'Technisch plausibel; echte Sensorwerte prüfen.'
                    :implode(' ',$issues).' Für diese Typ-/Profilabweichung ist vor dem Austausch '
                        .'eine passende Hilfsvariable bzw. gesonderte fachliche Bestätigung nötig.')
                .' Archivhistorie der bisherigen Variablen bleibt erhalten.';
            $this->WriteAttributeString('CatalogBindingNotice',$note);
            $this->UpdateFormField('CatalogBindingNotice','caption',$note);
            $this->UpdateFormField('CatalogBindingPreviewRows','values',
                json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $this->rccBindingTrace('PREVIEW_OK',['oldId'=>$oldId,'newId'=>$newId,
                'references'=>count($rows),'issues'=>count($issues)]);
        } catch (Throwable $e) {
            $this->rccBindingTrace('PREVIEW_FAILED',['error'=>$e->getMessage()]);
            $this->WriteAttributeString('CatalogBindingNotice',$e->getMessage());
            $this->UpdateFormField('CatalogBindingNotice','caption',$e->getMessage());
        }
    }

    private static function rccReplaceBindingPath(array &$tree,array $path,string $kind,int $oldId,int $newId): void
    {
        $node=&$tree;
        $last=array_pop($path);
        foreach ($path as $part) {
            if (!isset($node[$part]) || !is_array($node[$part]))
                throw new RuntimeException('Fundstelle wurde inzwischen verändert.');
            $node=&$node[$part];
        }
        if ($kind==='key') {
            if (!array_key_exists($last,$node) || array_key_exists($newId,$node))
                throw new RuntimeException('Zielschlüssel bereits vorhanden oder alter Schlüssel fehlt.');
            $node[$newId]=$node[$last];
            unset($node[$last]);
        } else {
            if (self::rccIsId($node[$last]??null)!==$oldId)
                throw new RuntimeException('Alter Variablenwert hat sich geändert.');
            $node[$last]=is_string($node[$last])?(string)$newId:$newId;
        }
    }

    public function ApplyCatalogVariableBinding(int $oldId,int $newId,bool $confirmed): void
    {
        try {
            if (!$confirmed)throw new RuntimeException('Bitte die betroffenen Zuordnungen bestätigen.');
            $preview=json_decode($this->ReadAttributeString('CatalogBindingPreview'),true);
            if (!is_array($preview) || (int)($preview['time']??0)<time()-600
                || $oldId!==(int)($preview['oldId']??0)
                || $newId!==(int)($preview['newId']??0))
                throw new RuntimeException('Bitte die Migration zuerst erneut prüfen.');
            [$script,$original,$catalog]=$this->systemTreeEditContext();
            if ($script!==(int)$preview['script'] ||
                !hash_equals((string)$preview['hash'],hash('sha256',$original)))
                throw new RuntimeException('EDIT wurde zwischenzeitlich geändert.');
            $old=$this->rccBindingInfo($oldId);$new=$this->rccBindingInfo($newId);
            if (!$new['exists'])throw new RuntimeException('Die Ersatzvariable existiert nicht mehr.');
            $issues=self::rccBindingAdvice($old,$new);
            if ($issues!==[])throw new RuntimeException('Typ/Profil passt nicht: '
                .implode(' ',$issues).' Bitte geeignete kompatible Hilfsvariable wählen.');
            if ($oldId===$newId) {
                // Recheck metadata, archive availability and references without
                // writing the exact same catalog content.
                $this->rccBindingTrace('SAME_ID_REFRESH',['id'=>$oldId,
                    'parent'=>$new['parent'],'count'=>count($preview['rows'])]);
                $this->WriteAttributeString('CatalogBindingNotice',
                    'Gleiche Variable erfolgreich geprüft. Parent #'.$new['parent']
                    .'. Keine unnötige EDIT-/PROD-Änderung.');
                return;
            }
            // Values before keys: references nested below a numeric
            // point key must be updated before that point key is moved.
            $rows=(array)$preview['rows'];
            $refs=array_values(array_filter($this->rccBindingRefs($catalog),
                static fn(array $ref):bool=>$ref['id']===$oldId));
            usort($refs,static fn(array $a,array $b):int=>
                ($a['kind']==='key'?1:0)<=>($b['kind']==='key'?1:0));
            if (count($refs)!==count($rows))
                throw new RuntimeException('Fundstellen haben sich verändert.');
            foreach ($refs as $ref)
                self::rccReplaceBindingPath($catalog,$ref['path'],$ref['kind'],$oldId,$newId);
            // Technical point metadata must agree with the selected new sensor.
            $walk=static function (array &$node) use (&$walk,$newId,$new): void {
                foreach ($node as $k=>&$v) {
                    if (is_array($v)) {
                        if ($k==='points' && isset($v[$newId]) && is_array($v[$newId])) {
                            $v[$newId]['id']=$newId;
                            $v[$newId]['parent']=$new['parent'];
                            $v[$newId]['type']=$new['type'];
                            $v[$newId]['profile']=$new['profile'];
                            $v[$newId]['manualEdit']=true;
                            $v[$newId]['manualSource']=true;
                        }
                        $walk($v);
                    }
                }
                unset($v);
            };
            $walk($catalog);
            // A replaced physical sensor should not be re-imported at the
            // next irrigation object-tree scan.
            foreach ((array)($catalog['dataSources']??[]) as &$source) {
                if (!is_array($source)
                    || ($source['provider']??'')!=='ObjectTreeIrrigation')continue;
                if (isset($source['points'][$newId]) && !isset($source['points'][$oldId])) {
                    $source['excludedPoints'][$oldId]=(string)
                        ($source['points'][$newId]['label']??('Replaced '.$oldId));
                    // A replacement actuator never inherits permission from
                    // the old physical hardware without re-approval.
                    $source['voiceActions']=array_values(array_diff(
                        (array)($source['voiceActions']??[]),[$newId]));
                    $source['points'][$newId]['actionCandidate']=false;
                }
            }
            unset($source);
            $this->saveSystemTreeEdit($script,$original,$catalog);
            $this->WriteAttributeString('CatalogBindingPreview','');
            $this->WriteAttributeString('CatalogBindingInventory','[]');
            $this->WriteAttributeString('CatalogBindingNotice',
                count($refs).' Verweise in EDIT ersetzt. PROD unverändert. '
                .'Archivhistorie und PHP-Skripte außerhalb RoomsCatalog bleiben unverändert.');
            $this->rccBindingTrace('EDIT_SAVED',['oldId'=>$oldId,'newId'=>$newId,'references'=>count($refs)]);
        } catch (Throwable $e) {
            $this->rccBindingTrace('SAVE_FAILED',['oldId'=>$oldId,'newId'=>$newId,
                'error'=>$e->getMessage()]);
            $this->WriteAttributeString('CatalogBindingNotice',$e->getMessage());
        }
        $this->UpdateFormField('CatalogBindingNotice','caption',
            $this->ReadAttributeString('CatalogBindingNotice'));
    }

    private function rccBindingPanel(): array
    {
        $rows=json_decode($this->ReadAttributeString('CatalogBindingInventory'),true);
        $preview=json_decode($this->ReadAttributeString('CatalogBindingPreview'),true);
        return ['type'=>'ExpansionPanel','caption'=>'Alle Variablen im RoomsCatalog zentral verwalten',
            'expanded'=>false,'items'=>[
                ['type'=>'Label','caption'=>'Variablenzuordnungen und Fundstellen werden erst '
                    .'auf Knopfdruck ermittelt, damit das Hauptformular schneller bleibt. '
                    .'Auch ungültige IDs aus EDIT sind sichtbar. Nur nach EDIT speichern.'],
                ['type'=>'ValidationTextBox','name'=>'CatalogBindingFilter',
                    'caption'=>'ID, Name oder Katalogpfad suchen (optional)'],
                ['type'=>'Button','caption'=>'VARIABLEN IM EDIT-KATALOG SUCHEN',
                    'onClick'=>'RCC_ScanCatalogVariableBindings($id,(string)$CatalogBindingFilter);'],
                ['type'=>'List','name'=>'CatalogBindingRows','caption'=>'Variablenreferenzen',
                    'rowCount'=>10,'loadValuesFromConfiguration'=>false,
                    'add'=>false,'delete'=>false,
                    'values'=>is_array($rows)?array_slice($rows,0,200):[],
                    'columns'=>[
                        ['name'=>'variableId','caption'=>'ID','width'=>'80px','save'=>false,
                            'onClick'=>'RCC_SelectCatalogVariableBinding($id,(int)($CatalogBindingRows["variableId"] ?? 0));'],
                        ['name'=>'name','caption'=>'Variable','width'=>'160px','save'=>false],
                        ['name'=>'parent','caption'=>'Parent','width'=>'70px','save'=>false],
                        ['name'=>'type','caption'=>'Typ','width'=>'65px','save'=>false],
                        ['name'=>'profile','caption'=>'Profil','width'=>'130px','save'=>false],
                        ['name'=>'references','caption'=>'Verweise','width'=>'75px','save'=>false],
                        ['name'=>'paths','caption'=>'Fundstellen (erste 3)','width'=>'auto','save'=>false],
                        ['name'=>'status','caption'=>'Status','width'=>'85px','save'=>false]
                    ]],
                ['type'=>'NumberSpinner','name'=>'CatalogBindingOld',
                    'caption'=>'Alte Variablen-ID (auch wenn nicht mehr vorhanden)',
                    'minimum'=>0,'maximum'=>2147483647,
                    'value'=>(int)$this->ReadAttributeString('CatalogBindingSelected')],
                ['type'=>'SelectVariable','name'=>'CatalogBindingNew',
                    'caption'=>'Neue oder gleiche Variable auswählen',
                    'validVariableTypes'=>[0,1,2,3]],
                ['type'=>'CheckBox','name'=>'CatalogBindingConfirmed',
                    'caption'=>'Ich habe alle Fundstellen und die Ersatzvariable geprüft'],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'Button','caption'=>'1. MIGRATION PRÜFEN',
                        'onClick'=>'RCC_PreviewCatalogVariableBinding($id,(int)$CatalogBindingOld,(int)$CatalogBindingNew);'],
                    ['type'=>'Button','caption'=>'2. IN EDIT ÜBERNEHMEN',
                        'confirm'=>'Die geprüften Referenzen im RoomsCatalog EDIT ersetzen? '
                            .'PROD und Archivhistorie bleiben unverändert.',
                        'onClick'=>'RCC_ApplyCatalogVariableBinding($id,(int)$CatalogBindingOld,(int)$CatalogBindingNew,(bool)$CatalogBindingConfirmed);']
                ]],
                ['type'=>'Label','name'=>'CatalogBindingNotice',
                    'caption'=>$this->ReadAttributeString('CatalogBindingNotice')],
                ['type'=>'List','name'=>'CatalogBindingPreviewRows','caption'=>'Vorschau der Fundstellen',
                    'rowCount'=>10,'loadValuesFromConfiguration'=>false,
                    'add'=>false,'delete'=>false,
                    'values'=>is_array($preview)?($preview['rows']??[]):[],
                    'columns'=>[
                        ['name'=>'path','caption'=>'Konfigurationspfad','width'=>'auto','save'=>false],
                        ['name'=>'kind','caption'=>'Art','width'=>'80px','save'=>false],
                        ['name'=>'oldId','caption'=>'Alt','width'=>'80px','save'=>false],
                        ['name'=>'newId','caption'=>'Neu','width'=>'80px','save'=>false]
                    ]],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'Button','caption'=>'DIAGNOSE AKTUALISIEREN',
                        'onClick'=>'RCC_RefreshCatalogBindingLog($id);'],
                    ['type'=>'Button','caption'=>'DIAGNOSE LEEREN',
                        'onClick'=>'RCC_ClearCatalogBindingLog($id);']
                ]],
                ['type'=>'ValidationTextBox','name'=>'CatalogBindingLogText',
                    'caption'=>'Diagnose JSON','multiline'=>true,
                    'value'=>$this->ReadAttributeString('CatalogBindingLog')]
            ]];
    }

    public function RefreshCatalogBindingLog(): void
    {
        $this->UpdateFormField('CatalogBindingLogText','value',
            $this->ReadAttributeString('CatalogBindingLog'));
    }

    public function ClearCatalogBindingLog(): void
    {
        $this->WriteAttributeString('CatalogBindingLog','[]');
        $this->RefreshCatalogBindingLog();
    }
}
