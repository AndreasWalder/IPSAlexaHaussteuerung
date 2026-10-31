<?php
declare(strict_types=1);

// Behavior tests run the REAL template helper, with a deterministic
// fake of the SDK's array-backed JsonSerializable response model.
$src=(string)file_get_contents(
    __DIR__.'/../IPSAlexaHaussteuerung/resources/action_entry.php'
);
$start='// IAH_TILE_QUIET_HELPER_BEGIN';
$end='// IAH_TILE_QUIET_HELPER_END';
$begin=strpos($src,$start);
$finish=$begin===false?false:strpos($src,$end,$begin);
if($begin===false || $finish===false)throw new RuntimeException('Missing quiet response helper');
eval(substr($src,$begin,$finish+strlen($end)-$begin));

$logs=[];
function IPS_LogMessage(string $source,string $message): void {
    global $logs;
    $logs[]=$source.' '.$message;
}

class TestAlexaSdkResponse implements JsonSerializable {
    public function __construct(private array $data) {}
    public function jsonSerialize(): array{return $this->data;}
}

function assertQuiet(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
function body(object $response): array {
    return json_decode(json_encode($response,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR)['response'];
}
function makeHtml(string $type='Alexa.Presentation.HTML.Start',bool $end=false): TestAlexaSdkResponse {
    return new TestAlexaSdkResponse([
        'version'=>'1.0','response'=>[
            'outputSpeech'=>['type'=>'PlainText','text'=>''],
            'reprompt'=>['outputSpeech'=>['type'=>'PlainText','text'=>'']],
            'shouldEndSession'=>$end,
            'directives'=>[[
                'type'=>$type,'request'=>['method'=>'GET','uri'=>'https://example.org/tile']
            ]]
        ]
    ]);
}

$a=makeHtml();
assertQuiet(iah_tile_quiet_html_response($a,false)===$a,
    'Opt-in disabled: same SDK object returned');
assertQuiet(array_key_exists('shouldEndSession',body($a)),
    'Disabled mode must not remove session flag');

$b=makeHtml();
assertQuiet(iah_tile_quiet_html_response($b,true)===$b,
    'Opt-in enabled: same SDK response object returned');
assertQuiet(!array_key_exists('shouldEndSession',body($b)),
    'The microphone-opening false field must be OMITTED, not null');
assertQuiet(!array_key_exists('reprompt',body($b)),
    'Reprompt must also be omitted to prevent unsolicited follow-up');
assertQuiet(body($b)['directives'][0]['type']==='Alexa.Presentation.HTML.Start',
    'HTML.Start directive retained');
assertQuiet(body($b)['outputSpeech']['type']==='PlainText',
    'Other response fields retained');

$c=makeHtml('Alexa.Presentation.HTML.HandleMessage');
iah_tile_quiet_html_response($c,true);
assertQuiet(!array_key_exists('shouldEndSession',body($c)),
    'HTML.HandleMessage can remain quiet on re-entry');

$d=new TestAlexaSdkResponse(['response'=>[
    'shouldEndSession'=>false,'directives'=>[['type'=>'Alexa.Presentation.APL.RenderDocument']]
]]);
iah_tile_quiet_html_response($d,true);
assertQuiet(array_key_exists('shouldEndSession',body($d)),
    'Existing APL routes never modified');

$e=makeHtml('Alexa.Presentation.HTML.Start',true);
iah_tile_quiet_html_response($e,true);
assertQuiet(body($e)['shouldEndSession']===true,
    'Any genuine end-session response remains unchanged');

class FixedSdkResponse implements JsonSerializable {
    public function jsonSerialize(): array {
        return ['response'=>[
            'shouldEndSession'=>false,
            'directives'=>[['type'=>'Alexa.Presentation.HTML.Start']]
        ]];
    }
}
$f=new FixedSdkResponse();
iah_tile_quiet_html_response($f,true);
assertQuiet(body($f)['shouldEndSession']===false,
    'Unsupported SDK layout safely retains the original response');

assertQuiet(in_array('Alexa TileQuiet.ready field_omitted',$logs,true),
    'Successful removal gets a safe diagnostic code');
assertQuiet(in_array('Alexa TileQuiet.unsupported sdk_layout',$logs,true),
    'Unsupported SDK layout gets a safe diagnostic code');
assertQuiet(!array_filter($logs,static fn(string $line):bool=>
    str_contains($line,'https://') || str_contains($line,'password')
), 'No URL or passwords in diagnostic logs');

$mod=(string)file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/module.php');
$action=(string)file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/resources/action_entry.php');
$form=json_decode((string)file_get_contents(__DIR__.'/../IPSAlexaHaussteuerung/form.json'),true);
assertQuiet(str_contains($mod,"RegisterPropertyBoolean('TileQuietNoFollowup', false)"),
    'Quiet mode must default to OFF');
assertQuiet(str_contains($mod,"patchActionScriptTileQuietFollowup((int) $id)"),
    'Action script migration must run on ApplyChanges');
assertQuiet(str_contains($mod,"IAH_TILE_QUIET_HELPER_BEGIN"),
    'Installer must migrate helper into customized Action scripts');
assertQuiet(str_contains($action,"(string)\$__route === 'external'"),
    'Only externally rendered HTML route gets quiet start');
assertQuiet(str_contains($action,"'TileQuietNoFollowup' ?? false"),
    'Existing APL responses must keep standard session behavior');
$found=false;
foreach($form['elements']??[] as $panel)foreach($panel['items']??[] as $field){
    if(($field['name']??'')==='TileQuietNoFollowup' &&
       ($field['type']??'')==='CheckBox')$found=true;
}
assertQuiet($found,'Quiet opt-in must appear in module form');

echo "PASS: Alexa Tile quiet HTML response omits shouldEndSession without changing SDK type, APL routes or credentials\n";
