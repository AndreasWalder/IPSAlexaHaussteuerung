<?php
declare(strict_types=1);

require __DIR__.'/test_alexa_voice_test_v381.php';

$catalog['wohnzimmer']['floor']='EG';
$catalog['buero']=['display'=>'Büro','aliases'=>['Arbeitszimmer'],'floor'=>'1. OG',
    'domains'=>['licht'=>['switches'=>['og'=>['title'=>'Bürolicht','state'=>811,'kiEnabled'=>true]]]]];
IPS_SetScriptContent(502,IAHSystemNavigationEditor::encode($catalog));
$GLOBALS['preview38Values']=[810=>true,811=>true];
$a=str_repeat('a',32);
$b=str_repeat('b',32);
$preview=static fn(string $text,string $session):array=>
    json_decode($module->GetAlexaVoiceTest($text,'PROD',$session),true,512,JSON_THROW_ON_ERROR);
$new='Welche Lichter sind eingeschaltet?';
$follow='Und im Obergeschoss?';
ck38(str_contains($preview($follow,$a)['answer'],'zuerst die vollständige'),
    'no topic is guessed when a conversation starts with a follow-up');
ck38(str_contains($preview($new,$a)['answer'],'Deckenlicht'),'first question uses whole home');
$og=$preview($follow,$a);
ck38($og['questionScope']['rooms']===['buero']&&str_contains($og['answer'],'Bürolicht')
    &&!str_contains($og['answer'],'Deckenlicht'),'follow-up uses only matching floor, even with 1. OG spelling');
ck38(str_contains($preview($follow,$b)['answer'],'zuerst die vollständige'),
    'different test forms cannot access each other or Alexa context');
$GLOBALS['preview38Values'][811]=false;
ck38($preview($follow,$a)['readQueries'][0]['result']['matchedReadings']===0,'follow-up reads fresh data');
ck38($preview('Und im Arbeitszimmer?',$a)['questionScope']['name']==='Büro',
    'configured room aliases are resolved');
ck38($preview('Und im Wintergarten?',$a)['answer']==='Welchen Raum oder welche Etage meinst du?',
    'unknown place cannot expand query back to the whole house');
ck38(str_contains($preview('Und welche Lichter sind ausgeschaltet?',$a)['answer'],'Wandlicht'),
    'a complete new question clears location and switches state filter');
ck38(str_contains($preview($follow,$a)['answer'],'Bürolicht'),'next follow-up inherits new question');
ck38($preview('Wie warm ist es im Haus?',$a)['category']==='house_question',
    'a different full question is recognized even with no configured API');
ck38(str_contains($preview($follow,$a)['answer'],'zuerst die vollständige'),
    'failed new question must not retain earlier light topic');
$preview($new,$a);
$preview('Wer ist Andreas?',$a);
ck38(str_contains($preview($follow,$a)['answer'],'zuerst die vollständige'),
    'a new custom question also stops the previous house context');

$live=static fn(string $session,string $device,string $text):array=>
    json_decode($module->PrepareAlexaHouseQuestion($session,$device,$text,502),true,512,JSON_THROW_ON_ERROR);
$liveCatalog=['catalog_script_id'=>502];
$first=iah_rc_voice($new,$liveCatalog);
ck38(!empty($first['contextEligible']),'live light question works without OpenAI and can seed context');
$module->RememberAlexaHouseQuestion('session-one','echo-one',$new);
$livePlan=$live('session-one','echo-one',$follow);
ck38(($livePlan['scope']['rooms']??[])===['buero'],'Alexa and preview use the same location resolver');
$liveAnswer=iah_rc_voice($livePlan['question'],$liveCatalog+['question_scope'=>$livePlan['scope'],'allow_writes'=>true]);
ck38(str_contains($liveAnswer['answer'],'1. OG') && !str_contains($liveAnswer['answer'],'Deckenlicht'),
    'real KI helper handles follow-up using scoped live values');
foreach([['session-two','echo-one'],['session-one','echo-two'],['','echo-one']] as [$sid,$device])
    ck38(isset($live($sid,$device,$follow)['answer']),'no cross-session, cross-device or absent-session reuse');
$live('session-one','echo-one','Und wie warm ist es?');
ck38(isset($live('session-one','echo-one',$follow)['answer']),
    'new complete Alexa question invalidates old topic before querying');
$module->RememberAlexaHouseQuestion('session-one','echo-one','Welche Lichter sind im Erdgeschoss eingeschaltet?');
$replacement=$live('session-one','echo-one',$follow);
ck38($replacement['question']==='Welche Lichter sind eingeschaltet?'
    && $replacement['scope']['rooms']===['buero'],'old explicit floor is replaced, not combined');
ck38(IAHHouseQuestionContext::withoutLocation('Welche Räume im Büro sind wärmer als 21,5 Grad?',$catalog)
    ==='Welche Räume sind wärmer als 21,5 Grad?', 'location replacement preserves numbers and original wording');
$module->ResetAlexaHouseQuestion('session-one','echo-one');
ck38(isset($live('session-one','echo-one',$follow)['answer']),'explicit session reset drops topic');
ck38(!str_contains($module->ReadAttributeString('HouseQuestionContexts'),'session-one'),
    'no raw Echo session IDs are stored');

$phase=0;
$scopedModel=static function(array $payload)use(&$phase):array{
    $tools=array_column(array_column($payload['tools'],'function'),'name');
    ck38(!in_array('set_device',$tools,true)&&!in_array('set_multiple',$tools,true),
        'Alexa follow-ups are read-only even with enabled KI commands');
    if($phase++===0)$message=['tool_calls'=>[['id'=>'s','function'=>['name'=>'query_house',
        'arguments'=>'{"property":"activity","domain":"licht","room":"Wohnzimmer"}']]]];
    else{
        $result=json_decode(end($payload['messages'])['content'],true,512,JSON_THROW_ON_ERROR);
        ck38($result['matchedDevices']===1&&$result['rows'][0]['name']==='Bürolicht',
            'model cannot undo the resolved room scope with a conflicting filter');
        $message=['content'=>'Im Büro ist das Licht aus.'];
    }
    return ['choices'=>[['message'=>$message]]];
};
$answer=iah_rc_voice('Welche Beleuchtung ist aktiv?',[
    'catalog_script_id'=>502,'api_key'=>'fake','allow_writes'=>true,
    'question_scope'=>$livePlan['scope']
],null,$scopedModel);
ck38($answer['handled']&&$answer['contextEligible']&&$phase===2,'scoped AI uses the same verified read path');

$module->WriteAttributeString('HouseQuestionContexts',json_encode([
    'test:'.$a=>['expires'=>time()-1,'question'=>$new]
],JSON_THROW_ON_ERROR));
ck38(str_contains($preview($follow,$a)['answer'],'zuerst die vollständige'),'stale preview context expires');
$panel1=json_encode(IAHVoiceRulePreviewForm::panel('test($VoiceTestConversation);'));
$panel2=json_encode(IAHVoiceRulePreviewForm::panel('test($VoiceTestConversation);'));
ck38($panel1!==$panel2&&!str_contains($panel1,'$VoiceTestConversation'),
    'newly opened form binds a fresh conversation directly into the callback');
$rcc->TestAlexaVoice(100,$new,'PROD',$a);
$rcc->TestAlexaVoice(100,$follow,'PROD',$a);
ck38(str_contains($rcc->updates['VoiceTestResult.value'],'questionScope'),'RoomsCatalog forwards its form conversation');
ck38($scriptCalls===$GLOBALS['vr371Calls']&&$GLOBALS['preview38Actions']===[],
    'no scripts or device actions were executed');
echo "PASS V3.8.2: session isolation, fresh scoped follow-ups and clean topic changes\n";
