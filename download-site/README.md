# 下载中心

下载中心使用 React 19、Next 16、Vinext 与 Vite。它读取发行元数据并展示最新安装包、版本说明、校验和与下载入口。

```powershell
corepack pnpm install --frozen-lockfile
corepack pnpm test
corepack pnpm export:static
```

发布前必须确认 `release-metadata.json` 与 Android 源码和 APK manifest 的版本一致，并验证下载链接返回真实 APK 而非 HTML 错误页。
