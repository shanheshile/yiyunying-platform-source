# Production runtime deployment evidence — 2026-08-15

This record separates runtime installation from application deployment. It
contains no credential, protected value, mail setting, application key, or
unredacted remote output.

## Source chain

- `db420d55ff77c7d8f05809dc1a475e11c2a8bcf3` added the fail-closed
  production Python installer.
- `217e8febac8f1ab560ee907cf5455c8d51945312` added the explicit Android
  device-evidence / user-risk-waiver release gate.
- `ffc6deae5442b4d27da08aa8357256d0710900e2` added isolated remote Python
  validation and fixed failure-phase diagnostics.
- Each commit was pushed to `origin/main` and read back at the same SHA before
  the corresponding production action.

## Python 3.12.13 control runtime

Frozen source artifact:

- compressed size: `35,579,339` bytes
- compressed SHA-256:
  `4f5ba66719827d2c97e6562987e8f1c79b2f2e2d661548b6fc2e02d04828a798`
- projected content-manifest SHA-256:
  `56ae61726d6f9e3620be87724d5b5fd8ec835b08761986b5fd46fa1d78c21c9c`
- derived payload size: `52,390,506` bytes
- derived payload SHA-256:
  `8c36fc15be9e1acbe2869342551470d200a6241aba23ef2bf8b1f7d976e05a89`

The first execute attempt returned `RECOVERY_REQUIRED` without a success
receipt. Two independent, read-only samples approximately 5.5 seconds apart
then confirmed that the install lock, rollback receipt, remote upload stage,
stable link, immutable target and internal work directory were all absent,
with zero related processes. The attempt was therefore classified as a clean
rollback, not a partial installation. No manual deletion or unlock was used.

After the diagnostic update, `--remote-validate` passed. It extracted and
audited the exact derived payload in an isolated `/tmp` namespace, executed the
pinned interpreter smoke test, proved cleanup, and did not create or modify the
runtime target, install lock, rollback receipt, or stable link.

The subsequent execute returned the strict success receipt:

- version: `3.12.13`
- platform: `linux/amd64`
- immutable target:
  `/opt/yiyunying/python-runtime/3.12.13-20260718`
- stable link: `/usr/local/bin/python3`
- previous target: absent
- stable link switched: yes

A separate post-install dry run reported:

- `TARGET_STATE=ready`
- `STABLE_STATE=ready`
- `RECEIPT_STATE=ready`
- preflight: pass

## FFmpeg / FFprobe 8.1.2 media runtime

Frozen OCI identities:

- image: `mwader/static-ffmpeg:8.1.2`
- linux/amd64 manifest:
  `sha256:3bfa407c614a29a4535f1e3220fd9f6bc9cd7c25483036962e3c8ff711b56e01`
- layer:
  `sha256:9ec618fc9dc33fd2997bb09df3244055b00d519361a6d7083462638b414a939e`
- uncompressed diff ID:
  `sha256:c501421dac74c35e228240b1da269451b943dec115e0ad2aaafefce6f44c9325`
- FFmpeg SHA-256:
  `7b3fb9508c20166ab3ba236a9585c3e22e903880723c1a6448e69ae6e4cd88d2`
- FFprobe SHA-256:
  `fe39eb91eb04dd18dff3870a87b59e41be997476c2d373c46ff7e12bb284743c`

The production dry run passed using the pinned `/usr/local/bin/python3`.
Execution then passed the root-controlled tree audit and the `www` user media
smoke test, including local H.264/AAC generation and FFprobe JSON validation.
The strict success receipt reported:

- version: `8.1.2`
- platform: `linux/amd64`
- current target: `8.1.2-3bfa407c614a`
- previous target: none
- already current: false

A separate post-install dry run passed and revalidated the immutable files,
stable `current` link, provenance and `www` smoke test.

## Explicitly not claimed by this record

- The current backend application code has not yet been deployed by these
  runtime operations.
- The offline STT runtime, model and Python 3.11 dependency set have not yet
  been installed.
- PHP-FPM socket and environment hardening, `cgi.fix_pathinfo=0`, the Nginx
  static-only uploads location, the seven-node evidence archive, and the
  production permission transaction remain separate maintenance gates.
- Android real-device validation is pending user validation under the explicit
  release-risk-waiver path; it is not reported as passed.
- A public Stable APK release and customer download links are not established
  by runtime installation alone.
