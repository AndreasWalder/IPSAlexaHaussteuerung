<?php
declare(strict_types=1);

namespace IPSAlexaHaussteuerung;

trait LogTrait
{
    private function log(string $lvl, string $msg, array $ctx = []): void
    {
        $levels = ['off' => -1, 'error' => 0, 'warn' => 0, 'info' => 1, 'debug' => 2];
        $configured = method_exists($this, 'ReadPropertyString') ? $this->ReadPropertyString('LOG_LEVEL') : 'info';
        $current = $levels[$lvl] ?? 1;
        $limit = $levels[$configured] ?? 1;
        if ($current <= $limit) {
            @IPS_LogMessage('Alexa', sprintf('%s | %s | %s', strtoupper($lvl), $msg,
                json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }
        // The standalone Router has no instance. Do not write to another
        // instance's buffer or assume an InstanceID exists there.
        $instance = (int)($this->InstanceID ?? 0);
        if ($instance <= 0 || $limit < 0) return;
        try {
            $id = (int)(@IPS_GetObjectIDByIdent('logRecent', $instance)
                ?: @IPS_GetObjectIDByIdent('log_recent', $instance));
            if ($id <= 0 || !IPS_VariableExists($id) || (int)(IPS_GetVariable($id)['VariableType'] ?? -1) !== 3) return;
            // This small buffer is for local module events. Keep context
            // payloads out; the existing Alexa log view has its own buffer.
            $line = \iah_alexalog_redact(date(DATE_ATOM) . ' [' . strtoupper($lvl) . '] '
                . str_replace(["\r", "\n"], ' ', $msg));
            $lock = 'IAH.LogRecent.' . $instance;
            $useLock = function_exists('IPS_SemaphoreEnter');
            if ($useLock && !IPS_SemaphoreEnter($lock, 150)) return;
            try {
                $existing = rtrim(GetValueString($id), "\r\n");
                $lines = $existing !== '' ? explode("\n", $existing) : [];
                $lines[] = $line;
                SetValueString($id, implode("\n", array_slice($lines, -200)) . "\n");
            } finally {
                if ($useLock) IPS_SemaphoreLeave($lock);
            }
        } catch (\Throwable $ignored) {
            // Logging never interrupts a request or a refresh.
        }
    }
}
