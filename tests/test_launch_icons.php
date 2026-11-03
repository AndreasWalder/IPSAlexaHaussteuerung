<?php
declare(strict_types=1);

function iconCheck(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}
function IPS_GetKernelDir(): string { return sys_get_temp_dir() . DIRECTORY_SEPARATOR; }
function IPS_LogMessage(string $sender,string $message): void
{
    $GLOBALS['iconLogs'][]=$message;
}

$dir=dirname(__DIR__).'/IPSAlexaHaussteuerung';
$payload=[
    'aplSupported'=>true,
    'action'=>'',
    'args1'=>'',
    'alexa'=>'Laptop',
    'baseUrl'=>'https://example.test',
    'token'=>'TOKEN_WITH&?reserved',
    'launchCatalog'=>[
        'tiles'=>[
            ['id'=>'licht','title'=>'Licht neu','color'=>'#FFE066'],
            ['id'=>'jalousie','title'=>'Jalousie','icon'=>'Jalousie.png'],
            ['id'=>'unbekannt','title'=>'Eigene Kachel','color'=>'#FAFAFA']
        ]
    ]
];
$_IPS=['payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
ob_start();
require $dir.'/resources/renderers/LaunchRequest.php';
$output=ob_get_clean();
$result=json_decode($output,true,512,JSON_THROW_ON_ERROR);
$ds=$result['apl']['ds']['tilesData']??[];
iconCheck(count($ds['tiles']??[])===3, 'Custom tiles lost');
iconCheck(($ds['tiles'][0]['title']??'')==='Licht neu','Custom title overwritten');
iconCheck(($ds['tiles'][0]['color']??'')==='#FFE066','Custom color overwritten');
$expectedStart='https://example.test/hook/iah-alexa-icons?';
foreach (['logoUrl','homeIcon','Icon'] as $key) {
    iconCheck(str_starts_with($ds[$key]??'',$expectedStart),'Header '.$key.' has no dedicated hook URL');
}
iconCheck(str_starts_with($ds['tiles'][0]['icon']??'',$expectedStart)
    && str_contains($ds['tiles'][0]['icon'],'f=Licht.png'),
    'Missing icon in a custom known tile must fall back to default asset');
iconCheck(str_starts_with($ds['tiles'][1]['icon']??'',$expectedStart)
    && str_contains($ds['tiles'][1]['icon'],'f=Jalousie.png'),
    'Custom configured icon should use dedicated hook');
iconCheck(!isset($ds['tiles'][2]['icon']),'Unknown custom tile should not get made-up icon');
iconCheck(str_contains($ds['tiles'][0]['icon'],'token=TOKEN_WITH%26%3Freserved'),
    'Webhook token was not URL encoded');
iconCheck(!str_contains($output,'/hook/icons?') && str_contains($output,'/hook/iah-alexa-icons?'),
    'Legacy generic hook still used by LaunchRequest');

$module=file_get_contents($dir.'/module.php');
$form=json_decode(file_get_contents($dir.'/form.json'),true,512,JSON_THROW_ON_ERROR);
$launch=array_values(array_filter($form['elements'],static fn(array $part):bool =>
    ($part['caption']??'')==='Launch-Kacheln'))[0]??null;
iconCheck(is_array($launch) && str_contains(json_encode($launch), 'IAH_CheckLaunchIcons($id)'),
    'Launch icon diagnostic button missing');
iconCheck(str_contains($module,"'/hook/iah-alexa-icons' => \$icons"),
    'Icon endpoint not registered by module');
iconCheck(str_contains($module,"public function CheckLaunchIcons()"),
    'Icon diagnostics not implemented');
iconCheck(str_contains($module,"'iahAlexaIconsHook'"),
    'Icon handler script not looked up by ident');
echo "PASS: dedicated icon endpoint, known-tile fallback, URL escaping and diagnostic UI\n";
