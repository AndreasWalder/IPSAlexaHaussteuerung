<?php
declare(strict_types=1);
require __DIR__.'/test_weather_station.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/XSenseEditorTrait.php';
$GLOBALS['objects'][900]=['ObjectType'=>0,'ObjectName'=>'X-Sense-Rauchmelder','ParentID'=>0];
function xd(int $id,string $name):void{
    $GLOBALS['objects'][$id]=['ObjectType'=>1,'ObjectName'=>$name,'ObjectIdent'=>'','ParentID'=>900];
    $GLOBALS['instances'][$id]=['ModuleInfo'=>['ModuleID'=>IAHXSense::MODULE_ID],'InstanceStatus'=>102];
}
xd(901,'Rauchmelder Basisstation X-Sense');
sensor(901,2001,'binary_sensor_alarmstatus','Alarmstatus',false);
sensor(901,2002,'binary_sensor_connected','Connected',true);
sensor(901,2003,'reachable','Erreichbar',true);
sensor(901,2004,'sensor_ipaddress','IP Address','must-never-leak');
sensor(901,2005,'sensor_ssid','SSID','private-wlan');
sensor(901,2006,'binary_sensor_alarmstatus_old','Alarm Status (veraltet)',true);
foreach(['Büro','Dachboden','Eingang','Elternzimmer','Kinderzimmer groß','Kinderzimmer klein','Küche','PC Server Schrank','Wintergarten','Wohnzimmer'] as $i=>$name){
    $id=910+$i;xd($id,'Rauchmelder '.$name);$b=2100+$i*20;
    sensor($id,$b,'binary_sensor_smoke','Rauch',false);
    sensor($id,$b+1,'sensor_battery','Batterie',100.0,' %');
    sensor($id,$b+2,'reachable','Erreichbar',true);
    sensor($id,$b+3,'binary_sensor_islifeend','Is life end',false);
    sensor($id,$b+4,'binary_sensor_mutestatus','Mute Status',false);
    sensor($id,$b+5,'sensor_reporttime','Report Time',time());
    sensor($id,$b+6,'button_test','Test',0);
    sensor($id,$b+7,'sensor_lastselftest','Last Self Test (veraltet)','not_run');
}
foreach(['Küche','Waschmaschine','Whirlpool'] as $i=>$name){
    $id=930+$i;xd($id,'Wassermelder '.$name);$b=2400+$i*20;
    sensor($id,$b,'binary_sensor_smoke','Rauch',false); // The misleading water-device label in the supplied screenshot.
    sensor($id,$b+1,'sensor_battery','Batterie',100.0,' %');
    sensor($id,$b+2,'reachable','Erreichbar',true);
    sensor($id,$b+3,'switch_mute','Mute',false);
}
$xs=IAHXSense::merge($catalog);$source=IAHXSense::source($xs);
check(count($source['devices'])===14,'All ten smoke detectors, three water detectors and base discovered dynamically');
check($source['devices']['device_930']['variables']['var_2400']['role']==='waterLeak','Water alarm is not misclassified by its Rauch label');
check(!isset($source['devices']['device_901']['variables']['var_2004'],$source['devices']['device_901']['variables']['var_2005']),'No IP addresses or SSIDs in catalog');
check($source['devices']['device_901']['variables']['var_2006']['role']==='other','Deprecated hub alarm is never a live alarm');
check($source['devices']['device_910']['variables']['var_2106']['role']==='command','Test buttons remain commands, never feedback');
$rows=RCCDataSourceEditor::sourceRows($xs);$xsrows=array_values(array_filter($rows,static fn($r)=>($r['sourceKey']??'')==='xsense'));
check(count($xsrows)===14&&$xsrows[0]['domain']==='devices'&&$xsrows[0]['group']==='xsense'&&$xsrows[0]['kiReadOnly'],'Editable owner rows in the requested domain/group');
check(count(RCCXSenseCatalog::pendingRows($xs,$catalog))===14,'All new devices appear in pending EDIT changes');
foreach(['wie geht es meiner brandmeldestation','wie geht es meinen wassermeldern','wie ist die sicherheit im haus','welcher akku bei den xsense geräten ist am schlechtesten'] as $q){
    $r=iah_rc_voice($q,[],$xs);
    check($r['handled']&&$r['answerSource']==='house_status'&&$r['displayRows']!==[],'Natural question uses status page: '.$q);
    check(!str_contains($r['answer'],'Achtung:'),'Inactive/deprecated/test values cannot create a false alarm');
}
$r=iah_rc_voice('wie geht es meinen wassermeldern',[],$xs);
check($r['displayIcon']==='water'&&count($r['displayRows'])>=4&&!str_contains(json_encode($r),'must-never-leak'),'Water icon, base connection and all water devices');
$r=iah_rc_voice('wie geht es meiner brandmeldestation',[],$xs);
check($r['displayIcon']==='smoke'&&str_contains($r['answer'],'10 Rauchmelder'),'Smoke group report includes ten detectors');
$r=iah_rc_voice('welcher akku bei den xsense geräten ist am schlechtesten',[],$xs);
check(str_contains($r['answer'],'Alle 13')&&str_contains($r['answer'],'100 Prozent'),'Equal batteries do not invent a worst device');
$GLOBALS['values'][2441]=15.0;
$r=iah_rc_voice('welcher akku bei den xsense geräten ist am schlechtesten',[],$xs);
check(str_contains($r['answer'],'Wassermelder Whirlpool')&&str_contains($r['answer'],'15 Prozent'),'Lowest charge computed from actual numeric percent feedback');
$GLOBALS['values'][2400]=true;
$r=iah_rc_voice('wie geht es meinen wassermeldern',[],$xs);
check(str_contains($r['answer'],'Achtung:')&&str_contains($r['answer'],'Wasser gemeldet'),'Positive leak feedback has top priority');
$GLOBALS['values'][2400]=false;
sensor(910,2600,'binary_sensor_smoke_duplicate','Rauchalarm',true);
$xs=IAHXSense::merge($xs);
$r=iah_rc_voice('wie geht es meiner brandmeldestation',[],$xs);
check(str_contains($r['answer'],'Achtung:')&&str_contains($r['answer'],'widersprüchliche'),'Conflicting feedback never hides a positive alarm');
$GLOBALS['values'][2600]=false;$GLOBALS['values'][2102]=false;$GLOBALS['values'][2103]=true;$GLOBALS['values'][2104]=true;
$r=iah_rc_voice('wie geht es meinem Rauchmelder Büro',[],$xs);
check(str_contains($r['answer'],'Lebensdauer beendet')&&str_contains($r['answer'],'nicht verbunden'),'Offline and life-end warnings are explained');
$r=iah_rc_voice('Teste die X-Sense Rauchmelder',[],$xs);
check(str_contains($r['answer'],'kein Test'),'Voice status cannot execute a detector self-test');
$denied=$xs;$denied['dataSources']['xsense']['devices']['device_910']['enabled']=false;
check(!str_contains(json_encode(IAHXSense::index($denied)),'Rauchmelder Büro'),'Denied device not in KI index');
$denied['dataSources']['xsense']['kiEnabled']=false;
check(IAHXSense::devices($denied)===[],'Global source permission enforced');
$edited=$xs;$edited['dataSources']['xsense']['devices']['device_910']['name']='Mein Sensor';
$edited['dataSources']['xsense']['devices']['device_910']['variables']['var_2101']['enabled']=false;
check(IAHXSense::merge($edited)===$edited,'Rescan retains labels, links and explicit read denials');
check(IAHSecurityQuery::windowState(false,['stateMapping'=>['open'=>false,'closed'=>true]],2100,'open')===true,'Archive window state honors inverted mappings');
final class XSenseEditorFixture
{
    use RCCXSenseEditorTrait;
    public array $catalog;public int $saves=0;public int $refreshes=0;public array $updates=[];
    public function __construct(array $catalog){$this->catalog=$catalog;}
    private function loadConfiguredCatalog(...$args):array{return $this->catalog;}
    private function systemTreeEditContext():array{return [50,'old',$this->catalog];}
    private function saveSystemTreeEdit(int $id,string $old,array $next):void{$this->catalog=$next;$this->saves++;}
    private function refreshSystemTreeCatalog():void{$this->refreshes++;}
    public function UpdateFormField($name,$property,$value):void{$this->updates[]=[$name,$property,$value];}
}
$editor=new XSenseEditorFixture($xs);
$fields=json_decode($editor->GetXSenseEditForm('xsense_device_910'),true,64,JSON_THROW_ON_ERROR);
$list=array_values(array_filter($fields,static fn($f)=>($f['name']??'')==='XSenseVariables'))[0];
check(count($list['values'])===count($xs['dataSources']['xsense']['devices']['device_910']['variables']),'Gear lists every non-private variable, including unclassified values and command placeholders');
$before=$editor->catalog;
ob_start();$editor->SaveXSenseVariable('xsense_device_910',json_encode(['key'=>'var_2101','id'=>2101,'title'=>'Akku Büro','role'=>'batteryLevel','activeWhen'=>true,'enabled'=>false]));ob_end_clean();
check($editor->saves===1&&$editor->refreshes===0&&$editor->updates[0][0]==='XSenseVariables','Nested variable edit saves EDIT and only updates its list, retaining the selected dialog row');
check(!$editor->catalog['dataSources']['xsense']['devices']['device_910']['variables']['var_2101']['enabled'],'Per-variable read permission editable');
ob_start();$editor->SaveXSenseVariable('xsense_device_910',json_encode(['key'=>'var_2101','id'=>2421,'title'=>'Wrong parent','role'=>'batteryLevel','enabled'=>true]));$notice=ob_get_clean();
check($editor->saves===1&&str_contains($notice,'zum ausgewählten Gerät'),'Cannot attach another device sensor accidentally');
ob_start();$editor->SaveXSenseOverview('xsense_device_910','Rauchmelder Arbeitszimmer','Büromelder',true,'__xsense__','xsense','smoke');ob_end_clean();
check($editor->catalog['dataSources']['xsense']['devices']['device_910']['name']==='Rauchmelder Arbeitszimmer'&&$editor->refreshes===1,'Device names, aliases, room/group and kind editable in EDIT');
// Exercise actual generated list callbacks, including missing non-column sourceKey metadata.
function RCC_GetXSenseEditForm($id,$key):string{return '[{"caption":"xsense"}]';}
function RCC_SaveXSenseOverview(...$args):void{$GLOBALS['savedXSense']=$args;}
$Entries=$xsrows[1];unset($Entries['sourceKey']);$id=1;
check((eval($gearCallback))[0]['caption']==='xsense','Main gear callback selects X-Sense by the stable row key');
$GLOBALS['selectionOnly']=false;$Entries['XSenseAliases']='Büromelder';$Entries['XSenseKind']='smoke';
eval($saveCallback);check($GLOBALS['savedXSense'][1]===$Entries['key'],'Main overview saves through the dedicated X-Sense method');
unset($GLOBALS['savedXSense']);$GLOBALS['selectionOnly']=true;eval($saveCallback);
check(!isset($GLOBALS['savedXSense']),'Checkbox selection cannot open or save an X-Sense dialog');
$module=file_get_contents(__DIR__.'/../RoomsCatalogConfigurator/module.php');
preg_match('/\x27form\x27=>(\x27return json_decode\(str_starts_with.*?),\R\s*\x27columns\x27/s',$module,$pendingMatch);
$pendingCallback=eval('return '.$pendingMatch[1].';');token_get_all('<?php '.$pendingCallback,TOKEN_PARSE);
$PendingChanges=$Entries;check((eval($pendingCallback))[0]['caption']==='xsense','Pending-changes gear also opens the X-Sense editor');
foreach(['Wie geht es meiner Brandmeldestation?','Wie geht es meinen Wassermeldern?','Wie ist die Sicherheit im Haus?','Welcher Akku der X-Sense-Geräte ist am schlechtesten?'] as $q)
    check(iah_vir_classify(['text'=>$q,'intent'=>'HomeAgentFrageIntent'])['category']==='query','Security query routes into the question backend');
$displayRows=IAHSecurityQuery::query($xs,'Wie geht es meiner Brandmeldestation?',0,microtime(true)+2)['displayRows'];
foreach(['smoke'=>'statusSmoke','water'=>'statusWater','shield'=>'statusShield','battery'=>'statusBattery'] as $icon=>$graphic){
    $rendered=$kiAlexaKeepOpen('Status','Beantwortet','Sicherheit',true,'house_status',$displayRows,'Sicherheit',$icon);
    check(isset($rendered->apl['document']['graphics'][$graphic])&&$rendered->apl['datasources']['summary']['readings']===$displayRows,'Matching icon and all rows reach the actual Alexa APL renderer: '.$icon);
}
check(substr_count($module,"['name'=>'XSenseVariables','visible'=>false,'add'=>[],'save'=>false,'edit'=>['type'=>'List']]")===2,'Both dynamic dialogs bind the nested variable list');
$installer=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/module.php');
$generated=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php');
foreach(['IAH_XSENSE_INSERT'=>['XSense.php','IAHXSense'],'IAH_SECURITY_QUERY_INSERT'=>['SecurityQuery.php','IAHSecurityQuery']] as $marker=>[$file,$class]){
    check(str_contains($installer,$marker),'Production installer knows the helper: '.$class);
    $helper=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/'.$file);
    $helper=preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/','',$helper);
    $generated=str_replace('// '.$marker,"if (!class_exists('".$class."',false)) {\n".$helper."\n}",$generated);
}
token_get_all(IAHSymconScriptSource::normalize($generated),TOKEN_PARSE);
check(str_contains($generated,'final class IAHSecurityQuery')&&str_contains($generated,'final class IAHXSense'),'Generated controller embeds both helpers and parses after source normalization');
echo "PASS dynamic X-Sense discovery, 14 owner rows, detector semantics, privacy, current reports, alarm priority, permissions and battery comparison\n";
