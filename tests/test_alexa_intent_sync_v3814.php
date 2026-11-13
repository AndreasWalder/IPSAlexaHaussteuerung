<?php
declare(strict_types=1);

require __DIR__.'/test_system_configuration_v333.php';

function ck3814(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('V3.8.14: '.$message);
}

const INTENT3814 = '{F14921A2-405D-4576-A389-95FB9A5CD730}';
const WEBHOOK3814 = '{F416D335-7D66-4B91-97AA-02C7F4E3D343}';
const SCRIPT3814 = 'Patami_IPS_Services_Alexa_Skills_Custom_Intents_Custom_ScriptIntent_ScriptID';

function fixture3814(int $id, string $module, int $hook, string $name = '', int $script = 0, int $mode = 1): void
{
    $GLOBALS['intent3814Instances'][$id] = [
        'ModuleInfo'=>['ModuleID'=>$module], 'ConnectionID'=>$hook, 'InstanceStatus'=>102,
    ];
    $GLOBALS['intent3814Properties'][$id] = [
        'IntentName'=>$name, 'StandardIntentName'=>'', 'IntentType'=>$mode, SCRIPT3814=>$script,
    ];
    $GLOBALS['sc333Objects'][$id] = ['parent'=>15, 'name'=>$name, 'ident'=>''];
}
function IPS_GetInstanceListByModuleID(string $module): array
{
    return array_keys(array_filter($GLOBALS['intent3814Instances'], static fn(array $i): bool => $i['ModuleInfo']['ModuleID']===$module));
}
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['intent3814Instances'][$id]); }
function IPS_GetInstance(int $id): array { return $GLOBALS['intent3814Instances'][$id]; }
function IPS_GetParent(int $id): int { return $GLOBALS['sc333Objects'][$id]['parent']; }
function IPS_GetProperty(int $id, string $key): mixed { return $GLOBALS['intent3814Properties'][$id][$key]; }
function IPS_HasChanges(int $id): bool { return $GLOBALS['intent3814Pending'][$id] ?? false; }
function IPS_CreateInstance(string $module): int
{
    $id = ++$GLOBALS['sc333Next'];
    fixture3814($id,$module,801,'MyIntentName');
    $GLOBALS['intent3814Created'][] = $id;
    return $id;
}
function IPS_DisconnectInstance(int $id): bool
{
    $GLOBALS['intent3814Instances'][$id]['ConnectionID'] = 0;
    return true;
}
function IPS_ConnectInstance(int $id, int $hook): bool
{
    $GLOBALS['intent3814Instances'][$id]['ConnectionID'] = $hook;
    return true;
}
function IPS_SetProperty(int $id, string $key, mixed $value): bool
{
    $GLOBALS['intent3814Properties'][$id][$key] = $value;
    $GLOBALS['intent3814Pending'][$id] = true;
    return true;
}
function IPS_ApplyChanges(int $id): bool
{
    if ($id === ($GLOBALS['intent3814ApplyFailure'] ?? 0) && IPS_GetProperty($id,SCRIPT3814) === 46711)
        return false;
    $GLOBALS['intent3814Pending'][$id] = false;
    return true;
}
function IPS_SemaphoreEnter(string $name, int $ms): bool
{
    if (!empty($GLOBALS['intent3814Locks'][$name])) return false;
    $GLOBALS['intent3814Locks'][$name] = true;
    return true;
}
function IPS_SemaphoreLeave(string $name): void { unset($GLOBALS['intent3814Locks'][$name]); }

$GLOBALS['sc333Objects'][46711] = ['parent'=>100, 'name'=>'Action', 'ident'=>'iahActionEntry'];
IPS_SetScriptContent(46711, '<?php function Execute($request = null) {}');
fixture3814(800,WEBHOOK3814,0);
fixture3814(801,WEBHOOK3814,0);
fixture3814(52076,INTENT3814,800,'GetHaus',46711);
fixture3814(810,INTENT3814,800,'VisuIntent',46711);
fixture3814(811,INTENT3814,800,'HausWieIntent',46000);
fixture3814(812,INTENT3814,800,'HausWasIntent',0,0);
fixture3814(813,INTENT3814,801,'HausBefehlIntent',98765);
fixture3814(814,INTENT3814,800,'HausIstIntent',46000);
fixture3814(815,INTENT3814,800,'HausWarumIntent',46000);
fixture3814(816,INTENT3814,800,'HausWarumIntent',46000);
fixture3814(817,INTENT3814,800,'hauswointent',46000);
$GLOBALS['intent3814Pending'][814] = true;

$form = (new ReflectionMethod(IPSAlexaHaussteuerung::class,'buildAlexaIntentSyncForm'))->invoke($module);
ck3814($form['items'][1]['value']===52076, 'working GetHaus autodetected from local action');
ck3814($form['items'][1]['moduleID']===INTENT3814 && $form['items'][4]['items'][1]['caption']==='Alexa-Intents synchronisieren',
    'configuration form offers Patami reference and the synchronize button');
$names = ['GetHaus','VisuIntent','HausWieIntent','HausWasIntent','HausBefehlIntent','HausIstIntent',
    'HausWarumIntent','HausWoIntent','AMAZON.FallbackIntent','AMAZON.HelpIntent','AMAZON.PauseIntent','Extra_Intent'];
$model = static fn(array $names): string => json_encode(['interactionModel'=>['languageModel'=>[
    'intents'=>array_map(static fn(string $n): array=>['name'=>$n],$names),
]]], JSON_THROW_ON_ERROR);
$json = $model($names);
$rows = static function () use ($module): array {
    $value = json_decode($module->updates['AlexaIntentSyncRows.values'], true, 512, JSON_THROW_ON_ERROR);
    return array_column($value, null, 'intent');
};
$status = static fn(): string => $module->updates['AlexaIntentSyncStatus.caption'];
$baseline = $GLOBALS['intent3814Properties'];
$module->ApplyAlexaIntentSync(52076,$json);
ck3814(str_contains($status(),'Vorschau') && empty($GLOBALS['intent3814Created']), 'apply without preview cannot write');
$module->PreviewAlexaIntentSync(52076,$json);
$r = $rows();
ck3814($r['GetHaus']['result']==='Referenz' && $r['VisuIntent']['result']==='Passt'
    && $r['HausWieIntent']['result']==='Anderes Skript' && $r['HausWasIntent']['result']==='Unverändert'
    && $r['HausBefehlIntent']['result']==='Fehlt' && $r['HausIstIntent']['result']==='Konflikt'
    && $r['HausWarumIntent']['result']==='Konflikt' && $r['HausWoIntent']['result']==='Konflikt'
    && $r['AMAZON.FallbackIntent']['result']==='Fehlt' && $r['AMAZON.PauseIntent']['result']==='Übersprungen'
    && $r['Extra_Intent']['result']==='Übersprungen', 'plan distinguishes all relevant mappings');
ck3814($baseline===$GLOBALS['intent3814Properties'] && empty($GLOBALS['intent3814Created']), 'preview has no instance writes');
$module->ApplyAlexaIntentSync(52076,$json,true);
ck3814(str_contains($status(),'Vorschau') && empty($GLOBALS['intent3814Created']), 'different option requires a fresh preview');
$module->ApplyAlexaIntentSync(52076,$json);
$r = $rows();
$created = (int)$r['HausBefehlIntent']['id'];
ck3814($r['HausBefehlIntent']['result']==='Angelegt' && IPS_GetInstance($created)['ConnectionID']===800
    && IPS_GetProperty($created,SCRIPT3814)===46711 && IPS_GetParent($created)===15
    && IPS_GetProperty(813,SCRIPT3814)===98765, 'creates missing name only on the right webhook and attaches Action');
$standard = (int)$r['AMAZON.FallbackIntent']['id'];
ck3814(IPS_GetProperty($standard,'StandardIntentName')==='AMAZON.FallbackIntent'
    && IPS_GetProperty($standard,SCRIPT3814)===46711, 'dotted standard name does not use Patami custom-name field');
ck3814(IPS_GetProperty(811,SCRIPT3814)===46000, 'no existing remapping by default');

$module->PreviewAlexaIntentSync(52076,$json,true);
$GLOBALS['intent3814Properties'][811][SCRIPT3814] = 46001;
$module->ApplyAlexaIntentSync(52076,$json,true);
ck3814(str_contains($status(),'Vorschau') && IPS_GetProperty(811,SCRIPT3814)===46001,
    'stale preview after changed target is rejected');
$module->PreviewAlexaIntentSync(52076,$json,true);
$GLOBALS['intent3814ApplyFailure'] = 811;
$module->ApplyAlexaIntentSync(52076,$json,true);
ck3814($rows()['HausWieIntent']['result']==='Fehler' && IPS_GetProperty(811,SCRIPT3814)===46001
    && !IPS_HasChanges(811), 'failed remap restores original script on existing instance');
$GLOBALS['intent3814ApplyFailure'] = 0;
$module->PreviewAlexaIntentSync(52076,$json,true);
$module->ApplyAlexaIntentSync(52076,$json,true);
ck3814($rows()['HausWieIntent']['id']==='811' && $rows()['HausWieIntent']['result']==='Umgestellt'
    && IPS_GetProperty(811,SCRIPT3814)===46711 && IPS_GetProperty(812,'IntentType')===0,
    'approved remap only changes script on the same existing instance');
$module->PreviewAlexaIntentSync(52076,$json);
ck3814(str_contains($status(),'0 Änderung') && count($GLOBALS['intent3814Created'])===3
    && $GLOBALS['intent3814Properties'][52076]===$baseline[52076], 'second run is idempotent and GetHaus untouched');
$module->PreviewAlexaIntentSync(52076,$model(['GetHaus','HausTestIntent','haustestintent']));
ck3814(str_contains($status(),'Doppelter Intent'), 'duplicate names rejected case-insensitively');
echo "PASS V3.8.14: preview-only planning, Patami scope, creation, optional remap, conflicts, stale guard and rollback\n";
