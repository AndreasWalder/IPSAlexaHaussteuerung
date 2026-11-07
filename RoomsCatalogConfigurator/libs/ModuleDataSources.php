<?php
declare(strict_types=1);

/**
 * RoomsCatalog V3.6 – provider-based, read-only IP-Symcon module data sources.
 * HuaweiPV provider schema is based on peterchrisben/HuaweiPV:
 * libs/HPVRegisters.php (TOPICS/Definitions/Derived), module.json.
 *
 * No external HTTP requests, no HuaweiPV module dependency at runtime,
 * no RequestAction, no Archive Control mutation.
 */
final class RCCModuleDataSources
{
    public const HUAWEI_MODULE='{7C3D7EE3-1DE2-4C16-9BC4-AC1F091695E4}';

    /** Ident-to-topic mapping matches the upstream provider, not UI names. */
    public static function topics(): array
    {
        return [
            'pv'=>['PVPower','DailyYield','TotalYield','PV1Power','PV1Voltage','PV1Current','PV2Power','PV2Voltage','PV2Current'],
            'battery'=>['BatterySOC','BatteryPower','BatteryStatus','BatteryWorkingMode','BatteryChargeToday','BatteryDischargeToday','BatteryChargeTotal','BatteryDischargeTotal','BatteryTemp'],
            'grid'=>['GridPower','GridFeedIn','GridImport','GridExportTotal','GridImportTotal','GridPowerA','GridPowerB','GridPowerC','GridVoltageA','GridVoltageB','GridVoltageC','GridCurrentA','GridCurrentB','GridCurrentC','MeterFrequency','MeterStatus'],
            'house'=>['HouseConsumption','SelfSufficiency','SelfConsumptionRate','HouseEnergyToday','GridImportToday','GridExportToday'],
            'inverter'=>['ActivePower','DeviceStatus','FaultCode','InverterTemp','Efficiency','GridFrequency','PhaseVoltageA','PhaseVoltageB','PhaseVoltageC'],
            'device'=>['Model','SerialNumber','SoftwareVersion','FirmwareVersion','RatedPower','BatterySoftwareVersion','BatteryCapacity','BatteryMaxChargeLimit','BatteryMaxDischargeLimit'],
            'diag'=>['Online','LastSuccess','ErrorsToday','RejectedToday','LastError'],
            'settings'=>['WorkingModeSetting','MaxChargePower','MaxDischargePower','ChargeCutoff','DischargeCutoff','BackupSOC','GridCharge','GridChargeCutoff','GridChargePower'],
            'force'=>['ForceMode','ForcePower','ForceDuration','ForceTargetSOC','ForceEndTime'],
            'powerctl'=>['PowerControlMode','MaxFeedInPower','MaxFeedInPercent','InverterOn']
        ];
    }

    public static function units(): array
    {
        return [
            'PVPower'=>'W','ActivePower'=>'W','PV1Power'=>'W','PV2Power'=>'W',
            'GridPower'=>'W','GridFeedIn'=>'W','GridImport'=>'W',
            'HouseConsumption'=>'W','BatteryPower'=>'W',
            'GridPowerA'=>'W','GridPowerB'=>'W','GridPowerC'=>'W',
            'MaxChargePower'=>'W','MaxDischargePower'=>'W','GridChargePower'=>'W',
            'RatedPower'=>'W','MaxFeedInPower'=>'W','BatteryMaxChargeLimit'=>'W','BatteryMaxDischargeLimit'=>'W',
            'ForcePower'=>'W',
            'DailyYield'=>'kWh','TotalYield'=>'kWh','GridExportTotal'=>'kWh','GridImportTotal'=>'kWh',
            'BatteryChargeToday'=>'kWh','BatteryDischargeToday'=>'kWh',
            'BatteryChargeTotal'=>'kWh','BatteryDischargeTotal'=>'kWh',
            'HouseEnergyToday'=>'kWh','GridImportToday'=>'kWh','GridExportToday'=>'kWh',
            'BatteryCapacity'=>'kWh',
            'BatterySOC'=>'%','SelfSufficiency'=>'%','SelfConsumptionRate'=>'%',
            'Efficiency'=>'%','ChargeCutoff'=>'%','DischargeCutoff'=>'%',
            'BackupSOC'=>'%','GridChargeCutoff'=>'%','ForceTargetSOC'=>'%','MaxFeedInPercent'=>'%',
            'PV1Voltage'=>'V','PV2Voltage'=>'V','PhaseVoltageA'=>'V','PhaseVoltageB'=>'V','PhaseVoltageC'=>'V',
            'GridVoltageA'=>'V','GridVoltageB'=>'V','GridVoltageC'=>'V',
            'PV1Current'=>'A','PV2Current'=>'A','GridCurrentA'=>'A','GridCurrentB'=>'A','GridCurrentC'=>'A',
            'GridFrequency'=>'Hz','MeterFrequency'=>'Hz',
            'BatteryTemp'=>'°C','InverterTemp'=>'°C',
            'ForceDuration'=>'min'
        ];
    }

    private static function measurementKind(string $ident): string
    {
        if (in_array($ident,['TotalYield','GridExportTotal','GridImportTotal',
            'BatteryChargeTotal','BatteryDischargeTotal'],true)) return 'counter';
        if (in_array($ident,['DailyYield','BatteryChargeToday','BatteryDischargeToday',
            'HouseEnergyToday','GridImportToday','GridExportToday'],true)) return 'daily';
        if (in_array($ident,['Model','SerialNumber','SoftwareVersion','FirmwareVersion',
            'BatterySoftwareVersion','LastError'],true)) return 'text';
        return 'gauge';
    }

    private static function topicFor(string $ident): string
    {
        foreach (self::topics() as $topic=>$ids)
            if (in_array($ident,$ids,true)) return $topic;
        return '';
    }

    private static function moduleValid(int $sourceId): bool
    {
        if ($sourceId<=0 || !function_exists('IPS_InstanceExists')
            || !IPS_InstanceExists($sourceId) || !function_exists('IPS_GetInstance'))
            return false;
        $instance=IPS_GetInstance($sourceId);
        return strtoupper((string)($instance['ModuleInfo']['ModuleID']??''))
            ===self::HUAWEI_MODULE;
    }

    /**
     * The provider may use Dummy Module topic folders, which the simple
     * guided discovery deliberately does not traverse.
     */
    public static function scanHuawei(int $sourceId): array
    {
        if (!self::moduleValid($sourceId))
            throw new RuntimeException('Ausgewaehltes Objekt ist keine HuaweiPV-Instanz.');
        foreach(['IPS_GetChildrenIDs','IPS_GetObject','IPS_VariableExists',
            'IPS_GetVariable','IPS_ObjectExists'] as $required)
            if (!function_exists($required))
                throw new RuntimeException('IP-Symcon-Objektfunktionen fehlen: '.$required);
        $recognized=[];
        $skipped=[];$conflicts=[];
        $queue=[[$sourceId,0]];$seen=[];
        $allowed=[];
        foreach (self::topics() as $idents)
            foreach ($idents as $ident) $allowed[$ident]=true;
        while($queue!==[] && count($seen)<300) {
            [$parent,$depth]=array_shift($queue);
            if(isset($seen[$parent]))continue;
            $seen[$parent]=true;
            $children=IPS_GetChildrenIDs($parent);
            if(!is_array($children))continue;
            foreach ($children as $childId) {
                $childId=(int)$childId;
                if ($childId<=0 || !IPS_ObjectExists($childId))continue;
                $object=IPS_GetObject($childId);
                $type=(int)($object['ObjectType']??-1);
                if ($type===1 && $depth<4) {
                    $queue[]=[$childId,$depth+1]; // Dummy Module / topic folder
                    continue;
                }
                if ($type===0 && $depth<4) {
                    $queue[]=[$childId,$depth+1];
                    continue;
                }
                if ($type!==2 || !IPS_VariableExists($childId))continue;
                $ident=(string)($object['ObjectIdent']??'');
                if ($ident!=='' && str_ends_with($ident,'KW')
                    && isset($allowed[substr($ident,0,-2)])) {
                    $skipped[]=$ident.' (kW-Duplikat)';
                    continue;
                }
                if (!isset($allowed[$ident])) {
                    $skipped[]=$ident!==''?$ident:('#'.$childId);
                    continue;
                }
                if (isset($recognized[$ident])) {
                    $conflicts[]='Doppelter Ident '.$ident.' (#'.$childId.')';
                    continue;
                }
                $variable=IPS_GetVariable($childId);
                $vt=(int)($variable['VariableType']??-1);
                if (!in_array($vt,[0,1,2,3],true)) {
                    $conflicts[]='Unbekannter Datentyp bei '.$ident;
                    continue;
                }
                $topic=self::topicFor($ident);
                // Serial number is sensitive; never grant to external AI.
                if ($ident==='SerialNumber') {
                    $skipped[]='SerialNumber (Datenschutz)';
                    continue;
                }
                $label=mb_substr((string)($object['ObjectName']??$ident),0,100,'UTF-8');
                $isWritable=(int)($variable['VariableAction']??0)>0
                    || (int)($variable['VariableCustomAction']??0)>1;
                $recognized[$ident]=[
                    'id'=>$childId,
                    'ident'=>$ident,
                    'label'=>$label!==''?$label:$ident,
                    'topic'=>$topic,
                    'unit'=>self::units()[$ident]??'',
                    'kind'=>self::measurementKind($ident),
                    'valueType'=>$vt,
                    'readOnly'=>true,
                    'writableInIPSymcon'=>$isWritable,
                    'enabled'=>true
                ];
            }
        }
        if ($queue!==[]) $conflicts[]='Scanlimit erreicht; Ergebnis unvollstaendig.';
        if ($conflicts!==[])
            throw new RuntimeException('HuaweiPV-Import abgebrochen: '.implode('; ',array_slice($conflicts,0,5)));
        if ($recognized===[])
            throw new RuntimeException('Keine HuaweiPV-Variablen mit gueltigem ObjectIdent gefunden.');
        // Canonical deterministic order, independent of IPS_Object Position.
        ksort($recognized,SORT_STRING);
        $settings=['gridSign'=>0,'batterySign'=>0];
        if (function_exists('IPS_GetProperty')) {
            foreach(['GridSign'=>'gridSign','BatterySign'=>'batterySign'] as $key=>$field) {
                try { $settings[$field]=(int)IPS_GetProperty($sourceId,$key); }
                catch(Throwable $ignored) {}
            }
        }
        $name=function_exists('IPS_GetName') ? (string)IPS_GetName($sourceId) : 'Huawei Sun 2000 PV';
        return [
            'version'=>1,'provider'=>'HuaweiPV','sourceInstanceId'=>$sourceId,
            'moduleId'=>self::HUAWEI_MODULE,
            'title'=>$name,
            'domain'=>'energie',
            'kiEnabled'=>true,
            'kiReadOnly'=>true,
            'allowWrites'=>false,
            'signConventions'=>$settings,
            'metrics'=>$recognized,
            'readOnly'=>true,
            'discoveredAt'=>null,
            'scanSummary'=>[
                'recognized'=>count($recognized),
                'excluded'=>array_slice($skipped,0,60),
                'topics'=>array_count_values(array_column($recognized,'topic')),
                'scannedNodes'=>count($seen)
            ]
        ];
    }
}
