<?php
// api/log_temp.php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

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
$baseDir = dirname(__DIR__);
$storageDir = $baseDir . '/storage';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0775, true);
}

$logFile = $storageDir . '/temperature.log';

// Get IP address
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Build log line with IP
$line = sprintf(
    "%s,%.1f,%s,%s%s",
    date('Y-m-d H:i:s'),
    (float)$temp,
    get_temp_status_log((float)$temp),
    $ip,
    PHP_EOL
);

// Append with locking
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

// Determine status for logging
function get_temp_status_log($temp) {
    $warning_temp = 25;
    $critical_temp = 30;
    
    if ($temp >= $critical_temp) return 'CRITICAL';
    if ($temp >= $warning_temp) return 'WARNING';
    return 'NORMAL';
}

// Respond with success
echo json_encode(['ok' => $ok, 'timestamp' => date('c'), 'ip' => $ip]);
