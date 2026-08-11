# 易运盈平台源码

易运盈是一套面向 Android 的多角色社区与即时通信平台。本仓库是可交接、可复现构建的完整维护源码，包含 Android 四端、PHP/MySQL API、下载中心以及数据库初始化脚本。

当前本地源码候选已统一为 `2.7.15 (60)`；线上仍是 `2.7.14 (59)` 四端非强制 Debug 测试版。`2.7.15` 尚未构建四个新 APK、部署、创建标签、推送或归档，不能把源码候选写成已发布版本。线上制品仍为 Debug 签名且链路仍使用 HTTP，HTTPS、正式 Release 签名和真机覆盖安装均未完成，因此不是正式商用 Release。

本仓库区分“存在源码入口”和“已经通过生产验收”。页面、按钮或接口存在并不自动代表业务闭环完成；完整产品范围、实施顺序和验收定义以[全量需求与实施总纲](docs/MASTER_REQUIREMENTS_AND_IMPLEMENTATION_PLAN.md)为主，当前能力边界、阻塞项和证据另见[当前状态](docs/CURRENT_STATUS.md)与[需求追踪](docs/REQUIREMENTS_TRACEABILITY.md)。

## 目录

| 目录 | 说明 |
| --- | --- |
| `android/` | Android 原生客户端，包含平台总控、授权平台、管理员、用户四种产品角色 |
| `backend/` | PHP 8.1 + MySQL API、上传、通知、聊天、论坛、动态、订单与管理能力 |
| `download-site/` | React/Next/Vinext 下载中心，可构建为服务端或静态站点 |
| `database/` | 数据库说明；开发初始化脚本位于 `backend/database/install.sql` |
| `docs/` | 架构、本地开发、部署、测试、发布、历史和需求追踪文档 |
| `scripts/` | 一键校验与构建脚本 |

## 四类角色

1. **平台总控（1）**：平台、应用、租户、全局策略和审计的最高管理角色。
2. **授权平台（2）**：在授权范围内管理下级应用、功能和二次开发资源。
3. **管理员（3）**：负责具体应用内的用户、内容、论坛、群聊、订单和审核。
4. **用户（4）**：使用聊天、动态、论坛、悬赏、资源、商店、活动和个人中心。

权限必须在后端复核，Android 前端隐藏按钮不能替代权限校验。管理员查看匿名转发时保留真实审计身份，普通用户只看到匿名化后的快照。

## 主要业务域

- 账号、安全、资料、好友、分组、黑名单、关注和粉丝
- 私聊、群聊、聊天室、客服、机器人、消息通知和系统通知
- 图片、视频、语音、音频、文件、表情包、收藏、转发快照和离线缓存
- 群文件、群相册、投票、接龙、公告、二维码与成员权限
- 动态、笔记、论坛、帖子、评论、悬赏、举报、审核和版主管理
- 资源大厅（应用商店、源码商城）、商店、余额商店、订单与追踪
- 红包、转账、礼物、签到、等级、经验、会员、卡密、抽奖和兑换
- AI 问答、天气、新闻、语音转写、应用内语音/视频通话
- 更新、维护、公告、下载中心、审计、反馈和运行状态

## 2.7.15 本地候选重点

- 管理员端重构为“首页、源码示例、交流、我的”四栏，支持多应用切换、应用内功能入口、源码分类和管理员社区入口。
- 登录页不再展示服务器地址、平台标识或应用标识；这些身份由开发者写入 `BuildConfig`，后端仍按角色校验账号、密码、KEY、应用唯一 ID、时效性 Token 与登录状态。
- 用户端增加私聊、群聊、红包、论坛、短视频和商城的中文快捷入口，并由服务端权限与模块开关失败关闭。
- 审核统一为“通过、不通过、暂定”，覆盖内容、应用商店与资源管理；待审核资源使用私有存储和鉴权下载，禁止未审核文件直接公开。
- 软件内拍照/录像支持变焦和录像中聚焦；拍摄完成先在当前页面固定预览，确认后进入发送，取消后留在拍摄页重拍。
- 论坛评论只直接展示主评论，子回复提供预览、更多、回复关系、只看相关以及时间/热度/综合排序和分页；同步完善群头像、群管理、主题字体、弹窗颜色、状态栏安全区、底部弹层手势和媒体堆叠跟随动画。
- 默认登录凭据改为禁用占位并加入审计；发布链路增加固定主机密钥、备份/回滚、四包身份、完整下载、Range/ETag、策略原子激活与生产回读门禁。

上述内容是本地源码候选能力，不代表真实数据库迁移、真机验收或生产部署已完成。阶段说明见 [2.7.15 本地候选说明](docs/releases/2.7.15.md)。

## 快速开始

### 1. 后端

```powershell
Copy-Item backend/.env.example backend/.env
# 进入交互会话，在同一会话中显式 SET 安全身份变量并 SOURCE install.sql，详见 backend/deploy/DEPLOY.md。
mysql -u root -p
Set-Location backend
php -S 127.0.0.1:8788 -t public public/router.php
```

直接导入只创建不可登录的禁用占位身份，不提供任何默认账号或默认密码。需要本地闭环测试时，
按 [生产部署说明](backend/deploy/DEPLOY.md) 在一次性数据库会话中显式注入测试身份；上线时必须使用独立强口令和随机密钥，
并通过环境变量配置数据库、邮件、推送、地图、AI、支付和 TURN 服务。

### 2. Android

```powershell
Set-Location android
.\gradlew.bat testUserDebugUnitTest assembleUserDebug
```

模拟器默认 API 地址是 `http://10.0.2.2:8788/`。真机或生产构建请使用 Gradle 参数或环境变量：

```powershell
.\gradlew.bat assembleUserDebug -PapiBaseUrl=https://api.example.com/
```

### 3. 下载中心

```powershell
Set-Location download-site
corepack pnpm install --frozen-lockfile
corepack pnpm test
corepack pnpm export:static
```

## 开发前必读

- [项目文档索引](docs/PROJECT_INDEX.md)
- [全量需求与实施总纲](docs/MASTER_REQUIREMENTS_AND_IMPLEMENTATION_PLAN.md)
- [2.7.15 本地候选说明：管理重构、身份链与功能闭环](docs/releases/2.7.15.md)
- [2.7.14 Debug 测试版说明：治理、媒体与可恢复更新](docs/releases/2.7.14.md)
- [2.7.13 Debug 测试版说明：聊天、媒体、资料与论坛收尾](docs/releases/2.7.13.md)
- [2.7.12 发布说明：评论顶起与操作栏裁字修复](docs/releases/2.7.12.md)
- [2.7.11 发布说明：语音评论、评论互动与通知定位](docs/releases/2.7.11.md)
- [2.7.10 发布说明：置顶动态显示、评论预览与更新包复用](docs/releases/2.7.10.md)
- [2.7.9 发布说明：动态权限、评论折叠与原图/GIF 预览](docs/releases/2.7.9.md)
- [2.7.6 发布说明与十一领域验收矩阵](docs/releases/2.7.6.md)
- [2.7.5 发布说明与十一领域验收矩阵](docs/releases/2.7.5.md)
- [2.7.4 发布说明与十一领域验收矩阵](docs/releases/2.7.4.md)
- [2.7.3 发布说明与下一步开发目标](docs/releases/2.7.3.md)
- [架构说明](ARCHITECTURE.md)
- [当前状态与交付边界](docs/CURRENT_STATUS.md)
- [开发历史与版本时间线](docs/history/README.md)
- [源码来源与可追溯性](docs/history/SOURCE_PROVENANCE.md)
- [后续开发路线图](docs/ROADMAP.md)
- [风险登记](docs/RISK_REGISTER.md)
- [本地开发](docs/LOCAL_DEVELOPMENT.md)
- [部署说明](docs/DEPLOYMENT.md)
- [测试说明](docs/TESTING.md)
- [需求追踪](docs/REQUIREMENTS_TRACEABILITY.md)
- [发布检查表](docs/RELEASE_CHECKLIST.md)
- [安全策略](SECURITY.md)

## 外部依赖边界

以下能力已保留配置入口，但必须由部署环境提供有效服务或密钥：TURN、厂商推送、地图 SDK、天气与新闻源、SMTP、支付回调、在线 AI/Ollama、对象存储及可选在线语音识别。Android 16 的锁屏来电、后台保活、悬浮窗和厂商通知策略必须在目标品牌真机上验收。

## 安全说明

本仓库不包含生产数据库快照、服务器密码、私钥、签名文件或线上口令。发现安全问题请私下报告，不要提交包含用户数据的 Issue。详见 [SECURITY.md](SECURITY.md)。

## 许可

本项目为私有商业源码，保留全部权利。未经书面授权不得复制、分发、转售或公开部署。
