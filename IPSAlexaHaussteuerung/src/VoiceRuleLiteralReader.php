<?php
declare(strict_types=1);

/**
 * Read the literal PHP-array snapshot emitted by IAHSystemNavigationEditor::encode
 * without including/evaluating the script. This avoids stale include/OPcache
 * results in long-running IPS workers while never executing source text.
 *
 * Unrecognized tokens or expressions are rejected: old dynamically generated
 * configurations can still be read by the previous include path.
 */
final class IAHVoiceRuleLiteralReader
{
    private const MAX_SOURCE_BYTES = 10485760;
    private const MAX_TOKENS = 1200000;
    private const MAX_DEPTH = 50;

    public static function read(?string $source): ?array
    {
        if(!is_string($source) || $source==='' || strlen($source)>self::MAX_SOURCE_BYTES)
            return null;
        try {
            $all=token_get_all($source);
            if(count($all)>self::MAX_TOKENS)return null;
            $tokens=[];
            foreach($all as $token){
                if(is_array($token)){
                    if(in_array($token[0],[
                        T_OPEN_TAG,T_CLOSE_TAG,T_WHITESPACE,T_COMMENT,T_DOC_COMMENT
                    ],true))continue;
                    $tokens[]=['type'=>$token[0],'text'=>$token[1]];
                }else{
                    $tokens[]=['type'=>0,'text'=>$token];
                }
            }
            $i=0;
            if(($tokens[0]['type']??null)===T_DECLARE
                && array_column(array_slice($tokens,1,6),'text')===['(','strict_types','=','1',')',';'])
                $i=7;
            if(($tokens[$i]['type']??null)!==T_RETURN)return null;
            $i++;
            $result=self::value($tokens,$i,0);
            if(($tokens[$i]['text']??null)===';')$i++;
            if($i!==count($tokens)||!is_array($result))return null;
            return $result;
        }catch(Throwable $ignored){
            return null;
        }
    }

    private static function value(array &$tokens,int &$i,int $depth): mixed
    {
        if($depth>self::MAX_DEPTH)throw new RuntimeException('Maximale Array-Verschachtelung.');
        $token=$tokens[$i]??null;
        if($token===null)throw new RuntimeException('Unvollstaendiger PHP-Literalwert.');
        $type=$token['type'];
        $text=$token['text'];
        if($type===T_ARRAY){
            $i++;
            self::expect($tokens,$i,'(');
            return self::items($tokens,$i,')',$depth+1);
        }
        if($text==='['){
            $i++;
            return self::items($tokens,$i,']',$depth+1);
        }
        if($type===T_CONSTANT_ENCAPSED_STRING){
            $i++;
            // var_export always uses single-quoted PHP literals.
            if(strlen($text)<2 || $text[0]!=="'"
                || substr($text,-1)!=="'")
                throw new RuntimeException('Nicht unterstuetztes PHP-Stringformat.');
            return strtr(substr($text,1,-1),[
                '\\\\'=>'\\',
                "\\'"=>"'"
            ]);
        }
        if($type===T_STRING){
            $i++;
            return match(strtolower($text)){
                'true'=>true,'false'=>false,'null'=>null,
                default=>throw new RuntimeException('Nicht-literal PHP Ausdruck.')
            };
        }
        if($text==='-'||$text==='+'){
            $i++;
            $next=$tokens[$i]??null;
            if(!in_array($next['type']??null,[T_LNUMBER,T_DNUMBER],true))
                throw new RuntimeException('Nur vorzeichenbehaftete Zahl erlaubt.');
            $i++;
            $number=$next['text'];
            if(!is_numeric($number))throw new RuntimeException('Zahl ungueltig.');
            return $next['type']===T_DNUMBER
                ?(float)($text.$number):(int)($text.$number);
        }
        if(in_array($type,[T_LNUMBER,T_DNUMBER],true)){
            $i++;
            if(!is_numeric($text))throw new RuntimeException('Zahl ungueltig.');
            return $type===T_DNUMBER?(float)$text:(int)$text;
        }
        throw new RuntimeException('Unerwarteter PHP-Token.');
    }

    private static function items(array &$tokens,int &$i,string $end,int $depth): array
    {
        $result=[];
        while(($tokens[$i]['text']??null)!==$end){
            if(!isset($tokens[$i]))throw new RuntimeException('Array nicht abgeschlossen.');
            $first=self::value($tokens,$i,$depth);
            if(($tokens[$i]['type']??null)===T_DOUBLE_ARROW){
                $i++;
                if(!is_int($first)&&!is_string($first))
                    throw new RuntimeException('Array-Key kein Literal.');
                $result[$first]=self::value($tokens,$i,$depth);
            }else{
                $result[]=$first;
            }
            $next=$tokens[$i]['text']??null;
            if($next===','){
                $i++;
                continue;
            }
            if($next===$end)break;
            throw new RuntimeException('Unerwarteter Array-Trenner.');
        }
        self::expect($tokens,$i,$end);
        return $result;
    }

    private static function expect(array &$tokens,int &$i,string $symbol): void
    {
        if(($tokens[$i]['text']??null)!==$symbol)
            throw new RuntimeException('Unvollstaendiger PHP-Array-Literal.');
        $i++;
    }
}
