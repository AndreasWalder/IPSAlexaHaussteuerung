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

        // Pages
        $this->RegisterPropertyString('EnergiePageId', 'item2124');
        $this->RegisterPropertyString('KameraPageId', 'item9907');

        // Diagnostics payload editor
        $this->RegisterPropertyString('DiagPayload', '{"route":"main_launch","aplSupported":true}');

        // WebHook (optional): expose /hook/ipshalexa for Skill endpoint
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureHelperScripts();
        $this->EnsureInfrastructure();
        $this->EnsureActionEntryScript();
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
        $params = ['page' => $page, 'wfc' => $V['WfcId']];
        $delayScript = $this->getDelayScriptId();
        if ($delayScript > 0) {
            @IPS_RunScriptEx($delayScript, $params);
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

        return [
            'BaseUrl'       => $this->ReadPropertyString('BaseUrl'),
            'Source'        => $this->ReadPropertyString('Source'),
            'Token'         => $this->ReadPropertyString('Token'),
            'Passwort'      => $this->ReadPropertyString('Passwort'),
            'StartPage'     => $this->ReadPropertyString('StartPage'),
            'WfcId'         => $this->ReadPropertyInteger('WfcId'),
            'DelayScript'   => $this->getDelayScriptId(),
            'EnergiePageId' => $this->ReadPropertyString('EnergiePageId'),
            'KameraPageId'  => $this->ReadPropertyString('KameraPageId'),
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
                'devicemap_json'      => $get((int) $helper, 'deviceMapJson'),
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
            'ROUTE_ALL'         => $this->findScriptIdByName('Route_allRenderer'),
            'RENDER_MAIN'       => $this->findScriptIdByName('LaunchRequest'),
            'RENDER_SETTINGS'   => $this->findScriptIdByName('EinstellungsRender'),
            'RENDER_HEIZUNG'    => $this->findScriptIdByName('HeizungRenderer'),
            'RENDER_JALOUSIE'   => $this->findScriptIdByName('JalousieRenderer'),
            'RENDER_LICHT'      => $this->findScriptIdByName('LichtRenderer'),
            'RENDER_LUEFTUNG'   => $this->findScriptIdByName('LüftungRenderer'),
            'RENDER_GERAETE'    => $this->findScriptIdByName('GeraeteRenderer'),
            'RENDER_BEWAESSERUNG' => $this->findScriptIdByName('BewaesserungRenderer'),
        ];
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

    private function findScriptIdByName(string $name): int
    {
        $local = @IPS_GetObjectIDByName($name, $this->InstanceID);
        if ($local) {
            return (int) $local;
        }

        $global = @IPS_GetObjectIDByName($name, 0);
        return (int) $global;
    }

    /**
     * Create or update a forwarding child script selectable as entry point in skill instance
     */
    private function EnsureActionEntryScript(): void
    {
        $root = $this->InstanceID;
        $sid = $this->ensureScript($root, 'Action (Haus\\Übersicht/Einstellungen Entry)', 'iahActionEntry');
        $content = $this->EntryScriptContent();
        IPS_SetScriptContent($sid, $content);
        IPS_SetHidden($sid, false);
    }

    /**
     * Ensure/Update content of entry script
     */
    private function EntryScriptContent(): string
    {
        $path = __DIR__ . '/resources/action_entry.php';
        if (!is_file($path)) {
            return "<?php\n";
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return "<?php\n";
        }

        return $content;
    }

    private function ensureScript(int $parent, string $name, string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if (!$id) {
            $byName = @IPS_GetObjectIDByName($name, $parent);
            if ($byName) {
                $id = $byName;
                IPS_SetIdent($id, $ident);
            } else {
                $id = IPS_CreateScript(0); // PHP
                IPS_SetParent($id, $parent);
                IPS_SetName($id, $name);
                IPS_SetIdent($id, $ident);
            }
        }

        return $id;
    }

    private function ensureRoomsCatalogTemplate(int $parent): int
    {
        $name = 'RoomsCatalog';
        $ident = 'roomsCatalog';
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        $created = false;

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
            $created = true;
        }

        if ($created) {
            $template = __DIR__ . '/resources/helpers/RoomsCatalog.php';
            if (is_file($template)) {
                $content = file_get_contents($template);
                if ($content !== false) {
                    IPS_SetScriptContent($id, $content);
                }
            }
        }

        return (int) $id;
    }

    /**
     * Ensure helper scripts that are bundled with the module exist and keep properties in sync
     */
    private function EnsureHelperScripts(): void
    {
        $delayScriptPath = __DIR__ . '/resources/helpers/WfcDelayedPageSwitch.php';
        $this->ensureTemplateScript($this->InstanceID, 'WfcDelayedPageSwitch', 'iahWfcDelayedPageSwitch', $delayScriptPath);

        $configTemplate = __DIR__ . '/resources/helpers/SystemConfiguration.php';
        $this->ensureTemplateScript($this->InstanceID, 'SystemConfiguration', 'iahSystemConfiguration', $configTemplate);
    }

    private function ensureTemplateScript(int $parent, string $name, string $ident, string $templatePath): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        $created = false;

        if (!$id) {
            $byName = @IPS_GetObjectIDByName($name, $parent);
            if ($byName) {
                $id = $byName;
                IPS_SetIdent($id, $ident);
            } else {
                $id = IPS_CreateScript(0);
                IPS_SetParent($id, $parent);
                IPS_SetName($id, $name);
                IPS_SetIdent($id, $ident);
                $created = true;
            }
        }

        if ($created && is_file($templatePath)) {
            $content = file_get_contents($templatePath);
            if ($content !== false) {
                IPS_SetScriptContent($id, $content);
            }
        }

        return (int) $id;
    }

    private function getDelayScriptId(): int
    {
        return $this->getObjectIDByIdentOrName($this->InstanceID, 'iahWfcDelayedPageSwitch', 'WfcDelayedPageSwitch');
    }

    /**
     * Getter for gateway: which script should be called as main Action
     */
    public function GetActionTarget(): int
    {
        $scripts = $this->BuildScripts();
        return (int) ($scripts['ACTION'] ?? 0);
    }

    /**
     * Rebind and re-check infrastructure and scripts; returns summary string
     */
    public function DiagRebind(): string
    {
        $this->EnsureInfrastructure();
        $this->EnsureActionEntryScript();
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
