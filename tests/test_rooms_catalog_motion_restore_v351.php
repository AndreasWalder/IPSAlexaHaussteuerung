<?php
declare(strict_types=1);

/**
 * V3.5.1: Instanz-ID ist keine Lesevariable; Motion-Kinder im markierten
 * Editor nur eindeutig vorschlagen, Backups sicher nur nach EDIT einspielen.
 */
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
    public function ReloadForm():void{throw new RuntimeException('Form reset must not trigger reload');}
}
$GLOBALS['restoreTmp']=sys_get_temp_dir().'/rcc-v351-'.bin2hex(random_bytes(5));
mkdir($GLOBALS['restoreTmp'].'/scripts',0700,true);
$GLOBALS['restoreScripts']=[];
$GLOBALS['restoreNames']=[40=>'RoomsCatalog',41=>'RoomsCatalogEdit'];
$GLOBALS['restoreScriptParents']=[40=>1000,41=>1000];
$GLOBALS['restoreNextScriptId']=9000;
$GLOBALS['restoreIpsObjects']=[
    11995=>['ObjectType'=>1,'ParentID'=>3000,'ObjectName'=>'Aussen Strasse Bewegung'],
    70011=>['ObjectType'=>2,'ParentID'=>11995,'ObjectName'=>'Value','ObjectIdent'=>'STATE'],
    11996=>['ObjectType'=>1,'ParentID'=>3000,'ObjectName'=>'Zwei unsichere Rueckmeldungen'],
    70012=>['ObjectType'=>2,'ParentID'=>11996,'ObjectName'=>'Value','ObjectIdent'=>''],
    70013=>['ObjectType'=>2,'ParentID'=>11996,'ObjectName'=>'Signal','ObjectIdent'=>'']
];
$GLOBALS['restoreIpsVariables']=[
    70011=>['VariableType'=>0,'VariableProfile'=>'~Motion','VariableCustomProfile'=>'',
        'VariableAction'=>0,'VariableCustomAction'=>0],
    70012=>['VariableType'=>0,'VariableProfile'=>'','VariableCustomProfile'=>'',
        'VariableAction'=>0,'VariableCustomAction'=>0],
    70013=>['VariableType'=>0,'VariableProfile'=>'','VariableCustomProfile'=>'',
        'VariableAction'=>0,'VariableCustomAction'=>0]
];
function IPS_ScriptExists(int $id):bool{return array_key_exists($id,$GLOBALS['restoreScripts']);}
function IPS_GetKernelDir():string{return $GLOBALS['restoreTmp'].'/';}
function IPS_GetScriptFile(int $id):string{return $id.'.php';}
function IPS_SetScriptContent(int $id,string $content):bool{
    $GLOBALS['restoreScripts'][$id]=$content;
    file_put_contents($GLOBALS['restoreTmp'].'/scripts/'.$id.'.php',$content);
    return true;
}
function IPS_GetScriptContent(int $id):string{return $GLOBALS['restoreScripts'][$id]??'';}
function IPS_CreateScript(int $type):int{
    $id=++$GLOBALS['restoreNextScriptId'];
    IPS_SetScriptContent($id,'');
    return $id;
}
function IPS_SetParent(int $id,int $parent):bool{$GLOBALS['restoreScriptParents'][$id]=$parent;return true;}
function IPS_GetParent(int $id):int{return $GLOBALS['restoreScriptParents'][$id]??1000;}
function IPS_SetName(int $id,string $name):bool{$GLOBALS['restoreNames'][$id]=$name;return true;}
function IPS_GetName(int $id):string{return $GLOBALS['restoreNames'][$id]??'';}
function IPS_ObjectExists(int $id):bool{return isset($GLOBALS['restoreIpsObjects'][$id]);}
function IPS_GetObject(int $id):array{return $GLOBALS['restoreIpsObjects'][$id]??[];}
function IPS_GetChildrenIDs(int $parent):array{
    if($parent===1000)
        return array_values(array_filter(array_keys($GLOBALS['restoreScripts']),
            static fn(int $id):bool=>IPS_GetParent($id)===1000));
    $found=[];
    foreach($GLOBALS['restoreIpsObjects'] as $id=>$obj)
        if(($obj['ParentID']??0)===$parent)$found[]=$id;
    return $found;
}
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['restoreIpsVariables'][$id]);}
function IPS_GetVariable(int $id):array{return $GLOBALS['restoreIpsVariables'][$id]??[];}
function IPS_GetVariableProfile(string $name):array{return ['Associations'=>[]];}
function GetValue(int $id):mixed{return $id===70011;}
function GetValueFormatted(int $id):string{return $id===70011?'Bewegung':'Ruhe';}

require dirname(__DIR__).'/RoomsCatalogConfigurator/module.php';
function ck351(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('V3.5.1 '.$message);
}

$device=['title'=>'Aussen Strasse Bewegung','discoverySourceId'=>11995,
    'discoverySourceType'=>'instance','kiEnabled'=>true,'kiReadOnly'=>true,
    'ai'=>['version'=>1,'type'=>'motion','variables'=>[],
        'review'=>['status'=>'pending']]];
$catalog=['aussen'=>['display'=>'Außen','domains'=>['sensoren'=>['bewegung'=>[
    'aussenstrassebewegung_11995'=>$device
]]]]];
$prod=$catalog;
$prod['aussen']['domains']['sensoren']['bewegung']['aussenstrassebewegung_11995']['title']='PROD Original';
$source=static fn(array $data):string=>'<?php return '.var_export($data,true).';';
IPS_SetScriptContent(40,$source($prod));
IPS_SetScriptContent(41,$source($catalog));
$m=new RoomsCatalogConfigurator();
$m->properties=['RoomsCatalogScriptID'=>40,'RoomsCatalogEditScriptID'=>41];
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($m);
$candidate=new ReflectionMethod(RoomsCatalogConfigurator::class,'markedEditorMotionCandidate');
ck351($candidate->invoke($m,['discoverySourceId'=>11995,'aiMotionId'=>0])===70011,
    'must use unique readable child variable 70011 under instance 11995');
ck351($candidate->invoke($m,['discoverySourceId'=>11996,'aiMotionId'=>0])===0,
    'two ambiguous Boolean feedback values must never be auto-assigned');

$m->SetAllSelection(true);
$initial=IPS_GetScriptContent(41);
ob_start();$m->StartMarkedEditor();ob_end_clean();
ck351(($m->updates['MarkedEdit_aiMotionId.value']??0)===70011,
    'marked editor should preselect the child variable, not the instance');
ck351(str_contains((string)($m->updates['MarkedEditorSourceInfo.caption']??''),'11995')
    && str_contains((string)($m->updates['MarkedEditorSourceInfo.caption']??''),'70011'),
    'source and proposed variable must be visible and unverified');
$options=json_decode((string)($m->updates['MarkedEditorDevice.options']??''),true);
ck351(is_array($options) && count($options)===1
    && ($options[0]['value']??'')!=='' ,
    'current wizard selection must be present in JSON-encoded options');
ck351(IPS_GetScriptContent(41)===$initial
    && IPS_GetScriptContent(40)===$source($prod),
    'proposing motion variable must not write EDIT or PROD');

ob_start();$m->CreateCatalogEditBackup();$created=ob_get_clean();
ck351(str_contains($created,'9001') && IPS_GetScriptContent(9001)===$initial,
    'manual backup must be verified and identical to original EDIT');
ck351(str_contains($GLOBALS['restoreNames'][9001]??'','RoomsCatalogEdit_Backup_'),
    'new backup must be discoverable through the backup chooser');

// Edit changed deliberately, restore should recover the exact older content
$changed=$catalog;
$changed['aussen']['domains']['sensoren']['bewegung']['aussenstrassebewegung_11995']['title']='EDIT kaputt';
IPS_SetScriptContent(41,$source($changed));
ob_start();$m->PreviewCatalogRestore(9001);$previewMsg=ob_get_clean();
ck351(str_contains($previewMsg,'VORSCHAU') && str_contains($previewMsg,'9001'),
    'backup restore requires visible preview');
ob_start();$m->RestoreCatalogBackupToEdit(9001);$restoreMsg=ob_get_clean();
ck351(str_contains($restoreMsg,'RESTORE ERFOLGREICH')
    && IPS_GetScriptContent(41)===$initial,
    'valid backup should restore EDIT exactly');
ck351(IPS_GetScriptContent(40)===$source($prod),
    'restoring EDIT backup must NEVER touch PROD');
ck351(IPS_GetScriptContent(9002)===$source($changed),
    'pre-restore EDIT must be protected by independent new backup');
ck351($m->ReadAttributeString('MarkedEditorKeys')==='[]'
    && ($m->updates['MarkedEditorPanel.visible']??true)===false,
    'restoring and resetting must close old marked editor');

// Stale source cannot be replayed even after preview
ob_start();$m->PreviewCatalogRestore(9002);ob_end_clean();
IPS_SetScriptContent(41,$source($prod));
ob_start();$m->RestoreCatalogBackupToEdit(9002);$stale=ob_get_clean();
ck351(str_contains($stale,'BLOCKIERT')
    && IPS_GetScriptContent(41)===$source($prod),
    'changing EDIT after preview must reject stale restore');

foreach(array_keys($GLOBALS['restoreScripts']) as $id)
    @unlink($GLOBALS['restoreTmp'].'/scripts/'.$id.'.php');
@rmdir($GLOBALS['restoreTmp'].'/scripts');@rmdir($GLOBALS['restoreTmp']);
echo "PASS V3.5.1: motion from instance, guarded editor select and backup restore to EDIT only\n";
