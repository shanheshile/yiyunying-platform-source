# 正式 Release 真机证据与单次风险豁免门禁

本门禁只改变 `Finalize` 的证据选择，不把未执行的真机测试写成通过。用户在 2026-08-15 明确表示真机验证由用户后续自行完成，因此本次 `1.0.0/code66` 可以选择一次性风险豁免；当前仓库不预置 `release-risk-waiver.json`，也不能把当前 code65 或其他版本带过门禁。

## Finalize 必须二选一

Stable `Finalize` 在任何项目资产写入前，必须且只能在对应发布目录中找到一个文件：

1. `device-upgrade-evidence.json`：四角色均由 `android/tools/verify-device-upgrade.ps1` 完成 `code62 -> code66` 原位升级，且包名、生产签名、v2 签名、UID、数据目录和升级前后启动全部通过；
2. `release-risk-waiver.json`：仅适用于 `Stable 1.0.0/code66`，状态固定为 `pending-user-validation`。

两个文件同时存在、两个都不存在、字段缺失、出现 unknown 字段、版本/哈希/提交/标签漂移，都会失败关闭。风险豁免不能把 `deviceValidation.status` 写成 `passed`。

两种证据都必须精确绑定：

- Build 源码提交 A；
- 下载元数据/证据提交 B；
- 精确指向 B 的 annotated tag `v1.0.0`；
- Build 阶段 `release-manifest.json` 的 SHA-256；
- `1.0.0/code66`、Stable 通道与四个角色 `user/admin/authorized/owner`。

## 本次风险豁免明确未执行的项目

- Stable 生产签名基线 `code62 -> code66` 四角色原位升级；
- 旧 Debug `code60 -> code66 legacyCompat` 四角色兼容升级；
- 四角色登录；
- 四角色本地数据连续性；
- 四角色核心功能最小闭环；
- 小米、vivo、OPPO、华为和原生 Android 等多厂商矩阵。

用户承担上述后续真机验收；发现问题后仍必须修复并重新发布。本豁免不是设备验收证据，不得在报告、官网或发布说明中写“真机通过”。

## 风险豁免固定流程

先把版本单独更新并提交为 `1.0.0/code66`。Stable Build 必须显式选择计划并输入本次精确确认令牌；脚本会把官网可见发布说明固定加入“真机验证待用户完成（不得声明真机通过）”：

```powershell
$confirm = 'I_ACCEPT_1.0.0_CODE66_RELEASE_WITH_DEVICE_VALIDATION_PENDING'
powershell -File android/tools/release.ps1 `
  -Phase Build -Channel Stable `
  -DeviceValidationPlan UserRiskWaiver `
  -RiskWaiverConfirmationToken $confirm `
  -ExpectedSignerSha256 '<由受保护发布身份读取的生产签名 SHA-256>'
```

Build 产物和下载元数据提交为证据 B、并创建精确指向 B 的 annotated tag `v1.0.0` 后，才生成未被 Git 跟踪的单次豁免文件：

```powershell
powershell -File android/tools/new-release-risk-waiver.ps1 `
  -RiskWaiverConfirmationToken $confirm
```

生成器要求工作区完全干净、`main=HEAD=B`、A 是 B 的祖先、tag 为 annotated tag 且精确指向 B；目标文件已存在时拒绝覆盖。生成后再以同一精确令牌执行 Finalize：

```powershell
powershell -File android/tools/release.ps1 `
  -Phase Finalize -Channel Stable `
  -DeviceValidationPlan UserRiskWaiver `
  -RiskWaiverConfirmationToken $confirm
```

普通 `Finalize`、少传一次令牌、大小写不同、code65、其他版本、Debug 通道或把豁免文件复制到其他发布，均不会放行。

## 用户完成真机验证后的证据路径

若用户后续在 Finalize 前完成四角色真机门禁，应删除尚未使用的风险豁免，使用 `verify-device-upgrade.ps1` 的四条 `PASS` JSON 结果生成唯一的 `device-upgrade-evidence.json`。聚合文件必须遵循 `android/tools/verify-release-device-gate.ps1` 的严格 schema，角色顺序固定为 `user/admin/authorized/owner`，不得手工把失败或未执行项改成 `true`。

使用完整真机证据时，Build/Finalize 选择 `DeviceEvidence`，不得传风险豁免令牌。只有该路径可以在 finalized manifest 中产生 `deviceValidation.status=passed`。

## 官网与审计边界

`deviceValidationPlan` 在 pending metadata 与 finalized manifest 之间为不可变字段。风险豁免计划还强制 pending metadata 和最终 manifest 都包含官网可见文字“真机验证待用户完成（不得声明真机通过）”；下载站公开投影和原子部署会继续校验该字段与发布说明没有漂移。

自动化合同位于 `backend/tools/tests/test_release_device_gate.py`。它覆盖四角色证据通过、豁免待验收、二选一、精确令牌、code66/日期冻结、unknown 字段、A/B/tag/manifest 绑定和伪造通过拒绝。
