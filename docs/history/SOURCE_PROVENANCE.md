# 源码来源与边界

## 当前规范来源

当前可维护源码以本仓库 `main` 分支为唯一规范来源。仓库首次提交为：

```text
c8fe64eeef06e634c84e7c9f7b818f34421a9171 Initial source release 2.7.2
```

当前源码版本入口：

- Android：`android/version.properties`
- 下载中心：`download-site/release-metadata.json`
- 后端环境：`backend/.env.example` 与部署环境变量
- 数据库：`backend/database/install.sql` 及后续迁移

## 本机历史材料

规范仓库建立前的材料位于工作区父目录，主要包括：

- `yiyunying-android-java-source-v2.6.11.zip`
- `yiyunying-android-java-source-v2.6.12.zip`
- `yiyunying-android-java-source-v2.6.13.zip`
- `.baselines/yiyunying-android-2.6.33/`
- `release/2.6.28/`、`release/2.6.33/`、`release/2.6.35/`、`release/2.6.36/`、`release/2.7.0/`
- `releases/2.6.20-debug/` 至 `releases/2.7.2/`
- `yiyunying-platform-source-v2.7.2.zip`

这些文件用于追溯和比对，不应直接混入主分支。大型 APK、备份和归档包容易造成仓库膨胀，也可能包含环境差异或过时产物。

## 明确边界

- 早期文件时间戳不是 Git 提交时间。
- 目录名称不是功能完成证明。
- Debug APK 不是正式生产发行包。
- 本仓库不包含生产数据库、签名私钥、服务器凭据或用户数据。
- 历史对话中的密码、地址或令牌不应写入仓库；曾暴露的凭据必须轮换。
- 正式发布必须由当前源码重新构建，并由发布流水线生成版本、大小和 SHA-256 元数据。

## 迁移建议

1. 将旧归档放入只读、加密、带访问审计的归档存储。
2. 为每个可证明版本生成清单：版本号、版本码、源码提交、构建时间、签名证书指纹、APK 哈希、数据库迁移版本。
3. 只把文档化的哈希和来源信息提交到 Git，不提交大体积二进制。
4. 从下一版本开始使用标签、发布页和 CI 产物，不再人工复制发布目录。
