# Admin Console, runtime, and configuration

The Admin Console is an independent loopback application available only to an enabled System Administrator. Loopback enforcement, session authentication, backend authorization, and CSRF validation remain server-side requirements.

The development launchers establish the complete runtime: API and SQL Parser are started through their existing process managers, application database availability is validated and enabled, all three states are verified, and the Admin Console starts as the independent control plane. A failed component remains accurately unavailable and can be retried from System Health. Development Start/Stop/Restart continues to control real managed processes. In production, IIS/Nginx and FastCGI/PHP-FPM own the processes while the same actions are labeled Enable/Disable/Reload and change only application availability. Disabling either runtime never stops Admin. The parser remains independent of API authentication, sessions, and database connectivity.

## Pages

- **System Health** is the runtime control plane. Its cards are Admin Console, API Server, SQL Parser, Database, PHP Runtime, Configuration, Logging, Encryption, and Backup, and its Service Actions contain the API, SQL Parser, and database lifecycle controls. Database lifecycle actions exist only here. Filesystem and PHP session-directory diagnostics are not health cards; see [Monitoring and Health](Monitoring-and-Health.md).
- **System Info** returns only application, status, platform, PHP runtime, configuration status, database status, and Admin/API/parser service status.
- **Configuration** contains Server, Database, Security, Runtime & Performance, and Advanced in development. In production it contains only Database, Security, Runtime & Performance, and Advanced: the Server tab does not exist, and a request for it (including the legacy `features` alias) opens Database. The obsolete global Features section was removed; authorization now decides who can read or write.
- **Configuration → Database** edits, tests, and saves submitted SQL Server settings. It has no Connect, Disconnect, Restart, or saved-configuration test controls. Test Connection uses the values currently in the form and does not change runtime availability.
- **Users** manages username, password, enabled state, backend role, frontend access, and frontend role without exposing hashes, sessions, or secrets.
- **Roles & Permissions** describes the four fixed roles rather than presenting individual operation toggles.
- **API Keys** manages hashed, one-time-reveal keys with one backend role.

## Configuration and runtime ownership

`config/admin.json` schema version 6 contains server, CORS, authentication, validated runtime configuration, and the non-secret backup schedule. Versions 1–5 migrate in place; obsolete feature values are dropped because Read, Write, Pagination, Sorting, and Metadata remain framework capabilities governed by authorization.

The development-only Server tab owns the launcher's loopback port ranges, Admin port, and bind address. In production `admin.settings.get` returns `server: null`, `admin.server.save` is rejected with `409 SERVER_CONFIGURATION_DEPLOYMENT_MANAGED`, and listener configuration remains owned by IIS/Nginx and FastCGI/PHP-FPM. Development process state under `runtime/` is operational data, not configuration. System Health obtains each managed process's PID, selected port, and start time from that state. Production application availability uses locked, atomically replaced `config/application-runtime-state.json` and reports external infrastructure separately, with no PID/port/start-time claim. Browser requests may select only fixed lifecycle operations and cannot supply commands, paths, executables, or arguments.

The Database section reuses the validated SQL Server configuration, request-scoped driver, and AES-256-GCM credential envelope. System Health exposes only safe server, explicit port, database name, and connection status; it never invents a database PID or default port. Stored plaintext passwords, ciphertext, encryption keys, session IDs, password hashes, and API-key secrets are never returned.

Runtime & Performance controls query timeout, API/login rate limits, session expiration, JSON body size, and pagination defaults/maximums. These values apply to new requests immediately and do not restart a service. Production Reload records/reapplies the application runtime boundary without restarting IIS/Nginx/FastCGI/PHP-FPM. See [Runtime and performance controls](Runtime-and-Performance-Controls.md).

## Platform startup

`start-windows.bat` uses the bundled Windows PHP runtime. `start-linux.sh` prefers the bundled Linux runtime and falls back to installed PHP. Both pin development mode, bootstrap missing runtime configuration, prepare the local encryption key, start API and SQL Parser, connect application database availability, verify the resulting state, and keep the Admin server in the foreground. They never start or stop SQL Server or production web-server services.
