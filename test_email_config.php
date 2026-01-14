<?php
// test_email_config.php
echo "<h2>Testing Email Configuration</h2>";

// Load your .env file
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    echo "<p>✅ .env file found</p>";
    
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), 'MAIL_') === 0) {
            echo "<pre>" . htmlspecialchars($line) . "</pre>";
        }
    }
} else {
    echo "<p>❌ .env file not found</p>";
}

// Test PHP mail configuration
echo "<h3>PHP Mail Configuration:</h3>";
echo "<pre>";
echo "sendmail_path: " . ini_get('sendmail_path') . "\n";
echo "SMTP: " . ini_get('SMTP') . "\n";
echo "smtp_port: " . ini_get('smtp_port') . "\n";
echo "</pre>";

// Test sending email
echo "<h3>Test Email Sending:</h3>";
$to = "ian.tolentino.bp@j-display.com";
$subject = "Test from Server " . gethostname();
$message = "This is a test email from the iDRAC monitoring server.\n";
$message .= "Server: " . gethostname() . "\n";
$message .= "Time: " . date('Y-m-d H:i:s') . "\n";
$headers = "From: noreply@j-display.com\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

if (mail($to, $subject, $message, $headers)) {
    echo "<p style='color: green;'>✅ Test email sent to $to</p>";
    echo "<p>Please check your email inbox and spam folder.</p>";
} else {
    echo "<p style='color: red;'>❌ Failed to send test email</p>";
    echo "<p>Check server mail logs:</p>";
    echo "<pre>sudo tail -f /var/log/mail.log</pre>";
    echo "<pre>sudo tail -f /var/log/exim4/mainlog</pre>";
}

// Check if port 25 is open to mail relay
echo "<h3>Network Test to Mail Relay:</h3>";
$mail_host = "mrelay.intra.j-display.com";
$mail_port = 25;

$fp = @fsockopen($mail_host, $mail_port, $errno, $errstr, 5);
if ($fp) {
    echo "<p style='color: green;'>✅ Connection to $mail_host:$mail_port successful</p>";
    fclose($fp);
} else {
    echo "<p style='color: red;'>❌ Cannot connect to $mail_host:$mail_port</p>";
    echo "<p>Error: $errstr ($errno)</p>";
    echo "<p>Check firewall rules and network connectivity.</p>";
}
?>
