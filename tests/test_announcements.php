<?php
declare(strict_types=1);
require_once __DIR__ . '/fixtures/home_connect_runtime.php';
require_once __DIR__ . '/../IPSAlexaHaussteuerung/src/HomeConnectTrait.php';
require_once __DIR__ . '/../IPSAlexaHaussteuerung/src/AnnouncementTrait.php';
define('VM_UPDATE',10603);
$GLOBALS['locks'] = [];
function IPS_SemaphoreEnter(string $key, int $timeout): bool
{ if (!empty($GLOBALS['locks'][$key])) return false; $GLOBALS['locks'][$key]=true; return true; }
function IPS_SemaphoreLeave(string $key): void { unset($GLOBALS['locks'][$key]); }
final class AnnouncementFixture
{
    use IAHHomeConnectTrait;
    use IAHAnnouncementTrait;
    public int $InstanceID=900;
    public array $properties=['HomeConnectEnabled'=>true,'HomeConnectRootId'=>50710,'AnnouncementsEnabled'=>true];
    public array $attributes=[],$messages=[],$timers=[],$updates=[],$sent=[];
    public function ReadPropertyBoolean($key): bool { return (bool)($this->properties[$key]??false); }
    public function ReadPropertyInteger($key): int { return (int)($this->properties[$key]??0); }
    public function ReadAttributeString($key): string { return $this->attributes[$key]??''; }
    public function WriteAttributeString($key,$v): void { $this->attributes[$key]=$v; }
    public function RegisterMessage($id,$m): void { $this->messages[$id]=$m; }
    public function UnregisterMessage($id,$m): void { unset($this->messages[$id]); }
    public function SetTimerInterval($name,$ms): void { $this->timers[$name]=$ms; }
    public function UpdateFormField($name,$field,string|int|float|bool $value): void {
        // The native Symcon boundary accepts complex form parameters only as JSON strings.
        if (in_array($field,['values','options'],true)) {
            hcAssert(is_string($value) && is_array(json_decode($value,true,512,JSON_THROW_ON_ERROR)),
                'List values and Select options must be JSON encoded');
        }
        $this->updates[$name][$field]=$value;
    }
    public function Announce(string $text,int $target=0): bool { $this->sent[]=[$text,$target,'announcement']; return true; }
    public function SpeakText(string $text,int $target=0): bool { $this->sent[]=[$text,$target,'speech']; return true; }
    public function rules(): array { return json_decode($this->attributes['AnnouncementRules'],true); }
    public function queue(): array { return json_decode($this->attributes['AnnouncementQueue']??'[]',true); }
    public function panel(): array { return $this->buildAnnouncementPanel(); }
    public function save(array $row): string {
        $row['revision']=hash('sha256',serialize($this->rules()[$row['key']]));
        ob_start();$this->SaveAnnouncementRule(json_encode($row));return ob_get_clean();
    }
}
$a=new AnnouncementFixture();
$a->ReloadAnnouncementList();
hcAssert(json_decode($a->updates['AnnouncementRules']['values'],true,512,JSON_THROW_ON_ERROR)===[],
    'Empty announcement list updates use a JSON array');
$a->RefreshAnnouncements();
hcAssert(count($a->rules())===12 && count($a->messages)===5, 'Twelve templates watch the five relevant devices');
hcAssert($a->queue()===[] && $a->sent===[], 'Installation never speaks old snapshot events');
ob_start();$a->RefreshHomeConnectDevices();$refreshed=ob_get_clean();
$deviceRows=json_decode($a->updates['HomeConnectDevices']['values'],true,512,JSON_THROW_ON_ERROR);
hcAssert(count($deviceRows)===6 && str_contains($refreshed,'aktualisiert'),
    'Device refresh sends all six rows as JSON');
$coffee=array_values(array_filter($deviceRows,static fn(array $row):bool=>$row['name']==='Kaffeevollautomat'))[0];
$coffee['aliases']='Kaffee Küche';
ob_start();$a->SetHomeConnectDevice(json_encode($coffee));$saved=ob_get_clean();
$deviceRows=json_decode($a->updates['HomeConnectDevices']['values'],true,512,JSON_THROW_ON_ERROR);
hcAssert(str_contains($saved,'gespeichert') && count($deviceRows)===6
    && in_array('Kaffee Küche',array_column($deviceRows,'aliases'),true),
    'Saving a device refreshes JSON rows and preserves Unicode aliases');
$a->ReloadAnnouncementList();
hcAssert(count(json_decode($a->updates['AnnouncementRules']['values'],true,512,JSON_THROW_ON_ERROR))===12
    && count(json_decode($a->updates['AnnouncementTestKey']['options'],true,512,JSON_THROW_ON_ERROR))===13,
    'Announcement list and test selector update through the native scalar boundary');
$a->RefreshAnnouncements();
hcAssert(count($a->rules())===12, 'Refresh is idempotent');
$a->MessageSink(1,17605,VM_UPDATE,['BSH.Common.Event.ProgramFinished',true]);
hcAssert(count($a->queue())===1 && $a->sent===[], 'Event is queued outside the message callback');
$a->MessageSink(2,17605,VM_UPDATE,['BSH.Common.Event.ProgramFinished',false]);
hcAssert(count($a->queue())===1, 'Repeated events are debounced');
$a->ProcessAnnouncements();
hcAssert(count($a->sent)===1 && str_contains($a->sent[0][0],'Waschtrockner'), 'Native central output receives the right template');
hcAssert($a->queue()===[] && $a->timers['AnnouncementDispatch']===0, 'Queue drains and timer stops');
$a->MessageSink(3,35161,VM_UPDATE,['Cooking.Oven.Event.RegularPreheatFinished',true]);
hcAssert(count($a->queue())===1, 'Official and historical preheat event aliases are supported');
$a->ProcessAnnouncements();
$rule=$a->rules()['hc_16021_finished'];$rule['enabled']=false;$a->save($rule);
$a->MessageSink(4,56931,VM_UPDATE,['BSH.Common.Event.ProgramFinished',true]);
hcAssert($a->queue()===[], 'Disabled rules never enqueue');
$key='hc_33267_WaterTankEmpty';$row=$a->rules()[$key];
$a->DeleteAnnouncementRule($key,hash('sha256',serialize($row)));
$a->RefreshAnnouncements();
hcAssert(!isset($a->rules()[$key]), 'Deleted templates are not silently recreated');
ob_start();$a->AddAnnouncementRule();ob_end_clean();
$rows=$a->rules();$row=end($rows);
$row=array_merge($row,['title'=>'Tür ist offen','variableId'=>10641,'operator'=>'equals',
    'expected'=>'BSH.Common.EnumType.DoorState.Open','text'=>'Die Tür ist jetzt offen.','enabled'=>true]);
hcAssert(str_contains($a->save($row),'gespeichert'),'Own variable rule can be configured');
$a->MessageSink(5,10641,VM_UPDATE,['BSH.Common.EnumType.DoorState.Open',true]);
hcAssert($a->queue()===[], 'Creating a rule on an already matching value never speaks the old state');
$a->MessageSink(6,10641,VM_UPDATE,['BSH.Common.EnumType.DoorState.Closed',true]);
$a->MessageSink(7,10641,VM_UPDATE,['BSH.Common.EnumType.DoorState.Open',true]);
hcAssert(count($a->queue())===1, 'Condition crossing triggers');
$a->MessageSink(6,10641,VM_UPDATE,['BSH.Common.EnumType.DoorState.Open',false]);
hcAssert(count($a->queue())===1, 'Stable condition does not retrigger');
$a->properties['AnnouncementsEnabled']=false;$a->RefreshAnnouncements();$a->ProcessAnnouncements();
hcAssert($a->queue()===[], 'Global disable clears pending work');
$a->properties['AnnouncementsEnabled']=true;$a->RefreshAnnouncements();
$a->MessageSink(7,17605,VM_UPDATE,['BSH.Common.Event.ProgramFinished',true]);
hcAssert($a->queue()===[], 'Cooldown persists through refresh/disable cycles');
$a->MessageSink(8,38722,VM_UPDATE,['BSH.Common.Event.ProgramFinished',true]);
hcAssert(count($a->queue())===1,'Microwave finish can queue');
$GLOBALS['hcObjects'][38722]['ObjectIdent']='ReusedId';
$before=count($a->sent);$a->ProcessAnnouncements();
hcAssert(count($a->sent)===$before,'Changed source binding is checked again before output');
$GLOBALS['hcObjects'][38722]['ObjectIdent']='Event';
hcAssert(str_contains(json_encode($a->panel()),'IAH_AddAnnouncementRule'),'Dedicated common list exposes own rule creation');
hcAssert($GLOBALS['locks']===[],'All locks are released');
$GLOBALS['hcObjects'][700]=['ObjectName'=>'Temperatur','ObjectIdent'=>'Temp','ObjectType'=>2,'ParentID'=>50710];
$GLOBALS['hcVariables'][700]=['VariableType'=>2];$GLOBALS['hcValues'][700]=20.0;
ob_start();$a->AddAnnouncementRule();ob_end_clean();$rows=$a->rules();$threshold=end($rows);
$threshold=array_merge($threshold,['title'=>'Warm','enabled'=>true,'variableId'=>700,'operator'=>'above','expected'=>'30','text'=>'Es ist warm: {value}.']);
hcAssert(str_contains($a->save($threshold),'gespeichert'),'Generic threshold rule can be enabled');
$a->MessageSink(20,700,VM_UPDATE,[31.0,true]);
hcAssert(count($a->queue())===1,'Threshold crossing queues once');
$a->MessageSink(21,700,VM_UPDATE,[32.0,true]);
hcAssert(count($a->queue())===1,'Above threshold updates do not flood');
$a->ProcessAnnouncements();
hcAssert(str_contains(end($a->sent)[0],'31'),'Value placeholder uses event value rather than a later snapshot');
$row=$a->rules()[$threshold['key']];
$row['quietStart']=(int)date('G');$row['quietEnd']=((int)date('G')+1)%24;
$row['operator']='update';$a->save($row);
$state=json_decode($a->attributes['AnnouncementState'],true);$state[$row['key']]['lastQueued']=0;
$a->attributes['AnnouncementState']=json_encode($state);
$a->MessageSink(22,700,VM_UPDATE,[33.0,true]);
hcAssert($a->queue()===[],'Quiet hours suppress output without deferred replay');
$a->MessageSink(23,14971,VM_UPDATE,['ConsumerProducts.CoffeeMaker.Event.BeanContainerEmpty',true]);
$queue=$a->queue();hcAssert(count($queue)===1,'Coffee warning is queued');
$queue[0]['created']-=121;$a->attributes['AnnouncementQueue']=json_encode($queue);
$count=count($a->sent);$a->ProcessAnnouncements();
$state=json_decode($a->attributes['AnnouncementState'],true);
hcAssert(count($a->sent)===$count && $state['hc_33267_BeanContainerEmpty']['result']==='Verworfen', 'Expired queue items are visible as discarded, not spoken');
echo "PASS automatic announcement templates, generic rules, events, queue, cooldown, identity checks and deletion persistence\n";
