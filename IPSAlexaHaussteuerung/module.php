<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Helpers.php';
require_once __DIR__ . '/src/LogTrait.php';
require_once __DIR__ . '/src/Router.php';
require_once __DIR__ . '/src/Routes/RouteAll.php';
require_once __DIR__ . '/src/Renderers/RenderMain.php';
require_once __DIR__ . '/src/Renderers/RenderHeizung.php';
require_once __DIR__ . '/src/Renderers/RenderJalousie.php';
require_once __DIR__ . '/src/Renderers/RenderLicht.php';
require_once __DIR__ . '/src/Renderers/RenderLueftung.php';
require_once __DIR__ . '/src/Renderers/RenderGeraete.php';
require_once __DIR__ . '/src/Renderers/RenderBewaesserung.php';
require_once __DIR__ . '/src/Renderers/RenderSettings.php';
require_once __DIR__ . '/src/Renderers/RenderExternal.php';

class IPSAlexaHaussteuerung extends IPSModule
{
    use \IPSAlexaHaussteuerung\LogTrait;

    /** @var bool */
    private $isApplyingChanges = false;

    public function Create()
    {
        parent::Create();

        // Core settings
        $this->RegisterPropertyString('BaseUrl', '');
        $this->RegisterPropertyString('Source', '');
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('Passwort', '');
        $this->RegisterPropertyString('StartPage', '#45315');
        $this->RegisterPropertyInteger('WfcId', 45315);
        $this->RegisterPropertyString('LOG_LEVEL', 'debug');
        $this->RegisterPropertyInteger('SystemConfigScriptId', 0);
        $this->RegisterAttributeString('DelayedPageSwitchPayload', '');
        $this->RegisterTimer('DelayedPageSwitch', 0, 'IAH_HandleDelayedPageSwitch($_IPS["TARGET"]);');

        // Pages
        $this->RegisterPropertyString('EnergiePageId', 'item2124');
        $this->RegisterPropertyString('KameraPageId', 'item9907');

        // Diagnostics payload editor
        $this->RegisterPropertyString('DiagPayload', '{"route":"main_launch","aplSupported":true}');
        $this->RegisterPropertyString('DeviceMapJson', '');

        // Optional status variables (allow linking existing values instead of auto-created ones)
        $this->RegisterPropertyInteger('VarInformation', 0);
        $this->RegisterPropertyInteger('VarMeldungen', 0);
        $this->RegisterPropertyInteger('VarAussenTemp', 0);
        $this->RegisterPropertyInteger('VarHeizraumIst', 0);
        $this->RegisterPropertyInteger('VarOgGangIst', 0);
        $this->RegisterPropertyInteger('VarTechnikIst', 0);

        // WebHook (optional): expose /hook/ipshalexa for Skill endpoint

        $this->RegisterAttributeString('DeviceMapJsonMirror', '');
    }

    public function ApplyChanges()
    {
        $this->isApplyingChanges = true;

        parent::ApplyChanges();

        // String-Variable für DeviceMapJson anlegen (falls noch nicht)
        $varId = $this->RegisterVariableString('deviceMapJson', 'DeviceMapJson', '~TextBox', 10);
    
        // Auf Änderungen der Variable hören
        $this->RegisterMessage($varId, VM_UPDATE);
    
        // Property -> Variable synchronisieren
        $cfgJson  = $this->ReadPropertyString('DeviceMapJson');
        $varJson  = GetValueString($varId);
    
        if ($cfgJson !== '' && $cfgJson !== $varJson) {
            SetValueString($varId, $cfgJson);
        }

        $this->EnsureInfrastructure();
        $this->syncDeviceMapVarFromProperty();

        $this->isApplyingChanges = false;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
    
        // ID der DeviceMapJson-Variable
        $varId = @ $this->GetIDForIdent('deviceMapJson');
        if ($varId === 0) {
            return;
        }
    
        // Wenn DeviceMapJson geändert wurde → Property nachziehen
        if ($Message === VM_UPDATE && $SenderID === $varId) {
            $newJson    = GetValueString($varId);
            $currentCfg = $this->ReadPropertyString('DeviceMapJson');
    
            if ($newJson !== $currentCfg) {
                IPS_SetProperty($this->InstanceID, 'DeviceMapJson', $newJson);
                IPS_ApplyChanges($this->InstanceID);
            }
        }
    }


    /**
     * Ensure categories & runtime variables exist
     */
    private function EnsureInfrastructure(): void
    {
        $root = $this->InstanceID;
        $catSettings = $this->ensureCategory($root, 'Einstellungen', 'iahSettings');
        $catHelper = $this->ensureCategory($root, 'Alexa new devices helper', 'iahHelper');

        $this->ensureRoomsCatalogTemplate($catSettings);
        $this->ensureActionScript($root);
        $this->ensureHelperScripts($catHelper);
        $this->ensureRendererScripts($root);

        // Einstellungen toggles (Defaults wie im Original-Flow: aktiv = true)
        $this->ensureVar($catSettings, 'bewaesserung_toggle', 'bewaesserungToggle', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'geraete_toggle', 'geraeteToggle', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'heizung_stellen', 'heizungStellen', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'jalousie_steuern', 'jalousieSteuern', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'licht_dimmers', 'lichtDimmers', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'licht_switches', 'lichtSwitches', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'lueftung_toggle', 'lueftungToggle', VARIABLETYPE_BOOLEAN, '', true);

        // WFC PageSwitch Params → Default aus Instanz-Settings
        $wfc = $this->ReadPropertyInteger('WfcId');
        $page = $this->ReadPropertyString('EnergiePageId');
        $this->ensureVar(
            $catSettings,
            'WFC PageSwitch Params',
            'wfcPageParams',
            VARIABLETYPE_STRING,
            '',
            json_encode(['page' => $page, 'wfc' => $wfc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Helper
        $this->ensureVar($catHelper, 'DeviceMapJson', 'deviceMapJson', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($catHelper, 'PendingDeviceId', 'pendingDeviceId', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($catHelper, 'PendingStage', 'pendingStage', VARIABLETYPE_STRING, '', '');

        // Runtime vars under instance
        $this->ensureVar($root, 'action', 'action', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'device', 'device', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'room', 'room', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'skillActive', 'skillActive', VARIABLETYPE_BOOLEAN, '', false);
        $this->ensureVar($root, 'dumpFile', 'dumpFile', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'lastVariableDevice', 'lastVarDevice', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'lastVariableId', 'lastVarId', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'lastVariableAction', 'lastVarAction', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'lastVariableValue', 'lastVarValue', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'log_recent', 'logRecent', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'domain_flag', 'domainFlag', VARIABLETYPE_STRING, '', '');

        $this->ensureSystemConfigurationScript($catSettings, $catHelper);
        // Hinweis: Die Statusvariablen (Information/Meldungen/Außentemperatur) werden nicht mehr automatisch
        // erstellt. Sie müssen über die Instanzkonfiguration mit bestehenden Variablen verknüpft werden.
    }

    private function ensureCategory(int $parent, string $name, string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if (!$id) {
            $id = @IPS_GetObjectIDByName($name, $parent);
        }
        if (!$id) {
            $id = IPS_CreateCategory();
        }

        IPS_SetParent($id, $parent);
        IPS_SetName($id, $name);
        IPS_SetIdent($id, $ident);

        return $id;
    }

    private function ensureVar(
        int $parent,
        string $name,
        string $ident,
        int $type,
        string $profile,
        $defaultValue = null
    ): int {
        // 1) adopt existing by ident OR by name
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if (!$id) {
            $byName = @IPS_GetObjectIDByName($name, $parent);
            if ($byName) {
                $id = $byName;
                IPS_SetIdent($id, $ident);
            }
        }

        // 2) create if still missing
        if (!$id) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $parent);
            IPS_SetName($id, $name);
            IPS_SetIdent($id, $ident);
            if ($profile !== '') {
                @IPS_SetVariableCustomProfile($id, $profile);
            }
        }

        // 3) initialize value if provided and variable was just created or empty
        if ($defaultValue !== null) {
            switch ($type) {
                case VARIABLETYPE_BOOLEAN:
                    @SetValueBoolean($id, (bool) $defaultValue);
                    break;
                case VARIABLETYPE_INTEGER:
                    @SetValueInteger($id, (int) $defaultValue);
                    break;
                case VARIABLETYPE_FLOAT:
                    @SetValueFloat($id, (float) $defaultValue);
                    break;
                case VARIABLETYPE_STRING:
                    @SetValueString($id, (string) $defaultValue);
                    break;
            }
        }

        return $id;
    }

    private function getObjectIDByIdentOrName(int $parent, string $ident, string $name): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id) {
            return (int) $id;
        }

        $id = @IPS_GetObjectIDByName($name, $parent);
        if ($id) {
            return (int) $id;
        }

        return 0;
    }

    public function GetConfigurationForm()
    {
        $formPath = __DIR__ . '/form.json';
        if (!is_file($formPath)) {
            return json_encode(['elements' => [], 'actions' => []]);
        }

        $content = file_get_contents($formPath);
        $form = json_decode((string) $content, true);
        if (!is_array($form)) {
            $form = ['elements' => [], 'actions' => []];
        }

        if (!isset($form['elements']) || !is_array($form['elements'])) {
            $form['elements'] = [];
        } else {
            $form['elements'] = $this->injectDeviceMapValue($form['elements'], $this->readDeviceMapJsonFromVariable());
        }
        if (!isset($form['actions']) || !is_array($form['actions'])) {
            $form['actions'] = [];
        }

        $form['elements'][] = $this->buildLogPreviewPanel();

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function buildLogPreviewPanel(): array
    {
        $logId = (int) @IPS_GetObjectIDByIdent('logRecent', $this->InstanceID);
        if ($logId <= 0) {
            $logText = 'Variable "log_recent" wurde noch nicht angelegt.';
        } else {
            $logText = (string) @GetValue($logId);
            $logText = trim($logText);
            if ($logText === '') {
                $logText = '– keine Einträge vorhanden –';
            } else {
                $logText = substr($logText, -20000);
            }
        }

        return [
            'type'   => 'ExpansionPanel',
            'caption'=> 'Diagnose: Codex-Protokoll (log_recent)',
            'items'  => [
                [
                    'type'      => 'ValidationTextBox',
                    'name'      => 'DiagCodexLog',
                    'caption'   => 'Aktueller Inhalt',
                    'multiline' => true,
                    'enabled'   => false,
                    'value'     => $logText,
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Die Ausgabe stammt direkt aus der internen Variable "log_recent".',
                ],
            ],
        ];
    }

    /**
     * Button handler from form
     */
    public function TestOpenPage(int $which)
    {
        $V = $this->BuildVars();
        $page = ($which === 1)
            ? $this->ReadPropertyString('EnergiePageId')
            : $this->ReadPropertyString('KameraPageId');
        $this->TriggerPageSwitch($page, (int) $V['WfcId']);
    }

    public function TriggerPageSwitch(string $pageId, int $wfcId): void
    {
        $pageId = trim($pageId);
        if ($pageId === '' || $wfcId <= 0) {
            return;
        }

        $payload = json_encode([
            'page' => $pageId,
            'wfc'  => $wfcId,
        ], JSON_UNESCAPED_SLASHES);

        $this->WriteAttributeString('DelayedPageSwitchPayload', (string) $payload);
        $this->SetTimerInterval('DelayedPageSwitch', 10 * 1000);
    }

    public function HandleDelayedPageSwitch(): void
    {
        $raw = $this->ReadAttributeString('DelayedPageSwitchPayload');
        $data = @json_decode($raw, true);
        $this->SetTimerInterval('DelayedPageSwitch', 0);
        $this->WriteAttributeString('DelayedPageSwitchPayload', '');

        if (!is_array($data)) {
            $this->log('warn', 'DelayedPageSwitch: invalid payload', ['raw' => $raw]);
            return;
        }

        $page = (string) ($data['page'] ?? '');
        $wfc = (int) ($data['wfc'] ?? 0);
        if ($page === '' || $wfc <= 0) {
            $this->log('warn', 'DelayedPageSwitch: missing params', ['data' => $data]);
            return;
        }

        if (!IPS_InstanceExists($wfc)) {
            $this->log('warn', 'DelayedPageSwitch: WFC missing', ['wfc' => $wfc]);
            return;
        }

        try {
            WFC_SwitchPage($wfc, $page);
        } catch (\Throwable $t) {
            $this->log('error', 'DelayedPageSwitch failed', ['msg' => $t->getMessage()]);
        }
    }

    /**
     * Legacy-compatible API
     */
    public function RunRouteAll(string $payloadJson)
    {
        $payload = @json_decode($payloadJson, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $cfg = $this->BuildConfig();
        $router = new \IPSAlexaHaussteuerung\Router();
        $res = $router->route($payload, $cfg);

        return json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'RunRouteAll':
                if (!is_string($Value)) {
                    $Value = json_encode($Value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (!is_string($Value)) {
                    $Value = '';
                }

                return $this->RunRouteAll($Value);

            default:
                trigger_error('Invalid ident for IPSAlexaHaussteuerung: ' . (string) $Ident, E_USER_NOTICE);
        }

        return null;
    }

    /**
     * Build merged config for Router/Renderers
     */
    private function BuildConfig(): array
    {
        return [
            'V'         => $this->BuildVars(),
            'S'         => $this->BuildScripts(),
            'LOG_LEVEL' => $this->ReadPropertyString('LOG_LEVEL'),
        ];
    }

    private function BuildVars(): array
    {
        $root = $this->InstanceID;
        $settings = $this->getObjectIDByIdentOrName($root, 'iahSettings', 'Einstellungen');
        $helper = $this->getObjectIDByIdentOrName($root, 'iahHelper', 'Alexa new devices helper');

        $get = function (int $parent, string $ident) {
            return (int) @IPS_GetObjectIDByIdent($ident, $parent);
        };

        $resolveVar = static function (int $id): int {
            if ($id > 0 && IPS_VariableExists($id)) {
                return $id;
            }

            return 0;
        };

        $deviceMapJsonVar = $get((int) $helper, 'deviceMapJson');

        return [
            'BaseUrl'       => $this->ReadPropertyString('BaseUrl'),
            'Source'        => $this->ReadPropertyString('Source'),
            'Token'         => $this->ReadPropertyString('Token'),
            'Passwort'      => $this->ReadPropertyString('Passwort'),
            'StartPage'     => $this->ReadPropertyString('StartPage'),
            'WfcId'         => $this->ReadPropertyInteger('WfcId'),
            'EnergiePageId' => $this->ReadPropertyString('EnergiePageId'),
            'KameraPageId'  => $this->ReadPropertyString('KameraPageId'),
            'InstanceID'    => $this->InstanceID,
            // IDs der erzeugten/verknüpften Variablen
            'vars'          => [
                'settings_cat'       => (int) $settings,
                'helper_cat'         => (int) $helper,
                'bewaesserung_toggle' => $get((int) $settings, 'bewaesserungToggle'),
                'geraete_toggle'      => $get((int) $settings, 'geraeteToggle'),
                'heizung_stellen'     => $get((int) $settings, 'heizungStellen'),
                'jalousie_steuern'    => $get((int) $settings, 'jalousieSteuern'),
                'licht_dimmers'       => $get((int) $settings, 'lichtDimmers'),
                'licht_switches'      => $get((int) $settings, 'lichtSwitches'),
                'lueftung_toggle'     => $get((int) $settings, 'lueftungToggle'),
                'wfc_page_params'     => $get((int) $settings, 'wfcPageParams'),
                'devicemap_json'      => $deviceMapJsonVar,
                'pending_deviceid'    => $get((int) $helper, 'pendingDeviceId'),
                'pending_stage'       => $get((int) $helper, 'pendingStage'),
                'action'              => (int) @IPS_GetObjectIDByIdent('action', $root),
                'device'              => (int) @IPS_GetObjectIDByIdent('device', $root),
                'room'                => (int) @IPS_GetObjectIDByIdent('room', $root),
                'skill_active'        => (int) @IPS_GetObjectIDByIdent('skillActive', $root),
                'dump_file'           => (int) @IPS_GetObjectIDByIdent('dumpFile', $root),
                'last_var_device'     => (int) @IPS_GetObjectIDByIdent('lastVarDevice', $root),
                'last_var_id'         => (int) @IPS_GetObjectIDByIdent('lastVarId', $root),
                'last_var_action'     => (int) @IPS_GetObjectIDByIdent('lastVarAction', $root),
                'last_var_value'      => (int) @IPS_GetObjectIDByIdent('lastVarValue', $root),
                'log_recent'          => (int) @IPS_GetObjectIDByIdent('logRecent', $root),
            ],
            'scripts'       => [
                'rooms_catalog' => $this->getObjectIDByIdentOrName((int) $settings, 'roomsCatalog', 'RoomsCatalog'),
            ],
        ];
    }

    private function BuildScripts(): array
    {
        return [
            'ACTION'            => $this->getActionScriptId(),
            'ROUTE_ALL'         => $this->getRendererScriptId('iahRouteAllRenderer', 'Route_allRenderer'),
            'RENDER_MAIN'       => $this->getRendererScriptId('iahRenderLaunch', 'LaunchRequest'),
            'RENDER_SETTINGS'   => $this->getRendererScriptId('iahRenderSettings', 'EinstellungsRender'),
            'RENDER_HEIZUNG'    => $this->getRendererScriptId('iahRenderHeizung', 'HeizungRenderer'),
            'RENDER_JALOUSIE'   => $this->getRendererScriptId('iahRenderJalousie', 'JalousieRenderer'),
            'RENDER_LICHT'      => $this->getRendererScriptId('iahRenderLicht', 'LichtRenderer'),
            'RENDER_LUEFTUNG'   => $this->getRendererScriptId('iahRenderLueftung', 'LüftungRenderer'),
            'RENDER_GERAETE'    => $this->getRendererScriptId('iahRenderGeraete', 'GeraeteRenderer'),
            'RENDER_BEWAESSERUNG' => $this->getRendererScriptId('iahRenderBewaesserung', 'BewaesserungRenderer'),
        ];
    }

    private function injectDeviceMapValue(array $elements, string $value): array
    {
        foreach ($elements as &$element) {
            if (isset($element['name']) && $element['name'] === 'DeviceMapJson') {
                $element['value'] = $value;
            }
            if (isset($element['items']) && is_array($element['items'])) {
                $element['items'] = $this->injectDeviceMapValue($element['items'], $value);
            }
        }

        return $elements;
    }

    private function readDeviceMapJsonFromVariable(): string
    {
        $varId = $this->getDeviceMapVariableId();
        if ($varId > 0 && IPS_VariableExists($varId)) {
            return (string) GetValueString($varId);
        }

        return $this->ReadPropertyString('DeviceMapJson');
    }

    private function getDeviceMapVariableId(): int
    {
        $helper = $this->getObjectIDByIdentOrName($this->InstanceID, 'iahHelper', 'Alexa new devices helper');
        if ($helper > 0) {
            $varId = @IPS_GetObjectIDByIdent('deviceMapJson', (int) $helper);
            if ($varId) {
                return (int) $varId;
            }
        }

        return 0;
    }

    private function syncDeviceMapVarFromProperty(): void
    {
        $varId = $this->getDeviceMapVariableId();
        if ($varId <= 0 || !IPS_VariableExists($varId)) {
            return;
        }

        $propertyValue = $this->ReadPropertyString('DeviceMapJson');
        $currentValue = (string) GetValueString($varId);
        $mirror = $this->ReadAttributeString('DeviceMapJsonMirror');

        if ($mirror === '') {
            if ($propertyValue !== '') {
                SetValueString($varId, $propertyValue);
                $this->WriteAttributeString('DeviceMapJsonMirror', $propertyValue);
                return;
            }

            if ($currentValue !== '') {
                $this->updateDeviceMapProperty($currentValue);
                return;
            }

            $this->WriteAttributeString('DeviceMapJsonMirror', '');
            return;
        }

        if ($propertyValue !== $mirror) {
            SetValueString($varId, $propertyValue);
            $this->WriteAttributeString('DeviceMapJsonMirror', $propertyValue);
            return;
        }

        if ($currentValue !== $mirror) {
            $this->updateDeviceMapProperty($currentValue);
        }
    }

    private function updateDeviceMapProperty(string $value): void
    {
        if ($this->ReadPropertyString('DeviceMapJson') === $value) {
            $this->WriteAttributeString('DeviceMapJsonMirror', $value);
            return;
        }

        IPS_SetProperty($this->InstanceID, 'DeviceMapJson', $value);
        $this->WriteAttributeString('DeviceMapJsonMirror', $value);

        if ($this->isApplyingChanges) {
            return;
        }

        @IPS_ApplyChanges($this->InstanceID);
    }

    private function getActionScriptId(): int
    {
        $id = @IPS_GetObjectIDByIdent('iahActionEntry', $this->InstanceID);
        if ($id) {
            return (int) $id;
        }

        $name = 'Action (Haus\\Übersicht/Einstellungen Entry)';
        $local = @IPS_GetObjectIDByName($name, $this->InstanceID);
        if ($local) {
            IPS_SetIdent($local, 'iahActionEntry');
            return (int) $local;
        }

        $global = @IPS_GetObjectIDByName('Action', 0);
        return (int) $global;
    }

    private function ensureActionScript(int $parent): int
    {
        $ident = 'iahActionEntry';
        $name = 'Action (Haus\\Übersicht/Einstellungen Entry)';
        $id = @IPS_GetObjectIDByIdent($ident, $parent);

        if (!$id) {
            $byName = @IPS_GetObjectIDByName($name, $parent);
            if ($byName) {
                $id = $byName;
                IPS_SetIdent($id, $ident);
            }
        }

        if (!$id) {
            $id = IPS_CreateScript(0);
            IPS_SetParent($id, $parent);
            IPS_SetName($id, $name);
            IPS_SetIdent($id, $ident);
        }

        $template = __DIR__ . '/resources/action_entry.php';
        if (is_file($template)) {
            $content = file_get_contents($template);
            if ($content !== false) {
                IPS_SetScriptContent($id, $content);
            }
        }

        return (int) $id;
    }

    private function ensureHelperScripts(int $parent): void
    {
        $map = [
            ['name' => 'CoreHelpers', 'ident' => 'coreHelpersScript', 'file' => __DIR__ . '/resources/helpers/CoreHelpers.php'],
            ['name' => 'DeviceMap', 'ident' => 'deviceMapScript', 'file' => __DIR__ . '/resources/helpers/DeviceMap.php'],
            ['name' => 'RoomBuilderHelpers', 'ident' => 'roomBuilderHelpersScript', 'file' => __DIR__ . '/resources/helpers/RoomBuilderHelpers.php'],
            ['name' => 'DeviceMapWizard', 'ident' => 'deviceMapWizardScript', 'file' => __DIR__ . '/resources/helpers/DeviceMapWizard.php'],
            ['name' => 'Lexikon', 'ident' => 'lexikonScript', 'file' => __DIR__ . '/resources/helpers/Lexikon.php'],
            ['name' => 'Normalizer', 'ident' => 'normalizerScript', 'file' => __DIR__ . '/resources/helpers/Normalizer.php'],
        ];

        foreach ($map as $def) {
            $this->ensureScriptTemplate($parent, $def['name'], $def['ident'], $def['file']);
        }
    }

    private function ensureRendererScripts(int $parent): void
    {
        $base = __DIR__ . '/resources/renderers/';
        $map = [
            ['name' => 'Route_allRenderer',   'ident' => 'iahRouteAllRenderer',   'file' => $base . 'Route_allRenderer.php'],
            ['name' => 'LaunchRequest',       'ident' => 'iahRenderLaunch',       'file' => $base . 'LaunchRequest.php'],
            ['name' => 'HeizungRenderer',     'ident' => 'iahRenderHeizung',      'file' => $base . 'RenderHeizung.php'],
            ['name' => 'JalousieRenderer',    'ident' => 'iahRenderJalousie',     'file' => $base . 'RenderJalousie.php'],
            ['name' => 'LichtRenderer',       'ident' => 'iahRenderLicht',        'file' => $base . 'RenderLicht.php'],
            ['name' => 'LüftungRenderer',     'ident' => 'iahRenderLueftung',     'file' => $base . 'RenderLueftung.php'],
            ['name' => 'GeraeteRenderer',     'ident' => 'iahRenderGeraete',      'file' => $base . 'RenderGeraete.php'],
            ['name' => 'BewaesserungRenderer','ident' => 'iahRenderBewaesserung', 'file' => $base . 'RenderBewaesserung.php'],
            ['name' => 'EinstellungsRender',  'ident' => 'iahRenderSettings',     'file' => $base . 'RenderSettings.php'],
        ];

        foreach ($map as $def) {
            $this->ensureScriptTemplate($parent, $def['name'], $def['ident'], $def['file']);
        }
    }

    private function ensureScriptTemplate(int $parent, string $name, string $ident, string $template): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);

        if (!$id) {
            $byName = @IPS_GetObjectIDByName($name, $parent);
            if ($byName) {
                $id = $byName;
                IPS_SetIdent($id, $ident);
            }
        }

        if (!$id) {
            $id = IPS_CreateScript(0);
            IPS_SetParent($id, $parent);
            IPS_SetName($id, $name);
            IPS_SetIdent($id, $ident);
        }

        if (is_file($template)) {
            $content = file_get_contents($template);
            if ($content !== false) {
                IPS_SetScriptContent($id, $content);
            }
        }

        return (int) $id;
    }

    private function getRendererScriptId(string $ident, string $name): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id) {
            return (int) $id;
        }

        return $this->findScriptIdByName($name);
    }

    private function findScriptIdByName(string $name): int
    {
        $local = @IPS_GetObjectIDByName($name, $this->InstanceID);
        if ($local) {
            return (int) $local;
        }

        $global = @IPS_GetObjectIDByName($name, 0);
        return (int) $global;
    }

    private function ensureRoomsCatalogTemplate(int $parent): int
    {
        $name = 'RoomsCatalog';
        $ident = 'roomsCatalog';
        $id = @IPS_GetObjectIDByIdent($ident, $parent);

        if (!$id) {
            $byName = @IPS_GetObjectIDByName($name, $parent);
            if ($byName) {
                $id = $byName;
                IPS_SetIdent($id, $ident);
            }
        }

        if (!$id) {
            $id = IPS_CreateScript(0);
            IPS_SetParent($id, $parent);
            IPS_SetName($id, $name);
            IPS_SetIdent($id, $ident);
        }

        $template = __DIR__ . '/resources/helpers/RoomsCatalog.php';
        if (is_file($template)) {
            $content = file_get_contents($template);
            if ($content !== false) {
                IPS_SetScriptContent($id, $content);
            }
        }

        return (int) $id;
    }

    private function ensureSystemConfigurationScript(int $settingsCat, int $helperCat): int
    {
        $parent = $settingsCat;
        $ident = 'iahSystemConfiguration';
        $name = 'SystemConfiguration';
        $id = @IPS_GetObjectIDByIdent($ident, $parent);

        if (!$id) {
            $byName = @IPS_GetObjectIDByName($name, $parent);
            if ($byName) {
                $id = $byName;
                IPS_SetIdent($id, $ident);
            }
        }

        if (!$id) {
            $id = IPS_CreateScript(0);
            IPS_SetParent($id, $parent);
            IPS_SetName($id, $name);
            IPS_SetIdent($id, $ident);
        }

        $snapshot = $this->buildSystemConfigurationSnapshot($settingsCat, $helperCat);
        $content = "<?php\nreturn " . var_export($snapshot, true) . ";\n";
        IPS_SetScriptContent($id, $content);

        return (int) $id;
    }

    private function buildSystemConfigurationSnapshot(int $settingsCat, int $helperCat): array
    {
        $root = $this->InstanceID;
        $getVar = function (int $parent, string $ident, string $name = ''): int {
            $id = @IPS_GetObjectIDByIdent($ident, $parent);
            if ($id) {
                return (int) $id;
            }
            if ($name !== '') {
                $byName = @IPS_GetObjectIDByName($name, $parent);
                if ($byName) {
                    IPS_SetIdent($byName, $ident);
                    return (int) $byName;
                }
            }
            return 0;
        };

        $resolveVar = static function (int $id): int {
            if ($id > 0 && IPS_VariableExists($id)) {
                return $id;
            }
            return 0;
        };

        $lueftungToggleVar = $getVar($settingsCat, 'lueftungToggle', 'lueftung_toggle');

        $var = [
            'BaseUrl'       => $this->ReadPropertyString('BaseUrl'),
            'Source'        => $this->ReadPropertyString('Source'),
            'Token'         => $this->ReadPropertyString('Token'),
            'Passwort'      => $this->ReadPropertyString('Passwort'),
            'StartPage'     => $this->ReadPropertyString('StartPage'),
            'LOG_LEVEL'     => $this->ReadPropertyString('LOG_LEVEL'),
            'WfcId'         => $this->ReadPropertyInteger('WfcId'),
            'EnergiePageId' => $this->ReadPropertyString('EnergiePageId'),
            'KameraPageId'  => $this->ReadPropertyString('KameraPageId'),
            'ActionsEnabled' => [
                'heizung_stellen'     => $getVar($settingsCat, 'heizungStellen', 'heizung_stellen'),
                'jalousie_steuern'    => $getVar($settingsCat, 'jalousieSteuern', 'jalousie_steuern'),
                'licht_switches'      => $getVar($settingsCat, 'lichtSwitches', 'licht_switches'),
                'licht_dimmers'       => $getVar($settingsCat, 'lichtDimmers', 'licht_dimmers'),
                'lueft_stellen'       => $lueftungToggleVar,
                'lueftung_toggle'     => $lueftungToggleVar,
                'geraete_toggle'      => $getVar($settingsCat, 'geraeteToggle', 'geraete_toggle'),
                'bewaesserung_toggle' => $getVar($settingsCat, 'bewaesserungToggle', 'bewaesserung_toggle'),
            ],
            'DEVICE_MAP'     => $getVar($helperCat, 'deviceMapJson', 'DeviceMapJson'),
            'PENDING_DEVICE' => $getVar($helperCat, 'pendingDeviceId', 'PendingDeviceId'),
            'PENDING_STAGE'  => $getVar($helperCat, 'pendingStage', 'PendingStage'),
            'DOMAIN_FLAG'    => $getVar($root, 'domainFlag', 'domain_flag'),
            'SKILL_ACTIVE'   => $getVar($root, 'skillActive', 'skillActive'),
            'lastVariableDevice' => $getVar($root, 'lastVarDevice', 'lastVariableDevice'),
            'lastVariableId'     => $getVar($root, 'lastVarId', 'lastVariableId'),
            'lastVariableAction' => $getVar($root, 'lastVarAction', 'lastVariableAction'),
            'lastVariableValue'  => $getVar($root, 'lastVarValue', 'lastVariableValue'),
            'AUSSEN_TEMP'    => $resolveVar($this->ReadPropertyInteger('VarAussenTemp')),
            'INFORMATION'    => $resolveVar($this->ReadPropertyInteger('VarInformation')),
            'MELDUNGEN'      => $resolveVar($this->ReadPropertyInteger('VarMeldungen')),
            'HEIZRAUM_IST'   => $resolveVar($this->ReadPropertyInteger('VarHeizraumIst')),
            'OG_GANG_IST'    => $resolveVar($this->ReadPropertyInteger('VarOgGangIst')),
            'TECHNIK_IST'    => $resolveVar($this->ReadPropertyInteger('VarTechnikIst')),
            'CoreHelpers'        => $getVar($helperCat, 'coreHelpersScript', 'CoreHelpers'),
            'DeviceMap'          => $getVar($helperCat, 'deviceMapScript', 'DeviceMap'),
            'RoomBuilderHelpers' => $getVar($helperCat, 'roomBuilderHelpersScript', 'RoomBuilderHelpers'),
            'DeviceMapWizard'    => $getVar($helperCat, 'deviceMapWizardScript', 'DeviceMapWizard'),
            'Lexikon'            => $getVar($helperCat, 'lexikonScript', 'Lexikon'),
        ];

        $scripts = [
            'ROUTE_ALL'           => $this->getRendererScriptId('iahRouteAllRenderer', 'Route_allRenderer'),
            'RENDER_MAIN'         => $this->getRendererScriptId('iahRenderLaunch', 'LaunchRequest'),
            'RENDER_SETTINGS'     => $this->getRendererScriptId('iahRenderSettings', 'EinstellungsRender'),
            'RENDER_HEIZUNG'      => $this->getRendererScriptId('iahRenderHeizung', 'HeizungRenderer'),
            'RENDER_JALOUSIE'     => $this->getRendererScriptId('iahRenderJalousie', 'JalousieRenderer'),
            'RENDER_LICHT'        => $this->getRendererScriptId('iahRenderLicht', 'LichtRenderer'),
            'RENDER_LUEFTUNG'     => $this->getRendererScriptId('iahRenderLueftung', 'LüftungRenderer'),
            'RENDER_GERAETE'      => $this->getRendererScriptId('iahRenderGeraete', 'GeraeteRenderer'),
            'RENDER_BEWAESSERUNG' => $this->getRendererScriptId('iahRenderBewaesserung', 'BewaesserungRenderer'),
            'ROOMS_CATALOG'       => $getVar($settingsCat, 'roomsCatalog', 'RoomsCatalog'),
            'NORMALIZER'          => $getVar($helperCat, 'normalizerScript', 'Normalizer'),
        ];

        $requiredVars = ['CoreHelpers', 'DeviceMap', 'RoomBuilderHelpers', 'DeviceMapWizard', 'Lexikon', 'DEVICE_MAP', 'PENDING_DEVICE', 'PENDING_STAGE', 'DOMAIN_FLAG', 'SKILL_ACTIVE'];
        $requiredScripts = ['ROOMS_CATALOG', 'NORMALIZER'];
        $missing = [];

        foreach ($requiredVars as $key) {
            $val = $var[$key] ?? 0;
            if ((int) $val === 0) {
                $missing[] = $key;
            }
        }

        foreach ($requiredScripts as $key) {
            $val = $scripts[$key] ?? 0;
            if ((int) $val === 0) {
                $missing[] = $key;
            }
        }

        return ['var' => $var, 'script' => $scripts, 'missing' => $missing];
    }

    /**
     * Rebind and re-check infrastructure and scripts; returns summary string
     */
    public function DiagRebind(): string
    {
        $this->EnsureInfrastructure();
        $S = $this->BuildScripts();
        $ok = [];
        foreach ($S as $k => $v) {
            $ok[] = $k . ':' . ($v ?: 0);
        }

        return 'Scripts: [' . implode(', ', $ok) . ']';
    }

    /**
     * Build and run a minimal test payload ('main_launch')
     */
    public function DiagTestLaunch(): string
    {
        $payload = ['route' => 'main_launch', 'aplSupported' => true];
        $router = new \IPSAlexaHaussteuerung\Router();
        $res = $router->route($payload, $this->BuildConfig());

        return json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Sends the custom JSON payload (from property DiagPayload) through the Router and stores the result
     * in dumpFile var + log
     */
    public function DiagSendCustom(): string
    {
        $json = $this->ReadPropertyString('DiagPayload');
        $payload = @json_decode($json, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $router = new \IPSAlexaHaussteuerung\Router();
        $res = $router->route($payload, $this->BuildConfig());
        $out = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // write to dumpFile variable if present
        $dumpId = (int) ($this->BuildVars()['vars']['dump_file'] ?? 0);
        if ($dumpId > 0) {
            @SetValueString($dumpId, $out);
        }

        $this->log('info', 'DiagSendCustom result', ['len' => strlen($out)]);
        return $out;
    }

    /**
     * Clears the dumpFile variable (Antwort-Vorschau)
     */
    public function DiagClearDump(): void
    {
        $dumpId = (int) ($this->BuildVars()['vars']['dump_file'] ?? 0);
        if ($dumpId > 0) {
            @SetValueString($dumpId, '');
        }
    }

    /**
     * Clears the recent log buffer
     */
    public function DiagClearLogs(): void
    {
        $logId = (int) ($this->BuildVars()['vars']['log_recent'] ?? 0);
        if ($logId > 0) {
            @SetValueString($logId, '');
        }
    }
}
