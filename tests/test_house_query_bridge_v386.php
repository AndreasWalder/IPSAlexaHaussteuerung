<?php
declare(strict_types=1);

require __DIR__ . '/test_floor_window_status_v383.php';

$catalog['wohnzimmer']['domains']['heizung']['raum'] = [
    'title'=>'Raumfühler','ist'=>830,'soll'=>832,
];
$catalog['wohnzimmer']['domains']['heizung']['doppelt'] = [
    'title'=>'Doppelte Zuordnung','ist'=>830,
];
$catalog['buero']['domains']['heizung']['raum'] = [
    'title'=>'Bürofühler','kiEnabled'=>true,
    'ai'=>['type'=>'heating','version'=>1,'variables'=>['temperature'=>831,'targetTemperature'=>832]],
];
$catalog['heizraum'] = ['display'=>'Heizraum','floor'=>'KG','domains'=>[
    'heizung'=>['sollwert'=>['title'=>'Nur Sollwert','soll'=>832]],
]];
foreach ([830=>21.4,831=>19.5,832=>35.0] as $id=>$value) {
    $GLOBALS['preview38Variables'][$id]=['VariableType'=>2];
    $GLOBALS['preview38Values'][$id]=$value;
}
IPS_SetScriptContent(502,IAHSystemNavigationEditor::encode($catalog));
$module->properties['KIIntentApiKey']='';
$scope=IAHHouseQuestionContext::initialScope('wie warm ist es wohnzimmer',$catalog);
ck38($scope['rooms']===['wohnzimmer']
    && IAHHouseQuestionContext::withoutLocation('wie warm ist es wohnzimmer',$catalog)==='wie warm ist es',
    'GetHaus combines the question with the bare Room slot, and follow-up strips the previous room');

$noNetwork=static function (): never { throw new LogicException('Should never call an external API'); };
$live=iah_rc_voice('wie warm ist es wohnzimmer',[
    'catalog_script_id'=>502,'api_key'=>'test-key',
],null,$noNetwork);
ck38($live['localHouseQuery'] && $live['answer']==='Wohnzimmer – 21,4 Grad.'
    && $live['contextEligible'], 'the exact production question is resolved without a network call');
$askTemp=static fn(string $question):array=>json_decode(
    $module->GetAlexaVoiceTest($question,'PROD',str_repeat('a',32)),true,512,JSON_THROW_ON_ERROR);
$first=$askTemp('Wie warm ist es im Wohnzimmer?');
ck38($first['answer']===$live['answer'] && $first['readQueries'][0]['result']['matchedReadings']===1,
    'standard question equals the live GetHaus answer, duplicate variable counted once');
$next=$askTemp('Und im Büro?');
ck38($next['answer']==='Büro – 19,5 Grad.' && $next['matchMethod']==='local_house_query',
    'room follow-up uses the same topic and reads the other sensor');
$missing=$askTemp('Wie warm ist es im Heizraum?');
ck38(str_contains($missing['answer'],'Kein Temperaturwert verfügbar')
    && !str_contains($missing['answer'],'35'), 'a heating setpoint alone is not an actual temperature');

ob_start();
$reply=iah_rc_bridge_reply(static function (): array {
    echo "PHP Warning: simulated diagnostic output\n";
    return ['handled'=>true,'answer'=>'21,4 Grad.'];
});
$noise=ob_get_clean();
ck38($noise==='' && json_decode($reply,true,512,JSON_THROW_ON_ERROR)['answer']==='21,4 Grad.',
    'captured script output cannot corrupt the JSON reply');
$failed=json_decode(iah_rc_bridge_reply(static function (): never {
    echo 'arbitrary unexpected output';
    throw new RuntimeException('internal details not sent to Alexa');
}),true,512,JSON_THROW_ON_ERROR);
ck38($failed===['handled'=>false,'error'=>'house_query_internal_error'],
    'throwable still returns clean structured failure');

$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
ck38(str_contains($action,"CreatePlainText('Nicht verstanden.')->SetRepromptPlainText('')")
    && str_contains($action,"\$kiAlexaKeepOpen('Dazu habe ich gerade keine Antwort.', 'Fehler')")
    && !str_contains($action,'Konnte kein Gerät verstehen') && !str_contains($action,'ist gerade nicht erreichbar'),
    'Alexa speaks short plain answers and does not repeat an extra reprompt');
$oldAction=str_replace("        return AskResponse::CreatePlainText('Nicht verstanden.')->SetRepromptPlainText('');\n"
    .'        // IAH_LAST_ALEXA_NO_MATCH_END',
    '        // IAH_LAST_ALEXA_NO_MATCH_END'."\n"
    ."        return AskResponse::CreatePlainText('Konnte kein Gerät verstehen. Wie bitte?')->SetRepromptPlainText('Wie bitte?');",
    $action);
$oldAction .= "\n// Custom Action retained\n";
$GLOBALS['sc333Objects'][9191]=['parent'=>200,'ident'=>'iahV386ActionFixture','name'=>'Action'];
IPS_SetScriptContent(9191,$oldAction);
(new ReflectionMethod($module,'patchActionScriptLastAlexaInteraction'))->invoke($module,9191);
ck38(IPS_GetScriptContent(9191)===$action."\n// Custom Action retained\n",
    'installed V3.8.5 Action keeps customization but removes the previous fallbacks');

echo "PASS V3.8.6: local temperature, scoped follow-up, clean bridge JSON and concise Alexa replies\n";
