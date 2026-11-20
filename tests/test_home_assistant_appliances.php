<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/home_connect_runtime.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/HouseholdCatalog.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/src/AnnouncementTrait.php';
$devices=json_decode(file_get_contents(__DIR__.'/fixtures/home_assistant_appliances.json'),true,512,JSON_THROW_ON_ERROR);
foreach ($devices as $d) {
    $parent=$d['parentId'];
    $GLOBALS['hcObjects'][$parent]=['ObjectName'=>$d['name']==='Waschtrockner'?'AEG':'Liebherr','ParentID'=>0,'ObjectType'=>0];
    $GLOBALS['hcObjects'][$d['id']]=['ObjectName'=>$d['name'],'ParentID'=>$parent,'ObjectType'=>1];
    $GLOBALS['hcInstances'][$d['id']]=['ModuleInfo'=>['ModuleID'=>$d['moduleId']],'InstanceStatus'=>102];
    foreach ($d['variables'] as $v) {
        $id=$v['id'];
        $GLOBALS['hcObjects'][$id]=['ObjectName'=>$v['name'],'ParentID'=>$d['id'],'ObjectType'=>2,'ObjectIdent'=>$v['ident']];
        $GLOBALS['hcVariables'][$id]=['VariableType'=>$v['type'],'VariableProfile'=>'','VariableAction'=>$v['hasAction']?$d['id']:0,'VariableCustomAction'=>0,'VariableUpdated'=>time()];
        $GLOBALS['hcValues'][$id]=$v['rawValue'];
    }
}
$discovery=IAHHomeAssistantAppliances::discover();
hcAssert(count($discovery)===3&&array_sum(array_map(static fn($d):int=>count($d['variables']),$discovery))===55,
    'All 43 AEG and both six-variable Liebherr appliances discovered from their installed module and schema');
$hcSource=IAHHomeConnect::source(50710,[]);
$catalog=RCCHouseholdCatalog::merge([],$hcSource);
hcAssert(count(RCCHouseholdCatalog::rows($catalog))===9,'Six Home Connect and three Home Assistant appliances share the ordinary household table');
hcAssert(RCCHouseholdCatalog::merge($catalog,$hcSource)===$catalog,'Repeated scanning does not reset names, links or permissions');
$query=static fn(string $q,array $cfg=[]):array=>iah_rc_voice($q,$cfg,$catalog);
$freezer=$query('Wie geht es meinem Gefrierschrank?');
hcAssert($freezer['answerSource']==='home_connect_freezer'&&str_contains($freezer['answer'],'-18 °C'),'Freezer status has actual and target temperature with units and its own visual');
$fridge=$query('Wie warm ist der Kühlschrank?');
hcAssert($fridge['answerSource']==='home_connect_fridge'&&count($fridge['displayRows'])===2&&str_contains($fridge['answer'],'5 °C'),'Fridge temperature question is resolved locally');
hcAssert(count($query('Ist der Partymodus am Kühlschrank eingeschaltet?')['displayRows'])===1,
    'A specific mode question returns that mode rather than truncating it from a generic summary');
$washer=$query('Wie geht es meiner AEG Waschmaschine?');
hcAssert($washer['displayTitle']==='AEG Waschtrockner'&&str_contains($washer['answer'],'Nicht verbunden')
    &&str_contains($washer['answer'],'Letzte gemeldete Programmphase')&&!str_contains($washer['answer'],'fertig'),'AEG disconnection and last phase are not mistaken for active or finished state');
$ambiguous=$query('Wie geht es meiner Waschmaschine?');
hcAssert(!empty($ambiguous['awaitingDevice'])&&count($ambiguous['deviceChoices'])===2,'Shared washer alias uses the existing numbered device selection');
$details=$query('Zeige alle Werte des AEG Waschtrockners');
hcAssert(count($details['displayRows'])===43&&!str_contains(json_encode($details['displayRows']),'65535')
    &&str_contains(json_encode($details['displayRows']),'Nicht verf')&&!str_contains($details['answer'],'-1'),'All AEG variables remain available without exposing sentinels as useful measurements');
hcAssert(str_contains($query('Ist die AEG Waschmaschine fertig?')['answer'],'nicht bestätigt'),'Zero time remaining is never a successful finish');
hcAssert(count($query('Wie geht es meinen Hausgeräten?')['displayRows'])===9,'Overview combines both appliance providers');
$freezerKey='object_35524';$varKey='var_18281';
$before=count($GLOBALS['hcWrites']);
IAHHouseholdDevices::query($catalog,'Schalte den Partymodus am Gefrierschrank ein',true);
hcAssert(count($GLOBALS['hcWrites'])===$before,'Newly discovered devices default to reading only');
$writable=$catalog;
$writable['dataSources']['household_devices']['devices'][$freezerKey]['allowControl']=true;
$writable['dataSources']['household_devices']['devices'][$freezerKey]['variables'][$varKey]['writable']=true;
IAHHouseholdDevices::query($writable,'Schalte den Partymodus am Gefrierschrank ein',true);
hcAssert(end($GLOBALS['hcWrites'])===[18281,true],'An explicitly permitted, bound Boolean mode uses the native action');
$before=count($GLOBALS['hcWrites']);
foreach (['Schalte den Gefrierschrank ein','Schalte SuperFrost am Gefrierschrank ein','Stelle den Gefrierschrank auf -20 Grad','Starte die AEG Waschmaschine'] as $cmd)
    IAHHouseholdDevices::query($writable,$cmd,true);
hcAssert(count($GLOBALS['hcWrites'])===$before,'No whole-appliance power, denied mode, unbounded setpoint or unknown button value is inferred');
$offline=$writable;
$offline['dataSources']['household_devices']['devices']['object_42146']['allowControl']=true;
$offline['dataSources']['household_devices']['devices']['object_42146']['variables']['var_34074']['writable']=true;
hcAssert(str_contains(IAHHouseholdDevices::query($offline,'Schalte das Nachtprogramm am AEG Waschtrockner ein',true)['answer'],'nicht verbunden')
    &&count($GLOBALS['hcWrites'])===$before,'An offline appliance never queues a mode command');
$denied=$writable;$denied['dataSources']['household_devices']['devices'][$freezerKey]['enabled']=false;
hcAssert(str_contains(IAHHouseholdDevices::query($denied,'Status Gefrierschrank')['answer'],'gesperrt'),'Published device denial stays authoritative');
$custom=$catalog;
$custom['dataSources']['household_devices']['devices'][$freezerKey]['variables'][$varKey]['title']='Eigener Partyname';
$custom['dataSources']['household_devices']['devices'][$freezerKey]['variables'][$varKey]['enabled']=false;
hcAssert(RCCHouseholdCatalog::merge($custom,$hcSource)===$custom,'Rescan retains custom variable names and denials');

final class HaAnnouncementFixture
{
    use IAHAnnouncementTrait;
    public array $attributes=[];
    public function __construct(public array $catalog){}
    public function ReadAttributeString($n):string{return $this->attributes[$n]??'[]';}
    public function WriteAttributeString($n,$v):void{$this->attributes[$n]=$v;}
    private function homeConnectDevices():array{return [];}
    public function GetHomeConnectCatalog():string{return json_encode($this->catalog);}
    public function templates(array $rules=[]):array{return $this->announcementTemplates($rules);}
    public function valid(array $r):bool{return $this->announcementValid($r);}
}
$ann=new HaAnnouncementFixture($catalog);$rules=$ann->templates();
hcAssert(count($rules)===4&&$ann->templates($rules)===$rules,'All four proven connection/error templates appear once in the central announcements list');
$rule=$rules['household_35524_connection'];
hcAssert($ann->valid($rule),'Announcement validates the currently published device and variable identity');
$ann->catalog=$denied;
hcAssert(!$ann->valid($rule),'Disabling the device also blocks its automatic announcements');
echo "PASS AEG/Liebherr schema discovery, 55 variables, nine devices, questions, sentinels, permissions, Boolean commands and central announcement templates\n";
