<?php
declare(strict_types=1);

/**
 * Read-only technical inspection of RoomsCatalog EDIT selection.
 * Runs only when explicitly requested by the user in the popup.
 */
trait RCCMarkedDeviceInspectionTrait
{
    private function rccMarkedInspectionForm(): array
    {
        $result=json_decode($this->ReadAttributeString('MarkedInspectionResult'),true);
        $rows=is_array($result['rows']??null)?$result['rows']:[];
        return ['type'=>'PopupButton','caption'=>'MARKIERTE PRÜFEN',
            'name'=>'MarkedInspectionPopup',
            'popup'=>[
                'caption'=>'Markierte Geräte und Variablen prüfen',
                'closeCaption'=>'Schließen',
                'items'=>[
                    ['type'=>'Label','caption'=>'Lesende Prüfung der im RoomsCatalog EDIT markierten Geräte. '
                        .'Keine Aktionen am Gerät, keine EDIT-/PROD-Änderungen. '
                        .'Archivstatus wird nur bei ausdrücklicher Tiefenprüfung abgefragt.'],
                    ['type'=>'CheckBox','name'=>'MarkedInspectionDeep',
                        'caption'=>'Archivwerte und Aktualität mitprüfen (langsamer)','value'=>false],
                    ['type'=>'CheckBox','name'=>'MarkedInspectionHealth',
                        'caption'=>'Erweiterter Sensor-Gesundheitscheck (Messwerte, Batterie, Trend)',
                        'value'=>false],
                    ['type'=>'Button','caption'=>'JETZT PRÜFEN',
                        'onClick'=>'RCC_RunMarkedDeviceInspection($id,(bool)$MarkedInspectionDeep,'
                            .'(bool)$MarkedInspectionHealth);'],
                    ['type'=>'Label','name'=>'MarkedInspectionSummary',
                        'caption'=>(string)($result['summary']??'Noch keine Prüfung ausgeführt.')],
                    ['type'=>'List','name'=>'MarkedInspectionFindings',
                        'caption'=>'Auffälligkeiten und Empfehlungen',
                        'rowCount'=>12,'loadValuesFromConfiguration'=>false,
                        'add'=>false,'delete'=>false,'values'=>$rows,
                        'columns'=>[
                            ['name'=>'severity','caption'=>'Status','width'=>'90px','save'=>false],
                            ['name'=>'device','caption'=>'Gerät','width'=>'165px','save'=>false],
                            ['name'=>'field','caption'=>'Variable / Feld','width'=>'140px','save'=>false],
                            ['name'=>'variableId','caption'=>'ID','width'=>'75px','save'=>false],
                            ['name'=>'finding','caption'=>'Ergebnis / Empfehlung','width'=>'auto','save'=>false]
                        ]],
                    ['type'=>'Label','caption'=>'Reparatur: Die betroffene ID unter '
                        .'„Alle Variablen im RoomsCatalog zentral verwalten“ auswählen, '
                        .'Fundstellen prüfen und eine neue oder dieselbe Variable '
                        .'nach EDIT übernehmen.'],
                    ['type'=>'RowLayout','items'=>[
                        ['type'=>'Button','caption'=>'BERICHT AKTUALISIEREN',
                            'onClick'=>'RCC_RefreshMarkedDeviceInspection($id);']
                    ]],
                    ['type'=>'ValidationTextBox','name'=>'MarkedInspectionJson',
                        'caption'=>'Bericht als JSON kopieren',
                        'multiline'=>true,'value'=>$this->ReadAttributeString('MarkedInspectionResult')]
                ]
            ]
        ];
    }

    /** Only scan IDs which are actual bindings; not arbitrary object IDs. */
    private function rccInspectionVariableFields(array $row): array
    {
        $found=[];
        $walk=static function(array $part,array $path=[]) use (&$walk,&$found): void {
            foreach($part as $key=>$value) {
                $field=(string)$key;
                if ($field==='selected'||$field==='roomKey'||$field==='roomLabel'
                    ||$field==='key'||$field==='diffKeys')continue;
                $next=[...$path,$key];
                if (is_array($value)) {
                    $walk($value,$next);
                    continue;
                }
                if (is_string($value) && ($value[0]??'')==='{') {
                    $decoded=json_decode($value,true);
                    if(is_array($decoded)){$walk($decoded,$next);continue;}
                }
                if ((!is_int($value) && !is_string($value))
                    || !preg_match('/^[1-9][0-9]{1,9}$/D',(string)$value))continue;
                $id=(int)$value;
                if ($id<1000 || $id>2147483647)continue;
                if (!self::rccVariableKey($field,array_map('strval',$path)))continue;
                $found[implode('.',array_map('strval',$next))]=$id;
            }
        };
        $walk($row);
        return $found;
    }

    /** Include all physical points of a virtual datasource selection. */
    private function rccInspectionSourceVariables(array $source): array
    {
        $refs=[];
        foreach ((array)($source['points']??[]) as $key=>$point) {
            if (!is_array($point))continue;
            $id=(int)($point['id']??$key);
            if ($id>0)$refs['point.'.(string)$key]=$id;
        }
        foreach ((array)($source['metrics']??[]) as $key=>$metric) {
            if (!is_array($metric))continue;
            foreach (['variableId','valueId','id'] as $field) {
                $id=(int)($metric[$field]??0);
                if ($id>0) $refs['metric.'.(string)$key.'.'.$field]=$id;
            }
        }
        // Include additional variable references, but NEVER interpret a
        // script, instance or parent object ID as a variable.
        foreach ($this->rccBindingRefs(['dataSources'=>['current'=>$source]]) as $ref) {
            $id=(int)$ref['id'];
            if ($id<=0)continue;
            $parts=array_map('strval',(array)$ref['path']);
            if (array_intersect(['scripts','instances','excludedPoints'],$parts))continue;
            $last=strtolower((string)end($parts));
            if (in_array($last,['scriptid','instanceid','parentid','wfcid'],true))continue;
            $path=implode('.',$parts);
            $refs[$path]=$id;
        }
        return $refs;
    }

    /** Check scripts against the correct Symcon object type, once each. */
    private function rccInspectionSourceScripts(array $source): array
    {
        $scripts=[];
        foreach ((array)($source['scripts']??[]) as $key=>$entry) {
            $id=is_array($entry)?(int)($entry['id']??$key):(int)$entry;
            if ($id>0)$scripts[$id]=true;
        }
        return array_keys($scripts);
    }

    /** Historic data is required only when explicitly configured for a point. */
    private function rccInspectionArchivePolicy(array $source,int $id): array
    {
        $point=$source['points'][$id]??null;
        if (!is_array($point))return ['required'=>false,'maxAge'=>0];
        $mode=strtolower((string)($point['archiveMode']??''));
        $required=!empty($point['archiveRequired'])
            || in_array($mode,['required','history','heaterpower','manual'],true);
        $maxAge=(int)($point['maxAgeSeconds']??$point['expectedIntervalSeconds']??0);
        return ['required'=>$required,'maxAge'=>max(0,$maxAge)];
    }

    public function RunMarkedDeviceInspection(bool $deep=false,bool $health=false): void
    {
        $started=microtime(true);
        try {
            $this->ensureRuntimeCatalogCurrent();
            $selected=$this->bulkSelectionKeys();
            $entries=[];
            $sourceSelection=json_decode($this->ReadAttributeString('OverviewSourceSelections'),true);
            if (!is_array($sourceSelection))$sourceSelection=[];
            if ($sourceSelection!==[]) {
                $catalog=$this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT');
                foreach ($this->overviewSourceRows($catalog) as $row) {
                    $key=$this->buildCompositeKey($row);
                    if ($key===null || empty($sourceSelection[$key]))continue;
                    $sourceKey=(string)($row['sourceKey']??$row['key']??'');
                    $source=$catalog['dataSources'][$sourceKey]??null;
                    if (!is_array($source))continue;
                    $row['inspectionSource']=$source;
                    $entries[]=$row;
                }
            }
            foreach ($this->getRuntimeEntries() as $row) {
                if (!is_array($row))continue;
                $key=$this->buildCompositeKey($row);
                if ($key!==null && in_array($key,$selected,true))$entries[]=$row;
            }
            if ($entries===[])throw new RuntimeException(
                'Keine Geräte markiert. Bitte zuerst Häkchen in der Geräteliste setzen.');
            // Virtual data-source selections are stored separately from
            // RuntimeEntries. Do not reject them due to an empty bulk selection.
            if (function_exists('IPS_LogMessage'))IPS_LogMessage('RoomsCatalog Prüfung',
                'Auswahl erkannt: '.count($selected).' reguläre Geräte, '
                .count($sourceSelection).' Datenquellen-Markierungen, '
                .count($entries).' prüfbare Einträge.');
            $results=[];$checked=0;$ok=0;$issues=0;$scriptCount=0;
            $referenceCount=0;$severityCounts=['FEHLER'=>0,'Prüfen'=>0,'Hinweis'=>0];
            $healthChecked=0;$healthArchiveBudget=30;
            $archiveId=0;$archiveMap=[];
            if (($deep||$health) && function_exists('AC_GetAggregationVariables')) {
                try {
                    [$archiveId,$archiveMap]=$this->systemTreeArchive();
                } catch (Throwable $error) {
                    // Archive availability is not a failure of any sensor.
                    $results[]=['severity'=>'Hinweis','device'=>'Archiv',
                        'field'=>'Archive Control','variableId'=>0,
                        'finding'=>'Archiv nicht lesbar: '.$error->getMessage()];
                    $severityCounts['Hinweis']++;
                }
            }
            foreach ($entries as $row) {
                $device=(string)($row['title']??$row['key']??'Gerät');
                $source=is_array($row['inspectionSource']??null)
                    ?$row['inspectionSource']:null;
                $ids=$source!==null
                    ?$this->rccInspectionSourceVariables($source)
                    :$this->rccInspectionVariableFields($row);
                $grouped=[];
                foreach ($ids as $field=>$id) {
                    $id=(int)$id;
                    if ($id<=0)continue;
                    $referenceCount++;
                    $grouped[$id][]=(string)$field;
                }
                if ($grouped===[] && $source===null) {
                    $results[]=['severity'=>'Hinweis','device'=>$device,'field'=>'Zuordnung',
                        'variableId'=>0,'finding'=>'Keine typisierte Variablen-ID gefunden. '
                            .'Katalogzuordnung bei Bedarf manuell prüfen.'];
                    $severityCounts['Hinweis']++;
                }
                foreach ($grouped as $id=>$paths) {
                    $checked++;
                    $status='OK';$message='';
                    if (!function_exists('IPS_VariableExists')||!IPS_VariableExists((int)$id)) {
                        $status='FEHLER';$message='Variable existiert nicht. '
                            .'Im zentralen Variableneditor eine Ersatz-ID auswählen.';
                    } else {
                        try {
                            $obj=IPS_GetObject((int)$id);
                            $var=IPS_GetVariable((int)$id);
                            $type=(int)($var['VariableType']??-1);
                            $parent=(int)($obj['ParentID']??0);
                            if ($parent<=0 || !IPS_ObjectExists($parent)) {
                                $status='Prüfen';
                                $message='Parent-Objekt fehlt oder ist ungültig. '
                                    .'Variable und Zuordnung erneut einlesen.';
                            }
                            $profile=(string)(($var['VariableCustomProfile']??'')
                                ?:($var['VariableProfile']??''));
                            if ($profile!=='' && function_exists('IPS_VariableProfileExists')
                                && !IPS_VariableProfileExists($profile)) {
                                $status='Prüfen';$message='Variablenprofil '.$profile
                                    .' fehlt. Profil oder Datenformat prüfen.';
                            }
                            $fieldText=implode(' ',array_slice($paths,0,4));
                            if ($type===3 && preg_match(
                                '/(?:temperature|humidity|power|brightness|position|ist|soll)/i',
                                $fieldText)) {
                                $status='Prüfen';$message='String statt numerischem Messwert. '
                                    .'Format prüfen und ggf. eine Float-Hilfsvariable verwenden.';
                            }
                            if ($health) {
                                $point=is_array($source['points'][$id]??null)
                                    ?$source['points'][$id]:[];
                                $healthChecked++;
                                $observations=$this->rccInspectSensorHealth(
                                    (int)$id,$var,$point,$fieldText,
                                    $archiveId,$archiveMap,time(),$healthArchiveBudget
                                );
                                $priority=['OK'=>0,'Hinweis'=>1,'Prüfen'=>2,'FEHLER'=>3];
                                foreach ($observations as $observation) {
                                    $candidate=(string)($observation['severity']??'Hinweis');
                                    if (($priority[$candidate]??0)>($priority[$status]??0))
                                        $status=$candidate;
                                    $message.=($message!==''?' | ':'')
                                        .(string)($observation['message']??'');
                                }
                            }
                            // Never assume that every actuator, status or seasonal
                            // sensor requires archive data. Check only explicit
                            // historical requirements.
                            $policy=$source!==null
                                ?$this->rccInspectionArchivePolicy($source,(int)$id)
                                :['required'=>false,'maxAge'=>0];
                            if ($deep && $archiveId>0 && $policy['required']
                                && $status==='OK') {
                                if (!isset($archiveMap[$id])) {
                                    $status='Prüfen';$message='Archiv für diesen Messwert '
                                        .'explizit vorgesehen, aber nicht gefunden.';
                                } elseif (function_exists('AC_GetLoggedValues')) {
                                    try {
                                        $ageLimit=(int)$policy['maxAge'];
                                        $window=max(30*86400,$ageLimit*2);
                                        $latest=AC_GetLoggedValues($archiveId,(int)$id,
                                            time()-$window,time(),1);
                                        if (!is_array($latest) || $latest===[]) {
                                            $status='Hinweis';
                                            $message='Kein Archivwert im geprüften Zeitraum. '
                                                .'Messzyklus und Saison berücksichtigen.';
                                        } elseif ($ageLimit>0) {
                                            $last=(int)($latest[0]['TimeStamp']??0);
                                            if ($last>0 && time()-$last>$ageLimit) {
                                                $status='Prüfen';
                                                $message='Letzter Archivwert vom '
                                                    .date('d.m.Y H:i',$last)
                                                    .'; konfiguriertes Intervall überschritten.';
                                            }
                                        }
                                    } catch (Throwable $archiveError) {
                                        $status='Hinweis';$message='Archiv nicht auswertbar: '
                                            .$archiveError->getMessage();
                                    }
                                }
                            }
                        } catch (Throwable $e) {
                            $status='FEHLER';$message='Metadaten nicht lesbar: '.$e->getMessage();
                        }
                    }
                    if ($status==='OK'){$ok++;continue;}
                    $issues++;$severityCounts[$status]++;
                    $paths=array_values(array_unique($paths));
                    $results[]=['severity'=>$status,'device'=>$device,
                        'field'=>implode(' | ',array_slice($paths,0,3))
                            .(count($paths)>3?' (+'.(count($paths)-3).')':''),
                        'variableId'=>(int)$id,'finding'=>$message];
                }
                // Script IDs are neither variables nor failed sensors.
                if ($source!==null) {
                    foreach ($this->rccInspectionSourceScripts($source) as $scriptId) {
                        $scriptCount++;
                        if (function_exists('IPS_ScriptExists')
                            && !IPS_ScriptExists((int)$scriptId)) {
                            $issues++;$severityCounts['FEHLER']++;
                            $results[]=['severity'=>'FEHLER','device'=>$device,
                                'field'=>'Skript','variableId'=>(int)$scriptId,
                                'finding'=>'Verknüpftes PHP-Skript existiert nicht. '
                                    .'Skriptreferenz im Katalog prüfen.'];
                        }
                    }
                }
            }
            $ms=(int)round((microtime(true)-$started)*1000);
            $summary=count($entries).' markierte Geräte, '.$checked.' eindeutige Variablen '
                .'aus '.$referenceCount.' Fundstellen und '.$scriptCount.' Skriptreferenzen'
                .($health?' (Gesundheitscheck: '.$healthChecked
                    .' Variablen, '.(30-$healthArchiveBudget).' Archiv-Trends)':'').': '
                .$ok.' ohne technischen Befund, '.$severityCounts['FEHLER'].' Fehler, '
                .$severityCounts['Prüfen'].' prüfen, '.$severityCounts['Hinweis'].' Hinweise. '
                .'Dauer '.$ms.' ms. Prüfung lesend; plausibel bedeutet nicht '
                .'am echten Sensor bestätigt.';
            if ($issues===0)$summary.=' Keine technischen Auffälligkeiten erkannt.';
            $report=['at'=>date('Y-m-d H:i:s'),'summary'=>$summary,
                'selected'=>count($entries),'references'=>$referenceCount,
                'variables'=>$checked,'scripts'=>$scriptCount,
                'severityCounts'=>$severityCounts,
                'issues'=>$issues,'deep'=>$deep,'health'=>$health,
                'healthChecked'=>$healthChecked,
                'healthArchiveQueries'=>30-$healthArchiveBudget,
                'rows'=>$results];
            $this->WriteAttributeString('MarkedInspectionResult',json_encode($report,
                JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
            if (function_exists('IPS_LogMessage'))
                IPS_LogMessage('RoomsCatalog Prüfung',$summary);
        } catch(Throwable $e) {
            $this->WriteAttributeString('MarkedInspectionResult',json_encode([
                'summary'=>'Prüfung fehlgeschlagen: '.$e->getMessage(),
                'rows'=>[]],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
        }
        $this->RefreshMarkedDeviceInspection();
    }

    public function RefreshMarkedDeviceInspection(): void
    {
        $report=$this->ReadAttributeString('MarkedInspectionResult');
        $obj=json_decode($report,true);
        $this->UpdateFormField('MarkedInspectionSummary','caption',
            (string)($obj['summary']??'Noch keine Prüfung ausgeführt.'));
        $this->UpdateFormField('MarkedInspectionFindings','values',
            json_encode((array)($obj['rows']??[]),JSON_UNESCAPED_UNICODE));
        $this->UpdateFormField('MarkedInspectionJson','value',$report);
    }
}
