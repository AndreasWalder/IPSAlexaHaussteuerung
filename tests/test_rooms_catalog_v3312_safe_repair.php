<?php
declare(strict_types=1);
require __DIR__.'/../RoomsCatalogConfigurator/libs/DeviceTechnicalAudit.php';
require __DIR__.'/../RoomsCatalogConfigurator/libs/SafeAutoRepair.php';
function ck312(bool $ok,string $message):void {if (!$ok) throw new RuntimeException($message);}

$blind=['aiType'=>'blind','ai'=>[
    'type'=>'blind','variables'=>['action'=>300],
    'review'=>['status'=>'pending']
]];
$score=static fn(array $var,string $role):int=>
    $role==='position' && ($var['name']??'')==='Istposition' ? 150 :
    ($role==='status' && ($var['name']??'')==='Status' ? 145 : 20);
$scoped=[
    ['id'=>300,'type'=>1,'name'=>'Value','ident'=>'Value','profile'=>'Rolladen_Steuerung','action'=>true],
    ['id'=>301,'type'=>1,'name'=>'Istposition','ident'=>'Istposition','profile'=>'ShutterPosition','action'=>false]
];
$proposal=RCCSafeAutoRepair::propose($blind,$scoped,$score);
ck312($proposal===['position'=>301],'Real position readback must be recognized without reusing the command');
ck312(RCCSafeAutoRepair::propose($blind,[$scoped[0]],$score)===[],
    'A single KNX shutter command must never be invented as feedback');
$ambiguous=$scoped;
$ambiguous[]=['id'=>302,'type'=>1,'name'=>'Istposition','ident'=>'Istposition','profile'=>'ShutterPosition','action'=>false];
ck312(RCCSafeAutoRepair::propose($blind,$ambiguous,$score)===[],
    'Two equal position candidates must not be guessed');
$confirmed=$blind;
$confirmed['ai']['review']['status']='confirmed';
ck312(RCCSafeAutoRepair::propose($confirmed,$scoped,$score)===[],
    'Previously confirmed devices must not be silently remapped');

$catalog=['buero'=>['display'=>'Buero','domains'=>['jalousie'=>['fenster'=>[
    'title'=>'Fenster','kiEnabled'=>true,'kiReadOnly'=>true,
    'wert'=>300,'customField'=>'keep','ai'=>$blind['ai']
]]]]];
$next=RCCSafeAutoRepair::applyBindings($catalog,['buero|jalousie|jalousie|fenster'=>$proposal]);
ck312(($next['buero']['domains']['jalousie']['fenster']['ai']['variables']['position']??null)===301,
    'Missing typed binding was not inserted');
ck312(($next['buero']['domains']['jalousie']['fenster']['wert']??null)===300
    && ($next['buero']['domains']['jalousie']['fenster']['kiReadOnly']??null)===true
    && ($next['buero']['domains']['jalousie']['fenster']['customField']??null)==='keep'
    && ($next['buero']['domains']['jalousie']['fenster']['ai']['review']['status']??'')==='pending',
    'Repair must preserve original fields, permissions and review status');
ck312($catalog['buero']['domains']['jalousie']['fenster']['ai']['variables']===['action'=>300],
    'Repair must not modify input by reference');

$heater=['aiType'=>'heating','aiTemperatureId'=>111,'aiTargetTemperatureId'=>112,'aiControlId'=>112];
$fieldMap=['temperature'=>'aiTemperatureId','targetTemperature'=>'aiTargetTemperatureId','control'=>'aiControlId'];
$ips=[
    ['id'=>111,'type'=>2,'profile'=>'~Temperature.Room','name'=>'Isttemperatur',
        'ident'=>'Ist','action'=>false],
    ['id'=>112,'type'=>2,'profile'=>'~Temperature.Room','name'=>'Soll',
        'ident'=>'Value','action'=>true]
];
$quality=static fn(array $v,string $role):int=>$role==='control' ? 20 : 145;
$exists=static fn(int $id):bool=>in_array($id,[111,112],true);
$audit=RCCDeviceTechnicalAudit::assess(
    $heater,$fieldMap,$ips,[['temperature']],$quality,$exists);
ck312($audit['status']==='Technisch plausibel',
    'Writable KNX targetTemperature/control shared ID must not be a false warning: '.json_encode($audit));
$ips[1]['action']=false;
$unsafe=RCCDeviceTechnicalAudit::assess(
    $heater,$fieldMap,$ips,[['temperature']],$quality,$exists);
ck312($unsafe['status']==='Manuell pruefen'
    && in_array('no_action',$unsafe['issueCodes'],true),
    'Missing IPS write action must still block a heating control role');
$source=file_get_contents(__DIR__.'/../RoomsCatalogConfigurator/module.php');
ck312((RCCDeviceTypeRegistry::propertyCaptions()['illuminance'] ?? '') === 'Beleuchtungsstaerke (Lux)'
    && str_contains($source,'RCC_ExportAIRepairDiagnostics')
    && !str_contains($source,'RCC_ApplySafeAutoRepair')
    && str_contains($source,"'download'=>'RoomsCatalog_ChatGPT_Diagnose.json'"),
    'Diagnostics and technical assessment stay; obsolete PROD auto-correction is retired');
echo "PASS V3.3.12: safe metadata, corrected edit form, diagnostic export with one-off PROD repair retired\n";
