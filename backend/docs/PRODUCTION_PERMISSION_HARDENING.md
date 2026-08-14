# 生产权限收敛与回滚手册

适用目标固定为：

`/www/wwwroot/appht.jjmxg.xyz/yiyunying-backend`

本手册只处理文件系统、Nginx 上传目录和 PHP-FPM 边界，不替代数据库、上传文件和代码发布备份。正式执行必须处于已停止写入的维护窗口。

## 2026-08-14 只读审计证据

| 范围 | 实际状态 | 风险 |
| --- | --- | --- |
| 后端根 | `root:root 0777` | FPM 用户可替换任意同层文件或目录 |
| 后端顶层 | 固定目录为 `app/config/database/deploy/docs/public/routes/storage/tools`；固定普通文件为 `.env/.env.example/README.md/bootstrap.php/composer.json` | 旧审计只检查目录，可能漏掉同层文件、链接或特殊节点；新版逐项检查名称、类型、owner、group、mode 和 link count |
| `.env` | `www:www 0640` | FPM 对配置和 secret 有写权限 |
| `app/config/database/deploy/docs/routes/tools` | 18 个目录均为 `root:root 0777`；312 个文件均为 `root:root 0666` | 运行时可改源码并形成持久化 RCE |
| `public` | `root:root 0777`；`index.php/router.php/.user.ini` 为 `0666` | Web 入口和 PHP 每目录配置可被运行时改写 |
| public 顶层 | 固定目录为 `.well-known/downloads/download-center/uploads`；固定文件为 `.htaccess/.user.ini/api-docs.html/index.php/router.php`；另有 1 个旧 stage | 白名单外任何目录、文件、symlink 或特殊节点都会阻断 apply |
| `public/uploads` | 180 个目录：124 个 `root:root 0777`、45 个 `www:www 0775`、11 个 `www:www 0755`；431 个文件：140 个 `root:root 0666`、211 个 `www:www 0664`、80 个 `www:www 0644` | 混合属主且普遍可写 |
| `public/downloads` 与 `public/download-center` | 20 个目录 `root:root 0755`、98 个文件 `root:root 0644` | 当前不可由 FPM 写，模式可进一步收敛 |
| `storage` | `www:www 0777`；cache/logs 为 0777，tmp/uploads/stt 为 0775 | 写权限范围远超必要目录 |
| storage 未归档顶层文件 | 4 张 API 文档截图与 2 个 `php-8792` 输出/错误日志直接位于 storage 根 | 不属于白名单；apply 会阻断，须在维护窗口按哈希移入 root-only 证据/日志归档后再审计 |
| `storage/private` | 根和报告目录 `root:root 0700`、9 个报告 `0600`，尚无 `private/uploads` | 报告安全，但 FPM 无法创建或读取未来私有商品文件 |
| `storage/deploy-backups` | 1 个文件与 4 个目录均为 `www:www`，模式分别为 `0664/0775` | 部署前备份可由 FPM 修改；目标必须整树 `root:root 0700/0600` |
| STT | 3317 个目录 `www:www 0775`、30551 个文件 `www:www 0664`、2870 个符号链接、19426 个硬链接 | 运行时可篡改依赖；`venv/bin/python3` 最终目标为 0664，root 与 www 均不可执行，语音转写当前不可启动 |
| 链接 | 源码、public、private、downloads 均无符号/硬链接；STT 的 2870 个符号链接均不破损且不逃逸，9713 组硬链接均完整位于 STT 树内，跨 `*/bin/*` 可执行类与数据类的硬链接组为 0 | STT 必须保留链接关系并从可信安装产物恢复执行位；0750/0640 分类可在不拆硬链接的前提下实现 |
| PHP-FPM / Nginx | master 为 root，worker 为 www；PHP 8.2 socket `/tmp/php-cgi-82.sock` 为 `www:www 0666`；`clear_env=no`；`cgi.fix_pathinfo=1` | 任意本地用户可连接 FastCGI socket，环境和 PATH_INFO 边界偏宽 |
| Nginx uploads | 现有 `location /uploads/` 没有 `^~`，generic PHP regex 随后加载 | 若 uploads 出现 PHP 文件，regex location 可接管并执行 |
| 临时发布 | `/tmp` 遗留 8 个 0755 stage、1 个 0777 stage，后端源码压缩包为 0644；public 内还有一个 0777 的旧 download-center stage | 本机其他账号可读私有源码；public orphan 仍处于文档根 |
| 备份 | `/www/backup/yiyunying` 为 0700、文件 0600，但 136 个子目录中 125 个错误地为 0600；无链接/硬链接 | root 依靠特权可访问，但目录模式不规范，普通恢复工具可能失败 |
| 内部 APK | `/srv/yiyunying-internal-apks/current` 为 root:root，目录 0755、文件 0644；无链接/硬链接；secret 目录/文件为 0700/0600 | HTTP 有鉴权，但本机账号仍可读候选包；后续宜改为 root:www 0750/0640 |

审计期间 HTTPS `/api/health` 独立回读为 `200 / code=1 / status=ok / database=connected`。健康不代表上述权限风险已消失。

## 目标权限矩阵

| 路径 | owner:group | 目录 | 文件 | www 权限 |
| --- | --- | --- | --- | --- |
| 后端根、源码、配置、路由、工具、public 非可写区 | `root:www` | `0750` | `0640` | 只读/遍历，不可写 |
| 后端固定顶层普通文件、public 固定顶层普通文件、`.well-known` | `root:www` | `0750` | `0640` | 只读/遍历，不可写；白名单外节点拒绝 |
| `.env` | `root:www` | - | `0640` | 可读、不可写 |
| `public/downloads`、`public/download-center` | `root:www` | `0750` | `0640` | 可读、不可写 |
| `public/uploads` | `www:www` | `0750` | `0640` | 必要的创建、读取、删除 |
| `storage` | `root:www` | `0710` | 顶层配置 `0640` | 仅遍历，不可在顶层创建 |
| `storage/cache`、`logs`、`tmp`、`uploads` | `www:www` | `0700` | `0600` | 必要的运行时读写；tmp 用于 STT 临时文件 |
| `storage/private` | `root:www` | `0710` | - | 仅遍历，不能列目录或创建任意一级目录 |
| `storage/private/uploads` | `www:www` | `0700` | `0600` | 私有商品/论坛文件的必要读写 |
| private 报告、quarantine、recovery、receipt | `root:root` | `0700` | `0600` | 不可访问 |
| `storage/deploy-backups` | `root:root` | `0700` | `0600` | 不可访问 |
| STT `python-runtime`、`venv`、预装模型 | `root:www` | `0750` | 所有 `*/bin/*` 普通文件 `0750`；其余数据/库 `0640`；symlink 的 lstat owner/group 为 `root:www` | www 只读；任何 `www:www` 依赖、可写位、越界/破损 symlink 或逃逸 hardlink 均拒绝 |
| `/srv/yiyunying-internal-apks/current` | `root:www` | `0750` | `0640` | Nginx/FPM 可读，不可写 |
| 内部下载 secret | `root:root` | `0700` | `0600` | 不可访问 |

不要对 STT 使用 `chmod -R 0640`、`chmod -R 0750` 或重新复制去重。必须从锁定版本的安装产物恢复运行时与 venv，再按上述固定矩阵设置执行位；否则会再次破坏 Python、动态工具和硬链接。`models` 应在维护期预下载后改为只读，运行时输出只进入 `storage/tmp`。权限工具不会修改 STT；apply 前会要求整树 owner 为 `root`、group 为 `www`，拒绝任何 owner 为 `www` 的依赖，并保持 2870 个 symlink 与 9713 组 hardlink 的拓扑。

STT 门禁不是静态 `test -x`。矩阵和链接检查通过后，还必须以 `www` 的空环境、根目录工作目录运行两个无写入探针：`venv/bin/python3 -I -S -B -c` 导入隔离模式标准库，以及 `venv/bin/python3 -I -B -c` 导入 `faster_whisper.WhisperModel`。`-B` 禁止生成 pyc；探针失败即阻断，不会尝试在线修复或下载模型。

## 最小可回滚维护顺序

1. 进入维护并停止应用写入；确认当前 DB、public/uploads 和代码备份均完整可读。
2. 使用面板/服务配置备份 Nginx 主站、扩展片段、PHP 8.2 FPM 和 php.ini；备份文件设为 root:root 0600，目录 0700。
3. 清点 `/tmp/yiyunying-*` 和 public orphan 的创建时间、进程、哈希与所属部署；只处理已确认无进程占用的精确目标。不要通配删除。
4. 从可信、锁定哈希的 STT 安装产物重建 `python-runtime` 和 `venv`，恢复执行位，验证所有符号链接不破损/不逃逸、所有硬链接名称均在 STT 树内；不要在本步骤启动业务转写。
5. 先运行权限工具默认 dry-run。当前生产会因 STT 不可执行、socket 0666、public orphan 和权限漂移返回 2，这是安全阻断，不是脚本失败。
6. 用面板维护方式把 PHP 8.2 pool 配置为 `listen.owner=www`、`listen.group=www`、`listen.mode=0660`；不要对活动 socket 临时 `chmod`，因为 FPM 重启会覆盖且可能破坏面板管理。评审后设置 `clear_env=yes`，只保留显式 `env[...]`；把 `cgi.fix_pathinfo` 设为 0。分别执行 FPM 配置测试和面板回读。
7. 用 `nginx-uploads-static-only.conf.example` 替换原主站的 `location /uploads/`，不能同时保留两个相同 location。先 `nginx -t`，暂不释放维护。
8. 再跑 dry-run，但不要把“exit 0 / 所有权限已经正确”误当成 apply 的前置条件。`APPLY_READY_STRUCTURE_FUNCTION=no` 才表示结构/功能阻断：未知路径或类型、symlink/hardlink、特殊节点/挂载点、危险上传或发布 orphan、STT 矩阵/真实 www 运行探针、FPM socket、扫描期 shape 稳定性任一失败都禁止 apply。`APPLY_READY_STRUCTURE_FUNCTION=yes` 且 `EXPECTED_PERMISSION_DRIFT=yes` 表示仅有 apply 预期修复的 owner/group/mode 漂移；`WILL_CREATE_PRIVATE_UPLOADS=yes` 表示唯一允许由事务创建的目录尚不存在。这两类预期状态仍会令 dry-run 返回 2，但在写入已停止、备份已复核且结构/功能项为 yes 时属于 apply-ready，不应要求先手工 chmod 或 mkdir。只有 `AUDIT_RESULT=pass` 才返回 0。
9. 审计从整棵 backend 的路径、节点类型、device、inode 和 link count 生成扫描前/后 shape hash；完整覆盖 backend 根与顶层普通文件、public 根与所有白名单子树、storage/private、deploy-backups 和 STT。扫描期间任一树形变化、未知 symlink/hardlink、特殊节点或跨设备挂载点均 fail closed，避免 `find -xdev` 静默漏扫子树。
10. apply 会先在 `/www/backup/yiyunying/permission-hardening-.../` 写入 root-only 的完整 ACL/owner/mode 快照、SHA-256、整树 shape hash，再生成 NUL 分隔且带 SHA-256 的精确变更清单。清单与“除 STT 外的整树节点集合”哈希必须完全一致，并记录每个原节点的 path/type/device/inode；随后只逐项消费清单，禁止对未经清点的现场 `find -exec chmod/chown`。
11. apply 在权限变更前创建 `transaction-ledger.tsv`。唯一允许的新目录集合固定为“原先不存在时的空 `storage/private/uploads`”；其创建前先写 ledger，创建后立即冻结 expected post-classified、expected post-shape 和新目录 inode/type 回执。cache/logs/tmp/uploads/private/uploads/public/uploads 的每个随机探针也在创建前写入 ledger；正常探针创建和删除均以 www 身份执行。
12. 写 committed 回执前必须再次执行完整结构/分类检查、原 inventory SHA-256 与 path/type/device/inode 复核、唯一新目录身份复核、整棵 expected post-shape、精确 owner/group/mode 矩阵、FPM socket 和真实 STT www 探针；不是只复查 STT。维护期同名替换或新增任何异物都会进入失败 trap。
13. 任一失败 trap 先按 ledger 删除探针，再精确 `rmdir` 本事务新建的空目录，然后恢复 ACL 并核对原 shape hash。任何探针未删、目录非空、ledger 损坏、ACL 恢复失败、shape 不一致或状态文件写入失败，最终状态只能是 `recovery_required`（退出 97），绝不能写 `restored`。禁止 `rmdir ... || true` 吞错。
14. 执行 FPM reload、Nginx reload，依次验证 health、登录、普通上传/读取/删除、私有已购文件下载、公开 APK 200/206、内部签名 APK 200/206、无签名 403、脚本扩展与 SVG 404。
15. 全部通过后释放维护；保留权限快照、transaction ledger 和状态回执，不与源码、数据库或上传备份混放为公开文件。

默认 dry-run 示例（密码只通过当前进程的 `YY_SSH_PASSWORD` 提供）：

```powershell
python backend/tools/harden-production-permissions.py `
  --host 154.12.25.203 --user root `
  --known-hosts "$env:USERPROFILE\.ssh\known_hosts"
```

apply 必须显式确认维护：

```powershell
python backend/tools/harden-production-permissions.py `
  --host 154.12.25.203 --user root `
  --known-hosts "$env:USERPROFILE\.ssh\known_hosts" `
  --apply --maintenance-confirmed writes-stopped-and-backup-reviewed
```

同一维护窗口内的精确回滚：

```powershell
python backend/tools/harden-production-permissions.py `
  --host 154.12.25.203 --user root `
  --known-hosts "$env:USERPROFILE\.ssh\known_hosts" `
  --apply --maintenance-confirmed writes-stopped-and-backup-reviewed `
  --rollback-snapshot /www/backup/yiyunying/permission-hardening-YYYYMMDDTHHMMSSZ-0123456789abcdef/permissions-before.acl
```

回滚会先校验 ACL 文件 SHA-256，按同一 ledger 删除残留探针和本事务新建的空目录，再核对包含 device/inode/link count 的路径树哈希；若维护期间出现新文件、创建目录非空、ledger 损坏或快照被替换，写入 `recovery_required` 并保持维护状态，必须人工处理。任何失败都不能释放维护。

## 离线与上线合同

离线合同至少验证：

- 默认命令只包含 `find/stat/readlink/getent/su test` 等只读操作，不含 chmod/chown/mkdir/rm/mv/setfacl；
- apply 必须包含维护确认、固定根、固定备份命名空间、ACL 快照、SHA-256、失败 trap 和 `setfacl --restore`；
- backend/public/storage/private/deploy-backups 每个节点必须落入明确名称、类型和权限矩阵；扫描前后 shape hash 必须一致；
- apply 只允许消费校验过的 NUL inventory，不允许 `find -exec chmod/chown` 直接改未经清点的目标；
- `.env` 只能为 root:www 0640，www 可读不可写；
- symlink、Windows reparse、非预期 hardlink、未知路径和 public orphan 均 fail closed；
- STT 只做 root:www 只读/可执行/链接边界门禁，并真实以 www 运行隔离 Python 与 faster-whisper import；权限工具不猜测或重写其执行位；
- 动态故障注入必须证明：正常失败能删除 ledger 探针/新目录并写 `restored`；目录非空或 ACL 恢复失败均退出 97 且只写 `recovery_required`；未知非 STT hardlink 会被整树门禁拒绝；提交前新增异物或同名替换 inventory 节点均被 post-classified/inode+type/shape 门禁捕获并进入 `recovery_required`；
- Nginx uploads 使用 `^~`、禁脚本/SVG、禁 symlink、仅 GET/HEAD、nosniff，且不含 fastcgi。

上线合同还必须验证 `nginx -t`、PHP-FPM config test、socket 0660、实际 www 写权限白名单以及真实 HTTP 200/206/403/404 行为。仅有配置文本或 chmod 成功回显不算闭环。
