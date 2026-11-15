<?php
declare(strict_types=1);

/** Shared recognition for a general, read-only PV status question. */
final class IAHPvStatusQuestion
{
    public static function match(string $text): string
    {
        $text = str_replace(['ä', 'ü'], ['ae', 'ue'], mb_strtolower($text, 'UTF-8'));
        $text = trim(preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? '');
        if ($text === '' || strlen($text) > 160) return '';
        $text = preg_replace('/ (?:gerade|aktuell|heute)$/', '', $text) ?? $text;
        $healthQuestion = preg_match('/^(?:wie geht es(?: mit)?|wie laeuft|laeuft|was macht|wie steht es um) (.+)$/D',
            $text, $match) === 1;
        if (!$healthQuestion && !preg_match(
            '/^(?:wie ist der |(?:zeig(?:e)?|sag(?:e)?) mir (?:den |die )?)?(?:status|uebersicht) (.+)$|^(.+?) (?:status|uebersicht)$/D',
            $text, $match
        )) return '';

        $subject = trim($match[1] ?: $match[2]);
        $subject = preg_replace('/^(?:die|der|meine|meiner|unsere|unserer) /', '', $subject) ?? $subject;
        $name = str_replace(' ', '', $subject);
        if (in_array($name, ['pv','pvanlage','solaranlage','huaweipv','sun2000','sun2000pv'], true)) return 'pv';
        if ($healthQuestion && $name === 'tvanlage') return 'tv_candidate';

        // Permit a single transcription error in a long PV name, away from short device names.
        $name = preg_replace('/anlage$/D', '', $name) ?? $name;
        foreach (['photovoltaik','fotovoltaik'] as $alias) {
            if (abs(strlen($name) - strlen($alias)) <= 1 && levenshtein($name, $alias) <= 1) return 'pv';
        }
        return '';
    }
}
