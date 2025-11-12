<?php
declare(strict_types=1);
namespace IPSAlexaHaussteuerung;
use IPSAlexaHaussteuerung\Routes\RouteAll;
final class Router{use LogTrait;private $routeAll;public function __construct(){$this->routeAll=new RouteAll();}
public function route(array $payload,array $cfg):array{$route=(string)($payload['route']??'main_launch');$this->log('debug','RouteAll.enter',['route'=>$route]);$res=$this->routeAll->handle($payload,$cfg);if(!is_array($res))$res=['ok'=>false,'data'=>['speech'=>'','reprompt'=>'']];$res+=['ok'=>true,'aplToken'=>'hv-main','flags'=>[], 'data'=>['speech'=>'','reprompt'=>'']];return $res;}
public static function wrapApl(array $cfg,string $key,string $token='hv-main'):array{$doc=Helpers::jsonToArray((string)($cfg['apl']['doc'][$key]??'{}'),[]);$ds=Helpers::jsonToArray((string)($cfg['apl']['ds'][$key]??'[]'),[]);return ['token'=>$token,'doc'=>$doc,'ds'=>$ds];}}
