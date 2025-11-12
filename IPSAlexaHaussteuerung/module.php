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
        $this->RegisterPropertyInteger('DelayScript', 55368);
        $this->RegisterPropertyString('LOG_LEVEL', 'debug');

        // Pages
        $this->RegisterPropertyString('EnergiePageId', 'item2124');
        $this->RegisterPropertyString('KameraPageId', 'item9907');

        // Diagnostics payload editor
        $this->RegisterPropertyString('DiagPayload', '{"route":"main_launch","aplSupported":true}');

        // Flags
        $this->RegisterPropertyBoolean('flag_preload_profiles', true);
        $this->RegisterPropertyBoolean('flag_log_basic', true);
        $this->RegisterPropertyBoolean('flag_log_verbose', true);
        $this->RegisterPropertyBoolean('flag_log_apl_ds', true);

        // Script IDs
        $this->RegisterPropertyInteger('SCRIPT_ROUTE_ALL', 11978);
        $this->RegisterPropertyInteger('SCRIPT_ACTION', 39964);
        $this->RegisterPropertyInteger('SCRIPT_RENDER_MAIN', 56904);
        $this->RegisterPropertyInteger('SCRIPT_RENDER_SETTINGS', 59056);
        $this->RegisterPropertyInteger('SCRIPT_RENDER_HEIZUNG', 26735);
        $this->RegisterPropertyInteger('SCRIPT_RENDER_JALOUSIE', 50191);
        $this->RegisterPropertyInteger('SCRIPT_RENDER_LICHT', 56288);
        $this->RegisterPropertyInteger('SCRIPT_RENDER_LUEFTUNG', 28855);
        $this->RegisterPropertyInteger('SCRIPT_RENDER_GERAETE', 33310);
        $this->RegisterPropertyInteger('SCRIPT_RENDER_BEWAESSERUNG', 28653);

        // WebHook (optional): expose /hook/ipshalexa for Skill endpoint
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

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
        // base form
        $formPath = __DIR__ . '/form.json';
        $form = ['elements' => [], 'actions' => []];
        if (file_exists($formPath)) {
            $form = json_decode(file_get_contents($formPath), true);
            if (!is_array($form)) {
                $form = ['elements' => [], 'actions' => []];
            }
        }

        // inject dynamic status
        $S = $this->BuildScripts();
        $V = $this->BuildVars();
        $rows = [];
        foreach ($S as $k => $id) {
            $name = $id ? IPS_GetName($id) : '';
            $rows[] = ['key' => $k, 'id' => $id, 'name' => $name];
        }

        $vars = $V['vars'] ?? [];
        $vrows = [];
        foreach ($vars as $k => $id) {
            $name = $id ? IPS_GetName($id) : '';
            $vrows[] = ['key' => $k, 'id' => $id, 'name' => $name];
        }

        $statusPanel = [
            'type'   => 'ExpansionPanel',
            'caption' => 'Status (live)',
            'items'  => [
                ['type' => 'Label', 'caption' => 'Script-IDs'],
                [
                    'type'    => 'List',
                    'columns' => [
                        ['caption' => 'Key', 'name' => 'key', 'width' => '30%'],
                        ['caption' => 'ID', 'name' => 'id', 'width' => '20%'],
                        ['caption' => 'Name', 'name' => 'name', 'width' => '50%'],
                    ],
                    'values'  => $rows,
                ],
                ['type' => 'Label', 'caption' => 'Variablen-IDs'],
                [
                    'type'    => 'List',
                    'columns' => [
                        ['caption' => 'Key', 'name' => 'key', 'width' => '30%'],
                        ['caption' => 'ID', 'name' => 'id', 'width' => '20%'],
                        ['caption' => 'Name', 'name' => 'name', 'width' => '50%'],
                    ],
                    'values'  => $vrows,
                ],
            ],
        ];
        array_unshift($form['elements'], $statusPanel);

        // Dump preview (read-only)
        $dumpId = (int) ($V['vars']['dump_file'] ?? 0);
        $dumpText = ($dumpId > 0 && @IPS_VariableExists($dumpId)) ? (string) GetValue($dumpId) : '';
        if (strlen($dumpText) > 12000) {
            $dumpText = substr($dumpText, 0, 12000) . "\n... (truncated)";
        }

        $preview = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Antwort-Vorschau (dumpFile)',
            'items'   => [
                [
                    'type'      => 'ValidationTextBox',
                    'name'      => 'dumpPreview',
                    'caption'   => 'Letzte Antwort',
                    'value'     => $dumpText,
                    'multiline' => true,
                    'enabled'   => false,
                ],
            ],
        ];
        $form['elements'][] = $preview;

        // Logs panel (read-only)
        $logId = (int) ($V['vars']['log_recent'] ?? 0);
        $logText = ($logId > 0 && @IPS_VariableExists($logId)) ? (string) GetValue($logId) : '';
        if (strlen($logText) > 12000) {
            $logText = substr($logText, -12000);
        }

        $logsPanel = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Letzte Fehler / Logs',
            'items'   => [
                [
                    'type'      => 'ValidationTextBox',
                    'name'      => 'logsPreview',
                    'caption'   => 'Logs',
                    'value'     => $logText,
                    'multiline' => true,
                    'enabled'   => false,
                ],
            ],
        ];
        $form['elements'][] = $logsPanel;

        // Add payload editor field to elements if missing
        $hasDiag = false;
        foreach ($form['elements'] as $el) {
            if (isset($el['name']) && $el['name'] === 'DiagPayload') {
                $hasDiag = true;
                break;
            }
        }

        if (!$hasDiag) {
            $form['elements'][] = [
                'name'      => 'DiagPayload',
                'type'      => 'ValidationTextBox',
                'caption'   => 'Diagnose: Custom Payload (JSON)',
                'value'     => $this->ReadPropertyString('DiagPayload'),
                'multiline' => true,
            ];
        }

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        @IPS_RunScriptEx($V['DelayScript'], $params);
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
            'flags'     => $this->BuildFlags(),
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
            'DelayScript'   => $this->ReadPropertyInteger('DelayScript'),
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
        ];
    }

    private function BuildScripts(): array
    {
        $get = function (string $prop, string $fallbackName): int {
            $v = (int) $this->ReadPropertyInteger($prop);
            if ($v > 0 && @IPS_ObjectExists($v)) {
                return $v;
            }
            $id = @IPS_GetObjectIDByName($fallbackName, 0); // global search
            return (int) $id;
        };

        return [
            'ROUTE_ALL'         => $get('SCRIPT_ROUTE_ALL', 'Route_allRenderer'),
            'ACTION'            => $get('SCRIPT_ACTION', 'Action'),
            'RENDER_MAIN'       => $get('SCRIPT_RENDER_MAIN', 'LaunchRequest'),
            'RENDER_SETTINGS'   => $get('SCRIPT_RENDER_SETTINGS', 'EinstellungsRender'),
            'RENDER_HEIZUNG'    => $get('SCRIPT_RENDER_HEIZUNG', 'HeizungRenderer'),
            'RENDER_JALOUSIE'   => $get('SCRIPT_RENDER_JALOUSIE', 'JalousieRenderer'),
            'RENDER_LICHT'      => $get('SCRIPT_RENDER_LICHT', 'LichtRenderer'),
            'RENDER_LUEFTUNG'   => $get('SCRIPT_RENDER_LUEFTUNG', 'LüftungRenderer'),
            'RENDER_GERAETE'    => $get('SCRIPT_RENDER_GERAETE', 'GeraeteRenderer'),
            'RENDER_BEWAESSERUNG' => $get('SCRIPT_RENDER_BEWAESSERUNG', 'BewaesserungRenderer'),
        ];
    }

    private function BuildFlags(): array
    {
        return [
            'preload_profiles' => $this->ReadPropertyBoolean('flag_preload_profiles'),
            'log_basic'        => $this->ReadPropertyBoolean('flag_log_basic'),
            'log_verbose'      => $this->ReadPropertyBoolean('flag_log_verbose'),
            'log_apl_ds'       => $this->ReadPropertyBoolean('flag_log_apl_ds'),
        ];
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
        $content = <<<'PHP'
<?php
/**
 * Entry for AlexaCustomSkillIntent → calls the IPSAlexaHaussteuerung module.
 * You can select THIS script in the Skill-Instance configuration ("Dieses Skript ausführen").
 */
function Execute($request = null)
{
    // Determine module instance (parent of this script)
    $self = $_IPS['SELF'] ?? 0;
    $instanceID = IPS_GetParent($self);
    if (!$instanceID) {
        return;
    }

    // Normalize payload
    if ($request === null) {
        $payload = isset($_IPS['payload']) ? $_IPS['payload'] : [];
    } else {
        $payload = is_array($request) ? $request : (json_decode((string) $request, true) ?: []);
    }
    if (!is_array($payload)) {
        $payload = [];
    }

    // route via Module
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $res = IPS_RequestAction($instanceID, 'RunRouteAll', $json);

    // Echo back so the gateway can pass it along
    echo $res;
}
PHP;
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
