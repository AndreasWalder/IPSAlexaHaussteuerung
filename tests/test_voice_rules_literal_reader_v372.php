<?php
declare(strict_types=1);

/**
 * Regression: production snapshot accessible via IPS_GetScriptContent
 * but an include may be cached/stale or inconsistent with the source.
 */
require __DIR__.'/test_voice_rules_prod_refresh_ai_v372.php';

function ckLiteralVoice(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException('VoiceRules literal snapshot: '.$message);
}

$source=IPS_GetScriptContent(501);
$parsed=IAHVoiceRuleLiteralReader::read($source);
$included=require IPS_GetScriptFile(501);
ckLiteralVoice(is_array($parsed) && $parsed===$included
    && count(IAHVoiceRuleEngine::rules($parsed))>=8,
    'strict source parser reads full published PHP array identical to original');

$special=[
    'voiceRules'=>[
        'schemaVersion'=>1,
        'items'=>[
            'wer_bist_du'=>[
                'title'=>"It's ein Test \\ aus Österreich",
                'aliases'=>['Wer bist du',"Was sind 10 %?",'Andreas'],
                'answer'=>"Zeile 1\nZeile 2",
                'enabled'=>true,'confirmed'=>true,'scriptId'=>0,
                'steps'=>[],'scriptParams'=>['quote'=>"ab'cd","slash"=>"D:\\Beispiel"],
                'probability'=>0.0,'target'=>-2,'notSet'=>null,'flag'=>false
            ]
        ]
    ],
    'navigation'=>['items'=>['nested'=>[10,20,30]]],
    'keep'=>'Unverändert'
];
$encoded=IAHSystemNavigationEditor::encode($special);
ckLiteralVoice(IAHVoiceRuleLiteralReader::read($encoded)===$special,
    'round-trip var_export strings, backslashes, negative values, floats and nested arrays');
ckLiteralVoice(IAHVoiceRuleLiteralReader::read('<?php return system("echo TEST");')===null,
    'no arbitrary PHP function calls allowed');
ckLiteralVoice(IAHVoiceRuleLiteralReader::read('<?php return array("x"=>1); echo "bad";')===null,
    'no code accepted after the literal return');
ckLiteralVoice(IAHVoiceRuleLiteralReader::read('<?php return [1,2,3')===null,
    'broken PHP array rejected');

$beforeCount=count($GLOBALS['vr371Calls']??[]);
$originalFile=file_get_contents(IPS_GetScriptFile(501));
try {
    // Simulate a stale/wrong runtime include while IPS_GetScriptContent
    // still returns the verified published snapshot. This reproduced the
    // observed symptom: exported PROD 8, Alexa runtime PROD 0.
    file_put_contents(IPS_GetScriptFile(501),
        '<?php return ["voiceRules"=>["schemaVersion"=>1,"items"=>[]]];');
    $event=json_encode(['text'=>'wer bist du','intent'=>'GetHaus',
        'aplEvent'=>false,'skipLegacyFallback'=>true],JSON_THROW_ON_ERROR);
    $result=json_decode($module->ResolveVoiceRule($event),true,512,JSON_THROW_ON_ERROR);
    ckLiteralVoice(!empty($result['ok']) && ($result['ruleKey']??'')==='wer_bist_du',
        'read snapshot bytes instead of different on-disk include');
    ob_start();$module->ExportVoiceRulesDiagnostic();ob_end_clean();
    $dump=json_decode($module->ReadAttributeString('VoiceRulesDiagnosticJson'),true,
        512,JSON_THROW_ON_ERROR);
    ckLiteralVoice(($dump['runtimeSource']['prodLoadMethod']??'')==='literal_source'
        && ($dump['runtimeSource']['prodRulesRead']??0)>=8,
        'diagnosis reports exact source parsing and all published rules');
    $trace=end($dump['recentRecognition']);
    ckLiteralVoice(($trace['prodLoadMethod']??'')==='literal_source'
        && ($trace['prodScriptId']??0)===501
        && ($trace['prodRuleCount']??0)>=8,
        'Alexa trace identifies safe source reader and correct published count');
    ckLiteralVoice(IPS_GetScriptContent(501)===$source
        && count($GLOBALS['vr371Calls']??[])===$beforeCount,
        'read-only resolver did not mutate PROD or execute a scene');
}finally{
    file_put_contents(IPS_GetScriptFile(501),$originalFile);
}
echo "PASS VoiceRules literal source read; stale include ignored, 8 PROD rules, safe diagnostics\n";
