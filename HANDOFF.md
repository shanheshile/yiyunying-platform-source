# 易云后台（产品名：易运盈）最终交接

> 面向一台全新电脑上的新 AI/新会话。先读本文件，不需要恢复旧 Codex 会话。
>
> 状态时间：2026-08-10 22:40（Asia/Shanghai）。项目已进入冻结、交付和关闭阶段。

## 0. 一句话结论

`2.7.13 (58)` 四端 Debug 测试更新已经构建、部署并开启非强制更新：旧版 `versionCode=57` 的平台总控、授权平台、管理员和用户端均能检查到 58；当前 58 均返回无更新。它与历史 Debug 包的包名和证书连续，设计上支持覆盖安装，但真实设备覆盖安装仍待验收；它不是正式商用 Release。

## 1. 项目身份与唯一源码

- 工作区名称：易云后台。
- 产品/UI 名称：易运盈。
- GitHub 仓库：`shanheshile/yiyunying-platform-source`。
- 旧电脑规范源码目录：`C:\Users\Administrator\Documents\易云后台\github-source`。
- 分支：`main`；最终交付标签：`v2.7.13-debug`。
- 当前版本：Android `2.7.13`，`versionCode=58`。
- Android 四端包名：
  - 平台总控：`xyz.jjmxg.yiyunying.platformowner.debug`
  - 授权平台：`xyz.jjmxg.yiyunying.authorized.debug`
  - 管理员：`xyz.jjmxg.yiyunying.admin.debug`
  - 用户：`xyz.jjmxg.yiyunying.user.debug`

唯一规范源码是 `github-source` Git 仓库。工作区根目录、旧 ZIP、`release/` 副本、历史解压目录和 Codex 会话记录都不是源码来源。

## 2. 我们最后在做什么

最后一轮按用户截图与文字要求收尾以下内容，并补齐安全约束：

- 修复“11”等短文本气泡右侧大块空白，降低聊天轮询和列表绑定造成的抽帧；
- 完成更多面板、软件内拍照/录像、系统相机、完整相册和本人名片快捷操作；
- 在聊天、群聊、聊天室、动态、帖子、评论、收藏和板块页面增加顶部中央双击回顶；
- 优化动态评论弹层、论坛嵌套回复和可拖动音频进度；
- 重整本人、好友、群聊、聊天室及当前聊天设置，补群/聊天室/板块头像上传；
- 支持帖子正文、章节和附件分别免费、余额付费、按日期解锁或“付费/到期任一满足”；
- 把新增功能接入管理员开关，并在服务端执行不可绕过的权限检查；
- 修复付费内容泄露、公共附件绕过、重复发帖、短签名过期、已购内容被删除和原始文件名泄露。

## 3. 已经完成

### 3.1 聊天、拍摄与性能

- 纯文本气泡按正文实际宽度收缩；通话卡等结构化消息仍保留必要最小宽度。
- 无变化轮询不再每秒重复滚到底部；适配器使用消息 ID 索引和局部更新，媒体缩略图使用低内存、无入场动画路径。
- “更多”重复点击保持展开。
- 短按拍摄进入软件内 Camera2 页面：轻触拍照，长按录像，最长 60 秒，返回后统一执行本地媒体压缩。
- 长按拍摄进入系统原生相机；长按相册进入完整相册；长按名片直接发送本人名片。
- 拍摄、相册、名片和通话记录标签都有管理员开关；聊天上传来源由服务端 `uploads.scene` 复核，不能靠客户端 metadata 绕过。

### 3.2 评论、导航、资料与头像

- 相关长列表支持双击顶部中央回到顶部。
- 子回复保留在所属根评论内部，默认预览两条，展开/收起按钮缩小。
- 动态评论弹层增加不透明标题面和强制裁剪视口，修复标题错位、圆角外穿透和漏出其他评论。
- 内联音频支持拖动进度、倍速、错误释放和重试；私有媒体 URL 在到期前、回前台和点击播放前刷新。
- 本人、好友、群、聊天室和当前聊天设置统一资料卡层级；群、聊天室和板块头像上传均有租户/管理者/管理员开关校验。
- 首页消息、动态和活动快捷按钮补齐浅色/深色模式对比度。

### 3.3 论坛章节、解锁和安全

- 正文、章节和附件支持 `free`、`paid`、`scheduled`、`paid_or_scheduled`。
- 受保护附件进入私有 `forum_section` 存储，统一经鉴权下载；贴纸、裸 URL 和公共附件不能转成付费内容。
- 旧整帖付费在详情、列表、搜索、收藏、历史和公开主页统一脱敏；只有作者或购买者能获取原文/附件。
- 购买只允许审核通过内容；资产类型不可变地记录为 `balance`。
- 已发生购买后，用户端和管理员端均禁止破坏性修改/删除；仍被内容引用的上传不能移除。
- `client_draft_id`、数据库唯一键和事务行锁让重复点击/网络重试返回原帖子，不重复创建附件、奖励或日志。
- 公开帖子、评论和动态媒体会递归清除原始文件名、本地路径和 URI；该保护为安全强制项，不能关闭。
- 管理端只修改开关启用状态时会保留原 `config_json`。

### 3.4 数据库与后端

生产数据库已在完整备份后按以下顺序执行四个幂等迁移：

1. `upgrade_20260810_chat_experience_controls.sql`
2. `upgrade_20260810_profile_space_avatar_controls.sql`
3. `upgrade_20260810_forum_content_unlocks.sql`
4. `upgrade_20260810_forum_data_consistency.sql`

新增/补齐的管理员能力包括聊天拍摄、相册、名片、通话记录标签，群/聊天室/板块头像，以及论坛章节、付费、定时、附件解锁和媒体文件名隐私标识。私有媒体要求独立且不少于 32 字节的 `MEDIA_SIGNING_KEY`；生产环境已安全配置，但真实值不在仓库或交接包中。

### 3.5 Debug 更新部署

- 生产后端、四项迁移和健康检查：通过。
- 代码、数据库和 `.env` 恢复点：`/www/backup/yiyunying/20260810-221916-pre-2.7.13-debug`。
- APK 和更新策略恢复点：`/www/backup/yiyunying/20260810-223329-android-2.7.13-debug`。
- APK 先在同一文件系统隐藏目录中完成 4/4 大小与 SHA-256 校验，再通过目录改名原子公开到 `/downloads/2.7.13/`。
- 四条全局更新策略在单个数据库事务中启用，全部 `force_update=false`。
- 服务器内与旧电脑公网独立回读均通过：四端 `57 -> 58 available=true`，四端 `58 -> 58 available=false`。
- 四个公网 APK 的 MIME、Content-Length、Range `0-3` 文件头和完整 SHA-256 均与发布清单一致。

## 4. 验证证据

- `backend/tools/check.ps1`：通过；184 个 PHP 文件、215 张表、762 条路由、413 个文档端点及全部合同测试通过。
- Android：226 项 Gradle 任务全部执行；每个角色 202 项单元测试，共 808 项，0 失败、0 跳过；四端 Lint 和 Debug 构建通过。
- `scripts/verify.ps1 -SkipAndroid`：通过；版本链、秘密/大文件扫描、PHP 静态检查和下载站安装/Lint/构建/测试通过。下载站只有 4 条既有 `<img>` 性能 warning，无 error。
- `git diff --check`：通过，只有换行符提示。
- 四端证书 SHA-256：`10162EBB7147EA0823C281D9F86FEFF2A353984A41497F17E196E50614E9B76E`。

四端 APK：

| 端 | 文件 | 字节 | SHA-256 |
| --- | --- | ---: | --- |
| 用户 | `yiyunying-user-v2.7.13-debug.apk` | 96,538,285 | `DA766590E3CC838E98FA851E35D48F94B8F909A391F97310F8C800990E12E034` |
| 管理员 | `yiyunying-admin-v2.7.13-debug.apk` | 32,136,681 | `D5BE640F67EEAAF3C96176D7AB23C20B37EF6484264F8F7EB379E24265CFB44D` |
| 授权平台 | `yiyunying-authorized-platform-v2.7.13-debug.apk` | 32,136,681 | `DA369F6C221984A0B271E65E155C925EC89AEBFCE71715C4BE7D1DFAAC38CEF6` |
| 平台总控 | `yiyunying-platform-owner-v2.7.13-debug.apk` | 32,136,685 | `10BE40FC7CA8050D5C5F80769C1B80D319B98D9E4359E43656C73548E1F9D8C1` |

## 5. 当前卡在哪里 / 没有完成什么

### P0：不能称为正式商用 Release

- 当前四端都是 `.debug` 包和 Debug 证书；没有正式 release signingConfig、受保护生产签名、跨版本覆盖/回滚和防降级证明。
- 公网 API 与 APK 下载仍使用 HTTP；HTTPS 证书主机名/信任链尚未修好。
- 用于本次部署的 SSH 密码曾被粘贴到聊天中，必须视为泄露并立即轮换；旧服务器、面板、数据库和演示凭据也应一起审计撤销。
- 本轮通过自动化、真实生产迁移和公网更新链路，但没有完成 Android 14/15/16 多品牌真机覆盖安装、输入法、通知、后台限制、锁屏来电、媒体录制和弱网验收。
- 资金系统仍缺不可变双重记账、并发幂等、退款和日终对账的生产证明；四级 RBAC/ABAC 越权矩阵也未完成。
- TURN/STUN、厂商推送、地图、对象存储、AI/语音、支付回调、备份恢复演练和监控告警仍需真实环境验收。

### 已知非阻塞限制

- 本次更新通过生命周期接口生效；静态下载中心页面未在同批部署，页面可能仍显示 2.7.12。不要把页面版本当作客户端更新策略的证据。
- 解锁方式目前只有免费、余额付费、按日期、付费或到期；没有密码、关注、会员、角色、邀请或多阶段解锁。
- Debug keystore 不在 Git 和普通交接 ZIP 中。失去旧密钥后，新电脑生成的 Debug 包不能覆盖现有 Debug 安装；如果项目重新启动，应改用正式受保护签名并设计迁移。

## 6. 下一步计划

项目默认路线是结束和归档，不再扩需求：

1. 立即轮换本次在聊天中暴露的 SSH 密码，撤销旧会话，并保存不含秘密值的轮换记录。
2. 在至少一台已安装 2.7.12/57 的真实设备上打开应用或“设置 → 检查更新”，确认提示 2.7.13、下载、哈希校验和覆盖安装成功；记录设备型号/Android 版本。
3. 保留 GitHub 标签、E 盘最终归档、生产两个恢复点和 SHA-256 清单；不得删除旧 2.7.12 或更早历史。
4. 若静态下载页面仍需对外使用，再单独部署 2.7.13 页面并复核，不要改动已经生效的生命周期策略。
5. 若项目未来转正式商用，必须新立项处理 HTTPS、正式签名、凭据体系、真机矩阵、资金/权限安全、通话/推送和恢复演练；完成前保持“Debug 测试基线”口径。

## 7. 全新电脑如何恢复

### 7.1 在线恢复

GitHub 仓库为受限仓库，先登录具有权限的账号；不要把 token 写进 URL：

```powershell
gh auth login --web --hostname github.com --git-protocol https
New-Item -ItemType Directory -Force C:\src | Out-Null
gh repo clone shanheshile/yiyunying-platform-source C:\src\yiyunying-platform-source
Set-Location C:\src\yiyunying-platform-source
git fetch --all --tags --prune
git switch --detach v2.7.13-debug
git status --short --branch
Get-Content .\android\version.properties
```

期望版本为 `2.7.13/58`。Git 仓库不包含被 `.gitignore` 排除的 `releases/2.7.13` APK；二进制制品从 E 盘最终归档取得。

### 7.2 离线恢复

旧电脑 E 盘最终归档目录为 `E:\YiyunyingArchive\2026-08-10`。读取 ZIP 旁同名 `.sha256.txt`，先校验外层 ZIP，再解压并校验包内 `SHA256SUMS.txt`；随后用包内 Git bundle 克隆。盘符是旧电脑位置，新电脑应复制到自己的安全路径。

最终归档包含：

- 本文件；
- 2.7.13 四端 APK、发布清单和校验和；
- 完整 Git bundle；
- 对应冻结提交的源码 ZIP；
- 测试/部署摘要和顶层 SHA-256 清单。

明确排除：`.env`、服务器/数据库密码、应用/平台 key、SSH 私钥、keystore、生产数据库、用户上传、Codex 会话、`node_modules`、Gradle/Android 缓存和构建目录。

### 7.3 新机依赖与验证

- Git、GitHub CLI、PowerShell、JDK 17、Android SDK/Build Tools 36、PHP 8.1+（建议与 CI 一致使用 8.3）、MySQL 8、Node 22.13+、pnpm。
- 优先使用短 ASCII 路径，例如 `C:\src\yiyunying-platform-source`。

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\verify.ps1 -SkipAndroid
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\android\tools\verify.ps1 -JavaHome $env:JAVA_HOME
```

自动化通过不等于生产、真机或资金安全验收。

## 8. 绝对不要再踩的坑

1. 不要在工作区根目录、旧 ZIP、发布副本或历史归档上改代码；只在规范 Git 仓库工作。
2. 不要无参数运行 `android/tools/release.ps1`：默认 `Bump=patch`，会先改版本，产物仍是 Debug；封存版本也不要用同版本非 DryRun 覆盖。
3. 不要复用 `versionCode=58` 发布不同 APK。Android 更新判断要求新版本单调递增。
4. 不要把“有页面/按钮/接口”写成“已生产完成”；按源码、自动化、集成、真机、生产分别记录证据。
5. 不要把 Debug APK 称为正式 Release，也不要把 Debug keystore 提交、公开或放进普通交接包。
6. 不要只更新下载页面；APK、后端、数据库迁移、四条策略和生命周期 4/4 回读必须作为一个发布批次。
7. 不要覆盖已公开的同版本目录。四包必须先全部暂存、校验，再一次性公开；策略必须用数据库事务。
8. 不要更改 2026-08-10 四个迁移的依赖顺序，也不要在无可恢复数据库备份时执行迁移。
9. 不要把付费/定时媒体放在公共永久 URL，不要相信客户端 metadata 作为服务端授权依据。
10. 不要因管理员关闭功能而把旧草稿中的受保护正文/附件静默改成公开；不支持的策略必须阻止发布并要求明确转换。
11. 不要删除已售内容、仍被内容引用的上传或旧购买记录；购买权益具有不可破坏性。
12. 不要在交接、Git、命令输出或普通 ZIP 中写真实密码、token、应用 key、平台 key、数据库内容或签名密钥。
13. 不要运行 `package-project.ps1 -AllowDirty` 归档未提交源码；它基于 `git archive HEAD`，会漏掉 dirty 和未跟踪实现。
14. 不要删除旧归档。新增归档必须使用新目录/文件名、生成外层 SHA-256 并做解压恢复演练。
15. 不要把旧电脑的大型 Codex JSONL 会话重新导入新 Codex。它们可能包含敏感信息，也会放大索引和内存压力。

## 9. Codex 意外停止的已知原因与防复发

2026-08-04 的 Windows 事件记录显示 `codex.exe` 虚拟内存增长到约 31.47 GB 后以 `0xc0000409` 崩溃；历史会话中存在多个同时 `inProgress` 的重叠回合，连续发送“继续”会放大自动恢复和目录扫描压力。项目工作区曾包含大量发布包、缓存和构建目录，也会放大问题。

- 一次只保留一个活动回合；等待或中断后先确认状态，不要连续叠加“继续”。
- 只打开 `github-source`，排除 release 归档、`node_modules`、`.gradle`、`build`、Android SDK 和大目录扫描。
- Android 构建使用 `--no-daemon --no-parallel --max-workers=1`，不要与 Codex 全盘扫描并行。
- 长任务及时写本文件/checkpoint；若 Codex private/commit 持续超过 4—6 GB，结束任务并完整退出重开。
- 保持 Windows 页面文件为系统管理并留足磁盘；不要在 Codex 运行时直接删除 SQLite/WAL。
- 旧会话归档只用于经批准的离线取证，默认永不导回 Codex UI。

## 10. 最终口径

准确表述是：

> 易运盈 2.7.13（58）四端 Debug 测试更新已完成源码、自动化、生产迁移、APK 原子部署和客户端更新链路 4/4 验证；项目已冻结归档。它不是正式商用 Release，HTTPS、正式签名、凭据轮换、真机矩阵、资金/权限、通话/推送和恢复演练仍是明确限制。
