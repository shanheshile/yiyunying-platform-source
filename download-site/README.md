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
- 维护者页 `/internal-downloads/`：动态路由，要求 Sign in with ChatGPT 身份，并且邮箱必须出现在服务端 `YIYUNYING_INTERNAL_DOWNLOAD_EMAILS` 逗号分隔白名单中；白名单为空时失败关闭。该路由只能部署在 Sites 身份网关后方，或由受信任入口先剥离外部 `oai-authenticated-user-*` 请求头再注入已验证身份；不能把普通反向代理转发的同名请求头当作认证。该页只展示经过白名单投影的版本证据，不生成可转发的 APK 直链。
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
