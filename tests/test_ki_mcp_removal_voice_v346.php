<?php
declare(strict_types=1);

/** V3.4.6: Sole RoomsCatalog KI backend and safe data-driven voice reads. */
require dirname(__DIR__) . '/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
function chk346(bool $ok,string $label):void {
    if (!$ok) throw new RuntimeException($label);
}
function IPS_VariableExists(int $id):bool { return array_key_exists($id, $GLOBALS['ips346Values']); }
function IPS_GetVariable(int $id):array {
    return [
        'VariableType'=>$GLOBALS['ips346Types'][$id],
        'VariableChanged'=>time()-60,
    ];
}
function GetValue(int $id):mixed { return $GLOBALS['ips346Values'][$id]; }
function IPS_LogMessage(string $channel,string $message):void {}

$GLOBALS['ips346Values']=[
    101=>1, 102=>0, 103=>'EIN', 104=>47.0, 105=>73,
    201=>40.0,202=>20.0,203=>99.0
];
$GLOBALS['ips346Types']=[
    101=>1,102=>1,103=>3,104=>2,105=>1,
    201=>2,202=>2,203=>2,
];
$make=static function(string $id,string $type,array $reads,array $mapping=[]):array {
    return [
        'id'=>$id, 'name'=>$id,'room'=>'Wohnzimmer','roomKey'=>'wohnzimmer',
        'floor'=>'EG','domain'=>$type==='blind'?'jalousie':'licht',
        'group'=>$type==='blind'?'jalousie':'switches',
        'roomAliases'=>[],'synonyms'=>[],'read'=>reset($reads),
        'write'=>0,'writable'=>false,'typed'=>true,'type'=>$type,
        'reads'=>$reads,'writeRoles'=>[],'stateMapping'=>$mapping,
        'setpoint'=>0,'min'=>0,'max'=>100
    ];
};
$devices=[
    'l1'=>$make('l1','light',['status'=>101]),
    'l2'=>$make('l2','light',['status'=>102]),
    'l3'=>$make('l3','light',['status'=>103]),
    'd1'=>$make('d1','dimmer',['brightness'=>104]),
    'l4'=>$make('l4','light',['status'=>105]),
    'b1'=>$make('b1','blind',['position'=>201]),
    'b2'=>$make('b2','blind',['position'=>202],['open'=>20,'closed'=>100]),
    'b3'=>$make('b3','blind',['position'=>203]),
];

// A valid 0/1 or text status is a readable feedback, never an OpenAI error.
$active=iah_ai_query_house($devices,[
    'property'=>'activity','operation'=>'list','group_by'=>'device',
    'domain'=>'licht','condition'=>'active'
], 'Welche Lampen sind eingeschaltet?');
chk346(($active['matchedReadings']??0)===3, 'numeric/text light and dimmer feedback should be active');
$inactive=iah_ai_query_house($devices,[
    'property'=>'activity','operation'=>'count','group_by'=>'none',
    'domain'=>'licht','condition'=>'inactive'
], 'Wie viele Lampen sind aus?');
chk346(($inactive['totalCount']??0)===1, 'one confirmed inactive light');
$unknown=iah_ai_query_house($devices,[
    'property'=>'activity','operation'=>'count','group_by'=>'none',
    'name'=>'l4','condition'=>'active'
], 'Ist Lampe l4 eingeschaltet?');
chk346(($unknown['matchedReadings']??-1)===0 && ($unknown['withProperty']??-1)===0,
    'arbitrary numeric status must not be guessed as on');
$unknownSpeech=iah_ai_safe_voice_answer($unknown);
chk346(str_contains((string)$unknownSpeech, 'keine lesbaren Istwerte'),
    'zero reliable readings must not be interpreted as no light active');
chk346(iah_ai_property_value(['type'=>'fan','properties'=>['commandValue'=>true]],
    'activity',['type'=>'fan'])===null, 'control values must not be read as confirmed states');

// Never reinterpret positional 0-100 data as open/closed without mapping.
$closure=iah_ai_query_house($devices,[
    'property'=>'closure','type'=>'blind','operation'=>'list',
    'condition'=>'eq','value'=>'offen'
], 'Welche Jalousien sind offen?');
chk346(($closure['matchedReadings']??0)===1, 'only explicitly mapped blind is known open');
chk346(($closure['withProperty']??0)===1,'two blinds must remain unknown');
$noMapping=iah_ai_closure_feedback(iah_rc_state($devices['b1']));
chk346($noMapping===null, 'blind position alone is not a closure state');

// Safe response when model's third attempt would exceed 6 s Alexa budget.
$timeoutSpeech=iah_ai_safe_voice_answer($active);
chk346(str_contains((string)$timeoutSpeech, '3 passende Istwerte'),
    'after valid read, answer instead of HTTP-0 timeout');
chk346(iah_ai_safe_voice_answer(['ok'=>false,'error'=>'bad'])===null,
    'invalid tools cannot cause fabricated answers');

// Source-level regression checks ensure only direct RoomsCatalog/ChatGPT remains.
$root=dirname(__DIR__);
$module=file_get_contents($root.'/IPSAlexaHaussteuerung/module.php');
$form=json_decode(file_get_contents($root.'/IPSAlexaHaussteuerung/form.json'),true,512,JSON_THROW_ON_ERROR);
$action=file_get_contents($root.'/IPSAlexaHaussteuerung/resources/action_entry.php');
$controller=file_get_contents($root.'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php');
$ui=json_encode($form);
foreach (['HomeAgentVoiceEndpoint','HomeAgentVoiceToken','KIVoiceMode','HomeAgentVoiceBridge'] as $legacy) {
    chk346(!str_contains($module,"RegisterPropertyString('".$legacy."'"),
        'obsolete MCP config remains '.$legacy);
    chk346(!str_contains($ui,'"name":"'.$legacy.'"'), 'obsolete MCP UI remains '.$legacy);
}
chk346(!is_file($root.'/IPSAlexaHaussteuerung/resources/helpers/HomeAgentVoiceBridge.php'),
    'obsolete MCP PHP helper still exists');
chk346(!str_contains($action,'homeAgentVoiceBridgeScript')
    && !str_contains($action,'HomeAgentVoiceEndpoint')
    && !str_contains($action,'KIVoiceMode'),
    'Alexa action must not route to HomeAgent');
chk346(str_contains($action, "'mode'=>'RoomsCatalog'")
    && str_contains($action, "'kiCatalogText' => "),
    'direct ChatGPT RoomsCatalog route must remain');
chk346(str_contains($action, "KIVoice.bridge.decode")
    && str_contains($action, 'jsonError'),
    'missing diagnostic for successful KI but unreadable IPC return');
chk346(str_contains($controller,'iah_ai_safe_voice_answer(')
    && str_contains($controller, "'answer.fallback'"),
    'Read results must survive OpenAI status 0 after timeout');
$pref=strpos($controller,'$preferRead=(bool)');
$permission=strpos($controller,'if($allowWrites && !$preferRead)');
chk346($pref!==false && $permission!==false && $pref<$permission,
    'read-only question guard must be initialized before write-tool exposure');
chk346(!str_contains($ui,'Home Agent MCP-Endpunkt')
    && !str_contains($ui,'Home Agent Zugangstoken'),
    'obsolete MCP form settings remain');
echo "PASS V3.4.6: HomeAgent MCP removed, light states + blind closure fixed, safe model timeout fallback\n";
