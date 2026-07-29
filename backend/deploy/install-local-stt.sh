#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENV="$ROOT/storage/stt/venv"
RUNTIME="$ROOT/storage/stt/python-runtime"
PYTHON="${PYTHON:-}"
MINICONDA_URL="${MINICONDA_URL:-https://repo.anaconda.com/miniconda/Miniconda3-py311_24.5.0-0-Linux-x86_64.sh}"
PYPI_URL="${STT_PYPI_URL:-https://pypi.org/simple}"
PYAV_VERSION="${STT_PYAV_VERSION:-12.3.0}"

find_python() {
  if [[ -n "$PYTHON" ]] && command -v "$PYTHON" >/dev/null 2>&1; then
    command -v "$PYTHON"
    return
  fi
  for candidate in python3.12 python3.11 python3.10 python3; do
    if command -v "$candidate" >/dev/null 2>&1; then
      command -v "$candidate"
      return
    fi
  done
  if [[ -x "$RUNTIME/bin/python3" ]]; then
    printf '%s\n' "$RUNTIME/bin/python3"
    return
  fi
  return 1
}

install_python_runtime() {
  local installer="$ROOT/storage/stt/miniconda-installer.sh"
  local downloader=()
  if command -v curl >/dev/null 2>&1; then
    downloader=(curl -fL --retry 3 --connect-timeout 20 -o "$installer" "$MINICONDA_URL")
  elif command -v wget >/dev/null 2>&1; then
    downloader=(wget -O "$installer" "$MINICONDA_URL")
  else
    echo "服务器没有可用的 Python，也没有 curl 或 wget，无法安装本地语音转写运行时。" >&2
    exit 1
  fi

  echo "未找到 Python 3.10 及以上版本，正在安装易运盈独立 Python 运行时……"
  mkdir -p "$ROOT/storage/stt"
  "${downloader[@]}"
  rm -rf "$RUNTIME"
  bash "$installer" -b -p "$RUNTIME"
  rm -f "$installer"
}

mkdir -p "$ROOT/storage/stt/models" "$ROOT/storage/tmp"
PYTHON_BIN="$(find_python || true)"
if [[ -z "$PYTHON_BIN" ]]; then
  install_python_runtime
  PYTHON_BIN="$RUNTIME/bin/python3"
fi

if ! "$PYTHON_BIN" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 10) else 1)'; then
  echo "本地语音转写要求 Python 3.10 及以上版本，当前为 $($PYTHON_BIN --version 2>&1)。" >&2
  exit 1
fi

rm -rf "$VENV"
"$PYTHON_BIN" -m venv "$VENV"
"$VENV/bin/python3" -m pip install --index-url "$PYPI_URL" --upgrade pip wheel
# PyAV 18 has no CentOS 7/Python 3.11 wheel and falls back to an FFmpeg source build.
# Pin the last broadly compatible manylinux2014 wheel before installing faster-whisper.
"$VENV/bin/python3" -m pip install \
  --index-url "$PYPI_URL" \
  --only-binary=:all: \
  "av==$PYAV_VERSION"
# CentOS 7 resolves onnxruntime 1.16.x, which is built against NumPy 1.x.
"$VENV/bin/python3" -m pip install \
  --index-url "$PYPI_URL" \
  --only-binary=:all: \
  "numpy==1.26.4"
"$VENV/bin/python3" -m pip install \
  --index-url "$PYPI_URL" \
  --prefer-binary \
  "faster-whisper==1.2.1"
chmod +x "$ROOT/tools/stt/transcribe.py"
chown -R "${STT_USER:-www}:${STT_GROUP:-www}" "$ROOT/storage/stt" "$ROOT/storage/tmp" 2>/dev/null || true
chmod 755 "$ROOT" "$ROOT/tools" "$ROOT/tools/stt" "$ROOT/tools/stt/transcribe.py" 2>/dev/null || true
find "$VENV/bin" -maxdepth 1 -type f -exec chmod 755 {} + 2>/dev/null || true
find "$ROOT/storage/stt" "$ROOT/storage/tmp" -type d -exec chmod 755 {} + 2>/dev/null || true
chmod -R u+rwX,go+rX "$ROOT/storage/stt" 2>/dev/null || true

echo
echo "易运盈本地语音转文字插件安装完成。"
echo "Python：$VENV/bin/python3"
echo "脚本：  $ROOT/tools/stt/transcribe.py"
echo "模型：  ${STT_MODEL:-base}（首次转写时自动下载）"
echo "重载 PHP-FPM 后即可使用，不需要配置 STT_API_URL 或 STT_API_KEY。"
