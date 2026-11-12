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
        // EUR ist die sichtbare Währung, historische USD-Kosten bleiben
        // für Audits in den ausgeblendeten Altvariablen und Rohdaten erhalten.
        if (!\IPS_VariableProfileExists('IAH.KICostEUR')) {
            \IPS_CreateVariableProfile('IAH.KICostEUR',VARIABLETYPE_FLOAT);
            \IPS_SetVariableProfileDigits('IAH.KICostEUR',6);
            \IPS_SetVariableProfileText('IAH.KICostEUR','€ ','');
        }
        $this->ensureVar($root,'USD nach EUR – verwendeter Kurs','kiCostFxRate',VARIABLETYPE_FLOAT,'',null);
        $this->ensureVar($root,'Wechselkurs – Datum','kiCostFxDate',VARIABLETYPE_STRING,'',null);
        $this->ensureVar($root,'Wechselkurs – Quelle / Status','kiCostFxSource',VARIABLETYPE_STRING,'',null);
        $raw=$this->ensureVar($root,'KI-Kosten Rohdaten (nicht bearbeiten)','kiCostData',VARIABLETYPE_STRING,'',null);
        \IPS_SetHidden($raw,true);
        $this->ensureVar($root,'KI-Kosten Übersicht','kiCostOverview',VARIABLETYPE_STRING,'~HTMLBox',null);
        // Aktive Kostenbereiche: kein HomeAgent-Backend mehr.
        foreach ([
            'Alle'=>'KI-Kosten gesamt','RoomsCatalog'=>'ChatGPT + RoomsCatalog',
            'IntentParser'=>'KI – Intent-Parser'
        ] as $ident=>$label) {
            $category=$this->ensureCategory($root,$label,'iahKICost'.$ident);
            foreach ($this->KICostVariableDefinitions() as $key=>[$caption,$type,$profile])
                $this->ensureVar($category,$caption,$key,$type,$profile,null);
            // Frühere USD-Variablen nicht löschen (Referenzen behalten ihre
            // eindeutige Bedeutung), aber in der Oberfläche ausblenden.
            foreach (['costTodayUSD','costMonthUSD','costTotalUSD',
                'costMonthForecastUSD','averageCostPerQuestionUSD','lastCostUSD'] as $ident) {
                $old=(int)@\IPS_GetObjectIDByIdent($ident,$category);
                if($old>0&&\IPS_VariableExists($old))\IPS_SetHidden($old,true);
            }
        }
        // Bestehende Alt-Kategorie NICHT loeschen: alte Variablen-IDs,
        // Verknuepfungen und historische Kosten bleiben erhalten, aber die
        // nicht mehr verwendete Kategorie verschwindet aus dem Objektbaum.
        $legacy=(int)@\IPS_GetObjectIDByIdent('iahKICostHomeAgent',$root);
        if($legacy>0 && function_exists('IPS_ObjectExists')
            && \IPS_ObjectExists($legacy)) {
            \IPS_SetHidden($legacy,true);
        }
        $this->UpdateKICostExchangeRate();
        $this->RefreshKICostDisplay();
    }

    private function KICostVariableDefinitions(): array
    {
        $i=VARIABLETYPE_INTEGER;$f=VARIABLETYPE_FLOAT;$s=VARIABLETYPE_STRING;
        $eur='IAH.KICostEUR';
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
            'costTodayEUR'=>['KI-Kosten heute (EUR)',$f,$eur],
            'costMonthEUR'=>['KI-Kosten diesen Monat (EUR)',$f,$eur],
            'costTotalEUR'=>['KI-Kosten gesamt (EUR)',$f,$eur],
            'costMonthForecastEUR'=>['Hochrechnung Monatsende (EUR)',$f,$eur],
            'averageCostPerQuestionEUR'=>['Durchschnitt pro Frage (EUR)',$f,$eur],
            'lastCostEUR'=>['Letzte Frage – Kosten (EUR)',$f,$eur],
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


    /**
     * Liefert den gültigen USD→EUR-Kurs; nie einen unbezeichneten
     * Fantasie-Wert. Bei Netzwerkausfall wird der letzte bekannte ECB-Kurs
     * benutzt und die Veraltung in der Oberfläche ausgewiesen.
     */
    private function GetKICostCurrency(): array
    {
        $manual=(float)$this->ReadPropertyFloat('KICostManualUsdToEur');
        if($manual>0 && $manual<3) {
            return ['rate'=>$manual,'date'=>'manuell','source'=>'Manuell konfiguriert'];
        }
        $cached=json_decode((string)$this->ReadAttributeString('KICostFxData'),true);
        if(is_array($cached) && (float)($cached['eurPerUsd']??0)>0) {
            return ['rate'=>(float)$cached['eurPerUsd'],
                'date'=>(string)($cached['date']??''),
                'source'=>'EZB-Referenzkurs'.(
                    isset($cached['date'])&&$cached['date']!==(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Vienna')))->format('Y-m-d')
                        ? ' (letzter verfügbarer Banktag / ggf. veraltet)' : ''
                )];
        }
        // Letzter verifizierter Referenzkurs am 30.10.2026: 1 EUR = 1,1172 USD.
        // Falls der Server den ECB-Dienst nicht erreicht, sichtbar als Fallback.
        return ['rate'=>1.0/1.1172,'date'=>'2026-10-30',
            'source'=>'FALLBACK 30.10.2026 – EZB momentan nicht erreichbar'];
    }

    /**
     * ECB veröffentlicht den Kurs USD pro 1 EUR meist einmal pro Banktag.
     * Aus dem XML wird 1 / USD-pro-EUR berechnet. HTTPS-Zertifikate prüfen.
     * Caching vermeidet Verzögerungen bei jedem Alexa-Sprachbefehl.
     */
    public function UpdateKICostExchangeRate(): bool
    {
        $manual=(float)$this->ReadPropertyFloat('KICostManualUsdToEur');
        if($manual>0 && $manual<3)return true;
        $now=time();
        if($now<(int)$this->ReadAttributeInteger('KICostNextRateUpdate'))return true;
        $this->WriteAttributeInteger('KICostNextRateUpdate',$now+7200); // Fehler: 2 h Pause
        if(!function_exists('curl_init'))return false;
        $h=curl_init('https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml');
        if($h===false)return false;
        try {
            curl_setopt_array($h,[
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>3, CURLOPT_CONNECTTIMEOUT=>2,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_USERAGENT=>'IPSAlexaHaussteuerung/1.0'
            ]);
            $xml=curl_exec($h);
            $status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);
        } finally { curl_close($h); }
        $parsed=$status===200 && is_string($xml) ? \iah_ki_ecb_parse($xml) : null;
        if(!is_array($parsed))return false;
        // Offiziellen Kurs niemals mit ungültiger Datumsangabe übernehmen.
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)$parsed['date']);
        if(!$dt || $dt->getTimestamp()>$now+86400 || $dt->getTimestamp()<$now-14*86400)return false;
        $this->WriteAttributeString('KICostFxData',json_encode($parsed,JSON_THROW_ON_ERROR));
        $this->WriteAttributeInteger('KICostNextRateUpdate',$now+43200); // 12 h
        return true;
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
            $configuredPrices=json_decode($this->ReadPropertyString('KICostPriceTableJson'),true);
            // Neue Modellpreise stehen auch bei einem vorhandenen älteren JSON
            // zur Verfügung. Individuell geänderte Preise haben Vorrang.
            $prices=array_replace(\iah_ki_price_table(),is_array($configuredPrices)?$configuredPrices:[]);
            $now=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Vienna'));
            if (!\iah_ki_apply_event($store,$event,$prices,$now)) return false;
            \SetValueString($id,json_encode($store,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
            $snap=\iah_ki_section_snapshot($store,'Alle',$now);
            $limit=$this->ReadPropertyFloat('KICostMonthlyWarningEUR');
            $month=$now->format('Y-m');
            $fx=$this->GetKICostCurrency();
            $monthEUR=\iah_ki_eur((float)$snap['month']['costUsd'],(float)$fx['rate']);
            if ($limit>0 && $monthEUR >= $limit
                && ($store['warnedMonthEUR']??'')!==$month) {
                $store['warnedMonthEUR']=$month;
                \SetValueString($id,json_encode($store,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                \IPS_LogMessage('Alexa',sprintf('KICost.WARNUNG: Monatsgrenze %.2f EUR erreicht (%.6f EUR, Wechselkurs vom %s).',
                    $limit,$monthEUR,(string)$fx['date']));
            }
            // Nach der kompletten Frage aktualisieren, um Alexa nicht mit vielen IPS-SetValue-Aufrufen zu verzögern.
            // Bei einem harten Abbruch bleiben API-Kosten gespeichert und der 60-s-Timer aktualisiert die Ansicht.
            if (($event['type']??'') === 'finish') $this->PublishKICostVariables($store,$now);
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

    private function PublishKICostVariables(array $store, \DateTimeImmutable $now): void
    {
        $root=(int)@\IPS_GetObjectIDByIdent('iahKICost',$this->InstanceID);
        if ($root<=0) return;
        $fx=$this->GetKICostCurrency();
        $rate=(float)$fx['rate'];
        $rateId=(int)@\IPS_GetObjectIDByIdent('kiCostFxRate',$root);
        if($rateId>0 && \IPS_VariableExists($rateId)) \SetValueFloat($rateId,$rate);
        foreach (['kiCostFxDate'=>(string)$fx['date'],'kiCostFxSource'=>(string)$fx['source']] as $ident=>$value){
            $id=(int)@\IPS_GetObjectIDByIdent($ident,$root);
            if($id>0 && \IPS_VariableExists($id))\SetValueString($id,$value);
        }
        foreach (['Alle','RoomsCatalog','IntentParser'] as $backend) {
            $cat=(int)@\IPS_GetObjectIDByIdent('iahKICost'.$backend,$root);
            if ($cat<=0) continue;
            $snap=\iah_ki_section_snapshot($store,$backend,$now);
            $all=$snap['total'];$day=$snap['today'];$month=$snap['month'];$last=$snap['last'];
            $values=[
                'questionsToday'=>(int)$day['questions'],'questionsMonth'=>(int)$month['questions'],
                'questionsTotal'=>(int)$all['questions'],'questionsFailed'=>(int)$all['failures'],
                'apiCallsToday'=>(int)$day['apiCalls'],'apiCallsMonth'=>(int)$month['apiCalls'],
                'apiCallsTotal'=>(int)$all['apiCalls'],
                'inputTokensTotal'=>(int)$all['inputTokens'],
                'cachedTokensTotal'=>(int)$all['cachedInputTokens'],
                'outputTokensTotal'=>(int)$all['outputTokens'],
                'allTokensTotal'=>(int)$all['totalTokens'],
                'inputTokensMonth'=>(int)$month['inputTokens'],
                'outputTokensMonth'=>(int)$month['outputTokens'],
                'costTodayEUR'=>\iah_ki_eur((float)$day['costUsd'],$rate),
                'costMonthEUR'=>\iah_ki_eur((float)$month['costUsd'],$rate),
                'costTotalEUR'=>\iah_ki_eur((float)$all['costUsd'],$rate),
                'costMonthForecastEUR'=>\iah_ki_eur((float)$this->GetKICostForecast((float)$month['costUsd'],$now),$rate),
                'averageCostPerQuestionEUR'=>(int)$all['questions']>0
                    ? \iah_ki_eur((float)$all['costUsd']/(int)$all['questions'],$rate) : 0.0,
                'lastCostEUR'=>\iah_ki_eur((float)($last['costUsd']??0),$rate),
                'lastQuestionApiCalls'=>(int)($last['apiCalls']??0),
                'lastQuestionInputTokens'=>(int)($last['inputTokens']??0),
                'lastQuestionCachedTokens'=>(int)($last['cachedInputTokens']??0),
                'lastQuestionOutputTokens'=>(int)($last['outputTokens']??0),
                'lastQuestionModel'=>(string)($last['model']??''),
                'lastQuestionStatus'=>(string)($last['status']??''),
                'lastQuestionTime'=>(string)($last['finishedAt']??''),
                'unpricedCalls'=>(int)$all['callsWithoutPrice'],
                'unmeasuredCalls'=>(int)$all['callsWithoutUsage'],
                'detailsJSON'=>json_encode(\iah_ki_currency_breakdown($snap['history'],$rate),
                    JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),
            ];
            foreach ($values as $ident=>$value) {
                $var=(int)@\IPS_GetObjectIDByIdent($ident,$cat);
                if ($var<=0 || !\IPS_VariableExists($var)) continue;
                if (is_int($value)) \SetValueInteger($var,$value);
                elseif (is_float($value)) \SetValueFloat($var,$value);
                else \SetValueString($var,$value);
            }
        }
        $overview=(int)@\IPS_GetObjectIDByIdent('kiCostOverview',$root);
        if ($overview>0 && \IPS_VariableExists($overview))
            \SetValueString($overview,$this->RenderKICostOverview($store,$now));
    }


    private function GetKICostForecast(float $monthlyCost, \DateTimeImmutable $now): float
    {
        $daysInMonth=(int)$now->format('t');
        $elapsedDays=max(1,(int)$now->format('j'));
        // Konservative Tagesdurchschnitts-Hochrechnung; am Monatsanfang schwankend.
        return $monthlyCost/$elapsedDays*$daysInMonth;
    }

    private function RenderKICostOverview(array $store, \DateTimeImmutable $now): string
    {
        $fx=$this->GetKICostCurrency();
        $rate=(float)$fx['rate'];
        $eur=static fn(float $usd):string=>'€ '.number_format(
            \iah_ki_eur($usd,$rate),6,',','.');
        $int=static fn(int $number):string=>number_format($number,0,',','.');
        $esc=static fn($value):string=>htmlspecialchars(
            (string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $all=\iah_ki_section_snapshot($store,'Alle',$now);
        $day=$all['today'];$month=$all['month'];$total=$all['total'];
        $cards=[
            ['Fragen heute',$int((int)$day['questions'])],
            ['API-Aufrufe heute',$int((int)$day['apiCalls'])],
            ['Tokens heute',$int((int)$day['totalTokens'])],
            ['Kosten heute',$eur((float)$day['costUsd'])],
            ['Kosten diesen Monat',$eur((float)$month['costUsd'])],
            ['Prognose Monatsende',$eur($this->GetKICostForecast(
                (float)$month['costUsd'],$now))],
            ['Kosten gesamt',$eur((float)$total['costUsd'])]
        ];
        $html='<div style="font-family:Arial,sans-serif;font-size:13px;line-height:1.45;'
            .'color:#242424"><h3 style="margin:0 0 4px">KI-Kostenübersicht</h3>'
            .'<div style="color:#666;margin-bottom:14px">Gesamt · RoomsCatalog · Intent-Parser'
            .' — alles in EUR. USD→EUR: '.number_format($rate,6,',','.')
            .' ('.$esc($fx['date']).'; '.$esc($fx['source']).')</div>'
            .'<div style="display:flex;flex-wrap:wrap;gap:9px;margin-bottom:18px">';
        foreach($cards as [$label,$value]) {
            $html.='<div style="flex:1 1 155px;border:1px solid #dedede;'
                .'border-radius:6px;padding:10px 12px;min-width:120px">'
                .'<div style="color:#777;font-size:12px">'.$esc($label).'</div>'
                .'<div style="font-size:17px;font-weight:600;margin-top:5px">'
                .$esc($value).'</div></div>';
        }
        $html.='</div><h4 style="margin:10px 0">Aktive KI-Anbindungen</h4>'
            .'<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;'
            .'white-space:nowrap">'
            .'<thead><tr style="background:#eee">';
        foreach(['Anbindung','Fragen heute','API heute','Eingabe Monat','Cache Monat',
            'Ausgabe Monat','Kosten heute','Kosten Monat','Prognose','Gesamt'] as $heading)
            $html.='<th style="padding:7px;text-align:left">'.$esc($heading).'</th>';
        $html.='</tr></thead><tbody>';
        foreach ([
            'Alle'=>'Gesamt','RoomsCatalog'=>'ChatGPT + RoomsCatalog',
            'IntentParser'=>'Intent-Parser'
        ] as $key=>$label) {
            $snap=\iah_ki_section_snapshot($store,$key,$now);
            $today=$snap['today'];$m=$snap['month'];$t=$snap['total'];
            $values=[
                $label,
                $int((int)$today['questions']),
                $int((int)$today['apiCalls']),
                $int((int)$m['inputTokens']),
                $int((int)$m['cachedInputTokens']),
                $int((int)$m['outputTokens']),
                $eur((float)$today['costUsd']),
                $eur((float)$m['costUsd']),
                $eur($this->GetKICostForecast((float)$m['costUsd'],$now)),
                $eur((float)$t['costUsd'])
            ];
            $html.='<tr>';
            foreach($values as $value)
                $html.='<td style="padding:7px;border-bottom:1px solid #ddd">'
                    .$esc($value).'</td>';
            $html.='</tr>';
        }
        $html.='</tbody></table></div>';
        if(!empty($store['sections']['HomeAgent'])) {
            $html.='<p style="color:#666">Die Gesamtsumme enthaelt auch '
                .'bereits gespeicherte historische Aufrufe aus einer '
                .'frueheren KI-Anbindung. Diese Altdaten werden nicht '
                .'geloescht oder einer neuen Anbindung zugerechnet.</p>';
        }
        if((int)$total['callsWithoutPrice']>0
            || (int)$total['callsWithoutUsage']>0) {
            $html.='<p style="color:#975600"><b>Kosten unvollstaendig:</b> '
                .$int((int)$total['callsWithoutPrice']).' API-Aufrufe ohne Preis, '
                .$int((int)$total['callsWithoutUsage']).' ohne Token-Verbrauch. '
                .'Diese Aufrufe werden nicht als kostenlos betrachtet.</p>';
        }
        $html.='<h4 style="margin:18px 0 8px">Letzte 25 KI-Fragen</h4>'
            .'<div style="overflow-x:auto"><table style="width:100%;'
            .'border-collapse:collapse;white-space:nowrap">'
            .'<thead><tr style="background:#eee">';
        foreach(['Zeit','Anbindung','Modell','Eingabe','Cache','Ausgabe',
            'API-Aufrufe','Kosten','Status'] as $heading)
            $html.='<th style="padding:7px;text-align:left">'.$esc($heading).'</th>';
        $html.='</tr></thead><tbody>';
        $labels=['RoomsCatalog'=>'RoomsCatalog','IntentParser'=>'Intent-Parser',
            'HomeAgent'=>'Altbestand'];
        foreach(array_slice($all['history'],0,25) as $item) {
            if(!is_array($item))continue;
            $backend=(string)($item['backend']??'');
            $values=[
                $item['finishedAt']??'',
                $labels[$backend]??'Sonstige',
                $item['model']??'',
                $int((int)($item['inputTokens']??0)),
                $int((int)($item['cachedInputTokens']??0)),
                $int((int)($item['outputTokens']??0)),
                $int((int)($item['apiCalls']??0)),
                $eur((float)($item['costUsd']??0)),
                $item['status']??''
            ];
            $html.='<tr>';
            foreach($values as $value)
                $html.='<td style="padding:6px;border-bottom:1px solid #ddd">'
                    .$esc($value).'</td>';
            $html.='</tr>';
        }
        $html.='</tbody></table></div>'
            .'<p style="font-size:11px;color:#666;margin-top:12px">'
            .'Kostenschaetzung aus echten API-Tokens und hinterlegten USD-Modellpreisen. '
            .'Die angezeigten Eurobetraege werden anhand des ausgewiesenen '
            .'EZB-/manuellen Kurses berechnet. Keine offizielle Rechnung '
            .'und ohne Umsatzsteuer, Zahlungsgebuehren oder externe API-Projekte. '
            .'Historische USD-Werte bleiben zur Nachvollziehbarkeit unveraendert.'
            .'</p></div>';
        return $html;
    }

}
