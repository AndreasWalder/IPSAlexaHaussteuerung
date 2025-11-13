<?php
declare(strict_types=1);

/**
 * ============================================================
 * WFC-Seite verzögert schalten (Parameter aus IPS_RunScriptEx)
 * ============================================================
 *
 * Dieses Skript dient als Vorlage, um einen beliebigen WebFront-Client
 * nach einer kurzen Wartezeit (10 Sekunden) automatisch auf eine andere
 * Seite zu schalten. Es merkt sich die per IPS_RunScriptEx übergebenen
 * Parameter `page` und `wfc` in einer String-Variable und führt den
 * eigentlichen Seitenwechsel im Timer-Event aus.
 *
 * Verwendung:
 *   IPS_RunScriptEx($scriptId, [
 *       'page' => 'pageId',   // z. B. "page.Shutdown"
 *       'wfc'  => 12345       // Instanz-ID deines WebFront Controllers
 *   ]);
 *
 * Änderungsverlauf
 * 2025-11-09: Erste Version – page/wfc per RunScriptEx übernehmen,
 *             JSON puffern, nach 10 s WFC_SwitchPage ausführen.
 */

const PARAM_IDENT = 'wfc_page_switch_params';

if ($_IPS['SENDER'] !== 'TimerEvent') {
    // --- Initialaufruf via IPS_RunScriptEx: Parameter entgegennehmen und puffern ---
    $page = (string)($_IPS['page'] ?? '');
    $wfc  = (int)($_IPS['wfc'] ?? 0);

    if ($page === '' || $wfc === 0) {
        IPS_LogMessage('WFC-DelaySwitch', 'Fehlende Parameter: page oder wfc.');
        return;
    }

    $varId = getOrCreateParamVar((int)$_IPS['SELF']);
    SetValueString($varId, json_encode(['page' => $page, 'wfc' => $wfc], JSON_UNESCAPED_SLASHES));

    IPS_SetScriptTimer($_IPS['SELF'], 10); // in 10 s ausführen
    return;
}

// --- TimerEvent: Parameter lesen und Seite schalten ---
$varId = getOrCreateParamVar((int)$_IPS['SELF']);
$raw   = @GetValueString($varId);
$data  = json_decode($raw, true);

$page = (string)($data['page'] ?? '');
$wfc  = (int)($data['wfc'] ?? 0);

if ($page !== '' && $wfc !== 0 && IPS_InstanceExists($wfc)) {
    WFC_SwitchPage($wfc, $page);
} else {
    IPS_LogMessage('WFC-DelaySwitch', 'Ungültige/fehlende Parameter im Timer: ' . ($raw ?? 'null'));
}

IPS_SetScriptTimer($_IPS['SELF'], 0); // Timer wieder aus

// ------------------------------------------------------------

function getOrCreateParamVar(int $selfId): int
{
    $parentId = IPS_GetParent($selfId); // gleiche Kategorie wie das Script
    $varId = @IPS_GetObjectIDByIdent(PARAM_IDENT, $parentId);
    if (!$varId) {
        $varId = IPS_CreateVariable(VARIABLETYPE_STRING);
        IPS_SetParent($varId, $parentId);
        IPS_SetName($varId, 'WFC PageSwitch Params');
        IPS_SetIdent($varId, PARAM_IDENT);
    }
    return $varId;
}
