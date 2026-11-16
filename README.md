# Attendance Hub

A Laravel 13 + MySQL website for a ZKTeco fingerprint terminal (tested with an F22).
It saves every punch to MySQL, shows live scans as they happen, and exports filtered CSV reports.

## What it does

| Page | Who | What |
|---|---|---|
| Overview | everyone | Terminal status, today's numbers, memory use, live punch feed |
| Attendance | everyone | All punches with search, Check in/out, employee, month and date filters, sorting, paging, CSV export |
| Employees | everyone | People enrolled on the terminal |
| Device | everyone (controls: admins) | Hardware details, storage, clock sync, speaker test, reboot |
| Connection | admins | Terminal IP, port, protocol, timeout, comm key, connection test |
| Logins | admins | Create, change and delete website logins |

- **Administrators** can sync, change settings, control the terminal and manage logins.
- **Viewers** can view and export only.

## Run it on a Mac or PC (development)

```bash
composer install
cp .env.example .env && php artisan key:generate   # then set DB_* in .env
php artisan migrate
php artisan zk:import-sqlite                       # one-time: copies storage/app/legacy/attendance.db into MySQL
php artisan user:create you@example.com --name="Your Name" --role=admin

php artisan serve --no-reload     # website on http://127.0.0.1:8000 (--no-reload enables the 4 workers set in .env)
php artisan zk:listen             # in a second terminal: live punches
```

Works with MySQL 8+ or MariaDB 10.4+. On this Mac it uses XAMPP's MariaDB (`DB_CONNECTION=mariadb`, port 3307), so the `zk_attendance` database appears in XAMPP's phpMyAdmin.

## Push mode: the terminal sends punches to a cloud server

Use this mode to host the website on a cloud server with no computer in the office. The terminal connects out to the website by itself, so you don't need a VPN or port forwarding.

1. Host the app on any internet server with PHP 8.3+ and MySQL/MariaDB (see below). Make sure `http://your-domain/iclock/cdata` is reachable over **plain HTTP**: many terminals can't use HTTPS for this, so don't force-redirect `/iclock` to HTTPS.
2. In the website, go to **Connection**, choose **Push (Cloud Server)**, enter the terminal's serial number (System Info → Device Info), and save.
3. On the terminal, go to **Menu → COMM → Cloud Server Setting** and set:
   - **Server mode:** ADMS
   - **Enable domain name:** On
   - **Server address:** your domain
   - **Server port:** 80
   - **Proxy:** Off
4. Within a minute, **Connection** shows a "Last check-in" time. The terminal then uploads any punches it hasn't sent before, and every new scan arrives within seconds.

In push mode, Sync, clock sync, the speaker test and reboot are done from the terminal's own menu. `zk:listen` and `zk:sync` aren't needed.
Only terminals whose serial number is on the allowed list are accepted. Others get `403` and are logged.

> This needs terminal firmware with the **Cloud Server / ADMS** menu. If your terminal doesn't have it, ask your supplier for the "push" (ADMS) firmware, or use Direct mode with a computer in the office.

## Host it on cPanel (deploy with `git push`)

cPanel shared hosting suits **push mode**. Direct mode won't work there: the server can't reach a terminal inside your office, and shared hosts don't allow the always-running `zk:listen`.

**One-time setup**

1. **PHP:** in **MultiPHP Manager**, set the domain to PHP **8.3 or newer**. Check that `pdo_mysql`, `mbstring`, `xml` and `zip` are enabled under **Select PHP Version → Extensions**.
2. **Database:** in **MySQL Databases**, create a database and a user, and give the user **All privileges** on it. cPanel adds a prefix, for example `cpuser_attendance`.
3. **Repository:** in **Git™ Version Control**, click **Create**. Turn **Clone a Repository** off and set the path to `repositories/attendance-hub`. Copy the **Clone URL** it shows (`ssh://cpuser@host/home/cpuser/repositories/attendance-hub`).
4. **Domain folder:** in **Domains**, set the domain's document root to `repositories/attendance-hub/public`. Never set it to the repository folder itself, which would expose `.env`.
5. **Settings file:** in **File Manager** (or over SSH), create `repositories/attendance-hub/.env` from `.env.example` and set:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`
   - `DB_CONNECTION=mysql` plus the database name, user and password from step 2
   - `ZK_MODE=push` and `ZK_PUSH_SERIALS=<terminal serial>`

   Then generate a key: `php artisan key:generate` over SSH. Without SSH, create the key on your computer with `php artisan key:generate --show` and paste it into `APP_KEY`.
6. **PHP path:** check the `PHP=` line in [`.cpanel.yml`](.cpanel.yml) matches your PHP version, for example `ea-php83` or `ea-php84`.

**Deploy**

```bash
git remote add cpanel ssh://cpuser@host/home/cpuser/repositories/attendance-hub
git push cpanel master
```

cPanel then runs `.cpanel.yml`: it installs packages, updates the database and caches the config. Every later `git push cpanel master` deploys again.
If your host has no SSH access, use the **Pull or Deploy** tab instead. Clone from GitHub, then click **Update from Remote** and **Deploy HEAD Commit** after each GitHub push.

**After the first deploy**

- Create your login by running `php artisan user:create you@example.com --role=admin` over SSH or from **Terminal** in cPanel.
- Optionally, in **Cron Jobs**, add `* * * * * cd ~/repositories/attendance-hub && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >/dev/null 2>&1`. Push mode doesn't need it.
- Turn on **SSL/TLS Status → AutoSSL** for the domain. Don't use cPanel's **Force HTTPS Redirect**. The app already sends people to HTTPS when `APP_URL` is `https://…`, but leaves `/iclock` on plain HTTP for the terminal.
- On the terminal, set **Cloud Server Setting** as described in Push mode, with server port 80.

## Commands

| Command | Purpose |
|---|---|
| `php artisan zk:listen` | Captures punches in real time. Reconnects by itself. Keep it running. |
| `php artisan zk:sync` | Downloads all employees and punches. All or nothing. Runs every 30 minutes through the scheduler. |
| `php artisan zk:import-sqlite [path]` | Copies the old `attendance.db` and `config.json` into MySQL. Safe to run again. |
| `php artisan user:create email --role=admin\|viewer` | Creates a login, or resets its password. |

## Host it in the office (Ubuntu mini PC or Raspberry Pi)

The server must be on the **same network as the terminal**, ideally on a cable.

```bash
sudo apt install apache2 mysql-server php php-mysql php-xml php-mbstring php-curl php-zip unzip composer
sudo mysql -e "CREATE DATABASE zk_attendance; CREATE USER 'zk_attendance'@'localhost' IDENTIFIED BY 'CHANGE-ME'; GRANT ALL ON zk_attendance.* TO 'zk_attendance'@'localhost';"

sudo git clone <this repository> /var/www/attendance-app && cd /var/www/attendance-app
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
#   In .env set: APP_ENV=production, APP_DEBUG=false, APP_URL, DB_PASSWORD
php artisan migrate --force
php artisan user:create you@example.com --role=admin
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

Then install the three files in [`deploy/`](deploy/):

1. `apache-site.conf`: serves only `public/`.
2. `attendance-listener.service`: keeps `zk:listen` running as a systemd service.
3. `crontab.txt`: the scheduler (sync every 30 minutes) and a nightly MySQL backup. For the backup, put the database login in `~www-data/.my.cnf`.

Finally:

- In the router, reserve fixed IP addresses for the terminal and for the server.
- For access from outside the office, use **Tailscale** (or another VPN).
- **Never** forward port 80 or 4370 to the internet.

## Folder layout

This is a standard Laravel project. The project-specific parts are:

| Path | What |
|---|---|
| `app/Libraries/ZKLib/` | ZKTeco protocol library (TCP/UDP on port 4370) |
| `app/Services/` | `Terminal` (connection), `AttendanceStore` (saving to MySQL), `DeviceSync` (full sync, status) |
| `app/Console/Commands/` | `zk:listen`, `zk:sync`, `zk:import-sqlite`, `user:create` |
| `app/Http/Controllers/` | Pages and actions |
| `resources/views/` | Blade pages |
| `public/css/app.css`, `public/js/app.js`, `public/vendor/select2/` | Styles and scripts (no build step needed) |
| `deploy/` | Apache, systemd and cron files for the office server |
| `storage/app/legacy/` | The previous plain-PHP dashboard and its SQLite data, kept for reference. Not served and not in git. |
