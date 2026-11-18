<?php
declare(strict_types=1);

/** Keep decimal constants from looking like object references in Symcon scripts. */
final class IAHSymconScriptSource
{
    public static function normalize(string $source): string
    {
        $result = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $result .= $token;
                continue;
            }
            $text = $token[1];
            if ($token[0] === T_LNUMBER && preg_match('/^[1-9][0-9]{4,}$/D', $text)) {
                $text = self::integer((int)$text);
            }
            $result .= $text;
        }
        return $result;
    }

    private static function integer(int $value): string
    {
        if ($value < 10 * 1000) return (string)$value;
        $known = match ($value) {
            24 * 60 * 60 => '(24 * 60 * 60)',
            12 * 60 * 60 => '(12 * 60 * 60)',
            64 * 1024 => '(64 * 1024)',
            128 * 1024 => '(128 * 1024)',
            10 * 1024 * 1024 => '(10 * 1024 * 1024)',
            16 * 1024 * 1024 => '(16 * 1024 * 1024)',
            default => null,
        };
        if ($known !== null) return $known;
        $expression = self::integer(intdiv($value, 1000)) . ' * 1000';
        $remainder = $value % 1000;
        if ($remainder !== 0) $expression .= ' + ' . $remainder;
        return '(' . $expression . ')';
    }
}
