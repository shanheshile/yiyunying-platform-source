# 易运盈后台生产部署

## “无法解析服务器响应”的诊断

Android 端要求 API 始终返回 JSON。如果 `/api/health` 返回 Nginx 的 HTML 403/404/502，说明请求没有正确进入 `public/index.php`，应检查网站运行目录、URL 重写和 PHP-FPM，而不是修改账号或客户端 JSON 解析器。部署者必须显式提供目标 API 地址，健康检查必须返回本文末尾所示 JSON。

## 全新部署

1. 上传整个 `yiyunying-backend`，保留 `app/`、`config/`、`database/`、`public/`、`routes/` 和 `storage/`。
2. 创建数据库和最小权限数据库用户，按下方“首次安装身份注入”在同一个数据库会话中注入身份哈希，再以 UTF-8 无 BOM `SOURCE database/install.sql`。
3. 安装并启用 PHP 扩展：`curl`、`pdo_mysql`、`mbstring`、`json`、`fileinfo`。
4. 把网站运行目录设置为 `yiyunying-backend/public`，不要设置为源码根目录。
5. Nginx 使用 `nginx-site.conf.example`；Apache 使用 `apache-vhost.conf.example` 并启用 `mod_rewrite`、`mod_headers`。两份示例都对 `/uploads` 增加 `nosniff`，并拒绝直接读取 SVG；不得删掉这组规则。宝塔“伪静态”必须使用带 `__route=$uri` 的规则，不能只把所有请求改写成 `/index.php` 或 `/`。
6. 在 PHP-FPM pool 或服务器面板中配置数据库环境变量，可参考 `php-fpm-env.example`。
7. 重载 Nginx/Apache 和 PHP-FPM，再执行 `tools/check-deployment.ps1`。

### 首次安装身份注入

`database/install.sql` 不再包含默认账号、默认密码或公开应用密钥。直接导入仍可完整创建表结构和初始化数据，
但只会产生随机不可认证且 `status=0` 的占位身份，任何人都不能用已知口令登录。

需要导入后立即登录时，先在安全的本地环境中分别用 PHP `password_hash()` 生成账号密码哈希，
并对随机生成的 `app_secret` 计算 SHA-256。不要把明文、哈希或密钥放入命令历史、工单、聊天记录或版本库。
随后进入 MySQL/MariaDB 交互会话，显式设置需要启用的层级，再在**同一个会话**中执行 `SOURCE`：

```sql
SET @YY_BOOTSTRAP_ROOT_PLATFORM_KEY = '<平台所有者唯一 KEY>';
SET @YY_BOOTSTRAP_ROOT_ACCOUNT = '<平台所有者账号>';
SET @YY_BOOTSTRAP_ROOT_PASSWORD_HASH = '<PHP password_hash 输出>';

SET @YY_BOOTSTRAP_ADMIN_ACCOUNT = '<管理员账号>';
SET @YY_BOOTSTRAP_ADMIN_PASSWORD_HASH = '<PHP password_hash 输出>';
SET @YY_BOOTSTRAP_APP_KEY = '<应用唯一 APP KEY>';
SET @YY_BOOTSTRAP_APP_SECRET_HASH = '<随机 app_secret 的 64 位 SHA-256>';
SET @YY_BOOTSTRAP_USER_UID = '<用户唯一 UID>';
SET @YY_BOOTSTRAP_USER_ACCOUNT = '<用户账号>';
SET @YY_BOOTSTRAP_USER_PASSWORD_HASH = '<PHP password_hash 输出>';

-- 需要 2 级授权平台时再设置以下三项；不设置则保持安全禁用。
SET @YY_BOOTSTRAP_AUTHORIZED_PLATFORM_KEY = '<授权平台唯一 KEY>';
SET @YY_BOOTSTRAP_AUTHORIZED_ACCOUNT = '<授权平台账号>';
SET @YY_BOOTSTRAP_AUTHORIZED_PASSWORD_HASH = '<PHP password_hash 输出>';

SOURCE /服务器绝对路径/yiyunying-backend/database/install.sql;
```

各层是否启用会按依赖关系闭环校验：平台所有者未启用时，其下管理员、应用和用户都不会启用；
管理员未启用时应用和用户也不会启用。生产环境应只注入实际需要的身份，本地闭环测试可在一次性数据库中
显式注入测试身份，测试结束后销毁数据库，不能把测试密钥复制到生产。

升级旧环境时，先在维护窗口盘点并隔离 `public/uploads` 中扩展名为 SVG 或真实 MIME 为
`image/svg+xml` 的历史文件，再重载 Web 服务器。恢复流量前必须从公网回读旧 URL，确认返回 404/403，
不能只依赖新版本上传校验或数据库门禁。

## 服务器本地 AI

易运盈机器人默认使用同一台服务器上的 Ollama，不把用户问题、租户知识或会话发送到第三方 AI。回答链路固定为：实时天气等确定性工具、当前租户知识库、本地大模型、离线规则兜底。文学、科学、人文、文化、地理、农学、哲学等综合问题由本地模型回答；余额、账号、权限、天气等业务事实仍由 PHP 服务读取真实数据，不能让模型猜测。

在宝塔终端执行：

```bash
cd /www/wwwroot/appht.jjmxg.xyz/yiyunying-backend
chmod +x deploy/local-ai/*.sh
sudo AI_MODEL=qwen2.5:3b deploy/local-ai/install-ollama.sh
```

脚本会读取可用内存；默认模型在可用内存不足约 3.2 GB 时自动降为 `qwen2.5:1.5b`。约 6-8 GB 可用内存建议 `qwen2.5:3b`，12 GB 以上可测试 `qwen2.5:7b`。Ollama 只监听 `127.0.0.1:11434`，不要在安全组、Nginx 或公网开放 11434。

把 `deploy/php-fpm-env.example` 中的 `AI_*` 环境变量写入当前网站实际使用的 PHP-FPM pool，确认 `clear_env = no`，然后完整重启对应 PHP-FPM。宝塔 PHP 不在系统 PATH 时可这样验收：

```bash
PHP_BIN=/www/server/php/82/bin/php AI_MODEL=qwen2.5:3b \
  deploy/local-ai/verify-environment.sh
```

自检会同时验证 Ollama 服务、目标模型、PHP 必需扩展和一次真实问答。平台端再请求 `GET /api/platform/ai/status`；管理员可请求 `GET /api/admin/apps/{app_id}/ai/status`。两处均正常后，Android 用户端才算完成“应用 -> PHP -> 租户知识库/会话 -> 本地模型”的闭环。

1 级平台可创建全局、平台、管理员或应用范围知识；2 级只能管理自己的分支，并只读继承 1 级全局知识；3 级只能管理自己应用的知识。模型离线或超时时接口会快速退回租户知识库，不让客户端长时间卡住。

## 本地语音转文字

项目内置 `faster-whisper` 安装器，不需要配置第三方 `STT_API_URL` 或 `STT_API_KEY`：

```bash
cd /www/wwwroot/appht.jjmxg.xyz/yiyunying-backend
bash deploy/install-local-stt.sh
```

默认模型为 `base/int8`，首次转写时下载到 `storage/stt/models`。站点使用的 PHP-FPM 必须允许 `proc_open`；宝塔中应只从当前 PHP 版本的 `disable_functions` 删除 `proc_open`，保留其他安全限制，然后完整重启对应 PHP-FPM。验证命令：

```powershell
powershell -ExecutionPolicy Bypass -File tools/smoke-speech-transcription.ps1 `
  -BaseUrl http://appht.jjmxg.xyz
```

脚本会上传真实中文 WAV，验证首次本地推理及第二次缓存读取，并自动清理临时租户数据。

## 大文件与视频上传

HTTP 413 是 Nginx 在请求进入 PHP 前拒绝了请求，与 API 路由重写无关。宝塔面板需要在当前站点的 `server { ... }` 内加入：

```nginx
client_max_body_size 1100m;
client_body_timeout 900s;
```

同时在当前 PHP 版本中设置 `upload_max_filesize=1024M`、`post_max_size=1100M`、`max_input_time=900`、`max_execution_time=900`。保存后重载 Nginx，并重启对应 PHP-FPM；只改项目里的 `.user.ini` 不一定会覆盖服务器主配置。

## TURN 与 PHP-FPM 环境变量

生产环境的语音、视频通话必须返回至少一个可用的 TURN 中继地址。`VOICE_CALL_ICE_SERVERS` 的值必须是原始 JSON 数组，不能把整段 JSON 再包进一层会被 `getenv()` 读到的字面单引号或双引号；否则 JSON 解码会失败并退回仅 STUN 配置，跨运营商或严格 NAT 网络下会出现“网络通话连接失败”。

如果宝塔无法可靠注入 PHP-FPM 环境变量，可把同一段 JSON 写入：

```text
storage/voice-call-ice-servers.json
```

该文件必须位于 `public` 目录之外，只允许 PHP-FPM 运行用户读取，例如 Linux 权限 `640`。TURN 用户名和密钥不得写入 Android 安装包、源码 ZIP、公开 API 文档或公共备份。修改配置后需要完整重载 PHP-FPM，并执行：

```powershell
powershell -ExecutionPolicy Bypass -File tools/smoke-voice-calls.ps1 `
  -BaseUrl http://appht.jjmxg.xyz
```

脚本会明确断言通话接口返回 TURN 中继服务器，而不是只检查 STUN。

## 已有数据库升级

先完整备份数据库，再执行：

```text
database/upgrade_20260712_groups_admin_documents.sql
```

该脚本把文档归属扩展为 `admin/user`，并增加群策略、群邀请、入群申请、已读位置和管理员群消息字段。PHP 文件和 Android 客户端也必须同步更新，不能只执行 SQL。

## 正确结果

```bash
curl -i "${API_BASE_URL%/}/api/health"
```

响应头必须包含：

```text
HTTP/1.1 200
Content-Type: application/json; charset=utf-8
```

响应体必须是统一 JSON：

```json
{"code":1,"msg":"操作成功","data":{"status":"ok"},"trace_id":"..."}
```

只要看到 Nginx/Apache 的 HTML 403、404 或 502 页面，就仍是站点根目录、重写或 PHP-FPM 配置问题。

如果 `/api/health` 能返回 JSON，但 `POST /api/admin/login` 提示“请求方法不允许”，并且
`GET /api/admin/login` 返回健康接口内容，说明路径被折叠成了 `/`。宝塔“伪静态”应完整替换为：

```nginx
location / {
    try_files $uri $uri/ /index.php?__route=$uri&$query_string;
}
```

保存后重载 Nginx，并重启对应 PHP-FPM 以清理旧 OPcache。

## 四层身份诊断

```powershell
$apiBaseUrl = Read-Host 'API 地址'
$platformAccount = Read-Host '平台账号'
$platformKey = Read-Host '平台 KEY'
$platformPassword = Read-Host '平台密码' -AsSecureString
$adminAccount = Read-Host '管理员账号'
$adminPassword = Read-Host '管理员密码' -AsSecureString
$appKey = Read-Host '应用 APP KEY'
$userAccount = Read-Host '用户账号'
$userPassword = Read-Host '用户密码' -AsSecureString

Set-ExecutionPolicy -Scope Process Bypass -Force
& tools/check-deployment.ps1 `
  -BaseUrl $apiBaseUrl `
  -PlatformAccount $platformAccount -PlatformPassword $platformPassword -PlatformKey $platformKey `
  -AdminAccount $adminAccount -AdminPassword $adminPassword -AppKey $appKey `
  -UserAccount $userAccount -UserPassword $userPassword
```

脚本没有地址、账号、密码、平台 KEY 或 APP KEY 默认值。它依次验证健康检查、平台、管理员和应用用户，
并强制检查所有响应都是 JSON；密码使用 `SecureString` 显式传入，不应以明文写进命令历史。
