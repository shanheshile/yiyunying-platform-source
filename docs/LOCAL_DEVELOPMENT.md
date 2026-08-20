# 本地开发

## 环境要求

- Windows 11 或现代 Linux/macOS
- JDK 17、Android SDK 36
- PHP 8.1+，启用 JSON、mbstring、PDO、pdo_mysql
- MySQL 8.0+ 或兼容版本
- Node.js 22.13+，Corepack/pnpm

## 后端

1. 创建数据库并导入 `backend/database/install.sql`。
2. 将 `backend/.env.example` 复制为 `backend/.env`，修改本地数据库配置。
3. 在 `backend` 目录运行：

```powershell
php -S 127.0.0.1:8788 -t public public/router.php
```

接口默认根地址为 `http://127.0.0.1:8788`。生产环境不要使用 PHP 内置服务器。

## Android

创建 `android/local.properties` 指向 Android SDK，然后运行：

```powershell
Set-Location android
.\gradlew.bat testUserDebugUnitTest assembleUserDebug
```

四种角色构建任务：

```powershell
.\gradlew.bat assemblePlatformOwnerDebug
.\gradlew.bat assembleAuthorizedPlatformDebug
.\gradlew.bat assembleAdminDebug
.\gradlew.bat assembleUserDebug
```

模拟器在未传连接参数时默认访问 `http://10.0.2.2:8788/`。真机自建测试必须显式传
`-PconnectionMode=self_host`，再用 `-PapiBaseUrl` / `-PapiBaseUrls`（或对应
`YIYUNYING_CONNECTION_MODE`、`YIYUNYING_API_BASE_URL(S)` 环境变量）注入买方自己的线路；
HTTP 还必须显式传 `-PallowHttpEndpoints=true`。官方 Stable 四端只允许
`https://appht.jjmxg.xyz/`，详细重放与故障切换边界见 `android/README.md`。

## 下载中心

```powershell
Set-Location download-site
corepack pnpm install --frozen-lockfile
corepack pnpm dev
```

发布静态站：

```powershell
corepack pnpm export:static
```

## 本地语音转写

后端会优先检测 `backend/storage/stt/venv` 和 `backend/tools/stt/transcribe.py`。本地模型未配置时可改用 OpenAI 兼容接口。生产服务器若禁止 `proc_open`，必须使用独立转写服务，不能把 PHP Warning 直接返回用户。
