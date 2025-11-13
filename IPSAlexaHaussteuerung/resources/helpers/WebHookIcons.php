<?php
/**
 * ============================================================
 * ALEXA ACTION SCRIPT — WEBHOOK: ICON DELIVERY
 * ============================================================
 *
 * Dieses Skript kann als IP-Symcon WebHook-Target registriert werden
 * (z. B. /hook/alexa-icons) und liefert Dateien aus dem
 * user/icons/-Verzeichnis aus. Der Token muss in der
 * Instanzkonfiguration des Moduls hinterlegt sein und hier als
 * Konstante eingetragen werden.
 */

declare(strict_types=1);

$SECRET = '';
$base   = IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR . 'icons' . DIRECTORY_SEPARATOR;

$bad = static function (int $code): void {
    http_response_code($code);
    exit;
};

try {
    $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
    if ($SECRET === '' || !hash_equals($SECRET, $token)) {
        $bad(403);
    }

    $file = isset($_GET['f']) ? (string)$_GET['f'] : '';
    if ($file === '' && !empty($_SERVER['PATH_INFO'])) {
        $file = ltrim((string)$_SERVER['PATH_INFO'], '/');
    }
    $name = basename($file);
    if ($name === '' || $name[0] === '.') {
        $bad(404);
    }

    $path = $base . $name;
    if (!is_file($path)) {
        $bad(404);
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'html':
        case 'htm':
            $mime = 'text/html; charset=utf-8';
            break;
        case 'css':
            $mime = 'text/css; charset=utf-8';
            break;
        case 'js':
            $mime = 'application/javascript; charset=utf-8';
            break;
        case 'ico':
            $mime = 'image/x-icon';
            break;
        case 'png':
            $mime = 'image/png';
            break;
        case 'jpg':
        case 'jpeg':
            $mime = 'image/jpeg';
            break;
        case 'svg':
            $mime = 'image/svg+xml';
            break;
        case 'gif':
            $mime = 'image/gif';
            break;
        default:
            $mime = 'application/octet-stream';
    }

    $mtime = @filemtime($path) ?: time();
    $etag  = '"' . md5($name . $mtime) . '"';

    header('Content-Type: ' . $mime);
    if ($ext === 'html' || $ext === 'htm') {
        header('Cache-Control: no-store, max-age=0');
    } else {
        header('Cache-Control: public, max-age=31536000, immutable');
    }
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

    $ims = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? (string)$_SERVER['HTTP_IF_MODIFIED_SINCE'] : '';
    if ($ims !== '') {
        $ts = strtotime($ims);
        if ($ts !== false && $ts >= $mtime) {
            http_response_code(304);
            exit;
        }
    }

    $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string)$_SERVER['HTTP_IF_NONE_MATCH'] : '';
    if ($inm !== '' && trim($inm) === $etag) {
        http_response_code(304);
        exit;
    }

    readfile($path);
    exit;
} catch (Throwable $e) {
    IPS_LogMessage('Alexa', 'Webhook Error: ' . $e->getMessage());
    http_response_code(500);
    exit;
}
