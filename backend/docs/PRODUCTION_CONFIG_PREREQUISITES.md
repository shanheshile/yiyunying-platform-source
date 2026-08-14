# 生产配置与 7 个遗留节点前置维护

本手册对应 `tools/apply-production-config-prerequisites.py`。工具只适用于固定生产主机、`root` SSH 身份、固定后端根、3 份原始配置和 7 个已审计遗留节点。默认行为是严格只读审计；不会因为路径名、前缀或文件大小相似就采用现场节点。

## 冻结证据

2026-08-15 的两次只读采样确认：

- public 遗留发布 stage 仍为 32 个节点、29 个普通文件、344,903 字节；
- storage 根仍有 4 张 API 文档截图和 2 个旧 PHP 命令日志；
- 7 个节点均为同一设备，FD 引用和源码文件名引用均为 0；
- 3 份配置均为 root 所有、普通非链接文件、`nlink=1`，原始 SHA-256、device、inode、mode、size 和 mtime_ns 已写入工具常量；
- 数据库 5 项只来自后端 `.env`，FPM pool 不重复注入；
- AI 19 项只来自 FPM pool，`.env` 不重复覆盖；
- `MAIL_SETTINGS_MASTER_KEY` 与 `MAIL_TRANSPORT` 当前均未配置，因此应用使用代码默认的 `disabled`。缺少主密钥时，工具拒绝任何非 `disabled` transport。

对 `/www/backup/yiyunying` 受控代码备份、最近 24 个代码归档和当前旧代码做过额外严格只读盘点：找到的 6 个环境候选均没有 `MAIL_*` 键，transport、发件域和 master key 均缺失，没有可以直接恢复启用的 SMTP 候选。这个结论不排除更早版本曾通过系统 `sendmail`、数据库配置或非 `MAIL_*` 键发送；工具不会根据历史痕迹自动启用邮件，也不会输出配置值、账号或 host。

旧审计给出的 public 指纹 `cdedca6e...` 和 6 个 storage 指纹前缀没有留下字段顺序，不能伪造为完整可复算哈希。新工具使用 `canonical-manifest-v1`：UTF-8/LF、按路径 UTF-8 字节序排序，每行绑定相对路径、类型、mode、uid、gid、device、inode、nlink、size、mtime_ns，以及普通文件内容 SHA-256 或 symlink target SHA-256。旧指纹仅作为形状旁证，manifest 明确记录 `algorithm_comparable=false`。

## 默认只读审计

密码只允许经当前进程的 `YY_SSH_PASSWORD` 传入，known_hosts 必须为一个非链接普通文件：

```powershell
python backend/tools/apply-production-config-prerequisites.py `
  --host 154.12.25.203 --port 22 --user root `
  --known-hosts "$env:USERPROFILE\.ssh\known_hosts"
```

通过回执必须同时包含：`nodes=7`、`fd_refs=0`、`source_refs=0`、`write_actions=0`、`db_from_dotenv=5`、`ai_from_pool=19`、`mail_state=default-disabled`。候选配置的 3 个 SHA-256 也必须与工具内冻结值相同。

## apply 前置条件

执行前必须全部满足：

1. 已进入维护窗口，应用写入停止；
2. 当前数据库、上传、代码备份已独立复核；
3. 固定 Python 运行时 `/usr/local/bin/python3` 已安装并通过隔离探针；
4. 7 个节点和 3 份配置未发生任何 stat/hash 漂移；
5. FPM、PHP CLI、Nginx 和 reload 命令仍位于工具固定路径；
6. 负责人理解：任何 SSH 响应丢失或未知退出状态都只能报告 `RECOVERY_REQUIRED`，不能凭重连后“看起来正常”宣称完成。

执行需要两个完全匹配的确认串：

```powershell
python backend/tools/apply-production-config-prerequisites.py `
  --host 154.12.25.203 --port 22 --user root `
  --known-hosts "$env:USERPROFILE\.ssh\known_hosts" `
  --execute `
  --confirm archive-seven-nodes-and-harden-config `
  --maintenance-confirmed writes-stopped-and-backups-reviewed
```

执行包装器会在 SSH 连接和首个远端写入之前输出一个非秘密的 32 位十六进制 transaction token。必须立即记录该 token；它是响应丢失后查询唯一 root-only 状态日志的索引，不是密码，也不授权任何操作。

## 写事务

apply 只执行以下固定事务：

1. 在现有证据父目录 inode 上取得非阻塞独占 `flock`；锁覆盖从首次采样到 manifest、final rename、目录 fsync、配置回读和最终 journal 提交的完整事务；
2. 再次复算 7 个完整 canonical manifest，核验 FD、源码引用、device、inode、nlink，并读取和转换 3 份冻结配置；
3. 首个远端写入就是 `<token>.status.json`：root-only `0600` journal，写入 `created` 状态并 fsync 文件和父目录。其后每个持久状态只允许单调前进；
4. 在 `/www/backup/yiyunying` 创建唯一 root-only `0700` 的 `.partial` 证据目录，将 3 份原始配置复制为 `0600` 备份，原子写入仅可由 `prepared` 前进为 `committed` 的 manifest；
5. 第二次完整采样后，在同一维护锁内、每个 `mv` 紧邻前再次核验完整 preimage、FD、源码引用、device/inode/nlink；
6. 逐项使用 `/usr/bin/mv -T --no-clobber -- <source> <exact-destination>` 移入同卷归档，绝不删除 7 个节点。每次移动后以及 final/manifest 提交前均要求原路径严格 absent、归档 fingerprint 完整相等且 FD 为 0；
7. 为 FPM、php.ini、站点配置各创建同目录独占 candidate，并在 `.partial/config-original-inodes` 为每个原 inode 创建 hard-link hold；hold 目录项和 candidate 均单独 fsync；
8. 用 `os.replace` 原子切换 live 配置，精确修改 `listen.mode=0660`、`clear_env=yes`、`cgi.fix_pathinfo=0`，并将唯一 `/uploads/` 块替换为仓库哈希锁定的 `deploy/nginx-uploads-static-only.conf.example`；
9. 运行 FPM config test、PHP ini 探针和 `nginx -t`；在 journal 持久记录 `reload_started=true` 后才依次 reload FPM/Nginx，再回读 3 配置、FPM socket `www:www 0660`、再次三测、HTTPS health，并要求随机 `.php` uploads URL 返回 404；本工具不运行 STT 探针；
10. 再次证明 7 个源路径 absent、7 个归档 fingerprint/FD 正确、3 个候选和 3 个原 inode hold 正确，才把 `.partial` 原子改为 final 并 fsync 父目录；
11. final 路径上重复节点、配置、hold、语法、socket、HTTPS 和 uploads 拒绝回读；随后原子写 committed manifest、fsync final 目录，再将 journal 单调提交为 `committed`。3 个原 inode hold 永久保留在 root-only final 归档，不会提前删除。

manifest 位于 root-only 证据归档，包含精确源路径和配置路径；独立状态 journal 位于 root-only 证据父目录，保存精确 partial/final 路径和单调 revision。标准输出只返回 transaction token、状态、计数和路径 SHA-256，不回显这些路径、环境值、账号、SMTP host 或密钥。

## 只读状态与显式 reconcile

收到 `RECOVERY_REQUIRED`、SSH 响应丢失、JSON 缺失/重复字段、未知退出码或 replace+目录 fsync 不确定后，不得重跑 apply，也不得释放维护窗口。先用执行前记录的 token 进行严格只读查询：

```powershell
python backend/tools/apply-production-config-prerequisites.py `
  --host 154.12.25.203 --port 22 --user root `
  --known-hosts "$env:USERPROFILE\.ssh\known_hosts" `
  --status --transaction-token <32-hex-token>
```

`--status` 只取得共享维护锁、读取 `0600` journal 并复算现场计数；回执固定包含 `write_actions=0`、journal revision/phase、archive location、源/归档节点数、原始/候选配置数和 hold 数。只有 journal 为 `committed` 且 final/节点/配置/hold 计数全部闭合，或 journal 为 `restored` 且原始节点/配置全部闭合，才返回 `pass`。

确认仍在维护窗口、已保存 status 回执并由负责人选择恢复或完成后，才允许显式 reconcile：

```powershell
python backend/tools/apply-production-config-prerequisites.py `
  --host 154.12.25.203 --port 22 --user root `
  --known-hosts "$env:USERPROFILE\.ssh\known_hosts" `
  --reconcile --transaction-token <32-hex-token> `
  --confirm reconcile-production-config-prerequisites `
  --maintenance-confirmed writes-stopped-and-backups-reviewed
```

reconcile 使用同一独占维护锁和冻结常量。只有 7 个节点已完整归档、所有源路径 absent、3 个候选/hold/备份/manifest 全部匹配且语法、reload、socket、HTTPS、uploads 拒绝再次通过时，才补完 final/manifest/journal 并返回 `committed`；其他可验证状态一律使用 hold 和归档逆序恢复。任何污染、双路径冲突、指纹变化或再次不确定都只返回 `recovery_required`。

## 失败与恢复

- journal 是首个写入；即使在 archive 目录创建或 prepared manifest 之前失败，也可用 token 查询 `created` 状态。journal 自身首次 fsync 不确定时只允许 `RECOVERY_REQUIRED`。
- candidate 激活前的确定性失败：现场配置不变；若 `.partial` 已创建，保持 root-only 供审计。
- candidate 或语法测试的确定性失败：利用 hard-link hold 恢复原始 config inode，重新三测，并在先验证归档 fingerprint/FD 后，将已移动节点按逆序用同一 `mv -T --no-clobber` 精确移回。
- reload 后失败：先恢复 3 份配置、重新 reload 并验证 HTTPS，再恢复节点。
- 所有自动恢复均成功时，退出码为 2、回执状态为 `restored`，journal 单调前进到 `restored`，root-only `.partial` 证据保留；被消费的 hold 不再伪称保留。
- 配置/节点恢复失败时，journal 进入 `recovery_required`；后续显式 reconcile 可以继续前进到更高序的 `restored`，绝不倒写 manifest 或 journal 状态。
- `mv`、配置激活、archive rename、manifest/journal replace 后的响应或目录 fsync 不确定，均不自动声称恢复；退出码固定为 97，必须使用 status/reconcile。
- SSH 超时、stdout/stderr 异常、严格 JSON 缺失/重复/额外字段、状态码缺失或 SSH close 不确定时，本地包装器一律报告 `RECOVERY_REQUIRED`。以 transaction token 查询，不得凭目录“看起来存在”猜测结果。

## 最小离线闭环

`tools/tests/test_production_config_prerequisites.py` 的动态 fixture 覆盖：

- audit 零写入；
- 成功事务只归档不删除、3 配置全部生效、3 个原 inode hold 保留、manifest/journal 原子提交；
- candidate 语法失败后节点与原始 config inode 均恢复；
- 首写 journal、prepared manifest、逐节点移动、配置激活、final rename、manifest commit、journal commit 各阶段故障注入；
- replace/fsync 不确定后只读 status 和显式 reconcile 分别闭合到 `restored` 或 `committed`；
- 被篡改归档不会在未验证前移回源路径；注入恢复失败只返回 `recovery_required`；
- execute/reconcile 双确认、strict JSON 精确字段、重复键/额外行/畸形响应、响应丢失和仓库 Nginx 片段 SHA 绑定；
- Linux 平台额外用真实 `/usr/bin/mv`、目录/file fsync、`flock` 争用、`/proc` FD、Unix socket 和 loopback HTTP health/uploads 404 完成集成模拟；Windows 本地运行会明确跳过这一项，而不是伪装通过。

正式 apply 后仍需按总维护顺序继续：重建并验证 STT、运行权限 hardener 默认审计/应用、完成登录/上传/下载/邮件/聊天/STT 的外部最小闭环。这个工具的成功回执不等同于整套正式发布完成。
