<?php
declare(strict_types=1);

/** V3.5.2: 31er-Wizard: quellinstanzbegrenzte Variablen und Speichern+Weiter oben. */
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
    public function UpdateFormField(string $name,string $field,mixed $value):void{
        $this->updates[$name.'.'.$field]=$value;
    }
    public function ReloadForm():void{throw new RuntimeException('No form reload while navigating');}
}
$GLOBALS['scopeDir']=sys_get_temp_dir().'/rcc-v352-'.bin2hex(random_bytes(6));
mkdir($GLOBALS['scopeDir'].'/scripts',0700,true);
$GLOBALS['scopeScripts']=[];
$GLOBALS['scopeObjects']=[
    58353=>['ObjectType'=>1,'ParentID'=>3000,'ObjectName'=>'Außen Straße Bewegung'],
    73101=>['ObjectType'=>2,'ParentID'=>58353,'ObjectName'=>'Value','ObjectIdent'=>'Value'],
    73102=>['ObjectType'=>2,'ParentID'=>58353,'ObjectName'=>'Rueckmeldung','ObjectIdent'=>'Signal'],
    58354=>['ObjectType'=>1,'ParentID'=>3000,'ObjectName'=>'EG Bad Bewegung'],
    73103=>['ObjectType'=>2,'ParentID'=>58354,'ObjectName'=>'Bewegung','ObjectIdent'=>'Motion']
];
$GLOBALS['scopeVars']=[];
foreach([73101,73102,73103] as $id)
    $GLOBALS['scopeVars'][$id]=['VariableType'=>0,
        'VariableProfile'=>$id===73103?'~Motion':'',
        'VariableCustomProfile'=>'','VariableAction'=>0,'VariableCustomAction'=>0,
        'VariableUpdated'=>1793990100,'VariableChanged'=>1793990000];
function IPS_ScriptExists(int $id):bool{return array_key_exists($id,$GLOBALS['scopeScripts']);}
function IPS_GetKernelDir():string{return $GLOBALS['scopeDir'].'/';}
function IPS_GetScriptFile(int $id):string{return $id.'.php';}
function IPS_SetScriptContent(int $id,string $code):bool{
    $GLOBALS['scopeScripts'][$id]=$code;
    file_put_contents($GLOBALS['scopeDir'].'/scripts/'.$id.'.php',$code);return true;
}
function IPS_GetScriptContent(int $id):string{return $GLOBALS['scopeScripts'][$id]??'';}
function IPS_ObjectExists(int $id):bool{return isset($GLOBALS['scopeObjects'][$id]);}
function IPS_GetObject(int $id):array{return $GLOBALS['scopeObjects'][$id]??[];}
function IPS_GetChildrenIDs(int $parent):array{
    $result=[];
    foreach($GLOBALS['scopeObjects'] as $id=>$obj)
        if(($obj['ParentID']??-1)===$parent)$result[]=$id;
    return $result;
}
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['scopeVars'][$id]);}
function IPS_GetVariable(int $id):array{return $GLOBALS['scopeVars'][$id]??[];}
function IPS_GetVariableProfile(string $profile):array{return ['Associations'=>[]];}
function GetValue(int $id):bool{return $id===73101;}
function GetValueFormatted(int $id):string{return GetValue($id)?'Bewegung':'Keine Bewegung';}

require dirname(__DIR__).'/RoomsCatalogConfigurator/module.php';
function ck352(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException('V3.5.2 '.$message);
}
$entry=static fn(int $source,string $name):array=>[
    'title'=>$name,'kiEnabled'=>false,'kiReadOnly'=>true,
    'discoverySourceId'=>$source,'discoverySourceType'=>'instance',
    'ai'=>['type'=>'motion','version'=>1,'variables'=>[],
        'review'=>['status'=>'pending']]
];
$catalog=['aussen'=>['display'=>'Außen','domains'=>[
    'sensoren'=>['bewegung'=>['a'=>$entry(58353,'Außen Straße Bewegung'),
        'b'=>$entry(58354,'EG Bad Bewegung')]]
]]];
$source='<?php return '.var_export($catalog,true).';';
IPS_SetScriptContent(40,$source);
IPS_SetScriptContent(41,$source);
$m=new RoomsCatalogConfigurator();
$m->properties=['RoomsCatalogScriptID'=>40,'RoomsCatalogEditScriptID'=>41];
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($m);
$m->SetAllSelection(true);
ob_start();$m->StartMarkedEditor();ob_end_clean();
$keys=json_decode($m->ReadAttributeString('MarkedEditorKeys'),true,512,JSON_THROW_ON_ERROR);
ck352(count($keys)===2,'must retain both marked devices');
ck352(($m->updates['MarkedEdit_aiMotionId.value']??-1)===0,
    'ambiguous two-variable source must not silently select one');
$options=json_decode((string)($m->updates['MarkedScope_aiMotionId.options']??''),true);
$values=array_map('intval',array_column((array)$options,'value'));
ck352(in_array(73101,$values,true) && in_array(73102,$values,true)
    && !in_array(73103,$values,true),
    'first source picker must show only variables under instance #58353');
ck352(($m->updates['MarkedScope_aiMotionId.visible']??false)===true
    && !empty($m->updates['MarkedEditorSourceInfo.caption']),
    'scoped selector must be visible and display source information');

$form=json_decode($m->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$find=static function(array $items,string $name) use (&$find):?array {
    foreach($items as $item){
        if(($item['name']??'')===$name)return $item;
        if(is_array($item['items']??null)){
            $v=$find($item['items'],$name);
            if($v!==null)return $v;
        }
    }
    return null;
};
$panel=$find($form['elements'],'MarkedEditorPanel');
ck352(is_array($panel),'marked editor panel missing');
$upperRow=null;
foreach($panel['items'] as $item) {
    if(($item['type']??'')==='RowLayout' && is_array($item['items']??null)) {
        $names=array_column($item['items'],'name');
        if(in_array('MarkedEditorPrevious',$names,true)) {$upperRow=$item;break;}
    }
}
ck352(is_array($upperRow),'navigation row missing');
$upperNames=array_column($upperRow['items'],'name');
ck352(in_array('MarkedEditorSaveNext',$upperNames,true)
    && array_search('MarkedEditorSaveNext',$upperNames,true)
        >array_search('MarkedEditorNext',$upperNames,true),
    'Save and Next must be next to Back/Next/Finish at the top');
ck352(substr_count(json_encode($panel),'"name":"MarkedEditorSaveNext"')===1,
    'Save and Next must not be duplicated at bottom');
$local=$find($panel['items'],'MarkedScope_aiMotionId');
$full=$find($panel['items'],'MarkedEdit_aiMotionId');
ck352(($local['type']??'')==='Select'
    && str_contains((string)($local['caption']??''),'58353')
    && ($full['type']??'')==='SelectVariable',
    'scoped picker must show source filter and keep native global IPS tree as fallback');

$before=IPS_GetScriptContent(41);
$m->SelectMarkedScopedVariable('aiMotionId',73103);
ck352(!isset($m->updates['MarkedEdit_aiMotionId.value'])
    || (int)$m->updates['MarkedEdit_aiMotionId.value']===0,
    'foreign instance variable must be rejected');
$m->SelectMarkedScopedVariable('aiMotionId',73101);
ck352(($m->updates['MarkedEdit_aiMotionId.value']??0)===73101
    && str_contains((string)($m->updates['MarkedValuePreview_aiMotionId.caption']??''),'#73101'),
    'local selection must update canonical variable and live preview');
$m->RefreshMarkedVariablePreview('aiMotionId',73102);
ck352(($m->updates['MarkedScope_aiMotionId.value']??0)===73102,
    'global SelectVariable change must synchronize source picker');

$m->StepMarkedEditor(1);
$options=json_decode((string)($m->updates['MarkedScope_aiMotionId.options']??''),true);
$values=array_map('intval',array_column((array)$options,'value'));
ck352(in_array(73103,$values,true) && !in_array(73101,$values,true),
    'next device must replace scope, never carry previous IDs');
ck352((int)($m->updates['MarkedEdit_aiMotionId.value']??0)===73103,
    'second source should keep unique unverified suggestion');
ck352(IPS_GetScriptContent(41)===$before && IPS_GetScriptContent(40)===$source,
    'navigation and selections must not write EDIT or PROD');

foreach(array_keys($GLOBALS['scopeScripts']) as $id)
    @unlink($GLOBALS['scopeDir'].'/scripts/'.$id.'.php');
@rmdir($GLOBALS['scopeDir'].'/scripts');@rmdir($GLOBALS['scopeDir']);
echo "PASS V3.5.2: source picker #58353, no cross-device IDs, Save+Next at top\n";
