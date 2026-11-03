<?php
declare(strict_types=1);

/**
 * V3.3.12 – deterministic proposals for missing feedback variables.
 * No changes to permissions, device values, or real-world state.
 */
final class RCCSafeAutoRepair
{
    private const REQUIRED = [
        'light'=>['power','status'], 'dimmer'=>['brightness'],
        'blind'=>['position','status'], 'heating'=>['temperature'],
        'window'=>['open','status'], 'switch'=>['power','status'],
        'fan'=>['power','status'], 'motion'=>['motion'],
        'illuminance'=>['illuminance'], 'energy'=>['energy','powerWatts']
    ];

    private const TYPES = [
        'power'=>[0,1], 'status'=>[0,1,2,3], 'brightness'=>[1,2],
        'position'=>[1,2], 'open'=>[0,1], 'temperature'=>[1,2],
        'motion'=>[0,1], 'illuminance'=>[2], 'energy'=>[1,2],
        'powerWatts'=>[1,2]
    ];

    public static function propose(array $row, array $scoped, callable $score, array $reservedIds=[]): array
    {
        $ai=$row['ai'] ?? null;
        $type=(string)($row['aiType'] ?? '');
        if (!is_array($ai) || !is_array($ai['variables'] ?? null)
            || ($ai['review']['status'] ?? '')==='confirmed'
            || !isset(self::REQUIRED[$type])) return [];
        $bindings=$ai['variables'];
        $heatingFix=self::heatingSetpointAction($row,$scoped,$reservedIds);
        if ($heatingFix!==[]) return $heatingFix;
        $knxLightFix=self::knxLightFeedback($row,$scoped,$reservedIds);
        if ($knxLightFix!==[]) return $knxLightFix;
        foreach (self::REQUIRED[$type] as $role) {
            if ((int)($bindings[$role] ?? 0)>0) return [];
        }
        $used=array_map('intval',array_values($bindings));
        $ranked=[];
        foreach ($scoped as $variable) {
            $id=(int)($variable['id']??0);
            $kind=(int)($variable['type']??-1);
            if ($id<=0 || in_array($id,$used,true) || isset($reservedIds[$id])) continue;
            foreach (self::REQUIRED[$type] as $role) {
                if (!in_array($kind,self::TYPES[$role]??[],true)) continue;
                if (!self::credible($type,$role,$variable)) continue;
                $rank=(int)$score($variable,$role);
                if ($rank>0) $ranked[]=['role'=>$role,'id'=>$id,'score'=>$rank];
            }
        }
        usort($ranked,static fn(array $a,array $b):int=>
            $b['score']<=>$a['score'] ?: $a['id']<=>$b['id']);
        $top=$ranked[0]??null;
        if ($top===null || $top['score']<135
            || $top['score']-(int)($ranked[1]['score']??0)<25) return [];
        return [$top['role']=>$top['id']];
    }

    /**
     * The KNX readable targetTemperature and the writable setpoint are
     * sometimes two different variables in the same anchored scope.
     * Only substitute a currently UNWRITABLE control mapping when the
     * matching read-only targetTemperature ID is identical, a unique
     * writable temperature setpoint is present, and KI is read-only.
     */
    private static function heatingSetpointAction(array $row,array $scoped,array $reservedIds): array
    {
        if ((string)($row['aiType']??'')!=='heating' || empty($row['kiReadOnly'])) return [];
        $vars=(array)($row['ai']['variables']??[]);
        $current=(int)($vars['control']??0);
        $target=(int)($vars['targetTemperature']??0);
        $measured=(int)($vars['temperature']??0);
        if ($current<=0 || $target!==$current || $measured<=0
            || (int)($row['soll']??0)!==$current
            || (int)($row['ist']??0)!==$measured) return [];
        $scopeById=[];
        foreach ($scoped as $v) {
            $id=(int)($v['id']??0);
            if($id>0) $scopeById[$id]=$v;
        }
        $old=$scopeById[$current]??null;
        if (!is_array($old) || (int)($old['type']??-1)!==2
            || !empty($old['action'])
            || !str_contains(strtolower((string)($old['profile']??'')),'temperature')) return [];
        $candidates=[];
        foreach ($scopeById as $id=>$v) {
            if ($id===$current || $id===$measured || isset($reservedIds[$id])
                || (int)($v['type']??-1)!==2 || empty($v['action'])) continue;
            $name=mb_strtolower((string)($v['name']??''),'UTF-8');
            $profile=strtolower((string)($v['profile']??''));
            if (!str_contains($profile,'temperature')) continue;
            $normalized=str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],$name);
            if (str_contains($normalized,'solltemperatur') || str_contains($normalized,'setpoint')) {
                $candidates[$id]=true;
            }
        }
        if (count($candidates)!==1) return [];
        return ['control'=>(int)array_key_first($candidates)];
    }


    /**
     * KNX 2-bit control DPT2 Value0 is an enable/priority flag, not an
     * on/off feedback. Replace only when the same scoped DPT2 instance
     * exposes exactly one separate, read-only Switch Value1 / Funktionswert
     * with explicit Aus/An profile associations.
     *
     * The related legacy "state" field must be changed together with
     * ai.variables.power and status, so both AI and legacy readers agree.
     * "control" and all KI rights are left intact.
     */
    private static function knxLightFeedback(array $row,array $scoped,array $reservedIds): array
    {
        if ((string)($row['aiType']??'')!=='light' || empty($row['kiReadOnly'])) return [];
        $roles=(array)($row['ai']['variables']??[]);
        $old=(int)($roles['power']??0);
        if ($old<=0 || (int)($roles['status']??0)!==$old
            || (int)($row['state']??0)!==$old
            || (int)($roles['control']??0)<=0) return [];
        $scope=[];
        foreach ($scoped as $v) {
            $id=(int)($v['id']??0);
            if ($id>0) $scope[$id]=$v;
        }
        $priority=$scope[$old]??null;
        if (!is_array($priority)
            || (int)($priority['type']??-1)!==0
            || strtolower((string)($priority['ident']??''))!=='value0'
            || !str_contains(strtolower((string)($priority['profile']??'')),'pdpt2')) return [];
        $names=[];
        foreach ((array)($priority['associations']??[]) as $a) {
            $names[]=self::norm((string)($a['name']??''));
        }
        if (!in_array('prioritaet',$names,true) || !in_array('keineprioritaet',$names,true)) return [];
        $candidates=[];
        foreach ($scope as $id=>$v) {
            if ($id===$old || isset($reservedIds[$id])
                || (int)($v['type']??-1)!==0 || !empty($v['action'])
                || strtolower((string)($v['ident']??''))!=='value1'
                || !str_contains(strtolower((string)($v['profile']??'')),'switch')
                || self::norm((string)($v['name']??''))!=='funktionswert') continue;
            $states=[];
            foreach ((array)($v['associations']??[]) as $a) {
                $states[]=['v'=>$a['value']??null,'name'=>self::norm((string)($a['name']??''))];
            }
            $hasOn=false;
            $hasOff=false;
            foreach ($states as $state) {
                if ($state['v']===true && in_array($state['name'],['an','ein'],true)) $hasOn=true;
                if ($state['v']===false && in_array($state['name'],['aus'],true)) $hasOff=true;
            }
            if ($hasOn && $hasOff) $candidates[$id]=true;
        }
        if (count($candidates)!==1) return [];
        $id=(int)array_key_first($candidates);
        return ['power'=>$id,'status'=>$id];
    }

    private static function norm(string $value): string
    {
        $value=mb_strtolower(trim($value),'UTF-8');
        $value=str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],$value);
        return preg_replace('/[^a-z0-9]/','',$value)??'';
    }

    private static function credible(string $type,string $role,array $variable): bool
    {
        if ($type!=='blind' && $type!=='window') return true;
        $name=strtolower((string)($variable['name']??'').' '.(string)($variable['ident']??''));
        $profile=strtolower((string)($variable['profile']??''));
        if ($type==='blind' && $role==='position') {
            return str_contains($name,'position') || str_contains($name,'stellung')
                || str_contains($profile,'shutterposition')
                || str_contains($profile,'behangposition');
        }
        if ($role==='status') {
            return str_contains($name,'status') || str_contains($name,'zustand')
                || str_contains($profile,'shutter') || str_contains($profile,'blind')
                || str_contains($profile,'contact')
                || count((array)($variable['associations']??[]))>=2;
        }
        return true;
    }
    /** Apply only explicitly proposed additions to ai.variables; all other fields stay untouched. */
    public static function applyBindings(array $catalog, array $changes): array
    {
        $next=$catalog;
        if (isset($next['rooms']) && is_array($next['rooms'])) $rooms=&$next['rooms'];
        else $rooms=&$next;
        foreach ($changes as $key=>$bindings) {
            if (!is_array($bindings) || $bindings===[]) continue;
            $parts=explode('|',(string)$key,4);
            if (count($parts)!==4) throw new RuntimeException('Ungueltiger Reparaturschluessel');
            [$room,$domain,$group,$device]=$parts;
            $path=in_array($domain,['heizung','jalousie'],true)
                ? [$room,'domains',$domain,$device]
                : [$room,'domains',$domain,$group,$device];
            $item=&$rooms;
            foreach ($path as $part) {
                if (!is_array($item) || !array_key_exists($part,$item))
                    throw new RuntimeException('Geraet nicht gefunden: '.$key);
                $item=&$item[$part];
            }
            if (!is_array($item) || !is_array($item['ai']??null)
                || !is_array($item['ai']['variables']??null)
                || ($item['ai']['review']['status']??'')==='confirmed')
                throw new RuntimeException('Keine sichere Zuordnung fuer '.$key);
            $lightFix=(count($bindings)===2
                && array_key_exists('power',$bindings)
                && array_key_exists('status',$bindings)
                && (string)($item['ai']['type']??'')==='light'
                && !empty($item['kiReadOnly'])
                && (int)$bindings['power']===(int)$bindings['status']
                && (int)$bindings['power']>0
                && (int)($item['ai']['variables']['power']??0)>0
                && (int)($item['ai']['variables']['power']??0)===(int)($item['ai']['variables']['status']??0)
                && (int)($item['state']??0)===(int)($item['ai']['variables']['power']??0));
            if ($lightFix) {
                $item['state']=(int)$bindings['power'];
                $item['ai']['variables']['power']=(int)$bindings['power'];
                $item['ai']['variables']['status']=(int)$bindings['status'];
                unset($item);
                continue;
            }
            foreach ($bindings as $role=>$id) {
                $existing=(int)($item['ai']['variables'][$role]??0);
                if ((int)$id<=0 || $existing===(int)$id)
                    throw new RuntimeException('Rolle nicht sicher zu ersetzen: '.$key.'/'.$role);
                // Only this exact legacy KNX setpoint case is an authorized
                // replacement. All other changes must be strictly additive.
                if ($existing>0 && !(
                    $role==='control'
                    && (string)($item['ai']['type']??'')==='heating'
                    && $existing===(int)($item['ai']['variables']['targetTemperature']??0)
                    && !empty($item['kiReadOnly'])
                )) throw new RuntimeException('Rolle bereits belegt: '.$key.'/'.$role);
                $item['ai']['variables'][$role]=(int)$id;
            }
            unset($item);
        }
        unset($rooms);
        return $next;
    }

}
