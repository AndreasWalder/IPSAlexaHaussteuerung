<?php
declare(strict_types=1);
require dirname(__DIR__).'/IPSAlexaHaussteuerung/src/AlexaLogBuffer.php';
require dirname(__DIR__).'/IPSAlexaHaussteuerung/src/KICostCurrency.php';
function verifyLogEur(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException($msg);}
$file=tempnam(sys_get_temp_dir(),'iah_log_');
if($file===false)throw new RuntimeException('Temp file inaccessible');
try {
    $h=fopen($file,'wb');
    for($i=1;$i<=630;$i++) {
        fwrite($h,sprintf("31.10.2026 20:00:%02d [CUSTOM] Alexa  Test-Ereignis-%04d\n",$i%60,$i));
        fwrite($h,sprintf("31.10.2026 20:00:%02d [INFO] Andere  Irgendwas\n",$i%60));
        fwrite($h,sprintf("31.10.2026 20:00:%02d [INFO] VariableManager  Alexa-Meldungen (letzte 500)=Alt\n",$i%60));
    }
    fwrite($h,"31.10.2026 20:05:59 Alexa  token=abc123SECRETxyz password=hunter2 192.168.1.44\n");
    fclose($h);
    $last=iah_alexalog_tail($file,500);
    verifyLogEur(count($last)===500,'Exactly 500 most recent Alexa entries');
    verifyLogEur(str_contains($last[0],'Test-Ereignis-0132'),'Correct oldest selected (131 earlier + secret latest)');
    verifyLogEur(str_contains($last[498],'Test-Ereignis-0630'),'Correct newest regular entry');
    verifyLogEur(!str_contains($last[499],'hunter2')
        && !str_contains($last[499],'abc123SECRETxyz')
        && !str_contains($last[499],'192.168.1.44'),'Credentials and internal IP redacted');
    verifyLogEur(str_contains($last[499],'[GESCHUETZT]'),'Redaction trace remains visible');
    verifyLogEur(count(iah_alexalog_tail($file,2))===2,'Works for shorter count');
    verifyLogEur(count(iah_alexalog_tail($file,500,40))<=1,'Bounded read respects max bytes');
} finally { @unlink($file); }

$xml=<<<'XML'
<?xml version="1.0"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
 xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
<Cube><Cube time="2026-10-30"><Cube currency="USD" rate="1.1172"/></Cube></Cube>
</gesmes:Envelope>
XML;
$parsed=iah_ki_ecb_parse($xml);
verifyLogEur(is_array($parsed),'ECB XML parsed');
verifyLogEur($parsed['date']==='2026-10-30','ECB rate date correct');
verifyLogEur(abs($parsed['usdPerEur']-1.1172)<0.0000001,'ECB EUR→USD correct');
verifyLogEur(abs($parsed['eurPerUsd']-(1/1.1172))<0.0000001,'ECB USD→EUR inverted');
verifyLogEur(iah_ki_ecb_parse('<Cube time="2026-10-30"><Cube currency="USD" rate="0.00001"/></Cube>')===null,'Bogus rate rejected');
verifyLogEur(iah_ki_ecb_parse('<Cube currency="CHF" rate="1.2"/>')===null,'Only USD allowed');
$history=iah_ki_currency_breakdown([[
    'costUsd'=>0.02,'calls'=>[['costUsd'=>0.012],['costUsd'=>0.008]]
]],$parsed['eurPerUsd']);
verifyLogEur(abs($history[0]['costEUR']-(0.02/1.1172))<1e-12,'Question converted to EUR');
verifyLogEur(abs($history[0]['calls'][0]['costEUR']-(0.012/1.1172))<1e-12,'Individual round EUR');
verifyLogEur($history[0]['costUsd']===0.02,'Historical USD untouched');
$module=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/module.php');
$trait=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/src/KICostTrait.php');
$form=json_decode(file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/form.json'),true,512,JSON_THROW_ON_ERROR);
verifyLogEur(str_contains($module,"RegisterTimer('AlexaLogRefresh', 15000"),'15-s Alexa refresh installed');
verifyLogEur(str_contains($module,"RegisterPropertyFloat('KICostMonthlyWarningEUR'"),'EUR warning property');
verifyLogEur(str_contains($module,'IAH_UpdateKICostExchangeRate'),'Automatic ECB rate timer');
verifyLogEur(str_contains($module,'EnsureAlexaLogInfrastructure'),'Copyable TextBox created');
verifyLogEur(str_contains($trait,"'costTodayEUR'"),'Visible EUR variables');
verifyLogEur(str_contains($trait,"ReadPropertyFloat('KICostMonthlyWarningEUR')"),'Warning converted to EUR');
verifyLogEur(str_contains($trait,'KICostManualUsdToEur'),'Manual rate override');
verifyLogEur(str_contains($trait,'iah_ki_currency_breakdown'),'Detailed JSON has EUR values');
verifyLogEur(!str_contains(json_encode($form),'KICostMonthlyWarningUSD'),'Old USD warning hidden in UI');
$action=file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/action_entry.php');
$start=strpos($action,'        // HOME_AGENT_VOICE_HOOK_BEGIN');
$end=strpos($action,'        // HOME_AGENT_VOICE_HOOK_END');
verifyLogEur($start!==false && $end>$start,'Voice hook exists');
verifyLogEur(str_contains(substr($action,$start,$end-$start),'register_shutdown_function'),
    'Existing action script updates log after Alexa via migrating hook');
echo "PASS: 500 Alexa log lines, max-byte read, secret redaction, ECB USD/EUR, EUR statistics and shutdown hook\n";
