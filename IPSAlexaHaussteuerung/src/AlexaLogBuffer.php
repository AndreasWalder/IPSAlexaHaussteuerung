<?php
declare(strict_types=1);

/**
 * Die letzten Alexa-Meldungen aus dem existierenden Symcon-Systemlog lesen.
 * Keine weitere Datei, keine User-Zugriffe auf den Server und kein
 * zusätzliches Loggen während der Pufferaktualisierung.
 *
 * Es werden höchstens 16 MiB vom Dateiende gelesen und 500 Treffer behalten.
 * Falls der Symcon-Log zu viele Daten enthält, können ältere Treffer fehlen.
 */
function iah_alexalog_redact(string $line): string
{
    $line=trim(str_replace(["\r","\0"],'',$line));
    $line=preg_replace('~\b(?:sk-proj-|sk-|ghp_|github_pat_|AIza)[A-Za-z0-9_-]{12,}\b~iu','[SCHLUESSEL]',$line)??$line;
    $line=preg_replace('~\bamzn1\.[A-Za-z0-9._-]{12,}\b~iu','[ALEXA_ID]',$line)??$line;
    // Auch URL-Parameter und JSON-Attribute wie "token":"ABC" abfangen.
    $line=preg_replace('~((?:token|passwort|password|api[_ -]?key|client[_ -]?secret|authorization|access[_-]?token)\s*["\x27]?\s*(?:=|:)\s*["\x27]?)([^\s&,"\x27}\];]+)~iu','$1[GESCHUETZT]',$line)??$line;
    $line=preg_replace('~(?<=\?)(?:[^ \r\n]*)(?:token|passwort|password|api[_-]?key|secret|auth)=[^ \r\n]*~iu','[URL-QUERY-GESCHUETZT]',$line)??$line;
    $line=preg_replace('~\b(?:192\.168|10)\.(?:\d{1,3}\.){2}\d{1,3}\b~','[INTERNE_IP]',$line)??$line;
    return mb_substr($line,0,2500,'UTF-8');
}
function iah_alexalog_is_alexa(string $line): bool
{
    // Die eigenen Buffer-SetValue-Meldungen auslassen, sonst wächst das Log
    // durch das Einlesen desselben Logs (VariableManager) unkontrolliert.
    if (stripos($line,'VariableManager')!==false
        || stripos($line,'Alexa-Meldungen (letzte 500)')!==false
        || stripos($line,'iahAlexaLogText')!==false) return false;
    return preg_match('/(?<![a-z0-9])Alexa(?![a-z0-9])/iu',$line)===1;
}
function iah_alexalog_tail(string $file,int $limit=500,int $maxBytes=16777216): array
{
    if ($limit<1 || $maxBytes<1 || !is_readable($file))return [];
    $h=@fopen($file,'rb');
    if ($h===false)return [];
    $hits=[];
    try {
        if(fseek($h,0,SEEK_END)!==0)return [];
        $pos=ftell($h);
        if($pos===false)return [];
        $remaining=min((int)$pos,$maxBytes);
        $carry='';
        while($remaining>0 && count($hits)<$limit) {
            $n=min(65536,$remaining);
            $pos-=$n;
            if(fseek($h,$pos,SEEK_SET)!==0)break;
            $part=fread($h,$n);
            if($part===false || strlen($part)!==$n)break;
            $remaining-=$n;
            $lines=explode("\n",$part.$carry);
            $carry=array_shift($lines);
            for($i=count($lines)-1;$i>=0 && count($hits)<$limit;$i--) {
                if(iah_alexalog_is_alexa($lines[$i]))$hits[]=iah_alexalog_redact($lines[$i]);
            }
        }
        if($pos===0 && count($hits)<$limit && iah_alexalog_is_alexa($carry))
            $hits[]=iah_alexalog_redact($carry);
    } finally {
        fclose($h);
    }
    return array_reverse($hits);
}
