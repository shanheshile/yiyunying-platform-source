# 易云后台（产品名：易运盈）交接文档

> 状态时间：2026-08-13（Asia/Shanghai）。先读本文件，再读 `docs/CURRENT_STATUS.md` 和 `docs/releases/2.8.0.md`。

## 0. 一句话结论

本地 `2.8.0 (62)` 已从源码提交 A `f08147a9546d3b4bb3539a2e68732e1f15c4b03b` 完成四端生产签名 Stable Build、完整源码检查和官网闭环。pending manifest SHA-256 为 `19C62DE18547E591238570C1211DAA81CB8A8731AF11AB181DCE06C63C802EF7`。唯一发布门禁是 Android 设备升级：固件虚拟化关闭，AVD 无法进入 ADB，没有 APK 被安装。因此当前不能 Finalize、部署、公开发布、创建 `v2.8.0` 标签或归档；线上仍是 `2.7.14 (59)` Debug 测试版。

## 1. 唯一源码与版本身份

- 规范仓库：`C:\Users\Administrator\Documents\易云后台\github-source`
- GitHub：`shanheshile/yiyunying-platform-source`
- 当前源码 A/HEAD：`f08147a9546d3b4bb3539a2e68732e1f15c4b03b`
- 本地 Build：Stable `2.8.0`，`versionCode=62`
- 线上版本：Debug `2.7.14`，`versionCode=59`
- 目标 API：`https://appht.jjmxg.xyz/`
- pending manifest：`releases/2.8.0/release-manifest.json`
- manifest SHA-256：`19C62DE18547E591238570C1211DAA81CB8A8731AF11AB181DCE06C63C802EF7`
- manifest 状态：`pending`，`releaseEvidenceCommit=null`
- release identity SHA-256：`B4062E6CCDC320625F327DAD5E2A8DDD554862E37BFD64B8BFE2ECA6C487ED72`
- production signer SHA-256：`6CF7B18AF125A1D44E28FEAEE7A5C6D39CA0BBAE89529CA43A8C200B21DB9772`

源码真相只在上述 Git 仓库。工作区根目录、旧 ZIP、`releases/` 制品、RC 目录、历史归档和 Codex 会话都不是可反向覆盖的源码来源。

## 2. 四端 Stable 制品

| 角色 | 包名 | 文件 | 字节 | SHA-256 |
| --- | --- | --- | ---: | --- |
| 用户端 | `xyz.jjmxg.yiyunying.user` | `yiyunying-user-v2.8.0.apk` | 85,927,093 | `E9EE171A60A3237A7B615A3E8F74E87A2D4E723FEE53A2A0B5B16870EAD690A7` |
| 管理员 | `xyz.jjmxg.yiyunying.admin` | `yiyunying-admin-v2.8.0.apk` | 22,632,645 | `679344074AB7D880B4F7D63FD4CF0CA817B7BE6525BBC295490CC9C016018742` |
| 授权平台 | `xyz.jjmxg.yiyunying.authorized` | `yiyunying-authorized-platform-v2.8.0.apk` | 22,632,661 | `120A2C52B5574F1B18D9D2B8A1CEBFC1E35FB57C505EF7AD13A565710758FBC3` |
| 平台总控 | `xyz.jjmxg.yiyunying.platformowner` | `yiyunying-platform-owner-v2.8.0.apk` | 22,632,649 | `7E54B715FC045920EE4A29E360C3D0F39CDB34D085821FF9E1D4E0E3943A4834` |

独立 APK 审计确认：四包 code 62、非 `.debug` application ID、`debuggable=false`、v2 单一 production signer、cleartext false、只信任系统 CA、编译 API 仅 HTTPS。用户包较大来自用户端独有的离线语音/JNA/Vosk 依赖，不是四包串包。

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

## 4. 验证证据

- 后端 `backend/tools/check.ps1`：214 个 PHP 文件、221 张表、811 条路由、444 个文档端点、27 个 PowerShell 脚本解析和全部合同 PASS。
- Android：226/226 Gradle tasks；四端各 337 项/总 1,348 项测试，0 failure、0 error、0 skipped；四端 Lint 与独立 APK 审计 PASS。
- 发布证据链：12/12 PASS；Stable 渠道、版本、源码 A、production signer、tag 和 public whitelist 失败关闭。
- 官网：build、rendered HTML、静态导出、ESLint、敏感信息扫描、无 JS 导航、打印 CSS、四角色/13 示例/58 路由、原子部署合同 PASS。
- 生产 HTTPS 只读 HEAD 复核：2.7.14/2.7.15 的 source ZIP、Git bundle、delivery ZIP、project manifest 共 8 个历史私有资产 URL 全部 HTTP 404，旧私有项目资产未公开。
- 仓库 `git diff --check` 与 secret/large-file scan PASS。

这些结果证明源码和制品可核验，不证明真实设备升级、Finalized manifest、生产部署或公网下载。

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

- code 61 RC 没有安装；
- code 62 Stable 没有安装；
- 没有 `adb install -r` 覆盖升级；
- 没有启动应用、登录、联网或数据保留证据；
- 没有 Finalize、`v2.8.0` tag、部署、官网正式下载、更新策略、公共回读或归档。

解除方式只有两个：BIOS 开启虚拟化并重启后恢复 AVD，或连接真实 Android 调试设备。无论哪种方式，都必须先安装同角色 code 61 RC，再用 code 62 Stable 覆盖升级并记录包名、版本、签名连续、启动、登录、API、关键入口和数据保留。

## 7. 门禁解除后的固定顺序

1. 完成 code 61 → code 62 真实设备升级闭环并保存证据。
2. 提交当前 release metadata 与文档形成证据提交 B；不要在文档里猜 B。
3. 创建指向 B 的 annotated tag `v2.8.0`。
4. 运行 Finalize，回读 manifest 的 `releaseEvidenceCommit`、tag、source A 与 Git refs 一致。
5. 备份并验证生产恢复点；检查旧版本私有项目资产不能从公网下载。
6. 部署数据库/私有资源、后端和四个 APK 到隐藏暂存位置，复算 hash、签名、MIME、长度、Range/ETag。
7. 原子激活四条更新策略和官网；做服务器与公网下载、生命周期、HTTPS、健康和功能回读。
8. 推送 B 与标签；最后创建新归档，做解压、逐文件哈希和 Git bundle 恢复演练。

任何一步失败都保留线上 `2.7.14 (59)`，不得覆盖旧制品、策略、恢复点或归档。

## 8. 接手验证命令

```powershell
Set-Location 'C:\Users\Administrator\Documents\易云后台\github-source'
git status --short --branch
git rev-parse HEAD
Get-Content android\version.properties
Get-FileHash -Algorithm SHA256 releases\2.8.0\release-manifest.json
Get-FileHash -Algorithm SHA256 releases\2.8.0\*.apk
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\secret-scan.ps1
git diff --check
```

Android 完整验证需要 JDK 17、Android SDK 与生产签名环境；不能把 keystore 密码写在命令行、Git 或文档。下载站可在 `download-site` 使用锁文件安装后执行 `pnpm test`、`pnpm export:static` 和 `pnpm lint`。

## 9. 不能再踩的坑

1. 不要从外层目录、旧 ZIP、release APK 或归档反向覆盖规范仓库。
2. 不要把 Build、APK 审计、HTTPS 后端、公开网站页面或 Git tag 单独称为正式 Release。
3. 不要在真实设备升级完成前 Finalize、部署、打 `v2.8.0` 标签或公开 code 62。
4. 不要公开源码/history/delivery/project manifest；正式 public whitelist 只有四个 APK。
5. 不要把下载完成当成安装完成；只有 PackageManager/替换广播或安装版本回读才算成功。
6. 不要修改或公开平台 KEY、应用唯一 ID、卡密、API key、keystore、密码、Token 或数据库数据。
7. 不要覆盖 2.7.14 tag、APK、更新策略、备份和归档。
8. 不要用 `package-project.ps1 -AllowDirty` 归档未提交源码；它会漏掉 dirty/untracked 内容。
9. 不要同时运行多个 Gradle/emulator/全盘扫描；本机曾发生虚拟内存耗尽。
10. 不要只看按钮或成功提示；权限、身份、更新、部署和公网状态都必须独立回读。

## 10. 当前准确口径

> 易运盈 `2.8.0 (62)` 已从 A `f08147a9546d3b4bb3539a2e68732e1f15c4b03b` 完成四端生产签名 Stable Build与官网最小功能闭环。pending manifest SHA 为 `19C62DE18547E591238570C1211DAA81CB8A8731AF11AB181DCE06C63C802EF7`，四包统一 production signer 为 `6CF7B18AF125A1D44E28FEAEE7A5C6D39CA0BBAE89529CA43A8C200B21DB9772`。设备升级验收因固件虚拟化关闭、AVD 无 ADB 而 BLOCKED；未安装任何 APK，所以尚未 Finalize、部署、公开发布、打标签或归档。线上仍是 2.7.14 (59)。
