<?php
declare(strict_types=1);

class IPSModule {}
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/SystemTreeDiscovery.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/SystemTreeEditorTrait.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/DataSourceEditor.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/src/VoiceRuleLiteralReader.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';

function test(bool $ok,string $message): void {if (!$ok)throw new RuntimeException($message);}
$objects=[];$variables=[];$values=[];$scripts=[];$links=[];$events=[];$archive=[];$log=[];$requests=[];$config=[];
function obj(int $id,int $type,int $parent,string $name,string $ident=''): void {
    $GLOBALS['objects'][$id]=['ObjectType'=>$type,'ObjectParentID'=>$parent,'ObjectName'=>$name,'ObjectIdent'=>$ident];
}
function variable(int $id,int $parent,string $name,string $ident,int $type,mixed $value,string $profile='',int $action=0,int $custom=0): void {
    obj($id,2,$parent,$name,$ident);
    $GLOBALS['variables'][$id]=['VariableType'=>$type,'VariableProfile'=>$profile,'VariableAction'=>$action,'VariableCustomAction'=>$custom];
    $GLOBALS['values'][$id]=$value;
}
function linkTo(int $id,int $parent,string $name,int $target): void {
    obj($id,5,$parent,$name);$GLOBALS['links'][$id]=$target;
}
function IPS_ObjectExists(int $id): bool {return isset($GLOBALS['objects'][$id]);}
function IPS_GetObject(int $id): array {return $GLOBALS['objects'][$id]??[];}
function IPS_GetChildrenIDs(int $id): array {return array_keys(array_filter($GLOBALS['objects'],static fn($x)=>$x['ObjectParentID']===$id));}
function IPS_GetLink(int $id): array {return ['TargetID'=>$GLOBALS['links'][$id]??0];}
function IPS_InstanceExists(int $id): bool {return isset($GLOBALS['objects'][$id])&&$GLOBALS['objects'][$id]['ObjectType']===1;}
function IPS_GetInstance(int $id): array {
    return ['ModuleInfo'=>['ModuleID'=>match($id){
        50000=>RCCSystemTreeDiscovery::CONTROLLER,17597=>'{43192F0B-135B-4CE7-A0A7-1475603F3060}',default=>''}]];
}
function IPS_GetInstanceListByModuleID(string $module): array {
    return $module==='{43192F0B-135B-4CE7-A0A7-1475603F3060}'?[17597]:[];
}
function IPS_GetVariablePresentation(int $id): array {
    return $id===60106?['SUFFIX'=>' °C']:[];
}
function IPS_GetConfiguration(int $id): string {return json_encode($GLOBALS['config'][$id]??[]);}
function IPS_GetReferenceList(int $id): array {
    if ($id!==50000)throw new LogicException('not an instance with references');
    return [68000];
}
function IPS_VariableExists(int $id): bool {return isset($GLOBALS['variables'][$id]);}
function IPS_GetVariable(int $id): array {return $GLOBALS['variables'][$id]??[];}
function IPS_GetEvent(int $id): array {return $GLOBALS['events'][$id]??[];}
function IPS_GetScriptContent(int $id): string {return $GLOBALS['scripts'][$id]??'';}
function IPS_ScriptExists(int $id): bool {return isset($GLOBALS['scripts'][$id]);}
function IPS_SetScriptContent(int $id,string $source): bool {$GLOBALS['scripts'][$id]=$source;return true;}
function GetValue(int $id): mixed {return $GLOBALS['values'][$id]??null;}
function IPS_VariableProfileExists(string $profile): bool {return $profile!=='';}
function IPS_GetVariableProfile(string $profile): array {return ['Suffix'=>match($profile){
    '~Temperature'=>' °C','~Watt'=>' W','~Humidity'=>' %','IPSBW.DurationMin'=>' min',default=>''}];}
function IPS_LogMessage(string $tag,string $msg): void {$GLOBALS['log'][]=[$tag,$msg];}
function RequestAction(int $id,mixed $value): bool {$GLOBALS['requests'][]=[$id,$value];return true;}
function AC_GetAggregationVariables(int $id,bool $active): array {
    return array_map(static fn($vid)=>['VariableID'=>$vid],array_keys($GLOBALS['archive']));
}
function AC_GetLoggedValues(int $archiveId,int $id,int $from,int $to,int $limit): array {
    $rows=array_values(array_filter($GLOBALS['archive'][$id]??[],static fn($r)=>$r['TimeStamp']>=$from&&$r['TimeStamp']<=$to));
    usort($rows,static fn($a,$b)=>$b['TimeStamp']<=>$a['TimeStamp']);
    return array_slice($rows,0,$limit);
}
function record(int $id,int $ts,mixed $v): void {$GLOBALS['archive'][$id][]=['TimeStamp'=>$ts,'Value'=>$v];}

obj(41578,0,0,'Bewässerung');obj(14136,1,41578,'Kreise Manuell');obj(14872,1,41578,'Kreise Status');
obj(40892,1,41578,'Bodenfeuchte');obj(26483,1,41578,'Allgemein');obj(51071,1,41578,'Plan');
obj(50000,1,0,'IPSBewaesserung');obj(61000,1,0,'Wetterstation');obj(17597,1,0,'Archive Control');
obj(63000,1,0,'Messung');obj(65000,3,41578,'Frostschutz');obj(65001,4,65000,'Frostschutzregel');
obj(65002,3,0,'Heizung bewerten');obj(70000,0,0,'Sonstiges');
variable(50104,50000,'Manuell Hecke Ost','Manuell4',0,false,'~Switch',50000);
variable(50204,50000,'Status Hecke Ost','Status4',0,false);
variable(50304,50000,'Info Hecke Ost','Info4',3,'Manuell ausgeschaltet');
variable(50404,50000,'Dauer Hecke Ost','Dauer4',1,5,'IPSBW.DurationMin',50000);
variable(50504,50000,'DauerStart Hecke Ost','DauerStart4',1,10,'IPSBW.DurationMin');
variable(50600,50000,'Automatik Gesamtsystem','GesamtAutomatik',0,false,'~Switch',50000);
variable(50700,50000,'Pumpenstatus Modul','PumpeStatus',0,true);
variable(50800,50000,'Pumpe manuell','PumpeManuell',0,false,'~Switch',50000,1);
variable(60101,61000,'KNX Status','Power',0,false);
variable(60102,61000,'Kanal 1','Soil',1,56,'~Humidity');
variable(60103,61000,'Schaltschrank Verteiler','PowerWatts',2,15.1,'~Watt');
variable(60104,61000,'Temperatur','Temp1',2,9.2,'~Temperature');
variable(60105,61000,'Temperatur','Temp2',2,16.4,'~Temperature');
variable(60106,61000,'Temperatur','Temp3',2,7.9);
variable(60107,61000,'Temperatur Sollwert','TempSet',2,11.0,'~Temperature');
variable(60108,61000,'Winterbetrieb','Winterbetrieb',0,true);
variable(60109,61000,'Kanal 3','Sensor3',2,4.5);
variable(50901,50000,'DauerStart Kreis 4 Hecke Ost','',1,10,'IPSBW.DurationMin');
variable(50902,50000,'Automatik Nebenstelle','',0,false);
variable(50903,50000,'Status Nebenstelle (EIN/AUS)','',0,false);
variable(63101,63000,'Rohrbegleitheizung Leistung','HeaterPower',2,1.2,'~Watt');
variable(63102,63000,'Rohrbegleitheizung Status','HeaterFeedback',0,false,'~Switch');
variable(63103,63000,'Rohrbegleitheizung Soll','HeaterSet',0,true,'~Switch',63000);
variable(63104,63000,'API-Token','Token',3,'private-test-secret');
variable(63105,63000,'Netzleistung','Power',2,15,'~Watt');
variable(63106,63000,'Nächster Start Bewässerung','NextStart',1,0);
variable(68000,70000,'Ventilaktor','Valve',0,false);
linkTo(14137,14136,'Manuell Kreis 4 Hecke Ost',50104);
linkTo(14873,14872,'Status Kreis 4 Hecke Ost',50204);
linkTo(14874,14872,'Kreisbeschreibung',50304);
linkTo(40893,40892,'Hecke Ost (1)(433)',60102);
linkTo(40894,40892,'Weiterer Link',40893);
linkTo(26484,26483,'Schaltschrank Verteiler',60103);
linkTo(26485,26483,'Pumpenschacht oben',60104);
linkTo(26486,26483,'Pumpenschacht unten',60105);
linkTo(26487,26483,'Verteiler',60106);
linkTo(26488,26483,'Solltemperatur',60107);
linkTo(26489,26483,'Winterbetrieb',60108);
linkTo(55268,41578,'Garten Pumpe Status',60101);
linkTo(55269,41578,'Rohrbegleitheizung Status',63102);
linkTo(55270,41578,'Rohrbegleitheizung Hand',63103);
linkTo(55271,41578,'Zeitplan',63106);
linkTo(55272,41578,'Zirkulärer Link',41578);
linkTo(55273,41578,'Fehlendes Objekt',99999);
$config[50000]=['AktorID4'=>68000,'UnrelatedSecret'=>63104];
$scripts[65000]= <<<'SRC'
<?php
// RequestAction(63105,true);
$debug='GetValue(63105)';
$test=63105;
GetValue(63101);
\SetValue(63103, true);
IPS_RunScriptEx(65002,[]);
$this->GetValue(63105);
GetValue(63101 + 1);
SRC;
$scripts[65002]="<?php GetValue(63104); IPS_RunScript(65000);";
$events[65001]=['EventType'=>1,'EventActive'=>true,'NextRun'=>1,'LastRun'=>1];
$bindings=RCCSystemTreeDiscovery::scriptRefs('<?php $id=63101; GetValue($id); $change=63105; $change++; GetValue($change);');
test(isset($bindings[63101]['read_binding'])&&!isset($bindings[63105]),'literal local bindings are hints, mutations disqualify');
$scan=RCCSystemTreeDiscovery::scan(41578);$pts=$scan['points'];
test(count($scan['scripts'])===2&&count($scan['events'])===1,'Scripts, cycle and event');
test(isset($pts[63101])&&$pts[63101]['role']==='heaterSignal'&&!$pts[63101]['enabled'],'script value found but not enabled');
test(!isset($pts[63104],$pts[63105]),'secrets and unrelated literals excluded');
test($scan['actuators'][50000]['AktorID4']===68000,'actual controller reference');
test($pts[50104]['actionCandidate']&&$pts[50204]['zone']===4&&!$pts[50800]['actionCandidate'],'identity, zone and disabled action');
test($pts[50901]['role']==='configuredDuration'&&$pts[50901]['zone']===4
    &&$pts[50902]['role']==='zoneAutomatic'&&$pts[50902]['zone']===11
    &&$pts[68000]['role']==='zoneActuator'&&!$pts[68000]['enabled'],'label-based grouping and actuator mapping');
test($pts[60102]['zone']===0&&count($pts[60102]['aliases'])>=2,'duplicate aliases and misleading sensor numbers');
test($pts[60103]['role']==='power'&&$pts[60104]['role']==='stationTemperature'
    &&$pts[60106]['role']==='stationTemperature'&&$pts[60107]['role']==='winterSetting','sensor physical types and native presentation');
test($pts[63102]['role']==='heaterStatus'&&$pts[63103]['role']!=='heaterStatus','actual vs command');
test(count($scan['notes'])===1,'broken link is reported');

class Editor {
    use RCCSystemTreeEditorTrait;
    public array $attr=[],$updates=[];
    public function ReadPropertyInteger(string $key): int {return ['RoomsCatalogEditScriptID'=>10001,'RoomsCatalogScriptID'=>10002][$key]??0;}
    public function ReadAttributeString(string $key): string {return $this->attr[$key]??'';}
    public function WriteAttributeString(string $key,string $value): void {$this->attr[$key]=$value;}
    public function UpdateFormField(string $name,string $key,mixed $val): void {$this->updates[$name.'.'.$key]=$val;}
    private function catalogBackupCurrentEdit(int $id,string $content,string $reason): int {IPS_SetScriptContent(10003,$content);return 10003;}
    private function reloadAllFromCatalog(): void {}
    private function RefreshVisibleLists(): void {}
    private function huaweiEditorArchiveId(): int {return 17597;}
}
$initial="<?php return ['rooms'=>[], 'dataSources'=>['huawei_1'=>['provider'=>'HuaweiPV']]];";
$scripts[10001]=$initial;$scripts[10002]=$initial;
$ed=new Editor();
ob_start();$ed->PreviewSystemTree(41578);$ed->ImportSystemTreeToEdit(41578);$response=ob_get_clean();
$catalog=IAHVoiceRuleLiteralReader::read($scripts[10001]);
test(isset($catalog['dataSources']['irrigation_41578'],$catalog['dataSources']['huawei_1'])
    &&$scripts[10002]===$initial&&$scripts[10003]===$initial,'EDIT only with backup and Huawei untouched: '.$response);
$key='irrigation_41578';
ob_start();
$ed->ExportSystemTreeJson(41578);
$ed->AddSystemTreeVariable($key,60109,'stationTemperature','Pumpenschacht extern');
$ed->PreviewSystemTree(41578);
$ed->ImportSystemTreeToEdit(41578);
ob_end_clean();
$export=json_decode($ed->updates['SystemTreeJson.value'],true,512,JSON_THROW_ON_ERROR);
test($export['archiveId']===17597 && $export['scan']['sourceRootId']===41578
    && isset($export['scan']['points'][0]['archive']) && !str_contains(json_encode($export),'private-test-secret')
    && $scripts[10002]===$initial,'copyable read-only diagnostics');
$supplement=IAHVoiceRuleLiteralReader::read($scripts[10001])['dataSources'][$key]['points'][60109];
test($supplement['unit']==='°C'&&$supplement['enabled']&&!$supplement['actionCandidate']
    && $supplement['role']==='stationTemperature','supplemental sensor persists across rescan');
ob_start();$ed->RemoveSystemTreeVariable($key,60109);ob_end_clean();
test(!isset(IAHVoiceRuleLiteralReader::read($scripts[10001])['dataSources'][$key]['points'][60109]),
    'supplemental sensor can be removed');
$bad=['enabled'=>true,'allowAction'=>true,'role'=>'heaterSignal','zone'=>0,'archiveMode'=>'threshold','on'=>'14','off'=>'5'];
$ok=$bad;$ok['allowAction']=false;
ob_start();$ed->SaveSystemTreePoint($key,63101,json_encode($bad));$blocked=ob_get_clean();
test(str_contains($blocked,'Keine unterstützte Aktion'),'Script-derived point never actionable');
ob_start();$ed->SaveSystemTreePoint($key,63101,json_encode($ok));$ed->SaveSystemTreePoint($key,50104,
    json_encode(['enabled'=>false,'allowAction'=>true,'role'=>'zoneManual','zone'=>4,'archiveMode'=>'none']));ob_end_clean();
$catalog=IAHVoiceRuleLiteralReader::read($scripts[10001]);$s=$catalog['dataSources'][$key];
test($s['historySignals']['heater'][63101]['on']===14.0&&$s['voiceActions']===[50104],'approval and threshold persistence');
$form=json_decode($ed->GetSystemTreeEditForm($key),true,512,JSON_THROW_ON_ERROR);
test(array_is_list($form)&&str_contains(json_encode($form),'Winter und Stationen')
    && str_contains(json_encode($form),'Kreis 4')&&str_contains(json_encode($form),'SelectVariable'),'Grouped List.form fields');
$rows=RCCDataSourceEditor::sourceRows($catalog);
test(count($rows)===2&&$rows[1]['domain']==='bewaesserung','virtual irrigation source alongside Huawei');
ob_start();$ed->PreviewSystemTree(41578);$ed->ImportSystemTreeToEdit(41578);ob_end_clean();
test(IAHVoiceRuleLiteralReader::read($scripts[10001])['dataSources'][$key]['voiceActions']===[50104],'same verified capability survives rescan');
ob_start();$ed->PreviewSystemTree(41578);$scripts[10001].="\n";$ed->ImportSystemTreeToEdit(41578);$conflict=ob_get_clean();
test(str_contains($conflict,'EDIT wurde geändert'),'refuse stale preview');

$tz=new DateTimeZone('Europe/Vienna');$now=new DateTimeImmutable('2026-11-14 11:19:00',$tz);
$day=(new DateTimeImmutable('2026-11-01 00:00:00',$tz))->getTimestamp();
foreach ([-10=>1,0=>6,3=>20,4=>23,5=>9,6=>8,7=>4,8=>19,9=>20] as $dt=>$v)record(63101,$day+$dt,$v);
$on=(new DateTimeImmutable('2026-10-17 10:33:00',$tz))->getTimestamp();
foreach ([-30=>true,-20=>false,0=>true,1=>true,40=>false] as $dt=>$v)record(60101,$on+$dt,$v);
$ask=static fn($q,$d='')=>IAHIrrigationQuery::query($catalog,$q,$d,17597,microtime(true)+4,$now);
$winter=$ask('Wie geht es meiner Bewässerung?')['answer'];
test(str_contains($winter,'Pumpe aus')&&str_contains($winter,'alle erfassten Kreise aus')
    &&str_contains($winter,'Pumpenschacht oben 9,2 Grad')&&!str_contains($winter,'Bodenfeuchte'),'winter summary uses live feedback');
$values[60108]=false;
test(str_contains($ask('Wie geht es meiner Bewässerung?')['answer'],'Bodenfeuchte'),'actual mode beats month');
$values[60108]=true;
test(str_contains($ask('Wie kalt ist es in den jeweiligen Stationen?')['answer'],'Verteiler 7,9 Grad'),'all stations');
test($ask('Wie kalt ist es im Pumpenschacht unten?')['answer']==='Pumpenschacht unten 16,4 Grad.','specific station');
test(str_contains($ask('Ist noch alles aus?','bewaesserung')['answer'],'Pumpe aus'),'contextual status');
test(str_contains($ask('Wie oft hat die Rohrbegleitheizung diesen Winter eingeschaltet?')['answer'],'2 Einschaltungen'),'exact analog count across hysteresis');
test($ask('Wann war die Pumpe zuletzt eingeschaltet?')['answer']==='17.10.2026','pump date only, actual transition');
test(IAHIrrigationQuery::query($catalog,'Wann war die Pumpe zuletzt eingeschaltet?','',0,microtime(true)+4,$now)['answer']
    ==='17.10.2026','automatic Archive Control selection');
test($ask('Wann wurde die Gartenpumpe eingeschaltet?')['answer']==='17.10.2026','pump question without zuletzt');
test(str_contains($ask('Wo ist der Boden am trockensten?')['answer'],'Hecke Ost'),'soil minimum');
test(str_contains($ask('Wie feucht ist Hecke Ost?')['answer'],'56 Prozent'),'soil alias without parenthetical IDs');
test($ask('Bewässerung')===null&&$ask('Wo ist es im Haus am kältesten?','bewaesserung')===null,'existing routing stays intact');
test(iah_vir_classify(['text'=>'Läuft die Bewässerung?'])['category']==='query','live router question');
$bound=IAHIrrigationQuery::edges([
    ['TimeStamp'=>2,'Value'=>true],['TimeStamp'=>3,'Value'=>false],['TimeStamp'=>3,'Value'=>true],
    ['TimeStamp'=>4,'Value'=>true],['TimeStamp'=>5,'Value'=>false],['TimeStamp'=>6,'Value'=>true]],
    ['mode'=>'binary'],2,9);
test($bound['count']===1&&$bound['last']===6,'no initial or same-second false edges');
$preview=IAHIrrigationQuery::command($catalog,'Stoppe Kreis 4',true,false);
test($preview['reason']==='preview'&&$requests===[],'dry run without mutation');
test(IAHIrrigationQuery::command($catalog,'Bewässere Kreis 4 zehn Minuten',true,true)['reason']==='unsupported_command'
    &&$requests===[],'no timed unbounded actuation');
test(IAHIrrigationQuery::command($catalog,'Stoppe Kreis 4',false,true)['reason']==='not_allowed'&&$requests===[],'global opt-in');
$values[50600]=true;
test(IAHIrrigationQuery::command($catalog,'Stoppe Kreis 4',true,true)['reason']==='automatic_or_unknown'&&$requests===[],'no silent auto override');
$values[50600]=false;
test(IAHIrrigationQuery::command($catalog,'Stoppe Kreis 4',true,true)['reason']==='confirmed'
    &&$requests===[[50104,false]],'only action through recognized controller');
$variables[50104]['VariableCustomAction']=1;
test(IAHIrrigationQuery::command($catalog,'Stoppe Kreis 4',true,true)['reason']==='changed_action'
    &&count($requests)===1,'changed action fails closed');
$r=iah_rc_voice('Wie kalt ist es im Pumpenschacht oben?',['archive_control_id'=>17597],$catalog);
test($r['answer']==='Pumpenschacht oben 9,2 Grad.'&&$r['answerSource']==='irrigation','voice backend without AI');
test(str_contains(json_encode($log),'KIRoomsCatalog.irrigation.query'),'shared Alexa log trace');
echo "PASS V3.8.16: link/script graph, checked EDIT import, seasonal voice, rising edges and action guards\n";
