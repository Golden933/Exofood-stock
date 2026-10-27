<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rawurldecode($path ?: '/');

if (preg_match('~(^|/)\.~', $path)
    || preg_match('~^/private(?:/|$)~i', $path)
    || preg_match('~^/data(?:/|$)~i', $path)
    || preg_match('~^/(?:Dockerfile|README\.md|router\.php)$~i', $path)) {
    http_response_code(404);
    exit('Not found');
}

return false;
