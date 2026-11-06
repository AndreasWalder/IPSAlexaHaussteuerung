<?php
declare(strict_types=1);

/** V3.5.0: markierte Geraete in einem Arbeitsablauf durchblaettern, EDIT-Only. */
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
    public function ReloadForm():void{throw new RuntimeException('Wizard must not reload form on navigation');}
}
$GLOBALS['v350dir']=sys_get_temp_dir().'/rcc-v350-'.bin2hex(random_bytes(5));
mkdir($GLOBALS['v350dir'].'/scripts',0700,true);
$GLOBALS['v350sources']=[];
function IPS_ScriptExists(int $id):bool{return isset($GLOBALS['v350sources'][$id]);}
function IPS_GetKernelDir():string{return $GLOBALS['v350dir'].'/';}
function IPS_GetScriptFile(int $id):string{return $id.'.php';}
function IPS_SetScriptContent(int $id,string $value):bool{
    $GLOBALS['v350sources'][$id]=$value;
    file_put_contents($GLOBALS['v350dir'].'/scripts/'.$id.'.php',$value);
    return true;
}
function IPS_GetScriptContent(int $id):string{return $GLOBALS['v350sources'][$id]??'';}
function IPS_VariableExists(int $id):bool{return in_array($id,[50101,50102,50103,50104,50105],true);}
function IPS_GetVariable(int $id):array{
    return ['VariableType'=>0,'VariableProfile'=>'~Motion','VariableCustomProfile'=>'',
        'VariableChanged'=>1793990000,'VariableUpdated'=>1793990010];
}
function GetValue(int $id):mixed{return $id===50101||$id===50104;}
function GetValueFormatted(int $id):string{return GetValue($id)?'Bewegung':'Keine Bewegung';}
require dirname(__DIR__).'/RoomsCatalogConfigurator/module.php';
function assert350(bool $yes,string $message):void{
    if(!$yes)throw new RuntimeException('V3.5.0 '.$message);
}
$motion=static fn(int $id,string $name):array=>[
    'title'=>$name,'state'=>$id,'kiEnabled'=>false,'kiReadOnly'=>true,
    'ai'=>['version'=>1,'type'=>'motion',
        'variables'=>['motion'=>$id],
        'stateMapping'=>['on'=>true,'off'=>false],
        'review'=>['status'=>'pending']],
    'preserveExtra'=>['nested'=>'untouched']
];
$catalog=[
    'buero'=>['display'=>'Büro','floor'=>'EG','domains'=>['sensoren'=>['bewegung'=>[
        'a'=>$motion(50101,'EG Büro Bewegung'),
        'b'=>$motion(50102,'EG Büro Ecke Bewegung')
    ]]]],
    'kueche'=>['display'=>'Küche','floor'=>'EG','domains'=>['sensoren'=>['bewegung'=>[
        'c'=>$motion(50103,'EG Küche Bewegung')
    ]]]]
];
IPS_SetScriptContent(90,'<?php return '.var_export($catalog,true).';');
IPS_SetScriptContent(91,'<?php return '.var_export($catalog,true).';');
$m=new RoomsCatalogConfigurator();
$m->properties=['RoomsCatalogScriptID'=>90,'RoomsCatalogEditScriptID'=>91];
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($m);
$m->SetAllSelection(true);

$ui=$m->GetConfigurationForm();
assert350(str_contains($ui,'MARKIERTE NACHEINANDER BEARBEITEN')
    && str_contains($ui,'Gemeinsame Felder aller markierten Geraete aendern')
    && str_contains($ui,'RCC_PreviewBulkEdit')
    && str_contains($ui,'MarkedEdit_aiMotionId'),
    'bulk and all-bindings pager must be visible in UI');
$before=IPS_GetScriptContent(91);
ob_start();$m->StartMarkedEditor();ob_end_clean();
$keys=json_decode($m->ReadAttributeString('MarkedEditorKeys'),true,512,JSON_THROW_ON_ERROR);
assert350(count($keys)===3 && $m->ReadAttributeString('MarkedEditorIndex')==='0',
    'wizard should start with all 3 marked entries');
assert350(str_contains((string)($m->updates['MarkedEditorProgress.caption']??''),'1/3'),
    'wizard progress should show 1 of 3');
assert350(($m->updates['MarkedEdit_aiMotionId.value']??0)===50101,
    'first sensor feedback must be loaded without copying other variable IDs');
assert350(IPS_GetScriptContent(91)===$before,'starting wizard must be read-only');

$m->StepMarkedEditor(1);
assert350($m->ReadAttributeString('MarkedEditorIndex')==='1'
    && ($m->updates['MarkedEdit_aiMotionId.value']??0)===50102,
    'next should show second unique sensor');
assert350(IPS_GetScriptContent(91)===$before,'navigation should not write changes');
$m->StepMarkedEditor(-1);
assert350(($m->updates['MarkedEdit_aiMotionId.value']??0)===50101,
    'previous should restore first device feedback');
$m->ChooseMarkedEditor($keys[2]);
assert350($m->ReadAttributeString('MarkedEditorIndex')==='2'
    && ($m->updates['MarkedEdit_aiMotionId.value']??0)===50103,
    'dropdown should jump directly to last sensor');
$m->ChooseMarkedEditor($keys[0]);

$payload=['sourceKey'=>$keys[0],'title'=>'EG Büro PIR neu','synonyms'=>'Büro PIR',
    'entityId'=>'','kiEnabled'=>true,'kiReadOnly'=>true,'aiReviewed'=>false,
    'aiMotionId'=>50104,'aiOnValue'=>'true','aiOffValue'=>'false'];
ob_start();$m->SaveMarkedEditorDevice(json_encode($payload,JSON_THROW_ON_ERROR),true);
ob_end_clean();
$edit=require $GLOBALS['v350dir'].'/scripts/91.php';

assert350($edit['buero']['domains']['sensoren']['bewegung']['a']['title']==='EG Büro PIR neu'
    && $edit['buero']['domains']['sensoren']['bewegung']['a']['ai']['variables']['motion']===50104
    && $edit['buero']['domains']['sensoren']['bewegung']['a']['kiEnabled']===true
    && $edit['buero']['domains']['sensoren']['bewegung']['a']['state']===50104
    && $edit['buero']['domains']['sensoren']['bewegung']['a']['ai']['variables']['status']===50104,
    'save and next must update exactly first EDIT device');
assert350($edit['buero']['domains']['sensoren']['bewegung']['b']['ai']['variables']['motion']===50102
    && $edit['kueche']['domains']['sensoren']['bewegung']['c']['ai']['variables']['motion']===50103,
    'per-device editor may not copy selected source ID to other devices');
assert350($edit['buero']['domains']['sensoren']['bewegung']['a']['preserveExtra']===
    ['nested'=>'untouched'],'unknown legacy fields must survive EDIT update');
assert350((require $GLOBALS['v350dir'].'/scripts/90.php')===$catalog,
    'PROD must never be modified by pager');
assert350($m->ReadAttributeString('MarkedEditorIndex')==='1'
    && ($m->updates['MarkedEdit_aiMotionId.value']??0)===50102,
    'save + next must advance although SaveToEdit clears runtime selections');
assert350(count(json_decode($m->ReadAttributeString('MarkedEditorKeys'),true))===3,
    'save should preserve wizard snapshot');

$m->SaveMarkedEditorDevice(json_encode(array_merge($payload,
    ['sourceKey'=>$keys[0],'title'=>'Falscher Eintrag']),JSON_THROW_ON_ERROR));
assert350($edit===(require $GLOBALS['v350dir'].'/scripts/91.php'),
    'wrong source key must not overwrite the currently displayed device');

$editedExternally=IPS_GetScriptContent(91)."\n// Anderer Editor";
IPS_SetScriptContent(91,$editedExternally);
ob_start();$m->SaveMarkedEditorDevice(json_encode(array_merge($payload,
    ['sourceKey'=>$keys[1],'title'=>'Veralteter Versuch']),JSON_THROW_ON_ERROR));$error=ob_get_clean();
assert350(str_contains($error,'EDIT wurde seit dem Laden geaendert')
    && IPS_GetScriptContent(91)===$editedExternally,
    'stale wizard must reject writing after external EDIT update');

$m->FinishMarkedEditor();
assert350($m->ReadAttributeString('MarkedEditorKeys')==='[]'
    && ($m->updates['MarkedEditorPanel.visible']??true)===false,
    'finishing wizard should cleanly hide stale editor');
foreach(array_keys($GLOBALS['v350sources']) as $id)@unlink($GLOBALS['v350dir'].'/scripts/'.$id.'.php');
@rmdir($GLOBALS['v350dir'].'/scripts');@rmdir($GLOBALS['v350dir']);
echo "PASS V3.5.0: shared bulk controls, unique device variable pager, next/previous, EDIT-only save, snapshot preserved, stale writes blocked\n";
