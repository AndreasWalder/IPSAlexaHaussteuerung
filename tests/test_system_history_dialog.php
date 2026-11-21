<?php
declare(strict_types=1);
require __DIR__.'/test_weather_report.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/src/VoiceRuleEditorTrait.php';
$GLOBALS['historySeries']=[];$GLOBALS['historyCalls']=[];$GLOBALS['historyLogging']=[];
$GLOBALS['instances'][17597]=['ModuleInfo'=>['ModuleID'=>'{43192F0B-135B-4CE7-A0A7-1475603F3060}']];
function AC_GetAggregationVariables(int $id,bool $active):array {
 $out=[];foreach($GLOBALS['historySeries'] as $var=>$rows){$ts=array_column($rows,'TimeStamp');$out[]=['VariableID'=>$var,'AggregationActive'=>true,'FirstTime'=>$ts===[]?0:min($ts),'LastTime'=>$ts===[]?0:max($ts)];}return $out;
}
function AC_GetLoggedValues(int $a,int $id,int $start,int $end,int $limit):array {
 $GLOBALS['historyCalls'][]=[$id,$start,$end,$limit];$rows=array_values(array_filter($GLOBALS['historySeries'][$id]??[],static fn($r)=>$r['TimeStamp']>=$start&&$r['TimeStamp']<=$end));usort($rows,static fn($a,$b)=>$b['TimeStamp']<=>$a['TimeStamp']);return array_slice($rows,0,$limit);
}
function AC_GetLoggingStatus(int $archive,int $id):bool{return $GLOBALS['historyLogging'][$id]??true;}
function hr(int $id,int $time,mixed $value):void{$GLOBALS['historySeries'][$id][]=['TimeStamp'=>$time,'Value'=>$value];}
$now=new DateTimeImmutable('now',new DateTimeZone('Europe/Vienna'));$day=$now->setTime(0,0)->getTimestamp();
foreach([[-86460,2.0],[-60000,-1.0],[-50000,1.0],[-60,2.0],[3600,1.0],[7200,-2.0],[7500,-3.0],[10800,1.0],[14400,-1.0],[15000,-2.5]] as [$offset,$value])hr(44047,$day+$offset,$value);
$history=IAHSystemHistory::query($catalog,'wie oft war heute schon frost',17597,microtime(true)+3,null,$now);
check(str_contains($history['answer'],'2 Frostphasen')&&str_contains($history['answer'],'Tiefstwert -3'),'Logged frost question counts episodes, not live temperature or individual negative samples');
check($history['historyDiagnostics']['ids']===[44047]&&!$history['historyDiagnostics']['partial'],'Published outdoor feedback is dynamically resolved with a complete baseline');
check(str_contains(iah_rc_voice('wie oft war heute schon frost',['archive_control_id'=>17597],$catalog)['answer'],'2 Frostphasen'),'Controller selects archive before live weather');
check(str_contains(IAHSystemHistory::query($catalog,'Wie oft war gestern Frost?',17597,microtime(true)+3,null,$now)['answer'],'1 Frostphasen'),'Yesterday uses its own Vienna calendar interval');
$denied=$catalog;$denied['dataSources'][IAHWeatherStation::KEY]['devices']['station_49084']['variables']['var_44047']['enabled']=false;
$GLOBALS['historyCalls']=[];IAHSystemHistory::query($denied,'Wie oft war heute Frost?',17597,microtime(true)+3,null,$now);
check(!in_array(44047,array_column($GLOBALS['historyCalls'],0),true),'Denied published feedback is never read from the archive');
$GLOBALS['objects'][800]=['ObjectType'=>0,'ParentID'=>0,'ObjectName'=>'Bewässerung'];
sensor(800,804,'eastStatus','Kreis 4 Hecke Ost',false);sensor(800,805,'southStatus','Kreis 8 Rasen Süd',false);sensor(800,806,'nextStart','Nächster Start',time()+86400);
$points=[];foreach([804=>'zoneStatus',805=>'zoneStatus',806=>'nextStart'] as $id=>$role){$m=IAHHouseholdDevices::metric($id);$m['label']=$m['title'];$m['role']=$role;$m['zone']=$id===804?4:8;$points[$id]=$m;}
$catalog['dataSources']['irrigation_test']=['provider'=>'ObjectTreeIrrigation','domain'=>'bewaesserung','kiEnabled'=>true,'sourceRootId'=>800,'title'=>'Bewässerung','points'=>$points];
foreach([804,805] as $id)foreach([[-86400,false],[3600,true],[3700,true],[4000,false]] as [$offset,$v])hr($id,$day+$offset,$v);
hr(805,$day+7200,true);hr(805,$day+7300,false);
$last=iah_rc_voice('wann wurde das letzte mal gegossen',['archive_control_id'=>17597],$catalog);
check(str_contains($last['answer'],'Kreis 8 Rasen Süd')&&str_contains($last['answer'],$now->setTimestamp($day+7200)->format('H:i'))&&!str_contains($last['answer'],'nächste'),'Bare watering history searches actual archived feedback, never future schedules');
check(str_contains(iah_rc_voice('wann wurde das letzte mal die bewässerung ausgeführt',['archive_control_id'=>17597],$catalog)['answer'],'aktiv protokolliert'),'Explicit logged wording resolves to watering history');
$counts=IAHSystemHistory::query($catalog,'Wie oft wurde heute bewässert?',17597,microtime(true)+3,null,$now);
check(str_contains($counts['answer'],'Kreis 4 Hecke Ost: 1 nachgewiesene Einschaltungen')&&str_contains($counts['answer'],'Kreis 8 Rasen Süd: 2 nachgewiesene Einschaltungen'),'Repeated ON samples never create extra starts');
$phase=IAHSystemHistory::evaluate(['seed'=>-1,'complete'=>true,'values'=>[110=>['one'=>-2],120=>['one'=>1],140=>['one'=>-3]]],['kind'=>'numeric','unit'=>'°C'],100,200,true);
check($phase['count']===2&&$phase['duration']===80,'Overnight frost is one continuing episode plus the subsequent episode');
$partial=IAHSystemHistory::evaluate(['seed'=>null,'complete'=>false,'values'=>[110=>['one'=>-2],120=>['one'=>-3,'two'=>2],140=>['one'=>-3]]],['kind'=>'numeric','unit'=>'°C'],100,200,true);
check($partial['partial']&&$partial['count']===1,'Ambiguous samples never invent another frost phase');
$devices=[];for($i=0;$i<80;$i++)$devices['temp_'.$i]=['id'=>'temp_'.$i,'name'=>'Temp '.$i,'room'=>'House','domain'=>'heizung','reads'=>['temperature'=>44047],'type'=>'temperature'];
$devices['zone']=['id'=>'zone','name'=>'Rasen','room'=>'Außen','domain'=>'bewaesserung','reads'=>['power'=>805],'type'=>'switch','stateMapping'=>['on'=>true,'off'=>false]];
$generic=iah_archive_query($devices,['property'=>'power','operation'=>'count_active','period'=>'today'],'Wie oft war die Bewässerung an?',17597);
check($generic['ok']&&$generic['matchedDevices']===1&&$generic['devices'][0]['count']===2,'Role and actual logging are filtered before broadness checks; ON samples are collapsed');
final class HistoryDialogFixture
{
 use IAHVoiceRuleEditorTrait;
 public int $InstanceID=45;public array $attributes=[];public string $script;
 public function __construct(array $catalog){$this->script='<?php return '.var_export($catalog,true).';';}
 private function getScriptContent($id):string{return $this->script;}
 public function ReadAttributeString($name):string{return $this->attributes[$name]??'{}';}
 public function WriteAttributeString($name,$value):void{$this->attributes[$name]=$value;}
}
$d=new HistoryDialogFixture($catalog);
$d->RememberAlexaHouseQuestion('session','echo','wann wurde das letzte mal gegossen',true);
check($d->IsAlexaHouseLocationReply('session','echo','bewässerung',50),'Bare domain answer is recognized before legacy navigation');
$plan=json_decode($d->PrepareAlexaHouseQuestion('session','echo','bewässerung',50),true);
check($plan['question']==='wann wurde das letzte mal gegossen'&&$plan['scope']['domain']==='bewaesserung','Original question and domain survive the clarification');
check(str_contains(iah_rc_voice($plan['question'],['archive_control_id'=>17597,'question_scope'=>$plan['scope']],$catalog)['answer'],'Rasen Süd'),'Actual second turn answers the stored watering question');
$d->RememberAlexaHouseQuestion('session','echo','wie oft war heute schon frost');
check($d->IsAlexaHouseLocationReply('session','echo','und gestern',50),'Time follow-up is recognized after a completed answer');
$plan=json_decode($d->PrepareAlexaHouseQuestion('session','echo','und gestern',50),true);
check(str_contains($plan['question'],'gestern')&&!str_contains($plan['question'],'heute'),'Time follow-up replaces the old period');
$d->RememberAlexaHouseQuestion('session','echo','Wie wird das Wetter?');
$plan=json_decode($d->PrepareAlexaHouseQuestion('session','echo','am Mittwoch',50),true);
check(str_contains($plan['question'],'mittwoch'),'Forecast weekday reply is not treated as a room');
$d->RememberAlexaHouseQuestion('session','echo','wie geht es meiner heike',true);
check($d->IsAlexaHouseLocationReply('session','echo','die hecke',50),'Published plant-location correction is accepted');
$plan=json_decode($d->PrepareAlexaHouseQuestion('session','echo','die hecke',50),true);
check($plan['scope']['type']==='topic'&&str_contains($plan['question'],'hecke'),'Heike -> Hecke correction is grounded in published sensor names');
check(str_contains(iah_rc_voice($plan['question'],['question_scope'=>$plan['scope']],$catalog)['answer'],'Hecke Ost'),'Corrected second answer reaches sensor data');
$choices=IAHHouseQuestionContext::archiveChoices($catalog);
$d->RememberAlexaHouseQuestion('session','echo','Wann wurde gegossen?',true,json_encode($choices));
check($d->IsAlexaHouseLocationReply('session','echo','die zweite Option',50),'Ordinal replies use the common dialog resolver');
$plan=json_decode($d->PrepareAlexaHouseQuestion('session','echo','die zweite Option',50),true);
check($plan['scope']['domain']===$choices[1]['scope']['domain']&&$plan['scope']['type']==='domain','Numbered domain choice preserves its type, not a synthetic household ID');
$d->RememberAlexaHouseQuestion('session','echo','Wann wurde gegossen?',true);
check(!$d->IsAlexaHouseLocationReply('session','other echo','bewässerung',50),'Pending context is isolated by Echo and session');
check(!$d->IsAlexaHouseLocationReply('session','echo','Öffne die Bewässerung',50),'Explicit navigation keeps its meaning');
$d->RememberAlexaHouseQuestion('session','echo','Wann wurde zuletzt gegossen?',true);
$plan=json_decode($d->PrepareAlexaHouseQuestion('session','echo','bei der Bewässerung',50),true);
check(($plan['scope']['domain']??'')==='bewaesserung','Spoken prepositions and articles do not break a domain reply');
$d->RememberAlexaHouseQuestion('session','echo','wie geht es meiner heike',true);
$plan=json_decode($d->PrepareAlexaHouseQuestion('session','echo','ich meine die Pflanzen',50),true);
check(str_contains($plan['question'],'wie geht es meiner heike')&&str_contains($plan['question'],'ich meine die Pflanzen'),'Free clarifications retain both the original question and the correction for AI reasoning');
$installer=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/module.php');
check(str_contains($installer,'// IAH_SYSTEM_HISTORY_INSERT')&&str_contains($installer,'resources/helpers/SystemHistoryQuery.php'),'Production installer embeds the history helper');
$helper=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/SystemHistoryQuery.php');
$helper=preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/','',$helper);
$template=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php');
$embedded=str_replace('// IAH_SYSTEM_HISTORY_INSERT',"if (!class_exists('IAHSystemHistory',false)) {\n".$helper."\n}",$template);
token_get_all($embedded,TOKEN_PARSE);
check(str_contains($embedded,'final class IAHSystemHistory'),'Bundled production controller parses with the embedded helper');
echo "PASS dynamic archives, frost phases, watering history, role discovery, domain/topic/time/numbered second turns and session isolation\n";
