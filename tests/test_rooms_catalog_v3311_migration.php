<?php
declare(strict_types=1);
require __DIR__.'/../IPSAlexaHaussteuerung/src/RoomsCatalogAutoMigration.php';
function checkm(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);}

$meta=[
    100=>['type'=>0,'profile'=>'~Switch.KNX','hasAction'=>false],
    101=>['type'=>0,'profile'=>'~Switch.KNX','hasAction'=>true],
    200=>['type'=>1,'profile'=>'~Intensity.100','hasAction'=>true],
    300=>['type'=>2,'profile'=>'~Illumination.F','hasAction'=>false],
    400=>['type'=>1,'profile'=>'Rolladen_Steuerung','hasAction'=>true],
    500=>['type'=>2,'profile'=>'~Temperature.Room','hasAction'=>false],
    501=>['type'=>2,'profile'=>'~Temperature.Room','hasAction'=>true],
];
$resolve=static fn(int $id):?array => $meta[$id] ?? null;
$catalog=[
    'buero'=>['display'=>'Büro','floor'=>'EG','domains'=>[
        'licht'=>[
            'switches'=>['decke'=>['title'=>'Deckenlicht','kiEnabled'=>true,'kiReadOnly'=>true,
                'state'=>100,'toggle'=>101,'ai'=>['type'=>'light',
                    'variables'=>['power'=>100],'review'=>['status'=>'pending']]]],
            'dimmers'=>['links'=>['title'=>'Links','value'=>200,'set'=>200,
                'ai'=>['type'=>'light','variables'=>['power'=>200,'control'=>200],
                    'review'=>['status'=>'pending']]]],
            'status'=>['lux'=>['title'=>'Lux','value'=>300,
                'ai'=>['type'=>'light','variables'=>['power'=>300],
                    'review'=>['status'=>'pending']]]]
        ],
        'jalousie'=>['fenster'=>['title'=>'Büro','wert'=>400,
            'kiEnabled'=>false,'customField'=>['unchanged'=>true]]],
        'heizung'=>['buero'=>['title'=>'Büro','ist'=>500,'soll'=>501,
            'ai'=>['type'=>'heating','variables'=>['temperature'=>500]]]]
    ]],
    'global'=>['display'=>'global','domains'=>[
        'jalousie'=>['scenes'=>['all'=>['title'=>'Alle','var'=>400]],
            'icon'=>'Jalousie.png','open_close_var'=>400],
        'licht'=>['scenes'=>[
            ['section'=>'EG Szenen'],['title'=>'Szene 1','actions'=>[['label'=>'An']]],
            ['section'=>'OG Szenen'],['title'=>'Szene 2','actions'=>[['label'=>'Aus']]]
        ]],
        'lueftung'=>['default_buttons'=>[
            ['label'=>'An','argsTpl'=>['on']],
            ['label'=>'Aus','argsTpl'=>['off']]
        ],'central'=>[['title'=>'Stiegenhaus','entityId'=>'vent.central']],
            'icon'=>'vent.png','status_colors'=>['on'=>'@pillOpen'],
            'central_title'=>'Lüftung'],
        'floors'=>['order'=>['EG','OG'],'labels'=>['EG'=>'Erdgeschoss']],
        'save'=>[],
    ]]
];
$system=['navigation'=>['mode'=>'active','schemaVersion'=>1,'items'=>['existing'=>['enabled'=>true]]],
    'var'=>['customSecret'=>'KEEP_UNCHANGED'],
    'pageMappings'=>['nav'=>123]];
$beforeCatalog=$catalog;$beforeSystem=$system;
$out=IAHRoomsCatalogAutoMigration::project($catalog,$system,$resolve);
$c=$out['catalog'];$sys=$out['system'];
checkm($out['report']['devicesSeen']===5,'Scan count wrong');
checkm($out['report']['uiItemsMoved']===8,'Not all eight UI entries moved');
checkm(!isset($c['global']['domains']['licht']) && !isset($c['global']['domains']['jalousie'])
    && !isset($c['global']['domains']['lueftung']),
    'Global renderer UI still retained inside RoomsCatalog');
checkm(isset($c['global']['domains']['floors']) && isset($c['global']['domains']['save']),
    'Unrelated global settings lost');
checkm(($sys['roomUi']['globalDomains']['jalousie']['scenes']['all']['var']??0)===400
    && count($sys['roomUi']['globalDomains']['licht']['scenes']??[])===4,
    'UI payload not moved losslessly');
checkm($sys['var']['customSecret']==='KEEP_UNCHANGED' && $sys['navigation']===$system['navigation'],
    'Unrelated module configuration overwritten');
checkm($catalog===$beforeCatalog && $system===$beforeSystem,'Projection mutated inputs');
$dim=$c['buero']['domains']['licht']['dimmers']['links']['ai'];
checkm($dim['type']==='dimmer' && $dim['variables']['brightness']===200
    && !isset($dim['variables']['power']),'Dimmer not corrected');
$lux=$c['buero']['domains']['licht']['status']['lux']['ai'];
checkm($lux['type']==='illuminance' && $lux['variables']['illuminance']===300
    && !isset($lux['variables']['power']),'Lux not corrected');
$blind=$c['buero']['domains']['jalousie']['fenster'];
checkm(($blind['ai']['variables']['action']??0)===400
    && !isset($blind['ai']['variables']['position'])
    && ($blind['ai']['review']['status']??'')==='pending'
    && $blind['customField']===['unchanged'=>true],
    'Roller-shutter command incorrectly represented as position or user field lost');
$heat=$c['buero']['domains']['heizung']['buero']['ai'];
checkm($heat['variables']['temperature']===500 && $heat['variables']['targetTemperature']===501,
    'Heating multi-instance target not retained');
$light=$c['buero']['domains']['licht']['switches']['decke'];
checkm($light['ai']['variables']['control']===101 && $light['kiEnabled']===true
    && $light['kiReadOnly']===true,'Original KI permissions changed');
$again=IAHRoomsCatalogAutoMigration::project($c,$sys,$resolve);
checkm($again['catalog']===$c && $again['system']===$sys
    && $again['report']['uiItemsMoved']===0 && $again['report']['devicesChanged']===0,
    'Migration must be repeatable and idempotent');
$conflict=$system;$conflict['roomUi']['globalDomains']['licht']=['scenes'=>[]];
try {
    IAHRoomsCatalogAutoMigration::project($catalog,$conflict,$resolve);
    throw new RuntimeException('UI collision was not refused');
} catch (RuntimeException $e) {
    checkm(str_contains($e->getMessage(),'Konflikt'),'Unexpected error on UI collision');
}
$broken=$catalog;$broken['global']['domains']['licht']['devices']=['unsafe'=>['id'=>1]];
try{
    IAHRoomsCatalogAutoMigration::project($broken,$system,$resolve);
    throw new RuntimeException('Unknown UI domain contents were silently moved');
}catch(RuntimeException $e){
    checkm(str_contains($e->getMessage(),'Unerwartetes'),'Unknown UI structure not rejected');
}
echo "PASS V3.3.11: 8 UI items, 5 typed devices, lossless system config, KI flags, idempotency and conflict guards\n";
