<?php
declare(strict_types=1);

require __DIR__.'/test_voice_rules_editor_v37.php';

function ck371ui(bool $test,string $reason):void
{
    if(!$test)throw new RuntimeException('V3.7.1 UI '.$reason);
}
function IPS_RunScriptEx(int $id,array $args):bool
{
    $GLOBALS['vr371Calls'][]=['scriptId'=>$id,'args'=>$args];
    return true;
}
$GLOBALS['vr371Calls']=[];
$GLOBALS['sc333Scripts'][801]='<?php return 1;';
file_put_contents(IPS_GetScriptFile(801),$GLOBALS['sc333Scripts'][801]);

ob_start();$module->CreateVoiceRule('Abendroutine','Abendroutine','script');ob_end_clean();
$edit=require IPS_GetScriptFile($editId);
$found=null;
foreach(IAHVoiceRuleEngine::rules($edit) as $key=>$r)
    if(($r['title']??'')==='Abendroutine'){$found=$key;break;}
ck371ui($found!==null,'generic script rule created as EDIT draft');
$gear=json_decode($module->GetVoiceRuleEditForm($found),true,512,JSON_THROW_ON_ERROR);
$view=json_encode($gear,JSON_UNESCAPED_UNICODE);
foreach(['"name":"imageFile"','"name":"cardTitle"','"name":"cardText"',
    '"name":"scriptParams"','"name":"scriptId"'] as $field)
    ck371ui(str_contains($view,$field),'gear needs generic field '.$field);
$before=IPS_GetScriptContent(501);
$rule=IAHVoiceRuleEngine::rules($edit)[$found];
$payload=[
    'key'=>$found,'revision'=>hash('sha256',serialize($rule)),
    'title'=>'Abendroutine','aliases'=>'Abendroutine,Starte die Abendroutine',
    'kind'=>'script','intentName'=>'AbendroutineIntent',
    'answer'=>'Das Skript wurde gestartet.',
    'imageFile'=>'Abend.png','imageUrl'=>'',
    'cardTitle'=>'Abendlicht','cardText'=>'Die Abendroutine läuft.',
    'scriptId'=>801,'scriptParams'=>'{"Action":"abend","Helligkeit":25,"Sicher":true}',
    'enabled'=>true,'confirmed'=>true
];
ob_start();$module->SaveVoiceRule(json_encode($payload,JSON_THROW_ON_ERROR));$answer=ob_get_clean();
$edit=require IPS_GetScriptFile($editId);
$saved=IAHVoiceRuleEngine::rules($edit)[$found];
ck371ui(str_contains($answer,'gespeichert')
    && $saved['imageFile']==='Abend.png'
    && $saved['cardTitle']==='Abendlicht'
    && $saved['scriptParams']===['Action'=>'abend','Helligkeit'=>25,'Sicher'=>true]
    && IPS_GetScriptContent(501)===$before,
    'script parameters, image, card stored only in EDIT');
ob_start();$module->PreviewVoiceRules();$preview=ob_get_clean();
ck371ui(str_contains($preview,'geprüft'),'generic script rule must pass publish check');
ob_start();$module->PublishVoiceRules();$published=ob_get_clean();
ck371ui(str_contains($published,'nach PROD veröffentlicht'),
    'publish checked generic script with verified PROD backup');
$GLOBALS['vr371Calls']=[];
$resolved=json_decode($module->ResolveVoiceRule(
    '{"text":"Starte die Abendroutine","intent":"AbendroutineIntent","aplEvent":false}'),true);
ck371ui(($resolved['ok']??false)
    && ($resolved['scriptStarted']??false)
    && $resolved['imageFile']==='Abend.png'
    && $resolved['cardTitle']==='Abendlicht'
    && $resolved['cardText']==='Die Abendroutine läuft.'
    && $GLOBALS['vr371Calls']===[
        ['scriptId'=>801,'args'=>['Action'=>'abend','Helligkeit'=>25,'Sicher'=>true]]
    ],'published runtime invokes selected IPS_RunScriptEx with static parameters');
ck371ui(isset((require IPS_GetScriptFile(501))['navigation'])
    && (require IPS_GetScriptFile(501))['var']['Token']==='PRIVATE',
    'other SystemConfiguration PROD areas preserved');

echo "PASS V3.7.1 UI: reusable script/image/card fields, EDIT backup, reviewed PROD, runtime RunScriptEx\n";
