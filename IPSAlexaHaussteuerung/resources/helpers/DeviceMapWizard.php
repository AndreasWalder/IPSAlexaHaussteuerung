<?php
/**
 * ============================================================
 * ALEXA ACTION SCRIPT — HELFER: DEVICE-MAP WIZARD
 * ============================================================
 *
 * Dieses Skript kapselt den Dialog-Flow für den Device-Map-Wizard
 * (Name erfragen → APL-Support erfragen) und liefert eine einzige
 * Funktion `handle_wizard(...)`, die entweder eine Response (Ask/Tell)
 * zurückgibt oder `null`, wenn kein Wizard aktiv ist bzw. nichts zu tun ist.
 *
 * Verwendung im Action-Script:
 *   $WZ = require IPS_GetScriptFile((int)$V['DeviceMapWizard']);
 *   $resp = $WZ['handle_wizard'](
 *       $V, $intentName, $action, $alles, $room, $device,
 *       $DM_HELPERS, $STAGE_AWAIT_NAME, $STAGE_AWAIT_APL
 *   );
 *   if ($resp !== null) { return $resp; }
 */

declare(strict_types=1);

/**
 * Führt den Wizard-Flow aus und gibt ggf. eine Response zurück.
 *
 * @param array  $V                  SystemConfiguration['var']
 * @param string $intentName         Aktueller Intent-Name
 * @param string $action             Slot 'Action' (normalisiert)
 * @param string $alles              Slot 'Alles'  (normalisiert)
 * @param string $room               Slot 'Room'   (normalisiert)
 * @param string $device             Slot 'Device' (normalisiert)
 * @param array  $DM_HELPERS         DeviceMap-Helper (ensure/update/save/...)
 * @param string $STAGE_AWAIT_NAME   Konstantenwert für Name-Stufe
 * @param string $STAGE_AWAIT_APL    Konstantenwert für APL-Stufe
 *
 * @return mixed AskResponse|TellResponse|null
 */
$handle_wizard = static function(
    array  $V,
    string $intentName,
    string $action,
    string $alles,
    string $room,
    string $device,
    array  $DM_HELPERS,
    string $STAGE_AWAIT_NAME,
    string $STAGE_AWAIT_APL
) {
    $pendingStageVar = (int)($V['PENDING_STAGE'] ?? 0);
    $pendingDeviceVar = (int)($V['PENDING_DEVICE'] ?? 0);
    $deviceMapVar = (int)($V['DEVICE_MAP'] ?? 0);

    $resetWizard = static function () use ($pendingStageVar, $pendingDeviceVar): void {
        if ($pendingStageVar > 0) {
            SetValueString($pendingStageVar, '');
        }
        if ($pendingDeviceVar > 0) {
            SetValueString($pendingDeviceVar, '');
        }
    };

    $logWizard = static function (string $level, string $message, array $context = []): void {
        if (!empty($context)) {
            $message .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        IPS_LogMessage('Alexa', 'DeviceMapWizard[' . $level . ']: ' . $message);
    };

    $logWizardError = static function (string $message, array $context = []) use ($logWizard): void {
        $logWizard('ERROR', $message, $context);
    };

    $logWizardDebug = static function (string $message, array $context = []) use ($logWizard): void {
        $logWizard('DEBUG', $message, $context);
    };

    $formatCreated = static function (int $ts): string {
        $tz = new \DateTimeZone('Europe/Vienna');
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $wd = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
        return $wd[(int)$dt->format('w')] . ', ' . $dt->format('d.m.Y, H:i:s');
    };

    $fallbackUpdateEntry = static function (int $varId, string $deviceId, callable $mutator) use ($formatCreated): void {
        $raw = (string)GetValueString($varId);
        $map = json_decode($raw !== '' ? $raw : '[]', true);
        if (!is_array($map)) {
            $map = [];
        }
        if (!isset($map[$deviceId]) || !is_array($map[$deviceId])) {
            $map[$deviceId] = [
                'location' => '',
                'apl'      => false,
                'isNew'    => true,
                'created'  => $formatCreated(time()),
            ];
        }
        $entry = $map[$deviceId];
        $mutator($entry);
        $entry['isNew'] = false;
        $map[$deviceId] = $entry;
        SetValueString($varId, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    };

    $fallbackUpdateLocation = static function (int $varId, string $deviceId, string $location) use ($fallbackUpdateEntry): void {
        $fallbackUpdateEntry($varId, $deviceId, static function (array &$entry) use ($location): void {
            $entry['location'] = $location;
        });
    };

    $fallbackUpdateApl = static function (int $varId, string $deviceId, bool $apl) use ($fallbackUpdateEntry): void {
        $fallbackUpdateEntry($varId, $deviceId, static function (array &$entry) use ($apl): void {
            $entry['apl'] = $apl;
        });
    };

    if ($pendingStageVar <= 0 || $pendingDeviceVar <= 0 || $deviceMapVar <= 0) {
        $logWizardError('Missing helper ids', [
            'pendingStageVar' => $pendingStageVar,
            'pendingDeviceVar' => $pendingDeviceVar,
            'deviceMapVar' => $deviceMapVar,
        ]);
        $resetWizard();
        return TellResponse::CreatePlainText('Geräte-Assistent konnte nicht gestartet werden. Bitte prüfe die Konfiguration.');
    }

    $pendingStage = (string)GetValueString($pendingStageVar);
    $pendingDevId = (string)GetValueString($pendingDeviceVar);
    $abortWords   = ['zurück','exit','abbrechen','ende','fertig'];

    if ($pendingStage === '' || $pendingDevId === '') {
        $logWizardDebug('No active wizard, skip.', [
            'pendingStage' => $pendingStage,
            'pendingDeviceId' => $pendingDevId,
        ]);
        return null; // kein aktiver Wizard
    }

    // --- STAGE: Name erfragen ---
    if ($pendingStage === $STAGE_AWAIT_NAME) {
        $logWizardDebug('Stage: await name', [
            'slots' => [
                'action' => $action,
                'alles'  => $alles,
                'room'   => $room,
                'device' => $device,
            ],
        ]);
        if (in_array($action, $abortWords, true)) {
            $resetWizard();
            return TellResponse::CreatePlainText('Okay, abgebrochen.');
        }

        $proposed = '';
        if ($room   !== '') $proposed = trim($room);
        elseif ($device !== '') $proposed = trim($device);
        elseif ($alles  !== '') $proposed = trim($alles);
        elseif ($action !== '') $proposed = trim($action);

        if ($proposed === '') {
            $logWizardDebug('No valid name provided. Asking again.');
            return AskResponse::CreatePlainText('Wie soll ich das Gerät nennen?')
                ->SetRepromptPlainText('Sag z. B. Küche, Wohnzimmer oder Büro.');
        }

        $logWizardDebug('Proposed device name collected.', [
            'name' => $proposed,
            'deviceId' => $pendingDevId,
        ]);

        // Speichern & zur APL-Frage wechseln
        $updateLocation = $DM_HELPERS['update_location'] ?? null;
        if (!is_callable($updateLocation)) {
            $logWizardError('Helper "update_location" fehlt – nutze Fallback.', ['helpers' => array_keys((array)$DM_HELPERS)]);
            $updateLocation = $fallbackUpdateLocation;
        }

        try {
            $logWizardDebug('Calling update_location.');
            $updateLocation($deviceMapVar, $pendingDevId, $proposed);
            $logWizardDebug('update_location successful.');
        } catch (\Throwable $e) {
            $logWizardError('update_location failed', ['exception' => $e->getMessage()]);
            $resetWizard();
            return TellResponse::CreatePlainText('Gerät konnte nicht gespeichert werden. Bitte versuche es noch einmal.');
        }

        SetValueString($pendingStageVar, $STAGE_AWAIT_APL);
        $logWizardDebug('Stage switched to await APL.');
        return AskResponse::CreatePlainText('Alles klar – "' . $proposed . '". Hat dieses Gerät einen Bildschirm?')
            ->SetRepromptPlainText('Bitte antworte mit ja oder nein.');
    }

    // --- STAGE: APL ja/nein ---
    if ($pendingStage === $STAGE_AWAIT_APL) {
        $logWizardDebug('Stage: await APL', [
            'intent' => $intentName,
            'slots' => [
                'action' => $action,
                'alles'  => $alles,
                'room'   => $room,
            ],
        ]);
        $yesIntents = ['AMAZON.YesIntent','YesIntent'];
        $noIntents  = ['AMAZON.NoIntent','NoIntent'];
        $isYes = in_array($intentName, $yesIntents, true) || $action === 'ja'   || $alles === 'ja'   || $room === 'ja';
        $isNo  = in_array($intentName,  $noIntents,  true) || $action === 'nein' || $alles === 'nein' || $room === 'nein';

        if (!$isYes && !$isNo) {
            $logWizardDebug('APL question unanswered yet.');
            return AskResponse::CreatePlainText('Hat das Gerät einen Bildschirm?')
                ->SetRepromptPlainText('Ja oder nein?');
        }

        $apl = $isYes;
        $updateApl = $DM_HELPERS['update_apl'] ?? null;
        if (!is_callable($updateApl)) {
            $logWizardError('Helper "update_apl" fehlt – nutze Fallback.', ['helpers' => array_keys((array)$DM_HELPERS)]);
            $updateApl = $fallbackUpdateApl;
        }

        try {
            $logWizardDebug('Calling update_apl.', ['apl' => $apl]);
            $updateApl($deviceMapVar, $pendingDevId, $apl);
            $logWizardDebug('update_apl successful.');
        } catch (\Throwable $e) {
            $logWizardError('update_apl failed', ['exception' => $e->getMessage()]);
            $resetWizard();
            return TellResponse::CreatePlainText('Die Bildschirm-Einstellung konnte nicht gespeichert werden.');
        }

        $resetWizard();
        $logWizardDebug('Wizard finished successfully.');
        $txt = $apl ? 'Bildschirm erkannt' : 'Kein Bildschirm';
        return TellResponse::CreatePlainText('Danke, Einstellungen gespeichert. ' . $txt);
    }

    // unbekannte Stage → aufräumen
    $resetWizard();
    return TellResponse::CreatePlainText('Assistent zurückgesetzt.');
};

return [
    'handle_wizard' => $handle_wizard,
];
