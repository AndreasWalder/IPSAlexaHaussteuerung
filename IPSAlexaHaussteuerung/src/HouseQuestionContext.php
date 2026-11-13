<?php
declare(strict_types=1);

/** Resolve only location-only follow-ups, never invent a device or an action. */
final class IAHHouseQuestionContext
{
    public static function normalize(string $text): string
    {
        $text=mb_strtolower($text,'UTF-8');
        $text=strtr($text,['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
        return trim(preg_replace('/[^a-z0-9]+/u',' ',$text)??'');
    }

    private const LOCATION_PREFIX='(?:im|in der|in dem|in den|in|auf der|auf dem|auf|am|beim)';

    public static function followUp(string $text): ?string
    {
        $text=self::normalize($text);
        if(!preg_match('/^(?:und )?'.self::LOCATION_PREFIX.' ([a-z0-9 ]{1,70})$/D',$text,$m)
            || preg_match('/\b(?:welche|welcher|wie|ist|sind|schalte|schalten|mach|stelle|'
                .'oeffne|schliesse|starte|stoppe|dimme|fahre|an|aus|ein|auf|zu|prozent)\b/',$m[1]))
            return null;
        return trim($m[1]);
    }

    public static function isNewQuestion(string $text): bool
    {
        return preg_match('/^(?:und )?(?:wer|was|wann|wo|wie|wieviel|wieviele|'
            .'welche|welcher|welches|welchen|welchem|ist|sind|hat|haben|gibt|'
            .'pruefe|kontrolliere|zeige|nenne|liste|vergleiche|zaehle|warum|'
            .'leuchtet|leuchten|laeuft|laufen)\b/',self::normalize($text))===1;
    }

    private static function floor(string $label): string
    {
        $name=self::normalize($label);
        return match($name){
            'eg','erdgeschoss','parterre','0','0 eg'=>'eg',
            'og','1 og','og 1','og1','ober geschoss','obergeschoss','1 obergeschoss',
                'erstes obergeschoss','ersten obergeschoss','1 stock','ersten stock'=>'og',
            'kg','ug','keller','kellergeschoss','untergeschoss','1 ug','ug1'=>'ug',
            'dg','dachgeschoss'=>'dg',
            default=>preg_match('/^(?:(\d+) ?(?:og|obergeschoss|stock)|og ?(\d+))$/D',$name,$m)
                ?'og '.($m[1]!==''?$m[1]:$m[2]):$name
        };
    }

    private static function locations(array $catalog): array
    {
        $locations=[];
        foreach(($catalog['rooms']??$catalog) as $key=>$room){
            if(!is_array($room)||!is_array($room['domains']??null)
                || $key==='global'||$key==='laptop'||!empty($room['aiTestRoom'])
                || !empty($room['excludeFromAI']))continue;
            $floor=(string)($room['floor']??'');
            $floorKey=self::floor($floor);
            $name=(string)($room['display']??$key);
            $aliases=$room['aliases']??[];
            if(is_string($aliases))$aliases=preg_split('/[,;|]/',$aliases)?:[];
            $locations[(string)$key]=['name'=>$name,'aliases'=>array_values(array_unique(array_filter(array_map(
                static fn($v):string=>is_scalar($v)?self::normalize((string)$v):'',
                array_merge([$key,$name],(array)$aliases)
            )))),'floor'=>$floor,'floorKey'=>$floorKey];
        }
        return $locations;
    }

    public static function scope(string $target,array $catalog): ?array
    {
        $locations=self::locations($catalog);
        $floor=self::floor($target);
        $floorRooms=array_filter($locations,static fn(array $r):bool=>$floor!==''&&$r['floorKey']===$floor);
        if($floor==='og'&&$floorRooms===[]){
            $upper=array_filter($locations,static fn(array $r):bool=>str_starts_with($r['floorKey'],'og '));
            if(count(array_unique(array_column($upper,'floorKey')))===1)$floorRooms=$upper;
        }
        $rooms=array_filter($locations,static fn(array $r):bool=>in_array($target,$r['aliases'],true));
        if($floorRooms!==[]){
            if(array_diff_key($rooms,$floorRooms)!==[])return null;
            $first=reset($floorRooms);
            return ['type'=>'floor','name'=>$first['floor'],'rooms'=>array_keys($floorRooms)];
        }
        if(count($rooms)!==1)return null;
        $first=reset($rooms);
        return ['type'=>'room','name'=>$first['name'],'rooms'=>array_keys($rooms)];
    }

    /** Remove one explicit old location so the follow-up replaces it. */
    public static function withoutLocation(string $question,array $catalog): string
    {
        $labels=[];
        foreach(self::locations($catalog) as $location){
            foreach($location['aliases'] as $alias)$labels[$alias]=true;
            $floor=self::normalize($location['floor']);
            if($floor!=='')$labels[$floor]=true;
        }
        foreach(['erdgeschoss','obergeschoss','untergeschoss','keller','dachgeschoss',
            'erstes obergeschoss','ersten stock','1 obergeschoss','1 stock','eg','og','ug','kg','dg'] as $label)
            $labels[$label]=true;
        $labels=array_keys($labels);
        usort($labels,static fn($a,$b):int=>strlen((string)$b)<=>strlen((string)$a));
        $pattern=implode('|',array_map(static fn($v):string=>strtr(preg_quote((string)$v,'/'),[
            'ae'=>'(?:ae|ä)','oe'=>'(?:oe|ö)','ue'=>'(?:ue|ü)','ss'=>'(?:ss|ß)',
            ' '=>'[^\pL\pN]+'
        ]),$labels));
        $result=preg_replace('/\b'.self::LOCATION_PREFIX.'\s+(?:'.$pattern.')\b/ui',' ',$question)??$question;
        return trim(preg_replace('/\s+/u',' ',$result)??$result);
    }
}
