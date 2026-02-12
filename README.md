# iDRAC Temperature Monitor (iTM) - Web Application

A modern, responsive web application for monitoring server temperatures through Dell iDRAC interfaces with real-time tracking, historical data analysis, and automated alerting.

![iDRAC Temperature Monitor](https://img.shields.io/badge/Status-Production_Ready-green.svg)
![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)
![License](https://img.shields.io/badge/License-Internal_Use-red.svg)


---

##  Overview

**iDRAC Temperature Monitor (iTM)** is a comprehensive web-based solution designed to monitor server temperatures through Dell's iDRAC (Integrated Dell Remote Access Controller) interfaces.

The application provides:

- Real-time temperature tracking  
- Automated alerting  
- Historical data analysis  
- Responsive modern interface  

### Key Benefits

- **Real-time Monitoring** (5-minute intervals)
- **Automated Email Alerts**
- **Historical Graphs & Logs**
- **Responsive UI (Desktop / Tablet / Mobile)**
- **Simple Configuration**

---

##  Features

###  Core Monitoring

- Live monitoring via **iDRAC Redfish API**
- Threshold-based status indicators:
  - 🟢 Normal
  - 🟡 Warning
  - 🔴 Critical
- Automated temperature checks every 5 minutes

###  Smart Alerting

- Immediate status change notifications
- Persistent condition alerts (5+ minutes)
- Hourly summary reports
- Configurable thresholds

###  Data & Analytics

- Real-time temperature graph
- Historical trend analysis
- Date-range filtering
- CSV and raw log export


---

##  Prerequisites

### Server Requirements

- Apache 2.4+ or Nginx 1.18+
- PHP 7.4+
- PHP Extensions:
  - cURL
  - JSON
  - Fileinfo (optional)
- 100MB disk space minimum
- 128MB RAM minimum (256MB recommended)

### iDRAC Requirements

- Dell Server with iDRAC Enterprise
- Firmware 3.0+ (Redfish API support)
- Network accessibility
- Valid read-permission credentials

### Email Requirements

- SMTP server
- Sender account
- Valid recipient email addresses

---

##  Installation

### Step 1 – Download

```bash
git clone [repository-url]
cd idrac-temperature-monitor
```

Or download ZIP and extract manually.

---

### Step 2 – Set Permissions

```bash
chmod 755 .
chmod 644 *.php
chmod 666 idrac_state.json
chmod 666 idrac_log.csv
chmod 777 storage/
touch storage/temperature.log
chmod 666 storage/temperature.log
```

---

### Step 3 – Create Configuration

```bash
cp idrac_config.php.dist idrac_config.php
nano idrac_config.php
```

---

### Step 4 – Web Server Setup

#### Apache

```apache
<VirtualHost *:80>
    ServerName temperature-monitor.yourdomain.com
    DocumentRoot /path/to/idrac-temperature-monitor

    <Directory /path/to/idrac-temperature-monitor>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx

```nginx
server {
    listen 80;
    server_name temperature-monitor.yourdomain.com;
    root /path/to/idrac-temperature-monitor;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
    }
}
```

---

### Step 5 – Test

Open:

```
http://your-server-address/
```

Verify:
- Interface loads
- Temperature displays
- Email test works

---

##  Configuration

Edit `idrac_config.php`:

```php
<?php
$CONFIG = [
    'idrac_url'        => 'https://your.domain',
    'idrac_user'       => 'root',
    'idrac_pass'       => 'yourpassword',

    'warning_temp'     => 35,
    'critical_temp'    => 40,
    'check_interval'   => 5,

    'timezone'         => 'America/New_York',

    'transport'        => 'smtp',
    'smtp_host'        => 'smtp.yourdomain.com',
    'smtp_port'        => 587,
    'smtp_secure'      => 'tls',
    'smtp_auth'        => true,
    'smtp_user'        => 'noreply@yourdomain.com',
    'smtp_pass'        => 'your-password',
    'email_to'         => 'admin@example.com',
    'email_from'       => 'monitor@yourdomain.com',
    'email_from_name'  => 'iDRAC Temperature Monitor'
];
?>
```

---

## Usage

### Web Dashboard

- Current temperature display
- Status indicator
- Live graph
- Daily min/avg/max statistics

### Logs Modal

- Live logs
- History logs (date filter)
- Graph trends
- CSV export

---

### CLI Commands

```bash
php index.php --hourly
php index.php hourly
```

---

##  API Endpoints

### Temperature

```
?action=get_temp
```

### Email

```
?action=test_email
?action=hourly
```

### Logs

```
?action=download_logs&type=csv
?action=get_filtered_logs
?action=get_graph_data
```

### Example JSON

```json
{
    "success": true,
    "temperature": 32,
    "status": "NORMAL",
    "timestamp": "2024-01-15 14:30:00"
}
```

---

##  File Structure

```
idrac-temperature-monitor/
├── index.php
├── idrac_config.php
├── idrac_config.php.dist
├── idrac_state.json
├── idrac_log.csv
├── storage/
│   └── temperature.log
├── api/
│   └── log_temp.php
├── assets/
└── README.md
```

---
