<?php
declare(strict_types=1);

namespace IAHAlexaLog;

trait AlexaLogTrait
{
    private function EnsureAlexaLogInfrastructure(int $diagCategory): void
    {
        $category=$this->ensureCategory($diagCategory,'Alexa-Protokoll (kopieren)','iahAlexaLogCategory');
        $this->ensureVar($category,'Alexa-Meldungen (letzte 500)','iahAlexaLogText',VARIABLETYPE_STRING,'~TextBox',null);
        $this->ensureVar($category,'Anzahl gespeicherter Meldungen','iahAlexaLogCount',VARIABLETYPE_INTEGER,'',null);
        $this->ensureVar($category,'Letzte Aktualisierung','iahAlexaLogUpdated',VARIABLETYPE_STRING,'',null);
        $this->ensureVar($category,'Quelle / Status','iahAlexaLogStatus',VARIABLETYPE_STRING,'',null);
    }

    /**
     * Wird unmittelbar nach einem Alexa-Skript und ergänzend alle 15 s
     * aufgerufen. Nutzt ausschließlich das bestehende Symcon-Logfile.
     * Der Puffer wird ersetzt (rollierende letzte 500), nie angehängt.
     * Es wird absichtlich KEIN IPS_LogMessage aufgerufen, um keine
     * Schleife mit dem eigenen Protokoll-Export zu erzeugen.
     */
    public function RefreshAlexaLogBuffer(): bool
    {
        $diag=(int)@\IPS_GetObjectIDByIdent('iahDiag',$this->InstanceID);
        $category=$diag>0?(int)@\IPS_GetObjectIDByIdent('iahAlexaLogCategory',$diag):0;
        if($category<=0)return false;
        $id=(int)@\IPS_GetObjectIDByIdent('iahAlexaLogText',$category);
        if($id<=0||!\IPS_VariableExists($id))return false;
        $lock='IAH.AlexaLog.'.$this->InstanceID;
        if(!\IPS_SemaphoreEnter($lock,150))return false;
        try {
            $logDir=function_exists('IPS_GetLogDir') ? (string)\IPS_GetLogDir() : '';
            $path=rtrim($logDir,"/\\").DIRECTORY_SEPARATOR.'logfile.log';
            if($logDir==='' || !is_readable($path)) {
                $this->SetAlexaLogValue($category,'iahAlexaLogStatus',
                    'Systemlog derzeit nicht lesbar. Bisherigen Export beibehalten.');
                return false;
            }
            $rows=\iah_alexalog_tail($path,500);
            $text=implode("\n",$rows);
            $existing=(string)\GetValueString($id);
            if($text!==$existing) \SetValueString($id,$text);
            $this->SetAlexaLogValue($category,'iahAlexaLogCount',count($rows));
            // Damit eine Aktualisierung ohne neue Zeilen nicht ständig
            // VariableManager-Einträge erzeugt, Datum nur bei Textänderung.
            if($text!==$existing) {
                $now=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Vienna'));
                $this->SetAlexaLogValue($category,'iahAlexaLogUpdated',$now->format('d.m.Y H:i:s'));
            }
            $this->SetAlexaLogValue($category,'iahAlexaLogStatus',
                'Aktiv – IP-Symcon Systemlog (max. 500 Alexa-Zeilen, vertrauliche Muster maskiert)');
            return true;
        } catch (\Throwable $error) {
            $this->SetAlexaLogValue($category,'iahAlexaLogStatus',
                'Loglesefehler: '.get_class($error));
            return false;
        } finally {
            \IPS_SemaphoreLeave($lock);
        }
    }

    private function SetAlexaLogValue(int $category,string $ident,int|string $value): void
    {
        $var=(int)@\IPS_GetObjectIDByIdent($ident,$category);
        if($var<=0 || !\IPS_VariableExists($var))return;
        if(is_int($value)) {
            if((int)\GetValueInteger($var)!==$value)\SetValueInteger($var,$value);
        } elseif((string)\GetValueString($var)!==$value) {
            \SetValueString($var,$value);
        }
    }
}
