# 易运盈本地语音转文字插件

该插件使用 `faster-whisper` 在部署易运盈后台的服务器本机完成中文语音转写。音频不会发送给第三方 API，转写结果仍由 PHP 权限层校验后写入缓存。

## 宝塔/Linux 安装

在后端根目录执行：

```bash
bash deploy/install-local-stt.sh
```

完成后重启站点使用的 PHP-FPM。后端会自动检测 `storage/stt/venv/bin/python3`，无需填写 API 密钥。首次转写会下载默认 `base` 模型到 `storage/stt/models`。

默认使用更适合 4 GB 服务器的 `base/int8`；内存充足且追求准确率时可设置 `STT_MODEL=small` 或 `medium`。CPU 默认使用 `int8`，也可通过 `YIYUNYING_STT_DEVICE` 和 `YIYUNYING_STT_COMPUTE_TYPE` 配置支持的 GPU。服务器没有 Python 3.10 及以上版本时，安装脚本会在 `storage/stt/python-runtime` 安装隔离运行时，不会修改系统 Python。

安装器固定使用 `PyAV 12.3.0` 与 `NumPy 1.26.4`，用于兼容 CentOS 7 的预编译依赖和当前服务器上的 ONNX Runtime；不要在项目虚拟环境内直接升级到 NumPy 2.x。

## 权限

PHP-FPM 运行用户需要读写：

```text
storage/stt/
storage/tmp/
public/uploads/
```

如主机禁用了 PHP `proc_open`，本地插件无法启动，需要在 PHP 的禁用函数中移除 `proc_open`，或改用 OpenAI 兼容语音接口。
