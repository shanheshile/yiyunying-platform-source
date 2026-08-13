# 下载中心

下载中心使用 React 19、Next 16、Vinext 与 Vite。客户首页只在清单为 `Stable + finalized` 时展示四角色正式 APK；`pending` 和 `Debug` 不进入客户 HTML、浏览器脚本或公网部署。API 文档支持 cURL/Java/JavaScript 示例切换、复制、Web Share/复制 canonical URL 降级、打印、锚点/新窗口打开；正式下载区提供 APK 选包、SHA-256 校验、打开/安装、未知来源授权和失败恢复说明。

```powershell
corepack pnpm install --frozen-lockfile
corepack pnpm test
corepack pnpm export:static
corepack pnpm lint
```

发布前必须确认 `release-metadata.json` 与 Android 源码和 APK manifest 的版本一致，并验证下载链接返回真实 APK 而非 HTML 错误页。Stable 网站只能公开用户、管理员、授权平台、平台总控四个 APK；源码、Git history、delivery 包和 project manifest 必须保持私有。

## 下载受众分层

- 客户页 `/download-center/`：完全公开，但只消费已经 Finalize 的 Stable 四角色 APK。没有正式版时保留功能介绍和接口文档，且不输出候选版本、文件名、包名、SHA 或下载链接。
- 维护者页 `/internal-downloads/`：动态路由，要求 Sign in with ChatGPT 身份；邮箱和稳定用户 ID 必须分别命中服务端 `YIYUNYING_INTERNAL_DOWNLOAD_EMAILS`、`YIYUNYING_INTERNAL_DOWNLOAD_USER_IDS` 两个逗号分隔白名单，任一白名单为空时失败关闭。该路由只能部署在 Sites 身份网关后方，或由受信任入口先剥离外部 `oai-authenticated-user-*` 请求头再注入已验证身份；不能把普通反向代理转发的同名请求头当作认证。浏览器不接收 APK 固定直链；每次点击由服务端重新鉴权并签发五分钟 HMAC 下载地址。短链在有效期内可被转发，禁止分享。
- 本机 APK 页：运行下方命令后，只在 `127.0.0.1`/`::1` 随机端口与随机会话路径提供经过大小、SHA-256 和四角色身份复核的 APK；源码、bundle、交付包和 manifest 没有下载路由。

```powershell
python download-site/scripts/serve-internal-downloads.py `
  --manifest releases/1.0.0/release-manifest.json
```

Debug 只能走本机或专用私有存储，`deploy-static.py` 会拒绝把 Debug 或项目资产发布到公网。Nginx `/downloads/` 默认返回 404，仅放行四个不含 `-debug` 的 Stable APK 命名；正式客户别名与生命周期发布器生成的不可变 token 目录都受该白名单限制。

官网目标正式首发版本 `1.0.0 (63)` 已完成本地 Stable Build，pending manifest SHA-256 为 `B0FE890BA2F5D542D1A8C2DB26611287482EB68385A49A0AEE9F9640E0159EF9`；但 code62→63 真机升级、Finalize 和 APK 生产部署尚未完成，不能切换正式下载。生产生命周期策略仍为 `2.7.14 (59)` Debug；客户页已于 2026-08-13 原子切换为失败关闭状态，四个旧 Debug 公网 URL 已由 Nginx 封为 404。在 finalized 证据就绪前页面必须继续失败关闭。

## 客户页安全修复事务

`deploy-site-security-remediation.py` 与正式版 `deploy-static.py` 完全独立。它只允许上传经过严格白名单检查的 `download-site/static-dist`，要求客户页处于“正式版尚未开放”的失败关闭状态，并拒绝 APK、候选版本/文件名/包名/SHA、项目私有资产、内部下载路由或额外文件。默认命令只做本地检查，不建立 SSH 连接：

```powershell
python download-site/scripts/deploy-site-security-remediation.py
```

如需同时预检已经审核的 Nginx 下载白名单，可在 dry-run 中加入配置；这一步仍不会上传、执行 `nginx -t` 或 reload：

```powershell
python download-site/scripts/deploy-site-security-remediation.py `
  --nginx-config download-site/deploy/nginx-download-center.conf `
  --remote-nginx-config <远端已确认的站点配置绝对路径>
```

生产执行必须先完成离线测试、确认 pinned `known_hosts`、从本机安全存储向当前进程提供 `YY_SSH_PASSWORD`，再显式传入固定确认常量。站点事务使用部署锁、唯一 staging、逐文件大小/SHA-256、同盘 rename、完整公网首页回读和失败重连回滚；它不会创建、覆盖、删除或移动 `/downloads` 下的任何对象。

```powershell
python download-site/scripts/deploy-site-security-remediation.py `
  --execute `
  --confirmation SITE_ONLY_SECURITY_REMEDIATION_EXECUTE_CONFIRMED `
  --host <生产主机> `
  --port 22 `
  --username <已确认用户> `
  --known-hosts <固定主机公钥文件>
```

旧 Debug 直链只有在显式启用独立 Nginx 事务后才会被关闭。该事务仅接受仓库内已审核配置，先备份远端配置，再上传、核对、执行 `nginx -t` 和 reload，并要求四个 `2.7.14` Debug URL 全部返回 404；任一步失败都会恢复旧配置、重新检查并 reload。除上述站点确认外还必须传入第二个确认常量：

```powershell
python download-site/scripts/deploy-site-security-remediation.py `
  --execute `
  --confirmation SITE_ONLY_SECURITY_REMEDIATION_EXECUTE_CONFIRMED `
  --host <生产主机> `
  --known-hosts <固定主机公钥文件> `
  --nginx-config download-site/deploy/nginx-download-center.conf `
  --remote-nginx-config <远端已确认的站点配置绝对路径> `
  --nginx-confirmation NGINX_DEBUG_BLOCK_REMEDIATION_CONFIRMED
```

本事务不是 Finalize 或正式发布的替代品，不会公开 `1.0.0` 候选 APK。若回滚或清理报告不完整，必须保留锁和 staging 现场并人工审计，不能强行再次执行。

## 对内 APK 短时下载源

`deploy-internal-apks.py` 独立维护对内下载站使用的私有 APK 源，不修改客户官网和公网 `/downloads`。它只接受两条冻结轨道：`Debug 2.7.15 (60)` 与仍为 `pending` 的 `Stable 1.0.0 (63)`；每条轨道必须恰好包含用户端、管理员端、代理端和买断总控端四包。默认 dry-run 会用 `aapt2`、`apksigner`、文件大小和 SHA-256 复核八个真实 APK，全程不读取签名秘密、不连接服务器：

```powershell
python download-site/scripts/deploy-internal-apks.py
```

生产服务器没有 `secure_link` 模块，因此受审片段使用 Nginx `auth_request` 调用公网页根之外的只读 PHP 验证器。Sites 与验证器的唯一签名契约为：

```text
path = /__internal-apks/{debug|candidate}/{version}/{受控文件名}
expires = 当前 Unix 秒 + 300
sig = base64url_no_padding(HMAC-SHA256(hex_to_32_bytes(secret), expires + "\n" + path))
```

Sites 托管 secret 名为 `YIYUNYING_INTERNAL_DOWNLOAD_SIGNING_SECRET`，必须是 64 个小写十六进制字符；SSH 密码仍只从 `YY_SSH_PASSWORD` 读取。执行脚本不会输出二者。远端秘密写入固定的 `/etc/nginx/private/yiyunying-internal-apks-secret.conf`，权限为 `0600`；APK 和验证器原子切换到固定的 `/srv/yiyunying-internal-apks/current`，不进入任何 web root。PHP-FPM 地址和对应 PHP CLI 必须先从现有服务器配置只读确认，再以显式参数传入；当前生产 FPM 证据值为 `unix:/tmp/php-cgi-82.sock`，不可由脚本猜测。服务器 PATH 中的 `php` 是 7.0，禁止用于本闭环；`--remote-php-binary` 必须指向非符号链接的绝对可执行文件，脚本会调用该文件验证自身版本严格为 `8.2`，并只用它执行验证器语法检查。

主站配置必须已经包含显式片段或受控的单层 `*.conf` extension include。执行时同时传入该 include 原文、其证据配置、FPM 证据配置和目标片段路径。下列占位路径必须换成已只读确认的真实路径；两个确认常量缺一不可：

```powershell
python download-site/scripts/deploy-internal-apks.py `
  --execute `
  --confirmation INTERNAL_APK_PRIVATE_DEPLOY_EXECUTE_CONFIRMED `
  --nginx-confirmation INTERNAL_APK_NGINX_AUTH_REQUEST_CONFIRMED `
  --host <生产主机> --port 22 --username <已确认账户> `
  --known-hosts <固定known_hosts文件> `
  --fpm-upstream unix:/tmp/php-cgi-82.sock `
  --remote-php-binary <已确认的PHP-8.2-CLI绝对路径> `
  --remote-fpm-evidence-config <含该fastcgi_pass的现有配置绝对路径> `
  --remote-nginx-host-config <主站Nginx配置绝对路径> `
  --remote-nginx-host-include <主站中已有的精确include或单层*.conf路径> `
  --remote-nginx-include <上述include覆盖的internal-apks.conf绝对路径> `
  --remote-secret-include /etc/nginx/private/yiyunying-internal-apks-secret.conf
```

执行闭环固定为 pinned `known_hosts`/`RejectPolicy`、独占锁、私有同盘 staging、逐文件上传回读、PHP 语法检查、数据/秘密/配置分别同目录原子改名、`nginx -t`、reload、八包 HTTPS HEAD、两轨 Range `206`、ETag `304`、非法 Range `416`、错误签名 `404`、过期链接 `410`、非 GET/HEAD 拒绝和非法文件名 `404`。任一步失败会恢复旧数据、旧秘密和旧 Nginx 片段并再次执行 `nginx -t`/reload；回滚不完整时保留锁与事务现场。短链在五分钟内仍可转发，不得宣称绑定当前用户。
