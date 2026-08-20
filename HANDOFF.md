# 易云后台（产品名：易运盈）交接文档

> 状态时间：2026-08-15（Asia/Shanghai）。先读本文件，再读 `docs/CURRENT_STATUS.md` 和 `docs/releases/1.0.0.md`。

## 0. 一句话结论

正式首发候选 `1.0.0 (66)` 已从源码 A `9c645c035a290d2bfbec53022eb495c15265b29f` 完成四端生产签名 Stable Build；同版本四端 `legacyCompat` 也已构建，用于旧 Debug 包名的受控兼容升级。Stable manifest 当前仍为 `pending`、`releaseEvidenceCommit=null`，设备计划为 `risk-waiver`。真机验证由用户后续完成，只能记为 `pending-user-validation`，绝不能写成 `passed`；当前尚无风险豁免文件、Finalize、部署、公开发布或 `v1.0.0` 标签。上一轮 `1.0.0/code65` 已移到 `D:\易运盈\superseded-releases\1.0.0-code65-old-source-3a78b8c1`，只作历史核验。生产生命周期策略仍是 `2.7.14 (59)` Debug 测试版；客户官网继续失败关闭，旧 Debug 公网直链保持 404。

## 1. 唯一源码与版本身份

- 规范仓库：`D:\易运盈\github-source`（C 盘旧路径仅为无物理副本的兼容链接）
- GitHub：`shanheshile/yiyunying-platform-source`
- Build 源码 A：`9c645c035a290d2bfbec53022eb495c15265b29f`
- 下载分层实现与生产 Nginx 配置提交：`db1b58ffd6dae62909961196aa3dd031aa4ef40d`；当前 `main` / `origin/main` 必须现场用 `git rev-parse` 回读
- 正式首发候选：Stable `1.0.0`，`versionCode=66`，`finalizationStatus=pending`，`deviceValidationPlan=risk-waiver`
- 旧 Debug 兼容候选：`DebugCompatibility 1.0.0/code66`，`buildType=legacyCompat`，仅限内部受控分发
- 线上生命周期策略：Debug `2.7.14`，`versionCode=59`；客户下载入口已失败关闭，旧 Debug APK 公网 URL 为 404
- 目标 API：`https://appht.jjmxg.xyz/`
- pending manifest：`releases/1.0.0/release-manifest.json`
- manifest SHA-256：`F6EC5BD2F20F869DF1D0A4B4E7DE14EBB528B5033AD52D3E3F789BEC329D916F`
- release identity SHA-256：`DA8114FADBD08AD3B7DAE782AAC6DB24B89797A4F8BD72E6203728DA4261AEA5`
- 目标 Stable tag：`v1.0.0`；只能在发布元数据与文档形成证据提交 B 后创建并精确指向 B
- production signer SHA-256：`6CF7B18AF125A1D44E28FEAEE7A5C6D39CA0BBAE89529CA43A8C200B21DB9772`

源码真相只在上述 Git 仓库。工作区根目录、旧 ZIP、`releases/` 制品、RC 目录、历史归档和 Codex 会话都不是可反向覆盖的源码来源。

## 2. 1.0.0/code66 Stable 与 legacyCompat 制品

| 角色 | 文件 | 字节 | SHA-256 |
| --- | --- | ---: | --- |
| 用户端 | `yiyunying-user-v1.0.0.apk` | 85,927,165 | `CB0C7A77A0DCEF60CF5247EABEF67D6A11FCE1E4C9ACD380230B52D83E26AAE6` |
| 管理员 | `yiyunying-admin-v1.0.0.apk` | 22,649,105 | `9A9607D7CB32A8CCF43645B77D88EBA5F3EAB828963F9935F39EFFCE3A82A375` |
| 授权平台 | `yiyunying-authorized-platform-v1.0.0.apk` | 22,649,113 | `BC56570E5F1E3B03FEAC116CB82CB8D2A7D156135A5BB39E47AFC5A13C3783E6` |
| 平台总控 | `yiyunying-platform-owner-v1.0.0.apk` | 22,649,105 | `9705A3528B15AC51EFE9303B97CC4B96A1ED311A9997A657366C96B126B53685` |

四包均为 code66、正式包名、`1.0.0-<role>`、统一 production signer。它们仍是 pending 制品，Finalize 和正式部署完成前不得公开。`releases/internal/legacy-debug-compat/1.0.0/` 另有四个 code66、非 debuggable、旧 Debug 包名与旧 Debug signer 的 `legacyCompat` APK；其 manifest SHA-256 为 `C740DC71570BEE11608546611EF1EA368FCFDE3A52A248B0A0BBD64FD4D83D94`，仅限内部兼容轨道，不能冒充 Stable 或进入客户公网目录。

## 2.1 已被 code66 取代的历史候选与内部基线

| 角色 | 包名 | 文件 | 字节 | SHA-256 |
| --- | --- | --- | ---: | --- |
| 用户端 | `xyz.jjmxg.yiyunying.user` | `yiyunying-user-v2.8.0.apk` | 85,927,093 | `E9EE171A60A3237A7B615A3E8F74E87A2D4E723FEE53A2A0B5B16870EAD690A7` |
| 管理员 | `xyz.jjmxg.yiyunying.admin` | `yiyunying-admin-v2.8.0.apk` | 22,632,645 | `679344074AB7D880B4F7D63FD4CF0CA817B7BE6525BBC295490CC9C016018742` |
| 授权平台 | `xyz.jjmxg.yiyunying.authorized` | `yiyunying-authorized-platform-v2.8.0.apk` | 22,632,661 | `120A2C52B5574F1B18D9D2B8A1CEBFC1E35FB57C505EF7AD13A565710758FBC3` |
| 平台总控 | `xyz.jjmxg.yiyunying.platformowner` | `yiyunying-platform-owner-v2.8.0.apk` | 22,632,649 | `7E54B715FC045920EE4A29E360C3D0F39CDB34D085821FF9E1D4E0E3943A4834` |

这些文件已经从 `releases/2.8.0/` 移出并保存到 `D:\易运盈\.rc\superseded\2.8.0-code62-pending\`，附带原 manifest、`SHA256SUMS.txt` 和 `SUPERSEDED_INTERNAL_ONLY.md`。独立审计确认四包 code62、非 Debug 包名、`debuggable=false`、v2 单一 production signer、HTTPS-only。它们曾用于上一轮 code62→65 门禁；该计划已被 code66 取代。若用户后续选择完整 Stable 真机证据路径，只能按当前工具验证 code62→66，不能把历史 code62→65 结果当作本轮通过。

上一轮源码 A `3a78b8c1f5bae6cf49a7d4e5832f99c734371a78` 的 1.0.0/code65 Stable 制品、manifest 与校验和已移到 `D:\易运盈\superseded-releases\1.0.0-code65-old-source-3a78b8c1\`。它们只作可恢复历史核验，禁止恢复到 `releases/1.0.0/`、Finalize、部署或公开。

旧源码 A `ac574ed7923b826c29ccef2a681bf61fc09fdbb1` 生成的 1.0.0/code63 候选已移到 `%LOCALAPPDATA%\YiyunyingDeploy\superseded-releases\1.0.0-code63-old-source-ac574ed\`。它只作历史核验，不得恢复到当前发布目录、Finalize、部署或公开。

旧源码 A `7bbf955f56394bd8af838b6e30cf5d57afbe7fcf` 生成的 1.0.0/code64 候选已移到 `%LOCALAPPDATA%\YiyunyingDeploy\superseded-releases\1.0.0-code64-old-source-7bbf955\`，适用相同禁令。

## 3. 已完成的产品与官网闭环

### 管理端和用户端

- 管理员端为“首页、源码示例、交流、我的”四栏，支持一个账号管理多个应用，按当前应用控制所有系统和嵌入式审核/举报/统计内容。
- 登录页不显示服务器地址和应用标识；开发者把地址、KEY 和应用 API 唯一 ID 写入源码。后端仍联合校验账号、密码、账号 KEY、平台/应用身份、Token 时效/撤销/实时状态，并以 `/me` 二次确认。
- 用户端把私聊、群聊、红包、论坛、短视频、商城拆为可独立进入的最小闭环，并复用当前账号、应用、权限、Token 与失败关闭策略。
- 管理端涉及用户、邮箱、论坛、文档、好友、群聊、聊天、安全、卡密、云仓库、商城、公告/更新/维护三合一、反馈、在线人数、数据统计、审核和举报。
- 维护写入保护支持 header/body/query `app_key`，三种来源不一致返回 422；显式应用身份优先于 bearer 推断，维护异常语义不会被吞成 503。

### 官方网站与接口文档

- 四角色下载卡片：用户、管理员、授权平台、平台总控；Stable 发布脚本只允许公开四个 APK。
- 13 个系统级接入示例，精确映射 60 条后端路由；文件上传采用真实 multipart 合同并与列表回读闭环，每个功能说明用途、联动、条件、请求、结果、失败和下一步。
- “开始接入”示例使用占位域名/KEY/Token，不含真实凭据。
- 示例可在 cURL、Java、JavaScript 间切换，并可复制；复制有成功/失败反馈。
- 分享优先 Web Share，失败或不支持时复制 canonical URL，并报告状态。
- 打印样式隐藏导航和动作、展开正文，可由浏览器另存为 PDF。
- 目录、锚点、同窗/新窗打开、键盘/触控/ARIA 和无 JavaScript 基础导航均覆盖。
- APK 下载说明覆盖正确角色选包、SHA-256 校验、Android 打开/安装、未知来源授权和下载失败；不把下载成功写成安装成功。
- 源码 ZIP、Git history bundle、delivery 包和 project manifest 属于私有交付资产，不能放入客户公开下载目录。

## 4. 1.0.0 Build 证据

- 后端 `backend/tools/check.ps1`：214 个 PHP 文件、221 张表、811 条路由、444 个文档端点、27 个 PowerShell 脚本解析和全部合同 PASS。
- Android：当前源码 A 已生成四个 code66 Stable APK 和四个 code66 legacyCompat APK；清单内包名、角色版本、签名、体积与 SHA-256 已固化。上一轮 code65 的四端各 350 项/总 1,400 项测试、Release Lint 和 assemble 结果只作为 superseded 历史证据，不冒充本轮真机证据。
- 数据库：MariaDB 11.4.5 隔离验证库依次执行 install 与迁移 61、62、63、64、65；五个迁移连续实跑两轮均 exit 0，迁移标记、migration62 列/索引/功能开关与 migration65 邮件表终态验证 PASS，服务正常关闭且临时数据目录清理完成。
- 生产部署：此前一次尝试在迁移 62 处失败，代码、数据库和维护状态已完整回滚并核验；当前 code66 后端、迁移和 APK 仍未部署。
- 发布证据链：12/12 PASS；Stable 渠道、版本、源码 A、production signer、tag 和 public whitelist 失败关闭。
- 官网：build、rendered HTML、静态导出、ESLint、敏感信息扫描、无 JS 导航、打印 CSS、四角色/13 示例/60 路由、原子部署合同 PASS。
- 生产 HTTPS 只读 HEAD 复核：2.7.14/2.7.15 的 source ZIP、Git bundle、delivery ZIP、project manifest 共 8 个历史私有资产 URL 全部 HTTP 404，旧私有项目资产未公开。
- 仓库 `git diff --check` 与 secret/large-file scan PASS。

上述结果证明 1.0.0/code66 本地制品可核验，但不证明设备升级、Finalize、生产部署或公网下载。

## 5. 生产安全维护已完成

- 2 个平台账号、1 个管理员和 129 个用户分别生成独立随机密码。
- 恢复材料使用本机 Windows DPAPI 保存。
- 应用 secret 已轮换，全部 access/refresh Token 已撤销。
- 无已验证恢复渠道的账号只停用，不删除数据。
- 平台 KEY、应用唯一 ID、卡密和 API key 未修改。
- 生产签名 keystore 与口令材料不在 Git、普通归档、文档或命令输出中。

## 6. 设备状态与一次性风险豁免边界

### 已确认环境

- Android Emulator 37.1.11；API 35 Google APIs x86_64 image；AVD `Yiyunying_API35`。
- BIOS/UEFI firmware virtualization 为关闭。
- Hypervisor Platform、Virtual Machine Platform、Hyper-V 均未启用。
- `emulator-check accel` 报硬件加速不可用；`-accel off` 也没有产生 ADB 设备。
- 所有 emulator/qemu 进程已停止，`adb devices` 为空。

### 尚未发生

- 隔离的 code62 内部基线没有安装；
- 1.0.0/code66 Stable 和 legacyCompat APK 已 Build，但没有安装；
- 没有 `adb install -r` 覆盖升级；
- 没有启动应用、登录、联网或数据保留证据；
- 已有 pending manifest，但没有设备证据或风险豁免文件，也没有 Finalize、`v1.0.0` tag、部署、官网正式下载、更新策略、公共回读或归档。

用户已明确真机验证由其后续自行完成。本次 manifest 选择一次性 `risk-waiver` 计划；它只允许在证据提交 B 和精确指向 B 的 annotated tag 就绪后生成 `release-risk-waiver.json`，状态固定为 `pending-user-validation`。风险豁免不是测试结果，任何文档、官网或发布报告都不得写成真机 `passed`。若改走完整设备证据路径，应在真实 Android 设备上分别验证 Stable `code62→66` 和旧 Debug `code59/60（≤60）→66 legacyCompat` 的兼容、登录与数据连续性。

fail-closed 门禁 `android/tools/verify-device-upgrade.ps1` 要求基线版本号低于 Stable code66，并允许内部基线与正式首发使用不同 `versionName`。它仍强制同角色包名、非 debuggable、唯一 production signer、v2 签名、指定唯一 ADB serial、无 `-d`/卸载、`install -r` 以及 packageName/userId/dataDir 保留。真实安装仍未执行，当前状态只能是 `pending-user-validation`。

设备就绪后四个角色分别执行一次，参数模板如下（RC 与 Stable 必须选择同一角色）：

```powershell
powershell -File android/tools/verify-device-upgrade.ps1 `
  -Role <user|admin|authorized|owner> `
  -Serial <adb-serial> `
  -RcApk <2.8.0-code62-baseline-apk> `
  -StableApk <1.0.0-code66-stable-apk>
```

## 7. 门禁解除后的固定顺序

1. 提交 code66 release metadata 与本轮文档形成 B；不要在文档里猜 B。
2. 创建精确指向 B 的 annotated tag `v1.0.0`。
3. 在工作区完全干净且 A/B/tag 绑定回读通过后，按既定一次性确认生成 `release-risk-waiver.json`；其设备状态必须保持 `pending-user-validation`。若用户此前完成完整四角色真机证据，则二选一改用 `device-upgrade-evidence.json`，不得同时存在。
4. 运行 Finalize，回读 manifest 的 `releaseEvidenceCommit`、tag、source A、设备计划与 Git refs 一致；风险豁免路径绝不能产生 `passed`。
5. 备份并验证生产恢复点；检查旧版本私有项目资产不能从公网下载。
6. 部署数据库/私有资源、后端和四个 APK 到隐藏暂存位置，复算 hash、签名、MIME、长度、Range/ETag。
7. 原子激活四条正式策略和官网；做服务器与公网下载、生命周期、HTTPS、健康和功能回读。
8. 推送 B 与标签；最后创建新归档，做解压、逐文件哈希和 Git bundle 恢复演练。

任何一步失败都保留线上 `2.7.14 (59)`，不得覆盖旧制品、策略、恢复点或归档。

## 8. 接手验证命令

```powershell
Set-Location 'D:\易运盈\github-source'
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
3. 不要把隔离的 2.8.0/code62 或旧 1.0.0/code63、code64、code65 候选重新移回发布目录、Finalize、打标签或公开；不要把风险豁免写成真机通过。
4. 不要公开源码/history/delivery/project manifest；正式 public whitelist 只有四个 APK。
5. 不要把下载完成当成安装完成；只有 PackageManager/替换广播或安装版本回读才算成功。
6. 不要修改或公开平台 KEY、应用唯一 ID、卡密、API key、keystore、密码、Token 或数据库数据。
7. 不要覆盖 2.7.14 tag、APK、更新策略、备份和归档。
8. 不要用 `package-project.ps1 -AllowDirty` 归档未提交源码；它会漏掉 dirty/untracked 内容。
9. 不要同时运行多个 Gradle/emulator/全盘扫描；本机曾发生虚拟内存耗尽。
10. 不要只看按钮或成功提示；权限、身份、更新、部署和公网状态都必须独立回读。

## 10. 当前准确口径

> 易运盈 `1.0.0 (66)` 已从 A `9c645c035a290d2bfbec53022eb495c15265b29f` 完成四端 production Stable Build，并完成四端 code66 legacyCompat Build；Stable manifest 仍为 pending，设备计划为 risk-waiver。真机由用户后续验收，当前只能标记 `pending-user-validation`，绝不能写成 `passed`。上一轮 code65 已保存到 `D:\易运盈\superseded-releases\1.0.0-code65-old-source-3a78b8c1`；本轮尚未 Finalize、tag、部署或上线。线上生命周期策略仍是 2.7.14 (59) Debug，客户公开下载保持失败关闭。
