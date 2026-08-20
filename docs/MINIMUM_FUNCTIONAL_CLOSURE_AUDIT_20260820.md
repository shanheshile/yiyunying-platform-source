# 易运盈最小功能闭环对照审计（2026-08-20）

> 审计对象：`D:\易运盈\github-source` 当前工作树中的原生 Android（Java）客户端与 PHP/MySQL 后端。
> 审计目的：吸收用户附件及[共享对照页](https://chatgpt.com/share/6a86e5ab-7980-83ea-a45a-8985cf7e7a82)的检查方法，但不把另一个 iApp/Lua/WebView 项目的缺陷机械套用到本项目。
> 变更边界：本文件记录源码与本轮本地/只读生产证据；不代表 APK Finalize、tag、正式 APK 部署或真机验收。冻结提交和推送完成后仍须回读提交身份。

## 1. 结论先行

1. 当前项目是 `ANDROID_NATIVE`，不是 iApp/Lua/WebView。附件中的“WebView 不接管 APK”“两套 iApp token”“localStorage 随机设备号”“网页内通知轮询”等描述不能直接当成当前缺陷。
2. 注册动态字段、原生 APK 下载校验、论坛点赞、好友核心生命周期、服务端用户功能门禁已经有较完整的源码实现；但“有实现”不等于完成 MySQL 集成、真机或生产验收。
3. 当前最明确的产品/实现缺口是：
   - 启动与恢复场景下的强制更新尚未形成真正的 fail-closed 全入口门禁；网络失败仍可放行，服务端也没有统一的最低客户端版本写入门禁。
   - Android“我的订单”没有按 `order_source + order_id` 打开统一详情/物流事件，字段仍使用旧 `pay_amount`；全仓未找到面向 `shop_orders` 的完整后台发货/虚拟交付闭环。
   - 普通投票和群投票在 `allow_change=false` 且已投票时仍显示“修改投票”，后端随后以 409 拒绝，前后端状态语义不一致。
   - 聊天是 REST 定时轮询与只读缓存降级，不是 WebSocket/SSE 实时通道；不得宣传为“实时推送”。
4. 本轮已把 Android 连接身份做成 `hosted`/`self_host` 双轨：官方托管只允许唯一 `https://appht.jjmxg.xyz/`；源码买断必须注入自己的地址并拒绝官方域名，HTTP 还需第二次显式 opt-in。自建多线路只对 GET/HEAD 在连接类异常或 502/503/504 时切换，24 项定向 JVM/MockWebServer 测试通过。该实现属于提交 B 的源码能力，不追溯冒充已从提交 A 构建的 code66 官方候选 APK。
5. 当前 `releases/1.0.0/release-manifest.json` 仍是 `finalizationStatus=pending`、设备计划为 `risk-waiver`；`HANDOFF.md` 明确没有 Finalize、部署、公开发布或真机通过。因此本审计不能把 code66 写成正式上线或设备验收通过。
6. 本轮还复现并修复两个会阻断最小闭环的后端缺陷：安装 SQL 排序规则混用触发 MariaDB 1267；维护模式上下文引用 `admins` 表不存在的 `deleted_at`，使注册等写请求错误返回 503。全新隔离 MariaDB + PHP HTTP 四套 smoke 已通过，共 185 项检查。

## 2. 对照方法与证据等级

共享对照页在本次审计时可访问。它提供的有效方法是：先确认平台类型、让配置/API/UI/测试共享同一事实源、把“写了代码”和“真实闭环”分开、Android 正式包默认拒绝明文 HTTP。该页面是另一个项目的设计讨论，只能作为检查框架，不能证明易运盈仓库的任何功能。

本文件使用以下证据等级：

| 等级 | 能证明什么 | 不能证明什么 |
|---|---|---|
| 源码/静态 | 路由、字段、分支与失败策略存在，静态合同满足预期 | MySQL 真实事务、设备系统行为、线上配置 |
| Build/JVM | 指定变体可编译或纯 JVM 策略测试通过 | Android 安装器、权限页、通知栏、OEM 后台行为 |
| 集成 | 客户端请求与 PHP/MySQL 在隔离环境真实交互 | 正式服务器数据、证书、反代、生产权限 |
| Device | 指定 APK 在指定真机完成安装、升级、登录与交互 | 其他机型/OEM、生产部署已完成 |
| Production | 对正式域名、正式配置、正式数据库做了经授权的回读 | 未执行的写操作或未覆盖的用户路径 |

判定词含义：

- **已闭环（实现/静态）**：附件对应缺陷在当前技术栈中已有明确实现和本地证据；仍可附带更高层验收边界。
- **部分闭环**：主路径存在，但入口、错误路径、语义或测试矩阵不完整。
- **仍需真机/生产验收**：代码层没有发现明确缺口，但系统或生产行为不能靠静态测试替代。
- **真实缺口**：当前实现与明确需求直接冲突，或闭环中缺少必要操作。
- **本轮实现待主线验收**：并行工作树正在修复；尚未以冻结 diff、回归测试和制品扫描确认，不能记作已闭环。

## 3. 官方托管、自建买断、HTTP/HTTPS 与多线路

### 3.1 已核事实（审计基线）

- `android/app/build.gradle` 可由 Gradle 参数或环境变量注入 `YIYUNYING_API_BASE_URL`、App Key、Platform Key 和 Authorized Platform Key；调试默认值是隔离模拟器地址 `http://10.0.2.2:8788/`。
- 原基线 `release` 设置 `ALLOW_HTTP_ENDPOINTS=false`；主网络安全配置拒绝明文流量，debug overlay 才允许 HTTP。`EndpointPolicy` 还拒绝非法 scheme、userinfo、query 和 fragment。
- 原基线 `legacyCompat` 硬编码 `https://appht.jjmxg.xyz/` 且禁止 HTTP，它只服务官方旧 Debug 包兼容升级，不应作为买断方自建模板。
- `SessionManager.reconcileEditionIdentity()` 会把地址和租户身份校正回 BuildConfig，并在构建身份变化时清理旧会话。原基线没有用户可编辑的服务器地址，也没有有序 Base URL 列表。
- `ApiClient` 的相对路径解析保持同 scheme/host/port，不能用服务端返回的相对 URL 静默切到另一主机。
- `android/tools/release.ps1` 要求 Stable 使用规范 HTTPS，拒绝 loopback、`localhost`、`10.0.2.2`、占位值和带凭据 URL，并回读生成的 BuildConfig。现有脚本能限制“HTTPS Stable”，但原基线没有 `OFFICIAL_HOSTED` profile 来强制主机必须逐字等于 `https://appht.jjmxg.xyz/`。
- 当前 pending 清单与发布说明记录官方 API 为 `https://appht.jjmxg.xyz/`。这是候选制品元数据证据，不是线上配置或真机证据。
- 原基线可通过注入不同 URL/key 构建自建 HTTPS 包，但没有强制拒绝买断包复用官方域名、官方 key hash、官方签名/包名、官方数据库和官方更新通道。
- 原基线正式 Release 不支持自建 HTTP；只有 debug 允许。因此“买断自建正式包可选 HTTP/HTTPS”在原基线未实现。
- 原基线只有一个 `DEFAULT_API_BASE_URL`，没有优先级、同部署身份校验、线路切换、失败退避或“全部线路失败”状态机。

### 3.2 两条正式轨道应满足的不可变条件

| 项目 | 官方托管 `OFFICIAL_HOSTED` | 源码买断 `SELF_HOSTED` |
|---|---|---|
| API 地址 | 必须逐字绑定 `https://appht.jjmxg.xyz/` | 必须由买断方填写自己的规范 Base URL；不得使用官方域名 |
| 传输 | 只允许 HTTPS，任何 HTTP 都使构建/发布失败 | 支持 HTTPS；如明确选择 HTTP，必须显示不可忽略的明文风险提示并使用隔离网络策略 |
| key/tenant | 官方受控 key 的哈希与租户身份 | 买断方自有 key/tenant；门禁应拒绝官方 key hash |
| Android 身份 | 官方包名、官方 signer、官方更新轨道 | 自有包名/后缀、自有 signer、自有升级轨道；不得伪装或覆盖官方包 |
| 数据 | 官方生产数据库 | 买断方自有数据库，禁止默认指向或迁移官方生产数据 |
| 更新元数据 | 官方同源或官方批准下载源 | 自建下载源和签名链；不得消费官方更新策略 |
| 多线路 | 官方公开构建保持唯一官方 canonical Base URL | 可配置有序列表，但每条线路必须完成“同一部署身份”握手后才可自动切换 |

### 3.3 多线路最小可验证语义

买断自建多线路不能只是“数组里依次试 URL”。至少需要：

1. 配置包含规范 URL、优先级、传输类型和预期 deployment/tenant/app identity；密钥仍不能写入可公开元数据。
2. 首选线路成功时不探测低优先级线路；首选连接失败或达到明确的可切换错误条件后才尝试下一条。
3. 只有服务器返回的部署身份与当前会话完全一致才可保留 token 自动切换；身份不一致必须停止、清会话/缓存并要求重新登录，绝不能跨租户自动 failover。
4. 恢复首选线路的策略、抖动抑制、退避上限和手动重试必须确定化。
5. 全部失败时展示明确的“所有服务器线路不可用”、各线路脱敏诊断和重试入口；不得悄悄回落到官方域名、`localhost` 或示例地址。
6. HTTP 线路只在 `SELF_HOSTED_HTTP_EXPLICIT` 一类显式变体中生效，网络安全配置只放行配置的主机；官方 APK 字节扫描不得出现 HTTP 自建线路或可切换开关。

### 3.4 当前状态与文档风险

- **本地实现已验**：`hosted`/`self_host` profile、HTTP 双重 opt-in、有序 URL 规范化/去重、官方域名隔离和保守的 GET/HEAD 切线已编译并通过 24/24 定向测试；官方、自建 HTTPS 双线路、自建 HTTP+HTTPS 显式允许的非敏感配置回读通过，HTTP 未 opt-in 按预期构建失败。
- **不能扩大结论**：当前最小实现把“构建时明确列出的 TLS/HTTP 端点”视为买断方授权的同一部署，没有做服务端签名式 deployment identity 握手、恢复首选防抖或逐主机 cleartext allowlist；HTTP opt-in 是构建级明文风险。正式多区域部署上线前仍须补齐这些 P1 防护或由购买方在隔离验收中明确接受边界。
- **A/B 制品边界**：现有 code66 官方候选来自提交 A，保持唯一官方 HTTPS 且无需备用线路；本轮自建能力属于提交 B 源码，未来自建制品必须从自己的冻结提交、包名、signer、KEY、数据库与更新通道单独构建和验收。
- **已修正文档边界**：执行性部署说明、Apache/PHP-FPM 示例、STT/通话 smoke 默认值及 API 分享示例已统一使用官方 `https://appht.jjmxg.xyz/`；官方 HTTP 旧值只保留在失败注入、历史兼容/发布证据或明确的拒绝扫描规则中。
- `releases/2.7.15/`、`releases/2.7.5/` 与旧 Android 更新说明中的 HTTP 是历史证据，不应被发布脚本重新消费；历史文件如保留，需明确标成不可执行/已废弃。
- README 与公开接口文档中的自建地址统一使用 `*.example` 保留域，并紧邻标注“不可执行格式示例”；上线配置不得照抄示例值。
- `localhost`、`127.0.0.1`、`10.0.2.2` 只可存在于隔离开发/测试说明和 debug 变体；发布门禁必须证明它们未进入官方和自建正式 APK。

## 4. 最小功能证据表

| 核验项 | 当前判定 | 当前实现证据 | 未闭环边界/真实缺口 |
|---|---|---|---|
| 注册可选字段与配置一致性 | 已闭环（实现/静态）；集成/生产待验 | `RegisterActivity` 从 `/api/public/bootstrap` 读取 `registration_policy`，按 enabled/required 控制昵称、邮箱、手机，并用 `addIfPresent` 省略空值；`IdentityService` 与 `AuthController` 采用同一策略，昵称空值可回落账号 | 缺少 enabled/required 全组合的客户端+MySQL 矩阵；正式环境策略没有在本轮回读 |
| HTTPS/API/发布身份 | 本地最小闭环；制品/真机仍待验 | 官方 exact-host `hosted`、自建 `self_host`、HTTP 双重 opt-in 与 GET/HEAD 保守切线已实现；24/24 定向测试通过；生产域名、DNS、TLS/Nginx root 和健康数据库连接已只读回读 | code66 A 制品不包含 B 的自建源码能力；自建 signer/package/key/data/update channel、签名式线路身份握手、正式自建 APK 与真机仍未验收 |
| 启动/登录强制更新 | 真实缺口 | `LifecycleChecker` 支持维护、`force_update`、最低版本和不可取消更新 UI；登录按钮与 Main 首次打开有调用 | 非手动生命周期请求失败会 `allowOnce` 放行；登录页展示阶段未先检查；恢复 Activity 可绕过新检查；主内容/通知服务可能先初始化；服务端没有最低客户端版本写门禁 |
| APK 下载校验与安装 | 已闭环（策略/JVM）；仍需真机 | 原生 `AppUpdateInstaller` 校验 HTTPS/重定向、大小、SHA-256、包名、版本、当前/轮换 signer；支持断点、FileProvider、未知来源权限恢复、失败重试/退出 | Android 系统安装确认、未知来源权限、`install -r` 升级、登录与数据连续性、安装后版本回读仍是 `pending-user-validation` |
| 服务端权限门禁 | 部分闭环；生产待验 | `AuthService` 校验 token、App Key、租户、用户/应用/上级状态；`RolePermissionService` 定义 35 个用户功能并按路由族返回 403；维护写门禁对状态变更请求 fail-closed | 部分未映射 legacy 路由为兼容继续放行，不能宣称“全部路由统一门禁”；需真实 MySQL 做跨租户/停用/权限关闭矩阵；维护查询的 schema 漂移见第 5 节 |
| 订单详情与交付 | 真实缺口 | 后端有统一列表、`/{order_source}/{order_id}` 详情、source-aware cancel、物流字段与跟踪事件 | Android 仍显示旧 `pay_amount`，未用 `order_source` 打开详情/事件，cancel 仍偏 legacy；全仓未找到 `shop_orders` 后台发货完成路径；虚拟商品缺少购买者专属交付/权益载荷闭环 |
| 设备标识 | 部分闭环；产品定义/真机待验 | `SessionManager.cardDeviceId()` 生成安装级 UUID 并持久化；device secret 走 `SecureValueStore`；后端按 app 存 hash 并校验绑定 | 这不是硬件永久 ID；清数据/卸载会重置。若业务要求“终身设备上限”，必须定义重绑、找回和管理员审计流程；需真机验证升级保留与清数据行为 |
| 通知语义 | 部分闭环；真机待验 | `MessageNotificationService` 是 Android Service，以 REST 拉取 `/api/user/message-center`，创建系统通知、渠道、深链和快速回复，并带失败退避 | 不是 FCM/WebSocket 远程推送，应明确称“前台服务轮询驱动的系统通知”；Android 13 权限、OEM 保活、电池限制、重启后恢复和实际通知栏表现需真机 |
| 论坛点赞 | 已闭环（实现/静态）；集成/真机待验 | `ForumPostActivity` 有帖子和评论点赞入口、liked/count 状态；后端 toggle、详情状态和点赞相关路由存在 | 本轮未做两用户 MySQL 并发/幂等回归，也未真机验证按钮状态、计数和刷新；不能把论坛转发静态合同等同于点赞集成通过 |
| 好友完整操作 | 核心闭环；完整矩阵待验 | 已有搜索/主页/二维码申请、申请列表、接受/拒绝/忽略、好友列表、备注、分组增删改、删除好友、黑名单加入/移出及会话入口 | 缺少一个两用户端到端测试串起所有状态；未找到“撤回已发送的待处理申请”路由，若“完整操作”包含撤回则是待实现项；权限关闭/互相拉黑/重复请求需集成验证 |
| 投票后状态与改票 | 真实缺口 | `UniversalPollService` 与群投票后端均在 `allow_change=0` 时拒绝二次投票，在允许时事务替换选项；详情返回 voted/selected/results 信息 | `PollActivity` 和 `GroupSpaceActivity` 只看 `voted` 就显示“修改投票”，没有同时判断 `allow_change`；群 UI 甚至可一边显示“提交后不可修改”一边提供修改按钮，最终收到 409 |
| 聊天实时与降级 | 部分闭环/语义缺口 | `ChatActivity` 以可配置间隔（基线最小 1 秒）REST 轮询；GET 有按会话与 Base URL 命名空间隔离的只读缓存；失败会继续调度/退避 | 全仓未见 WebSocket/SSE 主通道；离线缓存不能替代消息补偿，发送也没有已证明的持久队列。若要求真正实时，需要主通道+轮询降级；否则必须如实标为“近实时轮询”并做弱网/重连测试 |

## 5. 本轮新发现的后端阻断

### 5.1 安装 SQL 排序规则混用

**事实**：表使用 `utf8mb4_unicode_ci`，但调用方可能在 `SOURCE backend/database/install.sql` 之前以客户端默认 `utf8mb4_general_ci` 注入 bootstrap 会话变量。变量与表列后续做等值比较时，MySQL/MariaDB 可能报 `Illegal mix of collations`（1267），导致全新安装或身份引导中断。

**工作树修正**：`install.sql` 使用 `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`，并把注入变量显式 `CONVERT ... COLLATE utf8mb4_unicode_ci`；`Database.php`/`config/app.php` 同时固定并校验 PDO session charset/collation。

**状态**：**本地隔离闭环通过**。`run-minimum-closure-local.ps1` 以全新 MariaDB 11.4.5 数据目录、SOURCE 前 bootstrap 变量和真实 PHP HTTP 服务执行 core/forum/notifications/chat-commerce 四套 smoke，185 项检查全部通过；证据目录为 `D:\易运盈\.test-evidence\minimum-closure\20260820-195611-b20d3f5c266a4de0aeec2a3527e27346`。这仍不替代正式服务器升级或生产数据验证。

### 5.2 维护上下文引用不存在的 `admins.deleted_at`

**事实**：`LifecycleService::maintenanceContextForApp()` 的查询曾包含 `a.deleted_at IS NULL`，但当前 `admins` 表没有该列。查询会抛 SQL 异常；`MaintenanceWriteGuard` 按设计把未知异常转换为通用 503，所以结果可能是所有状态变更请求都被错误地 fail-closed，而不是正确判断维护策略。

**工作树修正**：查询移除不存在的列，仅保留 `a.status=1`；静态合同增加“只使用当前 admins schema 列”的断言，debug 环境仅记录内部异常而客户端继续收到通用 503。

**状态**：**本地隔离闭环通过**。更新后的 schema 合同通过，且上述全新 MariaDB/PHP 四套 HTTP smoke 已实际完成注册、登录和多类写请求，不再被不存在列误伤；维护命中与未知查询异常仍保持 fail-closed。生产修改与生产写请求未执行。

## 6. 本次已获得的本地与只读生产证据及边界

| 检查 | 结果 | 边界 |
|---|---|---|
| 全新 MariaDB + PHP HTTP 最小闭环 | core/forum/notifications/chat-commerce 全部通过，共 185 checks | 隔离数据库，不是生产写入 |
| Android 多线路定向 JVM/MockWebServer | EndpointPolicy 6、RouteFailoverPolicy 4、ApiClient 14，共 24/24 | userDebug 本地行为，不是 Release APK/真机/真实多机房 |
| 官方/自建配置回读 | hosted、自建 HTTPS 双线路、自建 HTTP+HTTPS opt-in 通过；HTTP 未 opt-in 失败 | 非敏感 Gradle 配置合同，不包含自建签名/key/制品 |
| 私有 APK 部署器 | 28/28，包括五处 `find` 故障注入均 fail-closed 和 8 个真实 APK 审计 | 离线 dry-run，无 SSH、secret、上传、reload 或公网探测 |
| 生产权限 hardener | 21/21；补齐 STT inventory 的生成、校验与事务消费 | 没有在生产 chmod/chown/setfacl/reload；FPM 门禁阻断 apply |
| 生产 STT 模型只读缓存 | tar 147,927,040 bytes；SHA-256 `ac7ece51447408ee9f27aa4317c100741d1caa0d83816ab1eabba73176372dcd`；9 dirs/11 files/4 安全内向 symlinks | 仅复制 models，不含生产写操作；源树 canonical hash 前后相同 |
| 正式域名只读绑定 | `appht.jjmxg.xyz -> 154.12.25.203`；Nginx server_name/root、HTTPS 200 和 `database connected` 已回读 | 不能证明未回读的生命周期/key hash，也不授权生产变更 |

没有执行或不能从上述结果推出：

- 任一 code66 APK 的真实安装、升级、登录、数据连续性、通知和后台保活；
- 买断自建 HTTPS/HTTP 正式制品、独立 signer/package/key/data/update channel 和真实多节点身份一致性；
- 生产 STT 权限收紧：当前 FPM socket 仍为 `0666`，root master、9 个 `www` worker 及其他 `www` 进程使事务前置条件不满足，按设计失败关闭；
- APK Finalize、正式 tag、正式 APK 部署或设备通过。提交 B 与公开站部署必须在完成后另行记录精确回读。

## 7. 建议加入的最小回归测试

### P0：适合立即加入静态/JVM/隔离 DB 回归

1. **部署 profile 合同**：官方 profile 只接受 `https://appht.jjmxg.xyz/` 且只允许一条；自建 profile 拒绝官方域名/key hash/update origin；自建 HTTPS 正例、自建 HTTP 未显式确认反例。
2. **APK 禁止字节扫描**：官方四角色 Release 扫描 HTTP、loopback、示例域、自建线路和可编辑服务器开关；自建包反向扫描官方域名、官方 key hash、官方更新路径和官方 signer/package identity。
3. **多线路 JVM 状态机**：首选成功、首选失败次选成功、身份不一致禁止切换、恢复首选、防抖、全部失败、手动重试、无线路时 fail-closed。
4. **注册策略矩阵**：nickname/email/phone 的 disabled、optional、required 组合；Android 空值省略与后端保存/拒绝结果逐项一致。
5. **生命周期入口合同**：冷启动、登录页首次展示、登录点击、Main 恢复、进程恢复、网络失败、forced update 下载失败；断言业务内容和写 API 不会提前放行。
6. **订单映射合同**：Android 使用 `amount_money/amount_balance`；详情和取消都携带 `order_source + order_id`；物理订单状态机覆盖 paid→fulfilled/tracking，虚拟订单只向购买者返回交付权益。
7. **投票 UI 单测**：`voted=true, allow_change=false` 时禁用选择并显示“已投票，不可修改”；`allow_change=true` 才显示“修改投票”；普通投票和群投票共享同一决策函数。
8. **权限路由覆盖**：对关注功能的全部状态变更路由生成映射清单，任何新增未映射路由使合同失败；显式列出确需 legacy compatibility 的例外。
9. **数据库 schema 合同**：从 `install.sql` 建库后对关键查询做 prepare/execute，防止代码引用不存在的列；特别覆盖 `admins`、维护策略与订单表。
10. **排序规则安装用例**：在 `utf8mb4_general_ci` 客户端会话中先设置 bootstrap 变量再 SOURCE，验证安装成功、查询无 1267、启用身份与禁用占位身份都正确。

### P1：隔离集成/两用户回归

1. 好友 A→B 申请、忽略后再处理、拒绝、接受、备注/分组、拉黑/移出、删除、重复请求；若产品要求则加入撤回待处理申请。
2. 论坛两用户点赞/取消、重复请求、并发计数、列表与详情 liked 状态一致。
3. 强制更新期间对后端状态变更 API 直接请求，确认旧版本被拒绝且错误码/升级元数据一致。
4. 聊天断网→缓存只读→恢复补拉、重复消息去重、乱序、发送失败和重新登录后的缓存隔离；若新增 WebSocket，验证自动降级轮询与恢复主通道。
5. 通知中心已读/未读、系统通知 deep link、快速回复幂等与权限关闭后的明确降级。

### Device/Production：只能作为验收计划，不能本轮假定通过

1. 指定 serial 的官方四角色基线→code66 `install -r`，核对包名、signer、userId/dataDir、登录和数据连续性；安装后回读实际 versionCode。
2. Android 13+ 通知权限、未知来源安装权限、下载中断恢复、强制更新失败重试/退出、OEM 电池限制与重启恢复。
3. 自建 HTTPS 与显式 HTTP 各一台隔离测试服务器：验证风险提示、host allowlist、多线路顺序、身份不一致拒绝和全失败界面；不得连接官方数据。
4. 经授权只读回读官方生产：API origin、TLS、build identity、key hash、更新下载 origin、生命周期策略与数据库身份必须与 pending/final manifest 精确一致。

## 8. 最终放行口径

只有同时满足下列条件，才能把“最小功能闭环”写成正式通过：

1. 冻结提交上完成 Android 官方/自建双轨和多线路 code review，所有新增合同在干净工作树通过；
2. 安装排序规则与维护 schema 缺陷在 MySQL/MariaDB 隔离集成中复现后验证修复；
3. 订单交付、不可改票 UI、强制更新入口这三个真实缺口完成实现与回归；
4. 官方 APK 的 BuildConfig、manifest、签名和下载更新元数据精确绑定 `https://appht.jjmxg.xyz/`，且 APK/文档无可执行 HTTP/localhost/example.com 生产路径；
5. 买断包证明使用自有 server/key/package/signer/data/update channel，HTTP 仅在显式风险确认的隔离 profile 中存在；
6. 真机与生产证据分别留档，`pending-user-validation` 不得改写成 `passed`，风险豁免不得冒充测试结果；
7. 最终的 Finalize、tag、部署与公开回读另行授权并单独记录，不能由本审计文档代替。
