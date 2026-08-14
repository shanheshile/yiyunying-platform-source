<?php
declare(strict_types=1);

// This script is deployed outside every public web root. Nginx may reach it
// only through an `internal` auth_request FastCGI subrequest.

function finish_verification(int $status): never
{
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    http_response_code($status);
    exit;
}

if (($_SERVER['YY_INTERNAL_VERIFIER'] ?? '') !== '1') {
    finish_verification(401);
}

$originalMethod = $_SERVER['YY_INTERNAL_ORIGINAL_METHOD'] ?? '';
if ($originalMethod !== 'GET' && $originalMethod !== 'HEAD') {
    finish_verification(401);
}

$requestUri = $_SERVER['YY_INTERNAL_ORIGINAL_REQUEST_URI'] ?? '';
if ($requestUri === '' || strlen($requestUri) > 1024 || str_contains($requestUri, "\0")) {
    finish_verification(401);
}
$question = strpos($requestUri, '?');
if ($question === false || strpos($requestUri, '?', $question + 1) !== false) {
    finish_verification(401);
}
$path = substr($requestUri, 0, $question);
$query = substr($requestUri, $question + 1);

$debugVersion = $_SERVER['YY_INTERNAL_DEBUG_VERSION'] ?? '';
if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $debugVersion) !== 1) {
    finish_verification(401);
}
$debugVersionPattern = preg_quote($debugVersion, '~');
$pathPattern = '~^/__internal-apks/(?:debug/' . $debugVersionPattern
    . '/yiyunying-(?:user|admin|authorized-platform|platform-owner)-v' . $debugVersionPattern
    . '-debug\.apk|candidate/1\.0\.0/yiyunying-(?:user|admin|authorized-platform|platform-owner)-v1\.0\.0\.apk)$~D';
if (preg_match($pathPattern, $path) !== 1) {
    finish_verification(401);
}

$parameters = [];
foreach (explode('&', $query) as $part) {
    if (preg_match('/^(sig|expires)=([A-Za-z0-9_-]+)$/D', $part, $match) !== 1) {
        finish_verification(401);
    }
    if (array_key_exists($match[1], $parameters)) {
        finish_verification(401);
    }
    $parameters[$match[1]] = $match[2];
}
if (count($parameters) !== 2
    || !isset($parameters['sig'], $parameters['expires'])
    || preg_match('/^[A-Za-z0-9_-]{43}$/D', $parameters['sig']) !== 1
    || preg_match('/^[1-9][0-9]{9}$/D', $parameters['expires']) !== 1) {
    finish_verification(401);
}

$expires = (int) $parameters['expires'];
$now = time();
if ($expires <= $now) {
    finish_verification(403);
}
// Sites issues a fixed five-minute URL. A small allowance avoids rejecting a
// request whose two servers differ by a few seconds without accepting caller-
// selected long-lived URLs.
if ($expires > $now + 360) {
    finish_verification(401);
}

$secretHex = $_SERVER['YY_INTERNAL_DOWNLOAD_SECRET'] ?? '';
if (preg_match('/^[0-9a-f]{64}$/D', $secretHex) !== 1) {
    finish_verification(401);
}
$secret = hex2bin($secretHex);
if ($secret === false || strlen($secret) !== 32) {
    finish_verification(401);
}

$raw = hash_hmac('sha256', $parameters['expires'] . "\n" . $path, $secret, true);
$expected = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
if (!hash_equals($expected, $parameters['sig'])) {
    finish_verification(401);
}

finish_verification(204);
