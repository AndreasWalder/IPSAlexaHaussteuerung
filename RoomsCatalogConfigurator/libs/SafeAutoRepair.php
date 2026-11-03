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
            foreach ($bindings as $role=>$id) {
                if ((int)($item['ai']['variables'][$role]??0)>0 || (int)$id<=0)
                    throw new RuntimeException('Rolle bereits belegt: '.$key.'/'.$role);
                $item['ai']['variables'][$role]=(int)$id;
            }
            unset($item);
        }
        unset($rooms);
        return $next;
    }

}
