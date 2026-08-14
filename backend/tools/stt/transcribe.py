#!/usr/bin/env python3
"""易运盈后台本地语音转文字插件。"""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path


OFFLINE_ENVIRONMENT = {
    "HF_HUB_OFFLINE": "1",
    "HF_HUB_DISABLE_TELEMETRY": "1",
    "TRANSFORMERS_OFFLINE": "1",
}


def arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="使用 faster-whisper 在本机转写语音")
    parser.add_argument("--input", required=True, help="输入音频文件")
    parser.add_argument("--output", required=True, help="UTF-8 文本输出文件")
    parser.add_argument("--language", default="zh", help="语言代码；auto 表示自动检测")
    parser.add_argument("--model", default="base", help="Whisper 模型名称或本地模型目录")
    parser.add_argument(
        "--runtime-probe",
        action="store_true",
        help=argparse.SUPPRESS,
    )
    return parser.parse_args()


def project_root() -> Path:
    return Path(__file__).resolve().parents[2]


def force_offline_mode() -> None:
    """Prevent the local runner and its dependencies from using the network."""
    for name, value in OFFLINE_ENVIRONMENT.items():
        os.environ[name] = value


def resolve_model_source(
    model_name: str,
    root: Path | None = None,
    runtime_probe: bool = False,
) -> tuple[str, str | None, bool]:
    """Select the immutable current model, then retain the legacy cache fallback."""
    root = (root or project_root()).resolve()
    requested = model_name.strip() or "base"
    if runtime_probe:
        # The production installer supplies the model in its freshly built
        # content-addressed release.  Never let a pre-existing current/legacy
        # tree redirect this security probe to bytes controlled by www.
        requested_path = Path(requested).expanduser()
        if not requested_path.is_dir():
            raise ValueError("runtime probe requires an existing local model directory")
        return str(requested_path.resolve(strict=True)), None, True

    current_model = root / "storage" / "stt" / "current" / "model" / "base"
    if current_model.is_dir():
        # Resolving the current link once keeps an in-flight request on one
        # verified release even if a later deployment atomically switches it.
        return str(current_model.resolve(strict=True)), None, True

    requested_path = Path(requested).expanduser()
    if requested_path.is_dir():
        return str(requested_path.resolve(strict=True)), None, False

    legacy_root = Path(os.environ.get(
        "YIYUNYING_STT_MODEL_DIR",
        str(root / "storage" / "stt" / "models"),
    )).expanduser().resolve()
    return requested, str(legacy_root), False


def main() -> int:
    args = arguments()
    source = Path(args.input).resolve()
    output = Path(args.output).resolve()
    if not source.is_file():
        print("输入语音文件不存在", file=sys.stderr)
        return 2

    # Apply offline controls before importing huggingface_hub through
    # faster-whisper; some libraries read these flags during import.
    force_offline_mode()
    try:
        from faster_whisper import WhisperModel
    except ImportError:
        print("未安装 faster-whisper，本地 STT 离线运行时不完整", file=sys.stderr)
        return 3

    try:
        model_source, legacy_download_root, formal_runtime = resolve_model_source(
            args.model,
            runtime_probe=args.runtime_probe,
        )
    except (OSError, ValueError) as error:
        print(f"本地转写失败：{error}", file=sys.stderr)
        return 4
    output.parent.mkdir(parents=True, exist_ok=True)

    if formal_runtime:
        # The immutable production release is accepted only for the exact
        # base/cpu/int8 configuration proved by its activation probes.
        device = "cpu"
        compute_type = "int8"
    else:
        device = os.environ.get("YIYUNYING_STT_DEVICE", "cpu").strip() or "cpu"
        compute_type = os.environ.get(
            "YIYUNYING_STT_COMPUTE_TYPE",
            "int8" if device == "cpu" else "float16",
        ).strip()
    language = args.language.strip().lower()
    if language in {"", "auto", "automatic"}:
        language = None

    try:
        model_options = {
            "device": device,
            "compute_type": compute_type,
            "local_files_only": True,
        }
        if legacy_download_root is not None:
            model_options["download_root"] = legacy_download_root
        model = WhisperModel(
            model_source,
            **model_options,
        )
        segments, _ = model.transcribe(
            str(source),
            language=language,
            beam_size=5,
            vad_filter=True,
            vad_parameters={"min_silence_duration_ms": 400},
            condition_on_previous_text=True,
        )
        rows = list(segments)
        text = "".join(segment.text for segment in rows).strip()
    except Exception as error:  # The caller converts stderr into a Chinese API error.
        print(f"本地转写失败：{error}", file=sys.stderr)
        return 4

    if args.runtime_probe:
        receipt = json.dumps(
            {"runtime_probe": True, "segments": len(rows)},
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        )
        output.write_text(receipt, encoding="utf-8")
        print(receipt)
        return 0
    if not text:
        print("没有识别到清晰语音", file=sys.stderr)
        return 5
    output.write_text(text, encoding="utf-8")
    print(text)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
