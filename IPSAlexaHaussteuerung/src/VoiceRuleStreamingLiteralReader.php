<?php
declare(strict_types=1);

/**
 * Memory-efficient literal-array parser for large var_export() config scripts.
 * Parses only strings, numbers, booleans, null and nested array literals.
 * Never executes PHP or follows includes.
 */
final class IAHVoiceRuleStreamingLiteralReader
{
    private const MAX_DEPTH=60;
    private const MAX_BYTES=24*1024*1024;
    private string $source;
    private int $offset=0;
    private int $length;
    private int $count=0;
    private int $limit;

    private function __construct(string $source)
    {
        $this->source=$source;
        $this->length=strlen($source);
        $this->limit=self::phpLimit();
    }

    public static function read(string $source): ?array
    {
        if($source==='' || strlen($source)>self::MAX_BYTES)return null;
        try {
            $p=new self($source);
            $p->skip();
            $p->consume('<?php');
            $p->skip();
            if($p->word('declare')){
                $p->skip();$p->consume('(');$p->skip();
                $p->consume('strict_types');$p->skip();
                $p->consume('=');$p->skip();$p->consume('1');$p->skip();
                $p->consume(')');$p->skip();$p->consume(';');$p->skip();
            }
            $p->consume('return');
            $p->skip();
            $value=$p->parse(0);
            $p->skip();
            if($p->char()===';'){$p->offset++;$p->skip();}
            if(substr_compare($source,'?>',$p->offset,2)===0){
                $p->offset+=2;$p->skip();
            }
            return $p->offset===$p->length && is_array($value)?$value:null;
        } catch(\Throwable $ignored) {
            return null;
        }
    }

    private static function phpLimit(): int
    {
        $s=trim((string)ini_get('memory_limit'));
        if($s==='' || $s==='-1')return 0;
        if(!preg_match('/^(\d+)\s*([KMG]?)B?$/iD',$s,$m))return 0;
        return (int)$m[1]*match(strtoupper($m[2])){
            'G'=>1073741824,'M'=>1048576,'K'=>1024,default=>1
        };
    }

    private function guard(): void
    {
        if((++$this->count%128)!==0 || $this->limit<=0)return;
        if(memory_get_usage(true)>$this->limit-8*1024*1024)
            throw new \RuntimeException('Insufficient memory for literal array');
    }

    private function char(): ?string
    {
        return $this->offset<$this->length?$this->source[$this->offset]:null;
    }

    private function skip(): void
    {
        while($this->offset<$this->length){
            $c=$this->source[$this->offset];
            if($c===" " || $c==="\n" || $c==="\r" || $c==="\t"){
                $this->offset++;continue;
            }
            if($c==='#' || substr_compare($this->source,'//',$this->offset,2)===0){
                while($this->offset<$this->length
                    && $this->source[$this->offset]!=="\n")$this->offset++;
                continue;
            }
            if(substr_compare($this->source,'/*',$this->offset,2)===0){
                $end=strpos($this->source,'*/',$this->offset+2);
                if($end===false)throw new \RuntimeException('Unclosed PHP comment');
                $this->offset=$end+2;continue;
            }
            break;
        }
    }

    private function consume(string $wanted): void
    {
        if(substr_compare($this->source,$wanted,$this->offset,strlen($wanted))!==0)
            throw new \RuntimeException('Unexpected PHP literal');
        $this->offset+=strlen($wanted);
    }

    private function word(string $wanted): bool
    {
        $n=strlen($wanted);
        if(strncasecmp(substr($this->source,$this->offset,$n),$wanted,$n)!==0)return false;
        $next=$this->source[$this->offset+$n]??'';
        if($next!=='' && (ctype_alnum($next)||$next==='_'))return false;
        $this->offset+=$n;
        return true;
    }

    private function quoted(): string
    {
        $this->consume("'");
        $start=$this->offset;
        $hasEscape=false;
        while($this->offset<$this->length){
            $c=$this->source[$this->offset++];
            if($c===chr(92)){
                $hasEscape=true;
                $this->offset++;
                continue;
            }
            if($c==="'"){
                $raw=substr($this->source,$start,$this->offset-$start-1);
                if(!$hasEscape)return $raw;
                return strtr($raw,[
                    chr(92).chr(92)=>chr(92),
                    chr(92)."'"=>"'"
                ]);
            }
        }
        throw new \RuntimeException('Unclosed PHP string');
    }

    private function parse(int $depth): mixed
    {
        if($depth>self::MAX_DEPTH)throw new \RuntimeException('Array nesting exceeded');
        $this->guard();
        $this->skip();
        if($this->char()==="'")return $this->quoted();
        if($this->char()==='['){$this->offset++;return $this->items(']',$depth+1);}
        if($this->word('array')){
            $this->skip();$this->consume('(');
            return $this->items(')',$depth+1);
        }
        foreach(['true'=>true,'false'=>false,'null'=>null] as $word=>$value){
            if($this->word($word))return $value;
        }
        if(preg_match('/\G[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?/A',
            $this->source,$m,0,$this->offset)===1){
            $v=$m[0];$this->offset+=strlen($v);
            $next=$this->char();
            if($next!==null && (ctype_alnum($next)||$next==='_'||$next==='.'))
                throw new \RuntimeException('Invalid number');
            return strpbrk($v,'.eE')!==false?(float)$v:(int)$v;
        }
        throw new \RuntimeException('Expression is not a PHP literal');
    }

    private function items(string $ending,int $depth): array
    {
        $result=[];
        $this->skip();
        if($this->char()===$ending){$this->offset++;return $result;}
        while(true){
            $first=$this->parse($depth);
            $this->skip();
            if(substr_compare($this->source,'=>',$this->offset,2)===0){
                if(!is_int($first)&&!is_string($first))
                    throw new \RuntimeException('Nonliteral array key');
                $this->offset+=2;
                $result[$first]=$this->parse($depth);
            }else{
                $result[]=$first;
            }
            $this->skip();
            if($this->char()===$ending){$this->offset++;return $result;}
            $this->consume(',');
            $this->skip();
            if($this->char()===$ending){$this->offset++;return $result;}
        }
    }
}
