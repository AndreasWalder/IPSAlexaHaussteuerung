<?php
declare(strict_types=1);

/** Run: php tests/test_visual_navigation_group_wizard.php */
function IPS_HasChanges(int $id): bool { return false; }
function IPS_SetProperty(int $id,string $property,string $value): void {
    $GLOBALS['navHarness']->properties[$property]=$value;
}
function IPS_ApplyChanges(int $id): void { $GLOBALS['navHarness']->applyCount++; }

require_once dirname(__DIR__).'/IPSAlexaHaussteuerung/src/VisualNavigationGroupTrait.php';

final class VisualGroupHarness
{
    use IAHVisualNavigationGroupTrait;
    public int $InstanceID=16159;
    public int $applyCount=0;
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
$changed=$bienen;
$changed['title']='Bienen Übersicht';
$changed['rendererTarget']='bees';
$h->SaveVisualNavigationGroup(json_encode($changed,JSON_THROW_ON_ERROR));
$launch=json_decode($h->properties['LaunchCatalog'],true);
$render=json_decode($h->properties['RendererDomains'],true);
checkGroup($launch[0]['title']==='Bienen Übersicht','Launch change lost');
checkGroup($render[0]['roomDomain']==='bees','Renderer change lost');
checkGroup($render[0]['aplDoc']==='doc://existing','Renderer APL source must be preserved');
checkGroup($h->applyCount===1,'Exactly one ApplyChanges expected');
$backup=json_decode($h->attributes['VisualNavBackup']??'',true);
checkGroup(isset($backup['properties']['LaunchCatalog'],$backup['properties']['RendererDomains']),
    'Multi-source backup incomplete');
checkGroup(!isset($backup['properties']['PageMappings']),'Unchanged page should not be saved');

$nav=json_decode($h->GetVisualNavigationGroupForm('navigation:legacy_dampf'),true,512,JSON_THROW_ON_ERROR);
checkGroup(str_contains(json_encode($nav),'SelectObject'),'Navigation must use object selector');
checkGroup(str_contains(json_encode($nav),'PROD'),'Navigation must explicitly protect PROD');
echo "PASS: one entry per key, numbered wizard, optional links, safe multi-source save and backup\n";
