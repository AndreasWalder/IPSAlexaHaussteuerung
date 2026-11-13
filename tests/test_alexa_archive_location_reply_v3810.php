<?php
declare(strict_types=1);

require __DIR__.'/test_alexa_house_runtime_v388.php';

$spoken='wie wird wurde zu schluss das licht eingeschaltet';
$session='archive-session';
$echo='living-room-echo';
$module->ResetAlexaHouseQuestion($session,$echo);
$module->RememberAlexaHouseQuestion($session,$echo,$spoken,true);
ck38($module->IsAlexaHouseLocationReply($session,$echo,'Wohnzimmer',502),
    'recognizes bare room only while an archive clarification is pending');
ck38(!$module->IsAlexaHouseLocationReply($session,'other-echo','Wohnzimmer',502)
    && !$module->IsAlexaHouseLocationReply('other-session',$echo,'Wohnzimmer',502),
    'room reply never crosses Alexa sessions or devices');
$plan=json_decode($module->PrepareAlexaHouseQuestion($session,$echo,'Wohnzimmer',502),
    true,512,JSON_THROW_ON_ERROR);
ck38($plan['question']===$spoken && $plan['scope']['rooms']===['wohnzimmer'],
    'bare room retries the original archive question, not a scene');
ck38(!$module->IsAlexaHouseLocationReply($session,$echo,'Wohnzimmer',502),
    'pending prompt is consumed after one valid room reply');
$module->RememberAlexaHouseQuestion($session,$echo,$spoken);
ck38(!$module->IsAlexaHouseLocationReply($session,$echo,'Wohnzimmer',502),
    'ordinary completed house questions do not override navigation');
$module->RememberAlexaHouseQuestion($session,$echo,$spoken,true);
ck38(!$module->IsAlexaHouseLocationReply($session,$echo,'Welche Fenster sind offen?',502)
    && !$module->IsAlexaHouseLocationReply($session,$echo,'Wohnzimmer',502),
    'a new full question clears the pending prompt');
$module->RememberAlexaHouseQuestion($session,$echo,$spoken,true);
ck38(!$module->IsAlexaHouseLocationReply($session,$echo,'Schalte das Licht im Wohnzimmer aus',502),
    'a command does not become an archive follow-up');

$key='test:'.str_repeat('f',32);
$remember=new ReflectionMethod($module,'rememberHouseQuestion');
$remember->invoke($module,$key,['question'=>$spoken],['awaitingLocation'=>true]);
$result=json_decode($module->GetAlexaVoiceTest('Wohnzimmer','PROD',substr($key,5)),true,512,JSON_THROW_ON_ERROR);
ck38(($result['category']??'')==='house_question'
    && ($result['questionScope']['rooms']??[])===['wohnzimmer'],
    'text test uses the same scoped room follow-up');
$module->RememberAlexaHouseQuestion($session,$echo,'Wo wurde zuletzt draußen Bewegung erkannt?');
$motionArea=json_decode($module->PrepareAlexaHouseQuestion($session,$echo,'Und innen?',502),true);
ck38(($motionArea['scope']['area']??'')==='indoor' && $motionArea['question']==='Wo wurde zuletzt Bewegung erkannt?',
    'inside replaces the previous outdoor filter while retaining the archive question');
$motionRoom=json_decode($module->PrepareAlexaHouseQuestion($session,$echo,'Und im Wohnzimmer?',502),true);
ck38($motionRoom['scope']['rooms']===['wohnzimmer'] && $motionRoom['question']===$motionArea['question'],
    'an explicit room replaces the previous outdoor filter too');
$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
ck38(strpos($action,'IAH_IsAlexaHouseLocationReply(')<strpos($action,'if (!$iahPendingHouseLocation &&')
    && str_contains($action,"'reason'=>'pending_house_location'")
    && str_contains($action,"\$iahAwaitingLocation ? 'Rückfrage' : 'Beantwortet'"),
    'live Action prioritizes the read-only reply before voice rules and legacy navigation');

echo "PASS V3.8.10: pending archive room replies stay in their own Alexa session and never run a scene\n";
