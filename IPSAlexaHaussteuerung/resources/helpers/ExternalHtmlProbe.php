<?php
declare(strict_types=1);

/**
 * Diagnose Kamera/Energie (Alexa Web API for Games).
 * Wird asynchron per IPS_RunScriptEx aus Route_allRenderer aufgerufen.
 * Niemals komplette URI/Passwort/Token, HTTP-Bodies oder Headerwerte loggen.
 * Browser-/Iframe-Ereignisse auf dem Echo kann dieser SERVER-Check nicht sehen.
 */
function iah_html_diag_uri(string $uri): array
{
    $p=@parse_url($uri);
    if(!is_array($p))return ['valid'=>false,'https'=>false,'hasHost'=>false];
    $scheme=strtolower((string)($p['scheme']??''));
    $host=(string)($p['host']??'');
    return [
        'valid'=>in_array($scheme,['https','http'],true)&&$host!=='',
        'https'=>$scheme==='https',
        'hasHost'=>$host!=='',
        'hostIsIp'=>filter_var($host,FILTER_VALIDATE_IP)!==false,
        'hasQuery'=>isset($p['query'])&&$p['query']!=='',
        'hasFragment'=>isset($p['fragment'])&&$p['fragment']!=='',
    ];
}
/** Nur Vergleich; Host-/Pfadwerte niemals protokollieren. */
function iah_html_diag_same_origin(string $a,string $b): bool
{
    $x=@parse_url($a);
    $y=@parse_url($b);
    if(!is_array($x)||!is_array($y))return false;
    $sx=strtolower((string)($x['scheme']??''));
    $sy=strtolower((string)($y['scheme']??''));
    if($sx!==$sy||$sx==='')return false;
    $hx=strtolower((string)($x['host']??''));
    $hy=strtolower((string)($y['host']??''));
    if($hx===''||$hx!==$hy)return false;
    $portDefault=$sx==='https'?443:80;
    return (int)($x['port']??$portDefault)===(int)($y['port']??$portDefault);
}
function iah_html_diag_http(string $url): array
{
    $uri=iah_html_diag_uri($url);
    $result=['uri'=>$uri,'attempted'=>false,'status'=>0,'contentType'=>'',
        'curlCode'=>0,'timeMs'=>0,'bytesRead'=>0,'redirect'=>false,'tlsVerified'=>false,
        'xFrameOptions'=>'absent','cspFrameAncestors'=>'absent','cspFrameSrc'=>'absent',
        'looksHtml'=>false,'limitedRead'=>false];
    if(!$uri['valid']){$result['reason']='invalid_uri';return $result;}
    if(!$uri['https']){$result['reason']='not_https';return $result;}
    if(!function_exists('curl_init')){$result['reason']='curl_unavailable';return $result;}
    $ch=curl_init($url);
    if($ch===false){$result['reason']='curl_init_failed';return $result;}
    $headers=[];$body='';$max=16384;
    $start=microtime(true);
    try{
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>2,
            CURLOPT_TIMEOUT=>5,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'IP-Symcon Alexa HTML Diagnostics/1.0',
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers):int{
                $parts=explode(':',$line,2);
                if(count($parts)===2){
                    $key=strtolower(trim($parts[0]));
                    if(in_array($key,['content-type','location','x-frame-options','content-security-policy'],true)){
                        $headers[$key][]=trim($parts[1]);
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,$max):int{
                $len=strlen($chunk);
                $left=$max-strlen($body);
                if($left>0)$body.=substr($chunk,0,$left);
                // Alles lesen lassen, aber nur 16 KiB im Speicher behalten.
                return $len;
            }
        ]);
        $result['attempted']=true;
        $ok=curl_exec($ch);
        $result['status']=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $result['curlCode']=(int)curl_errno($ch);
        $result['timeMs']=(int)round((microtime(true)-$start)*1000);
        $result['tlsVerified']=$ok!==false && $result['curlCode']===0;
        $result['bytesRead']=strlen($body);
        $result['limitedRead']=strlen($body)===$max;
        $type=strtolower((string)($headers['content-type'][0]??''));
        $result['contentType']=str_contains($type,'text/html')?'text/html':
            (str_contains($type,'application/json')?'application/json':
            (str_contains($type,'text/plain')?'text/plain':
            ($type!==''?'other':'missing')));
        $result['redirect']=isset($headers['location']);
        $xframe=strtolower(trim((string)($headers['x-frame-options'][0]??'')));
        $result['xFrameOptions']=$xframe===''?'absent':
            (str_contains($xframe,'deny')?'DENY':
            (str_contains($xframe,'sameorigin')?'SAMEORIGIN':'present_other'));
        $csp=implode(' ',(array)($headers['content-security-policy']??[]));
        foreach(['frame-ancestors'=>'cspFrameAncestors','frame-src'=>'cspFrameSrc']as $directive=>$field){
            if(preg_match('/(?:^|;)\s*'.preg_quote($directive,'/').'\s+([^;]*)/i',$csp,$match)){
                $part=strtolower(trim($match[1]));
                $result[$field]=str_contains($part,"'none'")?'none':
                    (str_contains($part,"'self'")?'self_or_mixed':'restricted_or_listed');
            }
        }
        $prefix=ltrim(substr($body,0,512));
        $result['looksHtml']=str_contains(strtolower($prefix),'<!doctype html')||
            str_contains(strtolower($prefix),'<html')||str_contains(strtolower($prefix),'<head');
        $result['reason']=$result['status']>=200&&$result['status']<300&&$result['curlCode']===0
            ? 'http_ok':'http_or_tls_problem';
    }finally{curl_close($ch);}
    return $result;
}
function iah_html_diag_analyse(array $input,?callable $http=null):array
{
    $probe=$http??'iah_html_diag_http';
    $key=(string)($input['externalKey']??'');
    if(!in_array($key,['kamera','energie','kacheltest'],true))return ['error'=>'invalid_external_key'];
    $base=(string)($input['baseUrl']??'');
    $shell=(string)($input['shellUrl']??'');
    $target=(string)($input['targetUrl']??'');
    $local=(string)($input['localShell']??'');
    $localAvailable=$local!==''&&is_file($local);
    $localBytes=$localAvailable?(int)@filesize($local):0;
    $localContent=$localAvailable?(@file_get_contents($local,false,null,0,8192)?:''):'';
    $sameOrigin=iah_html_diag_same_origin($shell,$target);
    $doc=[
        'shellAndTargetSameOrigin'=>$sameOrigin,
        'time'=>date('c'),
        'key'=>$key,
        'pageType'=>in_array((string)($input['pageType']??''),['wfc_item','external_url'],true)
            ?(string)$input['pageType']:'other',
        'htmlSupported'=>$input['htmlSupported']??null,
        'aplSupported'=>(bool)($input['aplSupported']??false),
        'baseUri'=>iah_html_diag_uri($base),
        'shellUri'=>iah_html_diag_uri($shell),
        'targetUri'=>iah_html_diag_uri($target),
        'localShellPresent'=>$localAvailable,
        'localShellBytes'=>$localBytes,
        'localShellHasHtmlTag'=>stripos($localContent,'<html')!==false,
        'localShellHasIframe'=>stripos($localContent,'iframe')!==false,
        'localShellHasSrcParameter'=>stripos($localContent,'src')!==false,
        'shellHttp'=>$probe($shell),
        'targetHttp'=>$probe($target)
    ];
    $warnings=[];
    if($doc['htmlSupported']===false)$warnings[]='device_reports_no_html_support';
    if(!$doc['shellUri']['https'])$warnings[]='shell_requires_https';
    if(!$doc['targetUri']['https'])$warnings[]='target_requires_https_iframe';
    if(!$localAvailable)$warnings[]='local_shell_file_not_found_at_user_icons';
    foreach(['shellHttp'=>'shell','targetHttp'=>'target'] as $f=>$name){
        $p=$doc[$f];
        if(!$p['attempted'])$warnings[]=$name.'_probe_not_performed';
        elseif($p['curlCode']!==0)$warnings[]=$name.'_curl_error_'.$p['curlCode'];
        if($p['status']>=300 && $p['status']<400)$warnings[]=$name.'_redirect_or_login';
        if($p['status']===401||$p['status']===403)$warnings[]=$name.'_unauthorized';
        if($p['status']===404)$warnings[]=$name.'_not_found';
        if($p['status']>=500)$warnings[]=$name.'_server_error';
        if($name==='target'){
            if($p['xFrameOptions']==='DENY'
                || ($p['xFrameOptions']==='SAMEORIGIN' && !$sameOrigin))
                $warnings[]='target_iframe_restricted_by_xframe';
            if($p['cspFrameAncestors']==='none'
                || ($p['cspFrameAncestors']==='self_or_mixed' && !$sameOrigin))
                $warnings[]='target_iframe_possible_csp_frame_ancestors_block';
        }
        if($name==='shell' && $p['status']===200 && (int)($p['bytesRead']??0)===0)
            $warnings[]='shell_http_200_empty_body';
        if($name==='shell' && ($p['cspFrameSrc']==='none'
            || ($p['cspFrameSrc']==='self_or_mixed' && !$sameOrigin)))
            $warnings[]='shell_csp_frame_src_may_block_iframe';
        if($p['attempted'] && $p['status']===200 && $p['contentType']!=='text/html')
            $warnings[]=$name.'_not_text_html';
    }
    $doc['warnings']=array_values(array_unique($warnings));
    // Kein Erfolgsbeweis für den echten Echo-Browser: iframe onload,
    // Javascript-Konsole, Cookies und Cross-Origin CSP bleiben unbekannt.
    $doc['clientSideObserved']=false;
    return $doc;
}
function iah_html_diag_store(array $summary,int $instanceId):void
{
    $safe=json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES);
    IPS_LogMessage('Alexa','ExternalHTML.probe '.$safe);
    if($instanceId<=0 || !function_exists('IPS_GetObjectIDByIdent'))return;
    $diag=(int)@IPS_GetObjectIDByIdent('iahDiag',$instanceId);
    $category=$diag>0?(int)@IPS_GetObjectIDByIdent('iahExternalHtmlDiag',$diag):0;
    if($category<=0)return;
    $last=(int)@IPS_GetObjectIDByIdent('iahHtmlLast',$category);
    $history=(int)@IPS_GetObjectIDByIdent('iahHtmlHistory',$category);
    if($last>0&&IPS_VariableExists($last))SetValueString($last,$safe);
    if($history<=0||!IPS_VariableExists($history))return;
    $lock='IAH.ExternalHtmlDiag.'.$instanceId;
    if(!IPS_SemaphoreEnter($lock,500))return;
    try{
        $rows=explode("\n",trim((string)GetValueString($history)));
        if(count($rows)===1 && $rows[0]==='')$rows=[];
        $rows[]=$safe;
        $rows=array_slice($rows,-20);
        SetValueString($history,implode("\n",$rows));
    }finally{IPS_SemaphoreLeave($lock);}
}
if(isset($_IPS['iahHtmlDiagRequest']) && is_string($_IPS['iahHtmlDiagRequest'])){
    try{
        $input=json_decode($_IPS['iahHtmlDiagRequest'],true,32,JSON_THROW_ON_ERROR);
        $result=iah_html_diag_analyse((array)$input);
        iah_html_diag_store($result,(int)($input['instanceId']??0));
    }catch(Throwable $e){
        IPS_LogMessage('Alexa','ExternalHTML.probeError '.get_class($e));
    }
}
