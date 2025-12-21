<?php
/**
 * ============================================================
 * LÜFTUNG RENDERER (roomsCatalog-only, ohne Fallbacks)
 * ============================================================
 * - Nimmt Sprach-Slots & APL-Events entgegen (args1/args2/aplArgs)
 * - Liest Live-Werte & Toggle-IDs aus roomsCatalog
 * - Gruppiert nach Etagen (global.floors.*)
 * - Rendert APL-Datasource (rows)
 * - Aktionen nur, wenn explizit erlaubt (AE['lueftung']['toggle'] / kompatible Schlüssel)
 * - WICHTIG: Keine Fallbacks!
 *   • Kein „erstes Element“ auswählen, wenn Ziel unklar
 *   • Kein SetValue-Ersatz bei fehlgeschlagener RequestAction
 *   • Bei fehlender Variable → klare Sprachausgabe:
 *       "Schaltvorgang nicht möglich. Variable nicht vorhanden."
 *
 * Änderungsverlauf
 * 2025-10-29: Pure-Open-Erkennung für „lüftung“: nur APL, kein Fehlertext, sofortiges return.
 * 2025-10-29: Fallbacks entfernt, klare Fehlertexte bei fehlenden Variablen, nur noch RequestAction.
 */

$in = json_decode($_IPS['payload'] ?? '{}', true) ?: [];

/* =========================
   Flags / Logging
   ========================= */
$CFG   = is_array($in['CFG'] ?? null) ? $in['CFG'] : [];
$FLAGS = is_array($CFG['flags'] ?? null) ? $CFG['flags'] : [];
$AE    = is_array($in['ACTIONS_ENABLED'] ?? null) ? $in['ACTIONS_ENABLED'] : [];

$LOG_BASIC   = isset($in['logBasic'])   ? (bool)$in['logBasic']   : (isset($FLAGS['log_basic'])   ? (bool)$FLAGS['log_basic']   : true);
$LOG_VERBOSE = isset($in['logVerbose']) ? (bool)$in['logVerbose'] : (isset($FLAGS['log_verbose']) ? (bool)$FLAGS['log_verbose'] : true);

$LOG_TAG = 'Alexa';
$logB = static function(string $m) use($LOG_BASIC,$LOG_TAG){ if($LOG_BASIC)  IPS_LogMessage($LOG_TAG,$m); };
$logV = static function(string $m) use($LOG_VERBOSE,$LOG_TAG){ if($LOG_VERBOSE) IPS_LogMessage($LOG_TAG,$m); };

$RID = strtoupper(substr(hash('crc32b', microtime(true).mt_rand()),0,8));
$J   = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;

/* =========================
   Inputs (Slots / APL)
   ========================= */
$aplSupported = !empty($in['aplSupported']);
$action       = (string)($in['action'] ?? '');
$device       = (string)($in['device'] ?? '');
$roomSpoken   = (string)($in['room']   ?? '');
$object       = (string)($in['object'] ?? '');
$alles        = (string)($in['alles']  ?? '');
$number       = $in['number'] ?? null;
$args1        = $in['args1'] ?? null;
$args2        = $in['args2'] ?? null;
$aplArgs      = is_array($in['aplArgs'] ?? null) ? array_values($in['aplArgs']) : [];
$powerStr     = is_string($in['power'] ?? null) ? trim((string)$in['power']) : '';

$logV("[$RID] LueftungRenderer ENTER");
$logV("[$RID] INPUT ".json_encode([
  'aplSupported'=>$aplSupported,'action'=>$action,'device'=>$device,'room'=>$roomSpoken,'object'=>$object,'alles'=>$alles,
  'number'=>$number,'args1'=>$args1,'args2'=>$args2,'aplArgs'=>$aplArgs,'power'=>$powerStr,'AE'=>$AE
],$J));

/* =========================
   Allow toggle (NUR AE – ohne ??-Kaskaden)
   ========================= */
if (isset($AE['lueftung']['toggle'])) {
    $ALLOW_TOGGLE = (bool)$AE['lueftung']['toggle'];
} elseif (isset($AE['lueft_stellen'])) {
    $ALLOW_TOGGLE = (bool)$AE['lueft_stellen'];
} elseif (isset($AE['lueft']['stellen'])) {
    $ALLOW_TOGGLE = (bool)$AE['lueft']['stellen'];
} else {
    $ALLOW_TOGGLE = false;
}
$logV("[$RID] FLAGS allow_toggle=" . ($ALLOW_TOGGLE?1:0));

/* =========================
   Catalog (Source of truth)
   ========================= */
$CATALOG = is_array($in['roomsCatalog'] ?? null) ? $in['roomsCatalog'] : [];
if (empty($CATALOG)) {
    echo json_encode(['speech'=>'Konfiguration fehlt.','reprompt'=>'','apl'=>null,'endSession'=>false],$J);
    return;
}

/* =========================
   Icon-Hook (BaseUrl/Token)
   ========================= */
$V = is_array($CFG['var'] ?? null) ? $CFG['var'] : [];
$readVarValue = static function($entry){
    if (is_int($entry) && $entry>0){
        $s=@GetValueString($entry);
        if (is_string($s) && $s!=='') return trim($s);
        $m=@GetValue($entry);
        return is_scalar($m)?trim((string)$m):'';
    }
    if (is_string($entry)) return trim($entry);
    return '';
};
$baseUrl = trim((string)($CFG['baseUrl'] ?? ''));
$token   = trim((string)($CFG['token']   ?? ''));
if ($baseUrl==='' && isset($V['BaseUrl'])) $baseUrl = $readVarValue($V['BaseUrl']);
if ($token  ==='' && isset($V['Token']))   $token   = $readVarValue($V['Token']);

$iconUrl = static function(string $file,string $baseUrl,string $token): string {
    $path = IPS_GetKernelDir().'user'.DIRECTORY_SEPARATOR.'icons'.DIRECTORY_SEPARATOR.$file;
    $v = @filemtime($path) ?: 1;
    return rtrim($baseUrl,'/').'/hook/icons?f='.rawurlencode($file).'&token='.rawurlencode($token).'&v='.$v;
};
$resolveIcon = static function(?string $raw) use($baseUrl,$token,$iconUrl): ?string {
    $raw = trim((string)$raw);
    if ($raw==='') return null;
    if (preg_match('#^https?://#i',$raw)) return $raw;
    if ($baseUrl!=='' && $token!=='') return $iconUrl($raw,$baseUrl,$token);
    IPS_LogMessage('Lueftung',"Icon '{$raw}' ohne BaseUrl/Token nicht auflösbar.");
    return null;
};

/* =========================
   Floors (order/labels/ui)
   ========================= */
$GF = is_array($CATALOG['global']['domains']['floors'] ?? null)
    ? $CATALOG['global']['domains']['floors']
    : (is_array($CATALOG['global']['floors'] ?? null) ? $CATALOG['global']['floors'] : []);
$floorOrder=[]; if(isset($GF['order'])&&is_array($GF['order'])) foreach($GF['order'] as $fk) $floorOrder[]=strtoupper((string)$fk);
$floorLabels=[]; if(isset($GF['labels'])&&is_array($GF['labels'])) foreach($GF['labels'] as $fk=>$lab) $floorLabels[strtoupper((string)$fk)]=(string)$lab;
$floorSectionStyle=['height'=>'3.4vw','fontSize'=>'1.6vw','bold'=>false,'padY'=>'0.8vw']; if(isset($GF['section'])&&is_array($GF['section'])) $floorSectionStyle=array_merge($floorSectionStyle,$GF['section']);
$unknownFloorKey='Z'; $unknownFloorLabel=(string)($GF['unknownLabel'] ?? 'Weitere');

/* =========================
   Helpers
   ========================= */
function lv_norm(string $s): string { $t=mb_strtolower(trim($s),'UTF-8'); return str_replace(['oe','ae','ue','ss'],['ö','ä','ü','ß'],$t); }
function readVal($id){ if(!is_int($id)||$id<=0) return null; return @GetValue($id); }
function readFmt($id){ if(!is_int($id)||$id<=0) return '';   return (string)@GetValueFormatted($id); }
$mapStatusColor = static function(array $globalColors, ?string $level, ?string $explicit){
    if ($explicit && $explicit!=='') return $explicit;
    $lvl = lv_norm((string)$level);
    if ($lvl==='on'   && isset($globalColors['on']))    return (string)$globalColors['on'];
    if ($lvl==='off'  && isset($globalColors['off']))   return (string)$globalColors['off'];
    if ($lvl==='boost'&& isset($globalColors['boost'])) return (string)$globalColors['boost'];
    return '@pillClose';
};
$materializeButtons = static function(array $tplButtons,string $entityId){
    $out=[]; foreach($tplButtons as $b){ if(!is_array($b)) continue; $label=(string)($b['label']??''); $color=(string)($b['color']??'@pillOpen'); $args=[];
        foreach((array)($b['argsTpl']??[]) as $a){ $args[]=is_string($a)?str_replace('${entityId}',$entityId,$a):$a; }
        if(empty($args) && isset($b['args'])) $args=(array)$b['args'];
        if($label!=='') $out[]=['label'=>$label,'color'=>$color,'args'=>$args];
    } return $out;
};

/* =========================
   Build rows + flat index
   ========================= */
$rows = [];
$fanFlat = [];

$appendFanFlat = static function(array $row, string $roomKey = '', string $roomDisplay = '') use (&$fanFlat) {
    $fanFlat[] = [
        'title'       => (string)($row['title'] ?? ''),
        'entityId'    => (string)($row['entityId'] ?? ''),
        'toggleVarId' => (int)   (($row['_toggleVarId'] ?? 0)),
        'stateVarId'  => (int)   (($row['_stateVarId']  ?? 0)),
        'roomKey'     => $roomKey,
        'room'        => $roomDisplay,
    ];
};

$LGL = is_array($CATALOG['global']['domains']['lueftung'] ?? null)
    ? $CATALOG['global']['domains']['lueftung']
    : (is_array($CATALOG['global']['lueftung'] ?? null) ? $CATALOG['global']['lueftung'] : []);
$centralTitle   = (string)($LGL['central_title'] ?? 'Zentrale Lüftung');
$globalColors   = is_array($LGL['status_colors'] ?? null) ? $LGL['status_colors'] : ['on'=>'@pillOpen','off'=>'@pillClose','boost'=>'@pillMid'];
$defaultButtons = is_array($LGL['default_buttons'] ?? null) ? $LGL['default_buttons'] : [
    ['label'=>'An','color'=>'@pillOpen','argsTpl'=>['Ventilation','toggle','${entityId}','on']],
    ['label'=>'Aus','color'=>'@pillClose','argsTpl'=>['Ventilation','toggle','${entityId}','off']],
];
$globalVentIcon = isset($LGL['icon']) ? $resolveIcon((string)$LGL['icon']) : null;

$addCentral = function() use (&$rows,$LGL,$centralTitle,$resolveIcon,$globalVentIcon,$mapStatusColor,$globalColors,$materializeButtons,$defaultButtons,$appendFanFlat){
    $central = is_array($LGL['central'] ?? null) ? $LGL['central'] : [];
    if (empty($central)) return;
    $rows[] = ['section'=>$centralTitle];
    usort($central,function($a,$b){ $oa=(int)($a['order']??9999); $ob=(int)($b['order']??9999); return ($oa<=>$ob) ?: strcmp((string)($a['title']??''),(string)($b['title']??'')); });
    foreach($central as $c){
        $title    = (string)($c['title']??'Zentrale');
        $entityId = (string)($c['entityId']??'vent.central');
        $icon = isset($c['icon']) ? $resolveIcon((string)$c['icon']) : null; if(!$icon) $icon = $globalVentIcon ?: '';

        $statusText   = is_int($c['statusText']??null)? readFmt((int)$c['statusText']) : (string)($c['statusText']??'');
        $statusDetail = is_int($c['statusDetail']??null)? readFmt((int)$c['statusDetail']) : (string)($c['statusDetail']??'');
        $statusLevel  = (string)($c['statusLevel']??'');

        if($statusText==='' && isset($c['state']) && is_int($c['state'])){
            $raw=readVal((int)$c['state']); $fmt=readFmt((int)$c['state']);
            $statusText=$fmt!==''?$fmt:(string)$raw;
            $statusLevel=($raw===1||$raw===true)?'on':(($raw===2)?'boost':'off');
        }

        $color   = $mapStatusColor($globalColors,$statusLevel,(string)($c['statusColor']??''));
        $buttons = isset($c['buttons'])&&is_array($c['buttons']) ? $materializeButtons($c['buttons'],$entityId) : $materializeButtons($defaultButtons,$entityId);

        $row = ['icon'=>$icon,'title'=>$title,'entityId'=>$entityId,'status'=>['text'=>$statusText!==''?$statusText:'Aus','detail'=>$statusDetail,'color'=>$color],'buttons'=>$buttons];
        $row['_toggleVarId'] = is_int($c['toggle']??null)?(int)$c['toggle']:0;
        $row['_stateVarId']  = is_int($c['state'] ??null)?(int)$c['state'] :0;

        $rows[] = $row;
        $appendFanFlat($row,'','');
    }
};
$addCentral();

$byFloor=[];
foreach($CATALOG as $roomKey=>$def){
    if($roomKey==='global') continue;
    $roomDisplay=(string)($def['display']??ucfirst($roomKey));
    $roomFloor=strtoupper((string)($def['floor']??''));

    $L=$def['domains']['lueftung'] ?? null;
    if(!is_array($L)) continue;
    $DEV=$L['devices'] ?? ($L['fans'] ?? null);
    if(!is_array($DEV) || $DEV===[]) continue;

    foreach($DEV as $devKey=>$meta){
        if(!is_array($meta)) continue;
        $title    = (string)($meta['title'] ?? ($roomDisplay.' '.$devKey));
        $entityId = (string)($meta['entityId'] ?? ('luefter.'.$roomKey.'_'.$devKey));
        $icon = isset($meta['icon']) ? $resolveIcon((string)$meta['icon']) : null; if(!$icon) $icon = $globalVentIcon ?: '';

        $statusText   = is_int($meta['statusText']??null)? readFmt((int)$meta['statusText']) : (string)($meta['statusText']??'');
        $statusDetail = is_int($meta['statusDetail']??null)? readFmt((int)$meta['statusDetail']) : (string)($meta['statusDetail']??'');
        $statusLevel  = (string)($meta['statusLevel']??'');

        if($statusText==='' && isset($meta['state']) && is_int($meta['state'])){
            $raw=readVal((int)$meta['state']); $fmt=readFmt((int)$meta['state']);
            $statusText=$fmt!==''?$fmt:(string)$raw;
            $statusLevel=($raw===1||$raw===true)?'on':(($raw===2)?'boost':'off');
        }

        $color   = $mapStatusColor($globalColors,$statusLevel,(string)($meta['statusColor']??''));
        $buttons = isset($meta['buttons'])&&is_array($meta['buttons']) ? $materializeButtons($meta['buttons'],$entityId) : $materializeButtons($defaultButtons,$entityId);

        $floorKeyU=strtoupper((string)($meta['floor']??'')); $floorKey=$floorKeyU!==''?$floorKeyU:($roomFloor!==''?$roomFloor:$unknownFloorKey);
        $order=(int)($meta['order'] ?? 9999);

        $row = [
            'icon'=>$icon,'title'=>$title,'entityId'=>$entityId,
            'status'=>['text'=>$statusText!==''?$statusText:'Aus','detail'=>$statusDetail,'color'=>$color],
            'buttons'=>$buttons,'order'=>$order
        ];
        $row['_toggleVarId'] = is_int($meta['toggle']??null)?(int)$meta['toggle']:0;
        $row['_stateVarId']  = is_int($meta['state'] ??null)?(int)$meta['state'] :0;

        $byFloor[$floorKey][] = $row;
        $fanFlat[] = [
            'title'=>$title,'entityId'=>$entityId,
            'toggleVarId'=>$row['_toggleVarId'],'stateVarId'=>$row['_stateVarId'],
            'roomKey'=>$roomKey,'room'=>$roomDisplay
        ];
    }
}

foreach($byFloor as $fk=>&$list){
    usort($list,function($a,$b){ $oa=(int)($a['order']??9999); $ob=(int)($b['order']??9999); return ($oa<=>$ob) ?: strcmp((string)($a['title']??''),(string)($b['title']??'')); });
} unset($list);

$present=array_keys($byFloor);
$ordered=[]; foreach($floorOrder as $fk) if(isset($byFloor[$fk])) $ordered[]=$fk;
$remaining=array_diff($present,$ordered);
sort($remaining,SORT_NATURAL|SORT_FLAG_CASE);
foreach($remaining as $fk) if($fk!==$unknownFloorKey) $ordered[]=$fk;
if(isset($byFloor[$unknownFloorKey])) $ordered[]=$unknownFloorKey;

foreach($ordered as $fk){
    $label=$floorLabels[$fk] ?? ($fk===$unknownFloorKey?$unknownFloorLabel:$fk);
    $rows[]=['section'=>$label,'sectionH'=>$floorSectionStyle['height'],'sectionFont'=>$floorSectionStyle['fontSize'],'sectionBold'=>(bool)$floorSectionStyle['bold'],'sectionPadY'=>$floorSectionStyle['padY']];
    foreach($byFloor[$fk] as $r){
        $rows[]=['icon'=>$r['icon'],'title'=>$r['title'],'entityId'=>$r['entityId'],'status'=>$r['status'],'buttons'=>$r['buttons']];
    }
}

/* =========================
   Zielauflösung (ohne Fallback)
   ========================= */
$findByEntityId = static function(string $id) use ($fanFlat): ?array {
    foreach ($fanFlat as $r) if ((string)$r['entityId'] === (string)$id) return $r;
    return null;
};
$findByLabel = static function(string $label) use ($fanFlat): ?array {
    $needle = lv_norm($label);
    foreach ($fanFlat as $r) if (lv_norm((string)$r['title']) === $needle) return $r;
    return null;
};
$findByRoom = static function(string $room) use ($fanFlat): ?array {
    $needle = lv_norm($room);
    $hits = [];
    foreach ($fanFlat as $r) {
        if (lv_norm((string)$r['room']) === $needle || lv_norm((string)$r['roomKey']) === $needle) {
            $hits[] = $r;
        }
    }
    return count($hits) === 1 ? $hits[0] : null; // nur eindeutiger Treffer
};

/* =========================
   Operation bestimmen
   ========================= */
$desired = null;   // 0=off, 1=on, 2=boost, null=toggle
$target  = null;

/* Pure-Open-Erkennung: „lüftung“ ohne weitere Angaben → nur Übersicht zeigen */
$PURE_OPEN = (
    $device !== '' &&
    (lv_norm($device) === 'lüftung' || lv_norm($device) === 'lueftung')
) && $action === '' && $roomSpoken === '' && $object === '' && $alles === '' &&
   $powerStr === '' && empty($aplArgs) && (string)$args1 === '' && (string)$args2 === '';

/* APL args: ['Ventilation','toggle','<entityId>','on|off|boost'] */
if (!empty($aplArgs) && count($aplArgs) >= 2) {
    $cmd0 = lv_norm((string)$aplArgs[0]);
    $cmd1 = lv_norm((string)$aplArgs[1]);
    $cmd2 = (string)($aplArgs[2] ?? '');
    $cmd3 = lv_norm((string)($aplArgs[3] ?? ''));
    if (in_array($cmd0,['ventilation','lüftung','lueftung'],true) && $cmd1==='toggle' && $cmd2!=='') {
        $target  = $findByEntityId($cmd2) ?: $findByLabel($cmd2);
        if ($cmd3==='on')      $desired = 1;
        elseif ($cmd3==='off') $desired = 0;
        elseif ($cmd3==='boost') $desired = 2;
        else $desired = null;
    }
}

/* args1/args2: args1 = toggle/an/aus/boost; args2 = label/entityId */
if ($target === null && is_string($args1) && $args1!=='') {
    $a1 = lv_norm($args1);
    if (in_array($a1,['toggle','an','ein','on','aus','off','boost'],true)) {
        if (is_string($args2) && $args2!=='') {
            $target = $findByEntityId($args2) ?: $findByLabel($args2);
        } elseif ($roomSpoken!=='') {
            $target = $findByRoom($roomSpoken);
        }
        if (in_array($a1,['an','ein','on'],true))      $desired = 1;
        elseif (in_array($a1,['aus','off'],true))      $desired = 0;
        elseif ($a1==='boost')                         $desired = 2;
        else                                           $desired = null;
    }
}

/* Power-Hinweis (on/off) → nur bei eindeutigem Ziel */
if ($target === null && ($powerStr==='on' || $powerStr==='off')) {
    if ($roomSpoken!=='') {
        $target = $findByRoom($roomSpoken);
    } elseif (is_string($args2) && $args2!=='') {
        $target = $findByEntityId($args2) ?: $findByLabel($args2);
    }
    if ($powerStr==='on')  $desired = 1;
    if ($powerStr==='off') $desired = 0;
}

/* =========================
   Write + re-read + patch rows
   ========================= */
$speech = '';

if ($target !== null) {
    $toggleVar = (int)($target['toggleVarId'] ?? 0);
    $stateVar  = (int)($target['stateVarId']  ?? 0);

    if ($toggleVar<=0 && $stateVar<=0) {
        $speech = 'Schaltvorgang nicht möglich. Variable nicht vorhanden.';
    } else {
        if ($desired === null) {
            $cur = ($stateVar > 0) ? (int)readVal($stateVar) : 0;
            $desired = ($cur === 1) ? 0 : 1;
        }

        SetValueString($V['lastVariableDevice'], 'Lüftung');
        SetValueString($V['lastVariableId'], $toggleVar);
        SetValueString($V['lastVariableAction'], $desired);
        SetValueString($V['lastVariableValue'], 'schalten');


        $logV("[$RID] TARGET_RESOLVED ".json_encode([
            'title'=>(string)($target['title']??''),
            'entityId'=>(string)($target['entityId']??''),
            'toggleVarId'=>$toggleVar,'stateVarId'=>$stateVar,'desired'=>$desired
        ],$J));

        if (!$ALLOW_TOGGLE) {
            $logB("[$RID] ACTIONS_DISABLED(lueftung.toggle): skip write");
            $speech = 'Lüftung schalten ist derzeit nicht freigegeben.';
        } else {
            $ok = false;
            try {
                if     ($toggleVar > 0) { RequestAction($toggleVar, $desired); $ok = true; }
                elseif ($stateVar  > 0) { RequestAction($stateVar,  $desired); $ok = true; }
            } catch (\Throwable $e) {
                $ok = false;
                $logB("[$RID] RequestAction fehlgeschlagen: ".$e->getMessage());
            }

            if ($ok) {
                IPS_Sleep(150);
                $readId   = $stateVar > 0 ? $stateVar : ($toggleVar > 0 ? $toggleVar : 0);
                $nowRaw   = $readId > 0 ? readVal($readId)  : null;
                $nowText  = $readId > 0 ? readFmt($readId)  : '';
                $nowLevel = ($nowRaw===1 || $nowRaw===true) ? 'on' : (($nowRaw===2) ? 'boost' : 'off');
                if ($nowText === '') $nowText = ($nowLevel === 'on' ? 'Ein' : ($nowLevel === 'boost' ? 'Boost' : 'Aus'));
                $nowColor = $mapStatusColor($globalColors, $nowLevel, null);

                for ($i = 0, $n = count($rows); $i < $n; $i++) {
                    if (isset($rows[$i]['entityId']) && (string)$rows[$i]['entityId'] === (string)$target['entityId']) {
                        if (!isset($rows[$i]['status']) || !is_array($rows[$i]['status'])) $rows[$i]['status'] = [];
                        $rows[$i]['status']['text']  = $nowText;
                        $rows[$i]['status']['color'] = $nowColor;
                        if (!isset($rows[$i]['status']['detail'])) $rows[$i]['status']['detail'] = '';
                    }
                }

                $label  = (string)($target['title'] ?? '');
                $prefix = $label !== '' ? ($label.' ') : '';
                if     ($nowLevel === 'on')   { $speech = $prefix.'eingeschaltet.'; }
                elseif ($nowLevel === 'off')  { $speech = $prefix.'ausgeschaltet.'; }
                else                          { $speech = $prefix.'auf Boost.'; }

            } else {
                $speech = 'Schaltvorgang nicht möglich.';
            }
        }
    }
} else {
    $logB("[$RID] NO_TARGET(lueftung): args2=" . json_encode($args2,$J) . " room=" . json_encode($roomSpoken,$J));
    if (/*!$PURE_OPEN*/ false) {
        // nie erreicht; rein informativ
        $speech = 'Schaltvorgang nicht möglich. Variable nicht vorhanden.';
    }
}

/* =========================
   Voice: global open/close (nur Text, keine Fallback-Aktion)
   ========================= */
if ($speech==='' && $roomSpoken==='' && in_array(lv_norm($action),['oeffnen','öffnen','schließen','schliessen'],true)) {
    $txt = in_array(lv_norm($action),['schließen','schliessen'],true)
        ? 'Zentrale Lüftung wird ausgeschaltet.'
        : 'Zentrale Lüftung wird eingeschaltet.';
    echo json_encode(['speech'=>$txt,'reprompt'=>'','apl'=>null,'endSession'=>true],$J);
    return;
}

/* =========================
   APL render
   ========================= */
$doc = ['type'=>'Link','src'=>'doc://alexa/apl/documents/Lueftung'];
$ds  = ['imageListData'=>['title'=>'LÜFTUNG','subtitle'=>'Zentral & Räume','rows'=>$rows]];
$apl = $aplSupported ? ['doc'=>$doc,'ds'=>$ds,'token'=>'hv-lueftung'] : null;

/* Reiner „lüftung“-Aufruf → nur APL liefern und zurück */
if ($PURE_OPEN) {
    echo json_encode(['speech'=>'','reprompt'=>'','apl'=>$apl,'endSession'=>false], $J);
    return;
}

/* Optional: APL-Event-Feedback nur, wenn sonst kein Text */
if ($speech === '' && is_string($args2) && trim($args2) !== '') {
    echo json_encode([
        'speech'     => '',
        'reprompt'   => '',
        'apl'        => $apl,
        'endSession' => false
    ], $J);
    return;
}

echo json_encode(['speech'=>$speech,'reprompt'=>'','apl'=>$apl,'endSession'=>false],$J);
