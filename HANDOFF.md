# 易云后台（产品名：易运盈）交接文档

> 状态时间：2026-08-13（Asia/Shanghai）。先读本文件，再读 `docs/CURRENT_STATUS.md` 和 `docs/releases/1.0.0.md`。

## 0. 一句话结论

工作树已切换为正式首发候选 `1.0.0 (63)`。新的源码提交 A、四端 Stable APK、pending manifest 和 `2.8.0/code62 → 1.0.0/code63` 真机覆盖升级尚未形成，因此当前不能 Finalize、部署、公开发布、创建 `v1.0.0` 标签或归档。原 `2.8.0 (62)` pending 制品已隔离到外层 `.rc/superseded/2.8.0-code62-pending/`，仅作内部同签名升级基线，禁止公开或 Finalize。线上仍是 `2.7.14 (59)` Debug 测试版。

## 1. 唯一源码与版本身份

- 规范仓库：`C:\Users\Administrator\Documents\易云后台\github-source`
- GitHub：`shanheshile/yiyunying-platform-source`
- 当前已推送基线 HEAD：`c0e125c9e2e194fa5a4904ba1027dc395344769c`
- 正式首发候选：`1.0.0`，`versionCode=63`；新的 Build 源码 A 尚未提交，不能预写
- 线上版本：Debug `2.7.14`，`versionCode=59`
- 目标 API：`https://appht.jjmxg.xyz/`
- 目标 manifest：`releases/1.0.0/release-manifest.json`；当前尚不存在
- 目标 Stable tag：`v1.0.0`；只能在设备门禁通过并形成证据提交 B 后创建
- production signer SHA-256：`6CF7B18AF125A1D44E28FEAEE7A5C6D39CA0BBAE89529CA43A8C200B21DB9772`

源码真相只在上述 Git 仓库。工作区根目录、旧 ZIP、`releases/` 制品、RC 目录、历史归档和 Codex 会话都不是可反向覆盖的源码来源。

## 2. 已隔离的 2.8.0/code62 内部基线

| 角色 | 包名 | 文件 | 字节 | SHA-256 |
| --- | --- | --- | ---: | --- |
| 用户端 | `xyz.jjmxg.yiyunying.user` | `yiyunying-user-v2.8.0.apk` | 85,927,093 | `E9EE171A60A3237A7B615A3E8F74E87A2D4E723FEE53A2A0B5B16870EAD690A7` |
| 管理员 | `xyz.jjmxg.yiyunying.admin` | `yiyunying-admin-v2.8.0.apk` | 22,632,645 | `679344074AB7D880B4F7D63FD4CF0CA817B7BE6525BBC295490CC9C016018742` |
| 授权平台 | `xyz.jjmxg.yiyunying.authorized` | `yiyunying-authorized-platform-v2.8.0.apk` | 22,632,661 | `120A2C52B5574F1B18D9D2B8A1CEBFC1E35FB57C505EF7AD13A565710758FBC3` |
| 平台总控 | `xyz.jjmxg.yiyunying.platformowner` | `yiyunying-platform-owner-v2.8.0.apk` | 22,632,649 | `7E54B715FC045920EE4A29E360C3D0F39CDB34D085821FF9E1D4E0E3943A4834` |

这些文件已经从 `releases/2.8.0/` 移出并保存到 `C:\Users\Administrator\Documents\易云后台\.rc\superseded\2.8.0-code62-pending\`，附带原 manifest、`SHA256SUMS.txt` 和 `SUPERSEDED_INTERNAL_ONLY.md`。独立审计确认四包 code62、非 Debug 包名、`debuggable=false`、v2 单一 production signer、HTTPS-only。它们只用于验证同角色 code62→63 原地升级，不属于可发布版本。

## 3. 已完成的产品与官网闭环

### 管理端和用户端

- 管理员端为“首页、源码示例、交流、我的”四栏，支持一个账号管理多个应用，按当前应用控制所有系统和嵌入式审核/举报/统计内容。
- 登录页不显示服务器地址和应用标识；开发者把地址、KEY 和应用 API 唯一 ID 写入源码。后端仍联合校验账号、密码、账号 KEY、平台/应用身份、Token 时效/撤销/实时状态，并以 `/me` 二次确认。
- 用户端把私聊、群聊、红包、论坛、短视频、商城拆为可独立进入的最小闭环，并复用当前账号、应用、权限、Token 与失败关闭策略。
- 管理端涉及用户、邮箱、论坛、文档、好友、群聊、聊天、安全、卡密、云仓库、商城、公告/更新/维护三合一、反馈、在线人数、数据统计、审核和举报。
- 维护写入保护支持 header/body/query `app_key`，三种来源不一致返回 422；显式应用身份优先于 bearer 推断，维护异常语义不会被吞成 503。

### 官方网站与接口文档

- 四角色下载卡片：用户、管理员、授权平台、平台总控；Stable 发布脚本只允许公开四个 APK。
- 13 个系统级接入示例，精确映射 58 条后端路由；每个功能说明用途、联动、条件、请求、结果、失败和下一步。
- “开始接入”示例使用占位域名/KEY/Token，不含真实凭据。
- 示例可在 cURL、Java、JavaScript 间切换，并可复制；复制有成功/失败反馈。
- 分享优先 Web Share，失败或不支持时复制 canonical URL，并报告状态。
- 打印样式隐藏导航和动作、展开正文，可由浏览器另存为 PDF。
- 目录、锚点、同窗/新窗打开、键盘/触控/ARIA 和无 JavaScript 基础导航均覆盖。
- APK 下载说明覆盖正确角色选包、SHA-256 校验、Android 打开/安装、未知来源授权和下载失败；不把下载成功写成安装成功。
- 源码 ZIP、Git history bundle、delivery 包和 project manifest 属于私有交付资产，不能放入客户公开下载目录。

## 4. 已有基线证据与 1.0.0 重验要求

- 后端 `backend/tools/check.ps1`：214 个 PHP 文件、221 张表、811 条路由、444 个文档端点、27 个 PowerShell 脚本解析和全部合同 PASS。
- Android：226/226 Gradle tasks；四端各 337 项/总 1,348 项测试，0 failure、0 error、0 skipped；四端 Lint 与独立 APK 审计 PASS。
- 发布证据链：12/12 PASS；Stable 渠道、版本、源码 A、production signer、tag 和 public whitelist 失败关闭。
- 官网：build、rendered HTML、静态导出、ESLint、敏感信息扫描、无 JS 导航、打印 CSS、四角色/13 示例/58 路由、原子部署合同 PASS。
- 生产 HTTPS 只读 HEAD 复核：2.7.14/2.7.15 的 source ZIP、Git bundle、delivery ZIP、project manifest 共 8 个历史私有资产 URL 全部 HTTP 404，旧私有项目资产未公开。
- 仓库 `git diff --check` 与 secret/large-file scan PASS。

这些结果属于功能基线和已隔离 2.8.0 制品，不是 1.0.0 的 Build 证据。`1.0.0 (63)` 必须从新的干净源码提交 A 重新执行 Stable Build、完整自动化、四包审计、设备升级和官网测试，任何旧哈希不得复用为首发制品哈希。

## 5. 生产安全维护已完成

- 2 个平台账号、1 个管理员和 129 个用户分别生成独立随机密码。
- 恢复材料使用本机 Windows DPAPI 保存。
- 应用 secret 已轮换，全部 access/refresh Token 已撤销。
- 无已验证恢复渠道的账号只停用，不删除数据。
- 平台 KEY、应用唯一 ID、卡密和 API key 未修改。
- 生产签名 keystore 与口令材料不在 Git、普通归档、文档或命令输出中。

## 6. 唯一发布阻塞：设备升级

### 已确认环境

- Android Emulator 37.1.11；API 35 Google APIs x86_64 image；AVD `Yiyunying_API35`。
- BIOS/UEFI firmware virtualization 为关闭。
- Hypervisor Platform、Virtual Machine Platform、Hyper-V 均未启用。
- `emulator-check accel` 报硬件加速不可用；`-accel off` 也没有产生 ADB 设备。
- 所有 emulator/qemu 进程已停止，`adb devices` 为空。

### 尚未发生

- 隔离的 code62 内部基线没有安装；
- 1.0.0/code63 Stable APK 尚未 Build，也没有安装；
- 没有 `adb install -r` 覆盖升级；
- 没有启动应用、登录、联网或数据保留证据；
- 没有 1.0.0 pending manifest、Finalize、`v1.0.0` tag、部署、官网正式下载、更新策略、公共回读或归档。

解除方式只有两个：BIOS 开启虚拟化并重启后恢复 AVD，或连接真实 Android 调试设备。无论哪种方式，都必须先安装同角色 `2.8.0/code62` 隔离基线，再用新 Build 的 `1.0.0/code63` Stable APK 覆盖升级并记录包名、版本、签名连续、启动、登录、API、关键入口和数据保留。

fail-closed 门禁 `android/tools/verify-device-upgrade.ps1` 已调整为基线版本号低于 Stable code63，并允许内部基线与正式首发使用不同 `versionName`。它仍强制同角色包名、非 debuggable、唯一 production signer、v2 签名、指定唯一 ADB serial、无 `-d`/卸载、`install -r` 以及 packageName/userId/dataDir 保留。对应假工具链合同已改为 `2.8.0/code62 → 1.0.0/code63`；真实安装仍未执行。

设备就绪后四个角色分别执行一次，参数模板如下（RC 与 Stable 必须选择同一角色）：

```powershell
powershell -File android/tools/verify-device-upgrade.ps1 `
  -Role <user|admin|authorized|owner> `
  -Serial <adb-serial> `
  -RcApk <2.8.0-code62-baseline-apk> `
  -StableApk <1.0.0-code63-stable-apk>
```

## 7. 门禁解除后的固定顺序

1. 提交 1.0.0/code63 版本、门禁、测试和文档形成新的 Build 源码 A；不要预写其哈希。
2. 从干净 A 执行 Stable Build，生成 `releases/1.0.0/` 四包和 pending manifest。
3. 完成 `2.8.0/code62 → 1.0.0/code63` 真实设备升级闭环并保存证据。
4. 提交 1.0.0 release metadata 与设备证据形成 B；不要在文档里猜 B。
5. 创建指向 B 的 annotated tag `v1.0.0`。
6. 运行 Finalize，回读 manifest 的 `releaseEvidenceCommit`、tag、source A 与 Git refs 一致。
7. 备份并验证生产恢复点；检查旧版本私有项目资产不能从公网下载。
8. 部署数据库/私有资源、后端和四个 APK 到隐藏暂存位置，复算 hash、签名、MIME、长度、Range/ETag。
9. 原子激活四条正式策略和官网；做服务器与公网下载、生命周期、HTTPS、健康和功能回读。
10. 推送 B 与标签；最后创建新归档，做解压、逐文件哈希和 Git bundle 恢复演练。

任何一步失败都保留线上 `2.7.14 (59)`，不得覆盖旧制品、策略、恢复点或归档。

## 8. 接手验证命令

```powershell
Set-Location 'C:\Users\Administrator\Documents\易云后台\github-source'
git status --short --branch
git rev-parse HEAD
Get-Content android\version.properties
Test-Path releases\1.0.0
Get-ChildItem ..\.rc\superseded\2.8.0-code62-pending
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\secret-scan.ps1
git diff --check
```

Android 完整验证需要 JDK 17、Android SDK 与生产签名环境；不能把 keystore 密码写在命令行、Git 或文档。下载站可在 `download-site` 使用锁文件安装后执行 `pnpm test`、`pnpm export:static` 和 `pnpm lint`。

## 9. 不能再踩的坑

1. 不要从外层目录、旧 ZIP、release APK 或归档反向覆盖规范仓库。
2. 不要把 Build、APK 审计、HTTPS 后端、公开网站页面或 Git tag 单独称为正式 Release。
3. 不要把隔离的 2.8.0/code62 重新移回发布目录、Finalize、打标签或公开；不要在 1.0.0/code63 真机升级完成前创建 `v1.0.0`。
4. 不要公开源码/history/delivery/project manifest；正式 public whitelist 只有四个 APK。
5. 不要把下载完成当成安装完成；只有 PackageManager/替换广播或安装版本回读才算成功。
6. 不要修改或公开平台 KEY、应用唯一 ID、卡密、API key、keystore、密码、Token 或数据库数据。
7. 不要覆盖 2.7.14 tag、APK、更新策略、备份和归档。
8. 不要用 `package-project.ps1 -AllowDirty` 归档未提交源码；它会漏掉 dirty/untracked 内容。
9. 不要同时运行多个 Gradle/emulator/全盘扫描；本机曾发生虚拟内存耗尽。
10. 不要只看按钮或成功提示；权限、身份、更新、部署和公网状态都必须独立回读。

## 10. 当前准确口径

> 易运盈正式首发候选为 `1.0.0 (63)`，当前仅完成版本与 `2.8.0/code62 → 1.0.0/code63` 门禁源码调整；新的 Build 源码 A、四端 Stable APK、pending manifest 和真机升级证据尚未形成。2.8.0/code62 已隔离为 internal-only baseline，禁止 Finalize、打标签或公开。线上仍是 2.7.14 (59) Debug，1.0.0 不能称为已上线。
