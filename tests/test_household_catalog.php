<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/home_connect_runtime.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/HouseholdEditorTrait.php';
require_once __DIR__.'/../RoomsCatalogConfigurator/libs/DataSourceEditor.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/src/VoiceRuleLiteralReader.php';
require_once __DIR__.'/../IPSAlexaHaussteuerung/src/HomeConnectTrait.php';

$base=['rooms'=>['kueche'=>['display'=>'Küche','floor'=>'EG','domains'=>[]]],'custom'=>['keep'=>'yes']];
$discovery=IAHHomeConnect::source(50710,[]);
$catalog=RCCHouseholdCatalog::merge($base,$discovery);
hcAssert(count(IAHHouseholdDevices::source($catalog)['devices'])===6,'All six devices enter one generic household source');
$rows=RCCDataSourceEditor::sourceRows($catalog);
hcAssert(count($rows)===6 && array_unique(array_column($rows,'domain'))===['hausgeraete']
    && array_sum(array_column($rows,'metricCount'))===122,'Normal device table exposes six household devices and every variable');
hcAssert(RCCHouseholdCatalog::merge($catalog,$discovery)===$catalog,'Reloading never duplicates links or overwrites settings');
hcAssert(count(RCCHouseholdCatalog::pendingRows($catalog,$base))===6,'Unpublished household links appear in pending changes');

final class HouseholdEditorFixture
{
    use RCCHouseholdEditorTrait;
    private const ALEXA_MODULE_ID='{F528D4D0-5729-4315-AE88-9BBABDBD0392}';
    public array $catalog,$prod,$updates=[];
    public int $backups=0;
    public function __construct(array $catalog){$this->catalog=$catalog;$this->prod=$catalog;}
    private function loadConfiguredCatalog($property,$mode):array{return $this->catalog;}
    private function systemTreeEditContext():array{return [1,serialize($this->catalog),$this->catalog];}
    private function saveSystemTreeEdit($id,$old,array $next):int{
        hcAssert($old===serialize($this->catalog),'No stale EDIT overwrite');
        $this->backups++;$this->catalog=$next;return $this->backups;
    }
    private function refreshSystemTreeCatalog():void{}
    public function ReadPropertyInteger($name):int{return 0;}
    private function logDebug($message):void{}
    public function UpdateFormField($name,$field,string|bool|int|float $value):void{
        if($field==='values')hcAssert(is_array(json_decode((string)$value,true,512,JSON_THROW_ON_ERROR)),'Native updates must be JSON');
        $this->updates[$name][$field]=$value;
    }
}
$GLOBALS['hcInstances'][900]=['ModuleInfo'=>['ModuleID'=>'{F528D4D0-5729-4315-AE88-9BBABDBD0392}'],'InstanceStatus'=>102];
$GLOBALS['householdDiscovery']=$discovery;
function IAH_GetHomeConnectDiscoveryCatalog(int $id):string {
    hcAssert($id===900,'Use the linked Alexa module, not a hardcoded production ID');
    return json_encode(['dataSources'=>['home_connect'=>$GLOBALS['householdDiscovery']]]);
}
$syncEditor=new HouseholdEditorFixture($base);
ob_start();$syncEditor->SyncHouseholdDevices();ob_end_clean();
hcAssert(count(RCCDataSourceEditor::sourceRows($syncEditor->catalog))===6&&$syncEditor->prod===$base&&$syncEditor->backups===1,
    'Automatic module discovery materializes six EDIT rows with a backup and leaves PROD unchanged');
ob_start();$syncEditor->SyncHouseholdDevices();ob_end_clean();
hcAssert($syncEditor->backups===1,'Unchanged automatic reload does not create extra backups');
$editor=new HouseholdEditorFixture($catalog);
$form=json_decode($editor->GetHouseholdEditForm('household_hc_58379'),true,512,JSON_THROW_ON_ERROR);
$list=array_values(array_filter($form,static fn($e):bool=>($e['name']??'')==='HouseholdVariables'))[0];
hcAssert(count($list['values'])===28,'Normal gear editor lists all 28 washer/dryer variables');
$m=$catalog['dataSources'][IAHHouseholdDevices::KEY]['devices']['hc_58379']['variables']['OptionSpinSpeed'];
$m['key']='OptionSpinSpeed';$m['enabled']=false;$m['title']='Eigene Drehzahl';$m['writable']=false;
ob_start();$editor->SaveHouseholdVariable('household_hc_58379',json_encode($m));$notice=ob_get_clean();
hcAssert(str_contains($notice,'gespeichert')&&$editor->prod===$catalog&&$editor->backups===1,'Editing one variable writes only backed-up EDIT');
$edited=$editor->catalog;
$merged=RCCHouseholdCatalog::merge($edited,$discovery);
hcAssert($merged===$edited,'Automatic discovery preserves custom variable names and denials');
$home=IAHHouseholdDevices::homeSource(IAHHouseholdDevices::source($edited));
$device=$home['devices']['hc_58379'];
hcAssert(!isset(IAHHomeConnect::forDevice($device)['OptionSpinSpeed']),'Denied variable is excluded from the Alexa runtime');
$r=IAHHouseholdDevices::query($edited,'Wie geht es meiner Waschmaschine?');
hcAssert($r['displayTitle']==='Waschtrockner','Published-style source keeps appliance question and visual integration');
$GLOBALS['hcObjects'][700]=['ObjectType'=>1,'ObjectName'=>'Roboter','ParentID'=>50710];
$GLOBALS['hcObjects'][701]=['ObjectType'=>2,'ObjectName'=>'Ein/Aus','ObjectIdent'=>'Power','ParentID'=>700];
$GLOBALS['hcVariables'][701]=['VariableType'=>0,'VariableAction'=>700,'VariableUpdated'=>time()];$GLOBALS['hcValues'][701]=false;
ob_start();$editor->AddHouseholdDevice(700,'Staubsauger');$notice=ob_get_clean();
hcAssert(str_contains($notice,'angelegt')&&count(RCCDataSourceEditor::sourceRows($editor->catalog))===7,'A non-Home-Connect device is linked under the same domain');
$r=IAHHouseholdDevices::query($editor->catalog,'Wie geht es meinem Staubsauger?');
hcAssert($r['displayTitle']==='Staubsauger'&&count($r['displayRows'])===1,'Other household providers have voice and APL status too');
$d=$editor->catalog['dataSources'][IAHHouseholdDevices::KEY]['devices']['object_700'];
$var=$d['variables']['var_701']+['key'=>'var_701'];$var['role']='power';$var['writable']=true;
ob_start();$editor->SaveHouseholdVariable('household_object_700',json_encode($var));ob_end_clean();
ob_start();$editor->SaveHouseholdOverview('household_object_700','Staubsauger','Roboter',true,true,'kueche','reinigung');$notice=ob_get_clean();
hcAssert(str_contains($notice,'gespeichert'),'Generic device name, aliases, room and permission are editable');
$before=count($GLOBALS['hcWrites']);
IAHHouseholdDevices::query($editor->catalog,'Schalte den Staubsauger ein',false);
hcAssert(count($GLOBALS['hcWrites'])===$before,'Read-only preview never controls generic appliances');
IAHHouseholdDevices::query($editor->catalog,'Schalte den Staubsauger ein',true);
hcAssert(end($GLOBALS['hcWrites'])===[701,true],'Explicit generic power role and permission are required for control');
$before=count($GLOBALS['hcWrites']);
IAHHouseholdDevices::query($editor->catalog,'Schalte den Timer des Staubsaugers aus',true);
hcAssert(count($GLOBALS['hcWrites'])===$before,'A subfunction command cannot toggle the whole generic appliance');
$all=IAHHouseholdDevices::query($editor->catalog,'Wie geht es meinen Hausgeräten?');
hcAssert(count($all['displayRows'])===7,'Household overview spans all providers');

// Verify the module authority: once a household catalog exists, legacy attributes cannot reopen denied devices.
function IPS_ScriptExists(int $id):bool{return $id===50;}
final class HouseholdRuntimeFixture
{
    use IAHHomeConnectTrait;
    public int $InstanceID=1;
    public array $attributes=[];
    public string $source;
    public function ReadPropertyBoolean($name):bool{return true;}
    public function ReadPropertyInteger($name):int{return 0;}
    public function ReadAttributeString($name):string{return $this->attributes[$name]??'';}
    public function WriteAttributeString($name,$value):void{$this->attributes[$name]=$value;}
    private function getObjectIDByIdentOrName($parent,$ident,$name):int{return $ident==='iahSettings'?2:50;}
    private function getScriptContent($id):string{return $this->source;}
}
$runtime=new HouseholdRuntimeFixture();$published=$edited;
$published['dataSources'][IAHHouseholdDevices::KEY]['devices']['hc_58379']['enabled']=false;
$runtime->source='<?php return '.var_export($published,true).';';
hcAssert($runtime->IsHouseholdCommand('Starte die Waschmaschine'),'Normal spoken appliance commands reach the published household handler');
$out=json_decode($runtime->GetHomeConnectCatalog(),true,512,JSON_THROW_ON_ERROR);
hcAssert(!in_array(58379,array_column(IAHHomeConnect::devices($out),'id'),true),'Published device denial overrides discovery attributes');
$runtime->source='<?php broken;';
$out=json_decode($runtime->GetHomeConnectCatalog(),true,512,JSON_THROW_ON_ERROR);
hcAssert(IAHHomeConnect::devices($out)===[],'A removed/unreadable managed catalog never falls back to permissive discovery');
try {
    RCCHouseholdCatalog::merge(['dataSources'=>[IAHHouseholdDevices::KEY=>['provider'=>'Foreign']]],$discovery);
    hcAssert(false,'Foreign source keys must not be replaced');
} catch (RuntimeException $e) { hcAssert(str_contains($e->getMessage(),'anders belegt'),'Foreign source collision is reported'); }

class IPSModule{}
require_once __DIR__.'/../RoomsCatalogConfigurator/module.php';
$rcc=new RoomsCatalogConfigurator();
$options=(new ReflectionMethod($rcc,'buildFilterOptionsFromEntries'))->invoke($rcc,$rows);
hcAssert(in_array(['caption'=>'Hausgeräte','value'=>'hausgeraete'],$options['domainOptions']??$options[1]??[],true),'Domain filter presents the user-facing Hausgeräte label');
$rebuilt=(new ReflectionMethod($rcc,'rebuildRoomsCatalogFromEntries'))->invoke($rcc,[],$catalog);
hcAssert(IAHHouseholdDevices::source($rebuilt)===IAHHouseholdDevices::source($catalog),'Saving ordinary room entries retains the household data and every variable permission');

// Exercise generated gear callback syntax and dispatch for all existing source types.
$module=file_get_contents(__DIR__.'/../RoomsCatalogConfigurator/module.php');
preg_match('/\x27form\x27 => (\x27return json_decode\(!empty\(\$Entries.*?),\R\s*\x27add\x27/s',$module,$match);
$callback=eval('return '.$match[1].';');
token_get_all('<?php '.$callback,TOKEN_PARSE);
function RCC_GetHouseholdEditForm($id,$key):string{return '[{"caption":"household"}]';}
function RCC_GetSystemTreeEditForm($id,$key):string{return '[{"caption":"irrigation"}]';}
function RCC_GetHuaweiDataSourceEditForm($id,$key):string{return '[{"caption":"huawei"}]';}
function RCC_GetEntryEditForm(...$args):string{return '[{"caption":"normal"}]';}
foreach ([['hausgeraete','household_hc_58379',true,'household'],['bewaesserung','irrigation_1',true,'irrigation'],['energie','huawei_1',true,'huawei'],['licht','light',false,'normal']] as [$domain,$key,$source,$expected]) {
    $Entries=['domain'=>$domain,'key'=>$key,'isDataSource'=>$source];$id=1;
    $gear=eval($callback);
    hcAssert($gear[0]['caption']===$expected,'Normal gear dispatch survives: '.$expected);
}
$saveSelectedRowCallback='';
preg_match('/\$sourceRowSaveCallback=(.*?);\R\s*\$columnsEdit/s',$module,$match);
$saveCallback=eval('return '.$match[1].';');
token_get_all('<?php '.$saveCallback,TOKEN_PARSE);
function RCC_SaveHouseholdOverview(...$args):void{$GLOBALS['householdSaved']=$args;}
function RCC_UpdateOverviewSelection(int $id,string $json):bool{return false;}
$Entries=$rows[0];eval($saveCallback);
hcAssert($GLOBALS['householdSaved'][3]===$rows[0]['synonyms'],'Editing the main table does not clear alternate names');
echo "PASS household catalog migration, all variables, EDIT-only editing, provider-neutral linking, permissions and normal filter/gear dispatch\n";
