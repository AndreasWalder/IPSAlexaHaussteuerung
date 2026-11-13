<?php
declare(strict_types=1);

require __DIR__.'/test_house_question_context_v382.php';

function IPS_GetVariableProfile(string $name): array
{
    return $GLOBALS['v383Profiles'][$name]??[];
}

$GLOBALS['v383Profiles']['~TestWindow']=['Associations'=>[
    ['Value'=>17,'Name'=>'Geschlossen'],['Value'=>19,'Name'=>'Geöffnet'],['Value'=>99,'Name'=>'Störung']
]];
$GLOBALS['v383Profiles']['~InvertedWindow']=['Associations'=>[
    ['Value'=>1,'Name'=>'Geschlossen'],['Value'=>0,'Name'=>'Offen']
]];
$GLOBALS['preview38Variables']=[
    820=>['VariableType'=>0],
    821=>['VariableType'=>1,'VariableProfile'=>'~TestWindow'],
    822=>['VariableType'=>1,'VariableProfile'=>'~TestWindow'],
    823=>['VariableType'=>0,'VariableProfile'=>'~InvertedWindow'],
    824=>['VariableType'=>0],
    825=>['VariableType'=>0],
    826=>['VariableType'=>0],
    827=>['VariableType'=>0]
];
$GLOBALS['preview38Values']=[810=>true,811=>true,820=>false,821=>17,822=>99,
    823=>true,824=>true,825=>false,826=>true,827=>false];
$window=static fn(string $name,array $variables,bool $enabled=true):array=>[
    'title'=>$name,'kiEnabled'=>$enabled,
    'ai'=>['version'=>1,'type'=>'window','variables'=>$variables]
];
$catalog['buero']['floor']='EG';
$catalog['buero']['domains']['sensoren']['fenster']=[
    'status'=>$window('Bürofenster',['status'=>821]),
    'invalid'=>$window('Bürofenster mit Störung',['status'=>822]),
    'missing'=>$window('Bürofenster ohne Rückmeldung',['open'=>899]),
    'private'=>$window('Privates Fenster',['open'=>826],false)
];
$catalog['wohnzimmer']['floor']='Erdgeschoss';
$catalog['wohnzimmer']['domains']['sensoren']['fenster']=[
    'main'=>$window('Wohnzimmerfenster',['open'=>820]),
    'profile'=>$window('Fenster mit invertiertem Profil',['open'=>823]),
    'conflict'=>$window('Fenster mit widersprüchlicher Rückmeldung',['open'=>824,'status'=>827])
];
$catalog['wohnzimmer']['domains']['sensoren']['fenster']['conflict']['ai']['stateMapping']=[
    'open'=>true,'closed'=>false
];
foreach(['oben'=>'1. OG','zweites'=>'2. OG','keller'=>'KG','dach'=>'DG'] as $room=>$floor){
    $catalog[$room]=['display'=>$room,'floor'=>$floor,'domains'=>[
        'sensoren'=>['fenster'=>['f'=>$window('Fenster '.$room,['open'=>825])]]
    ]];
}
$catalog['laptop']=['floor'=>'EG','domains'=>['sensoren'=>['fenster'=>[
    'test'=>$window('Testfenster',['open'=>826])
]]]];
IPS_SetScriptContent(502,IAHSystemNavigationEditor::encode($catalog));
$source=IPS_GetScriptContent(502);
$module->properties['HomeAgentVoiceEnabled']=true;
$module->properties['KIIntentApiKey']='';
$session=str_repeat('c',32);
$ask=static fn(string $text):array=>json_decode($module->GetAlexaVoiceTest($text,'PROD',$session),
    true,512,JSON_THROW_ON_ERROR);
$first=$ask('Sind die Fenster im Erdgeschoss geschlossen?');
$again=$ask('Sind die Fenster im EG geschlossen?');
$query=$first['readQueries'][0]['result'];
$coverage=$query['closureCoverage'];
ck38($first['matchMethod']==='local_house_query'&&$again['answer']===$first['answer']
    && $first['questionScope']['rooms']===$again['questionScope']['rooms']
    && $first['questionScope']['rooms']===['wohnzimmer','buero'],
    'EG and Erdgeschoss deterministically select every published ground-floor room including Büro');
ck38($query['matchedDevices']===6&&$query['withProperty']===3&&$coverage['open']===0
    && $coverage['closed']===3&&$coverage['unknown']===3
    && str_contains($first['answer'],'Nicht sicher feststellbar'),
    'three known and three unknown, including conflicting states, missing variable and unrecognized code');
ck38(in_array('Bürofenster',array_column($query['rows'],'name'),true)
    && in_array('Bürofenster ohne Rückmeldung',array_column($coverage['unknownDevices'],'name'),true)
    && !in_array(826,$GLOBALS['preview38Reads'],true),
    'known and unknown Büro contacts are both represented, private or test contacts are not read');

$GLOBALS['preview38Values'][821]=19;
$changed=$ask('Sind die Fenster im EG geschlossen?');
ck38(str_contains($changed['answer'],'Nein.')&&$changed['readQueries'][0]['result']['closureCoverage']['open']===1,
    'a known open contact yields no even when other contacts are unknown');
ck38(str_contains($ask('Welche Fenster sind im Erdgeschoss offen?')['answer'],'Bürofenster'),
    'list question uses the same verified contact state');
$follow=$ask('Und im Obergeschoss?');
ck38($follow['questionScope']['rooms']===['oben']
    && $follow['readQueries'][0]['result']['closureCoverage']['closed']===1,
    'follow-up replaces the original EG scope and does not mix the second floor');
ck38($ask('Sind die Fenster im zweiten Obergeschoss geschlossen?')['questionScope']['rooms']===['zweites']
    && $ask('Sind die Fenster im Untergeschoss geschlossen?')['questionScope']['rooms']===['keller']
    && $ask('Sind die Fenster im Dachgeschoss geschlossen?')['questionScope']['rooms']===['dach'],
    'other floor aliases are normalized through the same resolver');
ck38($ask('Welche Lichter sind im Erdgeschoss eingeschaltet?')['readQueries'][0]['result']['matchedReadings']===3,
    'the light path uses the same floor resolution, not a guessed room list');
ck38($ask('Sind die Fenster im Büro geschlossen?')['questionScope']['rooms']===['buero'],
    'an explicit room is not broadened to the whole floor');
ck38(IAHHouseQuestionContext::initialScope('Vergleiche im EG und OG die Temperaturen',$catalog)['type']==='multiple',
    'comparison retains both mentioned floors rather than silently dropping one');
ck38(IAHHouseQuestionContext::initialScope('Schalte das Licht im EG aus',$catalog)===null,
    'read-only question scoping does not intercept existing explicit commands');
ck38(str_contains($ask('Sind die Fenster im dritten Obergeschoss geschlossen?')['answer'],'nicht eindeutig'),
    'an unknown explicit floor is not silently widened');

$prepare=json_decode($module->PrepareAlexaHouseQuestion('live','echo','Sind die Fenster im EG geschlossen?',502),
    true,512,JSON_THROW_ON_ERROR);
$live=iah_rc_voice($prepare['question'],['catalog_script_id'=>502,'question_scope'=>$prepare['scope']]);
ck38($live['answer']===$changed['answer']&&$live['localHouseQuery'],
    'Alexa live bridge and the test form use identical answers with no API');
$devices=iah_rc_index_catalog($catalog);
$filtered=iah_ai_query_house($devices,['property'=>'open','type'=>'window','rooms'=>['Büro']]);
ck38($filtered['matchedDevices']===2&&$filtered['closureCoverage']['open']===1,
    'query reports count after rooms filter and handles status-only contacts');
$floors=iah_rc_find($devices,['floor'=>'Erdgeschoss','type'=>'window']);
ck38(count($floors)===5&&iah_rc_find($devices,[
    'deviceKey'=>array_key_first($floors),'floor'=>'OG'
])===[],'shared floor filters match exactly, even with a selected device key');

$phase=0;
$model=static function(array $payload)use(&$phase):array{
    if($phase++===0)$message=['tool_calls'=>[['id'=>'q','function'=>['name'=>'query_house',
        'arguments'=>'{"property":"activity","domain":"licht","rooms":["Wohnzimmer"]}']]]];
    else{
        $msg=end($payload['messages']);
        $response=json_decode($msg['content'],true,512,JSON_THROW_ON_ERROR);
        ck38($response['matchedDevices']===3&&$response['statusCoverage']['on']===3,
            'AI cannot replace explicit floor scope with an incomplete list of rooms');
        $message=['content'=>'Zwei Lichter sind im Erdgeschoss an.'];
    }
    return ['choices'=>[['message'=>$message]]];
};
$result=iah_rc_voice('Wie ist die Beleuchtung im Erdgeschoss?',['api_key'=>'fake'],$catalog,$model);
ck38($result['handled']&&$phase===2,'non-window AI queries also use the scoped published inventory');

$template=dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
$generated=(new ReflectionMethod($module,'ensureScriptTemplate'))->invoke(
    $module,200,'KIHouseController','kiHouseControllerScript',$template);
$generatedSource=IPS_GetScriptContent($generated);
ck38(str_contains($generatedSource,'final class IAHHouseQuestionContext')
    &&!str_contains($generatedSource,'// IAH_HOUSE_QUESTION_CONTEXT_INSERT'),
    'generated live helper embeds the location resolver');
token_get_all($generatedSource,TOKEN_PARSE);
ck38($source===IPS_GetScriptContent(502)&&$GLOBALS['preview38Actions']===[],
    'production settings and hardware are unchanged');
echo "PASS V3.8.3: shared floor aliases, stable windows and scoped diagnostics\n";
