<?php
declare(strict_types=1);
namespace IPSAlexaHaussteuerung\Renderers;
final class RenderGeraete {
    public function process(array $payload, array $cfg): ?array {
        $sid = (int)($cfg['S']['RENDER_GERAETE'] ?? 0);
        if ($sid <= 0) return null;
        $payload['V'] = $cfg['V'] ?? [];
        $payload['S'] = $cfg['S'] ?? [];
        $args = ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        $json = @IPS_RunScriptWaitEx($sid, $args);
        $res = @json_decode((string)$json, true);
        if (!is_array($res)) return null;
        return $res;
    }
}
