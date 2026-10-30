<?php
declare(strict_types=1);

require dirname(__DIR__).'/IPSAlexaHaussteuerung/src/KICostAccounting.php';
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/HomeAgentVoiceBridge.php';
function checkModel(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}

foreach (['gpt-6-luna','gpt-4.1-mini'] as $model) {
    foreach ([0,1] as $round) {
        $messages=[['role'=>'user','content'=>'Frage']];
        $tools=[['type'=>'function','function'=>['name'=>'get_states']]];
        foreach ([
            'RoomsCatalog'=>iah_rc_chat_payload($model,$messages,$tools,$round),
            'HomeAgent'=>iah_homeagent_chat_payload($model,$messages,$tools,$round),
        ] as $backend=>$body) {
            checkModel($body['model']===$model,$backend.': selected model');
            checkModel($body['tool_choice']===($round===0?'required':'auto'),$backend.': tool choice');
            checkModel($body['max_completion_tokens']===200,$backend.': tokens');
            if ($model==='gpt-6-luna') {
                checkModel(($body['reasoning_effort']??null)==='none',$backend.': Luna must not reason in Chat Completions tool call');
            } else {
                checkModel(!array_key_exists('reasoning_effort',$body),$backend.': legacy request must be unchanged');
            }
        }
    }
}
$prices=iah_ki_price_table();
checkModel($prices['gpt-6-luna']===['input'=>0.10,'cached'=>0.01,'output'=>0.50],'Luna price');
checkModel($prices['gpt-4.1-mini']===['input'=>0.40,'cached'=>0.10,'output'=>1.60],'4.1 mini price');
$usage=['usage'=>['prompt_tokens'=>10000,'completion_tokens'=>1000,'prompt_tokens_details'=>['cached_tokens'=>2000]]];
foreach (['gpt-6-luna'=>0.00128,'gpt-4.1-mini'=>0.0054] as $model=>$expect) {
    $price=iah_ki_usage_row(array_merge($usage,['model'=>$model]),$prices);
    // 8000 * input + 2000 * cached + 1000 * output (USD per million)
    $rates=$prices[$model];
    $expected=(8000*$rates['input']+2000*$rates['cached']+1000*$rates['output'])/1000000;
    checkModel($price['priced'] && abs($price['costUsd']-$expected)<1e-12,$model.' token pricing');
}
$form=json_decode(file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/form.json'),true,512,JSON_THROW_ON_ERROR);
$fields=[];
foreach($form['elements'] as $e) foreach(($e['items']??[]) as $f) if(isset($f['name']))$fields[$f['name']]=$f;
checkModel(($fields['KIModelSelection']['type']??'')==='Select','model selector');
checkModel(($fields['KIModelSelection']['value']??'')==='gpt-6-luna','new model default');
checkModel(array_column($fields['KIModelSelection']['options'],'value')===['gpt-6-luna','gpt-4.1-mini'],'available model choices');
$module=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/module.php');
checkModel(str_contains($module,"RegisterPropertyString('KIModelSelection', 'gpt-6-luna')"),'existing instance auto-default');
$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
checkModel(substr_count($action,"'KIModelSelection'")>=3,'both backends and parser selection');
$parser=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIIntentParser.php');
checkModel(str_contains($parser,"\$body['reasoning_effort'] = 'none'"),'legacy parser Luna reasoning none');
echo "PASS: model selector, Luna-compatible Chat Completions tool calls, legacy GPT-4.1 mini, prices and parser\n";
