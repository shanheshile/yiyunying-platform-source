# 部署说明

## 后端

推荐 Nginx + PHP-FPM + MySQL。站点根目录指向 `backend/public`，未命中的 API 请求交给 `public/index.php` 或 `public/router.php`。

最低配置：

1. 复制 `backend/.env.example` 为服务器私有 `.env`。
2. 设置 `APP_ENV=production`、`APP_DEBUG=false` 和 HTTPS `APP_URL`。
3. 配置独立数据库账号，禁止使用 root。
4. 给 `storage/` 与受控上传目录最小写权限。
5. 限制上传大小、执行权限和可访问扩展名，启用 PHP OPcache。
6. 配置定时任务处理红包/转账过期、通知、缓存清理和审计归档。

生产环境需按业务启用：SMTP、厂商推送、地图、天气/新闻、支付、对象存储、AI、语音转写、WebRTC TURN。

## Android 发布

1. 修改 `android/version.properties`，`VERSION_CODE` 必须单调递增。
2. 使用 CI Secret 注入 API 地址、应用密钥和 release 签名。
3. 构建四角色 release 包并读取 APK manifest 校验版本。
4. 将 APK 的 SHA-256、大小和下载地址写入下载中心发行元数据。
5. 在真实 Android 16 设备验证通知、锁屏来电、后台限制、悬浮窗、文件选择和更新安装。

## 下载中心

下载中心可以作为 Node 服务或静态站部署。Nginx 必须为 `.apk` 返回正确的 `application/vnd.android.package-archive`，支持 Range 请求，并避免把 HTML 错误页伪装成 APK。

静态部署示例：

```powershell
Set-Location download-site
corepack pnpm install --frozen-lockfile
corepack pnpm export:static
```

上传 `static-dist/` 内容后，使用 `curl -I` 检查首页与 APK：状态码、Content-Type、Content-Length、ETag 和缓存策略必须正确。

## 数据库

- 生产迁移前先备份并验证恢复。
- 资金、红包、转账、订单和奖励变更使用事务与幂等键。
- 为消息、通知、时间线、订单和审计查询建立组合索引。
- 不把生产 SQL 导出放入源码仓库。
