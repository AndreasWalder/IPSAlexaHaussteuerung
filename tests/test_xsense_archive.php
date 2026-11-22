<?php
declare(strict_types=1);
$GLOBALS['xsHistory']=[];$GLOBALS['xsArchiveReads']=[];$GLOBALS['xsLogging']=[];
function AC_GetAggregationVariables(int $id,bool $active):array{
    $out=[];foreach($GLOBALS['xsHistory'] as $var=>$rows){$ts=array_column($rows,'TimeStamp');$out[]=['VariableID'=>$var,'FirstTime'=>min($ts),'LastTime'=>max($ts)];}return $out;
}
function AC_GetLoggedValues(int $a,int $id,int $start,int $end,int $limit):array{
    $GLOBALS['xsArchiveReads'][]=$id;$rows=array_values(array_filter($GLOBALS['xsHistory'][$id]??[],static fn($r)=>$r['TimeStamp']>=$start&&$r['TimeStamp']<=$end));
    usort($rows,static fn($a,$b)=>$b['TimeStamp']<=>$a['TimeStamp']);return array_slice($rows,0,$limit);
}
function AC_GetLoggingStatus(int $a,int $id):bool{return $GLOBALS['xsLogging'][$id]??true;}
function xr(int $id,int $at,mixed $v):void{$GLOBALS['xsHistory'][$id][]=['TimeStamp'=>$at,'Value'=>$v];}
require __DIR__.'/test_xsense_security.php';
$GLOBALS['instances'][3000]=['ModuleInfo'=>['ModuleID'=>'{43192F0B-135B-4CE7-A0A7-1475603F3060}'],'InstanceStatus'=>102];
$xs=IAHXSense::merge($catalog);
// Reset all live detector states after the alarm and offline tests in the prerequisite.
foreach(IAHXSense::source($xs)['devices'] as $d)foreach($d['variables'] as $m){
    if(in_array($m['role'],['smoke','waterLeak','alarm','muted','lifeEnd'],true))$GLOBALS['values'][$m['id']]=false;
    elseif(in_array($m['role'],['online','connected'],true))$GLOBALS['values'][$m['id']]=true;
}
sensor(900,9001,'window_kitchen','Fenster Küche',false);
sensor(900,9002,'window_living','Fenster Wohnzimmer',1);
$window=static fn($name,$vars,$mapping):array=>['title'=>$name,'kiEnabled'=>true,'kiReadOnly'=>true,'ai'=>['version'=>1,'type'=>'window','variables'=>$vars,'stateMapping'=>$mapping]];
$xs['rooms']['eg']=['display'=>'Erdgeschoss','domains'=>['sensoren'=>['fenster'=>[
    'kitchen'=>$window('Fenster Küche',['open'=>9001],['open'=>true,'closed'=>false]),
    'living'=>$window('Fenster Wohnzimmer',['open'=>9002],['open'=>0,'closed'=>1]),
]]]];
$now=time();xr(9001,$now-7200,1);xr(9001,$now-7000,0);xr(9002,$now-3600,0);xr(9002,$now-3000,1);
$r=iah_rc_voice('wie ist die sicherheit im haus',['archive_control_id'=>3000],$xs);
check(str_contains($r['answer'],'Alle 2 erfassten Fenster sind geschlossen')&&str_contains($r['answer'],'Zuletzt offen protokolliert war Fenster Wohnzimmer'),'Closed-window security report names actual last open window');
check(($r['securityDiagnostics']['windowHistory']['latest']['last']??0)===$now-3600,'Last open timestamp is actual archive value, not latest closure or VariableChanged');
check($r['displayIcon']==='shield'&&str_contains(json_encode($r['displayRows']),'Fenster zuletzt offen'),'Security page includes shield and last-window archive row');
$scoped=IAHSecurityQuery::query($xs,'wie ist die Sicherheit im Haus',3000,microtime(true)+2,['type'=>'room','rooms'=>['other'],'name'=>'Anderer Bereich']);
check($scoped['securityDiagnostics']['windows']['count']===0,'Room-scoped security question cannot include windows from unrelated rooms');
$GLOBALS['values'][9001]=true;
$r=iah_rc_voice('wie ist die sicherheit im haus',['archive_control_id'=>3000],$xs);
check(str_contains($r['answer'],'Fenster Küche ist offen')&&!isset($r['securityDiagnostics']['windowHistory']),'Current open window has priority; closed-window history only when all confirmed shut');
$GLOBALS['values'][9001]=false;
$xs['rooms']['eg']['domains']['sensoren']['fenster']['missing']=['title'=>'Fenster unbekannt','kiEnabled'=>true,'kiReadOnly'=>true,'ai'=>['version'=>1,'type'=>'window']];
$r=iah_rc_voice('wie ist die sicherheit im haus',['archive_control_id'=>3000],$xs);
check(!str_contains($r['answer'],'Alle 3')&&str_contains($r['answer'],'Zustand unklar'),'Unknown enabled window prevents all-closed claim');
unset($xs['rooms']['eg']['domains']['sensoren']['fenster']['missing']);
unset($GLOBALS['xsHistory'][9002]);
$r=iah_rc_voice('wie ist die sicherheit im haus',['archive_control_id'=>3000],$xs);
check(str_contains($r['answer'],'Unter den geprüften Werten zuletzt offen protokolliert: Fenster Küche')&&$r['securityDiagnostics']['windowHistory']['partial'],'Missing window archive qualifies global last-open assertion');
$day=(new DateTimeImmutable('now',new DateTimeZone('Europe/Vienna')))->setTime(0,0)->getTimestamp();
xr(2100,$day-10,false);xr(2100,$day+60,true);xr(2100,$day+80,true);xr(2100,$day+120,false);xr(2100,$day+180,true);xr(2100,$day+220,false);
$r=iah_rc_voice('Wie oft gab es heute Rauchalarm bei X-Sense?',['archive_control_id'=>3000],$xs);
$office=array_values(array_filter($r['securityDiagnostics']['alarmHistory']['results'],static fn($a)=>$a['id']===2100))[0];
check($office['count']===2&&!$office['partial'],'Repeated true samples do not invent alarm starts');
check(str_contains($r['answer'],'2 nachgewiesene Alarmstarts'),'Alarm count has explanatory speech');
xr(2400,$now-300,0);xr(2400,$now-200,1.0);xr(2400,$now-100,0.0);
$r=iah_rc_voice('Wann gab es zuletzt Wasseralarm?',['archive_control_id'=>3000],$xs);
check($r['securityDiagnostics']['alarmHistory']['latest']['last']===$now-200&&str_contains($r['answer'],'Wassermelder Küche'),'Historical leak query uses real sensor feedback, not mute command');
xr(2441,$now-20*86400,80.0);xr(2441,$now-86400,60.0);
$GLOBALS['values'][2441]=60.0;
$r=iah_rc_voice('Welcher Akku der X-Sense Geräte ist am schlechtesten?',['archive_control_id'=>3000],$xs);
check(str_contains($r['answer'],'60 Prozent')&&str_contains(json_encode($r['displayRows']),'20 Prozentpunkte'),'Battery comparison includes an archive delta without capacity/lifetime claims');
$deny=$xs;$deny['dataSources']['xsense']['devices']['device_932']['variables']['var_2441']['enabled']=false;
$GLOBALS['xsArchiveReads']=[];iah_rc_voice('Wie ist der Batterieverlauf bei X-Sense?',['archive_control_id'=>3000],$deny);
check(!in_array(2441,$GLOBALS['xsArchiveReads'],true),'Denied battery binding cannot be read through history');
$r=IAHSecurityQuery::query($xs,'wie ist die Sicherheit im Haus',3000,microtime(true)-1);
check($r['securityDiagnostics']['windowHistory']['partial']&&!str_contains($r['answer'],'Zuletzt offen protokolliert war'),'Expired budget never invents complete history');
echo "PASS X-Sense alarm archives, battery trends, inverted window history, last-open security summary, missing coverage and archive read permissions\n";
