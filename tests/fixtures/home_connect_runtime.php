<?php
declare(strict_types=1);
require_once __DIR__ . '/../../IPSAlexaHaussteuerung/resources/helpers/HomeConnect.php';
$fixture = json_decode(file_get_contents(__DIR__ . '/home_connect_devices.json'), true, 512, JSON_THROW_ON_ERROR);
$GLOBALS['hcObjects'] = [50710=>['ObjectName'=>'Home Connect','ParentID'=>0,'ObjectType'=>0]];
$GLOBALS['hcInstances'] = $GLOBALS['hcVariables'] = $GLOBALS['hcValues'] = $GLOBALS['hcProfiles'] = $GLOBALS['hcTypes'] = [];
$GLOBALS['hcWrites'] = [];
foreach ($fixture as $d) {
    $GLOBALS['hcObjects'][$d['id']] = ['ObjectName'=>$d['name'],'ParentID'=>$d['parentId'],'ObjectType'=>1];
    $GLOBALS['hcInstances'][$d['id']] = ['ModuleInfo'=>['ModuleID'=>IAHHomeConnect::MODULE_ID], 'InstanceStatus'=>102];
    $GLOBALS['hcTypes'][$d['id']] = $d['deviceType'];
    foreach ($d['variables'] as $v) {
        $id = $v['id'];
        $GLOBALS['hcObjects'][$id] = ['ObjectName'=>$v['name'],'ObjectIdent'=>$v['ident'],'ObjectType'=>2,'ParentID'=>$d['id']];
        $GLOBALS['hcVariables'][$id] = ['VariableType'=>$v['type'],'VariableUpdated'=>$v['updatedAt'],
            'VariableProfile'=>'fixture_' . $id, 'VariableCustomAction'=>0, 'VariableAction'=>$v['hasAction'] ? $d['id'] : 0];
        $GLOBALS['hcValues'][$id] = $v['rawValue'];
        $GLOBALS['hcProfiles']['fixture_' . $id] = $v['profile'];
    }
}
function hcAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['hcInstances'][$id]); }
function IPS_ObjectExists(int $id): bool { return isset($GLOBALS['hcObjects'][$id]); }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['hcVariables'][$id]); }
function IPS_GetInstance(int $id): array { return $GLOBALS['hcInstances'][$id]; }
function IPS_GetInstanceListByModuleID(string $module): array
{ return array_keys(array_filter($GLOBALS['hcInstances'], static fn($i): bool => ($i['ModuleInfo']['ModuleID'] ?? '') === $module)); }
function IPS_GetObject(int $id): array { return $GLOBALS['hcObjects'][$id] ?? throw new RuntimeException('Missing object'); }
function IPS_GetVariable(int $id): array { return $GLOBALS['hcVariables'][$id] ?? throw new RuntimeException('Missing variable'); }
function IPS_GetProperty(int $id, string $property): string
{ hcAssert($property === 'DeviceType', 'Only device type may be read'); return $GLOBALS['hcTypes'][$id]; }
function IPS_GetChildrenIDs(int $id): array
{ return array_keys(array_filter($GLOBALS['hcObjects'], static fn($o): bool => $o['ParentID'] === $id)); }
function IPS_VariableProfileExists(string $name): bool { return isset($GLOBALS['hcProfiles'][$name]); }
function IPS_GetVariableProfile(string $name): array { return $GLOBALS['hcProfiles'][$name]; }
function GetValue(int $id): mixed { return $GLOBALS['hcValues'][$id] ?? throw new RuntimeException('Missing value'); }
function RequestAction(int $id, mixed $value): bool { $GLOBALS['hcWrites'][]=[$id,$value]; $GLOBALS['hcValues'][$id]=$value; return true; }
function IPS_LogMessage(string $sender, string $message): void {}
