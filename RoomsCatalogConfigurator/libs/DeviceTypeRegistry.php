<?php
declare(strict_types=1);

/**
 * V3.4.4 – Einzige Quelle fuer Geraetetypen, sichtbare Eigenschaften,
 * Editorrollen, IPS-Datentypen und minimale lesbare Rueckmeldungen.
 * Keine Konfiguration wird migriert oder automatisch freigeschaltet.
 *
 * Ein neuer Typ wird HIER definiert: Der Konfigurator und die
 * Alexa-KI beziehen die Rollen aus derselben Registry.
 */
final class RCCDeviceTypeRegistry
{
    /** @return array<string,array{label:string,roles:list<string>,required:array,location:array}> */
    public static function definitions(): array
    {
        static $definitions = [
            'light'        => ['label'=>'Licht','roles'=>['power','status','control'],'required'=>[['power','status']],'location'=>['licht','switches']],
            'dimmer'       => ['label'=>'Dimmer','roles'=>['power','brightness','status','control'],'required'=>[['brightness']],'location'=>['licht','dimmers']],
            'blind'        => ['label'=>'Jalousie','roles'=>['position','targetPosition','tilt','status','moving','action','control'],'required'=>[['position','status']],'location'=>['jalousie','jalousie']],
            'heating'      => ['label'=>'Heizung','roles'=>['temperature','targetTemperature','control'],'required'=>[['temperature']],'location'=>['heizung','heizung']],
            'window'       => ['label'=>'Fensterkontakt','roles'=>['open','status'],'required'=>[['open','status']],'location'=>['sensoren','fenster']],
            'switch'       => ['label'=>'Steckdose/Schalter','roles'=>['power','powerWatts','energy','status','control'],'required'=>[['power','status']],'location'=>['geraete','switches']],
            'energy'       => ['label'=>'Energiezaehler','roles'=>['powerWatts','energy','status'],'required'=>[['energy','powerWatts']],'location'=>['energie','zaehler']],
            'motion'       => ['label'=>'Bewegungsmelder','roles'=>['motion','status'],'required'=>[['motion']],'location'=>['sensoren','bewegung']],
            'illuminance'  => ['label'=>'Helligkeitssensor (Lux)','roles'=>['illuminance'],'required'=>[['illuminance']],'location'=>['licht','status']],
            'fan'          => ['label'=>'Lueftung','roles'=>['power','control'],'required'=>[['power','control']],'location'=>['lueftung','fans']],
            'generic'      => ['label'=>'Sonstiges (ungeklaert)','roles'=>['power','status','action','control'],'required'=>[],'location'=>['devices','tabs']],

            // Zusätzliche, zunaechst nur lesbare Typen (kein KI-Schalten).
            'door'         => ['label'=>'Tuerkontakt','roles'=>['open','status'],'required'=>[['open','status']],'location'=>['sensoren','tueren']],
            'temperature'  => ['label'=>'Temperatursensor','roles'=>['temperature'],'required'=>[['temperature']],'location'=>['sensoren','temperatur']],
            'humidity'     => ['label'=>'Luftfeuchtigkeitssensor','roles'=>['humidity'],'required'=>[['humidity']],'location'=>['sensoren','luftfeuchtigkeit']],
            'smoke'        => ['label'=>'Rauchmelder','roles'=>['alarm','batteryLevel','status'],'required'=>[['alarm','status']],'location'=>['sicherheit','rauchmelder']],
            'water_leak'   => ['label'=>'Wassermelder','roles'=>['waterLeak','alarm','status'],'required'=>[['waterLeak','alarm','status']],'location'=>['sicherheit','wasser']],
            'weather'      => ['label'=>'Wetterstation','roles'=>['temperature','humidity','windSpeed','rain','illuminance'],'required'=>[['temperature','windSpeed','rain','illuminance']],'location'=>['information','wetter']],
            'camera'       => ['label'=>'Kamera','roles'=>['online','motion','status'],'required'=>[['online','status','motion']],'location'=>['sicherheit','kameras']],
            'irrigation'   => ['label'=>'Bewaesserung','roles'=>['power','status','control'],'required'=>[['power','status']],'location'=>['bewaesserung','ventile']],
            'lock'         => ['label'=>'Tuerschloss','roles'=>['locked','batteryLevel','status'],'required'=>[['locked','status']],'location'=>['sicherheit','schloesser']],
            'alarm'        => ['label'=>'Alarmanlage','roles'=>['armed','alarm','status'],'required'=>[['armed','alarm','status']],'location'=>['sicherheit','alarmanlage']],
            'solar'        => ['label'=>'Photovoltaik','roles'=>['powerWatts','energy','feedIn','selfConsumption'],'required'=>[['powerWatts','energy']],'location'=>['energie','solar']],
            'battery'      => ['label'=>'Batteriespeicher','roles'=>['batteryLevel','chargePower','dischargePower'],'required'=>[['batteryLevel','chargePower','dischargePower']],'location'=>['energie','speicher']],
            'water_meter'  => ['label'=>'Wasserzaehler','roles'=>['volume','flowRate'],'required'=>[['volume','flowRate']],'location'=>['energie','wasser']],
            'ev_charger'   => ['label'=>'Wallbox','roles'=>['charging','powerWatts','energy','batteryLevel'],'required'=>[['charging','powerWatts','energy']],'location'=>['energie','wallbox']],
            'media'        => ['label'=>'Mediengeraet','roles'=>['power','volume','status','control'],'required'=>[['power','status','volume']],'location'=>['geraete','media']],
            'network'      => ['label'=>'Netzwerkgeraet','roles'=>['online','status'],'required'=>[['online','status']],'location'=>['information','netzwerk']]
        ];
        return $definitions;
    }

    public static function roles(): array
    {
        return array_map(static fn(array $item):array=>$item['roles'], self::definitions());
    }
    public static function captions(): array
    {
        return array_map(static fn(array $item):string=>$item['label'], self::definitions());
    }
    public static function required(string $type): array
    {
        return self::definitions()[$type]['required'] ?? [];
    }
    public static function location(string $type): array
    {
        return self::definitions()[$type]['location'] ?? ['geraete','other'];
    }
    public static function fields(): array
    {
        return [
            'power'=>'aiPowerId','brightness'=>'aiBrightnessId',
            'position'=>'aiPositionId','targetPosition'=>'aiTargetPositionId',
            'tilt'=>'aiTiltId','moving'=>'aiMovingId','control'=>'aiControlId',
            'temperature'=>'aiTemperatureId','targetTemperature'=>'aiTargetTemperatureId',
            'open'=>'aiOpenId','powerWatts'=>'aiWattsId','energy'=>'aiEnergyId',
            'motion'=>'aiMotionId','illuminance'=>'aiIlluminanceId',
            'status'=>'aiStatusId','action'=>'aiActionId',
            'humidity'=>'aiHumidityId','alarm'=>'aiAlarmId','batteryLevel'=>'aiBatteryLevelId',
            'waterLeak'=>'aiWaterLeakId','windSpeed'=>'aiWindSpeedId','rain'=>'aiRainId',
            'online'=>'aiOnlineId','locked'=>'aiLockedId','armed'=>'aiArmedId',
            'feedIn'=>'aiFeedInId','selfConsumption'=>'aiSelfConsumptionId',
            'chargePower'=>'aiChargePowerId','dischargePower'=>'aiDischargePowerId',
            'volume'=>'aiVolumeId','flowRate'=>'aiFlowRateId',
            'charging'=>'aiChargingId'
        ];
    }
    public static function states(): array
    {
        return ['on'=>'aiOnValue','off'=>'aiOffValue',
                'open'=>'aiOpenValue','closed'=>'aiClosedValue'];
    }
    public static function propertyCaptions(): array
    {
        return [
            'power'=>'Ein/Aus (Rueckmeldung)','brightness'=>'Helligkeit',
            'position'=>'Istposition','targetPosition'=>'Sollposition',
            'tilt'=>'Lamellenwinkel','moving'=>'Fahrstatus',
            'control'=>'Steuerungsvariable','temperature'=>'Isttemperatur',
            'targetTemperature'=>'Solltemperatur','open'=>'Fenster-/Tuerstatus',
            'powerWatts'=>'Leistung (W)','energy'=>'Verbrauch / Energie (kWh)',
            'motion'=>'Bewegung','illuminance'=>'Beleuchtungsstaerke (Lux)',
            'status'=>'Geraetestatus','action'=>'Aktion (z.B. Auf/Ab/Stop)',
            'humidity'=>'Luftfeuchtigkeit (%)','alarm'=>'Alarm aktiv',
            'batteryLevel'=>'Batteriestand (%)','waterLeak'=>'Wasser erkannt',
            'windSpeed'=>'Windgeschwindigkeit','rain'=>'Regen erkannt',
            'online'=>'Online/Offline','locked'=>'Verriegelt',
            'armed'=>'Scharfgeschaltet','feedIn'=>'Einspeisung (W)',
            'selfConsumption'=>'Eigenverbrauch (W)',
            'chargePower'=>'Ladeleistung (W)','dischargePower'=>'Entladeleistung (W)',
            'volume'=>'Wasserverbrauch (m³)','flowRate'=>'Durchfluss',
            'charging'=>'Ladevorgang aktiv'
        ];
    }
    /** Eindeutige Namen fuer unverbindliche Inventur-Typvorschlaege.
     * Keine KI-Freigabe, kein PROD-Import und kein automatisches Schalten.
     * Die Zeichenfolgen sind bereits wie normalizeSuggestionText normalisiert.
     */
    public static function discoveryPatterns(): array
    {
        return [
            'blind'=>['rollladen','rolladen','jalousie','raffstore','shutter','blind','markise'],
            'heating'=>['heizung','thermostat','radiator','heating','climate','heizkreis'],
            'dimmer'=>['dimmer','dimming','dimmaktor'],
            'window'=>['fensterkontakt','windowcontact','reedkontakt'],
            'door'=>['tuerkontakt','doorcontact'],
            'temperature'=>['temperatursensor','temperaturfuehler','temperaturgeber','temperaturesensor'],
            'humidity'=>['luftfeuchte','feuchtigkeitssensor','humiditysensor'],
            'smoke'=>['rauchmelder','smokedetector','rauchalarm'],
            'water_leak'=>['wassermelder','wasserleck','leakdetector','waterleak'],
            'weather'=>['wetterstation','weatherstation','wetterdaten'],
            'camera'=>['kamera','camera','videoueberwachung'],
            'irrigation'=>['bewaesserung','bewasserung','sprinkler','bewasserungsventil'],
            'lock'=>['tuerschloss','doorlock','smartlock'],
            'alarm'=>['alarmanlage','alarmanlage','alarmsystem'],
            'solar'=>['photovoltaik','wechselrichter','solaranlage','inverter'],
            'battery'=>['batteriespeicher','energiespeicher','batteriesystem'],
            'water_meter'=>['wasserzaehler','watermeter','wasseruhr'],
            'ev_charger'=>['wallbox','ladestationev','ladepunkt','evcharger'],
            'media'=>['fernseher','mediengeraet','receiver','smarttv'],
            'network'=>['fritzbox','netzwerkgeraet','accesspoint','router','netzwerkswitch'],
            'motion'=>['bewegungsmelder','motion','pir'],
            'fan'=>['lueftung','ventilator','fan','ventilation','airhandling'],
            'energy'=>['energiezaehler','stromzaehler','powermeter','energymeter','smartmeter'],
            'switch'=>['steckdose','switchingactor','schaltaktor','relay','socket','plug'],
            'light'=>['licht','beleuchtung','lampe','leuchte','lighting','light']
        ];
    }
    public static function suggestions(): array
    {
        return [
            'power'=>['status','state','schaltstatus','schaltzustand','ein','onoff','powerstate'],
            'brightness'=>['helligkeit','brightness','dimmwert','dimmerlevel','dimming'],
            'position'=>['position','istposition','behangposition','rollladenposition','actualposition'],
            'targetPosition'=>['sollposition','targetposition','positionvorgabe','positiontarget'],
            'tilt'=>['lamelle','lamellen','positionlamelle','lamellenwinkel','lamellenposition','tilt','slatposition'],
            'moving'=>['fahrstatus','fahrrichtung','moving','bewegungstatus','ismoving'],
            'control'=>['schalten','schaltbefehl','steuern','steuerung','switch','command','control'],
            'temperature'=>['isttemperatur','temperaturist','raumtemperatur','actualtemperature','temperature'],
            'targetTemperature'=>['solltemperatur','temperatursoll','zieltemperatur','targettemperature','setpoint'],
            'open'=>['fensterkontakt','fensterstatus','tuerkontakt','doorcontact','kontakt','contact','openstate'],
            'powerWatts'=>['leistung','wirkleistung','powerw','powerwatts','currentpower'],
            'energy'=>['energie','energieverbrauch','zaehlerstand','energy','consumption','kwh'],
            'motion'=>['bewegung','bewegungsmelder','motion'],
            'illuminance'=>['lux','helligkeit','beleuchtungsstaerke','illuminance'],
            'status'=>['status','devicestatus','geraetestatus','rollladenstatus','shutterstatus','blindenstatus'],
            'action'=>['aktion','fahrbefehl','fahraktion','action','direction','fahrtrichtung','befehl'],
            'humidity'=>['luftfeuchtigkeit','humidity','relativehumidity'],
            'alarm'=>['alarm','alarmstatus','alarmstate','rauchalarm'],
            'batteryLevel'=>['batteriestand','batterielevel','batterie','batterylevel','batterypercent','soc'],
            'waterLeak'=>['wasseralarm','waterleak','wassererkannt','leakdetected','leak'],
            'windSpeed'=>['windgeschwindigkeit','windspeed','windvelocity'],
            'rain'=>['regen','rain','raining','regenstatus'],
            'online'=>['online','is online','onlinestatus','erreichbarkeit','reachable','verbindung'],
            'locked'=>['verriegelt','locked','lockstate','schlossstatus'],
            'armed'=>['scharfgeschaltet','armed','alarmarmed','scharfstatus'],
            'feedIn'=>['einspeisung','feedin','gridfeedin'],
            'selfConsumption'=>['eigenverbrauch','selfconsumption'],
            'chargePower'=>['ladeleistung','chargepower','chargingpower'],
            'dischargePower'=>['entladeleistung','dischargepower'],
            'volume'=>['wasserzaehlerstand','wassermenge','volume','verbrauchm3'],
            'flowRate'=>['durchfluss','flowrate','durchflussmenge'],
            'charging'=>['ladenaktiv','charging','ladevorgang'],
        ];
    }
    /** IP-Symcon-Datentypen: 0=Bool, 1=Integer, 2=Float, 3=String. */
    public static function dataTypes(): array
    {
        return [
            'power'=>[0,1],'brightness'=>[1,2],'position'=>[1,2],
            'targetPosition'=>[1,2],'tilt'=>[1,2],'moving'=>[0,1],
            'control'=>[0,1,2],'temperature'=>[1,2],
            'targetTemperature'=>[1,2],'open'=>[0,1],
            'powerWatts'=>[1,2],'energy'=>[1,2],'motion'=>[0,1],
            'status'=>[0,1,2,3],'action'=>[0,1,2],'illuminance'=>[2],
            'humidity'=>[1,2],'alarm'=>[0,1],'batteryLevel'=>[1,2],
            'waterLeak'=>[0,1],'windSpeed'=>[1,2],'rain'=>[0,1],
            'online'=>[0,1],'locked'=>[0,1],'armed'=>[0,1],
            'feedIn'=>[1,2],'selfConsumption'=>[1,2],
            'chargePower'=>[1,2],'dischargePower'=>[1,2],
            'volume'=>[1,2],'flowRate'=>[1,2],'charging'=>[0,1]
        ];
    }
}
