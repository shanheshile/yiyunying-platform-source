# 易运盈平台源码

易运盈是一套面向 Android 的多角色社区与即时通信平台。本仓库是可交接、可复现构建的完整维护源码，包含 Android 四端、PHP/MySQL API、下载中心以及数据库初始化脚本。

当前统一版本：`2.7.2 (47)`。

## 目录

| 目录 | 说明 |
| --- | --- |
| `android/` | Android 原生客户端，包含平台总控、授权平台、管理员、用户四种产品角色 |
| `backend/` | PHP 8.1 + MySQL API、上传、通知、聊天、论坛、动态、订单与管理能力 |
| `download-site/` | React/Next/Vinext 下载中心，可构建为服务端或静态站点 |
| `database/` | 数据库说明；开发初始化脚本位于 `backend/database/install.sql` |
| `docs/` | 架构、本地开发、部署、测试、发布和需求追踪文档 |
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

## 快速开始

### 1. 后端

```powershell
Copy-Item backend/.env.example backend/.env
mysql -u root -p < backend/database/install.sql
Set-Location backend
php -S 127.0.0.1:8788 -t public public/router.php
```

开发初始化数据只用于本地环境。上线前必须删除演示账号、轮换口令并通过环境变量配置数据库、邮件、推送、地图、AI、支付和 TURN 服务。

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

- [架构说明](ARCHITECTURE.md)
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
