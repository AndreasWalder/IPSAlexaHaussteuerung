<?php
declare(strict_types=1);
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/CatalogVariableBindingsTrait.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/MarkedDeviceInspectionTrait.php';

function testCheck(bool $condition,string $message): void {
    if (!$condition)throw new RuntimeException($message);
}
function IPS_VariableExists(int $id): bool {return in_array($id,[60001,60003],true);}
function IPS_ObjectExists(int $id): bool {return in_array($id,[50000,60001,60003],true);}
function IPS_GetObject(int $id): array {
    return ['ParentID'=>50000,'ObjectName'=>'Sensor #'.$id];
}
function IPS_GetVariable(int $id): array {
    return ['VariableType'=>2,'VariableProfile'=>'~Temperature'];
}
function IPS_VariableProfileExists(string $name): bool {return true;}
function IPS_GetName(int $id): string {return 'Sensor #'.$id;}
function IPS_LogMessage(string $sender,string $message): void {}
class InspectionHarness {
    use RCCCatalogVariableBindingsTrait;
    use RCCMarkedDeviceInspectionTrait;
    public array $attributes=['OverviewSourceSelections'=>'{}'];
    public array $updates=[];
    public array $catalog=['dataSources'=>[]];
    public array $runtime=[];
    public function ReadAttributeString(string $name): string {return $this->attributes[$name]??'';}
    public function WriteAttributeString(string $name,string $v): void {$this->attributes[$name]=$v;}
    public function UpdateFormField(string $name,string $property,mixed $v): void {
        $this->updates[$name.'.'.$property]=$v;
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
echo "PASS marked device inspection\n";
