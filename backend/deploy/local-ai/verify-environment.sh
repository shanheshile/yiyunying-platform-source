#!/usr/bin/env bash
set -euo pipefail

MODEL="${AI_MODEL:-qwen2.5:3b}"
ENDPOINT="${AI_ENDPOINT:-http://127.0.0.1:11434}"
PHP_BIN="${PHP_BIN:-php}"

fail() {
  echo "本地 AI 环境自检失败：$1" >&2
  exit 1
}

command -v curl >/dev/null 2>&1 || fail "缺少 curl"
if [[ "$PHP_BIN" == */* ]]; then
  [[ -x "$PHP_BIN" ]] || fail "PHP_BIN 指向的文件不存在或不可执行：$PHP_BIN"
else
  command -v "$PHP_BIN" >/dev/null 2>&1 || fail "找不到 PHP 命令，可通过 PHP_BIN 指定宝塔 PHP 路径"
fi

if command -v systemctl >/dev/null 2>&1; then
  systemctl is-active --quiet ollama || fail "Ollama 服务未运行"
fi

TAGS="$(curl --fail --silent --show-error --max-time 10 "$ENDPOINT/api/tags")"
[[ "$TAGS" == *"$MODEL"* ]] || fail "未发现模型 $MODEL，请先执行 ollama pull $MODEL"

for extension in curl pdo_mysql mbstring json fileinfo; do
  "$PHP_BIN" -r "exit(extension_loaded('$extension') ? 0 : 1);" \
    || fail "PHP 缺少扩展 $extension"
done

AI_MODEL="$MODEL" AI_ENDPOINT="$ENDPOINT" "$(dirname "$0")/verify.sh"
echo "本地 AI 环境自检通过：Ollama、${MODEL}、PHP 扩展和真实问答均正常。"
