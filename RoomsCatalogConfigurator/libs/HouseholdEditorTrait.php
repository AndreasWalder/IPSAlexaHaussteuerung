<?php
declare(strict_types=1);
require_once __DIR__.'/HouseholdCatalog.php';

trait RCCHouseholdEditorTrait
{
    private function householdAlexaInstance(): int
    {
        if (!function_exists('IPS_GetInstanceListByModuleID')) return 0;
        $id=$this->ReadPropertyInteger('DiscoveryAlexaInstanceId');
        $ids=IPS_GetInstanceListByModuleID(self::ALEXA_MODULE_ID);
        return in_array($id,$ids,true)?$id:(count($ids)===1?(int)reset($ids):0);
    }

    /** Add metadata to EDIT only, retaining every existing link, label and permission. */
    private function syncHouseholdToEdit(): string
    {
        if (!function_exists('IAH_GetHomeConnectDiscoveryCatalog')) return 'Bitte auch IPSAlexaHaussteuerung aktualisieren und die Einstellungen übernehmen.';
        try {
            $alexa=$this->householdAlexaInstance(); if ($alexa<=0) return 'Bitte die zuständige Alexa-Instanz im Konfigurator auswählen; die Verknüpfung ist nicht eindeutig.';
            [$id,$old,$catalog]=$this->systemTreeEditContext();
            $found=json_decode(IAH_GetHomeConnectDiscoveryCatalog($alexa),true,512,JSON_THROW_ON_ERROR);
            $source=$found['dataSources']['home_connect']??[];
            if (($source['provider']??'')!=='HomeConnect') return 'Keine Home-Connect-Erkennung verfügbar. Freigabe und Wurzelauswahl in IPSAlexaHaussteuerung prüfen.';
            $next=RCCHouseholdCatalog::merge($catalog,$source);
            if ($next!==$catalog) $this->saveSystemTreeEdit($id,$old,$next);
            return count(IAHHouseholdDevices::source($next)['devices']??[]).' Hausgeräte in EDIT abgeglichen. Domain Hausgeräte wählen; anschließend EDIT nach PROD übernehmen.';
        } catch (Throwable $e) { $this->logDebug('Hausgeräte-Abgleich: '.$e->getMessage()); return 'Hausgeräte-Abgleich: '.$e->getMessage(); }
    }

    public function SyncHouseholdDevices(): void
    {
        $notice=$this->syncHouseholdToEdit();
        $this->refreshSystemTreeCatalog();
        echo $notice;
    }

    private function householdDevice(string $rowKey): array
    {
        $catalog=$this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT');
        $key=str_starts_with($rowKey,'household_')?substr($rowKey,strlen('household_')):'';
        $source=IAHHouseholdDevices::source($catalog);
        $device=$source['devices'][$key]??null;
        if (!is_array($device)) throw new RuntimeException('Hausgerät nicht in EDIT gefunden.');
        return [$key,$device,$catalog];
    }

    private function mutateHousehold(string $rowKey,callable $change): void
    {
        [$key]=$this->householdDevice($rowKey);
        [$id,$old,$catalog]=$this->systemTreeEditContext();
        $d=&$catalog['dataSources'][IAHHouseholdDevices::KEY]['devices'][$key];
        if (!is_array($d)) throw new RuntimeException('Hausgerät wurde inzwischen entfernt.');
        $change($d,$catalog); unset($d);
        $this->saveSystemTreeEdit($id,$old,$catalog);
        $this->refreshSystemTreeCatalog();
    }

    public function SaveHouseholdOverview(string $rowKey,string $title,string $aliases,bool $enabled,bool $allowControl,string $roomKey,string $group): void
    {
        try {
            $title=IAHHomeConnect::clean($title); $group=trim($group);
            if ($title===''||!preg_match('/^[a-z][a-z0-9_ -]{0,59}$/D',$group)) throw new RuntimeException('Bitte Name und Gruppe prüfen.');
            $this->mutateHousehold($rowKey,static function(array &$d,array $catalog) use($title,$aliases,$enabled,$allowControl,$roomKey,$group):void {
                $rooms=$catalog['rooms']??$catalog;
                if ($roomKey!=='__household__'&&!isset($rooms[$roomKey]['domains'])) throw new RuntimeException('Raum nicht im Katalog gefunden.');
                $d['name']=$title; $d['aliases']=array_values(array_unique(array_merge([$title],array_filter(array_map('trim',explode(',',$aliases))))));
                $d['enabled']=$enabled; $d['allowControl']=$allowControl; $d['roomKey']=$roomKey;
                $d['roomLabel']=$roomKey==='__household__'?'Haus':(string)($rooms[$roomKey]['display']??$roomKey);
                $d['floor']=$roomKey==='__household__'?'—':(string)($rooms[$roomKey]['floor']??''); $d['group']=$group;
            });
            echo 'Hausgerät in EDIT gespeichert. Für Alexa anschließend nach PROD übernehmen.';
        } catch (Throwable $e) { echo $e->getMessage(); }
    }

    private function householdMetricRows(array $device): array
    {
        $rows=[];
        foreach ((array)$device['variables'] as $key=>$m) {
            try { $read=IAHHouseholdDevices::reading($m); } catch (Throwable $e) { $read=null; }
            $rows[]=$m+['key'=>(string)$key,'value'=>$read!==null?IAHHomeConnect::display($read):'Wert nicht verfügbar',
                'action'=>$read!==null&&$read['action']?'Ja':'Nein','bindingStatus'=>$read!==null?'OK':'Zuordnung prüfen'];
        }
        return $rows;
    }

    public function GetHouseholdEditForm(string $rowKey): string
    {
        try {
            [, $d,$catalog]=$this->householdDevice($rowKey); $q=var_export($rowKey,true);
            $rooms=[['caption'=>'Haus (raumübergreifend)','value'=>'__household__']];
            foreach (($catalog['rooms']??$catalog) as $key=>$room) if (is_array($room)&&isset($room['domains'])) $rooms[]=['caption'=>$room['display']??$key,'value'=>(string)$key];
            $roleOptions=[];
            foreach (['status'=>'Betriebsstatus','power'=>'Ein/Aus','temperature'=>'Temperatur','progress'=>'Fortschritt','remaining'=>'Restzeit','program'=>'Programm','event'=>'Ereignis','other'=>'Weiterer Wert'] as $value=>$caption) $roleOptions[]=compact('value','caption');
            $fields=[];
            foreach (['key','id','title','role','enabled','writable'] as $field) $fields[]=var_export($field,true).' => $HouseholdVariables['.var_export($field,true).']';
            $items=[
                ['type'=>'Label','caption'=>'Hausgerät · '.($d['provider']??'Allgemein').' · '.count($d['variables']).' verknüpfte Variablen. Änderungen werden ausschließlich in EDIT gespeichert. Name, Raum und Gesamtfreigaben beim Schließen speichern; Variablenänderungen werden direkt gesichert.'],
                ['type'=>'ValidationTextBox','name'=>'title','caption'=>'Gerätename','value'=>$d['name']],
                ['type'=>'ValidationTextBox','name'=>'HouseholdAliases','caption'=>'Alternative Namen (Komma)','value'=>implode(', ',(array)$d['aliases'])],
                ['type'=>'Select','name'=>'HouseholdRoom','caption'=>'Raum','options'=>$rooms,'value'=>$d['roomKey']],
                ['type'=>'ValidationTextBox','name'=>'HouseholdGroup','caption'=>'Gruppe','value'=>$d['group']??'hausgeraete'],
                ['type'=>'CheckBox','name'=>'kiEnabled','caption'=>'Gerät für Alexa freigeben','value'=>!empty($d['enabled'])],
                ['type'=>'CheckBox','name'=>'HouseholdAllowControl','caption'=>'Freigegebene Gerätefunktionen steuern','value'=>!empty($d['allowControl'])],
                ['type'=>'List','name'=>'HouseholdVariables','caption'=>'Alle zugehörigen Variablen – Werte, Verknüpfung und Freigaben','rowCount'=>15,
                    'add'=>false,'delete'=>false,'loadValuesFromConfiguration'=>false,'columns'=>[
                        ['name'=>'key','caption'=>'Kennung','width'=>'180px','save'=>false,'quickFilter'=>true],
                        ['name'=>'title','caption'=>'Name','width'=>'180px','quickFilter'=>true,'edit'=>['type'=>'ValidationTextBox']],
                        ['name'=>'id','caption'=>'Verknüpfte Variable','width'=>'120px','edit'=>['type'=>'SelectVariable']],
                        ['name'=>'role','caption'=>'Bedeutung','width'=>'120px','edit'=>['type'=>'Select','options'=>$roleOptions]],
                        ['name'=>'value','caption'=>'Aktueller Wert','width'=>'220px','save'=>false],
                        ['name'=>'enabled','caption'=>'KI lesen','width'=>'80px','edit'=>['type'=>'CheckBox']],
                        ['name'=>'writable','caption'=>'Schalten erlaubt','width'=>'100px','edit'=>['type'=>'CheckBox']],
                        ['name'=>'action','caption'=>'IPS-Aktion','width'=>'80px','save'=>false],
                        ['name'=>'bindingStatus','caption'=>'Zuordnung','width'=>'120px','save'=>false],
                    ],'values'=>$this->householdMetricRows($d),
                    'onEdit'=>'RCC_SaveHouseholdVariable($id, '.$q.', json_encode(['.implode(',',$fields).']));'],
                ['type'=>'RowLayout','items'=>[
                    ['type'=>'SelectVariable','name'=>'HouseholdNewVariable','caption'=>'Weitere Variable verknüpfen','value'=>0],
                    ['type'=>'Button','caption'=>'Variable hinzufügen','onClick'=>'RCC_AddHouseholdVariable($id, '.$q.', (int)$HouseholdNewVariable);'],
                ]],
                ['type'=>'Label','caption'=>'KI-Freigaben beziehen sich auf diese Zuordnungen. Nicht freigegebene Werte werden nicht an die KI übermittelt. Eine vorhandene IPS-Aktion allein gibt keine Sprachsteuerung frei.'],
            ];
        } catch (Throwable $e) { $items=[['type'=>'Label','caption'=>$e->getMessage()]]; }
        return json_encode($items,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    }

    public function SaveHouseholdVariable(string $rowKey,string $rowJson): void
    {
        try {
            $row=json_decode($rowJson,true,32,JSON_THROW_ON_ERROR);
            $this->mutateHousehold($rowKey,static function(array &$d)use($row):void {
                $key=(string)($row['key']??''); $old=$d['variables'][$key]??null;
                if (!is_array($old)) throw new RuntimeException('Variablenzuordnung fehlt.');
                $id=(int)($row['id']??0); $m=IAHHouseholdDevices::metric($id,IAHHomeConnect::clean((string)$row['title']));
                $m['enabled']=!empty($row['enabled']); $m['writable']=!empty($row['writable']);
                $m['role']=(string)($row['role']??'other');
                if (!in_array($m['role'],['status','power','temperature','progress','remaining','program','event','other'],true)) throw new RuntimeException('Unbekannte Bedeutung.');
                $read=IAHHouseholdDevices::reading($m);
                if ($m['writable']&&($read===null||!$read['action'])) throw new RuntimeException('Diese Variable besitzt keine aktivierte IPS-Aktion.');
                if (($d['provider']??'')==='HomeConnect') {
                    $live=IAHHomeConnect::variables((int)$d['id']);
                    if (!isset($live[$m['ident']])||$live[$m['ident']]['id']!==$id) throw new RuntimeException('Home-Connect-Zuordnung muss zu diesem Gerät gehören.');
                }
                $d['variables'][$key]=$m;
            });
            [, $d]=$this->householdDevice($rowKey);
            $this->UpdateFormField('HouseholdVariables','values',json_encode($this->householdMetricRows($d),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
            echo 'Variablenzuordnung in EDIT gespeichert.';
        } catch (Throwable $e) { echo $e->getMessage(); }
    }

    public function AddHouseholdVariable(string $rowKey,int $variableId): void
    {
        try {
            $m=IAHHouseholdDevices::metric($variableId);
            $this->mutateHousehold($rowKey,static function(array &$d)use($m):void {
                if (in_array($m['id'],array_column($d['variables'],'id'),true)) throw new RuntimeException('Variable ist bereits verknüpft.');
                if (($d['provider']??'')==='HomeConnect') {
                    $live=IAHHomeConnect::variables((int)$d['id']);
                    if (!isset($live[$m['ident']])||$live[$m['ident']]['id']!==$m['id']) throw new RuntimeException('Home-Connect-Zuordnung muss zu diesem Gerät gehören.');
                }
                $key=($d['provider']??'')==='HomeConnect'?$m['ident']:'var_'.$m['id'];
                if ($key===''||isset($d['variables'][$key])) throw new RuntimeException('Die Kennung ist bereits belegt.');
                $d['variables'][$key]=$m;
            });
            [, $d]=$this->householdDevice($rowKey);
            $this->UpdateFormField('HouseholdVariables','values',json_encode($this->householdMetricRows($d),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
            echo 'Weitere Variable in EDIT verknüpft.';
        } catch (Throwable $e) { echo $e->getMessage(); }
    }

    public function AddHouseholdDevice(int $root,string $title): void
    {
        try {
            if ($root<=0||!IPS_ObjectExists($root)) throw new RuntimeException('Bitte den Hausgeräte-Knoten auswählen.');
            [$id,$old,$catalog]=$this->systemTreeEditContext();
            if (isset($catalog['dataSources'][IAHHouseholdDevices::KEY])&&IAHHouseholdDevices::source($catalog)===null) throw new RuntimeException('Der Katalogschlüssel für Hausgeräte ist bereits anders belegt.');
            $source=IAHHouseholdDevices::source($catalog)??['provider'=>IAHHouseholdDevices::PROVIDER,'schemaVersion'=>1,'domain'=>'hausgeraete','kiEnabled'=>true,'devices'=>[]];
            foreach ($source['devices'] as $d) if ((int)$d['id']===$root) throw new RuntimeException('Hausgerät bereits verknüpft.');
            $variables=[]; $queue=[[$root,0]]; $seen=[];
            while ($queue!==[]&&count($seen)<500) {
                [$child,$depth]=array_pop($queue); if (isset($seen[$child])) continue; $seen[$child]=true;
                $o=IPS_GetObject($child);
                if ((int)$o['ObjectType']===2) $variables['var_'.$child]=IAHHouseholdDevices::metric($child);
                elseif ($depth<8&&in_array((int)$o['ObjectType'],[0,1],true)) foreach (IPS_GetChildrenIDs($child) as $c) $queue[]=[(int)$c,$depth+1];
            }
            if ($variables===[]) throw new RuntimeException('Keine Variablen unter diesem Knoten gefunden.');
            $name=IAHHomeConnect::clean(trim($title)!==''?$title:(string)IPS_GetObject($root)['ObjectName']);
            $source['devices']['object_'.$root]=['id'=>$root,'name'=>$name,'aliases'=>[$name],'type'=>'appliance','provider'=>'ObjectTree',
                'enabled'=>true,'allowControl'=>false,'roomKey'=>'__household__','roomLabel'=>'Haus','floor'=>'—','group'=>'hausgeraete','variables'=>$variables];
            $catalog['dataSources'][IAHHouseholdDevices::KEY]=$source;
            $this->saveSystemTreeEdit($id,$old,$catalog); $this->refreshSystemTreeCatalog();
            echo 'Hausgerät mit '.count($variables).' Variablen in EDIT angelegt. Im Zahnrad Bedeutung und Freigaben bearbeiten, danach nach PROD übernehmen.';
        } catch (Throwable $e) { echo $e->getMessage(); }
    }

    private function buildHouseholdPanel(): array
    {
        return ['type'=>'ExpansionPanel','caption'=>'HAUSGERÄTE – Vorhandene und weitere Geräte verknüpfen','expanded'=>false,'items'=>[
            ['type'=>'Label','caption'=>'Zentrale Verwaltung im normalen Geräteeditor: Domain-Filter Hausgeräte. Pro Gerät öffnet das Zahnrad alle verknüpften Variablen, aktuelle Werte, Namen, Rollen und Freigaben. Änderungen gehen nach EDIT und werden mit der normalen PROD-Übernahme veröffentlicht.'],
            ['type'=>'Button','caption'=>'Home-Connect-Geräte und neue Variablen nach EDIT abgleichen','onClick'=>'RCC_SyncHouseholdDevices($id);'],
            ['type'=>'RowLayout','items'=>[
                ['type'=>'SelectObject','name'=>'HouseholdNewRoot','caption'=>'Weiteres Hausgerät (Instanz, Kategorie oder Variable)','objectTypes'=>[0,1,2],'value'=>0],
                ['type'=>'ValidationTextBox','name'=>'HouseholdNewTitle','caption'=>'Name des Hausgeräts','value'=>''],
                ['type'=>'Button','caption'=>'Als Hausgerät verknüpfen','onClick'=>'RCC_AddHouseholdDevice($id, (int)$HouseholdNewRoot, (string)$HouseholdNewTitle);'],
            ]],
        ]];
    }
}
