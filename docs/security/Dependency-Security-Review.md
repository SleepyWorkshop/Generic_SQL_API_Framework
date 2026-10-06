# Dependency security review (v2.1.1)

Review status: **Completed** — 2026-10-06

This report records roadmap phase v2.1.1 of
[v2.1 — Security Verification & Operational Hardening](../Roadmap.md#v21--security-verification--operational-hardening).

## Purpose

Identify the third-party code the Generic SQL API Framework depends on, check
whether any of it had newer security or maintenance versions available, update
what was outdated, and confirm that the application still behaves correctly
afterwards.

## Scope

The review covers two separate kinds of dependency, which are kept apart
throughout this report:

- **Application dependencies** — third-party code that the repository itself
  declares, bundles, or loads (Composer/npm packages, vendored libraries,
  external `require`/`include` targets).
- **Runtime/OS dependencies** — the PHP interpreter, its extensions, and the
  operating-system libraries and drivers installed on the host that runs the
  application. These are provided by the host, not by the repository.

Out of scope:

- Production and staging hosts. Only the development/security-review
  environment was inspected and updated; target IIS/FastCGI or Nginx/PHP-FPM
  hosts must be reviewed separately (see
  [Remaining runtime considerations](#remaining-runtime-considerations)).
- The separate frontend projects that consume this API.
- Static analysis, DAST, and penetration testing, which are later v2.1 items.

## Review environment

- A WSL2 development/security-review environment running a rolling,
  Debian-based Linux distribution with the distribution's PHP packages.
- SQL Server access through PHP ODBC, unixODBC, and Microsoft ODBC Driver 18
  for SQL Server.
- Method: inspection of the repository contents, the installed PHP version and
  loaded extensions, and local package metadata compared against the
  candidates published in the environment's configured package repositories.

No external vulnerability scanner or CVE database scan was run.

## Application dependency inventory

| Check | Result |
| --- | --- |
| PHP source files in the repository | 216 |
| `composer.json` / Composer lock file | None — Composer is not used |
| `package.json` / npm lock file | None |
| Vendored dependency tree (for example `vendor/`) | None found |
| `require`/`require_once`/`include`/`include_once` of code outside the repository | None found |
| Database access | SQL Server through ODBC (PHP `odbc`/`PDO_ODBC` extensions) |

The application introduces **no third-party application dependencies**. All
loaded PHP code is first-party code in this repository. Its only external
dependencies are the runtime components listed below.

## Runtime dependency inventory

Versions observed in the review environment before remediation:

| Component | Version before remediation |
| --- | --- |
| PHP | 8.4.22 |
| OpenSSL | 3.6.2 |
| curl | 8.20.0 |
| SQLite | 3.46.1 |
| libxml2 | 2.15.3 |
| unixODBC | 2.3.14 |
| Microsoft ODBC Driver 18 for SQL Server | 18.7.1.1 |

## Initial findings

| Component | Finding |
| --- | --- |
| PHP | Newer security/maintenance version available |
| OpenSSL | Newer security/maintenance version available |
| curl | Newer security/maintenance version available |
| SQLite | Newer security/maintenance version available |
| libxml2 | Newer distribution package revision available |
| unixODBC | No newer candidate in the configured repositories |
| Microsoft ODBC Driver 18 | No newer candidate in the configured repositories |

No finding concerned application code, because the application declares no
third-party application dependencies.

## Remediation

The review environment was fully upgraded from its configured package
repositories. No application code or application runtime configuration was
changed.

| Component | Before | After |
| --- | --- | --- |
| PHP | 8.4.22 | 8.4.24 |
| PHP ODBC extension | 8.4.22 | 8.4.24 |
| OpenSSL | 3.6.2 | 3.6.3 |
| curl | 8.20.0 | 8.21.0 |
| SQLite | 3.46.1 | 3.53.4 |
| libxml2 | 2.15.3 | 2.15.3+dfsg-1+b1 (package rebuild) |
| unixODBC | 2.3.14 | 2.3.14 (unchanged; no candidate) |
| Microsoft ODBC Driver 18 | 18.7.1.1 | 18.7.1.1 (unchanged; no candidate) |

## Post-remediation verification

After the upgrade:

- the updated versions above were confirmed from the installed runtime;
- the PHP syntax (lint) check passed for all repository PHP files;
- the complete backend regression suite (`php tests/run.php`) passed;
- no application issue was observed with the updated runtime.

## Remaining runtime considerations

- **Production hosts are separate.** The upgrade applied only to the review
  environment. Windows/IIS and Linux/Nginx hosts install their own PHP,
  OpenSSL, curl, ODBC driver, and driver manager and must be kept current
  through their own update channels.
- **ODBC components.** unixODBC and Microsoft ODBC Driver 18 had no newer
  candidate in the configured repositories. Their versions should be rechecked
  against Microsoft's driver releases and the host distribution on each
  deployment target.
- **Rolling distribution.** The review environment uses a rolling
  distribution, so its package versions will continue to change; this report
  records a point-in-time state.
- **Repeat the review** whenever PHP, the operating system, or the ODBC stack
  is updated, and before each release.

## Limitations

- This was a version-currency review based on local package metadata and the
  configured repository information. It does not prove that any package,
  including the updated ones, is free of known or unknown vulnerabilities.
- No external vulnerability scanner, software composition analysis tool, or
  CVE database lookup was used.
- Only components with a newer candidate in the configured repositories could
  be identified as outdated; fixes published only upstream would not appear.
- Regression testing is database-independent; live SQL Server connectivity
  through the updated ODBC stack on target hosts is covered by later v2.1
  operational validation.

## Final assessment

- The application has no Composer, npm, vendored, or external-include
  dependencies; its dependency surface consists of the host PHP runtime, its
  extensions, operating-system libraries, and the ODBC stack.
- The outdated runtime components identified in the review environment (PHP,
  OpenSSL, curl, SQLite, and libxml2) were updated, and the PHP lint check and
  the full backend regression suite passed afterwards.
- unixODBC and Microsoft ODBC Driver 18 remain at their latest versions
  available from the configured repositories.
- v2.1.1 is complete. Production host runtimes remain an operational
  responsibility and should be reviewed on each target host.

| Field | Value |
| --- | --- |
| Roadmap phase | v2.1.1 — Dependency Security Review |
| Review date | 2026-10-06 |
| Status | Completed |
| Environment | Development/security-review (WSL2) |
| Application version line | 2.0.0 development line (unreleased) |
