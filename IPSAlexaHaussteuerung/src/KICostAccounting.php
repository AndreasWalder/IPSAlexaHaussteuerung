<?php
declare(strict_types=1);

/**
 * KI-Kostenstatistik: reine Funktionen, ohne Abhängigkeit von IP-Symcon.
 * Beträge sind API-Preisschätzungen in USD, keine offizielle Abrechnung.
 * Raten: USD pro 1 Mio. Tokens, jeweils ungekacht / gecacht / Ausgabe.
 */
function iah_ki_price_table(): array
{
    return [
        'gpt-6-luna' => ['input' => 0.10, 'cached' => 0.01, 'output' => 0.50],
        'gpt-4.1-mini' => ['input' => 0.40, 'cached' => 0.10, 'output' => 1.60],
        'gpt-4.1-nano' => ['input' => 0.10, 'cached' => 0.025, 'output' => 0.40],
        'gpt-4.1' => ['input' => 2.00, 'cached' => 0.50, 'output' => 8.00],
    ];
}
function iah_ki_price_for_model(string $model, array $prices): ?array
{
    // Snapshot-Suffix wie gpt-4.1-mini-2025-04-14 zulassen.
    foreach ($prices as $name => $rates) {
        if ($model !== $name && !preg_match('/^' . preg_quote((string)$name,'/') . '-\d{4}-\d{2}-\d{2}$/', $model)) continue;
        if (!is_array($rates) || !isset($rates['input'],$rates['cached'],$rates['output'])) return null;
        foreach (['input','cached','output'] as $key) {
            if (!is_numeric($rates[$key]) || (float)$rates[$key] < 0) return null;
        }
        return ['input'=>(float)$rates['input'], 'cached'=>(float)$rates['cached'], 'output'=>(float)$rates['output']];
    }
    return null;
}
function iah_ki_usage_row(array $response, array $prices): array
{
    $usage = $response['usage'] ?? null;
    $model = (string)($response['model'] ?? '');
    $measured = is_array($usage) && isset($usage['prompt_tokens'], $usage['completion_tokens'])
        && is_numeric($usage['prompt_tokens']) && is_numeric($usage['completion_tokens']);
    $input = $measured ? max(0,(int)$usage['prompt_tokens']) : 0;
    $output = $measured ? max(0,(int)$usage['completion_tokens']) : 0;
    $cached = $measured ? max(0,min($input,(int)($usage['prompt_tokens_details']['cached_tokens'] ?? 0))) : 0;
    $rates = iah_ki_price_for_model($model,$prices);
    $priced = $measured && $rates !== null;
    return [
        'model'=>$model, 'measured'=>$measured, 'priced'=>$priced,
        'inputTokens'=>$input, 'cachedInputTokens'=>$cached, 'outputTokens'=>$output,
        'totalTokens'=>$input+$output,
        'costUsd'=>$priced ? (($input-$cached)*$rates['input'] + $cached*$rates['cached'] + $output*$rates['output'])/1000000 : 0.0,
        'rates'=>$rates, // Preissnapshot wird beim Ereignis protokolliert.
    ];
}
function iah_ki_empty_counters(): array
{
    return [
        'questions'=>0,'success'=>0,'failures'=>0,'apiCalls'=>0,'callsWithoutUsage'=>0,
        'callsWithoutPrice'=>0,'inputTokens'=>0,'cachedInputTokens'=>0,'outputTokens'=>0,
        'totalTokens'=>0,'costUsd'=>0.0,
    ];
}
function iah_ki_merge_counters(array &$into, array $changes): void
{
    foreach (iah_ki_empty_counters() as $key=>$zero) {
        $into[$key] = ($into[$key] ?? $zero) + ($changes[$key] ?? 0);
    }
}
function iah_ki_apply_event(array &$store, array $event, array $prices, DateTimeImmutable $now): bool
{
    $backend = (string)($event['backend'] ?? '');
    $id = (string)($event['questionId'] ?? '');
    if (!in_array($backend,['RoomsCatalog','HomeAgent','IntentParser'],true)
        || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/D',$id)
        || !in_array($event['type'] ?? '', ['start','api','finish'],true)) return false;
    $date=$now->format('Y-m-d');$month=$now->format('Y-m');
    $type=$event['type'];
    $store['version']=1;
    foreach (['Alle',$backend] as $section) {
        if (!isset($store['sections'][$section])) $store['sections'][$section]=[];
        $s=&$store['sections'][$section];
        $s['total']??=iah_ki_empty_counters();
        $s['days'][$date]??=iah_ki_empty_counters();
        $s['months'][$month]??=iah_ki_empty_counters();
        $s['active']??=[];
        $active=&$s['active'];
        if ($type==='start') {
            if (!isset($active[$id])) {
                $active[$id]=[
                    'id'=>$id,'backend'=>$backend,'startedAt'=>$now->format(DATE_ATOM),
                    'model'=>(string)($event['model']??''),'status'=>'running','costUsd'=>0.0,
                    'inputTokens'=>0,'cachedInputTokens'=>0,'outputTokens'=>0,'totalTokens'=>0,
                    'apiCalls'=>0,'callsWithoutUsage'=>0,'callsWithoutPrice'=>0,'seenCalls'=>[],'calls'=>[]
                ];
                iah_ki_merge_counters($s['total'],['questions'=>1]);
                iah_ki_merge_counters($s['days'][$date],['questions'=>1]);
                iah_ki_merge_counters($s['months'][$month],['questions'=>1]);
            }
        } elseif ($type==='api') {
            if (!isset($active[$id])) { unset($active); unset($s); continue; }
            $callId=(string)($event['callId']??'');
            if ($callId==='' || isset($active[$id]['seenCalls'][$callId])) { unset($active);unset($s);continue; }
            $active[$id]['seenCalls'][$callId]=true;
            $row=iah_ki_usage_row((array)($event['response']??[]),$prices);
            $delta=['apiCalls'=>1,'inputTokens'=>$row['inputTokens'],'cachedInputTokens'=>$row['cachedInputTokens'],
                'outputTokens'=>$row['outputTokens'],'totalTokens'=>$row['totalTokens'],
                'costUsd'=>$row['costUsd'],'callsWithoutUsage'=>$row['measured']?0:1,
                'callsWithoutPrice'=>$row['priced']?0:1];
            foreach ($delta as $key=>$value) {
                $active[$id][$key]=($active[$id][$key]??0)+$value;
            }
            foreach (['total','day','month'] as $scope) {
                if($scope==='total')iah_ki_merge_counters($s['total'],$delta);
                elseif($scope==='day')iah_ki_merge_counters($s['days'][$date],$delta);
                else iah_ki_merge_counters($s['months'][$month],$delta);
            }
            $active[$id]['calls'][]=[
                'callId'=>$callId,'model'=>$row['model'],'inputTokens'=>$row['inputTokens'],
                'cachedInputTokens'=>$row['cachedInputTokens'],'outputTokens'=>$row['outputTokens'],
                'totalTokens'=>$row['totalTokens'],'costUsd'=>$row['costUsd'],
                'measured'=>$row['measured'],'priced'=>$row['priced'],'rates'=>$row['rates'],
                'timestamp'=>$now->format(DATE_ATOM)
            ];
            $active[$id]['model']=$row['model'] ?: $active[$id]['model'];
            $active[$id]['rateSnapshot']=$row['rates'];
            $models=&$s['models'];
            $model=$row['model']?:'(unbekannt)';
            $models[$model]??=iah_ki_empty_counters();
            iah_ki_merge_counters($models[$model],$delta);
            unset($models);
        } elseif ($type==='finish') {
            if (!isset($active[$id])) { unset($active);unset($s);continue; }
            $success=(bool)($event['success']??false);
            $delta=[$success?'success':'failures'=>1];
            iah_ki_merge_counters($s['total'],$delta);
            iah_ki_merge_counters($s['days'][$date],$delta);
            iah_ki_merge_counters($s['months'][$month],$delta);
            $entry=$active[$id];
            $entry['status']=$success?'success':'error';
            $entry['finishedAt']=$now->format(DATE_ATOM);
            $s['last']=$entry;
            $s['history']??=[];
            array_unshift($s['history'],$entry);
            $s['history']=array_slice($s['history'],0,100);
            unset($active[$id]);
        }
        // Keine unbeschränkt wachsenden Zeitreihen/abgebrochenen Sessions.
        if (isset($s['days']) && count($s['days'])>100) { ksort($s['days']);$s['days']=array_slice($s['days'],-100,null,true); }
        if (isset($s['months']) && count($s['months'])>36) { ksort($s['months']);$s['months']=array_slice($s['months'],-36,null,true); }
        foreach ($s['active'] as $otherId=>$pending) {
            if (strtotime((string)($pending['startedAt']??'')) < $now->getTimestamp()-3600)
                unset($s['active'][$otherId]); // API-Kosten bleiben gezählt, auch wenn Skript abbricht.
        }
        unset($active,$s);
    }
    return true;
}
function iah_ki_section_snapshot(array $store, string $section, DateTimeImmutable $now): array
{
    $data=$store['sections'][$section]??[];
    return [
        'total'=>array_replace(iah_ki_empty_counters(),(array)($data['total']??[])),
        'today'=>array_replace(iah_ki_empty_counters(),(array)($data['days'][$now->format('Y-m-d')]??[])),
        'month'=>array_replace(iah_ki_empty_counters(),(array)($data['months'][$now->format('Y-m')]??[])),
        'last'=>(array)($data['last']??[]),'history'=>(array)($data['history']??[]),
        'models'=>(array)($data['models']??[]),'running'=>count((array)($data['active']??[]))
    ];
}
