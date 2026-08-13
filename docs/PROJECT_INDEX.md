# 项目索引

本文档是易运盈平台源码仓库的导航页。它回答三个问题：当前仓库包含什么、从哪里开始阅读、哪些能力仍需真实环境验收。

## 当前基线

- Android 正式首发候选：`1.0.0`（`versionCode 63`）；新的源码 A、四端 Stable APK 和 pending manifest 尚未生成，当前不能称为已 Build 或已上线
- 内部升级基线：`2.8.0`（`versionCode 62`）已隔离到外层 `.rc/superseded/2.8.0-code62-pending/`，只用于 62→63 设备门禁，禁止 Finalize、tag、部署或公开
- 线上 Debug 测试版：`2.7.14`（`versionCode 59`）；四端非强制更新已上线，58→59 可更新、59→59 无更新
- 客户端形态：用户端、管理员端、授权端、总控端
- 服务端：PHP API、MySQL 数据库、WebSocket/TURN/推送等外部基础设施
- 下载站：正式官网、四角色下载中心、13 个系统接入示例、58 条后端路由与复制/分享/打印/示例格式切换/打开安装指引
- 历史说明：由本机留存源码包、发布清单、校验和及功能归档重建，不伪造旧 Git 提交

## 目录导航

| 路径 | 内容 |
| --- | --- |
| `android/` | Android 四端客户端源码与构建配置 |
| `backend/` | PHP API、业务服务、路由与配置 |
| `database/` | 数据库结构、迁移与初始化资料 |
| `download-site/` | 下载站页面与发布元数据 |
| `scripts/` | 构建、校验、敏感信息扫描与发布辅助脚本 |
| `docs/history/` | 可追溯的开发时间线、来源与归档校验和 |

## 文档导航

- [全量需求与实施总纲](MASTER_REQUIREMENTS_AND_IMPLEMENTATION_PLAN.md)：唯一主需求索引、编号、真实状态、实施顺序与完成定义

- [项目总览](../README.md)
- [1.0.0 正式首发候选说明：新 Build、code63 与 62→63 设备门禁](releases/1.0.0.md)
- [2.8.0 隔离内部基线说明：禁止公开与 Finalize](releases/2.8.0.md)
- [2.7.15 本地候选说明：管理重构、身份链与功能闭环](releases/2.7.15.md)
- [2.7.14 Debug 测试版说明：治理、媒体与可恢复更新](releases/2.7.14.md)
- [2.7.13 Debug 测试版说明：聊天、媒体、资料与论坛收尾](releases/2.7.13.md)
- [2.7.12 发布说明：评论顶起与操作栏裁字修复](releases/2.7.12.md)
- [2.7.11 发布说明：语音评论、评论互动与通知定位](releases/2.7.11.md)
- [2.7.10 发布说明：置顶动态显示、评论预览与更新包复用](releases/2.7.10.md)
- [2.7.9 发布说明：动态权限、评论折叠与原图/GIF 预览](releases/2.7.9.md)
- [2.7.8 发布说明：聊天快照、通话卡片与置顶动态](releases/2.7.8.md)
- [2.7.7 发布说明：置顶动态与评论线程修复](releases/2.7.7.md)
- [2.7.6 发布说明与十一领域验收矩阵](releases/2.7.6.md)
- [2.7.5 发布说明与十一领域验收矩阵](releases/2.7.5.md)
- [2.7.4 发布说明与十一领域验收矩阵](releases/2.7.4.md)
- [2.7.3 发布说明与下一步开发目标](releases/2.7.3.md)
- [架构说明](../ARCHITECTURE.md)
- [当前状态](CURRENT_STATUS.md)
- [需求追踪](REQUIREMENTS_TRACEABILITY.md)
- [风险登记](RISK_REGISTER.md)
- [后续路线图](ROADMAP.md)
- [开发历史](history/DEVELOPMENT_TIMELINE.md)
- [源码来源](history/SOURCE_PROVENANCE.md)
- [归档校验和](history/ARCHIVE_CHECKSUMS.md)
- [本地开发](LOCAL_DEVELOPMENT.md)
- [测试说明](TESTING.md)
- [部署说明](DEPLOYMENT.md)
- [版本完整性与生产发布规范](RELEASE_INTEGRITY.md)
- [发布检查表](RELEASE_CHECKLIST.md)
- [贡献指南](../CONTRIBUTING.md)
- [安全策略](../SECURITY.md)
- [版本记录](../CHANGELOG.md)

## 业务域索引

1. **账号与身份**：注册登录、UID、资料、设备、权限、隐私、等级与会员。
2. **即时通信**：私聊、群聊、聊天室、客服、机器人、通知与消息搜索。
3. **音视频与媒体**：图片、视频、语音、音频、文件、相册、通话与离线缓存。
4. **社区内容**：生活动态、论坛、帖子、评论、悬赏、投票、接龙与标签。
5. **交易权益**：红包、转账、礼物、余额、商品、订单、抽奖、兑换与账单。
6. **资源分发**：应用商店、源码商城、文件审核、分类、版本与下载。
7. **平台治理**：四级角色、审核、举报、封禁、公告、维护、更新与审计。
8. **智能能力**：机器人问答、天气工具、知识检索与语音转写。

## 阅读原则

- “源码入口存在”不等于“生产环境已验收”。
- 1.0.0 尚未形成新 Build 源码 A、APK 或 manifest；固件虚拟化关闭、AVD 无 ADB、未安装 APK。完成 `2.8.0/code62 → 1.0.0/code63` 设备证据前禁止 Finalize、公开部署、打 `v1.0.0` 标签或归档；线上仍为 2.7.14。
- 固定顺序为 A → Stable Build → 62→63 真机门禁 → 证据 B → annotated tag `v1.0.0` → Finalize；精确 A/B 与有效 tag 只按 manifest/Git refs 回读。
- 历史归档用于追溯，当前可发布状态以当前源码、构建结果和发布清单为准。
- 涉及资金、权限、隐私、通话、推送和升级的能力，必须经过服务端、数据库及真机联合验收。
- 文档中的未来方向是规划，不应被解释为已经交付。
- 1.0.0 具备 production signer 与 HTTPS 配置基础，但缺少新的 Stable Build 和真实设备升级验收，仍不能称为正式商业 Release。
