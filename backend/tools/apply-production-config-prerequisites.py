#!/usr/bin/env python3
"""Audit or apply the production config/legacy-node prerequisite transaction.

The default action is a strictly read-only audit.  The write path is pinned to
one host, one SSH identity, seven frozen legacy nodes and three frozen config
files.  It archives (never deletes) the legacy nodes, atomically installs the
reviewed FPM/PHP/Nginx changes, tests, reloads and reads the result back.
"""

from __future__ import annotations

import argparse
import base64
from dataclasses import asdict, dataclass
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shlex
import stat
import sys
import time
from typing import Any


EXPECTED_HOST = "154.12.25.203"
EXPECTED_PORT = 22
EXPECTED_USER = "root"
REMOTE_PYTHON = "/usr/local/bin/python3"
REMOTE_ROOT = "/www/wwwroot/appht.jjmxg.xyz/yiyunying-backend"
EXECUTE_CONFIRMATION = "archive-seven-nodes-and-harden-config"
MAINTENANCE_CONFIRMATION = "writes-stopped-and-backups-reviewed"
RECONCILE_CONFIRMATION = "reconcile-production-config-prerequisites"
NGINX_SNIPPET_SHA256 = "0407a87963e5e36dcdf4077b5bca63978d46896349e7f688a29f6e91f9d23064"
REMOTE_TIMEOUT_SECONDS = 15 * 60
MAX_REMOTE_OUTPUT = 1024 * 1024


@dataclass(frozen=True)
class ExpectedNode:
    path: str
    path_sha256: str
    kind: str
    canonical_manifest_v1_sha256: str
    device: int
    inode: int
    nlink: int
    uid: int
    gid: int
    mode: int
    size: int
    mtime_ns: int
    payload_size: int
    node_count: int
    file_count: int
    archive_name: str


@dataclass(frozen=True)
class ExpectedConfig:
    label: str
    path: str
    path_sha256: str
    sha256: str
    device: int
    inode: int
    nlink: int
    uid: int
    gid: int
    mode: int
    size: int
    mtime_ns: int
    candidate_sha256: str


EXPECTED_NODES = (
    ExpectedNode(
        REMOTE_ROOT + "/public/.download-center-stage-20260811-045121-5024",
        "110c32a18055a984a64159022c8f56d72a5fa26dce8b6a995e7685772e7c77cd",
        "directory",
        "db30d58ae489fb33b103cbafdec8b94ac9dbb7263f835d1520ae16ef64ec5a00",
        64785, 3014669, 4, 0, 0, 0o777, 4096, 1786395081000000000,
        344903, 32, 29, "01-public-download-center-stage",
    ),
    ExpectedNode(
        REMOTE_ROOT + "/storage/api-docs-desktop.png",
        "a342c4e185f3efd67f8a90d2fb4cefedeabd1e7a59fd01778c64f3ba06e900da",
        "file",
        "74facc28134227b66a4d10fd88315fd5b303b5c4ddb10b9415ca72c0057339e9",
        64785, 1573497, 1, 1000, 1000, 0o666, 96809, 1783856316000000000,
        96809, 1, 1, "02-api-docs-desktop.png",
    ),
    ExpectedNode(
        REMOTE_ROOT + "/storage/api-docs-final-desktop.png",
        "f51ec8509c553daad486ac4e02466760ab791cb0795c4a39a4fb10555146dcf7",
        "file",
        "73d7a775966842b6117855fef80bbf44e60788937612c9b55964b07e3cc593f2",
        64785, 1573788, 1, 1000, 1000, 0o666, 96173, 1783858324000000000,
        96173, 1, 1, "03-api-docs-final-desktop.png",
    ),
    ExpectedNode(
        REMOTE_ROOT + "/storage/api-docs-final-mobile.png",
        "28dbbc8a761beeacf6e5ebc94e44a4536e3fde9850d3f9bd5f0ad3c98d8616a8",
        "file",
        "cb897b26c6a07225ef0d64fb5fd11db93ea1a0c7d4f4796a6236120beef7f94e",
        64785, 1572877, 1, 1000, 1000, 0o666, 36487, 1783858335000000000,
        36487, 1, 1, "04-api-docs-final-mobile.png",
    ),
    ExpectedNode(
        REMOTE_ROOT + "/storage/api-docs-mobile.png",
        "235752600bb70ed80302f8b0e637a12d4d3444fbfeb97502e5ffcf3e1a14926c",
        "file",
        "31ed072a30ca302aea9b944d4d23a41e389a7fa6a0f88fdbf62187899d4973bf",
        64785, 1573838, 1, 1000, 1000, 0o666, 36651, 1783856347000000000,
        36651, 1, 1, "05-api-docs-mobile.png",
    ),
    ExpectedNode(
        REMOTE_ROOT + "/storage/php-8792.err.log",
        "40ab56cb78c36862ad8b71c591edd4c2b222d9ca67a5f18a5c02911e22f427c6",
        "file",
        "b287c24af836feceafcd6bb1647cc92c5a4981dc5603e22bae1c0697a8ae1e57",
        64785, 1576955, 1, 1000, 1000, 0o666, 189351, 1784455284000000000,
        189351, 1, 1, "06-php-8792.err.log",
    ),
    ExpectedNode(
        REMOTE_ROOT + "/storage/php-8792.out.log",
        "da58129dcafb3318c79e897d585e9890670c676d377b8e2ca586a0ea0d08b444",
        "file",
        "40d9a5bf55d757eb76fa02011e4c0ca91859dec8dc8a0ac593ed72a27b04474a",
        64785, 1576956, 1, 1000, 1000, 0o666, 0, 1783857868000000000,
        0, 1, 1, "07-php-8792.out.log",
    ),
)


EXPECTED_CONFIGS = (
    ExpectedConfig(
        "fpm", "/www/server/php/82/etc/php-fpm.conf",
        "bc2c592376f7299197659a0accd9aa66d5998794b2a0f09d9696fbbcbda32ec1",
        "c7f93e40074e1cb88c22b62f0491214e4975b844cc43346de6930418d6b8f314",
        64785, 149514, 1, 0, 0, 0o755, 1239, 1784495027851691548,
        "d6620a8ed2257eb2f0ee7ab30f38b90574ce0bedf19c6a1bc38c06248d42cf35",
    ),
    ExpectedConfig(
        "php_ini", "/www/server/php/82/etc/php.ini",
        "7e8711e1fcb54feb66559ed2457625436e306b7d85fcfeac93f18d4e787fbb03",
        "b6c64a038f9a7c4c5eb55d574525df023e72c022282487002e0be02ed4d5c627",
        64785, 149491, 1, 0, 0, 0o644, 74550, 1784106608121327261,
        "26ca71a9a685cfd84527780cd20554917a70ef89f12d0dff5114dc12dab25aee",
    ),
    ExpectedConfig(
        "nginx", "/www/server/panel/vhost/nginx/appht.jjmxg.xyz.conf",
        "46112b0456640d8024343810dd47d7ac161a98c44f043278f87240d5143064cd",
        "3ee581c29a5493c1d26b4997b7abdb0206865a4a21026f418e8cd3203cceef26",
        64785, 7743, 1, 0, 0, 0o600, 2365, 1786727971113521142,
        "8fb64ddb5e9d31bcfd7c1766f4df2d98644b732f3e4e97d57175481957c22298",
    ),
)


PRODUCTION_LAYOUT = {
    "test_mode": False,
    "real_linux_primitives": False,
    "fault": "",
    "root": REMOTE_ROOT,
    "evidence_parent": "/www/backup/yiyunying",
    "fpm_config": EXPECTED_CONFIGS[0].path,
    "php_ini": EXPECTED_CONFIGS[1].path,
    "nginx_config": EXPECTED_CONFIGS[2].path,
    "dotenv": REMOTE_ROOT + "/.env",
    "fpm_test": ["/www/server/php/82/sbin/php-fpm", "-t", "-y", EXPECTED_CONFIGS[0].path],
    "php_test": [
        "/www/server/php/82/bin/php", "-c", EXPECTED_CONFIGS[1].path, "-r",
        'exit(((string) ini_get("cgi.fix_pathinfo")) === "0" ? 0 : 23);',
    ],
    "nginx_test": ["/www/server/nginx/sbin/nginx", "-t"],
    "fpm_reload": ["/etc/init.d/php-fpm-82", "reload"],
    "nginx_reload": ["/www/server/nginx/sbin/nginx", "-s", "reload"],
    "socket": "/tmp/php-cgi-82.sock",
    "health_url": "https://appht.jjmxg.xyz/api/health",
    "uploads_url_prefix": "https://appht.jjmxg.xyz/uploads/",
}


REMOTE_BODY = r'''
from __future__ import annotations
import contextlib, datetime, hashlib, json, os, pathlib, re, shutil, stat, subprocess, sys, tempfile, time, urllib.error, urllib.request
try:
    import pwd, grp
except ImportError:
    pwd=grp=None
try:
    import fcntl
except ImportError:
    fcntl=None

LAYOUT = __LAYOUT__
EXPECTED_NODES = __EXPECTED_NODES__
EXPECTED_CONFIGS = __EXPECTED_CONFIGS__
NGINX_SNIPPET = __NGINX_SNIPPET__
NGINX_SNIPPET_SHA256 = __NGINX_SNIPPET_SHA256__
HISTORICAL_PUBLIC_FINGERPRINT = "cdedca6e26bb7825013ef8d1a3acbd25bfd600c726f62e4bf2e17356aa2f872d"
HISTORICAL_STORAGE_PREFIXES = ["578a7d13", "e14805e8", "4e7d0ffc", "4287b2b1", "02038de6", "0d6130a8"]
AI_KEYS = {
 "AI_ENABLED", "AI_PROVIDER", "AI_API_URL", "AI_MODEL", "AI_CONNECT_TIMEOUT", "AI_TIMEOUT",
 "AI_MAX_TOKENS", "AI_TEMPERATURE", "AI_HISTORY_LIMIT", "AI_KNOWLEDGE_LIMIT",
 "AI_CONTEXT_DOCUMENT_LIMIT", "AI_CONTEXT_CHARS_PER_DOCUMENT", "AI_HISTORY_MESSAGE_CHARS",
 "AI_RETRY_AFTER_SECONDS", "AI_FALLBACK_ENABLED", "AI_PUBLIC_KNOWLEDGE_ENABLED",
 "AI_PUBLIC_KNOWLEDGE_TIMEOUT", "AI_PUBLIC_KNOWLEDGE_CACHE_SECONDS", "AI_PUBLIC_KNOWLEDGE_LIMIT",
}
DB_KEYS = {"DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASSWORD"}

def sha_bytes(value): return hashlib.sha256(value).hexdigest()

def digest_file(path):
    digest=hashlib.sha256(); size=0
    with path.open("rb") as handle:
        while True:
            chunk=handle.read(1024*1024)
            if not chunk: break
            size+=len(chunk); digest.update(chunk)
    return size,digest.hexdigest()

def fsync_dir(path):
    if LAYOUT["test_mode"] and not LAYOUT.get("real_linux_primitives",False): return
    descriptor=os.open(path,os.O_RDONLY|getattr(os,"O_DIRECTORY",0))
    try: os.fsync(descriptor)
    finally: os.close(descriptor)

def node_kind(mode):
    if stat.S_ISREG(mode): return "file"
    if stat.S_ISDIR(mode): return "directory"
    if stat.S_ISLNK(mode): return "symlink"
    return "special"

def canonical_manifest(path):
    meta=os.lstat(path); kind=node_kind(meta.st_mode); rows=[]; files=0; payload=0
    if kind=="file":
        size,content=digest_file(path); files=1; payload=size
        rows.append("\t".join(map(str,(".","f",stat.S_IMODE(meta.st_mode),meta.st_uid,meta.st_gid,meta.st_dev,meta.st_ino,meta.st_nlink,size,meta.st_mtime_ns,content))))
    elif kind=="directory":
        for base,dirs,names in os.walk(path,followlinks=False):
            dirs.sort(); names.sort(); base_path=pathlib.Path(base)
            for name in ["."]+dirs+names:
                item=base_path if name=="." else base_path/name
                relative="." if item==path else item.relative_to(path).as_posix()
                current=os.lstat(item); current_kind=node_kind(current.st_mode)
                if current_kind=="directory": tag="d"; content="-"; size=0
                elif current_kind=="file":
                    tag="f"; size,content=digest_file(item); files+=1; payload+=size
                elif current_kind=="symlink":
                    tag="l"; raw=os.readlink(item).encode("utf-8"); content=sha_bytes(raw); size=len(raw)
                else: tag="x"; content="-"; size=current.st_size
                rows.append("\t".join(map(str,(relative,tag,stat.S_IMODE(current.st_mode),current.st_uid,current.st_gid,current.st_dev,current.st_ino,current.st_nlink,size,current.st_mtime_ns,content))))
        rows=sorted(set(rows),key=lambda value:value.encode("utf-8"))
    else:
        raise RuntimeError("unsupported_node_type")
    encoded=("canonical-manifest-v1\n"+"\n".join(rows)+"\n").encode("utf-8")
    return {"kind":kind,"canonical_manifest_v1_sha256":sha_bytes(encoded),"payload_size":payload,"node_count":len(rows),"file_count":files}

def fd_refs(path):
    if LAYOUT["test_mode"] and not LAYOUT.get("real_linux_primitives",False): return 0
    resolved=str(path.resolve(strict=True)); prefix=resolved+"/"; count=0
    for process in pathlib.Path("/proc").iterdir():
        if not process.name.isdigit(): continue
        try: descriptors=list((process/"fd").iterdir())
        except OSError: continue
        for descriptor in descriptors:
            try: target=os.path.realpath(descriptor)
            except OSError: continue
            if target==resolved or target.startswith(prefix): count+=1
    return count

def source_refs(path):
    if LAYOUT["test_mode"] and not LAYOUT.get("real_linux_primitives",False): return 0
    root=pathlib.Path(LAYOUT["root"]); needle=path.name.encode("utf-8"); count=0
    for relative in ("app","config","database","deploy","docs","routes","tools"):
        top=root/relative
        for base,dirs,files in os.walk(top,followlinks=False):
            dirs[:]=sorted(name for name in dirs if not pathlib.Path(base,name).is_symlink())
            for name in sorted(files):
                candidate=pathlib.Path(base,name)
                try:
                    meta=os.lstat(candidate)
                    if not stat.S_ISREG(meta.st_mode) or meta.st_size>8*1024*1024: continue
                    count+=candidate.read_bytes().count(needle)
                except OSError: continue
    return count

def sample_node(expected, path=None):
    target=pathlib.Path(path or expected["path"]); metadata=os.lstat(target)
    sample={
      "path_sha256":sha_bytes(expected["path"].encode("utf-8")), "device":metadata.st_dev,
      "inode":metadata.st_ino,"nlink":metadata.st_nlink,"uid":metadata.st_uid,"gid":metadata.st_gid,
      "mode":stat.S_IMODE(metadata.st_mode),"size":metadata.st_size,"mtime_ns":metadata.st_mtime_ns,
      "fd_refs":fd_refs(target),"source_refs":source_refs(target) if path is None else 0,
    }
    sample.update(canonical_manifest(target)); return sample

def expected_node_projection(expected):
    return {key:expected[key] for key in (
      "path_sha256","kind","canonical_manifest_v1_sha256","device","inode","nlink","uid","gid",
      "mode","size","mtime_ns","payload_size","node_count","file_count")}

def verify_node(expected, path=None, require_idle=True):
    sample=sample_node(expected,path)
    if {key:sample[key] for key in expected_node_projection(expected)} != expected_node_projection(expected):
        raise RuntimeError("node_fingerprint_mismatch")
    if require_idle and (sample["fd_refs"]!=0 or sample["source_refs"]!=0):
        raise RuntimeError("node_reference_boundary")
    return sample

def config_sample(expected):
    path=pathlib.Path(expected["path"]); metadata=os.lstat(path)
    if node_kind(metadata.st_mode)!="file" or metadata.st_nlink!=1: raise RuntimeError("config_type_boundary")
    size,digest=digest_file(path)
    result={"path_sha256":sha_bytes(expected["path"].encode("utf-8")),"sha256":digest,"device":metadata.st_dev,
      "inode":metadata.st_ino,"nlink":metadata.st_nlink,"uid":metadata.st_uid,"gid":metadata.st_gid,
      "mode":stat.S_IMODE(metadata.st_mode),"size":size,"mtime_ns":metadata.st_mtime_ns}
    projected={key:expected[key] for key in result}
    if result!=projected: raise RuntimeError("config_original_binding_mismatch")
    return result

def dotenv(path):
    values={}
    for index,raw in enumerate(path.read_text("utf-8",errors="strict").splitlines()):
        if index==0: raw=raw.lstrip("\ufeff")
        line=raw.strip()
        if not line or line.startswith("#") or line.startswith(";"): continue
        if line.startswith("export "): line=line[7:].strip()
        if "=" not in line: continue
        name,value=line.split("=",1); name=name.strip()
        if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*",name) or name in values: continue
        value=value.strip()
        if len(value)>=2 and value[0]==value[-1] and value[0] in "\"'": value=value[1:-1]
        else: value=re.sub(r"\s+[;#].*$","",value).rstrip()
        values[name]=value
    return values

def fpm_env(text):
    values={}
    for raw in text.splitlines():
        line=raw.strip()
        if not line or line.startswith(";") or line.startswith("#"): continue
        match=re.fullmatch(r"env\[([A-Za-z_][A-Za-z0-9_]*)\]\s*=\s*(.*)",line)
        if match: values.setdefault(match.group(1),[]).append(match.group(2).strip().strip("\"'"))
    return values

def environment_contract(fpm_text):
    dot=dotenv(pathlib.Path(LAYOUT["dotenv"])); pool=fpm_env(fpm_text)
    if any(not dot.get(key) for key in DB_KEYS) or any(key in pool for key in DB_KEYS):
        raise RuntimeError("database_environment_source_boundary")
    if any(key in dot for key in AI_KEYS) or any(len(pool.get(key,[]))!=1 or not pool[key][0] for key in AI_KEYS):
        raise RuntimeError("ai_environment_source_boundary")
    def effective(name,default=""):
        if name in pool:
            if len(pool[name])!=1: raise RuntimeError("mail_environment_duplicate")
            return pool[name][0]
        return dot.get(name,default)
    master=effective("MAIL_SETTINGS_MASTER_KEY","")
    transport=effective("MAIL_TRANSPORT","disabled").strip().lower()
    if master:
        if re.fullmatch(r"[0-9a-f]{64}",master) is None: raise RuntimeError("mail_master_key_format")
        mail_state="master-key-present"
    else:
        if transport!="disabled": raise RuntimeError("mail_without_master_key_must_be_disabled")
        mail_state="default-disabled" if "MAIL_TRANSPORT" not in pool and "MAIL_TRANSPORT" not in dot else "explicit-disabled"
    return {"db_from_dotenv":5,"ai_from_pool":19,"mail_state":mail_state}

def replace_directive(text,name,old,new):
    pattern=re.compile(r"(?m)^(?P<indent>[ \t]*)"+re.escape(name)+r"[ \t]*=[ \t]*(?P<value>[^\r\n;#]*?)[ \t]*(?P<comment>[;#][^\r\n]*)?$")
    matches=list(pattern.finditer(text))
    if len(matches)!=1 or matches[0].group("value").strip()!=old: raise RuntimeError("directive_precondition")
    match=matches[0]; replacement=match.group("indent")+name+" = "+new
    if match.group("comment"): replacement+=" "+match.group("comment")
    return text[:match.start()]+replacement+text[match.end():]

def active_directive(text,name):
    pattern=re.compile(r"(?m)^[ \t]*"+re.escape(name)+r"[ \t]*=[ \t]*([^\r\n;#]*?)[ \t]*(?:[;#][^\r\n]*)?$")
    values=[match.group(1).strip() for match in pattern.finditer(text)]
    if len(values)!=1: raise RuntimeError("directive_count")
    return values[0]

def mask_nginx(text):
    output=list(text); quote=None; escape=False; comment=False
    for index,char in enumerate(text):
        if comment:
            if char in "\r\n": comment=False
            else: output[index]=" "
            continue
        if quote:
            if escape: escape=False; output[index]=" "; continue
            if char=="\\": escape=True; output[index]=" "; continue
            if char==quote: quote=None
            output[index]=" "
            continue
        if char=="#": comment=True; output[index]=" "; continue
        if char in "\"'": quote=char; output[index]=" "
    if quote or escape: raise RuntimeError("nginx_lexical_boundary")
    return "".join(output)

def replace_uploads_block(text):
    masked=mask_nginx(text)
    pattern=re.compile(r"(?m)^(?P<indent>[ \t]*)location[ \t]+(?P<modifier>[=~^*]*)[ \t]*/uploads/[ \t]*\{")
    matches=list(pattern.finditer(masked))
    if len(matches)!=1 or matches[0].group("modifier")!="": raise RuntimeError("nginx_uploads_block_precondition")
    match=matches[0]; depth=0; end=None
    for index in range(match.end()-1,len(masked)):
        if masked[index]=="{": depth+=1
        elif masked[index]=="}":
            depth-=1
            if depth==0: end=index+1; break
        if depth<0: raise RuntimeError("nginx_brace_boundary")
    if end is None: raise RuntimeError("nginx_uploads_block_unclosed")
    while end<len(text) and text[end] in " \t": end+=1
    if end<len(text) and text[end]=="\r": end+=1
    if end<len(text) and text[end]=="\n": end+=1
    indent=match.group("indent")
    snippet="\n".join((indent+line if line else "") for line in NGINX_SNIPPET.rstrip("\r\n").splitlines())+"\n"
    candidate=text[:match.start()]+snippet+text[end:]
    candidate_mask=mask_nginx(candidate)
    safe=re.findall(r"(?m)^[ \t]*location[ \t]+\^~[ \t]+/uploads/[ \t]*\{",candidate_mask)
    unsafe=re.findall(r"(?m)^[ \t]*location[ \t]+(?:[=~^*]+[ \t]+)?/uploads/[ \t]*\{",candidate_mask)
    if len(safe)!=1 or len(unsafe)!=1: raise RuntimeError("nginx_uploads_block_postcondition")
    block=NGINX_SNIPPET
    if "fastcgi" in block.lower() or "disable_symlinks on;" not in block or "limit_except GET HEAD" not in block:
        raise RuntimeError("nginx_uploads_safety_postcondition")
    return candidate

def transform_configs(originals):
    fpm=originals["fpm"].decode("utf-8","strict")
    if active_directive(fpm,"listen.owner")!="www" or active_directive(fpm,"listen.group")!="www":
        raise RuntimeError("fpm_socket_owner_boundary")
    environment=environment_contract(fpm)
    fpm=replace_directive(fpm,"listen.mode","0666","0660")
    fpm=replace_directive(fpm,"clear_env","no","yes")
    if active_directive(fpm,"listen.mode")!="0660" or active_directive(fpm,"clear_env")!="yes":
        raise RuntimeError("fpm_postcondition")
    php=originals["php_ini"].decode("utf-8","strict")
    php=replace_directive(php,"cgi.fix_pathinfo","1","0")
    if active_directive(php,"cgi.fix_pathinfo")!="0": raise RuntimeError("php_ini_postcondition")
    nginx=replace_uploads_block(originals["nginx"].decode("utf-8","strict"))
    candidates={"fpm":fpm.encode("utf-8"),"php_ini":php.encode("utf-8"),"nginx":nginx.encode("utf-8")}
    expected_hashes={item["label"]:item["candidate_sha256"] for item in EXPECTED_CONFIGS}
    if {key:sha_bytes(value) for key,value in candidates.items()}!=expected_hashes: raise RuntimeError("config_candidate_binding_mismatch")
    return candidates,environment

def run_checked(command,label):
    if not isinstance(command,list) or not command or any(not isinstance(item,str) or not item for item in command):
        raise RuntimeError("command_boundary")
    result=subprocess.run(command,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=120,check=False,env={"PATH":"/usr/local/bin:/usr/bin:/bin","LC_ALL":"C","LANG":"C"} if not LAYOUT["test_mode"] else os.environ.copy())
    if result.returncode!=0: raise RuntimeError(label)

def syntax_tests(restoring=False):
    if LAYOUT["fault"] in {"syntax","syntax_rollback"} and not restoring: raise RuntimeError("injected_syntax_failure")
    run_checked(LAYOUT["fpm_test"],"fpm_syntax_failed")
    run_checked(LAYOUT["php_test"],"php_ini_syntax_failed")
    run_checked(LAYOUT["nginx_test"],"nginx_syntax_failed")

def read_bytes_exact(path):
    meta=os.lstat(path)
    if not stat.S_ISREG(meta.st_mode) or stat.S_ISLNK(meta.st_mode): raise RuntimeError("regular_file_boundary")
    return path.read_bytes()

def write_exclusive(path,payload,mode,uid,gid):
    descriptor=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|getattr(os,"O_NOFOLLOW",0)|getattr(os,"O_BINARY",0),mode)
    try:
        view=memoryview(payload)
        while view:
            written=os.write(descriptor,view)
            if written<=0: raise RuntimeError("short_write_boundary")
            view=view[written:]
        os.fsync(descriptor)
    finally: os.close(descriptor)
    if not LAYOUT["test_mode"]: os.chown(path,uid,gid)
    os.chmod(path,mode)
    if not LAYOUT["test_mode"] or LAYOUT.get("real_linux_primitives",False):
        descriptor=os.open(path,os.O_RDONLY|getattr(os,"O_NOFOLLOW",0))
        try: os.fsync(descriptor)
        finally: os.close(descriptor)

class StateUncertain(RuntimeError): pass

PHASE_RANK={
 "created":10,"prepared":20,"nodes_archived":30,"configs_activated":40,"validated":50,
 "archive_finalized":60,"manifest_committed":70,"committed":80,
 "rollback_started":90,"recovery_required":100,"restored":110,
}
MANIFEST_RANK={"prepared":10,"committed":20}

def failure_code(error):
    reason=str(error)
    return reason if re.fullmatch(r"[a-z0-9_]+",reason or "") else error.__class__.__name__.lower()

def strict_json_bytes(payload):
    return (json.dumps(payload,sort_keys=True,separators=(",",":"))+"\n").encode("utf-8")

def read_json_object(path):
    raw=read_bytes_exact(path)
    if not raw.endswith(b"\n") or raw.count(b"\n")!=1: raise RuntimeError("journal_json_boundary")
    def unique(pairs):
        result={}
        for key,value in pairs:
            if key in result: raise ValueError("duplicate_key")
            result[key]=value
        return result
    try: value=json.loads(raw.decode("utf-8","strict"),object_pairs_hook=unique)
    except (UnicodeError,ValueError,json.JSONDecodeError) as error: raise RuntimeError("journal_json_boundary") from error
    if not isinstance(value,dict): raise RuntimeError("journal_json_boundary")
    return value

def atomic_json(path,payload,fault_after_replace=""):
    revision=payload.get("revision",0)
    token=payload.get("token","none")
    temporary=path.with_name("."+path.name+"."+str(token)+"."+str(revision)+".candidate")
    write_exclusive(temporary,strict_json_bytes(payload),0o600,0,0)
    replaced=False
    try:
        os.replace(temporary,path); replaced=True
        if fault_after_replace and LAYOUT["fault"]==fault_after_replace:
            raise StateUncertain("atomic_replace_fsync_uncertain")
        try: fsync_dir(path.parent)
        except BaseException as error: raise StateUncertain("atomic_replace_fsync_uncertain") from error
    except StateUncertain: raise
    except BaseException:
        if replaced: raise StateUncertain("atomic_replace_result_uncertain")
        raise

def journal_file(parent,token):
    return parent/(".production-config-prerequisites-"+token+".status.json")

def validate_journal(path,token):
    value=read_json_object(path); meta=os.lstat(path)
    required={"schema","token","revision","phase","phase_rank","partial","final","archive_name","reload_started","last_failure_code"}
    if set(value)!=required or value["schema"]!="production-config-prerequisites-status-v1" or value["token"]!=token:
        raise RuntimeError("status_journal_contract")
    if not isinstance(value["revision"],int) or value["revision"]<1 or value.get("phase") not in PHASE_RANK:
        raise RuntimeError("status_journal_contract")
    if value["phase_rank"]!=PHASE_RANK[value["phase"]] or not isinstance(value["reload_started"],bool):
        raise RuntimeError("status_journal_contract")
    if not all(isinstance(value[key],str) for key in ("partial","final","archive_name","last_failure_code")):
        raise RuntimeError("status_journal_contract")
    if ((not LAYOUT["test_mode"] or LAYOUT.get("real_linux_primitives",False)) and stat.S_IMODE(meta.st_mode)!=0o600) or (not LAYOUT["test_mode"] and (meta.st_uid!=0 or meta.st_gid!=0 or meta.st_nlink!=1)):
        raise RuntimeError("status_journal_metadata")
    return value

def create_journal(parent,token,partial,final,archive_name):
    path=journal_file(parent,token)
    value={"schema":"production-config-prerequisites-status-v1","token":token,"revision":1,
      "phase":"created","phase_rank":PHASE_RANK["created"],"partial":str(partial),"final":str(final),
      "archive_name":archive_name,"reload_started":False,"last_failure_code":""}
    write_exclusive(path,strict_json_bytes(value),0o600,0,0)
    if LAYOUT["fault"]=="journal_after_file_fsync": raise StateUncertain("journal_parent_fsync_uncertain")
    try: fsync_dir(parent)
    except BaseException as error: raise StateUncertain("journal_parent_fsync_uncertain") from error
    return path,value

def advance_journal(path,current,phase,**updates):
    if phase not in PHASE_RANK or PHASE_RANK[phase]<current["phase_rank"]: raise RuntimeError("journal_phase_regression")
    value=dict(current); value.update(updates); value.update({"revision":current["revision"]+1,"phase":phase,"phase_rank":PHASE_RANK[phase]})
    fault="journal_commit_after_replace" if phase=="committed" else "journal_after_replace"
    atomic_json(path,value,fault)
    return value

@contextlib.contextmanager
def maintenance_lock(parent,exclusive):
    if LAYOUT["test_mode"] and not LAYOUT.get("real_linux_primitives",False) and fcntl is None:
        yield
        return
    descriptor=os.open(parent,os.O_RDONLY|getattr(os,"O_DIRECTORY",0))
    locked=False
    try:
        if fcntl is None:
            if not LAYOUT["test_mode"]: raise RuntimeError("maintenance_lock_unavailable")
        else:
            operation=(fcntl.LOCK_EX if exclusive else fcntl.LOCK_SH)|fcntl.LOCK_NB
            try: fcntl.flock(descriptor,operation); locked=True
            except BlockingIOError as error: raise RuntimeError("maintenance_lock_busy") from error
        yield
    finally:
        if locked: fcntl.flock(descriptor,fcntl.LOCK_UN)
        os.close(descriptor)

def prepare_candidate(expected,payload,token,holds_dir):
    live=pathlib.Path(expected["path"]); candidate=live.with_name("."+live.name+".yiyunying-prereq-"+token+".candidate")
    hold=holds_dir/(expected["label"]+".original-inode")
    if candidate.exists() or candidate.is_symlink() or hold.exists() or hold.is_symlink(): raise RuntimeError("config_stage_collision")
    config_sample(expected)
    write_exclusive(candidate,payload,expected["mode"],expected["uid"],expected["gid"])
    os.link(live,hold,follow_symlinks=False)
    live_meta=os.lstat(live); hold_meta=os.lstat(hold)
    if live_meta.st_dev!=expected["device"] or live_meta.st_ino!=expected["inode"] or live_meta.st_nlink!=2 or hold_meta.st_ino!=expected["inode"] or hold_meta.st_nlink!=2:
        raise RuntimeError("config_hold_boundary")
    fsync_dir(hold.parent); fsync_dir(live.parent)
    return candidate,hold

def activate_config(expected,candidate,hold):
    live=pathlib.Path(expected["path"]); live_meta=os.lstat(live); hold_meta=os.lstat(hold)
    if (live_meta.st_dev,live_meta.st_ino,live_meta.st_nlink)!=(expected["device"],expected["inode"],2) or (hold_meta.st_dev,hold_meta.st_ino,hold_meta.st_nlink)!=(expected["device"],expected["inode"],2):
        raise RuntimeError("config_hold_boundary")
    size,digest=digest_file(live)
    if size!=expected["size"] or digest!=expected["sha256"]: raise RuntimeError("config_original_binding_mismatch")
    os.replace(candidate,live)
    if LAYOUT["fault"]=="activation_after_replace": raise StateUncertain("activation_replace_fsync_uncertain")
    try: fsync_dir(live.parent)
    except BaseException as error: raise StateUncertain("activation_replace_fsync_uncertain") from error

def rollback_config(expected,hold):
    live=pathlib.Path(expected["path"])
    if LAYOUT["fault"] in {"rollback","syntax_rollback"}: raise RuntimeError("injected_rollback_failure")
    if hold.exists() or hold.is_symlink():
        hold_meta=os.lstat(hold)
        if (stat.S_ISLNK(hold_meta.st_mode) or not stat.S_ISREG(hold_meta.st_mode) or hold_meta.st_dev!=expected["device"]
          or hold_meta.st_ino!=expected["inode"] or hold_meta.st_uid!=expected["uid"] or hold_meta.st_gid!=expected["gid"]
          or stat.S_IMODE(hold_meta.st_mode)!=expected["mode"] or hold_meta.st_mtime_ns!=expected["mtime_ns"]):
            raise RuntimeError("config_hold_identity_boundary")
        hold_size,hold_digest=digest_file(hold)
        if hold_size!=expected["size"] or hold_digest!=expected["sha256"]: raise RuntimeError("config_hold_hash_boundary")
        if live.exists() or live.is_symlink():
            live_meta=os.lstat(live)
            if live_meta.st_dev==expected["device"] and live_meta.st_ino==expected["inode"]:
                hold.unlink(); fsync_dir(hold.parent); fsync_dir(live.parent); config_sample(expected); return
        os.replace(hold,live); fsync_dir(hold.parent); fsync_dir(live.parent); config_sample(expected); return
    config_sample(expected)

def verify_candidate_config(expected):
    path=pathlib.Path(expected["path"]); meta=os.lstat(path); size,digest=digest_file(path)
    if node_kind(meta.st_mode)!="file" or stat.S_ISLNK(meta.st_mode) or meta.st_nlink!=1:
        raise RuntimeError("candidate_config_type_boundary")
    if digest!=expected["candidate_sha256"] or size!=meta.st_size:
        raise RuntimeError("candidate_config_hash_boundary")
    if (not LAYOUT["test_mode"] or LAYOUT.get("real_linux_primitives",False)) and (meta.st_uid!=expected["uid"] or meta.st_gid!=expected["gid"] or stat.S_IMODE(meta.st_mode)!=expected["mode"]):
        raise RuntimeError("candidate_config_metadata_boundary")

def verify_original_hold(expected,hold):
    meta=os.lstat(hold); size,digest=digest_file(hold)
    if (node_kind(meta.st_mode)!="file" or stat.S_ISLNK(meta.st_mode) or meta.st_dev!=expected["device"] or meta.st_ino!=expected["inode"]
      or meta.st_nlink!=1 or meta.st_uid!=expected["uid"] or meta.st_gid!=expected["gid"]
      or stat.S_IMODE(meta.st_mode)!=expected["mode"] or meta.st_mtime_ns!=expected["mtime_ns"]):
        raise RuntimeError("config_hold_identity_boundary")
    if size!=expected["size"] or digest!=expected["sha256"]: raise RuntimeError("config_hold_hash_boundary")

def move_exact(source,destination,restoring=False):
    if LAYOUT["test_mode"] and not LAYOUT.get("real_linux_primitives",False):
        if destination.exists() or destination.is_symlink(): raise RuntimeError("archive_destination_exists")
        os.replace(source,destination)
    else:
        run_checked(["/usr/bin/mv","-T","--no-clobber","--",str(source),str(destination)],"exact_move_failed")
    if LAYOUT["fault"]=="move_after_replace" and not restoring: raise StateUncertain("move_replace_fsync_uncertain")
    try: fsync_dir(source.parent); fsync_dir(destination.parent)
    except BaseException as error: raise StateUncertain("move_replace_fsync_uncertain") from error

def restore_node(item,source,destination):
    source_exists=source.exists() or source.is_symlink(); destination_exists=destination.exists() or destination.is_symlink()
    if source_exists and not destination_exists:
        verify_node(item); return
    if destination_exists and not source_exists:
        verify_node(item,destination,require_idle=True)
        move_exact(destination,source,restoring=True); verify_node(item); return
    raise RuntimeError("node_restore_state_boundary")

def make_directory(path,mode):
    path.mkdir(mode=mode,parents=False,exist_ok=False)
    if not LAYOUT["test_mode"]: os.chown(path,0,0)
    os.chmod(path,mode); fsync_dir(path); fsync_dir(path.parent)

def manifest_base(token,archive,config_hashes):
    return {"schema":"production-config-prerequisites-v1","token":token,"archive":str(archive),
      "historical_evidence":{"public_legacy_fingerprint":HISTORICAL_PUBLIC_FINGERPRINT,
       "storage_legacy_prefixes":HISTORICAL_STORAGE_PREFIXES,"algorithm_comparable":False},
      "nodes":[{"source":item["path"],"path_sha256":item["path_sha256"],"canonical_manifest_v1_sha256":item["canonical_manifest_v1_sha256"],"archive_name":item["archive_name"]} for item in EXPECTED_NODES],
      "configs":config_hashes}

def write_manifest(path,manifest,state):
    if state not in MANIFEST_RANK: raise RuntimeError("manifest_state_boundary")
    if path.exists() or path.is_symlink():
        old=read_json_object(path); old_state=old.get("state")
        if old_state not in MANIFEST_RANK or MANIFEST_RANK[state]<MANIFEST_RANK[old_state]: raise RuntimeError("manifest_state_regression")
    value=dict(manifest); value["state"]=state
    fault="manifest_commit_after_replace" if state=="committed" else "manifest_prepared_after_replace"
    atomic_json(path,value,fault)
    return value

def validate_root_boundary():
    root=pathlib.Path(LAYOUT["root"]); parent=pathlib.Path(LAYOUT["evidence_parent"])
    if not LAYOUT["test_mode"]:
        if os.geteuid()!=0 or root.resolve(strict=True)!=root or parent.resolve(strict=True)!=parent: raise RuntimeError("root_boundary")
        state=os.lstat(parent)
        if not stat.S_ISDIR(state.st_mode) or stat.S_ISLNK(state.st_mode): raise RuntimeError("evidence_parent_type_boundary")
        if state.st_uid!=0 or state.st_gid!=0: raise RuntimeError("evidence_parent_owner_boundary")
        if stat.S_IMODE(state.st_mode)&0o022: raise RuntimeError("evidence_parent_mode_boundary")
    if parent.stat().st_dev!=root.stat().st_dev: raise RuntimeError("evidence_parent_device_boundary")
    if sha_bytes(NGINX_SNIPPET.encode("utf-8"))!=NGINX_SNIPPET_SHA256: raise RuntimeError("nginx_snippet_binding")
    return root,parent

def current_originals():
    originals={}
    for expected in EXPECTED_CONFIGS:
        config_sample(expected); originals[expected["label"]]=pathlib.Path(expected["path"]).read_bytes()
    return originals

def readback_configs(candidates):
    for expected in EXPECTED_CONFIGS:
        path=pathlib.Path(expected["path"]); meta=os.lstat(path); payload=read_bytes_exact(path)
        wanted=candidates[expected["label"]]
        compared=payload.replace(b"\r\n",b"\n") if LAYOUT["test_mode"] else payload
        if compared!=wanted: raise RuntimeError("config_readback_payload_"+expected["label"])
        if (not LAYOUT["test_mode"] or LAYOUT.get("real_linux_primitives",False)) and (meta.st_uid!=expected["uid"] or meta.st_gid!=expected["gid"] or stat.S_IMODE(meta.st_mode)!=expected["mode"]):
            raise RuntimeError("config_readback_metadata_"+expected["label"])

def wait_socket():
    if LAYOUT["test_mode"] and not LAYOUT.get("real_linux_primitives",False): return
    target=pathlib.Path(LAYOUT["socket"])
    if LAYOUT["test_mode"]:
        expected_uid=LAYOUT["socket_uid"]; expected_gid=LAYOUT["socket_gid"]
    else:
        if pwd is None or grp is None: raise RuntimeError("socket_identity_lookup_unavailable")
        expected_uid=pwd.getpwnam("www").pw_uid; expected_gid=grp.getgrnam("www").gr_gid
    deadline=time.monotonic()+20
    while time.monotonic()<deadline:
        try:
            meta=os.lstat(target)
            if stat.S_ISSOCK(meta.st_mode) and meta.st_uid==expected_uid and meta.st_gid==expected_gid and stat.S_IMODE(meta.st_mode)==0o660: return
        except OSError: pass
        time.sleep(0.2)
    raise RuntimeError("fpm_socket_readback_failed")

def https_readback(token):
    if LAYOUT["test_mode"] and not LAYOUT.get("real_linux_primitives",False):
        marker=pathlib.Path(LAYOUT["test_health"])
        data=json.loads(marker.read_text("utf-8"))
        if data!={"code":1,"status":"ok","database":"connected"}: raise RuntimeError("test_health_failed")
        return
    if LAYOUT["test_mode"] and (not LAYOUT["health_url"].startswith("http://127.0.0.1:") or not LAYOUT["uploads_url_prefix"].startswith("http://127.0.0.1:")):
        raise RuntimeError("test_http_loopback_boundary")
    request=urllib.request.Request(LAYOUT["health_url"],headers={"Accept":"application/json","User-Agent":"yiyunying-config-prerequisites/1"})
    with urllib.request.urlopen(request,timeout=15) as response:
        if response.status!=200: raise RuntimeError("https_health_status")
        payload=json.loads(response.read(1024*1024).decode("utf-8"))
    data=payload.get("data",{}) if isinstance(payload,dict) else {}
    if payload.get("code")!=1 or data.get("status")!="ok" or data.get("database")!="connected": raise RuntimeError("https_health_payload")
    probe=LAYOUT["uploads_url_prefix"]+".config-prerequisites-"+token+".php"
    try:
        urllib.request.urlopen(urllib.request.Request(probe,headers={"User-Agent":"yiyunying-config-prerequisites/1"}),timeout=15)
    except urllib.error.HTTPError as error:
        if error.code==404: return
        raise RuntimeError("upload_script_http_status")
    raise RuntimeError("upload_script_not_rejected")

def reload_and_readback(candidates,token):
    run_checked(LAYOUT["fpm_reload"],"fpm_reload_failed")
    run_checked(LAYOUT["nginx_reload"],"nginx_reload_failed")
    wait_socket(); readback_configs(candidates); syntax_tests(); https_readback(token)

def audit():
    validate_root_boundary(); first=[verify_node(item) for item in EXPECTED_NODES]
    originals=current_originals(); candidates,environment=transform_configs(originals)
    second=[verify_node(item) for item in EXPECTED_NODES]
    if first!=second: raise RuntimeError("node_double_sample_changed")
    return {"CONFIG_PREREQUISITES_AUDIT":"pass","schema":"production-config-prerequisites-v1","nodes":len(first),
      "fd_refs":sum(item["fd_refs"] for item in second),"source_refs":sum(item["source_refs"] for item in second),
      "public":{"nodes":first[0]["node_count"],"files":first[0]["file_count"],"payload_size":first[0]["payload_size"]},
      "environment":environment,"candidate_sha256":{key:sha_bytes(value) for key,value in candidates.items()},
      "historical_algorithm_comparable":False,"write_actions":0}

def archive_paths_from_journal(parent,journal):
    archive_name=journal["archive_name"]
    if re.fullmatch(r"production-config-prerequisites-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{32}",archive_name) is None:
        raise RuntimeError("journal_archive_name_boundary")
    partial=parent/(archive_name+".partial"); final=parent/archive_name
    if journal["partial"]!=str(partial) or journal["final"]!=str(final): raise RuntimeError("journal_archive_path_boundary")
    return partial,final

def archive_location(partial,final):
    partial_exists=partial.exists() or partial.is_symlink(); final_exists=final.exists() or final.is_symlink()
    if partial_exists and final_exists: return "conflict",None
    if final_exists: return "final",final
    if partial_exists: return "partial",partial
    return "absent",None

def validate_archive_root(path):
    meta=os.lstat(path)
    if not stat.S_ISDIR(meta.st_mode) or stat.S_ISLNK(meta.st_mode) or ((not LAYOUT["test_mode"] or LAYOUT.get("real_linux_primitives",False)) and stat.S_IMODE(meta.st_mode)!=0o700):
        raise RuntimeError("archive_root_boundary")
    if not LAYOUT["test_mode"] and (meta.st_uid!=0 or meta.st_gid!=0): raise RuntimeError("archive_root_owner_boundary")

def validate_archived_nodes(archive):
    validate_archive_root(archive); nodes_dir=archive/"archived-nodes"
    archived=[]
    for item in EXPECTED_NODES:
        source=pathlib.Path(item["path"]); destination=nodes_dir/item["archive_name"]
        if source.exists() or source.is_symlink(): raise RuntimeError("archived_source_still_present")
        archived.append(verify_node(item,destination,require_idle=True))
    fsync_dir(nodes_dir)
    return archived

def config_hash_rows(candidates):
    return [{"label":expected["label"],"path":expected["path"],"original_sha256":expected["sha256"],
      "candidate_sha256":sha_bytes(candidates[expected["label"]])} for expected in EXPECTED_CONFIGS]

def verify_success_config_state(archive,candidates):
    holds=archive/"config-original-inodes"
    for expected in EXPECTED_CONFIGS:
        verify_candidate_config(expected)
        verify_original_hold(expected,holds/(expected["label"]+".original-inode"))
    readback_configs(candidates)
    return len(EXPECTED_CONFIGS)

def status_counts(archive):
    source_nodes=archived_nodes=original_configs=candidate_configs=holds=0
    for item in EXPECTED_NODES:
        source=pathlib.Path(item["path"])
        try: verify_node(item); source_nodes+=1
        except BaseException: pass
        if archive is not None:
            try: verify_node(item,archive/"archived-nodes"/item["archive_name"],require_idle=True); archived_nodes+=1
            except BaseException: pass
    for expected in EXPECTED_CONFIGS:
        path=pathlib.Path(expected["path"])
        try:
            meta=os.lstat(path); size,digest=digest_file(path)
            if meta.st_dev==expected["device"] and meta.st_ino==expected["inode"] and meta.st_nlink in {1,2} and size==expected["size"] and digest==expected["sha256"]:
                original_configs+=1
        except BaseException: pass
        try: verify_candidate_config(expected); candidate_configs+=1
        except BaseException: pass
        if archive is not None:
            hold=archive/"config-original-inodes"/(expected["label"]+".original-inode")
            try:
                meta=os.lstat(hold); size,digest=digest_file(hold)
                if meta.st_dev==expected["device"] and meta.st_ino==expected["inode"] and meta.st_nlink in {1,2} and size==expected["size"] and digest==expected["sha256"]: holds+=1
            except BaseException: pass
    return source_nodes,archived_nodes,original_configs,candidate_configs,holds

def recovery_receipt(token,journal_path,archive,failure,components):
    archive_hash=sha_bytes(str(archive).encode("utf-8")) if archive is not None else "0"*64
    return {"CONFIG_PREREQUISITES_APPLY":"recovery_required","schema":"production-config-prerequisites-v1",
      "transaction_token":token,"phase":"recovery_required","journal_path_sha256":sha_bytes(str(journal_path).encode("utf-8")),
      "archive_path_sha256":archive_hash,"failure_code":failure,"components":sorted(set(components)),"reconcile_required":True}

def rollback_transaction(parent,token,journal_path,journal,primary_code):
    recovery=[]; restored_nodes=0; restored_configs=0
    if journal["phase_rank"]<PHASE_RANK["rollback_started"]:
        try: journal=advance_journal(journal_path,journal,"rollback_started",last_failure_code=primary_code)
        except StateUncertain: raise
        except BaseException: recovery.append("journal")
    partial,final=archive_paths_from_journal(parent,journal)
    location,archive=archive_location(partial,final)
    if location=="conflict": recovery.append("archive_conflict")
    elif location=="final":
        try:
            os.replace(final,partial)
            if LAYOUT["fault"]=="rollback_archive_after_replace": raise StateUncertain("rollback_archive_fsync_uncertain")
            try: fsync_dir(parent)
            except BaseException as error: raise StateUncertain("rollback_archive_fsync_uncertain") from error
            archive=partial
        except StateUncertain: raise
        except BaseException: recovery.append("archive_restore")
    try:
        holds_dir=(archive/"config-original-inodes") if archive is not None else partial/"config-original-inodes"
        for expected in reversed(EXPECTED_CONFIGS):
            hold=holds_dir/(expected["label"]+".original-inode")
            rollback_config(expected,hold); restored_configs+=1
            candidate=pathlib.Path(expected["path"]).with_name("."+pathlib.Path(expected["path"]).name+".yiyunying-prereq-"+token+".candidate")
            if candidate.exists() or candidate.is_symlink(): candidate.unlink(); fsync_dir(candidate.parent)
        syntax_tests(restoring=True)
        if journal.get("reload_started"):
            run_checked(LAYOUT["fpm_reload"],"rollback_fpm_reload_failed")
            run_checked(LAYOUT["nginx_reload"],"rollback_nginx_reload_failed")
            wait_socket(); https_readback(token)
    except StateUncertain: raise
    except BaseException: recovery.append("config_restore")
    try:
        nodes_dir=(archive/"archived-nodes") if archive is not None else partial/"archived-nodes"
        for item in reversed(EXPECTED_NODES):
            source=pathlib.Path(item["path"]); destination=nodes_dir/item["archive_name"]
            restore_node(item,source,destination); restored_nodes+=1
    except StateUncertain: raise
    except BaseException: recovery.append("node_restore")
    if recovery:
        try: journal=advance_journal(journal_path,journal,"recovery_required",last_failure_code=primary_code)
        except StateUncertain: raise
        except BaseException: recovery.append("journal")
        return 97,recovery_receipt(token,journal_path,archive,primary_code,recovery)
    journal=advance_journal(journal_path,journal,"restored",last_failure_code=primary_code)
    return 2,{"CONFIG_PREREQUISITES_APPLY":"restored","schema":"production-config-prerequisites-v1",
      "transaction_token":token,"phase":"restored","journal_path_sha256":sha_bytes(str(journal_path).encode("utf-8")),
      "archive_path_sha256":sha_bytes(str(partial).encode("utf-8")),"nodes_restored":restored_nodes,
      "configs_restored":restored_configs,"failure_code":primary_code,"reconcile_required":False}

def apply(token):
    root,parent=validate_root_boundary()
    timestamp=datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    archive_name="production-config-prerequisites-"+timestamp+"-"+token
    partial=parent/(archive_name+".partial"); final=parent/archive_name; journal_path=journal_file(parent,token)
    journal=None; archive_for_receipt=None
    with maintenance_lock(parent,True):
        try:
            first=[verify_node(item) for item in EXPECTED_NODES]
            originals=current_originals(); candidates,environment=transform_configs(originals)
            for item in EXPECTED_NODES:
                if pathlib.Path(item["path"]).stat().st_dev!=parent.stat().st_dev: raise RuntimeError("archive_cross_device_boundary")
            journal_path,journal=create_journal(parent,token,partial,final,archive_name)
            if LAYOUT["fault"]=="after_journal": raise RuntimeError("injected_after_journal_failure")
            make_directory(partial,0o700); archive_for_receipt=partial
            nodes_dir=partial/"archived-nodes"; backups_dir=partial/"config-backups"; holds_dir=partial/"config-original-inodes"
            make_directory(nodes_dir,0o700); make_directory(backups_dir,0o700); make_directory(holds_dir,0o700)
            for expected in EXPECTED_CONFIGS:
                payload=originals[expected["label"]]; backup=backups_dir/(expected["label"]+".original")
                write_exclusive(backup,payload,0o600,0,0)
                backup_meta=os.lstat(backup); backup_size,backup_sha=digest_file(backup)
                if backup_size!=len(payload) or backup_sha!=expected["sha256"]:
                    raise RuntimeError("config_backup_readback_failed")
                if ((not LAYOUT["test_mode"] or LAYOUT.get("real_linux_primitives",False)) and stat.S_IMODE(backup_meta.st_mode)!=0o600) or (not LAYOUT["test_mode"] and (backup_meta.st_uid!=0 or backup_meta.st_gid!=0 or backup_meta.st_nlink!=1)):
                    raise RuntimeError("config_backup_metadata_failed")
            fsync_dir(backups_dir)
            manifest=manifest_base(token,partial,config_hash_rows(candidates)); manifest["environment"]=environment
            write_manifest(partial/"manifest.json",manifest,"prepared")
            journal=advance_journal(journal_path,journal,"prepared")
            second=[verify_node(item) for item in EXPECTED_NODES]
            if first!=second: raise RuntimeError("node_double_sample_changed")
            current_originals()
            for index,item in enumerate(EXPECTED_NODES):
                source=pathlib.Path(item["path"]); destination=nodes_dir/item["archive_name"]
                immediate=verify_node(item)
                if immediate!=second[index]: raise RuntimeError("node_pre_move_changed")
                move_exact(source,destination)
                if source.exists() or source.is_symlink(): raise RuntimeError("archive_source_not_absent")
                verify_node(item,destination,require_idle=True)
                if index==0 and LAYOUT["fault"]=="after_first_move": raise RuntimeError("injected_move_failure")
            validate_archived_nodes(partial)
            journal=advance_journal(journal_path,journal,"nodes_archived")
            stages=[]
            for expected in EXPECTED_CONFIGS:
                candidate,hold=prepare_candidate(expected,candidates[expected["label"]],token,holds_dir)
                stages.append((expected,candidate,hold))
            for expected,candidate,hold in stages: activate_config(expected,candidate,hold)
            journal=advance_journal(journal_path,journal,"configs_activated")
            syntax_tests()
            journal=advance_journal(journal_path,journal,"configs_activated",reload_started=True)
            reload_and_readback(candidates,token)
            validate_archived_nodes(partial); verify_success_config_state(partial,candidates)
            journal=advance_journal(journal_path,journal,"validated")
            os.replace(partial,final); archive_for_receipt=final
            if LAYOUT["fault"]=="archive_after_replace": raise StateUncertain("archive_replace_fsync_uncertain")
            try: fsync_dir(parent)
            except BaseException as error: raise StateUncertain("archive_replace_fsync_uncertain") from error
            journal=advance_journal(journal_path,journal,"archive_finalized")
            validate_archived_nodes(final); verify_success_config_state(final,candidates)
            syntax_tests(); wait_socket(); https_readback(token)
            manifest["archive"]=str(final)
            write_manifest(final/"manifest.json",manifest,"committed")
            journal=advance_journal(journal_path,journal,"manifest_committed")
            validate_archived_nodes(final); holds=verify_success_config_state(final,candidates)
            journal=advance_journal(journal_path,journal,"committed")
            return 0,{"CONFIG_PREREQUISITES_APPLY":"pass","schema":"production-config-prerequisites-v1",
              "transaction_token":token,"phase":"committed","journal_path_sha256":sha_bytes(str(journal_path).encode("utf-8")),
              "archive_path_sha256":sha_bytes(str(final).encode("utf-8")),"nodes_archived":len(EXPECTED_NODES),
              "configs_applied":len(EXPECTED_CONFIGS),"holds_retained":holds,"environment":environment,
              "syntax_tests":3,"reloads":2,"https_health":"pass","upload_script_rejected":True}
        except StateUncertain as error:
            return 97,recovery_receipt(token,journal_path,archive_for_receipt,failure_code(error),["state_uncertain"])
        except BaseException as primary:
            code=failure_code(primary)
            if journal is None:
                if journal_path.exists() or journal_path.is_symlink():
                    return 97,recovery_receipt(token,journal_path,archive_for_receipt,code,["journal_initialization"])
                raise
            try: return rollback_transaction(parent,token,journal_path,journal,code)
            except StateUncertain as error:
                return 97,recovery_receipt(token,journal_path,archive_for_receipt,failure_code(error),["rollback_state_uncertain"])
            except BaseException as error:
                return 97,recovery_receipt(token,journal_path,archive_for_receipt,failure_code(error),["rollback_exception"])

def status(token):
    root,parent=validate_root_boundary(); path=journal_file(parent,token)
    with maintenance_lock(parent,False):
        journal=validate_journal(path,token); partial,final=archive_paths_from_journal(parent,journal)
        location,archive=archive_location(partial,final)
        source_nodes,archived_nodes,original_configs,candidate_configs,holds=status_counts(archive)
        committed_ok=(journal["phase"]=="committed" and location=="final" and source_nodes==0 and archived_nodes==len(EXPECTED_NODES)
          and original_configs==0 and candidate_configs==len(EXPECTED_CONFIGS) and holds==len(EXPECTED_CONFIGS))
        restored_ok=(journal["phase"]=="restored" and source_nodes==len(EXPECTED_NODES) and archived_nodes==0
          and original_configs==len(EXPECTED_CONFIGS) and candidate_configs==0 and holds==0)
        reconcile_required=not (committed_ok or restored_ok)
        state="recovery_required" if reconcile_required else "pass"
        return (97 if reconcile_required else 0),{"CONFIG_PREREQUISITES_STATUS":state,
          "schema":"production-config-prerequisites-status-v1","transaction_token":token,"phase":journal["phase"],
          "revision":journal["revision"],"journal_path_sha256":sha_bytes(str(path).encode("utf-8")),
          "archive_path_sha256":sha_bytes(str(archive).encode("utf-8")) if archive is not None else "0"*64,
          "archive_location":location,"source_nodes":source_nodes,"archived_nodes":archived_nodes,
          "original_configs":original_configs,"candidate_configs":candidate_configs,"holds":holds,
          "reconcile_required":reconcile_required,"write_actions":0}

def prepared_manifest_contract(path,token,allowed_archives,allowed_states,environment=None):
    value=read_json_object(path)
    required={"schema","token","archive","historical_evidence","nodes","configs","environment","state"}
    expected_nodes=[{"source":item["path"],"path_sha256":item["path_sha256"],
      "canonical_manifest_v1_sha256":item["canonical_manifest_v1_sha256"],"archive_name":item["archive_name"]} for item in EXPECTED_NODES]
    expected_configs=[{"label":item["label"],"path":item["path"],"original_sha256":item["sha256"],
      "candidate_sha256":item["candidate_sha256"]} for item in EXPECTED_CONFIGS]
    expected_history={"public_legacy_fingerprint":HISTORICAL_PUBLIC_FINGERPRINT,
      "storage_legacy_prefixes":HISTORICAL_STORAGE_PREFIXES,"algorithm_comparable":False}
    if (set(value)!=required or value.get("schema")!="production-config-prerequisites-v1" or value.get("token")!=token
      or value.get("archive") not in allowed_archives or value.get("state") not in allowed_states
      or value.get("historical_evidence")!=expected_history or value.get("nodes")!=expected_nodes or value.get("configs")!=expected_configs):
        raise RuntimeError("manifest_contract")
    if not isinstance(value.get("environment"),dict) or set(value["environment"])!={"db_from_dotenv","ai_from_pool","mail_state"}:
        raise RuntimeError("manifest_environment_contract")
    if value["environment"].get("db_from_dotenv")!=5 or value["environment"].get("ai_from_pool")!=19 or value["environment"].get("mail_state") not in {"default-disabled","explicit-disabled","master-key-present"}:
        raise RuntimeError("manifest_environment_contract")
    if environment is not None and value["environment"]!=environment: raise RuntimeError("manifest_environment_changed")
    return value

def reconcile(token):
    root,parent=validate_root_boundary(); path=journal_file(parent,token)
    with maintenance_lock(parent,True):
        journal=validate_journal(path,token); partial,final=archive_paths_from_journal(parent,journal)
        location,archive=archive_location(partial,final)
        if location=="conflict":
            return 97,recovery_receipt(token,path,None,"archive_conflict",["archive_conflict"])
        if journal["phase"]=="committed":
            validate_archived_nodes(final)
            originals={expected["label"]:(final/"config-backups"/(expected["label"]+".original")).read_bytes() for expected in EXPECTED_CONFIGS}
            candidates,environment=transform_configs(originals); holds=verify_success_config_state(final,candidates)
            manifest=prepared_manifest_contract(final/"manifest.json",token,{str(final)},{"committed"},environment)
            return 0,{"CONFIG_PREREQUISITES_RECONCILE":"committed","schema":"production-config-prerequisites-v1",
              "transaction_token":token,"phase":"committed","journal_path_sha256":sha_bytes(str(path).encode("utf-8")),
              "archive_path_sha256":sha_bytes(str(final).encode("utf-8")),"nodes_archived":len(EXPECTED_NODES),
              "configs_applied":len(EXPECTED_CONFIGS),"holds_retained":holds,"reconcile_required":False}
        if journal["phase"]=="restored":
            for item in EXPECTED_NODES: verify_node(item)
            for expected in EXPECTED_CONFIGS: config_sample(expected)
            source_nodes,archived_nodes,original_configs,candidate_configs,holds=status_counts(archive)
            if source_nodes!=len(EXPECTED_NODES) or archived_nodes!=0 or original_configs!=len(EXPECTED_CONFIGS) or candidate_configs!=0 or holds!=0:
                return 97,recovery_receipt(token,path,archive,"restored_state_mismatch",["restored_state"])
            return 0,{"CONFIG_PREREQUISITES_RECONCILE":"restored","schema":"production-config-prerequisites-v1",
              "transaction_token":token,"phase":"restored","journal_path_sha256":sha_bytes(str(path).encode("utf-8")),
              "archive_path_sha256":sha_bytes(str(partial).encode("utf-8")),"nodes_restored":len(EXPECTED_NODES),
              "configs_restored":len(EXPECTED_CONFIGS),"reconcile_required":False}
        can_finalize=journal["phase_rank"]<PHASE_RANK["rollback_started"] and archive is not None
        candidates=None; environment=None
        if can_finalize:
            try:
                validate_archived_nodes(archive)
                originals={expected["label"]:(archive/"config-backups"/(expected["label"]+".original")).read_bytes() for expected in EXPECTED_CONFIGS}
                candidates,environment=transform_configs(originals)
                prepared_manifest_contract(archive/"manifest.json",token,{str(partial),str(final)},{"prepared","committed"},environment)
                verify_success_config_state(archive,candidates)
            except BaseException: can_finalize=False
        if can_finalize:
            try:
                journal=advance_journal(path,journal,journal["phase"],reload_started=True)
                reload_and_readback(candidates,token)
                validate_archived_nodes(archive); verify_success_config_state(archive,candidates)
                if location=="partial":
                    os.replace(partial,final); archive=final
                    if LAYOUT["fault"]=="archive_after_replace": raise StateUncertain("archive_replace_fsync_uncertain")
                    try: fsync_dir(parent)
                    except BaseException as error: raise StateUncertain("archive_replace_fsync_uncertain") from error
                journal=advance_journal(path,journal,"archive_finalized")
                validate_archived_nodes(final); holds=verify_success_config_state(final,candidates)
                syntax_tests(); wait_socket(); https_readback(token)
                manifest=prepared_manifest_contract(final/"manifest.json",token,{str(partial),str(final)},{"prepared","committed"},environment)
                manifest["archive"]=str(final); manifest["environment"]=environment
                write_manifest(final/"manifest.json",manifest,"committed")
                journal=advance_journal(path,journal,"manifest_committed")
                validate_archived_nodes(final); holds=verify_success_config_state(final,candidates)
                journal=advance_journal(path,journal,"committed")
                return 0,{"CONFIG_PREREQUISITES_RECONCILE":"committed","schema":"production-config-prerequisites-v1",
                  "transaction_token":token,"phase":"committed","journal_path_sha256":sha_bytes(str(path).encode("utf-8")),
                  "archive_path_sha256":sha_bytes(str(final).encode("utf-8")),"nodes_archived":len(EXPECTED_NODES),
                  "configs_applied":len(EXPECTED_CONFIGS),"holds_retained":holds,"reconcile_required":False}
            except StateUncertain as error:
                return 97,recovery_receipt(token,path,archive,failure_code(error),["reconcile_state_uncertain"])
            except BaseException as error:
                primary=failure_code(error)
        else: primary="reconcile_finalize_preconditions"
        try:
            code,receipt=rollback_transaction(parent,token,path,journal,primary)
        except StateUncertain as error:
            return 97,recovery_receipt(token,path,archive,failure_code(error),["reconcile_rollback_uncertain"])
        except BaseException as error:
            return 97,recovery_receipt(token,path,archive,failure_code(error),["reconcile_rollback_exception"])
        if code==2:
            return 0,{"CONFIG_PREREQUISITES_RECONCILE":"restored","schema":"production-config-prerequisites-v1",
              "transaction_token":token,"phase":"restored","journal_path_sha256":receipt["journal_path_sha256"],
              "archive_path_sha256":receipt["archive_path_sha256"],"nodes_restored":receipt["nodes_restored"],
              "configs_restored":receipt["configs_restored"],"reconcile_required":False}
        receipt["CONFIG_PREREQUISITES_RECONCILE"]="recovery_required"; del receipt["CONFIG_PREREQUISITES_APPLY"]
        return code,receipt

def main():
    if len(sys.argv)!=3 or sys.argv[1] not in {"audit","apply","status","reconcile"} or re.fullmatch(r"[0-9a-f]{32}",sys.argv[2]) is None:
        raise RuntimeError("argument_boundary")
    action,token=sys.argv[1:]
    if action=="audit": receipt=audit(); code=0
    elif action=="apply": code,receipt=apply(token)
    elif action=="status": code,receipt=status(token)
    else: code,receipt=reconcile(token)
    print(json.dumps(receipt,sort_keys=True,separators=(",",":")),flush=True)
    raise SystemExit(code)

try: main()
except SystemExit: raise
except BaseException as error:
    action=sys.argv[1] if len(sys.argv)>1 else "unknown"
    key="CONFIG_PREREQUISITES" if action=="audit" else "CONFIG_PREREQUISITES_"+action.upper()
    state="audit_failed" if action=="audit" else "recovery_required"
    print(json.dumps({key:state,"schema":"production-config-prerequisites-v1","reason_code":failure_code(error)},sort_keys=True,separators=(",",":")),flush=True)
    raise SystemExit(1 if action=="audit" else 97)
'''


def snippet_path() -> Path:
    return Path(__file__).resolve().parents[1] / "deploy" / "nginx-uploads-static-only.conf.example"


def load_nginx_snippet() -> str:
    path = snippet_path()
    metadata = os.lstat(path)
    if path.is_symlink() or not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
        raise RuntimeError("Nginx upload snippet must be one regular non-link file")
    raw = path.read_bytes()
    if hashlib.sha256(raw).hexdigest() != NGINX_SNIPPET_SHA256:
        raise RuntimeError("Nginx upload snippet no longer matches the reviewed hash")
    return raw.decode("utf-8", errors="strict")


def build_remote_source(
    layout: dict[str, Any],
    nodes: list[dict[str, Any]],
    configs: list[dict[str, Any]],
    snippet: str,
) -> str:
    replacements = {
        "__LAYOUT__": "json.loads(" + repr(json.dumps(layout, sort_keys=True, separators=(",", ":"))) + ")",
        "__EXPECTED_NODES__": "json.loads(" + repr(json.dumps(nodes, sort_keys=True, separators=(",", ":"))) + ")",
        "__EXPECTED_CONFIGS__": "json.loads(" + repr(json.dumps(configs, sort_keys=True, separators=(",", ":"))) + ")",
        "__NGINX_SNIPPET__": repr(snippet),
        "__NGINX_SNIPPET_SHA256__": repr(NGINX_SNIPPET_SHA256),
    }
    source = REMOTE_BODY
    for marker, value in replacements.items():
        if source.count(marker) != 1:
            raise RuntimeError(f"remote source marker {marker} is not unique")
        source = source.replace(marker, value)
    return source


def production_remote_source() -> str:
    if PRODUCTION_LAYOUT["test_mode"] or PRODUCTION_LAYOUT["fault"]:
        raise RuntimeError("production layout cannot enable test hooks")
    return build_remote_source(
        dict(PRODUCTION_LAYOUT),
        [asdict(item) for item in EXPECTED_NODES],
        [asdict(item) for item in EXPECTED_CONFIGS],
        load_nginx_snippet(),
    )


def remote_command(action: str, token: str, source: str | None = None) -> str:
    if action not in {"audit", "apply", "status", "reconcile"} or re.fullmatch(r"[0-9a-f]{32}", token) is None:
        raise RuntimeError("remote action/token boundary")
    payload = base64.b64encode((source or production_remote_source()).encode("utf-8")).decode("ascii")
    bootstrap = "import base64;exec(compile(base64.b64decode(" + repr(payload) + "),'<config-prerequisites>','exec'))"
    return (
        "env -i PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C LANG=C "
        + shlex.quote(REMOTE_PYTHON)
        + " -I -S -B -c "
        + shlex.quote(bootstrap)
        + " "
        + shlex.quote(action)
        + " "
        + shlex.quote(token)
    )


def sanitize_for_log(value: object, sensitive: tuple[str, ...] = ()) -> str:
    text = str(value).replace("\x00", "?")
    for secret in sensitive:
        if secret:
            text = text.replace(secret, "[redacted]")
    return text[:4096]


def validate_known_hosts(path: Path) -> Path:
    resolved = path.expanduser().resolve(strict=True)
    metadata = os.lstat(path.expanduser())
    if path.expanduser().is_symlink() or not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
        raise RuntimeError("known_hosts must be one regular non-link file")
    reparse = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0)
    if reparse and getattr(metadata, "st_file_attributes", 0) & reparse:
        raise RuntimeError("known_hosts must not be a Windows reparse point")
    return resolved


def connect(args: argparse.Namespace, password: str):
    try:
        import paramiko
    except ImportError as exc:
        raise RuntimeError("paramiko is required; install backend/tools/requirements-release.txt") from exc
    known_hosts = validate_known_hosts(Path(args.known_hosts))
    client = paramiko.SSHClient()
    client.load_host_keys(str(known_hosts))
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(
        args.host, port=args.port, username=args.user, password=password,
        timeout=20, banner_timeout=20, auth_timeout=20,
        look_for_keys=False, allow_agent=False,
        disabled_algorithms={"kex": ["curve25519-sha256", "curve25519-sha256@libssh.org"]},
    )
    transport = client.get_transport()
    if transport is None or not transport.is_active():
        client.close()
        raise RuntimeError("SSH transport is inactive")
    transport.set_keepalive(15)
    return client


def collect_channel(channel: Any, timeout: float, password: str) -> tuple[int, str, str]:
    deadline = time.monotonic() + timeout
    stdout = bytearray()
    stderr = bytearray()
    while not channel.exit_status_ready():
        while channel.recv_ready():
            stdout.extend(channel.recv(8192))
        while channel.recv_stderr_ready():
            stderr.extend(channel.recv_stderr(8192))
        if len(stdout) + len(stderr) > MAX_REMOTE_OUTPUT:
            channel.close()
            raise RuntimeError("remote output exceeded the reviewed bound")
        if time.monotonic() >= deadline:
            channel.close()
            raise TimeoutError("remote command exceeded its reviewed timeout")
        time.sleep(0.02)
    while channel.recv_ready():
        stdout.extend(channel.recv(8192))
    while channel.recv_stderr_ready():
        stderr.extend(channel.recv_stderr(8192))
    if len(stdout) + len(stderr) > MAX_REMOTE_OUTPUT:
        raise RuntimeError("remote output exceeded the reviewed bound")
    return (
        channel.recv_exit_status(),
        sanitize_for_log(stdout.decode("utf-8", "replace"), (password,)),
        sanitize_for_log(stderr.decode("utf-8", "replace"), (password,)),
    )


def run_remote(client: Any, command: str, password: str, action: str) -> tuple[int, str]:
    mutating = action is True or action in {"apply", "reconcile"}
    try:
        _stdin, stdout, _stderr = client.exec_command(command, get_pty=False, timeout=REMOTE_TIMEOUT_SECONDS)
        status, output, error = collect_channel(stdout.channel, REMOTE_TIMEOUT_SECONDS, password)
    except BaseException as exc:
        if mutating:
            raise RuntimeError("RECOVERY_REQUIRED: remote transaction result is uncertain") from exc
        raise
    if error.strip():
        if mutating:
            raise RuntimeError("RECOVERY_REQUIRED: remote transaction returned unexpected stderr")
        raise RuntimeError("remote read-only operation returned unexpected stderr: " + error.strip())
    normalized_action = "apply" if action is True else "audit" if action is False else action
    allowed = {"audit": {0}, "apply": {0, 2, 97}, "status": {0, 97}, "reconcile": {0, 97}}[normalized_action]
    if status not in allowed:
        if mutating:
            raise RuntimeError("RECOVERY_REQUIRED: remote transaction returned an unreviewed status")
        raise RuntimeError(f"remote read-only operation failed ({status}): {output.strip()}")
    return status, output


def strict_json_line(output: str) -> dict[str, Any]:
    lines = output.splitlines()
    if len(lines) != 1 or not lines[0] or lines[0] != lines[0].strip():
        raise RuntimeError("remote result must be exactly one JSON line")

    def unique(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("duplicate key")
            result[key] = value
        return result

    try:
        value = json.loads(lines[0], object_pairs_hook=unique)
    except (json.JSONDecodeError, ValueError) as exc:
        raise RuntimeError("remote result is not strict JSON") from exc
    if not isinstance(value, dict):
        raise RuntimeError("remote result must be a JSON object")
    return value


def exact_keys(receipt: dict[str, Any], expected: set[str], label: str) -> None:
    if not isinstance(receipt, dict) or set(receipt) != expected:
        raise RuntimeError(f"{label} receipt fields are not exact")


def hex_digest(value: Any, length: int = 64) -> bool:
    return isinstance(value, str) and re.fullmatch(rf"[0-9a-f]{{{length}}}", value) is not None


def validate_audit_receipt(receipt: dict[str, Any]) -> None:
    exact_keys(receipt, {
        "CONFIG_PREREQUISITES_AUDIT", "schema", "nodes", "fd_refs", "source_refs", "public",
        "environment", "candidate_sha256", "historical_algorithm_comparable", "write_actions",
    }, "audit")
    expected_candidates = {item.label: item.candidate_sha256 for item in EXPECTED_CONFIGS}
    if not isinstance(receipt["environment"], dict) or not isinstance(receipt["public"], dict):
        raise RuntimeError("audit receipt nested fields are not objects")
    if (
        receipt["CONFIG_PREREQUISITES_AUDIT"] != "pass"
        or receipt["schema"] != "production-config-prerequisites-v1"
        or receipt["nodes"] != len(EXPECTED_NODES)
        or receipt["fd_refs"] != 0
        or receipt["source_refs"] != 0
        or receipt["write_actions"] != 0
        or receipt["historical_algorithm_comparable"] is not False
        or receipt["candidate_sha256"] != expected_candidates
        or receipt["environment"].get("db_from_dotenv") != 5
        or receipt["environment"].get("ai_from_pool") != 19
        or receipt["environment"].get("mail_state") not in {"default-disabled", "explicit-disabled", "master-key-present"}
    ):
        raise RuntimeError("remote audit receipt did not prove the frozen read-only contract")
    exact_keys(receipt["environment"], {"db_from_dotenv", "ai_from_pool", "mail_state"}, "audit environment")
    exact_keys(receipt["public"], {"nodes", "files", "payload_size"}, "audit public")
    if receipt["public"] != {"nodes": 32, "files": 29, "payload_size": 344903}:
        raise RuntimeError("remote audit public evidence changed")


def validate_recovery_fields(receipt: dict[str, Any], key: str) -> None:
    exact_keys(receipt, {
        key, "schema", "transaction_token", "phase", "journal_path_sha256", "archive_path_sha256",
        "failure_code", "components", "reconcile_required",
    }, "recovery")
    if (
        receipt[key] != "recovery_required"
        or receipt["schema"] != "production-config-prerequisites-v1"
        or receipt["phase"] != "recovery_required"
        or receipt["reconcile_required"] is not True
        or not hex_digest(receipt["transaction_token"], 32)
        or not hex_digest(receipt["journal_path_sha256"])
        or not hex_digest(receipt["archive_path_sha256"])
        or not isinstance(receipt["failure_code"], str)
        or not receipt["failure_code"]
        or not isinstance(receipt["components"], list)
        or not receipt["components"]
        or any(not isinstance(item, str) or not item for item in receipt["components"])
    ):
        raise RuntimeError("recovery receipt contract failed")


def validate_apply_receipt(status: int, receipt: dict[str, Any], token: str) -> str:
    state = receipt.get("CONFIG_PREREQUISITES_APPLY")
    if status == 0 and state == "pass":
        exact_keys(receipt, {
            "CONFIG_PREREQUISITES_APPLY", "schema", "transaction_token", "phase", "journal_path_sha256",
            "archive_path_sha256", "nodes_archived", "configs_applied", "holds_retained", "environment",
            "syntax_tests", "reloads", "https_health", "upload_script_rejected",
        }, "apply pass")
        if (
            receipt["schema"] != "production-config-prerequisites-v1" or receipt["transaction_token"] != token
            or receipt["phase"] != "committed" or receipt["nodes_archived"] != len(EXPECTED_NODES)
            or receipt["configs_applied"] != len(EXPECTED_CONFIGS) or receipt["holds_retained"] != len(EXPECTED_CONFIGS)
            or receipt["syntax_tests"] != 3 or receipt["reloads"] != 2 or receipt["https_health"] != "pass"
            or receipt["upload_script_rejected"] is not True or not hex_digest(receipt["journal_path_sha256"])
            or not hex_digest(receipt["archive_path_sha256"])
        ):
            raise RuntimeError("apply pass receipt contract failed")
        exact_keys(receipt["environment"], {"db_from_dotenv", "ai_from_pool", "mail_state"}, "apply environment")
        if (
            receipt["environment"]["db_from_dotenv"] != 5
            or receipt["environment"]["ai_from_pool"] != 19
            or receipt["environment"]["mail_state"] not in {"default-disabled", "explicit-disabled", "master-key-present"}
        ):
            raise RuntimeError("apply environment receipt contract failed")
        return "pass"
    if status == 2 and state == "restored":
        exact_keys(receipt, {
            "CONFIG_PREREQUISITES_APPLY", "schema", "transaction_token", "phase", "journal_path_sha256",
            "archive_path_sha256", "nodes_restored", "configs_restored", "failure_code", "reconcile_required",
        }, "apply restored")
        if (
            receipt["schema"] != "production-config-prerequisites-v1" or receipt["transaction_token"] != token
            or receipt["phase"] != "restored" or receipt["reconcile_required"] is not False
            or receipt["nodes_restored"] != len(EXPECTED_NODES) or receipt["configs_restored"] != len(EXPECTED_CONFIGS)
            or not hex_digest(receipt["journal_path_sha256"]) or not hex_digest(receipt["archive_path_sha256"])
            or not isinstance(receipt["failure_code"], str) or not receipt["failure_code"]
        ):
            raise RuntimeError("apply restored receipt contract failed")
        return "restored"
    if status == 97 and state == "recovery_required":
        validate_recovery_fields(receipt, "CONFIG_PREREQUISITES_APPLY")
        if receipt["transaction_token"] != token:
            raise RuntimeError("apply recovery token mismatch")
        return "recovery_required"
    raise RuntimeError("apply status and receipt disagree")


def validate_status_receipt(status: int, receipt: dict[str, Any], token: str) -> str:
    exact_keys(receipt, {
        "CONFIG_PREREQUISITES_STATUS", "schema", "transaction_token", "phase", "revision",
        "journal_path_sha256", "archive_path_sha256", "archive_location", "source_nodes", "archived_nodes",
        "original_configs", "candidate_configs", "holds", "reconcile_required", "write_actions",
    }, "status")
    state = receipt["CONFIG_PREREQUISITES_STATUS"]
    if (
        receipt["schema"] != "production-config-prerequisites-status-v1" or receipt["transaction_token"] != token
        or state not in {"pass", "recovery_required"} or receipt["archive_location"] not in {"absent", "partial", "final", "conflict"}
        or not isinstance(receipt["revision"], int) or receipt["revision"] < 1 or receipt["write_actions"] != 0
        or not hex_digest(receipt["journal_path_sha256"]) or not hex_digest(receipt["archive_path_sha256"])
        or any(not isinstance(receipt[key], int) or isinstance(receipt[key], bool) or receipt[key] < 0 for key in (
            "source_nodes", "archived_nodes", "original_configs", "candidate_configs", "holds"
        ))
        or (status == 0) != (state == "pass") or receipt["reconcile_required"] != (state == "recovery_required")
    ):
        raise RuntimeError("status receipt contract failed")
    return state


def validate_reconcile_receipt(status: int, receipt: dict[str, Any], token: str) -> str:
    state = receipt.get("CONFIG_PREREQUISITES_RECONCILE")
    if status == 97 and state == "recovery_required":
        validate_recovery_fields(receipt, "CONFIG_PREREQUISITES_RECONCILE")
        if receipt["transaction_token"] != token:
            raise RuntimeError("reconcile recovery token mismatch")
        return state
    if status != 0 or state not in {"committed", "restored"}:
        raise RuntimeError("reconcile status and receipt disagree")
    common = {"CONFIG_PREREQUISITES_RECONCILE", "schema", "transaction_token", "phase", "journal_path_sha256", "archive_path_sha256", "reconcile_required"}
    extra = {"nodes_archived", "configs_applied", "holds_retained"} if state == "committed" else {"nodes_restored", "configs_restored"}
    exact_keys(receipt, common | extra, "reconcile")
    if (
        receipt["schema"] != "production-config-prerequisites-v1" or receipt["transaction_token"] != token
        or receipt["phase"] != state or receipt["reconcile_required"] is not False
        or not hex_digest(receipt["journal_path_sha256"]) or not hex_digest(receipt["archive_path_sha256"])
    ):
        raise RuntimeError("reconcile receipt contract failed")
    if state == "committed" and (receipt["nodes_archived"], receipt["configs_applied"], receipt["holds_retained"]) != (len(EXPECTED_NODES), len(EXPECTED_CONFIGS), len(EXPECTED_CONFIGS)):
        raise RuntimeError("reconcile committed counts failed")
    if state == "restored" and (receipt["nodes_restored"], receipt["configs_restored"]) != (len(EXPECTED_NODES), len(EXPECTED_CONFIGS)):
        raise RuntimeError("reconcile restored counts failed")
    return state


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--host", required=True)
    result.add_argument("--port", type=int, default=EXPECTED_PORT)
    result.add_argument("--user", default=EXPECTED_USER)
    result.add_argument("--known-hosts", required=True)
    modes = result.add_mutually_exclusive_group()
    modes.add_argument("--execute", action="store_true")
    modes.add_argument("--status", action="store_true")
    modes.add_argument("--reconcile", action="store_true")
    result.add_argument("--transaction-token", default="")
    result.add_argument("--confirm", default="")
    result.add_argument("--maintenance-confirmed", default="")
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    if (args.host, args.port, args.user) != (EXPECTED_HOST, EXPECTED_PORT, EXPECTED_USER):
        raise RuntimeError("production endpoint is pinned")
    if args.execute:
        if args.confirm != EXECUTE_CONFIRMATION or args.maintenance_confirmed != MAINTENANCE_CONFIRMATION:
            raise RuntimeError("execute requires both exact confirmation tokens")
        if args.transaction_token:
            raise RuntimeError("execute generates its own transaction token")
        action = "apply"
        token = secrets.token_hex(16)
    elif args.reconcile:
        if args.confirm != RECONCILE_CONFIRMATION or args.maintenance_confirmed != MAINTENANCE_CONFIRMATION:
            raise RuntimeError("reconcile requires both exact confirmation tokens")
        if re.fullmatch(r"[0-9a-f]{32}", args.transaction_token) is None:
            raise RuntimeError("reconcile requires the exact transaction token")
        action = "reconcile"
        token = args.transaction_token
    elif args.status:
        if args.confirm or args.maintenance_confirmed:
            raise RuntimeError("status is read-only and rejects confirmation tokens")
        if re.fullmatch(r"[0-9a-f]{32}", args.transaction_token) is None:
            raise RuntimeError("status requires the exact transaction token")
        action = "status"
        token = args.transaction_token
    else:
        if args.confirm or args.maintenance_confirmed or args.transaction_token:
            raise RuntimeError("audit rejects transaction and confirmation tokens")
        action = "audit"
        token = secrets.token_hex(16)
    password = os.environ.get("YY_SSH_PASSWORD", "")
    if not password:
        raise RuntimeError("YY_SSH_PASSWORD is required and is never accepted on the command line")
    if action in {"apply", "reconcile"}:
        print("CONFIG_PREREQUISITES_TRANSACTION_TOKEN=" + token, flush=True)
    client = connect(args, password)
    mutating = action in {"apply", "reconcile"}
    try:
        status, output = run_remote(client, remote_command(action, token), password, action)
        try:
            receipt = strict_json_line(output)
            if action == "audit":
                validate_audit_receipt(receipt)
            elif action == "apply":
                state = validate_apply_receipt(status, receipt, token)
            elif action == "status":
                state = validate_status_receipt(status, receipt, token)
            else:
                state = validate_reconcile_receipt(status, receipt, token)
        except BaseException as exc:
            if mutating:
                raise RuntimeError("RECOVERY_REQUIRED: remote transaction receipt is missing or invalid") from exc
            raise
        if action == "audit":
            print("CONFIG_PREREQUISITES_AUDIT=" + json.dumps(receipt, sort_keys=True, separators=(",", ":")))
            return 0
        label = "CONFIG_PREREQUISITES_" + action.upper() + "_RECEIPT="
        print(label + json.dumps(receipt, sort_keys=True, separators=(",", ":")))
        if action == "status":
            return status
        if status == 0 and state in {"pass", "committed", "restored"}:
            return 0
        if status == 2 and state == "restored":
            raise RuntimeError("production prerequisite transaction failed but the frozen state was restored")
        raise RuntimeError("RECOVERY_REQUIRED: production prerequisite transaction needs manual recovery")
    finally:
        try:
            client.close()
        except BaseException as exc:
            if mutating:
                raise RuntimeError("RECOVERY_REQUIRED: SSH close result is uncertain") from exc
            raise


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        password = os.environ.get("YY_SSH_PASSWORD", "")
        print("production config prerequisite operation failed: " + sanitize_for_log(exc, (password,)), file=sys.stderr)
        raise SystemExit(1)
