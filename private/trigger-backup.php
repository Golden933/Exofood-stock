<?php
declare(strict_types=1);

// Internal endpoint invoked only by the GitHub Actions backup workflow.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
function failBackup(int $status, string $message): never {
    http_response_code($status);
    error_log('ExoFood backup: ' . $message);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    failBackup(405, 'Method not allowed');
}
$token = (string)(getenv('BACKUP_TRIGGER_TOKEN') ?: '');
$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($token === '' || !hash_equals('Bearer ' . $token, $authorization)) {
    failBackup(401, 'Unauthorized');
}
$accessKey = (string)(getenv('R2_ACCESS_KEY_ID') ?: '');
$secretKey = (string)(getenv('R2_SECRET_ACCESS_KEY') ?: '');
$bucket = (string)(getenv('R2_BUCKET') ?: '');
$endpoint = rtrim((string)(getenv('R2_ENDPOINT') ?: ''), '/');
if ($accessKey === '' || $secretKey === '' || $bucket === '' || $endpoint === '') {
    failBackup(503, 'R2 configuration missing');
}
$parts = parse_url($endpoint);
if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
    !preg_match('/^[a-z0-9.-]+\\.r2\\.cloudflarestorage\\.com$/i', (string)$parts['host']) ||
    !preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/i', $bucket)) {
    failBackup(503, 'Invalid R2 endpoint or bucket');
}
$dbFile = dirname(__DIR__) . '/data/exofood.sqlite';
if (!is_file($dbFile)) failBackup(503, 'Database file missing');
$tempFile = dirname(__DIR__) . '/data/.backup-' . bin2hex(random_bytes(8)) . '.sqlite';
try {
    $db = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('VACUUM INTO ' . $db->quote($tempFile));
    $db = null;
    $contents = file_get_contents($tempFile);
    if ($contents === false || strlen($contents) < 100) throw new RuntimeException('Empty SQLite snapshot');
    if (substr($contents, 0, 16) !== "SQLite format 3\\0") throw new RuntimeException('Invalid SQLite snapshot');
    $date = gmdate('Ymd\\THis\\Z');
    $key = 'hourly/exofood-' . $date . '-' . bin2hex(random_bytes(4)) . '.sqlite';
    $uri = '/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode($key));
    $host = (string)$parts['host'];
    $time = gmdate('Ymd\\THis\\Z');
    $shortDate = substr($time, 0, 8);
    $payloadHash = hash('sha256', $contents);
    $headers = "host:$host\\nx-amz-content-sha256:$payloadHash\\nx-amz-date:$time\\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonical = "PUT\\n$uri\\n\\n$headers\\n$signedHeaders\\n$payloadHash";
    $scope = "$shortDate/auto/s3/aws4_request";
    $toSign = "AWS4-HMAC-SHA256\\n$time\\n$scope\\n" . hash('sha256', $canonical);
    $hmac = static fn(string $key, string $data): string => hash_hmac('sha256', $data, $key, true);
    $signingKey = $hmac($hmac($hmac($hmac('AWS4' . $secretKey, $shortDate), 'auto'), 's3'), 'aws4_request');
    $signature = hash_hmac('sha256', $toSign, $signingKey);
    $auth = "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, SignedHeaders=$signedHeaders, Signature=$signature";
    $url = 'https://' . $host . $uri;
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Could not initialize HTTP client');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $contents,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $auth,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $time,
            'Content-Type: application/octet-stream',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($response === false || $status !== 200) {
        throw new RuntimeException('R2 upload failed (HTTP ' . $status . ')' . ($curlError !== '' ? ': ' . $curlError : ''));
    }
    echo json_encode(['ok' => true, 'object' => $key, 'bytes' => strlen($contents)]);
} catch (Throwable $e) {
    failBackup(500, $e->getMessage());
} finally {
    if (is_file($tempFile)) unlink($tempFile);
}
