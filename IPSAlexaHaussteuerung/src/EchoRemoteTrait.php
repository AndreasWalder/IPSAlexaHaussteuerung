<?php
declare(strict_types=1);

/** Central output adapter for the user's existing Echo Remote 2 installation. */
trait IAHEchoRemoteTrait
{
    private const ECHO_REMOTE_DEVICE_MODULE = '{496AB8B5-396A-40E4-AF41-32F4C48AC90D}';

    /** Zero uses the configured output (all devices or the default device). */
    public function Announce(string $text, int $echoDeviceId = 0): bool
    {
        return $this->sendEchoRemote($text, $echoDeviceId, true);
    }

    public function SpeakText(string $text, int $echoDeviceId = 0): bool
    {
        return $this->sendEchoRemote($text, $echoDeviceId, false);
    }

    private function echoRemoteTarget(int $override = 0): array
    {
        $deviceId = $override === 0 ? $this->ReadPropertyInteger('EchoRemoteDeviceId') : $override;
        $target = ['enabled' => $this->ReadPropertyBoolean('EchoRemoteEnabled'),
            'deviceId' => $deviceId,
            'allDevices' => $override === 0 && $this->ReadPropertyBoolean('EchoRemoteAllDevices'),
            'ready' => false, 'status' => 'disabled'];
        if (!$target['enabled']) return $target;
        $target['status'] = 'device_missing';
        if ($deviceId <= 0 || !IPS_InstanceExists($deviceId)) return $target;
        $device = IPS_GetInstance($deviceId);
        $target['status'] = 'wrong_module';
        if (strtoupper((string)($device['ModuleInfo']['ModuleID'] ?? '')) !== self::ECHO_REMOTE_DEVICE_MODULE) return $target;
        $target['status'] = 'device_inactive';
        if ((int)($device['InstanceStatus'] ?? 0) !== 102) return $target;
        $parentId = (int)($device['ConnectionID'] ?? 0);
        $target['status'] = 'connection_inactive';
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)
            || (int)(IPS_GetInstance($parentId)['InstanceStatus'] ?? 0) !== 102) return $target;
        $target['ready'] = true;
        $target['status'] = 'ready';
        return $target;
    }

    public function GetEchoRemoteStatus(): string
    {
        try {
            $status = $this->echoRemoteTarget();
        } catch (Throwable $e) {
            $status = ['ready' => false, 'status' => 'configuration_error'];
        }
        $status['lastResult'] = $this->ReadAttributeString('EchoRemoteLastResult');
        return json_encode($status, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function sendEchoRemote(string $text, int $override, bool $announcement): bool
    {
        $status = 'configuration_error';
        try {
            $target = $this->echoRemoteTarget($override);
            $status = $target['status'];
            if (!$target['ready']) return false;
            $text = trim((string)preg_replace('/[\x00-\x1f\x7f]/', ' ', strip_tags($text)));
            if ($text === '' || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 1000) {
                $status = 'text_invalid';
                return false;
            }
            $function = 'ECHOREMOTE_' . ($announcement ? 'Announcement' : 'TextToSpeech')
                . ($target['allDevices'] ? 'ToAll' : '');
            if (!function_exists($function)) {
                $status = 'function_missing';
                return false;
            }
            // Announcement wraps its argument in <speak> in Echo Remote 2.
            if ($announcement) $text = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $status = 'send_failed';
            $sent = $function($target['deviceId'], $text) === true;
            $status = $sent ? 'accepted' : 'rejected';
            return $sent;
        } catch (Throwable $e) {
            // Provider exceptions may contain account data. Never log their text.
            return false;
        } finally {
            $this->WriteAttributeString('EchoRemoteLastResult', $status);
        }
    }

    public function TestEchoRemote(bool $announcement): void
    {
        $sent = $announcement
            ? $this->Announce('Dies ist eine Testdurchsage der Haussteuerung.')
            : $this->SpeakText('Dies ist eine Testansage der Haussteuerung.');
        $notice = $sent ? 'Echo Remote 2 hat die Testausgabe angenommen.'
            : 'Testausgabe fehlgeschlagen: ' . $this->ReadAttributeString('EchoRemoteLastResult');
        $this->UpdateFormField('EchoRemoteNotice', 'caption', $notice);
        echo $notice;
    }

    private function buildEchoRemotePanel(): array
    {
        return ['type' => 'ExpansionPanel', 'caption' => 'ECHO REMOTE 2 – Zentrale Ansagen und Durchsagen',
            'expanded' => false, 'items' => [
                ['type' => 'Label', 'caption' => 'Vorhandene Echo-Remote-Geräteinstanz auswählen. Mit „Alle Geräte“ erfolgt die Ausgabe auf allen Echo-Geräten desselben Amazon-Kontos. Änderungen übernehmen, dann testen. Der Alexa-Skill muss für diese Ausgabe nicht geöffnet sein.'],
                ['type' => 'CheckBox', 'name' => 'EchoRemoteEnabled', 'caption' => 'Zentrale Echo-Ausgabe aktivieren'],
                ['type' => 'SelectInstance', 'name' => 'EchoRemoteDeviceId', 'caption' => 'Echo-Remote-Geräteinstanz (Ausgangsinstanz)',
                    'moduleID' => self::ECHO_REMOTE_DEVICE_MODULE],
                ['type' => 'CheckBox', 'name' => 'EchoRemoteAllDevices', 'caption' => 'Auf allen Geräten des verbundenen Amazon-Kontos ausgeben'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Testdurchsage senden', 'onClick' => 'IAH_TestEchoRemote($id, true);'],
                    ['type' => 'Button', 'caption' => 'Test-Sprachausgabe senden', 'onClick' => 'IAH_TestEchoRemote($id, false);'],
                ]],
                ['type' => 'Label', 'name' => 'EchoRemoteNotice', 'caption' => 'Letztes Sendeergebnis: ' . $this->ReadAttributeString('EchoRemoteLastResult')],
                ['type' => 'Label', 'caption' => 'Zentral aus Skripten: IAH_Announce($alexaInstanz, "Hallo", 0); oder IAH_SpeakText($alexaInstanz, "Die Wäsche ist fertig.", 0); Ein konkretes Echo kann als letzter Parameter übergeben werden. Automatische Meldungen und eigene Verknüpfungen stehen im Bereich AUTOMATISCHE DURCHSAGEN.'],
            ]];
    }
}
