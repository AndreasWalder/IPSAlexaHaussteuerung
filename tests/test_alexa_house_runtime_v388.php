<?php
declare(strict_types=1);

require __DIR__.'/test_house_question_retry_v387.php';

$raw=json_decode($module->QueryAlexaHouseReadOnly('welche fenster sind offen',502,'null'),true,512,JSON_THROW_ON_ERROR);
ck38(!empty($raw['handled']) && str_contains($raw['answer'],'offen')
    && $raw['answerSource']==='local','live read-only module endpoint resolves the reported unscoped window question');
ck38(json_decode($module->QueryAlexaHouseReadOnly('welche fenster sind offen',801,'null'),true)['error']
    ==='house_query_catalog_not_available','module read path cannot include an unrelated script');
$oldNoApi=$raw['answer'];

$calls=0;
$wording=static function (array $request) use (&$calls,$oldNoApi):array {
    $calls++;
    ck38(!isset($request['tools']),'read facts have no tool calls or writes on the wording request');
    $payload=json_decode($request['messages'][1]['content'],true,512,JSON_THROW_ON_ERROR);
    ck38($payload['frage']==='welche fenster sind offen' && $payload['istwerte']===$oldNoApi,
        'AI gets the original question and only the locally read answer');
    return ['choices'=>[['message'=>['content'=>'Die Fensterkontakte melden: '.$oldNoApi]]]];
};
$ai=iah_rc_voice('welche fenster sind offen',['prefer_ai'=>true,'api_key'=>'mock'],$catalog,$wording);
ck38($calls===1 && $ai['answerSource']==='ai_verified_feedback' && !empty($ai['contextEligible'])
    && !empty($ai['readQueries'][0]['result']['closureCoverage']),'AI wording retains facts and follow-up context');

$invalid=static fn():array=>['choices'=>[['message'=>['content'=>preg_replace(
    '/\b(\d+) offen, (\d+) geschlossen\b/u','$1 geschlossen, $2 offen',$oldNoApi)
]]]];
$fallback=iah_rc_voice('welche fenster sind offen',['prefer_ai'=>true,'api_key'=>'mock'],$catalog,$invalid);
ck38($fallback['answer']===$oldNoApi && $fallback['answerSource']==='local_fallback',
    'same numbers assigned to wrong states cannot replace measured facts');
$calls=0;
$fallback=iah_rc_voice('welche fenster sind offen',['prefer_ai'=>true,'api_key'=>'mock'],$catalog,$noAnswer);
ck38($calls===2 && $fallback['answer']===$oldNoApi && $fallback['answerSource']==='local_fallback',
    'a failed model retry still gives a real answer when sensor data exists');

$disabled=iah_rc_voice('welche fenster sind offen',['prefer_ai'=>false,'api_key'=>'mock'],$catalog,$noNetwork);
ck38($disabled['answerSource']==='local','AI wording can be disabled without turning off house queries');

$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
ck38(str_contains($action,'$iahUseModuleQuery = $iahReadOnlyQuery')
    && str_contains($action,'IAH_QueryAlexaHouseReadOnly('),
    'real Alexa sends queries to the module runtime');
$template=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php');
ck38(!str_contains($template,'use ($_IPS)'),'generated helper never tries to capture the Symcon auto-global');

class AskResponse
{
    public array $apl=[];
    public array $card=[];
    public string $reprompt='';

    public function __construct(public string $speech) {}
    public static function CreatePlainText(string $speech): self { return new self($speech); }
    public function SetRepromptPlainText(string $text): self { $this->reprompt=$text; return $this; }
    public function SetStandardCard(string ...$card): void { $this->card=$card; }
    public function AddAPLRenderDocument(array $doc,array $ds,string $token): void
    {
        $this->apl=['document'=>$doc,'datasources'=>$ds,'token'=>$token];
    }
}

preg_match('/\/\/ IAH_HOUSE_SUMMARY_BEGIN\R(.*?)\R *\/\/ IAH_HOUSE_SUMMARY_END/s',$action,$summaryBlock);
ck38(isset($summaryBlock[1]),'the house summary is part of the installed Action template');
$renderSummary=static function (string $source): Closure {
    $iahLastOutcome=static function (): void {};
    $applyApl=(require __DIR__.'/../IPSAlexaHaussteuerung/resources/helpers/CoreHelpers.php')['applyApl'];
    $baseUrl='https://example.org';
    $token='test';
    eval($source);
    return $kiAlexaKeepOpen;
};
$summary=$renderSummary($summaryBlock[1]);
$irrigation=$summary("Pumpe aus.\nFeuchtigkeit < 30 %.",'Beantwortet','Wie ist meine Bewässerung?',true);
$main=$irrigation->apl['document']['mainTemplate'];
$scroll=$main['items'][0]['items'][1];
ck38($main['parameters']===['summary'] && $main['items'][0]['items'][0]['items'][1]['text']==='${summary.title}'
    && $main['items'][0]['items'][0]['items'][0]['source']==='waterDrop'
    && $irrigation->apl['document']['graphics']['waterDrop']['type']==='AVG'
    && $scroll['type']==='ScrollView' && $scroll['grow']===1
    && $scroll['items'][0]['text']==='${summary.text}' && $scroll['items'][0]['lineHeight']==='125%'
    && $irrigation->apl['datasources']['summary']['text']==='Pumpe aus.<br>Feuchtigkeit &lt; 30 %.'
    && $irrigation->apl['datasources']['summary']['title']==='Bewässerung'
    && $irrigation->reprompt!=='','irrigation uses a bound, scrollable APL document and keeps the reprompt');
$irrigationRows=[['label'=>'Pumpe','value'=>'Aus'],['label'=>'Pumpenschacht unten','value'=>'7,6 °C']];
$irrigation=$summary('Pumpe aus.','Beantwortet','Wie ist meine Bewässerung?',true,'irrigation',$irrigationRows);
$list=$irrigation->apl['document']['mainTemplate']['items'][0]['items'][1]['items'][0];
$layout=$irrigation->apl['document']['mainTemplate']['items'][0]['bind'];
ck38($irrigation->apl['datasources']['summary']['readings']===$irrigationRows
    && $irrigation->apl['datasources']['summary']['rowCount']===2
    && str_contains($layout[0]['value'],'viewport.height') && str_contains($layout[0]['value'],'summary.rowCount')
    && $list['data']==='${summary.readings}' && $list['items'][0]['type']==='Frame'
    && $list['items'][0]['item']['items'][1]['text']==='${data.value}'
    && $list['items'][0]['item']['items'][1]['fontSize']==='${readingFont}'
    && $list['items'][0]['item']['items'][1]['textAlign']==='right',
    'irrigation scales the status rows and their two columns to the screen');
$readings=[['label'=>'PV-Leistung','value'=>'1.200 W']];
$pv=$summary('PV-Leistung 1.200 W.','Beantwortet','Wie geht es meiner PV-Anlage?',true,'energy_overview',$readings);
ck38($pv->apl['datasources']['summary']['title']==='PV-Anlage'
    && $pv->apl['datasources']['summary']['readings']===$readings
    && $pv->apl['document']['mainTemplate']['items'][0]['items'][1]['items'][0]['data']==='${summary.readings}',
    'PV values are bound to the visual rows');
ck38($summary('Antwort','Beantwortet','Wie geht es meiner PVAnlage?',true)->apl!==[],
    'PV compound spelling gets the visual response even for a backend answer without rows');
$withoutScreen=$summary('Antwort','Beantwortet','Wie geht es meiner PV Anlage?');
ck38($withoutScreen->apl===[] && $withoutScreen->card[0]==='PV-Anlage',
    'devices without APL keep the voice and standard app card');

$oldAction=str_replace($summaryBlock[0],"\$kiAlexaKeepOpen = static fn() => 'previous summary';",$action)
    ."\n// Custom Action retained\n";
IPS_SetScriptContent(9191,$oldAction);
(new ReflectionMethod($module,'patchActionScriptKIVoice'))->invoke($module,9191);
ck38(IPS_GetScriptContent(9191)===$action."\n// Custom Action retained\n",
    'the module update applies the summary to existing Action scripts');

echo "PASS V3.8.8: live module route, AI wording with fixed sensor facts and local fallback\n";
