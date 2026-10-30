<?php
declare(strict_types=1);

/**
 * Regressionstest: Kein echter RoomsCatalog, keine festen Heimnetz-Adressen,
 * keine in Symcon-Logausgaben geleakten Zugangsdaten.
 */
function checkSecurity(bool $condition, string $explanation): void
{
    if (!$condition) throw new RuntimeException($explanation);
}
$root=dirname(__DIR__);
$read=static fn(string $file):string=>(string)file_get_contents($root.'/'.$file);
$catalogFile='IPSAlexaHaussteuerung/resources/helpers/RoomsCatalog.php';
$catalog=require $root.'/'.$catalogFile;
checkSecurity($catalog === [],'Public RoomsCatalog template must contain no real devices');
$module=$read('IPSAlexaHaussteuerung/module.php');
$fnStart=strpos($module,'private function ensureRoomsCatalogTemplate(');
$fnEnd=strpos($module,'private function ensureSystemConfigurationScript(', $fnStart);
checkSecurity($fnStart!==false && $fnEnd!==false,'RoomsCatalog install helper must exist');
$fn=substr($module,$fnStart,$fnEnd-$fnStart);
checkSecurity(str_contains($fn,'if ($isNew) {'),'Template may be written only for a NEW RoomsCatalog script');
checkSecurity(str_contains($fn,'IPS_SetScriptContent($id, $content);'),'New installations get an empty template');
$helperPos=strpos($module,'private function ensureHelperScripts(');
$helperEnd=strpos($module,'private function ensureRendererScripts(',$helperPos);
checkSecurity(!str_contains(substr($module,$helperPos,$helperEnd-$helperPos),'RoomsCatalog.php'),
    'RoomsCatalog must not be overwritten by generic helper updater');
checkSecurity(str_contains($module,'patchActionScriptSafeRequestLogging((int) $id)'),
    'Module update must also sanitize EXISTING Alexa action script logs');
$renderer=$read('IPSAlexaHaussteuerung/resources/renderers/Route_allRenderer.php');
checkSecurity(!str_contains($renderer,"' token='.\$token"),'No raw token in renderer logs');
checkSecurity(!str_contains($renderer,"'tileBase'=>\$tileBase"),'No password-bearing full URLs in renderer log');
checkSecurity(!str_contains($renderer,"'shellUrl'=>\$shellUrl"),'No query-token shell URL in renderer log');
checkSecurity(!str_contains($renderer,"'pageValue'=>\$pageValue"),'No arbitrary external URL in debug log');
checkSecurity(!str_contains($renderer,"' raw='.(string)\$data"),'No unredacted renderer response logging');
$action=$read('IPSAlexaHaussteuerung/resources/action_entry.php');
checkSecurity(str_contains($action,'IAH_SAFE_REQUEST_LOG_BEGIN'),'Action request logging must be anonymized');
checkSecurity(!str_contains($action,"'REQUEST_RAW ' ."),'Never log full requests with tokens, user IDs, personal speech');
checkSecurity(!str_contains($action,"['text' => \$homeAgentSpokenQuestion]"),'Never log spoken Alexa free text');
$parser=$read('IPSAlexaHaussteuerung/resources/helpers/KIIntentParser.php');
checkSecurity(!str_contains($parser,"\$status . ': ' . \$response"),
    'HTTP error bodies can contain private data');
$docs=$read('docs/HomeAgent_Alexa_Setup.md');
checkSecurity(str_contains($docs,'192.0.2.10'),'Documentation uses RFC 5737 TEST-NET-1');
foreach (['README.md','docs/HomeAgent_Alexa_Setup.md'] as $path) {
    $text=$read($path);
    checkSecurity(!str_contains($text,'192.168.1.4'),'Do not publish actual private server IPv4');
    checkSecurity(!str_contains($text,'24681'),'Do not publish actual MCP instance ID');
}
echo "PASS: public catalog anonymized, existing Symcon catalog preserved, example IP and logs safe\n";
