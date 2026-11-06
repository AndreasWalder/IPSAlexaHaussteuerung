<?php
declare(strict_types=1);

/** V3.4.9: gefuehrter Baumsuch-Import: Bewegungssensoren aus KNX inklusive Einzelvariablen. */
class IPSModule
{
    public array $properties=[];
    public array $updates=[];
    private array $attrs=[];
    public function ReadPropertyInteger(string $key):int{return (int)($this->properties[$key]??0);}
    public function ReadPropertyString(string $key):string{return (string)($this->properties[$key]??'');}
    public function ReadPropertyBoolean(string $key):bool{return (bool)($this->properties[$key]??false);}
    public function ReadAttributeString(string $key):string{return (string)($this->attrs[$key]??'');}
    public function WriteAttributeString(string $key,string $val):void{$this->attrs[$key]=$val;}
    public function UpdateFormField(string $name,string $field,mixed $value):void{
        $this->updates[$name.'.'.$field]=$value;
    }
    public function ReloadForm():void{throw new RuntimeException('Do not collapse guided import form');}
}
$GLOBALS['g349Root']=sys_get_temp_dir().'/rcc-v349-'.bin2hex(random_bytes(6));
mkdir($GLOBALS['g349Root'].'/scripts',0700,true);
$GLOBALS['g349Scripts']=[];
$GLOBALS['g349Objects']=[
  100=>['ObjectType'=>0,'ParentID'=>0,'ObjectName'=>'KNX'],
  110=>['ObjectType'=>0,'ParentID'=>100,'ObjectName'=>'Sicherheit'],
  120=>['ObjectType'=>0,'ParentID'=>110,'ObjectName'=>'Bewegung'],
  130=>['ObjectType'=>0,'ParentID'=>120,'ObjectName'=>'Freie Melder'],
  2001=>['ObjectType'=>1,'ParentID'=>120,'ObjectName'=>'EG Büro Bewegung'],
  51001=>['ObjectType'=>2,'ParentID'=>2001,'ObjectName'=>'Value','ObjectIdent'=>'Value'],
  2002=>['ObjectType'=>1,'ParentID'=>120,'ObjectName'=>'EG Küche hinten Bewegung'],
  51002=>['ObjectType'=>2,'ParentID'=>2002,'ObjectName'=>'Bewegung','ObjectIdent'=>'Motion'],
  2003=>['ObjectType'=>1,'ParentID'=>120,'ObjectName'=>'EG Büro bekannte Bewegung'],
  51003=>['ObjectType'=>2,'ParentID'=>2003,'ObjectName'=>'Bewegung','ObjectIdent'=>'State'],
  53001=>['ObjectType'=>2,'ParentID'=>130,'ObjectName'=>'EG Büro Einzel Bewegung','ObjectIdent'=>'Motion'],
  53002=>['ObjectType'=>2,'ParentID'=>130,'ObjectName'=>'EG Küche Einzel Bewegung','ObjectIdent'=>'Motion']
];
$GLOBALS['g349Vars']=[];
foreach([51001,51002,51003,53001,53002] as $v) {
    $GLOBALS['g349Vars'][$v]=['VariableType'=>0,'VariableProfile'=>'~Motion',
        'VariableCustomProfile'=>'','VariableAction'=>0,'VariableCustomAction'=>0,
        'VariableChanged'=>1793990000,'VariableUpdated'=>1793990100];
}
function IPS_ScriptExists(int $id):bool{return isset($GLOBALS['g349Scripts'][$id]);}
function IPS_GetKernelDir():string{return $GLOBALS['g349Root'].'/';}
function IPS_GetScriptFile(int $id):string{return $id.'.php';}
function IPS_SetScriptContent(int $id,string $source):bool{
    $GLOBALS['g349Scripts'][$id]=$source;
    file_put_contents($GLOBALS['g349Root'].'/scripts/'.$id.'.php',$source);
    return true;
}
function IPS_GetScriptContent(int $id):string{return (string)($GLOBALS['g349Scripts'][$id]??'');}
function IPS_ObjectExists(int $id):bool{return isset($GLOBALS['g349Objects'][$id]);}
function IPS_GetObject(int $id):array{return $GLOBALS['g349Objects'][$id]??[];}
function IPS_GetChildrenIDs(int $id):array{
    $list=[];
    foreach($GLOBALS['g349Objects'] as $oid=>$obj)
        if(($obj['ParentID']??-1)===$id)$list[]=$oid;
    return $list;
}
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['g349Vars'][$id]);}
function IPS_GetVariable(int $id):array{return $GLOBALS['g349Vars'][$id]??[];}
function IPS_GetVariableProfile(string $name):array{return ['Associations'=>[]];}
function IPS_GetInstance(int $id):array{
    return ['ModuleInfo'=>['ModuleName'=>'KNX Bewegung','ModuleID'=>'{MOTION-KNX}']];
}
require dirname(__DIR__).'/RoomsCatalogConfigurator/module.php';
function ok349(bool $yes,string $what):void{if(!$yes)throw new RuntimeException('V3.4.9: '.$what);}

$catalog=[
    'buero'=>['display'=>'Büro','floor'=>'EG','aliases'=>['EG Büro'],
        'domains'=>['sicherheit'=>['bewegung'=>['bekannt'=>[
            'title'=>'Bereits bekannter Melder',
            'ai'=>['version'=>1,'type'=>'motion',
                'variables'=>['motion'=>51003],
                'review'=>['status'=>'confirmed']],
            'state'=>51003,'discoverySourceId'=>2003,
            'kiEnabled'=>true,'kiReadOnly'=>true
        ]]]]],
    'kueche'=>['display'=>'Küche','floor'=>'EG','domains'=>[]],
    'wohnzimmer'=>['display'=>'Wohnzimmer','floor'=>'EG','domains'=>[]]
];
IPS_SetScriptContent(40,'<?php return '.var_export($catalog,true).';');
IPS_SetScriptContent(41,'<?php return '.var_export($catalog,true).';');
$mod=new RoomsCatalogConfigurator();
$mod->properties=[
    'RoomsCatalogScriptID'=>40,'RoomsCatalogEditScriptID'=>41,
    'DiscoveryRootId'=>120,'DiscoveryExtraRootIds'=>'',
    'DiscoveryDomainOverride'=>'sicherheit','DiscoveryGroupOverride'=>'bewegung',
    'DiscoveryTypeOverride'=>'motion','DiscoveryRoomOverride'=>'',
    'DiscoveryUseAI'=>false,'DiscoveryAlexaInstanceId'=>0
];
$beforeEdit=IPS_GetScriptContent(41);
ob_start();$mod->ScanNewDevices();$scanMessage=ob_get_clean();
$preview=json_decode($mod->ReadAttributeString('DiscoveryCandidates'),true,512,JSON_THROW_ON_ERROR);
$found=[];foreach($preview['rows'] as $row)$found[$row['sourceId']]=$row;
ok349(count($found)===4 && !isset($found[2003]),'known motion device should not be duplicated');
foreach([2001=>51001,2002=>51002,53001=>53001,53002=>53002] as $src=>$value){
    $row=$found[$src]??null;
    ok349(is_array($row),'missing node '.$src);
    ok349($row['deviceType']==='motion' && ($row['mappedRoles']['motion']??0)===$value,
        'single Boolean motion feedback must be inferred for '.$src);
    ok349($row['locationDomain']==='sicherheit' && $row['locationGroup']==='bewegung',
        'selected domain/group must be preserved');
    ok349(in_array($row['roomKey'],['buero','kueche'],true),'room not inferred from KNX sensor name');
    ok349($row['selected']===false && $row['confidence']==='Hoch',
        'high-confidence candidate should require explicit marking');
}
ok349(($found[53001]['sourceType']??'')==='variable'
    && ($found[53002]['sourceType']??'')==='variable',
    'direct sensor variables in same category must become separate devices');
ok349($beforeEdit===IPS_GetScriptContent(41),'scan must be read-only');
ok349(str_contains($scanMessage,'Inventur'),'summary should explain discovered inventory');
ok349(str_contains($mod->ReadAttributeString('DiscoveryAIReport'),'ausgeschaltet'),
    'AI must never be invoked unless explicitly enabled');

// Fingerprint: user changes selected root after scan -> no EDIT writes.
$mod->properties['DiscoveryGroupOverride']='sicherheit';
$mod->SelectDiscoveredDevices(true);
ob_start();$mod->ApplyDiscoveredDevices();$stale=ob_get_clean();
ok349(str_contains($stale,'veraltet') && IPS_GetScriptContent(41)===$beforeEdit,
    'changing destination group must invalidate existing scan preview');
$mod->properties['DiscoveryGroupOverride']='bewegung';

ob_start();$mod->ApplyDiscoveredDevices();$imported=ob_get_clean();
ok349(str_contains($imported,'4 neue Geraete'), 'all four selected motions not imported into EDIT');
$edit=require $GLOBALS['g349Root'].'/scripts/41.php';
ok349((require $GLOBALS['g349Root'].'/scripts/40.php')===$catalog,'PROD unexpectedly modified');
$rows=$edit['buero']['domains']['sicherheit']['bewegung']+
    $edit['kueche']['domains']['sicherheit']['bewegung'];
ok349(count($rows)===5,'existing and imported motion sensors not preserved');
$importIds=[];
foreach([$edit['buero']['domains']['sicherheit']['bewegung'],
        $edit['kueche']['domains']['sicherheit']['bewegung']] as $group){
    foreach($group as $dev){
        if(!isset($dev['discoverySourceId']) || $dev['discoverySourceId']===2003)continue;
        $importIds[(int)$dev['discoverySourceId']]=true;
        ok349(($dev['ai']['version']??null)===1
            && ($dev['ai']['type']??'')==='motion'
            && (int)($dev['ai']['variables']['motion']??0)>0,
            'discovered sensor must use KIHouseController-compatible typed schema');
        ok349(($dev['kiEnabled']??null)===false && ($dev['kiReadOnly']??null)===true
            && ($dev['ai']['review']['status']??'')==='pending',
            'import may not authorize AI or write controls or mark verified');
    }
}
ok349(count($importIds)===4,'missing imported motion sensors');

ob_start();$mod->ScanNewDevices();ob_end_clean();
$again=json_decode($mod->ReadAttributeString('DiscoveryCandidates'),true,512,JSON_THROW_ON_ERROR);
ok349(count($again['rows'])===0,'imported sensors should not reappear on rescan');

foreach(array_keys($GLOBALS['g349Scripts']) as $id)@unlink($GLOBALS['g349Root'].'/scripts/'.$id.'.php');
@rmdir($GLOBALS['g349Root'].'/scripts');@rmdir($GLOBALS['g349Root']);
echo "PASS V3.4.9 guided subtree motion scan, grouped variables, room inference, EDIT-only safe staging and schema version\n";
