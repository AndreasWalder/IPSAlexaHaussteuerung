<?php
declare(strict_types=1);

/** Shared, transient form for the Alexa instance and RoomsCatalog. */
final class IAHVoiceRulePreviewForm
{
    public static function panel(string $onClick,?array $instances=null,int $instanceId=0): array
    {
        $items=[
            ['type'=>'Label','caption'=>'Testet nur eigene Sprachregeln. Kein Skriptstart, keine '
                .'Geräteaktion und keine Veröffentlichung. Die Echo-Spracherkennung wird nicht getestet.']
        ];
        if($instances!==null)
            $items[]=['type'=>'Select','name'=>'VoiceTestInstance','caption'=>'Alexa-Instanz',
                'value'=>$instanceId,'options'=>$instances];
        $items[]=['type'=>'RowLayout','items'=>[
            ['type'=>'ValidationTextBox','name'=>'VoiceTestText','caption'=>'Gesprochener Satz',
                'value'=>'','width'=>'350px'],
            ['type'=>'Select','name'=>'VoiceTestSource','caption'=>'Sprachregeln','value'=>'PROD',
                'options'=>[
                    ['caption'=>'PROD – veröffentlicht','value'=>'PROD'],
                    ['caption'=>'EDIT – Entwurf','value'=>'EDIT']
                ]],
            ['type'=>'ValidationTextBox','name'=>'VoiceTestIntent','caption'=>'Alexa-Intent (optional)',
                'value'=>'']
        ]];
        $items[]=['type'=>'CheckBox','name'=>'VoiceTestAi','value'=>false,
            'caption'=>'Bei Bedarf auch KI testen (nur Antworten/Messwerte, verwendet die konfigurierte API)'];
        $items[]=['type'=>'Button','caption'=>'SPRACHBEFEHL TESTEN','onClick'=>$onClick];
        $items[]=['type'=>'Label','name'=>'VoiceTestSummary','caption'=>'Noch kein Sprachbefehl getestet.'];
        $items[]=['type'=>'ValidationTextBox','name'=>'VoiceTestResult','caption'=>'Vorschau (kopierbares JSON)',
            'multiline'=>true,'width'=>'100%','value'=>''];
        return ['type'=>'ExpansionPanel','caption'=>'Sprachbefehl testen','expanded'=>false,'items'=>$items];
    }

    public static function summary(array $result): string
    {
        $source=(string)($result['source']??'');
        $prefix=$source!==''?$source.' · ':'';
        if(!empty($result['error']))return $prefix.($result['title']??$result['ruleKey']??'Test')
            .' – '.$result['error'];
        if(empty($result['matched']))return $prefix.'Keine aktive eigene Sprachregel passt.';
        $method=match($result['matchMethod']??''){
            'alias'=>'Alias „'.$result['matchedAlias'].'“',
            'intent'=>'Alexa-Intent',
            'ai_read_only'=>'KI (nur lesend)',
            default=>'Treffer'
        };
        return $prefix.$result['title'].' · '.$method.' · Nichts ausgeführt.';
    }
}
