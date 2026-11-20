<?php
declare(strict_types=1);
if (!class_exists('IAHHomeConnect', false)) {
    require_once __DIR__ . '/../resources/helpers/HomeConnect.php';
}

trait IAHHomeConnectTrait
{
    public function GetHomeConnectCatalog(): string
    {
        if (!$this->ReadPropertyBoolean('HomeConnectEnabled')) return '{"dataSources":{}}';
        try {
            $settings = json_decode($this->ReadAttributeString('HomeConnectDeviceSettings'), true) ?: [];
            $source = IAHHomeConnect::source($this->ReadPropertyInteger('HomeConnectRootId'), $settings);
            return json_encode(['dataSources'=>['home_connect'=>$source]], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) { return '{"dataSources":{}}'; }
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
            $source = IAHHomeConnect::source($this->ReadPropertyInteger('HomeConnectRootId'), $settings);
            $rows = [];
            foreach ($source['devices'] as $d) {
                $v = IAHHomeConnect::variables($d['id']);
                $rows[] = ['id'=>$d['id'], 'name'=>$d['name'], 'type'=>$d['type'],
                    'enabled'=>$d['enabled'], 'allowControl'=>$d['allowControl'],
                    'aliases'=>(string)($settings[(string)$d['id']]['aliases'] ?? ''),
                    'variables'=>count($v), 'status'=>isset($v['OperationState']) ? IAHHomeConnect::display($v['OperationState']) : 'Noch kein Status'];
            }
            return $rows;
        } catch (Throwable $e) { return []; }
    }

    public function SetHomeConnectDevice(string $rowJson): void
    {
        try {
            $row = json_decode($rowJson, true, 32, JSON_THROW_ON_ERROR);
            $id = (int)($row['id'] ?? 0);
            if (!IAHHomeConnect::validDevice($id)) throw new RuntimeException('Home-Connect-Gerät fehlt.');
            $settings = json_decode($this->ReadAttributeString('HomeConnectDeviceSettings'), true) ?: [];
            $settings[(string)$id] = ['enabled'=>!empty($row['enabled']), 'allowControl'=>!empty($row['allowControl']),
                'aliases'=>IAHHomeConnect::clean((string)($row['aliases'] ?? ''))];
            $this->WriteAttributeString('HomeConnectDeviceSettings', json_encode($settings, JSON_THROW_ON_ERROR));
            $this->RefreshAnnouncements();
            $this->UpdateFormField('HomeConnectDevices', 'values', $this->homeConnectRows());
            echo 'Home-Connect-Gerät gespeichert.';
        } catch (Throwable $e) { echo $e->getMessage(); }
    }

    public function RefreshHomeConnectDevices(): void
    {
        $this->RefreshAnnouncements();
        $this->UpdateFormField('HomeConnectDevices', 'values', $this->homeConnectRows());
        echo 'Geräte und Durchsagenvorlagen aktualisiert.';
    }

    private function buildHomeConnectPanel(): array
    {
        // Do not probe installed instances while the new property is still disabled/unavailable in older runtimes.
        return ['type'=>'ExpansionPanel', 'caption'=>'HOME CONNECT – Haushaltsgeräte', 'expanded'=>false, 'items'=>[
            ['type'=>'CheckBox', 'name'=>'HomeConnectEnabled', 'caption'=>'Home-Connect-Geräte für Alexa einbinden'],
            ['type'=>'SelectObject', 'name'=>'HomeConnectRootId', 'caption'=>'Optional: Hauptkategorie begrenzen (leer = alle Home-Connect-Geräte)', 'objectTypes'=>[0,1]],
            ['type'=>'Label', 'caption'=>'Geräte werden automatisch am offiziellen Home-Connect-Modul erkannt. Neue Variablen werden bei jeder Frage mitgelesen. „Steuern“ erlaubt nur eindeutige verfügbare Funktionen; zusätzlich muss „KI-Kommandos“ in den Alexa-Einstellungen aktiv sein. Fernstart und lokale Bedienung werden geprüft.'],
            ['type'=>'List', 'name'=>'HomeConnectDevices', 'caption'=>'Geräte und alternative Namen',
                'rowCount'=>8, 'add'=>false, 'delete'=>false, 'loadValuesFromConfiguration'=>false,
                'columns'=>[
                    ['caption'=>'ID','name'=>'id','width'=>'70px','save'=>false],
                    ['caption'=>'Gerät','name'=>'name','width'=>'180px','save'=>false],
                    ['caption'=>'Typ','name'=>'type','width'=>'110px','save'=>false],
                    ['caption'=>'Status','name'=>'status','width'=>'110px','save'=>false],
                    ['caption'=>'Werte','name'=>'variables','width'=>'60px','save'=>false],
                    ['caption'=>'Alexa','name'=>'enabled','width'=>'75px','edit'=>['type'=>'CheckBox']],
                    ['caption'=>'Steuern','name'=>'allowControl','width'=>'80px','edit'=>['type'=>'CheckBox']],
                    ['caption'=>'Weitere Namen (Komma)','name'=>'aliases','width'=>'230px','edit'=>['type'=>'ValidationTextBox']],
                ], 'values'=>$this->homeConnectRows(),
                'onEdit'=>'IAH_SetHomeConnectDevice($id, json_encode($HomeConnectDevices));'],
            ['type'=>'Button', 'caption'=>'Geräte und Durchsagenvorlagen aktualisieren', 'onClick'=>'IAH_RefreshHomeConnectDevices($id);'],
        ]];
    }
}
