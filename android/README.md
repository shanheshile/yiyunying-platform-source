# Android 客户端

原生 Android 应用，使用 Java 17 和 Android SDK 36。四个 product flavor 分别对应平台总控、授权平台、管理员和用户端，共享聊天、媒体、缓存、权限与设计系统。

版本由 `version.properties` 管理。平台与应用 KEY 不得写死，可通过 Gradle 属性或环境变量注入。

## 两套部署与多线路

连接身份在编译时固定，登录页不能临时改服务器：

- 官方托管版（`hosted`，默认）四个 Stable 端只允许唯一主线
  `https://appht.jjmxg.xyz/`，不接受备用线路，也不允许 HTTP 或 HTTPS→HTTP 降级。
- 源码买断自建版必须显式使用 `self_host`，并显式提供买方自己的服务器；构建门禁拒绝继续使用
  `appht.jjmxg.xyz`。`apiBaseUrls` 按书写顺序排列，用分号、逗号或换行分隔，规范化后去重。
- 只有 GET/HEAD 读取在连接/DNS/超时/连接重置，或明确的 502/503/504 时尝试下一线路。
  4xx、业务错误、普通 500 不切换；POST/PUT/PATCH/DELETE、上传和 Token 刷新始终只发一次且只走主线路。

官方托管配置可用非敏感校验任务复核：

```powershell
.\gradlew.bat :app:printConnectionConfig
```

源码买断自建 HTTPS 多线路示例：

```powershell
.\gradlew.bat assembleUserRelease `
  -PconnectionMode=self_host `
  '-PapiBaseUrls=https://api.customer.example/;https://api-backup.customer.example/'
```

也可使用 `YIYUNYING_CONNECTION_MODE`、`YIYUNYING_API_BASE_URLS` 环境变量。只配置一条线路时，
继续兼容 `-PapiBaseUrl` / `YIYUNYING_API_BASE_URL`。

HTTP 仅供买方明确接受明文传输风险的自建环境，除 `self_host` 外还要第二次显式授权；该开关会选择允许
cleartext 的 Android 网络安全配置，不能用于官方包：

```powershell
.\gradlew.bat assembleUserRelease `
  -PconnectionMode=self_host `
  '-PapiBaseUrls=http://192.0.2.10:8080/;https://api-backup.customer.example/' `
  -PallowHttpEndpoints=true
```

Release 仍要求买方注入自己的签名、平台 KEY、应用 KEY 等材料。Debug 在没有任何连接参数时保留
`http://10.0.2.2:8788/` 本机模拟器默认值；一旦显式传入自建地址，同样执行上述模式与 HTTP 门禁。

常用校验：

```powershell
.\gradlew.bat testPlatformOwnerDebugUnitTest testAuthorizedPlatformDebugUnitTest testAdminDebugUnitTest testUserDebugUnitTest
.\gradlew.bat assemblePlatformOwnerDebug assembleAuthorizedPlatformDebug assembleAdminDebug assembleUserDebug
```

锁屏来电、推送、悬浮窗、后台保活和文件/媒体权限必须在真实目标设备上验证。

## 旧 Debug 原包名安全覆盖构建

`legacyCompat` 仅用于覆盖已经安装的四个历史 `.debug` 包。它继承 Release
加固、禁止调试和明文流量，继续使用旧 Debug 证书与包名；不能作为公开 Debug
下载，也不能替代 Stable Release。

历史升级身份固定在受版本控制的
`legacy-debug-upgrade-identity.json`。构建脚本会同时复核冻结的 2.7.15 Debug
清单、当前 `version.properties` 和同版本、同 versionCode 的 Stable pending
`release-manifest.json`。当前全局 versionCode 必须大于历史上限 60。

构建前必须在当前进程安全注入以下三个真实连接身份值：

```powershell
$env:YIYUNYING_APP_KEY = (Get-SecretValueForCurrentApp)
$env:YIYUNYING_PLATFORM_KEY = (Get-SecretValueForOwnerPlatform)
$env:YIYUNYING_AUTHORIZED_PLATFORM_KEY = (Get-SecretValueForAuthorizedPlatform)
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\build-legacy-debug-compat.ps1
```

脚本拒绝空值、控制字符、首尾空白、常见占位值，以及与 Stable pending
`connectionIdentity` 三个 SHA-256 不一致的值。内部兼容清单仅写入三个 SHA-256，
不写 KEY 明文。验证后的四包和清单写入：

```text
releases/internal/legacy-debug-compat/<VERSION_NAME>/
```

从仓库根目录进行旧 2.7.15 Debug 只读审计时，可以不传兼容清单且不使用
`--execute`。任何真实内部部署执行都必须显式传入当前全局版本的精确清单：

```powershell
$compat = Resolve-Path .\releases\internal\legacy-debug-compat\1.0.0\release-manifest.json
python .\download-site\scripts\deploy-internal-apks.py `
  --debug-compatibility-manifest $compat `
  --execute `
  <其余经维护窗口复核的 SSH、Nginx、PHP 和双确认参数>
```

构建脚本和部署脚本会离线复核包名/versionCode、non-debuggable/testOnly、
APK Signature Scheme v2/单签名者、编译后的网络安全资源、系统证书信任和 DEX
生产 HTTPS endpoint。即使这些门禁全部通过，也只表示构建验证通过；在四角色目标
设备完成覆盖安装、登录、数据保留和回滚验证前，不得宣称“真机可用”。
