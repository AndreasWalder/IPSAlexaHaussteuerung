<?php
declare(strict_types=1);

/**
 * OpenAI rechnet in USD ab. Hier werden die echten USD-Tokensätze mit dem
 * jeweils verfügbaren USD/EUR-Kurs der Europäischen Zentralbank angezeigt.
 * ECB-Feed quotiert USD pro EUR: 1 USD = 1 / rate EUR.
 */
function iah_ki_ecb_parse(string $xml): ?array
{
    if(strlen($xml)>2000000 || stripos($xml,'Cube')===false)return null;
    if(!preg_match('~<[^>]*Cube\b[^>]*\bcurrency=["\x27]USD["\x27][^>]*>~i',$xml,$m)
        && !preg_match('~<[^>]*Cube\b[^>]*\brate=["\x27][^"\x27]+["\x27][^>]*\bcurrency=["\x27]USD["\x27][^>]*>~i',$xml,$m))return null;
    if(!preg_match('~\brate=["\x27]([0-9]+(?:\.[0-9]+)?)["\x27]~i',$m[0],$rate))return null;
    $usdPerEur=(float)$rate[1];
    if($usdPerEur<0.3 || $usdPerEur>3.0)return null;
    $date='';
    if(preg_match('~\btime=["\x27](\d{4}-\d{2}-\d{2})["\x27]~i',$xml,$dt))$date=$dt[1];
    if($date==='')return null;
    return ['date'=>$date,'usdPerEur'=>$usdPerEur,'eurPerUsd'=>1/$usdPerEur,'source'=>'ECB'];
}
function iah_ki_eur(float $usd,float $usdToEur): float
{
    return $usd*$usdToEur;
}
function iah_ki_currency_breakdown(array $history,float $usdToEur): array
{
    foreach($history as &$entry) {
        if(!is_array($entry))continue;
        $entry['costEUR']=iah_ki_eur((float)($entry['costUsd']??0),$usdToEur);
        if(isset($entry['calls'])&&is_array($entry['calls'])) {
            foreach($entry['calls'] as &$call) {
                if(is_array($call))$call['costEUR']=iah_ki_eur((float)($call['costUsd']??0),$usdToEur);
            }
            unset($call);
        }
    }
    unset($entry);
    return $history;
}
