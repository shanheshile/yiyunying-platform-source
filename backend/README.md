# PHP API

PHP 8.1 + PDO MySQL 后端，提供账号、聊天、群组、论坛、动态、资源、商店、资金、通知、文件、AI、通话信令和管理接口。

```powershell
Copy-Item .env.example .env
php -S 127.0.0.1:8788 -t public public/router.php
```

基础设施配置默认从环境变量读取，`config/app.php` 的默认值仅适合本地开发；唯一例外是一级总控保存后的邮件非敏感配置和认证加密 SMTP 密码。生产环境必须关闭调试并启用 HTTPS、最小权限数据库账号、上传隔离、审计与定时任务。

邮件基础配置可由一级总控安全管理；SMTP 密码使用服务器主密钥进行 AES-256-GCM 认证加密且从不回显。部署、环境回退、测试发送和密钥轮换门禁见 [docs/MAIL_SETTINGS.md](docs/MAIL_SETTINGS.md)。
