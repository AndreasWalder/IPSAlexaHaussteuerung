<?php
declare(strict_types=1);

if (!class_exists('IAHHomeConnect', false)) require_once __DIR__ . '/HomeConnect.php';

/** Provider-independent household catalog, shared by EDIT UI and the Alexa runtime. */
final class IAHHouseholdDevices
{
    public const KEY = 'household_devices';
    public const PROVIDER = 'HouseholdDevices';

    public static function source(array $catalog): ?array
    {
        $source=$catalog['dataSources'][self::KEY]??null;
        return is_array($source) && ($source['provider']??'')===self::PROVIDER && ($source['schemaVersion']??0)===1 ? $source : null;
    }

    public static function metric(int $id, string $title = ''): array
    {
        if ($id<=0 || !IPS_VariableExists($id)) throw new RuntimeException('Variable nicht vorhanden.');
        $o=IPS_GetObject($id); $v=IPS_GetVariable($id);
        return ['id'=>$id,'parent'=>(int)$o['ParentID'],'ident'=>(string)($o['ObjectIdent']??''),
            'type'=>(int)$v['VariableType'],'title'=>$title!==''?$title:IAHHomeConnect::clean((string)$o['ObjectName']),
            'enabled'=>true,'writable'=>false,'role'=>'other'];
    }

    public static function matches(array $m): bool
    {
        try {
            $id=(int)($m['id']??0);
            if (!IPS_VariableExists($id)) return false;
            $o=IPS_GetObject($id); $v=IPS_GetVariable($id);
            return (int)$o['ParentID']===($m['parent']??null) && (string)($o['ObjectIdent']??'')===($m['ident']??null)
                && (int)$v['VariableType']===($m['type']??null);
        } catch (Throwable $e) { return false; }
    }

    public static function reading(array $m): ?array
    {
        if (!self::matches($m)) return null;
        if (preg_match('/password|passwort|token|secret|credential|cookie|authorization/i',(string)$m['ident'].' '.(string)$m['title'])) return null;
        $id=(int)$m['id']; $v=IPS_GetVariable($id);
        $name=(string)($v['VariableCustomProfile']??'') ?: (string)($v['VariableProfile']??'');
        $profile=$name!=='' && IPS_VariableProfileExists($name)?IPS_GetVariableProfile($name):[];
        $raw=GetValue($id);
        if (!is_scalar($raw) || (is_float($raw)&&!is_finite($raw))
            || (is_string($raw)&&(strlen($raw)>2048||preg_match('~https?://|<[^>]+>~i',$raw)))) return null;
        $action=(int)($v['VariableCustomAction']??0);
        if ($action===0) $action=(int)($v['VariableAction']??0);
        return ['id'=>$id,'parent'=>$m['parent'],'ident'=>$m['ident'],'type'=>$m['type'],
            'name'=>(string)$m['title'],'raw'=>$raw,'profile'=>$profile,'action'=>$action>1,
            'updated'=>(int)($v['VariableUpdated']??0)];
    }

    public static function homeSource(array $source): array
    {
        $devices=[];
        foreach ((array)($source['devices']??[]) as $key=>$d) {
            if (($d['provider']??'')!=='HomeConnect') continue;
            $d['enabled']=!empty($source['kiEnabled'])&&!empty($d['enabled']);
            $devices[$key]=$d;
        }
        return ['provider'=>'HomeConnect','domain'=>'hausgeraete','kiEnabled'=>!empty($source['kiEnabled']),'devices'=>$devices];
    }

    public static function query(array $catalog,string $text,bool $allowWrites=false,int $selectedId=0): ?array
    {
        $source=self::source($catalog);
        if ($source===null) return null;
        $q=IAHHomeConnect::norm($text);
        $all=[];
        foreach ((array)($source['devices']??[]) as $d) {
            if (!empty($source['kiEnabled'])&&!empty($d['enabled'])) $all[]=$d;
        }
        $selected=IAHHomeConnect::select($all,$q);
        if ($selectedId>0) {
            $selected=array_values(array_filter($all,static fn($d):bool=>(int)$d['id']===$selectedId));
            $allowWrites=false;
            if ($selected===[]) return self::reply('Hausgeräte',[],'Das ausgewählte Hausgerät ist nicht mehr für Alexa verfügbar.');
        }
        if ($selected===[]&&IAHHomeConnect::select(array_values((array)($source['devices']??[])),$q)!==[]) {
            return self::reply('Hausgeräte',[],'Dieses Hausgerät ist im veröffentlichten RoomsCatalog für Alexa gesperrt.');
        }
        $overview=preg_match('/\b(?:hausgeraet\w*|haushaltsgeraet\w*)\b/',$q)===1;
        if ($selected===[]&&!$overview) return null;
        if (count($selected)>1) return IAHHomeConnect::clarification($selected);
        if ($selected!==[]) {
            $d=$selected[0];
            if (($d['provider']??'')==='HomeConnect') {
                $single=$source; $single['devices']=[$d];
                return IAHHomeConnect::query(['dataSources'=>['home_connect'=>self::homeSource($single)]],$text,$allowWrites,$selectedId);
            }
            $rows=[]; $command=preg_match('/^(?:bitte )?(?:schalte|stelle|setze|starte|stoppe|pausiere)\b/',$q)===1;
            if ($command) {
                $result='Für diesen Befehl ist keine eindeutige freigegebene Hausgerätefunktion hinterlegt.';
                $remainder=$q;
                foreach ((array)$d['aliases'] as $alias) {
                    $a=IAHHomeConnect::norm((string)$alias);
                    if ($a!=='') $remainder=preg_replace('/\b'.preg_quote($a,'/').'\b/',' ',$remainder)??$remainder;
                }
                $remainder=trim(preg_replace('/\s+/',' ',$remainder)??'');
                if ($allowWrites&&!empty($d['allowControl'])&&empty($d['approximate'])
                    &&preg_match('/^(?:bitte )?schalte (?:(?:die|das|den|meine|meinen|meiner|meinem) )?(ein|an|aus)$/D',$remainder,$switch)) {
                    $candidates=array_filter((array)$d['variables'],static fn($m):bool=>!empty($m['enabled'])&&!empty($m['writable'])&&($m['role']??'')==='power'&&($m['type']??-1)===0);
                    if (count($candidates)===1) {
                        $m=reset($candidates); $read=self::reading($m);
                        if ($read!==null&&$read['action']) {
                            try { $ok=RequestAction($read['id'],$switch[1]!=='aus'); $result=$ok===false?'Auftrag abgelehnt.':'Auftrag für '.$d['name'].' übergeben.'; }
                            catch (Throwable $e) { $result='Der Auftrag konnte nicht übergeben werden.'; }
                        }
                    }
                }
                return self::reply($d['name'],[['label'=>'Auftrag','value'=>$result]],$result)+['isCommand'=>true];
            }
            foreach ((array)$d['variables'] as $m) {
                if (empty($m['enabled'])) continue;
                $r=self::reading($m);
                $rows[]=['label'=>$m['title'],'value'=>$r!==null?IAHHomeConnect::display($r):'Wert nicht verfügbar'];
            }
            return self::reply($d['name'],$rows);
        }
        $rows=[];
        foreach ($all as $d) {
            $status='Keine Statusvariable zugeordnet';
            foreach ((array)$d['variables'] as $m) {
                if (empty($m['enabled'])||!in_array($m['role']??'',['status','power'],true)) continue;
                $r=self::reading($m); $status=$r!==null?IAHHomeConnect::display($r):'Wert nicht verfügbar'; break;
            }
            $rows[]=['label'=>$d['name'],'value'=>$status];
        }
        return self::reply('Hausgeräte',$rows,$rows===[]?'Es sind keine Hausgeräte für Alexa freigegeben.':null);
    }

    private static function reply(string $title,array $rows,?string $answer=null): array
    {
        return ['handled'=>true,'localHouseQuery'=>true,'answerSource'=>'home_connect_all','displayTitle'=>$title,
            'displayRows'=>$rows,'answer'=>$answer??$title.': '.implode('. ',array_map(static fn($r):string=>$r['label'].' '.$r['value'],array_slice($rows,0,4))).'.'];
    }
}
