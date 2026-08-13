# 下载中心

下载中心使用 React 19、Next 16、Vinext 与 Vite。它读取发行元数据并展示四角色安装包、版本说明、校验和、功能系统和接口文档。API 文档支持 cURL/Java/JavaScript 示例切换、复制、Web Share/复制 canonical URL 降级、打印、锚点/新窗口打开；下载区提供 APK 选包、SHA-256 校验、打开/安装、未知来源授权和失败恢复说明。

```powershell
corepack pnpm install --frozen-lockfile
corepack pnpm test
corepack pnpm export:static
corepack pnpm lint
```

发布前必须确认 `release-metadata.json` 与 Android 源码和 APK manifest 的版本一致，并验证下载链接返回真实 APK 而非 HTML 错误页。Stable 网站只能公开用户、管理员、授权平台、平台总控四个 APK；源码、Git history、delivery 包和 project manifest 必须保持私有。

`2.8.0 (62)` 当前仍是 Build-pending：设备升级因固件虚拟化关闭、AVD 无 ADB 而阻塞，未安装 APK，不能 Finalize 或切换正式下载。线上仍为 `2.7.14 (59)`。
