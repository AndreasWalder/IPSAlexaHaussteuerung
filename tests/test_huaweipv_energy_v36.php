<?php
declare(strict_types=1);

/** V3.6: HuaweiPV under Dummy Modules -> one catalog dataSource -> safe KI. */
class IPSModule
{
    public array $properties=[];
    public array $updates=[];
    private array $attrs=[];
    public function ReadPropertyInteger(string $key):int{return (int)($this->properties[$key]??0);}
    public function ReadPropertyString(string $key):string{return (string)($this->properties[$key]??'');}
    public function ReadPropertyBoolean(string $key):bool{return (bool)($this->properties[$key]??false);}
    public function ReadAttributeString(string $key):string{return (string)($this->attrs[$key]??'');}
    public function WriteAttributeString(string $key,string $value):void{$this->attrs[$key]=$value;}
    public function UpdateFormField(string $name,string $field,mixed $value):void{$this->updates[$name.'.'.$field]=$value;}
    public function ReloadForm():void{}
}
$GLOBALS['h36dir']=sys_get_temp_dir().'/huawei-v36-'.bin2hex(random_bytes(5));
mkdir($GLOBALS['h36dir'].'/scripts',0700,true);
$GLOBALS['h36Scripts']=[];
$GLOBALS['h36Parents']=[40=>1000,41=>1000];
$GLOBALS['h36Names']=[40=>'RoomsCatalog',41=>'RoomsCatalogEdit',59140=>'Huawei Sun 2000 PV'];
$GLOBALS['h36NextId']=9000;
$GLOBALS['h36Reads']=[];
$GLOBALS['h36Archive']=[];
$GLOBALS['h36Objects']=[
    59140=>['ObjectType'=>1,'ObjectName'=>'Huawei Sun 2000 PV','ObjectParentID'=>0],
    54287=>['ObjectType'=>1,'ObjectName'=>'PV-Anlage','ObjectParentID'=>59140],
    37913=>['ObjectType'=>1,'ObjectName'=>'Akku','ObjectParentID'=>59140],
    54371=>['ObjectType'=>1,'ObjectName'=>'Netz & Smartmeter','ObjectParentID'=>59140],
    32400=>['ObjectType'=>1,'ObjectName'=>'Verbrauch & Bilanz','ObjectParentID'=>59140],
    13371=>['ObjectType'=>1,'ObjectName'=>'Diagnose','ObjectParentID'=>59140],
];
$GLOBALS['h36Values']=[];
$GLOBALS['h36Variables']=[];
$add=static function(int $id,int $parent,string $ident,string $label,int $vt,mixed $value,int $action=0):void{
    $GLOBALS['h36Objects'][$id]=['ObjectType'=>2,'ObjectParentID'=>$parent,
        'ObjectName'=>$label,'ObjectIdent'=>$ident];
    $GLOBALS['h36Variables'][$id]=['VariableType'=>$vt,'VariableAction'=>$action,
        'VariableCustomAction'=>0,'VariableProfile'=>'','VariableCustomProfile'=>'',
        'VariableUpdated'=>time()-11];
    $GLOBALS['h36Values'][$id]=$value;
};
$add(56417,54287,'PVPower','PV-Leistung',2,1200.0);
$add(51669,54287,'PVPowerKW','PV-Leistung kW',2,1.2);
$add(25510,54287,'DailyYield','PV-Ertrag heute',2,8.26);
$add(46930,54287,'TotalYield','PV-Ertrag gesamt',2,36423.0);
$add(70005,37913,'BatterySOC','Akku Ladestand',2,86.5);
$add(70006,54371,'GridPower','Netzleistung',2,-420.0);
$add(70007,32400,'HouseConsumption','Hausverbrauch',2,1620.0);
$add(70008,13371,'Online','Online',0,true);
$add(41877,59140,'WorkingModeSetting','Akku Betriebsart Einstellung',1,2,59140);
$add(70101,59140,'SerialNumber','Seriennummer',3,'PRIVATE_SERIAL');
$add(70102,59140,'ExperimentalSecret','Unbekannte Variable',3,'PRIVATE_TOKEN');
function IPS_InstanceExists(int $id):bool{return in_array($id,[59140,17597],true);}
function IPS_GetInstance(int $id):array{
    return ['ModuleInfo'=>['ModuleID'=>$id===59140
        ?'{7C3D7EE3-1DE2-4C16-9BC4-AC1F091695E4}'
        :'{43192F0B-135B-4CE7-A0A7-1475603F3060}']];
}
function IPS_GetProperty(int $id,string $prop):int{
    return match($prop){'GridSign'=>0,'BatterySign'=>1,default=>0};
}
function IPS_ScriptExists(int $id):bool{return isset($GLOBALS['h36Scripts'][$id]);}
function IPS_GetScriptFile(int $id):string{return $id.'.php';}
function IPS_GetKernelDir():string{return $GLOBALS['h36dir'].'/';}
function IPS_SetScriptContent(int $id,string $s):bool{
    $GLOBALS['h36Scripts'][$id]=$s;
    file_put_contents($GLOBALS['h36dir'].'/scripts/'.$id.'.php',$s);
    return true;
}
function IPS_GetScriptContent(int $id):string{return $GLOBALS['h36Scripts'][$id]??'';}
function IPS_CreateScript(int $type):int{$id=++$GLOBALS['h36NextId'];IPS_SetScriptContent($id,'');return $id;}
function IPS_GetParent(int $id):int{return $GLOBALS['h36Parents'][$id]??1000;}
function IPS_SetParent(int $id,int $parent):bool{$GLOBALS['h36Parents'][$id]=$parent;return true;}
function IPS_SetName(int $id,string $name):bool{$GLOBALS['h36Names'][$id]=$name;return true;}
function IPS_GetName(int $id):string{return $GLOBALS['h36Names'][$id]??($GLOBALS['h36Objects'][$id]['ObjectName']??'');}
function IPS_ObjectExists(int $id):bool{return isset($GLOBALS['h36Objects'][$id]);}
function IPS_GetObject(int $id):array{return $GLOBALS['h36Objects'][$id]??[];}
function IPS_GetChildrenIDs(int $parent):array{
    if($parent===1000)return array_keys(array_filter($GLOBALS['h36Parents'],
        static fn(int $x):bool=>$x===1000));
    return array_keys(array_filter($GLOBALS['h36Objects'],
        static fn(array $o):bool=>(int)($o['ObjectParentID']??0)===$parent));
}
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['h36Variables'][$id]);}
function IPS_GetVariable(int $id):array{return $GLOBALS['h36Variables'][$id]??[];}
function GetValue(int $id):mixed{$GLOBALS['h36Reads'][]=$id;return $GLOBALS['h36Values'][$id]??null;}
function GetValueFormatted(int $id):string{return (string)($GLOBALS['h36Values'][$id]??'');}
function IPS_GetVariableProfile(string $profile):array{return ['Associations'=>[]];}
function IPS_LogMessage(string $a,string $b):void{}
function AC_GetAggregationVariables(int $archiveId,bool $all):array{
    return [['VariableID'=>46930,'AggregationActive'=>true]];
}
function AC_GetLoggedValues(int $archiveId,int $varId,int $from,int $to,int $limit):array{
    $GLOBALS['h36Archive'][]=['archive'=>$archiveId,'id'=>$varId,'from'=>$from,'to'=>$to];
    if($varId!==46930)return [];
    $first=(int)strtotime('first day of last month 00:00');
    $end=(int)strtotime('first day of this month 00:00')-1;
    $samples=[
        ['TimeStamp'=>$end,'Value'=>36423.0],
        ['TimeStamp'=>$first+3600,'Value'=>36421.0],
        ['TimeStamp'=>$first-3600,'Value'=>36420.0]
    ];
    return array_slice(array_values(array_filter($samples,
        static fn(array $s):bool=>$s['TimeStamp']>=$from && $s['TimeStamp']<=$to)),0,$limit);
}
function AC_GetAggregatedValues(int $archiveId,int $id,int $stage,int $from,int $to,int $count):array{
    return [['TimeStamp'=>time()-86400,'Avg'=>1000.0,'Min'=>0,'Max'=>3000.0]];
}
function check36(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('V3.6 '.$message);
}
$catalog=['buero'=>['display'=>'Büro','domains'=>['licht'=>['switches'=>[
    'decke'=>['title'=>'Licht','state'=>56417]]]]]];
$prod=$catalog;
$source=static fn(array $data):string=>"<?php\nreturn ".var_export($data,true).";\n";
IPS_SetScriptContent(40,$source($prod));
IPS_SetScriptContent(41,$source($catalog));
require dirname(__DIR__).'/RoomsCatalogConfigurator/module.php';
$scan=RCCModuleDataSources::scanHuawei(59140);
check36(count($scan['metrics'])===9,'must traverse Dummy topic folders and standalone settings');
check36(isset($scan['metrics']['PVPower']) && $scan['metrics']['PVPower']['id']===56417
    && !isset($scan['metrics']['PVPowerKW']),'canonical W metric, no duplicate KW');
check36(!isset($scan['metrics']['SerialNumber'])
    && !isset($scan['metrics']['ExperimentalSecret']),'no sensitive/unknown variables');
check36($scan['metrics']['WorkingModeSetting']['writableInIPSymcon']===true
    && $scan['metrics']['WorkingModeSetting']['readOnly']===true
    && $scan['allowWrites']===false,'controls cannot be authorized for AI writing');
check36($scan['signConventions']['batterySign']===1,'battery direction from Huawei module');
$m=new RoomsCatalogConfigurator();
$m->properties=['RoomsCatalogScriptID'=>40,'RoomsCatalogEditScriptID'=>41,
    'HuaweiDataSourceInstanceId'=>59140];
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($m);
$before=IPS_GetScriptContent(41);
ob_start();$m->PreviewHuaweiDataSource();$preview=ob_get_clean();
check36(str_contains($preview,'VORSCHAU') && IPS_GetScriptContent(41)===$before,
    'preview is read-only');
$report=json_decode($m->ReadAttributeString('HuaweiDataSourceReport'),true);
check36(($report['found']??0)===9
    && ($report['metrics'][0]['id']??0)>0,'preview lists source-specific metrics');
ob_start();$m->ImportHuaweiDataSourceToEdit();$import=ob_get_clean();
check36(str_contains($import,'HUAWEIPV IMPORTIERT')
    && IPS_GetScriptContent(40)===$source($prod),'EDIT-only import must preserve PROD');
$edit=require $GLOBALS['h36dir'].'/scripts/41.php';
check36($edit['buero']===$catalog['buero']
    && isset($edit['dataSources']['huawei_59140']['metrics']['TotalYield']),
    'existing room and new source must coexist');
check36(IPS_GetScriptContent(9001)===$before,'original EDIT backed up before writing');
ob_start();$m->ImportHuaweiDataSourceToEdit();$replay=ob_get_clean();
check36(str_contains($replay,'BLOCKIERT'),'preview cannot be reused');
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
check36(count(iah_rc_index(41))===1,'normal rooms index must not treat source as a room');
$energy=iah_energy_sources(41);
check36(count($energy)===1 && count($energy['huawei_59140']['metrics'])===9,
    'dynamic data source must be indexed as one entity');
$current=iah_energy_query($energy,['metric'=>'PVPower','operation'=>'current'],17597);
check36(($current['reading']['value']??0)===1200.0
    && $current['reading']['unit']==='W','live power correctly read');
$battery=iah_energy_query($energy,['metric'=>'BatteryPower'],17597);
check36(($battery['error']??'')==='unknown_metric','cannot invent missing measurement');
$overview=iah_energy_query($energy,['operation'=>'overview'],17597);
check36(($overview['ok']??false)===true && count($overview['values'])>=5,
    'natural-language PV overview includes known measurements');
$grid=iah_energy_query($energy,['metric'=>'GridPower'],17597);
check36(($grid['reading']['direction']??'')==='Netzbezug','Huawei grid sign is interpreted');
$delta=iah_energy_query($energy,['metric'=>'DailyYield','operation'=>'delta','period'=>'last_month'],17597);
check36(($delta['ok']??false) && ($delta['metric']??'')==='TotalYield'
    && abs(($delta['value']??0)-3.0)<0.001,
    'monthly production must use counter delta and archived boundary readings');
$bad=iah_energy_query($energy,['metric'=>'BatterySOC','operation'=>'delta','period'=>'last_month'],17597);
check36(($bad['error']??'')==='not_archived','missing archive must not be fabricated');
check36(count(array_unique(array_column($GLOBALS['h36Archive'],'archive')))===1
    && $GLOBALS['h36Archive'][0]['archive']===17597,
    'archive queries use only configured Archive Control');
$edit['dataSources']['huawei_59140']['kiEnabled']=false;
IPS_SetScriptContent(41,$source($edit));
check36(iah_energy_sources(41)===[],'disabling source removes all KI access');
foreach(array_keys($GLOBALS['h36Scripts']) as $id)@unlink($GLOBALS['h36dir'].'/scripts/'.$id.'.php');
@rmdir($GLOBALS['h36dir'].'/scripts');@rmdir($GLOBALS['h36dir']);
echo "PASS V3.6: HuaweiPV source #59140, 9 canonical metrics, EDIT backup, live data, Archive #17597, strict read-only\n";
