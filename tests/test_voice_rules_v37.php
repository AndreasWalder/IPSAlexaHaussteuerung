<?php
declare(strict_types=1);
require dirname(__DIR__).'/IPSAlexaHaussteuerung/src/VoiceRuleEngine.php';
function ck37(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('V3.7 '.$message);
}
$GLOBALS['vrVars']=[
    60001=>['VariableType'=>0,'action'=>true],
    60002=>['VariableType'=>0,'action'=>false],
    60003=>['VariableType'=>1,'action'=>true],
    60004=>['VariableType'=>1,'action'=>true],
    60005=>['VariableType'=>2,'action'=>true]
];
$GLOBALS['vrWrites']=[];$GLOBALS['vrScripts']=[];
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['vrVars'][$id]);}
function HasAction(int $id):bool{return !empty($GLOBALS['vrVars'][$id]['action']);}
function IPS_GetVariable(int $id):array{return $GLOBALS['vrVars'][$id]??[];}
function RequestAction(int $id,mixed $value):bool{$GLOBALS['vrWrites'][]=[$id,$value];return true;}
function IPS_ScriptExists(int $id):bool{return in_array($id,[801,802,803],true);}
function IPS_RunScript(int $id):void{$GLOBALS['vrScripts'][]=$id;}
function checkException(callable $call,string $message):void {
    try{$call();}catch(RuntimeException $e){return;}
    throw new RuntimeException('Expected rejection: '.$message);
}
$device=static function(string $name,string $type,array $v,bool $allowed=true):array {
    return ['title'=>$name,'kiEnabled'=>true,'kiReadOnly'=>!$allowed,
        'ai'=>['version'=>1,'type'=>$type,'variables'=>$v,
            'review'=>['status'=>'confirmed']]];
};
$catalog=[
    'wohnzimmer'=>['display'=>'Wohnzimmer','domains'=>[
        'licht'=>['switches'=>[
            'decke'=>$device('Wohnzimmer Deckenlicht','light',['power'=>60002,'control'=>60001]),
            'unsafe'=>$device('Licht ohne KI-Freigabe','light',['control'=>60001],false)
        ],'dimmers'=>['stehlampe'=>$device('Stehlampe','dimmer',['brightness'=>60003])]],
        'jalousie'=>[
            'fenster'=>$device('Wohnzimmer Jalousie','blind',['position'=>60002,
                'targetPosition'=>60004,'action'=>60005])
        ],
        'heizung'=>[
            'thermostat'=>$device('Thermostat','heating',
                ['temperature'=>60005,'targetTemperature'=>60005])
        ]
    ]],
    'laptop'=>['display'=>'Laptop','domains'=>[
        'licht'=>['switches'=>['test'=>$device('Laptop-Test','light',['control'=>60001])]]
    ]]
];
$caps=IAHVoiceRuleEngine::capabilities($catalog);
ck37(count($caps)===5 && !isset($caps['laptop/licht/switches/test']),
    'five devices indexed with test room excluded');
ck37(isset($caps['wohnzimmer/licht/switches/decke']['roles']['power'])
    && !isset($caps['wohnzimmer/licht/switches/unsafe']['roles']['power'])
    && $caps['wohnzimmer/jalousie/jalousie/fenster']['roles']['targetPosition']===60004,
    'writing only after explicit confirmed rights, no blind real-position feedback');
$initial=['navigation'=>['mode'=>'active'],'custom'=>['Token'=>'KEEP']];
$scene=[
    'title'=>'Guten Abend','aliases'=>['Guten Abend','Es ist Abend'],
    'kind'=>'scene','answer'=>'Schönen Abend!','imageUrl'=>'',
    'intentName'=>'GutenAbendIntent',
    'scriptId'=>0,'steps'=>[
        ['deviceKey'=>'wohnzimmer/licht/switches/decke','property'=>'power','value'=>'false'],
        ['deviceKey'=>'wohnzimmer/licht/dimmers/stehlampe','property'=>'brightness','value'=>'20'],
        ['deviceKey'=>'wohnzimmer/jalousie/jalousie/fenster','property'=>'targetPosition','value'=>'100']
    ],'enabled'=>true,'confirmed'=>true
];
$cfg=IAHVoiceRuleEngine::change($initial,'guten_abend',$scene);
ck37($cfg['custom']===$initial['custom'] && !isset($initial['voiceRules']),
    'voice rules additive, no mutation of input or navigation');
$answer=['title'=>'Wer ist Andreas?','aliases'=>['Wer ist Andreas','Kennst du Andreas'],
    'kind'=>'answer','answer'=>'Dies ist eine frei konfigurierbare Antwort.','imageUrl'=>'',
    'enabled'=>true,'confirmed'=>false,'scriptId'=>0,'steps'=>[]];
$cfg=IAHVoiceRuleEngine::change($cfg,'wer_ist_andreas',$answer);
ck37(IAHVoiceRuleEngine::normalize('  WER ist Ändreas?? ') === 'wer ist aendreas',
    'German normalization must be deterministic');
$q=IAHVoiceRuleEngine::run($cfg,$catalog,'Wer ist Andreas?');
ck37($q['matched'] && $q['ok'] && str_contains($q['answer'],'frei konfigurierbare')
    && !$GLOBALS['vrWrites'],'custom exact FAQ answers never execute an action');
$sceneResult=IAHVoiceRuleEngine::run($cfg,$catalog,'Guten Abend');
ck37($sceneResult['ok'] && $sceneResult['executed']===3
    && $GLOBALS['vrWrites']===[[60001,false],[60003,20],[60004,100]],
    'multi-device scene executes all prevalidated actions with correct typed values');
$GLOBALS['vrWrites']=[];
$slotless=IAHVoiceRuleEngine::run($cfg,$catalog,'',
    ['intent'=>'GutenAbendIntent']);
ck37($slotless['matched'] && $slotless['ok']
    && count($GLOBALS['vrWrites'])===3,
    'dedicated Alexa Intent must trigger approved rule without free text slot');
$GLOBALS['vrWrites']=[];
ck37(IAHVoiceRuleEngine::run($cfg,$catalog,'',['intent'=>'DifferentIntent'])['matched']===false
    && !$GLOBALS['vrWrites'],
    'unmapped Alexa intent must never execute a script or scene');
$GLOBALS['vrWrites']=[];
ck37(IAHVoiceRuleEngine::run($cfg,$catalog,'Guten Morgen')['matched']===false
    && !$GLOBALS['vrWrites'],'no fuzzy command matching and no guessed actions');
$wrong=$scene;$wrong['steps'][1]['value']='200';
checkException(static fn()=>IAHVoiceRuleEngine::planScene($wrong,$catalog),
    'out-of-range dimming must block entire scene');
$wrong=$scene;$wrong['steps'][1]['deviceKey']='wohnzimmer/licht/switches/unsafe';
$badCfg=$cfg;$badCfg['voiceRules']['items']['guten_abend']=$wrong;
$failed=IAHVoiceRuleEngine::run($badCfg,$catalog,'Guten Abend');
ck37($failed['matched'] && !$failed['ok'] && !$GLOBALS['vrWrites'],
    'no partial scene execution if any device is unauthorized');
$script=['title'=>'Abend-Skript','aliases'=>['Starte Abendprogramm'],
    'kind'=>'script','scriptId'=>801,'answer'=>'Abendprogramm gestartet',
    'imageUrl'=>'https://example.org/alexa/abend.png','steps'=>[],
    'enabled'=>true,'confirmed'=>true];
$cfg=IAHVoiceRuleEngine::change($cfg,'abend_script',$script);
$forbidden=IAHVoiceRuleEngine::run($cfg,$catalog,'Starte Abendprogramm',
    ['forbiddenScriptIds'=>[801]]);
ck37($forbidden['matched'] && !$forbidden['ok']
    && !$GLOBALS['vrScripts'],'system script ID must be forbidden');
$allowed=IAHVoiceRuleEngine::run($cfg,$catalog,'Starte Abendprogramm');
ck37($allowed['ok'] && $GLOBALS['vrScripts']===[801]
    && $allowed['imageUrl']==='https://example.org/alexa/abend.png',
    'selected script executes without any arbitrary Alexa arguments');
$unconfirmed=$script;$unconfirmed['confirmed']=false;
checkException(static fn()=>IAHVoiceRuleEngine::change($cfg,'unconfirmed',$unconfirmed),
    'enabled script requires explicit confirmation');
$badImg=$answer;$badImg['imageUrl']='http://localhost/private.png';
checkException(static fn()=>IAHVoiceRuleEngine::change($cfg,'badimage',$badImg),
    'no insecure local card images');
$dup=$answer;$dup['aliases']=['WER ist ANDREAS'];
checkException(static fn()=>IAHVoiceRuleEngine::change($cfg,'collision',$dup),
    'duplicate phrase across rules');
$duplicateIntent=$answer;$duplicateIntent['aliases']=['Andere Frage'];
$duplicateIntent['intentName']='GutenAbendIntent';
checkException(static fn()=>IAHVoiceRuleEngine::change($cfg,'duplicate_intent',$duplicateIntent),
    'two rules must not claim the same Alexa Intent');
$reservedIntent=$answer;$reservedIntent['aliases']=['Test eigener Intent'];
$reservedIntent['intentName']='HomeAgentFrageIntent';
checkException(static fn()=>IAHVoiceRuleEngine::change($cfg,'reserved_intent',$reservedIntent),
    'existing production Alexa question Intent may not be overridden');
$reserved=$answer;$reserved['aliases']=['Jalousie'];
checkException(static fn()=>IAHVoiceRuleEngine::change($cfg,'reserved',$reserved),
    'legacy Alexa tile cannot silently be replaced');
$legacy=IAHVoiceRuleEngine::legacyDrafts();
ck37(count($legacy)>=5 && empty($legacy['jalousien_oeffnen']['enabled']),
    'legacy hardcoded QA and blinds become inactive migration templates');
$tpl=$cfg;
foreach($legacy as $key=>$r)$tpl=IAHVoiceRuleEngine::change($tpl,$key,$r);
ck37(isset($tpl['voiceRules']['items']['wer_ist_andreas'])
    && count($tpl['voiceRules']['items'])===count($cfg['voiceRules']['items'])+4,
    'legacy template import is additive and must skip existing same key');
$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
$module=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/module.php');
ck37(str_contains($action,'IAH_VOICE_RULES_V37_BEGIN')
    && str_contains($action,'IAH_ResolveVoiceRule(')
    && str_contains($module,'patchActionScriptVoiceRules')
    && str_contains($module,'buildVoiceRuleEditorForm'),
    'Alexa voice hook must be patched and editable centrally in module');
echo "PASS V3.7: exact aliases, free QA, card URL, confirmed scene/script rights, legacy templates and Alexa bridge\n";
