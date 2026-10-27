<?php
declare(strict_types=1);

// Exécution uniquement depuis la console ou une tâche interne.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$database = '/app/data/exofood.sqlite';
$required = [
    'R2_ENDPOINT',
    'R2_BUCKET',
    'R2_ACCESS_KEY_ID',
    'R2_SECRET_ACCESS_KEY'
];

foreach ($required as $name) {
    if (!getenv($name)) {
        throw new RuntimeException("Variable manquante : $name");
    }
}

if (!is_file($database)) {
    throw new RuntimeException('Base SQLite introuvable');
}

$temporary = tempnam(sys_get_temp_dir(), 'exofood_');
if ($temporary === false) {
    throw new RuntimeException('Impossible de créer le fichier temporaire');
}

try {
    unlink($temporary);
    $db = new PDO('sqlite:' . $database);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Copie cohérente de la base active.
    $destination = str_replace("'", "''", $temporary);
    $db->exec("VACUUM INTO '$destination'");
    $db = null;

    $backup = new PDO('sqlite:' . $temporary);
    if ($backup->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
        throw new RuntimeException('Sauvegarde SQLite invalide');
    }
    $backup = null;

    $filename = 'hourly/exofood-' . gmdate('Y-m-d_H-i-s') . '.sqlite';
    $endpoint = rtrim(getenv('R2_ENDPOINT'), '/');
    $bucket = getenv('R2_BUCKET');
    $url = $endpoint . '/' . rawurlencode($bucket) . '/' . $filename;

    $file = fopen($temporary, 'rb');
    if ($file === false) {
        throw new RuntimeException('Lecture de la sauvegarde impossible');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $file,
        CURLOPT_INFILESIZE => filesize($temporary),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERPWD => getenv('R2_ACCESS_KEY_ID') . ':' .
            getenv('R2_SECRET_ACCESS_KEY'),
        CURLOPT_HTTPAUTH => CURLAUTH_AWS_SIGV4,
        CURLOPT_AWS_SIGV4 => 'aws:amz:auto:s3',
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream']
    ]);

    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);

    curl_close($curl);
    fclose($file);

    if ($response === false || !in_array($status, [200, 201], true)) {
        throw new RuntimeException(
            "Envoi R2 échoué (HTTP $status) : $error"
        );
    }

    echo "Sauvegarde envoyée : $filename" . PHP_EOL;
} finally {
    if (is_file($temporary)) {
        unlink($temporary);
    }
}
