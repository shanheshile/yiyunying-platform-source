# Catalog conflict server-local preparation

The two reviewed catalog media conflicts are prepared entirely on the
production host. Their original paths and bytes must never be copied through
SSH/SFTP, printed, placed in a release archive, or written to a workstation.

## Fixed boundary

`tools/prepare-catalog-public-conflicts-server-local.php` contains only the
two redacted path hashes, source SHA-256 values, source sizes, formats and
reference-count contracts approved for this one repair. It discovers each
file by hashing canonical paths below `public/uploads`; neither the discovered
path nor process diagnostics are returned to the deploy client.

The tool fails closed unless all of these statements are true:

- it is running as root on Linux after the deployment maintenance entry;
- this deployment's database and public-uploads backups are unique root-owned
  `0600` regular files, complete, hash-readable and less than four hours old;
- the catalog runtime gate independently reads closed and the catalog
  maintenance advisory lock is available;
- each path hash resolves exactly once to a non-link, single-link regular file
  with the frozen source size, SHA-256 and JPEG/HEIC signature;
- the database reference counts, upload/attachment counts and every row and
  reference all prove exactly one tenant;
- `current` resolves to the immutable reviewed media runtime version and both
  binaries have their frozen sizes, modes and SHA-256 values;
- fixed FFprobe commands prove the reviewed codec and bounded dimensions;
- fixed FFmpeg arguments use only the `file` protocol, disable stdin, strip
  metadata/chapters, select one video frame and have time/output limits;
- a streaming CRC-checked sanitizer retains only the 8-bit RGBA PNG's critical
  `IHDR`/`IDAT`/`IEND` chunks and removes every ancillary chunk before use;
- both results pass full GD PNG decode, dimension, signature, hash, size and
  critical-chunks-only (`no_ancillary_chunks_v1`) validation.

The PHP subprocess disables display/startup/log/html errors and converts
unsuppressed warnings to a generic caught exception. FFmpeg/FFprobe output is
captured into bounded private pipes and is never forwarded. The SSH deploy
client uses the same redacted capture path for preparation, repair apply and
repair readback: it never prints remote stdout/stderr and never includes those
bytes in an exception. Only after each strict redacted receipt validates does
the client print a fixed local `validated` marker. Preparation also has a
server-side 20-minute process-group timeout, shorter than the SSH timeout, so a
client timeout cannot race a still-running root preparation against rollback.

The deployment dependency preflight performs the runtime check before the
first `ffmpeg` or `ffprobe` invocation. It verifies every ancestor from `/`
through `/opt/yiyunying/media-runtime`, the exact root-owned `current` symlink
target, and the pinned regular single-link binaries' owner, mode, size and
SHA-256. A failed ownership, permission, link, target or fingerprint check
therefore stops before any media-runtime executable is run as root.

The temporary stage is an exclusive root-owned `0700` directory under the
fixed `/tmp/yiyunying-catalog-conflict-<deployment-id>` namespace. Original
media is copied only to generic `0600` names inside this server-local stage and
is deleted immediately after conversion. A successful stage retains only two
prepared PNGs and a root-only redacted source plan. Any failed preparation
performs a bounded, non-recursive cleanup; an unexpected entry leaves the
stage for root review and reports `cleanup_required` without revealing it.
Deployment cleanup follows the same boundary: it unlinks only the four known
root-owned `0600`, single-link regular plan/PNG files and removes the empty
stage. It never recursively deletes the stage; any unknown entry is retained
and fails cleanup for operator review.

## Deployment integration

Never invoke the preparation tool as a standalone shortcut. Use the reviewed
deployment transaction with the explicit mode:

```text
--catalog-conflict-repair-mode server-local
```

No local plan or PNG argument is accepted in that mode. The transaction order
is enforced and tested:

1. enter maintenance and stop traffic/writes;
2. create and verify this run's public-uploads and database backups;
3. apply the release migrations and independently read the catalog gate closed;
4. deploy the reviewed preparation/repair tools;
5. prepare both media files server-locally and parse one redacted receipt;
6. bind the source plan and PNGs to the same two backup artifacts in a runtime
   plan, then immediately apply and read back the existing atomic repair;
7. continue catalog binding, quarantine, private migration and gate activation;
8. remove the verified private conflict stage while rollback and maintenance
   are still active;
9. restart, health-check and release maintenance.

Any failure remains inside the deployment rollback transaction. Do not reuse a
stage, source plan, prepared PNG or backup from a previous attempt.

The original local-input path is retained only for controlled fixtures. It is
also explicit and mutually exclusive:

```text
--catalog-conflict-repair-mode local \
--catalog-conflict-repair-plan <private-source-plan> \
--catalog-conflict-repair-jpeg-png <prepared-fixture> \
--catalog-conflict-repair-heic-png <prepared-fixture>
```

Omitting the mode and all four related options leaves conflict repair disabled,
matching the prior default behavior. Supplying any local input without
`--catalog-conflict-repair-mode local` is rejected before SSH connection.

## Verification

Run before a release:

```powershell
php tools/test-catalog-conflict-server-local-preparation.php
python tools/tests/test_catalog_server_local_preparation.py
python tools/test-deploy-ssh-safety.py
powershell -NoProfile -ExecutionPolicy Bypass -File tools/check.ps1
```

These are offline contract tests. They do not access production, reveal media,
or prove that a production repair has run. Production completion requires the
repair apply report, independent dry-run readback, catalog migration report,
activated gate readback and final health check from the same transaction.
