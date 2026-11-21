<?php
declare(strict_types=1);
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/WeatherStationEditorTrait.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/DataSourceEditor.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/src/VoiceRuleLiteralReader.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/src/SymconScriptSource.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$GLOBALS['objects']=[];$GLOBALS['vars']=[];$GLOBALS['values']=[];$GLOBALS['profiles']=[];$GLOBALS['instances']=[];$GLOBALS['links']=[];$GLOBALS['saveAll']=[];
function IPS_InstanceExists(int $id):bool{return isset($GLOBALS['instances'][$id]);}
function IPS_GetInstance(int $id):array{return $GLOBALS['instances'][$id];}
function IPS_GetInstanceListByModuleID(string $module):array{return array_keys(array_filter($GLOBALS['instances'],static fn($i)=>$i['ModuleInfo']['ModuleID']===$module));}
function IPS_GetObject(int $id):array{return $GLOBALS['objects'][$id]??throw new RuntimeException('Missing object');}
function IPS_GetChildrenIDs(int $id):array{return array_keys(array_filter($GLOBALS['objects'],static fn($o)=>$o['ParentID']===$id));}
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['vars'][$id]);}
function IPS_ObjectExists(int $id):bool{return isset($GLOBALS['objects'][$id]);}
function IPS_GetVariable(int $id):array{return $GLOBALS['vars'][$id]??throw new RuntimeException('Missing variable');}
function IPS_GetProperty(int $id,string $p):mixed{check($p==='SaveAllValues','Only Froggit save policy can be read');return $GLOBALS['saveAll'][$id]??false;}
function IPS_GetLink(int $id):array{return ['TargetID'=>$GLOBALS['links'][$id]];}
function IPS_VariableProfileExists(string $p):bool{return isset($GLOBALS['profiles'][$p]);}
function IPS_GetVariableProfile(string $p):array{return $GLOBALS['profiles'][$p];}
function GetValue(int $id):mixed{return $GLOBALS['values'][$id]??throw new RuntimeException('Missing value');}
function IPS_LogMessage(...$args):void{}
function RequestAction(...$args):never{throw new RuntimeException('Weather queries must not control devices');}
function station(int $id,string $name):void{
    $GLOBALS['objects'][$id]=['ObjectType'=>1,'ObjectName'=>$name,'ObjectIdent'=>'','ParentID'=>0];
    $GLOBALS['instances'][$id]=['ModuleInfo'=>['ModuleID'=>IAHWeatherStation::MODULE_ID],'InstanceStatus'=>102];
}
function sensor(int $station,int $id,string $ident,string $name,mixed $value,string $unit='',?int $updated=null):void{
    $type=match(true){is_bool($value)=>0,is_int($value)=>1,is_float($value)=>2,default=>3};
    $GLOBALS['objects'][$id]=['ObjectType'=>2,'ObjectName'=>$name,'ObjectIdent'=>$ident,'ParentID'=>$station];
    $GLOBALS['vars'][$id]=['VariableType'=>$type,'VariableProfile'=>'profile_'.$id,'VariableUpdated'=>$updated??time(),'VariableAction'=>0];
    $GLOBALS['values'][$id]=$value;$GLOBALS['profiles']['profile_'.$id]=['Suffix'=>$unit,'Associations'=>[]];
}
station(49084,'Froggit - Wetterstation');station(15263,'Froggit - 433Station');
// Protocol identities and units from the Froggit module; fixture IDs are data only.
sensor(49084,44047,'tempf','Temperatur (Aussensensor)',-1.2,' °C');
sensor(49084,58367,'tempinf','Temperatur (Innensensor)',26.0,' °C');
sensor(49084,24562,'dewpoint','Taupunkt',-1.5,' °C');
sensor(49084,17530,'windchill','Gefühlte Temperatur',-1.2,' °C');
sensor(49084,49663,'humidity','Luftfeuchtigkeit (Aussensensor)',98,' %');
sensor(49084,38488,'baromabsin','Luftdruck (Absolut)',892.8,' hPa');
sensor(49084,20812,'winddir_int','Windrichtung',92,' °');
sensor(49084,30495,'winddir_txt','Windrichtung',0.0);
sensor(49084,52763,'windspeedmph','Windgeschwindigkeit',2.2,' km/h');
sensor(49084,31520,'windgustmph','Windböe',2.9,' km/h');
sensor(49084,24638,'maxdailygust','Windböe (Max) Tag',20.2,' km/h');
sensor(49084,48264,'solarradiation','Solar Einstrahlung',0,' lx');
sensor(49084,36830,'uv','UV Index',0);
sensor(49084,14904,'rainratein','Regen Rate',0.0,' mm');
sensor(49084,29088,'dailyrainin','Regen Tag',0.0,' mm');
sensor(49084,47424,'weeklyrainin','Regen Woche',1.1,' mm');
sensor(49084,12215,'yearlyrainin','Regen Jahr',1153.4,' mm');
sensor(49084,44566,'soilmoisture2','Bodenfeuchte (2) Beet und Tröge',40,' %');
sensor(49084,38644,'soilmoisture5','Bodenfeuchte (5)',0,' %',time()-60*86400);
sensor(49084,51573,'soilbatt5','Batterie Bodenfeuchtesensor (5)',-100,' %',time()-60*86400);
sensor(49084,39806,'console_batt','Batterie Wetterstation',60,' %',time()-3*86400);
sensor(49084,35361,'soilad2','AD (2)',200);
sensor(49084,36370,'vpd','VPD',0.0);
sensor(49084,47911,'model','Modell','HP2564AE_Pro_V2.1.4');
sensor(49084,600,'dateutc','Uhrzeit',time());sensor(49084,601,'runtime','Startzeit Gateway',time()-86400);
sensor(49084,602,'PASSKEY','PASSKEY','dont-export');sensor(49084,603,'interval','Intervall',60);
sensor(15263,40455,'tempinf','Temperatur (Aussensensor Mähroboter)',0.5,' °C');
sensor(15263,25030,'soilmoisture1','Bodenfeuchte (1) Hecke Ost',56,' %');
sensor(15263,47490,'soilmoisture2','Bodenfeuchte (2) Hecke Süd',50,' %');
sensor(15263,40021,'soilbatt1','Batterie Bodenfeuchtesensor (1) Hecke Ost',80,' %');
sensor(15263,33010,'dateutc','Uhrzeit',time());
foreach ([56338=>25030,42523=>47490,47297=>40021] as $link=>$target){$GLOBALS['objects'][$link]=['ObjectType'=>6,'ObjectName'=>IPS_GetObject($target)['ObjectName'],'ParentID'=>49084];$GLOBALS['links'][$link]=$target;}
$base=['rooms'=>[]];$catalog=IAHWeatherStation::merge($base);$devices=IAHWeatherStation::source($catalog)['devices'];
check(count($devices)===2,'Both installed Froggit instances become separate weather devices');
check(IAHWeatherStation::merge($catalog)===$catalog,'Rescan is idempotent');
$encoded=json_encode($catalog);
check(!str_contains($encoded,'Uhrzeit')&&!str_contains($encoded,'dateutc')&&!str_contains($encoded,'PASSKEY')&&!str_contains($encoded,'winddir_txt'),'Clocks, secrets and redundant direction representations are not catalog readings');
$literal='<?php return '.var_export($catalog,true).';';
check(IAHVoiceRuleLiteralReader::read($literal)===$catalog,'Nested weather sources retain the existing static catalog format');
$rows=RCCDataSourceEditor::sourceRows($catalog);
check(count($rows)===2&&count(array_filter($rows,static fn($r)=>$r['domain']==='wetterstation'&&$r['kiReadOnly']))===2,'Both devices appear in the ordinary Wetterstation domain, always read-only');
$ask=static fn(string $q,array $c=[]):array=>iah_rc_voice($q,$c,$catalog);
$r=$ask('Wie geht es meiner Wetterstation?');
check($r['displayIcon']==='weather'&&$r['answerSource']==='house_status'&&$r['displayTitle']==='Froggit - Wetterstation'&&str_contains($r['answer'],'-1,2 Grad'),'Default station returns the shared full status APL with a weather icon');
check(str_contains(json_encode($ask('Zeige alle Werte meiner Wetterstation')['displayRows']),'1153,4 mm'),'All-values page includes all readings, including annual rain');
check(str_contains($ask('Wie stark ist der Wind?')['answer'],'2,2 km/h'),'Wind question reads the configured converted value');
check(str_contains($ask('Aus welcher Richtung kommt der Wind?')['answer'],'Ost'),'Degree directions are spoken as compass points');
check(count($ask('Wie viel hat es heute geregnet?')['displayRows'])===1&&str_contains($ask('Wie viel hat es heute geregnet?')['answer'],'0 mm'),'Rain today selects the daily counter, not year or current rate');
check(str_contains($ask('Regnet es gerade?')['answer'],'mm/h'),'Rain rate uses its actual unit with time basis');
check(str_contains($ask('Wie viel Regen diese Woche?')['answer'],'1,1 mm'),'Weekly rain counter is addressable');
check(count($ask('Wie warm ist es draußen?')['displayRows'])===1&&str_contains($ask('Wie warm ist es draußen?')['answer'],'-1,2 Grad'),'Outdoor temperature never picks the indoor gateway sensor');
check(str_contains($ask('Wie hoch ist die solare Einstrahlung der Wetterstation?')['answer'],'0 lx'),'Lux is never invented as watts per square metre');
$soil=$ask('Wie feucht ist die Hecke Ost?');
check(count($soil['displayRows'])>1&&str_contains($soil['answer'],'56 Prozent'),'Named soil sensor is resolved across stations and duplicate links only counted once');
check(str_contains($ask('Wie geht es meiner 433Station?')['answer'],'0,5 °C'),'Sensor-only gateway is individually addressable');
$all=$ask('Zeige alle Werte beider Wetterstationen');
check(count(array_filter($all['displayRows'],static fn($r)=>str_contains($r['label'],'Hecke Ost')&&str_contains($r['value'],'56 %')))===1,'All-station status deduplicates linked targets');
check(!str_contains(json_encode($all['displayRows']),'-100')&&str_contains(json_encode($all['displayRows']),'Nicht verf'),'Invalid battery sentinel is not shown as a measurement');
check(str_contains($ask('Wie wird das Wetter morgen?')['answer'],'nicht zuverlässig abrufen'),'Live sensors never fabricate a forecast');
check(str_contains($ask('Wie kalt war es gestern draußen?')['answer'],'Archivaufzeichnung'),'Unsupported archive request does not silently return current readings');
$ask('Schalte die Wetterstation ein',['allow_writes'=>true]);
check(IAHWeatherStation::query($catalog,'Wie geht es dem Backofen?')===null,'Unrelated device queries remain untouched');
check(IAHWeatherStation::query([],'Wie feucht ist der Garten?')===null,'Existing irrigation/soil handling survives when no weather source exists');
$m=$devices['station_49084']['variables']['var_39806'];
check(IAHWeatherStation::reading($m)['quality']==='unconfirmed','Unchanged old battery reading is not incorrectly called an offline station');
$GLOBALS['saveAll'][49084]=true;
check(IAHWeatherStation::reading($m)['quality']==='stale','With SaveAllValues enabled, missing sensor updates are detectable');
$GLOBALS['saveAll'][49084]=false;$GLOBALS['vars'][600]['VariableUpdated']=time()-3600;
check(IAHWeatherStation::reading($devices['station_49084']['variables']['var_44047'])['quality']==='stale','Missing gateway uploads are marked stale even for otherwise valid values');
$GLOBALS['vars'][600]['VariableUpdated']=time();
$denied=$catalog;$denied['dataSources'][IAHWeatherStation::KEY]['devices']['station_15263']['enabled']=false;
check(str_contains(IAHWeatherStation::query($denied,'Wie geht es der 433Station?')['answer'],'nicht mehr'),'Published station denial is respected');
check(!str_contains(IAHWeatherStation::query($denied,'Wie feucht ist die Hecke Ost?')['answer'],'56 Prozent'),'A main-station link cannot bypass the linked station denial');
$denied=$catalog;$denied['dataSources'][IAHWeatherStation::KEY]['devices']['station_49084']['variables']['var_52763']['enabled']=false;
check(!str_contains(IAHWeatherStation::query($denied,'Wie stark ist der Wind?')['answer'],'2,2 km/h'),'Per-metric reading denial is respected');
$GLOBALS['objects'][52763]['ObjectIdent']='unrelated';
check(IAHWeatherStation::reading($devices['station_49084']['variables']['var_52763'])['quality']==='missing','Identity changes are rejected instead of reading a reused variable ID');
$GLOBALS['objects'][52763]['ObjectIdent']='windspeedmph';

final class WeatherEditorFixture
{
    use RCCWeatherStationEditorTrait;
    public int $backups=0;public int $refreshes=0;public array $prod;public array $updates=[];
    public function __construct(public array $catalog){$this->prod=$catalog;}
    private function systemTreeEditContext():array{return [1,serialize($this->catalog),$this->catalog];}
    private function saveSystemTreeEdit($id,$old,array $next):int{check($old===serialize($this->catalog),'No concurrent overwrite');$this->catalog=$next;return ++$this->backups;}
    private function loadConfiguredCatalog(...$args):array{return $this->catalog;}
    private function refreshSystemTreeCatalog():void{++$this->refreshes;}
    private function logDebug(...$args):void{}
    public function UpdateFormField($name,$field,string|int|bool|float $value):void{$this->updates[$name][$field]=$value;}
}
$editor=new WeatherEditorFixture($base);ob_start();$editor->SyncWeatherStations();ob_end_clean();
check($editor->prod===$base&&$editor->backups===1,'Discovery writes backed-up EDIT only');
ob_start();$editor->SyncWeatherStations();ob_end_clean();check($editor->backups===1,'Unchanged reopen does not create more backups');
$form=json_decode($editor->GetWeatherStationEditForm('weather_station_49084'),true,512,JSON_THROW_ON_ERROR);
$list=array_values(array_filter($form,static fn($e)=>($e['name']??'')==='WeatherVariables'))[0];
check(count($list['values'])===count($devices['station_49084']['variables']),'Gear editor includes every acquired variable');
$metric=$devices['station_49084']['variables']['var_52763'];$row=['key'=>'var_52763','id'=>$metric['id'],'title'=>'Mein Windmesser','enabled'=>false];
ob_start();$editor->SaveWeatherStationVariable('weather_station_49084',json_encode($row));ob_end_clean();
ob_start();$editor->SaveWeatherStationOverview('weather_station_49084','Gartenwetter','Wetterstation, Gartenwetter',true);ob_end_clean();
$custom=$editor->catalog;
check(IAHWeatherStation::merge($custom)===$custom&&$editor->prod===$base,'Custom names and denials survive rescanning; PROD untouched');
check(count(RCCWeatherStationCatalog::pendingRows($custom,$base))===2,'Both new stations are visible for EDIT to PROD review');
// Ordinary room saves must preserve the new source and callbacks must dispatch correctly.
class IPSModule{}
require_once __DIR__.'/../RoomsCatalogConfigurator/module.php';
$rcc=new RoomsCatalogConfigurator();
$options=(new ReflectionMethod($rcc,'buildFilterOptionsFromEntries'))->invoke($rcc,$rows);
check(in_array(['caption'=>'Wetterstation','value'=>'wetterstation'],$options['domainOptions']??$options[1]??[],true),'Wetterstation is an ordinary domain filter');
$rebuilt=(new ReflectionMethod($rcc,'rebuildRoomsCatalogFromEntries'))->invoke($rcc,[],$catalog);
check(IAHWeatherStation::source($rebuilt)===IAHWeatherStation::source($catalog),'Ordinary catalog saves retain the weather source');
$module=file_get_contents(__DIR__.'/../RoomsCatalogConfigurator/module.php');
preg_match('/\x27form\x27 => (\x27return json_decode\(!empty\(\$Entries.*?),\R\s*\x27add\x27/s',$module,$match);
$gearCallback=eval('return '.$match[1].';');token_get_all('<?php '.$gearCallback,TOKEN_PARSE);
function RCC_GetWeatherStationEditForm($id,$key):string{return '[{"caption":"weather"}]';}
$Entries=$rows[0];$id=1;check((eval($gearCallback))[0]['caption']==='weather','Ordinary source-row gear selects the weather editor');
$saveSelectedRowCallback='';preg_match('/\$sourceRowSaveCallback=(.*?);\R\s*\$columnsEdit/s',$module,$match);
$saveCallback=eval('return '.$match[1].';');token_get_all('<?php '.$saveCallback,TOKEN_PARSE);
function RCC_UpdateOverviewSelection($id,$json):bool{return $GLOBALS['selectionOnly']??false;}
function RCC_SaveWeatherStationOverview(...$args):void{$GLOBALS['savedWeather']=$args;}
eval($saveCallback);check($GLOBALS['savedWeather'][2]===$rows[0]['title'],'Ordinary row saves weather fields via the dedicated callback');
unset($GLOBALS['savedWeather']);$GLOBALS['selectionOnly']=true;eval($saveCallback);check(!isset($GLOBALS['savedWeather']),'Checkbox-only selection never opens or saves the weather dialog');
foreach (['Wetterstation','Zeige die Wetterstation','Windgeschwindigkeit','Regen heute','Wie ist das Wetter?'] as $spoken) check(iah_vir_classify(['text'=>$spoken,'intent'=>'HomeAgentFrageIntent'])['category']==='query','Weather wording routes to the question backend: '.$spoken);
// Embed the actual new helper exactly as the installer does and compile it as a Symcon script.
$weather=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/WeatherStation.php');
$weather=preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/','',$weather);
$generated=IAHSymconScriptSource::normalize('<?php if (!class_exists("IAHWeatherStation",false)) {'.$weather.'}');
token_get_all($generated,TOKEN_PARSE);
check(str_contains(file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/module.php'),'// IAH_WEATHER_STATION_INSERT'),'Installer embeds weather helper for generated voice scripts');
echo "PASS Froggit/Ecowitt discovery, two stations, complete variables, clock exclusion, links, units, freshness, Alexa/status icon, permissions and EDIT-only editor\n";

// Execute the actual shared status renderer, independently of legacy runtime test expectations.
class AskResponse
{
    public array $card=[];public array $apl=[];public string $reprompt='';
    public function __construct(public string $speech){}
    public static function CreatePlainText(string $speech):self{return new self($speech);}
    public function SetRepromptPlainText(string $text):self{$this->reprompt=$text;return $this;}
    public function SetStandardCard(string ...$card):void{$this->card=$card;}
    public function AddAPLRenderDocument(array $doc,array $ds,string $token):void{$this->apl=['document'=>$doc,'datasources'=>$ds];}
}
$action=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/resources/action_entry.php');
preg_match('/\/\/ IAH_HOUSE_SUMMARY_BEGIN\R(.*?)\R *\/\/ IAH_HOUSE_SUMMARY_END/s',$action,$match);
$iahLastOutcome=static function():void{};
$applyApl=(require __DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/CoreHelpers.php')['applyApl'];
$baseUrl='https://example.test';$token='fixture';$S=$V=$ROOMS=$CFG=$ACTIONS_ENABLED=[];
eval($match[1]);
$weather=IAHWeatherStation::query($catalog,'Zeige alle Werte beider Wetterstationen');
$rendered=$kiAlexaKeepOpen($weather['answer'],'Beantwortet','Zeige alle Werte beider Wetterstationen',true,$weather['answerSource'],$weather['displayRows'],$weather['displayTitle'],$weather['displayIcon']);
check($rendered->apl['datasources']['summary']['readings']===$weather['displayRows']&&$rendered->apl['datasources']['summary']['title']==='Wetterstationen','Actual APL renderer preserves all weather rows and title');
check(isset($rendered->apl['document']['graphics']['statusWeather'])&&str_contains(json_encode($rendered->apl['document']),'ScrollView'),'Status page uses a built-in weather vector and scrolls on small displays');
$screenless=$kiAlexaKeepOpen($weather['answer'],'Beantwortet','Wetter',false,$weather['answerSource'],$weather['displayRows'],$weather['displayTitle'],$weather['displayIcon']);
check($screenless->apl===[]&&$screenless->speech===$weather['answer'],'Alexa without a screen still receives the status answer');
echo "PASS actual Alexa APL weather vector, complete rows, scrollable layout and screen-free speech\n";
