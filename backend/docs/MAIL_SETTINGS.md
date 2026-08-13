# 总控邮件服务配置

邮件配置是服务器全局基础设施，只允许 1 级平台所有者读取非敏感状态和修改。2 级授权平台、3 级管理员和应用用户即使直接调用接口也会收到 403。

## 部署门禁

1. 先备份数据库并执行 `database/migrations/upgrade_20260814_secure_mail_settings.sql`；全新安装只需导入 `database/install.sql`。
2. 从服务器秘密管理注入 32 个随机字节对应的 64 位小写十六进制 `MAIL_SETTINGS_MASTER_KEY`，或注入 `MAIL_SETTINGS_KEYRING_JSON` 与 `MAIL_SETTINGS_ACTIVE_KEY_ID`。密钥不能进入数据库、仓库、接口响应或日志。
3. 保持 `MAIL_DATABASE_CONFIG_ENABLED=true`。数据库还没有配置行时，验证码发送继续显式回退到环境变量 `MAIL_*`；一旦总控保存了配置，该行（包括 `disabled`）完整覆盖环境变量，不会偷偷回退。
4. SMTP 生产配置只允许 `tls` 或 `ssl`，发件地址必须使用正式域名。上线前还要验证 SMTP 凭据以及发件域 SPF、DKIM、DMARC。

缺少活动配置密钥时，总控写配置会 503；数据库中存在加密 SMTP 密码但对应旧键缺失时，发送也会 503。环境变量模式且数据库无配置行时不依赖数据库加密密钥。

## 接口

所有接口都要求 root Bearer Token；写入、测试和重加密还要求当前 root 密码。

- `GET /api/platform/mail-settings`：只返回通道、发件人、SMTP 非敏感字段、`smtp_password_configured`、有效性问题和 `revision`。永不返回密码、密文、nonce、tag 或密钥。
- `PUT /api/platform/mail-settings`：提交 `transport,from_address,from_name,smtp_host,smtp_port,smtp_encryption,smtp_username,expected_revision,current_password`。`smtp_password` 省略或留空表示保留；`clear_smtp_password=true` 表示清除，两者同时提交返回 422。版本冲突返回 409。
- `POST /api/platform/mail-settings/test`：提交明确的 `recipient_email,current_password`。每 60 秒最多一次、每小时最多五次；审计只保存掩码地址和投递状态。HTTP 202 / `accepted_unconfirmed` 仅表示邮件服务接收，不代表收件箱已送达。
- `POST /api/platform/mail-settings/reencrypt`：提交 `expected_revision,current_password`，将已保存的 SMTP 密码用当前活动键重新加密。

## 安全轮换

轮换时不能直接删除旧键：

1. 在 `MAIL_SETTINGS_KEYRING_JSON` 同时放入旧键和新键。
2. 把 `MAIL_SETTINGS_ACTIVE_KEY_ID` 切换为新键标识并重载 PHP-FPM。
3. 在总控“邮件服务配置”执行“使用活动密钥重加密密码”。
4. 回读 revision 增加、执行一次测试邮件并检查审计。
5. 只有完成上述验证后，才可从 keyring 撤销旧键。

数据库备份不包含主密钥。必须对服务器秘密配置另做受控备份，否则恢复数据库后无法解密 SMTP 密码。
