<?php
declare(strict_types=1);
require_once __DIR__ . '/../IPSAlexaHaussteuerung/src/EchoRemoteTrait.php';
function echoCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['instances'][$id]); }
function IPS_GetInstance(int $id): array { return $GLOBALS['instances'][$id]; }
function echoSend(string $method, int $id, string $text): bool
{
    $GLOBALS['calls'][] = [$method, $id, $text];
    if ($GLOBALS['throw']) throw new RuntimeException('PRIVATE_PROVIDER_RESPONSE');
    return $GLOBALS['accepted'];
}
function ECHOREMOTE_Announcement(int $id, string $text): bool { return echoSend('announce', $id, $text); }
function ECHOREMOTE_AnnouncementToAll(int $id, string $text): bool { return echoSend('announce_all', $id, $text); }
function ECHOREMOTE_TextToSpeech(int $id, string $text): bool { return echoSend('speak', $id, $text); }
function ECHOREMOTE_TextToSpeechToAll(int $id, string $text): bool { return echoSend('speak_all', $id, $text); }
final class EchoRemoteFixture
{
    use IAHEchoRemoteTrait;
    public array $properties = ['EchoRemoteEnabled' => true, 'EchoRemoteDeviceId' => 42, 'EchoRemoteAllDevices' => true];
    public array $attributes = [];
    public array $updates = [];
    public function ReadPropertyInteger($name): int { return (int)($this->properties[$name] ?? 0); }
    public function ReadPropertyBoolean($name): bool { return (bool)($this->properties[$name] ?? false); }
    public function ReadAttributeString($name): string { return $this->attributes[$name] ?? ''; }
    public function WriteAttributeString($name, $value): void { $this->attributes[$name] = $value; }
    public function UpdateFormField($name, $field, $value): void { $this->updates[$name][$field] = $value; }
    public function panel(): array { return $this->buildEchoRemotePanel(); }
}
$GLOBALS['instances'] = [42 => ['ModuleInfo' => ['ModuleID' => '{496AB8B5-396A-40E4-AF41-32F4C48AC90D}'],
    'InstanceStatus' => 102, 'ConnectionID' => 43], 43 => ['InstanceStatus' => 102]];
$GLOBALS['calls'] = [];
$GLOBALS['accepted'] = true;
$GLOBALS['throw'] = false;
$echo = new EchoRemoteFixture();
echoCheck($echo->Announce('Hallo') && $GLOBALS['calls'][0] === ['announce_all', 42, 'Hallo'],
    'Configured all-devices output uses native AnnouncementToAll');
echoCheck($echo->SpeakText('Wäsche fertig') && end($GLOBALS['calls'])[0] === 'speak_all', 'TTS also supports all devices');
echoCheck($echo->Announce('Gerät', 42) && end($GLOBALS['calls'])[0] === 'announce', 'Explicit target is a single device');
$echo->properties['EchoRemoteAllDevices'] = false;
echoCheck($echo->SpeakText('Ein Echo') && end($GLOBALS['calls'])[0] === 'speak', 'Single default target works');
echoCheck($echo->Announce('Boiler & Küche') && end($GLOBALS['calls'])[2] === 'Boiler &amp; Küche',
    'Announcements escape text before provider SSML wrapping');
$echo->properties['EchoRemoteEnabled'] = false;
$count = count($GLOBALS['calls']);
echoCheck(!$echo->Announce('Aus') && count($GLOBALS['calls']) === $count, 'Disabled output never sends');
$echo->properties['EchoRemoteEnabled'] = true;
echoCheck(!$echo->Announce('Fehlt', 999) && !$echo->Announce('Falsches Modul', 43), 'Missing and unrelated instances are rejected');
$GLOBALS['instances'][43]['InstanceStatus'] = 201;
echoCheck(!$echo->Announce('Offline') && json_decode($echo->GetEchoRemoteStatus(), true)['status'] === 'connection_inactive',
    'Disconnected Echo IO is surfaced');
$GLOBALS['instances'][43]['InstanceStatus'] = 102;
echoCheck(!$echo->Announce('   ') && !$echo->Announce(str_repeat('a', 1001)), 'Empty and oversized messages never send');
$GLOBALS['accepted'] = false;
echoCheck(!$echo->Announce('Abgelehnt') && $echo->attributes['EchoRemoteLastResult'] === 'rejected', 'Provider rejection is not success');
$GLOBALS['throw'] = true;
echoCheck(!$echo->Announce('Fehler') && !str_contains($echo->GetEchoRemoteStatus(), 'PRIVATE'), 'Provider exceptions never leak');
$GLOBALS['throw'] = false;
$GLOBALS['accepted'] = true;
ob_start(); $echo->TestEchoRemote(true); $notice = ob_get_clean();
echoCheck(str_contains($notice, 'angenommen') && isset($echo->updates['EchoRemoteNotice']), 'UI test invokes real adapter');
echoCheck(str_contains(json_encode($echo->panel()), 'EchoRemoteAllDevices'), 'Central panel exposes all-devices option');
echo "PASS Echo Remote all/single targets, TTS, instance checks, connection failures, provider rejection and UI test\n";
