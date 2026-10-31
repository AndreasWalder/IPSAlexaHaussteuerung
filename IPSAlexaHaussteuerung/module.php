<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Helpers.php';
require_once __DIR__ . '/src/LogTrait.php';
require_once __DIR__ . '/src/KICostAccounting.php';
require_once __DIR__ . '/src/KICostCurrency.php';
require_once __DIR__ . '/src/AlexaLogBuffer.php';
require_once __DIR__ . '/src/AlexaLogTrait.php';
require_once __DIR__ . '/src/KICostTrait.php';
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
    use \IAHKICost\KICostTrait;
    use \IAHAlexaLog\AlexaLogTrait;

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
        $this->RegisterPropertyString(
            'RendererDomains',
            json_encode($this->rendererDomainDefaults(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $launchDefaults = $this->launchCatalogDefaults();
        $this->RegisterPropertyString(
            'LaunchCatalog',
            json_encode((array)($launchDefaults['tiles'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->RegisterPropertyString('LaunchTitle', (string) ($launchDefaults['title'] ?? 'HAUS VISUALISIERUNG'));
        $this->RegisterPropertyString(
            'LaunchSubtitleTemplate',
            (string) ($launchDefaults['subtitleTemplate'] ?? 'Raumname: {{alexa}}')
        );
        $this->RegisterPropertyString(
            'LaunchFooterText',
            (string) ($launchDefaults['footerText'] ?? 'Tipp eine Kachel an – oder sage z. B. „Jalousie“.')
        );
        $this->RegisterPropertyString('LaunchLogo', (string) ($launchDefaults['logo'] ?? 'Logo.png'));
        $this->RegisterPropertyString('LaunchHomeIcon', (string) ($launchDefaults['homeIcon'] ?? 'HomeIcon.png'));
        $this->RegisterPropertyString('LaunchHeaderIcon', (string) ($launchDefaults['headerIcon'] ?? 'Icon.png'));
        $this->RegisterPropertyInteger('SystemConfigScriptId', 0);
        $this->RegisterPropertyBoolean('KIIntentEnabled', true);
        $this->RegisterPropertyString('KIIntentApiKey', '');
        $this->RegisterPropertyString('KIIntentModel', 'gpt-4.1-mini'); // Altwert: weiterhin für bestehende Konfigurationen gespeichert
        // Neue zentrale Modellauswahl (auch bei bereits installierten Modulen standardmäßig Luna).
        $this->RegisterPropertyString('KIModelSelection', 'gpt-6-luna');
        $this->RegisterPropertyString('KIIntentLogChannel', 'Alexa');
        $this->RegisterPropertyInteger('KIIntentRateLimit', 60);
        $this->RegisterPropertyBoolean('HomeAgentVoiceEnabled', false);
        $this->RegisterPropertyString('KIVoiceMode', 'RoomsCatalog');
        $this->RegisterPropertyString('HomeAgentVoiceEndpoint', '');
        $this->RegisterPropertyString('HomeAgentVoiceToken', '');
        $this->RegisterPropertyBoolean('HomeAgentVoiceRouteCommands', false);
        $this->RegisterPropertyInteger('HomeAgentVoiceMaxWords', 20);
        $this->RegisterPropertyInteger('HomeAgentVoiceTimeoutSeconds', 6);
        $this->RegisterPropertyFloat('KICostMonthlyWarningUSD', 5.00); // Historischer Konfigurationswert
        $this->RegisterPropertyFloat('KICostMonthlyWarningEUR', 5.00);
        $this->RegisterPropertyFloat('KICostManualUsdToEur', 0.0);
        $this->RegisterAttributeString('KICostFxData', '');
        $this->RegisterAttributeInteger('KICostNextRateUpdate', 0);
        $this->RegisterPropertyString('KICostPriceTableJson', json_encode(iah_ki_price_table()));
        $this->RegisterPropertyBoolean('VoiceRouterStoreTranscript', true);
        $this->RegisterAttributeString('DelayedPageSwitchPayload', '');
        $this->RegisterTimer('DelayedPageSwitch', 0, 'IAH_HandleDelayedPageSwitch($_IPS["TARGET"]);');
        $this->RegisterTimer('KICostRefresh', 60000, 'IAH_UpdateKICostExchangeRate($_IPS["TARGET"]); IAH_RefreshKICostDisplay($_IPS["TARGET"]);');
        $this->RegisterTimer('AlexaLogRefresh', 15000, 'IAH_RefreshAlexaLogBuffer($_IPS["TARGET"]);');

        // Pages
        $this->RegisterPropertyString(
            'PageMappings',
            json_encode($this->pageMappingDefaults(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        // Legacy fallbacks (migrated into PageMappings automatically)
        $this->RegisterPropertyString('EnergiePageId', 'item2124');
        $this->RegisterPropertyString('KameraPageId', 'item9907');

        // Diagnostics payload editor
        $this->RegisterPropertyString('DiagPayload', '{"route":"main_launch","aplSupported":true}');
        // Isolierter Alexa-Kachelvisualisierungstest, WebFront bleibt unveraendert.
        $this->RegisterPropertyBoolean('TileTestEnabled', true);
        // 0 = Visualisierungsauswahl unter /tile/, ansonsten Instanz-ID.
        $this->RegisterPropertyInteger('TileTestVisualizationId', 0);
        // Opt-in: experimenteller localStorage-Ersatz nur bei Alexa Kacheltest.
        $this->RegisterPropertyBoolean('TileTestStorageCompat', false);
        // Dauerhaftes Alexa-Kommando "Test"; das Ziel ist spaeter erweiterbar.
        $this->RegisterPropertyString('AlexaTestTarget', 'kacheltest');

        // Optional status variables (allow linking existing values instead of auto-created ones)
        $this->RegisterPropertyInteger('VarInformation', 0);
        $this->RegisterPropertyInteger('VarMeldungen', 0);
        $this->RegisterPropertyInteger('VarAussenTemp', 0);
        $this->RegisterPropertyInteger('VarHeizraumIst', 0);
        $this->RegisterPropertyInteger('VarOgGangIst', 0);
        $this->RegisterPropertyInteger('VarTechnikIst', 0);

        // WebHook (optional): expose /hook/ipshalexa for Skill endpoint

    }

    public function ApplyChanges()
    {
        $this->isApplyingChanges = true;
        
        parent::ApplyChanges();

        $this->EnsureInfrastructure();

        $this->isApplyingChanges = false;
    }


    /**
     * Ensure categories & runtime variables exist
     */
    private function EnsureInfrastructure(): void
    {
        $root = $this->InstanceID;
        $catSettings = $this->ensureCategory($root, 'Einstellungen', 'iahSettings');
        $catHelper = $this->ensureCategory($root, 'Alexa new devices helper', 'iahHelper');
        $catRenderers = $this->ensureCategory($root, 'Renderers', 'iahRenderers');

        $this->ensureRoomsCatalogTemplate($catSettings);
        $this->ensureActionScript($catRenderers);
        $this->ensureHelperScripts($catHelper);
        $this->ensureRendererScripts($catRenderers);

        $catDiag = $this->ensureCategory($root, 'Diagnose', 'iahDiag');
        $this->EnsureAlexaLogInfrastructure($catDiag);
        $htmlDiag=$this->ensureCategory($catDiag,'Web API for Games – Kamera/Energie','iahExternalHtmlDiag');
        $this->ensureVar($htmlDiag,'Letzter HTML-Diagnosebericht (JSON)','iahHtmlLast',VARIABLETYPE_STRING,'~TextBox',null);
        $this->ensureVar($htmlDiag,'Letzte 20 HTML-Diagnosen (JSON-Zeilen)','iahHtmlHistory',VARIABLETYPE_STRING,'~TextBox',null);
        $this->ensureVar($htmlDiag,'Letztes Browser-Ereignis (JSON)','iahHtmlBrowserLast',VARIABLETYPE_STRING,'~TextBox',null);
        $this->ensureVar($htmlDiag,'Letzte 100 Browser-Ereignisse (JSON-Zeilen)','iahHtmlBrowserHistory',VARIABLETYPE_STRING,'~TextBox',null);
        // Idempotente Einrichtung: keine separaten Installationsskripte erforderlich.
        $this->ensureExternalHtmlBrowserDiagnostics($catHelper);
        $voiceDiag = $this->ensureCategory($catDiag, 'KI-Sprachrouter und APL-Navigation', 'iahVoiceDiag');
        // Entscheidungen und UI-Kontext pro anonymisiertem Alexa-Gerät.
        $rawVoiceState = $this->ensureVar($voiceDiag, 'Router-Datenspeicher (nicht bearbeiten)', 'iahVoiceState', VARIABLETYPE_STRING, '', null);
        IPS_SetHidden($rawVoiceState, true);
        $this->ensureVar($voiceDiag, 'Letzte Routerentscheidung (JSON)', 'iahVoiceLast', VARIABLETYPE_STRING, '', null);
        $this->ensureVar($voiceDiag, 'Letzte 80 Routerentscheidungen (JSON)', 'iahVoiceHistory', VARIABLETYPE_STRING, '', null);
        $this->ensureVar($voiceDiag, 'Routingzähler (JSON)', 'iahVoiceCounters', VARIABLETYPE_STRING, '', null);
        $this->ensureVar($voiceDiag, 'Alexa-UI-Kontext je Gerät (JSON)', 'iahVoiceContexts', VARIABLETYPE_STRING, '', null);

        // Einstellungen toggles (Defaults wie im Original-Flow: aktiv = true)
        $this->ensureVar($catSettings, 'bewaesserung_toggle', 'bewaesserungToggle', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'geraete_toggle', 'geraeteToggle', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'heizung_stellen', 'heizungStellen', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'jalousie_steuern', 'jalousieSteuern', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'licht_dimmers', 'lichtDimmers', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'licht_switches', 'lichtSwitches', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'lueftung_toggle', 'lueftungToggle', VARIABLETYPE_BOOLEAN, '', true);
        $this->ensureVar($catSettings, 'szene_toggle', 'szeneToggle', VARIABLETYPE_BOOLEAN, '', true);

        // WFC PageSwitch Params → Default aus Instanz-Settings
        $wfc = $this->ReadPropertyInteger('WfcId');
        $pageMappings = $this->readPageMappingsProperty();
        $page = $this->findDefaultWfcPageId($pageMappings);
        $this->ensureVar(
            $catSettings,
            'WFC PageSwitch Params',
            'wfcPageParams',
            VARIABLETYPE_STRING,
            '',
            json_encode(['page' => $page, 'wfc' => $wfc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Helper
        $this->ensureVar($catHelper, 'DeviceMapJson', 'deviceMapJson', VARIABLETYPE_STRING, '', null);
        $this->ensureVar($catHelper, 'PendingDeviceId', 'pendingDeviceId', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($catHelper, 'PendingStage', 'pendingStage', VARIABLETYPE_STRING, '', '');

        $diagVars = [
            ['action', 'action'],
            ['device', 'device'],
            ['room', 'room'],
            ['szene', 'szene'],
            ['object', 'object'],
            ['number', 'number'],
            ['prozent', 'prozent'],
            ['alles', 'alles'],
            ['alexa', 'alexa'],
        ];
        foreach ($diagVars as [$ident, $name]) {
            $this->adoptChildToCategory($root, $catDiag, $ident, $name);
            $this->ensureVar($catDiag, $name, $ident, VARIABLETYPE_STRING, '', '');
        }

        // Runtime vars under instance
        $this->ensureVar($root, 'skillActive', 'skillActive', VARIABLETYPE_BOOLEAN, '', false);
        $this->ensureVar($root, 'dumpFile', 'dumpFile', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'lastVariableDevice', 'lastVarDevice', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'lastVariableId', 'lastVarId', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'lastVariableAction', 'lastVarAction', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'lastVariableValue', 'lastVarValue', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'log_recent', 'logRecent', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'domain_flag', 'domainFlag', VARIABLETYPE_STRING, '', '');
        $this->ensureVar($root, 'activeTabState', 'activeTabState', VARIABLETYPE_STRING, '', '');

        $this->ensureSystemConfigurationScript($catSettings, $catHelper);
        $this->EnsureKICostInfrastructure();
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

    private function adoptChildToCategory(int $sourceParent, int $targetParent, string $ident, string $name): void
    {
        if ($sourceParent <= 0 || $targetParent <= 0) {
            return;
        }

        $id = @IPS_GetObjectIDByIdent($ident, $sourceParent);
        if (!$id && $name !== '') {
            $id = @IPS_GetObjectIDByName($name, $sourceParent);
        }

        if ($id && IPS_GetParent($id) !== $targetParent) {
            IPS_SetParent($id, $targetParent);
        }
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

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Button handler from form
     */
    public function TestOpenPage(int $which)
    {
        $V = $this->BuildVars();
        $pageMappings = (array)($V['pageMappings'] ?? []);
        $key = $which === 1 ? 'energie' : 'kamera';
        $page = '';
        if (isset($pageMappings[$key]) && ($pageMappings[$key]['type'] ?? '') === 'wfc_item') {
            $page = (string) ($pageMappings[$key]['value'] ?? '');
        }
        if ($page === '') {
            $legacy = $which === 1
                ? $this->ReadPropertyString('EnergiePageId')
                : $this->ReadPropertyString('KameraPageId');
            $page = trim($legacy);
        }

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

            case 'ManualRefresh':
                return $this->ManualRefresh();

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
            'rendererDomains' => $this->readRendererDomainsProperty(),
            'launchCatalog' => $this->readLaunchCatalogProperty(),
        ];
    }

    private function BuildVars(): array
    {
        $root = $this->InstanceID;
        $settings = $this->getObjectIDByIdentOrName($root, 'iahSettings', 'Einstellungen');
        $helper = $this->getObjectIDByIdentOrName($root, 'iahHelper', 'Alexa new devices helper');
        $diag    = $this->getObjectIDByIdentOrName($root, 'iahDiag', 'Diagnose');
        if ($diag <= 0) {
            $diag = $root;
        }

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

        $pageMappings = $this->readPageMappingsProperty();
        $startPage = $this->deriveStartPage();
        $energieLegacy = $this->legacyPageIdFromMappings(
            $pageMappings,
            'energie',
            $this->ReadPropertyString('EnergiePageId')
        );
        $kameraLegacy = $this->legacyPageIdFromMappings(
            $pageMappings,
            'kamera',
            $this->ReadPropertyString('KameraPageId')
        );

        return [
            'BaseUrl'       => $this->ReadPropertyString('BaseUrl'),
            'TileTestEnabled' => $this->ReadPropertyBoolean('TileTestEnabled'),
            'TileTestVisualizationId' => $this->ReadPropertyInteger('TileTestVisualizationId'),
            'Source'        => $this->ReadPropertyString('Source'),
            'Token'         => $this->ReadPropertyString('Token'),
            'Passwort'      => $this->ReadPropertyString('Passwort'),
            'StartPage'     => $startPage,
            'WfcId'         => $this->ReadPropertyInteger('WfcId'),
            'EnergiePageId' => $energieLegacy,
            'KameraPageId'  => $kameraLegacy,
            'pageMappings'  => $pageMappings,
            'InstanceID'    => $this->InstanceID,
            'KIIntentParser' => $this->buildKiIntentParserConfig(),
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
                'action'              => (int) @IPS_GetObjectIDByIdent('action', $diag),
                'device'              => (int) @IPS_GetObjectIDByIdent('device', $diag),
                'room'                => (int) @IPS_GetObjectIDByIdent('room', $diag),
                'szene'               => (int) @IPS_GetObjectIDByIdent('szene', $diag),
                'object'              => (int) @IPS_GetObjectIDByIdent('object', $diag),
                'number'              => (int) @IPS_GetObjectIDByIdent('number', $diag),
                'prozent'             => (int) @IPS_GetObjectIDByIdent('prozent', $diag),
                'alles'               => (int) @IPS_GetObjectIDByIdent('alles', $diag),
                'skill_active'        => (int) @IPS_GetObjectIDByIdent('skillActive', $root),
                'dump_file'           => (int) @IPS_GetObjectIDByIdent('dumpFile', $root),
                'last_var_device'     => (int) @IPS_GetObjectIDByIdent('lastVarDevice', $root),
                'last_var_id'         => (int) @IPS_GetObjectIDByIdent('lastVarId', $root),
                'last_var_action'     => (int) @IPS_GetObjectIDByIdent('lastVarAction', $root),
                'last_var_value'      => (int) @IPS_GetObjectIDByIdent('lastVarValue', $root),
                'log_recent'          => (int) @IPS_GetObjectIDByIdent('logRecent', $root),
                'active_tab_state'    => (int) @IPS_GetObjectIDByIdent('activeTabState', $root),
            ],
            'scripts'       => [
                'rooms_catalog' => $this->getObjectIDByIdentOrName((int) $settings, 'roomsCatalog', 'RoomsCatalog'),
                'ki_intent_parser' => $this->getObjectIDByIdentOrName((int) $helper, 'kiIntentParserScript', 'KI_IntentParser'),
            ],
        ];
    }

    private function buildKiIntentParserConfig(): array
    {
        return [
            'enabled'       => $this->ReadPropertyBoolean('KIIntentEnabled'),
            'api_key'        => $this->ReadPropertyString('KIIntentApiKey'),
            'model'          => $this->ReadPropertyString('KIModelSelection'),
            'log_channel'    => $this->ReadPropertyString('KIIntentLogChannel'),
            'rate_limit_sec' => $this->ReadPropertyInteger('KIIntentRateLimit'),
        ];
    }

    private function BuildScripts(): array
    {
        return [
            'ACTION'            => $this->getActionScriptId(),
            'ROUTE_ALL'         => $this->getRendererScriptId('iahRouteAllRenderer', 'Route_allRenderer'),
            'RENDER_MAIN'       => $this->getRendererScriptId('iahRenderLaunch', 'LaunchRequest'),
            'RENDER_SETTINGS'   => $this->getRendererScriptId('iahRenderSettings', 'EinstellungsRender'),
            'RENDER_EXTERNAL'   => $this->getRendererScriptId('iahRenderExternal', 'ExternalRenderer'),
            'RENDER_HEIZUNG'    => $this->getRendererScriptId('iahRenderHeizung', 'HeizungRenderer'),
            'RENDER_JALOUSIE'   => $this->getRendererScriptId('iahRenderJalousie', 'JalousieRenderer'),
            'RENDER_LICHT'      => $this->getRendererScriptId('iahRenderLicht', 'LichtRenderer'),
            'RENDER_LUEFTUNG'   => $this->getRendererScriptId('iahRenderLueftung', 'LüftungRenderer'),
            'RENDER_GERAETE'    => $this->getRendererScriptId('iahRenderGeraete', 'GeraeteRenderer'),
            'RENDER_BEWAESSERUNG' => $this->getRendererScriptId('iahRenderBewaesserung', 'BewaesserungRenderer'),
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

    private function ensureActionScript(int $parent): int
    {
        $ident = 'iahActionEntry';
        $name = 'Action (Haus\\Übersicht/Einstellungen Entry)';
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        $isNew = false;

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
            $isNew = true;
        }

        $template = __DIR__ . '/resources/action_entry.php';
        if (($isNew || $this->scriptContentIsEmpty((int) $id)) && is_file($template)) {
            $content = file_get_contents($template);
            if ($content !== false) {
                IPS_SetScriptContent($id, $content);
            }
        }
        $this->patchActionScriptTabScopedDirectCommands((int) $id);
        $this->patchActionScriptHomeAgentVoice((int) $id);
        $this->patchActionScriptVoiceNavigation((int) $id);
        $this->patchActionScriptSafeRequestLogging((int) $id);
        $this->patchActionScriptExternalHtmlMetadata((int) $id);
        $this->patchActionScriptTileTestNavigation((int) $id);

        return (int) $id;
    }

    /**
     * Alte Alexa-Action-Skripte bleiben erhalten; das Modul aktualisiert
     * einzelne erkannte Abschnitte statt sie pauschal zu überschreiben.
     * Die externe HTML-Diagnose benötigt die HTML-Gerätefähigkeit im
     * Routing-Payload – APL allein ist KEIN Nachweis für HTML-Unterstützung.
     */
    private function patchActionScriptExternalHtmlMetadata(int $scriptId): void
    {
        $current=$this->getScriptContent($scriptId);
        if(!is_string($current)||$current==='')return;
        $needle="'aplSupported'    => (bool)(\$aplSupported ?? false),";
        $addition="\n                'htmlSupported'   => \$iahHtmlSupport ?? null,";
        if(strpos($current,"'htmlSupported'   =>")!==false)return;
        if(strpos($current,$needle)===false){
            IPS_LogMessage('Alexa','ExternalHTML.migration: Payload-Feld nicht erkannt; bitte bestehendes Action-Skript prüfen.');
            return;
        }
        $new=str_replace($needle,$needle.$addition,$current);
        IPS_SetScriptContent($scriptId,$new);
        IPS_LogMessage('Alexa','ExternalHTML.migration: HTML-Geraetefaehigkeit in Action-Payload ergaenzt.');
    }


    /**
     * Diagnose-Shell und WebHooks automatisch bei Uebernehmen bereitstellen.
     * Vorhandene Fremd-Hooks werden NIEMALS ueberschrieben.
     * Die produktive alte alexa-shell.html wird NICHT veraendert.
     */
    private function ensureExternalHtmlBrowserDiagnostics(int $helperCategory): void
    {
        $receiver = $this->ensureScriptTemplate(
            $helperCategory, 'ExternalHtmlBrowserReceiver', 'iahHtmlBrowserReceiver',
            __DIR__ . '/resources/helpers/ExternalHtmlBrowserReceiver.php'
        );

        $icons = $this->ensureScriptTemplate(
            $helperCategory, 'AlexaIconsHook', 'iahAlexaIconsHook',
            __DIR__ . '/resources/helpers/WebHookIcons.php'
        );
        // WebHookIcons liest das bereits konfigurierte Modul-Token zur Laufzeit.
        // Weder API-Schluessel noch Passwoerter ins Skript kopieren.
        $iconsContent = $this->getScriptContent($icons);
        if (is_string($iconsContent)) {
            $updated = str_replace(
                "\$SECRET = '';",
                "\$SECRET = (string)@IPS_GetProperty(" . $this->InstanceID . ", 'Token');",
                $iconsContent
            );
            if ($updated !== $iconsContent) {
                IPS_SetScriptContent($icons, $updated);
            }
        }

        $template = __DIR__ . '/resources/html/alexa-diagnostic-shell.html';
        $dir = IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR . 'icons';
        $dest = $dir . DIRECTORY_SEPARATOR . 'alexa-diagnostic-shell.html';
        if (is_file($template)) {
            if (is_dir($dir) || @mkdir($dir, 0755, true)) {
                $source = @file_get_contents($template);
                if (is_string($source) && (!is_file($dest) || @hash_file('sha256', $dest) !== hash('sha256', $source))) {
                    if (@file_put_contents($dest, $source, LOCK_EX) === false) {
                        IPS_LogMessage('Alexa', 'ExternalHTML.setup cannot_write_shell');
                    }
                }
            } else {
                IPS_LogMessage('Alexa', 'ExternalHTML.setup cannot_create_icons_directory');
            }
        }

        $controllers = function_exists('IPS_GetInstanceListByModuleID')
            ? IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}') : [];
        if (!is_array($controllers) || count($controllers) === 0) {
            IPS_LogMessage('Alexa', 'ExternalHTML.setup webhook_control_missing');
            return;
        }
        $control = (int)$controllers[0];
        $hooks = json_decode((string)IPS_GetProperty($control, 'Hooks'), true);
        if (!is_array($hooks)) {
            IPS_LogMessage('Alexa', 'ExternalHTML.setup invalid_webhook_configuration');
            return;
        }
        $changed = false;
        foreach ([
            // Neue, modulspezifische Endpunkte: bestehende /hook/icons und
            // /hook/alexa-html-debug aus alten Tests oder anderen Modulen
            // werden nicht angeruehrt.
            '/hook/iah-alexa-shell' => $icons,
            '/hook/iah-alexa-html-debug' => $receiver
        ] as $name => $target) {
            $present = false;
            foreach ($hooks as $existing) {
                if (($existing['Hook'] ?? '') !== $name) {
                    continue;
                }
                $present = true;
                if ((int)($existing['TargetID'] ?? 0) !== (int)$target) {
                    // Nichts von Fremdmodulen bzw. vom Benutzer ueberschreiben.
                    IPS_LogMessage('Alexa', 'ExternalHTML.setup hook_exists_external ' . $name);
                }
                break;
            }
            if (!$present) {
                $hooks[] = ['Hook' => $name, 'TargetID' => (int)$target];
                $changed = true;
            }
        }
        if ($changed) {
            IPS_SetProperty($control, 'Hooks', json_encode($hooks, JSON_UNESCAPED_SLASHES));
            IPS_ApplyChanges($control);
            IPS_LogMessage('Alexa', 'ExternalHTML.setup dedicated_hooks_registered');
        }
    }

    /**
     * Spezielle Sprach-Aliase in vorhandene, benutzerangepasste
     * Action-Skripte uebernehmen; keine komplette Ueberschreibung.
     */
    private function patchActionScriptTileTestNavigation(int $scriptId): void
    {
        $current = $this->getScriptContent($scriptId);
        if (!is_string($current) || $current === '' || str_contains($current, "'test'=>'test'")) {
            return;
        }
        // Bereits migrierte Bestandsinstallationen ebenfalls aktualisieren.
        $oldAlias = "'kacheltest'=>'kacheltest','kachelvisualisierung'=>'kacheltest',";
        if (str_contains($current, $oldAlias)) {
            $updated = str_replace($oldAlias, "'test'=>'test',\n                     " . $oldAlias, $current);
        } else {
            $old = "'listen'=>'listen','energie'=>'energie','kamera'=>'kamera',";
            if (!str_contains($current, $old)) {
                IPS_LogMessage('Alexa', 'TileTest.migration action_alias_marker_not_found');
                return;
            }
            $new = $old . "\n                     'test'=>'test',"
                . "'kacheltest'=>'kacheltest','kachelvisualisierung'=>'kacheltest',";
            $updated = str_replace($old, $new, $current);
        }
        if ($updated !== $current) {
            IPS_SetScriptContent($scriptId, $updated);
            IPS_LogMessage('Alexa', 'TileTest.migration action_alias_added');
        }
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
            ['name' => 'KI_IntentParser', 'ident' => 'kiIntentParserScript', 'file' => __DIR__ . '/resources/helpers/KIIntentParser.php'],
            ['name' => 'HomeAgentVoiceBridge', 'ident' => 'homeAgentVoiceBridgeScript', 'file' => __DIR__ . '/resources/helpers/HomeAgentVoiceBridge.php'],
            ['name' => 'KIHouseController', 'ident' => 'kiHouseControllerScript', 'file' => __DIR__ . '/resources/helpers/KIHouseController.php'],
            ['name' => 'VoiceIntentRouter', 'ident' => 'voiceIntentRouterScript', 'file' => __DIR__ . '/resources/helpers/VoiceIntentRouter.php'],
            ['name' => 'ExternalHtmlProbe', 'ident' => 'externalHtmlProbeScript', 'file' => __DIR__ . '/resources/helpers/ExternalHtmlProbe.php'],
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
            ['name' => 'ExternalRenderer',    'ident' => 'iahRenderExternal',     'file' => $base . 'RenderExternal.php'],
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

        $renderersCat = @IPS_GetObjectIDByIdent('iahRenderers', $this->InstanceID);
        if ($renderersCat) {
            $id = @IPS_GetObjectIDByIdent($ident, $renderersCat);
            if ($id) {
                return (int) $id;
            }
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
        $isNew = false;

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
            $isNew = true;
        }

        if ($isNew) {
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

    private function ensureSystemConfigurationScript(int $settingsCat, int $helperCat): int
    {
        $parent = $settingsCat;
        $ident = 'iahSystemConfiguration';
        $name = 'SystemConfiguration';
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        $isNew = false;

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
            $isNew = true;
        }

        if ($isNew || $this->scriptContentIsEmpty((int) $id)) {
            $snapshot = $this->buildSystemConfigurationSnapshot($settingsCat, $helperCat);
            $content = "<?php\nreturn " . var_export($snapshot, true) . ";\n";
            IPS_SetScriptContent($id, $content);
        }

        return (int) $id;
    }

    private function scriptContentIsEmpty(int $scriptId): bool
    {
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            return true;
        }

        if (function_exists('IPS_GetScriptContent')) {
            $content = @IPS_GetScriptContent($scriptId);
            if (is_string($content)) {
                $trimmed = trim($content);
                return $trimmed === '' || $trimmed === '<?php';
            }
        }

        $file = @IPS_GetScriptFile($scriptId);
        if (is_string($file) && $file !== '' && is_file($file)) {
            $content = @file_get_contents($file);
            if (is_string($content)) {
                $trimmed = trim($content);
                return $trimmed === '' || $trimmed === '<?php';
            }
        }

        return false;
    }

    private function getScriptContent(int $scriptId): ?string
    {
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            return null;
        }

        if (function_exists('IPS_GetScriptContent')) {
            $content = @IPS_GetScriptContent($scriptId);
            if (is_string($content)) {
                return $content;
            }
        }

        $file = @IPS_GetScriptFile($scriptId);
        if (is_string($file) && $file !== '' && is_file($file)) {
            $content = @file_get_contents($file);
            if (is_string($content)) {
                return $content;
            }
        }

        return null;
    }

    private function patchActionScriptTabScopedDirectCommands(int $scriptId): void
    {
        $content = $this->getScriptContent($scriptId);
        if (!is_string($content) || $content === '') {
            return;
        }

        $updated = $content;
        $guard = <<<'PHP'
        $activeDomainPrefForDirectCommands = $getDomainPref($V);
        $activeDomainKeyForDirectCommands = gr_norm_text((string)($domain ?? ''));
        $activeDeviceKeyForDirectCommands = gr_norm_text((string)($device ?? ''));
        $insideDomainTab = (
            $activeDomainPrefForDirectCommands !== ''
            || $activeDomainKeyForDirectCommands !== ''
        );
        if (!$insideDomainTab && !empty($rendererDomainMap)) {
            foreach ($rendererDomainMap as $routeKey => $entry) {
                $keys = [
                    gr_norm_text((string)$routeKey),
                    gr_norm_text((string)($entry['roomDomain'] ?? '')),
                ];
                foreach ((array)($entry['synonyms'] ?? []) as $synonym) {
                    $keys[] = gr_norm_text((string)$synonym);
                }
                $keys = array_filter(array_unique($keys));
                if (
                    in_array($activeDomainKeyForDirectCommands, $keys, true)
                    || in_array($activeDeviceKeyForDirectCommands, $keys, true)
                ) {
                    $insideDomainTab = true;
                    break;
                }
            }
        }
PHP;

        if (strpos($updated, 'activeDomainKeyForDirectCommands') === false) {
            if (strpos($updated, '$activeDomainPrefForDirectCommands = $getDomainPref($V);') !== false) {
                $updated = preg_replace(
                    '/\n\s*\$activeDomainPrefForDirectCommands = \$getDomainPref\(\$V\);\R\s*\$insideDomainTab = \(\$activeDomainPrefForDirectCommands !== \'\'\);\R\s*\R\s*if \(!\$insideDomainTab && gr_match_maehroboter\(/',
                    "\n" . $guard . "\n\n        if (!\$insideDomainTab && gr_match_maehroboter(",
                    $updated,
                    1
                ) ?? $updated;
            } else {
                $updated = str_replace(
                    '        if (gr_match_maehroboter(',
                    $guard . "\n\n        if (!\$insideDomainTab && gr_match_maehroboter(",
                    $updated
                );
            }
        }

        if (strpos($updated, "\$insideDomainTab ? ['matched' => false] : gr_parse_roborock(") === false) {
            $updated = str_replace(
                '        $roborock = gr_parse_roborock(',
                "        \$roborock = \$insideDomainTab ? ['matched' => false] : gr_parse_roborock(",
                $updated
            );
        }

        if ($updated !== $content) {
            IPS_SetScriptContent($scriptId, $updated);
        }
    }


    /**
     * Sicherheitsmigration für bereits bestehende/angepasste Action-Skripte.
     * Der Action-Quelltext wird beim Modulupdate bewusst NICHT komplett
     * überschrieben; deshalb den bestehenden alten REQUEST_RAW-Block gezielt
     * ersetzen. Andere benutzerdefinierte Funktionen bleiben erhalten.
     */
    private function patchActionScriptSafeRequestLogging(int $scriptId): void
    {
        $template = @file_get_contents(__DIR__ . '/resources/action_entry.php');
        $content = $this->getScriptContent($scriptId);
        if (!is_string($template) || !is_string($content)) {
            return;
        }
        $begin = '        // IAH_SAFE_REQUEST_LOG_BEGIN';
        $end = '        // IAH_SAFE_REQUEST_LOG_END';
        $from = strpos($template, $begin);
        $to = strpos($template, $end, $from === false ? 0 : $from);
        if ($from === false || $to === false) {
            return;
        }
        $safe = substr($template, $from, $to + strlen($end) - $from);
        $oldFrom = strpos($content, $begin);
        $oldTo = $oldFrom === false ? false : strpos($content, $end, $oldFrom);
        if ($oldFrom !== false && $oldTo !== false) {
            $updated = substr_replace($content, $safe, $oldFrom, $oldTo + strlen($end) - $oldFrom);
        } else {
            $oldFrom = strpos($content, '        // REQUEST_RAW immer loggen bei LOG_LEVEL info + debug');
            $oldTo = $oldFrom === false ? false : strpos($content, '    } catch (Throwable $e) {', $oldFrom);
            if ($oldFrom === false || $oldTo === false) {
                // Keine bekannte REQUEST_RAW-Version: niemals Fremdcode zerstören.
                IPS_LogMessage('Alexa', 'SecurityMigration: REQUEST_RAW-Block nicht erkannt; Action-Skript manuell prüfen.');
                return;
            }
            $updated = substr_replace($content, $safe . "\n", $oldFrom, $oldTo - $oldFrom);
        }
        // Weitere bekannte Diagnosezeilen aus älteren Action-Skripten maskieren.
        $updated = str_replace(
            "IPS_LogMessage('Alexa', 'REQUEST_RAW error: ' . \$e->getMessage());",
            "IPS_LogMessage('Alexa', 'REQUEST_META errorType=' . get_class(\$e));",
            $updated
        );
        $updated = str_replace(
            "\$log('debug', 'HomeAgentVoice.recognized_text', ['text' => \$homeAgentSpokenQuestion]);",
            "\$log('debug', 'HomeAgentVoice.recognized_text', ['chars' => mb_strlen(\$homeAgentSpokenQuestion, 'UTF-8')]);",
            $updated
        );
        $updated = str_replace(
            "\$log('debug', 'KIIntentParser.trigger.' . \$reason, ['text' => \$text]);",
            "\$log('debug', 'KIIntentParser.trigger.' . \$reason, ['chars' => mb_strlen(\$text, 'UTF-8')]);",
            $updated
        );
        // Der Home-Agent-Hook wird bereits direkt davor aus dem sicheren
        // Action-Template aktualisiert; ein zweiter String-Patch ist unnötig.
        if ($updated !== $content) {
            IPS_SetScriptContent($scriptId, $updated);
            IPS_LogMessage('Alexa', 'SecurityMigration: Alexa-Action-Logging ohne Zugangsdaten aktualisiert.');
        }
    }

    /**
     * Der KI-Sprachrouter sitzt im HOME_AGENT_VOICE_HOOK, der eigentliche
     * UI-Override aber direkt DANACH. Ältere Installationen behalten ihre
     * Action-Skripte. Deshalb bei Modulupdates auch den UI-Override gezielt
     * migrieren, damit "Licht" über HomeAgentFrageIntent wirklich navigiert.
     *
     * Es wird nur der klar erkennbare, kleine UI-Override-Bereich ersetzt;
     * komplette Action-Skripte und lokale andere Anpassungen bleiben erhalten.
     */
    private function patchActionScriptVoiceNavigation(int $scriptId): void
    {
        $template = @file_get_contents(__DIR__ . '/resources/action_entry.php');
        $current = $this->getScriptContent($scriptId);
        if (!is_string($template) || !is_string($current)) return;

        $start = '        // IAH_VOICE_NAVIGATION_BEGIN';
        $end = '        // IAH_VOICE_NAVIGATION_END';
        $templateStart = strpos($template, $start);
        $templateEnd = strpos($template, $end, $templateStart === false ? 0 : $templateStart);
        if ($templateStart === false || $templateEnd === false) return;
        $nav = substr($template, $templateStart, $templateEnd + strlen($end) - $templateStart);
        $existingStart = strpos($current, $start);
        $existingEnd = $existingStart === false ? false : strpos($current, $end, $existingStart);
        if ($existingStart !== false && $existingEnd !== false) {
            $updated = substr_replace($current, $nav, $existingStart, $existingEnd + strlen($end) - $existingStart);
        } else {
            $existingStart = strpos($current, '        // FRÜHER, HARTER UI-OVERRIDE');
            $nextBlock = "        if (is_array(\$APL['args']) && count(\$APL['args']) >= 2 && (string)\$APL['args'][0] === 'GetHaus') {";
            $existingEnd = $existingStart === false ? false : strpos($current, $nextBlock, $existingStart);
            if ($existingStart === false || $existingEnd === false) {
                IPS_LogMessage('Alexa', 'VoiceRouter.migration: bestehender UI-Block nicht erkannt; bitte Action-Skript prüfen.');
                return;
            }
            $oldBlock = substr($current, $existingStart, $existingEnd - $existingStart);
            if (strpos($oldBlock, '$navForce = false;') === false
                || strpos($oldBlock, '$forcedDevice = null;') === false) {
                IPS_LogMessage('Alexa', 'VoiceRouter.migration: unbekannter UI-Block; kein Überschreiben.');
                return;
            }
            $updated = substr_replace($current, $nav . "\n", $existingStart, $existingEnd - $existingStart);
        }

        // Auch die vorherige Hauptmenü-Route aus einer älteren Installation
        // muss den neuen VoiceRouter-Back- und Home-Kontext berücksichtigen.
        if (strpos($updated, '$__route = $iahVoiceForceHome ?') === false) {
            $oldRoute = '        $__route = null;';
            $newRoute = "        \$__route = \$iahVoiceForceHome ? 'main_launch' : null;";
            if (strpos($updated, $oldRoute) !== false) {
                $updated = str_replace($oldRoute, $newRoute, $updated);
            }
        }
        // Bestehende Action-Skripte werden beim Modulupdate absichtlich
        // nicht komplett überschrieben. Daher den konkreten Fehler aus der
        // Live-Diagnose (isPlainGetHaus verdrängt navForce) mit migrieren.
        $oldPlain = "            && trim((string)\$rawUserText) === ''\n        );";
        $newPlain = "            && trim((string)\$rawUserText) === ''\n"
            . "            && !\$navForce // VoiceRouter: erkannte Kachel nie als leeres GetHaus-Hauptmenü behandeln\n"
            . "        );";
        if (strpos($updated, $oldPlain) !== false) {
            $updated = str_replace($oldPlain, $newPlain, $updated);
        }

        // Auch die endgültige Routenzuweisung nach dem Tabellen-Router
        // mitziehen. Dadurch wird die Kachel auch bei anderen Legacy-
        // Fallbacks nicht fälschlich wieder zu main_launch.
        $priorityBegin = '        // IAH_VOICE_ROUTE_PRIORITY_BEGIN';
        $priorityEnd = '        // IAH_VOICE_ROUTE_PRIORITY_END';
        $templateBegin = strpos($template, $priorityBegin);
        $templateEnd = $templateBegin === false ? false : strpos($template, $priorityEnd, $templateBegin);
        if ($templateBegin !== false && $templateEnd !== false) {
            $priority = substr($template, $templateBegin,
                $templateEnd + strlen($priorityEnd) - $templateBegin);
            $oldBegin = strpos($updated, $priorityBegin);
            $oldEnd = $oldBegin === false ? false : strpos($updated, $priorityEnd, $oldBegin);
            if ($oldBegin !== false && $oldEnd !== false) {
                $updated = substr_replace($updated, $priority, $oldBegin,
                    $oldEnd + strlen($priorityEnd) - $oldBegin);
            } else {
                $lazy = '        // --------- Lazy rooms nur wenn benötigt ---------';
                $lazyPos = strpos($updated, $lazy);
                if ($lazyPos !== false) {
                    $updated = substr_replace($updated, $priority . "\n\n", $lazyPos, 0);
                } else {
                    IPS_LogMessage('Alexa',
                        'VoiceRouter.migration: Route-Prioritätsabschnitt nicht erkannt; bitte Action-Skript prüfen.');
                }
            }
        }
        if ($updated !== $current) {
            IPS_SetScriptContent($scriptId, $updated);
            IPS_LogMessage('Alexa', 'VoiceRouter.migration: UI-Navigation und Route-Priorität aktualisiert.');
        }
    }

    private function patchActionScriptHomeAgentVoice(int $scriptId): void
    {
        $template = @file_get_contents(__DIR__ . '/resources/action_entry.php');
        $content = $this->getScriptContent($scriptId);
        if (!is_string($template) || !is_string($content)) {
            return;
        }
        $start = '        // HOME_AGENT_VOICE_HOOK_BEGIN';
        $end = '        // HOME_AGENT_VOICE_HOOK_END';
        $first = strpos($template, $start);
        $last = strpos($template, $end);
        if ($first === false || $last === false || $last < $first) {
            return;
        }
        $hook = substr($template, $first, $last + strlen($end) - $first);
        $existingStart = strpos($content, $start);
        $existingEnd = strpos($content, $end);
        if ($existingStart !== false && $existingEnd !== false && $existingEnd >= $existingStart) {
            $updated = substr_replace($content, $hook, $existingStart, $existingEnd + strlen($end) - $existingStart);
        } else {
            $pos = strpos($content, '        // FRÜHER, HARTER UI-OVERRIDE');
            if ($pos === false) {
                return;
            }
            $line = strrpos(substr($content, 0, $pos), "\n");
            $at = $line === false ? $pos : $line + 1;
            $updated = substr_replace($content, $hook . "\n\n", $at, 0);
        }
        if ($updated !== $content) {
            IPS_SetScriptContent($scriptId, $updated);
        }
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
        $diagCat = $this->getObjectIDByIdentOrName($root, 'iahDiag', 'Diagnose');
        if ($diagCat <= 0) {
            $diagCat = $root;
        }

        $pageMappings = $this->readPageMappingsProperty();
        $startPage = $this->deriveStartPage();
        $energieLegacy = $this->legacyPageIdFromMappings(
            $pageMappings,
            'energie',
            $this->ReadPropertyString('EnergiePageId')
        );
        $kameraLegacy = $this->legacyPageIdFromMappings(
            $pageMappings,
            'kamera',
            $this->ReadPropertyString('KameraPageId')
        );

        $var = [
            'BaseUrl'       => $this->ReadPropertyString('BaseUrl'),
            'TileTestEnabled' => $this->ReadPropertyBoolean('TileTestEnabled'),
            'TileTestVisualizationId' => $this->ReadPropertyInteger('TileTestVisualizationId'),
            'Source'        => $this->ReadPropertyString('Source'),
            'Token'         => $this->ReadPropertyString('Token'),
            'Passwort'      => $this->ReadPropertyString('Passwort'),
            'StartPage'     => $startPage,
            'LOG_LEVEL'     => $this->ReadPropertyString('LOG_LEVEL'),
            'WfcId'         => $this->ReadPropertyInteger('WfcId'),
            'EnergiePageId' => $energieLegacy,
            'KameraPageId'  => $kameraLegacy,
            'pageMappings'  => $pageMappings,
            'KIIntentParser' => $this->buildKiIntentParserConfig(),
            'ActionsEnabled' => [
                'heizung_stellen'     => $getVar($settingsCat, 'heizungStellen', 'heizung_stellen'),
                'jalousie_steuern'    => $getVar($settingsCat, 'jalousieSteuern', 'jalousie_steuern'),
                'licht_switches'      => $getVar($settingsCat, 'lichtSwitches', 'licht_switches'),
                'licht_dimmers'       => $getVar($settingsCat, 'lichtDimmers', 'licht_dimmers'),
                'lueft_stellen'       => $lueftungToggleVar,
                'lueftung_toggle'     => $lueftungToggleVar,
                'geraete_toggle'      => $getVar($settingsCat, 'geraeteToggle', 'geraete_toggle'),
                'bewaesserung_toggle' => $getVar($settingsCat, 'bewaesserungToggle', 'bewaesserung_toggle'),
                'szene_toggle'        => $getVar($settingsCat, 'szeneToggle', 'szene_toggle'),
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
            'ACTION_VAR'         => $getVar($diagCat, 'action', 'action'),
            'DEVICE_VAR'         => $getVar($diagCat, 'device', 'device'),
            'ROOM_VAR'           => $getVar($diagCat, 'room', 'room'),
            'SZENE_VAR'          => $getVar($diagCat, 'szene', 'szene'),
            'OBJECT_VAR'         => $getVar($diagCat, 'object', 'object'),
            'NUMBER_VAR'         => $getVar($diagCat, 'number', 'number'),
            'PROZENT_VAR'        => $getVar($diagCat, 'prozent', 'prozent'),
            'ALLES_VAR'          => $getVar($diagCat, 'alles', 'alles'),
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
            'KIIntentParserScript' => $getVar($helperCat, 'kiIntentParserScript', 'KI_IntentParser'),
        ];

        $scripts = [
            'ROUTE_ALL'           => $this->getRendererScriptId('iahRouteAllRenderer', 'Route_allRenderer'),
            'RENDER_MAIN'         => $this->getRendererScriptId('iahRenderLaunch', 'LaunchRequest'),
            'RENDER_SETTINGS'     => $this->getRendererScriptId('iahRenderSettings', 'EinstellungsRender'),
            'RENDER_EXTERNAL'     => $this->getRendererScriptId('iahRenderExternal', 'ExternalRenderer'),
            'RENDER_HEIZUNG'      => $this->getRendererScriptId('iahRenderHeizung', 'HeizungRenderer'),
            'RENDER_JALOUSIE'     => $this->getRendererScriptId('iahRenderJalousie', 'JalousieRenderer'),
            'RENDER_LICHT'        => $this->getRendererScriptId('iahRenderLicht', 'LichtRenderer'),
            'RENDER_LUEFTUNG'     => $this->getRendererScriptId('iahRenderLueftung', 'LüftungRenderer'),
            'RENDER_GERAETE'      => $this->getRendererScriptId('iahRenderGeraete', 'GeraeteRenderer'),
            'RENDER_BEWAESSERUNG' => $this->getRendererScriptId('iahRenderBewaesserung', 'BewaesserungRenderer'),
            'ROOMS_CATALOG'       => $getVar($settingsCat, 'roomsCatalog', 'RoomsCatalog'),
            'NORMALIZER'          => $getVar($helperCat, 'normalizerScript', 'Normalizer'),
        ];

        $requiredVars = ['CoreHelpers', 'DeviceMap', 'RoomBuilderHelpers', 'DeviceMapWizard', 'Lexikon', 'DEVICE_MAP', 'PENDING_DEVICE', 'PENDING_STAGE', 'DOMAIN_FLAG', 'SKILL_ACTIVE', 'ACTION_VAR', 'DEVICE_VAR', 'ROOM_VAR'];
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

        return [
            'var' => $var,
            'script' => $scripts,
            'missing' => $missing,
            'rendererDomains' => $this->readRendererDomainsProperty(),
            'launchCatalog' => $this->readLaunchCatalogProperty(),
        ];
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

    /**
     * Rebuilds renderer scripts and helper structure manually via the configuration form button.
     */
    public function ManualRefresh(): string
    {
        $this->log('info', 'Manual refresh requested from configuration form');
        $summary = $this->DiagRebind();
        $this->log('info', 'Manual refresh completed', ['summary' => $summary]);

        return 'alles neu aktualisiert';
    }

    private function readRendererDomainsProperty(): array
    {
        $raw = $this->ReadPropertyString('RendererDomains');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }

        $map = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $route = strtolower(trim((string)($entry['route'] ?? '')));
            if ($route === '') {
                continue;
            }
            $base = $this->rendererDomainBase($route);
            $override = $this->rendererDomainDefaultsMap()[$route] ?? [];
            $custom = $this->sanitizeRendererDomainEntry($entry);
            $custom['route'] = $route;
            $map[$route] = array_merge($base, $override, $custom);
        }

        foreach ($this->rendererDomainDefaults() as $def) {
            $route = $def['route'];
            if (!isset($map[$route])) {
                $map[$route] = $def;
            } else {
                $map[$route] = array_merge($def, $map[$route]);
            }
        }

        $claimedDomains = [];
        foreach ($map as $entry) {
            $roomDomain = strtolower((string)($entry['roomDomain'] ?? ''));
            if ($roomDomain === '') {
                $roomDomain = strtolower((string)($entry['route'] ?? ''));
            }
            if ($roomDomain !== '') {
                $claimedDomains[$roomDomain] = true;
            }
        }

        $dynamicRoutes = $this->discoverRoomsCatalogTabDomains();
        foreach ($dynamicRoutes as $route => $entry) {
            $roomDomain = strtolower((string)($entry['roomDomain'] ?? $route));
            if (isset($claimedDomains[$roomDomain])) {
                continue;
            }
            if (!isset($map[$route])) {
                $map[$route] = array_merge($this->rendererDomainBase($route), $entry);
            }
            $claimedDomains[$roomDomain] = true;
        }

        return array_values($map);
    }

    private function sanitizeRendererDomainEntry(array $entry): array
    {
        $out = [];
        $stringFields = ['logName', 'roomDomain', 'title', 'subtitle', 'speechEmpty', 'aplDoc', 'aplToken', 'toggleVarKey'];
        foreach ($stringFields as $key) {
            if (!array_key_exists($key, $entry)) {
                continue;
            }
            $val = trim((string)$entry[$key]);
            if ($val === '') {
                continue;
            }
            $out[$key] = $val;
        }

        return $out;
    }

    private function discoverRoomsCatalogTabDomains(): array
    {
        $settings = $this->getObjectIDByIdentOrName($this->InstanceID, 'iahSettings', 'Einstellungen');
        if ($settings <= 0) {
            return [];
        }

        $scriptId = $this->getObjectIDByIdentOrName($settings, 'roomsCatalog', 'RoomsCatalog');
        if ($scriptId <= 0 || !IPS_ScriptExists($scriptId)) {
            return [];
        }

        $path = IPS_GetScriptFile($scriptId);
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return [];
        }

        try {
            $rooms = @include $path;
        } catch (\Throwable $e) {
            $rooms = null;
        }

        if (!is_array($rooms)) {
            return [];
        }

        return $this->extractRoomsCatalogTabDomains($rooms);
    }

    private function extractRoomsCatalogTabDomains(array $rooms): array
    {
        $routes = [];
    
        foreach ($rooms as $room) {
            if (!is_array($room)) {
                continue;
            }
    
            $domains = (array)($room['domains'] ?? []);
            foreach ($domains as $domainKey => $definition) {
                $tabs = (array)($definition['tabs'] ?? []);
                if ($tabs === []) {
                    // nur Domains mit tabs
                    continue;
                }
    
                $baseDomain = strtolower((string)$domainKey);
                $route      = $this->normalizeRoomsCatalogDomainRoute($baseDomain, $tabs);
                if ($route === '' || isset($routes[$route])) {
                    continue;
                }
    
                $title = $this->inferRoomsCatalogDomainTitle($tabs, $route);
    
                $routes[$route] = [
                    'route'      => $route,
                    'roomDomain' => $baseDomain !== '' ? $baseDomain : $route,
                    'title'      => $title,
                    'logName'    => $title,
                ];
            }
        }
    
        return $routes;
    }

    private function normalizeRoomsCatalogDomainRoute(string $domainKey, array $tabs): string
    {
        $domainKey = strtolower($domainKey);
        $titleSlug = $this->slugifyRoute($this->inferRoomsCatalogDomainTitle($tabs, $domainKey));
        if ($titleSlug !== '') {
            return $titleSlug;
        }

        return $this->slugifyRoute($domainKey);
    }

    private function slugifyRoute(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = strtr($slug, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
        $slug = trim((string)$slug, '-');

        return $slug;
    }

    private function inferRoomsCatalogDomainTitle(array $tabs, string $route): string
    {
        foreach ($tabs as $key => $tab) {
            $title = '';
            if (is_array($tab)) {
                $title = trim((string)($tab['title'] ?? ''));
                if ($title === '') {
                    $title = trim((string)$key);
                }
            } else {
                $title = trim((string)$tab);
                if ($title === '') {
                    $title = trim((string)$key);
                }
            }

            if ($title !== '') {
                return $title;
            }
        }

        return ucfirst($route);
    }

    private function rendererDomainDefaults(): array
    {
        $defaults = [];
        foreach ($this->rendererDomainDefaultsMap() as $route => $override) {
            $defaults[] = array_merge($this->rendererDomainBase($route), $override);
        }

        return $defaults;
    }

    private function rendererDomainDefaultsMap(): array
    {
        return [
            'geraete' => [
                'logName'     => 'Geraete',
                'roomDomain'  => 'devices',
                'title'       => 'Geräte',
                'speechEmpty' => 'Keine Geräte im RoomsCatalog konfiguriert.',
                'aplDoc'      => 'doc://alexa/apl/documents/Geraete',
                'aplToken'    => 'hv-geraete',
            ],
            'bewaesserung' => [
                'logName'     => 'Bewaesserung',
                'roomDomain'  => 'sprinkler',
                'title'       => 'Bewässerung',
                'speechEmpty' => 'Keine Bewässerung im RoomsCatalog konfiguriert.',
                'aplDoc'      => 'doc://alexa/apl/documents/Bewaesserung',
                'aplToken'    => 'hv-bewaesserung',
            ],
        ];
    }

    private function launchCatalogDefaults(): array
    {
        $file = __DIR__ . '/resources/helpers/LaunchCatalogDefaults.php';
        $defaults = @include $file;
        if (is_array($defaults)) {
            return $defaults;
        }

        return [
            'title' => 'HAUS VISUALISIERUNG',
            'subtitleTemplate' => 'Raumname: {{alexa}}',
            'footerText' => 'Tipp eine Kachel an – oder sage z. B. „Jalousie“.',
            'logo' => 'Logo.png',
            'homeIcon' => 'HomeIcon.png',
            'headerIcon' => 'Icon.png',
            'tiles' => [],
        ];
    }

    private function readLaunchCatalogProperty(): array
    {
        $defaults = $this->launchCatalogDefaults();
        $raw = json_decode($this->ReadPropertyString('LaunchCatalog'), true);
        $tiles = [];
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $tile = $this->sanitizeLaunchCatalogEntry($entry);
                if ($tile !== null) {
                    $tiles[] = $tile;
                }
            }
        }
        if ($tiles === []) {
            $tiles = (array)($defaults['tiles'] ?? []);
        }

        $readString = static function (string $value, string $fallback): string {
            $trimmed = trim($value);
            return $trimmed !== '' ? $trimmed : $fallback;
        };

        return [
            'title' => $readString($this->ReadPropertyString('LaunchTitle'), (string) ($defaults['title'] ?? '')),
            'subtitleTemplate' => $readString(
                $this->ReadPropertyString('LaunchSubtitleTemplate'),
                (string) ($defaults['subtitleTemplate'] ?? '')
            ),
            'footerText' => $readString(
                $this->ReadPropertyString('LaunchFooterText'),
                (string) ($defaults['footerText'] ?? '')
            ),
            'logo' => $readString($this->ReadPropertyString('LaunchLogo'), (string) ($defaults['logo'] ?? 'Logo.png')),
            'homeIcon' => $readString(
                $this->ReadPropertyString('LaunchHomeIcon'),
                (string) ($defaults['homeIcon'] ?? 'HomeIcon.png')
            ),
            'headerIcon' => $readString(
                $this->ReadPropertyString('LaunchHeaderIcon'),
                (string) ($defaults['headerIcon'] ?? 'Icon.png')
            ),
            'tiles' => $tiles,
        ];
    }

    private function sanitizeLaunchCatalogEntry(array $entry): ?array
    {
        $id = strtolower(trim((string) ($entry['id'] ?? '')));
        $title = trim((string) ($entry['title'] ?? ''));
        if ($id === '' || $title === '') {
            return null;
        }

        $tile = ['id' => $id, 'title' => $title];
        $subtitle = trim((string) ($entry['subtitle'] ?? ''));
        if ($subtitle !== '') {
            $tile['subtitle'] = $subtitle;
        }

        $icon = trim((string) ($entry['icon'] ?? ''));
        if ($icon !== '') {
            $tile['icon'] = $icon;
        }

        $color = trim((string) ($entry['color'] ?? ''));
        if ($color !== '') {
            if ($color[0] !== '#') {
                $color = '#' . $color;
            }
            if (preg_match('/^#[0-9A-F]{3,6}$/i', $color)) {
                $tile['color'] = strtoupper($color);
            }
        }

        return $tile;
    }

    private function pageMappingDefaults(): array
    {
        return [
            ['key' => 'energie', 'type' => 'wfc_item', 'value' => 'item2124', 'label' => 'Energie'],
            ['key' => 'kamera', 'type' => 'wfc_item', 'value' => 'item9907', 'label' => 'Kamera'],
        ];
    }

    private function readPageMappingsProperty(): array
    {
        $raw = json_decode($this->ReadPropertyString('PageMappings'), true);
        $map = [];
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $parsed = $this->sanitizePageMappingEntry($entry);
                if ($parsed === null) {
                    continue;
                }
                $key = $parsed['key'];
                unset($parsed['key']);
                $map[$key] = $parsed;
            }
        }

        if ($map === []) {
            $fallbacks = [
                'energie' => trim($this->ReadPropertyString('EnergiePageId')),
                'kamera'  => trim($this->ReadPropertyString('KameraPageId')),
            ];
            foreach ($fallbacks as $key => $value) {
                if ($value === '') {
                    continue;
                }
                $map[$key] = [
                    'type'  => 'wfc_item',
                    'value' => $value,
                    'label' => ucfirst($key),
                ];
            }
        }

        if ($map === []) {
            foreach ($this->pageMappingDefaults() as $entry) {
                $parsed = $this->sanitizePageMappingEntry($entry);
                if ($parsed === null) {
                    continue;
                }
                $key = $parsed['key'];
                unset($parsed['key']);
                $map[$key] = $parsed;
            }
        }

        return $map;
    }

    private function sanitizePageMappingEntry(array $entry): ?array
    {
        $key = strtolower(trim((string) ($entry['key'] ?? '')));
        if ($key === '') {
            return null;
        }

        $type = strtolower(trim((string) ($entry['type'] ?? 'wfc_item')));
        if (!in_array($type, ['wfc_item', 'external_url'], true)) {
            $type = 'wfc_item';
        }

        $value = trim((string) ($entry['value'] ?? ''));
        if ($value === '') {
            return null;
        }
        if ($type === 'external_url' && stripos($value, 'https://') !== 0) {
            return null;
        }

        $normalized = [
            'key'   => $key,
            'type'  => $type,
            'value' => $value,
        ];

        $label = trim((string) ($entry['label'] ?? ''));
        if ($label !== '') {
            $normalized['label'] = $label;
        }

        return $normalized;
    }

    private function legacyPageIdFromMappings(array $pageMappings, string $key, string $fallback): string
    {
        $normKey = strtolower($key);
        if (isset($pageMappings[$normKey]) && ($pageMappings[$normKey]['type'] ?? '') === 'wfc_item') {
            $value = trim((string) ($pageMappings[$normKey]['value'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim($fallback);
    }

    private function findDefaultWfcPageId(array $pageMappings): string
    {
        if (isset($pageMappings['energie']) && ($pageMappings['energie']['type'] ?? '') === 'wfc_item') {
            $value = trim((string) ($pageMappings['energie']['value'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach ($pageMappings as $entry) {
            if (($entry['type'] ?? '') !== 'wfc_item') {
                continue;
            }
            $value = trim((string) ($entry['value'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim($this->ReadPropertyString('EnergiePageId'));
    }

    private function deriveStartPage(): string
    {
        $raw = trim($this->ReadPropertyString('StartPage'));
        $wfcId = (int) $this->ReadPropertyInteger('WfcId');

        if ($raw !== '' && !preg_match('/^#?\d+$/', $raw)) {
            return $raw;
        }

        if ($wfcId > 0) {
            return '#' . $wfcId;
        }

        return $raw;
    }

    private function rendererDomainBase(string $route): array
    {
        $normalizedRoute = strtolower($route);
        $title = $normalizedRoute !== '' ? ucfirst($normalizedRoute) : 'Renderer';
        $tokenSlug = preg_replace('/[^a-z0-9]+/i', '', $normalizedRoute);
        if ($tokenSlug === '') {
            $tokenSlug = 'renderer';
        }

        return [
            'route'        => $normalizedRoute,
            'logName'      => $title,
            'roomDomain'   => 'devices',
            'title'        => $title,
            'subtitle'     => 'Steckdosen & mehr',
            'speechEmpty'  => 'Keine Einträge im RoomsCatalog konfiguriert.',
            'aplDoc'       => 'doc://alexa/apl/documents/' . $title,
            'aplToken'     => 'hv-' . $tokenSlug,
            'toggleVarKey' => $normalizedRoute . '_toggle',
        ];
    }
}
