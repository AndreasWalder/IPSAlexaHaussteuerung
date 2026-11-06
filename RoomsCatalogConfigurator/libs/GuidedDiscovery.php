<?php
declare(strict_types=1);

/**
 * Deterministische, rein lesende Vorschlaege fuer die IP-Symcon-Baumsuche.
 * Keine globale Variablensuche, keine AI-Freigabe, keine Aktionen.
 */
final class RCCGuidedDiscovery
{
    public static function norm(string $text): string
    {
        $text=mb_strtolower($text,'UTF-8');
        $text=str_replace(['ä','ö','ü','ß'],['ae','oe','ue','ss'],$text);
        return (string)preg_replace('/[^a-z0-9]+/u','',$text);
    }

    /** Nur eine eindeutige Raum-Namenssignatur als Vorschlag zulassen. */
    public static function roomByName(string $sourceName, array $rooms): string
    {
        $needle=self::norm($sourceName);
        if ($needle==='') return '';
        $best=0;$matches=[];
        foreach ($rooms as $key=>$room) {
            if ($key==='global' || !is_array($room) || !isset($room['domains'])) continue;
            $names=[(string)$key,(string)($room['display']??$key)];
            $aliases=$room['aliases']??[];
            if (is_array($aliases)) $names=array_merge($names,$aliases);
            foreach ($names as $name) {
                $norm=self::norm((string)$name);
                // Zweibuchstabige Teilstrings ("WC") sind ohne Kontext zu
                // mehrdeutig. Die Anzeige "EG WC" ist hingegen eindeutig.
                if (strlen($norm)<4 || !str_contains($needle,$norm)) continue;
                $score=strlen($norm);
                if ($score>$best) {$best=$score;$matches=[(string)$key=>true];}
                elseif ($score===$best) $matches[(string)$key]=true;
            }
        }
        return count($matches)===1 ? (string)array_key_first($matches) : '';
    }

    /** Unzweideutig genau eine passende Lesevariable fuer einen Sensor. */
    public static function sensorRole(string $type, array $variables): array
    {
        $roles=[
            'motion'=>['motion',[0,1],['bewegung','motion','pir','trigger','praesenz']],
            'temperature'=>['temperature',[1,2],['temperatur','temperature','istwert']],
            'humidity'=>['humidity',[1,2],['feuchte','humidity','humidityvalue']],
            'illuminance'=>['illuminance',[1,2],['helligkeit','lux','illuminance']],
            'window'=>['open',[0,1],['offen','open','kontakt','contact']],
            'door'=>['open',[0,1],['offen','open','kontakt','contact']]
        ];
        if (!isset($roles[$type])) return [];
        [$role,$types,$terms]=$roles[$type];
        $compatible=[];
        foreach ($variables as $variable) {
            $id=(int)($variable['id']??0);
            if ($id<=0 || !in_array((int)($variable['type']??-1),$types,true)
                || !empty($variable['action'])) continue;
            // Temperatur und Beleuchtungsstaerke duerfen nicht per Integer
            // Bool-Signal verwechselt werden, sofern kein Profil-Hinweis
            // existiert. Bewegungsmelder koennen Integer 0/1 liefern.
            $text=self::norm((string)($variable['name']??'').' '.
                ($variable['ident']??'').' '.($variable['profile']??''));
            $score=0;
            foreach($terms as $term){
                if(str_contains($text,self::norm($term))) $score=max($score,120);
            }
            if($type==='motion' && (int)($variable['type']??-1)===0) $score+=20;
            if(!empty($variable['hasPresentation']) && $score===0) $score+=0;
            $compatible[]=['id'=>$id,'score'=>$score];
        }
        if ($compatible===[]) return [];
        usort($compatible,static fn($a,$b)=>$b['score']<=>$a['score'] ?: $a['id']<=>$b['id']);
        $top=$compatible[0];
        // Bei exakt einem kompatiblen Sensorwert ist die Rolle eindeutig;
        // bei mehreren nur bei eindeutig hoechster semantischer Uebereinstimmung.
        if(count($compatible)>1 && ($top['score']<100
            || $top['score']===$compatible[1]['score'])) return [];
        return [$role=>$top['id']];
    }

    public static function validLocation(string $domain,string $group): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]{0,40}$/D',$domain)===1
            && preg_match('/^[a-z][a-z0-9_-]{0,40}$/D',$group)===1;
    }

    /** Stabile Fingerprint-Werte fuer die Preview/EDIT-Hash-Sicherung. */
    public static function config(array $config): string
    {
        ksort($config);
        return hash('sha256',json_encode($config,JSON_THROW_ON_ERROR));
    }

    /** AI darf nur Vorschlaege fuer bekannte Typen, Raeume und LESEROLLEN liefern. */
    public static function checkedAiRoles(
        string $type, array $suggestions, array $variables, array $allowedRoles
    ): array {
        $byIndex=array_values($variables);
        $roles=[];
        foreach($suggestions as $role=>$index) {
            if(!is_string($role)||!in_array($role,$allowedRoles,true)
                || in_array($role,['action','control','commandValue','targetPosition','targetTemperature'],true)
                || !is_int($index)||!isset($byIndex[$index])) continue;
            $var=$byIndex[$index];
            $id=(int)($var['id']??0);
            if($id<=0||!empty($var['action'])||in_array($id,$roles,true)
                || !in_array((int)($var['type']??-1),
                    RCCDeviceTypeRegistry::dataTypes()[$role]??[],true)) continue;
            $roles[$role]=$id;
        }
        return $roles;
    }
}
