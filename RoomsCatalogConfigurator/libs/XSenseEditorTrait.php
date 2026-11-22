<?php
declare(strict_types=1);
require_once __DIR__.'/HouseholdCatalog.php';
require_once __DIR__.'/XSenseCatalog.php';

trait RCCXSenseEditorTrait
{
    private function syncXSenseToEdit(): string
    {
        try{
            [$id,$old,$catalog]=$this->systemTreeEditContext();$next=IAHXSense::merge($catalog,$this->ReadPropertyInteger('XSenseRootId'));
            if($next!==$catalog)$this->saveSystemTreeEdit($id,$old,$next);
            return count(IAHXSense::source($next)['devices']??[]).' X-Sense-Geräte in EDIT. Geräte bearbeiten → devices → xsense prüfen, danach EDIT nach PROD übernehmen.';
        }catch(Throwable $e){$this->logDebug('X-Sense: '.$e->getMessage());return 'X-Sense: '.$e->getMessage();}
    }

    public function SyncXSense(): void
    {
        $notice=$this->syncXSenseToEdit();$this->refreshSystemTreeCatalog();echo $notice;
    }

    private function xsenseDevice(string $rowKey): array
    {
        $catalog=$this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT');
        $key=str_starts_with($rowKey,'xsense_')?substr($rowKey,7):'';$d=IAHXSense::source($catalog)['devices'][$key]??null;
        if(!is_array($d))throw new RuntimeException('X-Sense-Gerät nicht in EDIT gefunden.');
        return [$key,$d,$catalog];
    }

    private function mutateXSense(string $rowKey,callable $change,bool $refresh=true): void
    {
        [$key]=$this->xsenseDevice($rowKey);[$id,$old,$catalog]=$this->systemTreeEditContext();
        $d=&$catalog['dataSources'][IAHXSense::KEY]['devices'][$key];
        if(!is_array($d))throw new RuntimeException('X-Sense-Gerät wurde inzwischen entfernt.');
        $change($d,$catalog);unset($d);$this->saveSystemTreeEdit($id,$old,$catalog);
        if($refresh)$this->refreshSystemTreeCatalog();
    }

    public function SaveXSenseOverview(string $rowKey,string $title,string $aliases,bool $enabled,string $roomKey,string $group,string $kind): void
    {
        try{
            $title=IAHHomeConnect::clean($title);
            if($kind===''){[, $current]=$this->xsenseDevice($rowKey);$kind=$current['kind'];}
            if($title===''||!preg_match('/^[a-z][a-z0-9_ -]{0,59}$/D',$group)||!in_array($kind,['base','smoke','water','unknown'],true))throw new RuntimeException('Bitte Name, Gruppe und Geräteart prüfen.');
            $this->mutateXSense($rowKey,static function(array &$d,array $catalog)use($title,$aliases,$enabled,$roomKey,$group,$kind):void{
                $rooms=$catalog['rooms']??$catalog;
                if($roomKey!=='__xsense__'&&!isset($rooms[$roomKey]['domains']))throw new RuntimeException('Raum nicht im Katalog.');
                $d['name']=$title;$d['aliases']=array_values(array_unique(array_filter(array_map([IAHHomeConnect::class,'clean'],array_merge([$title],explode(',',$aliases))))));
                $d['enabled']=$enabled;$d['roomKey']=$roomKey;$d['roomLabel']=$roomKey==='__xsense__'?'Haus':(string)($rooms[$roomKey]['display']??$roomKey);
                $d['floor']=$roomKey==='__xsense__'?'—':(string)($rooms[$roomKey]['floor']??'');$d['group']=$group;$d['kind']=$kind;
            });
            echo 'X-Sense-Gerät in EDIT gespeichert. Für Alexa anschließend nach PROD übernehmen.';
        }catch(Throwable $e){echo $e->getMessage();}
    }

    private function xsenseMetricRows(array $d): array
    {
        $rows=[];
        foreach($d['variables'] as $key=>$m){
            $enabled=$m;$enabled['enabled']=true;$r=IAHXSense::reading($enabled,$d['id']);
            $rows[]=$m+['key'=>$key,'metricTitle'=>$m['title'],'value'=>IAHXSense::format($m,$r),'bindingStatus'=>$r===null?'Zuordnung prüfen':'OK'];
        }
        return $rows;
    }

    public function GetXSenseEditForm(string $rowKey): string
    {
        try{
            [,$d,$catalog]=$this->xsenseDevice($rowKey);$quoted=var_export($rowKey,true);
            $rooms=[['caption'=>'Haus (raumübergreifend)','value'=>'__xsense__']];
            foreach(($catalog['rooms']??$catalog) as $key=>$room)if(is_array($room)&&isset($room['domains']))$rooms[]=['caption'=>$room['display']??$key,'value'=>(string)$key];
            $roles=[];foreach(IAHXSense::ROLES as $value=>$caption)$roles[]=compact('value','caption');
            $kinds=[];foreach(['base'=>'Basisstation','smoke'=>'Rauchmelder','water'=>'Wassermelder','unknown'=>'Noch ungeklärt'] as $value=>$caption)$kinds[]=compact('value','caption');
            $items=[
                ['type'=>'Label','caption'=>'X-Sense über Home Assistant · ausschließlich lesen. Test, Stummschalten und Einstellungen werden nicht ausgelöst. Alle Änderungen bleiben bis zur Übernahme in EDIT.'],
                ['type'=>'ValidationTextBox','name'=>'title','caption'=>'Gerätename','value'=>$d['name']],
                ['type'=>'ValidationTextBox','name'=>'XSenseAliases','caption'=>'Alternative Namen (Komma)','value'=>implode(', ',$d['aliases'])],
                ['type'=>'Select','name'=>'XSenseKind','caption'=>'Geräteart','options'=>$kinds,'value'=>$d['kind']],
                ['type'=>'Select','name'=>'XSenseRoom','caption'=>'Raum','options'=>$rooms,'value'=>$d['roomKey']],
                ['type'=>'ValidationTextBox','name'=>'XSenseGroup','caption'=>'Gruppe','value'=>$d['group']],
                ['type'=>'CheckBox','name'=>'kiEnabled','caption'=>'Für Alexa freigeben','value'=>!empty($d['enabled'])],
                ['type'=>'List','name'=>'XSenseVariables','caption'=>'Alle zugehörigen Variablen','rowCount'=>18,'add'=>false,'delete'=>false,'loadValuesFromConfiguration'=>false,
                    'columns'=>[
                        ['name'=>'key','visible'=>false],
                        ['name'=>'metricTitle','caption'=>'Name','width'=>'200px','quickFilter'=>true,'edit'=>['type'=>'ValidationTextBox']],
                        ['name'=>'id','caption'=>'Verknüpfung','width'=>'110px','edit'=>['type'=>'SelectVariable']],
                        ['name'=>'ident','caption'=>'Kennung','width'=>'180px','save'=>false],
                        ['name'=>'role','caption'=>'Bedeutung','width'=>'190px','edit'=>['type'=>'Select','options'=>$roles]],
                        ['name'=>'activeWhen','caption'=>'Aktiv bei wahr','width'=>'100px','edit'=>['type'=>'CheckBox']],
                        ['name'=>'value','caption'=>'Aktueller Wert','width'=>'260px','save'=>false],
                        ['name'=>'bindingStatus','caption'=>'Prüfung','width'=>'130px','save'=>false],
                        ['name'=>'enabled','caption'=>'KI lesen','width'=>'85px','edit'=>['type'=>'CheckBox']],
                    ],'values'=>$this->xsenseMetricRows($d),
                    'onEdit'=>'RCC_SaveXSenseVariable($id, '.$quoted.', json_encode(["key"=>$XSenseVariables["key"],"id"=>$XSenseVariables["id"],"title"=>$XSenseVariables["metricTitle"],"role"=>$XSenseVariables["role"],"activeWhen"=>(bool)$XSenseVariables["activeWhen"],"enabled"=>(bool)$XSenseVariables["enabled"]]));'],
                ['type'=>'Label','caption'=>'Bei Wassermeldern wird die teilweise als „Rauch“ benannte Alarmvariable als Wasseralarm eingeordnet. Veraltete Alarm-/Selbsttestvariablen und Befehlstasten gelten nicht als aktuelle Alarmrückmeldung. Unklare Rollen hier ausdrücklich zuordnen. Prozentwerte unter 20 % werden hervorgehoben; eine Geräte-Meldungszeit älter als 48 Stunden wird als Hinweis genannt. Erreichbar bedeutet nicht automatisch einen bestätigten Selbsttest. IP-Adresse, WLAN-Namen und Zugangsdaten sind ausgeschlossen.'],
            ];
        }catch(Throwable $e){$items=[['type'=>'Label','caption'=>$e->getMessage()]];}
        return json_encode($items,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    }

    public function SaveXSenseVariable(string $rowKey,string $json): void
    {
        try{
            $row=json_decode($json,true,32,JSON_THROW_ON_ERROR);
            $this->mutateXSense($rowKey,static function(array &$d)use($row):void{
                $key=(string)($row['key']??'');if(!isset($d['variables'][$key]))throw new RuntimeException('Zuordnung fehlt.');
                $m=IAHXSense::metric((int)($row['id']??0),$d['kind'],IAHHomeConnect::clean((string)($row['title']??'')));
                if($m['parent']!==$d['id'])throw new RuntimeException('Variable muss zum ausgewählten Gerät gehören.');
                $role=(string)($row['role']??'other');if(!isset(IAHXSense::ROLES[$role]))throw new RuntimeException('Unbekannte Rolle.');
                if(in_array($role,['smoke','waterLeak','alarm','online','connected','muted','lifeEnd','batteryLow','testActive'],true)&&$m['type']!==0)throw new RuntimeException('Diese Rolle benötigt eine Boolean-Rückmeldung.');
                if($role==='batteryLevel'&&!in_array($m['type'],[1,2],true))throw new RuntimeException('Batteriestand benötigt einen Prozentwert.');
                if(str_starts_with($m['ident'],'button_')&&$role!=='command')throw new RuntimeException('Befehlstaste ist keine Zustandsrückmeldung.');
                foreach($d['variables'] as $otherKey=>$other)if($otherKey!==$key&&$other['id']===$m['id'])throw new RuntimeException('Variable ist bereits zugeordnet.');
                $m['role']=$role;$m['activeWhen']=!empty($row['activeWhen']);$m['enabled']=!empty($row['enabled']);$d['variables'][$key]=$m;
            },false);
            [,$d]=$this->xsenseDevice($rowKey);$this->UpdateFormField('XSenseVariables','values',json_encode($this->xsenseMetricRows($d),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            echo 'X-Sense-Variable in EDIT gespeichert.';
        }catch(Throwable $e){echo $e->getMessage();}
    }

    private function buildXSensePanel(): array
    {
        return ['type'=>'ExpansionPanel','caption'=>'X-SENSE – Rauchmelder, Wassermelder und Basisstation','expanded'=>false,'items'=>[
            ['type'=>'Label','caption'=>'Home-Assistant-Geräte unter einem X-Sense benannten Zweig automatisch abgleichen. Alternativ eine Kategorie wählen, deren untergeordnete Home-Assistant-Geräte aufgenommen werden. Danach Geräte bearbeiten → devices → xsense prüfen und EDIT nach PROD übernehmen.'],
            ['type'=>'SelectObject','name'=>'XSenseRootId','caption'=>'Kategorie (optional; 0 = X-Sense-Zweig automatisch finden)'],
            ['type'=>'Button','caption'=>'X-SENSE IN EDIT ABGLEICHEN','onClick'=>'RCC_SyncXSense($id);'],
            ['type'=>'Label','caption'=>'Alexa: Wie geht es meiner Brandmeldestation? · Wie geht es meinen Wassermeldern? · Wie ist die Sicherheit im Haus? · Welcher Akku der X-Sense-Geräte ist am niedrigsten? · Wann gab es zuletzt Rauchalarm?'],
        ]];
    }
}
