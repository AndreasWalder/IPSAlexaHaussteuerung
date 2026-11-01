<?php
declare(strict_types=1);
$GLOBALS['tree']=['16159:iahDiag'=>20001,'20001:iahVoiceDiag'=>20002,'20002:iahVoiceState'=>20003,'20002:iahVoiceLast'=>20004,'20002:iahVoiceHistory'=>20005,'20002:iahVoiceCounters'=>20006,'20002:iahVoiceContexts'=>20007];
$GLOBALS['variables']=array_fill_keys(range(20003,20007),'');
function IPS_GetObjectIDByIdent(string $ident,int $parent):int{return $GLOBALS['tree'][$parent.':'.$ident]??0;}
function IPS_VariableExists(int $id):bool{return array_key_exists($id,$GLOBALS['variables']);}
function GetValueString(int $id):string{return $GLOBALS['variables'][$id];}
function SetValueString(int $id,string $s):void{$GLOBALS['variables'][$id]=$s;}
function IPS_SemaphoreEnter(string $lock,int $ms):bool{return true;}
function IPS_SemaphoreLeave(string $lock):void{}
function IPS_LogMessage(string $channel,string $msg):void{}
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/VoiceIntentRouter.php';
$question=iah_vir_ip_symcon(['instanceId'=>16159,'op'=>'classify','intent'=>'GetHaus','requestType'=>'IntentRequest','text'=>'Welche Lampen sind eingeschaltet?','legacySlots'=>['Action'=>'Welche Lampen sind eingeschaltet?'],'deviceId'=>'unit-test']);
if(($question['category']??'')!=='query')throw new RuntimeException('Light state question not classified as query');
$last=json_decode($GLOBALS['variables'][20004],true);
if(($last['category']??'')!=='query')throw new RuntimeException('Router last decision not written in nested Diagnose category');
$tile=iah_vir_ip_symcon(['instanceId'=>16159,'op'=>'classify','intent'=>'GetHaus','text'=>'Licht','legacySlots'=>['Device'=>'licht'],'deviceId'=>'unit-test']);
if(($tile['route']??'')!=='licht')throw new RuntimeException('Single tile must still navigate');
$GLOBALS['tree']=['16159:iahVoiceDiag'=>20002,'20002:iahVoiceState'=>20003];
$legacy=iah_vir_ip_symcon(['instanceId'=>16159,'op'=>'classify','intent'=>'HomeAgentFrageIntent','text'=>'Welche Lichter sind an','deviceId'=>'unit-test']);
if(($legacy['category']??'')!=='query')throw new RuntimeException('Legacy path should still work');
echo "PASS nested diagnostic parent, light question, voice navigation and legacy fallback\n";
