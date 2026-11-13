<?php
declare(strict_types=1);

/** Resolve house-question locations from the published room metadata. */
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
        if(self::isNewQuestion($text))return null;
        if(preg_match('/^(?:und )?(innen|drinnen|draussen|nur aussen|ausser aussen)$/D',$text,$area))
            return $area[1];
        if(!preg_match('/^(?:und )?'.self::LOCATION_PREFIX.' ([a-z0-9 ]{1,70})$/D',$text,$m)
            || preg_match('/\b(?:welche|welcher|wie|ist|sind|schalte|schalten|mach|stelle|'
                .'oeffne|schliesse|starte|stoppe|dimme|fahre|an|aus|ein|auf|zu|prozent)\b/',$m[1]))
            return null;
        return trim($m[1]);
    }

    public static function isNewQuestion(string $text): bool
    {
        return preg_match('/^(?:und )?(?:in|aus|bei|auf|vor|hinter|neben|unter|ueber|zwischen|seit|mit|nach|an|von|zu) welche[rsnm]?\s+\S+\s+\S+/u',self::normalize($text))===1
            || preg_match('/^(?:und )?(?:wer|was|wann|wo|wie|wieviel|wieviele|'
            .'welche|welcher|welches|welchen|welchem|ist|sind|hat|haben|gibt|'
            .'pruefe|kontrolliere|zeige|nenne|liste|vergleiche|zaehle|warum|'
            .'leuchtet|leuchten|laeuft|laufen)\b/',self::normalize($text))===1;
    }

    /** Interpret only recognizable historical motion questions, not live status or counts. */
    public static function latestMotionQuestion(string $text): bool
    {
        $text=self::normalize($text);
        if (!preg_match('/\bbewegung(?:en)?\b/',$text)
            || preg_match('/\b(?:wie oft|wie haeufig|wie viele|wieviele|seit wann|verlauf|historie|gestern|vorgestern|letzte woche|letzten tage|heute|letzten \d+|zwischen|vorhin)\b/',$text))
            return false;
        if (!preg_match('/^(?:und )?(?:wo|wie|wann|welche[rsnm]?|in welche[rsnm]?)\b/',$text))return false;
        return (bool)preg_match('/\b(?:zuletzt|zu letzt|zu schluss|zum schluss|letzte[rsnm]?)\b/',$text)
            || ((self::motionArea($text)!=='all' || preg_match('/^(?:und )?(?:wo|wann|in welche[rsnm]?)\b/',$text))
                && preg_match('/\b(?:wurde|wurden|war)\b.*\b(?:erkannt|bekannt|gemeldet|festgestellt)\b/',$text));
    }

    public static function motionArea(string $text): string
    {
        $text=self::normalize($text);
        if (preg_match('/\b(?:innen|drinnen|innerhalb des hauses|(?:nur )?im (?:ganzen |gesamten )?haus|'
            .'ausser(?:halb)? (?:dem )?aussen(?:bereich)?|ohne aussen(?:bereich)?)\b/',$text))return 'indoor';
        if (preg_match('/\b(?:draussen|im aussenbereich|nur aussen|ausserhalb des hauses)\b/',$text))return 'outdoor';
        return 'all';
    }

    /** Older GetHaus joins the question and the Room slot without a preposition. */
    public static function simpleTemperatureQuestion(string $text): ?array
    {
        if (!preg_match('/^((?:wie (?:warm|kalt) ist es|(?:wie (?:hoch )?ist|was ist) die (?:raum)?temperatur'
            .'|(?:raum)?temperatur)(?: (?:gerade|aktuell|momentan))?)(?: (.+))?$/D',
            self::normalize($text), $match)) return null;
        return ['question'=>$match[1], 'room'=>$match[2]??''];
    }

    public static function floor(string $label): string
    {
        $name=self::normalize($label);
        return match($name){
            'eg','e g','erdgeschoss','erd geschoss','parterre','0','0 eg'=>'eg',
            'og','o g','1 og','og 1','og1','1og','ober geschoss','obergeschoss','1 obergeschoss',
                'erstes obergeschoss','ersten obergeschoss','1 stock','erster stock','ersten stock',
            '1 etage','erste etage','ersten etage','stock 1','etage 1'=>'og',
            'kg','k g','ug','u g','keller','kellergeschoss','untergeschoss','1 ug','ug1','1ug',
            '1 untergeschoss','ug 1','erstes untergeschoss','ersten untergeschoss'=>'ug',
            'dg','d g','dachgeschoss','dach geschoss'=>'dg',
            default=>self::numberedFloor($name)??$name
        };
    }

    private static function numberedFloor(string $name): ?string
    {
        $name=preg_replace_callback('/^(zweite[rsn]?|dritte[rsn]?|vierte[rsn]?) /',
            static fn(array $m):string=>match(substr($m[1],0,6)){
                'zweite'=>'2 ','dritte'=>'3 ',default=>'4 '
            },$name)??$name;
        if(preg_match('/^(\d+) ?(og|ug|obergeschoss|untergeschoss|stock|etage)$/D',$name,$m))
            return (in_array($m[2],['ug','untergeschoss'],true)?'ug':'og').' '.(int)$m[1];
        if(preg_match('/^(og|ug|stock|etage) ?(\d+)$/D',$name,$m))
            return ($m[1]==='ug'?'ug':'og').' '.(int)$m[2];
        return null;
    }

    private static function locations(array $catalog): array
    {
        $locations=[];
        foreach(($catalog['rooms']??$catalog) as $key=>$room){
            if(!is_array($room)||!is_array($room['domains']??null)
                || $key==='global'||self::normalize((string)$key)==='laptop'||!empty($room['aiTestRoom'])
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
        $target=self::normalize($target);
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

    /** Find explicit room/floor phrases in full questions, using longest labels first. */
    public static function initialScope(string $question,array $catalog): ?array
    {
        if(!self::isNewQuestion($question)&&!str_ends_with(rtrim($question),'?'))return null;
        $locations=self::locations($catalog);
        $labels=self::locationLabels($locations);
        if(!preg_match_all(self::locationPattern($labels,true),$question,$matches,PREG_SET_ORDER)){
            $simple=self::simpleTemperatureQuestion($question);
            if(($simple['room']??'')==='')return null;
            $scope=self::scope($simple['room'],$catalog);
            return ($scope['type']??'')==='room'?$scope:null;
        }
        if(!preg_match(self::locationPattern($labels),$question))return null;
        $scopes=[];
        foreach($matches as $match){
            $scope=self::scope($match[1],$catalog);
            if($scope===null)return ['type'=>'unresolved','name'=>$match[1],'rooms'=>[]];
            $scopes[implode('|',$scope['rooms'])]=$scope;
        }
        if(count($scopes)===1)return reset($scopes);
        // A comparison can mention several locations. Limit the candidate
        // set to their union, but leave per-tool filters available to the model.
        $rooms=[];$names=[];
        foreach($scopes as $scope){
            $rooms=array_merge($rooms,$scope['rooms']);
            $names[]=$scope['name'];
        }
        return ['type'=>'multiple','name'=>implode(', ',$names),'rooms'=>array_values(array_unique($rooms))];
    }

    private static function locationLabels(array $locations): array
    {
        $labels=[];
        foreach($locations as $location){
            foreach($location['aliases'] as $alias)$labels[$alias]=true;
            $floor=self::normalize($location['floor']);
            if($floor!=='')$labels[$floor]=true;
        }
        foreach(['erdgeschoss','erd geschoss','parterre','obergeschoss','ober geschoss',
            'untergeschoss','keller','kellergeschoss','dachgeschoss','dach geschoss',
            'erstes obergeschoss','ersten obergeschoss','erster stock','ersten stock',
            'erste etage','ersten etage','1 obergeschoss','1 stock','1 etage','1 og','og 1','og1',
            'erstes untergeschoss','ersten untergeschoss','1 untergeschoss',
            'eg','og','ug','kg','dg','e g','o g','u g','k g','d g'] as $label)
            $labels[$label]=true;
        $labels=array_keys($labels);
        usort($labels,static fn($a,$b):int=>strlen((string)$b)<=>strlen((string)$a));
        return $labels;
    }

    private static function locationPattern(array $labels,bool $includeConjunction=false): string
    {
        $pattern=implode('|',array_map(static fn($v):string=>strtr(preg_quote((string)$v,'/'),[
            'ae'=>'(?:ae|ä)','oe'=>'(?:oe|ö)','ue'=>'(?:ue|ü)','ss'=>'(?:ss|ß)',
            ' '=>'[^\pL\pN]+'
        ]),$labels));
        $numbered='(?:\d+\.?\s*(?:obergeschoss|untergeschoss|og|ug|stock|etage)'
            .'|(?:og|ug|stock|etage)\s*\d+'
            .'|(?:zweite[rsn]?|dritte[rsn]?|vierte[rsn]?)\s+(?:obergeschoss|untergeschoss|stock|etage))';
        $prefix=$includeConjunction?'(?:'.self::LOCATION_PREFIX.'|und|oder)':self::LOCATION_PREFIX;
        return '/(?<![\pL\pN])'.$prefix.'\s+(?:(?:ganzen|gesamten)\s+)?('.$numbered.'|'.$pattern.')(?![\pL\pN])/ui';
    }

    /** Remove explicit old locations so a follow-up replaces them. */
    public static function withoutLocation(string $question,array $catalog): string
    {
        $result=preg_replace(self::locationPattern(self::locationLabels(self::locations($catalog))),
            ' ',$question)??$question;
        if($result===$question){
            $simple=self::simpleTemperatureQuestion($question);
            if(($simple['room']??'')!=='' && (self::scope($simple['room'],$catalog)['type']??'')==='room')
                return $simple['question'];
        }
        return trim(preg_replace('/\s+/u',' ',$result)??$result);
    }
}
