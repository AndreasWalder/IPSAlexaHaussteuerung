<?php
declare(strict_types=1);

/**
 * V3.7.1 one-time inventory of the old, hard-coded voice responses.
 * These are IMPORT DATA ONLY. No old handler/renderer is executed here.
 *
 * The original Alexa Action and renderer scripts stay intact as backups.
 * Imported actions are disabled and must be explicitly reviewed in EDIT.
 */
final class IAHVoiceLegacyMigration
{
    public static function drafts(array $catalog, int $outsideTemperatureId=0): array
    {
        $global=(array)($catalog['global']??[]);
        $domains=(array)($global['domains']??[]);
        $blind=(array)($domains['jalousie']??$global['jalousie']??[]);
        $globalBlindId=(int)($blind['open_close_var']??0);

        $rules=IAHVoiceRuleEngine::legacyDrafts();
        foreach($rules as &$rule){
            $rule['origin']='legacy';
            $rule['intentName']='';
            $rule['scriptParams']=[];
            $rule['imageFile']='';
            $rule['imageUrl']='';
            $rule['cardTitle']='';
            $rule['cardText']='';
            $rule['migrationNote']='Alter statischer Alexa-Antwortpfad. Bitte Wortlaut pruefen.';
        }
        unset($rule);

        $rules['wer_bist_du']['aliases']=[
            'Wer bist du','Was bist du','Wer seid ihr','Wer genau bist du',
            'Wer bist du eigentlich','Wie heißt du','Wie heisst du',
            'Sage mir deinen Namen','Mit wem spreche ich','In welchem Raum bin ich',
            'werbistdu'
        ];
        $rules['wer_bist_du']['answer']='Ich bin deine Alexa-Haussteuerung.';
        $rules['wer_bist_du']['migrationNote']=
            'Frueher wurde der Alexa-Geraetename in die Antwort eingesetzt; '
            .'dieser dynamische Teil muss bei Bedarf separat konfiguriert werden.';

        $rules['wer_ist_andreas']['aliases']=[
            'Wer ist dein Erfinder','Wer ist dein Programmierer',
            'Wer hat dich erfunden','Wer hat dich programmiert',
            'Wer hat dich erschaffen','Wer ist Andreas',
            'Kennst du Andreas','Wer Andreas','Wer dein Erfinder'
        ];
        $rules['wer_ist_andreas']['answer']=
            'Andreas, der geniale Programmierer, hat mich erschaffen um im Smart Home zu unterstützen.';

        $rules['wer_ist_andrea']['aliases']=[
            'Wer ist Andrea','Kennst du Andrea','Wer Andrea','Wer seine Frau'
        ];
        $rules['wer_ist_andrea']['answer']=
            'Andrea, die liebevolle Frau hat ihn dabei unterstützt und musste seine Launen aushalten!';

        $blinds=[
            'jalousien_oeffnen'=>[
                'value'=>'1','image'=>'JalousieTag.png','title'=>'Die Jalousien fahren auf',
                'answer'=>'Die Jalousien fahren auf.',
                'aliases'=>['Jalousien öffnen','Jalousie auf','Jalousie öffnen',
                    'Jalousien auf','Öffnen Jalousie','Öffnen Jalousien']
            ],
            'jalousien_schliessen'=>[
                'value'=>'2','image'=>'JalousieAbend.png','title'=>'Die Jalousien fahren zu',
                'answer'=>'Die Jalousien fahren zu.',
                'aliases'=>['Jalousien schließen','Jalousie ab','Jalousie zu',
                    'Jalousien zu','Jalousie schließen','Schließen Jalousie',
                    'Schließen Jalousien']
            ]
        ];
        foreach($blinds as $key=>$data){
            $r=&$rules[$key];
            $r['aliases']=$data['aliases'];
            $r['answer']=$data['answer'];
            $r['imageFile']=$data['image'];
            $r['cardTitle']=$data['title'];
            $r['cardText']="- Küche\n- Wohnzimmer\n- Elternzimmer\n- Bad\n"
                ."- Stiegenhaus\n\n"
                .($key==='jalousien_oeffnen'?'Schönen Tag!':'Schönen Abend!');
            // A missing legacy global target must NOT turn the rule into
            // 'script': selecting a variable in that editor would be lost
            // by validateRule, and 'Aktiv' would then fail without scriptId.
            $r['kind']='variable';
            $r['variableId']=$globalBlindId>0?$globalBlindId:0;
            $r['variableValue']=$data['value'];
            $r['scriptId']=0;
            if($globalBlindId>0){
                $r['migrationNote']='Globales Jalousie-Ziel aus RoomsCatalog '
                    .'(global.domains.jalousie.open_close_var) uebernommen. '
                    .'Wert '.$data['value'].'. Variable und Ergebnis vor Aktivierung pruefen.';
            }else{
                $r['migrationNote']='Globale open_close_var nicht gefunden. '
                    .'Bitte Schaltvariable im Editor auswaehlen und bestaetigen. '
                    .'Der alte Renderer wird nicht aufgerufen.';
            }
            unset($r);
        }

        $rules['aussentemperatur']=[
            'title'=>'Außentemperatur',
            'aliases'=>['Außentemperatur','Aussentemperatur','Temperatur draußen',
                'Temperatur draussen','Temperatur außen','Temperatur aussen'],
            'kind'=>'read','answer'=>'Die Außentemperatur beträgt {value}.',
            'readVariableId'=>$outsideTemperatureId,
            'imageUrl'=>'https://media.istockphoto.com/id/1323823418/de/foto/niedrigwinkelansicht-thermometer-am-blauen-himmel-mit-sonnenschein.jpg?s=612x612&w=0&k=20&c=iFJaAAxJ_chcBz5Bnjy20HSlULU7AWIW16d_bwlB0Ss=',
            'imageFile'=>'','cardTitle'=>'Außentemperatur',
            'cardText'=>'Die Außentemperatur beträgt {value}.',
            'scriptId'=>0,'scriptParams'=>[],'steps'=>[],
            'enabled'=>false,'confirmed'=>false,'origin'=>'legacy',
            'migrationNote'=>$outsideTemperatureId>0
                ?'Messwert-ID aus Modulkonfiguration uebernommen; Profil und Anzeige pruefen.'
                :'VarAussenTemp ist nicht belegt. Variable vor Aktivierung auswaehlen.'
        ];

        $rules['maehroboter']=[
            'title'=>'Mähroboter',
            'aliases'=>['Mähroboter','Vox','Wrox'],
            'kind'=>'script','scriptId'=>0,'scriptParams'=>[],
            'answer'=>'Der Mähroboterbefehl wurde gestartet.',
            'imageUrl'=>'','imageFile'=>'','cardTitle'=>'','cardText'=>'',
            'steps'=>[],'enabled'=>false,'confirmed'=>false,'origin'=>'legacy',
            'migrationNote'=>'Der alte Code gab nur einen Text aus und startete keinen Roboter. '
                .'Fuer eine echte Aktion muss ein Skript ausgewaehlt werden.'
        ];
        $rules['roborock_reinigung']=[
            'title'=>'Roborock Reinigung',
            'aliases'=>['Roborock','Staubsaugen','Saugen'],
            'kind'=>'script','scriptId'=>0,'scriptParams'=>[],
            'answer'=>'Die Reinigung wurde gestartet.',
            'imageUrl'=>'','imageFile'=>'','cardTitle'=>'','cardText'=>'',
            'steps'=>[],'enabled'=>false,'confirmed'=>false,'origin'=>'legacy',
            'migrationNote'=>'Frueher nur eine Textantwort, ohne echte Robotersteuerung. '
                .'Raumbezogene Freitext-Befehle benoetigen ein eigenes Intent-/Skriptkonzept.'
        ];
        return $rules;
    }

    /** Used exclusively to retire previously baked-in shortcuts. */
    public static function isRetiredPhrase(string $spoken,array $catalog=[],int $outsideTemperatureId=0): bool
    {
        $value=IAHVoiceRuleEngine::normalize($spoken);
        if($value==='')return false;
        // Legacy renderer also accepted room names, percentages and extra
        // words around blinds/robot commands. Those must not slip through
        // to the old renderer when no new exact rule is configured.
        // A single 'Jalousie' remains navigation, not a control command.
        if(preg_match('/\\b(?:jalousie|jalousien|rollo|rollos|rollladen|raffstore|beschattung)\\b/u',$value)
            && preg_match('/\\b(?:auf|zu|ab|oeffnen|schliessen|fahren|fahre|stelle|stellen|position|prozent|[0-9]+%?)\\b/u',$value))
            return true;
        if(preg_match('/\\b(?:maehroboter|roborock|staubsaugen|saugen|vox|wrox)\\b/u',$value))
            return true;
        if(preg_match('/\\b(?:aussentemperatur)\\b/u',$value)
            || (preg_match('/\\b(?:draussen|aussen)\\b/u',$value)
                && preg_match('/\\b(?:warm|temperatur|kalt|grad)\\b/u',$value)))
            return true;
        foreach(self::drafts($catalog,$outsideTemperatureId) as $draft){
            foreach(IAHVoiceRuleEngine::phrases($draft['aliases']??[]) as $alias)
                if(IAHVoiceRuleEngine::normalize($alias)===$value)return true;
        }
        return false;
    }
}
