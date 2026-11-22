<?php
declare(strict_types=1);
// KIHouseController embeds this class in its generated IP-Symcon script.
// Avoid re-declaring it when the module trait is loaded in the same PHP runtime.
if (!class_exists('IAHTauernwetterForecast', false)) {
    require_once __DIR__.'/../resources/helpers/TauernwetterForecast.php';
}

trait IAHWeatherForecastTrait
{
    public function GetWeatherForecastData(): string
    {
        if (!$this->ReadPropertyBoolean('TauernwetterForecastEnabled')) return '{"enabled":false}';
        $cache=json_decode($this->ReadAttributeString('TauernwetterForecastCache'),true);
        if (is_array($cache)&&IAHTauernwetterForecast::valid($cache,time())&&time()-$cache['fetchedAt']<=30*60)
            return json_encode(['enabled'=>true,'data'=>$cache],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (is_array($cache)&&isset($cache['retryAfter'])) return '{"enabled":true,"status":"unavailable"}';
        return '{"enabled":true,"status":"warming_up"}';
    }

    /** Slow source requests run on a background timer, never inside an Alexa response. */
    public function RefreshWeatherForecast(): void
    {
        $this->SetTimerInterval('WeatherForecastRefresh',0);
        if (!$this->ReadPropertyBoolean('TauernwetterForecastEnabled')) return;
        $lock='IAH.Tauernwetter.'.$this->InstanceID; $locked=false;
        $interval=15*60*1000;
        try {
            if (function_exists('IPS_SemaphoreEnter')) {
                $locked=IPS_SemaphoreEnter($lock,100);
                if (!$locked) return;
            }
            $cache=json_decode($this->ReadAttributeString('TauernwetterForecastCache'),true);
            $cache=is_array($cache)?$cache:[]; $now=time();
            try { $next=IAHTauernwetterForecast::refresh($cache,fn($headers):array=>$this->weatherForecastHttp($headers),$now); }
            catch (Throwable $error) {
                $cache['retryAfter']=$now+60;
                $interval=60*1000;
                $this->WriteAttributeString('TauernwetterForecastCache',json_encode($cache,JSON_THROW_ON_ERROR));
                return;
            }
            if ($next!==$cache) $this->WriteAttributeString('TauernwetterForecastCache',json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        } catch (Throwable $ignored) { $interval=60*1000; }
        finally {
            if ($locked) IPS_SemaphoreLeave($lock);
            $this->SetTimerInterval('WeatherForecastRefresh',$this->ReadPropertyBoolean('TauernwetterForecastEnabled')?$interval:0);
        }
    }

    private function weatherForecastHttp(array $headers): array { return IAHTauernwetterForecast::http($headers); }

    public function TestWeatherForecast(): void
    {
        $this->RefreshWeatherForecast();
        $r=IAHTauernwetterForecast::query('Wie wird das Wetter?',fn():array=>json_decode($this->GetWeatherForecastData(),true));
        echo $r['answer'];
    }

    private function buildWeatherForecastPanel(): array
    {
        return ['type'=>'ExpansionPanel','caption'=>'WETTERVORHERSAGE – Sillian / Tauernwetter','expanded'=>false,'items'=>[
            ['type'=>'CheckBox','name'=>'TauernwetterForecastEnabled','caption'=>'Öffentliche Tauernwetter-Vorhersage für Sillian aktivieren'],
            ['type'=>'Label','caption'=>'„Wie wird das Wetter?“: morgen und die beiden Folgetage, mit Wochentag, Wetterlage und Temperatur. Auf dem Echo Show stehen zusätzlich Niederschlag, Quelle und Datenstand. Kein API-Schlüssel nötig. Wird alle 15 Minuten im Hintergrund geladen, damit die Sprachausgabe nicht auf die Website warten muss.'],
            ['type'=>'Button','caption'=>'VORHERSAGE TESTEN','onClick'=>'IAH_TestWeatherForecast($id);'],
        ]];
    }
}
