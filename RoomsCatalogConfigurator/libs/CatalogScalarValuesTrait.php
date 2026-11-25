<?php
declare(strict_types=1);

/**
 * Fallback editor for scalar RoomsCatalog values not yet represented by
 * specialized forms. Scans only after explicit user action. Complex arrays
 * remain owned by dedicated editors; secret values are never shown here.
 */
trait RCCCatalogScalarValuesTrait
{
    private function rccScalarValueRows(array $catalog,string $filter=''): array
    {
        $rows=[];
        $filter=mb_strtolower(trim($filter),'UTF-8');
        $walk=static function (array $node,array $path=[]) use (&$walk,&$rows,$filter): void {
            foreach ($node as $key=>$value) {
                $name=(string)$key;
                if (preg_match('/(?:password|passwort|secret|token|api.?key|credential|authorization|private.?key)/i',$name))
                    continue;
                $next=[...$path,$key];
                if (is_array($value)) {
                    $walk($value,$next);
                    continue;
                }
                if (!is_scalar($value) || count($next)>24)continue;
                $label=implode(' / ',array_map('strval',$next));
                if ($filter!=='' && !str_contains(mb_strtolower($label,'UTF-8'),$filter))
                    continue;
                // Variable IDs have a specialized type-aware editor.
                $isVariable=false;
                $id=self::rccIsId($value);
                if ($id>0 && ($id>=10000 || IPS_VariableExists($id))
                    && self::rccVariableKey($name,$path))$isVariable=true;
                if ($isVariable)continue;
                $type=gettype($value);
                $shown=is_bool($value)?($value?'true':'false'):(string)$value;
                if (strlen($shown)>250)continue;
                $rows[]=['path'=>$label,
                    'pathKey'=>base64_encode(json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
                    'type'=>$type,'value'=>$shown];
            }
        };
        $walk($catalog);
        return $rows;
    }

    public function ScanCatalogScalarValues(string $filter=''): void
    {
        try {
            [$id,$source,$catalog]=$this->systemTreeEditContext();
            $all=$this->rccScalarValueRows($catalog,$filter);
            $this->WriteAttributeString('CatalogScalarHash',hash('sha256',$source));
            $rows=array_slice($all,0,150);
            $this->WriteAttributeString('CatalogScalarRows',json_encode($rows,
                JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $notice=count($all).' vorhandene Felder gefunden; '
                .count($rows).' angezeigt. '
                .'Weitere Werte durch Suche nach Konfigurationspfad eingrenzen. '
                .'Variable-IDs bitte über die zentrale Variablenverwaltung ändern.';
            $this->WriteAttributeString('CatalogScalarNotice',$notice);
            $this->UpdateFormField('CatalogScalarRows','values',json_encode($rows,
                JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $this->UpdateFormField('CatalogScalarNotice','caption',$notice);
        } catch (Throwable $e) {
            $this->WriteAttributeString('CatalogScalarNotice',$e->getMessage());
            $this->UpdateFormField('CatalogScalarNotice','caption',$e->getMessage());
        }
    }

    public function SaveCatalogScalarValue(string $pathKey,string $input): void
    {
        try {
            [$script,$original,$catalog]=$this->systemTreeEditContext();
            if (!hash_equals($this->ReadAttributeString('CatalogScalarHash'),
                    hash('sha256',$original)))
                throw new RuntimeException('EDIT inzwischen verändert: Felder neu suchen.');
            $path=json_decode(base64_decode($pathKey,true)?:'',true);
            if (!is_array($path)||$path===[] || count($path)>24)
                throw new RuntimeException('Ungültiger Konfigurationspfad.');
            $node=&$catalog;
            foreach ($path as $key) {
                if (!is_array($node) || !array_key_exists($key,$node))
                    throw new RuntimeException('Feld existiert nicht mehr.');
                $node=&$node[$key];
            }
            if (!is_scalar($node))
                throw new RuntimeException('Hier können nur bestehende einzelne Werte bearbeitet werden.');
            $type=gettype($node);
            $value=match($type) {
                'boolean'=>match(strtolower(trim($input))) {
                    'true','1','ja','ein'=>true,
                    'false','0','nein','aus'=>false,
                    default=>throw new RuntimeException('Boolean nur true oder false.')
                },
                'integer'=>preg_match('/^-?[0-9]+$/D',trim($input))
                    ?(int)$input:throw new RuntimeException('Integer-Zahl erwartet.'),
                'double'=>is_numeric(trim($input))?(float)$input
                    :throw new RuntimeException('Float-Wert erwartet, Dezimalpunkt verwenden.'),
                'string'=>$input,
                default=>throw new RuntimeException('Datentyp nicht bearbeitbar.')
            };
            if ($node===$value) {
                $this->WriteAttributeString('CatalogScalarNotice','Wert unverändert.');
                return;
            }
            $oldValue=$node;
            $node=$value;
            $this->saveSystemTreeEdit($script,$original,$catalog);
            $this->WriteAttributeString('CatalogScalarHash','');
            $this->WriteAttributeString('CatalogScalarRows','[]');
            $notice='Feld in EDIT gespeichert. Datentyp '.$type.' erhalten. '
                .'PROD unverändert. Vor weiteren Änderungen Liste neu laden.';
            $this->WriteAttributeString('CatalogScalarNotice',$notice);
            $this->rccBindingTrace('SCALAR_EDIT_SAVED',[
                'path'=>implode('/',array_map('strval',$path)),
                'oldHash'=>substr(hash('sha256',serialize($oldValue)),0,12),
                'newHash'=>substr(hash('sha256',serialize($value)),0,12)
            ]);
        } catch (Throwable $e) {
            $this->WriteAttributeString('CatalogScalarNotice',$e->getMessage());
            $this->rccBindingTrace('SCALAR_EDIT_FAILED',['error'=>$e->getMessage()]);
        }
        $this->UpdateFormField('CatalogScalarNotice','caption',
            $this->ReadAttributeString('CatalogScalarNotice'));
    }

    private function rccScalarPanel(): array
    {
        $rows=json_decode($this->ReadAttributeString('CatalogScalarRows'),true);
        return ['type'=>'ExpansionPanel',
            'caption'=>'Weitere Katalogwerte ohne PHP-Skript bearbeiten',
            'expanded'=>false,
            'items'=>[
                ['type'=>'Label','caption'=>'Fallback für vorhandene einzelne Werte '
                    .'aus allen RoomsCatalog-Datenbereichen. Suche wird nur auf '
                    .'Knopfdruck ausgeführt. Geheimnisse und komplexe Datenstrukturen '
                    .'bleiben aus Sicherheitsgründen ausgeschlossen. '
                    .'Änderungen werden mit Backup ausschließlich nach EDIT geschrieben.'],
                ['type'=>'ValidationTextBox','name'=>'CatalogScalarFilter',
                    'caption'=>'Konfigurationspfad suchen (optional)'],
                ['type'=>'Button','caption'=>'WERTE IM EDIT-KATALOG SUCHEN',
                    'onClick'=>'RCC_ScanCatalogScalarValues($id,(string)$CatalogScalarFilter);'],
                ['type'=>'Label','name'=>'CatalogScalarNotice',
                    'caption'=>$this->ReadAttributeString('CatalogScalarNotice')],
                ['type'=>'List','name'=>'CatalogScalarRows','caption'=>'Bestehende Werte bearbeiten',
                    'rowCount'=>10,'loadValuesFromConfiguration'=>false,
                    'add'=>false,'delete'=>false,
                    'values'=>is_array($rows)?$rows:[],
                    'columns'=>[
                        ['name'=>'path','caption'=>'Konfigurationspfad','width'=>'auto','save'=>false],
                        ['name'=>'type','caption'=>'Datentyp','width'=>'80px','save'=>false],
                        ['name'=>'value','caption'=>'Wert','width'=>'190px',
                            'edit'=>['type'=>'ValidationTextBox']],
                        ['name'=>'pathKey','visible'=>false,'save'=>true]
                    ],
                    'onEdit'=>'RCC_SaveCatalogScalarValue($id,'
                        .'(string)($CatalogScalarRows["pathKey"]??""),'
                        .'(string)($CatalogScalarRows["value"]??""));']
            ]];
    }
}
