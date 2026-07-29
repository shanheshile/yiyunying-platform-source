# 易运盈本地 AI

机器人采用“确定性工具 -> 租户知识库 -> 本机大模型 -> 本地规则兜底”的顺序。
天气、账号和后台数据不会交给模型猜测；本机大模型负责文学、科学、人文、文化、
地理、农学、哲学、旅行等开放问题，也会结合当前租户知识库与连续会话上下文回答。

## 部署

```bash
cd /www/wwwroot/appht.jjmxg.xyz/yiyunying-backend
chmod +x deploy/local-ai/install-ollama.sh
sudo AI_MODEL=qwen2.5:3b deploy/local-ai/install-ollama.sh
```

脚本会读取服务器可用内存。未手动指定模型且可用内存低于约 3.2 GB 时，
会自动改用 `qwen2.5:1.5b`；同时限制为单并发、单模型驻留，避免挤占
PHP-FPM 和 MySQL。部署结束会执行一次真实问答，而不只是检查端口。

把 `.env.example` 中的 AI 变量加入站点环境变量或 PHP-FPM 环境，然后重载 PHP。
Ollama 只监听 `127.0.0.1:11434`，不要在安全组或 Nginx 中公开该端口。

## 模型选择

- 4 GB 左右可用内存：`qwen2.5:1.5b`
- 6-8 GB 左右可用内存：`qwen2.5:3b`（默认）
- 12 GB 以上可用内存：`qwen2.5:7b`

推理服务离线时，接口会快速熔断并退回租户知识库，不会让 Android 一直等待。

## 手动自检

```bash
AI_MODEL=qwen2.5:3b deploy/local-ai/verify.sh
AI_MODEL=qwen2.5:3b deploy/local-ai/verify-environment.sh
```

第二条命令还会检查 Ollama 服务、模型是否已拉取，以及 PHP 的 `curl`、
`pdo_mysql`、`mbstring`、`json`、`fileinfo` 扩展。宝塔 PHP 不在 PATH 时使用
`PHP_BIN=/www/server/php/82/bin/php` 指定实际版本。

## 安全边界

- Android 只请求易运盈 PHP API，看不到 Ollama 地址和任何模型密钥。
- 1 级平台知识可向自己的下级继承；2 级平台、管理员和应用数据按租户隔离。
- 天气、余额、账号、权限等确定性数据先走业务服务，不让模型编造。
- 模型不可用时优先回答本租户知识库内容，再使用简短离线兜底。
