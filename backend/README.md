# PHP API

PHP 8.1 + PDO MySQL 后端，提供账号、聊天、群组、论坛、动态、资源、商店、资金、通知、文件、AI、通话信令和管理接口。

```powershell
Copy-Item .env.example .env
php -S 127.0.0.1:8788 -t public public/router.php
```

配置只从环境变量读取，`config/app.php` 的默认值仅适合本地开发。生产环境必须关闭调试并启用 HTTPS、最小权限数据库账号、上传隔离、审计与定时任务。
