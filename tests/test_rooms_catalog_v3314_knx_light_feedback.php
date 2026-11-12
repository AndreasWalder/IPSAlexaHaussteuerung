<?php
declare(strict_types=1);
require __DIR__.'/../RoomsCatalogConfigurator/libs/SafeAutoRepair.php';
function ck314(bool $ok,string $why):void {if (!$ok) throw new RuntimeException($why);}

$cfg=[
    'title'=>'OG WC Deckenlicht',
    'state'=>28641,
    'toggle'=>28493,
    'kiEnabled'=>true,
    'kiReadOnly'=>true,
    'customValue'=>'KEEP',
    'ai'=>['version'=>1,'type'=>'light',
        'variables'=>['power'=>28641,'status'=>28641,'control'=>28493],
        'stateMapping'=>['on'=>true,'off'=>false],
        'review'=>['status'=>'pending']]
];
$row=[
    'aiType'=>'light',
    'ai'=>$cfg['ai'],
    'state'=>$cfg['state'],
    'kiReadOnly'=>true
];
$scope=[
    ['id'=>28641,'name'=>'Steuerung','ident'=>'Value0','profile'=>'~PDPT2.KNX',
        'type'=>0,'action'=>true,
        'associations'=>[
            ['value'=>false,'name'=>'Keine Priorität'],
            ['value'=>true,'name'=>'Priorität']]],
    ['id'=>41827,'name'=>'Funktionswert','ident'=>'Value1','profile'=>'~Switch',
        'type'=>0,'action'=>false,
        'associations'=>[
            ['value'=>false,'name'=>'Aus'],
            ['value'=>true,'name'=>'An']]],
    ['id'=>28493,'name'=>'WC Deckenlicht','ident'=>'Value','profile'=>'~Switch',
        'type'=>0,'action'=>true,
        'associations'=>[
            ['value'=>false,'name'=>'Aus'],
            ['value'=>true,'name'=>'An']]]
];
$score=static fn(array $v,string $role):int=>0;
$proposal=RCCSafeAutoRepair::propose($row,$scope,$score);
ck314($proposal===['power'=>41827,'status'=>41827],
    'A unique read-only Value1/Boolean function must replace the priority bit');
$catalog=['og_wc'=>['display'=>'OG WC','domains'=>[
    'licht'=>['switches'=>['wc_deckenlicht'=>$cfg]]
]]];
$key='og_wc|licht|switches|wc_deckenlicht';
$repaired=RCCSafeAutoRepair::applyBindings($catalog,[$key=>$proposal]);
$entry=$repaired['og_wc']['domains']['licht']['switches']['wc_deckenlicht'];
ck314($entry['state']===41827
    && $entry['ai']['variables']['power']===41827
    && $entry['ai']['variables']['status']===41827
    && $entry['ai']['variables']['control']===28493
    && $entry['toggle']===28493
    && $entry['kiEnabled']===true
    && $entry['kiReadOnly']===true
    && $entry['customValue']==='KEEP'
    && $entry['ai']['stateMapping']===['on'=>true,'off'=>false]
    && $entry['ai']['review']['status']==='pending',
    'The correction must preserve commands, KI permissions and extra metadata');
ck314($catalog['og_wc']['domains']['licht']['switches']['wc_deckenlicht']['state']===28641,
    'Application modified original input catalog');

$withoutAction = $scope;
$withoutAction[1]['action']=true;
ck314(RCCSafeAutoRepair::propose($row,$withoutAction,$score)===[],
    'A writable Value1 could be a command and must not be trusted as readback');
$duplicate=$scope;
$duplicate[]=$scope[1];
$duplicate[3]['id']=42771;
ck314(RCCSafeAutoRepair::propose($row,$duplicate,$score)===[],
    'Multiple candidate feedback values require manual review');
$unconfirmed=$scope;
$unconfirmed[0]['profile']='~Switch';
ck314(RCCSafeAutoRepair::propose($row,$unconfirmed,$score)===[],
    'The original mapping must prove the KNX priority profile');
$claimed=$row;
$claimed['ai']['review']['status']='confirmed';
ck314(RCCSafeAutoRepair::propose($claimed,$scope,$score)===[],
    'An already manually verified device must not be remapped');
$noPermission=$row;
$noPermission['kiReadOnly']=false;
ck314(RCCSafeAutoRepair::propose($noPermission,$scope,$score)===[],
    'KI-writable entries are excluded from automatic rebindings');
ck314(RCCSafeAutoRepair::propose($row,$scope,$score,[41827=>true])===[],
    'A candidate claimed by another device must remain untouched');

$other=['aiType'=>'fan','ai'=>$cfg['ai'],'state'=>28641,'kiReadOnly'=>true];
ck314(RCCSafeAutoRepair::propose($other,$scope,$score)===[],
    'KNX priority workaround may not be generalized to other device types');
$source=file_get_contents(__DIR__.'/../RoomsCatalogConfigurator/module.php');
ck314(str_contains($source,'ExportAIRepairDiagnostics')
    && !str_contains($source,'public function ApplySafeAutoRepair'),
    'Read-only KNX proposal remains available in diagnostic export, but retired automatic PROD mutation must not return');
echo "PASS V3.3.14: cautious KNX DPT2 priority vs Funktionswert, atomic state/AI correction, no KI rights changes\n";
