<?php
declare(strict_types=1);

/**
 * Regression for dynamic, source-specific Symcon List.form editing.
 * Run: php tests/test_visual_navigation_dynamic_editor.php
 */
function IPS_GetKernelDir(): string { return sys_get_temp_dir().DIRECTORY_SEPARATOR; }
require_once dirname(__DIR__).'/IPSAlexaHaussteuerung/src/VisualNavigationEditorTrait.php';

class VisualNavigationFormHarness
{
    use IAHVisualNavigationEditorTrait;
    public array $updates=[];
    public function UpdateFormField(string $name,string $property,mixed $value): void
    {
        $this->updates[]=['name'=>$name,'property'=>$property,'value'=>$value];
    }
}
function assertVisual(bool $value,string $message): void
{
    if (!$value) throw new RuntimeException($message);
}
function flattenElements(array $items): array
{
    $results=[];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $results[]=$item;
        foreach ((array)($item['items']??[]) as $child) {
            if (is_array($child)) $results=array_merge($results,flattenElements([$child]));
        }
        foreach ((array)($item['options']??[]) as $option) {
            if (is_array($option)) $results=array_merge($results,flattenElements((array)($option['items']??[])));
        }
    }
    return $results;
}
$editor=new VisualNavigationFormHarness();
$expected=[
    'launch'=>['actionType','target','icon','iconUpload','color','resultTitle',
        'resultIconMode','resultStatusMode','resultLayout','speakAnswer','useArchive'],
    'navigation'=>['title','target'],
    'page'=>['actionType','target'],
    'renderer'=>['title','target']
];
foreach ($expected as $source=>$fields) {
    $form=json_decode($editor->GetVisualNavigationEditForm($source,
        $source==='launch'?'ai_query':($source==='page'?'wfc_item':$source)),true,512,JSON_THROW_ON_ERROR);
    $elements=flattenElements($form);
    $names=array_values(array_filter(array_map(static fn(array $el):string=>(string)($el['name']??''),$elements)));
    foreach ($fields as $field) assertVisual(in_array($field,$names,true),"$source missing $field");
    if ($source!=='launch') {
        foreach (['resultTitle','resultIconMode','resultStatusMode','resultLayout','speakAnswer','useArchive'] as $field)
            assertVisual(!in_array($field,$names,true),"$source unexpectedly has KI field $field");
        assertVisual(!in_array('color',$names,true),"$source unexpectedly has a tile color");
    }
    if ($source==='navigation') {
        $object=array_values(array_filter($elements,static fn($el)=>($el['name']??'')==='target'));
        assertVisual(($object[0]['type']??'')==='SelectObject','navigation uses a Symcon object picker');
    }
}
$route=json_decode($editor->GetVisualNavigationEditForm('launch','route'),true,512,JSON_THROW_ON_ERROR);
$ai=json_decode($editor->GetVisualNavigationEditForm('launch','ai_query'),true,512,JSON_THROW_ON_ERROR);
$findPanel=static function (array $form): array {
    foreach ($form as $el) if (($el['name']??'')==='VisualNavAiSettings') return $el;
    throw new RuntimeException('KI panel not found');
};
assertVisual(($findPanel($route)['visible']??null)===false,'route must hide KI settings');
assertVisual(($findPanel($ai)['visible']??null)===true,'KI action must show KI settings');
$editor->UpdateVisualNavigationActionForm('ai_query');
assertVisual(in_array(['name'=>'VisualNavAiSettings','property'=>'visible','value'=>true],
    $editor->updates,true),'switch to KI must expose KI panel');
$editor->UpdateVisualNavigationActionForm('route');
assertVisual(in_array(['name'=>'VisualNavAiSettings','property'=>'visible','value'=>false],
    $editor->updates,true),'switch to route must hide KI panel');

$source=(string)file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/src/VisualNavigationEditorTrait.php');
assertVisual(str_contains($source,"'form'=>'return json_decode(IAH_GetVisualNavigationEditForm("),
    'unified list must call source-specific form');
assertVisual(str_contains($source,"['name'=>'resultTitle','visible'=>false,'save'=>true]"),
    'hidden metadata must survive list edits');
echo "PASS: dynamic Launch/Page/Renderer/Navigation dialogs and action-dependent KI fields\n";
