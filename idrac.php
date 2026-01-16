<?php
// iDRAC Temperature Monitor - Responsive No-Border Version (Revised)
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
        $lines = file(LOG_FILE, FILE_IGNORE_NEWLINES | FILE_SKIP_EMPTY_LINES);
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
        $lines = file(STORAGE_LOG_FILE, FILE_IGNORE_NEWLINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
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
    return array_slice($logs, -500);
}

function get_filtered_logs(string $start_date = '', string $end_date = ''): array {
    $logs = get_storage_logs();
    
    if (empty($start_date) && empty($end_date)) {
        return $logs;
    }
    
    $filtered = [];
    foreach ($logs as $log) {
        $log_date = strtotime($log['timestamp']);
        $include = true;
        
        if (!empty($start_date)) {
            $start = strtotime($start_date . ' 00:00:00');
            if ($log_date < $start) $include = false;
        }
        
        if (!empty($end_date)) {
            $end = strtotime($end_date . ' 23:59:59');
            if ($log_date > $end) $include = false;
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
        header('Content-Disposition: attachment; filename="idrac_temperature_' . date('Y-m-d') . '.log"');
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
        header('Content-Disposition: attachment; filename="idrac_temperature_' . date('Y-m-d') . '.csv"');
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
    
    if (in_array($status, ['WARNING', 'CRITICAL'], true) && 
        $state['last_alert_status'] !== $status) {
        $send_alert = true;
        $alert_type = 'STATUS_CHANGE';
        
        if ($status === 'WARNING') {
            $state['warning_start_time'] = $current_time;
        } elseif ($status === 'CRITICAL') {
            $state['critical_start_time'] = $current_time;
        }
    }
    
    if ($status === 'WARNING' && $state['warning_start_time'] !== null) {
        $duration = $current_time - $state['warning_start_time'];
        if ($duration >= 300 && $duration < 360) {
            $send_alert = true;
            $alert_type = 'PERSISTENT_WARNING';
        }
    }
    
    if ($status === 'CRITICAL' && $state['critical_start_time'] !== null) {
        $duration = $current_time - $state['critical_start_time'];
        if ($duration >= 300 && $duration < 360) {
            $send_alert = true;
            $alert_type = 'PERSISTENT_CRITICAL';
        }
    }
    
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
    
    if ($status === 'NORMAL') {
        $state['warning_start_time'] = null;
        $state['critical_start_time'] = null;
    }
    
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
                    $temp = $sensor['ReadingCelsius'] - 62;
                    if ($temp >= 0 && $temp <= 100) {
                        $status = get_temp_status($temp);
                        $timestamp = format_ts();
                        
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

// =============== EMAIL FUNCTIONS ===============
function send_email(string $subject, string $message): bool {
    global $CONFIG;
    
    $to = $CONFIG['email_to'];
    $from = $CONFIG['email_from'];
    $from_name = $CONFIG['email_from_name'];
    
    if ($CONFIG['transport'] === 'smtp' && $CONFIG['smtp_host'] === 'mrelay.intra.j-display.com') {
        return send_email_internal_relay($subject, $message, $to, $from, $from_name);
    }
    
    return send_email_simple($subject, $message, $to, $from, $from_name);
}

function send_email_internal_relay(string $subject, string $message, string $to, string $from, string $from_name): bool {
    global $CONFIG;
    
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
    
    error_log("Attempting to send email via internal relay to: {$to}");
    
    $result = @mail($to, $subject, $message, $headers_str);
    
    if ($result) {
        error_log("Email sent successfully to: {$to}");
    } else {
        error_log("Failed to send email to: {$to}");
        $result = send_email_alternative($subject, $message, $to, $from, $from_name);
    }
    
    return $result;
}

function send_email_alternative(string $subject, string $message, string $to, string $from, string $from_name): bool {
    global $CONFIG;
    
    $smtp_host = $CONFIG['smtp_host'];
    $smtp_port = $CONFIG['smtp_port'];
    
    try {
        $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 10);
        
        if (!$socket) {
            error_log("SMTP Connection failed to {$smtp_host}:{$smtp_port} - {$errstr} ({$errno})");
            return false;
        }
        
        $response = fgets($socket, 515);
        
        fputs($socket, "HELO " . $_SERVER['SERVER_NAME'] . "\r\n");
        $response = fgets($socket, 515);
        
        fputs($socket, "MAIL FROM: <{$from}>\r\n");
        $response = fgets($socket, 515);
        
        $recipients = explode(',', $to);
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                fputs($socket, "RCPT TO: <{$recipient}>\r\n");
                $response = fgets($socket, 515);
            }
        }
        
        fputs($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        
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

// =============== EMAIL BUILDER ===============
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

    if (isset($payload['duration'])) {
        $lines[] = 'Duration: ' . $payload['duration'];
    }

    if (($payload['kind'] ?? '') === 'Alert') {
        if ($payload['status'] === 'CRITICAL') {
            $lines[] = 'Action: Immediate attention recommended (check cooling, workloads, iDRAC).';
        } elseif ($payload['status'] === 'WARNING') {
            $lines[] = 'Action: Monitor closely; investigate airflow and load.';
        }
    }

    return implode("\n", $lines);
}

// CLI Support
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
                check_extended_alerts($result['temperature'], $result['status'], $result['timestamp']);
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
            
        case 'get_filtered_logs':
            $start_date = $_GET['start_date'] ?? '';
            $end_date = $_GET['end_date'] ?? '';
            $logs = get_filtered_logs($start_date, $end_date);
            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'count' => count($logs)
            ]);
            break;
            
        case 'get_graph_data':
            $start_date = $_GET['start_date'] ?? '';
            $end_date = $_GET['end_date'] ?? '';
            $logs = get_filtered_logs($start_date, $end_date);
            $data = [];
            foreach ($logs as $log) {
                $data[] = [
                    'x' => $log['timestamp'],
                    'y' => $log['temperature'],
                    'status' => get_temp_status($log['temperature'])
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iTM - Temperature Monitor</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* =============== MOBILE-FIRST RESPONSIVE DESIGN =============== */
        :root {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --accent: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --critical: #ef4444;
            --unknown: #6b7280;
            --graph-normal: rgba(16, 185, 129, 0.8);
            --graph-warning: rgba(245, 158, 11, 0.8);
            --graph-critical: rgba(239, 68, 68, 0.8);
            --graph-line: #3b82f6;
            --graph-area: rgba(59, 130, 246, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 0;
        }
        
        .container {
            max-width: 100%;
            margin: 0;
            padding: 0;
            width: 100%;
        }
        
        /* =============== HEADER - NO BORDER, NO BACKGROUND =============== */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            margin-bottom: 15px;
            /* REMOVED BACKGROUND AND BORDER */
            background: transparent;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--accent) 0%, #8b5cf6 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 18px;
            color: white;
        }
        
        .header h1 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .header-subtitle {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        
        /* =============== REFRESH INDICATOR - MOVED BELOW STATUS =============== */
        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-muted);
            /* REMOVED GREEN DOT AND BACKGROUND */
            padding: 0;
            background: transparent;
            border-radius: 0;
        }
        
        /* Removed .refresh-dot class completely */
        
        /* =============== DASHBOARD LAYOUT - ENHANCED RESPONSIVENESS =============== */
        .dashboard {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            padding: 0 20px;
        }
        
        /* Tablet (768px and up) */
        @media (min-width: 768px) {
            .dashboard {
                grid-template-columns: 1fr 2fr;
                gap: 20px;
                min-height: 450px;
            }
        }
        
        /* Desktop (1024px and up) */
        @media (min-width: 1024px) {
            .dashboard {
                grid-template-columns: 1fr 3fr;
                gap: 25px;
                min-height: 500px;
            }
        }
        
        /* =============== TEMPERATURE PANEL - NO BORDER, NO BACKGROUND =============== */
        .temp-panel {
            /* REMOVED BACKGROUND AND BORDER */
            background: transparent;
            border-radius: 0;
            padding: 20px 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 300px;
        }
        
        @media (min-width: 768px) {
            .temp-panel {
                min-height: 400px;
            }
        }
        
        .temp-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .temp-display {
            font-size: 48px;
            font-weight: 800;
            margin: 15px 0;
            line-height: 1;
            text-align: center;
        }
        
        /* Mobile adjustments */
        @media (max-width: 480px) {
            .temp-display {
                font-size: 42px;
            }
        }
        
        @media (min-width: 768px) {
            .temp-display {
                font-size: 60px;
            }
        }
        
        @media (min-width: 1024px) {
            .temp-display {
                font-size: 70px;
            }
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
            letter-spacing: 0.3px;
            text-transform: uppercase;
            margin: 10px 0;
            text-align: center;
            min-width: 120px;
        }
        
        @media (max-width: 480px) {
            .status {
                padding: 8px 20px;
                font-size: 12px;
                min-width: 100px;
            }
        }
        
        .normal { background: var(--success); color: white; }
        .warning { background: var(--warning); color: white; }
        .critical { background: var(--critical); color: white; }
        .unknown { background: var(--unknown); color: white; }
        
        .meta {
            color: var(--text-muted);
            font-size: 12px;
            margin-top: 10px;
            text-align: center;
        }
        
        /* Refresh indicator in temp panel */
        .temp-panel .refresh-indicator {
            margin-top: 20px;
            font-size: 11px;
        }
        
        /* =============== STATS GRID - RESPONSIVE =============== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 25px;
            width: 100%;
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                gap: 8px;
            }
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            backdrop-filter: blur(10px);
        }
        
        @media (max-width: 480px) {
            .stat-card {
                padding: 12px;
            }
        }
        
        .stat-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        
        @media (min-width: 768px) {
            .stat-value {
                font-size: 18px;
            }
        }
        
        @media (min-width: 1024px) {
            .stat-value {
                font-size: 20px;
            }
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-weight: 600;
        }
        
        /* =============== GRAPH PANEL - NO BORDER, ENHANCED DESIGN =============== */
        .graph-panel {
            /* REMOVED BACKGROUND AND BORDER */
            background: transparent;
            border-radius: 0;
            padding: 20px 15px;
            display: flex;
            flex-direction: column;
            min-height: 400px;
        }
        
        .graph-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .graph-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .graph-container {
            flex: 1;
            position: relative;
            min-height: 300px;
            width: 100%;
        }
        
        /* =============== BUTTONS - WITH BORDER ONLY =============== */
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid transparent;
            background: var(--accent);
            color: white;
            white-space: nowrap;
        }
        
        .btn:hover {
            border-color: white;
            transform: translateY(-1px);
        }
        
        .btn-primary { background: var(--accent); }
        .btn-success { background: var(--success); }
        .btn-warning { background: var(--warning); }
        .btn-danger { background: var(--critical); }
        .btn-info { background: #06b6d4; }
        
        /* =============== MODAL SYSTEM =============== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 10px;
        }
        
        .modal {
            background: var(--bg-secondary);
            border-radius: 15px;
            width: 100%;
            max-width: 1200px;
            max-height: 95vh;
            display: flex;
            flex-direction: column;
        }
        
        @media (min-width: 768px) {
            .modal {
                max-height: 90vh;
            }
        }
        
        /* REMOVED BORDER BETWEEN MODAL-HEADER AND MODAL-TABS */
        .modal-header {
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            /* REMOVED BORDER BOTTOM */
            border-bottom: none;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 20px;
            cursor: pointer;
            padding: 5px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 5px;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-primary);
        }
        
        /* =============== MODAL TABS =============== */
        .modal-tabs {
            display: flex;
            gap: 8px;
            padding: 0 20px 10px;
            flex-wrap: wrap;
        }
        
        .modal-tab {
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 12px;
            font-weight: 600;
            flex: 1;
            text-align: center;
            min-width: 80px;
        }
        
        @media (min-width: 480px) {
            .modal-tab {
                flex: none;
            }
        }
        
        .modal-tab.active {
            background: var(--accent);
            color: white;
        }
        
        .modal-content {
            flex: 1;
            overflow-y: auto;
            padding: 0 20px 20px;
        }
        
        /* =============== DATE FILTER =============== */
        .date-filter {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        @media (min-width: 480px) {
            .date-filter {
                flex-direction: row;
                align-items: flex-end;
            }
        }
        
        .date-group {
            flex: 1;
        }
        
        .date-label {
            color: var(--text-muted);
            font-size: 11px;
            margin-bottom: 4px;
        }
        
        .date-input {
            padding: 6px 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 5px;
            color: var(--text-primary);
            font-family: inherit;
            font-size: 12px;
            width: 100%;
        }
        
        /* =============== LOGS TABLE =============== */
        .logs-container {
            max-height: 350px;
            overflow-y: auto;
        }
        
        .logs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        
        @media (min-width: 768px) {
            .logs-table {
                font-size: 12px;
            }
        }
        
        .logs-table th {
            position: sticky;
            top: 0;
            background: rgba(30, 41, 59, 0.9);
            backdrop-filter: blur(10px);
            padding: 10px 8px;
            text-align: left;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-size: 10px;
        }
        
        @media (min-width: 768px) {
            .logs-table th {
                padding: 12px 10px;
                font-size: 11px;
            }
        }
        
        .logs-table td {
            padding: 10px 8px;
            color: var(--text-secondary);
        }
        
        @media (min-width: 768px) {
            .logs-table td {
                padding: 12px 10px;
            }
        }
        
        .logs-table tr:nth-child(even) {
            background: rgba(255, 255, 255, 0.03);
        }
        
        .logs-table tr:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        
        /* =============== MODAL ACTIONS - FIXED VISIBILITY =============== */
        .modal-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;
            width: 100%;
        }
        
        .modal-actions .btn {
            flex: 1 1 auto;
            min-width: 120px;
            text-align: center;
            font-size: 11px;
            padding: 10px 12px;
            white-space: normal;
            word-break: break-word;
            line-height: 1.2;
        }
        
        @media (min-width: 480px) {
            .modal-actions .btn {
                flex: none;
                min-width: 140px;
                font-size: 12px;
                padding: 10px 16px;
                white-space: nowrap;
            }
        }
        
        @media (min-width: 768px) {
            .modal-actions .btn {
                min-width: 160px;
                font-size: 13px;
            }
        }
        
        /* =============== NOTIFICATION =============== */
        .notification {
            position: fixed;
            top: 15px;
            right: 15px;
            padding: 12px 20px;
            border-radius: 10px;
            background: var(--bg-secondary);
            display: none;
            z-index: 1001;
            animation: slideInRight 0.3s ease;
            border-left: 4px solid var(--success);
            max-width: 90%;
            font-size: 13px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        
        @media (min-width: 768px) {
            .notification {
                max-width: 350px;
            }
        }
        
        .notification.error {
            border-left-color: var(--critical);
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* =============== LOADING OVERLAY =============== */
        .loading {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            background: var(--bg-primary);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--bg-secondary);
            border-top: 3px solid var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            color: var(--text-primary);
            font-size: 13px;
            text-align: center;
        }
        
        /* =============== SCROLLBAR STYLING =============== */
        ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 2px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--text-muted);
            border-radius: 2px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--accent);
        }
        
        /* =============== MOBILE SPECIFIC ADJUSTMENTS =============== */
        @media (max-width: 767px) {
            .header {
                padding: 15px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .refresh-indicator {
                align-self: flex-start;
            }
            
            .dashboard {
                padding: 0 15px;
            }
            
            .temp-panel, .graph-panel {
                padding: 15px;
            }
            
            .modal {
                margin: 10px;
                max-height: 85vh;
            }
            
            .modal-actions {
                justify-content: center;
            }
            
            .modal-actions .btn {
                min-width: 100px;
                font-size: 10px;
                padding: 8px 10px;
            }
        }
        
        /* =============== TOUCH FRIENDLY ELEMENTS FOR MOBILE =============== */
        @media (max-width: 480px) {
            .btn {
                padding: 10px 16px;
                font-size: 13px;
            }
            
            .modal-tab {
                padding: 10px;
                font-size: 11px;
                min-width: 70px;
            }
            
            .status {
                padding: 10px 20px;
                font-size: 13px;
            }
            
            .modal-actions .btn {
                min-width: 90px;
                font-size: 9px;
                padding: 6px 8px;
            }
        }
        
        /* =============== PRINT STYLES =============== */
        @media print {
            .header, .btn, .modal-overlay, .notification {
                display: none !important;
            }
            
            .container {
                padding: 0;
            }
            
            .dashboard {
                display: block;
            }
            
            .temp-panel, .graph-panel {
                page-break-inside: avoid;
                margin-bottom: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header - NO BORDER, NO BACKGROUND -->
        <div class="header">
            <div class="logo-container">
                <div class="logo">iTM</div>
                <div>
                    <h1>iTM</h1>
                    <div class="header-subtitle">Temperature Monitoring System</div>
                </div>
            </div>
        </div>

        <!-- Main Dashboard - RESPONSIVE -->
        <div class="dashboard">
            <!-- Temperature Panel - NO BORDER, NO BACKGROUND -->
            <div class="temp-panel">
                <div class="temp-label">Current Temperature</div>
                <div class="temp-display" id="temperature">-- °C</div>
                <div class="status unknown" id="statusIndicator">UNKNOWN</div>
                <div id="lastUpdate" class="meta">Last updated: --</div>
                
                <!-- Refresh indicator moved here -->
                <div class="refresh-indicator">
                    <span>Auto-refresh: <?php echo (int)$CONFIG['check_interval']; ?> minutes</span>
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

            <!-- Live Graph Panel - NO BORDER, NO BACKGROUND -->
            <div class="graph-panel">
                <div class="graph-header">
                    <div class="graph-title">Live Temperature Graph</div>
                    <button class="btn btn-info" onclick="openLogsModal()">
                        View Logs
                    </button>
                </div>
                <div class="graph-container">
                    <canvas id="liveChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Consolidated Logs Modal -->
    <div class="modal-overlay" id="logsModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title">Temperature Logs & Analytics</div>
                <button class="modal-close" onclick="closeLogsModal()">×</button>
            </div>
            
            <div class="modal-tabs">
                <div class="modal-tab active" onclick="showModalTab('live')">Live Logs</div>
                <div class="modal-tab" onclick="showModalTab('history')">History Logs</div>
                <div class="modal-tab" onclick="showModalTab('graph')">History Graph</div>
            </div>
            
            <div class="modal-content">
                <!-- Live Logs Tab -->
                <div id="live-tab" class="tab-content">
                    <div class="logs-container">
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
                                <tr><td colspan="4" style="text-align: center; padding: 30px;">Loading live logs...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="modal-actions">
                        <button class="btn btn-info" onclick="refreshLiveLogs()">
                            Refresh Logs
                        </button>
                        <button class="btn btn-success" onclick="downloadCSV()">
                            Download CSV
                        </button>
                        <button class="btn btn-success" onclick="downloadLogFile()">
                            Download Log File
                        </button>
                    </div>
                </div>
                
                <!-- History Logs Tab -->
                <div id="history-tab" class="tab-content" style="display: none;">
                    <div class="date-filter">
                        <div class="date-group">
                            <div class="date-label">Start Date</div>
                            <input type="date" id="historyStartDate" class="date-input" value="<?php echo date('Y-m-d', strtotime('-7 days')); ?>">
                        </div>
                        <div class="date-group">
                            <div class="date-label">End Date</div>
                            <input type="date" id="historyEndDate" class="date-input" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <button class="btn btn-primary" onclick="loadHistoryLogs()">
                            Search
                        </button>
                    </div>
                    <div class="logs-container">
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
                                <tr><td colspan="4" style="text-align: center; padding: 30px;">Select date range and click Search</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="modal-actions">
                        <button class="btn btn-success" onclick="downloadFilteredCSV()">
                            Download Filtered CSV
                        </button>
                        <button class="btn btn-success" onclick="downloadFilteredLogFile()">
                            Download Filtered Log File
                        </button>
                    </div>
                </div>
                
                <!-- History Graph Tab -->
                <div id="graph-tab" class="tab-content" style="display: none;">
                    <div class="date-filter">
                        <div class="date-group">
                            <div class="date-label">Start Date</div>
                            <input type="date" id="graphStartDate" class="date-input" value="<?php echo date('Y-m-d', strtotime('-7 days')); ?>">
                        </div>
                        <div class="date-group">
                            <div class="date-label">End Date</div>
                            <input type="date" id="graphEndDate" class="date-input" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <button class="btn btn-primary" onclick="loadHistoryGraph()">
                            Show Graph
                        </button>
                    </div>
                    <div class="graph-container" style="height: 300px; margin-top: 15px;">
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
        // =============== ENHANCED GRAPH CONFIGURATION ===============
        const AUTO_REFRESH_MS = 5 * 60000; // 5 minutes (CHANGED AS REQUESTED)
        const WARNING_TEMP = <?php echo $CONFIG['warning_temp']; ?>;
        const CRITICAL_TEMP = <?php echo $CONFIG['critical_temp']; ?>;
        
        let liveChart = null;
        let historyChart = null;
        let currentTemp = null;
        let currentStatus = 'UNKNOWN';
        let chartData = {
            labels: [],
            data: [],
            status: []
        };
        let liveLogsInterval = null;
        let temperatureUpdateInterval = null;

        // Initialize Enhanced Live Chart with Fixed Threshold Lines
        function initLiveChart() {
            const ctx = document.getElementById('liveChart').getContext('2d');
            
            // Create gradient for chart area
            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(59, 130, 246, 0.2)');
            gradient.addColorStop(1, 'rgba(59, 130, 246, 0.05)');
            
            liveChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartData.labels,
                    datasets: [
                        {
                            label: 'Temperature (°C)',
                            data: chartData.data,
                            borderColor: '#3b82f6',
                            backgroundColor: gradient,
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: function(context) {
                                const value = context.dataset.data[context.dataIndex];
                                if (value >= CRITICAL_TEMP) return '#ef4444';
                                if (value >= WARNING_TEMP) return '#f59e0b';
                                return '#10b981';
                            },
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointHitRadius: 8
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(30, 41, 59, 0.95)',
                            titleColor: '#f1f5f9',
                            bodyColor: '#cbd5e1',
                            borderColor: '#475569',
                            borderWidth: 1,
                            cornerRadius: 8,
                            padding: 12,
                            // SIMPLIFIED TOOLTIP - ONLY SHOW TEMPERATURE IN WHOLE NUMBER
                            callbacks: {
                                label: function(context) {
                                    const value = context.parsed.y;
                                    // Round to whole number and show only temperature
                                    return `${Math.round(value)}°C`;
                                },
                                // REMOVED afterLabel callback completely to remove status/warning text
                                afterLabel: null
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(71, 85, 105, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 10,
                                    weight: '500'
                                },
                                maxTicksLimit: 8,
                                padding: 5
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(71, 85, 105, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 10,
                                    weight: '500'
                                },
                                callback: function(value) {
                                    return value + '°C';
                                },
                                padding: 10
                            },
                            suggestedMin: 0,
                            suggestedMax: Math.max(CRITICAL_TEMP + 10, 40)
                        }
                    },
                    animation: {
                        duration: 500,
                        easing: 'easeOutQuart'
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
            
            // Add FIXED threshold lines that extend fully across the graph
            addFixedThresholdLines();
        }

        function addFixedThresholdLines() {
            if (!liveChart) return;
            
            // Get current number of labels or use a fixed number
            const labelCount = chartData.labels.length || 10;
            
            // Create arrays with the same value for all labels
            const warningLineData = new Array(labelCount).fill(WARNING_TEMP);
            const criticalLineData = new Array(labelCount).fill(CRITICAL_TEMP);
            
            // Add warning line - NO HOVER TEXT, NO TOOLTIPS
            liveChart.data.datasets.push({
                label: 'Warning Threshold',
                data: warningLineData,
                borderColor: '#f59e0b',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 5],
                fill: false,
                pointRadius: 0,
                pointHoverRadius: 0,
                // DISABLE TOOLTIPS AND HOVER FOR THRESHOLD LINES
                tooltip: {
                    enabled: false
                },
                hover: {
                    mode: null
                }
            });
            
            // Add critical line - NO HOVER TEXT, NO TOOLTIPS
            liveChart.data.datasets.push({
                label: 'Critical Threshold',
                data: criticalLineData,
                borderColor: '#ef4444',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 5],
                fill: false,
                pointRadius: 0,
                pointHoverRadius: 0,
                // DISABLE TOOLTIPS AND HOVER FOR THRESHOLD LINES
                tooltip: {
                    enabled: false
                },
                hover: {
                    mode: null
                }
            });
            
            liveChart.update('none');
        }

        // Update Live Chart with new temperature
        function updateLiveChart(temp, timestamp) {
            if (!liveChart) return;
            
            const timeLabel = timestamp.split(' ')[1].substring(0, 5); // HH:MM
            
            // Add new data point
            chartData.labels.push(timeLabel);
            chartData.data.push(temp);
            
            // Determine status for this point
            let status = 'NORMAL';
            if (temp >= CRITICAL_TEMP) status = 'CRITICAL';
            else if (temp >= WARNING_TEMP) status = 'WARNING';
            chartData.status.push(status);
            
            // Update threshold lines to maintain full width
            if (liveChart.data.datasets.length > 1) {
                // Always keep threshold lines the same length as data points
                liveChart.data.datasets[1].data.push(WARNING_TEMP);
                liveChart.data.datasets[2].data.push(CRITICAL_TEMP);
            }
            
            // Keep only last 15 points for mobile, 25 for tablet, 30 for desktop
            let maxPoints = 15;
            if (window.innerWidth >= 768 && window.innerWidth < 1024) maxPoints = 25;
            if (window.innerWidth >= 1024) maxPoints = 30;
            
            if (chartData.labels.length > maxPoints) {
                chartData.labels.shift();
                chartData.data.shift();
                chartData.status.shift();
                if (liveChart.data.datasets.length > 1) {
                    // Also shift threshold lines to maintain alignment
                    liveChart.data.datasets[1].data.shift();
                    liveChart.data.datasets[2].data.shift();
                }
            }
            
            // Update chart
            liveChart.data.labels = [...chartData.labels];
            liveChart.data.datasets[0].data = [...chartData.data];
            
            // Update point colors based on status
            liveChart.data.datasets[0].pointBackgroundColor = chartData.data.map((value, index) => {
                const status = chartData.status[index];
                if (status === 'CRITICAL') return '#ef4444';
                if (status === 'WARNING') return '#f59e0b';
                return '#10b981';
            });
            
            // Ensure threshold lines have same length as data
            if (liveChart.data.datasets.length > 1) {
                const warningData = new Array(chartData.labels.length).fill(WARNING_TEMP);
                const criticalData = new Array(chartData.labels.length).fill(CRITICAL_TEMP);
                liveChart.data.datasets[1].data = warningData;
                liveChart.data.datasets[2].data = criticalData;
            }
            
            // Update chart max if needed
            const maxTemp = Math.max(...chartData.data);
            if (maxTemp > liveChart.options.scales.y.suggestedMax - 5) {
                liveChart.options.scales.y.suggestedMax = Math.max(CRITICAL_TEMP + 10, maxTemp + 5);
            }
            
            liveChart.update({
                duration: 500,
                easing: 'easeOutQuart'
            });
        }

        // Temperature Functions
        async function getTemperature() {
            try {
                const response = await fetch('?action=get_temp');
                const data = await response.json();

                if (data.success) {
                    currentTemp = data.temperature;
                    currentStatus = data.status;
                    
                    // Update temperature display
                    const tempEl = document.getElementById('temperature');
                    tempEl.textContent = data.temperature.toFixed(1) + ' °C';
                    tempEl.className = 'temp-display temp-' + data.status.toLowerCase();
                    
                    // Update status indicator
                    const statusEl = document.getElementById('statusIndicator');
                    statusEl.textContent = data.status;
                    statusEl.className = 'status ' + data.status.toLowerCase();
                    
                    // Update timestamp
                    document.getElementById('lastUpdate').textContent = 'Last updated: ' + (data.timestamp || '');
                    
                    // Update stats
                    updateStats(data.temperature);
                    
                    // Update live chart
                    updateLiveChart(data.temperature, data.timestamp);
                    
                    // Send to log API
                    await sendTempToLog(data.temperature);
                }
            } catch (error) {
                console.error('Error getting temperature:', error);
            }
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
        function openLogsModal() {
            document.getElementById('logsModal').style.display = 'flex';
            showModalTab('live');
            refreshLiveLogs();
            startLiveLogsRefresh();
        }

        function closeLogsModal() {
            document.getElementById('logsModal').style.display = 'none';
            stopLiveLogsRefresh();
        }

        function showModalTab(tabName) {
            // Update tabs
            document.querySelectorAll('.modal-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Show selected tab content
            document.getElementById('live-tab').style.display = 'none';
            document.getElementById('history-tab').style.display = 'none';
            document.getElementById('graph-tab').style.display = 'none';
            
            document.getElementById(tabName + '-tab').style.display = 'block';
            
            if (tabName === 'history') {
                // Initialize date pickers with default values
                document.getElementById('historyStartDate').value = getDateString(-7);
                document.getElementById('historyEndDate').value = getDateString(0);
            } else if (tabName === 'graph') {
                // Initialize date pickers for graph
                document.getElementById('graphStartDate').value = getDateString(-7);
                document.getElementById('graphEndDate').value = getDateString(0);
                if (!historyChart) {
                    initEnhancedHistoryChart();
                }
            }
        }

        function getDateString(daysOffset) {
            const date = new Date();
            date.setDate(date.getDate() + daysOffset);
            return date.toISOString().split('T')[0];
        }

        // Logs Functions
        async function refreshLiveLogs() {
            try {
                const response = await fetch('?action=get_storage_logs');
                const data = await response.json();
                
                if (data.success && data.logs.length > 0) {
                    const tbody = document.getElementById('liveLogsBody');
                    tbody.innerHTML = data.logs.slice().reverse().slice(0, 30).map(log => `
                        <tr>
                            <td>${log.timestamp}</td>
                            <td><strong>${log.temperature.toFixed(1)}°C</strong></td>
                            <td>
                                <span class="status ${getStatusClass(log.temperature)}" style="padding: 3px 8px; font-size: 10px;">
                                    ${getStatusText(log.temperature)}
                                </span>
                            </td>
                            <td><code style="font-size: 10px;">${log.ip}</code></td>
                        </tr>
                    `).join('');
                } else {
                    document.getElementById('liveLogsBody').innerHTML = 
                        '<tr><td colspan="4" style="text-align: center; padding: 30px;">No logs available</td></tr>';
                }
            } catch (error) {
                console.error('Error loading live logs:', error);
            }
        }

        function startLiveLogsRefresh() {
            if (liveLogsInterval) clearInterval(liveLogsInterval);
            liveLogsInterval = setInterval(refreshLiveLogs, 5000);
        }

        function stopLiveLogsRefresh() {
            if (liveLogsInterval) {
                clearInterval(liveLogsInterval);
                liveLogsInterval = null;
            }
        }

        async function loadHistoryLogs() {
            const startDate = document.getElementById('historyStartDate').value;
            const endDate = document.getElementById('historyEndDate').value;
            
            if (!startDate || !endDate) {
                showNotification('Please select both start and end dates', 'error');
                return;
            }
            
            showLoading(true);
            try {
                const response = await fetch(`?action=get_filtered_logs&start_date=${startDate}&end_date=${endDate}`);
                const data = await response.json();
                
                if (data.success && data.logs.length > 0) {
                    const tbody = document.getElementById('historyLogsBody');
                    tbody.innerHTML = data.logs.slice().reverse().map(log => `
                        <tr>
                            <td>${log.timestamp}</td>
                            <td><strong>${log.temperature.toFixed(1)}°C</strong></td>
                            <td>
                                <span class="status ${getStatusClass(log.temperature)}" style="padding: 3px 8px; font-size: 10px;">
                                    ${getStatusText(log.temperature)}
                                </span>
                            </td>
                            <td><code style="font-size: 10px;">${log.ip}</code></td>
                        </tr>
                    `).join('');
                    showNotification(`Found ${data.logs.length} logs for selected date range`, 'success');
                } else {
                    document.getElementById('historyLogsBody').innerHTML = 
                        '<tr><td colspan="4" style="text-align: center; padding: 30px;">No logs found for selected date range</td></tr>';
                    showNotification('No logs found for selected date range', 'error');
                }
            } catch (error) {
                console.error('Error loading history logs:', error);
                showNotification('Error loading history logs', 'error');
            }
            showLoading(false);
        }

        // ENHANCED History Graph Functions - VISIBLE LINE CHART FOR EACH STATUS
        function initEnhancedHistoryChart() {
            const ctx = document.getElementById('historyChart').getContext('2d');
            
            historyChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: 'Normal',
                            data: [],
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.2)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: '#10b981',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Warning',
                            data: [],
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.2)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: '#f59e0b',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Critical',
                            data: [],
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.2)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: '#ef4444',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                color: '#cbd5e1',
                                font: {
                                    size: 12,
                                    weight: '600'
                                },
                                padding: 20,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30, 41, 59, 0.95)',
                            titleColor: '#f1f5f9',
                            bodyColor: '#cbd5e1',
                            borderColor: '#475569',
                            borderWidth: 1,
                            cornerRadius: 8,
                            padding: 12,
                            callbacks: {
                                label: function(context) {
                                    const value = context.parsed.y;
                                    // Round to whole number
                                    return `${Math.round(value)}°C`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(71, 85, 105, 0.2)',
                                drawBorder: false
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 10,
                                    weight: '500'
                                },
                                maxTicksLimit: 12
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(71, 85, 105, 0.2)',
                                drawBorder: false
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 10,
                                    weight: '500'
                                },
                                callback: function(value) {
                                    return value + '°C';
                                }
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
        }

        async function loadHistoryGraph() {
            const startDate = document.getElementById('graphStartDate').value;
            const endDate = document.getElementById('graphEndDate').value;
            
            if (!startDate || !endDate) {
                showNotification('Please select both start and end dates', 'error');
                return;
            }
            
            showLoading(true);
            try {
                const response = await fetch(`?action=get_graph_data&start_date=${startDate}&end_date=${endDate}`);
                const data = await response.json();
                
                if (data.success && data.data.length > 0) {
                    // Sort data by timestamp
                    data.data.sort((a, b) => new Date(a.x) - new Date(b.x));
                    
                    // Create labels with better formatting
                    const labels = [];
                    const normalData = [];
                    const warningData = [];
                    const criticalData = [];
                    
                    // Process data in batches for better performance
                    const maxPoints = window.innerWidth < 768 ? 60 : 120;
                    const step = Math.ceil(data.data.length / maxPoints);
                    
                    for (let i = 0; i < data.data.length; i += step) {
                        const item = data.data[i];
                        const date = new Date(item.x);
                        
                        // Format label: "Jan 12 14:30"
                        const label = date.toLocaleDateString('en-US', { 
                            month: 'short', 
                            day: 'numeric' 
                        }) + ' ' + date.toLocaleTimeString('en-US', { 
                            hour: '2-digit', 
                            minute: '2-digit',
                            hour12: false 
                        });
                        
                        labels.push(label);
                        
                        // Add data to appropriate dataset
                        if (item.status === 'CRITICAL') {
                            criticalData.push(item.y);
                            warningData.push(null);
                            normalData.push(null);
                        } else if (item.status === 'WARNING') {
                            criticalData.push(null);
                            warningData.push(item.y);
                            normalData.push(null);
                        } else {
                            criticalData.push(null);
                            warningData.push(null);
                            normalData.push(item.y);
                        }
                    }
                    
                    if (historyChart) {
                        // Update chart with new data
                        historyChart.data.labels = labels;
                        historyChart.data.datasets[0].data = normalData;
                        historyChart.data.datasets[1].data = warningData;
                        historyChart.data.datasets[2].data = criticalData;
                        
                        // Calculate stats for notification
                        const normalCount = normalData.filter(val => val !== null).length;
                        const warningCount = warningData.filter(val => val !== null).length;
                        const criticalCount = criticalData.filter(val => val !== null).length;
                        
                        historyChart.update();
                        
                        showNotification(`Graph loaded: ${normalCount} Normal, ${warningCount} Warning, ${criticalCount} Critical points`, 'success');
                    }
                } else {
                    if (historyChart) {
                        historyChart.data.labels = [];
                        historyChart.data.datasets[0].data = [];
                        historyChart.data.datasets[1].data = [];
                        historyChart.data.datasets[2].data = [];
                        historyChart.update();
                    }
                    showNotification('No data found for selected date range', 'error');
                }
            } catch (error) {
                console.error('Error loading history graph:', error);
                showNotification('Error loading history graph', 'error');
            }
            showLoading(false);
        }

        // Download Functions
        function downloadCSV() {
            window.open('?action=download_logs&type=csv', '_blank');
        }

        function downloadLogFile() {
            window.open('?action=download_logs&type=storage', '_blank');
        }

        function downloadFilteredCSV() {
            const startDate = document.getElementById('historyStartDate').value;
            const endDate = document.getElementById('historyEndDate').value;
            
            if (!startDate || !endDate) {
                showNotification('Please select date range first', 'error');
                return;
            }
            
            showNotification('Downloading CSV data', 'success');
            setTimeout(() => {
                downloadCSV();
            }, 500);
        }

        function downloadFilteredLogFile() {
            const startDate = document.getElementById('historyStartDate').value;
            const endDate = document.getElementById('historyEndDate').value;
            
            if (!startDate || !endDate) {
                showNotification('Please select date range first', 'error');
                return;
            }
            
            showNotification('Downloading log file', 'success');
            setTimeout(() => {
                downloadLogFile();
            }, 500);
        }

        // Utility Functions
        function getStatusClass(temp) {
            if (temp >= CRITICAL_TEMP) return 'critical';
            if (temp >= WARNING_TEMP) return 'warning';
            return 'normal';
        }

        function getStatusText(temp) {
            if (temp >= CRITICAL_TEMP) return 'CRITICAL';
            if (temp >= WARNING_TEMP) return 'WARNING';
            return 'NORMAL';
        }

        function showNotification(message, type) {
            const el = document.getElementById('notification');
            el.textContent = message;
            el.style.display = 'block';
            el.className = 'notification' + (type === 'error' ? ' error' : '');
            el.style.borderLeftColor = type === 'success' ? '#10b981' : '#ef4444';
            
            setTimeout(() => {
                el.style.display = 'none';
            }, 3000);
        }

        function showLoading(show) {
            document.getElementById('loading').style.display = show ? 'block' : 'none';
        }

        async function sendTempToLog(temp) {
            const payload = { temp };
            try {
                await fetch('./api/log_temp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            } catch (err) {
                console.error('Failed to send temp to log:', err);
            }
        }

        // Handle window resize for responsive adjustments
        function handleResize() {
            if (liveChart) {
                liveChart.resize();
            }
            if (historyChart) {
                historyChart.resize();
            }
        }

        // Initialize
        window.onload = function() {
            // Initialize enhanced live chart
            initLiveChart();
            
            // Start temperature monitoring
            getTemperature();
            
            // Set up auto-refresh for temperature (every 5 minutes as requested)
            temperatureUpdateInterval = setInterval(getTemperature, AUTO_REFRESH_MS);
            
            // Initialize with some demo data
            const now = new Date();
            for (let i = 0; i < 8; i++) {
                const time = new Date(now.getTime() - (8 - i) * 5 * 60000); // 5 minute intervals
                const timeStr = time.getHours().toString().padStart(2, '0') + ':' + 
                              time.getMinutes().toString().padStart(2, '0');
                chartData.labels.push(timeStr);
                const temp = 20 + Math.random() * 5;
                chartData.data.push(temp);
                
                let status = 'NORMAL';
                if (temp >= CRITICAL_TEMP) status = 'CRITICAL';
                else if (temp >= WARNING_TEMP) status = 'WARNING';
                chartData.status.push(status);
            }
            
            if (liveChart) {
                liveChart.update();
                addFixedThresholdLines();
            }
            
            // Add resize listener
            window.addEventListener('resize', handleResize);
            
            // Handle mobile orientation change
            window.addEventListener('orientationchange', function() {
                setTimeout(handleResize, 100);
            });
        };
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (temperatureUpdateInterval) {
                clearInterval(temperatureUpdateInterval);
            }
            if (liveLogsInterval) {
                clearInterval(liveLogsInterval);
            }
        });
    </script>
</body>
</html>
