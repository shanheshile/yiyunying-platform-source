#!/usr/bin/env python3
"""易运盈后台本地语音转文字插件。"""

from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path


def arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="使用 faster-whisper 在本机转写语音")
    parser.add_argument("--input", required=True, help="输入音频文件")
    parser.add_argument("--output", required=True, help="UTF-8 文本输出文件")
    parser.add_argument("--language", default="zh", help="语言代码；auto 表示自动检测")
    parser.add_argument("--model", default="base", help="Whisper 模型名称或本地模型目录")
    return parser.parse_args()


def main() -> int:
    args = arguments()
    source = Path(args.input).resolve()
    output = Path(args.output).resolve()
    if not source.is_file():
        print("输入语音文件不存在", file=sys.stderr)
        return 2

    try:
        from faster_whisper import WhisperModel
    except ImportError:
        print("未安装 faster-whisper，请运行 deploy/install-local-stt.sh", file=sys.stderr)
        return 3

    project_root = Path(__file__).resolve().parents[2]
    model_dir = Path(os.environ.get(
        "YIYUNYING_STT_MODEL_DIR",
        str(project_root / "storage" / "stt" / "models"),
    )).resolve()
    model_dir.mkdir(parents=True, exist_ok=True)
    output.parent.mkdir(parents=True, exist_ok=True)

    device = os.environ.get("YIYUNYING_STT_DEVICE", "cpu").strip() or "cpu"
    compute_type = os.environ.get(
        "YIYUNYING_STT_COMPUTE_TYPE",
        "int8" if device == "cpu" else "float16",
    ).strip()
    language = args.language.strip().lower()
    if language in {"", "auto", "automatic"}:
        language = None

    try:
        model = WhisperModel(
            args.model,
            device=device,
            compute_type=compute_type,
            download_root=str(model_dir),
            local_files_only=False,
        )
        segments, _ = model.transcribe(
            str(source),
            language=language,
            beam_size=5,
            vad_filter=True,
            vad_parameters={"min_silence_duration_ms": 400},
            condition_on_previous_text=True,
        )
        text = "".join(segment.text for segment in segments).strip()
    except Exception as error:  # The caller converts stderr into a Chinese API error.
        print(f"本地转写失败：{error}", file=sys.stderr)
        return 4

    if not text:
        print("没有识别到清晰语音", file=sys.stderr)
        return 5
    output.write_text(text, encoding="utf-8")
    print(text)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
