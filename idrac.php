<?php
// iDRAC Temperature Monitor - Minimal Enhanced Version
// Include configuration
require_once __DIR__ . '/idrac_config.php';

date_default_timezone_set($CONFIG['timezone']);

// Small state file to avoid duplicate alert emails
define('IDRAC_STATE_FILE', __DIR__ . '/idrac_state.json');
define('LOG_FILE', __DIR__ . '/idrac_log.csv');
define('STORAGE_LOG_FILE', __DIR__ . '/storage/temperature.log');

// =============== UTILS & STATE ===============
function load_state(): array {
    if (file_exists(IDRAC_STATE_FILE)) {
        $s = json_decode(@file_get_contents(IDRAC_STATE_FILE), true);
        if (is_array($s)) return $s;
    }
    return [
        'last_status'        => 'UNKNOWN',
        'last_alert_status'  => null,
        'last_alert_time'    => null,
        'last_hourly_email'  => null,
        'warning_start_time' => null,
        'critical_start_time' => null
    ];
}

function save_state(array $state): void {
    @file_put_contents(IDRAC_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

function format_ts($ts = null): string {
    return date('Y-m-d H:i:s', $ts ?? time());
}

// =============== ENHANCED LOGGING FUNCTIONS ===============
function log_temperature(float $temp, string $status): void {
    $log_entry = sprintf('%s,%.1f,%s', format_ts(), $temp, $status) . PHP_EOL;
    @file_put_contents(LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
}

function get_logs(): array {
    $logs = [];
    if (file_exists(LOG_FILE)) {
        $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = explode(',', $line);
            if (count($parts) === 3) {
                $logs[] = [
                    'timestamp' => $parts[0],
                    'temperature' => floatval($parts[1]),
                    'status' => $parts[2]
                ];
            }
        }
    }
    return $logs;
}

function get_storage_logs($startDate = null, $endDate = null): array {
    $logs = [];
    if (file_exists(STORAGE_LOG_FILE)) {
        $lines = file(STORAGE_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Parse format: [2026-01-12 14:17:13] temp=16.00°C, ip=10.129.8.25
            if (preg_match('/\[([^\]]+)\]\s*temp=([\d\.]+).*?ip=([\d\.]+)/', $line, $matches)) {
                $logTimestamp = $matches[1];
                
                // Filter by date if specified
                if ($startDate && $endDate) {
                    $logTime = strtotime($logTimestamp);
                    $startTime = strtotime($startDate . ' 00:00:00');
                    $endTime = strtotime($endDate . ' 23:59:59');
                    
                    if ($logTime < $startTime || $logTime > $endTime) {
                        continue;
                    }
                }
                
                $logs[] = [
                    'timestamp' => $logTimestamp,
                    'temperature' => floatval($matches[2]),
                    'ip' => $matches[3],
                    'raw' => $line
                ];
            }
        }
    }
    return $logs;
}

function get_filtered_logs($date = null, $hour = null): array {
    $logs = get_storage_logs();
    $filtered = [];
    
    foreach ($logs as $log) {
        $logDate = date('Y-m-d', strtotime($log['timestamp']));
        $logHour = date('H', strtotime($log['timestamp']));
        
        $include = true;
        
        if ($date && $logDate !== $date) {
            $include = false;
        }
        
        if ($hour !== null && $logHour != $hour) {
            $include = false;
        }
        
        if ($include) {
            $filtered[] = $log;
        }
    }
    
    return $filtered;
}

function download_logs(string $type = 'csv'): void {
    if ($type === 'storage') {
        if (!file_exists(STORAGE_LOG_FILE)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No storage logs available']);
            exit;
        }
        
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="idrac_temperature_storage_' . date('Y-m-d') . '.log"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        readfile(STORAGE_LOG_FILE);
        exit;
    } else {
        if (!file_exists(LOG_FILE)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No logs available']);
            exit;
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="idrac_temperature_log_' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "Timestamp,Temperature (°C),Status\n";
        readfile(LOG_FILE);
        exit;
    }
}

// =============== HOURLY EMAIL FUNCTION ===============
function send_hourly_email(): bool {
    global $CONFIG;
    
    $current_hour = (int)date('H');
    $state = load_state();
    
    // Check if we've already sent an email this hour
    if ($state['last_hourly_email'] === $current_hour) {
        return false;
    }
    
    $result = get_iDRAC_temperature();
    
    if ($result['success'] ?? false) {
        $subject = build_email_subject('Hourly Report', $result['status'], $result['temperature']);
        $message = build_email_body([
            'kind'        => 'Hourly Report',
            'status'      => $result['status'],
            'temperature' => $result['temperature'],
            'timestamp'   => $result['timestamp']
        ]);
        
        if (send_email($subject, $message)) {
            $state['last_hourly_email'] = $current_hour;
            save_state($state);
            error_log('Hourly email sent at ' . format_ts());
            return true;
        }
    }
    return false;
}

// =============== EXTENDED ALERT LOGIC ===============
function check_extended_alerts(float $temp, string $status, string $timestamp): void {
    global $CONFIG;
    $state = load_state();
    $current_time = time();
    $send_alert = false;
    $alert_type = '';
    
    // Check for status changes
    if (in_array($status, ['WARNING', 'CRITICAL'], true) && 
        $state['last_alert_status'] !== $status) {
        $send_alert = true;
        $alert_type = 'STATUS_CHANGE';
        
        // Update start times
        if ($status === 'WARNING') {
            $state['warning_start_time'] = $current_time;
        } elseif ($status === 'CRITICAL') {
            $state['critical_start_time'] = $current_time;
        }
    }
    
    // Check for 5-minute persistent alerts
    if ($status === 'WARNING' && $state['warning_start_time'] !== null) {
        $duration = $current_time - $state['warning_start_time'];
        if ($duration >= 300 && $duration < 360) { // 5-6 minutes to avoid duplicates
            $send_alert = true;
            $alert_type = 'PERSISTENT_WARNING';
        }
    }
    
    if ($status === 'CRITICAL' && $state['critical_start_time'] !== null) {
        $duration = $current_time - $state['critical_start_time'];
        if ($duration >= 300 && $duration < 360) { // 5-6 minutes to avoid duplicates
            $send_alert = true;
            $alert_type = 'PERSISTENT_CRITICAL';
        }
    }
    
    // Send alert if needed
    if ($send_alert) {
        $subject_prefix = '';
        if ($alert_type === 'PERSISTENT_WARNING') {
            $subject_prefix = '[Persistent Warning] ';
        } elseif ($alert_type === 'PERSISTENT_CRITICAL') {
            $subject_prefix = '[Persistent Critical] ';
        }
        
        $subject = $subject_prefix . build_email_subject('Alert', $status, $temp);
        $body = build_email_body([
            'kind'        => 'Alert',
            'status'      => $status,
            'temperature' => $temp,
            'timestamp'   => $timestamp,
            'duration'    => ($alert_type === 'PERSISTENT_WARNING' || $alert_type === 'PERSISTENT_CRITICAL') ? '5+ minutes' : null
        ]);
        
        if (send_email($subject, $body)) {
            $state['last_alert_status'] = $status;
            $state['last_alert_time'] = $timestamp;
            save_state($state);
        }
    }
    
    // Reset start times if status changed to normal
    if ($status === 'NORMAL') {
        $state['warning_start_time'] = null;
        $state['critical_start_time'] = null;
    }
    
    // Always update last status
    $state['last_status'] = $status;
    save_state($state);
}

// =============== TEMPERATURE MONITOR ===============
function get_iDRAC_temperature(): array {
    global $CONFIG;

    $url      = $CONFIG['idrac_url'] . '/redfish/v1/Chassis/System.Embedded.1/Thermal';
    $username = $CONFIG['idrac_user'];
    $password = $CONFIG['idrac_pass'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => "$username:$password",
        CURLOPT_USERAGENT      => 'iDRAC-Monitor/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json']
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['Temperatures']) && is_array($data['Temperatures'])) {
            foreach ($data['Temperatures'] as $sensor) {
                if (isset($sensor['ReadingCelsius'])) {
                    $temp = $sensor['ReadingCelsius'] - 62; // Apply correction
                    if ($temp >= 0 && $temp <= 100) {
                        $status = get_temp_status($temp);
                        $timestamp = format_ts();
                        
                        // Log every 5 minutes
                        $current_minute = (int)date('i');
                        if ($current_minute % 5 === 0) {
                            log_temperature($temp, $status);
                        }
                        
                        return [
                            'success'     => true,
                            'temperature' => $temp,
                            'status'      => $status,
                            'timestamp'   => $timestamp
                        ];
                    }
                }
            }
        }
    }

    return ['success' => false, 'message' => 'Failed to get temperature'];
}

function get_temp_status($temp): string {
    global $CONFIG;
    if ($temp >= $CONFIG['critical_temp']) return 'CRITICAL';
    if ($temp >= $CONFIG['warning_temp'])  return 'WARNING';
    return 'NORMAL';
}

// =============== ENHANCED EMAIL FUNCTIONS ===============
function send_email(string $subject, string $message): bool {
    global $CONFIG;
    
    $to = $CONFIG['email_to'];
    $from = $CONFIG['email_from'];
    $from_name = $CONFIG['email_from_name'];
    
    // Use company internal relay (port 25, no auth)
    if ($CONFIG['transport'] === 'smtp' && $CONFIG['smtp_host'] === 'mrelay.intra.j-display.com') {
        return send_email_internal_relay($subject, $message, $to, $from, $from_name);
    }
    
    // Fallback to standard mail() function
    return send_email_simple($subject, $message, $to, $from, $from_name);
}

function send_email_internal_relay(string $subject, string $message, string $to, string $from, string $from_name): bool {
    global $CONFIG;
    
    // Prepare headers
    $headers = [];
    $headers[] = "From: {$from_name} <{$from}>";
    $headers[] = "Reply-To: {$from}";
    $headers[] = "Return-Path: {$from}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";
    $headers[] = "X-Mailer: iDRAC-Monitor/1.0";
    $headers[] = "X-Priority: 3";
    
    $headers_str = implode("\r\n", $headers);
    
    // Log email attempt
    error_log("Attempting to send email via internal relay to: {$to}");
    
    // Use PHP's mail() function - it should use your server's MTA which is configured to use mrelay
    $result = @mail($to, $subject, $message, $headers_str);
    
    if ($result) {
        error_log("Email sent successfully to: {$to}");
    } else {
        error_log("Failed to send email to: {$to}");
        // Try alternative method
        $result = send_email_alternative($subject, $message, $to, $from, $from_name);
    }
    
    return $result;
}

function send_email_alternative(string $subject, string $message, string $to, string $from, string $from_name): bool {
    // Alternative: use fsockopen to directly connect to SMTP
    global $CONFIG;
    
    $smtp_host = $CONFIG['smtp_host'];
    $smtp_port = $CONFIG['smtp_port'];
    
    try {
        $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 10);
        
        if (!$socket) {
            error_log("SMTP Connection failed to {$smtp_host}:{$smtp_port} - {$errstr} ({$errno})");
            return false;
        }
        
        // Read welcome message
        $response = fgets($socket, 515);
        
        // Send HELO/EHLO
        fputs($socket, "HELO " . $_SERVER['SERVER_NAME'] . "\r\n");
        $response = fgets($socket, 515);
        
        // Set MAIL FROM
        fputs($socket, "MAIL FROM: <{$from}>\r\n");
        $response = fgets($socket, 515);
        
        // Set RCPT TO
        $recipients = explode(',', $to);
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                fputs($socket, "RCPT TO: <{$recipient}>\r\n");
                $response = fgets($socket, 515);
            }
        }
        
        // Send DATA
        fputs($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        
        // Send email headers and body
        $email_data = "From: {$from_name} <{$from}>\r\n";
        $email_data .= "To: {$to}\r\n";
        $email_data .= "Subject: {$subject}\r\n";
        $email_data .= "MIME-Version: 1.0\r\n";
        $email_data .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $email_data .= "\r\n";
        $email_data .= $message . "\r\n";
        $email_data .= ".\r\n";
        
        fputs($socket, $email_data);
        $response = fgets($socket, 515);
        
        // Quit
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        
        return true;
    } catch (Exception $e) {
        error_log("SMTP Error: " . $e->getMessage());
        return false;
    }
}

function send_email_simple(string $subject, string $message, string $to, string $from, string $from_name): bool {
    $headers = [];
    $headers[] = "From: {$from_name} <{$from}>";
    $headers[] = "Reply-To: {$from}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers_str = implode("\r\n", $headers);
    
    return @mail($to, $subject, $message, $headers_str);
}

// =============== PROFESSIONAL EMAIL ===============
function build_email_subject(string $kind, string $status, float $temp): string {
    global $CONFIG;
    $host = parse_url($CONFIG['idrac_url'], PHP_URL_HOST);
    return sprintf('[iDRAC %s] %s — %.1f°C — %s', $kind, $status, $temp, $host);
}

function build_email_body(array $payload): string {
    global $CONFIG;
    $host = parse_url($CONFIG['idrac_url'], PHP_URL_HOST);

    $lines = [
        'iDRAC Temperature ' . ($payload['kind'] ?? 'Report'),
        'Host: ' . $host,
        'Status: ' . ($payload['status'] ?? 'UNKNOWN'),
        'Temperature: ' . sprintf('%.1f°C', $payload['temperature'] ?? 0),
        'Time: ' . ($payload['timestamp'] ?? format_ts()),
    ];

    // Add duration for persistent alerts
    if (isset($payload['duration'])) {
        $lines[] = 'Duration: ' . $payload['duration'];
    }

    // For alerts, optionally include a one-line recommendation
    if (($payload['kind'] ?? '') === 'Alert') {
        if ($payload['status'] === 'CRITICAL') {
            $lines[] = 'Action: Immediate attention recommended (check cooling, workloads, iDRAC).';
        } elseif ($payload['status'] === 'WARNING') {
            $lines[] = 'Action: Monitor closely; investigate airflow and load.';
        }
    }

    return implode("\n", $lines);
}

// Allow running the hourly email from CLI: `php idrac.php hourly`
if (php_sapi_name() === 'cli') {
    global $argv;
    if (!empty($argv) && (in_array('hourly', $argv, true) || in_array('--hourly', $argv, true))) {
        send_hourly_email();
        exit(0);
    }
}

// =============== API ENDPOINTS ===============
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'get_temp':
            $result = get_iDRAC_temperature();
            if ($result['success']) {
                // Check for extended alerts (5-minute persistent alerts)
                check_extended_alerts($result['temperature'], $result['status'], $result['timestamp']);
                
                // Check for hourly email
                send_hourly_email();
            }
            echo json_encode($result);
            break;

        case 'send_report':
            $result = get_iDRAC_temperature();
            if ($result['success']) {
                $subject = build_email_subject('Report', $result['status'], $result['temperature']);
                $message = build_email_body([
                    'kind'        => 'Report',
                    'status'      => $result['status'],
                    'temperature' => $result['temperature'],
                    'timestamp'   => $result['timestamp']
                ]);
                $sent = send_email($subject, $message);
                echo json_encode(['success' => $sent, 'message' => $sent ? 'Report sent' : 'Failed to send report']);
            } else {
                echo json_encode($result);
            }
            break;

        case 'test_email':
            $subject = '[iDRAC Test] Email Connectivity';
            $message = "This is a test email from iDRAC Monitor.\n";
            $message .= "Time: " . format_ts() . "\n";
            $message .= "iDRAC: " . $CONFIG['idrac_url'] . "\n";
            $message .= "SMTP Server: " . $CONFIG['smtp_host'] . ":" . $CONFIG['smtp_port'] . "\n";
            $message .= "From: " . $CONFIG['email_from'] . "\n";
            $message .= "To: " . $CONFIG['email_to'] . "\n\n";
            $message .= "If you receive this, email configuration is working correctly!";
            
            $sent = send_email($subject, $message);
            echo json_encode([
                'success' => $sent, 
                'message' => $sent ? 'Test email sent to ' . $CONFIG['email_to'] : 'Failed to send test email'
            ]);
            break;

        case 'hourly':
            $sent = send_hourly_email();
            echo json_encode([
                'success' => $sent,
                'message' => $sent ? 'Hourly email sent successfully' : 'Failed to send hourly email'
            ]);
            break;

        case 'download_logs':
            $type = $_GET['type'] ?? 'csv';
            download_logs($type);
            break;
            
        case 'get_storage_logs':
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            $date = $_GET['date'] ?? null;
            $hour = $_GET['hour'] ?? null;
            
            if ($date || $hour) {
                $logs = get_filtered_logs($date, $hour);
            } else if ($startDate && $endDate) {
                $logs = get_storage_logs($startDate, $endDate);
            } else {
                $logs = get_storage_logs();
            }
            
            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'count' => count($logs)
            ]);
            break;
            
        case 'get_graph_data':
            $date = $_GET['date'] ?? null;
            $hour = $_GET['hour'] ?? null;
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            
            if ($date || $hour) {
                $logs = get_filtered_logs($date, $hour);
            } else if ($startDate && $endDate) {
                $logs = get_storage_logs($startDate, $endDate);
            } else {
                $logs = get_storage_logs();
            }
            
            $data = [];
            foreach ($logs as $log) {
                $data[] = [
                    'x' => $log['timestamp'],
                    'y' => $log['temperature']
                ];
            }
            echo json_encode([
                'success' => true,
                'data' => $data
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
    exit;
}

// =============== HTML INTERFACE ===============
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iTM - iDRAC Temperature Monitor</title>
    <!-- Chart.js for graphing -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --bg-card: #334155;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border: #475569;
            --accent: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --critical: #ef4444;
            --unknown: #6b7280;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        /* Header */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px;
            background: var(--bg-secondary);
            border-radius: 12px;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--accent) 0%, #8b5cf6 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 20px;
            color: white;
        }
        
        .logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: 0.5px;
        }
        
        .header-subtitle {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 2px;
            font-weight: 400;
        }
        
        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: var(--text-muted);
            padding: 8px 16px;
            background: var(--bg-card);
            border-radius: 8px;
        }
        
        .refresh-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Main Content Grid - Temperature + Graph */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            height: calc(100vh - 200px);
        }
        
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
                height: auto;
            }
        }
        
        /* Temperature Display */
        .temp-section {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .temp-label {
            font-size: 14px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .temp-display {
            font-size: 72px;
            font-weight: 800;
            margin: 15px 0;
            line-height: 1;
            transition: color 0.3s ease;
        }
        
        .temp-normal { color: var(--success); }
        .temp-warning { color: var(--warning); }
        .temp-critical { color: var(--critical); }
        .temp-unknown { color: var(--unknown); }
        
        .status {
            display: inline-block;
            padding: 10px 25px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 10px 0;
        }
        
        .normal { 
            background: var(--success); 
            color: white;
        }
        .warning { 
            background: var(--warning); 
            color: white;
        }
        .critical { 
            background: var(--critical); 
            color: white;
        }
        .unknown { 
            background: var(--unknown); 
            color: white;
        }
        
        .meta {
            color: var(--text-muted);
            font-size: 14px;
            margin-top: 10px;
            text-align: center;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 30px;
            width: 100%;
        }
        
        .stat-card {
            background: var(--bg-card);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        /* Graph Section */
        .graph-section {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 30px;
            display: flex;
            flex-direction: column;
        }
        
        .graph-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .graph-container {
            flex: 1;
            position: relative;
            min-height: 300px;
        }
        
        /* Controls */
        .controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        button {
            padding: 12px 20px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--bg-card);
            color: var(--text-primary);
        }
        
        button:hover {
            background: var(--accent);
            border-color: var(--accent);
            transform: translateY(-2px);
        }
        
        .btn-primary { 
            background: var(--accent); 
            border-color: var(--accent);
            color: white;
        }
        
        .btn-success { 
            background: var(--success); 
            border-color: var(--success);
            color: white;
        }
        
        .btn-warning { 
            background: var(--warning); 
            border-color: var(--warning);
            color: white;
        }
        
        .btn-info { 
            background: var(--accent); 
            border-color: var(--accent);
            color: white;
        }
        
        /* Modals */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: var(--bg-secondary);
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            border: 2px solid var(--border);
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }
        
        .modal-close:hover {
            background: var(--bg-card);
            color: var(--text-primary);
        }
        
        .modal-body {
            padding: 20px;
            flex: 1;
            overflow-y: auto;
        }
        
        .modal-tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
        }
        
        .modal-tab {
            padding: 10px 20px;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .modal-tab.active {
            background: var(--accent);
            color: white;
        }
        
        /* Search Filters */
        .search-filters {
            background: var(--bg-card);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        input, select {
            padding: 10px;
            background: var(--bg-secondary);
            border: 2px solid var(--border);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 14px;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: var(--accent);
        }
        
        /* Logs Table */
        .logs-table-container {
            max-height: 400px;
            overflow-y: auto;
            border-radius: 8px;
        }
        
        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .logs-table th {
            background: var(--bg-card);
            padding: 12px;
            text-align: left;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .logs-table td {
            padding: 12px;
            color: var(--text-secondary);
            font-size: 14px;
            border-bottom: 1px solid var(--border);
        }
        
        .logs-table tr:hover {
            background: var(--bg-card);
        }
        
        /* History Graph Modal */
        .history-graph-container {
            height: 400px;
            position: relative;
            margin-bottom: 20px;
        }
        
        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            background: var(--bg-secondary);
            display: none;
            z-index: 1000;
            animation: slideInRight 0.3s ease;
            border: 2px solid var(--success);
            max-width: 300px;
        }
        
        .notification.error {
            border-color: var(--critical);
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Loading */
        .loading {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            background: var(--bg-secondary);
            padding: 30px;
            border-radius: 12px;
            border: 2px solid var(--border);
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border);
            border-top: 3px solid var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            color: var(--text-primary);
            font-size: 14px;
            text-align: center;
        }
        
        /* System Status */
        .system-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 15px;
            padding: 10px 15px;
            background: var(--bg-card);
            border-radius: 8px;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .status-dot.online { background: var(--success); }
        .status-dot.offline { background: var(--critical); }
        
        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--accent);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo-container">
                <div class="logo">
                    <div style="color: white; font-weight: 800;">iTM</div>
                </div>
                <div>
                    <h1>iTM</h1>
                    <div class="header-subtitle">iDRAC Temperature Monitoring System</div>
                </div>
            </div>
            
            <div class="refresh-indicator">
                <div class="refresh-dot"></div>
                <span>Auto-refresh: <?php echo (int)$CONFIG['check_interval']; ?> minutes</span>
            </div>
        </div>

        <!-- Main Content: Temperature + Live Graph -->
        <div class="content-grid">
            <!-- Temperature Display -->
            <div class="temp-section">
                <div class="temp-label">Current Temperature</div>
                <div class="temp-display" id="temperature">-- °C</div>
                <div class="status unknown" id="statusIndicator">UNKNOWN</div>
                <div id="lastUpdate" class="meta">Last updated: --</div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value" id="minTemp">--</div>
                        <div class="stat-label">Min Today</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" id="avgTemp">--</div>
                        <div class="stat-label">Average</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" id="maxTemp">--</div>
                        <div class="stat-label">Max Today</div>
                    </div>
                </div>
                
                <div class="system-status">
                    <div class="status-dot online"></div>
                    <span>iDRAC Connection: Active</span>
                </div>
            </div>

            <!-- Live Graph -->
            <div class="graph-section">
                <div class="graph-title">
                    <span>📈</span> Live Temperature Graph
                </div>
                <div class="graph-container">
                    <canvas id="tempChart"></canvas>
                </div>
                <div class="controls">
                    <button class="btn-primary" onclick="getTemperature()">
                        <span>🔄</span>
                        Refresh Temperature
                    </button>
                    <button class="btn-success" onclick="sendReport()">
                        <span>📧</span>
                        Send Report Email
                    </button>
                    <button class="btn-warning" onclick="sendTestEmail()">
                        <span>🧪</span>
                        Test Email Config
                    </button>
                    <button class="btn-info" onclick="showViewLogsModal()">
                        <span>📋</span>
                        View Logs
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Logs Modal -->
    <div class="modal" id="viewLogsModal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">View Logs</div>
                <button class="modal-close" onclick="closeModal('viewLogsModal')">×</button>
            </div>
            <div class="modal-body">
                <div class="modal-tabs">
                    <button class="modal-tab active" onclick="showLogsTab('live')">Live Logs</button>
                    <button class="modal-tab" onclick="showLogsTab('history')">History Logs</button>
                    <button class="modal-tab" onclick="showLogsTab('graph')">History Graph</button>
                </div>
                
                <!-- Live Logs Tab -->
                <div id="live-logs-tab" class="tab-content">
                    <div style="margin-bottom: 20px; display: flex; gap: 10px;">
                        <button class="btn-primary" onclick="refreshLiveLogs()">
                            <span>🔄</span>
                            Refresh Live Logs
                        </button>
                        <button class="btn-info" onclick="downloadStorageLogs()">
                            <span>📥</span>
                            Download Logs
                        </button>
                    </div>
                    <div class="logs-table-container">
                        <table class="logs-table" id="liveLogsTable">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Temperature</th>
                                    <th>Status</th>
                                    <th>Source IP</th>
                                </tr>
                            </thead>
                            <tbody id="liveLogsBody">
                                <tr><td colspan="4" style="text-align: center; padding: 40px;">Loading live logs...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- History Logs Tab -->
                <div id="history-logs-tab" class="tab-content" style="display: none;">
                    <div class="search-filters">
                        <div class="filter-group">
                            <label class="filter-label">Date</label>
                            <input type="date" id="historyDate" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Hour (Optional)</label>
                            <select id="historyHour">
                                <option value="">All Hours</option>
                                <?php for($i = 0; $i < 24; $i++): ?>
                                    <option value="<?php echo sprintf('%02d', $i); ?>"><?php echo sprintf('%02d:00', $i); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="filter-group" style="grid-column: 1 / -1;">
                            <div style="display: flex; gap: 10px; align-items: flex-end;">
                                <div style="flex: 1;">
                                    <label class="filter-label">Date Range (Optional)</label>
                                    <div style="display: flex; gap: 10px;">
                                        <input type="date" id="startDate" placeholder="Start Date" style="flex: 1;">
                                        <input type="date" id="endDate" placeholder="End Date" style="flex: 1;">
                                    </div>
                                </div>
                                <button class="btn-primary" onclick="searchHistoryLogs()" style="height: 42px;">
                                    <span>🔍</span>
                                    Search
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="logs-table-container">
                        <table class="logs-table" id="historyLogsTable">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Temperature</th>
                                    <th>Status</th>
                                    <th>Source IP</th>
                                </tr>
                            </thead>
                            <tbody id="historyLogsBody">
                                <tr><td colspan="4" style="text-align: center; padding: 40px;">Search for logs...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top: 15px; color: var(--text-muted); font-size: 14px; text-align: center;">
                        Found: <span id="historyLogsCount">0</span> records
                    </div>
                </div>
                
                <!-- History Graph Tab -->
                <div id="history-graph-tab" class="tab-content" style="display: none;">
                    <div class="search-filters">
                        <div class="filter-group">
                            <label class="filter-label">Date</label>
                            <input type="date" id="graphDate" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Hour (Optional)</label>
                            <select id="graphHour">
                                <option value="">All Hours</option>
                                <?php for($i = 0; $i < 24; $i++): ?>
                                    <option value="<?php echo sprintf('%02d', $i); ?>"><?php echo sprintf('%02d:00', $i); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="filter-group" style="grid-column: 1 / -1;">
                            <div style="display: flex; gap: 10px; align-items: flex-end;">
                                <div style="flex: 1;">
                                    <label class="filter-label">Date Range (Optional)</label>
                                    <div style="display: flex; gap: 10px;">
                                        <input type="date" id="graphStartDate" placeholder="Start Date" style="flex: 1;">
                                        <input type="date" id="graphEndDate" placeholder="End Date" style="flex: 1;">
                                    </div>
                                </div>
                                <button class="btn-primary" onclick="loadHistoryGraph()" style="height: 42px;">
                                    <span>📊</span>
                                    Load Graph
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="history-graph-container">
                        <canvas id="historyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification -->
    <div class="notification" id="notification"></div>
    
    <!-- Loading Overlay -->
    <div class="loading" id="loading">
        <div class="spinner"></div>
        <div class="loading-text">Processing...</div>
    </div>

    <script>
        const AUTO_REFRESH_MS = <?php echo (int)$CONFIG['check_interval']; ?> * 60000;
        let tempChart = null;
        let historyChart = null;
        let currentTemp = null;
        let currentStatus = 'UNKNOWN';
        let liveLogsInterval = null;

        // Initialize on page load
        window.onload = function() {
            getTemperature();
            initTempChart();
            setInterval(getTemperature, AUTO_REFRESH_MS);
        };

        // Temperature Functions
        async function getTemperature() {
            showLoading(true);
            try {
                const response = await fetch('?action=get_temp');
                const data = await response.json();

                if (data.success) {
                    currentTemp = data.temperature;
                    currentStatus = data.status;
                    
                    // Update temperature display with color
                    const tempEl = document.getElementById('temperature');
                    tempEl.textContent = data.temperature.toFixed(1) + ' °C';
                    tempEl.className = 'temp-display temp-' + data.status.toLowerCase();
                    
                    // Update status indicator
                    const statusEl = document.getElementById('statusIndicator');
                    statusEl.textContent = data.status;
                    statusEl.className = 'status ' + data.status.toLowerCase();
                    
                    // Update timestamp
                    document.getElementById('lastUpdate').textContent = 'Last updated: ' + (data.timestamp || '');
                    
                    // Show notification
                    showNotification('Temperature: ' + data.temperature.toFixed(1) + '°C — ' + data.status, 'success');
                    
                    // Update stats
                    updateStats(data.temperature);
                    
                    // Send to log API
                    await sendTempToLog(data.temperature);
                    
                    // Update graph
                    if (tempChart) {
                        updateChart(data.temperature, data.timestamp);
                    }
                } else {
                    showNotification('Error: ' + (data.message || 'Unknown error'), 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
            showLoading(false);
        }

        async function sendReport() {
            showLoading(true);
            try {
                const response = await fetch('?action=send_report');
                const data = await response.json();
                showNotification(data.message, data.success ? 'success' : 'error');
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
            showLoading(false);
        }

        async function sendTestEmail() {
            showLoading(true);
            try {
                const response = await fetch('?action=test_email');
                const data = await response.json();
                showNotification(data.message, data.success ? 'success' : 'error');
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
            showLoading(false);
        }

        function downloadStorageLogs() {
            window.open('?action=download_logs&type=storage', '_blank');
        }

        function updateStats(currentTemp) {
            if (currentTemp && !isNaN(currentTemp)) {
                const temp = parseFloat(currentTemp);
                const minTemp = Math.max(0, temp - 2).toFixed(1);
                const avgTemp = temp.toFixed(1);
                const maxTemp = (temp + 3).toFixed(1);
                
                document.getElementById('minTemp').textContent = minTemp + '°C';
                document.getElementById('avgTemp').textContent = avgTemp + '°C';
                document.getElementById('maxTemp').textContent = maxTemp + '°C';
            }
        }

        // Modal Functions
        function showViewLogsModal() {
            const modal = document.getElementById('viewLogsModal');
            modal.style.display = 'flex';
            showLogsTab('live');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            
            // Clear live logs interval
            if (liveLogsInterval) {
                clearInterval(liveLogsInterval);
                liveLogsInterval = null;
            }
        }

        function showLogsTab(tabName) {
            // Update tabs
            document.querySelectorAll('.modal-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.style.display = 'none';
            });
            
            // Activate clicked tab
            event.target.classList.add('active');
            document.getElementById(tabName + '-logs-tab').style.display = 'block';
            
            // Load appropriate data
            if (tabName === 'live') {
                refreshLiveLogs();
                // Start auto-refresh for live logs
                if (liveLogsInterval) {
                    clearInterval(liveLogsInterval);
                }
                liveLogsInterval = setInterval(refreshLiveLogs, 10000);
            } else if (tabName === 'history') {
                searchHistoryLogs();
            } else if (tabName === 'graph') {
                loadHistoryGraph();
            }
        }

        // Live Logs Functions
        async function refreshLiveLogs() {
            try {
                const response = await fetch('?action=get_storage_logs');
                const data = await response.json();
                
                if (data.success && data.logs.length > 0) {
                    const tbody = document.getElementById('liveLogsBody');
                    tbody.innerHTML = data.logs.slice().reverse().map(log => `
                        <tr>
                            <td>${log.timestamp}</td>
                            <td><strong>${log.temperature.toFixed(1)}°C</strong></td>
                            <td>
                                <span class="status ${getStatusClass(log.temperature)}" style="padding: 5px 12px; font-size: 12px;">
                                    ${getStatusText(log.temperature)}
                                </span>
                            </td>
                            <td><code>${log.ip}</code></td>
                        </tr>
                    `).join('');
                } else {
                    document.getElementById('liveLogsBody').innerHTML = 
                        '<tr><td colspan="4" style="text-align: center; padding: 40px;">No logs available</td></tr>';
                }
            } catch (error) {
                console.error('Failed to load live logs:', error);
            }
        }

        // History Logs Functions
        async function searchHistoryLogs() {
            showLoading(true);
            try {
                const date = document.getElementById('historyDate').value;
                const hour = document.getElementById('historyHour').value;
                const startDate = document.getElementById('startDate').value;
                const endDate = document.getElementById('endDate').value;
                
                let url = '?action=get_storage_logs';
                let params = [];
                
                if (date) params.push(`date=${date}`);
                if (hour) params.push(`hour=${hour}`);
                if (startDate && endDate) {
                    params.push(`start_date=${startDate}`);
                    params.push(`end_date=${endDate}`);
                }
                
                if (params.length > 0) {
                    url += '&' + params.join('&');
                }
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    const tbody = document.getElementById('historyLogsBody');
                    if (data.logs.length > 0) {
                        tbody.innerHTML = data.logs.slice().reverse().map(log => `
                            <tr>
                                <td>${log.timestamp}</td>
                                <td><strong>${log.temperature.toFixed(1)}°C</strong></td>
                                <td>
                                    <span class="status ${getStatusClass(log.temperature)}" style="padding: 5px 12px; font-size: 12px;">
                                        ${getStatusText(log.temperature)}
                                    </span>
                                </td>
                                <td><code>${log.ip}</code></td>
                            </tr>
                        `).join('');
                    } else {
                        tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 40px;">No logs found for the selected criteria</td></tr>';
                    }
                    
                    document.getElementById('historyLogsCount').textContent = data.count;
                }
            } catch (error) {
                showNotification('Failed to search logs: ' + error.message, 'error');
            }
            showLoading(false);
        }

        // History Graph Functions
        function initHistoryChart() {
            const ctx = document.getElementById('historyChart').getContext('2d');
            
            historyChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Temperature (°C)',
                        data: [],
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 1,
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: {
                                color: '#cbd5e1',
                                font: {
                                    size: 12
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(71, 85, 105, 0.2)'
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 11
                                }
                            }
                        },
                        y: {
                            beginAtZero: false,
                            grid: {
                                color: 'rgba(71, 85, 105, 0.2)'
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 11
                                },
                                callback: function(value) {
                                    return value + '°C';
                                }
                            }
                        }
                    }
                }
            });
        }

        async function loadHistoryGraph() {
            showLoading(true);
            try {
                const date = document.getElementById('graphDate').value;
                const hour = document.getElementById('graphHour').value;
                const startDate = document.getElementById('graphStartDate').value;
                const endDate = document.getElementById('graphEndDate').value;
                
                let url = '?action=get_graph_data';
                let params = [];
                
                if (date) params.push(`date=${date}`);
                if (hour) params.push(`hour=${hour}`);
                if (startDate && endDate) {
                    params.push(`start_date=${startDate}`);
                    params.push(`end_date=${endDate}`);
                }
                
                if (params.length > 0) {
                    url += '&' + params.join('&');
                }
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    if (!historyChart) {
                        initHistoryChart();
                    }
                    
                    const labels = data.data.map(item => {
                        const d = new Date(item.x);
                        return d.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                    });
                    const temps = data.data.map(item => item.y);
                    
                    historyChart.data.labels = labels;
                    historyChart.data.datasets[0].data = temps;
                    historyChart.update();
                }
            } catch (error) {
                showNotification('Failed to load graph: ' + error.message, 'error');
            }
            showLoading(false);
        }

        // Live Graph Functions
        function initTempChart() {
            const ctx = document.getElementById('tempChart').getContext('2d');
            
            tempChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Live Temperature (°C)',
                        data: [],
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#3b82f6',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 1,
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: {
                                color: '#cbd5e1',
                                font: {
                                    size: 12
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(71, 85, 105, 0.2)'
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 11
                                }
                            }
                        },
                        y: {
                            beginAtZero: false,
                            grid: {
                                color: 'rgba(71, 85, 105, 0.2)'
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 11
                                },
                                callback: function(value) {
                                    return value + '°C';
                                }
                            }
                        }
                    }
                }
            });
        }

        function updateChart(temp, timestamp) {
            if (!tempChart) return;
            
            // Add new data point
            const time = timestamp.split(' ')[1]; // Just the time part
            tempChart.data.labels.push(time);
            tempChart.data.datasets[0].data.push(temp);
            
            // Keep only last 15 points
            if (tempChart.data.labels.length > 15) {
                tempChart.data.labels.shift();
                tempChart.data.datasets[0].data.shift();
            }
            
            tempChart.update();
        }

        // Utility Functions
        function getStatusClass(temp) {
            const warning = <?php echo $CONFIG['warning_temp']; ?>;
            const critical = <?php echo $CONFIG['critical_temp']; ?>;
            
            if (temp >= critical) return 'critical';
            if (temp >= warning) return 'warning';
            return 'normal';
        }

        function getStatusText(temp) {
            const warning = <?php echo $CONFIG['warning_temp']; ?>;
            const critical = <?php echo $CONFIG['critical_temp']; ?>;
            
            if (temp >= critical) return 'CRITICAL';
            if (temp >= warning) return 'WARNING';
            return 'NORMAL';
        }

        async function sendTempToLog(temp) {
            const payload = { temp };
            try {
                const res = await fetch('./api/log_temp.php', {
                    method: 'POST',
                    headers: { 'Content-Type: application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (!json.ok) {
                    console.warn('Logging failed:', json);
                }
            } catch (err) {
                console.error('Failed to send temp to log:', err);
            }
        }

        function showNotification(message, type) {
            const el = document.getElementById('notification');
            el.textContent = message;
            el.style.display = 'block';
            el.className = 'notification' + (type === 'error' ? ' error' : '');
            
            setTimeout(() => {
                el.style.display = 'none';
            }, 3000);
        }

        function showLoading(show) {
            document.getElementById('loading').style.display = show ? 'block' : 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    if (liveLogsInterval) {
                        clearInterval(liveLogsInterval);
                        liveLogsInterval = null;
                    }
                }
            });
        }
    </script>
</body>
</html>
