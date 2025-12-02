<?php
/**
 * ============================================================
 * ALEXA ACTION SCRIPT — HELFER: CORE FUNCTIONS
 * ============================================================
 *
 * Dieses Skript wird vom Haupt-Action-Skript (Execute) per require geladen.
 * Es gibt ein Array von allgemeinen Hilfsfunktionen zurück.
 *
 * Es muss in der $CFG['var']-Variable des Hauptskripts
 * als 'CoreHelpers' mit seiner ID registriert werden.
 *
 * Änderungsverlauf
 * 2025-11-11: Erweiterung
 * - Zusätzliche Helper integriert: getSlot, parseAplArgs, room_key_by_spoken,
 *   domainFromAplArgs, buildTabsCache (static Cache), findTabId, fallbackTabDomain,
 *   extractNumberOnly, maybeMergeDecimalFromPercent
 */

declare(strict_types=1);

$JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

$applyApl = static function ($r, array $apl, string $fallbackToken) {
    if (empty($apl)) return;
    $token = (string)($apl['token'] ?? $fallbackToken);
    if (!empty($apl['doc']) && !empty($apl['ds'])) {
        if (method_exists($r, 'AddAPLRenderDocument')) {
            $r->AddAPLRenderDocument($apl['doc'], $apl['ds'], $token);
        } else {
            $r->AddDirective([
                'type'        => 'Alexa.Presentation.APL.RenderDocument',
                'token'       => $token,
                'document'    => $apl['doc'],
                'datasources' => $apl['ds'],
            ]);
        }
    }
    if (!empty($apl['commands']) && is_array($apl['commands'])) {
        if (method_exists($r, 'AddAPLExecuteCommands')) {
            $r->AddAPLExecuteCommands($apl['commands'], $token);
        } else {
            $r->AddDirective([
                'type'     => 'Alexa.Presentation.APL.ExecuteCommands',
                'token'    => $token,
                'commands' => $apl['commands'],
            ]);
        }
    }
};

$makeCard = static function ($r, array $c): void {
    $title = (string)($c['title'] ?? '');
    $text  = (string)($c['text'] ?? '');
    $small = (string)($c['smallImageUrl'] ?? '');
    $large = (string)($c['largeImageUrl'] ?? $small);
    if ($title !== '' || $text !== '') {
        $r->SetStandardCard($title, $text, $small, $large)->SetRepromptPlainText(' ');
    }
};

$maskToken = static fn(string $s) => $s;

$matchDomain = static function (string $action, string $device, string $alles, string $room): ?string {
    if ($action === 'heizung' || $device === 'temperatur' || $device === 'heizung' ||
        ($alles === 'grad' && $action === 'stellen' && mb_strpos($device, 'temperatur', 0, 'UTF-8') !== false && $room !== '')
    ) return 'heizung';
    if ($action === 'geräte' || $device === 'geräte' || $device === 'gerät') return 'geraete';
    if ($action === 'bewässerung' || $action === 'garten' || $device === 'bewässerung' || $device === 'garten') return 'bewaesserung';

    $jalSyn      = ['jalousie','jalousien','rollo','rollladen','rollläden','raffstore','beschattung'];
    $lightSyn    = ['licht','beleuchtung'];
    $ventSyn     = ['lüftung','lueftung','ventilation','lüfter','luefter'];
    $geratSyn    = ['geräte','geraete','gerät'];
    $bewSyn      = ['bewaesserung','bewässerung','garten'];
    $settingsSyn = ['einstellung','einstellungen','settings'];

    if (in_array($action,$jalSyn,true)   || in_array($device,$jalSyn,true)   || in_array($alles,$jalSyn,true))   return 'jalousie';
    if (in_array($action,$lightSyn,true) || in_array($device,$lightSyn,true) || in_array($alles,$lightSyn,true)) return 'licht';
    if (in_array($action,$ventSyn,true)  || in_array($device,$ventSyn,true)  || in_array($alles,$ventSyn,true) || in_array($room,$ventSyn,true)) return 'lueftung';
    if (in_array($action,$geratSyn,true) || in_array($device,$geratSyn,true) || in_array($alles,$geratSyn,true) || in_array($room,$geratSyn,true)) return 'geraete';
    if (in_array($action,$bewSyn,true)   || in_array($device,$bewSyn,true)   || in_array($alles,$bewSyn,true) || in_array($room,$bewSyn,true)) return 'bewaesserung';
    if (in_array($action,$settingsSyn,true) || in_array($device,$settingsSyn,true) || in_array($alles,$settingsSyn,true)) return 'einstellungen';
    return null;
};

$resetState = static function (array $V): void {
    SetValueString($V['ROOM_SAVE'], "");
    SetValueString($V['DEVICE_SAVE'], "");
    SetValueString($V['DOMAIN_FLAG'], "");
};

$getDomainPref = static function (array $V): string {
    $d = mb_strtolower(trim(GetValueString($V['DOMAIN_FLAG'] ?? 0)), 'UTF-8');
    if (in_array($d, ['jalousien','rollladen','rollläden','raffstore','beschattung'], true)) $d = 'jalousie';
    return $d;
};

$mkCid = static function(): string { return substr(hash('crc32b', microtime(true) . mt_rand()), 0, 8); };

$logExt = static function (string $cid, string $msg, array $ctx = []) use ($maskToken, $JSON) {
    foreach ($ctx as $k => $v) { if (is_string($v)) { $ctx[$k] = $maskToken($v); } }
    IPS_LogMessage('Alexa', 'EXTNAV[' . $cid . '] ' . $msg . ' ' . json_encode($ctx, $JSON));
};

// ===== Neue generische Helfer zum Auslagern aus Execute() =====

$getSlot = static function($request, string $name) {
    return isset($request->slots->$name) ? $request->slots->$name : null;
};

$parseAplArgs = static function($request): array {
    $args = [];
    $isLaunch = method_exists($request, 'IsLaunchRequest') ? $request->IsLaunchRequest() : false;
    if (!$isLaunch && method_exists($request, 'GetAplArguments')) {
        try { $args = $request->GetAplArguments(); } catch (Throwable $e) { $args = []; }
    }
    if (!$isLaunch && empty($args) && isset($request->attributes) && method_exists($request->attributes, 'Get')) {
        $args = $request->attributes->Get('APL_ARGS') ?: [];
    }
    if (!is_array($args)) {
        if ($args instanceof Traversable) {
            $args = iterator_to_array($args);
        } elseif (is_object($args)) {
            $args = get_object_vars($args);
        } else {
            $args = [];
        }
    }
    return [
        'args' => $args,
        'a1'   => $args[1] ?? null,
        'a2'   => $args[2] ?? null,
        'a3'   => $args[3] ?? null,
    ];
};

$room_key_by_spoken = static function(array $ROOMS, ?string $spoken, callable $token_norm): ?string {
    $ROOM_INDEX = [];
    foreach ($ROOMS as $key => $def) {
        $words = array_unique(array_filter(array_merge([$key, (string)($def['display'] ?? $key)], (array)($def['synonyms'] ?? []))));
        foreach ($words as $w) $ROOM_INDEX[$token_norm($w)] = $key;
    }
    $t = $token_norm((string)$spoken);
    if ($t === '') return null;
    foreach (['kinder_gross','kinder_klein'] as $prefer) {
        if (!isset($ROOMS[$prefer])) continue;
        foreach (($ROOMS[$prefer]['synonyms'] ?? []) as $syn) {
            $s = $token_norm($syn);
            if ($s !== '' && preg_match('/(^|\s)'.preg_quote($s,'/').'(\s|$)/u', ' '.$t.' ')) return $prefer;
        }
    }
    if (isset($ROOM_INDEX[$t])) return $ROOM_INDEX[$t];
    foreach ($ROOMS as $key => $def) {
        if ($token_norm($key) === $t) return $key;
        if ($token_norm($def['display'] ?? $key) === $t) return $key;
        foreach (($def['synonyms'] ?? []) as $syn) if ($token_norm($syn) === $t) return $key;
    }
    foreach ($ROOM_INDEX as $needle => $key) {
        if ($needle !== '' && preg_match('/(^|\s)'.preg_quote($needle,'/').'(\s|$)/u', ' '.$t.' ')) return $key;
    }
    return null;
};

$domainFromAplArgs = static function(array $APL, callable $lc): ?string {
    foreach ([$APL['a1'] ?? null, $APL['a2'] ?? null, $APL['a3'] ?? null] as $as) {
        if (!is_string($as) || $as === '') continue;
        $lower = $lc($as);
        if (preg_match('/^(jalousie|licht|lueftung|heizung|temperatur|geraete|bewaesserung)\./u', $lower, $m)) {
            return ($m[1] === 'temperatur' || $m[1] === 'heizung') ? 'heizung' : $m[1];
        }
    }
    return null;
};

$buildTabsCache = static function(array $ROOMS): array {
    static $DEVICE_TABS_CACHE = null;
    if ($DEVICE_TABS_CACHE !== null) return $DEVICE_TABS_CACHE;
    $DEVICE_TABS_CACHE = [];

    $addTabs = static function($set) use (&$DEVICE_TABS_CACHE) {
        if (!is_array($set)) return;
        foreach ($set as $title => $def) {
            if (is_int($def) || is_string($def)) {
                $DEVICE_TABS_CACHE[] = ['id'=>(string)$def, 'title'=>(string)$title, 'order'=>9999, 'syn'=>[]];
                continue;
            }
            if (is_array($def)) {
                $id = $def['id'] ?? $def['nodeId'] ?? $def['var'] ?? $def['value'] ?? $def['tabId'] ?? null;
                if ($id !== null) {
                    $DEVICE_TABS_CACHE[] = [
                        'id'    => (string)$id,
                        'title' => (string)$title,
                        'order' => (int)($def['order'] ?? 9999),
                        'syn'   => array_values((array)($def['synonyms'] ?? []))
                    ];
                }
            }
        }
    };

    $globalSets = [
        $ROOMS['global']['devices']['tabs'] ?? null,
        $ROOMS['global']['geraete']['tabs'] ?? null,
        $ROOMS['global']['sprinkler']['tabs'] ?? null,
        $ROOMS['global']['bewaesserung']['tabs'] ?? null,
        $ROOMS['global']['devices_tabs'] ?? null,
        $ROOMS['global']['geraete_tabs'] ?? null,
        $ROOMS['global']['sprinkler_tabs'] ?? null,
        $ROOMS['global']['bewaesserung_tabs'] ?? null,
    ];
    foreach ($globalSets as $set) $addTabs($set);

    foreach ($ROOMS as $roomKey => $roomDef) {
        if ($roomKey === 'global') continue;
        $addTabs($roomDef['domains']['devices']['tabs'] ?? null);
        $domains = $roomDef['domains'] ?? [];
        if (is_array($domains)) {
            foreach ($domains as $domVal) {
                if (!is_array($domVal)) continue;
                $addTabs($domVal['sprinkler']['tabs'] ?? null);
            }
        }
    }

    usort($DEVICE_TABS_CACHE, static function($a,$b){
        $oa=(int)($a['order']??9999); $ob=(int)($b['order']??9999);
        if($oa!==$ob) return $oa<=>$ob;
        return strcasecmp((string)$a['title'], (string)$b['title']);
    });

    return $DEVICE_TABS_CACHE;
};

$findTabId = static function(string $spoken, array $cache, callable $token_norm): ?string {
    $spoken = trim($spoken);
    if ($spoken === '') return null;
    $norm = $token_norm($spoken);
    foreach ($cache as $t) {
        $titleN = $token_norm($t['title'] ?? '');
        if ($titleN === $norm || ($titleN!=='' && (str_contains($titleN,$norm)||str_contains($norm,$titleN)))) return (string)$t['id'];
        foreach ((array)($t['syn'] ?? []) as $syn) {
            $sn = $token_norm($syn);
            if ($sn === $norm || ($sn!=='' && (str_contains($sn,$norm)||str_contains($norm,$sn)))) return (string)$t['id'];
        }
    }
    return null;
};

$fallbackTabDomain = static function(
    &$domain, &$device, string $domainName,
    string $spokenAction, string $spokenDevice, string $spokenObject, string $spokenAlles,
    callable $findTabIdWithCache, callable $logger
) use ($JSON) {
    if ($domain !== null) return false;
    $candidates = [];
    if ($spokenAction !== '') $candidates[] = $spokenAction;
    if ($spokenDevice !== '' && !in_array($spokenDevice, [$domainName,$domainName.'e','gerät','geräte'], true)) $candidates[] = $spokenDevice;
    if ($spokenObject !== '') $candidates[] = $spokenObject;
    if ($spokenAlles  !== '') $candidates[] = $spokenAlles;
    foreach ($candidates as $cand) {
        $cand = trim($cand);
        if ($cand === '') continue;
        $tabId = $findTabIdWithCache($cand);
        if ($tabId !== null) {
            $domain = $domainName;
            if ($device === '') $device = $domainName;
            $GLOBALS['_APL_OVERRIDE'] = [$domainName.'.tab', (string)$tabId];
            $logger('debug','GlobalVoiceTab '.json_encode(['spoken'=>$cand, 'tabId'=>$tabId, 'domain'=>$domainName], $JSON));
            return true;
        }
    }
    return false;
};

$extractNumberOnly = static function (
    $action, $device, $alles, $room, $object, $szene, $number,
    callable $normalize_decimal_words, array $PAT
) use ($JSON) {
    $numberFloat = null;
    if ($number !== '' && $number !== '?' && $number !== null) {
        $numRaw = trim((string)$number);
        $numberDisplay = str_replace('.', ',', $numRaw);
        $numberFloat   = (float)str_replace(',', '.', $numRaw);
        IPS_LogMessage('Alexa', 'NUM EXTRACT — (slot) number=' . json_encode($numberDisplay, $JSON) . ' float=' . $numberFloat);
        return [$numberDisplay, $numberFloat];
    }
    $haystack = mb_strtolower(trim(($action ?? '') . ' ' . ($alles ?? '') . ' ' . ($device ?? '') . ' ' . ($room ?? '') . ' ' . ($object ?? '') . ' ' . ($szene ?? '')), 'UTF-8');
    $haystack = $normalize_decimal_words($haystack);
    if (preg_match($PAT['temp_number1'],$haystack,$m) || preg_match($PAT['temp_number2'],$haystack,$m) || preg_match($PAT['plain_number'],$haystack,$m)) {
        $numRaw=$m[1]; $numberFloat=(float)str_replace(',', '.', $numRaw); $numberDisplay=str_replace('.', ',', $numRaw);
        IPS_LogMessage('Alexa', 'NUM EXTRACT — number=' . json_encode($numberDisplay, $JSON) . ' float=' . $numberFloat);
        return [$numberDisplay, $numberFloat];
    }
    return [null, null];
};

$maybeMergeDecimalFromPercent = static function ($numberStr, $prozentStr, $action, $device, $alles) {
    if ($numberStr === null || $numberStr === '' || $numberStr === '?') return [$numberStr, $prozentStr];
    if (!preg_match('/^\d$/u', (string)$prozentStr)) return [$numberStr, $prozentStr];
    $hay = mb_strtolower(trim(($action ?? '') . ' ' . ($device ?? '') . ' ' . ($alles ?? '')), 'UTF-8');
    $isTempCtx           = (mb_strpos($hay, 'grad', 0, 'UTF-8') !== false) || (mb_strpos($hay, 'temperatur', 0, 'UTF-8') !== false);
    $mentionsPercentWord = (mb_strpos($hay, 'prozent', 0, 'UTF-8') !== false);
    if ($isTempCtx && !$mentionsPercentWord && strpos((string)$numberStr, ',') === false) {
        $merged = str_replace('.', ',', (string)$numberStr) . ',' . (string)$prozentStr;
        return [$merged, null];
    }
    return [$numberStr, $prozentStr];
};

return [
    'applyApl'                    => $applyApl,
    'makeCard'                    => $makeCard,
    'maskToken'                   => $maskToken,
    'matchDomain'                 => $matchDomain,
    'resetState'                  => $resetState,
    'getDomainPref'               => $getDomainPref,
    'mkCid'                       => $mkCid,
    'logExt'                      => $logExt,

    'getSlot'                     => $getSlot,
    'parseAplArgs'                => $parseAplArgs,
    'room_key_by_spoken'          => $room_key_by_spoken,
    'domainFromAplArgs'           => $domainFromAplArgs,

    'buildTabsCache'              => $buildTabsCache,
    'findTabId'                   => $findTabId,
    'fallbackTabDomain'           => $fallbackTabDomain,

    'extractNumberOnly'           => $extractNumberOnly,
    'maybeMergeDecimalFromPercent'=> $maybeMergeDecimalFromPercent,
];
