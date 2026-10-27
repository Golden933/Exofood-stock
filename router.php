<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rawurldecode($path ?: '/');

// Seul le déclencheur de sauvegarde est autorisé
// dans le dossier private. Il vérifie lui-même
// le jeton d'autorisation.
if ($path === '/private/trigger-backup.php') {
    require __DIR__ . '/private/trigger-backup.php';
    return true;
}

// Bloquer les fichiers et dossiers sensibles
if (preg_match('~(^|/)\.\.~', $path)
    || preg_match('~^/private(?:/|$)~i', $path)
    || preg_match('~^/data(?:/|$)~i', $path)
    || preg_match('~^/(?:Dockerfile|README\.md|router\.php)$~i', $path)) {
    http_response_code(404);
    exit('Not found');
}

return false;
