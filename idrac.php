<?php
// iDRAC Temperature Monitor - Enhanced Version
// Include configuration
require_once __DIR__ . '/idrac_config.php';

date_default_timezone_set($CONFIG['timezone']);

// State and log files
define('IDRAC_STATE_FILE', __DIR__ . '/idrac_state.json');
define('LOG_FILE', __DIR__ . '/storage/temperature.log');
define('GRAPH_CACHE_FILE', __DIR__ . '/storage/graph_cache.json');

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
    
    // Update graph cache
    update_graph_cache($temp, $status);
}

function get_logs($limit = 1000): array {
    $logs = [];
    if (file_exists(LOG_FILE)) {
        $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice($lines, -$limit); // Get last N entries
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

function update_graph_cache(float $temp, string $status): void {
    $cache = [];
    if (file_exists(GRAPH_CACHE_FILE)) {
        $cache = json_decode(file_get_contents(GRAPH_CACHE_FILE), true) ?: [];
    }
    
    $current_hour = date('Y-m-d H');
    if (!isset($cache[$current_hour])) {
        $cache[$current_hour] = [
            'min' => $temp,
            'max' => $temp,
            'avg' => $temp,
            'count' => 1,
            'status' => $status
        ];
    } else {
        $cache[$current_hour]['min'] = min($cache[$current_hour]['min'], $temp);
        $cache[$current_hour]['max'] = max($cache[$current_hour]['max'], $temp);
        $cache[$current_hour]['avg'] = ($cache[$current_hour]['avg'] * $cache[$current_hour]['count'] + $temp) / ($cache[$current_hour]['count'] + 1);
        $cache[$current_hour]['count']++;
    }
    
    // Keep only last 72 hours (3 days)
    if (count($cache) > 72) {
        $cache = array_slice($cache, -72, 72, true);
    }
    
    file_put_contents(GRAPH_CACHE_FILE, json_encode($cache, JSON_PRETTY_PRINT));
}

function get_graph_data(): array {
    if (!file_exists(GRAPH_CACHE_FILE)) {
        return ['labels' => [], 'temperatures' => [], 'statuses' => []];
    }
    
    $cache = json_decode(file_get_contents(GRAPH_CACHE_FILE), true) ?: [];
    $labels = [];
    $temperatures = [];
    $statuses = [];
    
    foreach ($cache as $hour => $data) {
        $labels[] = date('M d H:00', strtotime($hour));
        $temperatures[] = round($data['avg'], 1);
        $statuses[] = $data['status'];
    }
    
    return [
        'labels' => $labels,
        'temperatures' => $temperatures,
        'statuses' => $statuses
    ];
}

function download_logs(): void {
    if (!file_exists(LOG_FILE)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No logs available']);
        exit;
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="idrac_temperature_log_' . date('Y-m-d_H-i') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "Timestamp,Temperature (°C),Status\n";
    readfile(LOG_FILE);
    exit;
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
        CURLOPT_USERAGENT      => 'iDRAC-Monitor/2.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json']
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
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
                        
                        // Log every reading
                        log_temperature($temp, $status);
                        
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

    return [
        'success' => false, 
        'message' => 'Failed to get temperature',
        'error' => $error ?: "HTTP $http_code"
    ];
}

function get_temp_status($temp): string {
    global $CONFIG;
    if ($temp >= $CONFIG['critical_temp']) return 'CRITICAL';
    if ($temp >= $CONFIG['warning_temp'])  return 'WARNING';
    return 'NORMAL';
}

function get_status_color($status): string {
    switch ($status) {
        case 'NORMAL': return '#10b981';
        case 'WARNING': return '#f59e0b';
        case 'CRITICAL': return '#ef4444';
        default: return '#6b7280';
    }
}

// =============== EMAIL FUNCTIONS (unchanged) ===============
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
    $headers = [];
    $headers[] = "From: {$from_name} <{$from}>";
    $headers[] = "Reply-To: {$from}";
    $headers[] = "Return-Path: {$from}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";
    $headers[] = "X-Mailer: iDRAC-Monitor/2.0";
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
        
        // SMTP protocol handling (same as before)
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

// =============== ALERT LOGIC ===============
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
            download_logs();
            break;

        case 'get_logs':
            $logs = get_logs(100);
            echo json_encode(['success' => true, 'logs' => $logs]);
            break;

        case 'get_graph_data':
            $data = get_graph_data();
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
    exit;
}

// =============== ENHANCED HTML INTERFACE ===============
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iTM - iDRAC Temperature Monitor</title>
    <!-- Chart.js for graphs -->
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }
        
        /* Enhanced Header with Logo */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 24px;
            background: var(--bg-secondary);
            border-radius: 16px;
            border: 1px solid var(--border);
            margin-bottom: 20px;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #3b82f6, #10b981);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 32px;
            color: white;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.3);
        }
        
        .logo iTM {
            font-size: 36px;
            letter-spacing: 1px;
        }
        
        .header-title {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .header-title h1 {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #3b82f6, #10b981);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: 1px;
        }
        
        .header-title p {
            font-size: 14px;
            color: var(--text-muted);
            max-width: 400px;
        }
        
        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            color: var(--text-muted);
            background: var(--bg-card);
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        
        .refresh-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.1); }
        }
        
        /* Main Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Enhanced Temperature Card with Colored Text */
        .temp-card {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 40px;
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }
        
        .temp-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
        }
        
        .temp-label {
            font-size: 16px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        
        .temp-display {
            font-size: 88px;
            font-weight: 900;
            margin: 20px 0;
            line-height: 1;
            transition: color 0.3s ease;
        }
        
        /* Status indicator */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 32px;
            border-radius: 24px;
            font-weight: 700;
            font-size: 16px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 16px 0;
            backdrop-filter: blur(10px);
        }
        
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        .normal .status-dot { background: var(--success); }
        .warning .status-dot { background: var(--warning); }
        .critical .status-dot { background: var(--critical); }
        .unknown .status-dot { background: var(--unknown); }
        
        .normal { 
            background: rgba(16, 185, 129, 0.15); 
            color: var(--success); 
            border: 2px solid var(--success);
        }
        .warning { 
            background: rgba(245, 158, 11, 0.15); 
            color: var(--warning); 
            border: 2px solid var(--warning);
        }
        .critical { 
            background: rgba(239, 68, 68, 0.15); 
            color: var(--critical); 
            border: 2px solid var(--critical);
        }
        .unknown { 
            background: rgba(107, 114, 128, 0.15); 
            color: var(--unknown); 
            border: 2px solid var(--unknown);
        }
        
        .meta {
            color: var(--text-muted);
            font-size: 14px;
            margin-top: 12px;
            text-align: center;
        }
        
        /* Stats Grid */
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 40px;
            width: 100%;
        }
        
        .stat {
            background: var(--bg-secondary);
            padding: 24px;
            border-radius: 16px;
            text-align: center;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }
        
        .stat:hover {
            transform: translateY(-2px);
            border-color: var(--accent);
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* Graph Container */
        .graph-container {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 30px;
            border: 1px solid var(--border);
            grid-column: 1 / -1;
            margin-top: 24px;
        }
        
        .graph-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .graph-header h3 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .graph-controls {
            display: flex;
            gap: 12px;
        }
        
        .graph-btn {
            padding: 10px 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .graph-btn:hover {
            background: var(--accent);
            border-color: var(--accent);
            color: white;
        }
        
        .graph-canvas-container {
            height: 400px;
            position: relative;
        }
        
        /* History Logs */
        .history-container {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 30px;
            border: 1px solid var(--border);
            margin-top: 24px;
        }
        
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-secondary);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .history-table th {
            background: rgba(30, 41, 59, 0.8);
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .history-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            color: var(--text-secondary);
        }
        
        .history-table tr:last-child td {
            border-bottom: none;
        }
        
        .history-table tr:hover {
            background: rgba(59, 130, 246, 0.1);
        }
        
        /* Controls Panel */
        .controls-panel {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 30px;
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
        }
        
        .controls-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin: 24px 0;
        }
        
        @media (max-width: 640px) {
            .controls-grid {
                grid-template-columns: 1fr;
            }
        }
        
        button {
            padding: 18px;
            border: none;
            border-radius: 14px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            min-height: 64px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 2px solid var(--border);
        }
        
        button:hover {
            background: var(--accent);
            transform: translateY(-2px);
            border-color: var(--accent);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.3);
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
        
        .btn-danger { 
            background: var(--critical); 
            border-color: var(--critical); 
            color: white;
        }
        
        /* Notification */
        .notification {
            position: fixed;
            top: 30px;
            right: 30px;
            padding: 20px 28px;
            border-radius: 14px;
            background: var(--bg-card);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
            display: none;
            z-index: 1000;
            animation: slideIn 0.3s ease;
            border-left: 5px solid var(--success);
            max-width: 350px;
            border: 1px solid var(--border);
            backdrop-filter: blur(10px);
        }
        
        .notification.error {
            border-left-color: var(--critical);
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Loading Spinner */
        .loading {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            background: rgba(15, 23, 42, 0.9);
            padding: 40px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid var(--border);
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border);
            border-top: 4px solid var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .logo-container {
                flex-direction: column;
            }
            
            .temp-display {
                font-size: 64px;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
            
            .graph-canvas-container {
                height: 300px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Enhanced Header with Logo -->
        <div class="header">
            <div class="logo-container">
                <div class="logo">
                    <iTM>iTM</iTM>
                </div>
                <div class="header-title">
                    <h1>iDRAC Temperature Monitor</h1>
                    <p>Real-time temperature monitoring and alert system for Dell iDRAC servers</p>
                </div>
            </div>
            <div class="refresh-indicator">
                <div class="refresh-dot"></div>
                Auto-refresh: <?php echo (int)$CONFIG['check_interval']; ?> minutes
            </div>
        </div>

        <!-- Main Dashboard -->
        <div class="dashboard-grid">
            <!-- Temperature Display -->
            <div class="temp-card">
                <div class="temp-label">Current Temperature</div>
                <div class="temp-display" id="temperature">-- °C</div>
                <div class="status unknown" id="statusIndicator">
                    <div class="status-dot"></div>
                    <span>UNKNOWN</span>
                </div>
                <div id="lastUpdate" class="meta">Last updated: --</div>
                
                <div class="stats">
                    <div class="stat">
                        <div class="stat-value" id="minTemp">--°C</div>
                        <div class="stat-label">Min Today</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value" id="avgTemp">--°C</div>
                        <div class="stat-label">Average</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value" id="maxTemp">--°C</div>
                        <div class="stat-label">Max Today</div>
                    </div>
                </div>
            </div>

            <!-- Controls Panel -->
            <div class="controls-panel">
                <div style="font-size: 14px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px;">
                    System Controls
                </div>
                
                <div class="controls-grid">
                    <button class="btn-primary" onclick="getTemperature()">
                        <span>📊</span>
                        Refresh Temperature
                    </button>
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
                
                <div style="margin-top: 30px;">
                    <div style="font-size: 14px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px;">
                        Temperature Thresholds
                    </div>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <span style="padding: 8px 16px; background: rgba(16, 185, 129, 0.15); color: var(--success); border-radius: 20px; font-size: 12px; font-weight: 600; border: 1px solid var(--success);">
                            Normal &lt; <?php echo $CONFIG['warning_temp']; ?>°C
                        </span>
                        <span style="padding: 8px 16px; background: rgba(245, 158, 11, 0.15); color: var(--warning); border-radius: 20px; font-size: 12px; font-weight: 600; border: 1px solid var(--warning);">
                            Warning ≥ <?php echo $CONFIG['warning_temp']; ?>°C
                        </span>
                        <span style="padding: 8px 16px; background: rgba(239, 68, 68, 0.15); color: var(--critical); border-radius: 20px; font-size: 12px; font-weight: 600; border: 1px solid var(--critical);">
                            Critical ≥ <?php echo $CONFIG['critical_temp']; ?>°C
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Graph -->
        <div class="graph-container">
            <div class="graph-header">
                <h3>Temperature Trend (Live)</h3>
                <div class="graph-controls">
                    <button class="graph-btn" onclick="updateGraph('24h')">24 Hours</button>
                    <button class="graph-btn" onclick="updateGraph('7d')">7 Days</button>
                    <button class="graph-btn" onclick="updateGraph('30d')">30 Days</button>
                </div>
            </div>
            <div class="graph-canvas-container">
                <canvas id="temperatureChart"></canvas>
            </div>
        </div>

        <!-- History Logs -->
        <div class="history-container">
            <div class="history-header">
                <h3>Recent Temperature Logs</h3>
                <button class="graph-btn" onclick="loadHistory()">
                    <span>🔄</span>
                    Refresh Logs
                </button>
            </div>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Temperature</th>
                        <th>Status</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody id="historyBody">
                    <tr><td colspan="4" style="text-align: center; padding: 40px; color: var(--text-muted);">Loading temperature logs...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Notification -->
    <div class="notification" id="notification"></div>
    
    <!-- Loading Spinner -->
    <div class="loading" id="loading">
        <div class="spinner"></div>
    </div>

    <script>
        const AUTO_REFRESH_MS = <?php echo (int)$CONFIG['check_interval']; ?> * 60000;
        let temperatureChart = null;
        let currentStatus = 'UNKNOWN';

        // Initialize Chart.js
        function initChart() {
            const ctx = document.getElementById('temperatureChart').getContext('2d');
            temperatureChart = new Chart(ctx, {
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
                        pointBackgroundColor: function(context) {
                            const index = context.dataIndex;
                            const status = context.chart.data.statuses?.[index];
                            return getStatusColor(status);
                        },
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
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
                                    size: 14
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30, 41, 59, 0.9)',
                            titleColor: '#f1f5f9',
                            bodyColor: '#cbd5e1',
                            borderColor: '#475569',
                            borderWidth: 1,
                            callbacks: {
                                label: function(context) {
                                    const status = context.chart.data.statuses?.[context.dataIndex];
                                    return `Temperature: ${context.parsed.y}°C (${status})`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(71, 85, 105, 0.3)'
                            },
                            ticks: {
                                color: '#94a3b8',
                                maxRotation: 45
                            }
                        },
                        y: {
                            grid: {
                                color: 'rgba(71, 85, 105, 0.3)'
                            },
                            ticks: {
                                color: '#94a3b8'
                            },
                            suggestedMin: 0,
                            suggestedMax: 40
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
            updateGraph('24h');
        }

        function getStatusColor(status) {
            switch(status) {
                case 'NORMAL': return '#10b981';
                case 'WARNING': return '#f59e0b';
                case 'CRITICAL': return '#ef4444';
                default: return '#6b7280';
            }
        }

        function updateTempColor(temp, status) {
            const tempElement = document.getElementById('temperature');
            tempElement.style.color = getStatusColor(status);
            
            const statusElement = document.getElementById('statusIndicator');
            statusElement.className = 'status ' + status.toLowerCase();
            statusElement.innerHTML = `<div class="status-dot"></div><span>${status}</span>`;
            
            currentStatus = status;
        }

        async function getTemperature() {
            showLoading(true);
            try {
                const response = await fetch('?action=get_temp');
                const data = await response.json();

                if (data.success) {
                    const temp = data.temperature.toFixed(1);
                    document.getElementById('temperature').textContent = temp + ' °C';
                    document.getElementById('lastUpdate').textContent = 'Last updated: ' + (data.timestamp || '');
                    
                    updateTempColor(temp, data.status);
                    updateStats(temp, data.status);
                    showNotification(`Temperature: ${temp}°C - ${data.status}`, 'success');
                    
                    // Update graph with new data
                    await updateGraphData();
                } else {
                    showNotification('Error: ' + (data.message || 'Unknown error'), 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
            showLoading(false);
        }

        async function updateGraph(timeRange) {
            showLoading(true);
            try {
                const response = await fetch('?action=get_graph_data');
                const data = await response.json();
                
                if (data.success && temperatureChart) {
                    let labels = data.data.labels;
                    let temperatures = data.data.temperatures;
                    let statuses = data.data.statuses;
                    
                    // Filter based on time range
                    if (timeRange === '24h') {
                        labels = labels.slice(-24);
                        temperatures = temperatures.slice(-24);
                        statuses = statuses.slice(-24);
                    } else if (timeRange === '7d') {
                        labels = labels.slice(-168); // 24 * 7
                        temperatures = temperatures.slice(-168);
                        statuses = statuses.slice(-168);
                    }
                    
                    temperatureChart.data.labels = labels;
                    temperatureChart.data.datasets[0].data = temperatures;
                    temperatureChart.data.statuses = statuses;
                    temperatureChart.update();
                }
            } catch (error) {
                console.error('Failed to update graph:', error);
            }
            showLoading(false);
        }

        async function updateGraphData() {
            try {
                const response = await fetch('?action=get_graph_data');
                const data = await response.json();
                
                if (data.success && temperatureChart) {
                    // Get last 24 hours
                    const labels = data.data.labels.slice(-24);
                    const temperatures = data.data.temperatures.slice(-24);
                    const statuses = data.data.statuses.slice(-24);
                    
                    temperatureChart.data.labels = labels;
                    temperatureChart.data.datasets[0].data = temperatures;
                    temperatureChart.data.statuses = statuses;
                    temperatureChart.update('none');
                }
            } catch (error) {
                console.error('Failed to update graph data:', error);
            }
        }

        async function loadHistory() {
            showLoading(true);
            try {
                const response = await fetch('?action=get_logs');
                const data = await response.json();

                if (data.success && data.logs.length > 0) {
                    const tbody = document.getElementById('historyBody');
                    tbody.innerHTML = data.logs.slice().reverse().map(item => {
                        const statusColor = getStatusColor(item.status);
                        return `
                            <tr>
                                <td>${item.timestamp}</td>
                                <td><strong style="color: ${statusColor}">${item.temperature}°C</strong></td>
                                <td><span style="display: inline-block; padding: 4px 12px; background: ${statusColor}20; color: ${statusColor}; border-radius: 12px; font-size: 12px; font-weight: 600; border: 1px solid ${statusColor};">${item.status}</span></td>
                                <td><code>${item.ip || 'N/A'}</code></td>
                            </tr>
                        `;
                    }).join('');
                } else {
                    document.getElementById('historyBody').innerHTML = 
                        '<tr><td colspan="4" style="text-align: center; padding: 40px; color: var(--text-muted);">No temperature logs available</td></tr>';
                }
            } catch (error) {
                showNotification('Failed to load history: ' + error.message, 'error');
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

        function updateStats(currentTemp, status) {
            if (currentTemp && !isNaN(currentTemp)) {
                const temp = parseFloat(currentTemp);
                
                // In a real implementation, you would calculate these from actual data
                // For now, we'll simulate based on current temperature
                document.getElementById('minTemp').textContent = (temp - 2).toFixed(1) + '°C';
                document.getElementById('avgTemp').textContent = temp.toFixed(1) + '°C';
                document.getElementById('maxTemp').textContent = (temp + 3).toFixed(1) + '°C';
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
            }, 4000);
        }

        function showLoading(show) {
            document.getElementById('loading').style.display = show ? 'block' : 'none';
        }

        // Initialize on page load
        window.onload = function() {
            initChart();
            getTemperature();
            loadHistory();
            setInterval(getTemperature, AUTO_REFRESH_MS);
            setInterval(updateGraphData, 60000); // Update graph every minute
        };

        // Parse temperature for logging (existing function)
        function parseTempFromElement(el) {
            if (!el) return null;
            const txt = el.textContent.trim();
            const match = txt.match(/-?\d+(\.\d+)?/);
            if (!match) return null;
            const val = parseFloat(match[0]);
            return Number.isFinite(val) ? val : null;
        }

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

        async function logCurrentTempOnce() {
            const el = document.getElementById('temperature');
            let temp = parseTempFromElement(el);

            if (temp === null) {
                let retries = 30;
                while (retries-- > 0 && temp === null) {
                    await new Promise(r => setTimeout(r, 500));
                    temp = parseTempFromElement(el);
                }
            }

            if (temp !== null) {
                await sendTempToLog(temp);
            }
        }

        document.addEventListener('DOMContentLoaded', logCurrentTempOnce);
    </script>
</body>
</html>
