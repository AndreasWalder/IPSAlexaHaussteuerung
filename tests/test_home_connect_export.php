<?php
declare(strict_types=1);

require_once __DIR__ . '/../RoomsCatalogConfigurator/libs/HomeConnectEditorTrait.php';

function hcCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function IPS_ObjectExists(int $id): bool { return isset($GLOBALS['objects'][$id]); }
function IPS_GetObject(int $id): array { return $GLOBALS['objects'][$id] ?? throw new RuntimeException('Missing'); }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['instances'][$id]); }
function IPS_GetInstance(int $id): array { return $GLOBALS['instances'][$id]; }
function IPS_GetProperty(int $id, string $name): string
{
    hcCheck($name === 'DeviceType', 'Never read account data or appliance serials');
    return 'WasherDryer';
}
function IPS_GetChildrenIDs(int $id): array
{
    return array_keys(array_filter($GLOBALS['objects'], static fn(array $o): bool => $o['ParentID'] === $id));
}
function IPS_GetVariable(int $id): array { return $GLOBALS['variables'][$id]; }
function GetValue(int $id): mixed { return $GLOBALS['values'][$id]; }
function GetValueFormatted(int $id): string { return (string)$GLOBALS['values'][$id]; }
function IPS_VariableProfileExists(string $name): bool { return $name === 'HomeConnect.OperationState'; }
function IPS_GetVariableProfile(string $name): array
{
    return ['ProfileType' => 3, 'Suffix' => '', 'Associations' => [
        ['Value' => 'BSH.Common.EnumType.OperationState.Run', 'Name' => 'Läuft'],
        ['Value' => 'BSH.Common.EnumType.OperationState.Finished', 'Name' => 'Beendet'],
    ]];
}
function IPS_GetLink(int $id): array { return ['TargetID' => 20]; }
function RequestAction(int $id, mixed $value): void { throw new RuntimeException('Export must be read-only'); }
function IPS_GetConfiguration(int $id): string { throw new RuntimeException('No config export'); }

$GLOBALS['objects'] = [10 => ['ParentID' => 0, 'ObjectType' => 0, 'ObjectName' => 'Home Connect']];
$GLOBALS['instances'] = $GLOBALS['variables'] = $GLOBALS['values'] = [];
foreach (['Dampf-Backofen', 'Geschirrspüler', 'Kaffeevollautomat', 'Kochfeld', 'Micro-Backofen', 'Waschtrockner'] as $i => $name) {
    $id = 20 + $i;
    $GLOBALS['objects'][$id] = ['ParentID' => 10, 'ObjectType' => 1, 'ObjectName' => $name];
    $GLOBALS['instances'][$id] = ['ModuleInfo' => ['ModuleID' => RCCHomeConnectExport::MODULE_ID], 'InstanceStatus' => 102];
}
function hcVar(int $id, string $ident, mixed $value, int $customAction = 0, int $parent = 25): void
{
    $GLOBALS['objects'][$id] = ['ParentID' => $parent, 'ObjectType' => 2, 'ObjectName' => $ident, 'ObjectIdent' => $ident];
    $GLOBALS['variables'][$id] = ['VariableType' => is_string($value) ? 3 : (is_bool($value) ? 0 : 1),
        'VariableAction' => 25, 'VariableCustomAction' => $customAction,
        'VariableProfile' => $ident === 'OperationState' ? 'HomeConnect.OperationState' : ''];
    $GLOBALS['values'][$id] = $value;
}
hcVar(101, 'OperationState', 'BSH.Common.EnumType.OperationState.Run');
hcVar(102, 'RemoteControlActive', false, 1);
hcVar(103, 'OptionRemainingProgramTime', 0);
hcVar(104, 'SelectedProgram', 'LaundryCare.Washer.Program.Cotton');
hcVar(105, 'UnknownFutureValue', 42);
hcVar(106, 'Password', 'PRIVATE_PASSWORD');
hcVar(107, 'Debug', 'https://private.example/token');
hcVar(108, 'HTML', '<p>PRIVATE_HTML</p>');
$GLOBALS['objects'][30] = ['ParentID' => 10, 'ObjectType' => 6, 'ObjectName' => 'Dampf-Link'];
$GLOBALS['objects'][31] = ['ParentID' => 10, 'ObjectType' => 1, 'ObjectName' => 'Home Connect impostor'];
$GLOBALS['instances'][31] = ['ModuleInfo' => ['ModuleID' => 'FOREIGN'], 'InstanceStatus' => 102];
hcVar(109, 'UnrelatedValue', 'PRIVATE_FOREIGN', 0, 31);

$before = serialize([$GLOBALS['objects'], $GLOBALS['variables'], $GLOBALS['values']]);
$report = RCCHomeConnectExport::scan(10);
hcCheck($report['complete'] && $report['deviceCount'] === 6 && $report['variableCount'] === 8,
    'Whole category exports six appliances once, excluding unrelated module variables');
$rows = array_column($report['devices'][5]['variables'], null, 'ident');
hcCheck($rows['RemoteControlActive']['rawValue'] === false && !$rows['RemoteControlActive']['hasAction']
    && $rows['RemoteControlActive']['actionDisabled'], 'False survives and disabled standard actions stay disabled');
hcCheck($rows['OptionRemainingProgramTime']['rawValue'] === 0 && $rows['SelectedProgram']['hasAction'],
    'Zero runtime and actionable programs survive');
hcCheck($rows['OperationState']['rawValue'] === 'BSH.Common.EnumType.OperationState.Run'
    && count($rows['OperationState']['profile']['Associations']) === 2, 'Exact enum codes and translations survive');
hcCheck($rows['UnknownFutureValue']['rawValue'] === 42, 'Unknown variables are included for later mapping');
$json = json_encode($report, JSON_THROW_ON_ERROR);
foreach (['PRIVATE_PASSWORD', 'private.example', 'PRIVATE_HTML', 'PRIVATE_FOREIGN'] as $secret) {
    hcCheck(!str_contains($json, $secret), 'Sensitive values are omitted: ' . $secret);
}
hcCheck($before === serialize([$GLOBALS['objects'], $GLOBALS['variables'], $GLOBALS['values']]),
    'Inventory never changes device values or object metadata');
hcCheck(RCCHomeConnectExport::scan(25)['deviceCount'] === 1, 'Single device is a supported root');

final class HomeConnectExportFixture
{
    use RCCHomeConnectEditorTrait;
    public array $updates = [];
    public function UpdateFormField($name, $field, $value): void { $this->updates[$name][$field] = $value; }
}
$editor = new HomeConnectExportFixture();
ob_start(); $editor->ExportHomeConnectJson(10); ob_end_clean();
hcCheck(json_decode($editor->updates['HomeConnectExportJson']['value'], true)['deviceCount'] === 6,
    'Form exports without catalog or EDIT prerequisites');
ob_start(); $editor->ExportHomeConnectJson(999); ob_end_clean();
hcCheck($editor->updates['HomeConnectExportJson']['value'] === '', 'Invalid root clears stale exported data');
for ($i = 200; $i < 227; $i++) {
    $GLOBALS['objects'][$i] = ['ParentID' => $i === 200 ? 10 : $i - 1, 'ObjectType' => 0, 'ObjectName' => 'Nested'];
}
hcCheck(!RCCHomeConnectExport::scan(10)['complete'], 'Scan depth limits are reported as an incomplete inventory');
echo "PASS Home Connect category export, exact states, profiles, disabled actions, secrets, limits and form handling\n";
