<?php
// iDRAC Temperature Monitor - Standalone Version
// Include configuration
require_once __DIR__ . '/idrac_config.php';

date_default_timezone_set($CONFIG['timezone']);

// Small state file to avoid duplicate alert emails
define('IDRAC_STATE_FILE', __DIR__ . '/idrac_state.json');
define('LOG_FILE', __DIR__ . '/idrac_log.csv');

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

// =============== LOGGING FUNCTION ===============
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

function download_logs(): void {
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
            download_logs();
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
    exit;
}

// =============== HTML INTERFACE ===============
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iDRAC Temperature Monitor</title>
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: var(--bg-secondary);
            border-radius: 16px;
            border: 1px solid var(--border);
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-muted);
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
        
        /* Main Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            height: calc(100vh - 200px);
        }
        
        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
                height: auto;
            }
        }
        
        /* Temperature Card */
        .temp-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 30px;
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .temp-display {
            font-size: 72px;
            font-weight: 800;
            margin: 20px 0;
            color: var(--text-primary);
            line-height: 1;
        }
        
        .status {
            display: inline-block;
            padding: 10px 28px;
            border-radius: 24px;
            font-weight: 600;
            font-size: 14px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 10px 0;
        }
        
        .normal { background: var(--success); color: white; }
        .warning { background: var(--warning); color: white; }
        .critical { background: var(--critical); color: white; }
        .unknown { background: var(--unknown); color: white; }
        
        .meta {
            color: var(--text-muted);
            font-size: 14px;
            margin-top: 8px;
            text-align: center;
        }
        
        /* Stats */
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 30px;
            width: 100%;
        }
        
        .stat {
            background: var(--bg-secondary);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid var(--border);
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 12px;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Controls */
        .controls-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 30px;
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
        }
        
        .controls-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 20px 0;
        }
        
        @media (max-width: 640px) {
            .controls-grid {
                grid-template-columns: 1fr;
            }
        }
        
        button {
            padding: 18px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 60px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }
        
        button:hover {
            background: var(--accent);
            transform: translateY(-2px);
            border-color: var(--accent);
        }
        
        .btn-primary { background: var(--accent); border-color: var(--accent); }
        .btn-success { background: var(--success); border-color: var(--success); }
        .btn-warning { background: var(--warning); border-color: var(--warning); }
        .btn-danger { background: var(--critical); border-color: var(--critical); }
        
        /* Thresholds */
        .thresholds {
            margin-top: 30px;
        }
        
        .threshold-list {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .threshold-item {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .threshold-normal { background: var(--success); color: white; }
        .threshold-warning { background: var(--warning); color: white; }
        .threshold-critical { background: var(--critical); color: white; }
        
        /* Config Panel */
        .config-panel {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid var(--border);
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .config-panel {
                grid-template-columns: 1fr;
            }
        }
        
        .config-item {
            padding: 15px;
            background: var(--bg-secondary);
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        
        .config-item h3 {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .config-item p {
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.4;
        }
        
        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 12px;
            background: var(--bg-card);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            display: none;
            z-index: 1000;
            animation: slideIn 0.3s ease;
            border-left: 4px solid var(--success);
            max-width: 300px;
            border: 1px solid var(--border);
        }
        
        .notification.error {
            border-left-color: var(--critical);
        }
        
        @keyframes slideIn {
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
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border);
            border-top: 3px solid var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* System Status */
        .system-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 10px;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .status-dot.online { background: var(--success); }
        .status-dot.offline { background: var(--critical); }
        .status-dot.pending { background: var(--warning); }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                <span style="font-size: 28px;"></span>
                iTM
            </h1>
            <div class="refresh-indicator">
                <div class="refresh-dot"></div>
                Auto-refresh: <?php echo (int)$CONFIG['check_interval']; ?> minutes
            </div>
        </div>

        <!-- Main Dashboard -->
        <div class="dashboard-grid">
            <!-- Temperature Display -->
            <div class="temp-card">
                <div style="font-size: 14px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px;">
                    Current Temperature
                </div>
                <div class="temp-display" id="temperature">-- °C</div>
                <div class="status unknown" id="statusIndicator">UNKNOWN</div>
                <div id="lastUpdate" class="meta">Last updated: --</div>
                
                <div class="system-status">
                    <div class="status-dot online"></div>
                    <span>System: Online</span>
                </div>
                
                <div class="stats">
                    <div class="stat">
                        <div class="stat-value" id="minTemp">--</div>
                        <div class="stat-label">Min Today</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value" id="avgTemp">--</div>
                        <div class="stat-label">Average</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value" id="maxTemp">--</div>
                        <div class="stat-label">Max Today</div>
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <div class="controls-card">
                <div style="font-size: 14px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px;">
                    <!-- Actions -->
                    <div class="config-item">
                        <h3>Email Server</h3>
                        <p><?php echo htmlspecialchars($CONFIG['smtp_host']); ?>:<?php echo htmlspecialchars($CONFIG['smtp_port']); ?></p>
                        <p style="margin-top: 8px; font-size: 12px; color: <?php echo $CONFIG['smtp_auth'] ? 'var(--success)' : 'var(--warning)'; ?>">
                            <?php echo $CONFIG['smtp_auth'] ? '🔐 Authentication Enabled' : '🔓 Internal Relay'; ?>
                        </p>
                    </div>
                </div>
                
                <!-- <div class="controls-grid">
                    <button class="btn-success" onclick="sendReport()">
                        <span>📧</span>
                        Send Report
                    </button>
                    <button class="btn-warning" onclick="sendTestEmail()">
                        <span>🧪</span>
                        Test Email
                    </button>
                    <button class="btn-danger" onclick="downloadLogs()">
                        <span>📥</span>
                        Download Logs
                    </button>
                </div>
                 -->
                <!-- <div class="thresholds">
                    <div style="font-size: 14px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px;">
                        Temperature Thresholds
                    </div>
                    <div class="threshold-list">
                        <span class="threshold-item threshold-normal">
                            Normal &lt; <?php echo $CONFIG['warning_temp']; ?>°C
                        </span>
                        <span class="threshold-item threshold-warning">
                            Warning ≥ <?php echo $CONFIG['warning_temp']; ?>°C
                        </span>
                        <span class="threshold-item threshold-critical">
                            Critical ≥ <?php echo $CONFIG['critical_temp']; ?>°C
                        </span>
                    </div>
                </div> -->
            </div>
        </div>

        <!-- Configuration Panel -->
        <!--<div class="config-panel">
            <!-- <div class="config-item">
                <h3>Email Server</h3>
                <p><?php echo htmlspecialchars($CONFIG['smtp_host']); ?>:<?php echo htmlspecialchars($CONFIG['smtp_port']); ?></p>
                <p style="margin-top: 8px; font-size: 12px; color: <?php echo $CONFIG['smtp_auth'] ? 'var(--success)' : 'var(--warning)'; ?>">
                    <?php echo $CONFIG['smtp_auth'] ? '🔐 Authentication Enabled' : '🔓 Internal Relay'; ?>
                </p>
            </div> -->
            
            <!-- <div class="config-item">
                <h3>Recipients</h3>
                <p>From: <?php echo htmlspecialchars($CONFIG['email_from']); ?></p>
                <p>To: 4 recipients configured</p>
            </div> -->
            
            <!-- <div class="config-item">
                <h3>Monitoring Schedule</h3>
                <p>📅 Hourly: 00:00–23:00</p>
                <p>⚠️ Alerts: Instant + 5-min follow-up</p>
            </div> -->
       <!-- </div>
    </div>

     Notification -->
    <div class="notification" id="notification"></div>
    
    <!-- Loading -->
    <div class="loading" id="loading">
        <div class="spinner"></div>
    </div>

    <script>
        const AUTO_REFRESH_MS = <?php echo (int)$CONFIG['check_interval']; ?> * 60000;

        async function getTemperature() {
            showLoading(true);
            try {
                const response = await fetch('?action=get_temp');
                const data = await response.json();

                if (data.success) {
                    document.getElementById('temperature').textContent = data.temperature + ' °C';
                    const statusEl = document.getElementById('statusIndicator');
                    statusEl.textContent = data.status;
                    statusEl.className = 'status ' + data.status.toLowerCase();
                    document.getElementById('lastUpdate').textContent = 'Last updated: ' + (data.timestamp || '');
                    showNotification(data.temperature + '°C - ' + data.status, 'success');
                    updateStats(data.temperature);
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

        async function downloadLogs() {
            window.open('?action=download_logs', '_blank');
        }

        function updateStats(currentTemp) {
            if (currentTemp && !isNaN(currentTemp)) {
                const temp = parseFloat(currentTemp);
                document.getElementById('minTemp').textContent = (temp - 1).toFixed(1) + '°C';
                document.getElementById('avgTemp').textContent = temp.toFixed(1) + '°C';
                document.getElementById('maxTemp').textContent = (temp + 2).toFixed(1) + '°C';
            }
        }

        function showNotification(message, type) {
            const el = document.getElementById('notification');
            el.textContent = message;
            el.style.display = 'block';
            el.className = 'notification' + (type === 'error' ? ' error' : '');
            el.style.borderLeftColor = type === 'success' ? 'var(--success)' : 'var(--critical)';
            
            setTimeout(() => {
                el.style.display = 'none';
            }, 3000);
        }

        function showLoading(show) {
            document.getElementById('loading').style.display = show ? 'block' : 'none';
        }

        // Auto-load on start
        window.onload = function() {
            getTemperature();
            setInterval(getTemperature, AUTO_REFRESH_MS);
        };

                
        /**
         * Parse a temperature string like "26.3 °C", "26°C", "26 C", or "-- °C".
         * Returns a number (e.g., 26.3) or null if not parseable.
         */
        function parseTempFromElement(el) {
            if (!el) return null;
            const txt = el.textContent.trim();
            // Extract the first valid number (handles negative and decimals)
            const match = txt.match(/-?\d+(\.\d+)?/);
            if (!match) return null;
            const val = parseFloat(match[0]);
            return Number.isFinite(val) ? val : null;
        }

        /**
         * Send the temperature to the logging endpoint.
         */
        async function sendTempToLog(temp) {
            const payload = { temp };
            try {
            const res = await fetch('./api/log_temp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                body: JSON.stringify(payload)
            });
            const json = await res.json();
            if (!json.ok) {
                console.warn('Logging failed:', json);
            } else {
                console.log('Logged temp:', temp, 'at', json.timestamp);
            }
            } catch (err) {
            console.error('Failed to send temp to log:', err);
            }
        }

        /**
         * Try to read temp and log it once per page load.
         * If temp is populated asynchronously by other scripts, wait/retry briefly.
         */
        async function logCurrentTempOnce() {
            const el = document.getElementById('temperature');
            let temp = parseTempFromElement(el);

            if (temp === null) {
            // Retry up to ~15 seconds in case another script populates it later
            let retries = 30;
            while (retries-- > 0 && temp === null) {
                await new Promise(r => setTimeout(r, 500));
                temp = parseTempFromElement(el);
            }
            }

            if (temp !== null) {
            await sendTempToLog(temp);
            } else {
            console.warn('Temperature not available in #temperature element; skipped logging');
            }
        }

        // Log once the DOM is ready
        document.addEventListener('DOMContentLoaded', logCurrentTempOnce);

        // Auto-refresh every  minutes (180,000 ms)
        setInterval(() => location.reload(), 1000000);

    </script>
</body>
</html>
