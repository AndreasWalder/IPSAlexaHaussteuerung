<?php
declare(strict_types=1);

require __DIR__.'/test_voice_rules_v37.php';

function IPS_RunScriptEx(int $scriptId,array $parameters):bool
{
    $GLOBALS['v371Executed'][]=['id'=>$scriptId,'params'=>$parameters];
    return true;
}

function ck371(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException('V3.7.1 '.$label);
}
function reject371(callable $fn,string $label):void
{
    try{$fn();}catch(RuntimeException $ignored){return;}
    throw new RuntimeException('V3.7.1 expected rejection: '.$label);
}
$GLOBALS['v371Executed']=[];
$generic=[
    'title'=>'Guten Abend mit Bild und Skript',
    'aliases'=>['Guten Abend starten','Abendprogramm starten'],
    'kind'=>'script','intentName'=>'AbendprogrammIntent',
    'scriptId'=>801,
    'scriptParams'=>'{"Richtung":"Auf","Position":0,"Schatten":false,"Stufe":0.75}',
    'imageFile'=>'Abend.png',
    'imageUrl'=>'',
    'cardTitle'=>'Haus – Abendprogramm',
    'cardText'=>'Jalousien werden geöffnet und Licht wird gedimmt.',
    'answer'=>'Das Abendprogramm wurde gestartet.',
    'steps'=>[],'enabled'=>true,'confirmed'=>true
];
$conf=IAHVoiceRuleEngine::change($cfg,'generic_abend',$generic);
$stored=$conf['voiceRules']['items']['generic_abend'];
ck371($stored['scriptParams']===[
    'Richtung'=>'Auf','Position'=>0,'Schatten'=>false,'Stufe'=>0.75
], 'typed RunScriptEx parameters preserved in PROD catalog');
ck371($stored['imageFile']==='Abend.png'&&$stored['imageUrl']===''
    && $stored['cardTitle']==='Haus – Abendprogramm',
    'local icon and independent Alexa card text are per-rule settings');
$result=IAHVoiceRuleEngine::run($conf,$catalog,'Abendprogramm starten');
ck371($result['matched']&&$result['ok']
    && !empty($result['scriptStarted'])
    && $result['imageFile']==='Abend.png'
    && $result['cardText']==='Jalousien werden geöffnet und Licht wird gedimmt.'
    && $GLOBALS['v371Executed']===[
        ['id'=>801,'params'=>['Richtung'=>'Auf','Position'=>0,'Schatten'=>false,'Stufe'=>0.75]]
    ],'script parameters, card data and exact alias executed');
$GLOBALS['v371Executed']=[];
$byIntent=IAHVoiceRuleEngine::run($conf,$catalog,'',['intent'=>'AbendprogrammIntent']);
ck371($byIntent['ok']&&count($GLOBALS['v371Executed'])===1,
    'existing slot-free Alexa Intent also triggers the generic script');

$copy=$generic;$copy['scriptParams']='{"Richtung":{"nested":"code"}}';
reject371(static fn()=>IAHVoiceRuleEngine::validateRule($copy),'nested JSON data');
$copy=$generic;$copy['scriptParams']='{"SENDER":"StatusEvent"}';
reject371(static fn()=>IAHVoiceRuleEngine::validateRule($copy),'reserved Symcon $_IPS name');
$copy=$generic;$copy['scriptParams']='not-json';
reject371(static fn()=>IAHVoiceRuleEngine::validateRule($copy),'invalid JSON');
$copy=$generic;$copy['imageFile']='../../secret.png';
reject371(static fn()=>IAHVoiceRuleEngine::validateRule($copy),'image path traversal');
$copy=$generic;$copy['imageFile']='Abend.php';
reject371(static fn()=>IAHVoiceRuleEngine::validateRule($copy),'nonimage asset');
$copy=$generic;$copy['imageUrl']='https://example.org/picture.png';
reject371(static fn()=>IAHVoiceRuleEngine::validateRule($copy),'ambiguous image sources');
$copy=$generic;$copy['scriptParams']=['Richtung'=>'Auf','Wert'=>20];
ck371(IAHVoiceRuleEngine::validateRule($copy)['scriptParams']===
    ['Richtung'=>'Auf','Wert'=>20],'backward-compatible associative typed parameters');
$old=IAHVoiceRuleEngine::validateRule($script);
ck371(($old['imageFile']??'broken')==='' && ($old['scriptParams']??null)===[]
    && ($old['cardTitle']??'broken')==='',
    'already saved V3.7 scripts remain valid with default metadata');
$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
ck371(str_contains($action,'/hook/iah-alexa-icons?f=')
    && str_contains($action,'VoiceRules.image_missing')
    && str_contains($action,"['cardTitle']")
    && !str_contains($action,'$ruleImage=(string)($iahRuleResult[\'ruleKey\']'),
    'generic Alexa image hook, card fields, missing-file diagnostic');
echo "PASS V3.7.1: typed IPS_RunScriptEx arguments, per-command icon filename, HTTPS fallback, custom cards, old rules\n";
