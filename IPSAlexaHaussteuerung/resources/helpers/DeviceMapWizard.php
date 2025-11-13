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
    $pendingStage = (string)GetValueString($V['PENDING_STAGE']);
    $pendingDevId = (string)GetValueString($V['PENDING_DEVICE']);
    $abortWords   = ['zurück','exit','abbrechen','ende','fertig'];

    if ($pendingStage === '' || $pendingDevId === '') {
        return null; // kein aktiver Wizard
    }

    // --- STAGE: Name erfragen ---
    if ($pendingStage === $STAGE_AWAIT_NAME) {
        if (in_array($action, $abortWords, true)) {
            SetValueString($V['PENDING_STAGE'], '');
            SetValueString($V['PENDING_DEVICE'], '');
            return TellResponse::CreatePlainText('Okay, abgebrochen.');
        }

        $proposed = '';
        if ($room   !== '') $proposed = trim($room);
        elseif ($device !== '') $proposed = trim($device);
        elseif ($alles  !== '') $proposed = trim($alles);

        if ($proposed === '') {
            return AskResponse::CreatePlainText('Wie soll ich das Gerät nennen?')
                ->SetRepromptPlainText('Sag z. B. Küche, Wohnzimmer oder Büro.');
        }

        // Speichern & zur APL-Frage wechseln
        $DM_HELPERS['update_location']((int)$V['DEVICE_MAP'], $pendingDevId, $proposed);
        SetValueString($V['PENDING_STAGE'], $STAGE_AWAIT_APL);
        return AskResponse::CreatePlainText('Alles klar – "' . $proposed . '". Hat dieses Gerät einen Bildschirm?')
            ->SetRepromptPlainText('Bitte antworte mit ja oder nein.');
    }

    // --- STAGE: APL ja/nein ---
    if ($pendingStage === $STAGE_AWAIT_APL) {
        $yesIntents = ['AMAZON.YesIntent','YesIntent'];
        $noIntents  = ['AMAZON.NoIntent','NoIntent'];
        $isYes = in_array($intentName, $yesIntents, true) || $action === 'ja'   || $alles === 'ja'   || $room === 'ja';
        $isNo  = in_array($intentName,  $noIntents,  true) || $action === 'nein' || $alles === 'nein' || $room === 'nein';

        if (!$isYes && !$isNo) {
            return AskResponse::CreatePlainText('Hat das Gerät einen Bildschirm?')
                ->SetRepromptPlainText('Ja oder nein?');
        }

        $apl = $isYes;
        $DM_HELPERS['update_apl']((int)$V['DEVICE_MAP'], $pendingDevId, $apl);
        SetValueString($V['PENDING_STAGE'], '');
        SetValueString($V['PENDING_DEVICE'], '');
        $txt = $apl ? 'Bildschirm erkannt' : 'Kein Bildschirm';
        return TellResponse::CreatePlainText('Danke, Einstellungen gespeichert. ' . $txt);
    }

    // unbekannte Stage → aufräumen
    SetValueString($V['PENDING_STAGE'], '');
    SetValueString($V['PENDING_DEVICE'], '');
    return TellResponse::CreatePlainText('Assistent zurückgesetzt.');
};

return [
    'handle_wizard' => $handle_wizard,
];
