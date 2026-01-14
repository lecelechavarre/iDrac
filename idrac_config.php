<?php
// idrac_config.php - Configuration loader for iDRAC Monitor

// Load environment variables if .env file exists
function loadEnv() {
    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue; // Skip comments
            
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // Only check for quotes if value is not empty
            if (strlen($value) > 1) {
                $firstChar = $value[0];
                $lastChar = $value[strlen($value) - 1];
                if (($firstChar === '"' && $lastChar === '"') ||
                    ($firstChar === "'" && $lastChar === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Call loadEnv to load environment variables
loadEnv();

// =============== CONFIGURATION ===============
$CONFIG = [
    'idrac_url'        => 'https://10.129.16.81',
    'idrac_user'       => 'root',
    'idrac_pass'       => 'P@ssw0rd3128!',
    
    // Email configuration from .env
    'email_from'       => getenv('MAIL_FROM_ADDRESS') ?: 'noreply@j-display.com',
    'email_from_name'  => getenv('MAIL_FROM_NAME') ?: 'iDRAC Monitor',
    
    // Multiple email recipients - comma-separated list
    'email_to'         => 'supercompnxp@gmail.com, ian.tolentino.bp@j-display.com, ferrerasroyce@gmail.com, raffy.santiago.rbs@gmail.com',
    
    'warning_temp'     => 25,
    'critical_temp'    => 30,
    'check_interval'   => 60,
    'timezone'         => 'Singapore',

    // ==== Email transport from .env ====
    'transport'        => 'smtp',
    'smtp_host'        => getenv('MAIL_HOST') ?: 'mrelay.intra.j-display.com',
    'smtp_port'        => (int)(getenv('MAIL_PORT') ?: 25),
    'smtp_secure'      => getenv('MAIL_ENCRYPTION') ?: '',
    'smtp_user'        => getenv('MAIL_USERNAME') ?: '',
    'smtp_pass'        => getenv('MAIL_PASSWORD') ?: '',
    'smtp_timeout'     => 20,
    
    // Additional email settings
    'smtp_debug'       => 0,
    'smtp_auth'        => !empty(getenv('MAIL_USERNAME')) // Enable auth if username is set
];
