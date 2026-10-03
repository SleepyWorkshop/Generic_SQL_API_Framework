# Windows and Linux production validation

This report records what was actually available on 2026-09-24. The
status terms are deliberately strict:

- **VALIDATED** — executed or deterministically checked in this workspace.
- **PARTIALLY VALIDATED** — a prerequisite or static structure was checked, but
  not the complete deployed behavior.
- **NOT EXECUTED** — the required infrastructure or authorized target was not
  available.
- **REQUIRES OPERATOR VALIDATION** — must be checked on the target host using
  its real identities, hostnames, certificates, configuration, and database.

Run `php scripts/validate-production.php` for a machine-readable discovery and
static-validation report. It never installs services, changes configuration,
connects to SQL Server, or prints secrets.

## Validation record

| Area | Status | Evidence |
|---|---|---|
| Repository baseline and automated backend suites | VALIDATED | Clean committed baseline; both normal PHP and `php -n` suites pass |
| Current operating environment | VALIDATED | Kali GNU/Linux 2026.2 under WSL2, Linux kernel 6.18 |
| PHP CLI | VALIDATED | PHP 8.4.22; JSON, OpenSSL, sessions, ODBC, PDO_ODBC, and OPcache loaded |
| Microsoft ODBC driver | PARTIALLY VALIDATED | `ODBC Driver 18 for SQL Server` is registered; no authorized server/DSN was supplied |
| Production PHP INI fragment | VALIDATED | Parsed and checked for error display, logging, sessions, uploads, UTC, limits, and OPcache |
| Effective local CLI INI as production configuration | NOT EXECUTED | Local CLI is not PHP-FPM/FastCGI and intentionally does not prove production settings |
| IIS templates | PARTIALLY VALIDATED | XML parsed/structure checked, routes/limits/headers/denials checked; IIS unavailable |
| IIS/FastCGI live deployment | NOT EXECUTED | No Windows/IIS execution environment |
| Bundled Windows PHP | PARTIALLY VALIDATED | PE64 `php.exe` and `php-cgi.exe` plus INI exist; binaries were not executable here |
| Nginx template | VALIDATED | Static routing, fixed FastCGI targets, TLS policy, redirects, limits, health and denial rules checked |
| Nginx/PHP-FPM live deployment | NOT EXECUTED | Neither Nginx nor PHP-FPM is installed/active |
| Application HTTP behavior | VALIDATED | Local production-mode PHP HTTP checks cover health and safe JSON errors |
| Real trusted TLS/certificate | NOT EXECUTED | OpenSSL 3.6.2 is available, but no deployed hostname/certificate/private key was supplied |
| Live SQL Server | NOT EXECUTED | Driver exists; connection, invalid credential, outage and live timeout tests require an authorized target |
| Production sessions/cookies | PARTIALLY VALIDATED | Application and template policy tested; no real HTTPS browser/IIS/FPM session was available |
| Production CORS/CSRF | PARTIALLY VALIDATED | Application behavior tested; deployed browser-origin and proxy behavior not executed |
| Production concurrency/load | NOT EXECUTED | Only safe database-independent process/concurrency tests were run; no load claim is made |

The current workspace directories and `/var/lib/php/sessions` are development
state. Their ownership/modes are observations, not validated IIS/FPM production
permissions. Do not copy them blindly to a target host.

## Production lifecycle boundary

`start-windows.bat` and `start-linux.sh` remain unchanged development tools
using `php -S`. In production, IIS/FastCGI or Nginx/PHP-FPM owns API and SQL
Parser workers. When `GENERIC_APP_ENV=production`, System Health labels those
services' infrastructure **externally managed** and exposes application-level
Enable, Disable, and Reload controls. These retain the existing Admin action
names for compatibility but change only the atomic application availability
state. Disabled execution requests receive a sanitized 503 while Admin and
liveness stay reachable. No action issues IIS, Nginx, FastCGI, PHP-FPM, systemd,
Windows service, or SQL Server commands.

## Windows/IIS operator checklist

Run these from an elevated administrative shell only where organizational
policy permits. Replace example paths and hosts; never paste secrets into the
command line or transcript.

1. Record Windows/IIS capability:

   ```powershell
   Get-ComputerInfo | Select-Object WindowsProductName,WindowsVersion,OsBuildNumber
   Get-WindowsFeature Web-Server,Web-CGI,Web-IP-Security
   & "$env:windir\System32\inetsrv\appcmd.exe" list modules
   ```

   Confirm CGI/FastCGI, URL Rewrite and IP restrictions are installed.

2. Parse templates before deployment:

   ```powershell
   Get-ChildItem .\deployment\iis\*.web.config.example |
     ForEach-Object { [xml](Get-Content -Raw $_.FullName) | Out-Null }
   ```

3. Validate the installed FastCGI runtime, not merely the bundled CLI:

   ```powershell
   C:\PHP\php-cgi.exe -c C:\PHP\php.ini -v
   C:\PHP\php-cgi.exe -c C:\PHP\php.ini -m
   C:\PHP\php-cgi.exe -c C:\PHP\php.ini -i |
     Select-String 'display_errors|display_startup_errors|log_errors|expose_php|session.save_path|opcache.enable'
   ```

4. Configure a dedicated application-pool identity. Use `icacls` to confirm:
   code is read-only; configuration and the separately stored encryption key
   are restricted; runtime, logs and sessions are writable; backup storage is
   outside web roots. Do not grant broad `Everyone` or inherited write access.

5. Validate IIS configuration and bindings:

   ```powershell
   & "$env:windir\System32\inetsrv\appcmd.exe" list apppool
   & "$env:windir\System32\inetsrv\appcmd.exe" list site
   & "$env:windir\System32\inetsrv\appcmd.exe" list config /section:system.webServer/fastCgi
   netsh http show sslcert
   ```

6. With trusted DNS/certificates, test without bypassing certificate checks:

   ```powershell
   curl.exe -i https://reports.example.internal/health/live
   curl.exe -i https://reports.example.internal/health/ready
   curl.exe -i https://reports.example.internal/api/not-a-route
   curl.exe -i -X GET https://reports.example.internal/api
   curl.exe -i -H "Content-Type: application/json" --data-binary "{broken" https://reports.example.internal/api
   ```

   Verify status, JSON content type, request ID on errors, HSTS/security
   headers, no detailed IIS page, and no internal details. Test Admin login,
   CSRF, logout and SQL Parser interactively without recording cookie/token
   values. Confirm top-level `/health/*` is internally routed to `/api/health/*`.

7. Validate Schannel protocol/cipher policy and certificate SAN/chain using
   approved Windows tooling. Recycle the application pool after code, secret,
   PHP INI, or OPcache changes.

## Linux/Nginx/PHP-FPM operator checklist

1. Record installed components and locate the real pool socket:

   ```sh
   uname -a
   nginx -V
   php-fpm8.4 -v
   php-fpm8.4 -tt
   systemctl status nginx php8.4-fpm
   grep -R '^[[:space:]]*listen[[:space:]]*=' /etc/php/*/fpm/pool.d
   ```

2. Replace every hostname, path, certificate and socket placeholder. Install
   the header snippets, then validate before reload:

   ```sh
   nginx -t
   systemctl reload php8.4-fpm
   systemctl reload nginx
   ```

3. Inspect effective PHP-FPM configuration offline under the worker identity:

   ```sh
   sudo -u www-data php -c /etc/php/8.4/fpm/php.ini -r '
   foreach (["display_errors","display_startup_errors","log_errors","expose_php","session.save_path","session.use_strict_mode","session.cookie_secure","opcache.enable"] as $k) echo $k,"=",ini_get($k),PHP_EOL;'
   ```

   Confirm the effective FPM pool environment provides
   `GENERIC_APP_ENV=production` and encryption-key access without printing the
   key. Account for the pool's `clear_env` policy.

4. Inspect ownership/permissions using `namei -l`, `stat`, and a harmless
   worker-identity write test approved for the target. Code must be read-only;
   configuration/key access restricted; runtime/log/session paths writable;
   backups outside web roots. Do not recursively chmod/chown an unknown tree.

5. Test real HTTPS without `-k`:

   ```sh
   curl --fail-with-body -i https://reports.example.internal/health/live
   curl -i https://reports.example.internal/health/ready
   curl -i https://reports.example.internal/api/not-a-route
   curl -i -X GET https://reports.example.internal/api
   curl -i -H 'Content-Type: application/json' --data-binary '{broken' https://reports.example.internal/api
   openssl s_client -connect reports.example.internal:443 -servername reports.example.internal -verify_return_error </dev/null
   ```

   Confirm TLS 1.2/1.3 policy, trusted chain/SAN, redirect, HSTS, boundary CSP,
   JSON application errors, no PHP warning/path/SQL/ODBC leakage, and `no-store`.

6. Use a staging System Administrator to validate Admin authentication,
   authorization, CSRF token rejection/acceptance, secure host-only cookies,
   session-ID regeneration and logout invalidation. Do not log token or cookie
   values. Confirm API/Parser show externally managed infrastructure and working
   application Enable/Disable/Reload controls without changing worker processes.

## SQL Server and operational validation

Under the real FastCGI/FPM identity and environment, run the existing database
check and readiness endpoint. Then, in an isolated staging configuration,
validate invalid credentials, unreachable server, encryption-key failure and a
controlled timeout. Restore the valid encrypted configuration and key through
the documented procedure. Verify safe client categories/request IDs and
redacted server logs; never print credentials.

Exercise a small number of simultaneous liveness/readiness and representative
authenticated requests. Verify worker responsiveness, body limits, PHP/proxy
timeouts, file locks and log writes. This is a smoke/concurrency check, not a
load test or capacity result. SQL Server backup/restore validation remains the
separate operator responsibility.
