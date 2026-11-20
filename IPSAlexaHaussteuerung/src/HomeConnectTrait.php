<?php
declare(strict_types=1);
if (!class_exists('IAHHomeConnect', false)) {
    require_once __DIR__ . '/../resources/helpers/HomeConnect.php';
}
if (!class_exists('IAHHouseholdDevices',false)) require_once __DIR__.'/../resources/helpers/HouseholdDevices.php';

trait IAHHomeConnectTrait
{
    public function GetHomeConnectCatalog(): string
    {
        $managed=$this->publishedHouseholdSource();
        if ($managed===null&&$this->ReadAttributeString('HouseholdCatalogManaged')==='1') {
            $managed=['provider'=>IAHHouseholdDevices::PROVIDER,'schemaVersion'=>1,'kiEnabled'=>false,'devices'=>[]];
        }
        if ($managed!==null) {
            $this->WriteAttributeString('HouseholdCatalogManaged','1');
            if (!$this->ReadPropertyBoolean('HomeConnectEnabled')) {
                foreach ($managed['devices'] as &$d) if (($d['provider']??'')==='HomeConnect') $d['enabled']=false;
                unset($d);
            }
            $home=IAHHouseholdDevices::homeSource($managed);
            if (!$this->ReadPropertyBoolean('HomeConnectEnabled')) $home['kiEnabled']=false;
            return json_encode(['dataSources'=>[IAHHouseholdDevices::KEY=>$managed,'home_connect'=>$home]],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return $this->GetHomeConnectDiscoveryCatalog();
    }

    public function GetHomeConnectDiscoveryCatalog(): string
    {
        if (!$this->ReadPropertyBoolean('HomeConnectEnabled')) return '{"dataSources":{}}';
        try {
            $settings = json_decode($this->ReadAttributeString('HomeConnectDeviceSettings'), true) ?: [];
            $source=IAHHomeConnect::source($this->ReadPropertyInteger('HomeConnectRootId'), $settings);
            return json_encode(['dataSources'=>['home_connect'=>$source]], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) { return '{"dataSources":{}}'; }
    }

    private function publishedHouseholdSource(): ?array
    {
        if (!method_exists($this,'getObjectIDByIdentOrName')||!function_exists('IPS_ScriptExists')) return null;
        try {
            $settings=$this->getObjectIDByIdentOrName($this->InstanceID,'iahSettings','Einstellungen');
            $id=$settings>0?$this->getObjectIDByIdentOrName($settings,'roomsCatalog','RoomsCatalog'):0;
            if ($settings>0&&method_exists($this,'systemConfigurationScriptIds')) {
                $ids=$this->systemConfigurationScriptIds($settings);
                $config=IAHVoiceRuleLiteralReader::read($this->getScriptContent((int)($ids['prod']??0)));
                $configured=(int)($config['script']['ROOMS_CATALOG']??0);
                if ($configured>0) $id=$configured;
            }
            if ($id<=0||!IPS_ScriptExists($id)) return null;
            $catalog=IAHVoiceRuleLiteralReader::read($this->getScriptContent($id));
            return is_array($catalog)?IAHHouseholdDevices::source($catalog):null;
        } catch (Throwable $e) { return null; }
    }

    public function IsHouseholdCommand(string $text): bool
    {
        if (mb_strlen($text)>300||!preg_match('/^(?:bitte )?(?:schalte|stelle|setze|starte|stoppe|pausiere)\b/',IAHHomeConnect::norm($text))) return false;
        $source=$this->publishedHouseholdSource();
        return $source!==null&&IAHHomeConnect::select(array_values((array)$source['devices']),IAHHomeConnect::norm($text))!==[];
    }

    private function homeConnectDevices(): array
    {
        $catalog = json_decode($this->GetHomeConnectCatalog(), true) ?: [];
        return IAHHomeConnect::devices($catalog);
    }

    private function homeConnectRows(): array
    {
        if (!$this->ReadPropertyBoolean('HomeConnectEnabled')) return [];
        try {
            $settings = json_decode($this->ReadAttributeString('HomeConnectDeviceSettings'), true) ?: [];
            $managed=$this->publishedHouseholdSource();
            $source=$managed!==null?IAHHouseholdDevices::homeSource($managed):IAHHomeConnect::source($this->ReadPropertyInteger('HomeConnectRootId'), $settings);
            $rows = [];
            foreach ($source['devices'] as $d) {
                $v = IAHHomeConnect::forDevice($d);
                $rows[] = ['id'=>$d['id'], 'name'=>$d['name'], 'type'=>$d['type'],
                    'enabled'=>$d['enabled'], 'allowControl'=>$d['allowControl'],
                    'aliases'=>$managed!==null?implode(', ',(array)$d['aliases']):(string)($settings[(string)$d['id']]['aliases'] ?? ''),
                    'variables'=>count($v), 'status'=>isset($v['OperationState']) ? IAHHomeConnect::display($v['OperationState']) : 'Noch kein Status'];
            }
            return $rows;
        } catch (Throwable $e) { return []; }
    }

    public function SetHomeConnectDevice(string $rowJson): void
    {
        try {
            if ($this->publishedHouseholdSource()!==null) throw new RuntimeException('Hausgeräte bitte zentral im RoomsCatalog unter Domain Hausgeräte bearbeiten.');
            $row = json_decode($rowJson, true, 32, JSON_THROW_ON_ERROR);
            $id = (int)($row['id'] ?? 0);
            if (!IAHHomeConnect::validDevice($id)) throw new RuntimeException('Home-Connect-Gerät fehlt.');
            $settings = json_decode($this->ReadAttributeString('HomeConnectDeviceSettings'), true) ?: [];
            $settings[(string)$id] = ['enabled'=>!empty($row['enabled']), 'allowControl'=>!empty($row['allowControl']),
                'aliases'=>IAHHomeConnect::clean((string)($row['aliases'] ?? ''))];
            $this->WriteAttributeString('HomeConnectDeviceSettings', json_encode($settings, JSON_THROW_ON_ERROR));
            $this->RefreshAnnouncements();
            $this->UpdateFormField('HomeConnectDevices', 'values', json_encode($this->homeConnectRows(),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
            echo 'Home-Connect-Gerät gespeichert.';
        } catch (Throwable $e) { echo $e->getMessage(); }
    }

    public function RefreshHomeConnectDevices(): void
    {
        $this->RefreshAnnouncements();
        $this->UpdateFormField('HomeConnectDevices', 'values', json_encode($this->homeConnectRows(),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        echo 'Geräte und Durchsagenvorlagen aktualisiert.';
    }

    private function buildHomeConnectPanel(): array
    {
        // Do not probe installed instances while the new property is still disabled/unavailable in older runtimes.
        return ['type'=>'ExpansionPanel', 'caption'=>'HOME CONNECT – Erkennung und Verbindung', 'expanded'=>false, 'items'=>[
            ['type'=>'CheckBox', 'name'=>'HomeConnectEnabled', 'caption'=>'Home-Connect-Geräte für Alexa einbinden'],
            ['type'=>'SelectObject', 'name'=>'HomeConnectRootId', 'caption'=>'Optional: Hauptkategorie begrenzen (leer = alle Home-Connect-Geräte)', 'objectTypes'=>[0,1]],
            ['type'=>'Label', 'caption'=>'Hausgeräte und alle Variablen zentral im RoomsCatalogConfigurator unter Domain Hausgeräte bearbeiten. Dort werden auch Geräte anderer Hersteller verknüpft. Änderungen nach PROD übernehmen. Diese Übersicht zeigt die Verbindung; Freigaben werden im Katalog gepflegt.'],
            ['type'=>'List', 'name'=>'HomeConnectDevices', 'caption'=>'Geräte und alternative Namen',
                'rowCount'=>8, 'add'=>false, 'delete'=>false, 'loadValuesFromConfiguration'=>false,
                'columns'=>[
                    ['caption'=>'ID','name'=>'id','width'=>'70px','save'=>false],
                    ['caption'=>'Gerät','name'=>'name','width'=>'180px','save'=>false],
                    ['caption'=>'Typ','name'=>'type','width'=>'110px','save'=>false],
                    ['caption'=>'Status','name'=>'status','width'=>'110px','save'=>false],
                    ['caption'=>'Werte','name'=>'variables','width'=>'60px','save'=>false],
                    ['caption'=>'Alexa','name'=>'enabled','width'=>'75px','save'=>false],
                    ['caption'=>'Steuern','name'=>'allowControl','width'=>'80px','save'=>false],
                    ['caption'=>'Namen (RoomsCatalog)','name'=>'aliases','width'=>'230px','save'=>false],
                ], 'values'=>$this->homeConnectRows(),
                ],
            ['type'=>'Button', 'caption'=>'Geräte und Durchsagenvorlagen aktualisieren', 'onClick'=>'IAH_RefreshHomeConnectDevices($id);'],
        ]];
    }
}
