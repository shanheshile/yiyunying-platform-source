#!/usr/bin/env bash
set -euo pipefail

# Run as root on the PHP server. Ollama is bound to loopback so it is not
# exposed to the Internet; PHP is the only public gateway.
MODEL="${AI_MODEL:-qwen2.5:3b}"
AVAILABLE_MB=0
if [[ -r /proc/meminfo ]]; then
  AVAILABLE_MB="$(awk '/MemAvailable:/ { print int($2 / 1024) }' /proc/meminfo)"
fi

# A 3B model can make PHP-FPM unstable on a small server. Pick the smaller
# model only when the operator kept the default and available memory is low.
if [[ "$MODEL" == "qwen2.5:3b" && "$AVAILABLE_MB" -gt 0 && "$AVAILABLE_MB" -lt 3200 ]]; then
  MODEL="qwen2.5:1.5b"
  echo "检测到可用内存约 ${AVAILABLE_MB} MB，自动改用 ${MODEL}。"
fi

if ! command -v ollama >/dev/null 2>&1; then
  curl -fsSL https://ollama.com/install.sh | sh
fi

install -d -m 0750 /etc/systemd/system/ollama.service.d
cat >/etc/systemd/system/ollama.service.d/yiyunying.conf <<'EOF'
[Service]
Environment="OLLAMA_HOST=127.0.0.1:11434"
Environment="OLLAMA_KEEP_ALIVE=5m"
Environment="OLLAMA_NUM_PARALLEL=1"
Environment="OLLAMA_MAX_LOADED_MODELS=1"
Environment="OLLAMA_MAX_QUEUE=32"
Restart=always
RestartSec=3
EOF

systemctl daemon-reload
systemctl enable --now ollama
ollama pull "$MODEL"
curl -fsS http://127.0.0.1:11434/api/tags >/dev/null
AI_MODEL="$MODEL" "$(dirname "$0")/verify-environment.sh"
echo "易运盈本地 AI 已就绪：${MODEL}，仅监听 127.0.0.1:11434。"
