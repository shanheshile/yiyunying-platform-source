(() => {
  "use strict";

  const ROOT_ACCOUNT = "root";
  const IDLE_TIMEOUT_MS = 15 * 60 * 1000;
  const REQUEST_TIMEOUT_MS = 20 * 1000;
  const TYPED_ROUTES = Object.freeze([
    { pattern: /^\/api\/platform\/login$/, methods: ["POST"] },
    { pattern: /^\/api\/platform\/(?:logout|me|dashboard)$/, methods: ["GET", "POST"] },
    { pattern: /^\/api\/platform\/operators$/, methods: ["GET"] },
    { pattern: /^\/api\/platform\/operators\/[1-9]\d*\/(?:ban|unban)$/, methods: ["POST"] },
    { pattern: /^\/api\/platform\/operators\/[1-9]\d*\/permissions$/, methods: ["GET", "PUT"] },
    { pattern: /^\/api\/platform\/admins$/, methods: ["GET"] },
    { pattern: /^\/api\/platform\/admins\/[1-9]\d*\/(?:ban|unban)$/, methods: ["POST"] },
    { pattern: /^\/api\/platform\/admins\/[1-9]\d*\/permissions$/, methods: ["GET", "PUT"] },
    { pattern: /^\/api\/platform\/apps$/, methods: ["GET"] },
    { pattern: /^\/api\/platform\/apps\/[1-9]\d*$/, methods: ["PUT"] },
    { pattern: /^\/api\/platform\/apps\/[1-9]\d*\/users$/, methods: ["GET"] },
    { pattern: /^\/api\/platform\/apps\/[1-9]\d*\/users\/[1-9]\d*\/permissions$/, methods: ["GET", "PUT"] },
    { pattern: /^\/api\/platform\/(?:software-updates|maintenances)$/, methods: ["GET", "POST"] },
    { pattern: /^\/api\/platform\/(?:software-updates|maintenances)\/[1-9]\d*$/, methods: ["DELETE"] },
  ]);

  let bearerToken = "";
  let verifiedActor = null;
  let idleTimer = 0;
  let appCache = [];
  let permissionContext = null;
  let confirmation = null;

  const byId = (id) => document.getElementById(id);
  const loginView = byId("login-view");
  const consoleView = byId("console-view");
  const loginForm = byId("login-form");
  const loginButton = byId("login-button");
  const loginMessage = byId("login-message");
  const passwordInput = byId("password");
  const platformKeyInput = byId("platform-key");
  const accountInput = byId("account");
  const logoutButton = byId("logout-button");
  const sessionState = byId("session-state");
  const actorLabel = byId("actor-label");
  const globalMessage = byId("global-message");
  const permissionDialog = byId("permission-dialog");
  const permissionForm = byId("permission-form");
  const confirmDialog = byId("confirm-dialog");
  const confirmInput = byId("confirm-input");

  class ApiError extends Error {
    constructor(message, status = 0) {
      super(message);
      this.name = "ApiError";
      this.status = status;
    }
  }

  function positiveId(value, label = "ID") {
    const id = Number(value);
    if (!Number.isSafeInteger(id) || id <= 0) throw new Error(`${label} 无效`);
    return id;
  }

  function search(params) {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== "" && value !== null && value !== undefined) query.set(key, String(value));
    });
    const value = query.toString();
    return value === "" ? "" : `?${value}`;
  }

  function assertTypedRoute(path, method) {
    if (typeof path !== "string" || !path.startsWith("/api/platform/")) {
      throw new Error("控制台拒绝非平台业务接口");
    }
    const url = new URL(path, window.location.origin);
    if (url.origin !== window.location.origin || url.username || url.password || url.hash) {
      throw new Error("控制台拒绝跨源或带凭据地址");
    }
    const allowed = TYPED_ROUTES.some((route) => route.pattern.test(url.pathname) && route.methods.includes(method));
    if (!allowed) throw new Error("控制台拒绝未列入类型化白名单的接口");
    return `${url.pathname}${url.search}`;
  }

  async function transport(path, options = {}) {
    const method = String(options.method || "GET").toUpperCase();
    const typedPath = assertTypedRoute(path, method);
    const authToken = options.authToken === undefined ? bearerToken : String(options.authToken || "");
    if (options.auth !== false && authToken === "") throw new ApiError("登录会话不存在，请重新登录", 401);

    const headers = new Headers({ Accept: "application/json" });
    if (authToken !== "") headers.set("Authorization", `Bearer ${authToken}`);
    let body;
    if (options.body !== undefined) {
      headers.set("Content-Type", "application/json; charset=utf-8");
      body = JSON.stringify(options.body);
    }
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
    let response;
    try {
      response = await fetch(typedPath, {
        method,
        headers,
        body,
        cache: "no-store",
        credentials: "same-origin",
        redirect: "error",
        referrerPolicy: "no-referrer",
        signal: controller.signal,
      });
    } catch (error) {
      if (error && error.name === "AbortError") throw new ApiError("请求超时，请检查网络后重试");
      throw new ApiError("无法连接同源业务接口");
    } finally {
      window.clearTimeout(timeout);
    }

    const contentType = response.headers.get("content-type") || "";
    if (!contentType.toLowerCase().includes("application/json")) {
      throw new ApiError("服务器返回了非 JSON 响应", response.status);
    }
    let envelope;
    try {
      envelope = await response.json();
    } catch (_) {
      throw new ApiError("服务器 JSON 响应无效", response.status);
    }
    if (!response.ok || !envelope || Number(envelope.code) !== 1) {
      const message = typeof envelope?.msg === "string" && envelope.msg.trim() !== ""
        ? envelope.msg.trim().slice(0, 300)
        : `请求失败（HTTP ${response.status}）`;
      throw new ApiError(message, response.status);
    }
    return envelope.data && typeof envelope.data === "object" ? envelope.data : {};
  }

  const api = Object.freeze({
    login: (body) => transport("/api/platform/login", { method: "POST", body, auth: false }),
    meWith: (token) => transport("/api/platform/me", { authToken: token }),
    logoutWith: (token) => transport("/api/platform/logout", { method: "POST", authToken: token }),
    dashboard: () => transport("/api/platform/dashboard"),
    operators: () => transport(`/api/platform/operators${search({ page: 1, limit: 100 })}`),
    operatorStatus: (id, enable) => transport(`/api/platform/operators/${positiveId(id)}/${enable ? "unban" : "ban"}`, {
      method: "POST", body: enable ? {} : { reason: "Root 安全总控操作" },
    }),
    operatorPermissions: (id) => transport(`/api/platform/operators/${positiveId(id)}/permissions`),
    saveOperatorPermissions: (id, permissions) => transport(`/api/platform/operators/${positiveId(id)}/permissions`, { method: "PUT", body: { permissions } }),
    admins: () => transport(`/api/platform/admins${search({ page: 1, limit: 100 })}`),
    adminStatus: (id, enable) => transport(`/api/platform/admins/${positiveId(id)}/${enable ? "unban" : "ban"}`, {
      method: "POST", body: enable ? {} : { reason: "Root 安全总控操作" },
    }),
    adminPermissions: (id) => transport(`/api/platform/admins/${positiveId(id)}/permissions`),
    saveAdminPermissions: (id, permissions) => transport(`/api/platform/admins/${positiveId(id)}/permissions`, { method: "PUT", body: { permissions } }),
    apps: () => transport(`/api/platform/apps${search({ page: 1, limit: 200 })}`),
    appStatus: (id, enable) => transport(`/api/platform/apps/${positiveId(id)}`, {
      method: "PUT", body: { status: enable ? 1 : 0, reason: enable ? "" : "Root 安全总控操作" },
    }),
    users: (appId, keyword) => transport(`/api/platform/apps/${positiveId(appId, "应用 ID")}/users${search({ page: 1, limit: 100, keyword })}`),
    userPermissions: (appId, userId) => transport(`/api/platform/apps/${positiveId(appId, "应用 ID")}/users/${positiveId(userId, "用户 ID")}/permissions`),
    saveUserPermissions: (appId, userId, permissions) => transport(`/api/platform/apps/${positiveId(appId, "应用 ID")}/users/${positiveId(userId, "用户 ID")}/permissions`, { method: "PUT", body: { permissions } }),
    updates: () => transport(`/api/platform/software-updates${search({ page: 1, limit: 100 })}`),
    createUpdate: (body) => transport("/api/platform/software-updates", { method: "POST", body }),
    deleteUpdate: (id) => transport(`/api/platform/software-updates/${positiveId(id, "策略 ID")}`, { method: "DELETE" }),
    maintenances: () => transport(`/api/platform/maintenances${search({ page: 1, limit: 100 })}`),
    createMaintenance: (body) => transport("/api/platform/maintenances", { method: "POST", body }),
    deleteMaintenance: (id) => transport(`/api/platform/maintenances/${positiveId(id, "策略 ID")}`, { method: "DELETE" }),
  });

  function clearNode(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  function node(tag, text, className) {
    const element = document.createElement(tag);
    if (text !== undefined && text !== null) element.textContent = String(text);
    if (className) element.className = className;
    return element;
  }

  function rows(data) {
    return Array.isArray(data?.items) ? data.items : [];
  }

  function showGlobal(message, type = "") {
    globalMessage.textContent = String(message || "").slice(0, 500);
    globalMessage.className = `global-message${type ? ` ${type}` : ""}`;
  }

  function stateLabel(status) {
    return Number(status) === 1 ? "启用" : "停用";
  }

  function dateLabel(value) {
    if (value === null || value === undefined || String(value).trim() === "") return "—";
    return String(value).slice(0, 19);
  }

  function renderTable(container, columns, items, actions) {
    clearNode(container);
    if (!Array.isArray(items) || items.length === 0) {
      container.append(node("div", "暂无记录", "empty-state"));
      return;
    }
    const table = document.createElement("table");
    const thead = document.createElement("thead");
    const headerRow = document.createElement("tr");
    columns.forEach((column) => headerRow.append(node("th", column.label)));
    if (actions) headerRow.append(node("th", "操作"));
    thead.append(headerRow);
    table.append(thead);
    const tbody = document.createElement("tbody");
    items.forEach((item) => {
      const row = document.createElement("tr");
      columns.forEach((column) => {
        const value = column.value(item);
        const cell = node("td", value === "" || value === null || value === undefined ? "—" : value, column.className ? column.className(item) : "");
        row.append(cell);
      });
      if (actions) {
        const cell = node("td", null, "actions");
        actions(item).forEach((action) => {
          const button = node("button", action.label, `button small ${action.kind || "secondary"}`);
          button.type = "button";
          button.addEventListener("click", async () => {
            button.disabled = true;
            try { await action.run(); } finally { button.disabled = false; }
          });
          cell.append(button);
        });
        row.append(cell);
      }
      tbody.append(row);
    });
    table.append(tbody);
    container.append(table);
  }

  function resetIdleTimer() {
    if (bearerToken === "") return;
    window.clearTimeout(idleTimer);
    idleTimer = window.setTimeout(() => void logout("会话因 15 分钟无操作已安全退出"), IDLE_TIMEOUT_MS);
  }

  function setSessionUi(authenticated) {
    loginView.hidden = authenticated;
    consoleView.hidden = !authenticated;
    logoutButton.hidden = !authenticated;
    sessionState.textContent = authenticated ? "Root 已验证" : "未登录";
    sessionState.className = `status-pill ${authenticated ? "online" : "offline"}`;
    actorLabel.textContent = authenticated && verifiedActor
      ? `${verifiedActor.nickname || verifiedActor.account || ROOT_ACCOUNT} · Level ${verifiedActor.level}`
      : "";
  }

  function forgetSession(message = "") {
    bearerToken = "";
    verifiedActor = null;
    permissionContext = null;
    appCache = [];
    window.clearTimeout(idleTimer);
    passwordInput.value = "";
    confirmInput.value = "";
    setSessionUi(false);
    if (permissionDialog.open) permissionDialog.close();
    if (confirmDialog.open) finishConfirmation(false);
    if (message) loginMessage.textContent = message;
  }

  async function logout(message = "已安全退出；服务端令牌已撤销") {
    const current = bearerToken;
    forgetSession(message);
    if (current !== "") {
      try { await api.logoutWith(current); } catch (_) { /* Local memory is already cleared. */ }
    }
  }

  function handleError(error) {
    const message = error instanceof Error ? error.message : "操作失败";
    if (error instanceof ApiError && error.status === 401) {
      forgetSession("登录已过期，请重新登录");
      return;
    }
    showGlobal(message, "error");
  }

  async function login(event) {
    event.preventDefault();
    if (window.location.protocol !== "https:" && !["localhost", "127.0.0.1", "::1"].includes(window.location.hostname)) {
      loginMessage.textContent = "正式总控只允许 HTTPS";
      return;
    }
    const platformKey = platformKeyInput.value.trim();
    const account = accountInput.value.trim();
    let password = passwordInput.value;
    passwordInput.value = "";
    loginMessage.textContent = "";
    if (account !== ROOT_ACCOUNT) {
      password = "";
      loginMessage.textContent = "本入口只允许 root 总控账号";
      return;
    }
    loginButton.disabled = true;
    let candidateToken = "";
    try {
      const result = await api.login({ platform_key: platformKey, account, password, device: "root-web-control" });
      candidateToken = typeof result.access_token === "string" ? result.access_token : "";
      password = "";
      if (candidateToken === "") throw new ApiError("登录响应未包含有效访问令牌");
      const me = await api.meWith(candidateToken);
      const platform = me.platform && typeof me.platform === "object" ? me.platform : {};
      if (Number(platform.level) !== 1 || String(platform.account || "") !== ROOT_ACCOUNT) {
        try { await api.logoutWith(candidateToken); } catch (_) { /* Reject locally even if revoke fails. */ }
        candidateToken = "";
        throw new ApiError("身份回读不是一级 root，总控访问已拒绝", 403);
      }
      bearerToken = candidateToken;
      candidateToken = "";
      verifiedActor = platform;
      setSessionUi(true);
      resetIdleTimer();
      showGlobal("Root 身份已通过服务端回读验证", "success");
      await switchPanel("dashboard");
    } catch (error) {
      password = "";
      if (candidateToken !== "") {
        try { await api.logoutWith(candidateToken); } catch (_) { /* Never retain an unverified token. */ }
      }
      loginMessage.textContent = error instanceof Error ? error.message : "登录失败";
      forgetSession(loginMessage.textContent);
    } finally {
      password = "";
      loginButton.disabled = false;
    }
  }

  async function loadDashboard() {
    const data = await api.dashboard();
    const summary = data.summary || {};
    const finance = data.finance || {};
    const metrics = [
      ["授权平台", summary.operators], ["管理员", summary.admins], ["应用", summary.apps],
      ["用户", summary.users], ["近 7 日活跃用户", summary.active_users_7d], ["文档", summary.documents],
      ["今日管理员登录", summary.today_admin_logins], ["已支付订单", finance.paid_orders],
    ];
    const container = byId("dashboard-cards");
    clearNode(container);
    metrics.forEach(([label, value]) => {
      const card = node("article", null, "metric-card");
      card.append(node("span", label), node("strong", Number(value || 0).toLocaleString("zh-CN")));
      container.append(card);
    });
  }

  async function loadOperators() {
    const data = await api.operators();
    renderTable(byId("operators-table"), [
      { label: "ID", value: (item) => item.id },
      { label: "账号", value: (item) => item.account },
      { label: "名称", value: (item) => item.nickname },
      { label: "状态", value: (item) => stateLabel(item.status), className: (item) => Number(item.status) === 1 ? "state-active" : "state-disabled" },
      { label: "授权到期", value: (item) => dateLabel(item.membership_expired_at) },
      { label: "管理员数", value: (item) => item.admin_count ?? item.counts?.admins ?? "—" },
    ], rows(data), (item) => [
      { label: "权限", run: () => openPermissions("operator", item.id) },
      { label: Number(item.status) === 1 ? "停用" : "启用", kind: Number(item.status) === 1 ? "danger" : "secondary", run: () => changeOperatorStatus(item) },
    ]);
  }

  async function changeOperatorStatus(item) {
    const enable = Number(item.status) !== 1;
    const label = `${enable ? "启用" : "停用"}授权平台 #${item.id}`;
    if (!await confirmDanger(`${label}。停用时服务端会撤销该平台及下游会话。`, label)) return;
    await api.operatorStatus(item.id, enable);
    showGlobal(`${label}成功`, "success");
    await loadOperators();
  }

  async function loadAdmins() {
    const data = await api.admins();
    renderTable(byId("admins-table"), [
      { label: "ID", value: (item) => item.id },
      { label: "账号", value: (item) => item.account },
      { label: "名称", value: (item) => item.nickname },
      { label: "所属平台", value: (item) => item.platform_nickname || item.platform_account || item.platform_id },
      { label: "状态", value: (item) => stateLabel(item.status), className: (item) => Number(item.status) === 1 ? "state-active" : "state-disabled" },
      { label: "会员状态", value: (item) => item.membership_status },
    ], rows(data), (item) => [
      { label: "权限", run: () => openPermissions("admin", item.id) },
      { label: Number(item.status) === 1 ? "停用" : "启用", kind: Number(item.status) === 1 ? "danger" : "secondary", run: () => changeAdminStatus(item) },
    ]);
  }

  async function changeAdminStatus(item) {
    const enable = Number(item.status) !== 1;
    const label = `${enable ? "启用" : "停用"}管理员 #${item.id}`;
    if (!await confirmDanger(`${label}。停用时下游用户会话会被撤销。`, label)) return;
    await api.adminStatus(item.id, enable);
    showGlobal(`${label}成功`, "success");
    await loadAdmins();
  }

  async function fetchApps() {
    const data = await api.apps();
    appCache = rows(data);
    refreshAppSelect();
    return appCache;
  }

  function refreshAppSelect() {
    const select = byId("user-app-select");
    const selected = select.value;
    clearNode(select);
    const placeholder = node("option", "请选择应用");
    placeholder.value = "";
    select.append(placeholder);
    appCache.forEach((app) => {
      const option = node("option", `${app.name || "应用"} · #${app.id} · ${app.admin_account || ""}`);
      option.value = String(app.id);
      select.append(option);
    });
    if (appCache.some((app) => String(app.id) === selected)) select.value = selected;
  }

  async function loadApps() {
    const items = await fetchApps();
    renderTable(byId("apps-table"), [
      { label: "ID", value: (item) => item.id },
      { label: "应用名称", value: (item) => item.name },
      { label: "管理员", value: (item) => item.admin_account },
      { label: "状态", value: (item) => stateLabel(item.status), className: (item) => Number(item.status) === 1 ? "state-active" : "state-disabled" },
      { label: "版本", value: (item) => item.version },
      { label: "用户数", value: (item) => item.user_count },
    ], items, (item) => [
      { label: Number(item.status) === 1 ? "停用" : "启用", kind: Number(item.status) === 1 ? "danger" : "secondary", run: () => changeAppStatus(item) },
    ]);
  }

  async function changeAppStatus(item) {
    const enable = Number(item.status) !== 1;
    const label = `${enable ? "启用" : "停用"}应用 #${item.id}`;
    if (!await confirmDanger(`${label}。停用应用将阻止客户端业务访问并撤销应用用户会话。`, label)) return;
    await api.appStatus(item.id, enable);
    showGlobal(`${label}成功`, "success");
    await loadApps();
  }

  async function loadUsers() {
    if (appCache.length === 0) await fetchApps();
    const appId = byId("user-app-select").value;
    const keyword = byId("user-keyword").value.trim();
    const container = byId("users-table");
    if (appId === "") {
      clearNode(container);
      container.append(node("div", "请先选择一个应用", "empty-state"));
      return;
    }
    const data = await api.users(appId, keyword);
    renderTable(container, [
      { label: "ID", value: (item) => item.id },
      { label: "UID", value: (item) => item.uid },
      { label: "账号", value: (item) => item.account },
      { label: "昵称", value: (item) => item.nickname || item.profile_nickname },
      { label: "状态", value: (item) => stateLabel(item.status), className: (item) => Number(item.status) === 1 ? "state-active" : "state-disabled" },
      { label: "最近登录", value: (item) => dateLabel(item.last_login_at) },
    ], rows(data), (item) => [
      { label: "功能权限", run: () => openPermissions("user", item.id, appId) },
    ]);
  }

  async function openPermissions(type, id, appId = null) {
    let payload;
    if (type === "operator") payload = await api.operatorPermissions(id);
    else if (type === "admin") payload = await api.adminPermissions(id);
    else payload = await api.userPermissions(appId, id);
    permissionContext = { type, id: positiveId(id), appId: appId === null ? null : positiveId(appId), payload };
    byId("permission-title").textContent = `${payload.target?.name || payload.target?.account || "对象"}权限`;
    const summary = payload.summary || {};
    byId("permission-summary").textContent = `共 ${summary.total || 0} 项；生效 ${summary.enabled || 0} 项；关闭 ${summary.disabled || 0} 项；锁定 ${summary.locked || 0} 项。`;
    clearNode(permissionForm);
    (Array.isArray(payload.groups) ? payload.groups : []).forEach((group) => {
      const fieldset = node("section", null, "permission-group");
      fieldset.append(node("h3", group.title || "权限组"));
      (Array.isArray(group.items) ? group.items : []).forEach((item) => {
        const label = node("label", null, "permission-row");
        const checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.checked = Boolean(item.configured_enabled);
        checkbox.disabled = !Boolean(item.editable);
        checkbox.dataset.permissionCode = String(item.code || "");
        const copy = node("span");
        copy.append(node("strong", `${item.title || item.code} · ${item.effective_enabled ? "当前生效" : "当前关闭"}`));
        copy.append(node("span", `${item.description || ""}${item.lock_reason ? `；${item.lock_reason}` : ""}`));
        label.append(checkbox, copy);
        fieldset.append(label);
      });
      permissionForm.append(fieldset);
    });
    permissionDialog.showModal();
  }

  async function savePermissions() {
    if (!permissionContext) return;
    const inputs = permissionForm.querySelectorAll("input[data-permission-code]");
    const permissions = {};
    inputs.forEach((input) => {
      const code = input.dataset.permissionCode || "";
      if (code === "") return;
      const previous = permissionContext.payload.permissions?.[code];
      const value = { allowed: input.checked };
      if (previous && previous.config && typeof previous.config === "object") value.config = previous.config;
      permissions[code] = value;
    });
    const target = permissionContext.payload.target || {};
    const phrase = `保存权限 ${target.account || `#${permissionContext.id}`}`;
    if (!await confirmDanger("保存后会立即改变该对象的功能授权；上级强制规则仍优先生效。", phrase)) return;
    if (permissionContext.type === "operator") await api.saveOperatorPermissions(permissionContext.id, permissions);
    else if (permissionContext.type === "admin") await api.saveAdminPermissions(permissionContext.id, permissions);
    else await api.saveUserPermissions(permissionContext.appId, permissionContext.id, permissions);
    permissionDialog.close();
    permissionContext = null;
    showGlobal("权限已通过类型化业务接口保存", "success");
  }

  async function loadLifecycle() {
    const [updateData, maintenanceData] = await Promise.all([api.updates(), api.maintenances()]);
    renderTable(byId("updates-table"), [
      { label: "ID", value: (item) => item.id },
      { label: "客户端", value: (item) => item.edition_code },
      { label: "版本", value: (item) => `${item.version_name || ""} (${item.version_code || 0})` },
      { label: "强制", value: (item) => Number(item.force_update) === 1 ? "是" : "否" },
    ], rows(updateData), (item) => [
      { label: "删除", kind: "danger", run: () => deleteLifecycle("update", item.id) },
    ]);
    renderTable(byId("maintenances-table"), [
      { label: "ID", value: (item) => item.id },
      { label: "客户端", value: (item) => item.edition_code },
      { label: "标题", value: (item) => item.title },
      { label: "开始", value: (item) => dateLabel(item.starts_at) },
      { label: "结束", value: (item) => dateLabel(item.ends_at) },
    ], rows(maintenanceData), (item) => [
      { label: "删除", kind: "danger", run: () => deleteLifecycle("maintenance", item.id) },
    ]);
  }

  async function deleteLifecycle(kind, id) {
    const label = `${kind === "update" ? "删除版本策略" : "删除维护策略"} #${id}`;
    if (!await confirmDanger(`${label}。删除后该策略不再参与客户端生命周期判定。`, label)) return;
    if (kind === "update") await api.deleteUpdate(id); else await api.deleteMaintenance(id);
    showGlobal(`${label}成功`, "success");
    await loadLifecycle();
  }

  function formObject(form) {
    return Object.fromEntries(new FormData(form).entries());
  }

  async function createUpdate(event) {
    event.preventDefault();
    const data = formObject(event.currentTarget);
    const versionCode = Number(data.version_code);
    const minCode = Number(data.min_supported_version_code);
    const sizeBytes = Number(data.size_bytes);
    const hash = String(data.sha256 || "").toLowerCase().trim();
    const packageName = String(data.package_name || "").trim();
    const download = new URL(String(data.download_url || ""), window.location.origin);
    if (download.protocol !== "https:" || download.hostname !== "appht.jjmxg.xyz" || !download.pathname.startsWith("/downloads/") || download.username || download.password || download.search || download.hash) {
      throw new Error("正式下载地址必须是主域名 HTTPS 的 /downloads/ 不可变地址，且不能包含凭据、查询参数或片段");
    }
    if (!Number.isSafeInteger(versionCode) || versionCode < 1 || !Number.isSafeInteger(minCode) || minCode < 0 || minCode > versionCode) throw new Error("版本代码或最低支持代码无效");
    if (!Number.isSafeInteger(sizeBytes) || sizeBytes < 1) throw new Error("文件字节数无效");
    if (!/^[a-f0-9]{64}$/.test(hash)) throw new Error("SHA-256 必须是 64 位小写十六进制摘要");
    if (!/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/.test(packageName)) throw new Error("Android 包名格式无效");
    const body = {
      edition_code: String(data.edition_code), target_type: "global", version_name: String(data.version_name).trim(),
      version_code: versionCode, min_supported_version_code: minCode, download_url: download.toString(),
      package_name: packageName, sha256: hash, size_bytes: sizeBytes,
      release_notes: String(data.release_notes || ""), force_update: data.force_update === "on", priority: 0,
    };
    const phrase = `发布版本 ${body.version_name}`;
    if (!await confirmDanger("该策略会进入客户端版本判定。APK 身份、签名、大小和摘要必须已独立验证。", phrase)) return;
    await api.createUpdate(body);
    showGlobal(`版本策略 ${body.version_name} 已发布`, "success");
    await loadLifecycle();
  }

  async function createMaintenance(event) {
    event.preventDefault();
    const data = formObject(event.currentTarget);
    const startsAt = String(data.starts_at || "");
    const endsAt = String(data.ends_at || "");
    if (startsAt && endsAt && new Date(endsAt).getTime() <= new Date(startsAt).getTime()) throw new Error("维护结束时间必须晚于开始时间");
    const allowlist = String(data.allowlist || "").split(",").map((value) => value.trim()).filter(Boolean);
    if (allowlist.some((value) => value.length > 64 || !/^[0-9a-fA-F:.]+$/.test(value))) throw new Error("允许 IP 列表格式无效");
    const body = {
      edition_code: String(data.edition_code), target_type: "global", title: String(data.title).trim(),
      message: String(data.message).trim(), starts_at: startsAt || null, ends_at: endsAt || null,
      forced: data.forced === "on", allowlist, priority: 0,
    };
    const phrase = "创建维护窗口";
    if (!await confirmDanger("强制维护会阻断目标客户端的一般写操作，请确认时间和放行 IP。", phrase)) return;
    await api.createMaintenance(body);
    showGlobal("维护策略已创建", "success");
    await loadLifecycle();
  }

  const loaders = Object.freeze({
    dashboard: loadDashboard,
    operators: loadOperators,
    admins: loadAdmins,
    apps: loadApps,
    users: async () => { if (appCache.length === 0) await fetchApps(); await loadUsers(); },
    lifecycle: loadLifecycle,
    documents: async () => {},
  });

  async function switchPanel(name) {
    if (bearerToken === "" || !Object.prototype.hasOwnProperty.call(loaders, name)) return;
    document.querySelectorAll("[data-panel-body]").forEach((panel) => { panel.hidden = panel.dataset.panelBody !== name; });
    document.querySelectorAll(".nav-item[data-panel]").forEach((button) => { button.classList.toggle("active", button.dataset.panel === name); });
    showGlobal("正在读取最新业务状态…");
    try {
      await loaders[name]();
      showGlobal(`已更新：${new Date().toLocaleTimeString("zh-CN", { hour12: false })}`, "success");
      resetIdleTimer();
    } catch (error) {
      handleError(error);
    }
  }

  function confirmDanger(description, phrase) {
    if (confirmation) return Promise.resolve(false);
    byId("confirm-description").textContent = description;
    byId("confirm-phrase").textContent = phrase;
    byId("confirm-error").textContent = "";
    confirmInput.value = "";
    confirmDialog.showModal();
    window.setTimeout(() => confirmInput.focus(), 0);
    return new Promise((resolve) => { confirmation = { phrase, resolve }; });
  }

  function finishConfirmation(result) {
    const current = confirmation;
    confirmation = null;
    confirmInput.value = "";
    if (confirmDialog.open) confirmDialog.close();
    if (current) current.resolve(Boolean(result));
  }

  loginForm.addEventListener("submit", (event) => void login(event));
  logoutButton.addEventListener("click", () => void logout());
  document.querySelectorAll(".nav-item[data-panel]").forEach((button) => button.addEventListener("click", () => void switchPanel(button.dataset.panel)));
  document.querySelectorAll("[data-refresh]").forEach((button) => button.addEventListener("click", () => void switchPanel(button.dataset.refresh)));
  byId("load-users").addEventListener("click", () => void switchPanel("users"));
  byId("user-app-select").addEventListener("change", () => void loadUsers().catch(handleError));
  byId("permission-save").addEventListener("click", () => void savePermissions().catch(handleError));
  byId("permission-cancel").addEventListener("click", () => { permissionContext = null; permissionDialog.close(); });
  permissionDialog.addEventListener("cancel", () => { permissionContext = null; });
  byId("confirm-cancel").addEventListener("click", () => finishConfirmation(false));
  byId("confirm-submit").addEventListener("click", () => {
    if (!confirmation || confirmInput.value !== confirmation.phrase) {
      byId("confirm-error").textContent = "确认短语不匹配";
      return;
    }
    finishConfirmation(true);
  });
  confirmDialog.addEventListener("cancel", (event) => { event.preventDefault(); finishConfirmation(false); });
  confirmDialog.addEventListener("close", () => { if (confirmation) finishConfirmation(false); });
  byId("update-form").addEventListener("submit", (event) => void createUpdate(event).catch(handleError));
  byId("maintenance-form").addEventListener("submit", (event) => void createMaintenance(event).catch(handleError));

  ["pointerdown", "keydown"].forEach((eventName) => document.addEventListener(eventName, resetIdleTimer, { passive: true }));
  window.addEventListener("pagehide", () => {
    bearerToken = "";
    verifiedActor = null;
    passwordInput.value = "";
  });

  if (window.location.protocol !== "https:" && !["localhost", "127.0.0.1", "::1"].includes(window.location.hostname)) {
    byId("transport-warning").hidden = false;
    loginButton.disabled = true;
  }
  setSessionUi(false);
})();
