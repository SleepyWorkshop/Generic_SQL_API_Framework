(() => {
  "use strict";
  const apiUrl = "/api.php",
    content = document.querySelector("#content"),
    title = document.querySelector("#page-title");
  const navigation = document.querySelector("#navigation"),
    logout = document.querySelector("#logout"),
    toast = document.querySelector("#toast"),
    sidebar = document.querySelector("#sidebar"),
    sidebarToggle = document.querySelector("#sidebar-toggle"),
    sidebarBackdrop = document.querySelector("#sidebar-backdrop");
  const confirmation = document.querySelector("#confirmation"),
    userDialog = document.querySelector("#user-dialog"),
    sidebarVersion = document.querySelector("#sidebar-version");
  const applicationVersion = document.body.dataset.appVersion || "unknown",
    versionLabel = `v${applicationVersion}`;
  let csrfToken = "",
    currentUser = null,
    activeConfigTab = "server",
    toastTimer = 0,
    mobileNavigationOpen = false;
  const sidebarStorageKey = "generic-admin-sidebar-collapsed",
    mobileNavigation = window.matchMedia("(max-width: 640px)");
  let sidebarCollapsed = false;
  try {
    sidebarCollapsed = localStorage.getItem(sidebarStorageKey) === "true";
  } catch {
    sidebarCollapsed = false;
  }
  const escapeHtml = (value) =>
    String(value ?? "").replace(
      /[&<>'"]/g,
      (c) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          "'": "&#39;",
          '"': "&quot;",
        })[c],
    );
  const row = (response) =>
    response.data && response.data[0] ? response.data[0] : {};
  const roleLabel = (role) =>
    ({
      "system-administrator": "Super Admin",
      "application-administrator": "Admin",
      "api-administrator": "Admin",
      "data-operator": "Data Operator",
      "read-only": "Read Only",
    })[role] || "None";
  function notify(message, error = false) {
    clearTimeout(toastTimer);
    toast.textContent = message;
    toast.className = `toast visible${error ? " error" : ""}`;
    toastTimer = setTimeout(() => (toast.className = "toast"), 4500);
  }
  function loading() {
    content.className = "panel loading";
    content.innerHTML =
      '<div class="skeleton"></div><div class="skeleton short"></div><div class="skeleton"></div>';
  }
  async function call(payload, csrf = false, retried = false) {
    const sentToken = csrf ? csrfToken : "",
      headers = { "Content-Type": "application/json" };
    if (sentToken) headers["X-CSRF-Token"] = sentToken;
    const response = await fetch(apiUrl, {
      method: "POST",
      credentials: "same-origin",
      headers,
      body: JSON.stringify(payload),
    });
    const next = response.headers.get("X-CSRF-Token");
    if (next && csrfToken === sentToken) csrfToken = next;
    const body = await response.json().catch(() => ({
      success: false,
      message: "The Admin API returned an unreadable response.",
    }));
    if (!response.ok || !body.success) {
      if (
        csrf &&
        !retried &&
        response.status === 403 &&
        body.error &&
        body.error.code === "CSRF_VALIDATION_FAILED"
      ) {
        if (csrfToken === sentToken) csrfToken = "";
        await loadCsrf();
        return call(payload, true, true);
      }
      const detail =
          body.error &&
          Array.isArray(body.error.details) &&
          body.error.details[0],
        message =
          detail && detail.message
            ? `${body.message || "The operation failed."} ${detail.message}`
            : body.message || "The operation failed.",
        error = new Error(message);
      error.code = body.error && body.error.code;
      error.details = body.error && body.error.details;
      throw error;
    }
    return body;
  }
  async function loadCsrf() {
    csrfToken = row(await call({ action: "auth.csrf" })).csrfToken || "";
  }
  function setButtonBusy(button, label) {
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = `<span class="spinner"></span> ${escapeHtml(label)}`;
    return () => {
      button.disabled = false;
      button.innerHTML = original;
    };
  }
  function confirmAction(message, titleText = "Confirm action") {
    document.querySelector("#confirm-title").textContent = titleText;
    document.querySelector("#confirm-message").textContent = message;
    document.body.classList.add("dialog-open");
    confirmation.addEventListener(
      "close",
      () => document.body.classList.remove("dialog-open"),
      { once: true },
    );
    confirmation.showModal();
    return new Promise((resolve) =>
      confirmation.addEventListener(
        "close",
        () => resolve(confirmation.returnValue === "confirm"),
        { once: true },
      ),
    );
  }
  function preAuth(enabled) {
    document.body.classList.toggle("pre-auth", enabled);
    navigation.hidden = enabled;
    logout.hidden = enabled;
  }
  function updateSidebar() {
    document.body.classList.toggle("sidebar-collapsed", sidebarCollapsed);
    document.body.classList.toggle("sidebar-open", mobileNavigationOpen);
    sidebar.dataset.collapsed = String(sidebarCollapsed);
    sidebar.dataset.mobileOpen = String(mobileNavigationOpen);
    const expanded = mobileNavigation.matches
      ? mobileNavigationOpen
      : !sidebarCollapsed;
    sidebarToggle.setAttribute("aria-expanded", String(expanded));
    sidebarToggle.setAttribute(
      "aria-label",
      expanded ? "Collapse navigation" : "Expand navigation",
    );
    sidebarBackdrop.setAttribute("aria-hidden", String(!mobileNavigationOpen));
    sidebarBackdrop.tabIndex = mobileNavigationOpen ? 0 : -1;
  }
  function closeMobileNavigation() {
    if (!mobileNavigationOpen) return;
    mobileNavigationOpen = false;
    updateSidebar();
    sidebarToggle.focus();
  }
  sidebarToggle.addEventListener("click", () => {
    if (mobileNavigation.matches) {
      mobileNavigationOpen = !mobileNavigationOpen;
    } else {
      sidebarCollapsed = !sidebarCollapsed;
      try {
        localStorage.setItem(sidebarStorageKey, String(sidebarCollapsed));
      } catch {
        // Sidebar persistence is optional when browser storage is unavailable.
      }
    }
    updateSidebar();
  });
  sidebarBackdrop.addEventListener("click", closeMobileNavigation);
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeMobileNavigation();
  });
  mobileNavigation.addEventListener("change", () => {
    mobileNavigationOpen = false;
    updateSidebar();
  });
  updateSidebar();
  function passwordToggle(form) {
    const input = form.querySelector('input[type="password"]'),
      button = form.querySelector("[data-password-toggle]");
    if (!input || !button) return;
    button.addEventListener("click", () => {
      const visible = input.type === "text";
      input.type = visible ? "password" : "text";
      button.textContent = visible ? "Show" : "Hide";
      button.setAttribute("aria-pressed", String(!visible));
    });
  }
  function protectSoleSuperAdminAuthorization(event) {
    const button = event.target.closest('[data-user-action="authorization"]');
    if (!button) return;
    const tableRow = button.closest("tr"),
      protectedAction =
        tableRow &&
        tableRow.querySelector('[data-user-action="disable"]:disabled');
    if (!protectedAction) return;
    queueMicrotask(() => {
      const form = userDialog.querySelector("form"),
        select = form && form.querySelector('[name="role"]');
      if (!form || !select) return;
      select.disabled = true;
      select.title = "At least one enabled Super Admin must remain";
      const preserved = document.createElement("input");
      preserved.type = "hidden";
      preserved.name = "role";
      preserved.value = "system-administrator";
      form.append(preserved);
      const note = document.createElement("p");
      note.className = "help";
      note.textContent = "At least one enabled Super Admin must remain.";
      select.closest("label").after(note);
    });
  }
  content.addEventListener("click", protectSoleSuperAdminAuthorization, true);

  function setupView() {
    preAuth(true);
    title.textContent = "Create Super Admin";
    content.className = "panel login-panel";
    content.innerHTML = `<div class="login-brand"><span class="brand-mark">G</span><span>Generic SQL API</span></div><form id="setup-form" class="stack"><div><h2>Create Super Admin</h2><p class="login-subtitle">Complete the first administrator profile. Role: <strong>Super Admin</strong>.</p></div><label>Name *<input name="name" autocomplete="name" required maxlength="120"></label><label>Username *<input name="username" autocomplete="username" required maxlength="64"></label><label>Mobile Number *<input name="mobile" autocomplete="tel" inputmode="tel" required maxlength="24"></label><label>Email (optional)<input name="email" type="email" autocomplete="email" maxlength="254"></label><label>Password *<input name="password" type="password" autocomplete="new-password" required></label><label>Confirm Password *<input name="passwordConfirmation" type="password" autocomplete="new-password" required></label><div><button>Create Super Admin</button></div><span class="login-version">${escapeHtml(versionLabel)}</span></form>`;
    document
      .querySelector("#setup-form")
      .addEventListener("submit", async (event) => {
        event.preventDefault();
        const button = event.currentTarget.querySelector("button"),
          done = setButtonBusy(button, "Creating…"),
          values = new FormData(event.currentTarget);
        try {
          await call(
            {
              action: "setup.createAdmin",
              name: values.get("name"),
              username: values.get("username"),
              mobile: values.get("mobile"),
              email: values.get("email") || null,
              password: values.get("password"),
              passwordConfirmation: values.get("passwordConfirmation"),
            },
            true,
          );
          notify("Super Admin created. Sign in to continue.");
          loginView();
        } catch (error) {
          notify(error.message, true);
        } finally {
          done();
        }
      });
  }
  function loginView() {
    currentUser = null;
    preAuth(true);
    title.textContent = "Welcome back";
    content.className = "panel login-panel";
    content.innerHTML = `<div class="login-brand"><span>Generic SQL API</span></div><form id="login-form" class="stack"><div><h2>Welcome back</h2><p class="login-subtitle">Sign in to continue to your workspace.</p></div><label>Username or email<input name="username" autocomplete="username" required autofocus></label><label>Password<div class="password-field"><input name="password" type="password" autocomplete="current-password" required><button class="password-toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false">Show</button></div></label><button class="login-submit">Sign In</button><span class="login-version">${escapeHtml(versionLabel)}</span></form>`;
    const form = document.querySelector("#login-form");
    passwordToggle(form);
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const button = event.currentTarget.querySelector(".login-submit"),
        done = setButtonBusy(button, "Signing in…"),
        values = new FormData(event.currentTarget);
      try {
        const snapshot = row(
          await call(
            {
              action: "auth.login",
              username: values.get("username"),
              password: values.get("password"),
            },
            true,
          ),
        );
        if (
          !snapshot.authenticated ||
          !snapshot.user ||
          snapshot.user.backendRole !== "system-administrator"
        ) {
          await call({ action: "auth.logout" }, true);
          throw new Error("Administrator access is required.");
        }
        currentUser = snapshot.user;
        await enterConsole();
      } catch (error) {
        notify(error.message, true);
      } finally {
        done();
      }
    });
  }
  async function entryView() {
    const setup = row(await call({ action: "setup.status" }));
    if (!setup.initialized) setupView();
    else loginView();
  }

  function healthCard(name, item, details = [], controls = "") {
    const ok = item.healthy ?? item.running ?? item.status === "healthy";
    return `<article class="card health-card"><h3>${escapeHtml(name)}</h3><div class="status-line"><span class="dot${ok ? "" : " offline"}"></span>${escapeHtml(item.status || (item.running ? "running" : "stopped"))}</div>${details
      .filter(([, v]) => v !== null && v !== undefined && v !== "")
      .map(
        ([k, v]) =>
          `<div class="metric">${escapeHtml(k)}: <strong>${escapeHtml(v)}</strong></div>`,
      )
      .join(
        "",
      )}${controls ? `<div class="actions section-actions">${controls}</div>` : ""}</article>`;
  }
  function serviceControls(service, item) {
    if (item.lifecycleManaged === false) return "";
    if (item.controlMode === "application")
      return item.running
        ? `<button data-service="${service}" data-operation="stop" data-control-mode="application" class="danger">Disable</button><button data-service="${service}" data-operation="restart" data-control-mode="application" class="secondary">Reload</button>`
        : `<button data-service="${service}" data-operation="start" data-control-mode="application">Enable</button>`;
    return item.running
      ? `<button data-service="${service}" data-operation="stop" class="danger">Stop</button><button data-service="${service}" data-operation="restart" class="secondary">Restart</button>`
      : `<button data-service="${service}" data-operation="start">Start</button>`;
  }
  function databaseControls(database) {
    return database.available
      ? '<button data-database-runtime="disconnect" class="danger">Disconnect</button><button data-database-runtime="restart" class="secondary">Restart</button>'
      : '<button data-database-runtime="connect">Connect</button>';
  }
  async function infoView() {
    loading();
    const info = row(await call({ action: "admin.system.info" }));
    title.textContent = "System Info";
    const display = (value) =>
      typeof value === "boolean"
        ? value
          ? "Available"
          : "Unavailable"
        : (value ?? "Unknown");
    content.className = "panel";
    content.innerHTML = `<div class="grid"><article class="section-card"><h2>System</h2><dl class="detail-list"><dt>Application</dt><dd>${escapeHtml(display(info.application))}</dd><dt>Status</dt><dd>${escapeHtml(display(info.status))}</dd><dt>Platform</dt><dd>${escapeHtml(display(info.platform))}</dd><dt>PHP Runtime</dt><dd>${escapeHtml(display(info.phpRuntime))}</dd><dt>Configuration</dt><dd>${escapeHtml(display(info.configurationStatus))}</dd><dt>Database</dt><dd>${escapeHtml(display(info.databaseStatus))}</dd></dl></article><article class="section-card"><h2>Services</h2><dl class="detail-list"><dt>Admin Console</dt><dd>${escapeHtml(display(info.services && info.services.adminConsole))}</dd><dt>API</dt><dd>${escapeHtml(display(info.services && info.services.api))}</dd><dt>SQL Parser</dt><dd>${escapeHtml(display(info.services && info.services.sqlParser))}</dd></dl></article></div>`;
  }

  function databasePayload(db, form = null) {
    if (form) {
      const v = new FormData(form);
      return {
        provider: "sqlserver",
        driver: v.get("driver"),
        server: v.get("server"),
        port: v.get("port"),
        database: v.get("database"),
        authentication: v.get("authentication"),
        username: v.get("username"),
        password: v.get("password") || null,
        encrypt: v.get("encrypt") === "on",
        trustServerCertificate: v.get("trustServerCertificate") === "on",
      };
    }
    return {
      provider: db.provider,
      driver: db.driver,
      server: db.server,
      port: db.port,
      database: db.database,
      authentication: db.authentication,
      username: db.username,
      password: null,
      encrypt: db.encrypt,
      trustServerCertificate: db.trustServerCertificate,
    };
  }
  async function configurationView(tab = activeConfigTab) {
    activeConfigTab = tab === "features" ? "server" : tab;
    loading();
    const settings = row(await call({ action: "admin.settings.get" }));
    title.textContent = "Configuration";
    content.className = "panel";
    content.innerHTML = `<div class="tabs">${["server", "database", "security", "runtime", "advanced"].map((name) => `<button class="tab${name === activeConfigTab ? " active" : ""}" data-tab="${name}">${name === "runtime" ? "Runtime & Performance" : name[0].toUpperCase() + name.slice(1)}</button>`).join("")}</div><div id="config-section"></div>`;
    content
      .querySelectorAll("[data-tab]")
      .forEach((button) =>
        button.addEventListener("click", () =>
          configurationView(button.dataset.tab),
        ),
      );
    const target = document.querySelector("#config-section");
    if (activeConfigTab === "server")
      serverSection(target, settings.server, settings.hostingMode);
    else if (activeConfigTab === "database") await databaseSection(target);
    else if (activeConfigTab === "security") securitySection(target, settings);
    else if (activeConfigTab === "runtime")
      runtimeSection(target, settings.runtime);
    else advancedSection(target, settings.advanced);
  }
  function serverSection(target, server, hostingMode) {
    const production = hostingMode === "production",
      lifecycleLabel = production ? "Reload" : "Restart",
      serverHelp = production
        ? "Production listener ports and worker processes are managed by IIS/Nginx and PHP FastCGI/FPM."
        : "API and SQL Parser each use the first available loopback port in their range. The Admin Console port is always reserved.",
      restartNote = production
        ? "● These ranges are development settings. Production listeners are deployment-managed; Reload records an application reload without restarting infrastructure."
        : "● API and SQL Parser restarts are required after changing their ranges. Admin port changes apply on the next launcher start.";
    target.innerHTML = `<form id="server-form" class="stack"><p class="help">${serverHelp}</p><div class="row"><label>API Port Minimum<input name="apiPortMinimum" type="number" min="1" max="65535" value="${server.apiPortMinimum}" required></label><label>API Port Maximum<input name="apiPortMaximum" type="number" min="1" max="65535" value="${server.apiPortMaximum}" required></label></div><div class="row"><label>Parser Port Minimum<input name="parserPortMinimum" type="number" min="1" max="65535" value="${server.parserPortMinimum}" required></label><label>Parser Port Maximum<input name="parserPortMaximum" type="number" min="1" max="65535" value="${server.parserPortMaximum}" required></label></div><div class="row"><label>Admin Port<input name="adminPort" type="number" min="1" max="65535" value="${server.adminPort}" required></label><label>Bind Address<input name="bindAddress" value="127.0.0.1" readonly></label></div><p class="restart-note">${restartNote}</p><div class="actions"><button>Save Server Configuration</button><button type="button" class="secondary" data-server-restart="api">${lifecycleLabel} API</button><button type="button" class="secondary" data-server-restart="sqlParser">${lifecycleLabel} SQL Parser</button></div></form>`;
    target.querySelector("form").addEventListener("submit", async (event) => {
      event.preventDefault();
      const button = event.currentTarget.querySelector(
          "button:not([type=button])",
        ),
        done = setButtonBusy(button, "Saving…"),
        v = new FormData(event.currentTarget);
      try {
        const result = row(
            await call(
              {
                action: "admin.server.save",
                server: {
                  apiPortMinimum: Number(v.get("apiPortMinimum")),
                  apiPortMaximum: Number(v.get("apiPortMaximum")),
                  parserPortMinimum: Number(v.get("parserPortMinimum")),
                  parserPortMaximum: Number(v.get("parserPortMaximum")),
                  adminPort: Number(v.get("adminPort")),
                  bindAddress: "127.0.0.1",
                },
              },
              true,
            ),
          ),
          restarts = [];
        if (result.apiRestartRequired) restarts.push("API");
        if (result.parserRestartRequired) restarts.push("SQL Parser");
        if (result.adminRestartRequired) restarts.push("Admin launcher");
        notify(
          production
            ? "Configuration saved. Production listeners remain infrastructure-managed."
            : restarts.length
              ? `Configuration saved. Restart required: ${restarts.join(", ")}.`
              : "Configuration saved.",
        );
      } catch (error) {
        notify(error.message, true);
      } finally {
        done();
      }
    });
    target.querySelectorAll("[data-server-restart]").forEach((button) =>
      button.addEventListener("click", async () => {
        const service = button.dataset.serverRestart,
          label = service === "api" ? "API" : "SQL Parser",
          done = setButtonBusy(button, `${lifecycleLabel}ing…`);
        try {
          const result = row(
            await call({ action: `admin.${service}.restart` }, true),
          );
          notify(
            `${label} ${production ? "reloaded" : "restarted"}${result.port ? ` on port ${result.port}` : ""}.`,
          );
        } catch (error) {
          notify(error.message, true);
        } finally {
          done();
        }
      }),
    );
  }
  async function databaseSection(target) {
    const db = row(await call({ action: "admin.database.get" })),
      options = db.availableDrivers
        .map(
          (driver) =>
            `<option${driver === db.driver ? " selected" : ""}>${escapeHtml(driver)}</option>`,
        )
        .join(""),
      authenticationOptions = db.availableAuthenticationModes
        .map(
          (mode) =>
            `<option value="${escapeHtml(mode)}"${mode === db.authentication ? " selected" : ""}>${mode === "windows" ? "Windows integrated" : "SQL login"}</option>`,
        )
        .join("");
    target.innerHTML = `<form id="database-form" class="stack"><p class="help">Stored credentials remain encrypted. Leave password blank to retain the current password.</p><div class="row"><label>ODBC Driver<select name="driver">${options}</select></label><label>Server<input name="server" value="${escapeHtml(db.server)}" required></label></div><div class="row"><label>Port<input name="port" value="${escapeHtml(db.port)}"></label><label>Database<input name="database" value="${escapeHtml(db.database)}" required></label></div><div class="row"><label>Authentication<select name="authentication">${authenticationOptions}</select></label><label>Username<input name="username" value="${escapeHtml(db.username)}"></label></div><label>Password<input name="password" type="password" autocomplete="new-password" placeholder="${db.passwordConfigured ? "Stored password retained" : "Required for SQL login"}"></label><div class="row"><label class="check"><input name="encrypt" type="checkbox"${db.encrypt ? " checked" : ""}> Encrypt connection</label><label class="check"><input name="trustServerCertificate" type="checkbox"${db.trustServerCertificate ? " checked" : ""}> Trust server certificate</label></div><div class="actions"><button type="button" id="test-database" class="secondary">Test Connection</button><button>Save Database</button></div></form>`;
    const form = target.querySelector("form");
    target
      .querySelector("#test-database")
      .addEventListener("click", async (event) => {
        const done = setButtonBusy(event.currentTarget, "Testing…");
        try {
          await call(
            {
              action: "admin.database.test",
              database: databasePayload(null, form),
            },
            true,
          );
          notify("Database connection succeeded.");
        } catch (error) {
          notify(error.message, true);
        } finally {
          done();
        }
      });
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const button = event.currentTarget.querySelector(
          "button:not([type=button])",
        ),
        done = setButtonBusy(button, "Saving…");
      try {
        await call(
          {
            action: "admin.database.save",
            database: databasePayload(null, form),
          },
          true,
        );
        notify("Encrypted database configuration saved.");
        await configurationView("database");
      } catch (error) {
        notify(error.message, true);
      } finally {
        done();
      }
    });
  }
  function securitySection(target, settings) {
    const mode = settings.authentication.mode,
      session = settings.security.session;
    target.innerHTML = `<div class="stack"><form id="authentication-form" class="stack"><h2>Authentication</h2><p class="help">Administrator endpoints always require an administrator session.</p><label>Authentication mode<select name="mode"><option value="session"${mode === "session" ? " selected" : ""}>Session</option><option value="api_key"${mode === "api_key" ? " selected" : ""}>API Key</option><option value="session+api_key"${mode === "session+api_key" ? " selected" : ""}>Session + API Key</option><option value="none"${mode === "none" ? " selected" : ""}>None for normal API actions</option></select></label><div><button>Save Authentication</button></div></form><div class="section-card"><h2>Session and CSRF</h2><dl class="detail-list"><dt>CSRF protection</dt><dd>${settings.security.csrfEnabled ? "Enabled" : "Disabled"}</dd><dt>Idle expiration</dt><dd>${escapeHtml(session.idleTimeout)} seconds</dd><dt>Absolute expiration</dt><dd>${escapeHtml(session.absoluteTimeout)} seconds</dd><dt>SameSite</dt><dd>${escapeHtml(session.samesite)}</dd><dt>HttpOnly</dt><dd>${session.httponly ? "Enabled" : "Disabled"}</dd><dt>Secure cookie</dt><dd>${session.secure ? "Enabled" : "Disabled"}</dd></dl></div><form id="cors-form" class="stack"><h2>CORS</h2><label>Allowed origins<textarea name="origins">${escapeHtml(settings.cors.allowedOrigins.join("\n"))}</textarea></label><label class="check"><input name="credentials" type="checkbox"${settings.cors.credentialsEnabled ? " checked" : ""}> Allow browser credentials</label><div><button>Save CORS</button></div></form></div>`;
    target
      .querySelector("#authentication-form")
      .addEventListener("submit", async (event) => {
        event.preventDefault();
        const button = event.currentTarget.querySelector("button"),
          done = setButtonBusy(button, "Saving…"),
          v = new FormData(event.currentTarget);
        try {
          await call(
            { action: "admin.authentication.save", mode: v.get("mode") },
            true,
          );
          notify("Authentication configuration saved.");
        } catch (error) {
          notify(error.message, true);
        } finally {
          done();
        }
      });
    target
      .querySelector("#cors-form")
      .addEventListener("submit", async (event) => {
        event.preventDefault();
        const button = event.currentTarget.querySelector("button"),
          done = setButtonBusy(button, "Saving…"),
          v = new FormData(event.currentTarget),
          origins = String(v.get("origins"))
            .split(/\r?\n/)
            .map((x) => x.trim())
            .filter(Boolean);
        try {
          await call(
            {
              action: "admin.cors.save",
              cors: {
                allowedOrigins: origins,
                credentialsEnabled: v.get("credentials") === "on",
                allowedMethods: ["POST", "OPTIONS"],
              },
            },
            true,
          );
          notify("CORS configuration saved.");
        } catch (error) {
          notify(error.message, true);
        } finally {
          done();
        }
      });
  }
  function runtimeSection(target, runtime) {
    const q = runtime.query,
      api = runtime.rateLimit.api,
      login = runtime.rateLimit.login,
      session = runtime.session,
      request = runtime.request;
    target.innerHTML = `<form id="runtime-form" class="stack"><p class="help">Changes apply to new requests immediately. Values are validated by the backend before the current configuration is replaced.</p><div class="section-card stack"><h2>Query</h2><label>SQL query timeout (seconds)<input name="queryTimeout" type="number" min="1" max="300" value="${q.timeoutSeconds}" required></label><p class="help">Stops supported ODBC statements that run longer than this duration.</p></div><div class="section-card stack"><h2>API Protection</h2><label class="check"><input name="apiEnabled" type="checkbox"${api.enabled ? " checked" : ""}> Enable API rate limiting</label><div class="row"><label>Requests per window<input name="apiRequests" type="number" min="1" max="10000" value="${api.requests}" required></label><label>Window duration (seconds)<input name="apiWindow" type="number" min="1" max="86400" value="${api.windowSeconds}" required></label></div></div><div class="section-card stack"><h2>Login Protection</h2><label class="check"><input name="loginEnabled" type="checkbox"${login.enabled ? " checked" : ""}> Enable login throttling</label><div class="row"><label>Maximum failed attempts<input name="loginAttempts" type="number" min="2" max="100" value="${login.maximumAttempts}" required></label><label>Failure window (seconds)<input name="loginWindow" type="number" min="60" max="86400" value="${login.windowSeconds}" required></label></div><label>Lockout duration (seconds)<input name="loginLockout" type="number" min="30" max="86400" value="${login.lockoutSeconds}" required></label></div><div class="section-card stack"><h2>Sessions</h2><div class="row"><label>Idle session timeout (seconds)<input name="sessionIdle" type="number" min="60" max="2592000" value="${session.idleTimeoutSeconds}" required></label><label>Maximum session lifetime (seconds)<input name="sessionAbsolute" type="number" min="300" max="2592000" value="${session.absoluteTimeoutSeconds}" required></label></div></div><div class="section-card stack"><h2>Requests</h2><label>Maximum request body (bytes)<input name="bodyBytes" type="number" min="1024" max="104857600" value="${request.maxBodyBytes}" required></label><div class="row"><label>Default page size<input name="defaultPageSize" type="number" min="1" max="10000" value="${request.defaultPageSize}" required></label><label>Maximum page size<input name="maxPageSize" type="number" min="1" max="10000" value="${request.maxPageSize}" required></label></div><p class="help">Oversized page requests are rejected rather than silently reduced.</p></div><div><button>Save Runtime Settings</button></div></form>`;
    target
      .querySelector("#runtime-form")
      .addEventListener("submit", async (event) => {
        event.preventDefault();
        const form = event.currentTarget,
          v = new FormData(form),
          number = (name) => Number(v.get(name)),
          button = form.querySelector("button"),
          done = setButtonBusy(button, "Saving…"),
          payload = {
            query: { timeoutSeconds: number("queryTimeout") },
            rateLimit: {
              api: {
                enabled: v.get("apiEnabled") === "on",
                requests: number("apiRequests"),
                windowSeconds: number("apiWindow"),
              },
              login: {
                enabled: v.get("loginEnabled") === "on",
                maximumAttempts: number("loginAttempts"),
                windowSeconds: number("loginWindow"),
                lockoutSeconds: number("loginLockout"),
              },
            },
            session: {
              idleTimeoutSeconds: number("sessionIdle"),
              absoluteTimeoutSeconds: number("sessionAbsolute"),
            },
            request: {
              maxBodyBytes: number("bodyBytes"),
              defaultPageSize: number("defaultPageSize"),
              maxPageSize: number("maxPageSize"),
            },
          };
        try {
          await call({ action: "admin.runtime.save", runtime: payload }, true);
          notify("Runtime configuration saved.");
          await configurationView("runtime");
        } catch (error) {
          notify(error.message, true);
        } finally {
          done();
        }
      });
  }
  function advancedSection(target, advanced) {
    target.innerHTML = `<div class="stack"><p class="help">Advanced runtime values are managed by the existing environment and server configuration.</p><dl class="detail-list"><dt>Logging</dt><dd>${escapeHtml(advanced.logging)}</dd><dt>Debug mode</dt><dd>${advanced.debugMode ? "Enabled" : "Disabled"}</dd></dl></div>`;
  }

  let usersView;
  function userRole(user) {
    if (user.backendRole === "system-administrator") return "Super Admin";
    if (user.frontendRole === "application-administrator") return "Admin";
    return roleLabel(user.backendRole || "read-only");
  }
  function userRolePreset(user) {
    if (user.backendRole === "system-administrator")
      return "system-administrator";
    if (user.frontendRole === "application-administrator")
      return "application-administrator";
    if (user.backendRole === "data-operator") return "data-operator";
    return "read-only";
  }
  function closeUserDialog() {
    if (userDialog.open) userDialog.close();
  }
  usersView = async function () {
    loading();
    const users = (await call({ action: "auth.users.list" })).data || [],
      enabledAdmins = users.filter(
        (user) => user.enabled && user.backendRole === "system-administrator",
      ).length;
    title.textContent = "Users";
    content.className = "panel users-panel";
    content.innerHTML = `<div class="users-heading"><p class="help">Manage user profiles and authorization without exposing secrets or session details.</p><button id="add-user">+ Create User</button></div>${
      users.length
        ? `<div class="table-wrap users-table-wrap"><table class="users-table"><thead><tr><th>Name</th><th>Username</th><th>Mobile Number</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>${users
            .map((user) => {
              const current =
                currentUser &&
                user.username.toLowerCase() ===
                  currentUser.username.toLowerCase();
              const lastAdmin =
                user.enabled &&
                user.backendRole === "system-administrator" &&
                enabledAdmins === 1;
              return `<tr><td data-label="Name">${escapeHtml(user.name || "—")}</td><td class="username-cell" data-label="Username">${escapeHtml(user.username)}${current ? ' <span class="current-user">(you)</span>' : ""}</td><td data-label="Mobile Number">${escapeHtml(user.mobile || "—")}</td><td data-label="Email">${escapeHtml(user.email || "—")}</td><td data-label="Role"><span class="role-badge">${escapeHtml(userRole(user))}</span></td><td data-label="Status"><span class="badge${user.enabled ? "" : " disabled"}">${user.enabled ? "Enabled" : "Disabled"}</span></td><td data-label="Created">${escapeHtml(new Date(user.createdAt).toLocaleString())}</td><td class="user-actions-cell" data-label="Actions"><div class="actions"><button class="small secondary" data-user-action="authorization" data-username="${escapeHtml(user.username)}"${current ? ' disabled title="You cannot change your own authorization"' : ""}>Authorization</button><button class="small secondary" data-user-action="edit" data-username="${escapeHtml(user.username)}">Edit User</button><button class="small secondary" data-user-action="password" data-username="${escapeHtml(user.username)}">Change Password</button><button class="small secondary" data-user-action="${user.enabled ? "disable" : "enable"}" data-username="${escapeHtml(user.username)}"${lastAdmin ? ' disabled title="At least one enabled Super Admin must remain"' : ""}>${user.enabled ? "Disable" : "Enable"}</button><button class="small danger" data-user-action="delete" data-username="${escapeHtml(user.username)}"${lastAdmin ? ' disabled title="At least one enabled Super Admin must remain"' : ""}>Delete</button></div></td></tr>`;
            })
            .join("")}</tbody></table></div>`
        : '<div class="empty">No users found.</div>'
    }`;
    const fields = (user) =>
      `<label>Name *<input name="name" required maxlength="120" autocomplete="name" value="${escapeHtml(user?.name || "")}"></label><label>Username *<input name="newUsername" required maxlength="64" autocomplete="username" value="${escapeHtml(user?.username || "")}"></label><label>Mobile Number *<input name="mobile" required maxlength="24" inputmode="tel" autocomplete="tel" value="${escapeHtml(user?.mobile || "")}"></label><label>Email (optional)<input name="email" type="email" maxlength="254" autocomplete="email" value="${escapeHtml(user?.email || "")}"></label>`;
    let opener = null;
    const open = (mode, user, button) => {
      opener = button;
      const heading =
        mode === "create"
          ? "Create User"
          : mode === "edit"
            ? "Edit User"
            : mode === "password"
              ? "Change Password"
              : `Authorization for ${user.username}`;
      let formBody = `${fields(null)}
        <label>Role *<select name="role" required><option value="read-only">Read Only</option><option value="data-operator">Data Operator</option><option value="application-administrator">Admin</option><option value="system-administrator">Super Admin</option></select></label>
        <label>Password *<input name="password" type="password" required autocomplete="new-password"></label>
        <label>Confirm Password *<input name="passwordConfirmation" type="password" required autocomplete="new-password"></label>
        <label class="check"><input name="enabled" type="checkbox" checked> Enabled</label>`;
      let submitLabel = "Create User";
      if (mode === "edit") {
        formBody = `<input name="username" type="hidden" value="${escapeHtml(user.username)}">${fields(user)}`;
        submitLabel = "Save User";
      }
      if (mode === "password") {
        formBody = `<input name="username" type="hidden" value="${escapeHtml(user.username)}">
          <label>New Password *<input name="newPassword" type="password" required autocomplete="new-password"></label>
          <label>Confirm Password *<input name="passwordConfirmation" type="password" required autocomplete="new-password"></label>`;
        submitLabel = "Change Password";
      }
      if (mode === "authorization") {
        const selected = userRolePreset(user);
        formBody = `<input name="username" type="hidden" value="${escapeHtml(user.username)}">
          <p class="help">Choose a role preset. The backend applies and validates the corresponding authorization.</p>
          <label>Role *<select name="role" required><option value="system-administrator"${selected === "system-administrator" ? " selected" : ""}>Super Admin</option><option value="application-administrator"${selected === "application-administrator" ? " selected" : ""}>Admin</option><option value="data-operator"${selected === "data-operator" ? " selected" : ""}>Data Operator</option><option value="read-only"${selected === "read-only" ? " selected" : ""}>Read Only</option></select></label>`;
        submitLabel = "Save Authorization";
      }
      document.querySelector("#user-dialog-content").innerHTML =
        `<div class="user-dialog__surface">
          <header class="user-dialog__header">
            <h2 id="user-dialog-title">${escapeHtml(heading)}</h2>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close">×</button>
          </header>
          <form class="user-dialog__form">
            <div class="user-dialog__body stack">
              ${formBody}
              <p class="dialog-error" data-dialog-error role="alert" hidden></p>
            </div>
            <footer class="dialog-actions user-dialog__footer">
              <button type="button" class="secondary" data-dialog-close>Cancel</button>
              <button>${escapeHtml(submitLabel)}</button>
            </footer>
          </form>
        </div>`;
      userDialog
        .querySelectorAll("[data-dialog-close]")
        .forEach((item) => item.addEventListener("click", closeUserDialog));
      userDialog
        .querySelector("form")
        .addEventListener("submit", async (event) => {
          event.preventDefault();
          const submit = event.currentTarget.querySelector(
              "button:not([type=button])",
            ),
            done = setButtonBusy(submit, "Saving…"),
            v = new FormData(event.currentTarget),
            dialogError = userDialog.querySelector("[data-dialog-error]");
          dialogError.hidden = true;
          try {
            if (mode === "create")
              await call(
                {
                  action: "auth.users.create",
                  name: v.get("name"),
                  username: v.get("newUsername"),
                  mobile: v.get("mobile"),
                  email: v.get("email") || null,
                  password: v.get("password"),
                  passwordConfirmation: v.get("passwordConfirmation"),
                  enabled: v.get("enabled") === "on",
                  role: v.get("role"),
                },
                true,
              );
            else if (mode === "edit")
              await call(
                {
                  action: "auth.users.update",
                  username: v.get("username"),
                  name: v.get("name"),
                  newUsername: v.get("newUsername"),
                  mobile: v.get("mobile"),
                  email: v.get("email") || null,
                },
                true,
              );
            else if (mode === "password")
              await call(
                {
                  action: "auth.users.changePassword",
                  username: v.get("username"),
                  newPassword: v.get("newPassword"),
                  passwordConfirmation: v.get("passwordConfirmation"),
                },
                true,
              );
            else
              await call(
                {
                  action: "auth.users.assignAuthorization",
                  username: user.username,
                  role: v.get("role"),
                },
                true,
              );
            closeUserDialog();
            notify("User updated. Existing sessions were invalidated.");
            if (
              user &&
              currentUser &&
              user.username.toLowerCase() ===
                currentUser.username.toLowerCase() &&
              mode !== "create"
            )
              return loginView();
            await usersView();
          } catch (error) {
            dialogError.textContent = error.message;
            dialogError.hidden = false;
            notify(error.message, true);
          } finally {
            done();
          }
        });
      userDialog.addEventListener("close", () => opener?.focus(), {
        once: true,
      });
      document.body.classList.add("dialog-open");
      userDialog.addEventListener(
        "close",
        () => document.body.classList.remove("dialog-open"),
        { once: true },
      );
      userDialog.showModal();
    };
    content
      .querySelector("#add-user")
      .addEventListener("click", (event) =>
        open("create", null, event.currentTarget),
      );
    content.onclick = async (event) => {
      const button = event.target.closest("[data-user-action]");
      if (!button || button.disabled) return;
      const user = users.find(
          (item) => item.username === button.dataset.username,
        ),
        action = button.dataset.userAction;
      if (["authorization", "edit", "password"].includes(action))
        return open(action, user, button);
      if (
        !(await confirmAction(
          action === "delete"
            ? `Delete ${user.username}? This cannot be undone.`
            : `${action === "disable" ? "Disable" : "Enable"} ${user.username}?`,
        ))
      )
        return;
      const done = setButtonBusy(button, "Working…");
      try {
        await call(
          { action: `auth.users.${action}`, username: user.username },
          true,
        );
        notify(`User ${action} completed.`);
        if (
          currentUser &&
          user.username.toLowerCase() === currentUser.username.toLowerCase()
        )
          return loginView();
        await usersView();
      } catch (error) {
        notify(error.message, true);
      } finally {
        done();
      }
    };
  };

  async function healthView() {
    loading();
    const health = row(await call({ action: "admin.health" })),
      checks = health.monitoring?.checks || {};
    title.textContent = "System Health";
    content.className = "panel";
    const serviceDetails = (service) =>
      service.controlMode === "application"
        ? [
            ["Infrastructure", service.infrastructure?.status],
            ["Application Runtime", service.applicationRuntime?.status],
            ["Updated", service.applicationRuntime?.updatedAt],
            ["Reloaded", service.applicationRuntime?.reloadedAt],
          ]
        : [
            ["PID", service.pid],
            ["Port", service.port],
            ["Started", service.startedAt],
          ];
    content.innerHTML = `<div class="grid">${healthCard(
      "Admin Console",
      health.adminConsole,
      [
        ["PID", health.adminConsole.pid],
        ["Port", health.adminConsole.port],
        ["Started", health.adminConsole.startedAt],
      ],
    )}${healthCard(
      "API Server",
      health.api,
      serviceDetails(health.api),
      serviceControls("api", health.api),
    )}${healthCard(
      "SQL Parser",
      health.sqlParser,
      serviceDetails(health.sqlParser),
      serviceControls("sqlParser", health.sqlParser),
    )}${healthCard(
      "Database",
      health.database,
      [
        ["Server", health.database.server],
        ["Port", health.database.port],
        ["Database", health.database.database],
      ],
      databaseControls(health.database),
    )}${healthCard("PHP Runtime", health.phpRuntime, [
      ["PHP", health.phpRuntime.phpVersion],
      ["ODBC", health.phpRuntime.odbcAvailable ? "Available" : "Unavailable"],
    ])}${[
      "configuration",
      "filesystem",
      "logging",
      "sessions",
      "encryption",
      "backup",
    ]
      .filter((name) => checks[name])
      .map((name) =>
        healthCard(name[0].toUpperCase() + name.slice(1), checks[name], [
          ["Category", checks[name].category],
        ]),
      )
      .join("")}</div>`;
    content.querySelectorAll("[data-service]").forEach((button) =>
      button.addEventListener("click", async () => {
        const operation = button.dataset.operation,
          service = button.dataset.service,
          label = service === "api" ? "API" : "SQL Parser",
          applicationControl = button.dataset.controlMode === "application";
        if (
          operation === "stop" &&
          !(await confirmAction(
            applicationControl
              ? `Disable the ${label} application runtime? Production infrastructure will remain running.`
              : `Stop the ${label}? The Admin Console will remain available.`,
            `${applicationControl ? "Disable" : "Stop"} ${label}`,
          ))
        )
          return;
        const done = setButtonBusy(button, "Working…");
        try {
          await call({ action: `admin.${service}.${operation}` }, true);
          const completedOperation = applicationControl
            ? { start: "enabled", stop: "disabled", restart: "reloaded" }[
                operation
              ]
            : operation;
          notify(`${label} ${completedOperation} completed.`);
          await healthView();
        } catch (error) {
          notify(error.message, true);
        } finally {
          done();
        }
      }),
    );
    content.querySelectorAll("[data-database-runtime]").forEach((button) =>
      button.addEventListener("click", async () => {
        const operation = button.dataset.databaseRuntime;
        if (
          operation === "disconnect" &&
          !(await confirmAction(
            "Disconnect database runtime access? New API requests will be denied until reconnected.",
            "Disconnect database",
          ))
        )
          return;
        const done = setButtonBusy(button, "Working…");
        try {
          await call({ action: `admin.database.${operation}` }, true);
          notify(`Database ${operation} completed.`);
          await healthView();
        } catch (error) {
          notify(error.message, true);
        } finally {
          done();
        }
      }),
    );
  }

  function openApiKeyDialog(users, roles, opener) {
    let keyCreated = false;
    const enabledUsers = users.filter((user) => user.enabled);
    const ownerOptions = enabledUsers
      .map(
        (user) =>
          `<option value="${escapeHtml(user.username)}">${escapeHtml(user.username)}</option>`,
      )
      .join("");
    const roleOptions = roles
      .map(
        (role) =>
          `<option value="${escapeHtml(role.id)}">${escapeHtml(roleLabel(role.id))}</option>`,
      )
      .join("");

    document.querySelector("#user-dialog-content").innerHTML = `
      <div class="user-dialog__surface">
        <header class="user-dialog__header">
          <h2 id="user-dialog-title">Create API Key</h2>
          <button type="button" class="dialog-close" data-dialog-close aria-label="Close">×</button>
        </header>
        <form class="user-dialog__form" data-api-key-form novalidate>
          <div class="user-dialog__body stack">
            <label>Name *<input name="name" maxlength="100" required autocomplete="off"></label>
            <label>Owner *<select name="owner" required>${ownerOptions}</select></label>
            <label>Role *<select name="role" required>${roleOptions}</select></label>
            <p class="dialog-error" data-dialog-error role="alert" hidden></p>
          </div>
          <footer class="dialog-actions user-dialog__footer">
            <button type="button" class="secondary" data-dialog-close>Cancel</button>
            <button>Create API Key</button>
          </footer>
        </form>
      </div>`;

    userDialog
      .querySelectorAll("[data-dialog-close]")
      .forEach((button) => button.addEventListener("click", closeUserDialog));
    userDialog
      .querySelector("[data-api-key-form]")
      .addEventListener("submit", async (event) => {
        event.preventDefault();
        const values = new FormData(event.currentTarget),
          dialogError = userDialog.querySelector("[data-dialog-error]");
        dialogError.hidden = true;
        if (!String(values.get("name") || "").trim()) {
          dialogError.textContent = "Name is required.";
          dialogError.hidden = false;
          return;
        }
        if (!values.get("owner") || !values.get("role")) {
          dialogError.textContent = "Owner and role are required.";
          dialogError.hidden = false;
          return;
        }
        const submit = event.currentTarget.querySelector(
            "button:not([type=button])",
          ),
          done = setButtonBusy(submit, "Creating…");
        try {
          const created = row(
            await call(
              {
                action: "auth.apiKeys.create",
                name: String(values.get("name")).trim(),
                ownerUsername: values.get("owner"),
                roles: [values.get("role")],
              },
              true,
            ),
          );
          keyCreated = true;
          document.querySelector("#user-dialog-content").innerHTML = `
            <div class="user-dialog__surface">
              <header class="user-dialog__header">
                <h2 id="user-dialog-title">Copy this API key now</h2>
                <button type="button" class="dialog-close" data-dialog-close aria-label="Close">×</button>
              </header>
              <div class="user-dialog__body stack">
                <p class="warning">It cannot be displayed again.</p>
                <div class="secret-reveal">${escapeHtml(created.apiKey)}</div>
              </div>
              <footer class="dialog-actions user-dialog__footer">
                <button type="button" data-dialog-close>Done</button>
              </footer>
            </div>`;
          userDialog
            .querySelectorAll("[data-dialog-close]")
            .forEach((button) =>
              button.addEventListener("click", closeUserDialog),
            );
        } catch (error) {
          dialogError.textContent = error.message;
          dialogError.hidden = false;
          notify(error.message, true);
        } finally {
          done();
        }
      });
    userDialog.addEventListener(
      "close",
      () => {
        if (keyCreated) {
          void apiKeysView().then(() =>
            content.querySelector("#add-api-key")?.focus(),
          );
          return;
        }
        opener.focus();
      },
      { once: true },
    );
    document.body.classList.add("dialog-open");
    userDialog.addEventListener(
      "close",
      () => document.body.classList.remove("dialog-open"),
      { once: true },
    );
    userDialog.showModal();
  }

  async function apiKeysView() {
    loading();
    const [keyResponse, userResponse, roleResponse] = await Promise.all([
        call({ action: "auth.apiKeys.list" }),
        call({ action: "auth.users.list" }),
        call({ action: "auth.roles.list" }),
      ]),
      keys = keyResponse.data || [],
      users = userResponse.data || [],
      roles = (roleResponse.data || []).filter(
        (role) => role.apiKeyAssignable === true,
      );
    title.textContent = "API Keys";
    content.className = "panel";
    content.innerHTML = `<div class="users-heading"><p class="help">Secrets are hashed at rest and shown only once. Each key receives one API role.</p><button id="add-api-key">+ Create API Key</button></div>${keys.length ? `<div class="table-wrap"><table><thead><tr><th>Name</th><th>Owner</th><th>Role</th><th>Status</th><th>Fingerprint</th><th>Last used</th><th>Actions</th></tr></thead><tbody>${keys.map((key) => `<tr><td>${escapeHtml(key.name)}</td><td>${escapeHtml(key.ownerUsername)}</td><td>${escapeHtml(key.roles.map(roleLabel).join(", "))}</td><td><span class="badge${key.enabled && !key.revoked ? "" : " disabled"}">${key.revoked ? "Revoked" : key.enabled ? "Enabled" : "Disabled"}</span></td><td>${escapeHtml(key.fingerprint)}</td><td>${escapeHtml(key.lastUsedAt ? new Date(key.lastUsedAt).toLocaleString() : "Never")}</td><td><div class="actions">${key.revoked ? "" : `<button class="small secondary" data-key-action="${key.enabled ? "disable" : "enable"}" data-id="${key.id}">${key.enabled ? "Disable" : "Enable"}</button><button class="small danger" data-key-action="revoke" data-id="${key.id}">Revoke</button>`}</div></td></tr>`).join("")}</tbody></table></div>` : '<div class="empty">No managed API keys.</div>'}`;
    content
      .querySelector("#add-api-key")
      .addEventListener("click", (event) =>
        openApiKeyDialog(users, roles, event.currentTarget),
      );
    content.onclick = async (event) => {
      const button = event.target.closest("[data-key-action]");
      if (!button) return;
      const operation = button.dataset.keyAction;
      if (
        (operation === "revoke" || operation === "disable") &&
        !(await confirmAction(
          `${operation[0].toUpperCase() + operation.slice(1)} this API key?`,
          `${operation} API key`,
        ))
      )
        return;
      const done = setButtonBusy(button, "Working…");
      try {
        await call(
          { action: `auth.apiKeys.${operation}`, id: button.dataset.id },
          true,
        );
        notify(`API key ${operation} completed.`);
        await apiKeysView();
      } catch (error) {
        notify(error.message, true);
      } finally {
        done();
      }
    };
  }

  function fileAsBase64(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.addEventListener("load", () => {
        const value = String(reader.result || ""),
          separator = value.indexOf(",");
        separator === -1
          ? reject(new Error("The selected backup could not be read."))
          : resolve(value.slice(separator + 1));
      });
      reader.addEventListener("error", () =>
        reject(new Error("The selected backup could not be read.")),
      );
      reader.readAsDataURL(file);
    });
  }

  function downloadArchive(download) {
    const binary = atob(download.archive),
      bytes = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index += 1)
      bytes[index] = binary.charCodeAt(index);
    const url = URL.createObjectURL(
        new Blob([bytes], { type: download.mediaType || "application/zip" }),
      ),
      link = document.createElement("a");
    link.href = url;
    link.download = download.filename;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function backupPreviewMarkup(preview) {
    const list = (values) =>
      values.length
        ? `<ul>${values.map((value) => `<li>${escapeHtml(value)}</li>`).join("")}</ul>`
        : "<p>None</p>";
    return `<section class="section-card stack backup-preview" aria-live="polite">
      <div><h2>Backup Verified</h2><p class="help">Integrity and authenticity checks passed. Selecting a file has not changed the application.</p></div>
      <dl class="detail-list"><dt>Recovery point</dt><dd>${escapeHtml(preview.recoveryPointId)}</dd><dt>Created</dt><dd>${escapeHtml(preview.createdAt)}</dd><dt>Application version</dt><dd>${escapeHtml(preview.applicationVersion)}</dd><dt>Backup format</dt><dd>${escapeHtml(preview.formatVersion)}</dd><dt>Verification</dt><dd><span class="badge">Valid</span></dd><dt>Authenticity</dt><dd><span class="badge">Valid</span></dd><dt>Schema compatible</dt><dd>${preview.schemaCompatible ? "Yes" : "No"}</dd><dt>Encryption key available</dt><dd>${preview.encryptionKeyAvailable ? "Yes" : "No"}</dd></dl>
      <div class="row"><div><h3>Files that will change</h3>${list(preview.changedFiles || [])}</div><div><h3>Files unchanged</h3>${list(preview.unchangedFiles || [])}</div></div>
      <p class="warning">Restoring replaces the current application configuration. SQL Server data is not part of this recovery point.</p>
      <div class="actions"><button type="button" class="danger" id="confirm-restore">Restore Configuration</button><button type="button" class="secondary" id="cancel-restore">Cancel</button></div>
    </section>`;
  }

  async function backupRecoveryView() {
    loading();
    const history = row(await call({ action: "admin.backup.history" }))
        .recoveryPoints || [];
    title.textContent = "Backup & Recovery";
    content.className = "panel stack";
    content.innerHTML = `<section class="section-card stack"><div><h2>Application Configuration Backup</h2><p class="help">Create a signed ZIP recovery point. Application backups do not contain SQL Server data, encryption keys, sessions, runtime state, or plaintext secrets.</p></div><div><button id="create-backup">Create Backup</button></div></section>
      <section class="section-card stack"><div><h2>Verify Backup & Restore</h2><p class="help">Select an Application Backup ZIP using the native file picker. It will be uploaded, verified, and previewed before explicit confirmation is available.</p></div><div class="actions"><button type="button" class="secondary" id="select-restore-backup">Restore Backup</button><span class="help" id="selected-backup-name">No backup selected.</span></div><input id="restore-backup" type="file" accept=".zip,application/zip" hidden><div id="restore-preview"></div></section>
      <section class="section-card stack"><div><h2>Recovery Points</h2><p class="help">Verified application configuration recovery points stored by this installation.</p></div>${
        history.length
          ? `<div class="table-wrap"><table><thead><tr><th>Created</th><th>Recovery Point</th><th>Size</th><th>Version</th><th>Verification</th><th>Authenticity</th><th>Actions</th></tr></thead><tbody>${history
              .map(
                (point) =>
                  `<tr><td>${escapeHtml(point.createdAt || "Unavailable")}</td><td>${escapeHtml(point.recoveryPointId || point.filename)}</td><td>${escapeHtml(point.size)} bytes</td><td>${escapeHtml(point.applicationVersion || "Unknown")}</td><td><span class="badge${point.verification === "valid" ? "" : " disabled"}">${escapeHtml(point.verification)}</span></td><td><span class="badge${point.authenticity === "valid" ? "" : " disabled"}">${escapeHtml(point.authenticity)}</span></td><td>${point.recoveryPointId ? `<button type="button" class="small secondary" data-download-backup="${escapeHtml(point.recoveryPointId)}">Download</button>` : "Unavailable"}</td></tr>`,
              )
              .join("")}</tbody></table></div>`
          : '<div class="empty">No recovery points have been created.</div>'
      }</section>`;

    content.querySelector("#create-backup").addEventListener("click", async (event) => {
      const done = setButtonBusy(event.currentTarget, "Creating and verifying…");
      try {
        const created = row(await call({ action: "admin.backup.create" }, true));
        const download = row(
          await call(
            { action: "admin.backup.download", recoveryPointId: created.recoveryPointId },
            true,
          ),
        );
        downloadArchive(download);
        notify("Backup verified and prepared for download.");
        await backupRecoveryView();
      } catch (error) {
        notify(error.message, true);
      } finally {
        done();
      }
    });
    content.querySelectorAll("[data-download-backup]").forEach((button) =>
      button.addEventListener("click", async () => {
        const done = setButtonBusy(button, "Preparing…");
        try {
          downloadArchive(
            row(
              await call(
                {
                  action: "admin.backup.download",
                  recoveryPointId: button.dataset.downloadBackup,
                },
                true,
              ),
            ),
          );
        } catch (error) {
          notify(error.message, true);
        } finally {
          done();
        }
      }),
    );
    const restoreInput = content.querySelector("#restore-backup");
    content
      .querySelector("#select-restore-backup")
      .addEventListener("click", () => restoreInput.click());
    restoreInput.addEventListener("change", async (event) => {
      const file = event.currentTarget.files && event.currentTarget.files[0],
        target = content.querySelector("#restore-preview");
      if (!file) return;
      content.querySelector("#selected-backup-name").textContent = file.name;
      target.innerHTML = '<p><span class="spinner"></span> Uploading and verifying backup…</p>';
      try {
        const preview = row(
          await call(
            {
              action: "admin.backup.preview",
              filename: file.name,
              archive: await fileAsBase64(file),
            },
            true,
          ),
        );
        target.innerHTML = backupPreviewMarkup(preview);
        target.querySelector("#cancel-restore").addEventListener("click", () => {
          target.innerHTML = "";
          event.currentTarget.value = "";
        });
        target.querySelector("#confirm-restore").addEventListener("click", async (restoreEvent) => {
          if (!(await confirmAction("Restore this verified recovery point? The current application configuration will be replaced.", "Restore Configuration"))) return;
          const done = setButtonBusy(restoreEvent.currentTarget, "Restoring…");
          try {
            await call(
              {
                action: "admin.backup.restore",
                uploadToken: preview.uploadToken,
                confirmed: true,
              },
              true,
            );
            notify("Configuration restore completed and passed health validation.");
            await backupRecoveryView();
          } catch (error) {
            notify(error.message, true);
            done();
          }
        });
      } catch (error) {
        target.innerHTML = '<p class="dialog-error" role="alert">Invalid Application Backup</p>';
        notify(error.message, true);
      }
    });
  }

  function currentRoute() {
    const path = location.pathname.replace(/\/$/, "");
    if (path === "" || path === "/admin" || path === "/admin/health")
      return "health";
    return path.split("/").pop();
  }
  async function render() {
    const route = currentRoute();
    content.onclick = null;
    document
      .querySelectorAll("nav a")
      .forEach((link) =>
        link.classList.toggle("active", link.dataset.route === route),
      );
    try {
      if (route === "info") await infoView();
      else if (route === "configuration") await configurationView();
      else if (route === "users") await usersView();
      else if (route === "api-keys") await apiKeysView();
      else if (route === "backup-recovery") await backupRecoveryView();
      else await healthView();
    } catch (error) {
      if (error.code === "AUTHENTICATION_REQUIRED") loginView();
      notify(error.message, true);
    }
  }
  async function enterConsole() {
    preAuth(false);
    if (location.pathname === "/" || location.pathname === "/admin")
      history.replaceState({}, "", "/admin/health");
    await render();
  }
  navigation.addEventListener("click", (event) => {
    const link = event.target.closest("a");
    if (!link) return;
    event.preventDefault();
    if (mobileNavigation.matches) closeMobileNavigation();
    history.pushState({}, "", link.href);
    render();
  });
  addEventListener("popstate", () => (currentUser ? render() : entryView()));
  logout.addEventListener("click", async (event) => {
    const done = setButtonBusy(event.currentTarget, "Signing out…");
    try {
      await call({ action: "auth.logout" }, true);
    } finally {
      done();
      csrfToken = "";
      await loadCsrf();
      loginView();
    }
  });
  sidebarVersion.textContent = versionLabel;
  (async () => {
    try {
      await loadCsrf();
      const setup = row(await call({ action: "setup.status" }));
      if (!setup.initialized) return setupView();
      const session = row(await call({ action: "auth.session" }));
      if (
        session.authenticated &&
        session.user &&
        session.user.backendRole === "system-administrator"
      ) {
        currentUser = session.user;
        await enterConsole();
      } else loginView();
    } catch (error) {
      title.textContent = "Unavailable";
      content.textContent = "The Admin API could not be reached.";
      notify(error.message, true);
    }
  })();
})();
