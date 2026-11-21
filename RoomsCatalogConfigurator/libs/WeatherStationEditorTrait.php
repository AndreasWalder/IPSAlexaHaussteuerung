<?php
declare(strict_types=1);
require_once __DIR__.'/WeatherStationCatalog.php';

trait RCCWeatherStationEditorTrait
{
    private function syncWeatherStationsToEdit(): string
    {
        try {
            [$id,$old,$catalog]=$this->systemTreeEditContext(); $next=IAHWeatherStation::merge($catalog);
            if ($next!==$catalog) $this->saveSystemTreeEdit($id,$old,$next);
            return count(IAHWeatherStation::source($next)['devices']??[]).' Wetterstationen in EDIT. Unter Geräte bearbeiten → Wetterstation prüfen; danach EDIT nach PROD übernehmen.';
        } catch (Throwable $e) { $this->logDebug('Wetterstationen: '.$e->getMessage()); return 'Wetterstationen: '.$e->getMessage(); }
    }

    public function SyncWeatherStations(): void
    {
        $notice=$this->syncWeatherStationsToEdit(); $this->refreshSystemTreeCatalog(); echo $notice;
    }

    private function weatherStationDevice(string $rowKey): array
    {
        $catalog=$this->loadConfiguredCatalog('RoomsCatalogEditScriptID','EDIT');
        $key=str_starts_with($rowKey,'weather_')?substr($rowKey,strlen('weather_')):'';
        $d=IAHWeatherStation::source($catalog)['devices'][$key]??null;
        if (!is_array($d)) throw new RuntimeException('Wetterstation nicht in EDIT gefunden.');
        return [$key,$d];
    }

    private function mutateWeatherStation(string $rowKey,callable $change): void
    {
        [$key]=$this->weatherStationDevice($rowKey); [$id,$old,$catalog]=$this->systemTreeEditContext();
        $d=&$catalog['dataSources'][IAHWeatherStation::KEY]['devices'][$key];
        if (!is_array($d)) throw new RuntimeException('Wetterstation wurde inzwischen entfernt.');
        $change($d); unset($d); $this->saveSystemTreeEdit($id,$old,$catalog); $this->refreshSystemTreeCatalog();
    }

    public function SaveWeatherStationOverview(string $rowKey,string $title,string $aliases,bool $enabled): void
    {
        try {
            $title=IAHHomeConnect::clean($title);
            if ($title==='') throw new RuntimeException('Bitte einen Gerätenamen angeben.');
            $this->mutateWeatherStation($rowKey,static function(array &$d)use($title,$aliases,$enabled):void {
                $d['name']=$title; $d['enabled']=$enabled;
                $d['aliases']=array_values(array_unique(array_filter(array_map([IAHHomeConnect::class,'clean'],array_merge([$title],explode(',',$aliases))))));
            });
            echo 'Wetterstation in EDIT gespeichert. Für Alexa anschließend nach PROD übernehmen.';
        } catch (Throwable $e) { echo $e->getMessage(); }
    }

    private function weatherStationMetricRows(array $d): array
    {
        $rows=[]; $receptions=[];
        foreach ((array)$d['variables'] as $key=>$m) {
            $r=IAHWeatherStation::reading($m,$receptions);
            $rows[]=$m+['key'=>$key,'value'=>$r['value'],'quality'=>match($r['quality']){'ok'=>'OK','stale'=>'Veraltet','unconfirmed'=>'Aktualität unbestätigt',default=>'Nicht verfügbar'},
                'updated'=>isset($r['updated'])&&$r['updated']>0?date('d.m.Y H:i',(int)$r['updated']):'—'];
        }
        return $rows;
    }

    public function GetWeatherStationEditForm(string $rowKey): string
    {
        try {
            [, $d]=$this->weatherStationDevice($rowKey); $q=var_export($rowKey,true);
            $items=[
                ['type'=>'Label','caption'=>'Froggit/Ecowitt · ausschließlich lesende Messwerte. Verknüpfte Sensoren werden aufgelöst. Uhrzeit ist ausgeschlossen. Änderungen werden in EDIT gesichert.'],
                ['type'=>'ValidationTextBox','name'=>'title','caption'=>'Gerätename','value'=>$d['name']],
                ['type'=>'ValidationTextBox','name'=>'WeatherAliases','caption'=>'Alternative Namen (Komma)','value'=>implode(', ',(array)$d['aliases'])],
                ['type'=>'CheckBox','name'=>'kiEnabled','caption'=>'Wetterstation für Alexa freigeben','value'=>!empty($d['enabled'])],
                ['type'=>'List','name'=>'WeatherVariables','caption'=>'Alle Messwerte und Sensoren','rowCount'=>18,'add'=>false,'delete'=>false,'loadValuesFromConfiguration'=>false,
                    'columns'=>[
                        ['name'=>'key','visible'=>false],
                        ['name'=>'title','caption'=>'Name','width'=>'240px','quickFilter'=>true,'edit'=>['type'=>'ValidationTextBox']],
                        ['name'=>'ident','caption'=>'Kennung','width'=>'170px','save'=>false,'quickFilter'=>true],
                        ['name'=>'id','caption'=>'Variable','width'=>'100px','edit'=>['type'=>'SelectVariable']],
                        ['name'=>'role','caption'=>'Messgruppe','width'=>'100px','save'=>false],
                        ['name'=>'value','caption'=>'Wert mit Einheit','width'=>'270px','save'=>false],
                        ['name'=>'updated','caption'=>'Zuletzt gespeichert','width'=>'150px','save'=>false],
                        ['name'=>'quality','caption'=>'Datenstatus','width'=>'180px','save'=>false],
                        ['name'=>'enabled','caption'=>'KI lesen','width'=>'80px','edit'=>['type'=>'CheckBox']],
                    ],'values'=>$this->weatherStationMetricRows($d),
                    'onEdit'=>'RCC_SaveWeatherStationVariable($id, '.$q.', json_encode(["key"=>$WeatherVariables["key"],"id"=>$WeatherVariables["id"],"title"=>$WeatherVariables["title"],"enabled"=>(bool)$WeatherVariables["enabled"]]));'],
                ['type'=>'Label','caption'=>'Froggit speichert unveränderte Messwerte je nach Einstellung nicht erneut. Ein alter Speicherzeitpunkt allein beweist deshalb keinen Sensorausfall. Gateway-Empfang und Messwert-Aktualität werden getrennt bewertet.'],
            ];
        } catch (Throwable $e) { $items=[['type'=>'Label','caption'=>$e->getMessage()]]; }
        return json_encode($items,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    }

    public function SaveWeatherStationVariable(string $rowKey,string $json): void
    {
        try {
            $row=json_decode($json,true,32,JSON_THROW_ON_ERROR);
            $this->mutateWeatherStation($rowKey,static function(array &$d)use($row):void {
                $key=(string)($row['key']??'');
                if (!isset($d['variables'][$key])) throw new RuntimeException('Messwertzuordnung fehlt.');
                $m=IAHWeatherStation::metric((int)($row['id']??0),IAHHomeConnect::clean((string)($row['title']??'')));
                foreach ($d['variables'] as $otherKey=>$other) if ($otherKey!==$key&&(int)$other['id']===$m['id']) throw new RuntimeException('Variable ist bereits zugeordnet.');
                $m['enabled']=!empty($row['enabled']); $d['variables'][$key]=$m;
            });
            [, $d]=$this->weatherStationDevice($rowKey);
            $this->UpdateFormField('WeatherVariables','values',json_encode($this->weatherStationMetricRows($d),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            echo 'Messwert in EDIT gespeichert.';
        } catch (Throwable $e) { echo $e->getMessage(); }
    }

    private function buildWeatherStationPanel(): array
    {
        return ['type'=>'ExpansionPanel','caption'=>'WETTERSTATION – Froggit / Ecowitt','expanded'=>false,'items'=>[
            ['type'=>'Label','caption'=>'Alle installierten Froggit-Instanzen automatisch erfassen, einschließlich Zusatz-Gateways und verknüpfter Sensoren. Namen, Variablen und Lesefreigaben unter Geräte bearbeiten → Domain Wetterstation ändern. Uhrzeit wird nicht übernommen.'],
            ['type'=>'Button','caption'=>'WETTERSTATIONEN IN EDIT ABGLEICHEN','onClick'=>'RCC_SyncWeatherStations($id);'],
            ['type'=>'Label','caption'=>'Alexa: Wie ist das Wetter? · Wie stark ist der Wind? · Wie viel hat es heute geregnet? · Wie feucht ist die Hecke Ost? · Welche Batteriewerte meldet die Wetterstation? · Zeige alle Werte der 433Station.'],
        ]];
    }
}
