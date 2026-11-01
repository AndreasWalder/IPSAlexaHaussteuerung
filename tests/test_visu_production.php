<?php
declare(strict_types=1);

$root=dirname(__DIR__).'/IPSAlexaHaussteuerung/';
$read=static fn(string $p): string=>(string)file_get_contents($root.$p);
$module=$read('module.php');
$action=$read('resources/action_entry.php');
$renderer=$read('resources/renderers/Route_allRenderer.php');
$router=$read('resources/helpers/VoiceIntentRouter.php');
$shell=$read('resources/html/alexa-diagnostic-shell.html');
$form=json_decode($read('form.json'),true,512,JSON_THROW_ON_ERROR);
function pv_assert(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException($message);
}
require_once $root.'resources/helpers/VoiceIntentRouter.php';
// Actual 01.11.2026 Echo logs: Alexa heard "wizo" or "wieso".
 // Only "wizo" is a safe, uncommon navigation alias. A real question
 // "wieso" must continue to the natural-language pipeline.
foreach(['Visu','Visualisierung','Wizo'] as $word){
    $result=iah_vir_classify([
        'intent'=>'GetHaus','legacySlots'=>['Action'=>$word],'text'=>$word
    ]);
    pv_assert(($result['route']??'')==='kacheltest'
        && ($result['category']??'')==='navigation',
        'Visu must navigate to the unchanged HTML internal route');
}
$wieso=iah_vir_classify([
    'intent'=>'GetHaus','legacySlots'=>['Action'=>'Wieso'],'text'=>'Wieso'
]);
pv_assert(($wieso['route']??'')!=='kacheltest',
    'Real question Wieso must never open the Visu');
pv_assert(str_contains($action,"'wizo'=>'kacheltest'")
    && !str_contains($action,"'wieso'=>'kacheltest'"),
    'Existing Action voice maps only the observed safe Wizo transcription');
pv_assert(str_contains($module,'$oldVisu')
    && str_contains($module,'$newVisu'),
    'Existing installed Action scripts must receive the Wizo fix on ApplyChanges');

$test=iah_vir_classify([
    'intent'=>'GetHaus','legacySlots'=>['Action'=>'Test'],'text'=>'Test'
]);
pv_assert(($test['route']??'')==='test','Test is an independent voice channel');
pv_assert(str_contains($action,"'visu'=>'kacheltest'")
    && str_contains($action,"'test'=>'test'")
    && str_contains($action,"\$iahLegacyNavKey === 'kacheltest'"),
    'Action must route Visu, reserve Test and reuse existing active HTML');
pv_assert(str_contains($module,"patchActionScriptVisuVoice((int) \$id)")
    && str_contains($module,"Visu.migration action_alias_active"),
    'Existing customized Alexa Action requires targeted Visu migration');
pv_assert(str_contains($renderer,"'Test.route reserved'")
    && str_contains($renderer,"'hv-test'")
    && !str_contains($renderer,"'target' => \$testTarget"),
    'Test must not start the product Visu');
pv_assert(str_contains($renderer,"if (\$route === 'kacheltest')")
    && str_contains($renderer,"'tileAutoLoginTicket'")
    && str_contains($renderer,"'tileBootstrapMode'"),
    'Product Visu must preserve the proven real URL bootstrap and signed login');
pv_assert(str_contains($renderer,"'title' => 'IP-Symcon Visu'"),
    'Product Visu must be named appropriately');
pv_assert(str_contains($module,"RegisterPropertyBoolean('TileTestStorageCompat', true)")
    && str_contains($module,"RegisterPropertyBoolean('TileAutoLoginEnabled', true)")
    && str_contains($module,"RegisterPropertyBoolean('TileQuietNoFollowup', true)"),
    'Proven compatibility, automatic login and quiet HTML are on by default');
pv_assert(str_contains($module,"RegisterPropertyBoolean('VisuDebugEnabled', false)"),
    'Product Visu debugging must default to OFF');

$panel=null;
foreach($form['elements']??[] as $item){
    if(($item['caption']??'')==='Alexa – Visu (produktive IP-Symcon-Kachelvisualisierung)'){
        $panel=$item;break;
    }
}
pv_assert(is_array($panel),'Product Visu settings are missing');
$names=[];
foreach($panel['items']??[] as $i){
    if(isset($i['name']))$names[]=$i['name'];
}
pv_assert($names===['TileTestVisualizationId','TileAutoLoginPassword','VisuDebugEnabled'],
    'Exactly three Visu settings allowed (instance, password, debug)');
pv_assert(str_contains($renderer,"'debug' =>")
    && str_contains($renderer,"'perf' =>")
    && str_contains($renderer,"IPS_GetProperty(\$tileTestInstanceId, 'VisuDebugEnabled')"),
    'Browser debug and performance must be controlled by product debug checkbox');
pv_assert(str_contains($shell,"if(route==='kacheltest' && !visuDebug)return;")
    && str_contains($shell,"if(route==='kacheltest' && visuDebug)")
    && str_contains($shell,"if(route==='kacheltest' && !visuDebug)return;"),
    'Browser debug logs and snapshots must be disabled in production');
pv_assert(str_contains($shell,"function presence(state)")
    && str_contains($shell,"presence('active')")
    && str_contains($shell,"function tileSendEnter"),
    'Device lifecycle and actual working Enter login must remain intact');

echo "PASS: Visu voice command, Test reserved, three-field product configuration, silent fast bootstrap and auto-login regression\n";
