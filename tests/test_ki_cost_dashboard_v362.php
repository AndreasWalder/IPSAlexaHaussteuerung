<?php
declare(strict_types=1);

/** V3.6.2 – current-cost dashboard, no obsolete backend UI, history remains. */
require dirname(__DIR__).'/IPSAlexaHaussteuerung/src/KICostAccounting.php';
require dirname(__DIR__).'/IPSAlexaHaussteuerung/src/KICostCurrency.php';
require dirname(__DIR__).'/IPSAlexaHaussteuerung/src/KICostTrait.php';

function ck362(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException('V3.6.2: '.$reason);
}
final class CostDashboardFixture
{
    use \IAHKICost\KICostTrait;
    private function GetKICostCurrency():array{
        return ['rate'=>0.9,'date'=>'2026-11-12','source'=>'Test-EZB'];
    }
    public function render(array $store,DateTimeImmutable $now):string{
        $method=new ReflectionMethod(self::class,'RenderKICostOverview');
        return $method->invoke($this,$store,$now);
    }
}
$now=new DateTimeImmutable('2026-11-12T08:30:00+01:00');
$counts=iah_ki_empty_counters();
$legacyTotal=array_replace($counts,
    ['questions'=>1,'apiCalls'=>1,'inputTokens'=>1200,'outputTokens'=>200,'totalTokens'=>1400,'costUsd'=>0.01]);
$roomTotal=array_replace($counts,
    ['questions'=>2,'apiCalls'=>4,'inputTokens'=>5000,'cachedInputTokens'=>900,
    'outputTokens'=>800,'totalTokens'=>5800,'costUsd'=>0.02]);
$parserTotal=array_replace($counts,
    ['questions'=>1,'apiCalls'=>1,'inputTokens'=>1000,
    'outputTokens'=>100,'totalTokens'=>1100,'costUsd'=>0.005]);
$allTotal=array_replace($counts,
    ['questions'=>4,'apiCalls'=>6,'inputTokens'=>7200,'cachedInputTokens'=>900,
    'outputTokens'=>1100,'totalTokens'=>8300,'costUsd'=>0.035]);
$history=[
    ['finishedAt'=>'2026-11-12T08:28:00+01:00','backend'=>'RoomsCatalog',
     'model'=>'gpt-4.1-mini','inputTokens'=>5000,
     'cachedInputTokens'=>900,'outputTokens'=>800,'apiCalls'=>4,
     'costUsd'=>0.02,'status'=>'success'],
    ['finishedAt'=>'2026-11-01T08:00:00+01:00','backend'=>'HomeAgent',
     'model'=>'gpt-4.1-mini','inputTokens'=>1200,
     'cachedInputTokens'=>0,'outputTokens'=>200,'apiCalls'=>1,
     'costUsd'=>0.01,'status'=>'success']
];
$store=['sections'=>[
    'Alle'=>[
        'total'=>$allTotal,'days'=>['2026-11-12'=>$roomTotal],
        'months'=>['2026-11'=>$allTotal],'history'=>$history
    ],
    'RoomsCatalog'=>[
        'total'=>$roomTotal,'days'=>['2026-11-12'=>$roomTotal],
        'months'=>['2026-11'=>$roomTotal],'history'=>[$history[0]]
    ],
    'IntentParser'=>[
        'total'=>$parserTotal,'months'=>['2026-11'=>$parserTotal]
    ],
    'HomeAgent'=>[
        'total'=>$legacyTotal,'history'=>[$history[1]]
    ]
]];
$html=(new CostDashboardFixture())->render($store,$now);
ck362(str_contains($html,'KI-Kostenübersicht')
    && str_contains($html,'ChatGPT + RoomsCatalog')
    && str_contains($html,'Intent-Parser'),
    'active RoomsCatalog and Intent-Parser visible');
ck362(!str_contains($html,'HomeAgent') && !str_contains($html,'Home Agent'),
    'obsolete brand never visible in EUR HTML');
ck362(str_contains($html,'Altbestand')
    && str_contains($html,'bereits gespeicherte historische Aufrufe'),
    'old historical backend transparently labelled but not erased');
ck362(str_contains($html,'Tokens heute')
    && str_contains($html,'Eingabe Monat')
    && str_contains($html,'Cache Monat')
    && str_contains($html,'Prognose Monatsende')
    && str_contains($html,'€ 0,031500'),
    'KPI cards, token breakdown and EUR conversion from unchanged totals');
ck362(str_contains($html,'2026-11-12T08:28:00+01:00')
    && str_contains($html,'2026-11-01T08:00:00+01:00'),
    'last 25 includes current and historical questions');

$form=json_decode(file_get_contents(
    dirname(__DIR__).'/IPSAlexaHaussteuerung/form.json'),
    true,512,JSON_THROW_ON_ERROR);
$captions=[];
$visit=static function(array $items)use(&$visit,&$captions):void{
    foreach($items as $item){
        if(isset($item['caption']))$captions[]=(string)$item['caption'];
        if(is_array($item['items']??null))$visit($item['items']);
    }
};
$visit($form['elements']??[]);
$ui=implode("\n",$captions);
ck362(!str_contains($ui,'HomeAgent')&&!str_contains($ui,'Home Agent'),
    'all visible configuration labels must be free of obsolete brand');
ck362(str_contains($ui,'KI-Kosten & Tokenverbrauch'),
    'cost panel remains accessible');

$trait=file_get_contents(
    dirname(__DIR__).'/IPSAlexaHaussteuerung/src/KICostTrait.php');
ck362(str_contains($trait,"IPS_GetObjectIDByIdent('iahKICostHomeAgent'")
    && str_contains($trait,'IPS_SetHidden($legacy,true)'),
    'old cost category hidden rather than deleted');
$start=strpos($trait,'foreach ([');
ck362(str_contains($trait,"'Alle'=>'KI-Kosten gesamt'")
    && str_contains($trait,"'IntentParser'=>'KI – Intent-Parser'"),
    'new cost categories are only two live backends plus aggregate');
echo "PASS V3.6.2: EUR cards, active backend summary, historic legacy preservation and clean labels\n";
