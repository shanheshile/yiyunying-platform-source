#!/usr/bin/env python3
"""Audit or harden the production filesystem permission boundary over SSH.

The default action is a read-only audit. Applying or rolling back permissions
requires an explicit maintenance acknowledgement. This tool is intentionally
independent from deploy-ssh.py so a permission rollback remains available when
an application deployment fails.
"""

from __future__ import annotations

import argparse
import os
from pathlib import Path
import posixpath
import re
import secrets
import shlex
import stat
import sys
import time
from typing import Any


EXPECTED_REMOTE_ROOT = "/www/wwwroot/appht.jjmxg.xyz/yiyunying-backend"
EXPECTED_BACKUP_ROOT = "/www/backup/yiyunying"
EXPECTED_RUNTIME_USER = "www"
EXPECTED_RUNTIME_GROUP = "www"
MAINTENANCE_ACK = "writes-stopped-and-backup-reviewed"
SSH_KEEPALIVE_SECONDS = 15
REMOTE_TIMEOUT_SECONDS = 15 * 60

IMMUTABLE_TREES = ("app", "config", "database", "deploy", "docs", "routes", "tools")
APPLICATION_TOP_LEVEL_FILES = (
    ".env",
    ".env.example",
    "README.md",
    "bootstrap.php",
    "composer.json",
)
PUBLIC_IMMUTABLE_TREES = (".well-known", "downloads", "download-center")
PUBLIC_TOP_LEVEL_FILES = (
    ".htaccess",
    ".user.ini",
    "api-docs.html",
    "index.php",
    "router.php",
)
RUNTIME_WRITABLE_TREES = ("cache", "logs", "tmp", "uploads")
PRIVATE_ROOT_ONLY_TREES = (
    "catalog-conflict-recovery",
    "catalog-conflict-repair-reports",
    "catalog-migration-reports",
    "quarantine",
    "release-publication-receipts",
)
STORAGE_TOP_LEVEL_ALLOWLIST = {
    "cache",
    "deploy-backups",
    "logs",
    "private",
    "stt",
    "tmp",
    "uploads",
    "voice-call-ice-servers.json",
}
APPLICATION_TOP_LEVEL_DIRECTORY_ALLOWLIST = {
    *IMMUTABLE_TREES,
    "public",
    "storage",
}
PUBLIC_TOP_LEVEL_DIRECTORY_ALLOWLIST = {
    *PUBLIC_IMMUTABLE_TREES,
    "uploads",
}
STT_TOP_LEVEL_DIRECTORY_ALLOWLIST = {"models", "python-runtime", "venv"}
BACKUP_SNAPSHOT_RE = re.compile(
    r"^/www/backup/yiyunying/permission-hardening-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{16}/permissions-before\.acl$"
)


def quote(value: str) -> str:
    return shlex.quote(value)


def validate_remote_root(value: str) -> str:
    value = value.rstrip("/")
    if value != EXPECTED_REMOTE_ROOT:
        raise ValueError(f"remote root is pinned to {EXPECTED_REMOTE_ROOT}")
    return value


def validate_runtime_identity(user: str, group: str) -> tuple[str, str]:
    if user != EXPECTED_RUNTIME_USER or group != EXPECTED_RUNTIME_GROUP:
        raise ValueError("runtime identity is pinned to www:www")
    return user, group


def validate_snapshot_path(value: str) -> str:
    if BACKUP_SNAPSHOT_RE.fullmatch(value) is None:
        raise ValueError("rollback snapshot is outside the reviewed permission-backup namespace")
    return value


def validate_local_regular_file(path: Path, label: str) -> Path:
    resolved = path.expanduser().resolve(strict=True)
    original_stat = os.lstat(path.expanduser())
    if not stat.S_ISREG(original_stat.st_mode) or path.expanduser().is_symlink():
        raise ValueError(f"{label} must be a regular non-symlink file")
    reparse_flag = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0)
    attributes = getattr(original_stat, "st_file_attributes", 0)
    if reparse_flag and attributes & reparse_flag:
        raise ValueError(f"{label} must not be a Windows reparse point")
    if not resolved.is_file() or resolved.stat().st_size < 1:
        raise ValueError(f"{label} is empty or not a regular file")
    return resolved


def shell_array(name: str, values: tuple[str, ...]) -> str:
    return f"{name}=({' '.join(quote(value) for value in values)})"


def common_shell_prelude(root: str, runtime_user: str, runtime_group: str) -> str:
    return "\n".join(
        (
            "set -eu",
            f"ROOT={quote(root)}",
            f"RUNTIME_USER={quote(runtime_user)}",
            f"RUNTIME_GROUP={quote(runtime_group)}",
            f"BACKUP_ROOT={quote(EXPECTED_BACKUP_ROOT)}",
            shell_array("IMMUTABLE", IMMUTABLE_TREES),
            shell_array("APPLICATION_FILES", APPLICATION_TOP_LEVEL_FILES),
            shell_array("PUBLIC_IMMUTABLE", PUBLIC_IMMUTABLE_TREES),
            shell_array("PUBLIC_FILES", PUBLIC_TOP_LEVEL_FILES),
            shell_array("RUNTIME_WRITABLE", RUNTIME_WRITABLE_TREES),
            shell_array("PRIVATE_ROOT_ONLY", PRIVATE_ROOT_ONLY_TREES),
            shell_array(
                "APPLICATION_ALLOWED",
                tuple(sorted(APPLICATION_TOP_LEVEL_DIRECTORY_ALLOWLIST)),
            ),
            shell_array(
                "PUBLIC_ALLOWED",
                tuple(sorted(PUBLIC_TOP_LEVEL_DIRECTORY_ALLOWLIST)),
            ),
            shell_array("STORAGE_ALLOWED", tuple(sorted(STORAGE_TOP_LEVEL_ALLOWLIST))),
            shell_array("STT_ALLOWED", tuple(sorted(STT_TOP_LEVEL_DIRECTORY_ALLOWLIST))),
            "test \"$(id -u)\" -eq 0",
            "test -d \"$ROOT\" && test ! -L \"$ROOT\"",
            "test \"$(readlink -f -- \"$ROOT\")\" = \"$ROOT\"",
            "test -f \"$ROOT/.env\" && test ! -L \"$ROOT/.env\"",
            "id -u \"$RUNTIME_USER\" >/dev/null",
            "getent group \"$RUNTIME_GROUP\" >/dev/null",
        )
    )


def audit_command(root: str, runtime_user: str, runtime_group: str) -> str:
    prelude = common_shell_prelude(root, runtime_user, runtime_group)
    return prelude + "\n" + r'''
drift=0
apply_blocked=0
expected_permission_drift=0
will_create_private_uploads=0

shape_hash() {
  find "$ROOT" -xdev -printf '%P\0%y\0%D\0%i\0%n\0' | LC_ALL=C sort -z | sha256sum | awk '{print $1}'
}

expect_node() {
  label="$1" path="$2" expected_type="$3" owner="$4" group="$5" mode="$6"
  permission_class="${7:-fixable}"
  actual_type=missing
  if [ -L "$path" ]; then actual_type=link
  elif [ -d "$path" ]; then actual_type=directory
  elif [ -f "$path" ]; then actual_type=file
  elif [ -e "$path" ]; then actual_type=special
  fi
  if [ "$actual_type" != "$expected_type" ]; then
    printf 'NODE|%s|type=%s|expected=%s\n' "$label" "$actual_type" "$expected_type"
    drift=1
    apply_blocked=1
    return
  fi
  state=$(stat -c '%a|%U|%G' -- "$path")
  printf 'NODE|%s|%s\n' "$label" "$state"
  if [ "$state" != "$mode|$owner|$group" ]; then
    drift=1
    if [ "$permission_class" = functional ]; then apply_blocked=1; else expected_permission_drift=1; fi
  fi
}

report_tree() {
  label="$1" path="$2" owner="$3" group="$4" dmode="$5" fmode="$6"
  if [ ! -d "$path" ] || [ -L "$path" ]; then
    printf 'TREE|%s|missing_or_link\n' "$label"
    drift=1
    apply_blocked=1
    return
  fi
  links=$(find "$path" -xdev -type l -print | wc -l)
  hardlinks=$(find "$path" -xdev -type f -links +1 -print | wc -l)
  specials=$(find "$path" -xdev ! -type d ! -type f ! -type l -print | wc -l)
  bad_dirs=$(find "$path" -xdev -type d \( ! -user "$owner" -o ! -group "$group" -o ! -perm "$dmode" \) -print | wc -l)
  bad_files=$(find "$path" -xdev -type f \( ! -user "$owner" -o ! -group "$group" -o ! -perm "$fmode" \) -print | wc -l)
  printf 'TREE|%s|links=%s|hardlinks=%s|specials=%s|bad_dirs=%s|bad_files=%s\n' \
    "$label" "$links" "$hardlinks" "$specials" "$bad_dirs" "$bad_files"
  if [ "$links" -ne 0 ] || [ "$hardlinks" -ne 0 ] || [ "$specials" -ne 0 ]; then
    drift=1
    apply_blocked=1
  fi
  if [ "$bad_dirs" -ne 0 ] || [ "$bad_files" -ne 0 ]; then
    drift=1
    expected_permission_drift=1
  fi
}

in_array() {
  needle="$1"
  shift
  for candidate in "$@"; do [ "$needle" = "$candidate" ] && return 0; done
  return 1
}

shape_before=$(shape_hash)
printf 'TREE_SHAPE_BEFORE|%s\n' "$shape_before"

specials=$(find "$ROOT" -xdev ! -type d ! -type f ! -type l -print | wc -l)
unknown_links=$(find "$ROOT" -xdev -type l ! -path "$ROOT/storage/stt/*" -print | wc -l)
unknown_hardlinks=$(find "$ROOT" -xdev -type f -links +1 ! -path "$ROOT/storage/stt/*" -print | wc -l)
root_device=$(find "$ROOT" -xdev -maxdepth 0 -printf '%D')
foreign_devices=$(find "$ROOT" -xdev -mindepth 1 -printf '%D\n' | awk -v expected="$root_device" '$1 != expected {bad++} END {print bad+0}')
printf 'FULL_TREE_TYPES|specials=%s|unknown_links=%s|unknown_hardlinks=%s|foreign_devices=%s\n' \
  "$specials" "$unknown_links" "$unknown_hardlinks" "$foreign_devices"
if [ "$specials" -ne 0 ] || [ "$unknown_links" -ne 0 ] || [ "$unknown_hardlinks" -ne 0 ] || \
   [ "$foreign_devices" -ne 0 ]; then drift=1; apply_blocked=1; fi

unknown_application=0
while IFS= read -r -d '' entry; do
  base=${entry##*/}
  if [ -d "$entry" ] && [ ! -L "$entry" ]; then
    in_array "$base" "${APPLICATION_ALLOWED[@]}" || {
      printf 'UNKNOWN_APPLICATION_DIRECTORY|%s\n' "$entry"
      unknown_application=$((unknown_application + 1))
    }
  elif [ -f "$entry" ] && [ ! -L "$entry" ]; then
    in_array "$base" "${APPLICATION_FILES[@]}" || {
      printf 'UNKNOWN_APPLICATION_FILE|%s\n' "$entry"
      unknown_application=$((unknown_application + 1))
    }
  else
    printf 'UNKNOWN_APPLICATION_NODE|%s\n' "$entry"
    unknown_application=$((unknown_application + 1))
  fi
done < <(find "$ROOT" -mindepth 1 -maxdepth 1 -print0)
printf 'UNKNOWN_APPLICATION_COUNT|%s\n' "$unknown_application"
if [ "$unknown_application" -ne 0 ]; then drift=1; apply_blocked=1; fi

expect_node backend-root "$ROOT" directory root "$RUNTIME_GROUP" 750
for relative in "${APPLICATION_FILES[@]}"; do
  expect_node "backend/$relative" "$ROOT/$relative" file root "$RUNTIME_GROUP" 640
done
if su -s /bin/sh -c "test -w '$ROOT/.env'" "$RUNTIME_USER"; then
  printf 'ENV_RUNTIME_WRITABLE|yes\n'
  drift=1
  expected_permission_drift=1
else
  printf 'ENV_RUNTIME_WRITABLE|no\n'
fi
for relative in "${IMMUTABLE[@]}"; do
  report_tree "source/$relative" "$ROOT/$relative" root "$RUNTIME_GROUP" 0750 0640
done

public="$ROOT/public"
expect_node public-root "$public" directory root "$RUNTIME_GROUP" 750
unknown_public=0
while IFS= read -r -d '' entry; do
  base=${entry##*/}
  if [ -d "$entry" ] && [ ! -L "$entry" ]; then
    in_array "$base" "${PUBLIC_ALLOWED[@]}" || {
      printf 'UNKNOWN_PUBLIC_DIRECTORY|%s\n' "$entry"
      unknown_public=$((unknown_public + 1))
    }
  elif [ -f "$entry" ] && [ ! -L "$entry" ]; then
    in_array "$base" "${PUBLIC_FILES[@]}" || {
      printf 'UNKNOWN_PUBLIC_FILE|%s\n' "$entry"
      unknown_public=$((unknown_public + 1))
    }
  else
    printf 'UNKNOWN_PUBLIC_NODE|%s\n' "$entry"
    unknown_public=$((unknown_public + 1))
  fi
done < <(find "$public" -mindepth 1 -maxdepth 1 -print0)
printf 'UNKNOWN_PUBLIC_COUNT|%s\n' "$unknown_public"
if [ "$unknown_public" -ne 0 ]; then drift=1; apply_blocked=1; fi
for relative in "${PUBLIC_FILES[@]}"; do
  expect_node "public/$relative" "$public/$relative" file root "$RUNTIME_GROUP" 640
done
for relative in "${PUBLIC_IMMUTABLE[@]}"; do
  report_tree "public/$relative" "$public/$relative" root "$RUNTIME_GROUP" 0750 0640
done
report_tree public/uploads "$public/uploads" "$RUNTIME_USER" "$RUNTIME_GROUP" 0750 0640

dangerous=$(find "$public/uploads" -xdev -type f \( \
  -iname '*.php' -o -iname '*.php[0-9]*' -o -iname '*.phtml' -o -iname '*.phar' \
  -o -iname '*.cgi' -o -iname '*.pl' -o -iname '*.py' -o -iname '*.sh' \
  -o -iname '*.svg' -o -iname '*.svgz' \) -print | wc -l)
printf 'PUBLIC_UPLOAD_DANGEROUS_FILES|%s\n' "$dangerous"
if [ "$dangerous" -ne 0 ]; then drift=1; apply_blocked=1; fi
orphans=$(find "$public" -mindepth 1 -maxdepth 1 \
  \( -name '.download-center-stage-*' -o -name '.download-center.previous-*' -o -name '.codex-deploy-*' \) \
  -print | wc -l)
printf 'PUBLIC_DEPLOY_ORPHANS|%s\n' "$orphans"
if [ "$orphans" -ne 0 ]; then drift=1; apply_blocked=1; fi

storage="$ROOT/storage"
expect_node storage-root "$storage" directory root "$RUNTIME_GROUP" 710
unknown_storage=0
while IFS= read -r -d '' entry; do
  base=${entry##*/}
  if in_array "$base" "${STORAGE_ALLOWED[@]}"; then
    case "$base" in
      voice-call-ice-servers.json) [ -f "$entry" ] && [ ! -L "$entry" ] || unknown_storage=$((unknown_storage + 1)) ;;
      *) [ -d "$entry" ] && [ ! -L "$entry" ] || unknown_storage=$((unknown_storage + 1)) ;;
    esac
  else
    printf 'UNKNOWN_STORAGE_ENTRY|%s\n' "$entry"
    unknown_storage=$((unknown_storage + 1))
  fi
done < <(find "$storage" -mindepth 1 -maxdepth 1 -print0)
printf 'UNKNOWN_STORAGE_COUNT|%s\n' "$unknown_storage"
if [ "$unknown_storage" -ne 0 ]; then drift=1; apply_blocked=1; fi
expect_node storage/voice-call-ice-servers.json "$storage/voice-call-ice-servers.json" file root "$RUNTIME_GROUP" 640
for relative in "${RUNTIME_WRITABLE[@]}"; do
  report_tree "storage/$relative" "$storage/$relative" "$RUNTIME_USER" "$RUNTIME_GROUP" 0700 0600
done
report_tree storage/deploy-backups "$storage/deploy-backups" root root 0700 0600

private="$storage/private"
expect_node storage/private "$private" directory root "$RUNTIME_GROUP" 710
private_unknown=0
if [ -d "$private" ] && [ ! -L "$private" ]; then
  while IFS= read -r -d '' entry; do
    base=${entry##*/}
    allowed=0
    [ "$base" = uploads ] && allowed=1
    in_array "$base" "${PRIVATE_ROOT_ONLY[@]}" && allowed=1
    if [ "$allowed" -ne 1 ] || [ ! -d "$entry" ] || [ -L "$entry" ]; then
      printf 'UNKNOWN_PRIVATE_ENTRY|%s\n' "$entry"
      private_unknown=$((private_unknown + 1))
    fi
  done < <(find "$private" -mindepth 1 -maxdepth 1 -print0)
else
  private_unknown=$((private_unknown + 1))
fi
printf 'UNKNOWN_PRIVATE_COUNT|%s\n' "$private_unknown"
if [ "$private_unknown" -ne 0 ]; then drift=1; apply_blocked=1; fi
if [ ! -e "$private/uploads" ] && [ ! -L "$private/uploads" ]; then
  printf 'WILL_CREATE_PRIVATE_UPLOADS|yes\n'
  will_create_private_uploads=1
  drift=1
else
  printf 'WILL_CREATE_PRIVATE_UPLOADS|no\n'
  report_tree storage/private/uploads "$private/uploads" "$RUNTIME_USER" "$RUNTIME_GROUP" 0700 0600
fi
for relative in "${PRIVATE_ROOT_ONLY[@]}"; do
  if [ -e "$private/$relative" ] || [ -L "$private/$relative" ]; then
    report_tree "storage/private/$relative" "$private/$relative" root root 0700 0600
  fi
done

stt="$storage/stt"
stt_bad=0
expect_node storage/stt "$stt" directory root "$RUNTIME_GROUP" 750 functional
unknown_stt=0
if [ -d "$stt" ] && [ ! -L "$stt" ]; then
  while IFS= read -r -d '' entry; do
    base=${entry##*/}
    if ! in_array "$base" "${STT_ALLOWED[@]}" || [ ! -d "$entry" ] || [ -L "$entry" ]; then
      printf 'UNKNOWN_STT_ENTRY|%s\n' "$entry"
      unknown_stt=$((unknown_stt + 1))
    fi
  done < <(find "$stt" -mindepth 1 -maxdepth 1 -print0)
  for required in "${STT_ALLOWED[@]}"; do [ -d "$stt/$required" ] && [ ! -L "$stt/$required" ] || unknown_stt=$((unknown_stt + 1)); done

  stt_specials=$(find "$stt" -xdev ! -type d ! -type f ! -type l -print | wc -l)
  stt_bad_dirs=$(find "$stt" -xdev -type d \( ! -user root -o ! -group "$RUNTIME_GROUP" -o ! -perm 0750 \) -print | wc -l)
  stt_bad_exec=$(find "$stt" -xdev -type f -path '*/bin/*' \( ! -user root -o ! -group "$RUNTIME_GROUP" -o ! -perm 0750 \) -print | wc -l)
  stt_bad_data=$(find "$stt" -xdev -type f ! -path '*/bin/*' \( ! -user root -o ! -group "$RUNTIME_GROUP" -o ! -perm 0640 \) -print | wc -l)
  stt_bad_link_owner=$(find "$stt" -xdev -type l \( ! -user root -o ! -group "$RUNTIME_GROUP" \) -print | wc -l)
  printf 'STT_MATRIX|unknown=%s|specials=%s|bad_dirs=%s|bad_exec=%s|bad_data=%s|bad_link_owner=%s\n' \
    "$unknown_stt" "$stt_specials" "$stt_bad_dirs" "$stt_bad_exec" "$stt_bad_data" "$stt_bad_link_owner"
  if [ "$unknown_stt" -ne 0 ] || [ "$stt_specials" -ne 0 ] || [ "$stt_bad_dirs" -ne 0 ] || \
     [ "$stt_bad_exec" -ne 0 ] || [ "$stt_bad_data" -ne 0 ] || [ "$stt_bad_link_owner" -ne 0 ]; then stt_bad=1; fi

  outside=0
  broken=0
  while IFS= read -r -d '' link; do
    if target=$(readlink -f -- "$link" 2>/dev/null); then
      if [ "$target" != "$stt" ] && [ "${target#"$stt"/}" = "$target" ]; then outside=$((outside + 1)); fi
    else
      broken=$((broken + 1))
    fi
  done < <(find "$stt" -xdev -type l -print0)
  escaped_hardlinks=$(find "$stt" -xdev -type f -links +1 -printf '%D:%i %n\n' | \
    awk '{seen[$1]++; links[$1]=$2} END {bad=0; for(k in seen) if(seen[k] != links[k]) bad++; print bad+0}')
  printf 'STT_LINK_TOPOLOGY|outside=%s|broken=%s|escaped_hardlink_groups=%s\n' "$outside" "$broken" "$escaped_hardlinks"
  if [ "$outside" -ne 0 ] || [ "$broken" -ne 0 ] || [ "$escaped_hardlinks" -ne 0 ]; then stt_bad=1; fi

  python="$stt/venv/bin/python3"
  python_target=missing
  if [ -e "$python" ] || [ -L "$python" ]; then
    if python_target=$(readlink -f -- "$python" 2>/dev/null); then :; else python_target=broken; fi
  fi
  if [ "$python_target" = missing ] || [ "$python_target" = broken ] || \
     { [ "$python_target" != "$stt" ] && [ "${python_target#"$stt"/}" = "$python_target" ]; } || \
     [ ! -f "$python_target" ] || [ ! -x "$python_target" ] || \
     [ "$(stat -c '%a|%U|%G' -- "$python_target" 2>/dev/null)" != "750|root|$RUNTIME_GROUP" ]; then
    stt_bad=1
  fi
  if [ "$stt_bad" -eq 0 ]; then
    if su -s /bin/sh -c "cd / && exec env -i HOME=/nonexistent PATH=/usr/bin:/bin '$python' -I -S -B -c 'import encodings,json,ssl,sys; assert sys.flags.isolated == 1'" "$RUNTIME_USER" >/dev/null 2>&1 && \
       su -s /bin/sh -c "cd / && exec env -i HOME=/nonexistent PATH=/usr/bin:/bin '$python' -I -B -c 'from faster_whisper import WhisperModel; assert WhisperModel'" "$RUNTIME_USER" >/dev/null 2>&1; then
      printf 'STT_RUNTIME_WWW_PROBE|pass\n'
    else
      printf 'STT_RUNTIME_WWW_PROBE|blocked\n'
      stt_bad=1
    fi
  else
    printf 'STT_RUNTIME_WWW_PROBE|skipped_matrix_blocked\n'
  fi
else
  printf 'STT_MATRIX|missing_or_link\n'
  stt_bad=1
fi
printf 'STT_READ_ONLY_EXECUTABLE_READY|%s\n' "$([ "$stt_bad" -eq 0 ] && echo yes || echo no)"
if [ "$stt_bad" -ne 0 ]; then drift=1; apply_blocked=1; fi

socket=/tmp/php-cgi-82.sock
if [ -S "$socket" ]; then
  socket_state=$(stat -c '%a|%U|%G' "$socket")
  printf 'FPM_SOCKET|%s\n' "$socket_state"
  if [ "$socket_state" != "660|$RUNTIME_USER|$RUNTIME_GROUP" ]; then drift=1; apply_blocked=1; fi
else
  printf 'FPM_SOCKET|missing\n'
  drift=1
  apply_blocked=1
fi

shape_after=$(shape_hash)
printf 'TREE_SHAPE_AFTER|%s\n' "$shape_after"
if [ "$shape_before" != "$shape_after" ]; then
  printf 'TREE_SHAPE_STABLE|no\n'
  drift=1
  apply_blocked=1
else
  printf 'TREE_SHAPE_STABLE|yes\n'
fi
printf 'APPLY_READY_STRUCTURE_FUNCTION|%s\n' "$([ "$apply_blocked" -eq 0 ] && echo yes || echo no)"
printf 'EXPECTED_PERMISSION_DRIFT|%s\n' "$([ "$expected_permission_drift" -eq 0 ] && echo no || echo yes)"
printf 'AUDIT_RESULT|%s\n' "$([ "$drift" -eq 0 ] && echo pass || { [ "$apply_blocked" -eq 0 ] && echo apply-ready-with-drift || echo blocked; })"
[ "$drift" -eq 0 ] || exit 2
'''

def preflight_link_gate_shell() -> str:
    """Return the whole-tree type/link gate used before any permission write."""
    return r'''
reject_unknown_links_and_hardlinks() {
  stt="$ROOT/storage/stt"
  test -d "$stt" && test ! -L "$stt"
  test "$(find "$ROOT" -xdev ! -type d ! -type f ! -type l -print | wc -l)" -eq 0
  test "$(find "$ROOT" -xdev -type l ! -path "$stt/*" -print | wc -l)" -eq 0
  test "$(find "$ROOT" -xdev -type f -links +1 ! -path "$stt/*" -print | wc -l)" -eq 0
  root_device=$(find "$ROOT" -xdev -maxdepth 0 -printf '%D')
  test "$(find "$ROOT" -xdev -mindepth 1 -printf '%D\n' | awk -v expected="$root_device" '$1 != expected {bad++} END {print bad+0}')" -eq 0
}
'''


def post_commit_integrity_shell_library() -> str:
    """Return exact path-set and inode/type identity checks used before commit."""
    return r'''
managed_path_hash() {
  find "$ROOT" -xdev \( -path "$ROOT/storage/stt" -o -path "$ROOT/storage/stt/*" \) -prune -o -print0 | \
    LC_ALL=C sort -z | sha256sum
}

classified_path_stream() {
  printf '%s\0' "$ROOT" "$ROOT/public" "$ROOT/storage" "$ROOT/storage/private"
  for inventory in "${inventory_files[@]}"; do cat "$inventory"; done
  for allowed_new_path in "$@"; do printf '%s\0' "$allowed_new_path"; done
}

classified_path_hash() {
  classified_path_stream "$@" | LC_ALL=C sort -z | sha256sum
}

inventory_identity_hash() {
  {
    while IFS= read -r -d '' path; do
      test ! -L "$path"
      test -d "$path" || test -f "$path"
      find "$path" -xdev -maxdepth 0 -printf '%p\0%y\0%D\0%i\0'
    done < <(classified_path_stream | LC_ALL=C sort -z)
  } | LC_ALL=C sort -z | sha256sum
}

node_identity() {
  path="$1"
  test ! -L "$path"
  test -d "$path" || test -f "$path"
  find "$path" -xdev -maxdepth 0 -printf '%p\0%y\0%D\0%i\0' | sha256sum
}
'''


def transaction_shell_library() -> str:
    """Return the apply/rollback cleanup transaction used by fault-injection tests."""
    return r'''
LEDGER="$BACKUP/transaction-ledger.tsv"

valid_probe_parent() {
  parent="$1"
  [ "$parent" = "$ROOT/storage/cache" ] || [ "$parent" = "$ROOT/storage/logs" ] || \
  [ "$parent" = "$ROOT/storage/tmp" ] || [ "$parent" = "$ROOT/storage/uploads" ] || \
  [ "$parent" = "$ROOT/storage/private/uploads" ] || [ "$parent" = "$ROOT/public/uploads" ]
}

valid_ledger_entry() {
  kind="$1" path="$2"
  case "$kind" in
    probe)
      parent=${path%/*}
      name=${path##*/}
      valid_probe_parent "$parent" && case "$name" in permission-probe-*) return 0;; *) return 1;; esac
      ;;
    newdir) [ "$path" = "$ROOT/storage/private/uploads" ] ;;
    *) return 1 ;;
  esac
}

ledger_append() {
  kind="$1" path="$2"
  valid_ledger_entry "$kind" "$path"
  printf '%s\t%s\n' "$kind" "$path" >> "$LEDGER"
  chmod 0600 "$LEDGER"
}

validate_transaction_ledger() {
  test -f "$LEDGER" && test ! -L "$LEDGER"
  valid=0
  while IFS="$(printf '\t')" read -r kind path extra; do
    [ -z "$extra" ] && [ -n "$kind" ] && [ -n "$path" ] && valid_ledger_entry "$kind" "$path" || valid=1
  done < "$LEDGER"
  return "$valid"
}

cleanup_ledger_kind() {
  wanted="$1"
  failed=0
  while IFS="$(printf '\t')" read -r kind path extra; do
    [ "$kind" = "$wanted" ] || continue
    if [ -n "$extra" ] || ! valid_ledger_entry "$kind" "$path"; then
      failed=1
      continue
    fi
    case "$kind" in
      probe)
        if [ -L "$path" ]; then
          failed=1
        elif [ -e "$path" ]; then
          if [ ! -f "$path" ] || ! rm -- "$path"; then failed=1; fi
        fi
        if [ -e "$path" ] || [ -L "$path" ]; then failed=1; fi
        ;;
      newdir)
        if [ -L "$path" ]; then
          failed=1
        elif [ -e "$path" ]; then
          if [ ! -d "$path" ] || ! rmdir -- "$path"; then failed=1; fi
        fi
        if [ -e "$path" ] || [ -L "$path" ]; then failed=1; fi
        ;;
    esac
  done < "$LEDGER"
  return "$failed"
}

cleanup_transaction_artifacts() {
  failed=0
  validate_transaction_ledger || failed=1
  cleanup_ledger_kind probe || failed=1
  cleanup_ledger_kind newdir || failed=1
  return "$failed"
}

write_transaction_status() {
  state="$1" original_status="$2" cleanup_status="$3" acl_status="$4" shape_status="$5"
  status_partial="$BACKUP/automatic-rollback.status.partial"
  if printf 'state=%s\noriginal_status=%s\ncleanup_status=%s\nacl_status=%s\nshape_status=%s\n' \
      "$state" "$original_status" "$cleanup_status" "$acl_status" "$shape_status" > "$status_partial" && \
     chmod 0600 "$status_partial" && mv -f -- "$status_partial" "$BACKUP/automatic-rollback.status"; then
    printf 'AUTOMATIC_ROLLBACK|%s|cleanup=%s|acl=%s|shape=%s\n' \
      "$state" "$cleanup_status" "$acl_status" "$shape_status" >&2
    return 0
  fi
  printf 'AUTOMATIC_ROLLBACK|recovery_required|status_file_write_failed\n' >&2
  return 1
}

automatic_rollback() {
  original_status=$?
  trap - ERR
  set +e
  cleanup_status=failed
  acl_status=failed
  shape_status=failed
  if cleanup_transaction_artifacts; then cleanup_status=clean; fi
  if setfacl --restore="$snapshot"; then acl_status=restored; fi
  current_tree=
  expected_tree=
  current_tree_read=failed
  expected_tree_read=failed
  if current_tree=$(find "$ROOT" -xdev -printf '%P\0%y\0%D\0%i\0%n\0' | LC_ALL=C sort -z | sha256sum); then current_tree_read=ok; fi
  if expected_tree=$(cat "$tree_hash") && [ -n "$expected_tree" ]; then expected_tree_read=ok; fi
  if [ "$current_tree_read" = ok ] && [ "$expected_tree_read" = ok ] && [ "$current_tree" = "$expected_tree" ]; then
    shape_status=restored
  fi
  state=restored
  if [ "$cleanup_status" != clean ] || [ "$acl_status" != restored ] || [ "$shape_status" != restored ]; then
    state=recovery_required
  fi
  if ! write_transaction_status "$state" "$original_status" "$cleanup_status" "$acl_status" "$shape_status"; then
    state=recovery_required
  fi
  if [ "$state" = recovery_required ]; then exit 97; fi
  if [ "$original_status" -eq 0 ]; then original_status=1; fi
  exit "$original_status"
}
'''


def apply_command(
    root: str,
    runtime_user: str,
    runtime_group: str,
    backup_directory: str,
) -> str:
    prelude = common_shell_prelude(root, runtime_user, runtime_group)
    if posixpath.dirname(backup_directory) != EXPECTED_BACKUP_ROOT or re.fullmatch(
        r"/www/backup/yiyunying/permission-hardening-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{16}",
        backup_directory,
    ) is None:
        raise ValueError("generated backup directory is outside the reviewed namespace")
    return (
        prelude
        + "\n"
        + f"BACKUP={quote(backup_directory)}\n"
        + preflight_link_gate_shell()
        + post_commit_integrity_shell_library()
        + r'''
set -Eeuo pipefail
command -v getfacl >/dev/null
command -v setfacl >/dev/null
command -v sha256sum >/dev/null
command -v sort >/dev/null
test -d "$BACKUP_ROOT" && test ! -L "$BACKUP_ROOT"
test "$(readlink -f -- "$BACKUP_ROOT")" = "$BACKUP_ROOT"
test "$(stat -c '%a|%U|%G' -- "$BACKUP_ROOT")" = '700|root|root'
test ! -e "$BACKUP" && test ! -L "$BACKUP"

shape_hash() {
  find "$ROOT" -xdev -printf '%P\0%y\0%D\0%i\0%n\0' | LC_ALL=C sort -z | sha256sum
}

in_array() {
  needle="$1"
  shift
  for candidate in "$@"; do [ "$needle" = "$candidate" ] && return 0; done
  return 1
}

reject_plain_tree() {
  path="$1"
  test -d "$path" && test ! -L "$path"
  test "$(find "$path" -xdev -type l -print | wc -l)" -eq 0
  test "$(find "$path" -xdev -type f -links +1 -print | wc -l)" -eq 0
  test "$(find "$path" -xdev ! -type d ! -type f ! -type l -print | wc -l)" -eq 0
}

validate_stt_gate() {
  stt="$ROOT/storage/stt"
  test -d "$stt" && test ! -L "$stt"
  while IFS= read -r -d '' entry; do
    base=${entry##*/}
    in_array "$base" "${STT_ALLOWED[@]}"
    test -d "$entry" && test ! -L "$entry"
  done < <(find "$stt" -mindepth 1 -maxdepth 1 -print0)
  for required in "${STT_ALLOWED[@]}"; do test -d "$stt/$required" && test ! -L "$stt/$required"; done
  test "$(find "$stt" -xdev ! -type d ! -type f ! -type l -print | wc -l)" -eq 0
  test "$(find "$stt" -xdev -type d \( ! -user root -o ! -group "$RUNTIME_GROUP" -o ! -perm 0750 \) -print | wc -l)" -eq 0
  test "$(find "$stt" -xdev -type f -path '*/bin/*' \( ! -user root -o ! -group "$RUNTIME_GROUP" -o ! -perm 0750 \) -print | wc -l)" -eq 0
  test "$(find "$stt" -xdev -type f ! -path '*/bin/*' \( ! -user root -o ! -group "$RUNTIME_GROUP" -o ! -perm 0640 \) -print | wc -l)" -eq 0
  test "$(find "$stt" -xdev -type l \( ! -user root -o ! -group "$RUNTIME_GROUP" \) -print | wc -l)" -eq 0
  test "$(find "$stt" -xdev \( -type d -o -type f -o -type l \) -user "$RUNTIME_USER" -print | wc -l)" -eq 0
  outside=0
  broken=0
  while IFS= read -r -d '' link; do
    if target=$(readlink -f -- "$link" 2>/dev/null); then
      if [ "$target" != "$stt" ] && [ "${target#"$stt"/}" = "$target" ]; then outside=$((outside + 1)); fi
    else
      broken=$((broken + 1))
    fi
  done < <(find "$stt" -xdev -type l -print0)
  test "$outside" -eq 0 && test "$broken" -eq 0
  escaped=$(find "$stt" -xdev -type f -links +1 -printf '%D:%i %n\n' | \
    awk '{seen[$1]++; links[$1]=$2} END {bad=0; for(k in seen) if(seen[k] != links[k]) bad++; print bad+0}')
  test "$escaped" -eq 0
  python="$stt/venv/bin/python3"
  python_target=$(readlink -f -- "$python")
  test "$python_target" != "$stt" && test "${python_target#"$stt"/}" != "$python_target"
  test -f "$python_target" && test -x "$python_target"
  test "$(stat -c '%a|%U|%G' -- "$python_target")" = "750|root|$RUNTIME_GROUP"
  su -s /bin/sh -c "cd / && exec env -i HOME=/nonexistent PATH=/usr/bin:/bin '$python' -I -S -B -c 'import encodings,json,ssl,sys; assert sys.flags.isolated == 1'" "$RUNTIME_USER" >/dev/null 2>&1
  su -s /bin/sh -c "cd / && exec env -i HOME=/nonexistent PATH=/usr/bin:/bin '$python' -I -B -c 'from faster_whisper import WhisperModel; assert WhisperModel'" "$RUNTIME_USER" >/dev/null 2>&1
}

validate_full_structure() {
  reject_unknown_links_and_hardlinks

  while IFS= read -r -d '' entry; do
    base=${entry##*/}
    if [ -d "$entry" ] && [ ! -L "$entry" ]; then in_array "$base" "${APPLICATION_ALLOWED[@]}"
    elif [ -f "$entry" ] && [ ! -L "$entry" ]; then in_array "$base" "${APPLICATION_FILES[@]}"
    else return 41
    fi
  done < <(find "$ROOT" -mindepth 1 -maxdepth 1 -print0)
  for required in "${APPLICATION_ALLOWED[@]}"; do test -d "$ROOT/$required" && test ! -L "$ROOT/$required"; done
  for required in "${APPLICATION_FILES[@]}"; do test -f "$ROOT/$required" && test ! -L "$ROOT/$required"; done

  public="$ROOT/public"
  while IFS= read -r -d '' entry; do
    base=${entry##*/}
    if [ -d "$entry" ] && [ ! -L "$entry" ]; then in_array "$base" "${PUBLIC_ALLOWED[@]}"
    elif [ -f "$entry" ] && [ ! -L "$entry" ]; then in_array "$base" "${PUBLIC_FILES[@]}"
    else return 42
    fi
  done < <(find "$public" -mindepth 1 -maxdepth 1 -print0)
  for required in "${PUBLIC_ALLOWED[@]}"; do test -d "$public/$required" && test ! -L "$public/$required"; done
  for required in "${PUBLIC_FILES[@]}"; do test -f "$public/$required" && test ! -L "$public/$required"; done

  storage="$ROOT/storage"
  while IFS= read -r -d '' entry; do
    base=${entry##*/}
    in_array "$base" "${STORAGE_ALLOWED[@]}"
    case "$base" in
      voice-call-ice-servers.json) test -f "$entry" && test ! -L "$entry" ;;
      *) test -d "$entry" && test ! -L "$entry" ;;
    esac
  done < <(find "$storage" -mindepth 1 -maxdepth 1 -print0)
  for required in "${STORAGE_ALLOWED[@]}"; do
    case "$required" in
      voice-call-ice-servers.json) test -f "$storage/$required" && test ! -L "$storage/$required" ;;
      *) test -d "$storage/$required" && test ! -L "$storage/$required" ;;
    esac
  done

  private="$storage/private"
  while IFS= read -r -d '' entry; do
    base=${entry##*/}
    if [ "$base" != uploads ]; then in_array "$base" "${PRIVATE_ROOT_ONLY[@]}"; fi
    test -d "$entry" && test ! -L "$entry"
  done < <(find "$private" -mindepth 1 -maxdepth 1 -print0)

  for relative in "${IMMUTABLE[@]}"; do reject_plain_tree "$ROOT/$relative"; done
  for relative in "${PUBLIC_IMMUTABLE[@]}"; do reject_plain_tree "$public/$relative"; done
  reject_plain_tree "$public/uploads"
  for relative in "${RUNTIME_WRITABLE[@]}"; do reject_plain_tree "$storage/$relative"; done
  reject_plain_tree "$storage/deploy-backups"
  if [ -e "$private/uploads" ] || [ -L "$private/uploads" ]; then reject_plain_tree "$private/uploads"; fi
  for relative in "${PRIVATE_ROOT_ONLY[@]}"; do
    if [ -e "$private/$relative" ] || [ -L "$private/$relative" ]; then reject_plain_tree "$private/$relative"; fi
  done
  test "$(find "$public/uploads" -xdev -type f \( \
    -iname '*.php' -o -iname '*.php[0-9]*' -o -iname '*.phtml' -o -iname '*.phar' \
    -o -iname '*.cgi' -o -iname '*.pl' -o -iname '*.py' -o -iname '*.sh' \
    -o -iname '*.svg' -o -iname '*.svgz' \) -print | wc -l)" -eq 0
  test "$(find "$public" -mindepth 1 -maxdepth 1 \
    \( -name '.download-center-stage-*' -o -name '.download-center.previous-*' -o -name '.codex-deploy-*' \) \
    -print | wc -l)" -eq 0
  validate_stt_gate
}

shape_preflight=$(shape_hash)
validate_full_structure
test "$shape_preflight" = "$(shape_hash)"
test -S /tmp/php-cgi-82.sock
test "$(stat -c '%a|%U|%G' /tmp/php-cgi-82.sock)" = "660|$RUNTIME_USER|$RUNTIME_GROUP"

umask 077
mkdir -m 0700 -- "$BACKUP"
snapshot="$BACKUP/permissions-before.acl"
snapshot_partial="$snapshot.partial"
tree_hash="$BACKUP/tree-before.sha256"
stt_hash="$BACKUP/stt-shape-before.sha256"
getfacl -R -P -p -- "$ROOT" > "$snapshot_partial"
test -s "$snapshot_partial"
chmod 0600 "$snapshot_partial"
mv -- "$snapshot_partial" "$snapshot"
sha256sum "$snapshot" > "$snapshot.sha256"
shape_hash > "$tree_hash"
find "$ROOT/storage/stt" -xdev -printf '%P\0%y\0%D\0%i\0%n\0' | LC_ALL=C sort -z | sha256sum > "$stt_hash"
chmod 0600 "$snapshot" "$snapshot.sha256" "$tree_hash" "$stt_hash"

inventory_immutable_dirs="$BACKUP/inventory-immutable-dirs.nul"
inventory_immutable_files="$BACKUP/inventory-immutable-files.nul"
inventory_root_files="$BACKUP/inventory-root-files.nul"
inventory_public_files="$BACKUP/inventory-public-files.nul"
inventory_public_upload_dirs="$BACKUP/inventory-public-upload-dirs.nul"
inventory_public_upload_files="$BACKUP/inventory-public-upload-files.nul"
inventory_runtime_dirs="$BACKUP/inventory-runtime-dirs.nul"
inventory_runtime_files="$BACKUP/inventory-runtime-files.nul"
inventory_root_only_dirs="$BACKUP/inventory-root-only-dirs.nul"
inventory_root_only_files="$BACKUP/inventory-root-only-files.nul"
inventory_private_upload_dirs="$BACKUP/inventory-private-upload-dirs.nul"
inventory_private_upload_files="$BACKUP/inventory-private-upload-files.nul"
inventory_storage_files="$BACKUP/inventory-storage-files.nul"
inventory_files=(
  "$inventory_immutable_dirs" "$inventory_immutable_files" "$inventory_root_files"
  "$inventory_public_files" "$inventory_public_upload_dirs" "$inventory_public_upload_files"
  "$inventory_runtime_dirs" "$inventory_runtime_files" "$inventory_root_only_dirs"
  "$inventory_root_only_files" "$inventory_private_upload_dirs" "$inventory_private_upload_files"
  "$inventory_storage_files"
)
for inventory in "${inventory_files[@]}"; do : > "$inventory"; done

inventory_tree_into() {
  path="$1" dirs="$2" files="$3"
  find "$path" -xdev -type d -print0 >> "$dirs"
  find "$path" -xdev -type f -print0 >> "$files"
}
for relative in "${IMMUTABLE[@]}"; do inventory_tree_into "$ROOT/$relative" "$inventory_immutable_dirs" "$inventory_immutable_files"; done
for relative in "${PUBLIC_IMMUTABLE[@]}"; do inventory_tree_into "$ROOT/public/$relative" "$inventory_immutable_dirs" "$inventory_immutable_files"; done
find "$ROOT" -mindepth 1 -maxdepth 1 -type f -print0 > "$inventory_root_files"
find "$ROOT/public" -mindepth 1 -maxdepth 1 -type f -print0 > "$inventory_public_files"
inventory_tree_into "$ROOT/public/uploads" "$inventory_public_upload_dirs" "$inventory_public_upload_files"
for relative in "${RUNTIME_WRITABLE[@]}"; do inventory_tree_into "$ROOT/storage/$relative" "$inventory_runtime_dirs" "$inventory_runtime_files"; done
inventory_tree_into "$ROOT/storage/deploy-backups" "$inventory_root_only_dirs" "$inventory_root_only_files"
for relative in "${PRIVATE_ROOT_ONLY[@]}"; do
  if [ -d "$ROOT/storage/private/$relative" ]; then
    inventory_tree_into "$ROOT/storage/private/$relative" "$inventory_root_only_dirs" "$inventory_root_only_files"
  fi
done
if [ -d "$ROOT/storage/private/uploads" ]; then
  inventory_tree_into "$ROOT/storage/private/uploads" "$inventory_private_upload_dirs" "$inventory_private_upload_files"
fi
printf '%s\0' "$ROOT/storage/voice-call-ice-servers.json" > "$inventory_storage_files"
chmod 0600 "${inventory_files[@]}"
sha256sum "${inventory_files[@]}" > "$BACKUP/inventories.sha256"
chmod 0600 "$BACKUP/inventories.sha256"
sha256sum -c "$BACKUP/inventories.sha256" >/dev/null

managed_hash=$(managed_path_hash)
classified_hash=$(classified_path_hash)
test "$managed_hash" = "$classified_hash"
inventory_identity_before="$BACKUP/inventory-identity-before.sha256"
inventory_identity_hash > "$inventory_identity_before"
chmod 0600 "$inventory_identity_before"
test "$shape_preflight" = "$(shape_hash)"
'''
        + transaction_shell_library()
        + r'''
: > "$LEDGER"
chmod 0600 "$LEDGER"
trap automatic_rollback ERR

private="$ROOT/storage/private"
created_private_uploads=0
created_post_paths=()
if [ ! -e "$private/uploads" ] && [ ! -L "$private/uploads" ]; then
  ledger_append newdir "$private/uploads"
  install -d -o "$RUNTIME_USER" -g "$RUNTIME_GROUP" -m 0700 -- "$private/uploads"
  created_private_uploads=1
  created_post_paths=("$private/uploads")
fi

validate_newdir_ledger() {
  newdir_count=0
  validate_transaction_ledger
  while IFS="$(printf '\t')" read -r kind path extra; do
    if [ "$kind" = newdir ]; then
      [ -z "$extra" ] && [ "$path" = "$private/uploads" ]
      newdir_count=$((newdir_count + 1))
    fi
  done < "$LEDGER"
  test "$newdir_count" -eq "$created_private_uploads"
}

validate_newdir_ledger
expected_post_classified="$BACKUP/expected-post-classified.sha256"
expected_post_shape="$BACKUP/expected-post-shape.sha256"
created_node_identity="$BACKUP/created-node-identity.sha256"
classified_path_hash "${created_post_paths[@]}" > "$expected_post_classified"
test "$(managed_path_hash)" = "$(cat "$expected_post_classified")"
validate_full_structure
shape_hash > "$expected_post_shape"
if [ "$created_private_uploads" -eq 1 ]; then
  node_identity "$private/uploads" > "$created_node_identity"
else
  : > "$created_node_identity"
fi
chmod 0600 "$expected_post_classified" "$expected_post_shape" "$created_node_identity"

harden_exact() {
  path="$1" kind="$2" owner="$3" group="$4" mode="$5"
  test ! -L "$path"
  case "$kind" in directory) test -d "$path";; file) test -f "$path";; *) return 68;; esac
  chown -h "$owner:$group" -- "$path"
  chmod "$mode" -- "$path"
}

harden_inventory() {
  inventory="$1" kind="$2" owner="$3" group="$4" mode="$5"
  while IFS= read -r -d '' path; do
    case "$path" in "$ROOT"/*) :;; *) return 69;; esac
    harden_exact "$path" "$kind" "$owner" "$group" "$mode"
  done < "$inventory"
}

verify_inventory() {
  inventory="$1" kind="$2" owner="$3" group="$4" mode="$5"
  while IFS= read -r -d '' path; do
    test ! -L "$path"
    case "$kind" in directory) test -d "$path";; file) test -f "$path";; *) return 70;; esac
    test "$(stat -c '%a|%U|%G' -- "$path")" = "$mode|$owner|$group"
  done < "$inventory"
}

verify_exact() {
  path="$1" kind="$2" owner="$3" group="$4" mode="$5"
  test ! -L "$path"
  case "$kind" in directory) test -d "$path";; file) test -f "$path";; *) return 71;; esac
  test "$(stat -c '%a|%U|%G' -- "$path")" = "$mode|$owner|$group"
}

verify_complete_permission_matrix() {
  verify_exact "$ROOT" directory root "$RUNTIME_GROUP" 750
  verify_exact "$ROOT/public" directory root "$RUNTIME_GROUP" 750
  verify_exact "$ROOT/storage" directory root "$RUNTIME_GROUP" 710
  verify_exact "$ROOT/storage/private" directory root "$RUNTIME_GROUP" 710
  verify_exact "$private/uploads" directory "$RUNTIME_USER" "$RUNTIME_GROUP" 700
  verify_inventory "$inventory_immutable_dirs" directory root "$RUNTIME_GROUP" 750
  verify_inventory "$inventory_immutable_files" file root "$RUNTIME_GROUP" 640
  verify_inventory "$inventory_root_files" file root "$RUNTIME_GROUP" 640
  verify_inventory "$inventory_public_files" file root "$RUNTIME_GROUP" 640
  verify_inventory "$inventory_public_upload_dirs" directory "$RUNTIME_USER" "$RUNTIME_GROUP" 750
  verify_inventory "$inventory_public_upload_files" file "$RUNTIME_USER" "$RUNTIME_GROUP" 640
  verify_inventory "$inventory_runtime_dirs" directory "$RUNTIME_USER" "$RUNTIME_GROUP" 700
  verify_inventory "$inventory_runtime_files" file "$RUNTIME_USER" "$RUNTIME_GROUP" 600
  verify_inventory "$inventory_root_only_dirs" directory root root 700
  verify_inventory "$inventory_root_only_files" file root root 600
  verify_inventory "$inventory_private_upload_dirs" directory "$RUNTIME_USER" "$RUNTIME_GROUP" 700
  verify_inventory "$inventory_private_upload_files" file "$RUNTIME_USER" "$RUNTIME_GROUP" 600
  verify_inventory "$inventory_storage_files" file root "$RUNTIME_GROUP" 640
  su -s /bin/sh -c "test -r '$ROOT/.env' && test ! -w '$ROOT/.env' && test -r '$ROOT/bootstrap.php' && test ! -w '$ROOT/bootstrap.php'" "$RUNTIME_USER"
  su -s /bin/sh -c "test ! -w '$ROOT/public/downloads' && test ! -w '$ROOT/public/download-center'" "$RUNTIME_USER"
}

harden_exact "$ROOT" directory root "$RUNTIME_GROUP" 750
harden_exact "$ROOT/public" directory root "$RUNTIME_GROUP" 750
harden_exact "$ROOT/storage" directory root "$RUNTIME_GROUP" 710
harden_exact "$ROOT/storage/private" directory root "$RUNTIME_GROUP" 710
harden_inventory "$inventory_immutable_dirs" directory root "$RUNTIME_GROUP" 750
harden_inventory "$inventory_immutable_files" file root "$RUNTIME_GROUP" 640
harden_inventory "$inventory_root_files" file root "$RUNTIME_GROUP" 640
harden_inventory "$inventory_public_files" file root "$RUNTIME_GROUP" 640
harden_inventory "$inventory_public_upload_dirs" directory "$RUNTIME_USER" "$RUNTIME_GROUP" 750
harden_inventory "$inventory_public_upload_files" file "$RUNTIME_USER" "$RUNTIME_GROUP" 640
harden_inventory "$inventory_runtime_dirs" directory "$RUNTIME_USER" "$RUNTIME_GROUP" 700
harden_inventory "$inventory_runtime_files" file "$RUNTIME_USER" "$RUNTIME_GROUP" 600
harden_inventory "$inventory_root_only_dirs" directory root root 700
harden_inventory "$inventory_root_only_files" file root root 600
harden_inventory "$inventory_private_upload_dirs" directory "$RUNTIME_USER" "$RUNTIME_GROUP" 700
harden_inventory "$inventory_private_upload_files" file "$RUNTIME_USER" "$RUNTIME_GROUP" 600
harden_inventory "$inventory_storage_files" file root "$RUNTIME_GROUP" 640
harden_exact "$private/uploads" directory "$RUNTIME_USER" "$RUNTIME_GROUP" 700

verify_complete_permission_matrix

probe="permission-probe-$$-$(date +%s)"
run_write_probe() {
  writable="$1"
  probe_path="$writable/$probe"
  test ! -e "$probe_path" && test ! -L "$probe_path"
  ledger_append probe "$probe_path"
  su -s /bin/sh -c "umask 077; : > '$probe_path'" "$RUNTIME_USER"
  test -f "$probe_path" && test ! -L "$probe_path"
  su -s /bin/sh -c "rm -- '$probe_path'" "$RUNTIME_USER"
  test ! -e "$probe_path" && test ! -L "$probe_path"
}

validate_completed_ledger() {
  validate_newdir_ledger
  seen_cache=0
  seen_logs=0
  seen_tmp=0
  seen_storage_uploads=0
  seen_private_uploads=0
  seen_public_uploads=0
  probe_count=0
  while IFS="$(printf '\t')" read -r kind path extra; do
    [ -z "$extra" ]
    case "$kind" in
      newdir) [ "$path" = "$private/uploads" ] ;;
      probe)
        [ "${path##*/}" = "$probe" ]
        test ! -e "$path" && test ! -L "$path"
        case "${path%/*}" in
          "$ROOT/storage/cache") test "$seen_cache" -eq 0; seen_cache=1 ;;
          "$ROOT/storage/logs") test "$seen_logs" -eq 0; seen_logs=1 ;;
          "$ROOT/storage/tmp") test "$seen_tmp" -eq 0; seen_tmp=1 ;;
          "$ROOT/storage/uploads") test "$seen_storage_uploads" -eq 0; seen_storage_uploads=1 ;;
          "$private/uploads") test "$seen_private_uploads" -eq 0; seen_private_uploads=1 ;;
          "$ROOT/public/uploads") test "$seen_public_uploads" -eq 0; seen_public_uploads=1 ;;
          *) return 72 ;;
        esac
        probe_count=$((probe_count + 1))
        ;;
      *) return 73 ;;
    esac
  done < "$LEDGER"
  test "$probe_count" -eq 6
  test "$seen_cache" -eq 1 && test "$seen_logs" -eq 1 && test "$seen_tmp" -eq 1
  test "$seen_storage_uploads" -eq 1 && test "$seen_private_uploads" -eq 1 && test "$seen_public_uploads" -eq 1
}

for writable in "$ROOT/storage/cache" "$ROOT/storage/logs" "$ROOT/storage/tmp" "$ROOT/storage/uploads" "$private/uploads" "$ROOT/public/uploads"; do
  run_write_probe "$writable"
done

validate_completed_ledger
validate_full_structure
test -S /tmp/php-cgi-82.sock
test "$(stat -c '%a|%U|%G' /tmp/php-cgi-82.sock)" = "660|$RUNTIME_USER|$RUNTIME_GROUP"
sha256sum -c "$BACKUP/inventories.sha256" >/dev/null
verify_complete_permission_matrix
test "$(inventory_identity_hash)" = "$(cat "$inventory_identity_before")"
if [ "$created_private_uploads" -eq 1 ]; then
  test "$(node_identity "$private/uploads")" = "$(cat "$created_node_identity")"
else
  test ! -s "$created_node_identity"
fi
test "$(classified_path_hash "${created_post_paths[@]}")" = "$(cat "$expected_post_classified")"
test "$(managed_path_hash)" = "$(cat "$expected_post_classified")"
test "$(shape_hash)" = "$(cat "$expected_post_shape")"
test "$(find "$ROOT/storage/stt" -xdev -printf '%P\0%y\0%D\0%i\0%n\0' | LC_ALL=C sort -z | sha256sum)" = "$(cat "$stt_hash")"
find "$ROOT" -xdev -mindepth 1 -maxdepth 1 -printf '%m|%u|%g|%p\n' > "$BACKUP/permissions-after.txt"
shape_hash > "$BACKUP/tree-after.sha256"
chmod 0600 "$BACKUP/permissions-after.txt" "$BACKUP/tree-after.sha256"
status_partial="$BACKUP/transaction.status.partial"
printf 'state=committed\n' > "$status_partial"
chmod 0600 "$status_partial"
mv -- "$status_partial" "$BACKUP/transaction.status"
trap - ERR
printf 'PERMISSION_HARDENING=applied\nBACKUP=%s\n' "$BACKUP"
'''
    )

def rollback_command(root: str, snapshot_path: str) -> str:
    validate_snapshot_path(snapshot_path)
    backup_directory = posixpath.dirname(snapshot_path)
    return (
        "\n".join(
            (
                "set -Eeuo pipefail",
                f"ROOT={quote(root)}",
                f"SNAPSHOT={quote(snapshot_path)}",
                f"BACKUP={quote(backup_directory)}",
                "snapshot=\"$SNAPSHOT\"",
                "tree_hash=\"$BACKUP/tree-before.sha256\"",
                "test \"$(id -u)\" -eq 0",
                "test -d \"$ROOT\" && test ! -L \"$ROOT\"",
                "test \"$(readlink -f -- \"$ROOT\")\" = \"$ROOT\"",
                "test -d \"$BACKUP\" && test ! -L \"$BACKUP\"",
                "test -f \"$SNAPSHOT\" && test ! -L \"$SNAPSHOT\"",
                "test \"$(stat -c '%a|%U|%G' \"$SNAPSHOT\")\" = '600|root|root'",
                "cd \"$BACKUP\"",
                "sha256sum -c permissions-before.acl.sha256",
            )
        )
        + "\n"
        + transaction_shell_library()
        + r'''
manual_rollback_failed() {
  original_status=$?
  trap - ERR
  set +e
  status_partial="$BACKUP/manual-rollback.status.partial"
  if printf 'state=recovery_required\noriginal_status=%s\n' "$original_status" > "$status_partial" && \
     chmod 0600 "$status_partial" && mv -f -- "$status_partial" "$BACKUP/manual-rollback.status"; then
    printf 'PERMISSION_HARDENING=recovery_required\nSNAPSHOT=%s\n' "$SNAPSHOT" >&2
  else
    printf 'PERMISSION_HARDENING=recovery_required\nSTATUS_FILE=write_failed\n' >&2
  fi
  exit 98
}
trap manual_rollback_failed ERR
cleanup_transaction_artifacts
current_tree=$(find "$ROOT" -xdev -printf '%P\0%y\0%D\0%i\0%n\0' | LC_ALL=C sort -z | sha256sum)
expected_tree=$(cat "$tree_hash")
test "$current_tree" = "$expected_tree"
setfacl --restore="$SNAPSHOT"
status_partial="$BACKUP/manual-rollback.status.partial"
printf 'state=rolled_back\n' > "$status_partial"
chmod 0600 "$status_partial"
mv -f -- "$status_partial" "$BACKUP/manual-rollback.status"
trap - ERR
printf 'PERMISSION_HARDENING=rolled-back\nSNAPSHOT=%s\n' "$SNAPSHOT"
'''
    )


def run_remote(client: Any, command: str, label: str, allowed: set[int]) -> tuple[int, str]:
    stdin, stdout, stderr = client.exec_command(command, get_pty=False, timeout=REMOTE_TIMEOUT_SECONDS)
    del stdin
    channel = stdout.channel
    channel.settimeout(REMOTE_TIMEOUT_SECONDS)
    output = stdout.read().decode("utf-8", errors="replace")
    error = stderr.read().decode("utf-8", errors="replace")
    status = channel.recv_exit_status()
    if output.strip():
        print(output.strip())
    if status not in allowed:
        raise RuntimeError(f"{label} failed ({status}): {error.strip() or output.strip()}")
    return status, output


def connect(args: argparse.Namespace):
    try:
        import paramiko
    except ImportError as exc:
        raise RuntimeError("paramiko is required; install backend/tools/requirements-release.txt") from exc
    password = os.environ.get("YY_SSH_PASSWORD", "")
    if not password:
        raise RuntimeError("YY_SSH_PASSWORD is required and must not be passed on the command line")
    known_hosts = validate_local_regular_file(Path(args.known_hosts), "known-hosts")
    client = paramiko.SSHClient()
    client.load_host_keys(str(known_hosts))
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(
        args.host,
        port=args.port,
        username=args.user,
        password=password,
        timeout=20,
        banner_timeout=20,
        auth_timeout=20,
        look_for_keys=False,
        allow_agent=False,
        disabled_algorithms={"kex": ["curve25519-sha256", "curve25519-sha256@libssh.org"]},
    )
    transport = client.get_transport()
    if transport is None or not transport.is_active():
        client.close()
        raise RuntimeError("SSH transport is inactive")
    transport.set_keepalive(SSH_KEEPALIVE_SECONDS)
    return client


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--host", required=True)
    result.add_argument("--port", type=int, default=22)
    result.add_argument("--user", required=True)
    result.add_argument("--known-hosts", required=True)
    result.add_argument("--remote-root", default=EXPECTED_REMOTE_ROOT)
    result.add_argument("--runtime-user", default=EXPECTED_RUNTIME_USER)
    result.add_argument("--runtime-group", default=EXPECTED_RUNTIME_GROUP)
    result.add_argument("--apply", action="store_true")
    result.add_argument("--maintenance-confirmed", default="")
    result.add_argument("--rollback-snapshot")
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    root = validate_remote_root(args.remote_root)
    runtime_user, runtime_group = validate_runtime_identity(args.runtime_user, args.runtime_group)
    if (args.apply or args.rollback_snapshot) and args.maintenance_confirmed != MAINTENANCE_ACK:
        raise RuntimeError(f"state-changing actions require --maintenance-confirmed {MAINTENANCE_ACK}")
    if args.rollback_snapshot and not args.apply:
        raise RuntimeError("rollback requires --apply")

    client = connect(args)
    try:
        if args.rollback_snapshot:
            run_remote(client, rollback_command(root, args.rollback_snapshot), "permission rollback", {0})
            return 0
        if args.apply:
            stamp = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
            backup = f"{EXPECTED_BACKUP_ROOT}/permission-hardening-{stamp}-{secrets.token_hex(8)}"
            run_remote(client, apply_command(root, runtime_user, runtime_group, backup), "permission hardening", {0})
            return 0
        status, _ = run_remote(client, audit_command(root, runtime_user, runtime_group), "permission dry-run", {0, 2})
        if status == 2:
            print(
                "[dry-run] inspect APPLY_READY_STRUCTURE_FUNCTION: exit 2 may be "
                "expected permission drift or will-create, not an apply blocker",
                file=sys.stderr,
            )
        return status
    finally:
        client.close()


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"permission hardening failed: {exc}", file=sys.stderr)
        raise SystemExit(1)
