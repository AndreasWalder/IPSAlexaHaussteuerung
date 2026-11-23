<?php
declare(strict_types=1);

/** Run: php tests/test_visual_navigation_group_wizard.php */
function IPS_HasChanges(int $id): bool { return false; }
function IPS_SetProperty(int $id,string $property,string $value): void {
    $GLOBALS['navHarness']->properties[$property]=$value;
}
function IPS_ApplyChanges(int $id): void { $GLOBALS['navHarness']->applyCount++; }
function IPS_GetInstanceListByModuleID(string $module): array {return [45135];}
function IPS_GetConfiguration(int $id): string {
    return json_encode(['Items'=>json_encode([
        ['ID'=>'item9907','ClassName'=>'TabPane',
            'Configuration'=>json_encode(['title'=>'Kamera'])]
    ])],JSON_THROW_ON_ERROR);
}
function IPS_GetName(int $id): string {return 'Haus';}


require_once dirname(__DIR__).'/IPSAlexaHaussteuerung/src/VisualNavigationGroupTrait.php';

final class VisualGroupHarness
{
    use IAHVisualNavigationGroupTrait;
    public int $InstanceID=16159;
    public int $applyCount=0;
    public array $formUpdates=[];
    public function UpdateFormField(string $name,string $field,mixed $value): void {
        $this->formUpdates[]=['name'=>$name,'field'=>$field,'value'=>$value];
    }
    public array $properties=[
        'LaunchCatalog'=>'[{"id":"bienen","title":"Bienen","actionType":"route","target":"bienen","icon":"Bienen.png","color":"#667788"}]',
        'PageMappings'=>'[{"key":"energie","type":"wfc_item","value":"item2124","label":"Energie"}]',
        'RendererDomains'=>'[{"route":"bienen","title":"Bienen","roomDomain":"been","aplDoc":"doc://existing"}]'
    ];
    public array $attributes=[];
    public array $records=[
        ['source'=>'launch','key'=>'bienen','title'=>'Bienen','actionType'=>'route',
            'target'=>'bienen','icon'=>'Bienen.png','color'=>0x667788],
        ['source'=>'renderer','key'=>'bienen','title'=>'Bienen','actionType'=>'render',
            'target'=>'been'],
        ['source'=>'page','key'=>'energie','title'=>'Energie',
            'actionType'=>'wfc_item','target'=>'item2124'],
        ['source'=>'navigation','key'=>'legacy_dampf','title'=>'Dampf-Backofen',
            'actionType'=>'navigation','target'=>32701]
    ];
    private function visualNavigationRows(): array {return $this->records;}
    private function visualTileIconOptions(): array {
        return [['caption'=>'Standard','value'=>''],['caption'=>'Bienen','value'=>'Bienen.png']];
    }
    private function visualTileColorHex(mixed $color): string {
        return sprintf('#%06X',(int)$color);
    }
    public function ReadPropertyString(string $p): string {return $this->properties[$p];}
    public function WriteAttributeString(string $p,string $v): void {$this->attributes[$p]=$v;}
    public function ReadAttributeString(string $p): string {return $this->attributes[$p]??'';}
    public function ReloadForm(): void {}
    public function groups(): array {return $this->visualNavigationGroups();}
}
function checkGroup(bool $ok,string $description): void {
    if(!$ok)throw new RuntimeException($description);
}
$h=new VisualGroupHarness();
$GLOBALS['navHarness']=$h;
$groups=$h->groups();
checkGroup(count($groups)===3,'Expected Bienen merged + Energie + Navigation');
$bienen=array_values(array_filter($groups,static fn($r)=>$r['groupId']==='route:bienen'))[0]??null;
checkGroup(is_array($bienen),'No Bienen group');
checkGroup($bienen['hasLaunch'] && $bienen['hasRenderer'] && !$bienen['hasPage'],
    'Bienen must merge launch and renderer without creating a page');
checkGroup($bienen['parts']==='Startkachel + Renderer','Incorrect summary');
$dialog=json_decode($h->GetVisualNavigationGroupForm('route:bienen'),true,512,JSON_THROW_ON_ERROR);
$captions=array_map(static fn($v)=>$v['caption']??'', $dialog);
foreach(['1. Startkachel','2. Seitenzuordnung','3. Renderer','4. Prüfen'] as $heading) {
    checkGroup(count(array_filter($captions,static fn($x)=>str_contains($x,$heading)))===1,
        'Missing wizard stage '.$heading);
}
checkGroup(str_contains(json_encode($dialog),'"name":"addPage"'),
    'Missing optional page creation');
checkGroup(str_contains(json_encode($dialog),'IAH_OpenVisualNavigationGroupStep'),
    'Wizard must have next/back navigation buttons');
$h->OpenVisualNavigationGroupStep(3);
checkGroup(in_array(['name'=>'VisualNavStage3','field'=>'expanded','value'=>true],
    $h->formUpdates,true),'Next/back did not expand selected wizard stage');
checkGroup(in_array(['name'=>'VisualNavStage1','field'=>'expanded','value'=>false],
    $h->formUpdates,true),'Next/back did not collapse previous wizard stage');
// Identical display name but a different legacy navigation key must still
// appear in one edit dialog, without re-keying the underlying navigation.
$h->records[]=['source'=>'navigation','key'=>'legacy_bienen','title'=>'Bienen',
    'actionType'=>'navigation','target'=>49803,
    'navigationRoom'=>'global','navigationDomain'=>'devices',
    'navigationOrder'=>25,'navigationAliases'=>'Imkerei, Bienenstock',
    'navigationEnabled'=>true];
$merged=$h->groups();
$mergedBienen=array_values(array_filter($merged,static fn($r)=>$r['groupId']==='route:bienen'))[0]??null;
checkGroup(count($merged)===3,'Matching navigation must not create a second Bienen row');
checkGroup($mergedBienen['navigationKey']==='legacy_bienen','Legacy navigation key lost');
checkGroup($mergedBienen['navigationTarget']===49803,'Navigation target lost');
checkGroup($mergedBienen['navigationOrder']===25,'Navigation order lost');
checkGroup($mergedBienen['navigationAliases']==='Imkerei, Bienenstock','Synonyms lost');
checkGroup($mergedBienen['navigationRoom']==='global','Navigation room lost');
checkGroup($mergedBienen['navigationDomain']==='devices','Navigation domain lost');
checkGroup($mergedBienen['navigationEnabled']===true,'Navigation active flag lost');
checkGroup(str_contains($mergedBienen['parts'],'Navigation (EDIT)'),'Merged overview must show navigation');
$mergedForm=json_decode($h->GetVisualNavigationGroupForm('route:bienen'),true,512,JSON_THROW_ON_ERROR);
checkGroup(str_contains(json_encode($mergedForm),'navigationTarget'),
    'Unified dialog must contain navigation object selector');
foreach (['navigationRoom','navigationDomain','navigationOrder',
    'navigationAliases','navigationEnabled'] as $field) {
    checkGroup(str_contains(json_encode($mergedForm),'"name":"'.$field.'"'),
        'Missing navigation editor field '.$field);
}
$h->records[]=['source'=>'navigation','key'=>'legacy_bienen_2','title'=>'Bienen',
    'actionType'=>'navigation','target'=>49804];
$ambiguous=$h->groups();
checkGroup(count($ambiguous)===5,'Ambiguous legacy navigation must remain independently editable');
// Restore the original fixture for the existing multi-property save checks.
array_pop($h->records);
array_pop($h->records);
$changed=$bienen;
$changed['title']='Bienen Übersicht';
$changed['rendererTarget']='bees';
$h->SaveVisualNavigationGroup(json_encode($changed,JSON_THROW_ON_ERROR));
$draft=json_decode($h->attributes['VisualNavDraft']??'',true);
checkGroup(isset($draft['properties']['LaunchCatalog'],$draft['properties']['RendererDomains']),
    'Grouped edits must be staged together in EDIT');
checkGroup(!isset($draft['properties']['PageMappings']),'Unchanged page must not be staged');
checkGroup($h->applyCount===0,'Group editing must never update PROD directly');
$launch=json_decode($draft['properties']['LaunchCatalog'],true);
$render=json_decode($draft['properties']['RendererDomains'],true);
checkGroup($launch[0]['title']==='Bienen Übersicht','EDIT launch change lost');
checkGroup($render[0]['roomDomain']==='bees','EDIT renderer change lost');
checkGroup($render[0]['aplDoc']==='doc://existing','Renderer APL source must be preserved');
$h->PreviewVisualNavigationDraft();
$preview=json_decode($h->attributes['VisualNavDraftPreview']??'',true);
checkGroup(is_array($preview)&&isset($preview['hash']),'EDIT preview not created');
$h->PublishVisualNavigationDraft();
checkGroup($h->applyCount===1,'Exactly one PROD apply expected');
checkGroup(json_decode($h->properties['LaunchCatalog'],true)[0]['title']==='Bienen Übersicht',
    'EDIT launch was not published');
checkGroup($h->ReadAttributeString('VisualNavDraft')==='','EDIT draft must be cleared');
$backup=json_decode($h->attributes['VisualNavBackup']??'',true);
checkGroup(isset($backup['properties']['LaunchCatalog'],$backup['properties']['RendererDomains']),
    'Multi-source publish backup incomplete');
checkGroup(!isset($backup['properties']['PageMappings']),'Unchanged page backup should not be saved');

// Creating a tile through the popup must be a staged EDIT operation.
$h->CreateVisualNavigationTileDraft(json_encode([
    'key'=>'neue_testkachel','title'=>'Neue Testkachel',
    'action'=>'route','target'=>'bienen','icon'=>'',
    'color'=>0x365C89,'resultLayout'=>'auto',
    'speakAnswer'=>true,'useArchive'=>true
],JSON_THROW_ON_ERROR));
$draft=json_decode($h->ReadAttributeString('VisualNavDraft'),true);
checkGroup(isset($draft['properties']['LaunchCatalog']),'Popup tile was not staged');
checkGroup($h->applyCount===1,'Creating a popup tile must not update PROD');
$draftTiles=json_decode($draft['properties']['LaunchCatalog'],true);
checkGroup(count(array_filter($draftTiles,static fn($tile)=>($tile['id']??'')==='neue_testkachel'))===1,
    'New tile must exist exactly once in EDIT');
checkGroup(count(json_decode($h->properties['LaunchCatalog'],true))===1,
    'New tile must not be visible in PROD before publication');
checkGroup($h->ValidateVisualNavigationTileDraft('neue_testkachel','Duplicate','route','bienen')!=='',
    'Duplicate EDIT tile IDs must be rejected');
$h->PreviewVisualNavigationDraft();
checkGroup(str_contains($h->ReadAttributeString('VisualNavNotice'),'NEU LaunchCatalog'),
    'Review must show newly added tile by name');
$h->PublishVisualNavigationDraft();
checkGroup(count(json_decode($h->properties['LaunchCatalog'],true))===2,
    'New tile was not published after explicit confirmation');
checkGroup($h->applyCount===2,'New tile publication must apply exactly once');
// Selecting a Classic WebFront item must stage the chosen ID in EDIT.
$energy=array_values(array_filter($h->groups(),
    static fn($g)=>$g['groupId']==='route:energie'))[0];
$energy['pageInstance']='45135';
$energy['pageTargetChoice']='item9907';
$h->SaveVisualNavigationGroup(json_encode($energy,JSON_THROW_ON_ERROR));
$staged=json_decode($h->ReadAttributeString('VisualNavDraft'),true);
checkGroup(isset($staged['properties']['PageMappings']),
    'Changing a WebFront target must write a page mapping EDIT draft');
checkGroup(json_decode($staged['properties']['PageMappings'],true)[0]['value']==='item9907',
    'Selected WebFront element ID not preserved');
checkGroup(json_decode($h->properties['PageMappings'],true)[0]['value']==='item2124',
    'PROD mapping was modified before approval');
checkGroup($h->applyCount===2,'WebFront dialog OK must not apply instance changes');
$log=json_decode($h->GetVisualNavigationDiagnostics(),true);
checkGroup(is_array($log)&&count($log)>0,'Missing persistent diagnostic entries');
checkGroup((bool)array_filter($log,static fn($row)=>($row['event']??'')==='DIALOG_EDIT_SAVED'),
    'Missing dialog save success log entry');
$h->DiscardVisualNavigationDraft();

$nav=json_decode($h->GetVisualNavigationGroupForm('navigation:legacy_dampf'),true,512,JSON_THROW_ON_ERROR);
checkGroup(str_contains(json_encode($nav),'SelectObject'),'Navigation must use object selector');
checkGroup(str_contains(json_encode($nav),'PROD'),'Navigation must explicitly protect PROD');
echo "PASS: grouped editor, navigation metadata, staged EDIT, preview and safe PROD publish\n";
