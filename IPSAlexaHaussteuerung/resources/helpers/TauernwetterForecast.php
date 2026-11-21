<?php
declare(strict_types=1);

/** Read only the public, dated Sillian forecast; no partner API or model-generated facts. */
final class IAHTauernwetterForecast
{
    public const URL='https://tauernwetter.at/wetter/osttirol/sillian';

    public static function matches(string $text): bool
    {
        $q=self::norm($text);
        if (preg_match('/\b(?:gestern|vorgestern|letzte|letzten)\b/',$q)) return false;
        $future=preg_match('/\b(?:wird|werden|morgen|uebermorgen|naechste\w*|wettervorhersage|wetterprognose|vorhersage|prognose|aussicht\w*|montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag)\b/',$q);
        return $future&&preg_match('/\b(?:wetter\w*|regnet|regen|schneit|schnee\w*|tauernwetter|vorhersage|wetterprognose)\b/',$q)===1;
    }

    private static function norm(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9 ]+/u',' ',strtr(mb_strtolower($s),['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']))??'');
    }

    private static function zone(): DateTimeZone { return new DateTimeZone('Europe/Vienna'); }
    private static function today(int $now): DateTimeImmutable { return (new DateTimeImmutable('@'.$now))->setTimezone(self::zone())->setTime(0,0); }
    private static function date(string $s): ?DateTimeImmutable
    {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s,self::zone());
        return $d!==false&&$d->format('Y-m-d')===$s?$d:null;
    }

    private static function text(string $s): string
    {
        return trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($s),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
    }

    private static function field(string $html,string $class): string
    {
        return preg_match('~<span\b[^>]*class=["\'][^"\']*\b'.preg_quote($class,'~').'\b[^"\']*["\'][^>]*>(.*?)</span>~is',$html,$m)?self::text($m[1]):'';
    }

    private static function temperature(string $s): ?float
    {
        $s=str_replace('−','-',$s);
        return preg_match('/^(-?\d+(?:[.,]\d+)?)\s*°(?:C)?$/Du',$s,$m)?(float)str_replace(',','.',$m[1]):null;
    }

    private static function condition(string $s): ?string
    {
        $q=self::norm(self::text($s));
        $plain=['wolkenlos'=>'sonnig','sonnig'=>'sonnig','klar'=>'klar','heiter'=>'heiter',
            'teils bewoelkt'=>'teils bewölkt','teilweise bewoelkt'=>'teils bewölkt','wenig bewoelkt'=>'leicht bewölkt',
            'stark bewoelkt'=>'stark bewölkt','bewoelkt'=>'bewölkt','bedeckt'=>'bedeckt','nebel'=>'neblig','nebelig'=>'neblig','neblig'=>'neblig','hochnebel'=>'Hochnebel','windig'=>'windig',
            'sonne und leichter regen'=>'Sonne und leichter Regen','sonne und regen'=>'Sonne und Regen'];
        if (isset($plain[$q])) return $plain[$q];
        if (preg_match('/^(?:(?:leichter?|leichte|maessiger?|maessige|starker?|starke|kraeftiger?|kraeftige) )?(?:regen|regenschauer|schnee|schneefall|schneeschauer|schneeregen|gewitter|gewitterregen|gewitter mit regen)$/D',$q)) return self::text($s);
        return null;
    }

    /** Extract dated factual day cards, never regional prose or executable page scripts. */
    public static function parse(string $html,int $now): array
    {
        if (strlen($html)>2*1024*1024) throw new RuntimeException('forecast_size');
        $modified='';
        preg_match_all('~<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',$html,$scripts);
        foreach ($scripts[1] as $script) {
            $data=json_decode($script,true);
            if (!is_array($data)) continue;
            foreach (isset($data['@type'])?[$data]:$data as $node) {
                if (is_array($node)&&($node['@type']??'')==='WebPage'&&rtrim((string)($node['url']??''),'/')===self::URL) $modified=(string)($node['dateModified']??'');
            }
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/D',$modified)) throw new RuntimeException('forecast_date');
        try { $updated=(new DateTimeImmutable($modified))->getTimestamp(); }
        catch (Throwable $ignored) { throw new RuntimeException('forecast_date'); }
        if ($updated>$now+15*60||$now-$updated>24*60*60) throw new RuntimeException('forecast_stale');
        preg_match_all('~<div\b(?=[^>]*\bclass=["\'][^"\']*\btw-fc-day-row\b)(?=[^>]*\bdata-date=["\'](\d{4}-\d{2}-\d{2})["\'])[^>]*>~i',$html,$matches,PREG_OFFSET_CAPTURE);
        $days=[]; $today=self::today($now);
        foreach ($matches[0] as $i=>$match) {
            $date=$matches[1][$i][0]; $d=self::date($date);
            if ($d===null||$d<$today->modify('-1 day')||$d>$today->modify('+14 days')) continue;
            $start=$match[1]+strlen($match[0]); $end=$matches[0][$i+1][1]??strlen($html);
            $row=substr($html,$start,min(8*1000,$end-$start));
            $min=self::temperature(self::field($row,'tw-fc-day-temp-min'));
            $max=self::temperature(self::field($row,'tw-fc-day-temp-max'));
            $condition=null;
            if (preg_match('~<img\b(?=[^>]*\bclass=["\'][^"\']*\btw-fc-day-icon\b)[^>]*\balt=["\']([^"\']*)["\'][^>]*>~i',$row,$img)) $condition=self::condition($img[1]);
            $precip=self::field($row,'tw-fc-day-precip-num'); $rain=null;
            if (mb_strtolower($precip)==='trocken') $rain=['kind'=>'amount','value'=>0.0];
            elseif (preg_match('/^(\d+(?:[.,]\d+)?)\s*(l\/m²|mm|%)$/Du',$precip,$p)) {
                $v=(float)str_replace(',','.',$p[1]); $kind=$p[2]==='%'?'probability':'amount';
                if ($v>=0&&$v<=($kind==='probability'?100:1000)) $rain=['kind'=>$kind,'value'=>$v];
            }
            if (isset($days[$date])) throw new RuntimeException('forecast_format');
            // An unsupported later trend must not hide valid near-term days. Missing requested days fail in query().
            if ($min===null||$max===null||$min < -80||$max>60||$min>$max||$condition===null||$rain===null) continue;
            $days[$date]=['date'=>$date,'condition'=>$condition,'min'=>$min,'max'=>$max,'precipitation'=>$rain];
        }
        ksort($days);
        if (count($days)<3) throw new RuntimeException('forecast_incomplete');
        return ['version'=>1,'place'=>'Sillian','url'=>self::URL,'updatedAt'=>$updated,'fetchedAt'=>$now,'days'=>array_values($days)];
    }

    public static function valid(array $data,int $now): bool
    {
        if (($data['version']??0)!==1||($data['place']??'')!=='Sillian'||($data['url']??'')!==self::URL) return false;
        $updated=$data['updatedAt']??0; $fetched=$data['fetchedAt']??0;
        if (!is_int($updated)||!is_int($fetched)||$updated>$now+15*60||$now-$updated>24*60*60
            ||$fetched>$now+60||!is_array($data['days']??null)||count($data['days'])<3||count($data['days'])>15) return false;
        $seen=[];
        foreach ($data['days'] as $day) {
            if (!is_array($day)||!is_string($day['date']??null)||self::date($day['date'])===null||isset($seen[$day['date']])
                ||!is_string($day['condition']??null)||self::condition($day['condition'])===null
                ||!is_numeric($day['min']??null)||!is_numeric($day['max']??null)||!is_finite((float)$day['min'])||!is_finite((float)$day['max'])
                ||$day['min'] < -80||$day['max']>60||$day['min']>$day['max']) return false;
            $p=$day['precipitation']??[];
            if (!is_array($p)||!in_array($p['kind']??null,['amount','probability'],true)||!is_numeric($p['value']??null)
                ||!is_finite((float)$p['value'])||$p['value']<0||$p['value']>($p['kind']==='probability'?100:1000)) return false;
            $seen[$day['date']]=true;
        }
        return true;
    }

    public static function refresh(array $cache,callable $http,int $now): array
    {
        if (self::valid($cache,$now)&&$now-$cache['fetchedAt']<15*60) return $cache;
        if ((int)($cache['retryAfter']??0)>$now) throw new RuntimeException('forecast_retry');
        $headers=[];
        foreach (['etag'=>'If-None-Match','lastModified'=>'If-Modified-Since'] as $key=>$header) {
            $v=(string)($cache[$key]??'');
            if ($v!==''&&strlen($v)<200&&!preg_match('/[\r\n]/',$v)) $headers[]=$header.': '.$v;
        }
        $r=$http($headers);
        if (($r['status']??0)===304&&self::valid($cache,$now)) { $cache['fetchedAt']=$now; unset($cache['retryAfter']); return $cache; }
        if (($r['status']??0)!==200||!str_contains(strtolower((string)($r['contentType']??'')),'html')) throw new RuntimeException('forecast_http');
        $data=self::parse((string)($r['body']??''),$now);
        foreach (['etag','lastModified'] as $key) {
            $v=(string)($r[$key]??'');
            if (strlen($v)<200&&!preg_match('/[\r\n]/',$v)) $data[$key]=$v;
        }
        return $data;
    }

    public static function http(array $headers): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('forecast_transport');
        $body=''; $metadata=[]; $ch=curl_init(self::URL);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'IPSAlexaHaussteuerung/1.0 (private weather question)',CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$body):int { if (strlen($body)+strlen($chunk)>2*1024*1024) return 0; $body.=$chunk; return strlen($chunk); },
            CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$metadata):int {
                if (preg_match('/^(etag|last-modified):\s*(.*?)\s*$/i',$line,$m)) $metadata[strtolower($m[1])==='etag'?'etag':'lastModified']=$m[2];
                return strlen($line);
            }]);
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE); curl_close($ch);
        if ($ok===false) throw new RuntimeException('forecast_network');
        return $metadata+['status'=>$status,'contentType'=>$type,'body'=>$body];
    }

    private static function number(float $v,bool $spoken=false): string
    {
        $n=rtrim(rtrim(number_format(abs($v),1,',',''),'0'),',');
        return ($v<0?($spoken?'minus ':'−'):'').$n;
    }

    private static function response(string $answer,array $rows=[]): array
    {
        return ['handled'=>true,'localHouseQuery'=>true,'answerSource'=>'house_status','displayIcon'=>'weather',
            'displayTitle'=>'Wettervorschau · Sillian','displayRows'=>$rows,'answer'=>$answer,'forecastSourceUrl'=>self::URL];
    }

    public static function query(string $text,?callable $provider=null,?int $now=null): ?array
    {
        if (!self::matches($text)) return null;
        $now??=time(); $q=self::norm($text); $today=self::today($now);
        if (preg_match('/\b(?:lienz|innsbruck|wien|salzburg|bozen)\b/',$q)&&!str_contains($q,'sillian')) return self::response('Die Tauernwetter-Vorschau ist für Sillian eingerichtet.');
        try {
            if ($provider===null) throw new RuntimeException('forecast_provider');
            $r=$provider();
            if (isset($r['enabled'])&&$r['enabled']===false) return self::response('Die Tauernwetter-Vorhersage ist deaktiviert.');
            if (($r['status']??'')==='warming_up') return self::response('Die Wettervorhersage für Sillian wird gerade geladen.');
            $data=$r['data']??[];
            if (!self::valid($data,$now)) throw new RuntimeException('forecast_unavailable');
            $weekdays=[1=>'Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'];
            $start=$today->modify('+1 day'); $count=3;
            $explicitThree=preg_match('/\b(?:3|drei|naechsten|naechste)\b/',$q)===1;
            if (str_contains($q,'uebermorgen')) { $start=$today->modify('+2 days'); $count=$explicitThree?3:1; }
            elseif (str_contains($q,'morgen')) $count=$explicitThree?3:1;
            elseif (preg_match('/\bheute\b/',$q)) { $start=$today; $count=$explicitThree?3:1; }
            else foreach ($weekdays as $n=>$name) if (preg_match('/\b'.strtolower($name).'\b/',$q)) {
                $offset=($n-(int)$today->format('N')+7)%7; $start=$today->modify('+'.$offset.' days'); $count=1; break;
            }
            $indexed=array_column($data['days'],null,'date'); $speech=[]; $rows=[];
            for ($i=0;$i<$count;$i++) {
                $d=$start->modify('+'.$i.' days'); $day=$indexed[$d->format('Y-m-d')]??null;
                if ($day===null) throw new RuntimeException('forecast_gap');
                $name=$weekdays[(int)$d->format('N')]; $rain=$day['precipitation'];
                $speech[]=$name.': '.$day['condition'].', '.self::number((float)$day['min'],true).' bis '.self::number((float)$day['max'],true).' Grad.';
                $amount=$rain['kind']==='amount'?($rain['value']>0?self::number((float)$rain['value']).' mm Niederschlag':'trocken'):self::number((float)$rain['value']).' % Niederschlagsrisiko';
                $rows[]=['label'=>$name.' · '.$d->format('d.m.'),'value'=>ucfirst($day['condition']).' · '.self::number((float)$day['min']).' bis '.self::number((float)$day['max']).' °C · '.$amount];
            }
            $stand=(new DateTimeImmutable('@'.$data['updatedAt']))->setTimezone(self::zone())->format('d.m.Y H:i');
            $rows[]=['label'=>'Quelle · Stand '.$stand,'value'=>'Tauernwetter · tauernwetter.at/wetter/osttirol/sillian'];
            return self::response('Für Sillian: '.implode(' ',$speech).' Quelle Tauernwetter.',$rows)+['forecastUpdatedAt'=>$data['updatedAt']];
        } catch (Throwable $ignored) { return self::response('Die aktuelle Tauernwetter-Vorhersage für Sillian kann ich gerade nicht zuverlässig abrufen.'); }
    }
}
