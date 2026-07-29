# 易运盈后台存储维护

维护工具只处理过期凭证、验证码、通话协商信令、历史技术日志、过期已读通知和天气缓存。它不会删除聊天消息、帖子、评论、订单、余额流水、文档、文件、收藏、群聊或未读通知。

## 使用

默认只预览，不写数据库：

```bash
php tools/maintenance.php
```

确认候选数量后正式执行一个批次：

```bash
php tools/maintenance.php --execute
```

输出机器可读结果：

```bash
php tools/maintenance.php --json
```

建议每天凌晨运行一次。脚本每张表单次最多处理 `5000` 行，避免长事务和长时间锁表；积压较多时让计划任务逐日消化即可。

```cron
20 3 * * * cd /www/wwwroot/appht.jjmxg.xyz/yiyunying-backend && /usr/bin/php tools/maintenance.php --execute >> storage/logs/maintenance.log 2>&1
```

## 保留周期

可通过环境变量调整：

| 环境变量 | 默认值 | 内容 |
| --- | ---: | --- |
| `MAINTENANCE_BATCH_SIZE` | 5000 | 每张表单批最大行数 |
| `MAINTENANCE_TOKEN_GRACE_DAYS` | 7 天 | 过期或撤销令牌额外保留期 |
| `MAINTENANCE_VERIFICATION_DAYS` | 2 天 | 过期验证码保留期 |
| `MAINTENANCE_VOICE_SIGNAL_DAYS` | 7 天 | WebRTC 协商信令保留期 |
| `MAINTENANCE_REQUEST_LOG_DAYS` | 30 天 | API 请求日志保留期 |
| `MAINTENANCE_ERROR_LOG_DAYS` | 90 天 | 系统错误日志保留期 |
| `MAINTENANCE_LOGIN_LOG_DAYS` | 180 天 | 各级登录日志保留期 |
| `MAINTENANCE_OPERATION_LOG_DAYS` | 365 天 | 操作审计日志保留期 |
| `MAINTENANCE_READ_NOTIFICATION_DAYS` | 180 天 | 已读通知保留期 |

大批量历史数据清理完成后，可在低峰维护窗口由数据库管理员针对高增长日志表执行 `OPTIMIZE TABLE` 回收物理空间。不要在业务高峰自动执行该命令。
