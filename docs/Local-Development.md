# Local development

The repository includes launchers that run the complete backend on one machine
with PHP's built-in server. This is for development only: it is single-process
per service and is not a production host. Production uses IIS/FastCGI or
Nginx/PHP-FPM; see [Production security and deployment](Production-Security-and-Deployment.md).

## Requirements

- PHP 8.2 or newer with JSON, OpenSSL, session, and ODBC support.
- A Microsoft ODBC Driver for SQL Server when connecting to a database. The PHP
  ODBC extension is only the PHP side of the connection.

The automated test suite needs none of the database components; see
[Testing](Testing.md).

## Launchers

From the `Backend` directory:

```bat
start-windows.bat
```

```bash
./start-linux.sh
```

- **Windows** uses the bundled PHP runtime in `runtime/windows/php/` (no PHP,
  XAMPP, or WAMP installation needed) and loads `runtime/windows/php/php.ini`.
  The bundle's provenance and checksums are recorded in
  [Security verification](security/Security-Verification.md#runtime-dependencies).
- **Linux** uses `runtime/linux/php/php` when present, otherwise `php` from
  `PATH`, and loads `runtime/linux/php/php.ini`.

Both launchers:

1. enforce PHP 8.2 or newer and check ODBC, OpenSSL, JSON, and session support;
2. set `GENERIC_APP_ENV=development` and pin `GENERIC_RUNTIME_CONFIG_DIR` to
   `Backend/config`, so an inherited override cannot redirect startup;
3. prepare OPcache and log directories;
4. create any missing runtime configuration from built-in defaults (never
   overwriting existing files);
5. load or generate the local database-encryption key in
   `runtime/secrets/database-encryption.key` — a key is generated only when no
   environment key or key file exists and no encrypted configuration depends on
   a missing key;
6. check that the configured Admin port is free;
7. start and verify the managed API and SQL Parser processes on loopback ports
   chosen from configured ranges;
8. validate the saved database configuration and enable database availability;
9. verify all three runtime states;
10. start the Admin Console in the foreground on `127.0.0.1:<admin-port>` with
    `GENERIC_ADMIN_ENABLED=1`, and open it in a browser (`xdg-open` or WSL on
    Linux).

If the API, SQL Parser, or database step fails, the launcher reports it, leaves
that component unavailable, and still starts the Admin Console so it can be
repaired. The launchers never start or stop SQL Server or any web-server
service.

## Working with the running system

- The Admin Console is served at `/` on its port; an `/admin` bookmark
  redirects there. Use first-run setup to create the System Administrator,
  then add a server profile and a database under **Databases** (the first
  database becomes the default). Saving writes the encrypted registry
  `database/config/databases.json`.
- **System Health** starts, stops, and restarts the API and SQL Parser
  processes and connects or disconnects database availability. Each component
  is independent; stopping one never stops Admin or the other.
- **Configuration → Server** sets the API and SQL Parser port ranges, the Admin
  port, and the loopback bind address.
- The development `/health` route on the API and parser reports process
  metadata for the process managers; `/health/live` and `/health/ready` behave
  as in production.

To run only the Admin Console with another PHP installation:

```bash
GENERIC_ADMIN_ENABLED=1 php -S 127.0.0.1:8090 -t admin admin/router.php
```

Keep the frontend and API on the same hostname form (`localhost` or
`127.0.0.1`): cookies are scoped by hostname, and mixing them causes CSRF
mismatches.

## Logs

Diagnostics go to `logs/{api,admin,database,sqlparser}/YYYY-MM-DD.txt` and
audit records to `logs/audit/YYYY-MM-DD.jsonl`. Search by the response's
`meta.requestId`. See [Logging](Logging.md).

## Troubleshooting

| Symptom | Action |
|---|---|
| `PHP runtime not found` or `php.ini not found` | Restore the bundled runtime files, or install PHP 8.2+ on Linux |
| `PHP ODBC extension not available` | Enable `odbc` in the launcher's `php.ini` and check its DLL/shared-library dependencies |
| `PHP OpenSSL extension not available` | Enable `openssl` (Windows: `php_openssl.dll` in the bundled `php.ini`) |
| `Database configuration has not been saved` | Add a server profile and a database on the **Databases** page |
| Encryption key errors | Restore the matching key file or environment value; the launcher never replaces a missing key for an already-encrypted configuration |
| Decryption failure | The encrypted configuration and key are not a matching pair, or the file was altered |
| `No compatible SQL Server ODBC driver` | Install a supported driver or configure the exact installed driver |
| No API or SQL Parser port available | Change the range in **Configuration → Server**, then start or restart the service |
| Admin port occupied | Stop the conflicting process or change the Admin port and relaunch |
| Runtime configuration bootstrap failure | Use the reported safe reason code |
| Query failures | Search `logs/database/YYYY-MM-DD.txt` by request ID; parameter values and credentials are never logged |
