<?php
declare(strict_types=1);
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';

function checkV343(bool $ok,string $test):void
{
    if(!$ok)throw new RuntimeException('V3.4.3: '.$test);
}
$root=dirname(__DIR__);
$source=file_get_contents($root.'/IPSAlexaHaussteuerung/resources/action_entry.php');
checkV343(is_string($source),'action template');
$start=strpos($source,'        // IAH_ALEXA_QUESTION_CAPTURE_BEGIN');
$end=strpos($source,'        // IAH_ALEXA_QUESTION_CAPTURE_END');
checkV343($start!==false&&$end!==false&&$end>$start,'question-capture markers');
$block=substr($source,$start,$end-$start);

// Die tatsaechliche PHP-Eingangsverarbeitung aus dem produktiven Template.
$capture=static function(string $intent,?string $slot,string $raw='')use($block):array{
    $intentName=$intent;
    $getSlotCH=static function(object $request,string $name)use($slot):?string{
        return $name==='Frage'?$slot:null;
    };
    $request=new stdClass();
    $rawUserText=$raw;
    $rawSlotValues=[];
    $rawSlotsToText=static fn(array $values):string=>implode(' ',$values);
    $log=static function(...$args):void{};
    eval($block);
    return [
        'spoken'=>$iahVoiceSpoken,
        'dedicated'=>$iahDedicatedSearchIntent,
        'prefix'=>$iahQuestionPrefix,
        'slot'=>$iahQuestionSlot,
    ];
};
$fixtures=[
    ['HausWelcheIntent','fenster sind offen','welche fenster sind offen'],
    ['HausWieIntent','warm ist es im Büro','wie warm ist es im Büro'],
    ['HausWasIntent','ist die temperatur im wohnzimmer','was ist die temperatur im wohnzimmer'],
    ['HausIstIntent','ein fenster offen','ist ein fenster offen'],
    ['HausSindIntent','alle jalousien zu','sind alle jalousien zu'],
    ['HomeAgentFrageIntent','welche fenster sind offen','welche fenster sind offen'],
];
foreach($fixtures as [$intent,$slot,$expected]){
    $captured=$capture($intent,$slot);
    checkV343($captured['dedicated'],$intent.' is a SearchQuery intent');
    checkV343($captured['spoken']===$expected,$intent.' spoken= '.var_export($captured['spoken'],true));
    $decision=iah_vir_classify(['intent'=>$intent,'text'=>$captured['spoken']]);
    checkV343($decision['category']==='query',$intent.' must be query');
}
checkV343($capture('HausWieIntent','wie warm ist es')['spoken']==='wie warm ist es',
    'do not duplicate prefix that Alexa includes');
checkV343($capture('HausWelcheIntent','')['spoken']==='','empty slot cannot synthesize a query');
checkV343($capture('HausWelcheIntent',null)['spoken']==='','null slot cannot synthesize a query');
checkV343($capture('GetHaus',null,'Licht')['spoken']==='Licht',
    'classic GetHaus text preserved');
checkV343(!$capture('GetHaus',null,'Licht')['dedicated'],'GetHaus is not a dedicated SearchQuery intent');

// Die echte V3.4.2-Gate-Closure muss alle neuen Intents unveraendert passieren lassen.
$gateStart=strpos($source,'        // IAH_KI_GATE_POLICY_BEGIN');
$gateEnd=strpos($source,'        // IAH_KI_GATE_POLICY_END');
checkV343($gateStart!==false&&$gateEnd>$gateStart,'policy boundaries');
eval(substr($source,$gateStart,$gateEnd-$gateStart));
foreach($fixtures as [$intent,$slot,$expected]){
    $result=$iahKiGatePolicy([
        'intent'=>$intent,'category'=>'query','aplEvent'=>false,
        'aplArgsCount'=>0,'legacyNav'=>'','launch'=>false,'back'=>false,
        'exit'=>false,'kiEnabled'=>true
    ]);
    checkV343($result['allowed']===true,'gate blocked '.$intent);
}
checkV343(str_contains($source,"'HomeAgentVoice.empty_gethaus'"),'empty GetHaus diagnosis missing');
checkV343(str_contains($source,'alexa_sent_no_slot_values'),'empty GetHaus reason missing');
checkV343(str_contains($source,'HomeAgentVoice.prompt_question'),'single-word Frage prompt missing');
checkV343(str_contains($source,'$homeAgentSpokenQuestion = $homeAgentDedicatedIntent')
    && str_contains($source,'? $iahVoiceSpoken'),
    'KI must receive reconstructed question rather than raw slot without question word');
$module=file_get_contents($root.'/IPSAlexaHaussteuerung/module.php');
checkV343(str_contains($module,'patchActionScriptKIVoice((int) $id)'),
    'existing Action script not migrated on update');

$package=json_decode((string)file_get_contents($root.'/docs/Alexa_Hausfragen_Intent_Paket.json'),true,512,JSON_THROW_ON_ERROR);
checkV343(count($package['intents']??[])===5,'missing direct Alexa question intents');
foreach($package['intents'] as $item){
    checkV343(count($item['slots']??[])===1
        && ($item['slots'][0]['type']??null)==='AMAZON.SearchQuery'
        && ($item['slots'][0]['name']??null)==='Frage',
        'only one SearchQuery per new intent');
    checkV343(count($item['samples']??[])===1
        && preg_match('/^[\\p{L}]+ \\{Frage\\}$/u',(string)$item['samples'][0])===1,
        'carrier phrase must precede SearchQuery');
}
echo "PASS: Alexa direct W-questions restored, empty SearchQuery preserved, classic navigation isolated, new intents valid\n";
