<?php
declare(strict_types=1);

namespace IPSAlexaHaussteuerung\Routes;

use IPSAlexaHaussteuerung\Renderers\RenderBewaesserung;
use IPSAlexaHaussteuerung\Renderers\RenderExternal;
use IPSAlexaHaussteuerung\Renderers\RenderGeraete;
use IPSAlexaHaussteuerung\Renderers\RenderHeizung;
use IPSAlexaHaussteuerung\Renderers\RenderJalousie;
use IPSAlexaHaussteuerung\Renderers\RenderLicht;
use IPSAlexaHaussteuerung\Renderers\RenderLueftung;
use IPSAlexaHaussteuerung\Renderers\RenderMain;
use IPSAlexaHaussteuerung\Renderers\RenderSettings;
use IPSAlexaHaussteuerung\Router;

final class RouteAll
{
    public function handle(array $payload, array $cfg): array
    {
        $route = (string) ($payload['route'] ?? 'main_launch');

        switch ($route) {
            case 'heizung':
                return $this->handleHeizung($payload, $cfg);
            case 'jalousie':
                return $this->handleJalousie($payload, $cfg);
            case 'licht':
                return $this->handleLicht($payload, $cfg);
            case 'lueftung':
                return $this->handleLueftung($payload, $cfg);
            case 'geraete':
                return $this->handleGeraete($payload, $cfg);
            case 'bewaesserung':
                return $this->handleBewaesserung($payload, $cfg);
            case 'settings':
                return $this->handleSettings($payload, $cfg);
            case 'external':
                return $this->handleExternal($payload, $cfg);
            case 'main_launch':
                return $this->handleMain($payload, $cfg);
        }

        $dynamic = $this->handleRendererDomainRoute($route, $payload, $cfg);
        if ($dynamic !== null) {
            return $dynamic;
        }

        return $this->handleMain($payload, $cfg);
    }

    private function handleMain(array $payload, array $cfg): array
    {
        $payload = $this->withRendererCfg($payload, $cfg);
        $custom  = (new RenderMain())->process($payload, $cfg);
        if (is_array($custom)) {
            return $custom;
        }

        return $this->baseResponse($payload, $cfg, 'hv-main', 'Main', 'main');
    }

    private function handleHeizung(array $payload, array $cfg): array
    {
        $payload = $this->withRendererCfg($payload, $cfg);
        $custom  = (new RenderHeizung())->process($payload, $cfg);
        if (is_array($custom)) {
            return $custom;
        }

        return $this->baseResponse($payload, $cfg, 'hv-heizung', 'Heizung', 'heizung');
    }

    private function handleJalousie(array $payload, array $cfg): array
    {
        $payload = $this->withRendererCfg($payload, $cfg);
        $custom  = (new RenderJalousie())->process($payload, $cfg);
        if (is_array($custom)) {
            return $custom;
        }

        return $this->baseResponse($payload, $cfg, 'hv-jalousie', 'Jalousie', 'jalousie');
    }

    private function handleLicht(array $payload, array $cfg): array
    {
        $payload = $this->withRendererCfg($payload, $cfg);
        $custom  = (new RenderLicht())->process($payload, $cfg);
        if (is_array($custom)) {
            return $custom;
        }

        return $this->baseResponse($payload, $cfg, 'hv-licht', 'Licht', 'licht');
    }

    private function handleLueftung(array $payload, array $cfg): array
    {
        $payload = $this->withRendererCfg($payload, $cfg);
        $custom  = (new RenderLueftung())->process($payload, $cfg);
        if (is_array($custom)) {
            return $custom;
        }

        return $this->baseResponse($payload, $cfg, 'hv-lueftung', 'Lueftung', 'lueftung');
    }

    private function handleGeraete(array $payload, array $cfg): array
    {
        $payload = $this->withRendererCfg($payload, $cfg);
        $custom  = (new RenderGeraete())->process($payload, $cfg);
        if (is_array($custom)) {
            return $custom;
        }

        return $this->baseResponse($payload, $cfg, 'hv-geraete', 'Geraete', 'geraete');
    }

    private function handleBewaesserung(array $payload, array $cfg): array
    {
        $payload = $this->withRendererCfg($payload, $cfg);
        $custom  = (new RenderBewaesserung())->process($payload, $cfg);
        if (is_array($custom)) {
            return $custom;
        }

        return $this->baseResponse($payload, $cfg, 'hv-bewaesserung', 'Bewasserung', 'bewaesserung');
    }

    private function handleSettings(array $payload, array $cfg): array
    {
        $payload = $this->withRendererCfg($payload, $cfg);
        $custom  = (new RenderSettings())->process($payload, $cfg);
        if (is_array($custom)) {
            return $custom;
        }

        return $this->baseResponse($payload, $cfg, 'hv-settings', 'Settings', 'settings');
    }

    private function handleExternal(array $payload, array $cfg): array
    {
        $payload = $this->withRendererCfg($payload, $cfg);
        $custom  = (new RenderExternal())->process($payload, $cfg);
        if (is_array($custom)) {
            return $custom;
        }

        $res = $this->baseResponse($payload, $cfg, 'hv-external', 'External', 'external');
        if (!empty($payload['externalDirective']) && is_array($payload['externalDirective'])) {
            $res['data']['directives'][] = $payload['externalDirective'];
        }

        return $res;
    }

    private function handleRendererDomainRoute(string $route, array $payload, array $cfg): ?array
    {
        $routeKey = strtolower($route);
        if ($routeKey === '') {
            return null;
        }

        $map = $this->rendererDomainMap($cfg);
        if (!isset($map[$routeKey])) {
            return null;
        }

        $payload  = $this->withRendererCfg($payload, $cfg);
        $renderer = $this->resolveRendererForDomain($map[$routeKey]);
        if ($renderer === null) {
            return null;
        }

        $custom = $renderer->process($payload, $cfg);
        if (is_array($custom)) {
            return $custom;
        }

        return $this->baseResponse($payload, $cfg, 'hv-main', 'Main', 'main');
    }

    private function resolveRendererForDomain(array $domain)
    {
        $roomDomain = strtolower((string) ($domain['roomDomain'] ?? ''));
        if ($roomDomain === 'sprinkler') {
            return new RenderBewaesserung();
        }

        return new RenderGeraete();
    }

    private function rendererDomainMap(array $cfg): array
    {
        $entries = (array) ($cfg['rendererDomains'] ?? []);
        $map     = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $route = strtolower((string) ($entry['route'] ?? ''));
            if ($route === '') {
                continue;
            }

            $map[$route] = $entry;
        }

        return $map;
    }

    private function withRendererCfg(array $payload, array $cfg): array
    {
        if (isset($payload['CFG']) && is_array($payload['CFG'])) {
            return $payload;
        }

        $payload['CFG'] = $this->buildRendererCfg($cfg);

        return $payload;
    }

    private function buildRendererCfg(array $cfg): array
    {
        $var     = is_array($cfg['V'] ?? null) ? $cfg['V'] : [];
        $scripts = is_array($cfg['S'] ?? null) ? $cfg['S'] : [];
        $domains = is_array($cfg['rendererDomains'] ?? null) ? $cfg['rendererDomains'] : [];
        $launch  = is_array($cfg['launchCatalog'] ?? null) ? $cfg['launchCatalog'] : [];
        $actions = is_array($var['ActionsEnabled'] ?? null) ? $var['ActionsEnabled'] : [];

        $flags = [
            'log_basic'  => true,
            'log_verbose' => ((string) ($cfg['LOG_LEVEL'] ?? '') === 'debug'),
            'log_apl_ds' => true,
        ];

        return [
            'var'              => $var,
            'script'           => $scripts,
            'rendererDomains'  => $domains,
            'renderer_domains' => $domains,
            'launchCatalog'    => $launch,
            'actions_vars'     => $actions,
            'baseUrl'          => (string) ($var['BaseUrl'] ?? ''),
            'token'            => (string) ($var['Token'] ?? ''),
            'source'           => (string) ($var['Source'] ?? ''),
            'flags'            => $flags,
        ];
    }

    private function baseResponse(array $payload, array $cfg, string $token, string $aplKey, string $speechKey): array
    {
        $apl    = Router::wrapApl($cfg, $aplKey, $token);
        $speech = $this->renderSpeech($cfg, $speechKey, $payload);

        $data = [
            'speech'     => $speech,
            'reprompt'   => '',
            'card'       => $this->maybeCard($payload),
            'apl'        => $apl,
            'directives' => [],
        ];

        if (!empty($payload['endSession'])) {
            $data['endSession'] = true;
        }

        $flags = (array) ($payload['flags'] ?? []);

        return ['ok' => true, 'aplToken' => $token, 'data' => $data, 'flags' => $flags];
    }

    private function renderSpeech(array $cfg, string $key, array $p): string
    {
        $tpl = (string) ($cfg['speech'][$key] ?? '');
        if ($tpl === '') {
            return '';
        }

        foreach ([
            '{{device}}'  => 'device',
            '{{room}}'    => 'room',
            '{{action}}'  => 'action',
            '{{number}}'  => 'number',
            '{{prozent}}' => 'prozent',
            '{{alexa}}'   => 'alexa',
        ] as $needle => $field) {
            $tpl = str_replace($needle, (string) ($p[$field] ?? ''), $tpl);
        }

        return $tpl;
    }

    private function maybeCard(array $payload): array
    {
        $title = (string) ($payload['cardTitle'] ?? '');
        $text  = (string) ($payload['cardText'] ?? '');
        $small = (string) ($payload['cardSmall'] ?? '');
        $large = (string) ($payload['cardLarge'] ?? ($small !== '' ? $small : ''));

        if ($title === '' && $text === '') {
            return [];
        }

        return [
            'title'         => $title,
            'text'          => $text,
            'smallImageUrl' => $small,
            'largeImageUrl' => $large,
        ];
    }
}
