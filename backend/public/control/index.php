<?php

declare(strict_types=1);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Cache-Control: no-store, max-age=0');
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
$host = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
$local = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
if (!$https && !$local) {
    header('Location: https://appht.jjmxg.xyz/control/', true, 308);
    exit;
}

header("Content-Security-Policy: default-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; connect-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self'; upgrade-insecure-requests");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; base-uri 'none'; object-src 'none'; form-action 'self'; connect-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self'; upgrade-insecure-requests">
  <title>易运盈安全总控</title>
  <link rel="stylesheet" href="/control/control.css">
  <script src="/control/control.js" defer></script>
</head>
<body>
  <noscript><div class="fatal">此安全总控需要 JavaScript；页面不会在无脚本模式提交密码。</div></noscript>
  <header class="topbar">
    <div>
      <p class="eyebrow">YIYUNYING CONTROL</p>
      <h1>易运盈安全总控</h1>
    </div>
    <div class="session-strip">
      <span id="session-state" class="status-pill offline">未登录</span>
      <span id="actor-label" class="actor-label"></span>
      <button id="logout-button" class="button ghost" type="button" hidden>安全退出</button>
    </div>
  </header>

  <main>
    <section id="transport-warning" class="fatal" hidden>
      正式总控只允许通过 HTTPS 使用。当前连接已被客户端阻断。
    </section>

    <section id="login-view" class="login-card" aria-labelledby="login-title">
      <div class="login-copy">
        <p class="eyebrow">ROOT ONLY</p>
        <h2 id="login-title">一级平台所有者登录</h2>
        <p>凭据只发送到同源登录接口。密码提交后立即清空；访问令牌仅保存在当前页面内存，刷新或关闭页面即丢失。</p>
        <ul class="security-list">
          <li>登录后必须通过 <code>/api/platform/me</code> 回读一级 Root 身份。</li>
          <li>只开放类型化业务接口，不提供数据库总控、源码、Git、环境变量或命令执行。</li>
          <li>所有写操作都有明确对象和二次确认；永久级操作不在本页面开放。</li>
        </ul>
      </div>
      <form id="login-form" class="login-form" action="/control/" method="post" autocomplete="on">
        <label>平台唯一标识
          <input id="platform-key" name="platform_key" type="text" required minlength="3" maxlength="80" autocomplete="organization" spellcheck="false" placeholder="请输入平台唯一标识">
        </label>
        <label>总控账号
          <input id="account" name="account" type="text" required minlength="3" maxlength="64" autocomplete="username" spellcheck="false" value="root">
        </label>
        <label>密码
          <input id="password" name="password" type="password" required minlength="12" maxlength="72" autocomplete="current-password">
        </label>
        <button id="login-button" class="button primary" type="submit">登录并验证 Root 身份</button>
        <p id="login-message" class="form-message" role="alert" aria-live="polite"></p>
      </form>
    </section>

    <section id="console-view" class="console" hidden>
      <aside class="sidebar" aria-label="总控模块">
        <button class="nav-item active" type="button" data-panel="dashboard">总览</button>
        <button class="nav-item" type="button" data-panel="operators">授权平台</button>
        <button class="nav-item" type="button" data-panel="admins">管理员</button>
        <button class="nav-item" type="button" data-panel="apps">应用</button>
        <button class="nav-item" type="button" data-panel="users">用户与权限</button>
        <button class="nav-item" type="button" data-panel="lifecycle">版本与维护</button>
        <button class="nav-item" type="button" data-panel="documents">文档入口</button>
      </aside>

      <div class="workspace">
        <div id="global-message" class="global-message" role="status" aria-live="polite"></div>

        <section id="panel-dashboard" class="panel" data-panel-body="dashboard">
          <div class="panel-head"><div><p class="eyebrow">OVERVIEW</p><h2>业务总览</h2></div><button class="button secondary" type="button" data-refresh="dashboard">刷新</button></div>
          <div id="dashboard-cards" class="metric-grid" aria-live="polite"></div>
        </section>

        <section id="panel-operators" class="panel" data-panel-body="operators" hidden>
          <div class="panel-head"><div><p class="eyebrow">LEVEL 2</p><h2>授权平台</h2></div><button class="button secondary" type="button" data-refresh="operators">刷新</button></div>
          <p class="panel-note">可查看、停用/启用授权平台并维护类型化权限。停用会撤销该平台及下游会话。</p>
          <div id="operators-table" class="table-wrap"></div>
        </section>

        <section id="panel-admins" class="panel" data-panel-body="admins" hidden>
          <div class="panel-head"><div><p class="eyebrow">LEVEL 3</p><h2>管理员</h2></div><button class="button secondary" type="button" data-refresh="admins">刷新</button></div>
          <p class="panel-note">可查看、停用/启用管理员并维护管理员业务权限。本页不提供代入登录或永久删除。</p>
          <div id="admins-table" class="table-wrap"></div>
        </section>

        <section id="panel-apps" class="panel" data-panel-body="apps" hidden>
          <div class="panel-head"><div><p class="eyebrow">APPLICATIONS</p><h2>应用</h2></div><button class="button secondary" type="button" data-refresh="apps">刷新</button></div>
          <p class="panel-note">可查看应用并切换启用状态；应用密钥、服务端哈希和安全表不会显示。</p>
          <div id="apps-table" class="table-wrap"></div>
        </section>

        <section id="panel-users" class="panel" data-panel-body="users" hidden>
          <div class="panel-head"><div><p class="eyebrow">LEVEL 4</p><h2>用户与权限</h2></div><button class="button secondary" type="button" data-refresh="users">刷新</button></div>
          <div class="filters">
            <label>选择应用<select id="user-app-select"><option value="">请先选择应用</option></select></label>
            <label>关键词<input id="user-keyword" type="search" maxlength="64" placeholder="账号、UID 或昵称"></label>
            <button id="load-users" class="button secondary" type="button">查询用户</button>
          </div>
          <p class="panel-note">本页按应用读取用户，并仅通过用户权限接口修改功能开关；不开放用户密码、Token 或原始数据表。</p>
          <div id="users-table" class="table-wrap"></div>
        </section>

        <section id="panel-lifecycle" class="panel" data-panel-body="lifecycle" hidden>
          <div class="panel-head"><div><p class="eyebrow">RELEASE & MAINTENANCE</p><h2>版本与维护</h2></div><button class="button secondary" type="button" data-refresh="lifecycle">刷新</button></div>
          <div class="split-grid">
            <article class="subpanel">
              <h3>版本策略</h3>
              <div id="updates-table" class="table-wrap compact"></div>
              <details>
                <summary>发布类型化版本策略</summary>
                <form id="update-form" class="typed-form">
                  <label>版本名称<input name="version_name" required maxlength="40" placeholder="1.0.0"></label>
                  <label>版本代码<input name="version_code" required type="number" min="1" step="1"></label>
                  <label>最低支持代码<input name="min_supported_version_code" required type="number" min="0" step="1" value="1"></label>
                  <label>客户端<select name="edition_code"><option value="user">用户端</option><option value="admin">管理员端</option><option value="authorized_platform">授权端</option><option value="platform_owner">总控端</option></select></label>
                  <label class="wide">HTTPS 下载地址<input name="download_url" required type="url" maxlength="1000" placeholder="https://appht.jjmxg.xyz/downloads/..."></label>
                  <label class="wide">Android 包名<input name="package_name" required maxlength="190" placeholder="xyz.example.app"></label>
                  <label class="wide">SHA-256<input name="sha256" required minlength="64" maxlength="64" spellcheck="false"></label>
                  <label>文件字节数<input name="size_bytes" required type="number" min="1" step="1"></label>
                  <label class="check"><input name="force_update" type="checkbox"> 强制更新</label>
                  <label class="wide">更新说明<textarea name="release_notes" maxlength="4000"></textarea></label>
                  <button class="button primary" type="submit">校验并发布</button>
                </form>
              </details>
            </article>
            <article class="subpanel">
              <h3>维护策略</h3>
              <div id="maintenances-table" class="table-wrap compact"></div>
              <details>
                <summary>创建类型化维护策略</summary>
                <form id="maintenance-form" class="typed-form">
                  <label>适用客户端<select name="edition_code"><option value="all">全部</option><option value="user">用户端</option><option value="admin">管理员端</option><option value="authorized_platform">授权端</option><option value="platform_owner">总控端</option></select></label>
                  <label>标题<input name="title" required maxlength="200" value="系统维护"></label>
                  <label class="wide">说明<textarea name="message" required maxlength="4000">系统维护中，请稍后再试</textarea></label>
                  <label>开始时间<input name="starts_at" type="datetime-local"></label>
                  <label>结束时间<input name="ends_at" type="datetime-local"></label>
                  <label class="check"><input name="forced" type="checkbox" checked> 强制维护</label>
                  <label class="wide">允许访问的 IP（逗号分隔）<input name="allowlist" maxlength="1000" placeholder="留空表示不放行"></label>
                  <button class="button danger" type="submit">确认创建维护窗口</button>
                </form>
              </details>
            </article>
          </div>
        </section>

        <section id="panel-documents" class="panel" data-panel-body="documents" hidden>
          <div class="panel-head"><div><p class="eyebrow">DOCUMENTATION</p><h2>文档与发布入口</h2></div></div>
          <div class="link-grid">
            <a class="link-card" href="/download-center/" target="_blank" rel="noopener noreferrer"><strong>客户下载官网</strong><span>查看正式发布状态与客户下载入口</span></a>
            <a class="link-card" href="/download-center/api-docs/" target="_blank" rel="noopener noreferrer"><strong>完整接口文档</strong><span>系统、接口、条件、联动和示例</span></a>
            <a class="link-card" href="/api-docs.html" target="_blank" rel="noopener noreferrer"><strong>后端路由参考</strong><span>同源后端生成的接口目录</span></a>
          </div>
          <p class="panel-note">文档入口为只读链接。本控制台不提供源码浏览、源码编辑、Git、环境变量、服务器文件或命令执行能力。</p>
        </section>
      </div>
    </section>
  </main>

  <dialog id="permission-dialog" class="modal">
    <form method="dialog" class="modal-head"><div><p class="eyebrow">TYPED PERMISSIONS</p><h2 id="permission-title">权限</h2></div><button class="icon-button" value="cancel" aria-label="关闭">×</button></form>
    <div id="permission-summary" class="panel-note"></div>
    <form id="permission-form" class="permission-form"></form>
    <div class="modal-actions"><button id="permission-cancel" class="button ghost" type="button">取消</button><button id="permission-save" class="button primary" type="button">保存权限</button></div>
  </dialog>

  <dialog id="confirm-dialog" class="modal confirm-modal">
    <form method="dialog" class="modal-head"><div><p class="eyebrow">SECOND CONFIRMATION</p><h2>危险操作确认</h2></div><button class="icon-button" value="cancel" aria-label="关闭">×</button></form>
    <p id="confirm-description"></p>
    <label>请输入确认短语 <strong id="confirm-phrase"></strong>
      <input id="confirm-input" type="text" maxlength="80" autocomplete="off" spellcheck="false">
    </label>
    <p id="confirm-error" class="form-message" role="alert"></p>
    <div class="modal-actions"><button id="confirm-cancel" class="button ghost" type="button">取消</button><button id="confirm-submit" class="button danger" type="button">确认执行</button></div>
  </dialog>
</body>
</html>
