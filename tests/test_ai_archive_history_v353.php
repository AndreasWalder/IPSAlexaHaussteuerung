<?php
declare(strict_types=1);

/** V3.5.3: KI Archive Control, last true movement, no manual binding. */
$GLOBALS['archiveTestTmp']=sys_get_temp_dir().'/iah-archive-'.bin2hex(random_bytes(6));
mkdir($GLOBALS['archiveTestTmp'],0700,true);
$GLOBALS['archiveObjects']=[
  58353=>['ObjectType'=>1,'ObjectName'=>'Außen Straße Bewegung','ObjectParentID'=>100],
  70011=>['ObjectType'=>2,'ObjectName'=>'Value','ObjectIdent'=>'Value','ObjectParentID'=>58353],
  70012=>['ObjectType'=>2,'ObjectName'=>'Bewegung','ObjectIdent'=>'Motion','ObjectParentID'=>58353],
  58354=>['ObjectType'=>1,'ObjectName'=>'Büro Bewegung','ObjectParentID'=>100],
  70013=>['ObjectType'=>2,'ObjectName'=>'Value','ObjectIdent'=>'','ObjectParentID'=>58354]
];
$GLOBALS['archiveVars']=[
  70011=>['VariableType'=>0,'VariableProfile'=>'','VariableCustomProfile'=>''],
  70012=>['VariableType'=>0,'VariableProfile'=>'~Motion','VariableCustomProfile'=>''],
  70013=>['VariableType'=>0,'VariableProfile'=>'~Motion','VariableCustomProfile'=>'']
];
$GLOBALS['archiveCalls']=[];
$GLOBALS['archiveValues']=[
  70012=>[
    ['TimeStamp'=>1793900000,'Value'=>false],
    ['TimeStamp'=>1793899980,'Value'=>true],
    ['TimeStamp'=>1793899900,'Value'=>false],
    ['TimeStamp'=>1793899000,'Value'=>true]
  ],
  70013=>[
    ['TimeStamp'=>1793899800,'Value'=>false],
    ['TimeStamp'=>1793899790,'Value'=>true]
  ]
];
function IPS_InstanceExists(int $id):bool{return $id===17597;}
function IPS_GetInstance(int $id):array{return ['ModuleInfo'=>[
    'ModuleID'=>'{43192F0B-135B-4CE7-A0A7-1475603F3060}']];}
function IPS_ScriptExists(int $id):bool{return $id===701;}
function IPS_GetKernelDir():string{return $GLOBALS['archiveTestTmp'].'/';}
function IPS_GetScriptFile(int $id):string{return $GLOBALS['archiveTestTmp'].'/701.php';}
function IPS_ObjectExists(int $id):bool{return isset($GLOBALS['archiveObjects'][$id]);}
function IPS_GetObject(int $id):array{return $GLOBALS['archiveObjects'][$id]??[];}
function IPS_GetChildrenIDs(int $id):array{
    return array_keys(array_filter($GLOBALS['archiveObjects'],static fn(array $v):bool=>
        ($v['ObjectParentID']??-1)===$id));
}
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['archiveVars'][$id]);}
function IPS_GetVariable(int $id):array{return $GLOBALS['archiveVars'][$id]??[];}
function IPS_GetVariableProfile(string $profile):array{return ['Associations'=>[]];}
function GetValue(int $id):bool{return false;}
function IPS_LogMessage(string $prefix,string $msg):void{}
function AC_GetAggregationVariables(int $archiveId,bool $full):array{
    $result=[];
    foreach(array_keys($GLOBALS['archiveValues']) as $varId)
        $result[]=['VariableID'=>$varId,'FirstTime'=>1793890000,
            'LastTime'=>1793900000,'RecordCount'=>5,'AggregationActive'=>true];
    return $result;
}
function AC_GetLoggedValues(int $archiveId,int $variableId,int $from,int $to,int $limit):array{
    $GLOBALS['archiveCalls'][]=[$archiveId,$variableId,$from,$to,$limit];
    return array_slice(array_values(array_filter($GLOBALS['archiveValues'][$variableId]??[],
        static fn(array $row):bool=>
            ($from===0 || $row['TimeStamp'] >= $from)
            && ($to===0 || $row['TimeStamp'] <= $to))),0,$limit);
}
function AC_GetAggregatedValues(int $archiveId,int $variableId,int $stage,int $start,int $end,int $limit):array{
    return [['TimeStamp'=>1793890000,'Avg'=>0.4,'Min'=>0,'Max'=>1]];
}
$catalog=['aussen'=>['display'=>'Außen','domains'=>[
  'sensoren'=>['bewegung'=>[
    'strasse'=>['title'=>'Außen Straße Bewegung','kiEnabled'=>true,'kiReadOnly'=>true,
        'discoverySourceId'=>58353,'ai'=>['version'=>1,'type'=>'motion',
            'variables'=>[],'review'=>['status'=>'pending']]],
    'buero'=>['title'=>'Büro Bewegung','kiEnabled'=>true,'kiReadOnly'=>true,
        'discoverySourceId'=>58354,'ai'=>['version'=>1,'type'=>'motion',
            'variables'=>[],'review'=>['status'=>'pending']]],
    'hidden'=>['title'=>'Geheimer Bewegungsmelder','kiEnabled'=>false,
        'discoverySourceId'=>58354,'ai'=>['version'=>1,'type'=>'motion',
            'variables'=>[],'review'=>['status'=>'pending']]]
  ]]
]]];
file_put_contents($GLOBALS['archiveTestTmp'].'/701.php',
    '<?php return '.var_export($catalog,true).';');
require dirname(__DIR__).'/IPSAlexaHaussteuerung/resources/helpers/KIHouseController.php';
function ck353(bool $cond,string $message):void{
    if (!$cond)throw new RuntimeException('V3.5.3 '.$message);
}
ck353(iah_rc_index(701)===[],'unbound devices must not enter ordinary live KI queries');
$devices=iah_rc_index(701,true);
ck353(count($devices)===2,'archive can see unbound permitted devices, not forbidden ones');
ck353(iah_archive_discover_source(58353,'motion',iah_archive_inventory(17597))===70012,
    'unique motion-named Boolean child among competing variables must be chosen');
ck353(iah_archive_valid(17597) && !iah_archive_valid(17598),
    'archive ID must be verified against configured Archive Control');
$question='wann wurde die letzte Bewegung erkannt';
$single=iah_archive_query($devices,['property'=>'motion','operation'=>'last_active',
    'name'=>'Außen Straße Bewegung','period'=>'all'],$question,17597);
ck353(($single['ok']??false) && $single['matchedDevices']===1
    && $single['mostRecent']['timestamp']===1793899980,
    'last movement must be last true entry, not latest false value');
ck353(($single['devices'][0]['events'][0]['value']??null)===true,
    'last movement must have actual true feedback');
$all=iah_archive_query($devices,['property'=>'motion','operation'=>'last_active',
    'period'=>'all'],$question,17597);
ck353($all['matchedDevices']===2 && $all['mostRecent']['timestamp']===1793899980,
    'all-room motion last event must pick globally latest actual activation');
$counts=iah_archive_query($devices,['property'=>'motion','operation'=>'count_active',
    'period'=>'all','name'=>'Außen Straße Bewegung'],'Wie oft Bewegung?',17597);
ck353(($counts['devices'][0]['count']??0)===2,
    'count true events only, never false edges');
$inactive=iah_archive_query($devices,['property'=>'motion','operation'=>'last_change',
    'name'=>'Außen Straße Bewegung','period'=>'all'],'Letzte Aenderung',17597);
ck353($inactive['mostRecent']['timestamp']===1793900000
    && $inactive['devices'][0]['events'][0]['value']===false,
    'last change distinct from last actual movement');
ck353(!array_key_exists('variableId',$single['devices'][0]),
    'OpenAI result may not contain raw variable IDs');
ck353(count($GLOBALS['archiveCalls'])>0
    && count(array_unique(array_column($GLOBALS['archiveCalls'],0)))===1,
    'queries only use explicitly configured archive');
$form=json_decode(file_get_contents(dirname(__DIR__).'/IPSAlexaHaussteuerung/form.json'),true);
$archiveMatches=[];
$collectArchive=static function(array $elements)use(&$collectArchive,&$archiveMatches):void {
    foreach($elements as $item) {
        if(($item['name']??'')==='KIArchiveControlId')$archiveMatches[]=$item;
        if(is_array($item['items']??null))$collectArchive($item['items']);
    }
};
$collectArchive($form['elements']??[]);
ck353(count($archiveMatches)===1
    && ($archiveMatches[0]['type']??'')==='SelectInstance'
    && ($archiveMatches[0]['moduleID']??'')==='{43192F0B-135B-4CE7-A0A7-1475603F3060}',
    'Archive Control must be the only new selectable setting');
@unlink($GLOBALS['archiveTestTmp'].'/701.php');
@rmdir($GLOBALS['archiveTestTmp']);
echo "PASS V3.5.3: selectable archive, unbound motion source, true-event history and protected catalog\n";
