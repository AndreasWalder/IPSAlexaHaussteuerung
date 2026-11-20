<?php
declare(strict_types=1);
require __DIR__ . '/test_alexa_house_runtime_v388.php';
$paths=[];
foreach (['laundry'=>'Waschtrockner','dishwasher'=>'Geschirrspüler','coffee'=>'Kaffeevollautomat','hob'=>'Kochfeld','oven'=>'Dampf-Backofen','microwave'=>'Micro-Backofen','all'=>'Haushaltsgeräte'] as $kind=>$title) {
    $rows=[['label'=>'Status','value'=>'Bereit'],['label'=>'Temperatur','value'=>'22 °C']];
    $rendered=$summary('Das Gerät meldet bereit.','Beantwortet','Wie geht es dem Gerät?',true,'home_connect_'.$kind,$rows,$title);
    ck38($rendered->apl['datasources']['summary']['title']===$title
        && $rendered->apl['datasources']['summary']['readings']===$rows,'Device-specific APL binds fresh readings: '.$kind);
    $graphic=$rendered->apl['document']['graphics']['homeConnectDevice'];
    ck38($graphic['type']==='AVG' && count($graphic['items'])>=2,'Device graphic works without image hosting: '.$kind);
    $paths[$kind]=json_encode($graphic['items']);
}
ck38(count(array_unique($paths))===7,'All appliance types have distinct vector symbols');
$phone=$summary('Bereit','Beantwortet','Waschmaschine',false,'home_connect_laundry',[],'Waschtrockner');
ck38(!isset($phone->apl['document']),'Screen-free Alexa still gets speech');
echo "PASS household device APL titles, live rows, seven built-in graphics and screen-free response\n";
