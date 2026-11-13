<?php
declare(strict_types=1);

require __DIR__.'/test_voice_rules_literal_reader_v372.php';
require dirname(__DIR__).'/RoomsCatalogConfigurator/module.php';

function ck38(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException('V3.8: '.$message);
}
function IPS_GetInstanceListByModuleID(string $guid): array
{
    return $guid==='{F528D4D0-5729-4315-AE88-9BBABDBD0392}'?[100]:[];
}
function IAH_GetVoiceRuleTestPreview(int $id,string $text,string $source,string $intent,bool $ai): string
{
    ck38($id===100,'call goes to the selected Alexa instance');
    return $GLOBALS['preview38Alexa']->GetVoiceRuleTestPreview($text,$source,$intent,$ai);
}
function IPS_VariableExists(int $id): bool { return in_array($id,[810,811],true); }
function IPS_GetVariable(int $id): array { return ['VariableType'=>0]; }
function HasAction(int $id): bool { return $id===810; }
function GetValue(int $id): mixed { throw new LogicException('Never read live values'); }
function RequestAction(int $id,mixed $value): bool
{
    $GLOBALS['preview38Actions'][]=[$id,$value];
    return true;
}

$GLOBALS['preview38Alexa']=$module;
$GLOBALS['preview38Actions']=[];
$prod=IAHVoiceRuleLiteralReader::read(IPS_GetScriptContent(501));
$edit=$prod;
$edit['voiceRules']['items']['wer_ist_andreas']['answer']='Antwort nur in EDIT';
$rules=&$edit['voiceRules']['items'];
$rules['abend_preview']=[
    'title'=>'Abendprogramm','aliases'=>['Guten Abend Preview','Abends testen'],'kind'=>'script',
    'intentName'=>'AbendPreviewIntent','enabled'=>true,'confirmed'=>true,'scriptId'=>801,
    'scriptParams'=>['Mode'=>'test','PrivateValue'=>'not-for-preview'],
    'imageFile'=>'Abend.png','cardTitle'=>'Abendkarte'
];
$rules['abend_variable']=[
    'title'=>'Abendvariable','aliases'=>['Abendvariable testen'],'kind'=>'variable',
    'enabled'=>true,'confirmed'=>true,'variableId'=>810,'variableValue'=>'true'
];
$rules['abend_scene']=[
    'title'=>'Abendszene','aliases'=>['Abendszene testen'],'kind'=>'scene',
    'enabled'=>true,'confirmed'=>true,'steps'=>[
        ['deviceKey'=>'wohnzimmer/licht/switches/decke','property'=>'power','value'=>'false']
    ]
];
$rules['abend_read']=[
    'title'=>'Abendmesswert','aliases'=>['Wie ist der Abendmesswert'],'kind'=>'read',
    'enabled'=>true,'readVariableId'=>811,'answer'=>'Aktuell {value}'
];
$rules['inactive']=array_replace($rules['abend_preview'],[
    'aliases'=>['Deaktivierte Testregel'],'intentName'=>'','enabled'=>false
]);
$rules['forbidden']=array_replace($rules['abend_preview'],[
    'aliases'=>['Systemskript testen'],'intentName'=>'','scriptId'=>501
]);
unset($rules);
$catalog=['wohnzimmer'=>['display'=>'Wohnzimmer','domains'=>[
    'licht'=>['switches'=>['decke'=>[
        'title'=>'Deckenlicht','kiEnabled'=>true,'kiReadOnly'=>false,
        'ai'=>['type'=>'light','version'=>1,'variables'=>['control'=>810],
            'review'=>['status'=>'confirmed']]
    ]]]
]]];
IPS_SetScriptContent(502,"<?php\ndeclare(strict_types=1);\nreturn ".var_export($catalog,true).";\n");
IPS_SetScriptContent($editId,IAHSystemNavigationEditor::encode($edit));
$before=$GLOBALS['sc333Scripts'];
$trace=$module->ReadAttributeString('VoiceRulesDiagnosticTrace');
$scriptCalls=$GLOBALS['vr371Calls'];
$preview=static fn(string $text,string $source='EDIT',string $intent='',bool $ai=false):array=>
    json_decode($module->GetVoiceRuleTestPreview($text,$source,$intent,$ai),true,512,JSON_THROW_ON_ERROR);

ck38($preview('Wer ist Andreas?')['answer']==='Antwort nur in EDIT'
    && $preview('Wer ist Andreas?','PROD')['answer']!== 'Antwort nur in EDIT',
    'EDIT and PROD use their own current source bytes');
file_put_contents(IPS_GetScriptFile(501),'<?php throw new RuntimeException("must not include");');
try {
    ck38($preview('Wer ist Andreas?','PROD')['ok']===true,'stale PROD file is never included');
}finally{
    file_put_contents(IPS_GetScriptFile(501),$before[501]);
}
$result=$preview('Abends testen?');
ck38($result['dryRun'] && $result['ok'] && $result['ruleKey']==='abend_preview'
    && $result['matchMethod']==='alias' && $result['matchedAlias']==='Abends testen'
    && $result['imageFile']==='Abend.png' && $result['cardTitle']==='Abendkarte'
    && $result['plannedAction']===['type'=>'script','scriptId'=>801,'parameterNames'=>['Mode','PrivateValue']]
    && !str_contains(json_encode($result),'not-for-preview'),
    'show alias, answer, image and script without exposing parameter values');
ck38(!$preview('Abends testen?','PROD')['matched']
    && $preview('','EDIT','AbendPreviewIntent')['matchMethod']==='intent'
    && $preview('Abends testen?','EDIT','AbendPreviewIntent')['matchMethod']==='alias',
    'unpublished commands remain absent in PROD and exact aliases have priority over Intent');
ck38($preview('Abendvariable testen')['plannedAction']===['type'=>'variable','variableId'=>810,'value'=>true],
    'show typed switch plan');
$scene=$preview('Abendszene testen');
ck38($scene['ok']&&$scene['plannedAction']['steps'][0]===[
    'id'=>810,'value'=>false,'name'=>'Deckenlicht','role'=>'power',
    'deviceKey'=>'wohnzimmer/licht/switches/decke'
], 'show scene plan checked against the productive catalog');
ck38($preview('Wie ist der Abendmesswert')['answer']==='Aktuell {value}',
    'read rule is a template with no live-value reads');
ck38($preview('Deaktivierte Testregel')['inactiveMatches']===['inactive']
    && !$preview('Systemskript testen')['ok']
    && !$preview('frei erfundener Testsatz')['matched'],
    'diagnose inactive, blocked and unknown rules');
ck38(!$preview('Abends testen','WRONG')['ok']&&!$preview('')['ok'],'reject invalid source and empty input');

IPS_SetScriptContent($editId,'<?php $GLOBALS["preview38ExecutedConfig"]=true; return [];');
ck38(!$preview('Abends testen')['ok']&&!isset($GLOBALS['preview38ExecutedConfig']),
    'dynamic source must not execute during preview');
IPS_SetScriptContent($editId,$before[$editId]);

$aiText='Wie war der Abendmesswert denn eigentlich?';
$model=static fn(array $allowed,string $text): array=>['key'=>'abend_read','confidence'=>0.99];
$aiMatch=IAHVoiceRuleAiMatcher::match($edit,$aiText,$model);
ck38(($aiMatch['method']??'')==='ai_read_only'
    && IAHVoiceRuleEngine::preview($aiMatch,null)['plannedAction']['type']==='read'
    && IAHVoiceRuleAiMatcher::match($edit,$aiText,static fn()=>[
        'key'=>'abend_preview','confidence'=>1
    ])===null, 'same AI resolver as live, only informational keys');
$ambiguous=$edit;
$ambiguous['voiceRules']['items']['same_alias']=$edit['voiceRules']['items']['abend_read'];
ck38(IAHVoiceRuleAiMatcher::match($ambiguous,$aiText,$model)===null,
    'AI cannot resolve an alias shared by multiple enabled rules');

$module->ShowVoiceRuleTestPreview('Abends testen','EDIT','',false);
ck38(str_contains($module->updates['VoiceTestSummary.caption']??'','Alias')
    && str_contains($module->updates['VoiceTestResult.value']??'','abend_preview'),
    'Alexa instance displays readable summary and copyable preview');
$rcc=new RoomsCatalogConfigurator();
$rcc->TestAlexaVoiceRule(100,'Abends testen','EDIT','',false);
ck38(str_contains($rcc->updates['VoiceTestSummary.caption']??'','Abendprogramm')
    && str_contains($rcc->updates['VoiceTestResult.value']??'','abend_preview'),
    'RoomsCatalog delegates to Alexa and displays the same result');
$rcc->TestAlexaVoiceRule(200,'Abends testen','EDIT','',false);
ck38(str_contains($rcc->updates['VoiceTestSummary.caption']??'','Instanz auswählen'),
    'reject an unrelated instance');
$rccPanel=(new ReflectionMethod($rcc,'buildVoiceTestPanel'))->invoke($rcc);
ck38($rccPanel['expanded']===false&&$rccPanel['items'][1]['value']===100
    && str_contains(json_encode($rccPanel),'RCC_TestAlexaVoiceRule'),
    'RoomsCatalog panel starts collapsed and auto-selects the only Alexa instance');

$form=json_decode($module->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
$checkPanels=static function(array $items)use(&$checkPanels):void {
    foreach($items as $item){
        if(($item['type']??'')==='ExpansionPanel')
            ck38(($item['expanded']??null)===false,'Alexa panels start collapsed: '.$item['caption']);
        if(isset($item['items']))$checkPanels($item['items']);
    }
};
$checkPanels($form['elements']);
ck38($before===$GLOBALS['sc333Scripts']
    && $trace===$module->ReadAttributeString('VoiceRulesDiagnosticTrace')
    && $scriptCalls===$GLOBALS['vr371Calls'] && $GLOBALS['preview38Actions']===[],
    'no config writes, new backups, live trace entries, script starts or device actions');
echo "PASS V3.8: EDIT/PROD dry run, safe plans, optional read-only AI, both forms and collapsed startup\n";
