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
                    ['type'=>'Button','caption'=>'JETZT PRÜFEN',
                        'onClick'=>'RCC_RunMarkedDeviceInspection($id,(bool)$MarkedInspectionDeep);'],
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

    public function RunMarkedDeviceInspection(bool $deep=false): void
    {
        $started=microtime(true);
        try {
            $this->ensureRuntimeCatalogCurrent();
            $selected=$this->bulkSelectionKeys();
            if ($selected===[]) throw new RuntimeException(
                'Keine Geräte markiert. Bitte zuerst Häkchen in der Geräteliste setzen.');
            $entries=[];
            foreach ($this->getRuntimeEntries() as $row) {
                if (!is_array($row))continue;
                $key=$this->buildCompositeKey($row);
                if ($key!==null && in_array($key,$selected,true))$entries[]=$row;
            }
            $results=[];$checked=0;$ok=0;$issues=0;
            $seen=[];
            $archiveId=0;$archiveMap=[];
            if ($deep && function_exists('AC_GetAggregationVariables')) {
                try {
                    [$archiveId,$archiveMap]=$this->systemTreeArchive();
                } catch (Throwable $error) {
                    $results[]=['severity'=>'Hinweis','device'=>'Archiv',
                        'field'=>'Archive Control','variableId'=>0,
                        'finding'=>'Archiv nicht lesbar: '.$error->getMessage()];
                }
            }
            foreach ($entries as $row) {
                $device=(string)($row['title']??$row['key']??'Gerät');
                $ids=$this->rccInspectionVariableFields($row);
                if ($ids===[]) {
                    $results[]=['severity'=>'Hinweis','device'=>$device,'field'=>'Zuordnung',
                        'variableId'=>0,'finding'=>'Keine typisierte Variablen-ID gefunden. '
                            .'Für Anlagen-Datenquellen bitte die zentrale Variablenverwaltung verwenden.'];
                    continue;
                }
                foreach ($ids as $field=>$id) {
                    $checked++;
                    $status='OK';$message='';
                    if (!function_exists('IPS_VariableExists')||!IPS_VariableExists($id)) {
                        $status='FEHLER';$message='Variable existiert nicht. '
                            .'Im zentralen Variableneditor eine Ersatz-ID auswählen.';
                    } else {
                        try {
                            $obj=IPS_GetObject($id);
                            $var=IPS_GetVariable($id);
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
                            if ($type===3 && preg_match('/(?:temperature|humidity|power|brightness|position|ist|soll)/i',$field)) {
                                $status='Prüfen';$message='String statt numerischem Messwert. '
                                    .'Format prüfen und ggf. eine Float-Hilfsvariable verwenden.';
                            }
                            if ($deep && $archiveId>0) {
                                $logged=isset($archiveMap[$id]);
                                if (!$logged && $status==='OK') {
                                    $status='Hinweis';
                                    $message='Kein Archiveintrag vorhanden. Archivlogging prüfen, '
                                        .'falls historische Auswertungen benötigt werden.';
                                }
                            }
                        } catch (Throwable $e) {
                            $status='FEHLER';$message='Metadaten nicht lesbar: '.$e->getMessage();
                        }
                    }
                    if ($status==='OK'){$ok++;continue;}
                    $issues++;
                    $results[]=['severity'=>$status,'device'=>$device,'field'=>$field,
                        'variableId'=>$id,'finding'=>$message];
                }
            }
            $ms=(int)round((microtime(true)-$started)*1000);
            $summary=count($entries).' markierte Geräte, '.$checked.' Variablenverweise: '
                .$ok.' ohne technischen Befund, '.$issues.' Auffälligkeiten. '
                .'Dauer '.$ms.' ms. Prüfung lesend; plausibel bedeutet nicht '
                .'am echten Sensor bestätigt.';
            if ($issues===0)$summary.=' Keine technischen Auffälligkeiten erkannt.';
            $report=['at'=>date('Y-m-d H:i:s'),'summary'=>$summary,
                'selected'=>count($entries),'references'=>$checked,
                'issues'=>$issues,'deep'=>$deep,'rows'=>$results];
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
