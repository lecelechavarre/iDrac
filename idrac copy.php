<?php
// iDRAC Temperature Monitor - Standalone Version 
// Include configuration 
require_once __DIR__ . '/idrac_config.php';

date_default_timezone_set($CONFIG['timezone']);

// Small state file to avoid duplicate alert emails
define('IDRAC_STATE_FILE', __DIR__ . '/idrac_state.json');

// =============== UTILS & STATE ===============
function load_state(): array {
    if (file_exists(IDRAC_STATE_FILE)) {
        $s = json_decode(@file_get_contents(IDRAC_STATE_FILE), true);
        if (is_array($s)) return $s;
    }
    return [
        'last_status'        => 'UNKNOWN',
        'last_alert_status'  => null,
        'last_alert_time'    => null
    ];
}

function save_state(array $state): void {
    @file_put_contents(IDRAC_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

function format_ts($ts = null): string {
    return date('Y-m-d H:i:s', $ts ?? time());
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
                        return [
                            'success'     => true,
                            'temperature' => $temp,
                            'status'      => get_temp_status($temp),
                            'timestamp'   => format_ts()
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
        // sprintf('Thresholds: Warning ≥ %d°C | Critical ≥ %d°C', $CONFIG['warning_temp'], $CONFIG['critical_temp']),
        'Time: ' . ($payload['timestamp'] ?? format_ts()),
        // 'Redfish: ' . $CONFIG['idrac_url']
    ];

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

// =============== ALERT LOGIC (on threshold) ===============
function check_and_alert(float $temp, string $status, string $timestamp): void {
    // Send a separate alert email when status first reaches WARNING or CRITICAL.
    $state = load_state();

    $prev_alert_status = $state['last_alert_status'];
    $should_alert = in_array($status, ['WARNING', 'CRITICAL'], true)
                    && $prev_alert_status !== $status;

    if ($should_alert) {
        $subject = build_email_subject('Alert', $status, $temp);
        $body    = build_email_body([
            'kind'        => 'Alert',
            'status'      => $status,
            'temperature' => $temp,
            'timestamp'   => $timestamp
        ]);

        if (send_email($subject, $body)) {
            $state['last_alert_status'] = $status;
            $state['last_alert_time']   = format_ts();
        }
    }

    // Track latest observed status regardless
    $state['last_status'] = $status;
    save_state($state);
}

// =============== SIMPLE HISTORY ===============
function save_to_history($temp, $status): void {
    $file = __DIR__ . '/idrac_history.json';
    $history = [];

    if (file_exists($file)) {
        $history = json_decode(@file_get_contents($file), true) ?: [];
    }

    $history[] = [
        'timestamp'   => format_ts(),
        'temperature' => $temp,
        'status'      => $status
    ];

    // Keep only last 200 entries for a bit more runway
    if (count($history) > 200) {
        $history = array_slice($history, -200);
    }

    @file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
}

function get_history(): array {
    $file = __DIR__ . '/idrac_history.json';
    if (file_exists($file)) {
        return json_decode(@file_get_contents($file), true) ?: [];
    }
    return [];
}

function send_hourly_check(): void {
    $result = get_iDRAC_temperature();

    if ($result['success'] ?? false) {
        // Persist the reading
        save_to_history($result['temperature'], $result['status']);

        // Trigger alert emails on state transitions (WARNING/CRITICAL)
        check_and_alert($result['temperature'], $result['status'], $result['timestamp']);

        // Send an hourly report email regardless of status (optional)
        $subject = build_email_subject('Hourly Report', $result['status'], $result['temperature']);
        $message = build_email_body([
            'kind'        => 'Report',
            'status'      => $result['status'],
            'temperature' => $result['temperature'],
            'timestamp'   => $result['timestamp']
        ]);

        if (!send_email($subject, $message)) {
            error_log('Hourly report: failed to send report email');
        } else {
            error_log('Hourly report: email sent');
        }
    } else {
        error_log('Hourly check: failed to get temperature - ' . ($result['message'] ?? 'unknown'));
    }
}

// Allow running the hourly check from CLI: `php idrac.php hourly`
if (php_sapi_name() === 'cli') {
    global $argv;
    if (!empty($argv) && (in_array('hourly', $argv, true) || in_array('--hourly', $argv, true))) {
        send_hourly_check();
        // Exit so the rest of the web-oriented script doesn't run in CLI mode
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
                save_to_history($result['temperature'], $result['status']);
                // If threshold crossed, send alert email (separate from regular report)
                check_and_alert($result['temperature'], $result['status'], $result['timestamp']);
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

        case 'get_history':
            echo json_encode(['success' => true, 'history' => get_history()]);
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
    <title>iDRAC Temperature Monitor</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
        .header { background: #2c3e50; color: white; padding: 20px; border-radius: 10px 10px 0 0; }
        .content { background: white; padding: 20px; border-radius: 0 0 10px 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .temp-display { font-size: 48px; font-weight: bold; text-align: center; margin: 20px 0; }
        .status { display: inline-block; padding: 10px 20px; border-radius: 20px; margin: 10px; font-weight: bold; }
        .normal  { background: #d4edda; color: #155724; border: 2px solid #155724; }
        .warning { background: #fff3cd; color: #856404; border: 2px solid #856404; }
        .critical{ background: #f8d7da; color: #721c24; border: 2px solid #721c24; }
        .unknown { background: #e2e3e5; color: #383d41; border: 2px solid #383d41; }
        .controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px; margin: 20px 0;
        }
        button { padding: 12px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        .btn-primary { background: #3498db; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-info    { background: #17a2b8; color: white; }
        .btn-danger  { background: #e74c3c; color: white; }
        .notification { padding: 10px; margin: 10px 0; border-radius: 5px; display: none; }
        .success { background: #d4edda; color: #155724; border-left: 4px solid #155724; }
        .error   { background: #f8d7da; color: #721c24; border-left: 4px solid #721c24; }
        .loading { text-align: center; display: none; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .meta { color: #666; font-size: 14px; margin-top: 8px; }
        .config-info { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0; font-family: monospace; font-size: 12px; }
        @media (max-width: 768px) { .controls { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>iDRAC Temperature Monitor</h1>
    </div>

    <div class="content">
        <div class="config-info">
            <strong>Current Email Configuration:</strong><br>
            SMTP Server: <?php echo htmlspecialchars($CONFIG['smtp_host']); ?>:<?php echo htmlspecialchars($CONFIG['smtp_port']); ?><br>
            From: <?php echo htmlspecialchars($CONFIG['email_from']); ?><br>
            To: <?php echo htmlspecialchars($CONFIG['email_to']); ?><br>
            Auth: <?php echo $CONFIG['smtp_auth'] ? 'Enabled' : 'Disabled (internal relay)'; ?>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 20px; margin-bottom: 30px;">
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; border-left: 5px solid #3498db; text-align: center;">
                <h2>Current Temperature</h2>
                <div class="temp-display" id="temperature">-- °C</div>
                <div class="status unknown" id="statusIndicator">UNKNOWN</div>
                <div id="lastUpdate" class="meta"></div>
                <div id="hourlyStatus" class="meta">Hourly emails: <strong>Stopped</strong></div>
            </div>

            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; border-left: 5px solid #f39c12;">
                <h3>Thresholds</h3>
                <p><span style="color: #27ae60;">Normal:</span> &lt; <?php echo $CONFIG['warning_temp']; ?>°C</p>
                <p><span style="color: #f39c12;">Warning:</span> ≥ <?php echo $CONFIG['warning_temp']; ?>°C</p>
                <p><span style="color: #e74c3c;">Critical:</span> ≥ <?php echo $CONFIG['critical_temp']; ?>°C</p>
            </div>

            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; border-left: 5px solid #27ae60;">
                <h3>Email Test</h3>
                <p><strong>Recipient:</strong><br><?php echo $CONFIG['email_to']; ?></p>
                <p><strong>SMTP:</strong><br><?php echo $CONFIG['smtp_host']; ?>:<?php echo $CONFIG['smtp_port']; ?></p>
                <p><strong>Click "Test Email" to verify</strong></p>
            </div>
        </div>

        <div class="controls">
            <button class="btn-primary" onclick="getTemperature()">Get Temperature</button>
            <button class="btn-success" onclick="sendReport()">Send Report</button>
            <button class="btn-warning" onclick="sendTestEmail()">Test Email</button>
            <button class="btn-info"    onclick="loadHistory()">Load History</button>
        </div>

        <div class="loading" id="loading"><p>Loading...</p></div>
        <div class="notification" id="notification"></div>

        <div>
            <h3>Temperature History</h3>
            <table id="historyTable">
                <thead>
                    <tr><th>Time</th><th>Temperature</th><th>Status</th></tr>
                </thead>
                <tbody id="historyBody">
                    <tr><td colspan="3" style="text-align: center;">No data yet</td></tr>
                </tbody>
            </table>
        </div>
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
                    document.getElementById('lastUpdate').textContent = 'Updated: ' + (data.timestamp || '');
                    showNotification('Temperature: ' + data.temperature + '°C — ' + data.status, 'success');
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

        async function loadHistory() {
            showLoading(true);
            try {
                const response = await fetch('?action=get_history');
                const data = await response.json();

                if (data.success && data.history.length > 0) {
                    const tbody = document.getElementById('historyBody');
                    tbody.innerHTML = data.history.slice().reverse().map(item => `
                        <tr>
                            <td>${item.timestamp}</td>
                            <td>${item.temperature}°C</td>
                            <td><span class="status ${item.status.toLowerCase()}">${item.status}</span></td>
                        </tr>
                    `).join('');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
            showLoading(false);
        }

        function showNotification(message, type) {
            const el = document.getElementById('notification');
            el.textContent = message;
            el.className = 'notification ' + type;
            el.style.display = 'block';
            setTimeout(() => el.style.display = 'none', 5000);
        }

        function showLoading(show) {
            const el = document.getElementById('loading');
            el.style.display = show ? 'block' : 'none';
        }

        // Auto-load on start
        window.onload = function() {
            getTemperature();
            loadHistory();
            setInterval(getTemperature, AUTO_REFRESH_MS);
        };
    </script>
</body>
</html>



