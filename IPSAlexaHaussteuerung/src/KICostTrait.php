<?php
declare(strict_types=1);

namespace IAHKICost;

trait KICostTrait
{
    private function EnsureKICostInfrastructure(): void
    {
        $root=$this->ensureCategory($this->InstanceID,'KI-Kosten','iahKICost');
        if (!\IPS_VariableProfileExists('IAH.KICostUSD')) {
            \IPS_CreateVariableProfile('IAH.KICostUSD',VARIABLETYPE_FLOAT);
            \IPS_SetVariableProfileDigits('IAH.KICostUSD',6);
            \IPS_SetVariableProfileText('IAH.KICostUSD','$','');
        }
        $raw=$this->ensureVar($root,'KI-Kosten Rohdaten (nicht bearbeiten)','kiCostData',VARIABLETYPE_STRING,'',null);
        \IPS_SetHidden($raw,true);
        $this->ensureVar($root,'KI-Kosten Übersicht','kiCostOverview',VARIABLETYPE_STRING,'~HTMLBox',null);
        foreach ([
            'Alle'=>'Alle KI-Anbindungen','RoomsCatalog'=>'KI + RoomsCatalog',
            'HomeAgent'=>'KI + Home Agent','IntentParser'=>'Sonstige KI – Intent-Parser'
        ] as $ident=>$label) {
            $category=$this->ensureCategory($root,$label,'iahKICost'.$ident);
            foreach ($this->KICostVariableDefinitions() as $key=>[$caption,$type,$profile])
                $this->ensureVar($category,$caption,$key,$type,$profile,null);
        }
        $this->RefreshKICostDisplay();
    }

    private function KICostVariableDefinitions(): array
    {
        $i=VARIABLETYPE_INTEGER;$f=VARIABLETYPE_FLOAT;$s=VARIABLETYPE_STRING;
        $usd='IAH.KICostUSD';
        return [
            'questionsToday'=>['Fragen heute',$i,''],
            'questionsMonth'=>['Fragen diesen Monat',$i,''],
            'questionsTotal'=>['Fragen gesamt',$i,''],
            'questionsFailed'=>['Fehlgeschlagene Fragen gesamt',$i,''],
            'apiCallsToday'=>['OpenAI-Aufrufe heute',$i,''],
            'apiCallsMonth'=>['OpenAI-Aufrufe diesen Monat',$i,''],
            'apiCallsTotal'=>['OpenAI-Aufrufe gesamt',$i,''],
            'inputTokensTotal'=>['Eingabe-Tokens gesamt',$i,''],
            'cachedTokensTotal'=>['Gecachte Eingabe-Tokens gesamt',$i,''],
            'outputTokensTotal'=>['Ausgabe-Tokens gesamt',$i,''],
            'allTokensTotal'=>['Tokens insgesamt',$i,''],
            'inputTokensMonth'=>['Eingabe-Tokens diesen Monat',$i,''],
            'outputTokensMonth'=>['Ausgabe-Tokens diesen Monat',$i,''],
            'costTodayUSD'=>['KI-Kosten heute (USD)',$f,$usd],
            'costMonthUSD'=>['KI-Kosten diesen Monat (USD)',$f,$usd],
            'costTotalUSD'=>['KI-Kosten gesamt (USD)',$f,$usd],
            'lastCostUSD'=>['Letzte Frage – Kosten (USD)',$f,$usd],
            'lastQuestionApiCalls'=>['Letzte Frage – API-Aufrufe',$i,''],
            'lastQuestionInputTokens'=>['Letzte Frage – Eingabe-Tokens',$i,''],
            'lastQuestionCachedTokens'=>['Letzte Frage – Cache-Tokens',$i,''],
            'lastQuestionOutputTokens'=>['Letzte Frage – Ausgabe-Tokens',$i,''],
            'lastQuestionModel'=>['Letztes Modell',$s,''],
            'lastQuestionStatus'=>['Letzte Frage – Ergebnis',$s,''],
            'lastQuestionTime'=>['Letzte Frage – Zeitpunkt',$s,''],
            'unpricedCalls'=>['API-Aufrufe ohne bekannte Preise',$i,''],
            'unmeasuredCalls'=>['API-Aufrufe ohne Token-Daten',$i,''],
            'detailsJSON'=>['Letzte 100 Fragen – Details (JSON)',$s,''],
        ];
    }

    public function RecordKICostEvent(string $eventJson): bool
    {
        $event=json_decode($eventJson,true);
        if (!is_array($event)) return false;
        $mutex='IAH.KICost.'.$this->InstanceID;
        if (!\IPS_SemaphoreEnter($mutex,5000)) {
            \IPS_LogMessage('Alexa','KICost.error: Statistik-Semaphor belegt');
            return false;
        }
        try {
            $root=(int)@\IPS_GetObjectIDByIdent('iahKICost',$this->InstanceID);
            $id=$root>0?(int)@\IPS_GetObjectIDByIdent('kiCostData',$root):0;
            if ($id<=0 || !\IPS_VariableExists($id)) return false;
            $store=json_decode((string)\GetValueString($id),true);
            if (!is_array($store)) $store=[];
            $prices=json_decode($this->ReadPropertyString('KICostPriceTableJson'),true);
            if (!is_array($prices)) $prices=\iah_ki_price_table();
            $now=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Vienna'));
            if (!\iah_ki_apply_event($store,$event,$prices,$now)) return false;
            \SetValueString($id,json_encode($store,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
            $snap=\iah_ki_section_snapshot($store,'Alle',$now);
            $limit=$this->ReadPropertyFloat('KICostMonthlyWarningUSD');
            $month=$now->format('Y-m');
            if ($limit>0 && (float)$snap['month']['costUsd'] >= $limit
                && ($store['warnedMonth']??'')!==$month) {
                $store['warnedMonth']=$month;
                \SetValueString($id,json_encode($store,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                \IPS_LogMessage('Alexa',sprintf('KICost.WARNUNG: Monatsgrenze %.2f USD erreicht (%.6f USD).',
                    $limit,(float)$snap['month']['costUsd']));
            }
            if (in_array($event['type']??'', ['api','finish'],true)) $this->PublishKICostVariables($store,$now);
            return true;
        } catch (\Throwable $e) {
            \IPS_LogMessage('Alexa','KICost.error: '.$e->getMessage());
            return false;
        } finally {
            \IPS_SemaphoreLeave($mutex);
        }
    }

    public function RefreshKICostDisplay(): void
    {
        $mutex='IAH.KICost.'.$this->InstanceID;
        if (!\IPS_SemaphoreEnter($mutex,500)) return;
        try {
            $root=(int)@\IPS_GetObjectIDByIdent('iahKICost',$this->InstanceID);
            $id=$root>0?(int)@\IPS_GetObjectIDByIdent('kiCostData',$root):0;
            if ($id<=0 || !\IPS_VariableExists($id)) return;
            $raw=json_decode((string)\GetValueString($id),true);
            $this->PublishKICostVariables(is_array($raw)?$raw:[],
                new \DateTimeImmutable('now',new \DateTimeZone('Europe/Vienna')));
        } finally { \IPS_SemaphoreLeave($mutex); }
    }
}
