<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__);
/** @var Yiyunying\Core\Router $router */
$router = require $root . '/routes/api.php';
$registered = $router->routes();
$routes = [];
foreach ($registered as $route) {
    $path = (string) $route['path'];
    $scope = str_starts_with($path, '/api/platform/') ? 'platform'
        : (str_starts_with($path, '/api/admin/') ? 'admin'
        : (str_starts_with($path, '/api/user/') ? 'user'
        : (str_starts_with($path, '/api/public/') ? 'public' : 'system')));
    $handler = $route['handler'];
    $routes[] = [
        'method' => (string) $route['method'],
        'path' => $path,
        'scope' => $scope,
        'handler' => is_array($handler) ? basename(str_replace('\\', '/', (string) $handler[0])) . '::' . $handler[1] : 'callable',
    ];
}
$sql = (string) file_get_contents($root . '/database/install.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS `[^`]+`/', $sql, $matches);
$tableCount = count($matches[0]);
$routeJson = json_encode($routes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

$html = <<<'HTML'
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>易运盈后台 API 工作台</title>
<style>
:root{--bg:#f4f7f5;--panel:#fff;--line:#dce5df;--text:#17231b;--muted:#66736b;--green:#176b45;--teal:#087d83;--amber:#b15c00;--red:#b3261e;--blue:#225aa7;--shadow:0 8px 24px rgba(25,53,36,.08)}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.55 system-ui,-apple-system,"Microsoft YaHei",sans-serif;letter-spacing:0}
button,input,textarea,select{font:inherit;letter-spacing:0}button{cursor:pointer}.app{min-height:100vh;display:grid;grid-template-columns:276px minmax(0,1fr)}
.side{position:sticky;top:0;height:100vh;background:#173f2c;color:#fff;padding:22px 16px;overflow:auto}.brand{display:flex;align-items:center;gap:12px;padding:0 8px 20px;border-bottom:1px solid rgba(255,255,255,.18)}
.mark{display:grid;place-items:center;width:40px;height:40px;border-radius:8px;background:#fff;color:var(--green);font-weight:800;font-size:19px}.brand strong{display:block;font-size:17px}.brand small{color:#c5d8cd}
.side h3{font-size:12px;font-weight:600;color:#b9d2c3;margin:22px 8px 8px}.nav{display:grid;gap:4px}.nav button{border:0;background:transparent;color:#eaf3ed;text-align:left;padding:10px 12px;border-radius:6px}.nav button:hover,.nav button.active{background:#fff;color:#173f2c}.side-note{margin:20px 8px 0;color:#b9d2c3;font-size:12px}
.main{min-width:0}.top{position:sticky;top:0;z-index:10;background:rgba(244,247,245,.96);backdrop-filter:blur(12px);border-bottom:1px solid var(--line);padding:16px 24px}.top-row{display:flex;align-items:center;gap:12px;max-width:1500px;margin:auto}.search{flex:1;min-width:180px}.input{width:100%;border:1px solid var(--line);background:#fff;color:var(--text);padding:10px 12px;border-radius:6px;outline:none}.input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(8,125,131,.12)}
.btn{border:1px solid var(--line);background:#fff;color:var(--text);padding:9px 12px;border-radius:6px}.btn:hover{border-color:var(--green);color:var(--green)}.btn.primary{background:var(--green);border-color:var(--green);color:#fff}.mobile-menu{display:none}
.content{max-width:1500px;margin:auto;padding:24px}.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.metric{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:16px}.metric b{display:block;font-size:24px;color:var(--green)}.metric span{color:var(--muted)}
.credentials{display:flex;gap:14px;flex-wrap:wrap;background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px 16px;margin-bottom:18px}.credentials b{color:var(--green)}.credentials code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;color:var(--text)}
.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 18px}.chip{border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 11px;color:var(--muted)}.chip.active{background:#e5f3ea;border-color:#8fc4a4;color:#125b3b}.count{margin-left:auto;color:var(--muted)}
.routes{display:grid;gap:8px}.route{display:grid;grid-template-columns:70px minmax(240px,1fr) 150px 34px;gap:12px;align-items:center;background:#fff;border:1px solid var(--line);border-radius:7px;padding:11px 12px;transition:.16s ease}.route:hover{transform:translateY(-1px);box-shadow:var(--shadow);border-color:#b4c9bb}.method{font-weight:800;font-size:12px}.GET{color:var(--teal)}.POST{color:var(--green)}.PUT{color:var(--amber)}.DELETE{color:var(--red)}.path{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}.desc{color:var(--muted);font-size:12px}.scope{color:var(--muted);text-align:right}.icon-btn{border:0;background:transparent;font-size:18px;color:var(--muted);padding:4px}
.empty{display:none;text-align:center;color:var(--muted);padding:80px 16px}.drawer{position:fixed;z-index:30;right:0;top:0;height:100vh;width:min(620px,100%);background:#fff;border-left:1px solid var(--line);box-shadow:-18px 0 50px rgba(20,40,28,.16);transform:translateX(102%);transition:transform .22s ease;display:flex;flex-direction:column}.drawer.open{transform:none}.drawer-head{padding:18px 20px;border-bottom:1px solid var(--line);display:flex;gap:12px;align-items:flex-start}.drawer-head h2{font-size:18px;margin:0;overflow-wrap:anywhere}.drawer-head p{margin:5px 0 0;color:var(--muted)}.drawer-head .icon-btn{margin-left:auto}.drawer-body{padding:20px;overflow:auto}.section{margin-bottom:20px}.section h3{font-size:13px;margin:0 0 8px;color:var(--muted)}.code{position:relative;background:#13271c;color:#e8f4ec;border-radius:7px;padding:14px;white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.6 ui-monospace,SFMono-Regular,Consolas,monospace}.copy{position:absolute;right:7px;top:7px;border:1px solid #476555;background:#203c2b;color:#fff;border-radius:5px;padding:5px 8px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.form-grid .wide{grid-column:1/-1}.label{display:grid;gap:5px;color:var(--muted);font-size:12px}.textarea{min-height:126px;resize:vertical;font-family:ui-monospace,Consolas,monospace}.response{min-height:110px;max-height:360px;overflow:auto}.backdrop{position:fixed;z-index:20;inset:0;background:rgba(12,24,17,.32);opacity:0;pointer-events:none;transition:.2s}.backdrop.open{opacity:1;pointer-events:auto}.toast{position:fixed;z-index:50;left:50%;bottom:28px;transform:translate(-50%,20px);background:#173f2c;color:#fff;padding:10px 16px;border-radius:6px;opacity:0;transition:.2s}.toast.show{opacity:1;transform:translate(-50%,0)}
@media(max-width:900px){.app{display:block}.side{position:fixed;z-index:40;left:0;top:0;width:276px;transform:translateX(-102%);transition:.2s}.side.open{transform:none}.mobile-menu{display:inline-block}.top{padding:12px}.content{padding:16px}.summary{grid-template-columns:1fr 1fr}.route{grid-template-columns:62px 1fr 28px}.scope{display:none}.form-grid{grid-template-columns:1fr}.form-grid .wide{grid-column:auto}}
@media(max-width:520px){.summary{grid-template-columns:1fr 1fr}.metric{padding:12px}.metric b{font-size:20px}.route{padding:10px 8px;gap:7px}.top-row{gap:7px}.top-row .btn:not(.mobile-menu){display:none}}
</style>
</head>
<body>
<div class="app">
  <aside class="side" id="side">
    <div class="brand"><div class="mark">易</div><div><strong>易运盈后台</strong><small>API 工作台</small></div></div>
    <h3>接口角色</h3><div class="nav" id="roleNav"></div>
    <h3>接口分类</h3><div class="nav" id="categoryNav"></div>
    <div class="side-note">所有接口来自当前后端路由注册表。平台、admin、user 数据按四级链路与租户边界隔离。</div>
  </aside>
  <main class="main">
    <header class="top"><div class="top-row">
      <button class="btn mobile-menu" id="menuBtn">菜单</button>
      <div class="search"><input class="input" id="search" placeholder="搜索路径、功能、处理器"></div>
      <button class="btn" id="copyGroup">复制当前接口</button>
      <button class="btn primary" id="settingsBtn">调试配置</button>
    </div></header>
    <div class="content">
      <section class="summary">
        <div class="metric"><b id="routeCount"></b><span>实际 API 路由</span></div>
        <div class="metric"><b>__TABLE_COUNT__</b><span>MySQL 数据表</span></div>
        <div class="metric"><b>4</b><span>独立角色软件</span></div>
        <div class="metric"><b>中文</b><span>提示与业务文案</span></div>
      </section>
      <section class="credentials"><b>默认测试账号</b><code>L1 root / 123456 / yiyunying-root</code><code>L2 authorized / 123456 / yiyunying-authorized</code><code>L3 admin / 123456 / yiyunying-root</code><code>L4 user / 123456 / yiyunying-demo</code></section>
      <div class="filters" id="quickFilters"></div>
      <div class="routes" id="routes"></div><div class="empty" id="empty">没有符合条件的接口</div>
    </div>
  </main>
</div>
<div class="backdrop" id="backdrop"></div>
<aside class="drawer" id="drawer">
  <div class="drawer-head"><div><h2 id="drawerTitle">接口调试</h2><p id="drawerDesc"></p></div><button class="icon-btn" id="closeDrawer">×</button></div>
  <div class="drawer-body">
    <section class="section"><h3>调用说明</h3><div class="code" id="curlCode"><button class="copy" data-copy="curlCode">复制</button></div></section>
    <section class="section"><h3>调试参数</h3><div class="form-grid">
      <label class="label wide">服务器地址<input class="input" id="baseUrl" value="http://appht.jjmxg.xyz"></label>
      <label class="label">Bearer Token<input class="input" id="token" placeholder="登录后获得的 access_token"></label>
      <label class="label">X-App-Key<input class="input" id="appKey" placeholder="user 接口必填"></label>
      <label class="label">X-Platform-Key<input class="input" id="platformKey" value="yiyunying-root"></label>
      <label class="label">路径参数 JSON<input class="input" id="pathParams" value="{}"></label>
      <label class="label wide">请求体 JSON<textarea class="input textarea" id="requestBody">{}</textarea></label>
      <button class="btn primary wide" id="sendRequest">发送请求</button>
    </div></section>
    <section class="section"><h3>服务器响应</h3><div class="code response" id="responseCode">尚未发送请求</div></section>
  </div>
</aside>
<div class="toast" id="toast"></div>
<script>
const ROUTES=__ROUTES__;
const roleLabels={all:'全部接口',platform:'平台端（1/2级）',admin:'管理员端（3级）',user:'用户端（4级）',public:'公开接口',system:'系统接口'};
const categoryLabels={auth:'认证账户',operators:'授权平台',admins:'管理员',apps:'应用管理',governance:'强制治理',community:'同级交流',activities:'红包抽奖悬赏',polls:'投票互动',lifecycle:'更新维护',users:'用户管理',notes:'笔记',documents:'文档能力',resources:'资源大厅',forum:'论坛社区',messages:'消息私信','chat-rooms':'用户群聊',service:'客服',cards:'卡密',wallet:'余额资产',orders:'订单支付',shop:'商城互动',files:'文件上传',feedbacks:'反馈',statistics:'日志统计',other:'其他'};
const actionNames={index:'列表',show:'详情',create:'创建',update:'修改',delete:'删除',login:'登录',logout:'退出',register:'注册',claim:'领取',draw:'抽奖',submit:'提交',award:'结算',close:'关闭',cancel:'取消',save:'保存',profile:'资料',password:'密码',bootstrap:'启动配置',check:'检查'};
let state={role:'all',category:'all',query:'',selected:null};
const $=id=>document.getElementById(id);
function category(route){const p=route.path.split('/').filter(Boolean);const parts=p.slice(2);let raw=parts[0]||'other';if(raw==='apps'&&parts[1]&&parts[1].startsWith('{')&&parts[2])raw=parts[2];if(raw.includes('login')||raw.includes('profile')||raw.includes('password')||raw==='me')return'auth';if(raw.includes('software')||raw.includes('maintenance')||raw.includes('version'))return'lifecycle';if(raw.includes('poll')||raw==='votes')return'polls';if(raw.includes('document'))return'documents';if(raw.includes('note'))return'notes';if(raw.includes('resource')||raw.includes('store'))return'resources';if(raw.includes('message')||raw.includes('friend')||raw.includes('notification'))return'messages';if(raw.includes('chat-room'))return'chat-rooms';if(raw.includes('file')||raw.includes('upload'))return'files';if(raw.includes('payment')||raw.includes('order'))return'orders';if(raw.includes('exchange')||raw.includes('integral')||raw.includes('wallet')||raw.includes('withdraw'))return'wallet';return categoryLabels[raw]?raw:'other'}
function description(route){const method=(route.handler.split('::')[1]||'').replace(/[A-Z]/g,m=>' '+m.toLowerCase());const action=Object.entries(actionNames).find(([k])=>method.includes(k));return `${categoryLabels[category(route)]||'业务'} · ${action?action[1]:method.trim()||'操作'}`}
function filtered(){return ROUTES.filter(r=>(state.role==='all'||r.scope===state.role)&&(state.category==='all'||category(r)===state.category)&&(!state.query||(`${r.path} ${r.handler} ${description(r)}`).toLowerCase().includes(state.query)))}
function init(){buildRoles();buildCategories();render();$('routeCount').textContent=ROUTES.length;$('search').addEventListener('input',e=>{state.query=e.target.value.trim().toLowerCase();render()});$('copyGroup').onclick=copyCurrent;$('settingsBtn').onclick=()=>openDrawer(null);$('closeDrawer').onclick=closeDrawer;$('backdrop').onclick=()=>{closeDrawer();$('side').classList.remove('open')};$('menuBtn').onclick=()=>{$('side').classList.add('open');$('backdrop').classList.add('open')};$('sendRequest').onclick=sendRequest;document.addEventListener('click',e=>{const id=e.target.dataset.copy;if(id)copyText($(id).innerText.replace('复制','').trim())})}
function buildRoles(){const nav=$('roleNav');Object.entries(roleLabels).forEach(([key,label])=>{const b=document.createElement('button');b.textContent=`${label} · ${key==='all'?ROUTES.length:ROUTES.filter(r=>r.scope===key).length}`;b.className=key===state.role?'active':'';b.onclick=()=>{state.role=key;state.category='all';buildRoles();buildCategories();render();closeMobile()};nav.appendChild(b)})}
function buildCategories(){const counts={};ROUTES.filter(r=>state.role==='all'||r.scope===state.role).forEach(r=>counts[category(r)]=(counts[category(r)]||0)+1);const nav=$('categoryNav');nav.innerHTML='';const entries=[['all','全部分类'],...Object.entries(categoryLabels)];entries.filter(([k])=>k==='all'||counts[k]).forEach(([key,label])=>{const b=document.createElement('button');b.textContent=`${label} · ${key==='all'?Object.values(counts).reduce((a,b)=>a+b,0):counts[key]}`;b.className=key===state.category?'active':'';b.onclick=()=>{state.category=key;buildCategories();render();closeMobile()};nav.appendChild(b)})}
function render(){const data=filtered(),box=$('routes');box.innerHTML='';data.forEach(route=>{const el=document.createElement('article');el.className='route';el.innerHTML=`<div class="method ${route.method}">${route.method}</div><div><div class="path">${escapeHtml(route.path)}</div><div class="desc">${escapeHtml(description(route))} · ${escapeHtml(route.handler)}</div></div><div class="scope">${roleLabels[route.scope]}</div><button class="icon-btn" title="打开调试">›</button>`;el.onclick=()=>openDrawer(route);box.appendChild(el)});$('empty').style.display=data.length?'none':'block';$('quickFilters').innerHTML=`<span class="chip active">${roleLabels[state.role]}</span>${state.category!=='all'?`<span class="chip active">${categoryLabels[state.category]}</span>`:''}<span class="count">当前 ${data.length} 条</span>`}
function openDrawer(route){state.selected=route||state.selected||ROUTES[0];const r=state.selected;$('drawerTitle').textContent=`${r.method} ${r.path}`;$('drawerDesc').textContent=`${roleLabels[r.scope]} · ${description(r)} · ${r.handler}`;$('requestBody').value=JSON.stringify(sampleBody(r),null,2);$('pathParams').value=JSON.stringify(sampleParams(r),null,2);refreshCurl();$('responseCode').textContent='尚未发送请求';$('drawer').classList.add('open');$('backdrop').classList.add('open');['baseUrl','token','appKey','platformKey','requestBody','pathParams'].forEach(id=>$(id).oninput=refreshCurl)}
function closeDrawer(){$('drawer').classList.remove('open');$('backdrop').classList.remove('open')}
function closeMobile(){$('side').classList.remove('open');$('backdrop').classList.remove('open')}
function sampleParams(r){const out={};[...r.path.matchAll(/\{([^}]+)\}/g)].forEach(m=>out[m[1]]=1);return out}
function sampleBody(r){
  if(['GET','DELETE'].includes(r.method))return{};
  if(r.path.endsWith('/login'))return r.scope==='user'?{app_key:'yiyunying-demo',account:'user',password:'123456'}:{platform_key:'yiyunying-root',account:r.scope==='platform'?'root':'admin',password:'123456'};
  if(r.path.endsWith('/register'))return{app_key:'yiyunying-demo',account:'new_user',nickname:'新用户',password:'123456',password_confirmation:'123456'};
  if(r.path.endsWith('/verification-code/email'))return{app_key:'yiyunying-demo',email:'user@example.com',scene:'register'};
  if(r.path.endsWith('/scan-qr'))return{qr_payload:'扫码得到的签名内容',message:'你好，我想添加你为好友'};
  if(r.path.includes('/identity-unbind-requests')&&r.path.endsWith('/review'))return{action:'approve',remark:'审核通过'};
  if(r.path.endsWith('/identity-unbind-requests'))return{identity_type:'phone',reason:'更换手机号'};
  if(r.path==='/api/platform/software-updates'||r.path==='/api/platform/software-updates/{policy_id}')return{edition_code:'user',target_type:'global',version_name:'2.7.14',version_code:59,min_supported_version_code:58,download_url:'https://downloads.example.com/yiyunying-user-v2.7.14.apk',package_name:'xyz.jjmxg.yiyunying.user',size_bytes:96538285,sha256:'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',release_notes:'稳定性与下载体验优化',force_update:false,priority:0};
  if(r.path==='/api/admin/apps/{app_id}/versions')return{version_name:'2.7.14',version_code:59,min_supported_version_code:58,apk_url:'https://downloads.example.com/yiyunying-user-v2.7.14.apk',package_name:'xyz.jjmxg.yiyunying.user',size_bytes:96538285,sha256:'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',update_content:'稳定性与下载体验优化',force_update:false};
  if(r.path.endsWith('/wallet/transfer'))return{to_uid:'目标用户 UID',amount:10};
  if(r.path.endsWith('/wallet/purchases'))return{product_type:'document_credit',quantity:1};
  if(r.path.endsWith('/activities'))return{activity_type:'red_packet',funding_mode:r.scope==='platform'?'issued':'balance',title:'测试活动',total_balance:10,total_count:2,targets:[{type:'level',level:r.scope==='admin'?4:3}]};
  return{};
}
function resolvedPath(r){let path=r.path;try{const values=JSON.parse($('pathParams').value||'{}');Object.entries(values).forEach(([k,v])=>path=path.replace(`{${k}}`,encodeURIComponent(v)))}catch{}return path}
function headers(r){const h={'Accept':'application/json','Content-Type':'application/json'};if(r.scope==='user'&&$('appKey').value.trim())h['X-App-Key']=$('appKey').value.trim();if((r.scope==='platform'||r.scope==='admin')&&$('platformKey').value.trim())h['X-Platform-Key']=$('platformKey').value.trim();if(r.scope!=='public'&&r.scope!=='system'&&$('token').value.trim())h['Authorization']='Bearer '+$('token').value.trim();return h}
function refreshCurl(){const r=state.selected;if(!r)return;const url=$('baseUrl').value.replace(/\/$/,'')+resolvedPath(r),h=headers(r);let curl=`curl -X ${r.method} '${url}'`;Object.entries(h).forEach(([k,v])=>curl+=` \\\n  -H '${k}: ${v}'`);if(!['GET','DELETE'].includes(r.method))curl+=` \\\n  --data '${($('requestBody').value||'{}').replace(/'/g,"'\\''")}'`;$('curlCode').childNodes.forEach(n=>{if(n.nodeType===3)n.remove()});$('curlCode').appendChild(document.createTextNode(curl))}
async function sendRequest(){const r=state.selected;if(!r)return;let body;try{body=JSON.parse($('requestBody').value||'{}')}catch(e){toast('请求体不是有效 JSON');return}const options={method:r.method,headers:headers(r)};if(!['GET','DELETE'].includes(r.method))options.body=JSON.stringify(body);$('responseCode').textContent='请求中...';const started=performance.now();try{const response=await fetch($('baseUrl').value.replace(/\/$/,'')+resolvedPath(r),options);const text=await response.text();let data;try{data=JSON.parse(text)}catch{data=text}$('responseCode').textContent=`HTTP ${response.status} · ${Math.round(performance.now()-started)}ms\n\n`+(typeof data==='string'?data:JSON.stringify(data,null,2))}catch(e){$('responseCode').textContent='请求失败：'+e.message+'\n\n请检查域名、HTTPS、CORS 和站点重写。'}}
function copyCurrent(){const lines=filtered().map(r=>`${r.method} ${r.path}  # ${description(r)}`);copyText(lines.join('\n'))}
async function copyText(text){try{await navigator.clipboard.writeText(text);toast('已复制')}catch{const t=document.createElement('textarea');t.value=text;document.body.appendChild(t);t.select();document.execCommand('copy');t.remove();toast('已复制')}}
function toast(text){$('toast').textContent=text;$('toast').classList.add('show');setTimeout(()=>$('toast').classList.remove('show'),1500)}
function escapeHtml(text){return String(text).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
init();
</script>
</body></html>
HTML;

$html = str_replace(['__ROUTES__', '__TABLE_COUNT__'], [$routeJson, (string) $tableCount], $html);
file_put_contents($root . '/public/api-docs.html', $html);
echo 'Generated public/api-docs.html (' . count($routes) . ' routes, ' . $tableCount . " tables)\n";
