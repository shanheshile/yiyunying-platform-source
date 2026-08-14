# Production Python runtime

This runbook provisions the root-controlled Python used by the separate
production media-runtime installer. It does not change the application code,
PHP-FPM configuration, database, download site, or Android package. Production
never downloads Python or contacts GitHub, PyPI, a mirror, or a package
repository.

## Frozen source and rejection record

The only accepted source is the `x86_64-unknown-linux-musl` static-full child
from `astral-sh/python-build-standalone` release `20260718`:

- artifact:
  `cpython-3.12.13+20260718-x86_64-unknown-linux-musl-noopt+static-full.tar.zst`
- compressed size: `35579339` bytes
- compressed SHA-256:
  `4f5ba66719827d2c97e6562987e8f1c79b2f2e2d661548b6fc2e02d04828a798`
- decompressed tar size: `257556480` bytes
- decompressed tar SHA-256:
  `a38850ff4a7bd20ad0d1b30326712c2e947b5b02842505ee20c09edf60dfcc9f`
- complete archive: `6480` members, `5432` regular files, `1048`
  relative contained symlinks, no directory entries, hard links, special
  files, escaping links, privileged modes, or other top-level roots
- complete regular-file payload: `252742364` bytes
- deployed `python/install/` projection: `6171` members, `5123` regular
  files, `1048` symlinks, `159084213` regular-file bytes and `337` derived
  parent directories
- canonical projection content-manifest SHA-256:
  `56ae61726d6f9e3620be87724d5b5fd8ec835b08761986b5fd46fa1d78c21c9c`
- `python/install/bin/python3.12`: `47591248` bytes, SHA-256
  `8a92a92d7612969cf0865f1e08cf46f691b6ae44d5b72b7ed56052a224d7fa84`

The content manifest is the SHA-256 of bytewise-sorted records. A regular file
record is `F\0<relative>\0<size>\0<file-sha256>\n`; a symlink record is
`L\0<relative>\0<relative-target>\n`. This binds every deployed byte and link,
not only archive totals.

The executable is independently parsed as little-endian ELF64, x86-64
`ET_EXEC`. It has no `PT_INTERP`, `PT_DYNAMIC`, or `DT_NEEDED` dependency.
The older `install_only_stripped` candidate (size `28196156`, SHA-256
`d62168126b2d92e5db649cfe89fb13bf165654c027707c0ef80d7823757c9b1d`)
is **rejected**: its executable requests `/lib/ld-musl-x86_64.so.1`, which the
CentOS 7 production host does not provide. Keep it only as rejected audit
evidence; never upload or install it.

## Local decoding and canonical payload

Use `backend/tools/install-production-python-runtime.py`. The local machine
must provide Paramiko 5.0.0 and one reviewed Zstandard decoder:

- Python 3.14 standard-library `compression.zstd`; or
- the offline `zstandard==0.25.0` CPython 3.12 module.

The current offline CPython 3.12 paths are:

```powershell
$env:PYTHONPATH = @(
  'D:\易运盈\.tools_deps',
  'D:\易运盈\.tools_deps\python\zstandard-0.25.0-cp312-win_amd64\site-packages'
) -join ';'
```

Execution never uploads the full source archive. After validating all source
pins, it locally creates one deterministic tar.gz containing only the reviewed
`python/install/` projection. It adds the 337 required directories, records
root ownership, normalizes non-executable files to `0644`, executable files to
`0755`, and directories to `0755`, and keeps only reviewed relative symlinks.
This is mandatory because every upstream projection file is group-writable
(`0664` or `0775`). The derived payload is rescanned before upload. Its frozen
size is `52390506` bytes and its SHA-256 is
`8c36fc15be9e1acbe2869342551470d200a6241aba23ef2bf8b1f7d976e05a89`;
that exact fingerprint must match the root-owned remote stage before
extraction. The temporary local payload is identity-bound and deleted after
the run.

## Trust and credential source

The current production SSH endpoint is `154.12.25.203:22`, user `root`. The
explicit OpenSSH trust file is
`C:\Users\Administrator\.ssh\known_hosts`; its reviewed production ECDSA key
fingerprint is `SHA256:rJXmIbEZbQUEDkUe0DfDrqmBbkrlLYg8KoqYttAVEeo`.
Unknown or changed keys fail closed.

The existing credential source is the current-user DPAPI file
`C:\Users\Administrator\AppData\Local\YiyunyingDeploy\credentials.json`.
Do not copy its protected value into a command, JSON handoff, log, clipboard,
or documentation. Decrypt it only into the current process, pass it through
`YY_SSH_PASSWORD`, and clear both variables in `finally`.

## Dry run

The default mode validates the complete local artifact and performs one
production read-only SSH preflight. It checks root identity, Linux/x86-64,
GNU tar and required system tools, root-owned non-writable ancestors, the
runtime target and stable-link state if already present, an absent install
lock, and at least 1 GiB free. It never discovers or runs Python through PATH;
only an already installed pinned target may be executed during readback.

```powershell
$ErrorActionPreference = 'Stop'
$python = 'C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
$artifact = 'D:\易运盈\.tools_deps\python\cpython-3.12.13-20260718-x86_64-musl-static\cpython-3.12.13+20260718-x86_64-unknown-linux-musl-noopt+static-full.tar.zst'
$knownHosts = "$env:USERPROFILE\.ssh\known_hosts"
$credentialFile = "$env:LOCALAPPDATA\YiyunyingDeploy\credentials.json"
$env:PYTHONPATH = @(
  'D:\易运盈\.tools_deps',
  'D:\易运盈\.tools_deps\python\zstandard-0.25.0-cp312-win_amd64\site-packages'
) -join ';'
$stored = Get-Content -LiteralPath $credentialFile -Raw -Encoding UTF8 | ConvertFrom-Json
$secure = ConvertTo-SecureString ([string] $stored.sshPasswordDpapi)
$plain = [Net.NetworkCredential]::new('', $secure).Password
try {
  $env:YY_SSH_PASSWORD = $plain
  & $python backend/tools/install-production-python-runtime.py `
    --host 154.12.25.203 --port 22 --user root `
    --known-hosts $knownHosts --artifact $artifact
  if ($LASTEXITCODE -ne 0) { throw 'Production Python runtime dry-run failed.' }
}
finally {
  Remove-Item Env:YY_SSH_PASSWORD -ErrorAction SilentlyContinue
  Remove-Item Env:PYTHONPATH -ErrorAction SilentlyContinue
  $plain = $null; $secure = $null; $stored = $null
}
```

Success prints `PYTHON_ARTIFACT_PIN`, `PYTHON_RUNTIME_PREFLIGHT=pass`, and an
explicit statement that no payload upload, extraction, installation, or
stable-link switch occurred.

## Remote payload validation without installation

The default dry run intentionally does not prove that the production kernel,
mount policy, GNU tar, normalized tree and pinned static interpreter work
together. Before another install attempt, run the explicit remote validation
mode with the exact validation-only confirmation:

```text
--remote-validate
--confirm validate-production-python-runtime-3.12.13
```

Use the same PowerShell wrapper, artifact, pinned host key and in-process DPAPI
credential handling shown above. Do not add `--execute` or the maintenance
confirmation. The validation confirmation does not authorize installation.

This mode rebuilds and rescans the same deterministic derived payload. It
creates one unique root-owned `0600` archive at
`/tmp/.yiyunying-python-runtime-3.12.13-<token>.tar.gz` and one unique
root-owned `0700` work directory at
`/tmp/.yiyunying-python-runtime-validate-3.12.13-<token>`. It extracts,
normalizes, audits and runs the pinned interpreter smoke test there, then
removes both exact temporary nodes and proves their absence. It never creates
or changes `/opt`, the install lock, rollback receipt, immutable target,
`/usr/local/bin/python3`, or any stable-link staging node. Cleanup is permitted
only for a node this process proved it created; a correct-looking pre-existing
node is not adopted or removed.

If the exclusive stage-creation command reports a collision or its SSH reply
is lost, creation ownership is unconfirmed. The wrapper does not run automatic
cleanup and does not adopt the path merely because its marker, size or payload
hash looks correct. It returns the exact `remote_stage` and `token` as
`RECOVERY_REQUIRED` evidence for read-only reconciliation.

Success emits one strictly parsed `PYTHON_RUNTIME_REMOTE_VALIDATE_RECEIPT`.
Any timeout, interrupt, malformed response, failed smoke test or cleanup that
cannot be proven is `RECOVERY_REQUIRED`. Reconcile only the exact
`remote_stage` and `work` paths from its `recovery_identifiers`; never use a
wildcard. A successful remote validation is evidence for payload/runtime
compatibility, not evidence that the runtime was installed.
The PASS receipt is printed only after the confirmed remote stage is removed
with empty stdout/stderr, SSH closes successfully, and the identity-bound local
derived payload is removed successfully.

## Execute

Execute only in a reviewed maintenance window after the same source commit and
the immediately preceding dry run pass. Use the identical PowerShell wrapper,
adding these three arguments:

```text
--execute
--confirm install-production-python-runtime-3.12.13
--maintenance-confirmed python-runtime-install-and-rollback-reviewed
```

Execution uses a unique root-owned `0600` upload stage and an exclusive
root-owned `0700` install lock. It extracts into an exact random directory
inside `/opt/yiyunying/python-runtime`, reapplies all ownership and mode
normalization, rejects hard links, special files, escaping/broken links,
non-root ownership, group/world writes and privileged bits, then runs the real
runtime with a cleared environment and `-I -S -B`. The smoke test requires
exact Python `3.12.13` and imports `ssl`, `sqlite3`, `ctypes`, `bz2`, `lzma`,
`zlib`, multiprocessing and the other required standard-library modules.

The immutable target and stable entry are:

```text
/opt/yiyunying/python-runtime/3.12.13-20260718/
/usr/local/bin/python3
```

The target directory move and stable symlink replacement are same-filesystem
atomic operations. An existing exact target is fully audited and smoke-tested
instead of overwritten. If post-switch verification fails, the exact previous
reviewed runtime symlink (or absence) is restored and read back. A successful
run emits exactly one duplicate-key-free JSON receipt, which the local wrapper
strictly parses. `PYTHON_RUNTIME_RECEIPT` is not printed until the confirmed
remote stage is removed and read back absent, the cleanup command returns
strictly empty stdout/stderr, SSH closes successfully, and the identity-bound
local derived payload is removed. Any secondary failure suppresses PASS.

The remote installer suppresses command output and preserves only two private
protocol descriptors. On `ERR`, `INT`, `TERM` or `HUP`, it attempts the exact
rollback and cleanup and emits exactly one diagnostic line:

```text
PYTHON_RUNTIME_FAILURE_PHASE=<allowlisted-phase>;EXIT_CODE=<matching-status>
```

The fixed phases are `archive`, `parents`, `lock`, `extract`, `normalize`,
`tree-audit`, `python-smoke`, `target-move`, `stable-switch`, `post-smoke` and
`cleanup`. The local wrapper rejects an unknown phase, a second line, free-form
stderr, stdout on failure, or an exit-code mismatch. A valid phase is copied
into `recovery_identifiers.failure_phase`; if the SSH result is lost or the
success receipt is malformed, the value is `unavailable` rather than a guess.

Immediately before the first stable-link switch, the installer create-once
records `missing` or the exact previous reviewed target in root-owned mode
`0400` at
`/opt/yiyunying/python-runtime/.previous-target-3.12.13-20260718`. Repeated
execution never overwrites it. A malformed receipt or one that conflicts with
the current pre-switch state blocks the switch for recovery review.

## Recovery boundary

Once the remote install command begins, a lost SSH status, timeout, malformed
receipt, operator interrupt, non-protocol output, or any ambiguous response is
`RECOVERY_REQUIRED`; it is never success. The local error includes a
`recovery_identifiers` JSON object containing the allowlisted failure phase (or
`unavailable`), exact non-secret 32-hex transaction token, and its `work`,
`remote_stage`, `link_stage`, `rollback_link`, `lock`, `target`, `stable` and
`receipt` paths. It deliberately does not echo the SSH password, commands or
remote stdout/stderr. Preserve that one object with the maintenance record.
If stage cleanup, SSH close or local-payload cleanup is also uncertain, the
primary recovery reason and all its identifiers remain intact and a fixed
`cleanup_uncertainties` list is appended. Secondary exception text is never
substituted for the primary evidence.

The earlier failed transaction that predates this phase protocol cannot be
retrospectively assigned a proven phase. A clean rollback readback proves only
that its reviewed paths are absent; it does not distinguish extraction, tree
audit and interpreter-smoke failures. Use `--remote-validate` to close that
diagnostic gap without installing anything, and do not weaken a safety gate on
the basis of a guessed root cause.

Reconnect through the pinned host key and perform read-only reconciliation of
all reported exact paths before retrying:

```text
/usr/local/bin/python3
/opt/yiyunying/python-runtime/3.12.13-20260718/
/opt/yiyunying/python-runtime/.install-lock
/opt/yiyunying/python-runtime/.previous-target-3.12.13-20260718
/opt/yiyunying/python-runtime/.stage-3.12.13-20260718-<reported-token>
/tmp/.yiyunying-python-runtime-3.12.13-<reported-token>.tar.gz
/usr/local/bin/.python3.yiyunying-<reported-token>
/usr/local/bin/.python3.rollback-<reported-token>
```

Never use wildcard cleanup. The installer automatically removes a stage only
after the same process received positive proof that its exclusive creation
succeeded. It never uses a marker, size or payload hash to take ownership of an
unconfirmed or collided path. During manual recovery, first record the exact
path, owner, mode, link state, marker and payload fingerprint as read-only
evidence; removal requires a separate reviewed decision. Do not substitute
`/www`, an application or STT virtual environment, the BaoTa panel Python,
PATH lookup, or a caller-provided interpreter.

For each exact reported path, first use `stat -c '%n|%a|%U|%G|%F' -- <path>`;
use `readlink -- <path>` only when `stat` proves it is a symbolic link, and use
`sha256sum -- <remote_stage>` only when it is a root-owned `0600` regular file.
Record absent paths explicitly. Do not delete the work directory, lock,
rollback receipt, target, stable link or upload stage merely because the SSH
client lost its response. Compare the read-only evidence with the receipt and
this runbook, then choose and review one exact recovery action.

After a successful receipt, rerun the default dry run. Then rerun
`install-production-media-runtime.py` dry-run: its existing trust-chain audit
must independently accept `/usr/local/bin/python3` and only then may the FFmpeg
maintenance-window installation be considered.
