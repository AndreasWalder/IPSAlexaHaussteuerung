<?php
declare(strict_types=1);
/** Alexa -> OpenAI tool calling -> Home Agent MCP; short, spoken-only answers. */
function iah_homeagent_debug(string $stage, array $fields = []): void
{
    // Nur Laufzeiten, Methodennamen und Fehler; niemals API-Key, MCP-Token oder HTTP-Header.
    $details = $fields === [] ? '' : ' ' . json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    IPS_LogMessage('Alexa', 'HomeAgentVoice.' . $stage . $details);
}
function iah_homeagent_post(string $url, array $payload, array $headers, float $deadline): array
{
    $ms = (int)(($deadline - microtime(true)) * 1000);
    if ($ms < 250) throw new RuntimeException('Zeitbudget ausgeschöpft');
    $ch = curl_init($url);
    if (!$ch) throw new RuntimeException('cURL nicht verfügbar');
    try {
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_CONNECTTIMEOUT_MS => min(1000, $ms), CURLOPT_TIMEOUT_MS => $ms,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $data = curl_exec($ch);
        if (!is_string($data) || curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) throw new RuntimeException('HTTP-Anfrage fehlgeschlagen');
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    } finally { curl_close($ch); }
}
function iah_homeagent_rpc(string $url, string $token, string $method, array $params, float $deadline): array
{
    static $id = 0;
    $out = iah_homeagent_post($url,
        ['jsonrpc'=>'2.0','id'=>++$id,'method'=>$method,'params'=>$params ?: new stdClass()],
        ['Authorization: Bearer '.$token,'MCP-Protocol-Version: 2026-07-28'], $deadline);
    if (isset($out['error']) || !is_array($out['result'] ?? null)) throw new RuntimeException('MCP-Fehler: '.$method);
    return $out['result'];
}
function iah_homeagent_voice(string $text, array $cfg): array
{
    $endpoint = trim((string)($cfg['endpoint'] ?? ''));
    $token = trim((string)($cfg['token'] ?? ''));
    $key = trim((string)($cfg['api_key'] ?? ''));
    if ($endpoint === '' || $token === '' || $key === '' || trim($text) === '') {
        iah_homeagent_debug('config.missing', ['endpointSet'=>$endpoint !== '', 'tokenSet'=>$token !== '', 'openAiKeySet'=>$key !== '']);
        return ['handled'=>false,'error'=>'Konfiguration unvollständig'];
    }
    $parsed = parse_url($endpoint);
    if (!is_array($parsed) || !in_array($parsed['scheme'] ?? '', ['http','https'], true)
        || !isset($parsed['host']) || isset($parsed['user']) || isset($parsed['pass'])
        || !preg_match('~^/hook/homeagent/\\d+/?$~', $parsed['path'] ?? '')) {
        return ['handled'=>false,'error'=>'Ungültiger MCP-Endpunkt'];
    }
    $deadline = microtime(true) + max(2, min(7, (int)($cfg['timeout_seconds'] ?? 6)));
    $maxWords = max(8, min(45, (int)($cfg['max_words'] ?? 20)));
    $write = (bool)($cfg['allow_writes'] ?? false);
    $allowed = $write ? ['get_state','set_state','run_scene'] : ['get_state'];
    $attemptedWrite = false;
    $startedAt = microtime(true);
    iah_homeagent_debug('bridge.received', ['length'=>mb_strlen($text,'UTF-8'), 'timeoutSeconds'=>round($deadline - $startedAt, 2), 'writeEnabled'=>$write]);
    try {
        iah_homeagent_debug('mcp.tools_list.start');
        $mcpStarted = microtime(true);
        $list = iah_homeagent_rpc($endpoint, $token, 'tools/list', [], $deadline);
        iah_homeagent_debug('mcp.tools_list.done', ['durationMs'=>round((microtime(true) - $mcpStarted)*1000)]);
        $tools = [];
        foreach ($list['tools'] ?? [] as $tool) {
            if (!in_array($tool['name'] ?? '', $allowed, true)) continue;
            $tools[] = ['type'=>'function','function'=>[
                'name'=>$tool['name'],
                'description'=>$tool['description'] ?? '',
                'parameters'=>$tool['inputSchema'] ?? ['type'=>'object','properties'=>new stdClass()],
            ]];
        }
        iah_homeagent_debug('mcp.tools.available', ['names'=>array_map(static fn(array $item): string => $item['function']['name'], $tools)]);
        if (!$tools) throw new RuntimeException('Keine freigegebenen MCP-Tools');
        $messages = [
            ['role'=>'system','content'=>'Du bist eine deutsche Alexa-Haussteuerung. Benutze Home-Agent-Werkzeuge für alle Hausdaten. Erfinde keine Zustände und bestätige Aktionen nur bei Erfolg. Antworte in höchstens '.$maxWords.' Wörtern, einem kurzen Satz, ohne Markdown.'],
            ['role'=>'user','content'=>mb_substr(trim($text),0,500)],
        ];
        for ($round = 0; $round < 2; $round++) {
            iah_homeagent_debug('openai.start', ['round'=>$round+1, 'model'=>(string)($cfg['model'] ?? 'gpt-4.1-mini')]);
            $openAiStarted = microtime(true);
            $out = iah_homeagent_post('https://api.openai.com/v1/chat/completions', [
                'model'=>(string)($cfg['model'] ?? 'gpt-4.1-mini'),
                'messages'=>$messages,'tools'=>$tools,
                'tool_choice'=>$round === 0 ? 'required' : 'auto',
                'max_completion_tokens'=>200,
            ], ['Authorization: Bearer '.$key], $deadline);
            iah_homeagent_debug('openai.done', ['round'=>$round+1, 'durationMs'=>round((microtime(true)-$openAiStarted)*1000)]);
            $message = $out['choices'][0]['message'] ?? null;
            if (!is_array($message)) throw new RuntimeException('Keine KI-Antwort');
            $calls = $message['tool_calls'] ?? [];
            if (!$calls) {
                $words = preg_split('/\\s+/u', trim(strip_tags((string)($message['content'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY);
                $answer = implode(' ', array_slice($words ?: [],0,$maxWords));
                if ($answer === '') throw new RuntimeException('Leere Antwort');
                iah_homeagent_debug('answer.done', ['totalMs'=>round((microtime(true)-$startedAt)*1000), 'words'=>count($words ?: [])]);
                return ['handled'=>true,'answer'=>$answer];
            }
            if ($round > 0) throw new RuntimeException('Zu viele Werkzeugrunden');
            $messages[] = $message;
            foreach (array_slice($calls,0,3) as $call) {
                $name = (string)($call['function']['name'] ?? '');
                $args = json_decode((string)($call['function']['arguments'] ?? '{}'),true);
                if (!in_array($name,$allowed,true) || !is_array($args)) throw new RuntimeException('Unzulässiges Werkzeug');
                if ($name !== 'get_state') $attemptedWrite = true;
                iah_homeagent_debug('mcp.tool.start', ['name'=>$name]);
                $toolStarted = microtime(true);
                $result = iah_homeagent_rpc($endpoint,$token,'tools/call',['name'=>$name,'arguments'=>$args],$deadline);
                iah_homeagent_debug('mcp.tool.done', ['name'=>$name, 'durationMs'=>round((microtime(true)-$toolStarted)*1000), 'isError'=>(bool)($result['isError'] ?? false)]);
                $messages[] = ['role'=>'tool','tool_call_id'=>$call['id'],'content'=>mb_substr(json_encode($result,JSON_UNESCAPED_UNICODE),0,12000)];
                if (!empty($result['isError'])) return ['handled'=>true,'answer'=>'Die Haussteuerung hat einen Fehler gemeldet.'];
            }
        }
    } catch (Throwable $e) {
        iah_homeagent_debug('bridge.failed', ['durationMs'=>round((microtime(true)-$startedAt)*1000), 'error'=>$e->getMessage()]);
        return $attemptedWrite ? ['handled'=>true,'answer'=>'Ich konnte die Ausführung nicht bestätigen.'] : ['handled'=>false,'error'=>$e->getMessage()];
    }
    return ['handled'=>false,'error'=>'Keine Antwort'];
}
if (isset($_IPS) && array_key_exists('homeAgentText', $_IPS)) {
    echo json_encode(iah_homeagent_voice((string)$_IPS['homeAgentText'], (array)($_IPS['config'] ?? [])), JSON_UNESCAPED_UNICODE);
}
