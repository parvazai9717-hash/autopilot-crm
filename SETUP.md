# Meeting → Action Autopilot CRM — Server Setup & Deployment Guide

This document specifies the complete server setup, prerequisites, service configurations, environment variables, and exact deployment commands for the Meeting → Action Autopilot CRM application targeting a Hostinger VPS (Ubuntu, nginx, PHP-FPM, MySQL 8).

---

## 1. Server Prerequisites

### Operating System & Packages
- **Operating System:** Ubuntu 22.04 LTS or Ubuntu 24.04 LTS
- **Web Server:** Nginx 1.18+
- **Database:** MySQL 8.0+
- **PHP Version:** PHP 8.2 or 8.3
- **Audio Processing:** `ffmpeg` and `ffprobe`
- **Process Manager:** `supervisor`
- **Node.js:** Node.js 18+ & `npm`
- **Package Manager:** Composer 2+

### Package Installation Commands (Ubuntu)
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common curl git unzip supervisor

# Add PHP PPA and install PHP 8.2 with required extensions
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.2 php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml \
    php8.2-curl php8.2-zip php8.2-bcmath php8.2-intl php8.2-gd php8.2-cli

# Install MySQL 8 and Nginx
sudo apt install -y mysql-server nginx

# Install ffmpeg and ffprobe (MANDATORY for audio compression & chunking)
sudo apt install -y ffmpeg

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Node.js (v20 LTS)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

---

## 2. PHP & Nginx Configuration (Per Section 10 of SPEC)

Whisper rejects audio recordings larger than 25MB. Uploaded meetings can be multi-hour WAV or MP4 files up to 500MB. Default PHP and Nginx limits will reject uploads with HTTP 413 or timeout during processing. The following values must be configured.

### `php.ini` (`/etc/php/8.2/fpm/php.ini` & `/etc/php/8.2/cli/php.ini`)
```ini
upload_max_filesize = 500M
post_max_size = 500M
max_execution_time = 300
memory_limit = 512M
```
Apply changes:
```bash
sudo systemctl restart php8.2-fpm
```

### Nginx Site Configuration (`/etc/nginx/sites-available/autopilot`)
```nginx
server {
    listen 80;
    server_name crm.yourdomain.com;
    root /var/www/autopilot/web/dist;

    index index.html;

    # Maximum file upload size and client timeouts (per section 10)
    client_max_body_size 500M;
    client_body_timeout 300s;

    # SPA Frontend routes
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Laravel API and Sanctum routes
    location ~ ^/(api|sanctum|v1|storage|up) {
        root /var/www/autopilot/api/public;
        try_files $uri $uri/ /index.php?$query_string;

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
            fastcgi_param SCRIPT_FILENAME /var/www/autopilot/api/public$fastcgi_script_name;
            include fastcgi_params;
            fastcgi_read_timeout 300;
        }
    }

    # Deny direct access to hidden files
    location ~ /\. {
        deny all;
    }
}
```
Enable site and restart Nginx:
```bash
sudo ln -s /etc/nginx/sites-available/autopilot /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

---

## 3. Supervisor Queue Worker Configuration (Verbatim from Section 10)

The audio conversion and outbound webhook dispatches run on the database queue. Without a running queue worker, uploads will sit indefinitely in `processing`.

Create `/etc/supervisor/conf.d/autopilot-worker.conf`:
```ini
[program:autopilot-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/autopilot/api/artisan queue:work --sleep=3 --tries=3 --timeout=600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/autopilot/api/storage/logs/worker.log
stopwaitsecs=700
```
> **Note on `--timeout=600`:** Converting long audio files with ffmpeg can take several minutes. The default 60-second worker timeout would terminate the job prematurely.

Activate Supervisor:
```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start autopilot-worker:*
```

---

## 4. Cron Scheduler Configuration

Add the Laravel scheduler to the server's cron (`crontab -e -u www-data`):
```cron
* * * * * cd /var/www/autopilot/api && php artisan schedule:run >> /dev/null 2>&1
```

### Scheduled Commands
- `meetings:check-stuck`: Runs every 10 minutes (`*/10 * * * *`). It finds meetings stuck in `processing` older than `MEETING_PROCESSING_TIMEOUT` minutes and transitions them to `failed` with an explicit diagnostic message.

---

## 5. Environment Variables (`.env`)

Create `/var/www/autopilot/api/.env` with these exact names:

```dotenv
# Core Application Settings
APP_NAME="Autopilot CRM"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://crm.yourdomain.com

# Organization Timezone Rule (Section 8)
# All due date comparisons must evaluate against this timezone, not UTC
APP_TIMEZONE=Asia/Karachi

# Database Connection (MySQL 8)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=autopilot
DB_USERNAME=autopilot_user
DB_PASSWORD=your_secure_db_password

# Queue Connection (Must be 'database' per Section 2 & 3)
QUEUE_CONNECTION=database
SESSION_DRIVER=database

# Machine Authentication (n8n API Key checked via X-API-Key)
N8N_API_KEY=your_generated_static_api_key_here

# Outbound Webhook HMAC-SHA256 Signing Secret (Sent as X-Signature)
WEBHOOK_SIGNING_SECRET=your_webhook_signing_secret_here

# Outbound Webhook Target URLs (Per Section 14)
N8N_WEBHOOK_MEETING_UPLOADED=https://n8n.yourdomain.com/webhook/meeting-uploaded
N8N_WEBHOOK_MEETING_NEEDS_REVIEW=https://n8n.yourdomain.com/webhook/meeting-needs-review
N8N_WEBHOOK_TASKS_APPROVED=https://n8n.yourdomain.com/webhook/tasks-approved
N8N_WEBHOOK_TASK_COMPLETED=https://n8n.yourdomain.com/webhook/task-completed
N8N_WEBHOOK_TASK_BLOCKED=https://n8n.yourdomain.com/webhook/task-blocked

# Audio & Video Processing Paths and Limits (Section 10)
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
MAX_UPLOAD_MB=500
AUDIO_CHUNK_MAX_MB=24
MEETING_PROCESSING_TIMEOUT=30
```

---

## 6. Deployment Commands (Clone to Running Application)

```bash
# 1. Clone repository into /var/www/autopilot
cd /var/www
sudo git clone <your-repo-url> autopilot
cd /var/www/autopilot

# 2. Configure Backend (/api)
cd /var/www/autopilot/api
cp .env.example .env
# Edit .env with production database credentials and keys
nano .env

# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Generate application key
php artisan key:generate

# Run database migrations and seed default organization and test roster
php artisan migrate --force --seed

# Create storage symbolic link
php artisan storage:link

# Set correct storage and cache directory permissions
sudo chown -R www-data:www-data /var/www/autopilot/api/storage /var/www/autopilot/api/bootstrap/cache
sudo chmod -R 775 /var/www/autopilot/api/storage /var/www/autopilot/api/bootstrap/cache

# 3. Build Frontend (/web)
cd /var/www/autopilot/web
npm ci
npm run build

# Set permissions for web dist folder
sudo chown -R www-data:www-data /var/www/autopilot/web/dist
sudo chmod -R 755 /var/www/autopilot/web/dist

# 4. Verify System Status
cd /var/www/autopilot/api
php artisan test
```

Default seeded credentials for initial login:
- **Admin:** `admin@test.com` / `password123`
- **Executive:** `exec@test.com` / `password123`
- **Manager:** `bilal@test.com` / `password123`
- **Employee:** `ahmed@test.com` / `password123`
