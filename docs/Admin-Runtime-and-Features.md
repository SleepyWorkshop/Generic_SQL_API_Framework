# Admin runtime reference

The Admin Console uses dedicated System Administrator actions on its loopback-only `admin/api.php` endpoint:

| Action | Purpose | CSRF |
| --- | --- | ---: |
| `admin.health` | Safe Admin/API/parser/database/PHP status | no |
| `admin.system.info` | Minimal operational system information | no |
| `admin.console.restart` | Revalidate Admin configuration and reload application bytecode without stopping hosting infrastructure | yes |
| `admin.api.start/stop/restart` | Development process lifecycle; production application Enable/Disable/Reload | yes |
| `admin.sqlParser.start/stop/restart` | Development process lifecycle; production application Enable/Disable/Reload | yes |
| `admin.settings.get` | Read redacted configuration | no |
| `admin.server.save` | Development only: save launcher loopback ports and ranges. Production rejects it with `409 SERVER_CONFIGURATION_DEPLOYMENT_MANAGED` because IIS/Nginx own listeners | yes |
| `admin.database.get/test/save` | Read, test submitted values, or save SQL Server configuration | mutations/tests |
| `admin.database.connect/disconnect/restart` | Control the request-availability gate | yes |
| `admin.cors.save` | Save exact CORS origins | yes |
| `admin.authentication.save` | Save `none`, `session`, `api_key`, or `session+api_key` | yes |

The general API rejects `admin.*`. Runtime controls accept fixed operations only and cannot execute user-supplied commands. In development, the launchers start API and SQL Parser through the existing process managers and validate/enable application database availability; their Admin actions continue delegating to those same managers and gates. Failed startup is reported while Admin remains available for recovery. In production, infrastructure is already hosted externally and the same compatible actions change application availability: Start means Enable, Stop means Disable, and Restart means Reload. No action invokes IIS, Nginx, FastCGI, PHP-FPM, systemd, a Windows service, or SQL Server. The Admin control plane remains available when either runtime is disabled or the database is disconnected.

Configuration → Server is environment-specific. In development it edits the launcher's API and SQL Parser port ranges, Admin port, and loopback bind address, with Restart API and Restart SQL Parser. In production `admin.settings.get` returns `server: null` and a read-only `hosting` summary (mode, detected web server, and web-server-managed Admin Console, API, and SQL Parser); the page renders that summary without listener inputs or save controls.

System Health is the runtime control plane. Diagnostic cards contain status and
runtime information only; their controls are collected in the separate Service
Actions section below the cards. Development API and SQL Parser cards report the actual managed PID, dynamically selected active port, start time, and process state; stopped, crashed, or stale state contains no operational metadata. Production cards separate `Infrastructure: externally managed` from application `Enabled`/`Disabled` state and expose no fabricated process metadata. The database card reports only safe configured server, explicit port, database name, and availability/health state; its Connect, Disconnect, and Restart actions exist only in Service Actions, with no Test Saved Configuration operation. Configuration → Database retains only form validation, Test Connection for submitted values, and Save. Admin Console exposes Restart only: it revalidates configuration and clears supported application bytecode caches while the deployment service manager remains authoritative for IIS, Nginx, PHP-FPM, and local launcher lifecycle.

Production state is stored atomically in `config/application-runtime-state.json`
and serialized across workers. It survives normal requests and reboot, starts
enabled when first bootstrapped, contains no secrets, and is excluded from backup
bundles as host-specific operational state. Runtime and performance settings are
request-scoped and take effect on new requests; the production Reload action
records the explicit application reload boundary without recycling hosting
infrastructure.

There is no global Features configuration. Read, Write, Pagination, Sorting, Metadata, SQL resources, and routines remain supported and are authorized by the backend role model.
