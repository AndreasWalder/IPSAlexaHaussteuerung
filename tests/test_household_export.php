<?php
declare(strict_types=1);
require __DIR__.'/test_home_connect_export.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/HouseholdEditorTrait.php';

$GLOBALS['objects']=[10=>['ParentID'=>0,'ObjectType'=>0,'ObjectName'=>'AEG'],
    11=>['ParentID'=>0,'ObjectType'=>0,'ObjectName'=>'Liebherr']];
$GLOBALS['instances']=$GLOBALS['variables']=$GLOBALS['values']=[];
foreach ([20=>'Waschtrockner',30=>'Gefrierschrank',40=>'Kühlschrank'] as $id=>$name) {
    $GLOBALS['objects'][$id]=['ParentID'=>$id===20?10:11,'ObjectType'=>1,'ObjectName'=>$name,'ObjectIdent'=>'Appliance_'.$id];
    $GLOBALS['instances'][$id]=['ModuleInfo'=>['ModuleID'=>'HOME_ASSISTANT_DEVICE','ModuleName'=>'Home Assistant Device'],'InstanceStatus'=>102];
}
hcVar(201,'DryingTime',65535,0,20);
hcVar(202,'OperationState','BSH.Common.EnumType.OperationState.Run',0,20);
hcVar(203,'RemoteControl',false,1,20);
hcVar(301,'Temperature',-18.0,0,30);
hcVar(401,'Temperature',5.0,0,40);
hcVar(402,'Target',5,0,40);
hcVar(403,'AccessToken','PRIVATE_HA_TOKEN',0,40);
hcVar(404,'WebPanel','https://PRIVATE_HA_HOST/token',0,40);
// A nested device component carries its own module identity and variables.
$GLOBALS['objects'][45]=['ParentID'=>40,'ObjectType'=>1,'ObjectName'=>'Kühlteil'];
$GLOBALS['instances'][45]=['ModuleInfo'=>['ModuleID'=>'COMPONENT'],'InstanceStatus'=>102];
hcVar(405,'SuperCool',false,0,45);
$before=serialize([$GLOBALS['objects'],$GLOBALS['instances'],$GLOBALS['variables'],$GLOBALS['values']]);
foreach ([20=>3,30=>1,40=>5] as $root=>$count) {
    $report=RCCHouseholdExport::scan($root);
    hcCheck($report['complete']&&$report['variableCount']===$count&&$report['domain']==='hausgeraete',
        'Manufacturer-neutral export reads individual devices from separate categories');
    hcCheck($report['root']['moduleId']==='HOME_ASSISTANT_DEVICE'&&$report['devices'][0]['id']===$root,
        'Device grouping preserves the selected instance and installed module identity');
}
$washer=RCCHouseholdExport::scan(20);
$vars=array_column($washer['devices'][0]['variables'],null,'ident');
hcCheck($vars['DryingTime']['rawValue']===65535&&$vars['DryingTime']['formattedValue']==='65535',
    'Unclassified sentinel values are retained exactly for later interpretation');
hcCheck(count($vars['OperationState']['profile']['Associations'])===2&&$vars['OperationState']['hasAction'],
    'Profiles, raw enum codes, labels and available actions are exported');
hcCheck($vars['RemoteControl']['rawValue']===false&&$vars['RemoteControl']['actionDisabled'],
    'False values and explicitly disabled actions remain distinct');
$fridge=RCCHouseholdExport::scan(40);
$objectMap=array_column($fridge['devices'][0]['objects'],null,'id');
hcCheck($objectMap[45]['parentId']===40&&$objectMap[45]['moduleId']==='COMPONENT'
    &&$objectMap[405]['parentId']===45,'Nested components retain their relationships');
$json=json_encode($fridge,JSON_THROW_ON_ERROR);
hcCheck(!str_contains($json,'PRIVATE_HA_TOKEN')&&!str_contains($json,'PRIVATE_HA_HOST'),'Shared sensitive-value filtering applies to all providers');
hcCheck(RCCHouseholdExport::scan(301)['variableCount']===1,'A single variable is also a valid root');
hcCheck(RCCHouseholdExport::scan(11)['variableCount']===6,'A selected category includes all descendant device data');
hcCheck($before===serialize([$GLOBALS['objects'],$GLOBALS['instances'],$GLOBALS['variables'],$GLOBALS['values']]),
    'Export never moves objects, changes values or publishes catalog permissions');

final class HouseholdExportFixture
{
    use RCCHouseholdEditorTrait;
    public array $updates=[];
    public function UpdateFormField(string $name,string $field,string|int|bool|float $value):void{$this->updates[$name][$field]=$value;}
    public function panel():array{return $this->buildHouseholdPanel();}
}
$exporter=new HouseholdExportFixture();
ob_start();$exporter->ExportHouseholdJson(40);ob_end_clean();
hcCheck(json_decode($exporter->updates['HouseholdExportJson']['value'],true)['variableCount']===5,
    'The form export works before any catalog exists and passes only native scalar values');
ob_start();$exporter->ExportHouseholdJson(999);ob_end_clean();
hcCheck($exporter->updates['HouseholdExportJson']['value']==='','Invalid root clears the previous device export');
$panel=json_encode($exporter->panel());
hcCheck(str_contains($panel,'RCC_ExportHouseholdJson($id, (int)$HouseholdNewRoot)')
    &&str_contains($panel,'HouseholdExportJson'),'Household panel shares its object selector with the provider-neutral export');
for ($id=1000;$id<1510;$id++) hcVar($id,'LimitValue_'.$id,1,0,20);
hcCheck(!RCCHouseholdExport::scan(20)['complete'],'Large inventories explicitly report incomplete data');
echo "PASS generic household export for distributed Home Assistant devices, values, profiles, permissions, relationships, limits and form\n";
