<?php
declare(strict_types=1);

/** Read-only diagnostics and stale-filter migration regression. */
class IPSModule
{
    public array $properties=[];
    private array $attributes=[];
    public array $updates=[];
    public function ReadPropertyInteger(string $n): int { return (int)($this->properties[$n]??0); }
    public function ReadPropertyString(string $n): string { return (string)($this->properties[$n]??''); }
    public function ReadAttributeString(string $n): string { return (string)($this->attributes[$n]??''); }
    public function WriteAttributeString(string $n,string $v): void { $this->attributes[$n]=$v; }
    public function UpdateFormField(string $n,string $k,mixed $v): void { $this->updates[$n.'.'.$k]=$v; }
    public function ReloadForm(): void { throw new RuntimeException('Unexpected form reload'); }
}
$GLOBALS['rccAuditDir']=sys_get_temp_dir().'/rcc-audit-'.bin2hex(random_bytes(6));
mkdir($GLOBALS['rccAuditDir'].'/scripts',0700,true);
$GLOBALS['rccAuditSources']=[];
function IPS_GetKernelDir(): string { return $GLOBALS['rccAuditDir'].'/'; }
function IPS_GetScriptFile(int $id): string { return $id.'.php'; }
function IPS_ScriptExists(int $id): bool { return isset($GLOBALS['rccAuditSources'][$id]); }
function IPS_GetScriptContent(int $id): string { return $GLOBALS['rccAuditSources'][$id]??''; }
function IPS_SetScriptContent(int $id,string $source): bool {
    $GLOBALS['rccAuditSources'][$id]=$source;
    file_put_contents($GLOBALS['rccAuditDir'].'/scripts/'.$id.'.php',$source);
    return true;
}
function IPS_VariableExists(int $id): bool { return in_array($id,[90001,90002,91001,91002],true); }
require dirname(__DIR__).'/RoomsCatalogConfigurator/module.php';
function auditCheck(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function auditCatalog(array $v): string { return '<?php return '.var_export($v,true).';'; }
function auditReadForm(RoomsCatalogConfigurator $module): array {
    return json_decode($module->GetConfigurationForm(),true,512,JSON_THROW_ON_ERROR);
}
function auditFind(array $form,string $name): ?array {
    $walk=function(array $list)use(&$walk,$name):?array{
        foreach($list as $row){
            if(($row['name']??'')===$name)return $row;
            if(is_array($row['items']??null)){
                $match=$walk($row['items']); if($match!==null)return $match;
            }
        }
        return null;
    };
    return $walk($form['elements']??[]);
}
$deviceLight=['title'=>'Decke','state'=>90001,'toggle'=>90002,
    'kiEnabled'=>true,'kiReadOnly'=>true,
    'ai'=>['version'=>1,'type'=>'light','variables'=>['power'=>90001],
        'review'=>['status'=>'confirmed']]];
$deviceHeater=['title'=>'Bad','ist'=>91001,'soll'=>91002,
    'kiEnabled'=>false,'kiReadOnly'=>true]; // legacy and without KI
$base=[
    'global'=>['display'=>'global','domains'=>['save'=>['tabs'=>[
        'Fritzbox'=>['title'=>'Fritzbox','id'=>4251]
    ]]]],
    'wohnzimmer'=>['display'=>'Wohnzimmer','floor'=>'EG','domains'=>[
        'licht'=>['switches'=>['decke'=>$deviceLight]]
    ]],
    'bad'=>['display'=>'Bad','floor'=>'EG','domains'=>['heizung'=>['bad'=>$deviceHeater]]]
];
IPS_SetScriptContent(40,auditCatalog($base));
IPS_SetScriptContent(41,auditCatalog($base));
$mod=new RoomsCatalogConfigurator();
$mod->properties=['RoomsCatalogScriptID'=>40,'RoomsCatalogEditScriptID'=>41];
(new ReflectionMethod(RoomsCatalogConfigurator::class,'reloadAllFromCatalog'))->invoke($mod);
$mod->WriteAttributeString('FilterGroup','tabs');
$oldForm=auditReadForm($mod);
auditCheck(count(auditFind($oldForm,'Entries')['values'])===1,'Old tab selection not reproduced');

// External tab migration changes EDIT, but not the running module attribute.
$clean=$base;unset($clean['global']['domains']['save']['tabs']);
IPS_SetScriptContent(41,auditCatalog($clean));
$form=auditReadForm($mod);
auditCheck($mod->ReadAttributeString('FilterGroup')==='',
    'Obsolete tabs filter must be reset on external EDIT mutation');
auditCheck(count(auditFind($form,'Entries')['values'])===2,
    'Stale RuntimeEntries not refreshed from updated EDIT');
$group=auditFind($form,'FilterGroup');
auditCheck($group['value']==='' &&
    !in_array('tabs',array_column($group['options'],'value'),true),
    'Filter options still include obsolete tabs or wrong current value');
auditCheck(!str_contains(json_encode($form,JSON_THROW_ON_ERROR),'Aktueller Wert "tabs"'),
    'Form still exposes obsolete filter warning');

$mod->SetRoomFilter('wohnzimmer');
$form=auditReadForm($mod);
$domain=auditFind($form,'FilterDomain');
$group=auditFind($form,'FilterGroup');
auditCheck(in_array('licht',array_column($domain['options'],'value'),true)
    && !in_array('heizung',array_column($domain['options'],'value'),true),
    'Room-scoped domain choices wrong');
auditCheck(in_array('switches',array_column($group['options'],'value'),true)
    && !in_array('heizung',array_column($group['options'],'value'),true),
    'Room-scoped group choices wrong');
$mod->SetDomainFilter('heizung');
auditCheck($mod->ReadAttributeString('FilterDomain')==='',
    'Unavailable room-specific domain should auto reset');
$catalogSummary=auditFind($form,'CatalogSummary');
auditCheck(is_array($catalogSummary)
    && !str_contains((string)($catalogSummary['caption']??''),'KI nur lesen: 1'),
    'Summary should not use old confusing KI wording');
$ui=json_encode($form,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
auditCheck(str_contains($ui,'Datenstand und KI-Berechtigungen')
    && str_contains($ui,'MODELL, IPS-VARIABLEN UND MIGRATION PRUEFEN'),
    'Migration audit UI missing');
$top=array_column($form['elements'],'caption');
auditCheck(in_array('Raeume & Etagen',$top,true)
    && in_array('RoomsCatalog Scripts',$top,true)
    && in_array('Geraete bearbeiten (RoomsCatalog EDIT)',$top,true)
    && in_array('Weitere Funktionen (Experten): Pruefen, Migration und PROD',$top,true)
    && !in_array('Geraete erkennen, inventarisieren und reparieren',$top,true)
    && !in_array('Variablenzuordnungen pruefen',$top,true),
    'Only rooms, script settings, devices and collapsed experts should be visible');
$expert=array_values(array_filter($form['elements'],
    static fn(array $row):bool =>
        ($row['caption']??'')==='Weitere Funktionen (Experten): Pruefen, Migration und PROD'))[0];
auditCheck($expert['expanded']===false
    && str_contains(json_encode($expert),'RCC_ScanNewDevices')
    && str_contains(json_encode($expert),'RCC_AnalyzeLegacyDevices')
    && str_contains(json_encode($expert),'RCC_ApplyScopedProdPublish')
    && ($form['actions']??[])===[],
    'Advanced operations must stay reachable only under collapsed experts');

$prodBefore=IPS_GetScriptContent(40);
$editBefore=IPS_GetScriptContent(41);
ob_start();$mod->AuditMigrationReadiness();$report=ob_get_clean();
auditCheck(str_contains($report,'KI-Zugriff: 1/2 freigegeben')
    && str_contains($report,'1 Legacy')
    && str_contains($report,'noch NICHT vollstaendig'),
    'Read-only migration report not transparent about missing typing');
auditCheck(IPS_GetScriptContent(40)===$prodBefore && IPS_GetScriptContent(41)===$editBefore,
    'Read-only audit must never modify PROD or EDIT');

$updated=$clean;
$updated['bad']['domains']['heizung']['bad']['ai']=[
    'version'=>1,'type'=>'heating',
    'variables'=>['temperature'=>91001,'targetTemperature'=>91002],
    'review'=>['status'=>'confirmed']
];
IPS_SetScriptContent(40,auditCatalog($updated));
IPS_SetScriptContent(41,auditCatalog($updated));
$allDone=auditReadForm($mod);
$reportField=auditFind($allDone,'MigrationReadinessReport');
auditCheck(str_contains((string)($reportField['caption']??''),'Datenstand erneut pruefen'),
    'Migration report must expire when EDIT changes outside the configurator');
$compact=array_values(array_filter($allDone['elements'],
    static fn(array $row):bool => ($row['caption']??'')==='Geraete erkennen, inventarisieren und reparieren'))[0];
auditCheck(!str_contains(json_encode($compact),'RCC_AnalyzeLegacyDevices')
    && str_contains(json_encode($compact),'RCC_ScanNewDevices'),
    'Fully typed catalog should hide only obsolete legacy migration, not new-device scanner');
ob_start();$mod->AuditMigrationReadiness();$doneReport=ob_get_clean();
auditCheck(str_contains($doneReport,'Katalog-Modell technisch konsistent'),
    'All-confirmed migration should be recognized without granting KI writes');
auditCheck(($updated['bad']['domains']['heizung']['bad']['kiEnabled']??null)===false,
    'Audit must preserve explicit KI opt-out');
foreach(array_keys($GLOBALS['rccAuditSources']) as $id) @unlink($GLOBALS['rccAuditDir'].'/scripts/'.$id.'.php');
@rmdir($GLOBALS['rccAuditDir'].'/scripts');@rmdir($GLOBALS['rccAuditDir']);
echo "PASS: stale tabs filter, scoped filters, KI permissions, read-only migration audit and setup UI\n";
