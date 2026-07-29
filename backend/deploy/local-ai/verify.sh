#!/usr/bin/env bash
set -euo pipefail

MODEL="${AI_MODEL:-qwen2.5:3b}"
ENDPOINT="${AI_ENDPOINT:-http://127.0.0.1:11434}"
PAYLOAD="$(printf '{\"model\":\"%s\",\"stream\":false,\"messages\":[{\"role\":\"user\",\"content\":\"只回答：易运盈本地AI正常\"}],\"options\":{\"temperature\":0}}' "$MODEL")"

RESPONSE="$(curl --fail --silent --show-error --max-time 120 \
  -H 'Content-Type: application/json' \
  -d "$PAYLOAD" \
  "$ENDPOINT/api/chat")"

if [[ "$RESPONSE" != *'\"message\"'* ]]; then
  echo "本地 AI 自检失败：响应中缺少 message 字段。" >&2
  exit 1
fi

echo "本地 AI 问答自检通过。"
