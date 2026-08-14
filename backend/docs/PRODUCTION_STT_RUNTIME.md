# 生产离线 STT 运行时重建与恢复

本流程为正式生产 STT 的唯一受支持重建链。它固定使用 CPython 3.11.15、30 个 Linux x86_64 wheel、`faster-whisper 1.2.1` 和固定 revision 的 `base` 模型。生产主机全程断网安装；正式 release 不读取、不复制、不执行旧运行时字节。旧 `storage/stt/venv`、`python-runtime` 和 `models` 只保留给旧 Debug 客户端兼容与故障回退，安装器不会删除或替换它们。

## 固定边界

- 平台：Linux x86_64，GNU libc 不低于 2.17。
- Python：Astral `python-build-standalone` 20260718，CPython 3.11.15 GNU `install_only_stripped`。完整 `pgo+lto-full` 工件只用于补齐许可证和来源证据，不进入生产运行时。
- 依赖：`tools/stt/offline/requirements-linux-x86_64-cp311.lock` 中恰好 30 个 wheel；禁止 sdist、Windows wheel 和 musllinux wheel；安装使用 `--no-index --require-hashes --no-deps`。
- 模型：`Systran/faster-whisper-base@ebe41f70d5b6dfa9166e2c581c45c9c0cfc57b66`，只物化四个普通文件。完整大小和 SHA-256 见 `tools/stt/offline/model-manifest.json`。
- 模型旧缓存曾观察到的四个链接目标 basename（仅用于审计对照，不会被复用）：`config.json -> 867cf1a0fece1394e01d55e287ba2f09a577c046`、`model.bin -> d01c3014881c9c6f3133c182f3d2887eb6ca1c789a7538c5c007196857a0a6a9`、`tokenizer.json -> 7818adb6de9fa3064d3ff81226fdd675be1f6344`、`vocabulary.txt -> c9074644d9d1205686f16d411564729461324b75`。
- 生产布局：`storage/stt/releases/<release-id>` 是 `root:www`；目录与仅 `*/bin/*` 普通文件为 `0750`，其他普通文件为 `0640`；`current` 只能是 `root:www` 且精确指向同文件系统下 `releases/py31115-fw121-ebe41f70d5b6-<12位十六进制>`。
- 正式推理合同：`base/cpu/int8`。业务请求与安装探针使用相同配置；环境中的 model/device/compute-type 覆盖只对无正式 `current` 的 legacy 路径生效。

## 1. 可信工作站获取和构建

只有这一阶段允许访问公开互联网。默认命令只输出计划，不建目录、不下载：

```powershell
python backend/tools/prepare-offline-stt-runtime.py
python backend/tools/prepare-offline-stt-runtime.py --download-license-evidence
python backend/tools/prepare-offline-stt-runtime.py --download
python backend/tools/prepare-offline-stt-runtime.py --build `
  --zstandard-wheel D:\易运盈\.tools_deps\python\zstandard-0.25.0-cp312-win_amd64\zstandard-0.25.0-cp312-cp312-win_amd64.whl
```

默认输出固定在 `D:\易运盈\.tools_deps\stt`。36 个运行工件与 3 个非执行许可证证据分层管理；`--download-license-evidence` 不会选择 Python、wheel 或模型。每个响应先写入唯一 `.partial`，再校验固定 size/SHA-256、刷盘并以 write-through 原子改名。构建前会拒绝多余文件、残留 partial、链接、重解析点、特殊文件和硬链接。

`--zstandard-wheel` 是构建必需的可信 Windows 工作站工具，只用于读取完整 Python `.tar.zst` 中的许可证；其唯一允许身份见 `tools/stt/offline/builder-tools.json`。它自身不会被打入生产 payload，但其声明和提取的 BSD-3-Clause 许可证证据会进入 notices。构建脚本不信任 ambient `zstandard` 包或系统 `tar`。`THIRD_PARTY_NOTICES.json` 的完整 size/SHA-256 也被代码锁定，安装器拒绝 source bundle 自清单夹带文件。

构建输出：

- `stt-offline-source-bundle-20260718.tar`
- `stt-offline-source-bundle-20260718.tar.sha256`
- `bundle-receipt.json`
- `source/tree-manifest.json`
- `source/licenses/THIRD_PARTY_NOTICES.json` 与逐组件许可证原文
- `source/metadata/dependency-closure.json`（按 Linux/CPython 3.11.15 marker 求值的全部依赖边）
- `source/metadata/builder-tools.json`（工作站构建器的独立锁，不包含 wheel 字节）

若构建中断，不要覆盖或手工拼接半成品；保留现场，在新的空输出目录重新执行并比较最终 bundle SHA-256。

## 2. 上线前门禁

1. 先部署本版本的 `config/app.php`、`SpeechController.php` 和 `tools/stt/transcribe.py`。停止全部 `www` worker 后，按权限加固 runbook 完成带 ACL/拓扑快照与自动回滚的 legacy STT 只读冻结，再重启 worker；仅有 dry-run 报告不算完成。`transcribe.py` 及其父目录、backend root、`storage`、`storage/stt` 必须已是 root-owned 且不可由 `www` 写入；安装器会回读父链 device/inode/mode，并把 wrapper 与内容寻址 payload 中的副本逐字节比较后才执行。
2. 固定 `known_hosts` 文件；禁止 `AutoAddPolicy`、SSH agent、自动发现私钥或命令行密码。
3. SSH 凭据必须是当前 Windows 用户 DPAPI 封装的 JSON：wrapper 字段仅允许 `schemaVersion=1`、`purpose=yiyunying-production-ssh`、`protection=Windows-DPAPI-CurrentUser`、`entropyBase64`、`ciphertextBase64`、`ciphertextSha256`、`payloadSha256`；解密 payload 仅允许 `host`、`port`、`username=root`、`password`。
4. 记录并人工核对 bundle SHA-256 与 `source/tree-manifest.json` SHA-256。不得从终端历史、聊天或参数传入密码。

先执行默认 dry-run；它会完整校验本地 bundle 并只读检查生产 Linux/架构/glibc、实际 `storage/stt` 卷至少 2 GiB 可用空间、`/tmp` 至少 512 MiB、用户、`tar` 等命令、root-owned 非链接且 Python >= 3.9 的系统解释器、父链和 current 形态，不上传和不修改：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File backend/tools/install-production-stt-runtime.ps1 `
  -CredentialFile <DPAPI-SSH.json> -KnownHosts <known_hosts> `
  -Bundle D:\易运盈\.tools_deps\stt\stt-offline-source-bundle-20260718.tar `
  -BundleSha256 <bundle-sha256> -Python <reviewed-python.exe>
```

## 3. 维护窗口执行

确认没有并发 STT 部署/权限调整，保存 dry-run 输出后，使用三项独立确认：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File backend/tools/install-production-stt-runtime.ps1 `
  -CredentialFile <DPAPI-SSH.json> -KnownHosts <known_hosts> `
  -Bundle D:\易运盈\.tools_deps\stt\stt-offline-source-bundle-20260718.tar `
  -BundleSha256 <bundle-sha256> -Python <reviewed-python.exe> -Execute `
  -Confirm install-offline-stt-cpython-3.11.15-faster-whisper-1.2.1 `
  -MaintenanceConfirmed stt-current-switch-and-rollback-reviewed `
  -ConfirmManifestSha <tree-manifest-sha256>
```

启动器默认从 `D:\易运盈\.tools_deps` 加载并验证 Paramiko 5.0.0；如工具依赖位于其他受审目录，必须显式传入 `-ReleaseToolsPath`。该路径和 Python 均拒绝重解析点。

安装器只上传本地派生并再次哈希的生产 payload 到 `/tmp` 下原子创建的唯一 root-only `0700` 随机目录；archive、helper 和唯一 partial 均在该目录内，避免公共 `/tmp` 的 symlink/test-open 竞态。生产端使用已验证的 root-owned 系统 Python 解包；wheel 安装和两类探针均在 `unshare --net` 网络命名空间中运行。激活前后均以真实 `www` 身份执行：

- Python 3.11.15 和全部 30 个包版本导入检查；
- `ldd` 无 `not found`，ELF 不含外部 RPATH/RUNPATH；
- 固定 1 秒 WAV 对固定本地模型的最小推理；
- 实际 `tools/stt/transcribe.py --runtime-probe`；
- `www` 对 release 的写入必须被拒绝。

只有全部通过，才用同卷 `os.replace` 原子切换 `current`，回读并再次探针。成功凭证必须恰好包含一条 `STT_RUNTIME_RECEIPT=`，且 `status=committed`、Python/`faster-whisper`/source manifest/payload SHA/release id 全部匹配。

## 4. 回滚与 `RECOVERY_REQUIRED`

- 激活后任何检查失败，安装器会原子恢复先前受信 `current`，回读并以 `www` 重新探针。
- 首次安装没有先前受信 `current` 时，失败会移除 `current`，旧 legacy 目录仍保持原样；返回 `RECOVERY_REQUIRED=stt-no-prior-trusted-current`，不得称为上线成功。
- stage、destination、锁、远端临时文件或回执的状态只要无法证明，统一返回 `RECOVERY_REQUIRED=...`。此时停止重试，不删除旧 runtime，也不手工修改 `current`。
- 先运行权限加固只读审计并保存 `current`、release receipt、目录形态和哈希证据。确认是已提交 release 但仅临时清理失败时，保留 release/current；确认自动回滚完成时，继续使用先前受信 release。任何 `.partial`、prepared destination 或锁的清理都必须另开恢复操作，精确确认路径、owner、mode、hash 后执行。

## 5. 最小闭环验收

本地必须通过：

```powershell
python backend/tools/tests/test_prepare_offline_stt_runtime.py
python backend/tools/tests/test_install_production_stt_runtime.py
python backend/tools/tests/test_stt_runtime_selection.py
python backend/tools/tests/test-production-permission-hardening.py
powershell -NoProfile -File backend/tools/check.ps1
```

生产正式验收以 committed receipt、`current` 回读、真实 `www` 的库探针和实际 wrapper 探针四项同时成立为准。仅有 bundle、SSH 上传、release 目录或一次 import 成功，都不等于正式上线。
