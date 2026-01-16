<?php
// iDRAC Temperature Monitor - Simplified UI Version
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

function get_storage_logs(): array {
    $logs = [];
    if (file_exists(STORAGE_LOG_FILE)) {
        $lines = file(STORAGE_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Parse format: [2026-01-12 14:17:13] temp=16.00°C, ip=10.129.8.25
            if (preg_match('/\[([^\]]+)\]\s*temp=([\d\.]+).*?ip=([\d\.]+)/', $line, $matches)) {
                $logs[] = [
                    'timestamp' => $matches[1],
                    'temperature' => floatval($matches[2]),
                    'ip' => $matches[3],
                    'raw' => $line
                ];
            }
        }
    }
    return array_slice($logs, -200); // Last 200 entries
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
            $logs = get_storage_logs();
            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'count' => count($logs)
            ]);
            break;
            
        case 'get_graph_data':
            $logs = get_storage_logs();
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
<html lang="en" data-theme="dark">
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
            gap: 24px;
        }
        
        /* Header - NO BORDER, NO SHADOW */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 30px;
            background: var(--bg-secondary);
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .logo {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent) 0%, #8b5cf6 100%);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 24px;
            color: white;
        }
        
        .logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .header h1 {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: 1px;
        }
        
        .header-subtitle {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 5px;
            font-weight: 400;
        }
        
        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            color: var(--text-muted);
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
        }
        
        .refresh-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { 
                opacity: 1; 
            }
            50% { 
                opacity: 0.7;
            }
        }
        
        /* Main Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Temperature Card - NO BORDER, NO SHADOW */
        .temp-card {
            background: var(--bg-card);
            border-radius: 24px;
            padding: 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .temp-label {
            font-size: 14px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .temp-display {
            font-size: 96px;
            font-weight: 800;
            margin: 20px 0;
            line-height: 1;
            transition: color 0.3s ease;
        }
        
        .temp-normal { color: var(--success); }
        .temp-warning { color: var(--warning); }
        .temp-critical { color: var(--critical); }
        .temp-unknown { color: var(--unknown); }
        
        .status {
            display: inline-block;
            padding: 12px 32px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 16px;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin: 15px 0;
            transition: all 0.3s ease;
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
            margin-top: 15px;
            text-align: center;
        }
        
        /* Stats Grid - NO BORDER, NO SHADOW */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 40px;
            width: 100%;
        }
        
        .stat-card {
            background: var(--bg-secondary);
            padding: 25px;
            border-radius: 16px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        /* Controls Panel - NO BORDER, NO SHADOW */
        .controls-panel {
            background: var(--bg-card);
            border-radius: 24px;
            padding: 40px;
        }
        
        .panel-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        /* BUTTONS ONLY - WITH BORDER */
        .btn {
            padding: 22px;
            border-radius: 16px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            color: white;
            border: 2px solid transparent;
        }
        
        .btn:hover {
            transform: translateY(-3px);
            border-color: white;
        }
        
        .btn-primary { 
            background: linear-gradient(135deg, var(--accent) 0%, #2563eb 100%);
        }
        
        .btn-success { 
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
        }
        
        .btn-warning { 
            background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
        }
        
        .btn-danger { 
            background: linear-gradient(135deg, var(--critical) 0%, #dc2626 100%);
        }
        
        .btn-info { 
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        }
        
        /* System Status */
        .system-status {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 20px;
            padding: 12px 20px;
            background: var(--bg-secondary);
            border-radius: 12px;
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        .status-dot.online { 
            background: var(--success); 
        }
        .status-dot.offline { 
            background: var(--critical); 
        }
        
        /* Graphs Section - NO BORDER, NO SHADOW */
        .graphs-section {
            grid-column: 1 / -1;
            background: var(--bg-card);
            border-radius: 24px;
            padding: 40px;
        }
        
        .graphs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .graph-container {
            height: 400px;
            position: relative;
            margin-bottom: 30px;
        }
        
        .graph-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .tab {
            padding: 12px 24px;
            background: var(--bg-secondary);
            border-radius: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            background: var(--accent);
            color: white;
        }
        
        .tab:hover:not(.active) {
            background: var(--bg-secondary);
        }
        
        /* Logs Table */
        .logs-table-container {
            max-height: 400px;
            overflow-y: auto;
            border-radius: 12px;
        }
        
        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .logs-table th {
            position: sticky;
            top: 0;
            background: var(--bg-secondary);
            padding: 16px;
            text-align: left;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .logs-table td {
            padding: 16px;
            color: var(--text-secondary);
        }
        
        .logs-table tr:nth-child(even) {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .logs-table tr:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        /* Notification */
        .notification {
            position: fixed;
            top: 30px;
            right: 30px;
            padding: 20px 30px;
            border-radius: 16px;
            background: var(--bg-card);
            display: none;
            z-index: 1000;
            animation: slideInRight 0.3s ease;
            border-left: 5px solid var(--success);
        }
        
        .notification.error {
            border-left-color: var(--critical);
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
            background: var(--bg-primary);
            padding: 40px;
            border-radius: 20px;
        }
        
        .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid var(--border);
            border-top: 4px solid var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            color: var(--text-primary);
            font-size: 16px;
            text-align: center;
        }
        
        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 4px;
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
                    <!-- Placeholder for your logo - replace with actual logo -->
                    <div style="color: white; font-weight: 800; font-size: 20px;">iTM</div>
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

        <!-- Main Dashboard -->
        <div class="dashboard-grid">
            <!-- Temperature Display -->
            <div class="temp-card">
                <div class="temp-label">Current Temperature</div>
                <div class="temp-display" id="temperature">-- °C</div>
                <div class="status unknown" id="statusIndicator">UNKNOWN</div>
                <div id="lastUpdate" class="meta">Last updated: --</div>
                
                <div class="system-status">
                    <div class="status-dot online"></div>
                    <span>iDRAC Connection: Active</span>
                </div>
                
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
            </div>

            <!-- Controls Panel (EMPTIED BUT PRESERVED STRUCTURE) -->
            <div class="controls-panel">
                <!-- Removed: Temperature Thresholds panel title -->
                <!-- Removed: Monitoring Controls buttons -->
                <!-- System Status Only -->
                <div class="system-status" style="margin-top: 0;">
                    <div class="status-dot <?php echo $CONFIG['smtp_auth'] ? 'online' : 'offline'; ?>"></div>
                    <span>SMTP: <?php echo htmlspecialchars($CONFIG['smtp_host']); ?>:<?php echo htmlspecialchars($CONFIG['smtp_port']); ?></span>
                </div>
            </div>
        </div>

        <!-- Graphs & Logs Section -->
        <div class="graphs-section">
            <div class="graphs-header">
                <div class="graph-title">Temperature Analytics</div>
                <div class="tabs">
                    <div class="tab active" onclick="showTab('graph')">Live Graph</div>
                    <div class="tab" onclick="showTab('history')">History Graph</div>
                    <div class="tab" onclick="showTab('logs')">View Logs</div> <!-- CHANGED: Raw Logs -> View Logs -->
                </div>
            </div>
            
            <!-- Graph Container -->
            <div id="graph-tab" class="tab-content">
                <div class="graph-container">
                    <canvas id="tempChart"></canvas>
                </div>
                <div style="display: flex; gap: 12px;">
                    <button class="btn btn-info" onclick="downloadCSV()">
                        <span>📥</span>
                        Download CSV Logs
                    </button>
                    <button class="btn btn-info" onclick="downloadStorageLogs()">
                        <span>📥</span>
                        Download Storage Logs
                    </button>
                    <button class="btn btn-primary" onclick="refreshGraph()">
                        <span>🔄</span>
                        Refresh Graph
                    </button>
                </div>
            </div>
            
            <!-- History Graph Container -->
            <div id="history-tab" class="tab-content" style="display: none;">
                <div class="graph-container">
                    <canvas id="historyChart"></canvas>
                </div>
            </div>
            
            <!-- Logs Table Container -->
            <div id="logs-tab" class="tab-content" style="display: none;">
                <div class="logs-table-container">
                    <table class="logs-table" id="logsTable">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Temperature</th>
                                <th>Status</th>
                                <th>Source IP</th>
                            </tr>
                        </thead>
                        <tbody id="logsBody">
                            <tr><td colspan="4" style="text-align: center; padding: 40px;">Loading logs...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 20px; display: flex; gap: 12px;">
                    <button class="btn btn-primary" onclick="refreshLogs()">
                        <span>🔄</span>
                        Refresh Logs
                    </button>
                    <button class="btn btn-info" onclick="clearLogs()">
                        <span>🗑️</span>
                        Clear Table
                    </button>
                </div>
            </div>
        </div>
        
        <!-- FOOTER REMOVED COMPLETELY -->
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
                    
                    // Update graph if active
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

        // EMAIL FUNCTIONALITY PRESERVED (but buttons removed)
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

        function downloadCSV() {
            window.open('?action=download_logs&type=csv', '_blank');
        }

        function downloadStorageLogs() {
            window.open('?action=download_logs&type=storage', '_blank');
        }

        async function showLogs() {
            showTab('logs');
            await refreshLogs();
        }

        async function refreshLogs() {
            showLoading(true);
            try {
                const response = await fetch('?action=get_storage_logs');
                const data = await response.json();
                
                if (data.success && data.logs.length > 0) {
                    const tbody = document.getElementById('logsBody');
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
                    document.getElementById('logsBody').innerHTML = 
                        '<tr><td colspan="4" style="text-align: center; padding: 40px;">No logs available</td></tr>';
                }
            } catch (error) {
                showNotification('Failed to load logs: ' + error.message, 'error');
            }
            showLoading(false);
        }

        function clearLogs() {
            document.getElementById('logsBody').innerHTML = 
                '<tr><td colspan="4" style="text-align: center; padding: 40px;">Logs cleared</td></tr>';
        }

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

        function showTab(tabName) {
            // Update tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Show selected tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.style.display = 'none';
            });
            document.getElementById(tabName + '-tab').style.display = 'block';
            
            // Initialize charts if needed
            if (tabName === 'graph' && !tempChart) {
                initTempChart();
            }
            if (tabName === 'history' && !historyChart) {
                initHistoryChart();
            }
        }

        function initTempChart() {
            const ctx = document.getElementById('tempChart').getContext('2d');
            
            tempChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Temperature (°C)',
                        data: [],
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#3b82f6',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 8
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
                                    size: 14,
                                    family: "'Segoe UI', sans-serif"
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30, 41, 59, 0.9)',
                            titleColor: '#f1f5f9',
                            bodyColor: '#cbd5e1',
                            borderColor: '#475569',
                            borderWidth: 1,
                            cornerRadius: 8
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(71, 85, 105, 0.3)',
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 12
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(71, 85, 105, 0.3)',
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 12
                                },
                                callback: function(value) {
                                    return value + '°C';
                                }
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeOutQuart'
                    }
                }
            });
            
            // Add threshold lines
            addThresholdLines();
            
            // Start with some initial data
            for (let i = 0; i < 10; i++) {
                updateChart(currentTemp || 20, new Date(Date.now() - (10 - i) * 60000).toLocaleTimeString());
            }
        }

        function initHistoryChart() {
            const ctx = document.getElementById('historyChart').getContext('2d');
            
            historyChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Historical Temperature (°C)',
                        data: [],
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7
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
                                    size: 14,
                                    family: "'Segoe UI', sans-serif"
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30, 41, 59, 0.9)',
                            titleColor: '#f1f5f9',
                            bodyColor: '#cbd5e1',
                            borderColor: '#475569',
                            borderWidth: 1,
                            cornerRadius: 8
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(71, 85, 105, 0.3)',
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 12
                                },
                                maxTicksLimit: 10
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(71, 85, 105, 0.3)',
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 12
                                },
                                callback: function(value) {
                                    return value + '°C';
                                }
                            }
                        }
                    }
                }
            });
            
            // Load historical data
            loadHistoryData();
        }

        function addThresholdLines() {
            if (!tempChart) return;
            
            const warningLine = <?php echo $CONFIG['warning_temp']; ?>;
            const criticalLine = <?php echo $CONFIG['critical_temp']; ?>;
            
            // Add warning line
            tempChart.data.datasets.push({
                label: 'Warning Threshold',
                data: Array(tempChart.data.labels.length).fill(warningLine),
                borderColor: '#f59e0b',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 5],
                fill: false,
                pointRadius: 0
            });
            
            // Add critical line
            tempChart.data.datasets.push({
                label: 'Critical Threshold',
                data: Array(tempChart.data.labels.length).fill(criticalLine),
                borderColor: '#ef4444',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 5],
                fill: false,
                pointRadius: 0
            });
            
            tempChart.update();
        }

        function updateChart(temp, timestamp) {
            if (!tempChart) return;
            
            // Add new data point
            tempChart.data.labels.push(timestamp.split(' ')[1]); // Just the time part
            tempChart.data.datasets[0].data.push(temp);
            
            // Update threshold lines
            if (tempChart.data.datasets.length > 1) {
                tempChart.data.datasets[1].data.push(<?php echo $CONFIG['warning_temp']; ?>);
                tempChart.data.datasets[2].data.push(<?php echo $CONFIG['critical_temp']; ?>);
            }
            
            // Keep only last 20 points
            if (tempChart.data.labels.length > 20) {
                tempChart.data.labels.shift();
                tempChart.data.datasets.forEach(dataset => {
                    dataset.data.shift();
                });
            }
            
            tempChart.update();
        }

        async function loadHistoryData() {
            if (!historyChart) return;
            
            showLoading(true);
            try {
                const response = await fetch('?action=get_graph_data');
                const data = await response.json();
                
                if (data.success) {
                    const labels = data.data.map(item => item.x.split(' ')[1]);
                    const temps = data.data.map(item => item.y);
                    
                    historyChart.data.labels = labels.slice(-50); // Last 50 points
                    historyChart.data.datasets[0].data = temps.slice(-50);
                    
                    historyChart.update();
                }
            } catch (error) {
                console.error('Failed to load history data:', error);
            }
            showLoading(false);
        }

        function refreshGraph() {
            if (tempChart) {
                tempChart.data.labels = [];
                tempChart.data.datasets[0].data = [];
                if (tempChart.data.datasets.length > 1) {
                    tempChart.data.datasets[1].data = [];
                    tempChart.data.datasets[2].data = [];
                }
                tempChart.update();
                
                // Re-add threshold lines
                addThresholdLines();
            }
        }

        function showNotification(message, type) {
            const el = document.getElementById('notification');
            el.textContent = message;
            el.style.display = 'block';
            el.className = 'notification' + (type === 'error' ? ' error' : '');
            el.style.borderLeftColor = type === 'success' ? '#10b981' : '#ef4444';
            
            setTimeout(() => {
                el.style.display = 'none';
            }, 4000);
        }

        function showLoading(show) {
            document.getElementById('loading').style.display = show ? 'block' : 'none';
        }

        // Temperature logging function
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
                }
            } catch (err) {
                console.error('Failed to send temp to log:', err);
            }
        }

        // Auto-load on start
        window.onload = function() {
            getTemperature();
            setInterval(getTemperature, AUTO_REFRESH_MS);
            
            // Initialize graph tab by default
            initTempChart();
        };

        // Auto-refresh logs every 30 seconds if logs tab is active
        setInterval(() => {
            if (document.getElementById('logs-tab').style.display === 'block') {
                refreshLogs();
            }
        }, 30000);
    </script>
</body>
</html>
