# 语音转文字

聊天语音通过 `POST /api/user/audio/transcriptions` 转写。已发送消息提交 `message_id`、`attachment_id`、`scope_type` 和 `scope_id`；编辑框语音输入先上传本人临时录音，再提交 `upload_id`。后端会校验消息读取权或临时文件所有权，不接受仅凭文件地址访问。

首次转写调用服务器配置的本地插件或 OpenAI 兼容语音接口，结果写入 `audio_transcriptions`，并同步写回 `media_attachments.metadata_json.transcript`。再次展开同一语音时直接读取缓存，不重复识别。

## 推荐：正式离线本地插件

源码包已经包含 `tools/stt/transcribe.py`。正式生产按
`docs/PRODUCTION_STT_RUNTIME.md` 使用固定 CPython、`--require-hashes` wheelhouse、
固定 revision 模型、真实 `www` 推理探针和原子 `current`/回滚；生产主机不得联网下载。

下面的旧脚本只供旧 Debug 环境兼容，不是正式生产安装方式：

```bash
bash deploy/install-local-stt.sh
```

正式环境优先检测 `storage/stt/current/python/bin/python3` 与固定 `current/model/base`；没有受信 `current` 时仍保留 `storage/stt/venv`/`models` legacy 回退，保证旧 Debug 软件可继续使用。正式 release 为 `root:www` 只读，`www` 不得写入；业务临时目录权限维持原规则。

正式离线 release 的运行合同固定为 `base/cpu/int8`，`STT_MODEL`、`YIYUNYING_STT_DEVICE` 和 `YIYUNYING_STT_COMPUTE_TYPE` 不会覆盖已激活的 `current`；这样线上请求与安装验收使用完全相同的配置。`small`/`medium` 或 GPU 配置仅属于没有正式 `current` 时的旧 Debug/legacy 路径。PHP 必须允许 `proc_open`；若主机禁止执行本地程序，再使用下面的兼容 API 方案。

## 可选：OpenAI 兼容接口

PHP-FPM 需要配置：

```text
STT_PROVIDER=openai-compatible
STT_API_URL=https://api.openai.com/v1/audio/transcriptions
STT_API_KEY=实际密钥
STT_MODEL=whisper-1
STT_TIMEOUT=120
STT_MAX_BYTES=104857600
```

未配置转写服务时，发送、播放、倍速和拖动语音不受影响；用户点击“转文字”会收到明确的中文配置提示。已有数据库执行 `database/upgrade_20260715_speech_transcription.sql`，全新安装只导入 `database/install.sql`。

也可以覆盖为其他本地 Whisper 命令。PHP-FPM 示例：

```text
STT_PROVIDER=local-command
STT_COMMAND=/usr/local/bin/whisper
STT_COMMAND_ARGS=["{input}","--language","{language}","--model","{model}","--output_format","txt","--output_dir","{output_dir}"]
STT_MODEL=base
STT_TIMEOUT=300
```

`STT_COMMAND_ARGS` 必须是 JSON 字符串数组，支持 `{input}`、`{language}`、`{model}`、`{output}`、`{output_dir}` 占位符。程序可把纯文本写到 `{output}`、输出目录内任意 `.txt`，或标准输出。不要把整条命令写进一个字符串，后端使用参数数组启动进程以避免 Shell 注入。
