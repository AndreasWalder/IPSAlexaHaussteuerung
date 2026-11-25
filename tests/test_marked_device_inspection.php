<?php
declare(strict_types=1);
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/CatalogVariableBindingsTrait.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/MarkedDeviceInspectionTrait.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/SensorHealthCheckTrait.php';

function testCheck(bool $condition,string $message): void {
    if (!$condition)throw new RuntimeException($message);
}
function IPS_VariableExists(int $id): bool {return in_array($id,[60001,60003],true);}
function IPS_ScriptExists(int $id): bool {return $id===70001;}
function IPS_ObjectExists(int $id): bool {return in_array($id,[50000,60001,60003],true);}
function IPS_GetObject(int $id): array {
    return ['ParentID'=>50000,'ObjectName'=>'Sensor #'.$id];
}
function IPS_GetVariable(int $id): array {
    return $GLOBALS['healthVariables'][$id]??['VariableType'=>2,'VariableProfile'=>'~Temperature'];
}
function GetValue(int $id): mixed {return $GLOBALS['healthValues'][$id]??21.5;}
function IPS_VariableProfileExists(string $name): bool {return true;}
function IPS_GetName(int $id): string {return 'Sensor #'.$id;}
function IPS_LogMessage(string $sender,string $message): void {}
function AC_GetAggregationVariables(int $id): array {return [];}
function AC_GetAggregatedValues(int $archive,int $id,int $level,int $start,int $end,int $limit): array {
    $GLOBALS['healthArchiveCalls']=($GLOBALS['healthArchiveCalls']??0)+1;
    return array_map(static fn($i)=>[
        'Min'=>21.5,'Max'=>21.5,'TimeStamp'=>time()-$i*86400,'Count'=>4
    ],[1,2,3,4,5]);
}
class InspectionHarness {
    use RCCCatalogVariableBindingsTrait;
    use RCCMarkedDeviceInspectionTrait;
    use RCCSensorHealthCheckTrait;
    public array $attributes=['OverviewSourceSelections'=>'{}'];
    public array $updates=[];
    public array $catalog=['dataSources'=>[]];
    public array $runtime=[];
    public function ReadAttributeString(string $name): string {return $this->attributes[$name]??'';}
    public function WriteAttributeString(string $name,string $v): void {$this->attributes[$name]=$v;}
    public function UpdateFormField(string $name,string $property,mixed $v): void {
        $this->updates[$name.'.'.$property]=$v;
    }
    private function systemTreeArchive(): array {
        return [90000,[60001=>['AggregationActive'=>true]]];
    }
    private function ensureRuntimeCatalogCurrent(): void {}
    private function getRuntimeEntries(): array {return $this->runtime;}
    private function bulkSelectionKeys(): array {
        return array_map(fn($r)=>$this->buildCompositeKey($r),
            array_values(array_filter($this->runtime,fn($r)=>!empty($r['selected']))));
    }
    private function buildCompositeKey(array $row): ?string {
        return implode('|',array_map(fn($key)=>(string)($row[$key]??''),
            ['roomKey','domain','group','key']));
    }
    private function loadConfiguredCatalog(string $prop,string $type): array {return $this->catalog;}
    private function overviewSourceRows(array $catalog): array {
        $key='__irrigation_source__|bewaesserung|anlage|irrigation_52811';
        return [['roomKey'=>'__irrigation_source__','domain'=>'bewaesserung',
            'group'=>'anlage','key'=>'irrigation_52811','title'=>'IPSBewaesserung',
            'sourceKey'=>'irrigation_52811','selected'=>true]];
    }
}
$h=new InspectionHarness();
$h->runtime=[['selected'=>true,'roomKey'=>'eg','domain'=>'licht','group'=>'switches',
    'key'=>'lamp','title'=>'Lamp','controlId'=>60001,'statusId'=>60002]];
$h->RunMarkedDeviceInspection(false);
$r=json_decode($h->ReadAttributeString('MarkedInspectionResult'),true,512,JSON_THROW_ON_ERROR);
testCheck($r['selected']===1 && $r['references']===2 && $r['issues']===1,
    'Markierte normale Geräte müssen defekte Variablen finden');
testCheck($r['rows'][0]['variableId']===60002,
    'Die fehlende Variable muss im Bericht stehen');
$h->runtime=[];
$h->catalog['dataSources']['irrigation_52811']=[
    'provider'=>'ObjectTreeIrrigation','points'=>[
        60001=>['id'=>60001,'role'=>'stationTemperature'],
        60002=>['id'=>60002,'role'=>'stationTemperature']]];
$h->WriteAttributeString('OverviewSourceSelections',
    '{"__irrigation_source__|bewaesserung|anlage|irrigation_52811":true}');
$h->RunMarkedDeviceInspection(false);
$r=json_decode($h->ReadAttributeString('MarkedInspectionResult'),true,512,JSON_THROW_ON_ERROR);
testCheck($r['selected']===1 && $r['issues']>=1,
    'Markierte virtuelle Bewässerungsanlagen müssen geprüft werden');
testCheck(str_contains($r['summary'],'IP')===false || $r['references']>=2,
    'Bewässerungspunkte müssen in die technische Prüfung einfließen');
testCheck($r['variables']===2,
    'Each physical irrigation variable must be checked only once');
$h->catalog['dataSources']['irrigation_52811']=[
    'provider'=>'ObjectTreeIrrigation',
    'points'=>[
        60001=>['id'=>60001,'role'=>'stationTemperature','archiveMode'=>'none'],
        60002=>['id'=>60002,'role'=>'stationTemperature']
    ],
    'scripts'=>[
        70001=>['id'=>70001,'name'=>'Existing script'],
        70002=>['id'=>70002,'name'=>'Missing script']
    ],
    'actuators'=>[52811=>['AktorID1'=>60001]]
];
$h->RunMarkedDeviceInspection(false);
$r=json_decode($h->ReadAttributeString('MarkedInspectionResult'),true,512,JSON_THROW_ON_ERROR);
testCheck($r['variables']===2 && $r['scripts']===2,
    'Duplicated sensor paths and script IDs must remain distinct');
testCheck(count(array_filter($r['rows'],static fn(array $row)=>
    (int)$row['variableId']===60002))===1,
    'Missing variable must appear only once');
testCheck(count(array_filter($r['rows'],static fn(array $row)=>
    (int)$row['variableId']===70002 && $row['field']==='Skript'))===1,
    'Missing script must be checked as script, not variable');
testCheck(count(array_filter($r['rows'],static fn(array $row)=>
    (int)$row['variableId']===70001))===0,
    'Existing script should not appear as a missing variable');

// Health mode: check freshness only with a declared expected interval.
$h->runtime=[];
$h->catalog['dataSources']['irrigation_52811']=[
    'provider'=>'ObjectTreeIrrigation','points'=>[
        60001=>['id'=>60001,'role'=>'stationTemperature',
            'expectedIntervalSeconds'=>3600],
        60003=>['id'=>60003,'role'=>'sensorBattery','unit'=>'%']
    ]];
$GLOBALS['healthVariables']=[
    60001=>['VariableType'=>2,'VariableProfile'=>'~Temperature',
        'VariableUpdated'=>time()-7200],
    60003=>['VariableType'=>1,'VariableProfile'=>'~Battery',
        'VariableUpdated'=>time()-120]
];
$GLOBALS['healthValues']=[60001=>21.5,60003=>12];
$h->RunMarkedDeviceInspection(false,true);
$health=json_decode($h->ReadAttributeString('MarkedInspectionResult'),true,512,JSON_THROW_ON_ERROR);
testCheck($health['health']===true && $health['healthChecked']===2,
    'Health check must assess each selected distinct variable');
testCheck(count(array_filter($health['rows'],static fn($r)=>
    (int)($r['variableId']??0)===60001
    && str_contains($r['finding'],'Aktualisierung')))===1,
    'Stale active temperature must be flagged with its configured interval');
testCheck(count(array_filter($health['rows'],static fn($r)=>
    (int)($r['variableId']??0)===60003
    && str_contains($r['finding'],'Batteriestand')))===1,
    'Low battery must be flagged with actionable explanation');
testCheck(($health['healthArchiveQueries']??0)===1,
    'Only eligible continuously measured active sensors should query archive trends');
testCheck(($GLOBALS['healthArchiveCalls']??0)===1,
    'Archived temperature must use only one bounded daily aggregation request');

$h->catalog['dataSources']['irrigation_52811']['points'][60001]['role']='zoneStatus';
$h->catalog['dataSources']['irrigation_52811']['points'][60001]['expectedIntervalSeconds']=0;
$h->catalog['dataSources']['irrigation_52811']['points'][60003]['role']='zoneStatus';
$GLOBALS['healthVariables'][60001]['VariableType']=0;
$GLOBALS['healthVariables'][60003]['VariableType']=0;
$h->RunMarkedDeviceInspection(false,true);
$switches=json_decode($h->ReadAttributeString('MarkedInspectionResult'),true,512,JSON_THROW_ON_ERROR);
testCheck($switches['issues']===0,
    'Seasonal switch values without configured freshness must not raise health alarms');
testCheck(($switches['healthArchiveQueries']??-1)===0,
    'Boolean and seasonal switches must skip expensive trend analysis');

echo "PASS marked device inspection\n";
