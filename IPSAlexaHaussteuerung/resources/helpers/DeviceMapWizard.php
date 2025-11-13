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
 */

declare(strict_types=1);

use IPSAlexaHaussteuerung\AskResponse;
use IPSAlexaHaussteuerung\TellResponse;

/**
 * Führt den Wizard-Flow aus und gibt ggf. eine Response zurück.
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
    $pendingStageVar  = (int)($V['PENDING_STAGE']  ?? 0);
    $pendingDeviceVar = (int)($V['PENDING_DEVICE'] ?? 0);
    $deviceMapVar     = (int)($V['DEVICE_MAP']     ?? 0);

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

    $buildAskResponse = static function (string $text, ?string $reprompt = null) use ($logWizardError) {
        try {
            $ask = AskResponse::CreatePlainText($text);
            if ($reprompt !== null) {
                $ask->SetRepromptPlainText($reprompt);
            }
            return $ask;
        } catch (\Throwable $e) {
            $logWizardError('Failed to build AskResponse', [
                'text' => $text,
                'reprompt' => $reprompt,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    };

    $formatCreated = static function (int $ts): string {
        $tz = new \DateTimeZone('Europe/Vienna');
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $wd = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
        return $wd[(int)$dt->format('w')] . ', ' . $dt->format('d.m.Y, H:i:s');
    };

    $sanitizeSpeechName = static function (string $value): string {
        $value = str_replace(["\r", "\n"], ' ', $value);
        $value = preg_replace('/["“”„]+/u', ' ', $value);
        $value = preg_replace('/\s{2,}/u', ' ', $value);
        return trim((string)$value);
    };

    $normalizePlainText = static function (string $value): string {
        $map = [
            "\u{00A0}" => ' ',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201A}" => ',',
            "\u{201C}" => '"',
            "\u{201D}" => '"',
            "\u{201E}" => '"',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[\r\n]+/u', ' ', $value);
        $value = preg_replace('/\s{2,}/u', ' ', $value);
        return trim((string)$value);
    };

    $codepointOfChar = static function (string $char): string {
        $converted = mb_convert_encoding($char, 'UCS-4BE', 'UTF-8');
        $data = unpack('N', $converted === false ? '' : $converted);
        $code = (int)($data[1] ?? 0);
        return sprintf('U+%04X', $code);
    };

    $detectUnsupportedQuestionChars = static function (string $value) use ($codepointOfChar): array {
        if ($value === '') {
            return [];
        }
        if (!preg_match_all('/[\x{2000}-\x{206F}]/u', $value, $matches)) {
            return [];
        }
        $seen = [];
        foreach ($matches[0] as $char) {
            $seen[$char] = true;
        }
        $result = [];
        foreach (array_keys($seen) as $char) {
            $result[] = [
                'char' => $char,
                'codepoint' => $codepointOfChar($char),
                'hex' => strtoupper(bin2hex($char)),
            ];
        }
        return $result;
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

    // Missing IDs → Fehler
    if ($pendingStageVar <= 0 || $pendingDeviceVar <= 0 || $deviceMapVar <= 0) {
        $logWizardError('Missing helper ids', [
            'pendingStageVar'  => $pendingStageVar,
            'pendingDeviceVar' => $pendingDeviceVar,
            'deviceMapVar'     => $deviceMapVar,
        ]);
        $resetWizard();
        return TellResponse::CreatePlainText('Geräte-Assistent konnte nicht gestartet werden. Bitte prüfe die Konfiguration.');
    }

    $pendingStage = (string)GetValueString($pendingStageVar);
    $pendingDevId = (string)GetValueString($pendingDeviceVar);

    $abortWords = ['zurück','exit','abbrechen','ende','fertig'];

    if ($pendingStage === '' || $pendingDevId === '') {
        $logWizardDebug('No active wizard, skip.', [
            'pendingStage' => $pendingStage,
            'pendingDeviceId' => $pendingDevId,
        ]);
        return null;
    }

    // -----------------------
    // STAGE 1 — NAME
    // -----------------------
    if ($pendingStage === $STAGE_AWAIT_NAME) {

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
            $ask = AskResponse::CreatePlainText('Wie soll ich das Gerät nennen?');
            $ask->SetRepromptPlainText('Sag z. B. Küche, Wohnzimmer oder Büro.');
            return $ask;
        }

        $speechName = $sanitizeSpeechName($proposed);

        $questionText = $speechName !== ''
            ? 'Alles klar - "' . $speechName . '". Hat dieses Gerät einen Bildschirm?'
            : 'Alles klar, Name gespeichert. Hat dieses Gerät einen Bildschirm?';

        $questionText = $normalizePlainText($questionText);

        $unsupported = $detectUnsupportedQuestionChars($questionText);
        if ($unsupported !== []) {
            $questionText = $normalizePlainText('Hat dieses Gerät einen Bildschirm?');
        }

        // speichern
        $updateLocation = $DM_HELPERS['update_location'] ?? null;
        if (!is_callable($updateLocation)) {
            $updateLocation = $fallbackUpdateLocation;
        }

        try {
            $updateLocation($deviceMapVar, $pendingDevId, $proposed);
        } catch (\Throwable $e) {
            $resetWizard();
            return TellResponse::CreatePlainText('Gerät konnte nicht gespeichert werden. Bitte versuche es noch einmal.');
        }

        SetValueString($pendingStageVar, $STAGE_AWAIT_APL);

        $ask = $buildAskResponse($questionText, 'Bitte antworte mit ja oder nein.');
        if ($ask === null) {
            $ask = $buildAskResponse('Hat dieses Gerät einen Bildschirm?', 'Bitte antworte mit ja oder nein.');
        }
        if ($ask === null) {
            $resetWizard();
            return TellResponse::CreatePlainText('Gerät konnte nicht gespeichert werden. Bitte versuche es noch einmal.');
        }

        return $ask;
    }

    // -----------------------
    // STAGE 2 — APL JA/NEIN
    // -----------------------
    if ($pendingStage === $STAGE_AWAIT_APL) {

        $yesIntents = ['AMAZON.YesIntent','YesIntent'];
        $noIntents  = ['AMAZON.NoIntent','NoIntent'];

        $isYes = in_array($intentName, $yesIntents, true)
              || $action === 'ja' || $alles === 'ja' || $room === 'ja';

        $isNo  = in_array($intentName, $noIntents, true)
              || $action === 'nein' || $alles === 'nein' || $room === 'nein';

        if (!$isYes && !$isNo) {
            $ask = AskResponse::CreatePlainText('Hat das Gerät einen Bildschirm?');
            $ask->SetRepromptPlainText('Ja oder nein?');
            return $ask;
        }

        $apl = $isYes;

        $updateApl = $DM_HELPERS['update_apl'] ?? null;
        if (!is_callable($updateApl)) {
            $updateApl = $fallbackUpdateApl;
        }

        try {
            $updateApl($deviceMapVar, $pendingDevId, $apl);
        } catch (\Throwable $e) {
            $resetWizard();
            return TellResponse::CreatePlainText('Die Bildschirm-Einstellung konnte nicht gespeichert werden.');
        }

        $resetWizard();
        return TellResponse::CreatePlainText(
            'Danke, Einstellungen gespeichert. ' . ($apl ? 'Bildschirm erkannt' : 'Kein Bildschirm')
        );
    }

    // UNBEKANNTE STAGE
    $resetWizard();
    return TellResponse::CreatePlainText('Assistent zurückgesetzt.');
};

return [
    'handle_wizard' => $handle_wizard,
];
