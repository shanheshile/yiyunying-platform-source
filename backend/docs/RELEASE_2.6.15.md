# 易运盈后台 2.6.15 本地 AI 综合知识版

构建日期：2026-07-18  
Android 版本号：25  
交付范围：平台总控、授权平台、管理员、用户端、PHP/MySQL 后端、接口文档、部署脚本。

## 本次完成内容

- 智能机器人改为“实时工具 + 四级知识库 + 本地模型 + 固定问答兜底”的组合架构。
- 本地模型默认面向中文综合问答，覆盖文学、语言、历史、人文、文化、地理、农学、自然科学、数学、工程、哲学、教育、旅行与生活常识。
- 天气问题先走实时天气链路；明确城市名的问题不再依赖设备定位。模型负责解释、比较、连续追问和跨学科归纳。
- 新增 1 级、2 级、admin 和 app 四层知识继承与隔离；用户端仅能问答，不能查看模型地址、密钥或内部提示词。
- 新增知识库管理入口：平台/授权平台可维护全局、平台、管理员和应用范围资料；admin 可维护当前应用资料。
- 新增节日主题策略，可按节日、日期范围或常驻策略远程下发；客户端在命中日期时加载对应主题，不需要重新发布 APK。
- 保留应用内浏览器、远程更新元数据、更新公告和版本策略，链接可优先在软件内打开。
- 同步安卓 `api_catalog.json` 为 664 条接口，并将模块契约测试基准更新为 664 条，防止前后端入口漂移。

## 数据库与接口规模

- MySQL 表：190 张。
- API 路由：664 条。
- 文档化端点：398 个，所有路由均有文档覆盖。
- 主要新增数据库对象：`ai_knowledge_documents`、`ai_conversations`、`ai_messages`、`festival_theme_policies`。

## 本地 AI 部署

服务器需要安装 Ollama，并在后端目录执行：

```bash
chmod +x deploy/local-ai/install-ollama.sh deploy/local-ai/verify-environment.sh
sudo ./deploy/local-ai/install-ollama.sh
AI_MODEL=qwen2.5:3b ./deploy/local-ai/verify-environment.sh
```

默认仅监听本机 `127.0.0.1:11434`，不对公网暴露模型服务。内存不足时安装脚本会选择较小模型；可通过 `AI_MODEL` 变更模型而不用重新打 APK。

详细知识维护规则见 `docs/AI_KNOWLEDGE_GUIDE.md`，服务器部署规则见 `deploy/local-ai/README.md` 与 `deploy/DEPLOY.md`。

## 升级顺序

1. 备份数据库和现有后端目录。
2. 上传新的 `yiyunying-backend`，将站点根目录指向其 `public` 目录。
3. 按 `database/MIGRATION_ORDER.md` 执行尚未执行的升级 SQL；全新安装直接执行 `database/install.sql`。
4. 在 PHP-FPM 环境配置 AI 相关环境变量，并重载 PHP-FPM。
5. 安装并验证本地 Ollama，再在平台端录入或导入知识资料。
6. 安装相应身份的 Android APK；首次登录后确认 API 地址、更新策略、通知权限、定位权限和后台运行权限。

## 已验证

- PHP 语法检查：139 个 PHP 文件通过。
- 数据库/路由/接口文档/PowerShell 静态检查：通过。
- Android 平台总控、授权平台、管理员、用户端：均已编译并生成 Debug APK。
- Android 四个变体单元测试：通过。
- 用户端单元测试共 60 项通过，包含接口契约、模块角色边界、天气问题分类、通话时钟、聊天视口与近期照片策略等。

## 需要真实环境验收的项目

WebRTC 音视频、系统推送、锁屏来电、通知栏操作、悬浮窗、设备相册/相机、定位和本地模型响应依赖真实 Android 设备、网络、TURN 服务、推送通道及生产服务器配置。本交付不把这些运行环境结果伪装为本机自动化测试结果；部署后应使用两个账号、两台设备做联机验收。
