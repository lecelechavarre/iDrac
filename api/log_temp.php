
<?php
// api/log_temp.php (placed at: C:\wamp64\www\idrac\api\log_temp.php)
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila'); // adjust as needed

// Read JSON body
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$temp = $payload['temp'] ?? null;
if (!is_numeric($temp)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing or non-numeric temp']);
    exit;
}

// Prepare paths
$baseDir = dirname(__DIR__);           // C:\wamp64\www\idrac
$storageDir = $baseDir . '/storage';   // C:\wamp64\www\idrac\storage
if (!is_dir($storageDir)) {
    // Create storage directory if missing
    mkdir($storageDir, 0775, true);
}

$logFile = $storageDir . '/temperature.log';

// Build log line
$line = sprintf(
    "[%s] temp=%.2f°C, ip=%s%s",
    date('Y-m-d H:i:s'),
    (float)$temp,
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    PHP_EOL
);

// Append with locking to avoid concurrency issues
$ok = false;
$fp = fopen($logFile, 'ab');
if ($fp) {
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, $line);
        fflush($fp);
        flock($fp, LOCK_UN);
        $ok = true;
    }
    fclose($fp);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Cannot open log file']);
    exit;
}

// Respond with success
echo json_encode(['ok' => $ok, 'timestamp' => date('c')]);
