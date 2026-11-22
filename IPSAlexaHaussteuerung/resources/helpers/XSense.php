<?php
declare(strict_types=1);
if (!class_exists('IAHHouseholdDevices',false)) require_once __DIR__.'/HouseholdDevices.php';

/** Published, read-only X-Sense bindings. No IPs, network names or command payloads. */
final class IAHXSense
{
    public const KEY='xsense';
    public const PROVIDER='XSenseHomeAssistant';
    public const MODULE_ID='{72D6A284-1870-4E11-92D8-0402C8233C29}';
    public const ROLES=[
        'smoke'=>'Rauchalarm','waterLeak'=>'Wasseralarm','alarm'=>'Alarm der Basis',
        'batteryLevel'=>'Batterie (%)','batteryLow'=>'Batteriewarnung','online'=>'Erreichbar',
        'connected'=>'Verbunden','lifeEnd'=>'Lebensdauer beendet','muted'=>'Stummgeschaltet',
        'testActive'=>'Test aktiv','status'=>'Gerätestatus','signal'=>'Funkqualität',
        'reportTime'=>'Letzte Sensormeldung','setting'=>'Einstellung (nur lesen)',
        'command'=>'Befehlstaste (wird nicht ausgeführt)','other'=>'Weiterer Wert',
    ];

    public static function source(array $catalog): ?array
    {
        $s=$catalog['dataSources'][self::KEY]??null;
        return is_array($s)&&($s['provider']??'')===self::PROVIDER&&($s['schemaVersion']??0)===1?$s:null;
    }

    public static function instance(int $id): bool
    {
        try {return $id>0&&IPS_InstanceExists($id)&&strtoupper((string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??''))===self::MODULE_ID;}
        catch(Throwable $ignored){return false;}
    }

    public static function excluded(string $ident,string $name): bool
    {
        return preg_match('/password|passwort|token|secret|credential|cookie|authorization|ip.?address|ip.?adresse|ssid|mac.?address/i',$ident.' '.$name)===1;
    }

    public static function role(string $ident,string $name,string $kind,int $type): string
    {
        $q=IAHHomeConnect::norm($ident.' '.$name);
        if (preg_match('/veraltet|deprecated/',$q))return 'other';
        if (str_starts_with($ident,'button_')||($type===1&&preg_match('/\b(?:test|mute|fire drill)\b/',$q)))return 'command';
        if (preg_match('/report.?time|last.?seen|letzte.*meldung/',$q))return 'reportTime';
        if (preg_match('/is.?life.?end|lebensdauer/',$q))return 'lifeEnd';
        if (preg_match('/mute.?status|stumm/',$q)||($type===0&&preg_match('/\bmute\b/',$q)))return 'muted';
        if (preg_match('/drill.?active|test.?active|test aktiv/',$q))return 'testActive';
        if ($ident==='reachable'||preg_match('/\berreichbar\b/',$q))return 'online';
        if (preg_match('/\bconnected\b|verbunden/',$q))return 'connected';
        if (preg_match('/battery.?low|low.?battery|batterie.*(?:niedrig|warn)/',$q))return 'batteryLow';
        if (preg_match('/battery|batterie|akku/',$q)&&in_array($type,[1,2],true))return 'batteryLevel';
        if (preg_match('/rf.?level|signal|funk/',$q))return 'signal';
        if ($type===0&&preg_match('/smoke|\brauch\b|alarm.?status|alarmstatus|water.?alarm|water.?leak|wasseralarm|moisture/',$q))
            return match($kind){'smoke'=>'smoke','water'=>'waterLeak',default=>'alarm'};
        if (preg_match('/device.?status|geraetestatus/',$q))return 'status';
        if (preg_match('/volume|lautstaerke|brightness|helligkeit|tone|alarmton|safe.?mode|zone.?name|software.?version|led.?light/',$q))return 'setting';
        return 'other';
    }

    public static function metric(int $id,string $kind,string $title=''): array
    {
        $m=IAHHouseholdDevices::metric($id,$title);
        if (!self::instance($m['parent'])||self::excluded($m['ident'],$m['title']))throw new RuntimeException('Bitte eine zulässige Variable einer Home-Assistant-X-Sense-Instanz auswählen.');
        $m['role']=self::role($m['ident'],$m['title'],$kind,$m['type']);$m['activeWhen']=true;$m['writable']=false;
        return $m;
    }

    public static function discover(int $root=0): array
    {
        if (!function_exists('IPS_GetInstanceListByModuleID'))return [];
        $out=[];
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_ID) as $id)try {
            if (!self::instance((int)$id))continue;
            $o=IPS_GetObject($id);$name=IAHHomeConnect::clean((string)$o['ObjectName']);$context=$name;
            $parent=(int)$o['ParentID'];$underRoot=$root===(int)$id;$visited=[];
            for($n=0;$parent>0&&$n<12&&!isset($visited[$parent]);$n++){
                $visited[$parent]=true;if($parent===$root)$underRoot=true;
                $p=IPS_GetObject($parent);$context.=' '.(string)$p['ObjectName'];$parent=(int)$p['ParentID'];
            }
            if ($root>0&&!$underRoot)continue;
            $q=IAHHomeConnect::norm($context);
            if ($root===0&&!preg_match('/\bx ?sense\b|\bxsense\b/',$q))continue;
            $local=IAHHomeConnect::norm($name);
            $kind=match(true){preg_match('/basis|base|sbs50|sbs10/',$local)===1=>'base',preg_match('/wasser|water|sws51/',$local)===1=>'water',preg_match('/rauch|smoke|xs01|xs0b/',$local)===1=>'smoke',default=>'unknown'};
            $d=['id'=>(int)$id,'parent'=>(int)$o['ParentID'],'moduleId'=>self::MODULE_ID,'name'=>$name,'kind'=>$kind,
                'aliases'=>[$name],'enabled'=>true,'roomKey'=>'__xsense__','roomLabel'=>'Haus','floor'=>'—','group'=>'xsense','variables'=>[]];
            foreach (IPS_GetChildrenIDs($id) as $child){
                $c=IPS_GetObject($child);
                if ((int)$c['ObjectType']!==2||self::excluded((string)($c['ObjectIdent']??''),(string)$c['ObjectName']))continue;
                $d['variables']['var_'.$child]=self::metric((int)$child,$kind);
            }
            $out['device_'.$id]=$d;
        }catch(Throwable $ignored){}
        return $out;
    }

    public static function merge(array $catalog,int $root=0): array
    {
        if(isset($catalog['dataSources'][self::KEY])&&self::source($catalog)===null)throw new RuntimeException('Katalogschlüssel xsense ist bereits anders belegt.');
        $s=self::source($catalog)??['provider'=>self::PROVIDER,'schemaVersion'=>1,'domain'=>'devices','kiEnabled'=>true,'kiReadOnly'=>true,'devices'=>[]];
        foreach(self::discover($root) as $key=>$d){
            if(!isset($s['devices'][$key])){$s['devices'][$key]=$d;continue;}
            $known=array_column((array)$s['devices'][$key]['variables'],'id');
            foreach($d['variables'] as $mk=>$m)if(!in_array($m['id'],$known,true)&&!isset($s['devices'][$key]['variables'][$mk]))$s['devices'][$key]['variables'][$mk]=$m;
        }
        if($s['devices']!==[]||self::source($catalog)!==null)$catalog['dataSources'][self::KEY]=$s;
        return $catalog;
    }

    public static function devices(array $catalog): array
    {
        $s=self::source($catalog);if(empty($s['kiEnabled']))return [];
        return array_filter((array)$s['devices'],static function($d)use($catalog):bool{
            if(empty($d['enabled'])||!self::instance((int)$d['id']))return false;
            $room=($catalog['rooms']??$catalog)[$d['roomKey']]??[];
            return empty($room['excludeFromAI'])&&empty($room['aiTestRoom'])&&IAHHomeConnect::norm((string)$d['roomKey'])!=='laptop';
        });
    }

    public static function reading(array $m,int $owner): ?array
    {
        if(empty($m['enabled'])||($m['parent']??0)!==$owner||self::excluded((string)$m['ident'],(string)$m['title']))return null;
        try{return IAHHouseholdDevices::reading($m);}catch(Throwable $ignored){return null;}
    }

    public static function binary(mixed $value,array $m): ?bool
    {
        if(!is_bool($value))return null;
        return $value===($m['activeWhen']??true);
    }

    public static function archiveBinary(mixed $value,array $m): ?bool
    {
        if(($m['type']??-1)===0&&(is_int($value)||is_float($value))&&in_array((float)$value,[0.0,1.0],true))$value=(bool)$value;
        return self::binary($value,$m);
    }

    /** Multiple differing bindings are unknown, never an arbitrary first sensor. */
    public static function values(array $d): array
    {
        $values=[];
        foreach((array)$d['variables'] as $m){
            $r=self::reading($m,(int)$d['id']);if($r===null)continue;
            $role=$m['role']??'other';$raw=$r['raw'];
            if(in_array($role,['smoke','waterLeak','alarm','online','connected','muted','lifeEnd','batteryLow','testActive'],true))$raw=self::binary($raw,$m);
            elseif($role==='batteryLevel'){
                $suffix=trim((string)($r['profile']['Suffix']??''));
                $raw=(!is_bool($raw)&&is_numeric($raw)&&is_finite((float)$raw)&&$raw>=0&&$raw<=100&&in_array($suffix,['','%'],true))?(float)$raw:null;
            }
            if(!isset($values[$role]))$values[$role]=['raw'=>$raw,'metric'=>$m,'reading'=>$r,'conflict'=>false,'anyActive'=>$raw===true];
            elseif($values[$role]['raw']!==$raw){$values[$role]['raw']=null;$values[$role]['conflict']=true;}
            $values[$role]['anyActive']=$values[$role]['anyActive']||$raw===true;
        }
        return $values;
    }

    public static function format(array $m,?array $r): string
    {
        if($r===null)return 'Nicht verfügbar';$role=$m['role'];$raw=$r['raw'];
        if($role==='command')return 'Befehlstaste · nur Anzeige';
        if(in_array($role,['smoke','waterLeak','alarm','online','connected','muted','lifeEnd','batteryLow','testActive'],true)){
            $v=self::binary($raw,$m);if($v===null)return 'Status unklar';
            return match($role){'smoke'=>$v?'Rauch gemeldet':'Kein Rauch gemeldet','waterLeak'=>$v?'Wasser gemeldet':'Trocken gemeldet',
                'online','connected'=>$v?'Ja':'Nein','muted'=>$v?'Stumm':'Nicht stumm','lifeEnd'=>$v?'Lebensdauer beendet':'Kein Lebensdauerfehler gemeldet',
                'batteryLow'=>$v?'Batteriewarnung':'Keine Batteriewarnung',default=>$v?'Aktiv':'Inaktiv'};
        }
        if($role==='reportTime'&&is_int($raw)&&($raw<0||$raw>time()+5*60))return 'Meldungszeit unplausibel';
        if($role==='reportTime'&&is_int($raw))return $raw>0?(new DateTimeImmutable('@'.$raw))->setTimezone(new DateTimeZone('Europe/Vienna'))->format('d.m.Y H:i'):'Keine Meldungszeit';
        return IAHHomeConnect::display($r);
    }

    /** Read-only virtual entries for the existing KI and archive tools. */
    public static function index(array $catalog): array
    {
        $out=[];
        foreach(self::devices($catalog) as $d){
            $values=self::values($d);$reads=[];
            foreach(['smoke'=>'alarm','waterLeak'=>'waterLeak','alarm'=>'alarm','batteryLevel'=>'batteryLevel','online'=>'online'] as $role=>$property){
                $v=$values[$role]??null;
                if($v===null||$v['conflict']||($role!=='batteryLevel'&&empty($v['metric']['activeWhen'])))continue;
                if(!in_array($role,['batteryLevel'],true)&&($v['metric']['type']??-1)!==0)continue;
                $reads[$property]=(int)$v['metric']['id'];
            }
            if($reads===[])continue;
            $key='xsense_'.substr(hash('sha256',(string)$d['id']),0,16);
            $out[$key]=['id'=>$key,'name'=>$d['name'],'room'=>$d['roomLabel'],'roomKey'=>$d['roomKey'],'roomAliases'=>[],
                'floor'=>$d['floor'],'provider'=>'xsense','domain'=>'devices','group'=>$d['group'],'type'=>match($d['kind']){'smoke'=>'smoke','water'=>'water_leak',default=>'alarm'},
                'synonyms'=>$d['aliases'],'typed'=>true,'reads'=>$reads,'read'=>(int)reset($reads),'write'=>0,'writeRoles'=>[],
                'writable'=>false,'archiveOnly'=>false,'archiveSourceId'=>0,'stateMapping'=>[],'min'=>null,'max'=>null,'setpoint'=>0,'legacyTemperatureRead'=>false];
        }
        return $out;
    }
}
