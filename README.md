# iDRAC Temperature Monitor (iTM)

Enterprise-grade web application for monitoring Dell server temperatures via iDRAC (Redfish API), providing real-time tracking, alerting, logging, and historical analysis.

---

## 1. Purpose

iTM exists to provide lightweight, reliable temperature monitoring for Dell servers in environments where:

- Full monitoring stacks (Nagios, Zabbix, Prometheus) are not deployed
- A focused temperature-only monitoring solution is required
- Fast deployment and minimal infrastructure overhead are preferred

This system prioritizes:

- Operational simplicity
- Deterministic alert behavior
- Minimal dependencies
- Clear audit logging
- Deployment flexibility

---

## 2. System Architecture

### 2.1 High-Level Flow

```
Client Browser
     │
     ▼
index.php (Controller / UI / API Router)
     │
     ├── iDRAC Redfish API (Temperature Pull)
     │
     ├── CSV Logger (idrac_log.csv)
     │
     ├── Raw Log Writer (storage/temperature.log)
     │
     ├── State Manager (idrac_state.json)
     │
     └── Email Notification Engine
```

### 2.2 Core Components

| Component | Responsibility |
|------------|---------------|
| `index.php` | Main application controller + API router |
| `idrac_config.php` | Runtime configuration (credentials, thresholds) |
| `idrac_state.json` | Alert state persistence |
| `idrac_log.csv` | Structured temperature history |
| `storage/temperature.log` | Detailed raw logs |
| Redfish API | Temperature data source |

---

## 3. Design Principles

### 3.1 Stateless HTTP + Persistent Alert State

The web layer remains stateless, while alert transitions are tracked in:

```
idrac_state.json
```

This ensures:

- Alert changes are detected accurately
- Duplicate alerts are avoided
- System restarts do not reset alert logic

---

### 3.2 Deterministic Alert Logic

Alerts are triggered on:

1. Status transition (Normal → Warning → Critical)
2. Persistent abnormal state (> configured interval)
3. Scheduled hourly summary

This avoids alert storms and notification fatigue.

---

### 3.3 Minimal Infrastructure Footprint

No database required.

Storage model:

- CSV for structured logs
- JSON for state
- Flat log file for audit trail

This keeps deployment portable and lightweight.

---

## 4. Feature Set

### Monitoring

- Real-time temperature polling via Redfish API
- Configurable thresholds
- Configurable polling interval
- Multi-recipient alert support

### Alerting

- Immediate state change alerts
- Persistent condition alerts
- Hourly temperature summaries
- SMTP or PHP mail transport

### Logging & Analytics

- Structured CSV export
- Raw log auditing
- Historical graph visualization
- Date-range filtering

### CLI Support

Manual operations supported via:

```bash
php index.php --hourly
php index.php hourly
```

This allows cron-based automation.

---

## 5. Technical Requirements

### Server

- PHP 7.4+
- Apache 2.4+ or Nginx 1.18+
- cURL extension enabled
- JSON extension enabled

### iDRAC

- Firmware 3.0+
- Redfish API enabled
- Dedicated monitoring account recommended

---

## 6. Installation

### 6.1 Clone

```bash
git clone <repository-url>
cd idrac-temperature-monitor
```

---

### 6.2 Configure Permissions

```bash
chown -R www-data:www-data .
chmod 755 .
chmod 644 *.php
chmod 666 idrac_state.json idrac_log.csv
chmod 777 storage/
```

---

### 6.3 Configure Application

```bash
cp idrac_config.php.dist idrac_config.php
nano idrac_config.php
```

---

## 7. Configuration

### Example Configuration

```php
$CONFIG = [
    'idrac_url'      => 'https://192.168.1.100',
    'idrac_user'     => 'monitor_user',
    'idrac_pass'     => 'strong-password',

    'warning_temp'   => 35,
    'critical_temp'  => 40,
    'check_interval' => 5,

    'timezone'       => 'UTC',

    'transport'      => 'smtp',
    'smtp_host'      => 'smtp.company.com',
    'smtp_port'      => 587,
    'smtp_secure'    => 'tls',
    'smtp_auth'      => true,
    'smtp_user'      => 'monitor@company.com',
    'smtp_pass'      => 'smtp-password',
    'email_to'       => 'ops@company.com',
];
```

---

## 8. Production Deployment Considerations

### 8.1 Cron Automation

Instead of relying only on web access, configure cron:

```bash
*/5 * * * * /usr/bin/php /var/www/iTM/index.php
0 * * * * /usr/bin/php /var/www/iTM/index.php --hourly
```

---

### 8.2 Log Rotation (Recommended)

Create `/etc/logrotate.d/itm`:

```
/var/www/iTM/storage/temperature.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
}
```

---

### 8.3 Environment Separation

Recommended structure:

- Dev
- Staging
- Production

Do NOT reuse credentials across environments.

---

## 9. Security Hardening

### 9.1 Credentials

- Use dedicated iDRAC account
- Limit permissions to read-only
- Rotate passwords periodically
- Never commit `idrac_config.php`

Add to `.gitignore`:

```
idrac_config.php
idrac_state.json
idrac_log.csv
```

---

### 9.2 HTTPS Enforcement

Always serve behind HTTPS.

### 9.3 IP Restriction (Optional)

Restrict dashboard to internal network:

Apache:

```
Require ip 192.168.1.0/24
```

---

### 9.4 SSL Verification

If disabling SSL verification for Redfish (`-k` behavior), understand this introduces MITM risk.  
Proper certificate installation on iDRAC is recommended.

---

## 10. Performance & Scalability

### Current Model

- Single-server monitoring
- File-based logging
- No database
- Low CPU footprint

### Scaling Strategy

For multi-server monitoring at scale:

- Convert logs to database (MySQL/PostgreSQL)
- Separate API worker from UI
- Introduce queue-based alert processing
- Implement rate limiting

---

## 11. API Endpoints

| Endpoint | Purpose |
|-----------|----------|
| `?action=get_temp` | Get current temperature |
| `?action=test_email` | Test SMTP configuration |
| `?action=hourly` | Trigger hourly report |
| `?action=get_graph_data` | Fetch graph dataset |
| `?action=download_logs&type=csv` | Download CSV logs |

---

## 12. Failure Scenarios

### iDRAC Unreachable

- Temperature marked as unknown
- Error logged
- Alert triggered if configured

### SMTP Failure

- Error logged
- No retry queue implemented (intentional simplicity)

### File Permission Errors

- Application will fail to log
- Must be resolved at OS level

---

## 13. Known Trade-offs

- No database (simplifies deployment but limits analytics)
- No retry queue for failed email
- No clustering support
- No built-in authentication layer (should be handled at web server level)

---

## 14. Maintenance

Recommended:

- Weekly log review
- Monthly credential review
- Quarterly alert testing
- Regular PHP security updates

---

## 15. Versioning

### v1.0.0

- Initial production release
- Real-time monitoring
- Alert engine
- CSV export
- Historical visualization

---

## 16. License

Internal enterprise use only.

---

## 17. Future Roadmap

- Multi-server dashboard
- Database-backed storage
- REST API separation
- Token-based authentication
- Containerized deployment (Docker)
- Integration hooks (Webhook / Slack / Monitoring systems)

---

