<?php
declare(strict_types=1);

/**
 * Regression: KI tiles must return a validated icon/status with the SAME model
 * response, without accepting external images or fabricated "ok" defaults.
 * Run: php tests/test_ai_tile_presentation.php
 */
function ckAiTile(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$root=dirname(__DIR__).'/IPSAlexaHaussteuerung/';
$backend=file_get_contents($root.'resources/helpers/KIHouseController.php');
$action=file_get_contents($root.'resources/action_entry.php');
$module=file_get_contents($root.'module.php');
ckAiTile(is_string($backend)&&is_string($action)&&is_string($module),'Source files readable');
$begin=strpos($backend,'function iah_rc_tile_ai_reply(');
$end=strpos($backend,'function iah_rc_bridge_reply(',$begin);
ckAiTile($begin!==false&&$end!==false,'AI metadata decoder available');
eval(substr($backend,$begin,$end-$begin));
$result=iah_rc_tile_ai_reply(json_encode([
    'answer'=>'Die Anlage meldet normale Werte.',
    'title'=>'PV-Status','icon'=>'energy','status'=>'ok'
],JSON_UNESCAPED_UNICODE),'Wie geht es meiner PV Anlage?');
ckAiTile($result['displayIcon']==='energy','Model-selected icon is preserved');
ckAiTile($result['displayStatus']==='ok','Explicit model status is preserved');
ckAiTile($result['displayTitle']==='PV-Status','Title preserved');
$unsafe=iah_rc_tile_ai_reply(json_encode([
    'answer'=>'Werte nicht verfügbar','title'=>'<script>Alarm</script>',
    'icon'=>'https://example.org/malicious.svg','status'=>'all_good'
]),'Anlagenstatus');
ckAiTile($unsafe['displayIcon']==='device','External icon URLs rejected');
ckAiTile($unsafe['displayStatus']==='unknown','Unknown status cannot imply OK');
ckAiTile(!str_contains($unsafe['displayTitle'],'<'),'Title is escaped/stripped');
ckAiTile(str_contains($backend,"'tile_display'")===false ||
    str_contains($backend,"$cfg['tile_display']"),'AI display is explicitly opt-in');
ckAiTile(str_contains($action,'IAH_APL_TILE_ROUTE_V5_BEGIN')
    && str_contains($action,'IAH_APL_TILE_ROUTE_V5_END')
    && str_contains($action,"'allow_writes'=>false")
    && str_contains($action,"'tile_display'=>true"),
    'Configured tile invokes read-only AI and APL V3');
ckAiTile(str_contains($module,'patchActionScriptAiStatusPage((int)$id)')
    && str_contains($module,'patchActionScriptAplTileRoute((int)$id)'),
    'Existing installed Action scripts receive both patches');
echo "PASS: KI icon, status, title, APL routing and safe installation\n";
