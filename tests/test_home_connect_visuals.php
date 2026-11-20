<?php
declare(strict_types=1);
require __DIR__ . '/test_alexa_house_runtime_v388.php';
$paths=[];
foreach (['laundry'=>'Waschtrockner','dishwasher'=>'Geschirrspüler','coffee'=>'Kaffeevollautomat','hob'=>'Kochfeld','oven'=>'Dampf-Backofen','microwave'=>'Micro-Backofen','all'=>'Haushaltsgeräte','fridge'=>'Kühlschrank','freezer'=>'Gefrierschrank'] as $kind=>$title) {
    $rows=[['label'=>'Status','value'=>'Bereit'],['label'=>'Temperatur','value'=>'22 °C']];
    $rendered=$summary('Das Gerät meldet bereit.','Beantwortet','Wie geht es dem Gerät?',true,'home_connect_'.$kind,$rows,$title);
    ck38($rendered->apl['datasources']['summary']['title']===$title
        && $rendered->apl['datasources']['summary']['readings']===$rows,'Device-specific APL binds fresh readings: '.$kind);
    $graphic=$rendered->apl['document']['graphics']['homeConnectDevice'];
    ck38($graphic['type']==='AVG' && count($graphic['items'])>=2,'Device graphic works without image hosting: '.$kind);
    $paths[$kind]=json_encode($graphic['items']);
}
ck38(count(array_unique($paths))===9,'All appliance types have distinct vector symbols');
$phone=$summary('Bereit','Beantwortet','Waschmaschine',false,'home_connect_laundry',[],'Waschtrockner');
ck38(!isset($phone->apl['document']),'Screen-free Alexa still gets speech');
$instruction='Sage zum Beispiel das erste oder den Gerätenamen.';
$speech='Welches Gerät meinst du? 1: Micro-Backofen. 2: Dampf-Backofen. '.$instruction;
$choices=[['label'=>'1','value'=>'Micro-Backofen'],['label'=>'2','value'=>'Dampf-Backofen']];
$choice=$summary($speech,'Rückfrage','Backofen',true,'home_connect_all',$choices,'Hausgeräteauswahl');
ck38($choice->speech===$speech && $choice->apl['datasources']['summary']['readings']===$choices
    && $choice->apl['datasources']['summary']['text']===''
    && $choice->card[1]==="1  Micro-Backofen\n2  Dampf-Backofen"
    && !str_contains(json_encode([$choice->apl,$choice->card]),$instruction),
    'appliance choice keeps spoken help separate from digit-only cards and APL');
$generic=$summary('Welchen Eintrag meinst du? '.$instruction,'Rückfrage','',true,'',[
    ['label'=>'Nummer 1','value'=>'Erster Eintrag'],['label'=>'Nummer 2','value'=>'Zweiter Eintrag']
]);
ck38($generic->apl['datasources']['summary']['title']==='Auswahl'
    && array_column($generic->apl['datasources']['summary']['readings'],'label')===['1','2']
    && !str_contains(json_encode([$generic->apl,$generic->card]),'Nummer'),
    'other dialogs use the same compact choice policy without a provider-specific renderer');
$question=$summary('Welchen Raum meinst du? Bitte sage zum Beispiel Wohnzimmer.','Rückfrage','',true);
ck38($question->apl['datasources']['summary']['text']==='Welchen Raum meinst du?'
    && $question->card[1]==='Welchen Raum meinst du?'
    && str_contains($question->speech,'Bitte sage'),
    'plain clarification shows its question while spoken response hints stay out of cards and APL');
echo "PASS household device APL titles, live rows, seven built-in graphics and screen-free response\n";
