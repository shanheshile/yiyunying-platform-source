(() => {
  'use strict';
  const token = new URLSearchParams(location.search).get('token') || '';
  const initialView = document.body.dataset.initialView || 'active';
  const state = { data: null, view: initialView, search: '', sort: 'platform', asc: true, reveal: false, selected: new Set() };
  const $ = (id) => document.getElementById(id);

  async function request(path, options = {}) {
    const headers = Object.assign({ 'X-Local-Credential-Token': token }, options.headers || {});
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, Object.assign({}, options, { headers, cache: 'no-store' }));
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
    return data;
  }

  function toast(message, error = false) {
    const element = $('toast');
    element.textContent = message;
    element.className = error ? 'show error' : 'show';
    setTimeout(() => { element.className = ''; }, 2800);
  }

  function cell(row) {
    const element = document.createElement('td');
    row.appendChild(element);
    return element;
  }

  function inputField(parent, field, value, type = 'text') {
    const input = document.createElement('input');
    input.type = type;
    input.dataset.field = field;
    input.value = String(value ?? '');
    parent.appendChild(input);
    return input;
  }

  function button(parent, className, label) {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = className;
    element.textContent = label;
    parent.appendChild(element);
    return element;
  }

  function selectField(parent, field, value, options) {
    const select = document.createElement('select');
    select.dataset.field = field;
    options.forEach(([optionValue, label]) => {
      const option = document.createElement('option');
      option.value = optionValue;
      option.textContent = label;
      option.selected = optionValue === value;
      select.appendChild(option);
    });
    parent.appendChild(select);
    return select;
  }

  function matchesView(account) {
    if (state.view === 'trash') return account.deleted;
    if (account.deleted) return false;
    if (state.view === 'disabled') return account.status !== 'active';
    if (account.status !== 'active') return false;
    if (state.view === 'test') return account.environment === 'test';
    if (state.view === 'production') return account.environment === 'production';
    if (state.view === 'unknown') return account.environment === 'unknown';
    return true;
  }

  function visibleAccounts() {
    let rows = state.data.accounts.filter(matchesView);
    if (state.search) {
      const needle = state.search.toLocaleLowerCase();
      rows = rows.filter((item) => Object.values(item).some((value) => String(value ?? '').toLocaleLowerCase().includes(needle)));
    }
    const key = state.sort;
    rows.sort((a, b) => String(a[key] ?? '').localeCompare(String(b[key] ?? ''), 'zh-CN', { numeric: true, sensitivity: 'base' }) * (state.asc ? 1 : -1));
    return rows;
  }

  function setView(view) {
    state.view = view;
    document.querySelectorAll('[data-view]').forEach((button) => button.classList.toggle('active', button.dataset.view === view));
    render();
  }

  function render() {
    if (!state.data) return;
    const counts = { active: 0, test: 0, production: 0, unknown: 0, disabled: 0, trash: 0 };
    state.data.accounts.forEach((item) => {
      if (item.deleted) counts.trash++;
      else if (item.status !== 'active') counts.disabled++;
      else {
        counts.active++;
        counts[item.environment]++;
      }
    });
    document.querySelectorAll('[data-count]').forEach((item) => { item.textContent = counts[item.dataset.count] ?? 0; });
    const rows = visibleAccounts();
    $('resultCount').textContent = `${rows.length} 条`;
    const tbody = $('tbody');
    tbody.replaceChildren();
    const fragment = document.createDocumentFragment();
    rows.forEach((item) => {
      const secretType = state.reveal ? 'text' : 'password';
      const row = document.createElement('tr');
      row.dataset.id = item.recordId;
      const checkbox = inputField(cell(row), '', '', 'checkbox');
      delete checkbox.dataset.field;
      checkbox.className = 'select';
      checkbox.checked = state.selected.has(item.recordId);
      inputField(cell(row), 'platform', item.platform);
      inputField(cell(row), 'software', item.software);
      inputField(cell(row), 'role', item.role);
      let target = cell(row);
      inputField(target, 'loginAccount', item.loginAccount);
      let copy = button(target, 'mini copy', '复制'); copy.dataset.copy = 'loginAccount';
      target = cell(row);
      inputField(target, 'password', item.password, secretType);
      copy = button(target, 'mini copy', '复制'); copy.dataset.copy = 'password';
      inputField(cell(row), 'appId', item.appId);
      target = cell(row);
      inputField(target, 'appSecret', item.appSecret, secretType);
      copy = button(target, 'mini copy', '复制'); copy.dataset.copy = 'appSecret';
      selectField(cell(row), 'environment', item.environment, [['unknown', '待确认'], ['test', '测试'], ['production', '生产']]);
      selectField(cell(row), 'status', item.status, [['active', '启用'], ['disabled', '停用'], ['inactive', '未激活']]);
      inputField(cell(row), 'notes', item.notes);
      target = cell(row);
      button(target, 'mini row-delete', item.deleted ? '永久删除' : '移到回收站');
      if (item.deleted) button(target, 'mini row-undo', '撤销');
      fragment.appendChild(row);
    });
    tbody.appendChild(fragment);
    $('empty').hidden = rows.length !== 0;
  }

  function accountForRow(row) {
    return state.data.accounts.find((item) => item.recordId === row.dataset.id);
  }

  async function save(message = '已保存') {
    try {
      state.data = await request('/api/state', { method: 'POST', body: JSON.stringify({ expectedRevision: state.data.revision, accounts: state.data.accounts }) });
      toast(message);
      render();
      return true;
    } catch (error) {
      toast(error.message, true);
      return false;
    }
  }

  async function copyText(value) {
    try {
      await navigator.clipboard.writeText(value || '');
      toast('已复制到剪贴板');
    } catch (_) {
      const area = document.createElement('textarea');
      area.value = value || ''; document.body.appendChild(area); area.select();
      const copied = document.execCommand('copy');
      area.remove();
      if (!copied) { toast('浏览器拒绝复制，请手动选择后复制', true); return; }
      toast('已复制到剪贴板');
    }
  }

  async function load() {
    try {
      state.data = await request('/api/state');
      if (token) history.replaceState(null, '', location.pathname);
      render();
    } catch (error) { toast(error.message, true); }
  }

  document.addEventListener('click', (event) => {
    const view = event.target.closest('[data-view]');
    if (view) { setView(view.dataset.view); return; }
    const row = event.target.closest('tr[data-id]');
    if (event.target.classList.contains('copy') && row) { copyText(accountForRow(row)[event.target.dataset.copy] || ''); return; }
    if (event.target.classList.contains('row-delete') && row) {
      const account = accountForRow(row);
      if (account.deleted) {
        if (!confirm('永久删除后只能从备份恢复，确认继续？')) return;
        state.data.accounts = state.data.accounts.filter((item) => item.recordId !== account.recordId);
        save('已永久删除');
      } else {
        account.deleted = true;
        save('已移到回收站');
      }
      return;
    }
    if (event.target.classList.contains('row-undo') && row) { accountForRow(row).deleted = false; save('已撤销删除'); }
  });

  $('tbody').addEventListener('change', (event) => {
    const row = event.target.closest('tr[data-id]'); if (!row) return;
    const account = accountForRow(row);
    if (event.target.classList.contains('select')) {
      event.target.checked ? state.selected.add(account.recordId) : state.selected.delete(account.recordId); return;
    }
    const field = event.target.dataset.field; if (!field) return;
    account[field] = event.target.value;
    if (field === 'status') { account.canLogin = account.status === 'active'; account.loginEvidence = 'source-status-only-not-live-verified'; }
    account.updatedAtUtc = new Date().toISOString();
  });

  $('search').addEventListener('input', (event) => { state.search = event.target.value; render(); });
  $('sort').addEventListener('change', (event) => { state.sort = event.target.value; render(); });
  $('direction').addEventListener('click', () => { state.asc = !state.asc; $('direction').textContent = state.asc ? '升序' : '降序'; render(); });
  $('reveal').addEventListener('click', () => { state.reveal = !state.reveal; $('reveal').textContent = state.reveal ? '隐藏密码' : '显示密码'; render(); });
  $('save').addEventListener('click', () => save());
  $('reload').addEventListener('click', load);
  $('export').addEventListener('click', async () => {
    const blob = new Blob([JSON.stringify(state.data, null, 2)], { type: 'application/json' });
    const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = '易运盈账号导出.json'; link.click(); URL.revokeObjectURL(link.href);
  });
  $('importFile').addEventListener('change', async (event) => {
    const file = event.target.files[0]; if (!file) return;
    try {
      const imported = JSON.parse(await file.text());
      const accounts = Array.isArray(imported) ? imported : imported.accounts;
      if (!Array.isArray(accounts)) throw new Error('导入文件缺少 accounts 数组');
      if (!confirm(`导入 ${accounts.length} 条账号并替换当前数据？保存前会自动备份。`)) return;
      const previousAccounts = state.data.accounts;
      state.data.accounts = accounts;
      if (!(await save('导入并保存完成'))) { state.data.accounts = previousAccounts; render(); }
    } catch (error) { toast(error.message, true); }
    event.target.value = '';
  });
  $('batchDelete').addEventListener('click', () => {
    if (!state.selected.size) return toast('请先选择账号', true);
    state.data.accounts.forEach((item) => { if (state.selected.has(item.recordId)) item.deleted = true; });
    state.selected.clear(); save('所选账号已移到回收站');
  });
  $('batchUndo').addEventListener('click', () => {
    if (!state.selected.size) return toast('请先选择账号', true);
    state.data.accounts.forEach((item) => { if (state.selected.has(item.recordId)) item.deleted = false; });
    state.selected.clear(); save('所选删除已撤销');
  });
  // The first authenticated page load establishes an HttpOnly SameSite cookie.
  // Never put the in-memory bootstrap token back into history when switching views.
  $('openTests').addEventListener('click', () => { location.href = '/tests'; });
  $('openAll').addEventListener('click', () => { location.href = '/'; });
  $('stop').addEventListener('click', async () => { await request('/api/shutdown', { method: 'POST', body: '{}' }); document.body.innerHTML = '<main class="stopped"><h1>账号管理已安全关闭</h1><p>需要时重新双击桌面启动器。</p></main>'; });
  load();
})();
