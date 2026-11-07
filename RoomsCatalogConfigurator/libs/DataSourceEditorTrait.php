<?php
declare(strict_types=1);

/**
 * V3.6.1: Huawei source editor in the regular RoomsCatalog device table.
 *
 * Source rows deliberately remain virtual. They are NOT returned by
 * getRuntimeEntries(), so existing bulk device actions cannot accidentally
 * delete/duplicate/convert a Huawei module source.
 */
trait RCCDataSourceEditorTrait
{
    private function huaweiEditorArchiveId(): int
    {
        $ids=[];
        $explicit=$this->ReadPropertyInteger('DiscoveryAlexaInstanceId');
        if($explicit>0)$ids[]=$explicit;
        if(function_exists('IPS_GetInstanceListByModuleID')) {
            try {
                $auto=IPS_GetInstanceListByModuleID(
                    '{F528D4D0-5729-4315-AE88-9BBABDBD0392}');
                if(is_array($auto)) {
                    sort($auto,SORT_NUMERIC);
                    foreach($auto as $id)$ids[]=(int)$id;
                }
            }catch(Throwable $ignored){}
        }
        foreach(array_unique($ids) as $instanceId) {
            if($instanceId<=0 || !function_exists('IPS_InstanceExists')
                || !IPS_InstanceExists($instanceId)
                || !function_exists('IPS_GetProperty'))continue;
            try {
                $archive=(int)IPS_GetProperty($instanceId,'KIArchiveControlId');
                if(RCCDataSourceEditor::archiveInventory($archive)!==null)return $archive;
            }catch(Throwable $ignored){}
        }
        return 0;
    }

    private function huaweiEditorSource(string $key): array
    {
        $catalog=$this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT');
        $source=$catalog['dataSources'][$key]??null;
        if(!is_array($source)
            || ($source['provider']??'')!=='HuaweiPV'
            || $key!==('huawei_'.(int)($source['sourceInstanceId']??0)))
            throw new RuntimeException('HuaweiPV-Datenquelle nicht eindeutig in EDIT gefunden.');
        return $source;
    }

    private function huaweiEditorMetricRows(string $key,string $topic): array
    {
        return RCCDataSourceEditor::metricRows(
            $this->huaweiEditorSource($key),$topic,
            RCCDataSourceEditor::archiveInventory($this->huaweiEditorArchiveId()));
    }

    private function huaweiEditorMetricList(string $key,array $source,string $topic,?array $inventory): array
    {
        $name='HuaweiMetrics_'.$topic;
        $label=RCCDataSourceEditor::topics()[$topic]??$topic;
        $rows=RCCDataSourceEditor::metricRows($source,$topic,$inventory);
        $qkey=var_export($key,true);
        $qtopic=var_export($topic,true);
        return ['type'=>'ExpansionPanel',
            'caption'=>$label.' ('.count($rows).' Variablen)',
            'expanded'=>$topic==='pv','items'=>[
                ['type'=>'Label','caption'=>'Messwerte ueber das Zahnrad in '
                    .'dieser Tabelle einzeln freigeben oder sperren. '
                    .'Schaltbefehle bleiben immer gesperrt.'],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'Button','caption'=>'ALLE DER GRUPPE FUER KI FREIGEBEN',
                        'confirm'=>'Alle Messwerte der Gruppe in EDIT nur fuer KI-Lesezugriff freigeben?',
                        'onClick'=>'RCC_SetHuaweiMetricGroup($id, '.$qkey.','.$qtopic.',true);'],
                    ['type'=>'Button','caption'=>'ALLE DER GRUPPE SPERREN',
                        'confirm'=>'Alle Messwerte der Gruppe in EDIT fuer die KI sperren?',
                        'onClick'=>'RCC_SetHuaweiMetricGroup($id, '.$qkey.','.$qtopic.',false);']
                ]],
                ['type'=>'List','name'=>$name,
                    'caption'=>$label,'rowCount'=>min(14,max(3,count($rows))),
                    'loadValuesFromConfiguration'=>false,'add'=>false,'delete'=>false,
                    'rowColor'=>'rowColor',
                    'columns'=>[
                        ['caption'=>'Huawei-Ident','name'=>'ident','width'=>'145px',
                            'save'=>false,'quickFilter'=>true],
                        ['caption'=>'Name','name'=>'title','width'=>'180px',
                            'save'=>false,'quickFilter'=>true],
                        ['caption'=>'IPS-ID','name'=>'id','width'=>'70px','save'=>false],
                        ['caption'=>'Aktueller Wert','name'=>'value','width'=>'125px','save'=>false],
                        ['caption'=>'Einheit','name'=>'unit','width'=>'60px','save'=>false],
                        ['caption'=>'Mit KI','name'=>'enabled','width'=>'90px',
                            'edit'=>['type'=>'CheckBox']],
                        ['caption'=>'Archiv','name'=>'archive','width'=>'120px','save'=>false],
                        ['caption'=>'Status','name'=>'status','width'=>'135px','save'=>false],
                        ['caption'=>'Letztes Update','name'=>'updated','width'=>'150px','save'=>false]
                    ],
                    'values'=>$rows,
                    'onEdit'=>'RCC_SetHuaweiMetricEnabled($id, '.$qkey.', '
                        .'(string)($'.$name.'["ident"] ?? ""), '
                        .'(bool)($'.$name.'["enabled"] ?? false));'
                ]
            ]
        ];
    }

    /** Form returned to the gear icon on ONE source row of Entries. */
    public function GetHuaweiDataSourceEditForm(string $key): string
    {
        try {
            $source=$this->huaweiEditorSource($key);
            $sourceId=(int)$source['sourceInstanceId'];
            $archiveId=$this->huaweiEditorArchiveId();
            $inventory=RCCDataSourceEditor::archiveInventory($archiveId);
            $totals=RCCDataSourceEditor::totals($source,$archiveId);
            $items=[
                ['type'=>'Label','caption'=>'Energiesystem: HuaweiPV-Instanz #'.$sourceId.
                    ', Katalogpfad dataSources.'.$key.'. '
                    .'Die IDs werden aus dem Huawei-Objektbaum gelesen. '
                    .'Der normale Geraete-Sammelassistent aendert diese Quelle nicht.'],
                ['type'=>'ValidationTextBox','name'=>'title',
                    'caption'=>'Name der gesamten PV-Anlage',
                    'value'=>(string)($source['title']??'HuaweiPV')],
                ['type'=>'CheckBox','name'=>'kiEnabled',
                    'caption'=>'Ganze Anlage fuer KI-Leseabfragen freigeben',
                    'value'=>!empty($source['kiEnabled'])],
                ['type'=>'Label','caption'=>'Nur KI-Lesezugriff. '
                    .'Akkusteuerung, Zwangsladung, Wechselrichter und '
                    .'Einspeisebegrenzung werden NIEMALS durch die KI geschaltet. '
                    .'Name und KI-Gesamtfreigabe werden beim Speichern des '
                    .'Zahnrad-Dialogs uebernommen; Metriken und Gruppen '
                    .'werden mit den jeweiligen Aktionen nach EDIT gespeichert.'],
                ['type'=>'Label','caption'=>sprintf(
                    'Messwerte: %d gesamt, %d KI-lesen, %d ungueltig. '
                    .'Archive Control: %s, archivierte Variablen: %d/%d.',
                    $totals['total'],$totals['enabled'],$totals['invalid'],
                    $totals['archiveConfigured']?'#'.$archiveId:'nicht konfiguriert',
                    $totals['archived'],$totals['total'])],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'Button','caption'=>'1 – QUELLINSTANZ ERNEUT SCANNEN',
                        'onClick'=>'RCC_PreviewHuaweiDataSource($id, '.$sourceId.');'],
                    ['type'=>'Button','caption'=>'2 – SCAN NACH EDIT SYNCHRONISIEREN',
                        'confirm'=>'Huawei-Scan nach EDIT aktualisieren? '
                            .'Ein Backup wird angelegt. PROD bleibt unveraendert.',
                        'onClick'=>'RCC_ImportHuaweiDataSourceToEdit($id, '.$sourceId.');']
                ]],
                ['type'=>'Label','caption'=>'Die Scan-Vorschau und das '
                    .'Ergebnis stehen auch im Bereich ENERGIE-DATENQUELLEN. '
                    .'Nach Aenderungen die Zahnradansicht erneut oeffnen, '
                    .'um Werte und Archivstatus zu aktualisieren.']
            ];
            $topics=[];
            foreach((array)($source['metrics']??[]) as $metric)
                if(is_array($metric) && isset($metric['topic']))
                    $topics[(string)$metric['topic']]=true;
            foreach(RCCDataSourceEditor::topics() as $topic=>$caption)
                if(isset($topics[$topic]))
                    $items[]=$this->huaweiEditorMetricList($key,$source,$topic,$inventory);
            return json_encode($items,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        } catch(Throwable $error) {
            return json_encode([['type'=>'Label',
                'caption'=>'HuaweiPV-Editor nicht verfuegbar: '.$error->getMessage()]],
                JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }

    /**
     * Atomic, EDIT-only modification of exactly one dataSource. Backup and
     * readback before accepting a changed script. Never accept caller-supplied
     * raw variable IDs or extra PHP code.
     */
    private function mutateHuaweiDataSource(string $key,callable $change): string
    {
        $editId=$this->ReadPropertyInteger('RoomsCatalogEditScriptID');
        $prodId=$this->ReadPropertyInteger('RoomsCatalogScriptID');
        if($editId<=0 || $editId===$prodId || !IPS_ScriptExists($editId))
            throw new RuntimeException('EDIT/PROD-Skripte fehlen oder sind identisch.');
        $original=(string)IPS_GetScriptContent($editId);
        if(!RCCDeviceTechnicalAudit::isLiteralArraySource($original))
            throw new RuntimeException('Nur ein statischer PHP-Array-Katalog ist zulaessig.');
        $catalog=$this->loadRoomsCatalog($editId,'EDIT');
        $source=$catalog['dataSources'][$key]??null;
        if(!is_array($source) || ($source['provider']??'')!=='HuaweiPV'
            || $key!==('huawei_'.(int)($source['sourceInstanceId']??0)))
            throw new RuntimeException('HuaweiPV-Datenquelle nicht in EDIT gefunden.');
        $change($source);
        // Never accept legacy write flags from a stale/corrupt catalog.
        $source['kiReadOnly']=true;$source['readOnly']=true;
        $source['allowWrites']=false;
        foreach($source['metrics'] as &$metric)
            if(is_array($metric))$metric['readOnly']=true;
        unset($metric);
        $catalog['dataSources'][$key]=$source;
        $next="<?php\nreturn ".var_export($catalog,true).";\n";
        if(IPS_GetScriptContent($editId)!==$original)
            throw new RuntimeException('EDIT hat sich inzwischen geaendert.');
        if($next===$original)return 'Keine Aenderung erforderlich.';
        $backup=$this->catalogBackupCurrentEdit($editId,$original,'before_huawei_editor_v361');
        try {
            IPS_SetScriptContent($editId,$next);
            if(IPS_GetScriptContent($editId)!==$next)
                throw new RuntimeException('EDIT-Schreibverifikation fehlgeschlagen.');
        }catch(Throwable $e) {
            IPS_SetScriptContent($editId,$original);
            if(IPS_GetScriptContent($editId)!==$original)
                throw new RuntimeException('Ruecknahme nicht verifiziert! Backup #'.$backup.' pruefen.');
            throw $e;
        }
        $this->reloadAllFromCatalog();
        $this->RefreshVisibleLists();
        return 'Nur EDIT gespeichert. Backup #'.$backup.'. PROD unveraendert.';
    }

    public function SaveHuaweiDataSourceOverview(string $key,string $title,bool $kiEnabled): void
    {
        try {
            $title=trim($title);
            if($title==='' || mb_strlen($title,'UTF-8')>100)
                throw new RuntimeException('Geraetename muss 1–100 Zeichen enthalten.');
            $msg=$this->mutateHuaweiDataSource($key,
                static function(array &$source)use($title,$kiEnabled):void {
                    $source['title']=$title;$source['kiEnabled']=$kiEnabled;
                });
        } catch(Throwable $error) {
            $msg='HUAWEIPV NICHT GESPEICHERT: '.$error->getMessage();
        }
        $this->WriteAttributeString('HuaweiDataSourceNotice',$msg);
        $this->UpdateFormField('HuaweiDataSourceNotice','caption',$msg);
        echo $msg;
    }

    public function SetHuaweiMetricEnabled(string $key,string $ident,bool $enabled): void
    {
        try {
            if($ident==='' || !preg_match('/^[A-Za-z][A-Za-z0-9]{1,60}$/D',$ident))
                throw new RuntimeException('Unbekannter Huawei-Variablen-Ident.');
            $msg=$this->mutateHuaweiDataSource($key,
                static function(array &$source)use($ident,$enabled):void {
                    if(!isset($source['metrics'][$ident])
                        || !is_array($source['metrics'][$ident]))
                        throw new RuntimeException('Messwert nicht in EDIT gefunden.');
                    $source['metrics'][$ident]['enabled']=$enabled;
                });
            $topic=$this->huaweiEditorSource($key)['metrics'][$ident]['topic']??'';
            if(is_string($topic)&&$topic!=='')
                $this->UpdateFormField('HuaweiMetrics_'.$topic,'values',
                    json_encode($this->huaweiEditorMetricRows($key,$topic),
                        JSON_INVALID_UTF8_SUBSTITUTE));
            $msg='Huawei-Messwert '.$ident.' '.($enabled?'freigegeben':'gesperrt').
                '. '.$msg;
        }catch(Throwable $error){
            $msg='HUAWEI-MESSWERT NICHT GESPEICHERT: '.$error->getMessage();
        }
        $this->WriteAttributeString('HuaweiDataSourceNotice',$msg);
        echo $msg;
    }

    public function SetHuaweiMetricGroup(string $key,string $topic,bool $enabled): void
    {
        try {
            if(!array_key_exists($topic,RCCDataSourceEditor::topics()))
                throw new RuntimeException('Unbekannte Huawei-Gruppe.');
            $count=0;
            $msg=$this->mutateHuaweiDataSource($key,
                static function(array &$source)use($topic,$enabled,&$count):void {
                    foreach($source['metrics'] as &$metric) {
                        if(!is_array($metric)||($metric['topic']??'')!==$topic)continue;
                        if((bool)($metric['enabled']??false)!==$enabled){
                            $metric['enabled']=$enabled;$count++;
                        }
                    }
                    unset($metric);
                });
            $this->UpdateFormField('HuaweiMetrics_'.$topic,'values',
                json_encode($this->huaweiEditorMetricRows($key,$topic),
                    JSON_INVALID_UTF8_SUBSTITUTE));
            $msg=$count.' Messwerte in '.$topic.' '.($enabled?'freigegeben':'gesperrt')
                .'. '.$msg;
        }catch(Throwable $error) {
            $msg='HUAWEI-GRUPPE NICHT GESPEICHERT: '.$error->getMessage();
        }
        $this->WriteAttributeString('HuaweiDataSourceNotice',$msg);
        echo $msg;
    }
}
