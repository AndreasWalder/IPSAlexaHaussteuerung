<?php
declare(strict_types=1);
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';
function t(bool $good,string $msg):void {if(!$good)throw new RuntimeException($msg);}
function classify(string $text,string $intent='HomeAgentFrageIntent',array $ctx=[]):array {
    return iah_vir_classify(['text'=>$text,'intent'=>$intent,'routes'=>[['route'=>'pv','title'=>'Photovoltaik','synonyms'=>['Solarstrom']]]],$ctx);
}
foreach(['Licht'=>'licht','Jalousie'=>'jalousie','Lüftung'=>'lueftung','Heizung'=>'heizung',
    'Öffne Licht'=>'licht','Öffne die Kachel Jalousie'=>'jalousie',
    'Zeige mir die Seite Lüftung'=>'lueftung','Photovoltaik'=>'pv',
    'Solarstrom'=>'pv','Gehe zum Menü Licht'=>'licht'] as $word=>$route){
    $decision=classify($word);
    t($decision['category']==='navigation'&&$decision['route']===$route,"navigation [$word] -> $route");
}
foreach(['zurück','Hauptmenü','Home','Zur Startseite'] as $word) {
    $dec=classify($word);
    t($dec['route']==='main_launch'&&$dec['category']==='navigation',"home [$word]");
}
foreach(['Welche Lichter sind an?','Wie warm ist das Büro?','Welche sind eingeschaltet'] as $word){
    t(classify($word)['category']==='query',"read-only question [$word]");
}
foreach(['Schalte das Licht im Wohnzimmer ein','Fahre die Jalousie runter','Dimme die Lampe'] as $word){
    t(classify($word)['category']==='action',"explicit action [$word]");
}
foreach(['Licht aus','Jalousie auf','Schlafzimmer'] as $word){
    $d=classify($word);
    t($d['category']==='clarify'&&$d['clarify'],"ambiguous input must clarify [$word]");
}
$apl=iah_vir_classify(['requestType'=>'Alexa.Presentation.APL.UserEvent','aplArgs'=>['GetHaus','licht'],'text'=>'']);
t($apl['category']==='apl_navigation'&&$apl['route']==='licht','APL GetHaus click');
$other=iah_vir_classify(['requestType'=>'Alexa.Presentation.APL.UserEvent','aplArgs'=>['licht.setEnum',123,1]]);
t($other['category']==='apl_action','APL action must bypass AI');
$data=[];$now=time();
$input=['op'=>'classify','deviceId'=>'example-echo-device-A','intent'=>'HomeAgentFrageIntent','text'=>'Licht','screen'=>true,'storeTranscript'=>true];
$x=iah_vir_execute($input,$data,$now);
t($x['category']==='navigation'&&$data['last']['transcript']==='Licht','spoken text logged when enabled');
t(!str_contains(json_encode($data),'example-echo-device-A'),'raw device ID must not be persisted');
iah_vir_execute(['op'=>'complete','deviceId'=>'example-echo-device-A','route'=>'licht','source'=>'voice_navigation','screen'=>true],$data,$now);
t($data['last']['category']==='ui_rendered'&&$data['counts']['ui_rendered']===1,'completed render recorded');
t($data['history'][1]['transcript']==='Licht','history preserves original utterance');
$otherDevice=iah_vir_execute(['op'=>'classify','deviceId'=>'example-echo-device-B','intent'=>'HomeAgentFrageIntent','text'=>'Welche sind an?'], $data, $now);
t($otherDevice['contextDomain']==='','context isolated per Alexa device');
$follow=iah_vir_execute(['op'=>'classify','deviceId'=>'example-echo-device-A','intent'=>'HomeAgentFrageIntent','text'=>'Welche sind an?','storeTranscript'=>false],$data,$now);
t($follow['contextDomain']==='licht'&&$data['last']['transcript']==='','read context, opt-out transcript');
$redacted=iah_vir_transcript('Mein Passwort ist supergeheim! und sk-proj-ABCDEF0123456789abc');
t(!str_contains($redacted,'supergeheim')&&!str_contains($redacted,'ABCDEF0123456789abc'),'spoken secrets redacted');
$expired=classify('Welche sind an?','HomeAgentFrageIntent',['domain'=>'licht','updatedUnix'=>time()-2000]);
t($expired['contextDomain']==='','old UI context expires');
$module=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/module.php');
t(str_contains($module,"RegisterPropertyBoolean('VoiceRouterStoreTranscript', true)"),'transcript setting present');
t(str_contains($module,"'VoiceIntentRouter.php'"),'router installed on module update');
$entry=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
t(str_contains($entry,'$iahVoiceDecision')&&str_contains($entry,'$iahVoiceNavKey'),'classification used before existing UI');
t(str_contains($entry,"'ui_context_domain' => $iahVoiceContextDomain"),'context passed to KI');
echo "PASS: classic tiles, APL navigation, query/action separation, safe transcript, screen context, expiry and isolation\n";
