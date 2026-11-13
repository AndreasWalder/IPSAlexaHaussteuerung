<?php
declare(strict_types=1);

/** Shared, transient form for the Alexa instance and RoomsCatalog. */
final class IAHVoiceRulePreviewForm
{
    public static function panel(string $onClick,?array $instances=null,int $instanceId=0): array
    {
        // Bind the random conversation to this rendered form, not to an
        // instance property or a field shared by all open consoles.
        $onClick=str_replace('$VoiceTestConversation',var_export(bin2hex(random_bytes(16)),true),$onClick);
        $items=[
            ['type'=>'Label','caption'=>'Satz wie zu Alexa eingeben, z. B. „Welche Lichter sind eingeschaltet?“. '
                .'Eigene Regeln, Hausfragen und Navigation werden automatisch unterschieden. '
                .'Danach kannst du „Und im Obergeschoss?“ fragen. Eine neue vollständige Hausfrage startet ein neues Thema. '
                .'Nichts wird geschaltet.']
        ];
        if($instances!==null)
            $items[]=['type'=>'Select','name'=>'VoiceTestInstance','caption'=>'Alexa-Instanz',
                'value'=>$instanceId,'options'=>$instances];
        $items[]=['type'=>'RowLayout','items'=>[
            ['type'=>'ValidationTextBox','name'=>'VoiceTestText','caption'=>'Gesprochener Satz',
                'value'=>'','width'=>'350px'],
            ['type'=>'Select','name'=>'VoiceTestSource','caption'=>'Stand eigener Regeln','value'=>'PROD',
                'options'=>[
                    ['caption'=>'PROD – veröffentlicht','value'=>'PROD'],
                    ['caption'=>'EDIT – Entwurf','value'=>'EDIT']
                ]]
        ]];
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
        if(($result['category']??'')==='house_question')
            return 'Hausfrage · RoomsCatalog PROD · '.($result['answer']??$result['error']??'Keine Antwort.');
        if(!empty($result['inactiveMatches'])&&!empty($result['error'])
            && ($result['category']??'')==='retired')
            return $prefix.'Passende eigene Regel ist deaktiviert.';
        if(!empty($result['error']))return $prefix.($result['title']??$result['ruleKey']??'Test')
            .' – '.$result['error'];
        if(empty($result['matched']))return $prefix.'Keine aktive eigene Sprachregel passt.';
        if(in_array($result['category']??'',['navigation','session','clarify','action'],true))
            return (string)$result['answer'];
        $method=match($result['matchMethod']??''){
            'alias'=>'Alias „'.$result['matchedAlias'].'“',
            'intent'=>'Alexa-Intent',
            'ai_read_only'=>'KI (nur lesend)',
            default=>'Treffer'
        };
        return $prefix.$result['title'].' · '.$method
            .(!empty($result['answer'])?' · '.$result['answer']:'').' · Vorschau.';
    }
}
