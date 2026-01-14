<?php
// api/log_temp.php - Enhanced logging with IP tracking
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

// Get client IP with better detection
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
      $_SERVER['HTTP_CLIENT_IP'] ?? 
      $_SERVER['REMOTE_ADDR'] ?? 
      'unknown';

// Build enhanced log line
$line = sprintf(
    "[%s] temp=%.2f°C, ip=%s, agent=%s%s",
    date('Y-m-d H:i:s'),
    (float)$temp,
    $ip,
    substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 50),
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

// Also log to main application log
$mainLog = $baseDir . '/idrac_log.csv';
$csvLine = sprintf('%s,%.1f,%s%s', 
    date('Y-m-d H:i:s'), 
    (float)$temp, 
    get_temp_status((float)$temp),
    PHP_EOL
);
@file_put_contents($mainLog, $csvLine, FILE_APPEND | LOCK_EX);

function get_temp_status($temp): string {
    // Load config to get thresholds
    $configFile = dirname(__DIR__) . '/idrac_config.php';
    if (file_exists($configFile)) {
        include $configFile;
        if ($temp >= $CONFIG['critical_temp']) return 'CRITICAL';
        if ($temp >= $CONFIG['warning_temp'])  return 'WARNING';
    }
    return 'NORMAL';
}

// Respond with success
echo json_encode([
    'ok' => $ok, 
    'timestamp' => date('c'),
    'logged_temp' => (float)$temp,
    'source_ip' => $ip
]);
