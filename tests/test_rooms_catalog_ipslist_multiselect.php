<?php
declare(strict_types=1);

/**
 * Verifiziert echte IP-Symcon-List-Callback-Semantik:
 * IPSList ist ArrayAccess/iterierbar, aber KEIN PHP-Array.
 */
class IPSList implements ArrayAccess, IteratorAggregate
{
    public function __construct(private array $row, private array $all=[]) {}
    public function offsetExists(mixed $offset): bool { return array_key_exists($offset,$this->row); }
    public function offsetGet(mixed $offset): mixed { return $this->row[$offset] ?? null; }
    public function offsetSet(mixed $offset,mixed $value): void { $this->row[$offset]=$value; }
    public function offsetUnset(mixed $offset): void { unset($this->row[$offset]); }
    public function getIterator(): Traversable { return new ArrayIterator($this->all); }
}
class IPSModule
{
    private array $attrs=[];
    public array $props=[];
    public array $formUpdates=[];
    public function ReadPropertyInteger(string $k):int { return (int)($this->props[$k]??0); }
    public function ReadAttributeString(string $k):string { return (string)($this->attrs[$k]??''); }
    public function WriteAttributeString(string $k,string $v):void { $this->attrs[$k]=$v; }
    public function UpdateFormField(string $name,string $field,mixed $v):void { $this->formUpdates[$name.'.'.$field]=$v; }
    public function ReloadForm():void { throw new RuntimeException('Editor collapsed'); }
}
function IPS_ScriptExists(int $id):bool {return $id===42;}
function IPS_GetScriptFile(int $id):string {return '';}
function IPS_VariableExists(int $id):bool {return $id>=90000&&$id<99000;}
function IPS_SetScriptContent(int $id,string $content):void {throw new RuntimeException('Selection must never overwrite EDIT script');}
require dirname(__DIR__).'/RoomsCatalogConfigurator/module.php';
function testAssert(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException($msg);}
$inst=new RoomsCatalogConfigurator();
$GLOBALS['rccTestInstance']=$inst;
function RCC_SaveToEdit(int $id,string $json):void {
    testAssert($id===123,'Callback instance ID');
    $GLOBALS['rccTestInstance']->SaveToEdit($json);
}
$rows=[
    ['selected'=>false,'roomKey'=>'raum1','roomLabel'=>'Raum 1','floor'=>'EG','domain'=>'licht',
     'group'=>'switches','key'=>'decke','title'=>'Licht Decke','state'=>91001,'toggle'=>91002,
     'kiEnabled'=>true,'kiReadOnly'=>true,'customFlag'=>false],
    ['selected'=>false,'roomKey'=>'raum1','roomLabel'=>'Raum 1','floor'=>'EG','domain'=>'licht',
     'group'=>'switches','key'=>'wand','title'=>'Licht Wand','state'=>91003,'toggle'=>91004,
     'kiEnabled'=>true,'kiReadOnly'=>true,'customFlag'=>false],
    ['selected'=>false,'roomKey'=>'raum2','roomLabel'=>'Raum 2','floor'=>'EG','domain'=>'licht',
     'group'=>'switches','key'=>'decke','title'=>'Licht Decke 2','state'=>91005,'toggle'=>91006,
     'kiEnabled'=>true,'kiReadOnly'=>true,'customFlag'=>false],
];
$inst->WriteAttributeString('RuntimeEntries',json_encode($rows,JSON_THROW_ON_ERROR));
$inst->WriteAttributeString('FilterAI','all');
$form=json_decode($inst->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$list=null;
foreach($form['elements'] as $element) {
    if(($element['caption']??'')!=='Geraete bearbeiten (RoomsCatalog EDIT)')continue;
    foreach($element['items'] as $item){if(($item['name']??'')==='Entries')$list=$item;}
}
testAssert(is_array($list),'List must be present');
testAssert(!str_contains($list['onEdit'],'array_filter($Entries'),'Unsafe IPSList filter remains');
testAssert(str_contains($list['onEdit'],"'customFlag'"),'Dynamic custom field must be retained');
$inst->props['RoomsCatalogEditScriptID']=42;
$id=123;
// In real Symcon, $Entries is an IPSList with string index for selected row.
// Simulate toggling two different checkboxes without reopening/refreshing form.
foreach([0,1] as $idx) {
    $before=json_decode($inst->ReadAttributeString('RuntimeEntries'),true);
    $before[$idx]['selected']=true;
    $Entries=new IPSList($before[$idx],$before);
    eval($list['onEdit']);
}
$result=json_decode($inst->ReadAttributeString('RuntimeEntries'),true);
testAssert($result[0]['selected']===true && $result[1]['selected']===true
    && $result[2]['selected']===false,'Two rows must be selectable simultaneously');
testAssert($result[0]['customFlag']===false,'false values must not disappear');
testAssert($result[0]['state']===91001,'IP-Symcon IDs must remain untouched');
// Untick row 1, row 2 remains selected.
$result[0]['selected']=false;
$Entries=new IPSList($result[0],$result);
eval($list['onEdit']);
$result=json_decode($inst->ReadAttributeString('RuntimeEntries'),true);
testAssert(!$result[0]['selected'] && $result[1]['selected'],'Unmarking one row must preserve others');
// Programmatic select visible entries, even with filters active.
$inst->SetRoomFilter('raum1');
$inst->SetVisibleSelection(true);
$result=json_decode($inst->ReadAttributeString('RuntimeEntries'),true);
testAssert($result[0]['selected'] && $result[1]['selected'] && !$result[2]['selected'],
    'Bulk marking only filtered rows');
testAssert(isset($inst->formUpdates['Entries.values']),'Checkbox list updated without ReloadForm');
$inst->SetVisibleSelection(false);
$result=json_decode($inst->ReadAttributeString('RuntimeEntries'),true);
testAssert(count(array_filter($result,static fn($r)=>!empty($r['selected'])))===0,'Unmark all');
echo "PASS: IPSList callbacks, multiple mark/unmark, custom fields and filtered bulk checkbox selection\n";
