<?php
declare(strict_types=1);
$root=dirname(__DIR__).'/IPSAlexaHaussteuerung/';
$read=static fn(string $p): string=>(string)file_get_contents($root.$p);
$module=$read('module.php');
$action=$read('resources/action_entry.php');
$renderer=$read('resources/renderers/Route_allRenderer.php');
$shell=$read('resources/html/alexa-diagnostic-shell.html');
$receiver=$read('resources/helpers/ExternalHtmlBrowserReceiver.php');
$form=json_decode($read('form.json'),true,512,JSON_THROW_ON_ERROR);
$verify=static function(bool $ok,string $message): void {
  if(!$ok)throw new RuntimeException($message);
};
foreach(['TilePreserveHtmlOnVoice','TilePerformanceDiagnostics','TileFastBootstrapEnabled'] as $field){
  $verify(str_contains($module,"RegisterPropertyBoolean('".$field."', true)"),$field.' default missing');
  $verify(str_contains($module,'patchActionScriptTileVoiceBridge'),$field.' Action migration missing');
  $present=false;
  foreach($form['elements'] as $item){
    foreach($item['items']??[] as $child){if(($child['name']??null)===$field)$present=true;}
  }
  $verify($present,$field.' not configurable');
}
$verify(str_contains($module,"'iahTileHtmlSessions'"),'Per-device status variable missing');
$verify(str_contains($module,"IPS_SetHidden(\$tileSessions,true)"),'Device state must be hidden');
$verify(str_contains($action,"hash_hmac('sha256', \$iahVoiceDeviceId, \$token)"),'Device HMAC isolation missing');
$verify(str_contains($action,"'iahTileHtmlSessions'"),'Voice action must check active device state');
$verify(str_contains($action,"'type'=>'Alexa.Presentation.HTML.HandleMessage'"),'HTML voice message missing');
$verify(str_contains($action,"'type'=>'iah_tile_resume'"),'Test resume without reload missing');
$verify(str_contains($action,"'htmlDeviceKey'"),'Device key missing from existing action routes');
$verify(str_contains($renderer,"'deviceKey'"),'Device key not sent to browser');
$verify(str_contains($renderer,"!empty(\$payload['tileFastStart'])"),'Fast start not scoped to tile route');
$verify(str_contains($renderer,"ExternalHTML.probeSkipped tile_fast_mode"),'Fast start log missing');
$verify(str_contains($receiver,"'tile_lifecycle'"),'Voice live-state hook missing');
$verify(str_contains($receiver,"\$now + 100"),'Live state must automatically expire');
$verify(str_contains($receiver,"IPS_SemaphoreEnter(\$lock, 500)"),'Concurrent heartbeat locking missing');
$verify(str_contains($receiver,"array_slice(\$states, 0, 24, true)"),'Active devices map must be bounded');
$verify(str_contains($shell,"function presence(state)"),'Active page heartbeat missing');
$verify(str_contains($shell,"},40000);"),'Live page renewal missing');
$verify(str_contains($shell,"if(pagePresent)presence('closed')"),'Page close cleanup missing');
$verify(str_contains($shell,"sdk.async=true"),'Alexa SDK must load after Tile startup');
$verify(str_contains($shell,"alexa.skill.onMessage"),'HTML.HandleMessage listener missing');
$verify(str_contains($shell,"function timing(code)"),'Performance measurement missing');
$verify(str_contains($shell,"timing('login_field')"),'Login field latency missing');
$verify(str_contains($shell,"timing('login_end')"),'Login completion latency missing');
$verify(str_contains($receiver,"'tile_performance'"),'Performance event receiver missing');
$verify(!str_contains($shell,"emit('tile_performance','ok',code,{password"),
 'No password in timing data');
$verify(str_contains($action,"['main_launch','external','test','kacheltest']"),
 'Explicit exit and camera/energy routes must not be intercepted');
echo "PASS: live Tile device presence, quiet HTML voice bridge, fast start, privacy, and migration\n";
