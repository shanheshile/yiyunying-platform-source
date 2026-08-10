# 易云后台（产品名：易运盈）交接文档

> 写给一台完全没有项目上下文的新电脑上的新 AI/新会话。先读本文件，再读 `docs/CURRENT_STATUS.md` 和 `docs/releases/2.7.14.md`。
>
> 状态时间：2026-08-11（Asia/Shanghai）。2.7.14 Debug 测试版已经上线并完成 Git 标签与 E 盘非覆盖归档；真机验收仍未完成。

## 0. 一句话结论

`2.7.14 (59)` 四端非强制 Debug 测试更新已经上线：后端、审核迁移、四包、更新策略、静态下载中心及独立回读通过；最终源码以注释标签 `v2.7.14-debug` 固定，并已复制到 E 盘全新归档且完成恢复演练。它仍是 Debug 签名且使用 HTTP，Android 真机覆盖安装尚未验收，不能称为正式商用 Release。

## 1. 项目身份与唯一源码

- 工作区名称：易云后台。
- 产品/UI 名称：易运盈。
- GitHub 仓库：`shanheshile/yiyunying-platform-source`。
- 旧电脑规范源码目录：`C:\Users\Administrator\Documents\易云后台\github-source`。
- 唯一规范源码是上面的 Git 仓库；工作区根目录、旧 ZIP、发布副本、历史解压目录和 Codex 会话都不是源码来源。
- 当前源码版本：Android `2.7.14`，`versionCode=59`。
- 当前线上版本：`2.7.14`，`versionCode=59`，四端非强制 Debug 测试更新。
- Android 四端包名：
  - 平台总控：`xyz.jjmxg.yiyunying.platformowner.debug`
  - 授权平台：`xyz.jjmxg.yiyunying.authorized.debug`
  - 管理员：`xyz.jjmxg.yiyunying.admin.debug`
  - 用户：`xyz.jjmxg.yiyunying.user.debug`

已推送的源码实现提交为 `8e8d5de7bdad09ef672d038fba57cab1565720e1`，远端部署代码哈希与本地一致；最终部署证据提交由注释标签 `v2.7.14-debug` 固定。精确最终提交 SHA、归档目录和外层 ZIP SHA-256 记录在 E 盘归档旁的 `RELEASE_STATE.md` 与顶层 manifest，不在本文件内硬编码会自我变化的值。接手时先运行 `git status --short --branch`，不要从旧归档反向覆盖规范源码。

## 2. 我们在做什么

最后一轮任务是把用户提出的管理审核、聊天展示、弹层、拍摄焦点、媒体堆叠、GIF、评论 UI、操作图标和应用更新链路做成最小可测试闭环，并发布新的 `2.7.14 (59)` 安装版本。

本轮最重要的新增发布目标是：安装包下载中断后继续上次进度；设置里可查看、继续、安装和删除历史安装包；用户可以选择安装成功后自动删除；安装授权、校验、并发和结果识别都不能造成重复下载或误删文件。

## 3. 已经完成什么

### 3.1 管理审核、权限与弹层

- 管理端新增真正落到后端路由、权限服务、审核状态和审计数据的内容审核能力，不再只是静态页面或按钮。
- 动态、帖子、评论等审核对象有统一的状态与合同检查；功能入口继续接受管理员策略，关键授权由服务端复核。
- 用户端和管理端二维码分享弹层统一修复主题字体颜色。
- 共用弹层将固定标题区与可滚动内容区隔离，修复内容穿透、标题重叠和圆角区域漏出。

### 3.2 聊天昵称、撤回与操作图标

- 修复聊天中自己或他人昵称偶发显示为 `user` 的问题，展示名按已有资料稳定降级。
- 撤回消息严格按“备注优先，其次昵称，最后账号”显示。
- 删除、收藏、转发、点赞、评论、回复等常用操作完成全量检查和图标化替换，并保留可访问性描述及安全点击区域。

### 3.3 相机焦点、媒体堆叠与 GIF

- 软件内 Camera2 拍摄增加可见焦点框：点按聚焦、拖动焦点、长按锁焦，焦点状态和移动边界由独立控制器维护。
- 保留轻触拍照、长按录像最长 60 秒、系统原生相机和完整相册等既有入口。
- 图片和视频分别堆叠；聊天与帖子/评论复用层级动画规则。上层滑到底层、下一层或上一层时，动画与错开显示区域边界同步；反向切换同理。
- GIF 支持查看，并提供重播与循环观看控制。

### 3.4 评论 UI 与媒体

- 修复动态评论顶部未对齐、圆角外漏出其他评论和标题/内容滚动重叠。
- 评论回复继续归入所属根评论内部，预览、展开/收起和媒体区域更紧凑。
- 图片/视频分别堆叠；音频保留可拖动进度、错误恢复、资源释放和重试。

### 3.5 断点续传、历史安装包与自动清理

- 下载请求支持 Range、If-Range、ETag 和 Last-Modified。`206` 必须精确匹配 Content-Range 才追加；`200` 安全重建；`416` 只在本地完整并符合预期时进入完整校验，否则清理后重试。
- `.part` 文件、已下载字节、服务端校验标识和任务状态持久化；异常退出后可继续，页面切换或重复点击不会启动多个同包下载。
- 下载完成后严格校验包名、目标版本、精确大小、SHA-256 和签名关系，验证前不安装。
- 设置页新增安装包历史、空间摘要和“安装完成后自动删除”开关。可继续下载/安装、单个删除和批量删除；活跃任务先暂停后再确认删除。
- 自动删除默认关闭，只在系统确认安装完成后清理；下载、校验、授权或安装失败时保留包，避免无法恢复。
- 未知来源安装授权返回后可继续；安装请求与安装完成状态持久化，应用冷启动和替换广播均可对账。
- HTTPS 跳转禁止降级到 HTTP；Release 构建禁止 HTTP，Debug 仅为兼容当前测试链路保留受控 HTTP。
- 后端更新元数据缺少包名、大小或 SHA-256 时失败关闭，不向客户端提供不可可靠验证的更新。

## 4. 自动化、制品与生产证据

- Android：226 项 Gradle 任务全部执行通过；四个 variant 各 75 个 suites、260 项 tests，均 0 failure、0 skipped，合计 1,040 项 tests；四端 Lint、Debug 构建及包身份检查通过。
- 后端 `backend/tools/check.ps1`：189 个 PHP 文件、215 张表、769 条路由、413 个文档端点及全部合同检查通过。
- 下载站：Lint `0 error / 4 warning`；测试 `2/2`；静态导出通过。4 条 warning 是既有 `<img>` 性能提示，不是构建错误。
- 四包均为 `2.7.14`、`versionCode=59`，文件事实来自 `releases/2.7.14/release-manifest.json`。

| 端 | 文件 | 字节 | SHA-256 |
| --- | --- | ---: | --- |
| 用户 | `yiyunying-user-v2.7.14-debug.apk` | 96,596,676 | `B7466E8C62C28D4E226F0C6B276772B2175A6598C227A8CF9EAA52CAE236E9F3` |
| 管理员 | `yiyunying-admin-v2.7.14-debug.apk` | 32,211,464 | `50286A7802F82DFC595D3B77DDB8DAF76E5C18B0957B94AFFF7B56C71B329ED4` |
| 授权平台 | `yiyunying-authorized-platform-v2.7.14-debug.apk` | 32,211,468 | `737B533B167333DAEC9F11670E3D6CCC456560DFB83EC47FA645E574E5E9A226` |
| 平台总控 | `yiyunying-platform-owner-v2.7.14-debug.apk` | 32,211,464 | `BD9F6024F9AA62081985FEC62260C493CC7BA6911C98E31128F406CA7B198760` |

四包证书 SHA-256：

`10162EBB7147EA0823C281D9F86FEFF2A353984A41497F17E196E50614E9B76E`

它是与历史 Debug 安装连续的候选证书，不是受保护的正式生产 Release 证书。

### 4.1 生产部署与回读

- 后端和 `upgrade_20260811_content_moderation_closure.sql` 已部署；实际结构为 9 列、2 索引、2 外键、0 个缺失设置、1 条迁移记录。远端代码哈希与本地一致，健康状态为 `ok / database connected`。
- 第一次部署因索引行值校验兼容误判安全停止：代码成功回滚，数据库迁移已经完整生效，59 策略没有激活。修正校验后幂等重跑成功。这是正确的失败关闭，不要尝试撤销已成功的幂等结构迁移。
- 四包已公开到 `/downloads/2.7.14/`；服务器完整 SHA-256、MIME、Content-Length、ETag 和 Range 206 均 4/4 通过。
- 本机独立生命周期八项回读通过：四端 `58 -> 59 available=true`，四端 `59 -> available=false`。
- 静态下载中心显示 2.7.14，四条下载链接通过。

## 5. 当前卡在哪里 / 尚未完成什么

- 尚未在已安装 2.7.13 的 Android 真机验证断点续传、暂停恢复、未知来源授权、覆盖安装、手动删除和自动清理。
- 真机未验不影响已经完成的服务器与公网回读，但严禁把两者混写成同一项验收。

### 5.1 已完成的冻结与归档

- 已推送源码实现提交 `8e8d5de7bdad09ef672d038fba57cab1565720e1`；部署证据文档另形成最终提交，并由注释标签 `v2.7.14-debug` 固定。
- E 盘新建 `E:\YiyunyingArchive\yiyunying-v2.7.14-debug-final-<时间戳>\` 及同名 ZIP，没有覆盖或删除任何历史归档。
- 归档包含四个被 Git 忽略的 APK、两份发布清单、源码 ZIP、仅含 `main` 与固定标签的完整 Git bundle、两份一致的 HANDOFF、测试/部署证据、`RELEASE_STATE.md` 和顶层逐文件哈希清单；已完成解压、逐文件哈希及从 bundle 克隆/检出标签的恢复演练。
- 新电脑必须以归档旁 `RELEASE_STATE.md`、顶层 JSON manifest 及其 sidecar SHA 为实际目录、精确提交和校验值依据，不要猜测时间戳。

### 5.2 正式商用仍受阻

- 四包都是 `.debug` 包和 Debug 证书；没有正式 release signingConfig、生产密钥托管、跨版本覆盖/回滚和防降级证明。
- 当前生产 API 与 APK 下载链路仍使用 HTTP；正式发布必须完成 HTTPS 主机名、信任链和强制跳转。
- 沟通过程中暴露过部署凭据，必须视为泄露并轮换；任何真实密码、token、数据库数据、应用 key、平台 key 或签名密钥都不得进入 Git、文档、命令输出或普通归档。
- Android 14/15/16 多品牌真机、弱网、输入法、后台限制、通知、锁屏来电、拍摄、媒体和通话未形成正式验收矩阵。
- 资金双重记账、并发幂等、退款/对账，四级 RBAC/ABAC 越权矩阵，以及 TURN/STUN、推送、地图、对象存储、AI/语音、支付回调、监控告警和恢复演练仍需独立生产证明。

## 6. 生产恢复点与 2.7.13 历史

必须保留 2.7.13，不删除历史：

- 2.7.14 真正迁移前：`/www/backup/yiyunying/20260811-044109-pre-2.7.14-debug`。
- 2.7.14 成功部署前第二份：`/www/backup/yiyunying/20260811-044605-pre-2.7.14-debug`。
- 2.7.14 APK 和更新策略：`/www/backup/yiyunying/20260811-044801-android-2.7.14-debug`。
- 2.7.14 静态站：`/www/backup/yiyunying/download-center/20260811-045152-pre-2.7.14-static`。

- 生产代码、数据库和 `.env` 恢复点：`/www/backup/yiyunying/20260810-221916-pre-2.7.13-debug`。
- APK 和更新策略恢复点：`/www/backup/yiyunying/20260810-223329-android-2.7.13-debug`。
- 2.7.13 四端 APK 已在 `/downloads/2.7.13/` 原子公开；四条策略均为非强制更新。
- 历史回读证据：四端 `57 -> 58` 可更新，`58 -> 58` 无更新，服务器内与公网均 4/4 通过。
- 后续发布失败时，按发布脚本的事务和目录补偿选择正确恢复点；不要删除或覆盖任何 2.7.13/2.7.14 恢复点。

## 7. 下一步计划

1. 在至少一台已有 2.7.13 的真机完成更新最小闭环，记录设备、Android 版本、网络中断点、安装授权、覆盖结果和清理结果。
2. 轮换沟通过程中暴露过的部署凭据，保留不含秘密值的轮换记录。
3. 若继续走正式商用路线，先完成独立 Release 签名、HTTPS、真机矩阵和生产安全验收，再使用全新且单调递增的 `versionCode`；不得覆盖 59。

## 8. 全新电脑如何接手

### 8.1 在线源码

仓库可能是受限仓库。使用具有权限的账号进行网页授权，不要把 token 写入 URL：

```powershell
gh auth login --web --hostname github.com --git-protocol https
New-Item -ItemType Directory -Force C:\src | Out-Null
gh repo clone shanheshile/yiyunying-platform-source C:\src\yiyunying-platform-source
Set-Location C:\src\yiyunying-platform-source
git fetch --all --tags --prune
git cat-file -e 'v2.7.14-debug^{commit}'
git switch --detach v2.7.14-debug
git status --short --branch
Get-Content .\android\version.properties
```

以 `v2.7.14-debug` 指向的提交为最终源码与部署证据锚点；不要直接检出会继续变化的未来 `main` 来复现 2.7.14。

### 8.2 离线归档恢复

- 在 E 盘 `YiyunyingArchive` 中选择 `yiyunying-v2.7.14-debug-final-<时间戳>`，先读同级/包内 `RELEASE_STATE.md` 与顶层 manifest。
- 先校验外层 ZIP、manifest sidecar、归档内逐文件 SHA-256，再用归档内 Git bundle 克隆新目录并检出 `v2.7.14-debug`；恢复后的 `HEAD`、标签提交和 `RELEASE_STATE.md` 必须一致，工作树必须干净。
- Git clone 只恢复源码和文档，不包含被 `.gitignore` 排除的 `releases/2.7.14/`；四个 APK 与两份清单必须从该 E 盘归档取得并复算哈希。
- 不要把 E 盘路径当作跨电脑固定盘符；迁移到新介质后仍按 manifest 和 SHA-256 识别，不按文件名猜测。

### 8.3 依赖与验证

- Git、GitHub CLI、PowerShell、JDK 17、Android SDK/Build Tools 36、PHP 8.1+（建议与 CI 一致使用 8.3）、MySQL 8、Node 22.13+、pnpm。
- 优先使用短 ASCII 路径，例如 `C:\src\yiyunying-platform-source`。
- Android 构建统一使用 `--no-daemon --no-parallel --max-workers=1`，不要并行启动多个 Gradle。

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\verify.ps1 -SkipAndroid
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\android\tools\verify.ps1 -JavaHome $env:JAVA_HOME
```

自动化通过不等于部署、真机、资金或正式商用验收。

## 9. 绝对不要再踩的坑

1. 不要在工作区根目录、旧 ZIP、发布副本或历史归档上改代码；只在规范 Git 仓库工作。
2. 不要把“线上 Debug 测试部署”写成“正式商用完成”；版本、迁移、策略、APK、公网回读和真机证据必须分别记录。
3. 不要复用 `versionCode=58` 或 `59` 发布不同 APK；已公开版本必须单调递增且不可覆盖。
4. 不要无参数运行 `android/tools/release.ps1`；必须显式 `-Bump none`，并传入发布脚本要求的签名指纹与生产配置。
5. 不要把 Debug APK 称为正式 Release，也不要提交或公开 keystore。
6. 不要只更新下载页面或只上传一个包；后端、数据库迁移、四包、四条策略和生命周期回读必须作为原子批次。
7. 不要在无完整数据库备份和回读计划时执行迁移；2026-08-11 审核迁移已经生效，不要手工撤销、重复改写或破坏其幂等性。
8. 不要覆盖 `/downloads/2.7.13/` 或删除其恢复点；新包先全部隐藏暂存、校验，再一次性公开。
9. 不要接受缺少包名、大小、SHA-256 或签名不连续的更新元数据；不要允许 HTTPS 跳转降级到 HTTP。
10. 不要把下载完成等同于安装成功；只有替换广播或已安装版本对账确认后才能自动删除 APK。
11. 不要在安装授权取消、安装失败、校验失败或应用异常退出时删除 `.part`/APK；保留可恢复状态并给用户明确操作。
12. 不要只用文件是否存在判断任务完成；同时核对状态、预期长度、完整 SHA-256、包名、版本和签名。
13. 不要因管理员关闭功能而静默公开受保护内容，也不要删除已售内容、仍被引用的上传或购买记录。
14. 不要把真实密码、token、应用/平台 key、数据库内容、私钥或签名密钥写入 HANDOFF、Git、普通 ZIP、命令行或聊天回复。
15. 不要运行 `package-project.ps1 -AllowDirty` 归档未提交源码；它基于 `git archive HEAD`，会漏掉 dirty 和未跟踪实现。
16. 不要删除旧归档。新归档必须用新目录/文件名、生成外层 SHA-256，并做解压和 Git bundle 恢复演练。
17. 不要连续叠加“继续”或同时启动多个长任务；这台电脑曾因 Codex 内存增长和重叠回合意外停止。

## 10. Codex 意外停止的防复发

- 一次只保留一个活动回合；等待或中断后先确认状态。
- 只打开 `github-source`，排除 `releases` 二进制、`node_modules`、`.gradle`、`build`、Android SDK 和大归档扫描。
- Android 构建使用单 daemon/单 worker策略，不与全盘扫描并行。
- 长任务及时更新本文件或 checkpoint；内存持续异常增长时结束任务并完整退出重开。
- 不要在 Codex 运行时直接移动/删除其 SQLite、WAL 或会话目录；高风险迁移遵循盘点、非破坏预复制、停机最终同步、逐项验证。

## 11. 当前准确口径

> 易运盈 2.7.14（59）四端非强制 Debug 测试更新已完成后端、审核迁移、APK/策略、静态下载中心部署和生产/公网回读；审核、聊天展示、二维码与弹层、相机焦点、媒体堆叠/GIF、评论操作及可恢复更新链路已通过自动化和制品校验。最终源码已由 `v2.7.14-debug` 固定，并完成 E 盘非覆盖归档与恢复演练。它仍使用 Debug 签名与 HTTP，真机覆盖安装尚未验收，不能称为正式商用 Release。
