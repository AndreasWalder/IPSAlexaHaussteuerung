<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/home_connect_runtime.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/HouseholdCatalog.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/src/VoiceRuleLiteralReader.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/src/VoiceRuleEditorTrait.php';

$catalog=RCCHouseholdCatalog::merge([],IAHHomeConnect::source(50710,[]));
$legacy=['dataSources'=>['home_connect'=>IAHHomeConnect::source(50710,[])]];
foreach ([$catalog,$legacy] as $data) {
    foreach (['Mikrobackofen','Microbackofen','Mikro Backofen','Micro-Backofen','Mikro Back Ofen'] as $name) {
        $r=iah_rc_voice('Wie geht es meinem '.$name.'?',[],$data);
        hcAssert(($r['displayTitle']??'')==='Micro-Backofen'&&$r['displayRows']!==[],'Joined/split micro oven: '.$name);
    }
    foreach (['Dampfbackofen','Dampf Backofen','Dampf-Backofen','Dampf Back Ofen'] as $name)
        hcAssert(iah_rc_voice('Wie geht es meinem '.$name.'?',[],$data)['displayTitle']==='Dampf-Backofen','Steam oven specificity: '.$name);
    $prompt=iah_rc_voice('Wie warm ist mein Backofen?',[],$data);
    hcAssert($prompt['awaitingDevice']&&count($prompt['deviceChoices'])===2&&count($prompt['displayRows'])===2,'Both catalogs return real numbered choice data');
    hcAssert(array_column($prompt['displayRows'],'label')===['1','2'],'Choice display uses plain digits for both providers');
    hcAssert(!str_contains($prompt['answer'],'Sage zum Beispiel'),'Selection speech has no response example');
}
$choices=$prompt['deviceChoices'];
$aiCalls=0;
$ai=static function()use(&$aiCalls):array{$aiCalls++;return [];};
iah_rc_voice('Wie geht es meinem Backofen?',['api_key'=>'mock','prefer_ai'=>true],$catalog,$ai);
hcAssert($aiCalls===0,'A model cannot rewrite the numbered order of a pending choice');
foreach (['erste','erstes','erster','das erste','den ersten','Nummer eins','1','1. das','das erste bitte','erster Backofen'] as $reply)
    hcAssert(IAHHomeConnect::choiceReply($reply,$choices)===$choices[0]['id'],'First reply from speech/log: '.$reply);
foreach (['zweite','zweites','zweiter','das zweite','den zweiten','Nummer zwei','2','2. das','die zweite bitte'] as $reply)
    hcAssert(IAHHomeConnect::choiceReply($reply,$choices)===$choices[1]['id'],'Second reply from speech/log: '.$reply);
hcAssert(IAHHomeConnect::choiceReply('den Dampfbackofen',$choices)===$choices[1]['id'],'Named reply uses configured aliases');
hcAssert(IAHHomeConnect::choiceReply('Mikroparkoffen',$choices)===$choices[0]['id'],'A phonetic named reply remains a read-only device choice');
hcAssert(IAHHomeConnect::choiceReply('dritte',$choices)===0&&IAHHomeConnect::choiceReply('Backofen',$choices)===0,'Unavailable or ambiguous choice is clarified');
foreach (['Schalte das erste Licht aus','Wie warm ist es?','zurück','Ende','23 Grad','Mikrobackofen einschalten'] as $reply)
    hcAssert(IAHHomeConnect::choiceReply($reply,$choices)===null,'Another request is not a choice: '.$reply);

final class HomeConnectConversationFixture
{
    use IAHVoiceRuleEditorTrait;
    public int $InstanceID=0;
    public array $attributes=[];
    public function __construct(public array $catalog){}
    public function ReadAttributeString(string $name):string{return $this->attributes[$name]??'{}';}
    public function WriteAttributeString(string $name,string $value):void{$this->attributes[$name]=$value;}
    private function getScriptContent(int $id):string{return '<?php return '.var_export($this->catalog,true).';';}
    public function ReadPropertyString(string $name):string{return '';}
    public function ReadPropertyInteger(string $name):int{return 0;}
    public function ReadPropertyBoolean(string $name):bool{return $name==='HomeAgentVoiceEnabled';}
    public function preview(string $spoken,string $key):array{return $this->previewAlexaHouseQuestion($spoken,$this->catalog,$key);}
}
$module=new HomeConnectConversationFixture($catalog);
function IAH_RememberAlexaHouseQuestion(int $instance,string $session,string $device,string $question,bool $location,string $choices=''):void
{
    $GLOBALS['module']->RememberAlexaHouseQuestion($session,$device,$question,$location,$choices);
}
// Execute the actual Action serialization block, including its native scalar boundary.
$action=file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/resources/action_entry.php');
$begin=strpos($action,'                                $iahAwaitingLocation = !empty($homeAgentData');
$end=strpos($action,'                                $homeAgentAnswer =',$begin);
$rememberAction=substr($action,$begin,$end-$begin);
$instanceId=0;$iahHouseSessionId='oven-session';$iahVoiceDeviceId='living-room-echo';
$houseQuestion='Wie warm ist mein Backofen?';$homeAgentData=$prompt;
eval($rememberAction);
hcAssert(!str_contains($module->ReadAttributeString('HouseQuestionContexts'),'oven-session'),'No raw session/device IDs stored');
foreach ([['oven-session','other-echo'],['another-session','living-room-echo'],['','living-room-echo']] as [$s,$d])
    hcAssert(!$module->IsAlexaHouseLocationReply($s,$d,'erste',1),'No cross-session or device reuse');
foreach (['1. das','2. das','das zweite','Dampfbackofen'] as $reply) {
    eval($rememberAction);
    hcAssert($module->IsAlexaHouseLocationReply($iahHouseSessionId,$iahVoiceDeviceId,$reply,1),'Actual Action gate accepts pending choice');
    $plan=json_decode($module->PrepareAlexaHouseQuestion($iahHouseSessionId,$iahVoiceDeviceId,$reply,1),true,512,JSON_THROW_ON_ERROR);
    $id=IAHHomeConnect::choiceReply($reply,$choices);
    hcAssert($plan['question']===$houseQuestion&&$plan['scope']['deviceId']===$id,'Selection retains original temperature question');
    $r=iah_rc_voice($plan['question'],['question_scope'=>$plan['scope'],'allow_writes'=>true],$catalog);
    hcAssert(!isset($r['awaitingDevice'])&&$r['displayRows']!==[]&&str_contains($r['displayTitle'],$id===$choices[0]['id']?'Micro':'Dampf'),'Selection renders only the chosen live appliance');
    hcAssert(!$module->IsAlexaHouseLocationReply($iahHouseSessionId,$iahVoiceDeviceId,'erste',1),'Pending choice consumed once');
}
eval($rememberAction);
$invalid=json_decode($module->PrepareAlexaHouseQuestion($iahHouseSessionId,$iahVoiceDeviceId,'dritte',1),true);
hcAssert($invalid['awaitingDevice']&&$invalid['deviceChoices']===$choices,'Invalid index repeats same numbered choices');
hcAssert($module->IsAlexaHouseLocationReply($iahHouseSessionId,$iahVoiceDeviceId,'zweite',1),'Invalid selection does not discard pending question');
foreach (['Welche Fenster sind offen?','Schalte das Licht aus','zurück','Ende'] as $new) {
    eval($rememberAction);
    hcAssert(!$module->IsAlexaHouseLocationReply($iahHouseSessionId,$iahVoiceDeviceId,$new,1),'New topic or navigation stays independent');
    hcAssert(!$module->IsAlexaHouseLocationReply($iahHouseSessionId,$iahVoiceDeviceId,'erste',1),'New topic clears pending selection');
}
eval($rememberAction);
$entries=json_decode($module->ReadAttributeString('HouseQuestionContexts'),true);
foreach ($entries as &$entry)$entry['awaitingDeviceUntil']=time()-1;unset($entry);
$module->WriteAttributeString('HouseQuestionContexts',json_encode($entries));
hcAssert(!$module->IsAlexaHouseLocationReply($iahHouseSessionId,$iahVoiceDeviceId,'erste',1),'Expired prompt is not reused');

$testKey='test:'.str_repeat('a',32);
$p=$module->preview($houseQuestion,$testKey);
hcAssert($p['awaitingDevice']&&$module->preview('zweite',$testKey)['questionScope']['deviceId']===$choices[1]['id'],'Text preview shares the real session planner');
$before=count($GLOBALS['hcWrites']);
foreach ([$catalog,$legacy] as $data) {
    $scope=['type'=>'household_device','deviceId'=>$choices[0]['id']];
    iah_rc_voice('Starte den Backofen',['question_scope'=>$scope,'allow_writes'=>true],$data);
}
hcAssert(count($GLOBALS['hcWrites'])===$before,'A selection never executes a previous ambiguous command');
$denied=$catalog;$denied['dataSources']['household_devices']['devices']['hc_'.$choices[0]['id']]['enabled']=false;
$r=iah_rc_voice($houseQuestion,['question_scope'=>['type'=>'household_device','deviceId'=>$choices[0]['id']]],$denied);
hcAssert(str_contains($r['answer'],'nicht mehr')&&$r['displayRows']===[],'Current permissions revalidated after a choice');
echo "PASS compound oven ASR, numbered choices, live Action serialization, session isolation, preview, expiry and read-only permissions\n";
