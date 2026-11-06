<?php
declare(strict_types=1);

/** V3.4.8 – device editor shows live values after a native SelectVariable selection. */
class IPSModule {
    public array $updates=[];
    public function ReadPropertyInteger(string $name):int {return 0;}
    public function ReadAttributeString(string $name):string {return '';}
    public function UpdateFormField(string $name,string $field,mixed $value):void {
        $this->updates[$name.'.'.$field]=$value;
    }
}
function IPS_ScriptExists(int $id):bool {return false;}
function IPS_VariableExists(int $id):bool {return in_array($id,[101,102],true);}
function IPS_GetVariable(int $id):array {
    return ['VariableType'=>$id===101?0:1,
        'VariableProfile'=>$id===101?'~Switch':'TestStatus',
        'VariableCustomProfile'=>''];
}
function GetValue(int $id):mixed {return $id===101?true:25;}
function GetValueFormatted(int $id):string {return $id===101?'Ein':'Wartung';}
require dirname(__DIR__).'/RoomsCatalogConfigurator/module.php';
function checkPreview348(bool $value,string $message):void {
    if (!$value)throw new RuntimeException($message);
}
$mod=new RoomsCatalogConfigurator();
$form=json_decode($mod->GetEntryEditForm(),true,512,JSON_THROW_ON_ERROR);
$find=function(array $items,string $name) use (&$find):?array {
    foreach($items as $i){
        if(($i['name']??'')===$name)return $i;
        if(is_array($i['items']??null)){
            $child=$find($i['items'],$name);
            if($child!==null)return $child;
        }
    }
    return null;
};
$selector=$find($form,'aiPowerId');
$label=$find($form,'ValuePreview_aiPowerId');
checkPreview348(($selector['type']??'')==='SelectVariable'
    && str_contains((string)($selector['onChange']??''),'RCC_RefreshSelectedVariablePreview'),
    'Selecting a variable must trigger an update');
checkPreview348(($label['type']??'')==='Label' && ($label['visible']??false)===true,
    'status label beside light variable missing');
checkPreview348(str_contains((string)$label['caption'],'keine Variable'),
    'no selection should be clearly displayed');
$mod->RefreshSelectedVariablePreview('aiPowerId',101);
$preview=(string)($mod->updates['ValuePreview_aiPowerId.caption']??'');
checkPreview348(str_contains($preview,'true') && str_contains($preview,'Ein')
    && str_contains($preview,'#101') && str_contains($preview,'~Switch'),
    'real current Boolean, formatted label, ID and IPS profile must be visible');
$mod->RefreshSelectedVariablePreview('aiPowerId',102);
checkPreview348(str_contains((string)($mod->updates['ValuePreview_aiPowerId.caption']??''),'25')
    && str_contains((string)($mod->updates['ValuePreview_aiPowerId.caption']??''),'Wartung'),
    'Integer status including formatted profile meaning must be visible');
$mod->RefreshSelectedVariablePreview('aiPowerId',999);
checkPreview348(str_contains((string)($mod->updates['ValuePreview_aiPowerId.caption']??''),'existiert nicht'),
    'dead ID should be marked');
$mod->UpdateEntryTypeForm('blind');
checkPreview348(($mod->updates['ValuePreview_aiPowerId.visible']??null)===false
    && ($mod->updates['ValuePreview_aiPositionId.visible']??null)===true,
    'type change must hide/show matching labels without form reload');
$before=count($mod->updates);
$mod->RefreshSelectedVariablePreview('InvalidField',101);
checkPreview348(count($mod->updates)===$before,'unauthorized field must not update form');
echo "PASS V3.4.8: live current IPS value and profile beside SelectVariable + onChange, no write or reload\n";
