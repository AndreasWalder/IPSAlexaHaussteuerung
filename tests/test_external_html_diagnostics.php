<?php
declare(strict_types=1);
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/ExternalHtmlProbe.php';
function hd(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
$valid=iah_html_diag_uri('https://example.org/hook/icons?f=alexa-shell.html&token=MYSECRET');
hd($valid['https']&&$valid['hasQuery']&&$valid['valid'],'HTTPS shell parsed');
hd(!array_key_exists('host',$valid)&&!array_key_exists('query',$valid),'Sensitive URL components not disclosed');
hd(!iah_html_diag_uri('http://example.org/')['https'],'HTTP recognized as not Alexa-compatible');
$raw=tempnam(sys_get_temp_dir(),'iah-html-');
if($raw===false)throw new RuntimeException('temp unavailable');
file_put_contents($raw,'<!doctype html><html><head></head><body><iframe src="http://local"></iframe></body></html>');
try{
    $fake=static function(string $uri):array{
        $blocked=str_contains($uri,'target');
        return [
            'attempted'=>true,'status'=>$blocked?403:200,'curlCode'=>0,
            'contentType'=>$blocked?'text/html':'text/html',
            'xFrameOptions'=>$blocked?'SAMEORIGIN':'absent',
            'cspFrameAncestors'=>$blocked?'self_or_mixed':'absent',
            'cspFrameSrc'=>'absent','uri'=>iah_html_diag_uri($uri),
            'looksHtml'=>true
        ];
    };
    $data=iah_html_diag_analyse([
        'externalKey'=>'kamera','pageType'=>'wfc_item','instanceId'=>123,
        'baseUrl'=>'https://example.org?password=SECRET1',
        'shellUrl'=>'https://example.org/hook/icons?f=alexa-shell.html&token=SECRET2',
        'targetUrl'=>'https://webfront.example.net/target?password=SECRET3',
        'localShell'=>$raw,'aplSupported'=>true,'htmlSupported'=>false
    ],$fake);
    hd($data['localShellPresent'] && $data['localShellHasIframe'],'Local HTML shell scanned');
    hd($data['shellAndTargetSameOrigin']===false,'Cross-origin embed recognized without revealing host');
    hd(in_array('device_reports_no_html_support',$data['warnings'],true),'HTML support distinct from APL');
    hd(in_array('target_unauthorized',$data['warnings'],true),'HTTP 403 recognized');
    hd(in_array('target_iframe_restricted_by_xframe',$data['warnings'],true),'X-Frame-Options recognized');
    hd(in_array('target_iframe_possible_csp_frame_ancestors_block',$data['warnings'],true),'CSP recognized');
    hd($data['clientSideObserved']===false,'Do not claim Alexa browser was tested');
    $saved=json_encode($data,JSON_THROW_ON_ERROR);
    foreach(['SECRET1','SECRET2','SECRET3','example.org','password=','token='] as $secret)
        hd(!str_contains($saved,$secret),'Sensitive URL must not be logged: '.$secret);
    $noFile=iah_html_diag_analyse([
        'externalKey'=>'energie','localShell'=>'/path/does/not/exist.html',
        'baseUrl'=>'http://192.168.0.1/','shellUrl'=>'http://192.168.0.1/shell',
        'targetUrl'=>'http://192.168.0.1/target'
    ],static function(string $url):array{return [
        'attempted'=>false,'status'=>0,'curlCode'=>0,
        'xFrameOptions'=>'absent','cspFrameAncestors'=>'absent','contentType'=>'missing'
    ];});
    hd(in_array('shell_requires_https',$noFile['warnings'],true),'HTTP shell flagged');
    hd(in_array('local_shell_file_not_found_at_user_icons',$noFile['warnings'],true),'Missing shell flagged');
    hd(iah_html_diag_same_origin('https://example.org/shell?a=secret',
        'https://example.org/frame?password=secret'),'Same origin ignores query');
    hd(!iah_html_diag_same_origin('https://example.org/shell',
        'https://other.example.org/frame'),'Different hosts are cross-origin');
    hd(!iah_html_diag_same_origin('https://example.org/shell',
        'http://example.org/frame'),'HTTP and HTTPS must differ');
    hd(iah_html_diag_analyse(['externalKey'=>'licht'])['error']==='invalid_external_key',
        'HTML probe is only for supported diagnostic routes');
    $tile=iah_html_diag_analyse([
        'externalKey'=>'kacheltest', 'pageType'=>'external_url',
        'baseUrl'=>'https://example.org', 'shellUrl'=>'https://example.org/diagnose',
        'targetUrl'=>'https://example.org/tile/',
        'localShell'=>$raw
    ], $fake);
    hd(($tile['key']??null)==='kacheltest', 'Server probe accepts Kacheltest');
    hd($tile['shellAndTargetSameOrigin']===true, 'Tile iframe same-origin shell');
} finally { @unlink($raw); }

$renderer=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/renderers/Route_allRenderer.php');
$module=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/module.php');
$entry=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
hd(str_contains($renderer,"ExternalHTML.probeQueued"),'Asynchronous renderer dispatch logged');
hd(str_contains($renderer,"IPS_RunScriptEx("),'Nonblocking probe (no Alexa-response latency)');
hd(str_contains($renderer,"ExternalHTML.directive"),'HTML.Start directive logged');
hd(str_contains($module,"'externalHtmlProbeScript'"),'Helper installed');
hd(str_contains($module,"'iahHtmlHistory'"),'Copyable HTML diagnostic history installed');
hd(str_contains($module,'patchActionScriptExternalHtmlMetadata((int) $id)'),'Legacy Action migrated');
hd(str_contains($entry,"'htmlSupported'   =>"),'HTML capability passed');
hd(str_contains($entry,"ExternalHTML.clientEvent"),'Sanitized HTML.Message telemetry');
hd(str_contains($renderer,"alexa-diagnostic-shell.html"),'Kamera/Energie diagnostic shell selected');
hd(str_contains($renderer,"/hook/iah-alexa-shell?"),'Isolated shell webhook used by renderer');
hd(str_contains($renderer,"'route' => " . '$externalKey'),'Route identity forwarded to shell');
hd(str_contains($module,"ensureExternalHtmlBrowserDiagnostics"),'Browser diagnostics auto-installed');
hd(str_contains($module,"'iahHtmlBrowserHistory'"),'Copyable browser event history created');
hd(str_contains($module,"/hook/iah-alexa-html-debug"),'Browser callback webhook configured automatically');
hd(str_contains($module,"/hook/iah-alexa-shell"),'Missing icons webhook configured automatically');
hd(str_contains($module,"hook_exists_external"),'Existing third-party hooks must not be overwritten');
$shell=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/html/alexa-diagnostic-shell.html');
$receiver=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/ExternalHtmlBrowserReceiver.php');
hd(str_contains($shell,"shell_boot") && str_contains($shell,"iframe_timeout"),'Browser bootstrap and timeout telemetry');
hd(str_contains($shell,"'/hook/iah-alexa-html-debug'"),'Client reports to local WebHook');
hd(str_contains($receiver,"hash_equals("),'Browser callback authenticates token');
$icons=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/WebHookIcons.php');
hd(str_contains($icons,"ExternalHTML.shellDelivery"),'Shell request delivery is logged without URLs');
hd(str_contains($receiver,'array_slice($rows, -100)'),'Browser event history bounded to 100');
hd(!str_contains($receiver, '$input[' . chr(39) . 'src' . chr(39) . ']'), 'Browser diagnostic cannot store iframe URL');
echo "PASS: asynchronous HTML probes, HTTP/TLS/frame policy flags, missing shell, safe logs, Action migration\n";
