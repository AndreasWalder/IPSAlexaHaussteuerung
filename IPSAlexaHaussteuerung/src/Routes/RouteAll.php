<?php
declare(strict_types=1);
namespace IPSAlexaHaussteuerung\Routes;
use IPSAlexaHaussteuerung\Router;
use IPSAlexaHaussteuerung\Renderers\RenderMain;
use IPSAlexaHaussteuerung\Renderers\RenderHeizung;
use IPSAlexaHaussteuerung\Renderers\RenderJalousie;
use IPSAlexaHaussteuerung\Renderers\RenderLicht;
use IPSAlexaHaussteuerung\Renderers\RenderLueftung;
use IPSAlexaHaussteuerung\Renderers\RenderGeraete;
use IPSAlexaHaussteuerung\Renderers\RenderBewaesserung;
use IPSAlexaHaussteuerung\Renderers\RenderSettings;
use IPSAlexaHaussteuerung\Renderers\RenderExternal;
final class RouteAll{
    public function handle(array $payload,array $cfg):array{
        $route=(string)($payload['route']??'main_launch');
        switch($route){
            case 'heizung':return $this->handleHeizung($payload,$cfg);
            case 'jalousie':return $this->handleJalousie($payload,$cfg);
            case 'licht':return $this->handleLicht($payload,$cfg);
            case 'lueftung':return $this->handleLueftung($payload,$cfg);
            case 'geraete':return $this->handleGeraete($payload,$cfg);
            case 'bewaesserung':return $this->handleBewaesserung($payload,$cfg);
            case 'settings':return $this->handleSettings($payload,$cfg);
            case 'external':return $this->handleExternal($payload,$cfg);
            case 'main_launch':
            default:return $this->handleMain($payload,$cfg);
        }
    }
    private function handleMain(array $payload,array $cfg):array{$custom=(new RenderMain())->process($payload,$cfg);if(is_array($custom))return $custom;return $this->baseResponse($payload,$cfg,'hv-main','Main','main');}
    private function handleHeizung(array $payload,array $cfg):array{$custom=(new RenderHeizung())->process($payload,$cfg);if(is_array($custom))return $custom;return $this->baseResponse($payload,$cfg,'hv-heizung','Heizung','heizung');}
    private function handleJalousie(array $payload,array $cfg):array{$custom=(new RenderJalousie())->process($payload,$cfg);if(is_array($custom))return $custom;return $this->baseResponse($payload,$cfg,'hv-jalousie','Jalousie','jalousie');}
    private function handleLicht(array $payload,array $cfg):array{$custom=(new RenderLicht())->process($payload,$cfg);if(is_array($custom))return $custom;return $this->baseResponse($payload,$cfg,'hv-licht','Licht','licht');}
    private function handleLueftung(array $payload,array $cfg):array{$custom=(new RenderLueftung())->process($payload,$cfg);if(is_array($custom))return $custom;return $this->baseResponse($payload,$cfg,'hv-lueftung','Lueftung','lueftung');}
    private function handleGeraete(array $payload,array $cfg):array{$custom=(new RenderGeraete())->process($payload,$cfg);if(is_array($custom))return $custom;return $this->baseResponse($payload,$cfg,'hv-geraete','Geraete','geraete');}
    private function handleBewaesserung(array $payload,array $cfg):array{$custom=(new RenderBewaesserung())->process($payload,$cfg);if(is_array($custom))return $custom;return $this->baseResponse($payload,$cfg,'hv-bewaesserung','Bewasserung','bewaesserung');}
    private function handleSettings(array $payload,array $cfg):array{$custom=(new RenderSettings())->process($payload,$cfg);if(is_array($custom))return $custom;return $this->baseResponse($payload,$cfg,'hv-settings','Settings','settings');}
    private function handleExternal(array $payload,array $cfg):array{$custom=(new RenderExternal())->process($payload,$cfg);if(is_array($custom))return $custom;$res=$this->baseResponse($payload,$cfg,'hv-external','External','external');if(!empty($payload['externalDirective'])&&is_array($payload['externalDirective'])){$res['data']['directives'][]=$payload['externalDirective'];}return $res;}
    private function baseResponse(array $payload,array $cfg,string $token,string $aplKey,string $speechKey):array{$apl=Router::wrapApl($cfg,$aplKey,$token);$speech=$this->renderSpeech($cfg,$speechKey,$payload);$data=['speech'=>$speech,'reprompt'=>'','card'=>$this->maybeCard($payload),'apl'=>$apl,'directives'=>[]];if(!empty($payload['endSession'])){$data['endSession']=true;}$flags=(array)($payload['flags']??[]);return ['ok'=>true,'aplToken'=>$token,'data'=>$data,'flags'=>$flags];}
    private function renderSpeech(array $cfg,string $key,array $p):string{$tpl=(string)($cfg['speech'][$key]??'');if($tpl==='')return '';foreach(['{{device}}'=>'device','{{room}}'=>'room','{{action}}'=>'action','{{number}}'=>'number','{{prozent}}'=>'prozent','{{alexa}}'=>'alexa'] as $needle=>$field){$tpl=str_replace($needle,(string)($p[$field]??''),$tpl);}return $tpl;}
    private function maybeCard(array $payload):array{$title=(string)($payload['cardTitle']??'');$text=(string)($payload['cardText']??'');$small=(string)($payload['cardSmall']??'');$large=(string)($payload['cardLarge']??($small!==''?$small:''));if($title===''&&$text==='')return [];return ['title'=>$title,'text'=>$text,'smallImageUrl'=>$small,'largeImageUrl'=>$large];}
}
