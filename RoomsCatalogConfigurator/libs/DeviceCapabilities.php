<?php
declare(strict_types=1);

/** Dynamic, non-persistent capability overview for RoomsCatalog EDIT gear. */
final class RCCDeviceCapabilities
{
    public static function describe(array $row): array
    {
        $type=(string)($row['aiType']??'');
        $roles=RCCDeviceTypeRegistry::roles()[$type]??[];
        $fields=RCCDeviceTypeRegistry::fields();
        $names=RCCDeviceTypeRegistry::propertyCaptions();
        $reads=[];$commands=[];
        $candidates=match($type){
            'light','switch','fan'=>['power'=>'control'],
            'dimmer'=>['power'=>'control','brightness'=>'brightness'],
            'blind'=>['targetPosition'=>'targetPosition'],
            'heating'=>['targetTemperature'=>'targetTemperature'],
            default=>[]
        };
        foreach($roles as $role) {
            $id=(int)($row[$fields[$role]??'']??0);
            if($id<=0 || !function_exists('IPS_VariableExists')
                || !IPS_VariableExists($id))continue;
            if(!in_array($role,['control','action'],true))
                $reads[]=(string)($names[$role]??$role).' (#'.$id.')';
        }
        foreach($candidates as $role=>$field){
            $id=(int)($row[$fields[$field]??'']??0);
            if($id<=0||!function_exists('IPS_VariableExists')
                || !IPS_VariableExists($id)||!function_exists('HasAction')
                || !HasAction($id))continue;
            if($type==='blind' && in_array($id,[
                (int)($row['aiPositionId']??0),
                (int)($row['aiActionId']??0)],true))continue;
            $commands[]=$role.' (#'.$id.')';
        }
        $writeAllowed=!empty($row['kiEnabled'])
            && array_key_exists('kiReadOnly',$row)
            && $row['kiReadOnly']===false
            && !empty($row['aiReviewed']);
        return [
            'readable'=>$reads,
            'availableCommands'=>$commands,
            'usableInVoiceScene'=>$writeAllowed&&$commands!==[],
            'message'=>'Lese-Eigenschaften: '.($reads?implode(', ',$reads):'keine').
                '. Technisch mögliche Aktionen: '.($commands?implode(', ',$commands):'keine').
                '. Für Alexa-Szenen: '.($writeAllowed&&$commands!==[]
                    ?'freigegeben, sofern PROD ebenfalls fachlich bestätigt ist'
                    :'noch nicht für das Schalten freigegeben').
                '. Die Szene wird zentral in IPSAlexaHaussteuerung gepflegt.'
        ];
    }
}
