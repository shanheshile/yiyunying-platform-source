# 易运盈本地语音转文字插件

该插件使用 `faster-whisper` 在部署易运盈后台的服务器本机完成中文语音转写。音频不会发送给第三方 API，转写结果仍由 PHP 权限层校验后写入缓存。

## 正式生产安装

正式环境禁止使用会在线下载依赖/模型的旧脚本。请按
`docs/PRODUCTION_STT_RUNTIME.md` 在可信工作站构建固定哈希的离线 bundle，先 dry-run，
再由 DPAPI 凭据启动器执行双确认的原子安装。正式运行时从
`storage/stt/current/python/bin/python3` 与 `current/model/base` 读取，生产主机不联网。

`deploy/install-local-stt.sh` 仅为旧 Debug 部署兼容入口，继续保留但不构成正式生产信任链：

```bash
bash deploy/install-local-stt.sh
```

后端在没有受信 `current` 时仍会回退检测 `storage/stt/venv/bin/python3`，因此旧 Debug 软件不会因正式 runtime 上线而失效。该 legacy 目录不得被正式安装器复制、执行或删除。

正式 `current` 固定使用已经过安装验收的 `base/cpu/int8`，不会被 `STT_MODEL`、`YIYUNYING_STT_DEVICE` 或 `YIYUNYING_STT_COMPUTE_TYPE` 覆盖。`small`/`medium` 和 GPU 配置只保留给没有正式 `current` 的旧 Debug/legacy 路径。旧安装脚本在服务器没有 Python 3.10 及以上版本时会在 `storage/stt/python-runtime` 安装隔离运行时，不会修改系统 Python。

安装器固定使用 `PyAV 12.3.0` 与 `NumPy 1.26.4`，用于兼容 CentOS 7 的预编译依赖和当前服务器上的 ONNX Runtime；不要在项目虚拟环境内直接升级到 NumPy 2.x。

## 正式权限

正式 release 必须是 `root:www`：目录/可执行文件 `0750`，数据 `0640`，`www` 只有读取和执行权限。PHP-FPM 仅需要继续读写业务临时目录：

```text
storage/tmp/
public/uploads/
```

如主机禁用了 PHP `proc_open`，本地插件无法启动，需要在 PHP 的禁用函数中移除 `proc_open`，或改用 OpenAI 兼容语音接口。
