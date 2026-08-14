# Production media runtime

This runbook installs the server-side FFmpeg/FFprobe runtime used by media
validation and optimization. It is deliberately separate from the application
deployment. Production never downloads a binary or container image.

## Frozen artifact

The reviewed artifact is the `linux/amd64` child of
`mwader/static-ffmpeg:8.1.2`:

- OCI index: `sha256:33f770f812cbfc3de96c547157fc9faf8bd95a36481753439ffa761045167585`
- amd64 manifest: `sha256:3bfa407c614a29a4535f1e3220fd9f6bc9cd7c25483036962e3c8ff711b56e01`
- config: `sha256:34e6fa0a15eb08744e6a2926eead1d91a6bd7e3764278638f6b62d3fe0b386e2`
- compressed layer: `sha256:9ec618fc9dc33fd2997bb09df3244055b00d519361a6d7083462638b414a939e`,
  `123477739` bytes
- uncompressed layer diff ID:
  `sha256:c501421dac74c35e228240b1da269451b943dec115e0ad2aaafefce6f44c9325`
- `ffmpeg`: `sha256:7b3fb9508c20166ab3ba236a9585c3e22e903880723c1a6448e69ae6e4cd88d2`
- `ffprobe`: `sha256:fe39eb91eb04dd18dff3870a87b59e41be997476c2d373c46ff7e12bb284743c`
- `versions.json`:
  `sha256:494357b48cdfb7710c804b66f3794d0b7e1b04cf05f6c3c2d4ab131f25684bf1`

The index, child-manifest and config descriptors above are frozen provenance
from the offline Registry v2 audit. The installer is intentionally given only
the local compressed layer, so it does **not** claim to reconstruct those three
registry JSON links during production installation. It independently verifies
the layer digest and size, streaming diff ID, all three payload hashes and the
`versions.json` value, then reports both the locally verified scope and the
offline provenance pins in `OCI_PROVENANCE_PIN`. A new image tag or a layer
with the same filenames is not an allowed substitute.

The two binaries are x86-64 musl static PIE executables with no ELF
interpreter or `DT_NEEDED` dependencies. The build uses FFmpeg 8.1.2,
`--toolchain=hardened`, static linking, GPL and version-3 features. The
upstream FFmpeg 8.1.2 release is the current 8.1 security point release.

Do not substitute the old John Van Sickle 7.0.2 build: it is non-PIE and
contains obsolete libraries. Do not substitute the BtbN build: it requires
GLIBC 2.28 and cannot run on CentOS 7's GLIBC 2.17.

The selected musl-static binaries do not depend on CentOS 7's GLIBC and are
the only reviewed CentOS 7 runtime candidate. Compatibility is still accepted
only after the real `www` smoke test on the target kernel. The control script
requires a trusted Python 3.8+ at one of the two reviewed system paths; stock
CentOS 7 Python is not enough. If neither path qualifies, provision Python
offline under root control and repeat the dry run—never fall back to the
application's writable virtual environment.

## Safety boundary

Use `backend/tools/install-production-media-runtime.py`. It defaults to a
read-only dry run and accepts the OCI layer only through `--layer`. The host is
never allowed to contact Docker Hub, a mirror, GitHub, or another package
repository.

The OCI layer contains an otherwise normal container root filesystem,
including font symlinks and two absolute virtual-root font links. The installer
scans every tar member but extracts only three unique, top-level regular files:
`ffmpeg`, `ffprobe`, and `versions.json`. Never run `tar -x` on the complete
layer as root.

The installer additionally enforces:

- pinned layer size, compressed SHA-256, streaming diff ID and member hashes;
- no path traversal, duplicate paths, reserved duplicate basenames, devices,
  FIFOs, privileged modes, or escaping links;
- a unique `/tmp/.yiyunying-media-runtime-8.1.2-<random>.tar.gz` stage owned by
  root with mode `0600`;
- `Linux/x86_64` twice (read-only shell preflight and the isolated remote
  installer), plus ELF64 little-endian `ET_DYN`/`EM_X86_64` headers on both
  pinned binaries;
- only `/usr/bin/python3` or `/usr/local/bin/python3`, after an `lstat` and
  `readlink` walk of every symlink and ancestor proves root ownership and no
  group/world write permission; application paths such as `storage`, `stt` or
  a project `venv` are never candidates and are never executed;
- a cleared interpreter environment and `-I -S -B`; no user/site imports and
  no bytecode writes during preflight or installation;
- at least 1 GiB free on the runtime filesystem;
- root-controlled, group/world-nonwritable ancestors and a root-owned immutable
  version directory and files, with no hard links, symlinks, capabilities,
  ACLs, or other extended attributes on the installed runtime;
- execution as the real `www:www` identity, not merely as root;
- a 15-second ceiling and bounded output for every runtime probe;
- version, hardened/static build configuration, GPLv3 license text, H.264
  (`libx264`) and AAC encoder checks;
- a local-only protocol-whitelist rejection check that fails before any
  network connection;
- a 16x16 H.264/AAC MP4 encode followed by FFprobe JSON readback;
- atomic `current` symlink replacement and automatic readback rollback.

Immediately before switching, the installer durably records the exact old
target in the root-only `0400` file
`.previous-target-8.1.2-3bfa407c614a`. This receipt is not an instruction to
switch blindly: a rollback still has to validate that the recorded relative
target exists inside the runtime root. It is create-once: an unexpected receipt
blocks a later switch for operator review. If `current` already points at this
version, a repeated execution performs the complete hash/metadata audit and
`www` smoke test only; it neither replaces `current` nor overwrites the receipt.

## Dry run

Set the password in the current process only. Do not put it in a command line,
JSON file, shell history, or documentation.

```powershell
$env:YY_SSH_PASSWORD = '<current production SSH password>'
python backend/tools/install-production-media-runtime.py `
  --host '<production SSH host>' `
  --user root `
  --known-hosts '<private pinned known_hosts path>' `
  --layer 'D:\易运盈\.tools_deps\ffmpeg\mwader-8.1.2-amd64\sha256-9ec618fc9dc33fd2997bb09df3244055b00d519361a6d7083462638b414a939e.tar.gz'
Remove-Item Env:YY_SSH_PASSWORD
```

A successful dry run prints `MEDIA_RUNTIME_PREFLIGHT=pass` and explicitly
states that no upload, installation, symlink switch, or application
configuration change occurred. Its one remote command performs only identity,
filesystem, disk, platform and trusted-interpreter read checks. `-B` and the
isolated, site-disabled Python probe prevent cache writes.

## Execute

Execution has two independent acknowledgement tokens in addition to
`--execute`. Run it only in a reviewed maintenance window after the dry run:

```powershell
$env:YY_SSH_PASSWORD = '<current production SSH password>'
python backend/tools/install-production-media-runtime.py `
  --host '<production SSH host>' `
  --user root `
  --known-hosts '<private pinned known_hosts path>' `
  --layer 'D:\易运盈\.tools_deps\ffmpeg\mwader-8.1.2-amd64\sha256-9ec618fc9dc33fd2997bb09df3244055b00d519361a6d7083462638b414a939e.tar.gz' `
  --execute `
  --confirm install-production-media-runtime-8.1.2 `
  --maintenance-confirmed runtime-install-and-rollback-reviewed
Remove-Item Env:YY_SSH_PASSWORD
```

The immutable installation path is:

```text
/opt/yiyunying/media-runtime/8.1.2-3bfa407c614a/
```

The stable invocation paths are:

```text
/opt/yiyunying/media-runtime/current/ffmpeg
/opt/yiyunying/media-runtime/current/ffprobe
```

The outer installer accepts success only when the SSH command exits normally,
stderr is empty and stdout contains exactly one duplicate-key-free JSON object proving all of
the following: `MEDIA_RUNTIME_INSTALL=pass`, version `8.1.2`, current target
`8.1.2-3bfa407c614a`, platform `linux/amd64`, a boolean repeat flag and at least
1 GiB free. Extra output, two receipts, missing fields, wrong values, malformed
JSON, a nonzero status, timeout or lost channel are not success. Only after
strict parsing does the local tool print the canonical
`MEDIA_RUNTIME_RECEIPT=...` line.

No switch occurs unless extraction and the complete `www` smoke test pass. If
the atomic `os.replace` succeeds but its directory `fsync`, post-switch hash,
symlink, or executable readback fails, the installer replaces `current`
atomically with its exact previous target and verifies that rollback. If that
rollback cannot be proved, it emits `RECOVERY_REQUIRED` and does not claim a
known state. The previous version is retained for rollback; do not remove it
during the same release window.

Once the remote installer command has started, losing its exit status or its
single success receipt is an indeterminate production state: the remote
process may already have completed `os.replace`. The outer installer therefore
wraps every such failure—including an operator `Ctrl+C`/`KeyboardInterrupt`—as
`RECOVERY_REQUIRED: remote install result uncertain`, even when stage cleanup
succeeds. A second interrupt or failure during cleanup/SSH close is reported
separately and cannot replace that primary uncertainty marker. Stop the release
and reconcile `current` plus the root-only
`.previous-target-8.1.2-3bfa407c614a` receipt in a fresh trusted SSH session;
do not infer rollback from a clean `/tmp` directory and do not announce
deployment success from the absence of an error file.

Stage creation uses an exclusive, random path and a token-bound ownership
marker. If an SSH/SFTP response is lost, cleanup may remove only a root-owned
`0600` regular file that either contains that exact marker or has the complete
pinned layer size and SHA-256. A partial or ambiguous file is left in place and
reported as `RECOVERY_REQUIRED`; a cleanup failure can never turn the run into
success. Audit the exact reported random path and remove only that regular file
after confirming ownership, mode and content. Do not use a wildcard cleanup.

## Application integration gate

Installation alone does not authorize media optimization. After the upload
pipeline changes are frozen, the application must be wired to the two absolute
`current` paths above. PATH lookup, `exec`, a shell command string, a caller
supplied executable path, or a caller supplied FFmpeg option is prohibited.

Before enabling the feature, the integrated application/FPM path must prove:

1. `proc_open` remains available in the FPM configuration.
2. Both executable paths resolve inside the pinned runtime root and are not
   writable by `www`.
3. Every input is an absolute local regular file, and FFprobe/FFmpeg use the
   reviewed local-file protocol whitelist.
4. Process time, output size, dimensions, duration and file size remain bounded.
5. The FPM-triggered minimum media loop passes through the same production code
   path. A CLI-only test does not prove the FPM/SELinux execution boundary.

Until all five checks pass, keep optimization fail-closed and do not mark the
runtime gate ready in deployment reporting.

## Licensing and provenance

The selected FFmpeg build reports GPL version 3 or later. Server-only internal
operation does not place the binary in the Android/customer download package.
If this binary is later redistributed, include the GPLv3 text, complete
third-party notices and the corresponding-source offer required for that
distribution. The OCI manifest has no verified Cosign/referrer signature; the
pinned registry digest provides immutable integrity but not publisher signing.
That supply-chain limitation must remain in the release risk register until a
reproducible signed build replaces it.
