# 语音转文字

聊天语音通过 `POST /api/user/audio/transcriptions` 转写。已发送消息提交 `message_id`、`attachment_id`、`scope_type` 和 `scope_id`；编辑框语音输入先上传本人临时录音，再提交 `upload_id`。后端会校验消息读取权或临时文件所有权，不接受仅凭文件地址访问。

首次转写调用服务器配置的本地插件或 OpenAI 兼容语音接口，结果写入 `audio_transcriptions`，并同步写回 `media_attachments.metadata_json.transcript`。再次展开同一语音时直接读取缓存，不重复识别。

## 推荐：随包本地插件

源码包已经包含 `tools/stt/transcribe.py`。在 Linux/宝塔后端根目录执行：

```bash
bash deploy/install-local-stt.sh
```

安装脚本创建独立 Python 环境并安装 `faster-whisper 1.2.1`；没有系统 Python 3.10 及以上版本时，会自动安装项目专用运行时。安装完成后，后端自动检测本地插件，不需要 API 密钥。默认 `base` 模型保存在 `storage/stt/models`，首次使用时下载；PHP-FPM 运行用户必须能读写 `storage/stt`、`storage/tmp` 与 `public/uploads`。

服务器内存充足且追求更高准确率时可配置 `STT_MODEL=small` 或 `medium`。PHP 必须允许 `proc_open`；若主机禁止执行本地程序，再使用下面的兼容 API 方案。

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
