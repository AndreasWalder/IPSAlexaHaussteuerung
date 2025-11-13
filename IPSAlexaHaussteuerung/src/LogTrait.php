<?php
declare(strict_types=1);
namespace IPSAlexaHaussteuerung;
trait LogTrait{
    private function log(string $lvl,string $msg,array $ctx=[]): void {
        // level filter
        $map = ['error'=>0,'info'=>1,'debug'=>2];
        $set = 1;
        if (method_exists($this,'ReadPropertyString')) {
            $lev = $this->ReadPropertyString('LOG_LEVEL');
            $set = $map[$lev] ?? 1;
        }
        $cur = $map[$lvl] ?? 1;
        if ($cur <= $set) {
            @IPS_LogMessage('Alexa', sprintf('%s | %s | %s', strtoupper($lvl), $msg, json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
        }
        // store in recent buffer (last ~200 lines)
        try {
            $id = @IPS_GetObjectIDByIdent('log_recent', $this->InstanceID);
            if ($id) {
                $line = date('c').' ['.strtoupper($lvl).'] '.$msg;
                if (!empty($ctx)) {
                    $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                }
                $line .= "\n";
                $curTxt = (string)@GetValue($id);
                $curTxt .= $line;
                // keep last 200 lines
                $parts = explode("\n", $curTxt);
                if (count($parts) > 210) {
                    $parts = array_slice($parts, -210);
                }
                $newTxt = implode("\n", $parts);
                @SetValueString($id, $newTxt);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
